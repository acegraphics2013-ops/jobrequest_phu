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
   CSRF TOKEN
========================================================= */
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];

/* =========================================================
   FLASH MESSAGE
========================================================= */
$requestMessage = $_SESSION["request_message"] ?? null;
unset($_SESSION["request_message"]);

/* =========================================================
   FILTERS
========================================================= */
$search = trim((string) ($_GET["search"] ?? ""));
$statusFilter = trim((string) ($_GET["status"] ?? ""));
$departmentFilter = trim((string) ($_GET["department"] ?? ""));

/* =========================================================
   SUMMARY COUNTS
========================================================= */
$summaryResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(approval_status = 'Pending Review') AS pending_total,
        SUM(approval_status = 'Under Review') AS review_total,
        SUM(approval_status = 'Approved') AS approved_total,
        SUM(approval_status = 'For Revision') AS revision_total,
        SUM(approval_status = 'In Development') AS development_total,
        SUM(approval_status = 'Completed') AS completed_total,
        SUM(approval_status = 'For Evaluation') AS evaluation_total,
        SUM(approval_status = 'Closed') AS closed_total,
        SUM(approval_status = 'Cancelled') AS cancelled_total
     FROM job_requests
     WHERE COALESCE(archived, 0) = 0"
);

$summary = $summaryResult->fetch_assoc() ?: [];

$totalRequests = (int) ($summary["total"] ?? 0);
$pendingTotal = (int) ($summary["pending_total"] ?? 0);
$reviewTotal = (int) ($summary["review_total"] ?? 0);
$approvedTotal = (int) ($summary["approved_total"] ?? 0);
$revisionTotal = (int) ($summary["revision_total"] ?? 0);
$developmentTotal = (int) ($summary["development_total"] ?? 0);
$completedTotal = (int) ($summary["completed_total"] ?? 0);
$evaluationTotal = (int) ($summary["evaluation_total"] ?? 0);
$closedTotal = (int) ($summary["closed_total"] ?? 0);
$cancelledTotal = (int) ($summary["cancelled_total"] ?? 0);

/* =========================================================
   DEPARTMENTS
========================================================= */
$departments = $conn->query(
    "SELECT DISTINCT department
     FROM job_requests
     WHERE department IS NOT NULL
       AND department <> ''
       AND COALESCE(archived, 0) = 0
     ORDER BY department"
)->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   REQUEST QUERY
========================================================= */
$sql = "
        SELECT
            jr.id,
            jr.job_no,
            jr.requestor AS requestor_id,
            jr.requestor_name,
            jr.department,
            jr.contact,
            jr.project_title,
            jr.purpose_objective,
            jr.date_request,
            jr.date_completion,
            jr.request_types,
            jr.reviewed_by,
            jr.reviewed_date,
            jr.approved_by,
            jr.approved_date,
            jr.approval_status,
            jr.satisfaction_rating,
            jr.created_at,
            u.email AS requestor_email
        FROM job_requests jr
        LEFT JOIN users u ON u.id = jr.requestor
        WHERE 1 = 1
          AND COALESCE(jr.archived, 0) = 0
    ";

$types = "";
$params = [];

if ($search !== "") {
    $sql .= "
        AND (
            jr.job_no LIKE ?
            OR jr.requestor_name LIKE ?
            OR jr.department LIKE ?
            OR jr.project_title LIKE ?
            OR jr.approval_status LIKE ?
            OR u.email LIKE ?
        )
    ";

    $searchValue = "%" . $search . "%";
    $types .= "ssssss";

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
}

if ($statusFilter !== "") {
    $sql .= " AND approval_status = ? ";
    $types .= "s";
    $params[] = $statusFilter;
}

if ($departmentFilter !== "") {
    $sql .= " AND department = ? ";
    $types .= "s";
    $params[] = $departmentFilter;
}

$sql .= " ORDER BY id DESC ";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$requests = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

/* =========================================================
   NEW-MESSAGE NOTIFY (per job request)
   Counts messages after this staff member's last read,
   excluding their own messages. One GROUP BY query.
========================================================= */
$currentUserId = (int) ($_SESSION["user_id"] ?? 0);
$msgUnread = [];
$msgLatest = [];
$msgTotalUnread = 0;

try {
    $msgTableCheck = $conn->query("SHOW TABLES LIKE 'request_messages'");

    if ($msgTableCheck && $msgTableCheck->num_rows > 0 && $currentUserId > 0) {
        $unreadStmt = $conn->prepare(
            "SELECT m.request_id, COUNT(*) AS unread
             FROM request_messages m
             LEFT JOIN request_message_reads r
               ON r.request_id = m.request_id AND r.user_id = ?
             WHERE m.id > COALESCE(r.last_read_message_id, 0)
               AND m.sender_id <> ?
             GROUP BY m.request_id"
        );

        if ($unreadStmt) {
            $unreadStmt->bind_param("ii", $currentUserId, $currentUserId);
            $unreadStmt->execute();

            foreach (
                $unreadStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $unreadRow
            ) {
                $rid = (int) ($unreadRow["request_id"] ?? 0);
                $cnt = (int) ($unreadRow["unread"] ?? 0);

                if ($rid > 0 && $cnt > 0) {
                    $msgUnread[$rid] = $cnt;
                    $msgTotalUnread += $cnt;
                }
            }

            $unreadStmt->close();
        }

        // Latest message preview for rows with unread (tooltip).
        if (!empty($msgUnread)) {
            $unreadIds = implode(",", array_map("intval", array_keys($msgUnread)));

            $latestResult = $conn->query(
                "SELECT m.request_id, m.sender_name, m.sender_role, m.message, m.created_at
                 FROM request_messages m
                 INNER JOIN (
                   SELECT request_id, MAX(id) AS max_id
                   FROM request_messages
                   WHERE request_id IN ({$unreadIds})
                   GROUP BY request_id
                 ) t ON t.request_id = m.request_id AND t.max_id = m.id"
            );

            if ($latestResult) {
                while ($latestRow = $latestResult->fetch_assoc()) {
                    $preview = trim((string) ($latestRow["message"] ?? ""));

                    if ($preview === "") {
                        $preview = "(attachment)";
                    } elseif (mb_strlen($preview) > 120) {
                        $preview = mb_substr($preview, 0, 120) . "…";
                    }

                    $msgLatest[(int) $latestRow["request_id"]] = [
                        "sender" => (string) ($latestRow["sender_name"] ?? ""),
                        "role" => (string) ($latestRow["sender_role"] ?? ""),
                        "message" => $preview,
                        "created_at" => (string) ($latestRow["created_at"] ?? ""),
                    ];
                }
            }
        }
    }
} catch (Throwable $e) {
    $msgUnread = [];
    $msgLatest = [];
    $msgTotalUnread = 0;
}

/* =========================================================
   UI HELPERS
========================================================= */
function requestStatusClass(string $status): string
{
    return match ($status) {
        "Pending Review" => "status-pending",
        "Under Review" => "status-review",
        "Approved" => "status-approved",
        "In Development" => "status-development",
        "For Revision" => "status-revision",
        "Disapproved" => "status-disapproved",
        "Completed" => "status-completed",
        "For Evaluation" => "status-evaluation",
        "Closed" => "status-closed",
        "Cancelled" => "status-cancelled",
        default => "status-default"
    };
}

function requestStatusIcon(string $status): string
{
    return match ($status) {
        "Pending Review" => "bi-hourglass-split",
        "Under Review" => "bi-search",
        "Approved" => "bi-check-circle-fill",
        "In Development" => "bi-tools",
        "For Revision" => "bi-arrow-repeat",
        "Disapproved" => "bi-x-circle-fill",
        "Completed" => "bi-check2-all",
        "For Evaluation" => "bi-clipboard2-check",
        "Closed" => "bi-lock-fill",
        "Cancelled" => "bi-x-octagon-fill",
        default => "bi-info-circle"
    };
}

function requestTypeClass(string $type): string
{
    return match ($type) {
        "Graphic Design" => "type-graphic",
        "Video Production" => "type-video",
        "Photo/Video Coverage" => "type-coverage",
        "IEC Materials" => "type-iec",
        default => "type-other"
    };
}

function requestTypeIcon(string $type): string
{
    return match ($type) {
        "Graphic Design" => "bi-palette-fill",
        "Video Production" => "bi-camera-reels-fill",
        "Photo/Video Coverage" => "bi-camera-fill",
        "IEC Materials" => "bi-file-earmark-richtext",
        default => "bi-tag-fill"
    };
}

function reviewButtonLabel(string $role, string $status): string
{
    if (in_array($status, ["Completed", "For Evaluation", "Closed", "Cancelled"], true)) {
        return "Development";
    }

    return match ($role) {
        "supervisor" => "Approve",
        "admin" => "Review / Approve",
        default => "Review"
    };
}

function reviewButtonIcon(string $role, string $status): string
{
    if (in_array($status, ["Completed", "For Evaluation", "Closed", "Cancelled"], true)) {
        return "bi-tools";
    }

    return $role === "supervisor"
        ? "bi-patch-check"
        : "bi-clipboard-check";
}

function requestorInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = "";

    foreach (array_slice($parts, 0, 2) as $part) {
        if ($part !== "") {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }
    }

    return $initials !== "" ? $initials : "?";
}

function requestorAvatarClass(string $name): string
{
    $len = mb_strlen(trim($name));
    return "av-" . (($len % 5) + 1);
}

function requestFilterUrl(
    string $status,
    string $search,
    string $department
): string {
    $query = [];

    if ($search !== "") {
        $query["search"] = $search;
    }

    if ($status !== "") {
        $query["status"] = $status;
    }

    if ($department !== "") {
        $query["department"] = $department;
    }

    return "requests.php" . (!empty($query) ? "?" . http_build_query($query) : "");
}

function compactDate(?string $value, string $fallback = "—"): string
{
    if (!$value) {
        return $fallback;
    }

    $timestamp = strtotime($value);

    return $timestamp !== false
        ? date("M d, Y", $timestamp)
        : $fallback;
}

/*
|--------------------------------------------------------------------------
| Processing Lead Time
|--------------------------------------------------------------------------
| HPU processing starts only after supervisor approval.
|
| Minimum lead time:
| - Graphic Design / Video Production = 5 calendar days
| - Photo/Video Coverage / IEC Materials = 3 calendar days
|
| If multiple request types are selected, the longest applicable
| minimum lead time is used.
|--------------------------------------------------------------------------
*/

function staffRequestTypes(
    mixed $value
): array {
    if (is_array($value)) {
        return array_values(
            array_filter(
                array_map(
                    static fn($item): string =>
                        trim((string) $item),
                    $value
                )
            )
        );
    }

    $raw = trim((string) $value);

    if ($raw === "") {
        return [];
    }

    $decoded = json_decode(
        $raw,
        true
    );

    if (is_array($decoded)) {
        return array_values(
            array_filter(
                array_map(
                    static fn($item): string =>
                        trim((string) $item),
                    $decoded
                )
            )
        );
    }

    /*
     * Backward-compatible fallback for old comma/pipe-separated data.
     */
    return array_values(
        array_filter(
            array_map(
                "trim",
                preg_split(
                    '/[,|]+/',
                    $raw
                ) ?: []
            )
        )
    );
}

function staffLeadDays(
    mixed $requestTypes
): int {
    $types = staffRequestTypes(
        $requestTypes
    );

    $leadDays = 0;

    foreach ($types as $type) {
        $normalized = mb_strtolower(
            trim($type)
        );

        if (
            str_contains(
                $normalized,
                "graphic design"
            )
            ||
            str_contains(
                $normalized,
                "video production"
            )
        ) {
            $leadDays = max(
                $leadDays,
                5
            );

            continue;
        }

        if (
            str_contains(
                $normalized,
                "photo/video coverage"
            )
            ||
            str_contains(
                $normalized,
                "photo / video coverage"
            )
            ||
            str_contains(
                $normalized,
                "iec materials"
            )
        ) {
            $leadDays = max(
                $leadDays,
                3
            );
        }
    }

    return $leadDays;
}

function staffProcessingTarget(
    ?string $preferredDate,
    ?string $approvedDate,
    int $leadDays
): ?string {
    if (!$approvedDate) {
        return null;
    }

    try {
        $approved = new DateTimeImmutable(
            $approvedDate
        );

        $minimumTarget =
            $leadDays > 0
            ? $approved->modify(
                "+"
                . $leadDays
                . " days"
            )
            : $approved;

        /*
         * Never shorten a requestor's later preferred completion date.
         * Effective target = later of:
         * 1. preferred completion date
         * 2. approval date + minimum processing lead time
         */
        if ($preferredDate) {
            $preferred = new DateTimeImmutable(
                $preferredDate
            );

            if ($preferred > $minimumTarget) {
                return $preferred->format(
                    "Y-m-d"
                );
            }
        }

        return $minimumTarget->format(
            "Y-m-d"
        );
    } catch (Throwable $exception) {
        return null;
    }
}

