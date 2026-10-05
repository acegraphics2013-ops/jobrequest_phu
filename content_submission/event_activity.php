<?php
declare(strict_types=1);

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";
include "../includes/hpu_availability.php";

checkLogin();
requireModule($conn, "publication_submission");

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

hpuEnsureBlockedDatesTable($conn);


function contentTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?"
    );
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row["total"] ?? 0) > 0;
}

function contentValidDate(?string $value): ?string
{
    $value = trim((string)$value);

    if ($value === "") {
        return null;
    }

    $date = DateTime::createFromFormat("!Y-m-d", $value);

    if (!$date || $date->format("Y-m-d") !== $value) {
        return null;
    }

    return $value;
}

function contentFlash(string $type, string $message): void
{
    $_SESSION["content_submission_flash"] = [
        "type" => $type,
        "message" => $message
    ];
}

function contentPullFlash(): ?array
{
    $flash = $_SESSION["content_submission_flash"] ?? null;
    unset($_SESSION["content_submission_flash"]);
    return is_array($flash) ? $flash : null;
}

function contentEnsurePublicTokenColumn(mysqli $conn): void
{
    try {
        $check = $conn->query(
            "SHOW COLUMNS FROM publication_event_submissions LIKE 'public_token'"
        );

        if ($check && $check->num_rows > 0) {
            return;
        }

        $conn->query(
            "ALTER TABLE publication_event_submissions
             ADD COLUMN public_token CHAR(64) NULL,
             ADD UNIQUE INDEX uniq_event_public_token (public_token)"
        );
    } catch (Throwable $e) {
        try {
            $conn->query(
                "ALTER TABLE publication_event_submissions
                 ADD COLUMN public_token CHAR(64) NULL"
            );
        } catch (Throwable $e2) {
        }
    }
}

function contentNewPublicToken(mysqli $conn, string $table): string
{
    do {
        $token = bin2hex(random_bytes(32));

        $stmt = $conn->prepare(
            "SELECT id FROM `{$table}` WHERE public_token = ? LIMIT 1"
        );

        if (!$stmt) {
            return $token;
        }

        $stmt->bind_param("s", $token);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Also avoid colliding with job request tokens.
        if (!$exists) {
            try {
                $jobCheck = $conn->prepare(
                    "SELECT id FROM job_requests WHERE public_token = ? LIMIT 1"
                );

                if ($jobCheck) {
                    $jobCheck->bind_param("s", $token);
                    $jobCheck->execute();
                    $exists = $jobCheck->get_result()->fetch_assoc();
                    $jobCheck->close();
                }
            } catch (Throwable $e) {
            }
        }
    } while ($exists);

    return $token;
}


$userId = (int)($_SESSION["user_id"] ?? 0);
$sessionEmail = trim((string)($_SESSION["email"] ?? ""));

$eventTypes = [
    "Medical Mission",
    "Health Forum / Seminar",
    "Training / Seminar",
    "Community Outreach",
    "Program Launch",
    "Hospital Anniversary",
    "Sports / Wellness Activity",
    "Others"
];

/*
|--------------------------------------------------------------------------
| Storage table
|--------------------------------------------------------------------------
*/

$conn->query(
    "CREATE TABLE IF NOT EXISTS publication_event_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        submitted_by INT NOT NULL,
        email_address VARCHAR(190) NOT NULL,
        title VARCHAR(255) NOT NULL,
        activity_type VARCHAR(120) NOT NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        activity_time VARCHAR(120) NULL,
        venue VARCHAR(255) NULL,
        lead_office VARCHAR(255) NULL,
        partner_offices TEXT NULL,
        person_in_charge VARCHAR(255) NULL,
        participant_count INT NULL,
        background TEXT NULL,
        objectives TEXT NULL,
        activity_highlights LONGTEXT NULL,
        key_outputs LONGTEXT NULL,
        impact_significance LONGTEXT NULL,
        quotable_statement TEXT NULL,
        remembrance_sentence TEXT NULL,
        preferred_publication_date DATE NULL,
        privacy_confirmed TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(40) NOT NULL DEFAULT 'For Review',
        publication_notes TEXT NULL,
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        published_at DATETIME NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_event_submitted_by (submitted_by),
        INDEX idx_event_created_at (created_at),
        INDEX idx_event_pub_date (preferred_publication_date)
    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci"
);

contentEnsurePublicTokenColumn($conn);

if (empty($_SESSION["content_event_csrf"])) {
    $_SESSION["content_event_csrf"] = bin2hex(random_bytes(32));
}

