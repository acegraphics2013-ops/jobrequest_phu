<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

requireRole([
    "staff",
    "supervisor",
    "admin"
]);
requireModule($conn, "review_requests");

$role = $_SESSION["role"] ?? "";

/* =========================================================
   CSRF TOKEN AND FLASH MESSAGE
========================================================= */
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];
$statusMessage = $_SESSION["status_message"] ?? null;
unset($_SESSION["status_message"]);

$requestId = filter_input(
    $_SERVER["REQUEST_METHOD"] === "POST"
        ? INPUT_POST
        : INPUT_GET,
    "request_id",
    FILTER_VALIDATE_INT
);

/* Keep compatibility with the existing ?id= URL. */
if (!$requestId && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $requestId = filter_input(
        INPUT_GET,
        "id",
        FILTER_VALIDATE_INT
    );
}

if (!$requestId || $requestId <= 0) {
    die("Invalid request ID.");
}

/* =========================================================
   GET REQUEST
========================================================= */
$stmt = $conn->prepare(
    "SELECT
        jr.*,
        u.email AS requestor_email,
        u.name AS account_name
     FROM job_requests jr
     INNER JOIN users u
        ON u.id = jr.requestor
     WHERE jr.id = ?
     LIMIT 1"
);

$stmt->bind_param(
    "i",
    $requestId
);

$stmt->execute();

$request = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if (!$request) {
    die("Job request not found.");
}

/* =========================================================
   MANUAL STATUS OPTIONS AND DEFAULT NOTIFICATIONS
========================================================= */
$allowedStatuses = [
    "Pending Review",
    "Under Review",
    "Approved",
    "In Development",
    "For Revision",
    "Completed",
    "For Evaluation"
];

$statusNotificationMessages = [
    "Pending Review" =>
        "Your job request {$request["job_no"]} is pending review. Please check and update the Requestor Information and Job Details if necessary.",

    "Under Review" =>
        "Your job request {$request["job_no"]} is currently under review. You will be notified when there is another update.",

    "Approved" =>
        "Your job request {$request["job_no"]} has been approved. Please log in to view the latest request details.",

    "In Development" =>
        "Your job request {$request["job_no"]} is now in development. Work on the requested output has started.",

    "For Revision" =>
        "Your job request {$request["job_no"]} requires revision. Please log in and update the Requestor Information or Job Details as instructed.",

    "Completed" =>
        "Your job request {$request["job_no"]} has been marked as completed. Please log in to review the completed request details.",

    "For Evaluation" =>
        "Your job request {$request["job_no"]} is ready for evaluation. Please complete the Post Project Evaluation."
];

/* Change these paths if your user pages use different filenames. */
$userRequestPath = "/user/edit_request.php";
$userEvaluationPath = "/user/client_experience_survey.php";
$formError = null;

/* =========================================================
   UPDATE STATUS AND NOTIFY REQUESTOR
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submittedToken = (string) (
        $_POST["csrf_token"] ?? ""
    );

    $selectedStatus = trim(
        (string) ($_POST["approval_status"] ?? "")
    );

    $notificationMessage = trim(
        (string) ($_POST["notification_message"] ?? "")
    );

    if (!hash_equals($csrfToken, $submittedToken)) {
        $formError = "Invalid security token. Please refresh the page and try again.";
    } elseif (!in_array($selectedStatus, $allowedStatuses, true)) {
        $formError = "Please select a valid request status.";
    } elseif ($notificationMessage === "") {
        $formError = "Please enter a notification message.";
    } else {
        $notificationTitle =
            "Job Request Status: " . $selectedStatus;

        /* Only For Evaluation directs the user to the evaluation form. */
        $actionPath = $selectedStatus === "For Evaluation"
            ? $userEvaluationPath
            : $userRequestPath;

        $actionUrl = rtrim(BASE_URL, "/") .
            $actionPath .
            "?id=" .
            $requestId;

        /* Select a notification type that works with either VARCHAR
           or common ENUM configurations. */
        $notificationType = "status_update";
        $typeColumnResult = $conn->query(
            "SHOW COLUMNS FROM notifications LIKE 'notification_type'"
        );

        if ($typeColumnResult) {
            $typeColumn = $typeColumnResult->fetch_assoc();

            if (
                $typeColumn &&
                str_starts_with(
                    strtolower((string) $typeColumn["Type"]),
                    "enum("
                )
            ) {
                preg_match_all(
                    "/'((?:[^'\\]|\\.)*)'/",
                    (string) $typeColumn["Type"],
                    $enumMatches
                );

                $enumValues = array_map(
                    static fn ($value) => stripcslashes($value),
                    $enumMatches[1] ?? []
                );

                foreach (
                    [
                        "status_update",
                        "request_update",
                        "general",
                        "evaluation"
                    ] as $candidateType
                ) {
                    if (in_array($candidateType, $enumValues, true)) {
                        $notificationType = $candidateType;
                        break;
                    }
                }

                if (
                    !in_array($notificationType, $enumValues, true) &&
                    !empty($enumValues)
                ) {
                    $notificationType = $enumValues[0];
                }
            }
        }

        try {
            $conn->begin_transaction();

            $updateStmt = $conn->prepare(
                "UPDATE job_requests
                 SET approval_status = ?
                 WHERE id = ?"
            );

            $updateStmt->bind_param(
                "si",
                $selectedStatus,
                $requestId
            );

            $updateStmt->execute();
            $updateStmt->close();

            $insertStmt = $conn->prepare(
                "INSERT INTO notifications (
                    user_id,
                    request_id,
                    title,
                    message,
                    notification_type,
                    action_url,
                    is_read,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())"
            );

            $requestorId = (int) $request["requestor"];

            $insertStmt->bind_param(
                "iissss",
                $requestorId,
                $requestId,
                $notificationTitle,
                $notificationMessage,
                $notificationType,
                $actionUrl
            );

            $insertStmt->execute();
            $insertStmt->close();

            $conn->commit();

            $_SESSION["status_message"] = [
                "type" => "success",
                "text" =>
                    "The request status was updated to " .
                    $selectedStatus .
                    " and the requestor was notified."
            ];

            header(
                "Location: " .
                rtrim(BASE_URL, "/") .
                "/staff/evaluation.php?id=" .
                $requestId
            );

            exit;
        } catch (Throwable $exception) {
            $conn->rollback();
            $formError =
                "The status could not be updated or the notification could not be sent.";
        }
    }
}