function staffDeadlineInfo(
    ?string $preferredDate,
    ?string $approvedDate,
    string $status,
    mixed $requestTypes
): array {
    $leadDays = staffLeadDays(
        $requestTypes
    );

    /*
     * No overdue/due-soon countdown before approval.
     * For Revision also pauses deadline pressure while changes are required.
     */
    if (
        in_array(
            $status,
            [
                "Pending Review",
                "Under Review",
                "For Revision"
            ],
            true
        )
    ) {
        return [
            "state" => "awaiting",
            "text" =>
                $status === "For Revision"
                ? "For revision — processing countdown is paused"
                : "Awaiting supervisor approval",
            "class" => "",
            "icon" => "bi-hourglass-split",
            "lead_days" => $leadDays,
            "target_date" => null
        ];
    }

    if ($status === "Disapproved") {
        return [
            "state" => "disapproved",
            "text" => "Request disapproved",
            "class" => "",
            "icon" => "bi-x-circle",
            "lead_days" => $leadDays,
            "target_date" => null
        ];
    }

    if ($status === "Cancelled") {
        return [
            "state" => "cancelled",
            "text" => "Request cancelled",
            "class" => "",
            "icon" => "bi-x-octagon",
            "lead_days" => $leadDays,
            "target_date" => null
        ];
    }

    if (
        in_array(
            $status,
            [
                "Completed",
                "For Evaluation",
                "Closed"
            ],
            true
        )
    ) {
        $target = staffProcessingTarget(
            $preferredDate,
            $approvedDate,
            $leadDays
        );

        return [
            "state" => "finished",
            "text" =>
                $target
                ? "Processing target " . compactDate($target)
                : "Processing completed",
            "class" => "positive",
            "icon" => "bi-check-circle",
            "lead_days" => $leadDays,
            "target_date" => $target
        ];
    }

    /*
     * Legacy safeguard:
     * An Approved/In Development row without approved_date must not
     * immediately become overdue.
     */
    if (!$approvedDate) {
        return [
            "state" => "awaiting_date",
            "text" => "Approval date not yet recorded",
            "class" => "",
            "icon" => "bi-calendar2-check",
            "lead_days" => $leadDays,
            "target_date" => null
        ];
    }

    $target = staffProcessingTarget(
        $preferredDate,
        $approvedDate,
        $leadDays
    );

    if (!$target) {
        return [
            "state" => "unavailable",
            "text" => "Processing target unavailable",
            "class" => "",
            "icon" => "bi-calendar-x",
            "lead_days" => $leadDays,
            "target_date" => null
        ];
    }

    try {
        $today = new DateTimeImmutable(
            "today"
        );

        $due = new DateTimeImmutable(
            $target
        );

        $days = (int) $today
            ->diff($due)
            ->format("%r%a");
    } catch (Throwable $exception) {
        return [
            "state" => "unavailable",
            "text" => "Processing target unavailable",
            "class" => "",
            "icon" => "bi-calendar-x",
            "lead_days" => $leadDays,
            "target_date" => null
        ];
    }

    if ($days < 0) {
        $lateDays = abs($days);

        return [
            "state" => "overdue",
            "text" =>
                number_format($lateDays)
                . " day"
                . ($lateDays === 1 ? "" : "s")
                . " overdue",
            "class" => "danger",
            "icon" => "bi-exclamation-circle",
            "lead_days" => $leadDays,
            "target_date" => $target
        ];
    }

    if ($days === 0) {
        return [
            "state" => "today",
            "text" => "Processing target today",
            "class" => "warning",
            "icon" => "bi-alarm",
            "lead_days" => $leadDays,
            "target_date" => $target
        ];
    }

    if ($days === 1) {
        return [
            "state" => "soon",
            "text" => "Processing target tomorrow",
            "class" => "warning",
            "icon" => "bi-clock",
            "lead_days" => $leadDays,
            "target_date" => $target
        ];
    }

    return [
        "state" => "remaining",
        "text" =>
            number_format($days)
            . " day"
            . ($days === 1 ? "" : "s")
            . " remaining",
        "class" =>
            $days <= 3
            ? "warning"
            : "",
        "icon" => "bi-calendar-check",
        "lead_days" => $leadDays,
        "target_date" => $target
    ];
}

function requestWorkflowIndex(string $status): int
{
    return match ($status) {
        "Pending Review" => 0,
        "Under Review" => 1,
        "Approved" => 2,
        "In Development" => 3,
        "Completed" => 4,
        "For Evaluation" => 5,
        "Closed" => 6,
        "For Revision" => 1,
        "Disapproved" => 1,
        "Cancelled" => 1,
        default => 0
    };
}

function requestWorkflowState(
    int $stepIndex,
    int $currentIndex,
    string $status
): string {
    if ($status === "Cancelled") {
        if ($stepIndex < $currentIndex) {
            return "done";
        }

        return $stepIndex === $currentIndex
            ? "blocked"
            : "future";
    }

    if ($status === "Disapproved") {
        if ($stepIndex < $currentIndex) {
            return "done";
        }

        return $stepIndex === $currentIndex
            ? "blocked"
            : "future";
    }

    if ($status === "For Revision") {
        if ($stepIndex < $currentIndex) {
            return "done";
        }

        return $stepIndex === $currentIndex
            ? "revision"
            : "future";
    }

    if ($stepIndex < $currentIndex) {
        return "done";
    }

    return $stepIndex === $currentIndex
        ? "current"
        : "future";
}

$pageTitle = match ($role) {
    "supervisor" => "Request Approval",
    "admin" => "Request Management",
    default => "Staff Request Review"
};

$pageDescription = match ($role) {
    "supervisor" => "Approve reviewed requests and monitor approval-based processing deadlines, development and evaluation.",
    "admin" => "Review, approve and monitor job requests using approval-based processing deadlines.",
    default => "Review submissions, monitor approval-based processing targets and update development details."
};

$activeFilterCount = ($search !== "" ? 1 : 0)
    + ($statusFilter !== "" ? 1 : 0)
    + ($departmentFilter !== "" ? 1 : 0);

$filteredTotal = count($requests);

/* Attention counts derived from the already-fetched rows (no extra queries). */
$attentionOverdue = 0;
$attentionDueSoon = 0;

foreach ($requests as $attentionRow) {
    $attentionInfo = staffDeadlineInfo(
        $attentionRow["date_completion"] ?? null,
        $attentionRow["approved_date"] ?? null,
        (string) ($attentionRow["approval_status"] ?? ""),
        $attentionRow["request_types"] ?? null
    );

    $attentionState = (string) ($attentionInfo["state"] ?? "");

    if ($attentionState === "overdue") {
        $attentionOverdue++;
    } elseif (in_array($attentionState, ["today", "soon"], true)) {
        $attentionDueSoon++;
    }
}

$attentionTotal = $attentionOverdue + $attentionDueSoon;

$summaryCards = [
    ["label" => "All", "status" => "", "count" => $totalRequests, "icon" => "bi-inbox", "class" => "metric-all"],
    ["label" => "Pending", "status" => "Pending Review", "count" => $pendingTotal, "icon" => "bi-hourglass-split", "class" => "metric-pending"],
    ["label" => "Review", "status" => "Under Review", "count" => $reviewTotal, "icon" => "bi-search", "class" => "metric-review"],
    ["label" => "Approved", "status" => "Approved", "count" => $approvedTotal, "icon" => "bi-check-circle", "class" => "metric-approved"],
    ["label" => "Revision", "status" => "For Revision", "count" => $revisionTotal, "icon" => "bi-pencil-square", "class" => "metric-revision"],
    ["label" => "Development", "status" => "In Development", "count" => $developmentTotal, "icon" => "bi-tools", "class" => "metric-development"],
    ["label" => "Completed", "status" => "Completed", "count" => $completedTotal, "icon" => "bi-check2-all", "class" => "metric-completed"],
    ["label" => "Evaluation", "status" => "For Evaluation", "count" => $evaluationTotal, "icon" => "bi-clipboard2-check", "class" => "metric-evaluation"],
    ["label" => "Closed", "status" => "Closed", "count" => $closedTotal, "icon" => "bi-lock", "class" => "metric-closed"],
    ["label" => "Cancelled", "status" => "Cancelled", "count" => $cancelledTotal, "icon" => "bi-x-octagon", "class" => "metric-cancelled"]
];