$csrf = (string)$_SESSION["content_event_csrf"];
$error = "";
$submitted = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedCsrf = (string)($_POST["csrf_token"] ?? "");

    if (
        $postedCsrf === ""
        ||
        !hash_equals($csrf, $postedCsrf)
    ) {
        $error = "Security token expired. Please refresh the page and try again.";
    } else {
        $email = trim((string)($_POST["email_address"] ?? ""));
        $title = trim((string)($_POST["title"] ?? ""));
        $type = trim((string)($_POST["type"] ?? ""));
        $startDate = contentValidDate($_POST["start_date"] ?? null);
        $endDate = contentValidDate($_POST["end_date"] ?? null);
        $activityTime = trim((string)($_POST["activity_time"] ?? ""));
        $venue = trim((string)($_POST["venue"] ?? ""));
        $leadOffice = trim((string)($_POST["lead_office"] ?? ""));
        $partnerOffices = trim((string)($_POST["partner_offices"] ?? ""));
        $personInCharge = trim((string)($_POST["person_in_charge"] ?? ""));
        $participantCountRaw = trim((string)($_POST["participant_count"] ?? ""));
        $background = trim((string)($_POST["background"] ?? ""));
        $objectives = trim((string)($_POST["objectives"] ?? ""));
        $activityHighlights = trim((string)($_POST["activity_highlights"] ?? ""));
        $keyOutputs = trim((string)($_POST["key_outputs"] ?? ""));
        $impact = trim((string)($_POST["impact_significance"] ?? ""));
        $quote = trim((string)($_POST["quotable_statement"] ?? ""));
        $remembrance = trim((string)($_POST["remembrance_sentence"] ?? ""));
        $pubDate = contentValidDate($_POST["pub_date"] ?? null);
        $privacyConfirmed = isset($_POST["privacy_confirmed"]) ? 1 : 0;

        $participantCount =
            $participantCountRaw !== ""
                ? max(0, (int)$participantCountRaw)
                : null;

        if (
            $email === ""
            ||
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $error = "Please enter a valid email address.";
        } elseif ($title === "" || $type === "") {
            $error = "Please provide the activity title and type.";
        } elseif ($startDate === null || $endDate === null) {
            $error = "Please provide valid start and end dates.";
        } elseif (strtotime($endDate) < strtotime($startDate)) {
            $error = "End date cannot be earlier than the start date.";
        } elseif ($leadOffice === "") {
            $error = "Please provide the lead office / unit / committee division.";
        } elseif ($personInCharge === "") {
            $error = "Please provide the person-in-charge.";
        } elseif ($background === "" || $objectives === "") {
            $error = "Please complete the Background and Objectives sections.";
        } elseif ($activityHighlights === "" || $impact === "") {
            $error = "Please complete the Activity Highlights and Impact / Significance sections.";
        } elseif (!$privacyConfirmed) {
            $error = "Please confirm the Data Privacy declaration before submitting.";
        }

        /*
         * The actual activity dates are documentation fields and are not
         * restricted by HPU publication availability. Only the preferred
         * publication date is checked.
         */
        if ($error === "" && $pubDate !== null) {
            $publicationBlock =
                hpuAvailabilityFindBlock(
                    $conn,
                    $pubDate,
                    ["Publication Submission"]
                );

            if ($publicationBlock) {
                $error =
                    hpuAvailabilityMessage(
                        $pubDate,
                        $publicationBlock
                    );
            }
        }

        if ($error === "") {
            $publicToken = contentNewPublicToken($conn, "publication_event_submissions");

            $stmt = $conn->prepare(
                "INSERT INTO publication_event_submissions (
                    submitted_by,
                    email_address,
                    title,
                    activity_type,
                    start_date,
                    end_date,
                    activity_time,
                    venue,
                    lead_office,
                    partner_offices,
                    person_in_charge,
                    participant_count,
                    background,
                    objectives,
                    activity_highlights,
                    key_outputs,
                    impact_significance,
                    quotable_statement,
                    remembrance_sentence,
                    preferred_publication_date,
                    privacy_confirmed,
                    public_token
                 )
                 VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                 )"
            );

            $stmt->bind_param(
                "issssssssssissssssssis",
                $userId,
                $email,
                $title,
                $type,
                $startDate,
                $endDate,
                $activityTime,
                $venue,
                $leadOffice,
                $partnerOffices,
                $personInCharge,
                $participantCount,
                $background,
                $objectives,
                $activityHighlights,
                $keyOutputs,
                $impact,
                $quote,
                $remembrance,
                $pubDate,
                $privacyConfirmed,
                $publicToken
            );

            $stmt->execute();
            $stmt->close();

            $submitted = true;
            contentFlash(
                "success",
                "Institutional Activity Documentation submitted successfully. HPU personnel can review it under Publication Requests."
            );

            header(
                "Location: "
                . BASE_URL
                . "/content_submission/event_activity.php"
            );
            exit;
        }
    }
}

