<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

$advancedHelpersPath = "../includes/advanced_helpers.php";
if (is_file($advancedHelpersPath)) {
    require_once $advancedHelpersPath;
}

checkLogin();
requireAnyModule($conn, ["job_requests_admin", "review_requests", "my_requests"]);

/* =========================================================
   GET REQUEST ID
========================================================= */
$requestId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

if (!$requestId || $requestId <= 0) {
    http_response_code(400);
    die("Invalid request ID.");
}

/* =========================================================
   GET REQUEST
========================================================= */
$stmt = $conn->prepare(
    "SELECT *
     FROM job_requests
     WHERE id = ?
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
    http_response_code(404);
    die("Request not found.");
}

/* =========================================================
   ACCESS CONTROL
========================================================= */
$currentRole = $_SESSION["role"] ?? "user";
$currentUserId = (int) ($_SESSION["user_id"] ?? 0);

if (
    $currentRole === "user" &&
    (int) $request["requestor"] !== $currentUserId
) {
    // Same-office colleagues may view (read-only) but never act.
    $viewerIsSameOffice = false;

    try {
        $viewerDept = trim((string) ($_SESSION["department"] ?? ""));

        if ($viewerDept === "") {
            $deptStmt = $conn->prepare(
                "SELECT department FROM users WHERE id = ? LIMIT 1"
            );

            if ($deptStmt) {
                $deptStmt->bind_param("i", $currentUserId);
                $deptStmt->execute();
                $deptRow = $deptStmt->get_result()->fetch_assoc();
                $deptStmt->close();
                $viewerDept = trim((string) ($deptRow["department"] ?? ""));
            }
        }

        if ($viewerDept !== "") {
            $ownerStmt = $conn->prepare(
                "SELECT department FROM users WHERE id = ? LIMIT 1"
            );

            if ($ownerStmt) {
                $ownerId = (int) $request["requestor"];
                $ownerStmt->bind_param("i", $ownerId);
                $ownerStmt->execute();
                $ownerRow = $ownerStmt->get_result()->fetch_assoc();
                $ownerStmt->close();
                $ownerDept = trim((string) ($ownerRow["department"] ?? ""));
            } else {
                $ownerDept = "";
            }

            $norm = static function ($v) {
                return mb_strtolower(trim((string) $v));
            };

            $viewerIsSameOffice =
                ($ownerDept !== "" && $norm($ownerDept) === $norm($viewerDept))
                || ($norm((string) ($request["department"] ?? "")) === $norm($viewerDept)
                    && trim((string) ($request["department"] ?? "")) !== "");
        }
    } catch (Throwable $e) {
        $viewerIsSameOffice = false;
    }

    if (!$viewerIsSameOffice) {
        http_response_code(403);
        die("Access denied.");
    }

    $readOnlyViewer = true;
} else {
    $readOnlyViewer = false;
}

/* =========================================================
   CONVERSATION UNREAD (Messages button badge)
   Counts messages after this viewer's last read, excluding
   their own. Staff sees internal notes; requestors only
   non-internal. Same rule as ajax/chat_unread.php.
========================================================= */
$convUnread = 0;
$convTotal = 0;
$convPreview = null;
$isStaffViewer = in_array($currentRole, ["staff", "supervisor", "admin"], true);

try {
    $convTableCheck = $conn->query("SHOW TABLES LIKE 'request_messages'");

    if ($convTableCheck && $convTableCheck->num_rows > 0 && $currentUserId > 0) {
        $convTotalStmt = $conn->prepare(
            "SELECT COUNT(*) AS total FROM request_messages WHERE request_id = ?"
        );

        if ($convTotalStmt) {
            $convTotalStmt->bind_param("i", $requestId);
            $convTotalStmt->execute();
            $convTotalRow = $convTotalStmt->get_result()->fetch_assoc();
            $convTotalStmt->close();
            $convTotal = (int) ($convTotalRow["total"] ?? 0);
        }

        $lastReadId = 0;
        $readStmt = $conn->prepare(
            "SELECT last_read_message_id FROM request_message_reads
             WHERE request_id = ? AND user_id = ? LIMIT 1"
        );

        if ($readStmt) {
            $readStmt->bind_param("ii", $requestId, $currentUserId);
            $readStmt->execute();
            $readRow = $readStmt->get_result()->fetch_assoc();
            $readStmt->close();
            $lastReadId = (int) ($readRow["last_read_message_id"] ?? 0);
        }

        $internalFilter = $isStaffViewer ? "" : " AND is_internal = 0";

        $unreadStmt = $conn->prepare(
            "SELECT COUNT(*) AS unread FROM request_messages
             WHERE request_id = ? AND id > ? AND sender_id <> ?{$internalFilter}"
        );

        if ($unreadStmt) {
            $unreadStmt->bind_param("iii", $requestId, $lastReadId, $currentUserId);
            $unreadStmt->execute();
            $unreadRow = $unreadStmt->get_result()->fetch_assoc();
            $unreadStmt->close();
            $convUnread = (int) ($unreadRow["unread"] ?? 0);
        }

        if ($convTotal > 0) {
            $prevStmt = $conn->prepare(
                "SELECT sender_name, message, created_at FROM request_messages
                 WHERE request_id = ?{$internalFilter}
                 ORDER BY id DESC LIMIT 1"
            );

            if ($prevStmt) {
                $prevStmt->bind_param("i", $requestId);
                $prevStmt->execute();
                $prevRow = $prevStmt->get_result()->fetch_assoc();
                $prevStmt->close();

                if ($prevRow) {
                    $prevMsg = trim((string) ($prevRow["message"] ?? ""));

                    if ($prevMsg === "") {
                        $prevMsg = "(attachment)";
                    } elseif (mb_strlen($prevMsg) > 120) {
                        $prevMsg = mb_substr($prevMsg, 0, 120) . "…";
                    }

                    $convPreview = [
                        "sender" => (string) ($prevRow["sender_name"] ?? ""),
                        "message" => $prevMsg,
                        "created_at" => (string) ($prevRow["created_at"] ?? ""),
                    ];
                }
            }
        }
    }
} catch (Throwable $e) {
    $convUnread = 0;
}

$convPreviewTitle = "";

if ($convPreview) {
    $convPreviewTitle = (($convPreview["sender"] !== "")
        ? $convPreview["sender"] . ": "
        : "") . ($convPreview["message"] ?? "");
}

$convBtnTitle = $convUnread > 0
    ? "Open communication center — " . $convUnread . " new message(s)" . ($convPreviewTitle !== "" ? " — " . $convPreviewTitle : "")
    : "Open communication center" . ($convPreviewTitle !== "" ? " — latest: " . $convPreviewTitle : "");

/* =========================================================
   QR / PUBLIC TRACKING
========================================================= */
$trackingToken = "";
$trackingUrl = "";
$qrTrackingAvailable = false;
$qrTrackingMessage = "QR tracking is not available.";

try {
    $publicTokenColumn = $conn->query(
        "SHOW COLUMNS FROM job_requests LIKE 'public_token'"
    );

    if ($publicTokenColumn && $publicTokenColumn->num_rows > 0) {
        if (function_exists("ensurePublicToken")) {
            $trackingToken = (string) ensurePublicToken($conn, $requestId);
        } else {
            $trackingToken = trim((string) ($request["public_token"] ?? ""));
            if (strlen($trackingToken) !== 64 || !ctype_xdigit($trackingToken)) {
                $trackingToken = bin2hex(random_bytes(32));
                $tokenStmt = $conn->prepare(
                    "UPDATE job_requests SET public_token = ? WHERE id = ?"
                );
                $tokenStmt->bind_param("si", $trackingToken, $requestId);
                $tokenStmt->execute();
                $tokenStmt->close();
            }
        }

        if (strlen($trackingToken) === 64 && ctype_xdigit($trackingToken)) {
            $trackingUrl = rtrim((string) BASE_URL, "/")
                . "/track.php?token="
                . rawurlencode($trackingToken);
            $qrTrackingAvailable = true;
            $qrTrackingMessage = "Scan the QR code to open the public request tracking page.";
        }
    } else {
        $qrTrackingMessage = "The public_token column is missing from job_requests.";
    }
} catch (Throwable $qrException) {
    $qrTrackingMessage = "QR tracking could not be prepared: " . $qrException->getMessage();
}

/* =========================================================
   GET IEC ITEMS
========================================================= */
$iecItems = [];
$hasIecFulfilledQty = false;

$iecTableCheck = $conn->query(
    "SHOW TABLES LIKE 'job_request_iec_items'"
);

if (
    $iecTableCheck &&
    $iecTableCheck->num_rows > 0
) {
    try {
        $iecFulfilledColumn = $conn->query(
            "SHOW COLUMNS
             FROM job_request_iec_items
             LIKE 'fulfilled_qty'"
        );

        $hasIecFulfilledQty =
            $iecFulfilledColumn
            &&
            $iecFulfilledColumn->num_rows > 0;
    } catch (Throwable $iecColumnException) {
        $hasIecFulfilledQty = false;
    }

    $iecFulfilledSelect =
        $hasIecFulfilledQty
        ? "fulfilled_qty"
        : "0 AS fulfilled_qty";

    $iecStmt = $conn->prepare(
        "SELECT
            material_name,
            quantity,
            {$iecFulfilledSelect}
         FROM job_request_iec_items
         WHERE request_id = ?
         ORDER BY id ASC"
    );

    $iecStmt->bind_param(
        "i",
        $requestId
    );

    $iecStmt->execute();

    $iecItems = $iecStmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $iecStmt->close();
}