/* =========================================================
   REFRESH REQUEST AFTER ANY UPDATE
========================================================= */
$stmt = $conn->prepare(
    "SELECT
        jr.*,
        u.email AS requestor_email,
        u.name AS account_name
     FROM job_requests jr
     INNER JOIN users u
        ON u.id = jr.requestor
     WHERE jr.id = ?
     LIMIT 1"
);

$stmt->bind_param("i", $requestId);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* =========================================================
   CHECK NOTIFICATIONS
========================================================= */
$notificationStmt = $conn->prepare(
    "SELECT
        id,
        title,
        message,
        notification_type,
        action_url,
        is_read,
        created_at
     FROM notifications
     WHERE request_id = ?
        AND user_id = ?
     ORDER BY id DESC"
);

$notificationStmt->bind_param(
    "ii",
    $requestId,
    $request["requestor"]
);

$notificationStmt->execute();

$notifications = $notificationStmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$notificationStmt->close();

/* =========================================================
   CLIENT EXPERIENCE SURVEY (authoritative evaluation source)
========================================================= */
$clientSurvey = null;

$surveyTableCheck = $conn->query(
    "SHOW TABLES LIKE 'job_request_client_experience'"
);

if ($surveyTableCheck && $surveyTableCheck->num_rows > 0) {
    $surveyStmt = $conn->prepare(
        "SELECT *
          FROM job_request_client_experience
          WHERE request_id = ?
          LIMIT 1"
    );

    if ($surveyStmt) {
        $surveyStmt->bind_param("i", $requestId);
        $surveyStmt->execute();
        $clientSurvey = $surveyStmt
            ->get_result()
            ->fetch_assoc() ?: null;
        $surveyStmt->close();
    }
}

/* =========================================================
   DISPLAY HELPERS
========================================================= */
function evFmtDateTime($value, string $fallback = "Not specified"): string
{
    if ($value === null || trim((string) $value) === "") {
        return $fallback;
    }

    $timestamp = strtotime((string) $value);

    if ($timestamp === false) {
        return (string) $value;
    }

    return date("M d, Y g:i A", $timestamp);
}

function evAgo(?string $datetime): string
{
    if (!$datetime) {
        return "";
    }

    try {
        $then = new DateTime($datetime);
        $diff = (new DateTime())->diff($then);

        if ($diff->y > 0) {
            return $diff->y . ($diff->y === 1 ? " yr ago" : " yrs ago");
        }

        if ($diff->m > 0) {
            return $diff->m . ($diff->m === 1 ? " mo ago" : " mos ago");
        }

        if ($diff->d > 0) {
            return $diff->d . ($diff->d === 1 ? " day ago" : " days ago");
        }

        if ($diff->h > 0) {
            return $diff->h . ($diff->h === 1 ? " hr ago" : " hrs ago");
        }

        if ($diff->i > 0) {
            return $diff->i . ($diff->i === 1 ? " min ago" : " mins ago");
        }

        return "just now";
    } catch (Throwable $e) {
        return "";
    }
}

function evRequestTypes($value): array
{
    if (is_array($value)) {
        $list = $value;
    } else {
        $raw = trim((string) ($value ?? ""));

        if ($raw === "") {
            return [];
        }

        $decoded = json_decode($raw, true);
        $list = is_array($decoded)
            ? $decoded
            : (preg_split('/[,|]+/', $raw) ?: []);
    }

    $out = [];

    foreach ($list as $item) {
        $item = trim((string) $item);

        if ($item !== "" && !in_array($item, $out, true)) {
            $out[] = $item;
        }
    }

    return $out;
}

function evTypeClass(string $type): string
{
    return match ($type) {
        "Graphic Design" => "type-graphic",
        "Video Production" => "type-video",
        "Photo/Video Coverage" => "type-coverage",
        "IEC Materials" => "type-iec",
        default => "type-other"
    };
}

function evTypeIcon(string $type): string
{
    return match ($type) {
        "Graphic Design" => "bi-palette-fill",
        "Video Production" => "bi-camera-reels-fill",
        "Photo/Video Coverage" => "bi-camera-fill",
        "IEC Materials" => "bi-file-earmark-richtext",
        default => "bi-tag-fill"
    };
}

function evHistoryIcon(?string $title): string
{
    $title = mb_strtolower((string) ($title ?? ""));

    if (str_contains($title, "evaluat")) {
        return "bi-clipboard2-check";
    }

    if (str_contains($title, "approv")) {
        return "bi-patch-check";
    }

    if (str_contains($title, "revision")) {
        return "bi-arrow-repeat";
    }

    if (str_contains($title, "disapprov")) {
        return "bi-x-circle";
    }

    if (str_contains($title, "complet")) {
        return "bi-check2-all";
    }

    return "bi-bell";
}

$evWorkflowStages = [
    ["label" => "Submitted", "icon" => "bi-send-check"],
    ["label" => "Review", "icon" => "bi-search"],
    ["label" => "Approved", "icon" => "bi-patch-check"],
    ["label" => "Development", "icon" => "bi-tools"],
    ["label" => "Completed", "icon" => "bi-check2-all"],
    ["label" => "Evaluation", "icon" => "bi-clipboard2-check"],
    ["label" => "Closed", "icon" => "bi-lock"],
];