$flash = contentPullFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Institutional Activity Documentation | HPU Workbench</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">

    <style>
        :root{
            --ui-primary:#2563eb;
            --ui-text:#0f172a;
            --ui-muted:#64748b;
            --ui-border:#dbe3ef;
            --ui-bg:#f4f7fb;
            --ui-surface:#fff;
        }
        body{background:var(--ui-bg);}
        .content-submit-page{max-width:1120px;margin:0 auto;}
        .clean-card{overflow:hidden;margin-bottom:16px;border:1px solid var(--ui-border);border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.05);}
        .stack-section{padding:20px;}
        .doc-intro{display:flex;gap:12px;margin-bottom:14px;padding:14px 15px;border:1px solid #bfdbfe;border-radius:13px;background:#f8fbff;color:#334155;font-size:.76rem;line-height:1.55;}
        .doc-intro i{color:#2563eb;font-size:1.05rem;margin-top:1px;}
        .privacy-box{display:flex;gap:11px;margin-bottom:18px;padding:14px 15px;border:1px solid #e2e8f0;border-radius:13px;background:#f8fafc;color:#475569;font-size:.69rem;line-height:1.55;}
        .privacy-box i{color:#475569;font-size:1rem;margin-top:1px;}
        .section-caption{margin:0 0 13px;color:#0f172a;font-size:.82rem;font-weight:850;}
        .form-label{color:#334155;font-size:.72rem;font-weight:800;}
        .form-control,.form-select{min-height:43px;border-color:#dbe3ef;border-radius:9px;font-size:.8rem;}
        textarea.form-control{min-height:auto;}
        .field-help{margin-top:4px;color:#64748b;font-size:.59rem;line-height:1.4;}
        .required-star{color:#b42318;}
        .request-actions{position:sticky;bottom:0;z-index:10;display:flex;justify-content:flex-end;gap:8px;margin-top:14px;padding:12px 0 3px;background:linear-gradient(to bottom,rgba(244,247,251,0),#f4f7fb 42%);}
        .request-actions .btn{min-width:135px;font-weight:750;}
        .availability-warning{display:none;margin-top:5px;color:#b42318;font-size:.68rem;font-weight:750;}
        .availability-warning.show{display:block;}
        .flatpickr-input[readonly]{background:#fff;cursor:pointer;}
        .flatpickr-day.flatpickr-disabled,.flatpickr-day.flatpickr-disabled:hover{color:#b42318!important;background:#fff1f0!important;text-decoration:line-through;cursor:not-allowed;}
        .privacy-confirm{display:flex;align-items:flex-start;gap:9px;padding:12px;border:1px solid #dbe3ef;border-radius:10px;background:#fff;}
        .privacy-confirm .form-check-input{margin-top:2px;flex:0 0 auto;}
        /* ---- Polished page header (matches Recognition / Milestone) ---- */
        .rm-crumb{display:flex;align-items:center;flex-wrap:wrap;gap:7px;margin:0 0 10px 2px;color:#94a3b8;font-size:.7rem;font-weight:650;}
        .rm-crumb a{display:inline-flex;align-items:center;gap:5px;color:#64748b;text-decoration:none;font-weight:750;}
        .rm-crumb a:hover{color:#1d4ed8;text-decoration:underline;}
        .rm-crumb-sep{color:#cbd5e1;font-weight:400;}
        .rm-crumb-current{color:#0f172a;font-weight:800;}
        .ph--recognition{position:relative;overflow:hidden;padding:20px 22px;border:1px solid #dbe6f7;background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%);}
        .ph--recognition::before{content:"";position:absolute;inset:0 auto 0 0;width:4px;background:linear-gradient(180deg,#2563eb,#7c3aed 55%,#f59e0b);}
        .ph--recognition .ph-copy{align-items:flex-start;}
        .ph-icon--recognition{width:52px;height:52px;flex:0 0 52px;border:0;border-radius:14px;background:linear-gradient(135deg,#2563eb 0%,#4f46e5 55%,#7c3aed 100%);color:#fff;font-size:23px;box-shadow:0 8px 18px rgba(37,99,235,.28),inset 0 1px 0 rgba(255,255,255,.35);}
        .ph--recognition .ph-eyebrow{display:flex;align-items:center;gap:7px;color:#1d4ed8;}
        .ph--recognition .eyebrow-dot{width:7px;height:7px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.18);}
        .ph--recognition .eyebrow-divider{color:#b6c6e3;font-weight:400;}
        .ph--recognition .ph-title{font-size:1.45rem;letter-spacing:-.025em;}
        .ph--recognition .ph-subtitle{max-width:62ch;}
        .rm-meta{display:flex;flex-wrap:wrap;gap:6px 14px;margin:10px 0 0;padding:0;list-style:none;color:#475569;font-size:.7rem;font-weight:650;}
        .rm-meta li{display:inline-flex;align-items:center;gap:6px;}
        .rm-meta i{color:#2563eb;font-size:.78rem;}
        .rm-code{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1px dashed #93c5fd;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:.7rem;font-weight:850;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap;}
        .ph--recognition .ph-actions{align-items:center;}
        .ph--recognition .ph-actions .btn{box-shadow:0 2px 8px rgba(37,99,235,.12);}
        /* ============ WIZARD UX (additive, no backend change) ============ */
        .wizard-on .request-actions{display:none !important;}
        .wizard-shell{margin:0 0 14px;}
        .wizard-progress{height:6px;border-radius:999px;background:#e6edf7;overflow:hidden;margin-bottom:10px;box-shadow:inset 0 1px 2px rgba(15,23,42,.06);}
        .wizard-progress-bar{height:100%;width:25%;border-radius:999px;background:linear-gradient(90deg,#2563eb,#7c3aed);transition:width .35s ease;}
        .wizard-steps{list-style:none;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:0 0 10px;padding:0;}
        .wizard-step{margin:0;}
        .wizard-step-btn{display:flex;gap:10px;align-items:center;width:100%;padding:10px 12px;border:1px solid var(--ui-border);border-radius:12px;background:#fff;cursor:pointer;text-align:left;transition:.18s;color:var(--ui-text);}
        .wizard-step-btn:hover{border-color:#93c5fd;box-shadow:0 4px 12px rgba(37,99,235,.08);}
        .wizard-step-num{display:grid;width:30px;height:30px;flex:0 0 30px;place-items:center;border-radius:50%;background:#eef2f7;color:#475569;font-size:.78rem;font-weight:850;}
        .wizard-step-copy{min-width:0;}
        .wizard-step-copy strong{display:block;font-size:.74rem;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .wizard-step-copy small{display:block;color:var(--ui-muted);font-size:.6rem;font-weight:650;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .wizard-step.is-active .wizard-step-btn{border-color:var(--ui-primary);background:#eff6ff;box-shadow:0 0 0 3px rgba(37,99,235,.12);}
        .wizard-step.is-active .wizard-step-num{background:var(--ui-primary);color:#fff;}
        .wizard-step.is-done .wizard-step-num{background:#16a34a;color:#fff;}
        .wizard-step.is-done .wizard-step-btn{border-color:#bbf7d0;}
        .wizard-step.is-invalid .wizard-step-btn{border-color:#f04438;background:#fff6f5;}
        .wizard-step.is-invalid .wizard-step-num{background:#f04438;color:#fff;}
        .wizard-meta{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;color:var(--ui-muted);font-size:.68rem;font-weight:700;}
        .wizard-meta .draft-status{display:inline-flex;align-items:center;gap:6px;}
        .wizard-meta .draft-dot{width:7px;height:7px;border-radius:50%;background:#cbd5e1;}
        .wizard-meta .draft-dot.saved{background:#22c55e;}
        .wizard-panel{display:none;}
        .wizard-panel.is-active{display:block;animation:wizardIn .28s ease both;}
        @keyframes wizardIn{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:none;}}
        .draft-banner{display:none;gap:10px;align-items:flex-start;margin-bottom:12px;padding:11px 13px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;color:#1e3a8a;font-size:.76rem;line-height:1.45;}
        .draft-banner.show{display:flex;}
        .wizard-error-summary{display:none;margin-bottom:14px;padding:12px 14px;border:1px solid #fecaca;border-radius:12px;background:#fef2f2;color:#7f1d1d;font-size:.76rem;}
        .wizard-error-summary.show{display:block;}
        .wizard-error-summary strong{display:block;margin-bottom:6px;}
        .wizard-error-summary ul{margin:0;padding-left:18px;}
        .wizard-error-summary a{color:#b42318;font-weight:700;cursor:pointer;}
        .field-invalid{border-color:#f04438 !important;box-shadow:0 0 0 .16rem rgba(240,68,56,.12) !important;background:#fff7f7 !important;}
        .char-counter{margin-top:4px;color:#94a3b8;font-size:.6rem;font-weight:650;text-align:right;}
        .char-counter.warn{color:#b45309;}
        .char-counter.over{color:#b42318;font-weight:800;}
        .review-card{margin-bottom:12px;border:1px solid #dbe3ef;border-radius:14px;background:#fff;overflow:hidden;}
        .review-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 14px;border-bottom:1px solid #edf1f5;background:#fbfcfe;}
        .review-head h5{margin:0;font-size:.76rem;font-weight:850;color:#1e293b;display:flex;gap:8px;align-items:center;}
        .review-head h5 i{color:var(--ui-primary);}
        .review-edit{border:1px solid var(--ui-border);background:#fff;border-radius:8px;font-size:.66rem;font-weight:800;padding:4px 10px;cursor:pointer;color:#334155;}
        .review-edit:hover{border-color:#93c5fd;color:#1d4ed8;}
        .review-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 14px;padding:12px 14px;font-size:.74rem;}
        .review-item{min-width:0;}
        .review-item.full{grid-column:1/-1;}
        .review-item small{display:block;color:#94a3b8;font-size:.58rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;margin-bottom:2px;}
        .review-item div{color:#0f172a;font-weight:650;overflow-wrap:anywhere;}
        .review-empty{color:#94a3b8 !important;font-weight:500 !important;font-style:italic;}
        .wizard-footer{position:sticky;bottom:0;z-index:20;display:flex;align-items:center;gap:10px;margin-top:14px;padding:12px 14px;border:1px solid var(--ui-border);border-radius:14px;background:rgba(255,255,255,.96);backdrop-filter:blur(8px);box-shadow:0 10px 28px rgba(15,23,42,.10);}
        .wizard-footer .step-count{color:var(--ui-muted);font-size:.68rem;font-weight:800;white-space:nowrap;}
        .wizard-footer .spacer{flex:1;}
        .wizard-footer .btn{min-height:44px;border-radius:10px;font-weight:800;padding:8px 18px;}
        .shake{animation:wshake .3s ease;}
        @keyframes wshake{25%{transform:translateX(-4px);}50%{transform:translateX(4px);}75%{transform:translateX(-2px);}}
        @media(max-width:650px){
            .wizard-steps{grid-template-columns:repeat(4,minmax(0,1fr));}
            .wizard-step-copy strong{font-size:.62rem;}
            .wizard-step-copy small{display:none;}
            .review-grid{grid-template-columns:1fr;}
            .wizard-footer{flex-wrap:wrap;}
            .wizard-footer .btn{flex:1;}
            .content{padding:12px;}
            .stack-section{padding:15px;}
            .request-actions{flex-direction:column-reverse;}
            .request-actions .btn{width:100%;}
            .ph--recognition{padding:16px;}
            .ph--recognition .ph-title{font-size:1.2rem;}
            .ph-icon--recognition{width:44px;height:44px;flex-basis:44px;font-size:19px;}
            .rm-meta{gap:5px 12px;}
        }
    </style>
</head>

<body class="app-shell">
<div class="d-flex min-vh-100">

<?php include "../includes/sidebar.php"; ?>

<div class="main flex-grow-1">
<main class="content">
<div class="container-fluid">
<div class="content-submit-page">

    <nav class="rm-crumb" aria-label="Breadcrumb">
        <a href="<?= e(BASE_URL) ?>/dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a>
        <span class="rm-crumb-sep">/</span>
        <span>Publication Submission</span>
        <span class="rm-crumb-sep">/</span>
        <span class="rm-crumb-current" aria-current="page">Event / Activity</span>
    </nav>

    <div class="ph ph--recognition">
        <div class="ph-copy">
            <div class="ph-icon ph-icon--recognition" aria-hidden="true">
                <i class="bi bi-calendar2-event-fill"></i>
            </div>
            <div class="min-w-0">
                <p class="ph-eyebrow"><span class="eyebrow-dot" aria-hidden="true"></span> Publication Submission <span class="eyebrow-divider">•</span> Events &amp; Activities</p>
                <h1 class="ph-title">Institutional Activity Documentation</h1>
                <p class="ph-subtitle">Showcase what happened — submit ASTMMC activities, trainings, meetings, programs, and events for official publication and records.</p>
                <ul class="rm-meta">
                    <li><i class="bi bi-clock-history"></i> ~5 mins to complete</li>
                    <li><i class="bi bi-paperclip"></i> Up to 5 supporting files</li>
                    <li><i class="bi bi-eye"></i> HPU review before publishing</li>
                </ul>
            </div>
        </div>
        <div class="ph-actions">
            <span class="rm-code">
                <i class="bi bi-file-earmark-text"></i>
                Form IADF
            </span>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/content_submission/recognition_milestone.php">
                <i class="bi bi-arrow-left-right"></i>
                Recognition / Milestone form
            </a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash["type"]) ?> alert-dismissible fade show">
            <?= e($flash["message"]) ?>
            <button class="btn-close" type="button" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form id="eventActivityForm" method="post" action="event_activity.php">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

        <section class="clean-card">
            <div class="stack-section">

                <div class="doc-intro">
                    <i class="bi bi-info-circle-fill"></i>
                    <div>
                        The Institutional Activity Documentation Form (IADF) collects essential information on ASTMMC activities, trainings, meetings, programs, and events. The information supports website publication, institutional records, accomplishment reporting, knowledge management, and future reference.
                    </div>
                </div>

                <div class="privacy-box">
                    <i class="bi bi-shield-lock-fill"></i>
                    <div>
                        <strong>Data Privacy Disclaimer.</strong>
                        Personal data submitted through this form shall be processed for legitimate institutional purposes such as documentation, reporting, publication, and knowledge management. By submitting, the office confirms that personal data included has been collected with proper consent and may be processed for the stated purposes.
                    </div>
                </div>

                <h2 class="section-caption">A. Activity Profile</h2>

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">
                            Email Address <span class="required-star">*</span>
                        </label>
                        <input
                            type="email"
                            class="form-control"
                            name="email_address"
                            value="<?= e($_POST["email_address"] ?? $sessionEmail) ?>"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">
                            Title of Activity <span class="required-star">*</span>
                        </label>
                        <input
                            class="form-control"
                            name="title"
                            value="<?= e($_POST["title"] ?? "") ?>"
                            required
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">
                            Type of Activity <span class="required-star">*</span>
                        </label>
                        <select class="form-select" name="type" required>
                            <option value="">Select activity type</option>
                            <?php foreach ($eventTypes as $eventType): ?>
                                <option
                                    value="<?= e($eventType) ?>"
                                    <?= ($_POST["type"] ?? "") === $eventType ? "selected" : "" ?>
                                >
                                    <?= e($eventType) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">
                            Start Date <span class="required-star">*</span>
                        </label>
                        <input
                            type="date"
                            class="form-control"
                            name="start_date"
                            value="<?= e($_POST["start_date"] ?? "") ?>"
                            required
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">
                            End Date <span class="required-star">*</span>
                        </label>
                        <input
                            type="date"
                            class="form-control"
                            name="end_date"
                            value="<?= e($_POST["end_date"] ?? "") ?>"
                            required
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Time of Activity</label>
                        <input
                            class="form-control"
                            name="activity_time"
                            value="<?= e($_POST["activity_time"] ?? "") ?>"
                            placeholder="e.g. 8:00 AM – 5:00 PM"
                        >
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">Venue</label>
                        <input
                            class="form-control"
                            name="venue"
                            value="<?= e($_POST["venue"] ?? "") ?>"
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">
                            Lead Office / Unit / Committee Division <span class="required-star">*</span>
                        </label>
                        <input
                            class="form-control"
                            name="lead_office"
                            value="<?= e($_POST["lead_office"] ?? "") ?>"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">
                            Partner Office(s) / Organization(s)
                        </label>
                        <input
                            class="form-control"
                            name="partner_offices"
                            value="<?= e($_POST["partner_offices"] ?? "") ?>"
                        >
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">
                            Person-in-Charge <span class="required-star">*</span>
                        </label>
                        <input
                            class="form-control"
                            name="person_in_charge"
                            value="<?= e($_POST["person_in_charge"] ?? "") ?>"
                            required
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">
                            Estimated / Actual Number of Participants
                        </label>
                        <input
                            type="number"
                            min="0"
                            class="form-control"
                            name="participant_count"
                            value="<?= e($_POST["participant_count"] ?? "") ?>"
                        >
                    </div>

                </div>
            </div>
        </section>

        <section class="clean-card">
            <div class="stack-section">

                <h2 class="section-caption">B. Activity Information</h2>

                <div class="row g-3">

                    <div class="col-12">
                        <label class="form-label">
                            Background <span class="required-star">*</span>
                        </label>
                        <textarea
                            class="form-control"
                            name="background"
                            rows="4"
                            required
                            placeholder="Provide a brief background and rationale of the activity."
                        ><?= e($_POST["background"] ?? "") ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label">
                            Objectives <span class="required-star">*</span>
                        </label>
                        <textarea
                            class="form-control"
                            name="objectives"
                            rows="4"
                            required
                            placeholder="List the objectives of the activity."
                        ><?= e($_POST["objectives"] ?? "") ?></textarea>
                    </div>

                </div>
            </div>
        </section>

        <section class="clean-card">
            <div class="stack-section">

                <h2 class="section-caption">C. Activity Highlights</h2>

                <label class="form-label">
                    Major Activities / Significant Discussions / Presentations / Ceremonies / Accomplishments
                    <span class="required-star">*</span>
                </label>

                <textarea
                    class="form-control"
                    name="activity_highlights"
                    rows="6"
                    required
                    placeholder="Describe the major activities conducted and the most significant highlights."
                ><?= e($_POST["activity_highlights"] ?? "") ?></textarea>

            </div>
        </section>

        <section class="clean-card">
            <div class="stack-section">

                <h2 class="section-caption">D. Key Outputs / Agreements</h2>

                <textarea
                    class="form-control"
                    name="key_outputs"
                    rows="5"
                    placeholder="List important outputs, resolutions, commitments, or agreements resulting from the activity."
                ><?= e($_POST["key_outputs"] ?? "") ?></textarea>

            </div>
        </section>

        <section class="clean-card">
            <div class="stack-section">

                <h2 class="section-caption">E. Impact / Significance</h2>

                <textarea
                    class="form-control"
                    name="impact_significance"
                    rows="5"
                    required
                    placeholder="Briefly describe how the activity contributes to the hospital, employees, patients, stakeholders, or community."
                ><?= e($_POST["impact_significance"] ?? "") ?></textarea>

            </div>
        </section>

        <section class="clean-card">
            <div class="stack-section">

                <h2 class="section-caption">F. Quotable Statement</h2>

                <div class="row g-3">

                    <div class="col-12">
                        <label class="form-label">
                            Quotable Statement from Resource Speaker or Participant
                            <span class="text-muted">(Optional)</span>
                        </label>
                        <textarea
                            class="form-control"
                            name="quotable_statement"
                            rows="3"
                        ><?= e($_POST["quotable_statement"] ?? "") ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label">
                            In one sentence, how would you like this activity to be remembered?
                        </label>
                        <textarea
                            class="form-control"
                            name="remembrance_sentence"
                            rows="2"
                            maxlength="500"
                        ><?= e($_POST["remembrance_sentence"] ?? "") ?></textarea>
                    </div>

                </div>
            </div>
        </section>

        <section class="clean-card">
            <div class="stack-section">

                <h2 class="section-caption">Publication Scheduling</h2>

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">
                            Preferred Publication Date
                        </label>

                        <input
                            id="pub_date"
                            type="text"
                            inputmode="none"
                            autocomplete="off"
                            class="form-control"
                            name="pub_date"
                            value="<?= e($_POST["pub_date"] ?? "") ?>"
                        >

                        <div class="field-help">
                            Dates blocked for Publication Submission cannot be selected.
                        </div>

                        <div
                            id="pub_date_warning"
                            class="availability-warning"
                            role="alert"
                        ></div>
                    </div>

                    <div class="col-12">
                        <label class="privacy-confirm">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="privacy_confirmed"
                                value="1"
                                required
                                <?= isset($_POST["privacy_confirmed"]) ? "checked" : "" ?>
                            >
                            <span>
                                I confirm that any personal data included in this submission has been collected with proper consent and may be processed for ASTMMC documentation, reporting, publication, and knowledge-management purposes.
                            </span>
                        </label>
                    </div>

                </div>
            </div>
        </section>

        <div class="request-actions">
            <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/dashboard.php">
                Cancel
            </a>

            <button class="btn btn-primary" type="submit">
                <i class="bi bi-send-check me-1"></i>
                Submit Documentation
            </button>
        </div>

    </form>

</div>
</div>
</main>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
document.addEventListener(
    "DOMContentLoaded",
    async function () {

        const field =
            document.getElementById(
                "pub_date"
            );

        const warning =
            document.getElementById(
                "pub_date_warning"
            );

        if (!field || !warning) {
            return;
        }

        let blockedRanges = [];

        try {
            const params =
                new URLSearchParams();

            params.append(
                "services[]",
                "Publication Submission"
            );

            const response =
                await fetch(
                    <?= json_encode(BASE_URL . "/ajax/get_blocked_dates.php") ?>
                    + "?"
                    + params.toString(),
                    {
                        credentials:
                            "same-origin"
                    }
                );

            const data =
                await response.json();

            if (
                data.success
                &&
                Array.isArray(
                    data.ranges
                )
            ) {
                blockedRanges =
                    data.ranges;
            }
        } catch (error) {
            console.error(
                "Blocked publication dates load failed:",
                error
            );
        }

        flatpickr(
            field,
            {
                dateFormat: "Y-m-d",
                altInput: true,
                altFormat: "F j, Y",
                allowInput: false,
                disableMobile: true,
                defaultDate:
                    field.value || null,
                disable:
                    blockedRanges.map(
                        function (range) {
                            return {
                                from: range.from,
                                to: range.to
                            };
                        }
                    ),
                onChange:
                    async function () {

                        warning.classList.remove(
                            "show"
                        );

                        warning.textContent =
                            "";

                        if (!field.value) {
                            return;
                        }

                        const params =
                            new URLSearchParams();

                        params.set(
                            "date",
                            field.value
                        );

                        params.append(
                            "services[]",
                            "Publication Submission"
                        );

                        try {
                            const response =
                                await fetch(
                                    <?= json_encode(BASE_URL . "/ajax/check_blocked_date.php") ?>
                                    + "?"
                                    + params.toString(),
                                    {
                                        credentials:
                                            "same-origin"
                                    }
                                );

                            const data =
                                await response.json();

                            if (
                                data.success
                                &&
                                data.blocked
                            ) {
                                field._flatpickr.clear();

                                warning.textContent =
                                    data.message;

                                warning.classList.add(
                                    "show"
                                );
                            }
                        } catch (error) {
                            console.error(
                                "Publication availability re-check failed:",
                                error
                            );
                        }
                    }
            }
        );

    }
);
</script>
<script>
/* =====================================================
   EVENT / ACTIVITY — WIZARD UX (additive, no backend change)
===================================================== */
document.addEventListener("DOMContentLoaded", function () {
    var form = document.getElementById("eventActivityForm");
    if (!form) return;
    function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function esc(s) { return String(s == null ? "" : s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;"); }
    function val(name) { var el = form.querySelector('[name="' + name + '"]'); return el ? (el.value || "").trim() : ""; }

    var sections = Array.prototype.filter.call(form.children, function (el) {
        return el.tagName === "SECTION" && el.classList.contains("clean-card");
    });
    if (sections.length < 4) return;
    /* Group 7 cards into 4 steps: [A] [B+C] [D+E+F] [sched] */
    var groups;
    if (sections.length >= 7) groups = [[sections[0]], [sections[1], sections[2]], [sections[3], sections[4], sections[5]], [sections[6]]];
    else groups = [[sections[0]], [sections[1]], [sections[2]], [sections.slice(3)]];

    form.classList.add("wizard-on");
    form.setAttribute("novalidate", "novalidate");
    groups.forEach(function (g) { g.forEach(function (sec) { sec.classList.add("wizard-panel"); }); });

    var steps = [
        { t: "Activity Profile", s: "What, when & who" },
        { t: "Story", s: "Background & highlights" },
        { t: "Results & Voices", s: "Outputs & impact" },
        { t: "Schedule & Review", s: "Publish & send" }
    ];
    var shell = document.createElement("div");
    shell.className = "wizard-shell";
    shell.innerHTML =
        '<div class="wizard-progress" aria-hidden="true"><div class="wizard-progress-bar"></div></div>' +
        '<ol class="wizard-steps" aria-label="Form progress"></ol>' +
        '<div class="wizard-meta"><span class="step-indicator">Step 1 of 4</span>' +
        '<span class="draft-status"><span class="draft-dot"></span><span class="draft-text">No unsaved draft</span></span></div>' +
        '<div class="draft-banner" role="status"></div>';
    form.insertBefore(shell, form.firstChild);
    var stepsOl = shell.querySelector(".wizard-steps");
    steps.forEach(function (st, i) {
        var li = document.createElement("li");
        li.className = "wizard-step";
        li.innerHTML = '<button type="button" class="wizard-step-btn" data-goto="' + (i + 1) + '">' +
            '<span class="wizard-step-num">' + (i + 1) + '</span>' +
            '<span class="wizard-step-copy"><strong>' + esc(st.t) + '</strong><small>' + esc(st.s) + '</small></span></button>';
        stepsOl.appendChild(li);
    });
    groups.forEach(function (g, gi) {
        g.forEach(function (sec) {
            var box = document.createElement("div");
            box.className = "wizard-error-summary";
            box.setAttribute("role", "alert");
            var anchor = sec.querySelector(".stack-section");
            if (anchor) anchor.insertBefore(box, anchor.firstChild);
        });
    });

    var footer = document.createElement("div");
    footer.className = "wizard-footer";
    footer.innerHTML =
        '<button type="button" class="btn btn-outline-secondary btn-back">&larr; Back</button>' +
        '<span class="step-count">Step 1 of 4</span><span class="spacer"></span>' +
        '<a class="btn btn-outline-secondary" href="dashboard.php">Cancel</a>' +
        '<button type="button" class="btn btn-primary btn-next">Continue &rarr;</button>' +
        '<button type="submit" class="btn btn-primary btn-submit" style="display:none;"><i class="bi bi-send-check me-1"></i>Submit Documentation</button>';
    (function () {
        var cancel = form.querySelector(".request-actions a.btn");
        if (cancel) footer.querySelector("a.btn").setAttribute("href", cancel.getAttribute("href"));
    })();
    form.appendChild(footer);
    var backBtn = footer.querySelector(".btn-back");
    var nextBtn = footer.querySelector(".btn-next");
    var submitBtn = footer.querySelector(".btn-submit");
    var stepCount = footer.querySelector(".step-count");
    var progressBar = shell.querySelector(".wizard-progress-bar");
    var stepIndicator = shell.querySelector(".step-indicator");
    var current = 1;

    function stepSections(n) { return groups[n - 1] || []; }
    function clearStepMarks(n) {
        stepSections(n).forEach(function (sec) {
            $all(".field-invalid", sec).forEach(function (el) { el.classList.remove("field-invalid"); });
            var b = sec.querySelector(".wizard-error-summary");
            if (b) { b.classList.remove("show"); b.innerHTML = ""; }
        });
    }
    function showStepErrors(n, items) {
        clearStepMarks(n);
        if (!items.length) return;
        var secs = stepSections(n);
        var box = secs[0] ? secs[0].querySelector(".wizard-error-summary") : null;
        items.forEach(function (it) { if (it.el && it.el.classList) it.el.classList.add("field-invalid"); });
        if (box) {
            box.innerHTML = "<strong>Please fix " + items.length + " item" + (items.length > 1 ? "s" : "") + " to continue:</strong><ul>" +
                items.map(function (it, i) { return '<li><a data-err="' + i + '">' + esc(it.label) + " — " + esc(it.msg) + "</a></li>"; }).join("") + "</ul>";
            box.classList.add("show");
            $all("a[data-err]", box).forEach(function (a) {
                a.addEventListener("click", function () {
                    var it = items[+a.getAttribute("data-err")];
                    if (it && it.el && it.el.focus) { try { it.el.focus(); } catch (e) {} it.el.scrollIntoView({ behavior: "smooth", block: "center" }); }
                });
            });
        }
    }
    function reqInGroups(n, name, label, msg, extraCheck) {
        var el = form.querySelector('[name="' + name + '"]');
        if (!el || el.disabled) return null;
        var v = (el.value || "").trim();
        if (!v) return { el: el, label: label, msg: msg || "required" };
        if (extraCheck) return extraCheck(el, v);
        return null;
    }
    function validateStep(n) {
        var items = [];
        function push(it) { if (it) items.push(it); }
        if (n === 1) {
            push(reqInGroups(n, "email_address", "Email address", "enter a valid email", function (el, v) {
                return /.+@.+\..+/.test(v) ? null : { el: el, label: "Email address", msg: "enter a valid email" };
            }));
            push(reqInGroups(n, "title", "Title of activity", "required"));
            push(reqInGroups(n, "type", "Type of activity", "select a type"));
            push(reqInGroups(n, "start_date", "Start date", "required"));
            push(reqInGroups(n, "end_date", "End date", "required"));
            var s = val("start_date"), e = val("end_date");
            if (s && e && e < s) {
                var ee = form.querySelector('[name="end_date"]');
                items.push({ el: ee, label: "End date", msg: "cannot be earlier than start date" });
            }
            push(reqInGroups(n, "lead_office", "Lead office", "required"));
            push(reqInGroups(n, "person_in_charge", "Person-in-charge", "required"));
        } else if (n === 2) {
            push(reqInGroups(n, "background", "Background", "required"));
            push(reqInGroups(n, "objectives", "Objectives", "required"));
            push(reqInGroups(n, "activity_highlights", "Activity highlights", "required"));
        } else if (n === 3) {
            push(reqInGroups(n, "impact_significance", "Impact / significance", "required"));
        } else if (n === 4) {
            var pc = form.querySelector('[name="privacy_confirmed"]');
            if (pc && !pc.checked) items.push({ el: pc, label: "Data privacy", msg: "confirmation is required" });
        }
        showStepErrors(n, items);
        var li = stepsOl.children[n - 1];
        if (li) li.classList.toggle("is-invalid", items.length > 0);
        return items.length === 0;
    }
    function goTo(n, opts) {
        opts = opts || {};
        if (n < 1) n = 1; if (n > 4) n = 4;
        if (n > current && !opts.skipValidation) {
            for (var s = current; s < n; s++) { if (!validateStep(s)) { goTo(s, { skipValidation: true }); return false; } }
        }
        current = n;
        groups.forEach(function (g, gi) { g.forEach(function (sec) { sec.classList.toggle("is-active", gi === n - 1); sec.style.display = gi === n - 1 ? "" : "none"; }); });
        $all(".wizard-step", stepsOl).forEach(function (li, i) {
            li.classList.toggle("is-active", i === n - 1);
            li.classList.toggle("is-done", i < n - 1);
            var b = li.querySelector("button");
            if (b) { if (i === n - 1) b.setAttribute("aria-current", "step"); else b.removeAttribute("aria-current"); }
        });
        backBtn.style.visibility = n === 1 ? "hidden" : "visible";
        nextBtn.style.display = n === 4 ? "none" : "";
        submitBtn.style.display = n === 4 ? "" : "none";
        stepCount.textContent = "Step " + n + " of 4";
        stepIndicator.textContent = "Step " + n + " of 4 — " + steps[n - 1].t;
        progressBar.style.width = (n / 4 * 100) + "%";
        if (n === 4) buildReview();
        updateProgress();
        try { shell.scrollIntoView({ behavior: opts.instant ? "auto" : "smooth", block: "start" }); } catch (e) { window.scrollTo(0, 0); }
        return true;
    }
    $all(".wizard-step-btn", stepsOl).forEach(function (btn) {
        btn.addEventListener("click", function () { goTo(+btn.getAttribute("data-goto")); });
    });
    backBtn.addEventListener("click", function () { goTo(current - 1, { skipValidation: true }); });
    nextBtn.addEventListener("click", function () {
        if (!validateStep(current)) {
            var secs = stepSections(current);
            if (secs[0]) { secs[0].classList.remove("shake"); void secs[0].offsetWidth; secs[0].classList.add("shake"); }
            return;
        }
        goTo(current + 1);
    });
    form.addEventListener("keydown", function (ev) {
        if (ev.key !== "Enter" || current === 4) return;
        var t = ev.target; if (!t) return;
        var tag = (t.tagName || "").toUpperCase();
        if (tag === "TEXTAREA" || tag === "BUTTON" || tag === "SELECT") return;
        ev.preventDefault(); nextBtn.click();
    });

    /* progress */
    function updateProgress() {
        var reqs = $all("[required]", form).filter(function (el) { return !el.disabled && el.type !== "hidden" && el.name !== "csrf_token"; });
        var filled = reqs.filter(function (el) {
            if (el.type === "checkbox") return el.checked;
            return (el.value || "").trim() !== "";
        }).length;
        var pct = reqs.length ? Math.round(filled / reqs.length * 100) : 0;
        var dt = shell.querySelector(".draft-text");
        if (dt && !dt.dataset.locked) dt.textContent = pct === 100 ? "Ready to submit" : pct + "% complete";
    }
    form.addEventListener("input", function () { updateProgress(); scheduleSave(); });
    form.addEventListener("change", function () { updateProgress(); scheduleSave(); });

    /* counters */
    [["background", 3000], ["objectives", 3000], ["activity_highlights", 5000], ["key_outputs", 5000], ["impact_significance", 5000], ["quotable_statement", 1000], ["remembrance_sentence", 500]].forEach(function (c) {
        var el = form.querySelector('[name="' + c[0] + '"]');
        if (!el || el.parentElement.querySelector(".char-counter")) return;
        var div = document.createElement("div");
        div.className = "char-counter";
        el.parentElement.appendChild(div);
        function upd() {
            var len = (el.value || "").length;
            div.textContent = len.toLocaleString() + " characters" + (c[0] === "remembrance_sentence" ? " / 500" : "");
            div.classList.toggle("warn", len > c[1] * 0.85 && len <= c[1]);
            div.classList.toggle("over", len > c[1]);
        }
        el.addEventListener("input", upd); upd();
    });

    /* draft */
    var DKEY = "hpu_event_draft_v1";
    var dot = shell.querySelector(".draft-dot"), dtext = shell.querySelector(".draft-text");
    var banner = shell.querySelector(".draft-banner");
    var saveT = null;
    function scheduleSave() {
        if (dot) dot.classList.remove("saved");
        clearTimeout(saveT);
        saveT = setTimeout(function () {
            try {
                var data = {};
                $all("input, select, textarea", form).forEach(function (el) {
                    if (!el.name || el.name === "csrf_token" || el.type === "file" || el.disabled) return;
                    if (el.type === "checkbox") data[el.name + "||" + el.value] = el.checked;
                    else data[el.name] = el.value;
                });
                localStorage.setItem(DKEY, JSON.stringify({ at: Date.now(), data: data }));
                if (dot) dot.classList.add("saved");
                if (dtext) { dtext.textContent = "Draft saved • " + new Date().toLocaleTimeString([], { hour: "numeric", minute: "2-digit" }); dtext.dataset.locked = "1"; setTimeout(function () { delete dtext.dataset.locked; updateProgress(); }, 4000); }
            } catch (e) {}
        }, 800);
    }
    function isPristine() { return !val("title") && !val("background") && !val("objectives"); }
    try {
        var raw = localStorage.getItem(DKEY);
        if (raw && isPristine()) {
            var parsed = JSON.parse(raw);
            if (parsed && parsed.data) {
                banner.innerHTML = '<i class="bi bi-history mt-1"></i><div style="flex:1;"><strong>Unsaved draft found</strong> from ' +
                    esc(new Date(parsed.at).toLocaleString()) + ".</div>" +
                    '<button type="button" class="btn btn-sm btn-primary" data-act="restore">Restore</button>' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="discard">Discard</button>';
                banner.classList.add("show");
                banner.querySelector('[data-act="restore"]').addEventListener("click", function () {
                    Object.keys(parsed.data).forEach(function (k) {
                        if (k.indexOf("||") !== -1) {
                            var p = k.split("||");
                            var box = form.querySelector('input[name="' + p[0] + '"][value="' + p[1] + '"]');
                            if (box) box.checked = !!parsed.data[k];
                        } else {
                            var el = form.querySelector('[name="' + k + '"]');
                            if (el) el.value = parsed.data[k];
                        }
                    });
                    banner.classList.remove("show"); updateProgress(); goTo(1, { instant: true, skipValidation: true });
                });
                banner.querySelector('[data-act="discard"]').addEventListener("click", function () {
                    try { localStorage.removeItem(DKEY); } catch (e) {}
                    banner.classList.remove("show");
                });
            }
        }
    } catch (e) {}
    form.addEventListener("submit", function () { try { localStorage.removeItem(DKEY); } catch (e) {} });

    /* review */
    function row(label, value, full) {
        var empty = !value;
        return '<div class="review-item' + (full ? " full" : "") + '"><small>' + esc(label) + '</small><div class="' + (empty ? "review-empty" : "") + '">' + (empty ? "—" : esc(value)) + "</div></div>";
    }
    function buildReview() {
        var old = form.querySelector(".review-stack");
        if (old) old.remove();
        var stack = document.createElement("div");
        stack.className = "review-stack";
        var h = "";
        h += '<div class="review-card"><div class="review-head"><h5><i class="bi bi-calendar2-event"></i>Activity profile</h5><button type="button" class="review-edit" data-edit="1">Edit</button></div><div class="review-grid">' +
            row("Title", val("title"), true) + row("Type", val("type")) + row("Email", val("email_address")) +
            row("Dates", (val("start_date") || "—") + " → " + (val("end_date") || "—")) + row("Time", val("activity_time")) +
            row("Venue", val("venue"), true) + row("Lead office", val("lead_office")) + row("Partners", val("partner_offices")) +
            row("Person-in-charge", val("person_in_charge")) + row("Participants", val("participant_count")) + "</div></div>";
        h += '<div class="review-card"><div class="review-head"><h5><i class="bi bi-journal-text"></i>Story</h5><button type="button" class="review-edit" data-edit="2">Edit</button></div><div class="review-grid">' +
            row("Background", val("background"), true) + row("Objectives", val("objectives"), true) + row("Highlights", val("activity_highlights"), true) + "</div></div>";
        h += '<div class="review-card"><div class="review-head"><h5><i class="bi bi-graph-up-arrow"></i>Results & voices</h5><button type="button" class="review-edit" data-edit="3">Edit</button></div><div class="review-grid">' +
            row("Key outputs", val("key_outputs"), true) + row("Impact", val("impact_significance"), true) +
            row("Quote", val("quotable_statement"), true) + row("Remembered as", val("remembrance_sentence"), true) + "</div></div>";
        var pc = form.querySelector('[name="privacy_confirmed"]');
        h += '<div class="review-card"><div class="review-head"><h5><i class="bi bi-send-check"></i>Publishing</h5><button type="button" class="review-edit" data-edit="4">Edit</button></div><div class="review-grid">' +
            row("Preferred publication", val("pub_date") || "(as soon as reviewed)") + row("Privacy confirmed", pc && pc.checked ? "Yes" : "No") + "</div></div>";
        stack.innerHTML = h;
        var host = stepSections(4)[0];
        if (host) {
            var ss = host.querySelector(".stack-section");
            if (ss) ss.insertBefore(stack, ss.firstChild);
        }
        $all(".review-edit", stack).forEach(function (b) { b.addEventListener("click", function () { goTo(+b.getAttribute("data-edit")); }); });
    }

    goTo(1, { instant: true, skipValidation: true });
});
</script>

</body>
</html>