/* =========================================================
   HELPERS
========================================================= */
function hasValue($value): bool
{
    return trim((string) ($value ?? "")) !== "";
}

function safeArray($value): array
{
    if (!$value) {
        return [];
    }

    $decoded = json_decode(
        (string) $value,
        true
    );

    if (is_array($decoded)) {
        return array_values(
            array_filter(
                $decoded,
                static fn($item) =>
                    trim((string) $item) !== ""
            )
        );
    }

    return [];
}

function displayList(
    array $items,
    ?string $otherValue = null
): string {
    if (
        empty($items) &&
        !hasValue($otherValue)
    ) {
        return '<span class="text-muted">Not specified</span>';
    }

    $html = '<ul class="detail-list mb-0">';

    foreach ($items as $item) {
        $displayValue = $item;

        if (
            $item === "Others" &&
            hasValue($otherValue)
        ) {
            $displayValue =
                "Others: " .
                $otherValue;
        }

        $html .=
            "<li>" .
            e($displayValue) .
            "</li>";
    }

    /*
     * Show the other value even when the array failed
     * to contain the Others entry.
     */
    if (
        hasValue($otherValue) &&
        !in_array("Others", $items, true)
    ) {
        $html .=
            "<li>" .
            e("Others: " . $otherValue) .
            "</li>";
    }

    $html .= "</ul>";

    return $html;
}

function displayValue(
    $value,
    string $fallback = "Not specified"
): string {
    return hasValue($value)
        ? nl2br(e($value))
        : '<span class="text-muted">' .
        e($fallback) .
        '</span>';
}

function displayDate(
    $value,
    string $fallback = "Not specified"
): string {
    if (!hasValue($value)) {
        return '<span class="text-muted">' .
            e($fallback) .
            '</span>';
    }

    $timestamp = strtotime(
        (string) $value
    );

    if ($timestamp === false) {
        return e($value);
    }

    return date(
        "F d, Y",
        $timestamp
    );
}

function displayDateTime(
    $value,
    string $fallback = "Not specified"
): string {
    if (!hasValue($value)) {
        return '<span class="text-muted">' .
            e($fallback) .
            '</span>';
    }

    $timestamp = strtotime(
        (string) $value
    );

    if ($timestamp === false) {
        return e($value);
    }

    return date(
        "F d, Y g:i A",
        $timestamp
    );
}

function yesNoValue(
    $value,
    string $fallback = "Not specified"
): string {
    if ($value === null || $value === "") {
        return '<span class="text-muted">' .
            e($fallback) .
            '</span>';
    }

    return (int) $value === 1
        ? '<span class="badge bg-success">Yes</span>'
        : '<span class="badge bg-secondary">No</span>';
}

/* =========================================================
   DECODE SAVED ARRAYS
========================================================= */
$requestTypes = safeArray(
    $request["request_types"] ?? null
);

$graphicDesignTypes = safeArray(
    $request["graphic_design_types"] ?? null
);

$graphicColors = safeArray(
    $request["graphic_colors"] ?? null
);

$publicationMethods = safeArray(
    $request["publication_methods"] ?? null
);

$videoTypes = safeArray(
    $request["video_types"] ?? null
);

$videoFormats = safeArray(
    $request["video_formats"] ?? null
);

$coverageFormats = safeArray(
    $request["coverage_formats"] ?? null
);

/* =========================================================
   CONDITIONAL SECTIONS
========================================================= */
$showGraphic =
    in_array(
        "Graphic Design",
        $requestTypes,
        true
    )
    || !empty($graphicDesignTypes)
    || !empty($graphicColors)
    || !empty($publicationMethods)
    || hasValue(
        $request["graphic_design_other"] ?? ""
    )
    || hasValue(
        $request["publication_other"] ?? ""
    )
    || (int) (
        $request["graphic_quantity"] ?? 0
    ) > 0;

$showVideo =
    in_array(
        "Video Production",
        $requestTypes,
        true
    )
    || !empty($videoTypes)
    || !empty($videoFormats)
    || hasValue(
        $request["video_type_other"] ?? ""
    )
    || hasValue(
        $request["video_format_other"] ?? ""
    )
    || (int) (
        $request["video_quantity"] ?? 0
    ) > 0;

$showCoverage =
    in_array(
        "Photo/Video Coverage",
        $requestTypes,
        true
    )
    || (int) (
        $request["photographers_needed"] ?? 0
    ) > 0
    || hasValue(
        $request["specific_shots"] ?? ""
    )
    || !empty($coverageFormats)
    || hasValue(
        $request["coverage_format_other"] ?? ""
    )
    || (int) (
        $request["coverage_quantity"] ?? 0
    ) > 0;

$showIec =
    in_array(
        "IEC Materials",
        $requestTypes,
        true
    )
    || !empty($iecItems);

/* =========================================================
   STATUS STYLE
========================================================= */
$status = $request["approval_status"]
    ?? "Pending Review";

$statusClass = match ($status) {
    "Approved" =>
        "bg-success",

    "Completed" =>
        "bg-primary",

    "For Evaluation" =>
        "bg-warning text-dark",

    "Closed" =>
        "bg-dark",

    "Disapproved" =>
        "bg-danger",

    "For Revision" =>
        "bg-warning text-dark",

    "Under Review" =>
        "bg-info text-dark",

    default =>
        "bg-secondary"
};

/* =========================================================
   REVIEW SECTION VISIBILITY
========================================================= */
$showReviewSection =
    hasValue(
        $request["reviewed_by"] ?? ""
    )
    || hasValue(
        $request["reviewed_date"] ?? ""
    )
    || hasValue(
        $request["approved_by"] ?? ""
    )
    || hasValue(
        $request["approved_date"] ?? ""
    )
    || !empty(
    $request["disapproved"] ?? 0
)
    || hasValue(
        $request["disapproved_reason"] ?? ""
    )
    || hasValue(
        $request["initial_draft_date"] ?? ""
    )
    || hasValue(
        $request["final_approval_date"] ?? ""
    )
    || array_key_exists(
        "revision_required",
        $request
    )
    || (int) (
        $request["revision_count"] ?? 0
    ) > 0
    || hasValue(
        $request["distribution_mode"] ?? ""
    );

$evaluationSubmitted =
    array_key_exists(
        "satisfaction_rating",
        $request
    )
    && $request["satisfaction_rating"] !== null;

/* =========================================================
   DISPLAY-ONLY WORKFLOW SUMMARY (NO DATABASE CHANGES)
========================================================= */
$workflowStages = [
    "Pending Review" => ["label" => "Submitted", "icon" => "bi-send-check"],
    "Under Review" => ["label" => "Under Review", "icon" => "bi-search"],
    "Approved" => ["label" => "Approved", "icon" => "bi-patch-check"],
    "In Development" => ["label" => "In Development", "icon" => "bi-tools"],
    "Completed" => ["label" => "Completed", "icon" => "bi-check2-all"],
    "For Evaluation" => ["label" => "For Evaluation", "icon" => "bi-clipboard2-check"],
    "Closed" => ["label" => "Closed", "icon" => "bi-lock"],
];

$workflowKeys = array_keys($workflowStages);
$currentStageIndex = array_search($status, $workflowKeys, true);
if ($currentStageIndex === false) {
    $currentStageIndex = 0;
}

$progressPercent = (int) round((($currentStageIndex + 1) / count($workflowKeys)) * 100);
if ($status === "Disapproved") {
    $progressPercent = 0;
}
if ($status === "For Revision") {
    $progressPercent = 35;
}

$requestedTimestamp = !empty($request["date_request"])
    ? strtotime((string) $request["date_request"])
    : false;
$ageDays = $requestedTimestamp
    ? max(0, (int) floor((time() - $requestedTimestamp) / 86400))
    : null;

$completionTimestamp = !empty($request["date_completion"])
    ? strtotime((string) $request["date_completion"])
    : false;
$remainingDays = $completionTimestamp
    ? (int) floor(($completionTimestamp - strtotime("today")) / 86400)
    : null;

