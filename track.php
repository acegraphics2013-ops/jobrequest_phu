<?php

include "includes/config.php";
include "includes/helpers.php";

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

/* =========================================================
   TRACKING TOKEN
========================================================= */
$token = trim(
    (string) ($_GET["token"] ?? "")
);

if (
    strlen($token) !== 64 ||
    !ctype_xdigit($token)
) {
    http_response_code(400);

    die(
        "Invalid tracking token."
    );
}

/* =========================================================
   PUBLICATION TOKEN INFRASTRUCTURE (auto-migrate)
   Allows the same track.php?token= link to resolve:
   - job_requests
   - publication_event_submissions (Event / Activity)
   - publication_recognition_submissions (Recognition / Milestone)
   - publication_content_contributions (Content Contribution)
========================================================= */
function trackTableExists(
    mysqli $conn,
    string $table
): bool {
    try {
        $safe = $conn->real_escape_string($table);
        $check = $conn->query(
            "SHOW TABLES LIKE '{$safe}'"
        );

        return $check && $check->num_rows > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function trackColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?"
        );

        $stmt->bind_param("ss", $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row["total"] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function trackEnsurePubTokenColumn(
    mysqli $conn,
    string $table
): void {
    if (!trackTableExists($conn, $table)) {
        return;
    }

    if (trackColumnExists($conn, $table, "public_token")) {
        return;
    }

    try {
        $conn->query(
            "ALTER TABLE `{$table}`
             ADD COLUMN `public_token` CHAR(64) NULL,
             ADD UNIQUE INDEX `uniq_{$table}_public_token` (`public_token`)"
        );
    } catch (Throwable $e) {
        // Unique index may already exist under another name, or DDL
        // may be restricted. Fall back to a plain column so tracking
        // lookups still work.
        try {
            $conn->query(
                "ALTER TABLE `{$table}`
                 ADD COLUMN `public_token` CHAR(64) NULL"
            );
        } catch (Throwable $e2) {
            // Silently ignore; lookup below will simply be skipped.
        }
    }
}

foreach (
    [
        "publication_event_submissions",
        "publication_recognition_submissions",
        "publication_content_contributions",
    ] as $pubTable
) {
    trackEnsurePubTokenColumn($conn, $pubTable);
}

/* =========================================================
   CHECK OPTIONAL COLUMNS (job_requests)
========================================================= */
$archivedColumnExists = false;

$archivedColumnCheck = $conn->query(
    "SHOW COLUMNS
     FROM job_requests
     LIKE 'archived'"
);

if (
    $archivedColumnCheck &&
    $archivedColumnCheck->num_rows > 0
) {
    $archivedColumnExists = true;
}

$priorityColumnExists = false;

$priorityColumnCheck = $conn->query(
    "SHOW COLUMNS
     FROM job_requests
     LIKE 'priority'"
);

if (
    $priorityColumnCheck &&
    $priorityColumnCheck->num_rows > 0
) {
    $priorityColumnExists = true;
}

/* =========================================================
   RESOLVE TOKEN ACROSS ALL TRACKABLE TYPES
========================================================= */
$trackType = null; // job | event | recognition | contribution
$request = null;
$pubRecord = null;

$archivedSelect = $archivedColumnExists
    ? "archived"
    : "0 AS archived";

$prioritySelect = $priorityColumnExists
    ? "priority"
    : "'Normal' AS priority";

try {
    $stmt = $conn->prepare(
        "SELECT
            job_no,
            project_title,
            department,
            date_request,
            date_completion,
            approval_status,
            {$prioritySelect},
            {$archivedSelect}
         FROM job_requests
         WHERE public_token = ?
         LIMIT 1"
    );

    $stmt->bind_param("s", $token);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($found) {
        $trackType = "job";
        $request = $found;
    }
} catch (Throwable $e) {
    $trackType = null;
}

/* Publication lookups (only if no job matched). */
if ($trackType === null) {
    $pubQueries = [
        "event" => "SELECT
                id,
                title,
                activity_type,
                venue,
                lead_office,
                person_in_charge,
                start_date,
                end_date,
                preferred_publication_date,
                status,
                created_at
             FROM publication_event_submissions
             WHERE public_token = ?
             LIMIT 1",

        "recognition" => "SELECT
                id,
                recognized_entity,
                recognition_type,
                achievement,
                email_address,
                preferred_publication_date,
                status,
                created_at
             FROM publication_recognition_submissions
             WHERE public_token = ?
             LIMIT 1",

        "contribution" => "SELECT
                id,
                content_title,
                contribution_type,
                publication_channel,
                lead_office,
                focal_person,
                preferred_publication_date,
                status,
                created_at
             FROM publication_content_contributions
             WHERE public_token = ?
             LIMIT 1",
    ];

    foreach ($pubQueries as $kind => $sql) {
        $table = $kind === "event"
            ? "publication_event_submissions"
            : ($kind === "recognition"
                ? "publication_recognition_submissions"
                : "publication_content_contributions");

        if (
            !trackTableExists($conn, $table) ||
            !trackColumnExists($conn, $table, "public_token")
        ) {
            continue;
        }

        try {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $trackType = $kind;
                $pubRecord = $row;
                break;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
}

if ($trackType === null) {
    http_response_code(404);

    die(
        "Tracking record not found."
    );
}

$isPublication = $trackType !== "job";

/* =========================================================
   NORMALIZE DISPLAY FIELDS
========================================================= */
if (!$isPublication) {
    /* ---------------- Job request ---------------- */
    $workflow = [
        "Pending Review",
        "Under Review",
        "Approved",
        "In Development",
        "Completed",
        "For Evaluation",
        "Closed"
    ];

    $currentStatus =
        $request["approval_status"]
        ?? "Pending Review";

    $displayCode = (string) ($request["job_no"] ?? "—");
    $displayTitle = (string) ($request["project_title"] ?? "Untitled Request");
    $displayOffice = (string) ($request["department"] ?? "—");
    $displaySubtitle = "";
    $dateRequested = $request["date_request"] ?? null;
    $datePreferred = $request["date_completion"] ?? null;
    $dateRequestedLabel = "Date Requested";
    $datePreferredLabel = "Preferred Completion";
    $typeLabel = "Job Request";
    $typeIcon = "bi-briefcase-fill";
    $typeCss = "type-job";
} else {
    /* ---------------- Publication submission ---------------- */
    $workflow = [
        "For Review",
        "In Preparation",
        "For Approval",
        "Published",
        "Closed"
    ];

    if ($trackType === "event") {
        $currentStatus = (string) ($pubRecord["status"] ?? "For Review");
        $pubId = (int) ($pubRecord["id"] ?? 0);
        $displayCode = "PHU-HPU-JRF-EVT-" . str_pad((string) max(0, $pubId), 5, "0", STR_PAD_LEFT);
        $displayTitle = (string) ($pubRecord["title"] ?? "Untitled Activity");
        $displaySubtitle = (string) ($pubRecord["activity_type"] ?? "");
        $displayOffice = (string) ($pubRecord["lead_office"] ?? "—");

        if (!empty($pubRecord["venue"])) {
            $displaySubtitle .= $displaySubtitle !== ""
                ? " • " . $pubRecord["venue"]
                : (string) $pubRecord["venue"];
        }

        $createdDate = null;

        if (!empty($pubRecord["created_at"])) {
            $createdDate = substr((string) $pubRecord["created_at"], 0, 10);
        }

        $dateRequested = $createdDate;
        $datePreferred = $pubRecord["preferred_publication_date"] ?? null;
        $typeLabel = "Event / Activity";
        $typeIcon = "bi-calendar2-event-fill";
        $typeCss = "type-event";
    } elseif ($trackType === "recognition") {
        $currentStatus = (string) ($pubRecord["status"] ?? "For Review");
        $pubId = (int) ($pubRecord["id"] ?? 0);
        $displayCode = "PHU-HPU-JRF-REC-" . str_pad((string) max(0, $pubId), 5, "0", STR_PAD_LEFT);
        $displayTitle = (string) ($pubRecord["recognized_entity"] ?? "Untitled Recognition");

        $recTypeRaw = (string) ($pubRecord["recognition_type"] ?? "");
        $decoded = json_decode($recTypeRaw, true);
        $displaySubtitle = is_array($decoded)
            ? implode(", ", array_map("strval", $decoded))
            : $recTypeRaw;

        $displayOffice = (string) ($pubRecord["email_address"] ?? "—");

        $createdDate = null;

        if (!empty($pubRecord["created_at"])) {
            $createdDate = substr((string) $pubRecord["created_at"], 0, 10);
        }

        $dateRequested = $createdDate;
        $datePreferred = $pubRecord["preferred_publication_date"] ?? null;
        $typeLabel = "Recognition / Milestone";
        $typeIcon = "bi-trophy-fill";
        $typeCss = "type-recognition";
    } else {
        $currentStatus = (string) ($pubRecord["status"] ?? "For Review");
        $pubId = (int) ($pubRecord["id"] ?? 0);
        $displayCode = "PHU-HPU-JRF-CON-" . str_pad((string) max(0, $pubId), 5, "0", STR_PAD_LEFT);
        $displayTitle = (string) ($pubRecord["content_title"] ?? "Untitled Content");
        $displaySubtitle = (string) ($pubRecord["contribution_type"] ?? "");

        if (!empty($pubRecord["publication_channel"])) {
            $displaySubtitle .= $displaySubtitle !== ""
                ? " • " . $pubRecord["publication_channel"]
                : (string) $pubRecord["publication_channel"];
        }

        $displayOffice = (string) ($pubRecord["lead_office"] ?? "—");

        $createdDate = null;

        if (!empty($pubRecord["created_at"])) {
            $createdDate = substr((string) $pubRecord["created_at"], 0, 10);
        }

        $dateRequested = $createdDate;
        $datePreferred = $pubRecord["preferred_publication_date"] ?? null;
        $typeLabel = "Content Contribution";
        $typeIcon = "bi-megaphone-fill";
        $typeCss = "type-contribution";
    }

    $dateRequestedLabel = "Date Submitted";
    $datePreferredLabel = "Preferred Publication";
}

$currentIndex = array_search(
    $currentStatus,
    $workflow,
    true
);

/*
 * For Revision and Disapproved are special states (job requests only).
 */
$isSpecialStatus = !$isPublication && in_array(
    $currentStatus,
    [
        "For Revision",
        "Disapproved"
    ],
    true
);

if ($currentIndex === false) {
    $currentIndex = 0;
}

$totalWorkflowSteps = count(
    $workflow
);

$progressPercentage = $isSpecialStatus
    ? 0
    : (int) round(
        (
            ($currentIndex + 1) /
            $totalWorkflowSteps
        ) *
        100
    );

/* =========================================================
   LOCAL HELPERS
========================================================= */
function trackingEscape(
    $value
): string {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

function trackingFormattedDate(
    ?string $date
): string {
    if (!$date) {
        return "Not specified";
    }

    $timestamp = strtotime(
        $date
    );

    return $timestamp
        ? date(
            "F d, Y",
            $timestamp
        )
        : $date;
}

function trackingStatusClass(
    string $status,
    bool $isPublication = false
): string {
    if ($isPublication) {
        return match ($status) {
            "For Review" =>
                "status-review",

            "In Preparation" =>
                "status-development",

            "For Approval" =>
                "status-evaluation",

            "Published" =>
                "status-approved",

            "Closed" =>
                "status-closed",

            default =>
                "status-default"
        };
    }

    return match ($status) {
        "Pending Review" =>
            "status-pending",

        "Under Review" =>
            "status-review",

        "Approved" =>
            "status-approved",

        "In Development" =>
            "status-development",

        "For Revision" =>
            "status-revision",

        "Disapproved" =>
            "status-disapproved",

        "Completed" =>
            "status-completed",

        "For Evaluation" =>
            "status-evaluation",

        "Closed" =>
            "status-closed",

        default =>
            "status-default"
    };
}

function trackingStatusIcon(
    string $status,
    bool $isPublication = false
): string {
    if ($isPublication) {
        return match ($status) {
            "For Review" =>
                "bi-send-check",

            "In Preparation" =>
                "bi-tools",

            "For Approval" =>
                "bi-patch-check",

            "Published" =>
                "bi-megaphone-fill",

            "Closed" =>
                "bi-lock-fill",

            default =>
                "bi-info-circle"
        };
    }

    return match ($status) {
        "Pending Review" =>
            "bi-hourglass-split",

        "Under Review" =>
            "bi-search",

        "Approved" =>
            "bi-check-circle-fill",

        "In Development" =>
            "bi-tools",

        "For Revision" =>
            "bi-arrow-repeat",

        "Disapproved" =>
            "bi-x-circle-fill",

        "Completed" =>
            "bi-check2-all",

        "For Evaluation" =>
            "bi-clipboard2-check",

        "Closed" =>
            "bi-lock-fill",

        default =>
            "bi-info-circle"
    };
}

function trackingPriorityClass(
    string $priority
): string {
    return match ($priority) {
        "Low" =>
            "priority-low",

        "High" =>
            "priority-high",

        "Urgent" =>
            "priority-urgent",

        default =>
            "priority-normal"
    };
}

function trackingDueDate(
    ?string $date,
    string $status,
    bool $isPublication = false
): array {
    $terminalStatuses = $isPublication
        ? ["Published", "Closed"]
        : ["Closed", "Disapproved"];

    if (
        !$date ||
        in_array($status, $terminalStatuses, true)
    ) {
        // Published items have no active deadline; keep the same
        // muted pill used by completed job requests.
        if ($isPublication && in_array($status, ["Published", "Closed"], true)) {
            return [
                "text" =>
                    $status === "Published"
                    ? "Published — no active deadline"
                    : "Closed",

                "class" =>
                    "deadline-muted",

                "icon" =>
                    "bi-calendar-minus"
            ];
        }

        return [
            "text" =>
                "No active deadline",

            "class" =>
                "deadline-muted",

            "icon" =>
                "bi-calendar-minus"
        ];
    }

    $today = new DateTime(
        "today"
    );

    $dueDate = new DateTime(
        $date
    );

    $days = (int) $today
        ->diff($dueDate)
        ->format("%r%a");

    if ($days < 0) {
        return [
            "text" =>
                "Overdue by " .
                abs($days) .
                " day(s)",

            "class" =>
                "deadline-danger",

            "icon" =>
                "bi-exclamation-triangle-fill"
        ];
    }

    if ($days === 0) {
        return [
            "text" =>
                $isPublication ? "Scheduled today" : "Due today",

            "class" =>
                "deadline-danger",

            "icon" =>
                "bi-alarm-fill"
        ];
    }

    if ($days === 1) {
        return [
            "text" =>
                $isPublication ? "Scheduled tomorrow" : "Due tomorrow",

            "class" =>
                "deadline-warning",

            "icon" =>
                "bi-clock-fill"
        ];
    }

    return [
        "text" =>
            ($isPublication ? "Scheduled in " : "Due in ") .
            $days .
            " days",

        "class" =>
            "deadline-success",

        "icon" =>
            "bi-calendar-check-fill"
    ];
}

$dueInformation = trackingDueDate(
    $isPublication ? ($datePreferred ?? null) : ($request["date_completion"] ?? null),
    $currentStatus,
    $isPublication
);

$workflowColumns = $totalWorkflowSteps > 0 ? $totalWorkflowSteps : 1;
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="theme-color" content="#064a26">

    <meta name="description"
        content="Track your HPU request status in real time. Reference <?= trackingEscape($displayCode) ?>.">

    <title>
        Track <?= trackingEscape($displayCode) ?> | ASTMMC HPU
    </title>

    <link rel="icon" href="<?= trackingEscape(rtrim(BASE_URL, "/") . "/assets/images/astmmc-logo.png") ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <?php
    $shareUrl = rtrim(BASE_URL, "/") . "/track.php?token=" . urlencode($token);
    $stepNumber = $isSpecialStatus ? 0 : ($currentIndex + 1);
    $stepLabel = $isSpecialStatus
        ? "Needs attention"
        : ("Step " . $stepNumber . " of " . $totalWorkflowSteps);

    $stepDescriptions = $isPublication
        ? [
            "For Review" => "Submission received and queued for HPU review.",
            "In Preparation" => "Content is being drafted, designed or edited.",
            "For Approval" => "Draft is ready for final approval.",
            "Published" => "Your content has been published.",
            "Closed" => "Submission is closed. Thank you!",
        ]
        : [
            "Pending Review" => "We received your request. It is queued for review.",
            "Under Review" => "HPU is checking your details and requirements.",
            "Approved" => "Approved and queued for production.",
            "In Development" => "Our team is now working on your output.",
            "Completed" => "Output is ready for release or delivery.",
            "For Evaluation" => "Please share your feedback when contacted.",
            "Closed" => "Request is closed. Thank you!",
        ];

    $nextGuidance = (!$isPublication
        ? [
            "Pending Review" => ["icon" => "bi-hourglass-split", "title" => "No action needed", "text" => "HPU will review shortly. Check back in 1-2 working days."],
            "Under Review" => ["icon" => "bi-search", "title" => "Keep your lines open", "text" => "HPU may contact you if details or files are lacking."],
            "Approved" => ["icon" => "bi-check-circle", "title" => "You are in queue", "text" => "Your request is approved. Production starts soon."],
            "In Development" => ["icon" => "bi-tools", "title" => "Work is ongoing", "text" => "Our team is building your output. We will update the status once ready."],
            "Completed" => ["icon" => "bi-check2-all", "title" => "Ready for release", "text" => "Watch for HPU's delivery message via email or phone."],
            "For Evaluation" => ["icon" => "bi-clipboard2-check", "title" => "One last step", "text" => "Please accomplish the client evaluation if contacted by HPU."],
            "Closed" => ["icon" => "bi-lock", "title" => "All done", "text" => "This request is complete. No further action needed."],
            "For Revision" => ["icon" => "bi-arrow-repeat", "title" => "Revision needed", "text" => "Please coordinate with HPU to update your requirements, then resubmit as advised."],
            "Disapproved" => ["icon" => "bi-x-circle", "title" => "Not approved", "text" => "Contact HPU for clarification on why this request could not proceed."],
        ]
        : [
            "For Review" => ["icon" => "bi-send-check", "title" => "No action needed", "text" => "Your submission is queued. HPU reviews in 1-2 working days."],
            "In Preparation" => ["icon" => "bi-tools", "title" => "Work is ongoing", "text" => "HPU is preparing your content for publication."],
            "For Approval" => ["icon" => "bi-patch-check", "title" => "Almost there", "text" => "The draft is for final approval. Publication follows shortly."],
            "Published" => ["icon" => "bi-megaphone", "title" => "Congratulations", "text" => "Your content is now published. Thank you for contributing."],
            "Closed" => ["icon" => "bi-lock", "title" => "All done", "text" => "This submission is closed. No further action needed."],
        ])[$currentStatus] ?? ["icon" => "bi-info-circle", "title" => "Stay tuned", "text" => "Check back here for the latest update."];

    $isDone = !$isSpecialStatus && (
        (!$isPublication && in_array($currentStatus, ["Completed", "For Evaluation", "Closed"], true)) ||
        ($isPublication && in_array($currentStatus, ["Published", "Closed"], true))
    );
    ?>

    <style>
        :root {
            --brand-950: #052e1b;
            --brand-900: #064a26;
            --brand-700: #00662d;
            --brand-600: #008037;
            --brand-500: #16a34a;
            --brand-100: #dcfce7;
            --ink: #0f1f17;
            --muted: #5f6f66;
            --line: #e2ebe6;
            --card: #ffffff;
            --soft: #f4f8f6;
            --radius: 18px;
            --shadow: 0 12px 32px rgba(6, 74, 38, .10);
            --shadow-sm: 0 4px 14px rgba(15, 31, 23, .07);
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(900px 320px at 15% -80px, #d8efe1 0%, transparent 60%),
                radial-gradient(800px 300px at 95% -60px, #dbeafe 0%, transparent 55%),
                #eef3f1;
            color: var(--ink);
            font-family: Inter, system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .skip-link {
            position: absolute;
            left: -999px;
            top: 8px;
            z-index: 100;
            padding: 10px 14px;
            border-radius: 10px;
            background: #fff;
            color: var(--brand-900);
            font-weight: 800;
        }

        .skip-link:focus {
            left: 8px;
        }

        /* ---------- Top bar ---------- */
        .topbar {
            position: sticky;
            top: 0;
            z-index: 40;
            border-bottom: 1px solid rgba(226, 235, 230, .9);
            background: rgba(255, 255, 255, .86);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .topbar-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            width: 100%;
            max-width: 1080px;
            margin: 0 auto;
            padding: 10px 16px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            min-width: 0;
        }

        .brand-mark {
            display: grid;
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            place-items: center;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 50%;
            background: #fff;
        }

        .brand-mark img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 3px;
        }

        .brand-name {
            line-height: 1.15;
            min-width: 0;
        }

        .brand-name strong {
            display: block;
            font-size: 13.5px;
            letter-spacing: -.01em;
        }

        .brand-name span {
            display: block;
            color: var(--muted);
            font-size: 11.5px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .topbar-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .btn-ghost {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 13px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #fff;
            color: var(--brand-900);
            font-size: 13px;
            font-weight: 800;
            text-decoration: none;
            transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
        }

        .btn-ghost:hover {
            border-color: #b9d5c4;
            box-shadow: var(--shadow-sm);
            color: var(--brand-900);
            transform: translateY(-1px);
        }

        .btn-solid {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 14px;
            border: 1px solid var(--brand-700);
            border-radius: 999px;
            background: var(--brand-700);
            color: #fff;
            font-size: 13px;
            font-weight: 800;
            text-decoration: none;
            transition: transform .12s ease, filter .12s ease;
        }

        .btn-solid:hover {
            color: #fff;
            filter: brightness(1.06);
            transform: translateY(-1px);
        }

        /* ---------- Layout ---------- */
        .wrap {
            width: 100%;
            max-width: 1080px;
            margin: 0 auto;
            padding: 18px 16px 40px;
        }

        .crumbs {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin: 4px 2px 12px;
            color: var(--muted);
            font-size: 12.5px;
            font-weight: 600;
        }

        .crumbs i {
            font-size: 11px;
            opacity: .7;
        }

        .crumbs .here {
            max-width: 42ch;
            overflow: hidden;
            color: var(--ink);
            font-weight: 800;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* ---------- Hero ---------- */
        .hero {
            position: relative;
            overflow: hidden;
            border: 1px solid #0b3d22;
            border-radius: 22px;
            background:
                radial-gradient(520px 260px at 88% 0%, rgba(74, 222, 128, .32) 0%, transparent 60%),
                radial-gradient(460px 300px at 0% 100%, rgba(255, 255, 255, .10) 0%, transparent 55%),
                linear-gradient(135deg, #073d20 0%, #00662d 55%, #0a7a3a 100%);
            color: #fff;
            box-shadow: var(--shadow);
            animation: rise .45s ease both;
        }

        .hero::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            opacity: .14;
            background-image: radial-gradient(rgba(255, 255, 255, .9) 1px, transparent 1px);
            background-size: 22px 22px;
            mask-image: linear-gradient(180deg, #000 0%, transparent 72%);
        }

        .hero-inner {
            position: relative;
            z-index: 1;
            padding: 22px 22px 20px;
        }

        .hero-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .ref-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            max-width: 100%;
            padding: 7px 8px 7px 12px;
            border: 1px solid rgba(255, 255, 255, .28);
            border-radius: 999px;
            background: rgba(255, 255, 255, .12);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .05em;
        }

        .ref-chip code {
            padding: 0;
            background: transparent;
            color: #fff;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 12px;
            letter-spacing: .02em;
        }

        .mini-copy {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 10px;
            border: 0;
            border-radius: 999px;
            background: #fff;
            color: var(--brand-900);
            font-size: 11.5px;
            font-weight: 900;
            cursor: pointer;
        }

        .mini-copy:hover {
            background: #eafff1;
        }

        .secure-note {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: rgba(255, 255, 255, .82);
            font-size: 11.5px;
            font-weight: 700;
        }

        .hero h1 {
            margin: 14px 0 6px;
            font-size: clamp(24px, 4.6vw, 36px);
            font-weight: 900;
            line-height: 1.08;
            letter-spacing: -.03em;
            text-wrap: balance;
        }

        .hero-sub {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 4px;
            color: rgba(255, 255, 255, .88);
            font-size: 13.5px;
            font-weight: 600;
        }

        .hero-office {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            color: rgba(255, 255, 255, .78);
            font-size: 13px;
            font-weight: 600;
        }

        .chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 16px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 12.5px;
            font-weight: 800;
            line-height: 1;
            border: 1px solid transparent;
        }

        .pill.on-light {
            background: rgba(255, 255, 255, .14);
            border-color: rgba(255, 255, 255, .24);
            color: #fff;
        }

        .pill.on-white {
            background: #fff;
            color: var(--brand-900);
            border-color: #fff;
        }

        /* status colors - readable on hero (white pills) and cards */
        .status-pending {
            background: #f1f5f9;
            color: #475569;
            border-color: #e2e8f0;
        }

        .status-review {
            background: #cffafe;
            color: #155e75;
            border-color: #a5f3fc;
        }

        .status-approved {
            background: #dcfce7;
            color: #166534;
            border-color: #bbf7d0;
        }

        .status-development {
            background: #e0e7ff;
            color: #3730a3;
            border-color: #c7d2fe;
        }

        .status-revision {
            background: #fef3c7;
            color: #92400e;
            border-color: #fde68a;
        }

        .status-disapproved {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fecaca;
        }

        .status-completed {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #bfdbfe;
        }

        .status-evaluation {
            background: #f3e8ff;
            color: #7e22ce;
            border-color: #e9d5ff;
        }

        .status-closed {
            background: #e2e8f0;
            color: #0f172a;
            border-color: #cbd5e1;
        }

        .status-default {
            background: #f1f5f9;
            color: #475569;
            border-color: #e2e8f0;
        }

        .hero .status-pending,
        .hero .status-review,
        .hero .status-approved,
        .hero .status-development,
        .hero .status-revision,
        .hero .status-disapproved,
        .hero .status-completed,
        .hero .status-evaluation,
        .hero .status-closed,
        .hero .status-default {
            background: #fff;
            border-color: #fff;
        }

        .type-job {
            background: #e0e7ff;
            color: #3730a3;
            border-color: #c7d2fe;
        }

        .type-event {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #bfdbfe;
        }

        .type-recognition {
            background: #fef3c7;
            color: #92400e;
            border-color: #fde68a;
        }

        .type-contribution {
            background: #f3e8ff;
            color: #7e22ce;
            border-color: #e9d5ff;
        }

        .hero .type-job,
        .hero .type-event,
        .hero .type-recognition,
        .hero .type-contribution {
            background: rgba(255, 255, 255, .14);
            color: #fff;
            border-color: rgba(255, 255, 255, .28);
        }

        .priority-low {
            background: #dcfce7;
            color: #166534;
            border-color: #bbf7d0;
        }

        .priority-normal {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #bfdbfe;
        }

        .priority-high {
            background: #ffedd5;
            color: #9a3412;
            border-color: #fed7aa;
        }

        .priority-urgent {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fecaca;
        }

        .hero .priority-low,
        .hero .priority-normal,
        .hero .priority-high,
        .hero .priority-urgent {
            background: rgba(255, 255, 255, .14);
            color: #fff;
            border-color: rgba(255, 255, 255, .28);
        }

        .deadline-success {
            background: #dcfce7;
            color: #166534;
            border-color: #bbf7d0;
        }

        .deadline-warning {
            background: #fef3c7;
            color: #92400e;
            border-color: #fde68a;
        }

        .deadline-danger {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fecaca;
        }

        .deadline-muted {
            background: #f1f5f9;
            color: #64748b;
            border-color: #e2e8f0;
        }

        .hero .deadline-success,
        .hero .deadline-warning,
        .hero .deadline-danger,
        .hero .deadline-muted {
            background: #fff;
            border-color: #fff;
        }

        .hero .deadline-success {
            color: #166534;
        }

        .hero .deadline-warning {
            color: #92400e;
        }

        .hero .deadline-danger {
            color: #991b1b;
        }

        .hero .deadline-muted {
            color: #475569;
        }

        .progress-wrap {
            margin-top: 18px;
            padding: 14px;
            border: 1px solid rgba(255, 255, 255, .22);
            border-radius: 16px;
            background: rgba(0, 0, 0, .18);
        }

        .progress-meta {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }

        .progress-meta strong {
            font-size: 13px;
            letter-spacing: -.01em;
        }

        .progress-meta .pct {
            font-size: 22px;
            font-weight: 900;
            letter-spacing: -.03em;
        }

        .progress-step {
            color: rgba(255, 255, 255, .8);
            font-size: 12px;
            font-weight: 700;
        }

        .progress {
            height: 10px;
            overflow: hidden;
            border-radius: 999px;
            background: rgba(255, 255, 255, .22);
        }

        .progress-bar {
            width: 0;
            border-radius: 999px;
            background: linear-gradient(90deg, #bbf7d0, #fff);
            transition: width 1s cubic-bezier(.22, 1, .36, 1);
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 14px;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            text-decoration: none;
            transition: transform .12s ease, background .12s ease;
            border: 1px solid transparent;
        }

        .action-primary {
            background: #fff;
            color: var(--brand-900);
            border-color: #fff;
        }

        .action-primary:hover {
            background: #eafff1;
            color: var(--brand-900);
            transform: translateY(-1px);
        }

        .action-ghost {
            background: rgba(255, 255, 255, .12);
            color: #fff;
            border-color: rgba(255, 255, 255, .3);
        }

        .action-ghost:hover {
            background: rgba(255, 255, 255, .2);
            color: #fff;
            transform: translateY(-1px);
        }

        .last-checked {
            margin-top: 10px;
            color: rgba(255, 255, 255, .72);
            font-size: 11.5px;
            font-weight: 600;
        }

        /* ---------- Content grid ---------- */
        .layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
            margin-top: 14px;
        }

        @media (min-width: 960px) {
            .layout {
                grid-template-columns: 1.62fr .92fr;
                align-items: start;
            }

            .hero-inner {
                padding: 28px 28px 24px;
            }
        }

        .card {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--card);
            box-shadow: var(--shadow-sm);
            animation: rise .5s ease both;
        }

        .card-pad {
            padding: 20px;
        }

        .card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 6px;
        }

        .card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            font-size: 16px;
            font-weight: 900;
            letter-spacing: -.02em;
        }

        .card-title .ticon {
            display: grid;
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            place-items: center;
            border-radius: 10px;
            background: #e7f3ec;
            color: var(--brand-700);
            font-size: 16px;
        }

        .card-sub {
            margin: 2px 0 0 44px;
            color: var(--muted);
            font-size: 12.5px;
            font-weight: 600;
        }

        .step-badge {
            flex: 0 0 auto;
            padding: 7px 10px;
            border-radius: 999px;
            background: var(--soft);
            border: 1px solid var(--line);
            color: var(--brand-900);
            font-size: 11.5px;
            font-weight: 900;
            white-space: nowrap;
        }

        .callout {
            display: flex;
            gap: 12px;
            margin: 14px 0 4px;
            padding: 14px;
            border-radius: 14px;
            font-size: 13.5px;
            line-height: 1.5;
        }

        .callout i {
            font-size: 20px;
            line-height: 1;
            margin-top: 1px;
        }

        .callout strong {
            display: block;
            margin-bottom: 2px;
            font-size: 13.5px;
        }

        .callout p {
            margin: 0;
            opacity: .92;
        }

        .callout-revision {
            border: 1px solid #fde68a;
            background: #fffbeb;
            color: #78350f;
        }

        .callout-disapproved {
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #7f1d1d;
        }

        .callout-done {
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: #14532d;
        }

        .callout-archive {
            border: 1px dashed #94a3b8;
            background: #f8fafc;
            color: #334155;
        }

        /* ---------- Timeline ---------- */
        .timeline {
            list-style: none;
            margin: 14px 0 2px;
            padding: 0;
        }

        .t-step {
            position: relative;
            display: grid;
            grid-template-columns: 40px 1fr;
            gap: 12px;
            padding: 0 0 18px;
        }

        .t-step:last-child {
            padding-bottom: 2px;
        }

        .t-rail {
            position: relative;
            display: flex;
            justify-content: center;
        }

        .t-dot {
            position: relative;
            z-index: 1;
            display: grid;
            width: 36px;
            height: 36px;
            place-items: center;
            border-radius: 50%;
            border: 2px solid var(--line);
            background: #fff;
            color: #94a3b8;
            font-size: 15px;
        }

        .t-step::before {
            content: "";
            position: absolute;
            top: 40px;
            bottom: 2px;
            left: 19px;
            width: 2px;
            border-radius: 2px;
            background: var(--line);
        }

        .t-step:last-child::before {
            display: none;
        }

        .t-step.is-complete::before {
            background: #86efac;
        }

        .t-step.is-complete .t-dot {
            border-color: #16a34a;
            background: #16a34a;
            color: #fff;
        }

        .t-step.is-current .t-dot {
            border-color: #2563eb;
            background: #2563eb;
            color: #fff;
            box-shadow: 0 0 0 6px rgba(37, 99, 235, .14);
        }

        .t-step.is-current .t-dot::after {
            content: "";
            position: absolute;
            inset: -8px;
            border: 2px solid rgba(37, 99, 235, .28);
            border-radius: 50%;
            animation: ping 1.8s ease-out infinite;
        }

        .t-step.is-attention .t-dot {
            border-color: #f59e0b;
            background: #f59e0b;
            color: #fff;
        }

        .t-body {
            padding: 11px 13px 12px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #fbfdfc;
        }

        .t-step.is-current .t-body {
            border-color: #bfdbfe;
            background: #eff6ff;
        }

        .t-step.is-complete .t-body {
            border-color: #bbf7d0;
            background: #f0fdf4;
        }

        .t-name {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            font-size: 13.5px;
            font-weight: 900;
        }

        .t-state {
            flex: 0 0 auto;
            font-size: 10.5px;
            font-weight: 900;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .t-state.done {
            color: #15803d;
        }

        .t-state.now {
            color: #1d4ed8;
        }

        .t-state.todo {
            color: #94a3b8;
        }

        .t-desc {
            margin: 4px 0 0;
            color: #40514a;
            font-size: 12.8px;
            line-height: 1.5;
            font-weight: 500;
        }

        /* ---------- Side cards ---------- */
        .side-stack {
            display: grid;
            gap: 14px;
        }

        .kv {
            display: grid;
            gap: 0;
            margin-top: 8px;
            border-top: 1px solid #eef3f1;
        }

        .kv-row {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            padding: 11px 2px;
            border-bottom: 1px solid #eef3f1;
        }

        .kv-row:last-child {
            border-bottom: 0;
        }

        .kv-ic {
            display: grid;
            width: 32px;
            height: 32px;
            flex: 0 0 32px;
            place-items: center;
            border-radius: 9px;
            background: var(--soft);
            color: var(--brand-700);
            font-size: 15px;
        }

        .kv-label {
            color: var(--muted);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .kv-value {
            margin-top: 1px;
            font-size: 13.5px;
            font-weight: 800;
            line-height: 1.4;
            overflow-wrap: anywhere;
        }

        .kv-value small {
            display: block;
            color: var(--muted);
            font-weight: 600;
        }

        .next-box {
            display: flex;
            gap: 12px;
            margin-top: 12px;
            padding: 14px;
            border-radius: 14px;
            background: linear-gradient(180deg, #f0fdf4, #ecfdf5);
            border: 1px solid #bbf7d0;
        }

        .next-box i {
            font-size: 22px;
            color: var(--brand-700);
        }

        .next-box strong {
            display: block;
            font-size: 13.5px;
        }

        .next-box p {
            margin: 3px 0 0;
            color: #33503f;
            font-size: 13px;
            line-height: 1.55;
        }

        .help-list {
            display: grid;
            gap: 8px;
            margin-top: 12px;
        }

        .help-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 11px 12px;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 800;
            text-decoration: none;
        }

        .help-note {
            margin: 10px 2px 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.55;
            text-align: center;
        }

        .foot {
            margin-top: 16px;
            padding: 14px 8px 0;
            color: var(--muted);
            font-size: 11.5px;
            line-height: 1.6;
            text-align: center;
        }

        .foot code {
            color: #334155;
            font-size: 11px;
        }

        /* ---------- Toast ---------- */
        .toast-box {
            position: fixed;
            left: 50%;
            bottom: 18px;
            z-index: 80;
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 12px 15px;
            border-radius: 14px;
            background: #052e1b;
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            box-shadow: 0 14px 30px rgba(0, 0, 0, .22);
            transform: translate(-50%, 16px);
            opacity: 0;
            pointer-events: none;
            transition: opacity .2s ease, transform .2s ease;
            max-width: min(92vw, 420px);
        }

        .toast-box.show {
            opacity: 1;
            transform: translate(-50%, 0);
        }

        @keyframes rise {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        @keyframes ping {
            0% {
                transform: scale(.86);
                opacity: 1;
            }

            100% {
                transform: scale(1.18);
                opacity: 0;
            }
        }

        @media (max-width: 560px) {
            .wrap {
                padding: 12px 10px 30px;
            }

            .hero-inner,
            .card-pad {
                padding: 18px 16px;
            }

            .topbar-inner {
                padding: 9px 10px;
            }

            .brand-name span {
                display: none;
            }

            .btn-ghost span.hide-sm,
            .btn-solid span.hide-sm {
                display: none;
            }

            .hero-actions .action-btn {
                flex: 1 1 100%;
                justify-content: center;
            }

            .card-sub {
                margin-left: 0;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                animation: none !important;
                transition: none !important;
            }

            html {
                scroll-behavior: auto;
            }
        }

        :focus-visible {
            outline: 3px solid rgba(37, 99, 235, .45);
            outline-offset: 2px;
        }

        @media print {
            body {
                background: #fff;
            }

            .topbar,
            .hero-actions,
            .help-list,
            .toast-box,
            .last-checked,
            .secure-note,
            .mini-copy {
                display: none !important;
            }

            .wrap {
                max-width: 100%;
                padding: 0;
            }

            .hero {
                box-shadow: none;
                border-radius: 0;
            }

            .card {
                box-shadow: none;
                break-inside: avoid;
            }

            .layout {
                grid-template-columns: 1fr;
            }
        }
    </style>

</head>

<body>

    <a class="skip-link" href="#progress-timeline">Skip to progress</a>

    <div class="topbar">
        <div class="topbar-inner">
            <div class="brand">
                <span class="brand-mark" aria-hidden="true">
                    <img src="<?= trackingEscape(rtrim(BASE_URL, "/") . "/assets/images/astmmc-logo.png") ?>" alt=""
                        onerror="this.style.display='none';this.parentElement.innerHTML='<i class=&quot;bi bi-hospital&quot; style=&quot;color:#00662d;font-size:19px&quot;></i>';">
                </span>
                <span class="brand-name">
                    <strong>ASTMMC | Health Promotion Unit</strong>
                    <span>Job Request &amp; Publication Tracking Portal</span>
                </span>
            </div>
            <div class="topbar-actions">
                <a class="btn-ghost" href="mailto:astmmchealthpromounit@gmail.com">
                    <i class="bi bi-envelope-fill"></i><span class="hide-sm">Email HPU</span><span
                        class="d-sm-none">Email</span>
                </a>
                <a class="btn-solid" href="tel:1051">
                    <i class="bi bi-telephone-fill"></i><span class="hide-sm">Fanvil 1051</span><span
                        class="d-sm-none">Call</span>
                </a>
            </div>
        </div>
    </div>

    <main class="wrap" id="main">

        <nav class="crumbs" aria-label="Breadcrumb">
            <span>Tracking</span>
            <i class="bi bi-chevron-right"></i>
            <span><?= trackingEscape($typeLabel) ?></span>
            <i class="bi bi-chevron-right"></i>
            <span class="here"><?= trackingEscape($displayCode) ?></span>
        </nav>

        <!-- HERO -->
        <section class="hero" aria-labelledby="track-title">
            <div class="hero-inner">

                <div class="hero-top">
                    <span class="ref-chip">
                        <i class="bi bi-upc-scan" aria-hidden="true"></i>
                        <code><?= trackingEscape($displayCode) ?></code>
                        <button type="button" class="mini-copy" data-copy="<?= trackingEscape($displayCode) ?>">
                            <i class="bi bi-copy"></i> Copy
                        </button>
                    </span>
                    <span class="secure-note">
                        <i class="bi bi-shield-lock-fill"></i> Secure tracking | details are protected
                    </span>
                </div>

                <h1 id="track-title"><?= trackingEscape($displayTitle) ?></h1>

                <?php if ($displaySubtitle !== ""): ?>
                    <div class="hero-sub">
                        <i class="bi bi-tag-fill" aria-hidden="true"></i>
                        <span><?= trackingEscape($displaySubtitle) ?></span>
                    </div>
                <?php endif; ?>

                <div class="hero-office">
                    <i class="bi bi-building" aria-hidden="true"></i>
                    <span><?= trackingEscape($displayOffice) ?></span>
                </div>

                <div class="chip-row" aria-label="Current status summary">
                    <span class="pill <?= trackingStatusClass($currentStatus, $isPublication) ?>">
                        <i class="bi <?= trackingStatusIcon($currentStatus, $isPublication) ?>"></i>
                        <?= trackingEscape($currentStatus) ?>
                    </span>
                    <span class="pill <?= trackingEscape($typeCss) ?>">
                        <i class="bi <?= trackingEscape($typeIcon) ?>"></i>
                        <?= trackingEscape($typeLabel) ?>
                    </span>
                    <?php if (!$isPublication): ?>
                        <span class="pill <?= trackingPriorityClass($request["priority"] ?? "Normal") ?>">
                            <i class="bi bi-flag-fill"></i>
                            <?= trackingEscape($request["priority"] ?? "Normal") ?> priority
                        </span>
                    <?php endif; ?>
                    <span class="pill <?= trackingEscape($dueInformation["class"]) ?>">
                        <i class="bi <?= trackingEscape($dueInformation["icon"]) ?>"></i>
                        <?= trackingEscape($dueInformation["text"]) ?>
                    </span>
                </div>

                <div class="progress-wrap">
                    <div class="progress-meta">
                        <div>
                            <strong>Overall progress</strong>
                            <div class="progress-step"><?= trackingEscape($stepLabel) ?><?php if (!$isSpecialStatus): ?>
                                    | <?= trackingEscape($currentStatus) ?><?php endif; ?></div>
                        </div>
                        <div class="pct" id="pctLabel">0%</div>
                    </div>
                    <div class="progress" role="progressbar" aria-valuenow="<?= (int) $progressPercentage ?>"
                        aria-valuemin="0" aria-valuemax="100" aria-label="Overall progress">
                        <div class="progress-bar" id="progressBar" data-target="<?= (int) $progressPercentage ?>"></div>
                    </div>
                </div>

                <div class="hero-actions">
                    <button type="button" class="action-btn action-primary" id="copyLinkBtn"
                        data-copy="<?= trackingEscape($shareUrl) ?>">
                        <i class="bi bi-link-45deg"></i> Copy tracking link
                    </button>
                    <button type="button" class="action-btn action-ghost" id="shareBtn">
                        <i class="bi bi-share-fill"></i> Share
                    </button>
                    <button type="button" class="action-btn action-ghost" onclick="window.print()">
                        <i class="bi bi-printer-fill"></i> Print / Save PDF
                    </button>
                    <button type="button" class="action-btn action-ghost" onclick="window.location.reload()">
                        <i class="bi bi-arrow-clockwise"></i> Refresh status
                    </button>
                </div>
                <div class="last-checked">Last checked: <span id="checkedAt">just now</span> | This page updates
                    whenever HPU changes the status.</div>

            </div>
        </section>

        <div class="layout">

            <!-- TIMELINE -->
            <section class="card" aria-labelledby="timeline-heading">
                <div class="card-pad">
                    <div class="card-head">
                        <h2 class="card-title" id="progress-timeline">
                            <span class="ticon"><i class="bi bi-diagram-3-fill"></i></span>
                            <span
                                id="timeline-heading"><?= $isPublication ? "Publication progress" : "Request progress" ?></span>
                        </h2>
                        <span class="step-badge"><?= trackingEscape($stepLabel) ?></span>
                    </div>
                    <p class="card-sub">Follow each stage from submission to completion. The highlighted step is where
                        your <?= $isPublication ? "submission" : "request" ?> is right now.</p>

                    <?php if (!$isPublication && $currentStatus === "For Revision"): ?>
                        <div class="callout callout-revision" role="alert">
                            <i class="bi bi-arrow-repeat"></i>
                            <div>
                                <strong>Revision needed before we can continue</strong>
                                <p>Please coordinate with HPU to update the requirements. Your progress is paused until the
                                    revision is submitted.</p>
                            </div>
                        </div>
                    <?php elseif (!$isPublication && $currentStatus === "Disapproved"): ?>
                        <div class="callout callout-disapproved" role="alert">
                            <i class="bi bi-x-circle-fill"></i>
                            <div>
                                <strong>This request was disapproved</strong>
                                <p>Contact HPU if you need clarification. You may submit a new request with corrected
                                    details.</p>
                            </div>
                        </div>
                    <?php elseif ($isDone): ?>
                        <div class="callout callout-done">
                            <i class="bi bi-check-circle-fill"></i>
                            <div>
                                <strong><?= $isPublication && $currentStatus === "Published" ? "Published - congratulations!" : "Completed - thank you!" ?></strong>
                                <p><?= $isPublication ? "There is no active deadline. Keep this link for your records." : "There is no active deadline. Keep this link for your records." ?>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <ol class="timeline">
                        <?php foreach ($workflow as $index => $step): ?>
                            <?php
                            if ($isSpecialStatus) {
                                $state = "todo";
                                $liClass = "is-attention";
                                $stateText = "Paused";
                            } elseif ($index < $currentIndex) {
                                $state = "done";
                                $liClass = "is-complete";
                                $stateText = "Completed";
                            } elseif ($index === $currentIndex) {
                                $state = "now";
                                $liClass = "is-current";
                                $stateText = "Current";
                            } else {
                                $state = "todo";
                                $liClass = "";
                                $stateText = "Upcoming";
                            }
                            $desc = $stepDescriptions[$step] ?? "";
                            $icon = $index < $currentIndex && !$isSpecialStatus
                                ? "bi-check-lg"
                                : ($index === $currentIndex && !$isSpecialStatus ? "bi-record-circle-fill" : "bi-circle");
                            ?>
                            <li class="t-step <?= trackingEscape($liClass) ?>" <?= $index === $currentIndex && !$isSpecialStatus ? 'aria-current="step"' : "" ?>>
                                <span class="t-rail"><span class="t-dot"><i
                                            class="bi <?= trackingEscape($icon) ?>"></i></span></span>
                                <div class="t-body">
                                    <div class="t-name">
                                        <span><?= trackingEscape($step) ?></span>
                                        <span
                                            class="t-state <?= trackingEscape($state) ?>"><?= trackingEscape($stateText) ?></span>
                                    </div>
                                    <?php if ($desc !== ""): ?>
                                        <p class="t-desc"><?= trackingEscape($desc) ?></p>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>

                    <?php if (!$isPublication && (int) ($request["archived"] ?? 0) === 1): ?>
                        <div class="callout callout-archive">
                            <i class="bi bi-archive-fill"></i>
                            <div>
                                <strong>Archived record</strong>
                                <p>This completed request has been archived for record-keeping. No further updates are
                                    expected.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- SIDE -->
            <aside class="side-stack">

                <section class="card" aria-labelledby="glance-heading">
                    <div class="card-pad">
                        <h2 class="card-title" id="glance-heading">
                            <span class="ticon"><i class="bi bi-card-checklist"></i></span>
                            At a glance
                        </h2>

                        <div class="kv">
                            <div class="kv-row">
                                <span class="kv-ic"><i class="bi bi-upc"></i></span>
                                <div>
                                    <div class="kv-label">Reference number</div>
                                    <div class="kv-value"><?= trackingEscape($displayCode) ?></div>
                                </div>
                            </div>
                            <div class="kv-row">
                                <span class="kv-ic"><i class="bi bi-calendar-plus"></i></span>
                                <div>
                                    <div class="kv-label"><?= trackingEscape($dateRequestedLabel) ?></div>
                                    <div class="kv-value"><?= trackingEscape(trackingFormattedDate($dateRequested)) ?>
                                    </div>
                                </div>
                            </div>
                            <div class="kv-row">
                                <span class="kv-ic"><i class="bi bi-calendar-check"></i></span>
                                <div>
                                    <div class="kv-label"><?= trackingEscape($datePreferredLabel) ?></div>
                                    <div class="kv-value"><?= trackingEscape(trackingFormattedDate($datePreferred)) ?>
                                    </div>
                                </div>
                            </div>
                            <div class="kv-row">
                                <span class="kv-ic"><i class="bi bi-clock-history"></i></span>
                                <div>
                                    <div class="kv-label">
                                        <?= $isPublication ? "Publication status" : "Deadline status" ?>
                                    </div>
                                    <div class="kv-value">
                                        <span class="pill <?= trackingEscape($dueInformation["class"]) ?>">
                                            <i class="bi <?= trackingEscape($dueInformation["icon"]) ?>"></i>
                                            <?= trackingEscape($dueInformation["text"]) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="card" aria-labelledby="next-heading">
                    <div class="card-pad">
                        <h2 class="card-title" id="next-heading">
                            <span class="ticon"><i class="bi bi-compass"></i></span>
                            What happens next?
                        </h2>
                        <div class="next-box">
                            <i class="bi <?= trackingEscape($nextGuidance["icon"]) ?>"></i>
                            <div>
                                <strong><?= trackingEscape($nextGuidance["title"]) ?></strong>
                                <p><?= trackingEscape($nextGuidance["text"]) ?></p>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="card" aria-labelledby="help-heading">
                    <div class="card-pad">
                        <h2 class="card-title" id="help-heading">
                            <span class="ticon"><i class="bi bi-headset"></i></span>
                            Need assistance?
                        </h2>
                        <p class="card-sub" style="margin-left:0">Contact the Health Promotion Unit about this
                            <?= $isPublication ? "submission" : "request" ?>. Include your reference number.
                        </p>
                        <div class="help-list">
                            <a class="help-btn btn btn-success"
                                href="mailto:astmmchealthpromounit@gmail.com?subject=<?= urlencode("Tracking " . $displayCode) ?>">
                                <i class="bi bi-envelope-fill me-1"></i> Email HPU
                            </a>
                            <a class="help-btn btn btn-outline-success" href="tel:1002">
                                <i class="bi bi-telephone-fill me-1"></i> Fanvil 1051
                            </a>
                        </div>
                        <p class="help-note">Mon-Fri, office hours | Replies within 1-2 working days.</p>
                    </div>
                </section>

            </aside>
        </div>

        <footer class="foot">
            <i class="bi bi-lock-fill"></i>
            This page shows tracking information only. Confidential <?= $isPublication ? "submission" : "request" ?>
            details are protected.<br>
            Reference: <code><?= trackingEscape($displayCode) ?></code> | <?= trackingEscape($typeLabel) ?> | ASTMMC HPU
        </footer>

    </main>

    <div class="toast-box" id="toast" role="status" aria-live="polite">
        <i class="bi bi-check-circle-fill"></i>
        <span id="toastText">Copied</span>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            var bar = document.getElementById("progressBar");
            var pct = document.getElementById("pctLabel");
            if (bar && pct) {
                var target = parseInt(bar.getAttribute("data-target") || "0", 10);
                requestAnimationFrame(function () {
                    bar.style.width = target + "%";
                });
                var start = null, dur = 900;
                function tick(ts) {
                    if (!start) start = ts;
                    var p = Math.min(1, (ts - start) / dur);
                    var eased = 1 - Math.pow(1 - p, 3);
                    pct.textContent = Math.round(target * eased) + "%";
                    if (p < 1) requestAnimationFrame(tick);
                }
                requestAnimationFrame(tick);
            }

            var checked = document.getElementById("checkedAt");
            if (checked) {
                try {
                    checked.textContent = new Date().toLocaleString("en-PH", {
                        month: "short", day: "numeric", year: "numeric",
                        hour: "numeric", minute: "2-digit"
                    });
                } catch (e) { checked.textContent = "just now"; }
            }

            var toast = document.getElementById("toast");
            var toastText = document.getElementById("toastText");
            var timer = null;
            function showToast(msg) {
                if (!toast) return;
                toastText.textContent = msg;
                toast.classList.add("show");
                clearTimeout(timer);
                timer = setTimeout(function () { toast.classList.remove("show"); }, 2200);
            }
            function copyText(text, okMsg) {
                function fallback() {
                    var ta = document.createElement("textarea");
                    ta.value = text;
                    ta.style.position = "fixed";
                    ta.style.opacity = "0";
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand("copy"); showToast(okMsg); }
                    catch (e) { showToast("Copy failed - long-press to copy"); }
                    document.body.removeChild(ta);
                }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () {
                        showToast(okMsg);
                    }, fallback);
                } else { fallback(); }
            }

            document.querySelectorAll("[data-copy]").forEach(function (el) {
                el.addEventListener("click", function () {
                    copyText(el.getAttribute("data-copy") || "", "Reference copied to clipboard");
                });
            });

            var copyLink = document.getElementById("copyLinkBtn");
            if (copyLink) {
                copyLink.addEventListener("click", function () {
                    copyText(copyLink.getAttribute("data-copy") || window.location.href, "Tracking link copied");
                });
            }

            var shareBtn = document.getElementById("shareBtn");
            if (shareBtn) {
                shareBtn.addEventListener("click", function () {
                    var url = (copyLink && copyLink.getAttribute("data-copy")) || window.location.href;
                    var data = { title: document.title, text: "Track my HPU request", url: url };
                    if (navigator.share) {
                        navigator.share(data).catch(function () { });
                    } else {
                        copyText(url, "Tracking link copied - paste it anywhere to share");
                    }
                });
            }
        })();
    </script>

</body>

</html>