$evWorkflowIndex = match ($request["approval_status"] ?? "") {
    "Pending Review" => 0,
    "Under Review", "For Revision" => 1,
    "Approved" => 2,
    "In Development" => 3,
    "Completed" => 4,
    "For Evaluation" => 5,
    "Closed" => 6,
    default => 0
};

$evRequestTypeList = evRequestTypes($request["request_types"] ?? null);

$evSurveyCriteria = [
    "timeliness" => "Timeliness",
    "responsiveness" => "Responsiveness",
    "quality_output" => "Quality of Output",
    "professionalism" => "Professionalism",
    "communication" => "Communication",
    "service_met_needs" => "Service Met Needs",
];

/* =========================================================
   EVALUATION STATUS
========================================================= */
$evaluationSubmitted =
    $request["satisfaction_rating"] !== null;

$canNotify = in_array(
    $role,
    [
        "staff",
        "supervisor",
        "admin"
    ],
    true
);

$defaultNotificationMessage =
    $statusNotificationMessages[
        $request["approval_status"]
    ] ??
    "There is an update regarding your job request {$request["job_no"]}. Please log in to view the latest details.";

$statusClass = match (
    $request["approval_status"]
) {
    "Pending Review" =>
        "bg-secondary",

    "Under Review" =>
        "bg-info text-dark",

    "Approved" =>
        "bg-success",

    "In Development" =>
        "bg-primary",

    "For Revision" =>
        "bg-warning text-dark",

    "Completed" =>
        "bg-dark",

    "For Evaluation" =>
        "bg-warning text-dark",

    "Closed" =>
        "bg-success",

    default =>
        "bg-secondary"
};
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Request Status and Notification |
        <?= e($request["job_no"]) ?>
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="<?= e(BASE_URL) ?>/assets/css/style.css"
    >

    <link
        rel="stylesheet"
        href="<?= e(BASE_URL) ?>/assets/css/page_headers.css"
    >

    <style>
        .ph--eval::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: linear-gradient(180deg, #2563eb, #7c3aed 55%, #0ea5e9);
        }

        .ph-icon--eval {
            background: linear-gradient(135deg, #2563eb 0%, #4f46e5 55%, #7c3aed 100%);
            color: #fff;
            border: 0;
            box-shadow: 0 8px 18px rgba(37, 99, 235, .28), inset 0 1px 0 rgba(255, 255, 255, .35);
        }

        .job-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 11px;
            border: 1px dashed #93c5fd;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .03em;
            white-space: nowrap;
        }

        .detail-box {
            height: 100%;
            padding: 16px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #f8fafc;
        }

        .detail-label {
            margin-bottom: 5px;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .timeline-item {
            position: relative;
            padding: 0 0 20px 30px;
            border-left: 2px solid #dbe3ef;
        }

        .timeline-item::before {
            position: absolute;
            top: 0;
            left: -7px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #2563eb;
            content: "";
        }

        .timeline-item:last-child {
            padding-bottom: 0;
            border-left-color: transparent;
        }

        .type-row {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .type-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: .62rem;
            font-weight: 850;
            white-space: nowrap;
        }

        .type-badge i {
            font-size: .64rem;
        }

        .type-graphic { background: #e4edff; color: #1e54ba; }
        .type-video { background: #f1ebff; color: #5d36ad; }
        .type-coverage { background: #fff0d9; color: #8a4d04; }
        .type-iec { background: #e1f7ea; color: #126b43; }
        .type-other { background: #eef1f5; color: #566174; }

        .mini-steps {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 2px;
            margin-top: 12px;
            overflow-x: auto;
            padding-bottom: 2px;
        }

        .mini-step {
            min-width: 74px;
            text-align: center;
            position: relative;
            padding-top: 2px;
        }

        .mini-step::before {
            content: "";
            position: absolute;
            top: 11px;
            left: -50%;
            width: 100%;
            height: 2px;
            background: #dbe3ef;
        }

        .mini-step:first-child::before {
            display: none;
        }

        .mini-step.done::before {
            background: #16a34a;
        }

        .mini-dot {
            position: relative;
            z-index: 1;
            width: 20px;
            height: 20px;
            margin: 0 auto 4px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: #fff;
            border: 2px solid #cbd5e1;
            color: #94a3b8;
            font-size: 9px;
        }

        .mini-step.done .mini-dot {
            border-color: #16a34a;
            background: #16a34a;
            color: #fff;
        }

        .mini-step.now .mini-dot {
            border-color: #2563eb;
            background: #2563eb;
            color: #fff;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, .15);
        }

        .mini-step span {
            display: block;
            font-size: .58rem;
            font-weight: 750;
            color: #64748b;
            line-height: 1.25;
        }

        .mini-step.now span {
            color: #1d4ed8;
        }

        .char-counter {
            margin-top: 4px;
            color: #94a3b8;
            font-size: .68rem;
            font-weight: 650;
            text-align: right;
        }

        .history-filters {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .history-chip {
            border: 1px solid #dbe3ef;
            background: #fff;
            border-radius: 999px;
            padding: 4px 11px;
            font-size: .68rem;
            font-weight: 750;
            color: #475569;
            cursor: pointer;
        }

        .history-chip.active {
            border-color: #2563eb;
            background: #eff6ff;
            color: #1d4ed8;
        }

        .history-empty {
            padding: 30px 16px;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            color: #64748b;
            text-align: center;
        }

        .history-empty i {
            display: block;
            margin-bottom: 8px;
            font-size: 1.6rem;
            color: #94a3b8;
        }

        .rating-bar {
            height: 8px;
            border-radius: 999px;
            background: #e9eef5;
            overflow: hidden;
            margin-top: 6px;
        }

        .rating-bar span {
            display: block;
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, #2563eb, #7c3aed);
        }

        .confirm-flow {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
            padding: 12px;
            border: 1px dashed #cfe0f8;
            border-radius: 12px;
            background: #f8fbff;
            margin-bottom: 14px;
        }

        .confirm-flow .arrow {
            color: #94a3b8;
        }

        .confirm-message-preview {
            max-height: 220px;
            overflow-y: auto;
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #f8fafc;
            font-size: .82rem;
            white-space: pre-line;
        }

        @media (max-width: 640px) {
            .mini-steps {
                grid-template-columns: repeat(7, minmax(74px, 1fr));
            }
        }
    </style>

</head>

<body class="app-shell">

<div class="d-flex min-vh-100">

    <?php include "../includes/sidebar.php"; ?>

    <div class="main flex-grow-1">

        <main class="content">

            <div class="container-fluid">

                <nav class="rm-crumb" aria-label="Breadcrumb" style="display:flex;align-items:center;flex-wrap:wrap;gap:7px;margin:0 0 10px 2px;color:#94a3b8;font-size:.7rem;font-weight:650;">
                    <a href="<?= e(BASE_URL) ?>/dashboard.php" style="display:inline-flex;align-items:center;gap:5px;color:#64748b;text-decoration:none;font-weight:750;"><i class="bi bi-house-door"></i> Dashboard</a>
                    <span style="color:#cbd5e1;">/</span>
                    <a href="<?= e(BASE_URL) ?>/staff/requests.php" style="display:inline-flex;align-items:center;gap:5px;color:#64748b;text-decoration:none;font-weight:750;">Job Requests</a>
                    <span style="color:#cbd5e1;">/</span>
                    <span aria-current="page" style="color:#0f172a;font-weight:800;">Status &amp; Notification</span>
                </nav>

                <!-- HEADER -->
                <header class="ph ph--eval mb-4">

                    <div class="ph-copy">

                        <div class="ph-icon ph-icon--eval" aria-hidden="true">
                            <i class="bi bi-bell-fill"></i>
                        </div>

                        <div class="min-w-0">
                            <p class="ph-eyebrow">HPU Workbench &bull; Status &amp; Notification</p>

                            <h1 class="ph-title">
                                <?= e($request["project_title"] ?: "Untitled Project") ?>
                            </h1>

                            <p class="ph-subtitle">
                                <span class="job-chip">
                                    <i class="bi bi-hash"></i><?= e($request["job_no"]) ?>
                                </span>
                                <span class="badge <?= $statusClass ?> ms-1">
                                    <?= e($request["approval_status"]) ?>
                                </span>
                                <span class="d-block mt-1">
                                    <?= e($request["requestor_name"]) ?>
                                    &middot; <?= e($request["department"]) ?>
                                </span>
                            </p>

                            <?php if ($evRequestTypeList): ?>
                                <div class="type-row" aria-label="Type of request">
                                    <?php foreach ($evRequestTypeList as $evType): ?>
                                        <span class="type-badge <?= e(evTypeClass($evType)) ?>">
                                            <i class="bi <?= e(evTypeIcon($evType)) ?>"></i><?= e($evType) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="mini-steps" aria-label="Workflow progress">
                                <?php foreach ($evWorkflowStages as $evStageIndex => $evStage): ?>
                                    <div class="mini-step <?= $evStageIndex < $evWorkflowIndex ? "done" : ($evStageIndex === $evWorkflowIndex ? "now" : "") ?>">
                                        <div class="mini-dot">
                                            <i class="bi <?= e($evStage["icon"]) ?>"></i>
                                        </div>
                                        <span><?= e($evStage["label"]) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    </div>

                    <div class="ph-actions">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/admin/view.php?id=<?= (int) $requestId ?>" title="Open the full request record">
                            <i class="bi bi-eye me-1"></i> Full Record
                        </a>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/staff/review_request.php?id=<?= (int) $requestId ?>" title="Open staff review and approval">
                            <i class="bi bi-clipboard-check me-1"></i> Review
                        </a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(BASE_URL) ?>/user/print_request.php?id=<?= (int) $requestId ?>" target="_blank" rel="noopener" title="Print-ready request form">
                            <i class="bi bi-printer me-1"></i> Print
                        </a>
                    </div>

                </header>

                <?php if ($statusMessage): ?>

                    <div
                        class="alert alert-<?= e(
                            $statusMessage["type"] ?? "success"
                        ) ?> alert-dismissible fade show"
                        role="alert"
                    >
                        <?= e($statusMessage["text"] ?? "") ?>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="alert"
                        ></button>
                    </div>

                <?php endif; ?>

                <?php if ($formError): ?>

                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-circle me-1"></i>
                        <?= e($formError) ?>
                    </div>

                <?php endif; ?>

                <!-- REQUEST INFORMATION -->
                <section class="card mb-4">

                    <div class="card-body">

                        <div class="sh mb-3">
                            <div class="sh-icon"><i class="bi bi-info-circle"></i></div>
                            <div>
                                <h4 class="sh-title">Request Information</h4>
                                <p class="sh-subtitle">Who filed it, key dates, and evaluation state at a glance.</p>
                            </div>
                        </div>

                        <div class="row g-3">

                            <div class="col-md-6">

                                <div class="detail-box">

                                    <div class="detail-label">
                                        Project
                                    </div>

                                    <div class="fw-semibold">
                                        <?= e(
                                            $request["project_title"]
                                        ) ?>
                                    </div>

                                </div>

                            </div>

                            <div class="col-md-6">

                                <div class="detail-box">

                                    <div class="detail-label">
                                        Requestor
                                    </div>

                                    <div class="fw-semibold">
                                        <i class="bi bi-person me-1 text-muted"></i><?= e(
                                            $request[
                                                "requestor_name"
                                            ]
                                        ) ?>
                                    </div>

                                    <?php if (trim((string) ($request["requestor_email"] ?? "")) !== ""): ?>
                                        <small>
                                            <i class="bi bi-envelope me-1 text-muted"></i><a href="mailto:<?= e($request["requestor_email"]) ?>"><?= e(
                                                $request[
                                                    "requestor_email"
                                                ]
                                            ) ?></a>
                                        </small>
                                    <?php else: ?>
                                        <small class="text-muted">No email on file</small>
                                    <?php endif; ?>

                                </div>

                            </div>

                            <div class="col-md-4">

                                <div class="detail-box">

                                    <div class="detail-label">
                                        Department
                                    </div>

                                    <i class="bi bi-building me-1 text-muted"></i><?= e(
                                        $request["department"]
                                    ) ?>

                                </div>

                            </div>

                            <div class="col-md-4">

                                <div class="detail-box">

                                    <div class="detail-label">
                                        Completion Date
                                    </div>

                                    <i class="bi bi-calendar-event me-1 text-muted"></i><?= e(
                                        evFmtDateTime(
                                            $request[
                                                "final_approval_date"
                                            ]
                                            ?: $request[
                                                "date_completion"
                                            ]
                                            ?: null,
                                            "Not specified"
                                        )
                                    ) ?>

                                </div>

                            </div>

                            <div class="col-md-4">

                                <div class="detail-box">

                                    <div class="detail-label">
                                        Evaluation Status
                                    </div>

                                    <?php if (
                                        $evaluationSubmitted
                                        || $clientSurvey
                                    ): ?>

                                        <span class="badge bg-success">
                                            Submitted
                                        </span>

                                    <?php elseif (
                                        $request[
                                            "approval_status"
                                        ] === "For Evaluation"
                                    ): ?>

                                        <span
                                            class="badge bg-warning text-dark"
                                        >
                                            Waiting for Requestor
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-secondary">
                                            Not Yet Requested
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>

                            <div class="col-md-6">

                                <div class="detail-box">

                                    <div class="detail-label">
                                        Reviewed By
                                    </div>

                                    <div class="fw-semibold">
                                        <?= e(
                                            $request["reviewed_by"]
                                            ?: "Not yet reviewed"
                                        ) ?>
                                    </div>

                                    <small class="text-muted">
                                        <?= e(
                                            evFmtDateTime(
                                                $request["reviewed_date"] ?? null,
                                                "No review date"
                                            )
                                        ) ?>
                                    </small>

                                </div>

                            </div>

                            <div class="col-md-6">

                                <div class="detail-box">

                                    <div class="detail-label">
                                        Approved By
                                    </div>

                                    <div class="fw-semibold">
                                        <?= e(
                                            $request["approved_by"]
                                            ?: "Not yet approved"
                                        ) ?>
                                    </div>

                                    <small class="text-muted">
                                        <?= e(
                                            evFmtDateTime(
                                                $request["approved_date"] ?? null,
                                                "No approval date"
                                            )
                                        ) ?>
                                    </small>

                                </div>

                            </div>

                        </div>

                    </div>

                </section>

                <!-- STATUS UPDATE AND NOTIFICATION ACTION -->
                <section class="card mb-4">

                    <div class="card-body">

                        <div class="sh mb-3">
                            <div class="sh-icon"><i class="bi bi-bell"></i></div>
                            <div>
                                <h4 class="sh-title">Update Status and Notify Requestor</h4>
                                <p class="sh-subtitle">A confirmation preview appears before anything is sent.</p>
                            </div>
                        </div>

                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            You may freely select any status below. The user
                            can open the evaluation form only when the request
                            is marked <strong>For Evaluation</strong>.
                        </div>

                        <?php if ($evaluationSubmitted || $clientSurvey): ?>

                            <div class="alert alert-success">
                                <i class="bi bi-check-circle me-2"></i>
                                The requestor has already submitted the
                                evaluation. You may still update
                                the status and send another notification.
                            </div>

                            <?php if ($clientSurvey): ?>
                                <a
                                    class="btn btn-success mb-3"
                                    href="<?= e(BASE_URL) ?>/user/client_experience_survey.php?id=<?= (int) $requestId ?>"
                                >
                                    <i class="bi bi-eye me-1"></i>
                                    View Evaluation Result
                                </a>
                            <?php else: ?>
                                <a
                                    class="btn btn-success mb-3"
                                    href="<?= e(BASE_URL) ?>/admin/view.php?id=<?= (int) $requestId ?>"
                                >
                                    <i class="bi bi-eye me-1"></i>
                                    View Evaluation Result
                                </a>
                            <?php endif; ?>

                        <?php endif; ?>

                        <?php if (!$canNotify): ?>

                            <div class="alert alert-warning">
                                You do not have permission to update the
                                status or notify the requestor.
                            </div>

                        <?php else: ?>

                            <form
                                id="notifyForm"
                                method="POST"
                                action="<?= e(BASE_URL) ?>/staff/evaluation.php?id=<?= (int) $requestId ?>"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrfToken) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="request_id"
                                    value="<?= (int) $requestId ?>"
                                >

                                <div class="row g-3">

                                    <div class="col-lg-4">

                                        <label
                                            class="form-label"
                                            for="approval_status"
                                        >
                                            New Request Status
                                        </label>

                                        <select
                                            class="form-select"
                                            id="approval_status"
                                            name="approval_status"
                                            required
                                        >
                                            <?php foreach ($allowedStatuses as $availableStatus): ?>

                                                <option
                                                    value="<?= e($availableStatus) ?>"
                                                    <?= $request["approval_status"] === $availableStatus
                                                        ? "selected"
                                                        : ""
                                                    ?>
                                                >
                                                    <?= e($availableStatus) ?>
                                                    <?= $availableStatus === "For Evaluation"
                                                        ? " — enables evaluation"
                                                        : ""
                                                    ?>
                                                </option>

                                            <?php endforeach; ?>
                                        </select>

                                        <div class="form-text">
                                            Statuses may be selected in any order.
                                        </div>

                                    </div>

                                    <div class="col-lg-8">

                                        <label
                                            class="form-label"
                                            for="notification_message"
                                        >
                                            Notification Message
                                        </label>

                                        <textarea
                                            class="form-control"
                                            id="notification_message"
                                            name="notification_message"
                                            rows="5"
                                            maxlength="2000"
                                            required
                                        ><?= e($defaultNotificationMessage) ?></textarea>

                                        <div class="char-counter" id="messageCounter" aria-live="polite"></div>

                                        <div class="form-text">
                                            The suggested message changes when
                                            you select a different status, and
                                            you may edit it before sending.
                                        </div>

                                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2 d-none" id="resetTemplateBtn">
                                            <i class="bi bi-arrow-counterclockwise me-1"></i>
                                            Reset to suggested message
                                        </button>

                                    </div>

                                </div>

                                <div class="mt-3">
                                    <button
                                        type="submit"
                                        class="btn btn-primary"
                                    >
                                        <i class="bi bi-bell me-1"></i>
                                        Update Status and Notify Requestor
                                    </button>
                                </div>

                            </form>

                        <?php endif; ?>

                    </div>

                </section>

                <!-- NOTIFICATION HISTORY -->
                <section class="card mb-4">

                    <div class="card-body">

                        <div class="sh mb-3">
                            <div class="sh-icon"><i class="bi bi-clock-history"></i></div>
                            <div class="flex-grow-1">
                                <h4 class="sh-title">Notification History
                                    <span class="badge bg-primary ms-1"><?= count($notifications) ?></span>
                                </h4>
                                <p class="sh-subtitle">Every status message sent to the requestor for this request.</p>
                            </div>
                        </div>

                        <?php if (
                            empty($notifications)
                        ): ?>

                            <div class="history-empty">
                                <i class="bi bi-bell-slash"></i>
                                <strong class="d-block text-dark mb-1">No notifications yet</strong>
                                Status updates sent from the form above will appear here.
                            </div>

                        <?php else: ?>

                            <?php
                            $historyUnread = 0;
                            foreach ($notifications as $historyRow) {
                                if (empty($historyRow["is_read"])) {
                                    $historyUnread++;
                                }
                            }
                            ?>

                            <div class="history-filters mb-3" role="group" aria-label="Filter notification history">
                                <button type="button" class="history-chip active" data-history-filter="all">All (<?= count($notifications) ?>)</button>
                                <button type="button" class="history-chip" data-history-filter="unread">Unread (<?= $historyUnread ?>)</button>
                                <button type="button" class="history-chip" data-history-filter="read">Read (<?= count($notifications) - $historyUnread ?>)</button>
                            </div>

                            <div id="historyList">
                            <?php foreach (
                                $notifications as $notification
                            ): ?>

                                <div class="timeline-item" data-history-read="<?= !empty($notification["is_read"]) ? "1" : "0" ?>">

                                    <div
                                        class="d-flex justify-content-between gap-3"
                                    >

                                        <div class="min-w-0">

                                            <strong>
                                                <i class="bi <?= e(evHistoryIcon($notification["title"] ?? "")) ?> me-1 text-primary"></i><?= e(
                                                    $notification[
                                                        "title"
                                                    ]
                                                ) ?>
                                            </strong>

                                            <p class="mb-1">
                                                <?= nl2br(
                                                    e(
                                                        $notification[
                                                            "message"
                                                        ]
                                                    )
                                                ) ?>
                                            </p>

                                            <small class="text-muted" title="<?= e(evFmtDateTime($notification["created_at"] ?? null, "")) ?>">
                                                <i class="bi bi-calendar3 me-1"></i><?= e(
                                                    evFmtDateTime(
                                                        $notification[
                                                            "created_at"
                                                        ] ?? null,
                                                        "Unknown date"
                                                    )
                                                ) ?>
                                                <?php $historyAgo = evAgo($notification["created_at"] ?? null); ?>
                                                <?php if ($historyAgo !== ""): ?>
                                                    &middot; <?= e($historyAgo) ?>
                                                <?php endif; ?>
                                            </small>

                                        </div>

                                        <div class="flex-shrink-0">

                                            <?php if (
                                                $notification[
                                                    "is_read"
                                                ]
                                            ): ?>

                                                <span
                                                    class="badge bg-success"
                                                >
                                                    Read
                                                </span>

                                            <?php else: ?>

                                                <span
                                                    class="badge bg-warning text-dark"
                                                >
                                                    Unread
                                                </span>

                                            <?php endif; ?>

                                        </div>

                                    </div>

                                </div>

                            <?php endforeach; ?>
                            </div>

                            <div class="history-empty d-none" id="historyNoMatch">
                                <i class="bi bi-funnel"></i>
                                <strong class="d-block text-dark mb-1">Nothing in this view</strong>
                                Try a different filter.
                            </div>

                        <?php endif; ?>

                    </div>

                </section>

                <!-- EVALUATION RESULTS -->
                <?php if ($evaluationSubmitted || $clientSurvey): ?>

                    <section class="card mb-4">

                        <div class="card-body">

                            <div class="sh mb-3">
                                <div class="sh-icon"><i class="bi bi-star"></i></div>
                                <div>
                                    <h4 class="sh-title">Evaluation Results</h4>
                                    <p class="sh-subtitle">
                                        <?php if ($clientSurvey): ?>
                                            Client Experience Survey submitted <?= e(evFmtDateTime($clientSurvey["submitted_at"] ?? null, "")) ?>.
                                        <?php else: ?>
                                            Legacy post-project evaluation.
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>

                            <?php if ($clientSurvey): ?>

                                <?php $evOverall = (int) ($clientSurvey["overall_satisfaction"] ?? 0); ?>

                                <div class="detail-box mb-3 d-flex align-items-center gap-3 flex-wrap">
                                    <div>
                                        <div class="detail-label">Overall Satisfaction</div>
                                        <div class="fw-bold" style="font-size:1.6rem;line-height:1;">
                                            <?= $evOverall > 0 ? $evOverall . " <small class='text-muted fw-normal'>/ 5</small>" : "Not rated" ?>
                                        </div>
                                        <?php if ($evOverall > 0): ?>
                                            <div style="color:#f59e0b;letter-spacing:2px;" aria-label="<?= $evOverall ?> out of 5 stars">
                                                <?php for ($star = 1; $star <= 5; $star++): ?><i class="bi <?= $star <= $evOverall ? "bi-star-fill" : "bi-star" ?>"></i><?php endfor; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ms-auto text-end">
                                        <div class="detail-label">Outcome</div>
                                        <?php $evOutcome = trim((string) ($clientSurvey["outcome_assessment"] ?? "")); ?>
                                        <span class="badge <?= $evOutcome === "Yes" ? "bg-success" : ($evOutcome === "Partially" ? "bg-warning text-dark" : "bg-secondary") ?>">
                                            <?= e($evOutcome !== "" ? $evOutcome : "Not stated") ?>
                                        </span>
                                        <div class="mt-1"><small class="text-muted">
                                            <?= e(trim((string) ($clientSurvey["department_visited"] ?? "")) !== "" ? (string) $clientSurvey["department_visited"] : "") ?>
                                            <?= !empty($clientSurvey["date_of_visit"]) ? "&middot; visited " . e(date("M d, Y", strtotime((string) $clientSurvey["date_of_visit"]))) : "" ?>
                                        </small></div>
                                    </div>
                                </div>

                                <div class="row g-3 mb-3">
                                    <?php foreach ($evSurveyCriteria as $evField => $evLabel): ?>
                                        <?php $evScore = (int) ($clientSurvey[$evField] ?? 0); ?>
                                        <div class="col-md-6 col-lg-4">
                                            <div class="detail-box">
                                                <div class="detail-label"><?= e($evLabel) ?></div>
                                                <div class="fw-bold"><?= $evScore > 0 ? $evScore . " / 5" : "Not rated" ?></div>
                                                <?php if ($evScore > 0): ?>
                                                    <div class="rating-bar" aria-hidden="true"><span style="width:<?= $evScore * 20 ?>%"></span></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="row g-3">

                                    <?php if (trim((string) ($clientSurvey["phu_did_well"] ?? "")) !== ""): ?>
                                        <div class="col-md-6">
                                            <div class="detail-box">
                                                <div class="detail-label">What HPU Did Well</div>
                                                <?= nl2br(e((string) $clientSurvey["phu_did_well"])) ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (trim((string) ($clientSurvey["phu_improve"] ?? "")) !== ""): ?>
                                        <div class="col-md-6">
                                            <div class="detail-box">
                                                <div class="detail-label">To Improve</div>
                                                <?= nl2br(e((string) $clientSurvey["phu_improve"])) ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                </div>

                            <?php endif; ?>

                            <?php if ($evaluationSubmitted): ?>

                            <?php if ($clientSurvey): ?>
                                <hr class="my-4">
                                <p class="text-muted mb-3" style="font-size:.76rem;font-weight:750;">Legacy evaluation (kept for record)</p>
                            <?php endif; ?>

                            <div class="row g-3">

                                <div class="col-md-6">

                                    <div class="detail-box">

                                        <div class="detail-label">
                                            Delivered On Time
                                        </div>

                                        <?= $request[
                                            "delivered_on_time"
                                        ]
                                            ? "Yes"
                                            : "No"
                                        ?>

                                    </div>

                                </div>

                                <div class="col-md-6">

                                    <div class="detail-box">

                                        <div class="detail-label">
                                            Objectives Met
                                        </div>

                                        <?= $request[
                                            "objectives_met"
                                        ]
                                            ? "Yes"
                                            : "No"
                                        ?>

                                    </div>

                                </div>

                                <div class="col-md-6">

                                    <div class="detail-box">

                                        <div class="detail-label">
                                            Challenges Encountered
                                        </div>

                                        <?= $request[
                                            "challenges_encountered"
                                        ]
                                            ? "Yes"
                                            : "No"
                                        ?>

                                    </div>

                                </div>

                                <div class="col-md-6">

                                    <div class="detail-box">

                                        <div class="detail-label">
                                            Satisfaction Rating
                                        </div>

                                        <strong>
                                            <?= (int) $request[
                                                "satisfaction_rating"
                                            ] ?>
                                            / 5
                                        </strong>

                                    </div>

                                </div>

                                <?php if (
                                    !empty(
                                        $request[
                                            "challenges_details"
                                        ]
                                    )
                                ): ?>

                                    <div class="col-12">

                                        <div class="detail-box">

                                            <div class="detail-label">
                                                Challenge Details
                                            </div>

                                            <?= nl2br(
                                                e(
                                                    $request[
                                                        "challenges_details"
                                                    ]
                                                )
                                            ) ?>

                                        </div>

                                    </div>

                                <?php endif; ?>

                                <div class="col-12">

                                    <div class="detail-box">

                                        <div class="detail-label">
                                            Suggestions for Improvement
                                        </div>

                                        <?= nl2br(
                                            e(
                                                $request[
                                                    "improvement_suggestions"
                                                ]
                                                ?: "N/A"
                                            )
                                        ) ?>

                                    </div>

                                </div>

                            </div>

                            <?php endif; ?>

                        </div>

                    </section>

                <?php endif; ?>

                <div class="mb-4">

                    <a
                        class="btn btn-outline-secondary"
                        href="<?= e(BASE_URL) ?>/staff/requests.php"
                    >
                        <i class="bi bi-arrow-left me-1"></i>
                        Back to Requests
                    </a>

                </div>

            </div>

        </main>

    </div>

</div>

<!-- CONFIRM NOTIFICATION MODAL (direct child of body: avoids the .content
     entrance-animation stacking trap that renders Bootstrap modals black) -->
<div class="modal fade" id="confirmNotifyModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="confirmNotifyTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-2">
                    <span class="d-grid border rounded" style="width:42px;height:42px;place-items:center;background:#eef4ff;color:#2563eb;font-size:19px;border-radius:11px;"><i class="bi bi-bell"></i></span>
                    <div>
                        <h5 class="modal-title" id="confirmNotifyTitle">Confirm Status Update</h5>
                        <small class="text-muted">Review before the requestor is notified.</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="confirm-flow">
                    <span class="badge <?= $statusClass ?>"><?= e($request["approval_status"]) ?></span>
                    <i class="bi bi-arrow-right arrow"></i>
                    <span class="badge bg-primary" id="confirmNewStatus">—</span>
                </div>
                <div class="d-flex align-items-center gap-2 mb-2 text-muted" style="font-size:.78rem;">
                    <i class="bi bi-person"></i>
                    <span>To: <strong class="text-dark"><?= e($request["requestor_name"]) ?></strong>
                        <?php if (trim((string) ($request["requestor_email"] ?? "")) !== ""): ?>
                            &lt;<?= e($request["requestor_email"]) ?>&gt;
                        <?php endif; ?>
                    </span>
                </div>
                <div class="detail-label mb-1">Message preview</div>
                <div class="confirm-message-preview" id="confirmMessagePreview"></div>
                <p class="mb-0 mt-2 text-muted" style="font-size:.76rem;">
                    <i class="bi bi-info-circle me-1"></i>The status changes and the notification is sent immediately. This cannot be undone from here.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep editing</button>
                <button type="button" class="btn btn-primary" id="confirmNotifyBtn">
                    <i class="bi bi-bell me-1"></i> Send Notification
                </button>
            </div>
        </div>
    </div>
</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
></script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("notifyForm");
    const statusSelect = document.getElementById("approval_status");
    const messageField = document.getElementById("notification_message");
    const counter = document.getElementById("messageCounter");
    const resetBtn = document.getElementById("resetTemplateBtn");
    const confirmModalEl = document.getElementById("confirmNotifyModal");
    const confirmNewStatus = document.getElementById("confirmNewStatus");
    const confirmPreview = document.getElementById("confirmMessagePreview");
    const confirmBtn = document.getElementById("confirmNotifyBtn");
    const statusMessages = <?= json_encode(
        $statusNotificationMessages,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    ) ?>;

    if (!form || !statusSelect || !messageField) {
        return;
    }

    const templateValues = Object.values(statusMessages).map(function (v) {
        return v.trim();
    });

    function isTemplateText(value) {
        return templateValues.indexOf((value || "").trim()) !== -1;
    }

    function updateCounter() {
        if (!counter) {
            return;
        }

        const len = messageField.value.length;
        counter.textContent = len + " / 2000 characters";
        counter.style.color = len > 1000 ? "#b45309" : "";
    }

    function updateResetVisibility() {
        if (!resetBtn) {
            return;
        }

        resetBtn.classList.toggle("d-none", isTemplateText(messageField.value));
    }

    /* Only autofill when the user has not written a custom message. */
    statusSelect.addEventListener("change", function () {
        const suggestedMessage = statusMessages[this.value];

        if (suggestedMessage && isTemplateText(messageField.value)) {
            messageField.value = suggestedMessage;
        }

        updateCounter();
        updateResetVisibility();
    });

    messageField.addEventListener("input", function () {
        updateCounter();
        updateResetVisibility();
    });

    if (resetBtn) {
        resetBtn.addEventListener("click", function () {
            const suggestedMessage = statusMessages[statusSelect.value];

            if (suggestedMessage) {
                messageField.value = suggestedMessage;
            }

            updateCounter();
            updateResetVisibility();
            messageField.focus();
        });
    }

    updateCounter();
    updateResetVisibility();

    /* Notification history filters. */
    const historyChips = Array.from(document.querySelectorAll("[data-history-filter]"));
    const historyItems = Array.from(document.querySelectorAll("#historyList .timeline-item"));
    const historyNoMatch = document.getElementById("historyNoMatch");

    if (historyChips.length && historyItems.length) {
        historyChips.forEach(function (chip) {
            chip.addEventListener("click", function () {
                historyChips.forEach(function (c) {
                    c.classList.remove("active");
                });
                chip.classList.add("active");

                const mode = chip.getAttribute("data-history-filter");
                let visible = 0;

                historyItems.forEach(function (item) {
                    const isRead = item.getAttribute("data-history-read") === "1";
                    const show = mode === "all"
                        || (mode === "read" && isRead)
                        || (mode === "unread" && !isRead);

                    item.style.display = show ? "" : "none";

                    if (show) {
                        visible++;
                    }
                });

                if (historyNoMatch) {
                    historyNoMatch.classList.toggle("d-none", visible > 0);
                }
            });
        });
    }

    /* Always confirm before anything is sent to the requestor. */
    let confirmed = false;

    form.addEventListener("submit", function (e) {
        if (confirmed) {
            return;
        }

        e.preventDefault();

        if (!statusSelect.value) {
            statusSelect.focus();
            return;
        }

        if (messageField.value.trim() === "") {
            messageField.focus();
            return;
        }

        if (confirmNewStatus) {
            confirmNewStatus.textContent = statusSelect.value;
        }

        if (confirmPreview) {
            confirmPreview.textContent = messageField.value.trim();
        }

        if (confirmModalEl && window.bootstrap) {
            window.bootstrap.Modal.getOrCreateInstance(confirmModalEl).show();
        } else {
            confirmed = true;
            form.submit();
        }
    });

    if (confirmBtn) {
        confirmBtn.addEventListener("click", function () {
            confirmed = true;
            form.submit();
        });
    }
});
</script>

</body>
</html>