$evaluationLabel = $evaluationSubmitted
    ? "Submitted"
    : (in_array($status, ["Completed", "For Evaluation"], true)
        ? "Pending"
        : "Not Available");
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= e($request["job_no"]) ?>
        | Job Request
    </title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">

    <style>
        :root {
            --ui-primary: #1769ff;
            --ui-primary-dark: #0f4fd6;
            --ui-primary-soft: #eef5ff;
            --ui-success: #16a263;
            --ui-warning: #f59e0b;
            --ui-danger: #dc3545;
            --ui-purple: #7c3aed;
            --ui-text: #10213e;
            --ui-muted: #64748b;
            --ui-border: #dfe7f2;
            --ui-surface: #ffffff;
            --ui-soft: #f7f9fd;
            --ui-shadow: 0 14px 36px rgba(16, 33, 62, .07);
        }

        body {
            background: #f3f6fb;
            color: var(--ui-text);
        }

        .request-view-page {
            max-width: 1540px;
            margin: 0 auto;
        }

        .content {
            padding: 20px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
            padding: 9px 14px;
            border: 1px solid var(--ui-border);
            border-radius: 10px;
            background: #fff;
            color: var(--ui-primary);
            font-weight: 700;
            text-decoration: none;
        }

        .back-link:hover {
            background: var(--ui-primary-soft);
            color: var(--ui-primary-dark);
        }

        .request-hero {
            overflow: hidden;
            margin-bottom: 18px;
            border: 1px solid var(--ui-border);
            border-radius: 18px;
            background: linear-gradient(135deg, #ffffff 0%, #f7fbff 100%);
            box-shadow: var(--ui-shadow);
        }

        .hero-main {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto 140px;
            gap: 24px;
            align-items: center;
            padding: 24px;
        }

        .hero-title {
            margin: 0 0 8px;
            font-size: clamp(1.4rem, 2.4vw, 2rem);
            font-weight: 850;
            letter-spacing: -.025em;
        }

        .hero-meta {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .job-chip,
        .type-chip,
        .priority-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 8px;
            font-size: .75rem;
            font-weight: 800;
        }

        .job-chip {
            background: #e8f1ff;
            color: var(--ui-primary);
        }

        .type-chip {
            background: #f2eaff;
            color: var(--ui-purple);
        }

        .priority-chip {
            background: #fff1f2;
            color: #dc2626;
        }

        .hero-status {
            min-width: 180px;
            padding-left: 22px;
            border-left: 1px solid var(--ui-border);
        }

        .eyebrow {
            color: var(--ui-muted);
            font-size: .67rem;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .hero-status .badge {
            margin-top: 7px;
            padding: 9px 12px;
            border-radius: 999px;
        }

        .progress-ring {
            width: 106px;
            height: 106px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: conic-gradient(var(--ui-primary) calc(var(--progress) * 1%), #e9eef6 0);
            position: relative;
            margin-inline: auto;
        }

        .progress-ring::before {
            content: "";
            position: absolute;
            inset: 9px;
            border-radius: 50%;
            background: #fff;
        }

        .progress-ring strong {
            position: relative;
            z-index: 1;
            font-size: 1.4rem;
        }

        .progress-caption {
            margin-top: 7px;
            color: var(--ui-muted);
            font-size: .72rem;
            font-weight: 700;
            text-align: center;
        }

        .hero-facts {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            border-top: 1px solid var(--ui-border);
            background: rgba(255, 255, 255, .75);
        }

        .hero-fact {
            display: grid;
            grid-template-columns: 38px minmax(0, 1fr);
            gap: 10px;
            padding: 16px 20px;
        }

        .hero-fact+.hero-fact {
            border-left: 1px solid var(--ui-border);
        }

        .fact-icon {
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            background: var(--ui-primary-soft);
            color: var(--ui-primary);
        }

        .fact-label {
            color: var(--ui-muted);
            font-size: .66rem;
            font-weight: 800;
            text-transform: uppercase;
        }

        .fact-value {
            margin-top: 2px;
            font-size: .82rem;
            font-weight: 800;
        }

        .fact-note {
            margin-top: 2px;
            color: var(--ui-success);
            font-size: .69rem;
        }

        .workflow-panel {
            margin-bottom: 18px;
            padding: 20px;
            border: 1px solid var(--ui-border);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 8px 24px rgba(16, 33, 62, .05);
        }

        .workflow-track {
            display: grid;
            grid-template-columns: repeat(7, minmax(100px, 1fr));
            overflow-x: auto;
            padding: 4px 0 2px;
        }

        .workflow-step {
            position: relative;
            min-width: 108px;
            text-align: center;
        }

        .workflow-step:not(:last-child)::after {
            content: "";
            position: absolute;
            top: 18px;
            left: 58%;
            right: -42%;
            height: 3px;
            background: #dce4ef;
        }

        .workflow-step.done:not(:last-child)::after {
            background: var(--ui-success);
        }

        .workflow-node {
            position: relative;
            z-index: 2;
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            margin: 0 auto 8px;
            border: 2px solid #cbd5e1;
            border-radius: 50%;
            background: #fff;
            color: #94a3b8;
        }

        .workflow-step.done .workflow-node {
            border-color: var(--ui-success);
            background: var(--ui-success);
            color: #fff;
        }

        .workflow-step.current .workflow-node {
            border-color: var(--ui-primary);
            background: var(--ui-primary);
            color: #fff;
            box-shadow: 0 0 0 5px #dbeafe;
        }

        .workflow-label {
            font-size: .69rem;
            font-weight: 800;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .summary-card {
            display: grid;
            grid-template-columns: 42px minmax(0, 1fr);
            gap: 12px;
            padding: 16px;
            border: 1px solid var(--ui-border);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 8px 22px rgba(16, 33, 62, .04);
        }

        .summary-icon {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: var(--ui-primary-soft);
            color: var(--ui-primary);
            font-size: 1.1rem;
        }

        .summary-card:nth-child(2) .summary-icon {
            background: #eafaf2;
            color: var(--ui-success);
        }

        .summary-card:nth-child(3) .summary-icon {
            background: #f3eefe;
            color: var(--ui-purple);
        }

        .summary-card:nth-child(4) .summary-icon {
            background: #fff5e8;
            color: #ea7200;
        }

        .summary-label {
            color: var(--ui-muted);
            font-size: .66rem;
            font-weight: 800;
            text-transform: uppercase;
        }

        .summary-value {
            margin-top: 3px;
            font-size: .86rem;
            font-weight: 850;
        }

        .summary-note {
            margin-top: 3px;
            color: var(--ui-muted);
            font-size: .68rem;
        }

        .card {
            border: 1px solid var(--ui-border);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 10px 28px rgba(16, 33, 62, .05);
        }

        .section-banner {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 18px;
            border-bottom: 1px solid var(--ui-border);
            border-radius: 15px 15px 0 0;
            background: linear-gradient(90deg, #f0f6ff, #fff);
            color: #1d4ed8;
            font-size: .8rem;
            font-weight: 850;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .section-heading {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--hd-row-line, #edf1f6);
        }

        .section-icon {
            display: grid;
            width: 38px;
            height: 38px;
            place-items: center;
            flex-shrink: 0;
            border: 1px solid #cfe0f8;
            border-radius: 10px;
            background: var(--ui-primary-soft);
            color: var(--ui-primary);
            font-size: 17px;
        }

        .detail-box {
            height: 100%;
            padding: 16px;
            border: 1px solid #e5ebf3;
            border-radius: 12px;
            background: #fafcff;
            transition: .18s ease;
        }

        .detail-box:hover {
            transform: translateY(-1px);
            border-color: #bdd4ff;
            background: #fff;
            box-shadow: 0 7px 18px rgba(23, 105, 255, .06);
        }

        .detail-label {
            margin-bottom: 7px;
            color: var(--ui-muted);
            font-size: .65rem;
            font-weight: 850;
            letter-spacing: .055em;
            text-transform: uppercase;
        }

        .detail-list {
            padding-left: 20px;
        }

        .detail-list li {
            margin-bottom: 4px;
        }

        .service-section+.service-section {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--ui-border);
        }

        .service-title {
            display: flex;
            align-items: center;
            gap: 9px;
            margin-bottom: 16px;
            color: var(--ui-text);
            font-size: 1rem;
            font-weight: 850;
        }

        .service-title i {
            color: var(--ui-primary);
        }

        .attachment-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px;
            border: 1px dashed #8fb4f4;
            border-radius: 12px;
            background: #f6faff;
        }

        .attachment-info {
            min-width: 0;
        }

        .attachment-name {
            overflow: hidden;
            margin-bottom: 2px;
            font-weight: 750;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .attachment-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 8px;
        }

        .attachment-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 9px 11px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #fff;
        }

        .attachment-file-name {
            overflow: hidden;
            color: #263248;
            font-size: .76rem;
            font-weight: 650;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .attachment-item .btn {
            padding: 4px 10px;
            font-size: .72rem;
            border-radius: 8px;
        }

        .action-bar {
            position: sticky;
            bottom: 12px;
            z-index: 20;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 12px;
            border: 1px solid var(--ui-border);
            border-radius: 14px;
            background: rgba(255, 255, 255, .94);
            backdrop-filter: blur(12px);
            box-shadow: 0 14px 34px rgba(16, 33, 62, .14);
        }

        .action-bar .btn {
            border-radius: 10px;
            font-weight: 750;
        }

        @media (max-width: 1100px) {
            .hero-main {
                grid-template-columns: 1fr auto;
            }

            .progress-wrap {
                grid-column: 1 / -1;
            }

            .hero-facts,
            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .hero-fact:nth-child(3) {
                border-left: 0;
                border-top: 1px solid var(--ui-border);
            }

            .hero-fact:nth-child(4) {
                border-top: 1px solid var(--ui-border);
            }
        }

        @media (max-width: 700px) {
            .content {
                padding: 10px;
            }

            .hero-main {
                grid-template-columns: 1fr;
                padding: 18px;
            }

            .hero-status {
                padding-left: 0;
                border-left: 0;
            }

            .hero-facts,
            .summary-grid {
                grid-template-columns: 1fr;
            }

            .hero-fact+.hero-fact {
                border-left: 0;
                border-top: 1px solid var(--ui-border);
            }

            .workflow-panel {
                padding: 15px 10px;
            }

            .attachment-box {
                align-items: stretch;
                flex-direction: column;
            }

            .attachment-box .btn {
                width: 100%;
            }

            .action-bar {
                position: static;
            }
        }

        .qr-tracking-card {
            overflow: hidden;
        }

        .qr-tracking-layout {
            display: grid;
            grid-template-columns: minmax(260px, 360px) 1fr;
            gap: 28px;
            align-items: center;
        }

        .qr-code-shell {
            display: grid;
            place-items: center;
            min-height: 300px;
            border: 1px dashed var(--ui-border);
            border-radius: 18px;
            background: linear-gradient(145deg, rgba(37, 99, 235, .06), rgba(16, 185, 129, .06));
            padding: 24px;
        }

        .qr-code-canvas {
            width: 240px;
            min-height: 240px;
            display: grid;
            place-items: center;
            background: #fff;
            border-radius: 16px;
            padding: 10px;
            box-shadow: 0 14px 32px rgba(15, 23, 42, .12);
        }

        .qr-code-canvas img,
        .qr-code-canvas canvas {
            max-width: 100%;
            height: auto !important;
        }

        .tracking-url-group {
            display: flex;
            gap: 10px;
        }

        .tracking-url-group .form-control {
            min-width: 0;
        }

        .qr-feature-list {
            display: grid;
            gap: 10px;
            margin: 20px 0;
        }

        .qr-feature-item {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            color: var(--ui-muted);
        }

        .qr-feature-item i {
            color: #16a34a;
            margin-top: 2px;
        }

        .qr-unavailable {
            border: 1px solid rgba(245, 158, 11, .35);
            background: rgba(245, 158, 11, .08);
            color: #92400e;
            border-radius: 14px;
            padding: 16px;
        }

        @media (max-width: 820px) {
            .qr-tracking-layout {
                grid-template-columns: 1fr;
            }

            .qr-code-shell {
                min-height: 270px;
            }
        }

        @media print {
            body {
                background: #fff;
            }

            .sidebar,
            .no-print,
            .back-link {
                display: none !important;
            }

            .main {
                width: 100% !important;
                margin: 0 !important;
            }

            .content {
                padding: 0;
            }

            .request-view-page {
                max-width: none;
            }

            .card,
            .request-hero,
            .workflow-panel,
            .summary-card {
                break-inside: avoid;
                box-shadow: none !important;
            }
        }
    </style>

    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/request_view_phase3.css?v=3.0">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/request_chat.css?v=1.0">
    <style>
        /* Messages button unread badge (integrated notify) */
        #openConversationBtn { position: relative; }
        #convMsgBadge {
            position: absolute;
            top: -8px;
            right: -8px;
            display: inline-grid;
            min-width: 20px;
            height: 20px;
            place-items: center;
            padding: 0 5px;
            border: 2px solid #fff;
            border-radius: 999px;
            background: #d92d20;
            color: #fff;
            font-size: .62rem;
            font-weight: 900;
            line-height: 1;
        }
        #convMsgBadge.d-none { display: none !important; }
        #openConversationBtn.has-unread {
            border-color: #f04438;
            background: #fffbfa;
            animation: convBtnPulse 1.6s ease-in-out infinite;
        }
        @keyframes convBtnPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(180, 35, 24, .25); }
            50% { box-shadow: 0 0 0 4px rgba(180, 35, 24, 0); }
        }
        #convPreviewLine {
            display: flex;
            align-items: center;
            gap: 6px;
            max-width: 100%;
            margin: 6px 0 0;
            color: #7a2e0e;
            font-size: .72rem;
            font-weight: 600;
        }
        #convPreviewLine span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
    </style>