$workflowSteps = [
    ["label" => "Submitted", "icon" => "bi-send-fill"],
    ["label" => "Review", "icon" => "bi-search"],
    ["label" => "Approved", "icon" => "bi-patch-check-fill"],
    ["label" => "Development", "icon" => "bi-tools"],
    ["label" => "Completed", "icon" => "bi-check-lg"],
    ["label" => "Evaluation", "icon" => "bi-clipboard2-check-fill"],
    ["label" => "Closed", "icon" => "bi-lock-fill"]
];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?= e($pageTitle) ?> | HPU Workbench
    </title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">

    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">

    <style>
        :root {
            --request-primary: #1769e0;
            --request-primary-dark: #0f4fb7;
            --request-primary-soft: #edf5ff;
            --request-text: #172033;
            --request-muted: #68758b;
            --request-border: #dfe6ef;
            --request-border-strong: #cfd9e7;
            --request-surface: #ffffff;
            --request-page: #f4f7fb;
            --request-soft: #f8fafc;
            --request-success: #158a55;
            --request-danger: #c9363e;
            --request-warning: #a86108;
            --request-shadow: 0 8px 24px rgba(28, 42, 72, .06);
        }

        body {
            background: var(--request-page);
            color: var(--request-text);
        }

        .min-w-0 {
            min-width: 0;
        }

        .requests-page {
            max-width: 1680px;
            margin: 0 auto;
        }

        .requests-page .content {
            padding: 16px 18px 28px;
        }

        /* Page header styles moved to assets/css/page_headers.css (.ph) */

        .compact-tool-btn {
            display: inline-flex;
            min-height: 34px;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 7px 10px;
            border: 1px solid var(--request-border-strong);
            border-radius: 8px;
            background: #fff;
            color: #42506a;
            font-size: .75rem;
            font-weight: 750;
            text-decoration: none;
            transition: .16s ease;
        }

        .compact-tool-btn:hover {
            border-color: #a8c4eb;
            background: var(--request-primary-soft);
            color: var(--request-primary-dark);
        }

        .result-pill {
            display: inline-flex;
            min-height: 34px;
            align-items: center;
            gap: 6px;
            padding: 7px 11px;
            border-radius: 8px;
            background: #eaf8f1;
            color: #116c45;
            font-size: .75rem;
            font-weight: 800;
        }

        .alert.compact-alert {
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: .82rem;
        }

        .metrics-strip {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 8px;
            margin-bottom: 10px;
            padding-bottom: 2px;
        }

        .metric-card {
            --metric-color: #55708f;
            --metric-bg: #f3f6fa;
            position: relative;
            display: grid;
            grid-template-columns: 30px minmax(0, 1fr);
            align-items: center;
            gap: 8px;
            overflow: hidden;
            padding: 8px 10px 8px 13px;
            border: 1px solid var(--request-border);
            border-radius: 10px;
            background: #fff;
            color: inherit;
            text-decoration: none;
            box-shadow: 0 3px 12px rgba(28, 42, 72, .035);
            transition: .16s ease;
        }

        .metric-card::before {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            width: 3px;
            background: var(--metric-color);
            content: "";
        }

        .metric-card:hover,
        .metric-card.active {
            border-color: color-mix(in srgb, var(--metric-color) 36%, white);
            background: color-mix(in srgb, var(--metric-bg) 70%, white);
            transform: translateY(-1px);
        }

        .metric-card.active {
            box-shadow: inset 0 -2px 0 var(--metric-color);
        }

        .metric-icon {
            display: grid;
            width: 30px;
            height: 30px;
            place-items: center;
            border-radius: 8px;
            background: var(--metric-bg);
            color: var(--metric-color);
            font-size: .9rem;
        }

        .metric-label {
            overflow: hidden;
            color: var(--request-muted);
            font-size: .62rem;
            font-weight: 800;
            letter-spacing: .025em;
            text-overflow: ellipsis;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .metric-value {
            margin-top: 1px;
            color: var(--request-text);
            font-size: 1rem;
            font-weight: 900;
            line-height: 1;
        }

        .metric-all {
            --metric-color: #375a7f;
            --metric-bg: #edf2f7;
        }

        .metric-pending {
            --metric-color: #68758b;
            --metric-bg: #f1f4f8;
        }

        .metric-review {
            --metric-color: #087c91;
            --metric-bg: #e4f8fb;
        }

        .metric-approved {
            --metric-color: #168653;
            --metric-bg: #e7f8ef;
        }

        .metric-revision {
            --metric-color: #b96c0a;
            --metric-bg: #fff7e6;
        }

        .metric-development {
            --metric-color: #a05c08;
            --metric-bg: #fff3df;
        }

        .metric-completed {
            --metric-color: #245dcc;
            --metric-bg: #eaf1ff;
        }

        .metric-evaluation {
            --metric-color: #6d43c4;
            --metric-bg: #f1ebff;
        }

        .metric-closed {
            --metric-color: #334155;
            --metric-bg: #edf0f4;
        }

        .metric-cancelled {
            --metric-color: #b42318;
            --metric-bg: #fee4e2;
        }

        .filter-panel {
            margin-bottom: 10px;
            border: 1px solid var(--request-border);
            border-radius: 11px;
            background: #fff;
            box-shadow: var(--request-shadow);
        }

        .filter-panel .card-body {
            padding: 8px 10px;
        }

        /* Minimal single-row filter bar (labels kept screen-reader-only) */
        .filter-grid {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .filter-search-field {
            flex: 1 1 auto;
            min-width: 140px;
        }

        .filter-grid .filter-select-wrap {
            flex: 0 1 172px;
            min-width: 0;
        }

        .compact-label {
            position: absolute;
            width: 1px;
            height: 1px;
            margin: -1px;
            padding: 0;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .request-search {
            position: relative;
        }

        .request-search>i {
            position: absolute;
            top: 50%;
            left: 11px;
            z-index: 2;
            color: #8390a4;
            font-size: .82rem;
            transform: translateY(-50%);
        }

        .filter-panel .form-control,
        .filter-panel .form-select {
            min-height: 32px;
            border-color: var(--request-border-strong);
            border-radius: 8px;
            font-size: .75rem;
            padding-top: 4px;
            padding-bottom: 4px;
        }

        .filter-panel .form-control:focus,
        .filter-panel .form-select:focus {
            border-color: #8ab6ed;
            box-shadow: 0 0 0 .15rem rgba(23, 105, 224, .10);
        }

        .request-search .form-control {
            padding-left: 34px;
        }

        .filter-actions {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
        }

        .filter-actions .btn {
            width: 32px;
            min-height: 32px;
            padding: 0;
            border-radius: 8px;
            font-size: .78rem;
            font-weight: 800;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .active-filters {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
            margin-top: 7px;
            padding-top: 7px;
            border-top: 1px solid #edf1f6;
        }

        .active-filter-label {
            color: var(--request-muted);
            font-size: .67rem;
            font-weight: 800;
        }

        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            max-width: 320px;
            padding: 4px 8px;
            border-radius: 999px;
            background: var(--request-primary-soft);
            color: var(--request-primary-dark);
            font-size: .68rem;
            font-weight: 750;
        }

        .filter-chip span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .list-shell {
            overflow: hidden;
            border: 1px solid var(--request-border);
            border-radius: 12px;
            background: #fff;
            box-shadow: var(--request-shadow);
        }

        .list-toolbar {
            display: flex;
            min-height: 40px;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 8px 11px;
            border-bottom: 1px solid var(--request-border);
            background: #fafbfd;
        }

        .list-toolbar-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .77rem;
            font-weight: 850;
        }

        .list-toolbar-title i {
            color: var(--request-primary);
        }

        .list-toolbar-note {
            color: var(--request-muted);
            font-size: .68rem;
        }

        /* Inbox-style queue: flush rows with dividers, hover actions */
        .request-list-body {
            padding: 0;
            background: #fff;
        }

        .request-row {
            --status-accent: #8793a4;
            --status-border: #e6ebf1;
            --status-soft: #f7f9fc;
            position: relative;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
            padding: 9px 12px 9px 15px;
            border-bottom: 1px solid var(--status-border);
            background: #fff;
            transition: background .13s ease;
        }

        .request-row:first-child {
            border-top: 1px solid var(--status-border);
        }

        .request-row::before {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            width: 3px;
            background: var(--status-accent);
            content: "";
        }

        .request-row:hover {
            z-index: 1;
            background: linear-gradient(90deg, var(--status-soft) 0, #fff 30%, #fff 100%);
        }

        .row-main {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            min-width: 0;
        }

        .row-main .avatar {
            flex-shrink: 0;
            margin-top: 1px;
        }

        .row-text {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 0;
            flex: 1;
        }

        .row-top {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-wrap: wrap;
            min-width: 0;
        }

        .job-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 7px;
            border-radius: 6px;
            background: #0f172a;
            color: #fff;
            font-size: .64rem;
            font-weight: 850;
            letter-spacing: .02em;
            white-space: nowrap;
        }

        .job-chip .copy-job-btn {
            border: 0;
            background: rgba(255, 255, 255, .16);
            color: #fff;
            border-radius: 4px;
            width: 18px;
            height: 18px;
            display: grid;
            place-items: center;
            font-size: .6rem;
            cursor: pointer;
        }

        .job-chip .copy-job-btn:hover {
            background: rgba(255, 255, 255, .32);
        }

        .row-title {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--request-text);
            font-size: .85rem;
            font-weight: 800;
            letter-spacing: -.01em;
            text-decoration: none;
            flex: 1 1 160px;
        }

        .row-title:hover {
            color: var(--request-primary);
            text-decoration: underline;
        }

        .row-meta {
            display: flex;
            align-items: center;
            gap: 4px 12px;
            flex-wrap: wrap;
            color: var(--request-muted);
            font-size: .7rem;
            min-width: 0;
        }

        .meta-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            min-width: 0;
            max-width: 260px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .meta-item i {
            color: #9aa6b8;
        }

        .meta-link {
            color: var(--request-muted);
            text-decoration: none;
            max-width: 220px;
        }

        .meta-link:hover {
            color: var(--request-primary);
            text-decoration: underline;
        }

        .row-purpose {
            color: #5b6b82;
            font-size: .72rem;
            line-height: 1.4;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .row-side {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .row-deadline {
            display: flex;
            align-items: center;
            gap: 7px;
            min-width: 118px;
            color: var(--request-muted);
            font-size: .7rem;
            text-align: left;
        }

        .row-deadline>i {
            font-size: .95rem;
            color: #9aa6b8;
        }

        .row-deadline small {
            display: block;
            font-size: .58rem;
            font-weight: 850;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: #9aa6b8;
        }

        .row-deadline b {
            display: block;
            color: var(--request-text);
            font-weight: 800;
            white-space: nowrap;
        }

        .row-deadline.warning {
            color: #8a5a05;
        }

        .row-deadline.warning>i,
        .row-deadline.warning b {
            color: #8a5a05;
        }

        .row-deadline.danger,
        .row-deadline.danger>i,
        .row-deadline.danger b {
            color: #b4232c;
        }

        .request-row.status-pending {
            --status-accent: #7a8798;
            --status-border: #d8dee7;
            --status-soft: #f3f5f8;
        }

        .request-row.status-review {
            --status-accent: #07869b;
            --status-border: #bfe7ed;
            --status-soft: #ecfbfd;
        }

        .request-row.status-approved {
            --status-accent: #19905b;
            --status-border: #bee6cf;
            --status-soft: #edf9f2;
        }

        .request-row.status-development {
            --status-accent: #d17a0c;
            --status-border: #f0d3aa;
            --status-soft: #fff8ed;
        }

        .request-row.status-revision {
            --status-accent: #b96c0a;
            --status-border: #ebc98e;
            --status-soft: #fff7e6;
        }

        .request-row.status-disapproved {
            --status-accent: #d43b45;
            --status-border: #efbec3;
            --status-soft: #fff1f2;
        }

        .request-row.status-completed {
            --status-accent: #2b69dc;
            --status-border: #bfd0f5;
            --status-soft: #f0f5ff;
        }

        .request-row.status-evaluation {
            --status-accent: #7148c8;
            --status-border: #d1c4ef;
            --status-soft: #f6f2ff;
        }

        .request-row.status-closed {
            --status-accent: #475569;
            --status-border: #ccd3dc;
            --status-soft: #f3f5f7;
        }

        .request-row.status-cancelled {
            --status-accent: #d92d20;
            --status-border: #fecdca;
            --status-soft: #fffbfa;
        }

        .request-row.status-default {
            --status-accent: #8793a4;
            --status-border: #d9e0e9;
            --status-soft: #f7f9fc;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 7px;
            border-radius: 999px;
            font-size: .61rem;
            font-weight: 850;
            white-space: nowrap;
        }

        .status-pending.status-badge {
            background: #eef1f5;
            color: #566174;
        }

        .status-review.status-badge {
            background: #dff7fa;
            color: #08697a;
        }

        .status-approved.status-badge {
            background: #e1f7ea;
            color: #126b43;
        }

        .status-development.status-badge {
            background: #fff0d9;
            color: #8a4d04;
        }

        .status-revision.status-badge {
            background: #fff1d7;
            color: #8a4d04;
        }

        .status-disapproved.status-badge {
            background: #ffe6e8;
            color: #a61e2c;
        }

        .status-completed.status-badge {
            background: #e4edff;
            color: #1e54ba;
        }

        .status-evaluation.status-badge {
            background: #eee8ff;
            color: #5d36ad;
        }

        .status-closed.status-badge {
            background: #e8ecf1;
            color: #263244;
        }

        .status-cancelled.status-badge {
            background: #fee4e2;
            color: #b42318;
        }

        .status-default.status-badge {
            background: #eef1f5;
            color: #566174;
        }

        .evaluation-complete {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: var(--request-success);
            font-weight: 800;
        }

        .cell-value {
            overflow: hidden;
            color: #263248;
            font-size: .73rem;
            font-weight: 750;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .cell-note {
            display: flex;
            align-items: center;
            gap: 4px;
            overflow: hidden;
            margin-top: 3px;
            color: var(--request-muted);
            font-size: .64rem;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .cell-note.positive {
            color: var(--request-success);
        }

        .cell-note.warning {
            color: var(--request-warning);
        }

        .cell-note.danger {
            color: var(--request-danger);
        }

        .type-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: .6rem;
            font-weight: 850;
            white-space: nowrap;
        }

        .type-badge i {
            font-size: .62rem;
        }

        .type-graphic {
            background: #e4edff;
            color: #1e54ba;
        }

        .type-video {
            background: #f1ebff;
            color: #5d36ad;
        }

        .type-coverage {
            background: #fff0d9;
            color: #8a4d04;
        }

        .type-iec {
            background: #e1f7ea;
            color: #126b43;
        }

        .type-other {
            background: #eef1f5;
            color: #566174;
        }

        .workflow-tracker {
            grid-column: 1 / -1;
            min-width: 0;
            margin-top: 2px;
            padding: 10px 10px 3px;
            border-top: 1px solid var(--status-border);
            border-radius: 8px;
            background: rgba(255, 255, 255, .78);
        }

        .workflow-tracker.collapsing {
            grid-column: 1 / -1;
            overflow: hidden;
        }

        .workflow-tracker-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 7px;
        }

        .workflow-tracker-title {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #526078;
            font-size: .62rem;
            font-weight: 900;
            letter-spacing: .045em;
            text-transform: uppercase;
        }

        .workflow-tracker-title i {
            color: var(--request-primary);
        }

        .approved-by-meta {
            display: inline-flex;
            min-width: 0;
            align-items: center;
            gap: 6px;
            color: var(--request-muted);
            font-size: .65rem;
            white-space: nowrap;
        }

        .approved-by-meta i {
            color: var(--request-success);
        }

        .approved-by-meta strong {
            overflow: hidden;
            max-width: 230px;
            color: #263248;
            font-weight: 850;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .approved-by-meta.pending i,
        .approved-by-meta.pending strong {
            color: #8a6a25;
        }

        .workflow-track-scroll {
            overflow-x: auto;
            overflow-y: hidden;
            padding: 2px 2px 3px;
            scrollbar-width: thin;
        }

        .workflow-track {
            display: grid;
            min-width: 650px;
            grid-template-columns: repeat(7, minmax(78px, 1fr));
            align-items: start;
        }

        .workflow-step {
            position: relative;
            min-width: 78px;
            text-align: center;
        }

        .workflow-step:not(:last-child)::after {
            position: absolute;
            z-index: 0;
            top: 13px;
            left: calc(50% + 14px);
            right: calc(-50% + 14px);
            height: 2px;
            border-radius: 999px;
            background: #dbe3ee;
            content: "";
        }

        .workflow-step.done:not(:last-child)::after {
            background: #2abb70;
        }

        .workflow-node {
            position: relative;
            z-index: 2;
            display: grid;
            width: 27px;
            height: 27px;
            place-items: center;
            margin: 0 auto 5px;
            border: 2px solid #cbd6e4;
            border-radius: 50%;
            background: #fff;
            color: #8b98aa;
            font-size: .7rem;
            transition: .16s ease;
        }

        .workflow-step.done .workflow-node {
            border-color: #27b76b;
            background: #27b76b;
            color: #fff;
        }

        .workflow-step.current .workflow-node {
            border-color: #2d74ea;
            background: #2d74ea;
            color: #fff;
            box-shadow: 0 0 0 4px rgba(45, 116, 234, .13);
        }

        .workflow-step.revision .workflow-node {
            border-color: #d58a19;
            background: #d58a19;
            color: #fff;
            box-shadow: 0 0 0 4px rgba(213, 138, 25, .12);
        }

        .workflow-step.blocked .workflow-node {
            border-color: #d94852;
            background: #d94852;
            color: #fff;
            box-shadow: 0 0 0 4px rgba(217, 72, 82, .12);
        }

        .workflow-label {
            color: #8591a3;
            font-size: .56rem;
            font-weight: 800;
            line-height: 1.2;
            white-space: nowrap;
        }

        .workflow-step.done .workflow-label {
            color: #377255;
        }

        .workflow-step.current .workflow-label {
            color: #235dbb;
        }

        .workflow-step.revision .workflow-label {
            color: #9a610b;
        }

        .workflow-step.blocked .workflow-label {
            color: #ac2731;
        }

        .request-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 5px;
            white-space: nowrap;
        }

        .request-actions .btn {
            display: inline-flex;
            min-height: 31px;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 5px 8px;
            border-radius: 7px;
            font-size: .67rem;
            font-weight: 800;
        }

        .request-actions .btn-icon {
            width: 31px;
            padding: 5px;
        }

        .workflow-toggle-btn {
            border-color: #b7c6dc;
            background: #f7faff;
            color: #315987;
        }

        .workflow-toggle-btn:hover,
        .workflow-toggle-btn:focus,
        .workflow-toggle-btn.is-open {
            border-color: var(--status-accent);
            background: var(--status-soft);
            color: var(--status-accent);
        }

        .workflow-toggle-btn .bi-chevron-down {
            margin-left: 1px;
            font-size: .55rem;
            transition: transform .18s ease;
        }

        .workflow-toggle-btn.is-open .bi-chevron-down {
            transform: rotate(180deg);
        }

        .request-actions .dropdown-menu {
            min-width: 205px;
            padding: 5px;
            border-color: var(--request-border);
            border-radius: 9px;
            box-shadow: 0 14px 35px rgba(28, 42, 72, .15);
        }

        .request-actions .dropdown-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 9px;
            border-radius: 6px;
            font-size: .74rem;
            font-weight: 650;
        }

        .request-actions .dropdown-item i {
            width: 16px;
            color: #67758b;
        }

        .request-actions .dropdown-divider {
            margin: 5px 0;
        }

        .empty-state {
            padding: 54px 20px;
            text-align: center;
            color: var(--request-muted);
        }

        .empty-icon {
            display: grid;
            width: 54px;
            height: 54px;
            place-items: center;
            margin: 0 auto 12px;
            border-radius: 15px;
            background: var(--request-primary-soft);
            color: var(--request-primary);
            font-size: 1.45rem;
        }

        .empty-state h4 {
            margin-bottom: 5px;
            color: var(--request-text);
            font-size: 1rem;
            font-weight: 850;
        }

        .empty-state p {
            font-size: .76rem;
        }

        .delete-warning {
            border-left: 4px solid var(--request-danger);
        }

        .cancel-warning {
            border-left: 4px solid var(--request-danger);
        }

        .modal-content {
            overflow: hidden;
            border: 0;
            border-radius: 14px;
            box-shadow: 0 20px 55px rgba(28, 42, 72, .2);
        }

        .modal-header,
        .modal-footer {
            border-color: var(--request-border);
        }

        body.ultra-compact .request-row {
            min-height: 70px;
            padding-top: 7px;
            padding-bottom: 7px;
        }

        body.ultra-compact .request-row {
            padding-top: 6px;
            padding-bottom: 6px;
        }

        body.ultra-compact .row-purpose,
        body.ultra-compact .row-meta .meta-types,
        body.ultra-compact .msg-preview,
        body.ultra-compact .workflow-tracker-head {
            display: none;
        }

        body.ultra-compact .workflow-tracker {
            padding-top: 6px;
        }

        body.ultra-compact .workflow-node {
            width: 24px;
            height: 24px;
            margin-bottom: 3px;
            font-size: .64rem;
        }

        body.ultra-compact .workflow-step:not(:last-child)::after {
            top: 11px;
        }

        body.ultra-compact .metric-card {
            padding-top: 7px;
            padding-bottom: 7px;
        }

        @media (max-width: 1380px) {
            .request-actions .action-label-secondary {
                display: none;
            }

            .request-actions .quick-review-btn {
                padding-left: 9px;
                padding-right: 9px;
            }
        }

        @media (max-width: 1120px) {
            .metrics-strip {
                grid-template-columns: repeat(4, minmax(140px, 1fr));
                overflow-x: auto;
            }

            .filter-grid {
                flex-wrap: wrap;
            }

            .filter-search-field {
                flex: 1 1 100%;
            }

            .filter-grid .filter-select-wrap {
                flex: 1 1 150px;
            }

            .row-deadline {
                display: none;
            }
        }

        @media (max-width: 820px) {
            .requests-page .content {
                padding: 10px;
            }

            .ph {
                align-items: flex-start;
            }

            .ph-subtitle {
                max-width: 55vw;
            }

            .ph-actions .result-pill,
            .ph-actions .compact-tool-btn span {
                display: none;
            }

            .compact-tool-btn {
                width: 34px;
                padding: 7px;
            }

            .filter-grid {
                flex-wrap: wrap;
            }

            .filter-search-field {
                flex: 1 1 100%;
            }

            .filter-grid .filter-select-wrap {
                flex: 1 1 140px;
            }

            .request-row {
                grid-template-columns: minmax(0, 1fr) auto;
                gap: 8px;
                padding: 9px 10px 9px 14px;
            }

            .row-purpose,
            .row-meta .meta-types {
                display: none;
            }

            .request-actions .quick-review-btn .action-label-secondary {
                display: none;
            }

            .request-actions .quick-review-btn {
                padding-left: 9px;
                padding-right: 9px;
            }

            .workflow-tracker-head {
                align-items: flex-start;
                flex-direction: column;
                gap: 4px;
            }

            .approved-by-meta {
                width: 100%;
                white-space: normal;
            }

            .approved-by-meta strong {
                max-width: none;
                white-space: normal;
            }
        }

        @media (max-width: 560px) {
            .ph-subtitle {
                display: none;
            }

            .filter-grid .filter-select-wrap {
                flex: 1 1 100%;
            }

            .filter-actions {
                width: 100%;
            }

            .filter-actions .btn-primary {
                flex: 1;
            }

            .request-row {
                grid-template-columns: minmax(0, 1fr);
            }

            .row-side {
                justify-content: space-between;
                border-top: 1px dashed var(--request-border);
                padding-top: 7px;
            }

            .row-title {
                white-space: normal;
            }

            .metrics-strip {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        /* ===== UI upgrade: breadcrumb, avatars, due pills, live tools ===== */
        .rm-crumb {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 7px;
            margin: 0 0 10px 2px;
            color: #94a3b8;
            font-size: .7rem;
            font-weight: 650;
        }

        .rm-crumb a {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #64748b;
            text-decoration: none;
            font-weight: 750;
        }

        .rm-crumb a:hover {
            color: var(--request-primary-dark);
            text-decoration: underline;
        }

        .rm-crumb-sep {
            color: #cbd5e1;
        }

        .rm-crumb-current {
            color: var(--request-text);
            font-weight: 800;
        }

        .attention-chip {
            display: inline-flex;
            min-height: 34px;
            align-items: center;
            gap: 6px;
            padding: 7px 11px;
            border-radius: 8px;
            font-size: .75rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .attention-chip.has-attention {
            background: #fef3f2;
            color: #b42318;
        }

        .attention-chip.all-clear {
            background: #ecfdf3;
            color: #027a48;
        }

        .requestor-id {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }

        .avatar {
            display: inline-flex;
            width: 30px;
            height: 30px;
            flex: 0 0 30px;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            font-size: .62rem;
            font-weight: 900;
            letter-spacing: .02em;
        }

        .av-1 { background: #e4edff; color: #1e54ba; }
        .av-2 { background: #e1f7ea; color: #126b43; }
        .av-3 { background: #f1ebff; color: #5d36ad; }
        .av-4 { background: #fff3df; color: #8a4d04; }
        .av-5 { background: #ffe9f1; color: #a1125a; }

        .due-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: .6rem;
            font-weight: 850;
            white-space: nowrap;
        }

        .due-pill.due-overdue {
            background: #fee4e2;
            color: #b42318;
        }

        .due-pill.due-warning {
            background: #fef0c7;
            color: #b54708;
        }

        /* ---- New-message notify ---- */
        .msg-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 999px;
            background: #e0e7ff;
            color: #3730a3;
            font-size: .6rem;
            font-weight: 850;
            white-space: nowrap;
        }

        .msg-pill.has-unread {
            background: #fee4e2;
            color: #b42318;
            animation: msgPulse 1.6s ease-in-out infinite;
        }

        @keyframes msgPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(180, 35, 24, .25); }
            50% { box-shadow: 0 0 0 4px rgba(180, 35, 24, 0); }
        }

        .msg-badge {
            position: absolute;
            top: -7px;
            right: -7px;
            display: inline-grid;
            min-width: 19px;
            height: 19px;
            place-items: center;
            padding: 0 5px;
            border: 2px solid #fff;
            border-radius: 999px;
            background: #d92d20;
            color: #fff;
            font-size: .6rem;
            font-weight: 900;
            line-height: 1;
        }

        .msg-badge.d-none {
            display: none !important;
        }

        .btn-has-badge {
            position: relative;
        }

        .msg-preview {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 5px;
            color: #7a2e0e;
            font-size: .66rem;
            font-weight: 600;
        }

        .msg-preview i {
            color: #b42318;
        }

        .msg-preview span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .attention-toggle.msg-toggle:hover {
            border-color: #4f46e5;
            background: #eef2ff;
            color: #3730a3;
        }

        .attention-toggle.msg-toggle.is-active {
            border-color: #4f46e5;
            background: #4f46e5;
            color: #fff;
        }

        .copy-job-btn {
            border: 0;
            background: transparent;
            color: #98a2b3;
            padding: 2px 4px;
            border-radius: 6px;
            font-size: .68rem;
            cursor: pointer;
            line-height: 1;
        }

        .copy-job-btn:hover {
            color: var(--request-primary-dark);
            background: var(--request-primary-soft);
        }

        .copy-job-btn:focus-visible {
            outline: 2px solid #93c5fd;
            outline-offset: 1px;
        }

        .search-clear {
            position: absolute;
            top: 50%;
            right: 9px;
            transform: translateY(-50%);
            border: 0;
            background: #eef2f7;
            color: #64748b;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: .65rem;
            cursor: pointer;
            z-index: 3;
        }

        .search-clear.show {
            display: inline-flex;
        }

        .search-clear:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .request-search .form-control {
            padding-right: 32px;
        }

        .toolbar-select {
            min-height: 34px;
            border: 1px solid var(--request-border-strong);
            border-radius: 8px;
            font-size: .73rem;
            font-weight: 700;
            color: #42506a;
            background: #fff;
            padding: 6px 8px;
            max-width: 180px;
        }

        .toolbar-select:focus {
            border-color: #8ab6ed;
            box-shadow: 0 0 0 .15rem rgba(23, 105, 224, .10);
            outline: none;
        }

        .attention-toggle {
            display: inline-flex;
            min-height: 34px;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border: 1px solid var(--request-border-strong);
            border-radius: 8px;
            background: #fff;
            color: #42506a;
            font-size: .73rem;
            font-weight: 800;
            cursor: pointer;
            white-space: nowrap;
        }

        .attention-toggle:hover {
            border-color: #f04438;
            color: #b42318;
            background: #fffbfa;
        }

        .attention-toggle.is-active {
            border-color: #b42318;
            background: #b42318;
            color: #fff;
        }

        .request-row.reveal {
            opacity: 0;
            transform: translateY(10px);
        }

        .request-row.reveal.in {
            opacity: 1;
            transform: none;
            transition: opacity .35s ease, transform .35s ease;
        }

        .back-top {
            position: fixed;
            right: 18px;
            bottom: 18px;
            z-index: 60;
            width: 40px;
            height: 40px;
            display: none;
            place-items: center;
            border: 1px solid var(--request-border);
            border-radius: 12px;
            background: #fff;
            color: var(--request-primary-dark);
            box-shadow: 0 10px 24px rgba(15, 23, 42, .14);
            cursor: pointer;
        }

        .back-top.show {
            display: grid;
        }

        .back-top:hover {
            background: var(--request-primary-soft);
        }

        .metric-card:focus-visible,
        .compact-tool-btn:focus-visible,
        .attention-toggle:focus-visible {
            outline: 2px solid #93c5fd;
            outline-offset: 2px;
        }

        #toastStack {
            z-index: 1080;
        }

        @media print {
            .sidebar,
            .filter-panel,
            .ph-actions,
            .request-actions,
            .metrics-strip,
            .workflow-tracker,
            .back-top,
            #toastStack {
                display: none !important;
            }

            body {
                background: #fff;
            }

            .request-row {
                box-shadow: none;
                break-inside: avoid;
            }

            .request-row.reveal {
                opacity: 1;
                transform: none;
            }

            .request-list-body {
                background: #fff;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .request-row.reveal {
                opacity: 1;
                transform: none;
            }

            .request-row.reveal.in {
                transition: none;
            }
        }
    </style>
</head>

<body class="app-shell">
    <div class="d-flex min-vh-100">

        <?php include "../includes/sidebar.php"; ?>

        <div class="main flex-grow-1 requests-page">
            <main class="content">
                <div class="container-fluid px-0">

                    <nav class="rm-crumb" aria-label="Breadcrumb">
                        <a href="<?= e(BASE_URL) ?>/dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a>
                        <span class="rm-crumb-sep">/</span>
                        <span class="rm-crumb-current" aria-current="page"><?= e($pageTitle) ?></span>
                    </nav>

                    <header class="ph">
                        <div class="ph-copy">
                            <div class="ph-icon" aria-hidden="true">
                                <i class="bi bi-kanban"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="ph-eyebrow">HPU Workbench &middot; <?= e(ucfirst($role)) ?> Portal</p>
                                <h1 class="ph-title">
                                    <?= e($pageTitle) ?>
                                </h1>
                                <p class="ph-subtitle">
                                    <?= e($pageDescription) ?>
                                </p>
                            </div>
                        </div>

                        <div class="ph-actions no-print">
                            <span class="result-pill" title="Matching records">
                                <i class="bi bi-list-check"></i>
                                <span id="phResultCount"><?= number_format($filteredTotal) ?></span>&nbsp;result<span id="phResultPlural"><?= $filteredTotal === 1 ? "" : "s" ?></span>
                            </span>

                            <?php if ($filteredTotal > 0): ?>
                                <?php if ($attentionTotal > 0): ?>
                                    <span class="attention-chip has-attention" title="<?= $attentionOverdue ?> overdue · <?= $attentionDueSoon ?> due within 3 days">
                                        <i class="bi bi-exclamation-triangle-fill"></i>
                                        <?= number_format($attentionTotal) ?> need<?= $attentionTotal === 1 ? "s" : "" ?> attention
                                    </span>
                                <?php else: ?>
                                    <span class="attention-chip all-clear" title="No overdue or due-soon requests">
                                        <i class="bi bi-check-circle-fill"></i>
                                        All caught up
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <button type="button" class="compact-tool-btn" id="densityToggle" aria-pressed="false"
                                title="Toggle row density">
                                <i class="bi bi-arrows-collapse"></i>
                                <span>Compact</span>
                            </button>

                            <a class="compact-tool-btn" href="<?= e(BASE_URL) ?>/staff/archived_requests.php"
                                title="View archived requests">
                                <i class="bi bi-archive"></i>
                                <span>Archived</span>
                            </a>

                            <a class="compact-tool-btn" href="requests.php" title="Refresh request list">
                                <i class="bi bi-arrow-clockwise"></i>
                                <span>Refresh</span>
                            </a>
                        </div>
                    </header>

                    <?php if ($requestMessage): ?>
                                <div class="alert alert-<?= e($requestMessage["type"] ?? "success") ?> compact-alert
                        alert-dismissible fade show"
                                    role="alert"
                                    >
                                    <i class="bi bi-info-circle me-1"></i>
                                    <?= e($requestMessage["text"] ?? "") ?>

                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                    <?php endif; ?>

                    <nav class="metrics-strip" aria-label="Request status summary">
                        <?php foreach ($summaryCards as $metric): ?>
                                    <?php
                                    $isMetricActive = $statusFilter === $metric["status"];
                                    ?>
                                        <a class="metric-card <?= e($metric["class"]) ?>
                            <?= $isMetricActive ? "active" : "" ?>"
                                            href="
                            <?= e(requestFilterUrl($metric["status"], $search, $departmentFilter)) ?>"
                                            title="<?= $metric["status"] === "" ? "Show all requests" : "Filter by " . e($metric["label"]) ?>"
                                        aria-current="
                            <?= $isMetricActive ? "page" : "false" ?>"
                                        >
                                        <span class="metric-icon">
                                            <i class="bi <?= e($metric["icon"]) ?>"></i>
                                        </span>
                                        <span class="min-w-0">
                                            <span class="metric-label d-block">
                                                <?= e($metric["label"]) ?>
                                            </span>
                                            <span class="metric-value d-block">
                                                <?= number_format((int) $metric["count"]) ?>
                                            </span>
                                        </span>
                                    </a>
                        <?php endforeach; ?>
                    </nav>

                    <section class="filter-panel no-print" aria-label="Request filters">
                        <div class="card-body">
                            <form method="GET" id="requestFilterForm">
                                <div class="filter-grid">
                                    <div class="filter-search-field">
                                        <label class="compact-label" for="requestSearch">Search requests</label>
                                    <div class="request-search">
                                        <i class="bi bi-search"></i>
                                        <input id="requestSearch" type="search" class="form-control" name="search"
                                            value="<?= e($search) ?>"
                                            placeholder="Search job no., project, requestor…"
                                            autocomplete="off">
                                        <button type="button" class="search-clear" id="searchClearBtn" title="Clear search" aria-label="Clear search"><i class="bi bi-x-lg"></i></button>
                                    </div>
                                    </div>

                                    <div class="filter-select-wrap">
                                        <label class="compact-label" for="statusFilter">Status</label>
                                        <select id="statusFilter" class="form-select" name="status" aria-label="Filter by status">
                                            <option value="">All statuses</option>
                                            <?php foreach ([
                                                "Pending Review",
                                                "Under Review",
                                                "Approved",
                                                "In Development",
                                                "For Revision",
                                                "Disapproved",
                                                "Completed",
                                                "For Evaluation",
                                                "Closed",
                                                "Cancelled"
                                            ] as $filterStatus): ?>
                                                        <option value="<?= e($filterStatus) ?>" <?= $statusFilter === $filterStatus ? "selected" : "" ?>
                                                            >
                                                            <?= e($filterStatus) ?>
                                                        </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="filter-select-wrap">
                                        <label class="compact-label" for="departmentFilter">Department</label>
                                        <select id="departmentFilter" class="form-select" name="department" aria-label="Filter by department">
                                            <option value="">All departments</option>
                                            <?php foreach ($departments as $department): ?>
                                                        <?php $departmentName = (string) $department["department"]; ?>
                                                        <option value="<?= e($departmentName) ?>"
                                                            <?= $departmentFilter === $departmentName ? "selected" : "" ?>
                                                            >
                                                            <?= e($departmentName) ?>
                                                        </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="filter-actions">
                                        <button class="btn btn-primary" type="submit" title="Apply filters" aria-label="Apply filters">
                                            <i class="bi bi-funnel"></i>
                                        </button>

                                        <a class="btn btn-outline-secondary" href="requests.php"
                                            title="Clear all filters" aria-label="Clear all filters">
                                            <i class="bi bi-x-lg"></i>
                                        </a>
                                    </div>
                                </div>

                                <?php if ($activeFilterCount > 0): ?>
                                            <div class="active-filters">
                                                <?php if ($search !== ""): ?>
                                                            <span class="filter-chip">
                                                                <i class="bi bi-search"></i>
                                                                <span>
                                                                    <?= e($search) ?>
                                                                </span>
                                                            </span>
                                                <?php endif; ?>

                                                <?php if ($statusFilter !== ""): ?>
                                                            <span class="filter-chip">
                                                                <i class="bi bi-activity"></i>
                                                                <span>
                                                                    <?= e($statusFilter) ?>
                                                                </span>
                                                            </span>
                                                <?php endif; ?>

                                                <?php if ($departmentFilter !== ""): ?>
                                                            <span class="filter-chip">
                                                                <i class="bi bi-building"></i>
                                                                <span>
                                                                    <?= e($departmentFilter) ?>
                                                                </span>
                                                            </span>
                                                <?php endif; ?>
                                            </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </section>

                    <section class="list-shell" aria-label="Job request list">
                    <div class="list-toolbar">
                        <div class="list-toolbar-title">
                            <i class="bi bi-list-ul"></i>
                            Request Queue
                            <span class="badge text-bg-light border" id="queueCount">
                                <?= number_format($filteredTotal) ?>
                            </span>
                        </div>

                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <div class="list-toolbar-note d-none d-xl-block">
                                Color-coded by status · newest requests first
                            </div>

                            <button type="button" class="attention-toggle" id="overdueToggle" aria-pressed="false" title="Show only overdue requests">
                                <i class="bi bi-exclamation-circle"></i>
                                <span>Overdue</span>
                            </button>

                            <button type="button" class="attention-toggle msg-toggle" id="newMsgToggle" aria-pressed="false" title="Show only requests with new messages">
                                <i class="bi bi-chat-dots-fill"></i>
                                <span>New messages</span>
                                <span class="badge text-bg-danger" id="newMsgCount"><?= (int) count($msgUnread) ?></span>
                            </button>

                            <select id="clientSort" class="toolbar-select" aria-label="Sort visible requests">
                                <option value="server">Server order</option>
                                <option value="unread">Unread first</option>
                                <option value="oldest">Oldest first</option>
                                <option value="title">Title A–Z</option>
                                <option value="deadline">Deadline soonest</option>
                            </select>
                        </div>
                    </div>

                        <div class="request-list-body" id="requestListBody">
                            <?php foreach ($requests as $row): ?>
                                        <?php
                                        $status = (string) ($row["approval_status"] ?? "");
                                        $evaluationSubmitted = $row["satisfaction_rating"] !== null;

                                        $canNotifyAnytime = in_array(
                                            $role,
                                            ["staff", "supervisor", "admin"],
                                            true
                                        );

                                        $showEvaluationButton = $canNotifyAnytime
                                            || in_array(
                                                $status,
                                                ["Completed", "For Evaluation", "Closed", "Cancelled"],
                                                true
                                            );

                                        $requestDateTimestamp = !empty($row["date_request"])
                                            ? strtotime((string) $row["date_request"])
                                            : (!empty($row["created_at"])
                                                ? strtotime((string) $row["created_at"])
                                                : false);

                                        $requestAgeDays = $requestDateTimestamp
                                            ? max(0, (int) floor((time() - $requestDateTimestamp) / 86400))
                                            : null;

                                        $deadlineInfo =
                                            staffDeadlineInfo(
                                                $row["date_completion"] ?? null,
                                                $row["approved_date"] ?? null,
                                                $status,
                                                $row["request_types"] ?? null
                                            );

                                        $processingTarget =
                                            $deadlineInfo["target_date"]
                                            ?? null;

                                        $leadDays =
                                            (int) (
                                                $deadlineInfo["lead_days"]
                                                ?? 0
                                            );

                                        $deadlineState =
                                            (string) (
                                                $deadlineInfo["state"]
                                                ?? ""
                                            );

                                        $deadlineText =
                                            (string) (
                                                $deadlineInfo["text"]
                                                ?? ""
                                            );

                                        $showDuePill = in_array(
                                            $deadlineState,
                                            ["overdue", "today", "soon"],
                                            true
                                        );

                                        $duePillClass = $deadlineState === "overdue"
                                            ? "due-overdue"
                                            : "due-warning";

                                        $rowTypes =
                                            staffRequestTypes(
                                                $row["request_types"]
                                                ?? null
                                            );

                                        $rowSearchHay = mb_strtolower(
                                            implode(
                                                " ",
                                                array_filter(
                                                    [
                                                        (string) ($row["job_no"] ?? ""),
                                                        (string) ($row["project_title"] ?? ""),
                                                        (string) ($row["requestor_name"] ?? ""),
                                                        (string) ($row["department"] ?? ""),
                                                        (string) ($row["requestor_email"] ?? ""),
                                                        $status,
                                                        implode(" ", $rowTypes)
                                                    ]
                                                )
                                            )
                                        );

                                        $rowSortTitle = mb_strtolower(
                                            (string) ($row["project_title"] ?? "")
                                        );

                                        $rowSortDeadline = (string) (
                                            $processingTarget
                                            ?? $row["date_completion"]
                                            ?? ""
                                        );

                                        $isFinished = in_array(
                                            $status,
                                            ["Completed", "For Evaluation", "Closed", "Cancelled"],
                                            true
                                        );

                                        $isCancellable = !in_array(
                                            $status,
                                            ["Cancelled", "Closed"],
                                            true
                                        );

                                        $workflowCurrentIndex = requestWorkflowIndex($status);
                                        $workflowCollapseId = "workflow-tracking-" . (int) $row["id"];

                                        $rowUnread = (int) ($msgUnread[(int) $row["id"]] ?? 0);
                                        $rowPreview = $msgLatest[(int) $row["id"]] ?? null;
                                        $rowPreviewTitle = $rowPreview
                                            ? (($rowPreview["sender"] !== "" ? $rowPreview["sender"] . ": " : "") . $rowPreview["message"])
                                            : "";
                                        ?>

                                        <article class="request-row reveal <?= e(requestStatusClass($status)) ?>"
                                            data-search="<?= e($rowSearchHay) ?>"
                                            data-id="<?= (int) $row["id"] ?>"
                                            data-status="<?= e($status) ?>"
                                            data-title="<?= e($rowSortTitle) ?>"
                                            data-deadline="<?= e($rowSortDeadline) ?>"
                                            data-deadline-state="<?= e($deadlineState) ?>"
                                            data-unread="<?= (int) $rowUnread ?>">
                                            <div class="row-main">
                                                <span class="avatar <?= e(requestorAvatarClass((string) ($row["requestor_name"] ?? ""))) ?>" aria-hidden="true" title="<?= e($row["requestor_name"] ?? "") ?>"><?= e(requestorInitials((string) ($row["requestor_name"] ?? ""))) ?></span>
                                                <div class="row-text">
                                                    <div class="row-top">
                                                        <span class="job-chip" title="Job <?= e($row["job_no"] ?? "") ?>">
                                                            <i class="bi bi-hash"></i><?= e($row["job_no"] ?? "") ?>
                                                            <button type="button" class="copy-job-btn" data-copy="<?= e($row["job_no"] ?? "") ?>" title="Copy job number" aria-label="Copy job number <?= e($row["job_no"] ?? "") ?>"><i class="bi bi-clipboard"></i></button>
                                                        </span>
                                                        <a class="row-title" href="<?= e(BASE_URL) ?>/admin/view.php?id=<?= (int) $row["id"] ?>" title="<?= e($row["project_title"] ?? "") ?>">
                                                            <?= e($row["project_title"] ?: "Untitled Project") ?>
                                                        </a>
                                                        <span class="status-badge <?= e(requestStatusClass($status)) ?>">
                                                            <i class="bi <?= e(requestStatusIcon($status)) ?>"></i>
                                                            <?= e($status ?: "Unknown") ?>
                                                        </span>
                                                        <?php if ($showDuePill): ?>
                                                            <span class="due-pill <?= e($duePillClass) ?>" title="<?= e($deadlineText) ?>">
                                                                <i class="bi <?= $deadlineState === "overdue" ? "bi-exclamation-octagon-fill" : "bi-alarm-fill" ?>"></i>
                                                                <?= $deadlineState === "overdue" ? "Overdue" : ($deadlineState === "today" ? "Due today" : "Due soon") ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="row-meta">
                                                        <span class="meta-item" title="<?= e($row["requestor_name"] ?? "") ?>">
                                                            <i class="bi bi-person"></i><?= e($row["requestor_name"] ?: "Not specified") ?>
                                                        </span>
                                                        <?php $requestorEmail = trim((string)($row["requestor_email"] ?? "")); ?>
                                                        <?php if ($requestorEmail !== "" && filter_var($requestorEmail, FILTER_VALIDATE_EMAIL)): ?>
                                                            <a class="meta-item meta-link" href="mailto:<?= e($requestorEmail) ?>" title="<?= e($requestorEmail) ?>">
                                                                <i class="bi bi-envelope"></i><?= e($requestorEmail) ?>
                                                            </a>
                                                        <?php endif; ?>
                                                        <span class="meta-item" title="<?= e($row["department"] ?? "") ?>">
                                                            <i class="bi bi-building"></i><?= e($row["department"] ?: "No department") ?>
                                                        </span>
                                                        <?php if ($rowTypes): ?>
                                                            <span class="meta-item meta-types" title="<?= e(implode(", ", $rowTypes)) ?>">
                                                                <i class="bi bi-tags"></i><?= e(implode(" · ", array_slice($rowTypes, 0, 2))) ?><?= count($rowTypes) > 2 ? " +" . (count($rowTypes) - 2) : "" ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <span class="meta-item" title="Request age">
                                                            <i class="bi bi-clock-history"></i><?= $requestAgeDays !== null ? number_format($requestAgeDays) . "d old" : "Age n/a" ?>
                                                        </span>
                                                        <?php if ($evaluationSubmitted): ?>
                                                            <span class="meta-item evaluation-complete">
                                                                <i class="bi bi-check-circle-fill"></i>Evaluated
                                                            </span>
                                                        <?php endif; ?>
                                                        <span class="msg-pill <?= $rowUnread > 0 ? "has-unread" : "" ?>" data-msg-pill title="<?= e($rowPreviewTitle !== "" ? $rowPreviewTitle : "No new messages") ?>">
                                                            <i class="bi <?= $rowUnread > 0 ? "bi-chat-dots-fill" : "bi-chat" ?>"></i>
                                                            <span data-msg-pill-label><?= $rowUnread > 0 ? ("New message" . ($rowUnread > 1 ? "s (" . $rowUnread . ")" : "")) : "No new messages" ?></span>
                                                        </span>
                                                    </div>
                                                    <?php $purposeSnippet = trim((string) ($row["purpose_objective"] ?? "")); ?>
                                                    <?php if ($purposeSnippet !== ""): ?>
                                                        <div class="row-purpose" title="<?= e($purposeSnippet) ?>">
                                                            <?= e(mb_strimwidth($purposeSnippet, 0, 140, "...")) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="msg-preview" data-msg-preview title="<?= e($rowPreviewTitle) ?>"<?= ($rowPreview && $rowUnread > 0) ? "" : ' style="display:none"' ?>>
                                                        <i class="bi bi-reply-fill"></i>
                                                        <span><?= e(($rowPreview && ($rowPreview["sender"] ?? "") !== "" ? $rowPreview["sender"] . ": " : "") . ($rowPreview["message"] ?? "")) ?></span>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row-side">
                                                <div class="row-deadline <?= e((string) ($deadlineInfo["class"] ?? "")) ?>" title="<?= e($deadlineText) ?>">
                                                    <i class="bi <?= e((string) ($deadlineInfo["icon"] ?? "bi-calendar-event")) ?>"></i>
                                                    <div>
                                                        <small><?= $processingTarget ? "Processing target" : "Preferred date" ?></small>
                                                        <b><?= e(compactDate($processingTarget ?? ($row["date_completion"] ?? null), "Not set")) ?></b>
                                                    </div>
                                                </div>
                                                <div class="request-actions no-print">
                                                <a class="btn btn-primary quick-review-btn"
                                                    href="<?= e(BASE_URL) ?>/staff/review_request.php?id=<?= (int) $row["id"] ?>"
                                                    title="<?= e(reviewButtonLabel($role, $status)) ?>"
                                                    >
                                                    <i class="bi <?= e(reviewButtonIcon($role, $status)) ?>"></i>
                                                    <span class="action-label-secondary">
                                                        <?= e(reviewButtonLabel($role, $status)) ?>
                                                    </span>
                                                </a>

                                                <a class="btn btn-outline-primary btn-icon quick-view-btn btn-has-badge"
                                                    href="<?= e(BASE_URL) ?>/admin/view.php?id=<?= (int) $row["id"] ?>"
                                                    title="Open request details<?= $rowUnread > 0 ? " — " . $rowUnread . " new message(s)" : "" ?>"
                                                    aria-label="Open request details"
                                                    >
                                                    <i class="bi bi-eye"></i>
                                                    <span class="msg-badge <?= $rowUnread > 0 ? "" : "d-none" ?>" data-msg-badge><?= $rowUnread > 99 ? "99+" : (int) $rowUnread ?></span>
                                                </a>

                                                <button class="btn btn-outline-secondary btn-icon workflow-toggle-btn" type="button" data-bs-toggle="collapse"
                                                    data-bs-target="#<?= e($workflowCollapseId) ?>" aria-expanded="false"
                                                    aria-controls="<?= e($workflowCollapseId) ?>" title="Show workflow tracking" aria-label="Show workflow tracking">
                                                    <i class="bi bi-diagram-3"></i>
                                                    <span class="action-label workflow-toggle-label visually-hidden">Track</span>
                                                </button>

                                                <div class="dropdown">
                                                    <button class="btn btn-outline-secondary btn-icon dropdown-toggle" type="button"
                                                        data-bs-toggle="dropdown" aria-expanded="false"
                                                        aria-label="More request actions" title="More actions">
                                                        <i class="bi bi-three-dots"></i>
                                                    </button>

                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li>
                                                            <a class="dropdown-item"
                                                                href="<?= e(BASE_URL) ?>/staff/activity.php?id=<?= (int) $row["id"] ?>"
                                                                >
                                                                <i class="bi bi-clock-history"></i>
                                                                Activity timeline
                                                            </a>
                                                        </li>

                                                        <li>
                                                            <a class="dropdown-item"
                                                                href="<?= e(BASE_URL) ?>/user/print_request.php?id=<?= (int) $row["id"] ?>"
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                >
                                                                <i class="bi bi-printer"></i>
                                                                Print request
                                                            </a>
                                                        </li>

                                                        <?php if ($showEvaluationButton): ?>
                                                                    <li>
                                                                        <a class="dropdown-item"
                                                                            href="<?= e(BASE_URL) ?>/staff/evaluation.php?id=<?= (int) $row["id"] ?>"
                                                                            >
                                                                            <i class="bi bi-clipboard2-check"></i>
                                                                            <?= $evaluationSubmitted
                                                                                ? "View evaluation"
                                                                                : ($status === "For Evaluation"
                                                                                    ? "Monitor evaluation"
                                                                                    : "Notify requestor")
                                                                                ?>
                                                                        </a>
                                                                    </li>
                                                        <?php endif; ?>

                                                        <?php if (in_array($role, ["staff", "supervisor", "admin"], true)): ?>
                                                                    <li>
                                                                        <hr class="dropdown-divider">
                                                                    </li>

                                                                    <?php if ($isCancellable): ?>
                                                                    <li>
                                                                        <button type="button" class="dropdown-item text-danger cancel-request-button"
                                                                            data-bs-toggle="modal" data-bs-target="#cancelRequestModal"
                                                                            data-id="<?= (int) $row["id"] ?>"
                                                                            data-job-no="<?= e($row["job_no"] ?? "") ?>"
                                                                            data-project="<?= e($row["project_title"] ?? "") ?>"
                                                                            data-status="<?= e($status) ?>"
                                                                            >
                                                                            <i class="bi bi-x-octagon text-danger"></i>
                                                                            Cancel request
                                                                        </button>
                                                                    </li>
                                                                    <?php endif; ?>

                                                                    <li>
                                                                        <button type="button" class="dropdown-item archive-request-button"
                                                                            data-bs-toggle="modal" data-bs-target="#archiveRequestModal"
                                                                            data-id="<?= (int) $row["id"] ?>"
                                                                            data-job-no="
                                                    <?= e($row["job_no"] ?? "") ?>"
                                                                            data-project="
                                                    <?= e($row["project_title"] ?? "") ?>"
                                                                            >
                                                                            <i class="bi bi-archive text-warning"></i>
                                                                            Archive request
                                                                        </button>
                                                                    </li>
                                                        <?php endif; ?>

                                                        <?php if (in_array($role, ["supervisor", "admin"], true)): ?>
                                                                    <li>
                                                                        <button type="button"
                                                                            class="dropdown-item text-danger delete-request-button"
                                                                            data-bs-toggle="modal" data-bs-target="#deleteRequestModal"
                                                                            data-id="<?= (int) $row["id"] ?>"
                                                                            data-job-no="
                                                    <?= e($row["job_no"] ?? "") ?>"
                                                                            data-project="
                                                    <?= e($row["project_title"] ?? "") ?>"
                                                                            >
                                                                            <i class="bi bi-trash text-danger"></i>
                                                                            Delete request
                                                                        </button>
                                                                    </li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            </div>

                                            <div class="workflow-tracker collapse" id="<?= e($workflowCollapseId) ?>"
                                                data-bs-parent="#requestListBody" aria-label="Request workflow tracking">
                                                <div class="workflow-tracker-head">
                                                    <span class="workflow-tracker-title">
                                                        <i class="bi bi-diagram-3"></i>
                                                        Workflow Tracking
                                                    </span>

                                                    <span class="approved-by-meta <?= empty($row["approved_by"]) ? "pending" : "" ?>">
                                                        <i class="bi <?= empty($row["approved_by"]) ? "bi-hourglass-split" : "bi-person-check-fill" ?>"></i>
                                                        <span>Approved by:</span>
                                                        <strong>
                                                            <?= e($row["approved_by"] ?: "Pending approval") ?>
                                                        </strong>
                                                        <?php if (!empty($row["approved_date"])): ?>
                                                                    <span>on
                                                                        <?= e(compactDate($row["approved_date"])) ?>
                                                                    </span>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>

                                                <div class="workflow-track-scroll">
                                                    <div class="workflow-track">
                                                        <?php foreach ($workflowSteps as $stepIndex => $workflowStep): ?>
                                                                    <?php
                                                                    $workflowState = requestWorkflowState(
                                                                        (int) $stepIndex,
                                                                        $workflowCurrentIndex,
                                                                        $status
                                                                    );
                                                                    ?>
                                                                    <div class="workflow-step <?= e($workflowState) ?>">
                                                                        <span class="workflow-node">
                                                                            <i class="bi <?= e($workflowStep["icon"]) ?>"></i>
                                                                        </span>
                                                                        <span class="workflow-label d-block">
                                                                            <?= e($workflowStep["label"]) ?>
                                                                        </span>
                                                                    </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </article>
                            <?php endforeach; ?>

                            <?php if (empty($requests)): ?>
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <i class="bi bi-inbox"></i>
                                            </div>
                                            <h4>No requests found</h4>
                                            <p class="mb-3">No job requests match the selected filters.</p>
                                            <a class="btn btn-sm btn-outline-primary" href="requests.php">
                                                <i class="bi bi-arrow-counterclockwise me-1"></i>
                                                Reset filters
                                            </a>
                                        </div>
                            <?php endif; ?>

                            <div class="empty-state d-none" id="clientNoMatch">
                                <div class="empty-icon">
                                    <i class="bi bi-search"></i>
                                </div>
                                <h4>No matching requests</h4>
                                <p class="mb-3">Nothing on this page matches your search or filters.</p>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="clientClearAll">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>
                                    Clear search &amp; filters
                                </button>
                            </div>
                        </div>
                    </section>
                </div>
            </main>
        </div>
    </div>

    <?php if (in_array($role, ["supervisor", "admin"], true)): ?>
                <div class="modal fade" id="deleteRequestModal" tabindex="-1" aria-labelledby="deleteRequestModalLabel"
                    aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <form method="POST" action="<?= e(BASE_URL) ?>/staff/delete_request.php">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                                <input type="hidden" id="delete_request_id" name="request_id">

                                <div class="modal-header">
                                    <h5 class="modal-title text-danger" id="deleteRequestModalLabel">
                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                        Delete Job Request
                                    </h5>

                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>

                                <div class="modal-body">
                                    <p class="mb-3">
                                        Are you sure you want to permanently delete this job request?
                                    </p>

                                    <div class="alert alert-light border py-2">
                                        <strong id="delete_job_number"></strong>
                                        <div id="delete_project_title" class="text-muted small mt-1"></div>
                                    </div>

                                    <div class="alert alert-danger delete-warning mb-0 small">
                                        <i class="bi bi-exclamation-circle me-1"></i>
                                        This deletes the job request, IEC materials, notifications and uploaded attachment. This
                                        action cannot be undone.
                                    </div>
                                </div>

                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                        Cancel
                                    </button>

                                    <button type="submit" class="btn btn-danger">
                                        <i class="bi bi-trash me-1"></i>
                                        Delete Permanently
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (in_array($role, ["staff", "supervisor", "admin"], true)): ?>
                <div class="modal fade" id="archiveRequestModal" tabindex="-1" aria-labelledby="archiveRequestModalLabel"
                    aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <form method="POST" action="<?= e(BASE_URL) ?>/staff/archive_request.php">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                                <input type="hidden" id="archive_request_id" name="request_id">

                                <div class="modal-header">
                                    <h5 class="modal-title" id="archiveRequestModalLabel">
                                        <i class="bi bi-archive me-2 text-warning"></i>
                                        Archive Job Request
                                    </h5>

                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>

                                <div class="modal-body">
                                    <p class="mb-3">
                                        Move this job request to the archive?
                                    </p>

                                    <div class="alert alert-light border py-2">
                                        <strong id="archive_job_number"></strong>
                                        <div id="archive_project_title" class="text-muted small mt-1"></div>
                                    </div>

                                    <div class="alert alert-info mb-0 small">
                                        <i class="bi bi-info-circle me-1"></i>
                                        The request will be removed from the active list and kept in the archive for audit and
                                        reporting. Supervisors and admins can restore it anytime.
                                    </div>
                                </div>

                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                        Cancel
                                    </button>

                                    <button type="submit" class="btn btn-warning">
                                        <i class="bi bi-archive me-1"></i>
                                        Archive Request
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="modal fade" id="cancelRequestModal" tabindex="-1" aria-labelledby="cancelRequestModalLabel"
                    aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <form method="POST" action="<?= e(BASE_URL) ?>/staff/cancel_request.php">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                                <input type="hidden" id="cancel_request_id" name="request_id">

                                <div class="modal-header">
                                    <h5 class="modal-title text-danger" id="cancelRequestModalLabel">
                                        <i class="bi bi-x-octagon me-2"></i>
                                        Cancel Job Request
                                    </h5>

                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>

                                <div class="modal-body">
                                    <p class="mb-3">
                                        Cancel this job request? The requestor will be notified automatically.
                                    </p>

                                    <div class="alert alert-light border py-2">
                                        <strong id="cancel_job_number"></strong>
                                        <div id="cancel_project_title" class="text-muted small mt-1"></div>
                                        <div id="cancel_current_status" class="mt-1">
                                            <span class="badge text-bg-light border">Status: <span id="cancel_status_text"></span></span>
                                        </div>
                                    </div>

                                    <label class="form-label fw-semibold" for="cancel_reason">
                                        Reason for cancellation <span class="text-muted fw-normal">(optional)</span>
                                    </label>
                                    <textarea class="form-control" id="cancel_reason" name="cancel_reason" rows="3" maxlength="1000"
                                        placeholder="e.g. Duplicate request, withdrawn by requestor, cannot be accommodated…"></textarea>

                                    <div class="alert alert-danger cancel-warning mt-3 mb-0 small">
                                        <i class="bi bi-exclamation-circle me-1"></i>
                                        This sets the status to <strong>Cancelled</strong>. The request stays in the list for audit
                                        and the requestor can no longer edit it.
                                    </div>
                                </div>

                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                        Keep Request
                                    </button>

                                    <button type="submit" class="btn btn-danger">
                                        <i class="bi bi-x-octagon me-1"></i>
                                        Cancel Request
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
    <?php endif; ?>

    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastStack" aria-live="polite">
        <div id="liveToast" class="toast align-items-center" role="status">
            <div class="d-flex">
                <div class="toast-body" id="liveToastBody">Done.</div>
                <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    </div>

    <button type="button" class="back-top no-print" id="backTop" title="Back to top" aria-label="Back to top">
        <i class="bi bi-arrow-up"></i>
    </button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const densityToggle = document.getElementById("densityToggle");
            const savedDensity = localStorage.getItem("hpuRequestDensity");

            if (savedDensity === "ultra") {
                document.body.classList.add("ultra-compact");
                densityToggle?.setAttribute("aria-pressed", "true");

                const savedIcon = densityToggle?.querySelector("i");
                const savedLabel = densityToggle?.querySelector("span");

                if (savedIcon) {
                    savedIcon.className = "bi bi-arrows-expand";
                }

                if (savedLabel) {
                    savedLabel.textContent = "Comfortable";
                }
            }

            densityToggle?.addEventListener("click", function () {
                const enabled = document.body.classList.toggle("ultra-compact");
                this.setAttribute("aria-pressed", enabled ? "true" : "false");
                localStorage.setItem("hpuRequestDensity", enabled ? "ultra" : "normal");

                const icon = this.querySelector("i");
                const label = this.querySelector("span");

                if (icon) {
                    icon.className = enabled
                        ? "bi bi-arrows-expand"
                        : "bi bi-arrows-collapse";
                }

                if (label) {
                    label.textContent = enabled ? "Comfortable" : "Compact";
                }
            });

            document.querySelectorAll(".delete-request-button").forEach(function (button) {
                button.addEventListener("click", function () {
                    const requestIdField = document.getElementById("delete_request_id");
                    const jobNumberField = document.getElementById("delete_job_number");
                    const projectTitleField = document.getElementById("delete_project_title");

                    if (requestIdField) {
                        requestIdField.value = this.dataset.id || "";
                    }

                    if (jobNumberField) {
                        jobNumberField.textContent = this.dataset.jobNo || "";
                    }

                    if (projectTitleField) {
                        projectTitleField.textContent = this.dataset.project || "";
                    }
                });
            });

            document.querySelectorAll(".archive-request-button").forEach(function (button) {
                button.addEventListener("click", function () {
                    const requestIdField = document.getElementById("archive_request_id");
                    const jobNumberField = document.getElementById("archive_job_number");
                    const projectTitleField = document.getElementById("archive_project_title");

                    if (requestIdField) {
                        requestIdField.value = this.dataset.id || "";
                    }

                    if (jobNumberField) {
                        jobNumberField.textContent = this.dataset.jobNo || "";
                    }

                    if (projectTitleField) {
                        projectTitleField.textContent = this.dataset.project || "";
                    }
                });
            });

            document.querySelectorAll(".cancel-request-button").forEach(function (button) {
                button.addEventListener("click", function () {
                    const requestIdField = document.getElementById("cancel_request_id");
                    const jobNumberField = document.getElementById("cancel_job_number");
                    const projectTitleField = document.getElementById("cancel_project_title");
                    const statusText = document.getElementById("cancel_status_text");
                    const reasonField = document.getElementById("cancel_reason");

                    if (requestIdField) {
                        requestIdField.value = this.dataset.id || "";
                    }

                    if (jobNumberField) {
                        jobNumberField.textContent = this.dataset.jobNo || "";
                    }

                    if (projectTitleField) {
                        projectTitleField.textContent = this.dataset.project || "";
                    }

                    if (statusText) {
                        statusText.textContent = this.dataset.status || "";
                    }

                    if (reasonField) {
                        reasonField.value = "";
                    }
                });
            });

            document.querySelectorAll(".workflow-tracker").forEach(function (panel) {
                const selector = '[data-bs-target="#' + panel.id + '"]';
                const toggle = document.querySelector(selector);
                const label = toggle?.querySelector(".workflow-toggle-label");

                panel.addEventListener("show.bs.collapse", function () {
                    document.querySelectorAll(".workflow-toggle-btn.is-open").forEach(function (otherToggle) {
                        if (otherToggle !== toggle) {
                            otherToggle.classList.remove("is-open");
                            otherToggle.setAttribute("aria-expanded", "false");
                            otherToggle.setAttribute("title", "Show workflow tracking");

                            const otherLabel = otherToggle.querySelector(".workflow-toggle-label");
                            if (otherLabel) {
                                otherLabel.textContent = "Track";
                            }
                        }
                    });

                    toggle?.classList.add("is-open");
                    toggle?.setAttribute("aria-expanded", "true");
                    toggle?.setAttribute("title", "Hide workflow tracking");

                    if (label) {
                        label.textContent = "Hide";
                    }
                });

                panel.addEventListener("hidden.bs.collapse", function () {
                    toggle?.classList.remove("is-open");
                    toggle?.setAttribute("aria-expanded", "false");
                    toggle?.setAttribute("title", "Show workflow tracking");

                    if (label) {
                        label.textContent = "Track";
                    }
                });
            });

            const statusFilter = document.getElementById("statusFilter");
            const departmentFilter = document.getElementById("departmentFilter");
            const filterForm = document.getElementById("requestFilterForm");

            [statusFilter, departmentFilter].forEach(function (select) {
                select?.addEventListener("change", function () {
                    filterForm?.requestSubmit();
                });
            });

            /* ===== UI upgrade: live search, sort, overdue filter, copy, reveal ===== */
            const searchInput = document.getElementById("requestSearch");
            const searchClear = document.getElementById("searchClearBtn");
            const clientSort = document.getElementById("clientSort");
            const overdueToggle = document.getElementById("overdueToggle");
            const newMsgToggle = document.getElementById("newMsgToggle");
            const newMsgCount = document.getElementById("newMsgCount");
            const listBody = document.getElementById("requestListBody");
            const clientNoMatch = document.getElementById("clientNoMatch");
            const queueCount = document.getElementById("queueCount");
            const phResultCount = document.getElementById("phResultCount");
            const phResultPlural = document.getElementById("phResultPlural");
            const clientClearAll = document.getElementById("clientClearAll");
            const backTop = document.getElementById("backTop");
            const rows = Array.prototype.slice.call(
                listBody ? listBody.querySelectorAll(".request-row") : []
            );
            const totalRows = rows.length;
            const reduceMotion = window.matchMedia
                && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
            let overdueOnly = false;
            let newMsgOnly = false;
            let searchTimer = null;

            function showToast(message) {
                const toastEl = document.getElementById("liveToast");
                const toastBody = document.getElementById("liveToastBody");

                if (!toastEl || !window.bootstrap) {
                    return;
                }

                if (toastBody) {
                    toastBody.textContent = message;
                }

                window.bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 2200 }).show();
            }

            function fmtNum(n) {
                return n.toLocaleString("en-US");
            }

            function updateCounts(visible) {
                if (queueCount) {
                    queueCount.textContent = visible === totalRows
                        ? fmtNum(totalRows)
                        : fmtNum(visible) + " / " + fmtNum(totalRows);
                }

                if (phResultCount) {
                    phResultCount.textContent = fmtNum(visible);
                }

                if (phResultPlural) {
                    phResultPlural.textContent = visible === 1 ? "" : "s";
                }
            }

            function applyClientFilters() {
                const q = ((searchInput && searchInput.value) || "").trim().toLowerCase();
                const filterActive = q !== "" || overdueOnly || newMsgOnly;
                let visible = 0;

                rows.forEach(function (row) {
                    const hay = row.getAttribute("data-search") || "";
                    const matchesSearch = q === "" || hay.indexOf(q) !== -1;
                    const matchesOverdue = !overdueOnly
                        || row.getAttribute("data-deadline-state") === "overdue";
                    const matchesNewMsg = !newMsgOnly
                        || (+row.getAttribute("data-unread") || 0) > 0;
                    const show = matchesSearch && matchesOverdue && matchesNewMsg;

                    row.classList.toggle("d-none", !show);

                    if (show) {
                        visible++;

                        if (filterActive && !row.classList.contains("in")) {
                            row.classList.add("in");
                        }
                    }
                });

                if (clientNoMatch) {
                    clientNoMatch.classList.toggle(
                        "d-none",
                        visible > 0 || totalRows === 0
                    );
                }

                updateCounts(visible);

                if (searchClear) {
                    searchClear.classList.toggle("show", q !== "");
                }
            }

            function applyClientSort() {
                if (!listBody || !clientSort || rows.length < 2) {
                    return;
                }

                const mode = clientSort.value;
                const sorted = rows.slice();

                if (mode === "unread") {
                    sorted.sort(function (a, b) {
                        const ua = (+a.getAttribute("data-unread")) || 0;
                        const ub = (+b.getAttribute("data-unread")) || 0;

                        if (ub !== ua) {
                            return ub - ua;
                        }

                        return (+b.dataset.id) - (+a.dataset.id);
                    });
                } else if (mode === "oldest") {
                    sorted.sort(function (a, b) {
                        return (+a.dataset.id) - (+b.dataset.id);
                    });
                } else if (mode === "title") {
                    sorted.sort(function (a, b) {
                        return String(a.dataset.title || "").localeCompare(
                            String(b.dataset.title || "")
                        );
                    });
                } else if (mode === "deadline") {
                    sorted.sort(function (a, b) {
                        const da = a.dataset.deadline || "";
                        const db = b.dataset.deadline || "";

                        if (!da && !db) {
                            return (+b.dataset.id) - (+a.dataset.id);
                        }

                        if (!da) {
                            return 1;
                        }

                        if (!db) {
                            return -1;
                        }

                        if (da === db) {
                            return (+b.dataset.id) - (+a.dataset.id);
                        }

                        return da < db ? -1 : 1;
                    });
                } else {
                    sorted.sort(function (a, b) {
                        return (+b.dataset.id) - (+a.dataset.id);
                    });
                }

                sorted.forEach(function (row) {
                    listBody.appendChild(row);
                });

                if (clientNoMatch) {
                    listBody.appendChild(clientNoMatch);
                }
            }

            if (searchInput) {
                searchInput.addEventListener("input", function () {
                    if (searchTimer) {
                        clearTimeout(searchTimer);
                    }

                    searchTimer = setTimeout(applyClientFilters, 150);
                });
            }

            searchClear?.addEventListener("click", function () {
                if (searchInput) {
                    searchInput.value = "";
                    searchInput.focus();
                }

                applyClientFilters();
            });

            clientSort?.addEventListener("change", applyClientSort);

            overdueToggle?.addEventListener("click", function () {
                overdueOnly = !overdueOnly;
                this.classList.toggle("is-active", overdueOnly);
                this.setAttribute("aria-pressed", overdueOnly ? "true" : "false");
                applyClientFilters();
            });

            newMsgToggle?.addEventListener("click", function () {
                newMsgOnly = !newMsgOnly;
                this.classList.toggle("is-active", newMsgOnly);
                this.setAttribute("aria-pressed", newMsgOnly ? "true" : "false");
                applyClientFilters();
            });

            clientClearAll?.addEventListener("click", function () {
                if (searchInput) {
                    searchInput.value = "";
                }

                overdueOnly = false;
                overdueToggle?.classList.remove("is-active");
                overdueToggle?.setAttribute("aria-pressed", "false");

                newMsgOnly = false;
                newMsgToggle?.classList.remove("is-active");
                newMsgToggle?.setAttribute("aria-pressed", "false");

                if (clientSort) {
                    clientSort.value = "server";
                    applyClientSort();
                }

                applyClientFilters();

                if (searchInput) {
                    searchInput.focus();
                }
            });

            document.querySelectorAll(".copy-job-btn").forEach(function (button) {
                button.addEventListener("click", function (event) {
                    event.stopPropagation();
                    const value = this.getAttribute("data-copy") || "";

                    if (!value) {
                        return;
                    }

                    const done = function () {
                        showToast("Job number " + value + " copied.");
                    };

                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(value).then(done, function () {
                            showToast("Copy failed — long-press to copy manually.");
                        });
                    } else {
                        const temp = document.createElement("textarea");
                        temp.value = value;
                        document.body.appendChild(temp);
                        temp.select();

                        try {
                            document.execCommand("copy");
                            done();
                        } catch (err) {
                            showToast("Copy failed — long-press to copy manually.");
                        }

                        document.body.removeChild(temp);
                    }
                });
            });

            if (backTop) {
                window.addEventListener("scroll", function () {
                    backTop.classList.toggle("show", window.scrollY > 600);
                }, { passive: true });

                backTop.addEventListener("click", function () {
                    window.scrollTo({
                        top: 0,
                        behavior: reduceMotion ? "auto" : "smooth"
                    });
                });
            }

            const revealRows = Array.prototype.slice.call(
                document.querySelectorAll(".request-row.reveal")
            );

            if (reduceMotion || !("IntersectionObserver" in window)) {
                revealRows.forEach(function (row) {
                    row.classList.add("in");
                });
            } else if (revealRows.length) {
                const observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting) {
                            entry.target.classList.add("in");
                            observer.unobserve(entry.target);
                        }
                    });
                }, { rootMargin: "0px 0px 40px 0px" });

                revealRows.forEach(function (row) {
                    observer.observe(row);
                });
            }

            applyClientFilters();
            });

            /* ===== New-message live polling (30s) ===== */
            (function initMsgPolling() {
                const baseUrl = <?= json_encode(rtrim(BASE_URL, "/"), JSON_UNESCAPED_SLASHES) ?>;
                const toggleCount = document.getElementById("newMsgCount");

                /* BroadcastChannel: listen for "marked_read" from other tabs */
                if (typeof BroadcastChannel !== "undefined") {
                    const bc = new BroadcastChannel("hpu_chat_read");
                    bc.onmessage = function (e) {
                        if (e.data && e.data.type === "marked_read" && e.data.request_id) {
                            poll();
                        }
                    };
                }

                function esc(s) {
                    return String(s == null ? "" : s)
                        .replace(/&/g, "&amp;").replace(/</g, "&lt;")
                        .replace(/>/g, "&gt;").replace(/"/g, "&quot;");
                }

                function applyUnreadMap(unread, latest) {
                    unread = unread || {};
                    latest = latest || {};
                    let distinct = 0;

                    document.querySelectorAll("#requestListBody .request-row").forEach(function (row) {
                        const id = row.getAttribute("data-id");
                        const count = Math.max(0, parseInt(unread[id] || unread[+id] || 0, 10) || 0);

                        if (count > 0) distinct++;

                        row.setAttribute("data-unread", String(count));

                        const badge = row.querySelector("[data-msg-badge]");
                        if (badge) {
                            badge.textContent = count > 99 ? "99+" : String(count);
                            badge.classList.toggle("d-none", count <= 0);
                        }

                        const pill = row.querySelector("[data-msg-pill]");
                        if (pill) {
                            pill.classList.toggle("has-unread", count > 0);
                            const label = pill.querySelector("[data-msg-pill-label]");
                            if (label) {
                                label.textContent = count > 0
                                    ? (count > 1 ? ("New messages (" + count + ")") : "New message")
                                    : "No new messages";
                            }
                            const prev = latest[id] || latest[+id];
                            pill.title = prev && prev.message
                                ? ((prev.sender ? prev.sender + ": " : "") + prev.message)
                                : (count > 0 ? (count + " new message(s)") : "No new messages");
                        }

                        const preview = row.querySelector("[data-msg-preview]");
                        const prev = latest[id] || latest[+id];
                        if (prev && count > 0) {
                            const text = (prev.sender ? prev.sender + ": " : "") + (prev.message || "");
                            if (preview) {
                                preview.style.display = "";
                                preview.title = text;
                                const span = preview.querySelector("span");
                                if (span) span.textContent = text;
                            }
                        } else if (preview) {
                            preview.style.display = "none";
                        }

                        const viewBtn = row.querySelector(".quick-view-btn");
                        if (viewBtn) {
                            viewBtn.title = count > 0
                                ? ("Open request details — " + count + " new message(s)")
                                : "Open request details";
                        }
                    });

                    if (toggleCount) toggleCount.textContent = String(distinct);
                }

                async function poll() {
                    try {
                        const res = await fetch(baseUrl + "/ajax/chat_unread.php?scope=jobs", { credentials: "same-origin" });
                        const data = await res.json();
                        if (data && data.ok) applyUnreadMap(data.unread, data.latest);
                    } catch (e) { /* keep old badges on failure */ }
                }

                setInterval(poll, 30000);
                document.addEventListener("visibilitychange", function () {
                    if (!document.hidden) poll();
                });
            })();
    </script>
</body>

</html>