</head>

<body class="app-shell">

    <div class="d-flex min-vh-100">

        <?php include "../includes/sidebar.php"; ?>

        <div class="main flex-grow-1">

            <main class="content">

                <div class="container-fluid">

                    <div class="request-view-page">

                        <div class="phase3-toolbar no-print" aria-label="Request page tools">
                            <div class="phase3-toolbar-left">
                                <button type="button" class="phase3-tool-btn" id="sectionMenuButton"
                                    aria-expanded="false">
                                    <i class="bi bi-list-nested"></i> Sections
                                </button>
                                <div class="phase3-section-menu" id="sectionMenu" hidden></div>
                            </div>
                            <div class="phase3-toolbar-actions">
                                <button type="button" class="phase3-tool-btn" id="copyJobNumber"
                                    data-job-number="<?= e($request["job_no"] ?? "") ?>">
                                    <i class="bi bi-copy"></i> Copy Job No.
                                </button>
                                <button type="button" class="phase3-tool-btn" id="compactToggle" aria-pressed="false">
                                    <i class="bi bi-arrows-collapse"></i> Compact
                                </button>
                                <button type="button" class="phase3-tool-btn" id="themeToggle" aria-pressed="false">
                                    <i class="bi bi-moon-stars"></i> Dark
                                </button>
                                <?php if ($qrTrackingAvailable): ?>
                                    <a class="phase3-tool-btn" href="#qr-tracking">
                                        <i class="bi bi-qr-code"></i> QR Tracking
                                    </a>
                                <?php endif; ?>
                                <?php if (empty($readOnlyViewer)): ?>
                                <button type="button" class="phase3-tool-btn" data-bs-toggle="modal"
                                    data-bs-target="#activityLogModal" title="Open activity log timeline">
                                    <i class="bi bi-clock-history"></i> Activity Log
                                </button>
                                <span id="conversation" style="display:inline-flex;">
                                <button type="button" class="phase3-tool-btn <?= $convUnread > 0 ? "has-unread" : "" ?>" id="openConversationBtn" data-bs-toggle="modal"
                                    data-bs-target="#commCenterModal" title="<?= e($convBtnTitle) ?>">
                                    <i class="bi bi-chat-dots"></i> Messages
                                    <?php if ($convTotal > 0): ?>
                                        <span class="text-muted fw-normal">(<?= (int) $convTotal ?>)</span>
                                    <?php endif; ?>
                                    <span id="convMsgBadge" class="<?= $convUnread > 0 ? "" : "d-none" ?>"><?= $convUnread > 99 ? "99+" : (int) $convUnread ?></span>
                                </button>
                                </span>
                                <a class="phase3-tool-btn phase3-tool-primary"
                                    href="<?= e(BASE_URL) ?>/user/print_request.php?id=<?= (int) $request["id"] ?>"
                                    target="_blank" rel="noopener noreferrer" title="Open the print-ready request form">
                                    <i class="bi bi-printer"></i> Print
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="phase3-scroll-progress no-print"><span id="pageScrollProgress"></span></div>

                        <a class="back-link no-print" href="javascript:history.back();">
                            <i class="bi bi-arrow-left"></i>
                            Back to Requests
                        </a>

                        <section class="request-hero">
                            <div class="hero-main">
                                <div>
                                    <h1 class="hero-title">
                                        <?= displayValue($request["project_title"] ?? "", "Untitled Project") ?>
                                    </h1>

                                    <div class="hero-meta">
                                        <span class="job-chip phase3-copyable" title="Click to copy"
                                            data-copy-value="<?= e($request["job_no"] ?? "") ?>">
                                            <i class="bi bi-hash"></i><?= e($request["job_no"]) ?>
                                            <i class="bi bi-copy ms-1"></i>
                                        </span>

                                        <?php foreach ($requestTypes as $requestType): ?>
                                            <span class="type-chip">
                                                <i class="bi bi-grid"></i><?= e($requestType) ?>
                                            </span>
                                        <?php endforeach; ?>

                                        <?php if (hasValue($request["priority"] ?? "")): ?>
                                            <span class="priority-chip">
                                                <i class="bi bi-flag-fill"></i>
                                                Priority: <?= e($request["priority"]) ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (!empty($readOnlyViewer)): ?>
                                            <span class="type-chip" title="Submitted by an office colleague — read only">
                                                <i class="bi bi-people"></i>Same Office · View only
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="hero-status">
                                    <div class="eyebrow">Current Status</div>
                                    <span class="badge <?= $statusClass ?>">
                                        <i class="bi bi-activity me-1"></i><?= e($status) ?>
                                    </span>
                                    <div class="text-muted small mt-2">
                                        Request age:
                                        <?= $ageDays !== null ? number_format($ageDays) . " day(s)" : "N/A" ?>
                                    </div>
                                </div>

                                <div class="progress-wrap">
                                    <div class="progress-ring" style="--progress: <?= $progressPercent ?>">
                                        <strong><?= $progressPercent ?>%</strong>
                                    </div>
                                    <div class="progress-caption">Overall Progress</div>
                                </div>
                            </div>

                            <div class="hero-facts">
                                <div class="hero-fact">
                                    <div class="fact-icon"><i class="bi bi-calendar-plus"></i></div>
                                    <div>
                                        <div class="fact-label">Submitted</div>
                                        <div class="fact-value">
                                            <?= displayDateTime($request["created_at"] ?? $request["date_request"] ?? "") ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="hero-fact">
                                    <div class="fact-icon"><i class="bi bi-calendar-check"></i></div>
                                    <div>
                                        <div class="fact-label">Preferred Completion</div>
                                        <div class="fact-value"><?= displayDate($request["date_completion"] ?? "") ?>
                                        </div>
                                        <?php if ($remainingDays !== null && !in_array($status, ["Completed", "For Evaluation", "Closed"], true)): ?>
                                            <div class="fact-note">
                                                <?= $remainingDays >= 0 ? $remainingDays . " day(s) remaining" : abs($remainingDays) . " day(s) past target" ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="hero-fact">
                                    <div class="fact-icon"><i class="bi bi-person"></i></div>
                                    <div>
                                        <div class="fact-label">Requested By</div>
                                        <div class="fact-value"><?= displayValue($request["requestor_name"] ?? "") ?>
                                        </div>
                                        <div class="text-muted small"><?= displayValue($request["department"] ?? "") ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="hero-fact">
                                    <div class="fact-icon"><i class="bi bi-clipboard2-check"></i></div>
                                    <div>
                                        <div class="fact-label">Evaluation</div>
                                        <div class="fact-value"><?= e($evaluationLabel) ?></div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="workflow-panel">
                            <div class="workflow-track">
                                <?php foreach ($workflowStages as $stageKey => $stageInfo): ?>
                                    <?php
                                    $stageIndex = array_search($stageKey, $workflowKeys, true);
                                    $isDone = $stageIndex < $currentStageIndex || $status === "Closed";
                                    $isCurrent = $stageKey === $status;
                                    ?>
                                    <div
                                        class="workflow-step <?= $isDone ? "done" : "" ?> <?= $isCurrent ? "current" : "" ?>">
                                        <div class="workflow-node">
                                            <i class="bi <?= e($stageInfo["icon"]) ?>"></i>
                                        </div>
                                        <div class="workflow-label"><?= e($stageInfo["label"]) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <div class="summary-grid">
                            <div class="summary-card">
                                <div class="summary-icon"><i class="bi bi-person-check"></i></div>
                                <div>
                                    <div class="summary-label">Reviewed By</div>
                                    <div class="summary-value">
                                        <?= displayValue($request["reviewed_by"] ?? "", "Not yet reviewed") ?>
                                    </div>
                                    <div class="summary-note">
                                        <?= displayDate($request["reviewed_date"] ?? "", "Pending") ?>
                                    </div>
                                </div>
                            </div>
                            <div class="summary-card">
                                <div class="summary-icon"><i class="bi bi-patch-check"></i></div>
                                <div>
                                    <div class="summary-label">Approved By</div>
                                    <div class="summary-value">
                                        <?= displayValue($request["approved_by"] ?? "", "Not yet approved") ?>
                                    </div>
                                    <div class="summary-note">
                                        <?= displayDate($request["approved_date"] ?? "", "Pending") ?>
                                    </div>
                                </div>
                            </div>
                            <div class="summary-card">
                                <div class="summary-icon"><i class="bi bi-calendar-event"></i></div>
                                <div>
                                    <div class="summary-label">Target Completion</div>
                                    <div class="summary-value"><?= displayDate($request["date_completion"] ?? "") ?>
                                    </div>
                                    <div class="summary-note">
                                        <?= $remainingDays !== null ? ($remainingDays >= 0 ? $remainingDays . " day(s) remaining" : abs($remainingDays) . " day(s) past target") : "No target date" ?>
                                    </div>
                                </div>
                            </div>
                            <div class="summary-card">
                                <div class="summary-icon"><i class="bi bi-star"></i></div>
                                <div>
                                    <div class="summary-label">Evaluation Status</div>
                                    <div class="summary-value"><?= e($evaluationLabel) ?></div>
                                    <div class="summary-note">
                                        <?= $evaluationSubmitted ? "Feedback received" : "Awaiting user feedback" ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <section class="phase3-insights no-print" aria-label="Request insights">
                            <div class="phase3-insight">
                                <i class="bi bi-clock-history"></i>
                                <div><span>Request
                                        Age</span><strong><?= $ageDays !== null ? number_format($ageDays) . " day(s)" : "N/A" ?></strong>
                                </div>
                            </div>
                            <div class="phase3-insight">
                                <i class="bi bi-arrow-repeat"></i>
                                <div><span>Revision
                                        Count</span><strong><?= number_format((int) ($request["revision_count"] ?? 0)) ?></strong>
                                </div>
                            </div>
                            <div class="phase3-insight">
                                <i class="bi bi-person-badge"></i>
                                <div><span>Current
                                        Owner</span><strong><?= displayValue($request["assigned_to"] ?? $request["reviewed_by"] ?? "", "Unassigned") ?></strong>
                                </div>
                            </div>
                            <div class="phase3-insight">
                                <i class="bi bi-shield-check"></i>
                                <div><span>Record Status</span><strong>Verified View</strong></div>
                            </div>
                        </section>

                        <!-- I. JOB DETAILS -->
                        <section class="card mb-4">

                            <div class="section-banner">
                                I. Job Details
                            </div>


                            <div class="card-body">

                                <div class="row g-3">

                                    <div class="col-md-6">
                                        <div class="detail-box">
                                            <div class="detail-label">
                                                Requestor Name
                                            </div>

                                            <?= displayValue(
                                                $request["requestor_name"] ?? ""
                                            ) ?>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="detail-box">
                                            <div class="detail-label">
                                                Department
                                            </div>

                                            <?= displayValue(
                                                $request["department"] ?? ""
                                            ) ?>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="detail-box">
                                            <div class="detail-label">
                                                Contact Number
                                            </div>

                                            <?= displayValue(
                                                $request["contact"] ?? ""
                                            ) ?>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="detail-box">
                                            <div class="detail-label">
                                                Date Requested
                                            </div>

                                            <?= displayDate(
                                                $request["date_request"] ?? ""
                                            ) ?>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="detail-box">
                                            <div class="detail-label">
                                                Preferred Completion
                                            </div>

                                            <?= displayDate(
                                                $request["date_completion"] ?? ""
                                            ) ?>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="detail-box">
                                            <div class="detail-label">
                                                Type of Request
                                            </div>

                                            <?= displayList(
                                                $requestTypes
                                            ) ?>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="detail-box">
                                            <div class="detail-label">
                                                Title / Project Name
                                            </div>

                                            <?= displayValue(
                                                $request["project_title"] ?? ""
                                            ) ?>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="detail-box">
                                            <div class="detail-label">
                                                Purpose / Objective
                                            </div>

                                            <?= displayValue(
                                                $request["purpose_objective"] ?? ""
                                            ) ?>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="detail-box">
                                            <div class="detail-label">
                                                Event Date & Time
                                            </div>

                                            <?php if (
                                                !empty(
                                                $request[
                                                    "event_not_applicable"
                                                ]
                                            )
                                            ): ?>

                                                <span class="badge bg-secondary">
                                                    N/A
                                                </span>

                                            <?php else: ?>

                                                <?= e(
                                                    formatEventRange(
                                                        $request["event_datetime"] ?? "",
                                                        $request["event_datetime_end"] ?? ""
                                                    )
                                                ) ?>

                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="detail-box">
                                            <div class="detail-label">
                                                Venue / Location
                                            </div>

                                            <?php if (
                                                !empty(
                                                $request[
                                                    "venue_not_applicable"
                                                ]
                                            )
                                            ): ?>

                                                <span class="badge bg-secondary">
                                                    N/A
                                                </span>

                                            <?php else: ?>

                                                <?= displayValue(
                                                    $request["venue_location"] ?? ""
                                                ) ?>

                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="detail-box">
                                            <div class="detail-label">
                                                Send Them To
                                            </div>

                                            <?php if (
                                                !empty(
                                                $request[
                                                    "send_to_not_applicable"
                                                ]
                                            )
                                            ): ?>

                                                <span class="badge bg-secondary">
                                                    N/A
                                                </span>

                                            <?php else: ?>

                                                <?= displayValue(
                                                    $request["send_to"] ?? ""
                                                ) ?>

                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="detail-box">
                                            <div class="detail-label">
                                                Requestor Signature Date
                                            </div>

                                            <?= displayDate(
                                                $request[
                                                    "requestor_signature_date"
                                                ] ?? ""
                                            ) ?>
                                        </div>
                                    </div>

                                </div>

                            </div>

                        </section>

                        <!-- II. SPECIFICATIONS -->
                        <?php if (
                            $showGraphic ||
                            $showVideo ||
                            $showCoverage ||
                            $showIec
                        ): ?>

                            <section class="card mb-4">

                                <div class="section-banner">
                                    II. Job Description & Specifications
                                </div>

                                <div class="card-body">

                                    <!-- GRAPHIC DESIGN -->
                                    <?php if ($showGraphic): ?>

                                        <div class="service-section">

                                            <h4 class="service-title">
                                                <i class="bi bi-palette"></i>
                                                GRAPHIC DESIGN
                                            </h4>

                                            <div class="row g-3">

                                                <div class="col-md-4">
                                                    <div class="detail-box">

                                                        <div class="detail-label">
                                                            Type of Design
                                                        </div>

                                                        <?= displayList(
                                                            $graphicDesignTypes,
                                                            $request[
                                                                "graphic_design_other"
                                                            ] ?? null
                                                        ) ?>

                                                    </div>
                                                </div>

                                                <div class="col-md-4">
                                                    <div class="detail-box">

                                                        <div class="detail-label">
                                                            Color
                                                        </div>

                                                        <?= displayList(
                                                            $graphicColors
                                                        ) ?>

                                                    </div>
                                                </div>

                                                <div class="col-md-4">
                                                    <div class="detail-box">

                                                        <div class="detail-label">
                                                            Publication Method
                                                        </div>

                                                        <?= displayList(
                                                            $publicationMethods,
                                                            $request[
                                                                "publication_other"
                                                            ] ?? null
                                                        ) ?>

                                                        <hr>

                                                        <strong>Quantity:</strong>

                                                        <?= number_format(
                                                            (int) (
                                                                $request[
                                                                    "graphic_quantity"
                                                                ] ?? 0
                                                            )
                                                        ) ?>

                                                    </div>
                                                </div>

                                            </div>

                                        </div>

                                    <?php endif; ?>

                                    <!-- VIDEO PRODUCTION -->
                                    <?php if ($showVideo): ?>

                                        <div class="service-section">

                                            <h4 class="service-title">
                                                <i class="bi bi-camera-reels"></i>
                                                VIDEO PRODUCTION
                                            </h4>

                                            <div class="row g-3">

                                                <div class="col-md-5">
                                                    <div class="detail-box">

                                                        <div class="detail-label">
                                                            Type of Video
                                                        </div>

                                                        <?= displayList(
                                                            $videoTypes,
                                                            $request[
                                                                "video_type_other"
                                                            ] ?? null
                                                        ) ?>

                                                    </div>
                                                </div>

                                                <div class="col-md-4">
                                                    <div class="detail-box">

                                                        <div class="detail-label">
                                                            Format
                                                        </div>

                                                        <?= displayList(
                                                            $videoFormats,
                                                            $request[
                                                                "video_format_other"
                                                            ] ?? null
                                                        ) ?>

                                                    </div>
                                                </div>

                                                <div class="col-md-3">
                                                    <div class="detail-box">

                                                        <div class="detail-label">
                                                            Quantity
                                                        </div>

                                                        <?= number_format(
                                                            (int) (
                                                                $request[
                                                                    "video_quantity"
                                                                ] ?? 0
                                                            )
                                                        ) ?>

                                                    </div>
                                                </div>

                                            </div>

                                        </div>

                                    <?php endif; ?>

                                    <!-- PHOTO / VIDEO COVERAGE -->
                                    <?php if ($showCoverage): ?>

                                        <div class="service-section">

                                            <h4 class="service-title">
                                                <i class="bi bi-camera"></i>
                                                PHOTO / VIDEO COVERAGE
                                            </h4>

                                            <div class="row g-3">

                                                <div class="col-md-4">
                                                    <div class="detail-box">

                                                        <div class="detail-label">
                                                            Photographers Needed
                                                        </div>

                                                        <?= number_format(
                                                            (int) (
                                                                $request[
                                                                    "photographers_needed"
                                                                ] ?? 0
                                                            )
                                                        ) ?>

                                                    </div>
                                                </div>

                                                <div class="col-md-8">
                                                    <div class="detail-box">

                                                        <div class="detail-label">
                                                            Specific Shots Required
                                                        </div>

                                                        <?= displayValue(
                                                            $request[
                                                                "specific_shots"
                                                            ] ?? ""
                                                        ) ?>

                                                    </div>
                                                </div>

                                                <div class="col-md-6">
                                                    <div class="detail-box">

                                                        <div class="detail-label">
                                                            Image / Video Format
                                                        </div>

                                                        <?= displayList(
                                                            $coverageFormats,
                                                            $request[
                                                                "coverage_format_other"
                                                            ] ?? null
                                                        ) ?>

                                                    </div>
                                                </div>

                                                <div class="col-md-6">
                                                    <div class="detail-box">

                                                        <div class="detail-label">
                                                            Quantity
                                                        </div>

                                                        <?= number_format(
                                                            (int) (
                                                                $request[
                                                                    "coverage_quantity"
                                                                ] ?? 0
                                                            )
                                                        ) ?>

                                                    </div>
                                                </div>

                                            </div>

                                        </div>

                                    <?php endif; ?>

                                    <!-- IEC MATERIALS -->
                                    <?php if ($showIec): ?>

                                        <div class="service-section">

                                            <h4 class="service-title">
                                                <i class="bi bi-journals"></i>
                                                IEC MATERIALS
                                            </h4>

                                            <?php if (!empty($iecItems)): ?>

                                                <div class="table-responsive">

                                                    <table class="table table-bordered align-middle mb-0">

                                                        <thead class="table-light">

                                                            <tr>
                                                                <th>
                                                                    Requested IEC
                                                                </th>

                                                                <th width="130" class="text-end">
                                                                    Requested
                                                                </th>

                                                                <th width="150" class="text-end">
                                                                    Fulfilled
                                                                </th>
                                                            </tr>

                                                        </thead>

                                                        <tbody>

                                                            <?php foreach (
                                                                $iecItems as $iecItem
                                                            ): ?>

                                                                <?php
                                                                $requestedQty =
                                                                    (int) (
                                                                        $iecItem[
                                                                            "quantity"
                                                                        ]
                                                                        ?? 0
                                                                    );

                                                                $fulfilledQty =
                                                                    (int) (
                                                                        $iecItem[
                                                                            "fulfilled_qty"
                                                                        ]
                                                                        ?? 0
                                                                    );
                                                                ?>

                                                                <tr>
                                                                    <td>
                                                                        <?= e(
                                                                            $iecItem[
                                                                                "material_name"
                                                                            ]
                                                                        ) ?>
                                                                    </td>

                                                                    <td class="text-end">
                                                                        <strong>
                                                                            <?= number_format(
                                                                                $requestedQty
                                                                            ) ?>
                                                                        </strong>
                                                                        <span class="text-muted">
                                                                            pcs
                                                                        </span>
                                                                    </td>

                                                                    <td class="text-end">
                                                                        <strong
                                                                            class="<?= $fulfilledQty > 0 ? "text-success" : "text-muted" ?>">
                                                                            <?= number_format(
                                                                                $fulfilledQty
                                                                            ) ?>
                                                                        </strong>
                                                                        <span class="text-muted">
                                                                            pcs
                                                                        </span>

                                                                        <?php if (
                                                                            $fulfilledQty
                                                                            >=
                                                                            $requestedQty
                                                                            &&
                                                                            $fulfilledQty > 0
                                                                        ): ?>
                                                                            <div class="small text-success fw-semibold">
                                                                                <i class="bi bi-check-circle-fill me-1"></i>
                                                                                Fulfilled
                                                                            </div>
                                                                        <?php elseif (
                                                                            $fulfilledQty > 0
                                                                        ): ?>
                                                                            <div class="small text-warning fw-semibold">
                                                                                <i class="bi bi-hourglass-split me-1"></i>
                                                                                Partially fulfilled
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>

                                                            <?php endforeach; ?>

                                                        </tbody>

                                                    </table>

                                                </div>

                                            <?php else: ?>

                                                <p class="text-muted mb-0">
                                                    No IEC items were recorded.
                                                </p>

                                            <?php endif; ?>

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </section>

                        <?php endif; ?>

                        <!-- ADDITIONAL DETAILS -->
                        <?php if (
                            hasValue(
                                $request["request_details"] ?? ""
                            )
                            || hasValue(
                                $request["attachment"] ?? ""
                            )
                        ): ?>

                            <section class="card mb-4">

                                <div class="card-body">

                                    <?php if (
                                        hasValue(
                                            $request["request_details"] ?? ""
                                        )
                                    ): ?>

                                        <div class="section-heading">

                                            <span class="section-icon">
                                                <i class="bi bi-card-text"></i>
                                            </span>

                                            <div>
                                                <h4 class="mb-0">
                                                    Additional Request Details
                                                </h4>
                                            </div>

                                        </div>

                                        <div class="detail-box mb-4">

                                            <?= displayValue(
                                                $request["request_details"]
                                            ) ?>

                                        </div>

                                    <?php endif; ?>

                                    <?php if (
                                        hasValue(
                                            $request["attachment"] ?? ""
                                        )
                                    ):
                                        $attachments = attachmentList(
                                            (string) $request["attachment"]
                                        );
                                        ?>

                                        <div class="attachment-box">

                                            <div class="attachment-info">

                                                <div class="detail-label">
                                                    Attachments (<?= count($attachments) ?>)
                                                </div>

                                                <div class="attachment-list">

                                                    <?php foreach ($attachments as $attachmentName): ?>
                                                        <?php
                                                        $fileUrl = uploadFileUrl("", $attachmentName);
                                                        $fileExt = strtolower(pathinfo($attachmentName, PATHINFO_EXTENSION));

                                                        if ($fileExt === "pdf") {
                                                            $badgeIcon = "bi-file-earmark-pdf-fill";
                                                            $badgeColor = "text-danger";
                                                        } elseif (in_array($fileExt, ["doc", "docx"], true)) {
                                                            $badgeIcon = "bi-file-earmark-word-fill";
                                                            $badgeColor = "text-primary";
                                                        } elseif (in_array($fileExt, ["xls", "xlsx", "csv"], true)) {
                                                            $badgeIcon = "bi-file-earmark-excel-fill";
                                                            $badgeColor = "text-success";
                                                        } elseif (in_array($fileExt, ["ppt", "pptx"], true)) {
                                                            $badgeIcon = "bi-file-earmark-ppt-fill";
                                                            $badgeColor = "text-warning";
                                                        } elseif (in_array($fileExt, ["jpg", "jpeg", "png", "webp", "gif"], true)) {
                                                            $badgeIcon = "bi-file-earmark-image-fill";
                                                            $badgeColor = "text-info";
                                                        } elseif (in_array($fileExt, ["mp4", "mov", "webm"], true)) {
                                                            $badgeIcon = "bi-file-earmark-play-fill";
                                                            $badgeColor = "text-indigo";
                                                        } else {
                                                            $badgeIcon = "bi-file-earmark-text-fill";
                                                            $badgeColor = "text-secondary";
                                                        }
                                                        ?>

                                                        <div class="attachment-item">

                                                            <div class="attachment-file-name d-flex align-items-center gap-2">
                                                                <i class="bi <?= $badgeIcon ?> <?= $badgeColor ?> fs-5" aria-hidden="true"></i>
                                                                <span class="fw-bold"><?= e($attachmentName) ?></span>
                                                                <span class="badge bg-light text-secondary border text-uppercase" style="font-size: 0.65rem;"><?= e($fileExt) ?></span>
                                                            </div>

                                                            <div class="attachment-actions d-flex align-items-center gap-1">
                                                                <button
                                                                    type="button"
                                                                    class="btn btn-sm btn-primary file-preview-btn me-1"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#phase3PreviewModal"
                                                                    data-file-name="<?= e($attachmentName) ?>"
                                                                    data-file-url="<?= e($fileUrl) ?>"
                                                                    data-file-ext="<?= e($fileExt) ?>"
                                                                    title="Preview <?= e($attachmentName) ?>"
                                                                >
                                                                    <i class="bi bi-eye me-1"></i>
                                                                    View
                                                                </button>

                                                                <a
                                                                    class="btn btn-sm btn-outline-secondary no-preview"
                                                                    href="<?= e($fileUrl) ?>"
                                                                    download="<?= e($attachmentName) ?>"
                                                                    title="Download <?= e($attachmentName) ?>"
                                                                >
                                                                    <i class="bi bi-download me-1"></i>
                                                                    Download
                                                                </a>
                                                            </div>

                                                        </div>

                                                    <?php endforeach; ?>

                                                </div>

                                            </div>

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </section>

                        <?php endif; ?>

                        <!-- REVIEW AND APPROVAL -->
                        <?php if ($showReviewSection): ?>

                            <section class="card mb-4">

                                <div class="card-body">

                                    <div class="section-heading">

                                        <span class="section-icon">
                                            <i class="bi bi-clipboard-check"></i>
                                        </span>

                                        <div>
                                            <h4 class="mb-0">
                                                Review and Approval
                                            </h4>
                                        </div>

                                    </div>

                                    <div class="row g-3">

                                        <div class="col-md-6">
                                            <div class="detail-box">

                                                <div class="detail-label">
                                                    Reviewed By
                                                </div>

                                                <?= displayValue(
                                                    $request["reviewed_by"] ?? "",
                                                    "Not yet reviewed"
                                                ) ?>

                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="detail-box">

                                                <div class="detail-label">
                                                    Review Date
                                                </div>

                                                <?= displayDate(
                                                    $request["reviewed_date"] ?? "",
                                                    "Not yet reviewed"
                                                ) ?>

                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="detail-box">

                                                <div class="detail-label">
                                                    Approved By
                                                </div>

                                                <?= displayValue(
                                                    $request["approved_by"] ?? "",
                                                    "Not yet approved"
                                                ) ?>

                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="detail-box">

                                                <div class="detail-label">
                                                    Approval Date
                                                </div>

                                                <?= displayDate(
                                                    $request["approved_date"] ?? "",
                                                    "Not yet approved"
                                                ) ?>

                                            </div>
                                        </div>

                                        <?php if (
                                            !empty(
                                            $request["disapproved"] ?? 0
                                        )
                                            || hasValue(
                                                $request[
                                                    "disapproved_reason"
                                                ] ?? ""
                                            )
                                        ): ?>

                                            <div class="col-12">
                                                <div class="detail-box border-danger">

                                                    <div class="detail-label text-danger">
                                                        Disapproval / Revision Reason
                                                    </div>

                                                    <?= displayValue(
                                                        $request[
                                                            "disapproved_reason"
                                                        ] ?? ""
                                                    ) ?>

                                                </div>
                                            </div>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </section>

                        <?php endif; ?>

                        <!-- III DEVELOPMENT -->
                        <section class="card mb-4">

                            <div class="section-banner">
                                III. Development and Review
                            </div>

                            <div class="card-body">

                                <div class="row g-3">

                                    <div class="col-md-6">
                                        <div class="detail-box">

                                            <div class="detail-label">
                                                Date of Initial Draft Submission
                                            </div>

                                            <?= displayDate(
                                                $request[
                                                    "initial_draft_date"
                                                ] ?? ""
                                            ) ?>

                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="detail-box">

                                            <div class="detail-label">
                                                Final Design Approval Date
                                            </div>

                                            <?= displayDate(
                                                $request[
                                                    "final_approval_date"
                                                ] ?? ""
                                            ) ?>

                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="detail-box">

                                            <div class="detail-label">
                                                Revision Required
                                            </div>

                                            <?= yesNoValue(
                                                $request[
                                                    "revision_required"
                                                ] ?? null
                                            ) ?>

                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="detail-box">

                                            <div class="detail-label">
                                                Number of Revisions
                                            </div>

                                            <?= number_format(
                                                (int) (
                                                    $request[
                                                        "revision_count"
                                                    ] ?? 0
                                                )
                                            ) ?>

                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="detail-box">

                                            <div class="detail-label">
                                                Mode of Distribution
                                            </div>

                                            <?= displayValue(
                                                $request[
                                                    "distribution_mode"
                                                ] ?? "",
                                                "N/A"
                                            ) ?>

                                        </div>
                                    </div>

                                </div>

                            </div>

                        </section>

                        <!-- IV EVALUATION -->
                        <?php if ($evaluationSubmitted && empty($readOnlyViewer)): ?>

                            <section class="card mb-4">

                                <div class="section-banner">
                                    IV. Post Project Evaluation
                                </div>

                                <div class="card-body">

                                    <div class="row g-3">

                                        <div class="col-md-4">
                                            <div class="detail-box">

                                                <div class="detail-label">
                                                    Delivered On Time
                                                </div>

                                                <?= yesNoValue(
                                                    $request[
                                                        "delivered_on_time"
                                                    ] ?? null
                                                ) ?>

                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="detail-box">

                                                <div class="detail-label">
                                                    Objectives Met
                                                </div>

                                                <?= yesNoValue(
                                                    $request[
                                                        "objectives_met"
                                                    ] ?? null
                                                ) ?>

                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="detail-box">

                                                <div class="detail-label">
                                                    Challenges Encountered
                                                </div>

                                                <?= yesNoValue(
                                                    $request[
                                                        "challenges_encountered"
                                                    ] ?? null
                                                ) ?>

                                            </div>
                                        </div>

                                        <?php if (
                                            hasValue(
                                                $request[
                                                    "challenges_details"
                                                ] ?? ""
                                            )
                                        ): ?>

                                            <div class="col-12">
                                                <div class="detail-box">

                                                    <div class="detail-label">
                                                        Challenge Details
                                                    </div>

                                                    <?= displayValue(
                                                        $request[
                                                            "challenges_details"
                                                        ]
                                                    ) ?>

                                                </div>
                                            </div>

                                        <?php endif; ?>

                                        <div class="col-12">
                                            <div class="detail-box">

                                                <div class="detail-label">
                                                    Suggestions for Improvement
                                                </div>

                                                <?= displayValue(
                                                    $request[
                                                        "improvement_suggestions"
                                                    ] ?? "",
                                                    "N/A"
                                                ) ?>

                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="detail-box">

                                                <div class="detail-label">
                                                    Satisfaction Rating
                                                </div>

                                                <strong class="fs-5">
                                                    <?= (int) $request[
                                                        "satisfaction_rating"
                                                    ] ?>
                                                    / 5
                                                </strong>

                                            </div>
                                        </div>

                                    </div>

                                </div>

                            </section>

                        <?php endif; ?>

                        <!-- QR TRACKING -->
                        <section class="card qr-tracking-card mb-4" id="qr-tracking" data-section-title="QR Tracking">
                            <div class="card-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
                                <div>
                                    <div class="eyebrow">Public Status Access</div>
                                    <h3 class="h5 mb-0"><i class="bi bi-qr-code me-2"></i>QR Code Tracking</h3>
                                </div>
                                <?php if ($qrTrackingAvailable): ?>
                                    <span class="badge bg-success-subtle text-success-emphasis rounded-pill px-3 py-2">
                                        <i class="bi bi-shield-check me-1"></i>Secure public link ready
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="card-body p-4">
                                <?php if ($qrTrackingAvailable): ?>
                                    <div class="qr-tracking-layout">
                                        <div class="qr-code-shell">
                                            <div class="qr-code-canvas" id="requestQrCode"
                                                aria-label="Request tracking QR code"></div>
                                        </div>
                                        <div>
                                            <h4 class="h4 mb-2">Scan to track this request</h4>
                                            <p class="text-muted mb-0"><?= e($qrTrackingMessage) ?></p>
                                            <div class="qr-feature-list">
                                                <div class="qr-feature-item"><i
                                                        class="bi bi-check-circle-fill"></i><span>Shows the current workflow
                                                        status and completion progress.</span></div>
                                                <div class="qr-feature-item"><i
                                                        class="bi bi-check-circle-fill"></i><span>Does not expose private
                                                        requestor details or internal notes.</span></div>
                                                <div class="qr-feature-item"><i
                                                        class="bi bi-check-circle-fill"></i><span>Can be printed and
                                                        attached to request documents.</span></div>
                                            </div>
                                            <label class="form-label fw-semibold" for="trackingUrlInput">Public tracking
                                                link</label>
                                            <div class="tracking-url-group">
                                                <input id="trackingUrlInput" class="form-control" type="text"
                                                    value="<?= e($trackingUrl) ?>" readonly>
                                                <button class="btn btn-outline-primary" type="button"
                                                    id="copyTrackingUrl"><i class="bi bi-copy"></i><span
                                                        class="d-none d-sm-inline ms-1">Copy</span></button>
                                            </div>
                                            <div class="d-flex gap-2 flex-wrap mt-3 no-print">
                                                <a class="btn btn-primary" href="<?= e($trackingUrl) ?>" target="_blank"
                                                    rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Open
                                                    Tracking Page</a>
                                                <button class="btn btn-outline-secondary" type="button"
                                                    id="printQrButton"><i class="bi bi-printer me-1"></i>Print QR
                                                    Code</button>
                                                <button class="btn btn-outline-secondary" type="button"
                                                    id="downloadQrButton"><i class="bi bi-download me-1"></i>Download
                                                    PNG</button>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="qr-unavailable">
                                        <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>QR tracking
                                            unavailable</div>
                                        <div><?= e($qrTrackingMessage) ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>

                        <!-- ACTIONS -->
                        <div class="action-bar no-print mb-4">

                            <a class="btn btn-outline-secondary" href="javascript:history.back();">
                                <i class="bi bi-arrow-left me-1"></i>
                                Back
                            </a>

                            <div class="d-flex gap-2 flex-wrap">



                                <?php if ($qrTrackingAvailable): ?>
                                    <a class="btn btn-outline-primary" href="#qr-tracking">
                                        <i class="bi bi-qr-code me-1"></i>QR Tracking
                                    </a>
                                <?php endif; ?>

                                <?php if (
                                    in_array(
                                        $currentRole,
                                        [
                                            "staff",
                                            "supervisor",
                                            "admin"
                                        ],
                                        true
                                    )
                                ): ?>

                                    <a class="btn btn-primary"
                                        href="<?= e(BASE_URL) ?>/staff/review_request.php?id=<?= (int) $requestId ?>">
                                        <i class="bi bi-clipboard-check me-1"></i>
                                        Review / Development
                                    </a>

                                <?php endif; ?>

                                <?php if (
                                    in_array(
                                        $status,
                                        [
                                            "Completed",
                                            "For Evaluation",
                                            "Closed"
                                        ],
                                        true
                                    )
                                    && in_array(
                                        $currentRole,
                                        [
                                            "staff",
                                            "supervisor",
                                            "admin"
                                        ],
                                        true
                                    )
                                ): ?>

                                    <a class="btn btn-success"
                                        href="<?= e(BASE_URL) ?>/staff/evaluation.php?id=<?= (int) $requestId ?>">
                                        <i class="bi bi-clipboard2-check me-1"></i>
                                        Evaluation Monitoring
                                    </a>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div><!-- /.request-view-page -->

                </div>

            </main>

        </div>

    </div>

    <div class="phase3-toast no-print" id="phase3Toast" role="status" aria-live="polite"></div>

    <div class="modal fade" id="phase3PreviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content phase3-preview-modal">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-paperclip me-2"></i>Attachment Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="phase3PreviewBody"></div>
            </div>
        </div>
    </div>

    <?php if (empty($readOnlyViewer)): ?>
    <div class="modal fade" id="activityLogModal" tabindex="-1" aria-labelledby="activityLogModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="activityLogModalLabel"><i class="bi bi-clock-history me-2"></i>Activity
                        Log Timeline</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php include "../includes/activity_timeline.php"; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="commCenterModal" tabindex="-1" aria-labelledby="commCenterModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="commCenterModalLabel"><i class="bi bi-chat-dots me-2"></i>Communication
                        Center
                        <span id="convModalCount" class="badge bg-light text-dark border ms-1"><?= (int) $convTotal ?> message<?= (int) $convTotal === 1 ? "" : "s" ?></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <?php if ($convPreview): ?>
                    <div class="px-3 pt-2">
                        <p id="convPreviewLine" class="mb-0" title="<?= e($convPreviewTitle) ?>">
                            <i class="bi bi-reply-fill"></i>
                            <span><?= e($convPreviewTitle) ?></span>
                        </p>
                    </div>
                <?php endif; ?>
                <div class="modal-body">
                    <?php include "../includes/request_chat_box.php"; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($qrTrackingAvailable): ?>
        <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
        <script>
            (() => {
                const trackingUrl = <?= json_encode($trackingUrl, JSON_UNESCAPED_SLASHES) ?>;
                const qrContainer = document.getElementById("requestQrCode");
                if (qrContainer && window.QRCode) {
                    new QRCode(qrContainer, { text: trackingUrl, width: 220, height: 220, colorDark: "#0f172a", colorLight: "#ffffff", correctLevel: QRCode.CorrectLevel.H });
                }
                const notify = (message) => {
                    const toast = document.getElementById("phase3Toast");
                    if (!toast) return;
                    toast.textContent = message;
                    toast.classList.add("show");
                    setTimeout(() => toast.classList.remove("show"), 2200);
                };
                document.getElementById("copyTrackingUrl")?.addEventListener("click", async () => {
                    try { await navigator.clipboard.writeText(trackingUrl); }
                    catch (error) { const input = document.getElementById("trackingUrlInput"); input?.select(); document.execCommand("copy"); }
                    notify("Tracking link copied.");
                });
                document.getElementById("downloadQrButton")?.addEventListener("click", () => {
                    const image = qrContainer?.querySelector("img");
                    const canvas = qrContainer?.querySelector("canvas");
                    const url = image?.src || canvas?.toDataURL("image/png");
                    if (!url) return;
                    const link = document.createElement("a");
                    link.href = url;
                    link.download = <?= json_encode(($request["job_no"] ?? "request") . "-tracking-qr.png") ?>;
                    link.click();
                });
                document.getElementById("printQrButton")?.addEventListener("click", () => {
                    const image = qrContainer?.querySelector("img");
                    const canvas = qrContainer?.querySelector("canvas");
                    const qrSource = image?.src || canvas?.toDataURL("image/png");
                    if (!qrSource) return;
                    const win = window.open("", "_blank", "width=720,height=820");
                    if (!win) return;
                    win.document.write(`<!doctype html><html><head><title>QR Tracking</title><style>body{font-family:Arial,sans-serif;text-align:center;padding:48px;color:#0f172a}img{width:300px;height:300px;margin:24px auto}.job{font-size:24px;font-weight:700}.title{font-size:18px;margin-top:8px}.url{font-size:11px;word-break:break-all;color:#475569;margin-top:20px}</style></head><body><div class="job"><?= e($request["job_no"] ?? "") ?></div><div class="title"><?= e($request["project_title"] ?? "") ?></div><img src="${qrSource}" alt="Tracking QR"><div>Scan to track request progress</div><div class="url">${trackingUrl}</div><script>window.onload=()=>{window.print();window.onafterprint=()=>window.close();}<\/script></body></html>`);
                win.document.close();
            });
        })();
    </script>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/mammoth@1.6.0/mammoth.browser.min.js"></script>
    <script src="<?= e(BASE_URL) ?>/assets/js/request_view_phase3.js?v=3.1"></script>
    <script src="<?= e(BASE_URL) ?>/assets/js/request_chat.js?v=1.0"></script>
    <script>
    // Deep link: #conversation / #messages / #requestChatApp auto-opens
    // the Communication Center so message links land on the thread.
    document.addEventListener("DOMContentLoaded", function () {
        const hash = (window.location.hash || "").toLowerCase();

        if (["#conversation", "#messages", "#requestchatapp", "#commcentermodal"].includes(hash)) {
            const modalEl = document.getElementById("commCenterModal");

            if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }

            const anchor = document.getElementById("conversation");

            if (anchor) {
                setTimeout(function () {
                    try { anchor.scrollIntoView({ behavior: "smooth", block: "center" }); } catch (e) {}
                }, 350);
            }
        }

        // Live unread badge on the Messages button (30s poll).
        const baseUrl = <?= json_encode(rtrim(BASE_URL, "/"), JSON_UNESCAPED_SLASHES) ?>;
        const requestId = <?= (int) $requestId ?>;
        const msgBtn = document.getElementById("openConversationBtn");
        const msgBadge = document.getElementById("convMsgBadge");
        const previewLine = document.getElementById("convPreviewLine");

        /* BroadcastChannel: listen for "marked_read" from other tabs */
        if (typeof BroadcastChannel !== "undefined") {
            const bc = new BroadcastChannel("hpu_chat_read");
            bc.onmessage = function (e) {
                if (e.data && e.data.type === "marked_read" && e.data.request_id == requestId) {
                    pollUnread();
                }
            };
        }

        function setBadge(count, latest) {
            count = Math.max(0, parseInt(count || 0, 10) || 0);

            if (msgBadge) {
                msgBadge.textContent = count > 99 ? "99+" : String(count);
                msgBadge.classList.toggle("d-none", count <= 0);
            }

            if (msgBtn) {
                msgBtn.classList.toggle("has-unread", count > 0);
                const preview = latest && latest.message
                    ? ((latest.sender ? latest.sender + ": " : "") + latest.message)
                    : "";
                msgBtn.title = count > 0
                    ? ("Open communication center — " + count + " new message(s)" + (preview ? " — " + preview : ""))
                    : ("Open communication center" + (preview ? " — latest: " + preview : ""));
            }

            if (previewLine && latest && latest.message) {
                const text = (latest.sender ? latest.sender + ": " : "") + latest.message;
                previewLine.title = text;
                const span = previewLine.querySelector("span");
                if (span) span.textContent = text;
            }
        }

        async function pollUnread() {
            try {
                const res = await fetch(
                    baseUrl + "/ajax/chat_unread.php?scope=jobs&ids=" + encodeURIComponent(String(requestId)),
                    { credentials: "same-origin" }
                );
                const data = await res.json();
                if (!data || !data.ok) return;
                const key = String(requestId);
                setBadge(data.unread[key] || 0, (data.latest && (data.latest[key] || data.latest[requestId])) || null);
            } catch (e) { /* keep old badge */ }
        }

        // Refresh right after the thread is read (chat_fetch marks read),
        // so the badge clears as soon as the modal opens.
        document.getElementById("commCenterModal")?.addEventListener("shown.bs.modal", function () {
            setTimeout(pollUnread, 2500);
        });

        setInterval(pollUnread, 30000);
        document.addEventListener("visibilitychange", function () {
            if (!document.hidden) pollUnread();
        });
    });
    </script>

</body>

</html>