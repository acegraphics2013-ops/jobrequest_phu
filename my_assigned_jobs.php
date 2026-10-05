<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - UPGRADED MY ASSIGNED JOBS
|--------------------------------------------------------------------------
| Drop-in replacement:
|   C:\laragon\www\jobrequest_phu\my_assigned_jobs.php
|--------------------------------------------------------------------------
*/

include __DIR__ . "/includes/config.php";
include __DIR__ . "/includes/auth.php";
include __DIR__ . "/includes/helpers.php";
require_once __DIR__ . "/includes/publication_tasks.php";
require_once __DIR__ . "/includes/module_access.php";

requireRole([
    "staff",
    "supervisor",
    "admin"
]);
requireModule($conn, "my_assigned_jobs");

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

$userId =
    (int)(
        $_SESSION["user_id"]
        ?? 0
    );

$userName =
    (string)(
        $_SESSION["name"]
        ?? "HPU Personnel"
    );

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function assignedColumnExists(
    mysqli $conn,
    string $column
): bool {
    $stmt =
        $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'job_requests'
               AND COLUMN_NAME = ?"
        );

    $stmt->bind_param(
        "s",
        $column
    );

    $stmt->execute();

    $row =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    return
        (int)(
            $row["total"]
            ?? 0
        ) > 0;
}

function ensureAssignedColumns(
    mysqli $conn
): void {
    $columns = [
        "assigned_to" =>
            "INT NULL",

        "assigned_at" =>
            "DATETIME NULL",

        "assignment_notes" =>
            "TEXT NULL",

        "priority" =>
            "ENUM(
                'Low',
                'Normal',
                'High',
                'Urgent'
             )
             NOT NULL DEFAULT 'Normal'",

        "archived" =>
            "TINYINT(1)
             NOT NULL DEFAULT 0"
    ];

    foreach (
        $columns
        as $column => $definition
    ) {
        if (
            !assignedColumnExists(
                $conn,
                $column
            )
        ) {
            $conn->query(
                "ALTER TABLE job_requests
                 ADD COLUMN `{$column}`
                 {$definition}"
            );
        }
    }
}

function assignedStatusProgress(
    string $status
): int {
    return match ($status) {
        "Pending Review" => 10,
        "Under Review" => 25,
        "Approved" => 45,
        "For Revision" => 55,
        "In Development" => 70,
        "Completed" => 85,
        "For Evaluation" => 95,
        "Closed" => 100,
        "Disapproved" => 100,
        default => 0
    };
}

function assignedStatusClass(
    string $status
): string {
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

function assignedPriorityClass(
    string $priority
): string {
    return match ($priority) {
        "Urgent" =>
            "priority-urgent",

        "High" =>
            "priority-high",

        "Low" =>
            "priority-low",

        default =>
            "priority-normal"
    };
}

function assignedRequestTypeClass(
    string $type
): string {
    return match ($type) {
        "Graphic Design" =>
            "type-graphic",

        "Video Production" =>
            "type-video",

        "Photo/Video Coverage" =>
            "type-coverage",

        "IEC Materials" =>
            "type-iec",

        "Publication Submission" =>
            "type-publication",

        default =>
            "type-other"
    };
}

function assignedRequestTypeIcon(
    string $type
): string {
    return match ($type) {
        "Graphic Design" =>
            "bi-palette",

        "Video Production" =>
            "bi-camera-reels",

        "Photo/Video Coverage" =>
            "bi-camera",

        "IEC Materials" =>
            "bi-file-earmark-richtext",

        "Publication Submission" =>
            "bi-megaphone",

        default =>
            "bi-tag"
    };
}

function assignedRowClass(
    array $types
): string {
    $primary =
        (string)(
            $types[0]
            ?? ""
        );

    return match ($primary) {
        "Graphic Design" =>
            "row-graphic",

        "Video Production" =>
            "row-video",

        "Photo/Video Coverage" =>
            "row-coverage",

        "IEC Materials" =>
            "row-iec",

        "Publication Submission" =>
            "row-publication",

        default =>
            "row-other"
    };
}

function assignedRelativeTime(
    ?string $datetime
): string {
    if (!$datetime) {
        return "No recent update";
    }

    try {
        $then = new DateTime($datetime);
        $now = new DateTime();
        $diff = (int)$then->diff($now)->format("%r%a");

        if ($diff === 0) {
            $mins = (int)floor((time() - $then->getTimestamp()) / 60);
            if ($mins < 1) {
                return "Updated just now";
            }
            if ($mins < 60) {
                return "Updated {$mins}m ago";
            }
            $hours = (int)floor($mins / 60);
            if ($hours < 24) {
                return "Updated {$hours}h ago";
            }
        }

        $days = abs($diff);

        if ($days === 0) {
            return "Updated today";
        }
        if ($days === 1) {
            return "Updated yesterday";
        }
        if ($days < 30) {
            return "Updated {$days} days ago";
        }

        return "Updated " . $then->format("M d, Y");
    } catch (Throwable $e) {
        return "No recent update";
    }
}

function assignedDaysFromToday(
    ?string $date
): ?int {
    if (!$date) {
        return null;
    }

    try {
        $today =
            new DateTime(
                "today"
            );

        $target =
            new DateTime(
                $date
            );

        $target->setTime(
            0,
            0,
            0
        );

        return
            (int)
            $today
                ->diff(
                    $target
                )
                ->format(
                    "%r%a"
                );

    } catch (Throwable $e) {
        return null;
    }
}

function assignedDeadlineMeta(
    ?string $date,
    string $status
): array {
    $closed = in_array(
        $status,
        [
            "Completed",
            "For Evaluation",
            "Closed",
            "Disapproved"
        ],
        true
    );

    if (!$date) {
        return [
            "label" => "No deadline",
            "class" => "deadline-none",
            "days" => null
        ];
    }

    $days =
        assignedDaysFromToday(
            $date
        );

    if ($closed) {
        return [
            "label" =>
                date(
                    "M d, Y",
                    strtotime(
                        $date
                    )
                ),

            "class" =>
                "deadline-closed",

            "days" =>
                $days
        ];
    }

    if ($days === null) {
        return [
            "label" => "No deadline",
            "class" => "deadline-none",
            "days" => null
        ];
    }

    if ($days < 0) {
        return [
            "label" =>
                abs($days)
                . " day"
                . (
                    abs($days) === 1
                        ? ""
                        : "s"
                  )
                . " overdue",

            "class" =>
                "deadline-overdue",

            "days" =>
                $days
        ];
    }

    if ($days === 0) {
        return [
            "label" =>
                "Due today",

            "class" =>
                "deadline-today",

            "days" =>
                0
        ];
    }

    if ($days <= 3) {
        return [
            "label" =>
                "Due in "
                . $days
                . " day"
                . (
                    $days === 1
                        ? ""
                        : "s"
                  ),

            "class" =>
                "deadline-soon",

            "days" =>
                $days
        ];
    }

    return [
        "label" =>
            "Due "
            . date(
                "M d, Y",
                strtotime(
                    $date
                )
            ),

        "class" =>
            "deadline-normal",

        "days" =>
            $days
    ];
}

function assignedAgeLabel(
    ?string $date
): string {
    if (!$date) {
        return "Assignment date unavailable";
    }

    try {
        $assigned =
            new DateTime(
                $date
            );

        $today =
            new DateTime();

        $days =
            (int)
            $assigned
                ->diff(
                    $today
                )
                ->format(
                    "%a"
                );

        if ($days === 0) {
            return "Assigned today";
        }

        if ($days === 1) {
            return "Assigned yesterday";
        }

        return
            "Assigned "
            . $days
            . " days ago";

    } catch (Throwable $e) {
        return "Assignment date unavailable";
    }
}

ensureAssignedColumns(
    $conn
);

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$tab =
    trim(
        (string)(
            $_GET["tab"]
            ?? "all"
        )
    );

$allowedTabs = [
    "all",
    "active",
    "due_today",
    "due_soon",
    "overdue",
    "completed"
];

if (
    !in_array(
        $tab,
        $allowedTabs,
        true
    )
) {
    $tab = "all";
}

/*
|--------------------------------------------------------------------------
| Top-level section: job request tasks vs production tasks
|--------------------------------------------------------------------------
*/

$section =
    trim(
        (string)(
            $_GET["section"]
            ?? "jobs"
        )
    );

if (
    !in_array(
        $section,
        ["jobs", "production"],
        true
    )
) {
    $section = "jobs";
}

$search =
    trim(
        (string)(
            $_GET["q"]
            ?? ""
        )
    );

$statusFilter =
    trim(
        (string)(
            $_GET["status"]
            ?? ""
        )
    );

$priorityFilter =
    trim(
        (string)(
            $_GET["priority"]
            ?? ""
        )
    );

$requestTypeFilter =
    trim(
        (string)(
            $_GET["request_type"]
            ?? ""
        )
    );

$deadlineFilter =
    trim(
        (string)(
            $_GET["deadline"]
            ?? ""
        )
    );

$sort =
    trim(
        (string)(
            $_GET["sort"]
            ?? "newest"
        )
    );

$priorities = [
    "Urgent",
    "High",
    "Normal",
    "Low"
];

if (
    $priorityFilter !== ""
    &&
    !in_array(
        $priorityFilter,
        $priorities,
        true
    )
) {
    $priorityFilter = "";
}

$requestTypes = [
    "Graphic Design",
    "Video Production",
    "Photo/Video Coverage",
    "IEC Materials",
    "Publication Submission"
];

if (
    $requestTypeFilter !== ""
    &&
    !in_array(
        $requestTypeFilter,
        $requestTypes,
        true
    )
) {
    $requestTypeFilter = "";
}

$allowedDeadlineFilters = [
    "",
    "due_today",
    "due_soon",
    "overdue",
    "no_deadline"
];

if (
    !in_array(
        $deadlineFilter,
        $allowedDeadlineFilters,
        true
    )
) {
    $deadlineFilter = "";
}

$allowedSort = [
    "priority_due",
    "newest",
    "oldest",
    "deadline"
];

if (
    !in_array(
        $sort,
        $allowedSort,
        true
    )
) {
    $sort =
        "newest";
}

/*
|--------------------------------------------------------------------------
| Query
|--------------------------------------------------------------------------
*/

$closedStatuses = [
    "Completed",
    "For Evaluation",
    "Closed",
    "Disapproved"
];

$where = [
    "jr.assigned_to = ?",
    "COALESCE(jr.archived, 0) = 0"
];

$types = "i";
$params = [
    $userId
];

if ($tab === "active") {
    $where[] =
        "jr.approval_status
         NOT IN (
            'Completed',
            'For Evaluation',
            'Closed',
            'Disapproved'
         )";

} elseif (
    $tab === "due_today"
) {
    $where[] =
        "jr.date_completion = CURDATE()";

    $where[] =
        "jr.approval_status
         NOT IN (
            'Completed',
            'For Evaluation',
            'Closed',
            'Disapproved'
         )";

} elseif (
    $tab === "due_soon"
) {
    $where[] =
        "jr.date_completion
         BETWEEN
            DATE_ADD(
                CURDATE(),
                INTERVAL 1 DAY
            )
            AND
            DATE_ADD(
                CURDATE(),
                INTERVAL 3 DAY
            )";

    $where[] =
        "jr.approval_status
         NOT IN (
            'Completed',
            'For Evaluation',
            'Closed',
            'Disapproved'
         )";

} elseif (
    $tab === "completed"
) {
    $where[] =
        "jr.approval_status
         IN (
            'Completed',
            'For Evaluation',
            'Closed'
         )";

} elseif (
    $tab === "overdue"
) {
    $where[] =
        "jr.date_completion
         IS NOT NULL";

    $where[] =
        "jr.date_completion
         < CURDATE()";

    $where[] =
        "jr.approval_status
         NOT IN (
            'Completed',
            'For Evaluation',
            'Closed',
            'Disapproved'
         )";
}

if ($search !== "") {
    $where[] = "(
        jr.job_no LIKE ?
        OR jr.project_title LIKE ?
        OR jr.requestor_name LIKE ?
        OR jr.department LIKE ?
        OR jr.assignment_notes LIKE ?
    )";

    $like =
        "%"
        . $search
        . "%";

    $types .=
        "sssss";

    array_push(
        $params,
        $like,
        $like,
        $like,
        $like,
        $like
    );
}

if ($statusFilter !== "") {
    $where[] =
        "jr.approval_status = ?";

    $types .= "s";
    $params[] =
        $statusFilter;
}

if ($priorityFilter !== "") {
    $where[] =
        "jr.priority = ?";

    $types .= "s";
    $params[] =
        $priorityFilter;
}

if ($requestTypeFilter !== "") {
    // Match both plain and JSON-escaped slash forms
    // (e.g. Photo/Video vs Photo\/Video).
    $where[] =
        "(jr.request_types LIKE ?
          OR jr.request_types LIKE ?)";

    $types .= "ss";

    $params[] =
        '%"'
        . $requestTypeFilter
        . '"%';

    $params[] =
        '%"'
        . str_replace(
            "/",
            "\\/",
            $requestTypeFilter
        )
        . '"%';
}

if ($deadlineFilter === "due_today") {
    $where[] =
        "jr.date_completion = CURDATE()";

} elseif (
    $deadlineFilter === "due_soon"
) {
    $where[] =
        "jr.date_completion
         BETWEEN
            DATE_ADD(
                CURDATE(),
                INTERVAL 1 DAY
            )
            AND
            DATE_ADD(
                CURDATE(),
                INTERVAL 3 DAY
            )";

} elseif (
    $deadlineFilter === "overdue"
) {
    $where[] =
        "jr.date_completion
         IS NOT NULL
         AND jr.date_completion
            < CURDATE()";

} elseif (
    $deadlineFilter === "no_deadline"
) {
    $where[] =
        "jr.date_completion
         IS NULL";
}

$orderSql = match ($sort) {
    "newest" =>
        "jr.assigned_at DESC,
         jr.id DESC",

    "oldest" =>
        "jr.assigned_at ASC,
         jr.id ASC",

    "deadline" =>
        "jr.date_completion IS NULL,
         jr.date_completion ASC,
         jr.id DESC",

    default =>
        "FIELD(
            jr.priority,
            'Urgent',
            'High',
            'Normal',
            'Low'
         ),
         jr.date_completion IS NULL,
         jr.date_completion ASC,
         jr.assigned_at DESC,
         jr.id DESC"
};

$eventEndSelect = assignedColumnExists($conn, "event_datetime_end")
    ? "jr.event_datetime_end,"
    : "NULL AS event_datetime_end,";

$sql = "
    SELECT
        jr.id,
        jr.job_no,
        jr.project_title,
        jr.requestor_name,
        jr.department,
        jr.date_request,
        jr.date_completion,
        jr.event_datetime,
        {$eventEndSelect}
        jr.venue_location,
        jr.request_types,
        jr.approval_status,
        jr.priority,
        jr.assigned_at,
        jr.assignment_notes,
        jr.updated_at
    FROM job_requests jr
    WHERE
        "
        . implode(
            " AND ",
            $where
        )
        . "
    ORDER BY
        {$orderSql}
";

$stmt =
    $conn->prepare(
        $sql
    );

$stmt->bind_param(
    $types,
    ...$params
);

$stmt->execute();

$jobs =
    $stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

$stmt->close();

/*
|--------------------------------------------------------------------------
| Production Tasks (publication team assignments for this user)
|--------------------------------------------------------------------------
*/

pubEnsureTaskTable($conn);

$myTasks = pubMyTasks($conn, $userId);

function pubTaskStatusClass(
    string $status
): string {
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

function pubTaskStatusProgress(
    string $status
): int {
    return match ($status) {
        "For Review" => 20,
        "In Preparation" => 45,
        "For Approval" => 75,
        "Published" => 100,
        "Closed" => 100,
        default => 0
    };
}

function pubTaskKindLabel(
    string $kind
): string {
    return match ($kind) {
        "recognition" => "Recognition / Milestone",
        "contribution" => "Content Contribution",
        default => "Event / Activity"
    };
}

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$summaryStmt =
    $conn->prepare(
        "SELECT
            COUNT(*) AS total,

            SUM(
                approval_status
                NOT IN (
                    'Completed',
                    'For Evaluation',
                    'Closed',
                    'Disapproved'
                )
            ) AS active_total,

            SUM(
                approval_status
                IN (
                    'Completed',
                    'For Evaluation',
                    'Closed'
                )
            ) AS completed_total,

            SUM(
                date_completion
                IS NOT NULL
                AND date_completion
                    < CURDATE()
                AND approval_status
                    NOT IN (
                        'Completed',
                        'For Evaluation',
                        'Closed',
                        'Disapproved'
                    )
            ) AS overdue_total,

            SUM(
                date_completion
                    = CURDATE()
                AND approval_status
                    NOT IN (
                        'Completed',
                        'For Evaluation',
                        'Closed',
                        'Disapproved'
                    )
            ) AS due_today_total,

            SUM(
                date_completion
                    BETWEEN
                        DATE_ADD(
                            CURDATE(),
                            INTERVAL 1 DAY
                        )
                        AND
                        DATE_ADD(
                            CURDATE(),
                            INTERVAL 3 DAY
                        )
                AND approval_status
                    NOT IN (
                        'Completed',
                        'For Evaluation',
                        'Closed',
                        'Disapproved'
                    )
            ) AS due_soon_total,

            SUM(
                priority = 'Urgent'
                AND approval_status
                    NOT IN (
                        'Completed',
                        'For Evaluation',
                        'Closed',
                        'Disapproved'
                    )
            ) AS urgent_total

         FROM job_requests

         WHERE
            assigned_to = ?
            AND COALESCE(
                archived,
                0
            ) = 0"
    );

$summaryStmt->bind_param(
    "i",
    $userId
);

$summaryStmt->execute();

$summary =
    $summaryStmt
        ->get_result()
        ->fetch_assoc()
    ?: [];

$summaryStmt->close();

/*
|--------------------------------------------------------------------------
| Status Options
|--------------------------------------------------------------------------
*/

$statuses = [];

$stmt =
    $conn->prepare(
        "SELECT DISTINCT
            approval_status

         FROM job_requests

         WHERE
            assigned_to = ?
            AND approval_status
                IS NOT NULL
            AND approval_status <> ''

         ORDER BY
            approval_status"
    );

$stmt->bind_param(
    "i",
    $userId
);

$stmt->execute();

$result =
    $stmt->get_result();

while (
    $row =
        $result->fetch_assoc()
) {
    $statuses[] =
        $row["approval_status"];
}

$stmt->close();

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
    My Assigned Jobs | HPU Workbench
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
:root {
    --jobs-bg:#f4f7fb;
    --jobs-border:#dfe6ef;
    --jobs-primary:#1769e0;
    --jobs-text:#172033;
    --jobs-muted:#667085;
    --jobs-shadow:0 8px 24px rgba(28,42,72,.05);
}

body {
    background:var(--jobs-bg);
    color:var(--jobs-text);
}

.content {
    padding:18px;
}

.container-fluid {
    max-width:1720px;
    margin:0 auto;
}

.scope-chip {
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:6px 9px;
    border-radius:999px;
    background:#ecfdf3;
    color:#027a48;
    font-size:.61rem;
    font-weight:850;
}

.kpi-grid {
    display:grid;
    grid-template-columns:repeat(6,minmax(0,1fr));
    gap:9px;
    margin-bottom:12px;
}

.kpi-card {
    display:block;
    min-height:90px;
    padding:11px;
    border:1px solid var(--jobs-border);
    border-radius:13px;
    background:#fff;
    box-shadow:var(--jobs-shadow);
    text-decoration:none;
    color:inherit;
}

.kpi-card:hover {
    border-color:#b7c9e4;
}

.kpi-label {
    color:#667085;
    font-size:.55rem;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.04em;
}

.kpi-value {
    margin-top:5px;
    font-size:1.32rem;
    line-height:1;
    font-weight:900;
}

.kpi-note {
    margin-top:5px;
    color:#98a2b3;
    font-size:.56rem;
}

.workspace {
    border:1px solid var(--jobs-border);
    border-radius:14px;
    background:#fff;
    box-shadow:var(--jobs-shadow);
    overflow:hidden;
}

.tabs {
    display:flex;
    gap:4px;
    padding:9px 10px 0;
    overflow-x:auto;
}

.tab-link {
    padding:6px 9px;
    border-radius:8px;
    color:#667085;
    font-size:.62rem;
    font-weight:850;
    text-decoration:none;
    white-space:nowrap;
}

.tab-link.active {
    background:#eff8ff;
    color:#175cd3;
}

.filters {
    padding:9px 10px;
    border-bottom:1px solid #edf1f6;
}

.filter-label {
    display:block;
    margin-bottom:3px;
    color:#667085;
    font-size:.54rem;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.04em;
}

.results-head {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    padding:7px 11px;
    border-bottom:1px solid #edf1f6;
    color:#667085;
    font-size:.59rem;
}

.job-card {
    position:relative;
    padding:13px 14px 13px 17px;
    border-bottom:1px solid #edf1f6;
    background:#fff;
}

.job-card:last-child {
    border-bottom:0;
}

.job-card::before {
    content:"";
    position:absolute;
    left:0;
    top:0;
    bottom:0;
    width:4px;
    background:#98a2b3;
}

.row-graphic::before {
    background:#2563eb;
}

.row-video::before {
    background:#7c3aed;
}

.row-coverage::before {
    background:#ea580c;
}

.row-iec::before {
    background:#15803d;
}

.row-other::before {
    background:#64748b;
}

.job-top {
    display:flex;
    justify-content:space-between;
    gap:14px;
    align-items:flex-start;
}

.job-main {
    min-width:0;
    flex:1;
}

.job-no {
    color:#1769e0;
    font-size:.61rem;
    font-weight:900;
    letter-spacing:.02em;
}

.job-title {
    margin-top:3px;
    color:#1d2939;
    font-size:.92rem;
    font-weight:900;
}

.job-meta {
    display:flex;
    flex-wrap:wrap;
    gap:5px 13px;
    margin-top:6px;
    color:#667085;
    font-size:.6rem;
}

.badge-row {
    display:flex;
    align-items:center;
    gap:5px;
    flex-wrap:wrap;
    margin-top:7px;
}

.status-pill,
.priority-pill,
.type-pill,
.deadline-pill {
    display:inline-flex;
    align-items:center;
    gap:4px;
    padding:3px 6px;
    border-radius:999px;
    font-size:.54rem;
    font-weight:850;
}

.status-pending,
.status-review {
    background:#fffaeb;
    color:#b54708;
}

.status-approved,
.status-completed,
.status-evaluation,
.status-closed {
    background:#ecfdf3;
    color:#027a48;
}

.status-development {
    background:#eff8ff;
    color:#175cd3;
}

.status-revision {
    background:#fff7ed;
    color:#c2410c;
}

.status-disapproved {
    background:#fef3f2;
    color:#b42318;
}

.status-default {
    background:#f2f4f7;
    color:#475467;
}

.priority-urgent {
    background:#fef3f2;
    color:#b42318;
}

.priority-high {
    background:#fff7ed;
    color:#c2410c;
}

.priority-normal {
    background:#eff8ff;
    color:#175cd3;
}

.priority-low {
    background:#ecfdf3;
    color:#027a48;
}

.type-graphic {
    background:#eff6ff;
    color:#1d4ed8;
}

.type-video {
    background:#f5f3ff;
    color:#6d28d9;
}

.type-coverage {
    background:#fff7ed;
    color:#c2410c;
}

.type-iec {
    background:#f0fdf4;
    color:#15803d;
}

.type-other {
    background:#f2f4f7;
    color:#475467;
}

.deadline-overdue {
    background:#fef3f2;
    color:#b42318;
}

.deadline-today {
    background:#fff1f3;
    color:#c01048;
}

.deadline-soon {
    background:#fffaeb;
    color:#b54708;
}

.deadline-normal {
    background:#f8fafc;
    color:#475467;
}

.deadline-none,
.deadline-closed {
    background:#f2f4f7;
    color:#667085;
}

.assignment-note {
    margin-top:9px;
    padding:9px 10px;
    border-left:3px solid #1769e0;
    border-radius:8px;
    background:#f8fafc;
    color:#475467;
    font-size:.62rem;
    line-height:1.45;
}

.progress-area {
    margin-top:9px;
}

.progress {
    height:6px;
    background:#edf1f6;
}

.progress-label {
    display:flex;
    justify-content:space-between;
    gap:10px;
    margin-bottom:4px;
    color:#98a2b3;
    font-size:.54rem;
}

.job-actions {
    display:flex;
    flex-direction:column;
    align-items:flex-end;
    gap:6px;
    min-width:126px;
}

.job-actions .btn {
    width:100%;
    font-size:.61rem;
    font-weight:800;
}

.assignment-age {
    color:#98a2b3;
    font-size:.54rem;
    text-align:right;
}

.empty {
    padding:50px 20px;
    text-align:center;
    color:#667085;
}

@media(max-width:1300px) {
    .kpi-grid {
        grid-template-columns:repeat(3,minmax(0,1fr));
    }
}

@media(max-width:800px) {
    .content {
        padding:12px;
    }

    .kpi-grid {
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .job-top {
        flex-direction:column;
    }

    .job-actions {
        width:100%;
        min-width:0;
        align-items:stretch;
    }
}

/* ============================================================
   MY ASSIGNED JOBS — MODERN UI/UX REFRESH (blocked_dates style)
   Override layer: keeps PHP/HTML structure intact.
   ============================================================ */

.ph {
    position:relative;
    overflow:hidden;
    background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%);
}

.ph::after {
    content:"";
    position:absolute;
    inset:0 0 auto 0;
    height:3px;
    background:linear-gradient(90deg,#2563eb,#7c3aed,#0ea5e9);
    opacity:.9;
}

.attention-pill {
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:6px 10px;
    border-radius:999px;
    font-size:.61rem;
    font-weight:850;
    text-decoration:none;
}

.attention-pill.danger {
    background:#fef3f2;
    color:#b42318;
}

.attention-pill.warning {
    background:#fffaeb;
    color:#b54708;
}

.attention-pill.ok {
    background:#ecfdf3;
    color:#027a48;
}

/* KPI with icon + accent bar */
.kpi-card {
    position:relative;
    display:flex;
    gap:11px;
    align-items:flex-start;
    overflow:hidden;
    transition:transform .15s ease, box-shadow .15s ease, border-color .15s ease;
}

.kpi-card::before {
    content:"";
    position:absolute;
    inset:0 0 auto 0;
    height:3px;
    background:#e2e8f0;
}

.kpi-card:nth-child(1)::before { background:linear-gradient(90deg,#2563eb,#60a5fa); }
.kpi-card:nth-child(2)::before { background:linear-gradient(90deg,#dc2626,#f87171); }
.kpi-card:nth-child(3)::before { background:linear-gradient(90deg,#d97706,#fbbf24); }
.kpi-card:nth-child(4)::before { background:linear-gradient(90deg,#b42318,#f97066); }
.kpi-card:nth-child(5)::before { background:linear-gradient(90deg,#7c3aed,#a78bfa); }
.kpi-card:nth-child(6)::before { background:linear-gradient(90deg,#059669,#34d399); }

.kpi-card:hover {
    transform:translateY(-2px);
    border-color:#b7c9e4;
    box-shadow:0 12px 28px rgba(15,23,42,.10);
}

.kpi-icon {
    width:36px;
    height:36px;
    flex:0 0 36px;
    display:grid;
    place-items:center;
    border-radius:10px;
    font-size:.95rem;
}

.kpi-card:nth-child(1) .kpi-icon { background:#eff6ff; color:#1d4ed8; }
.kpi-card:nth-child(2) .kpi-icon { background:#fef2f2; color:#b91c1c; }
.kpi-card:nth-child(3) .kpi-icon { background:#fffbeb; color:#b45309; }
.kpi-card:nth-child(4) .kpi-icon { background:#fef3f2; color:#b42318; }
.kpi-card:nth-child(5) .kpi-icon { background:#f5f3ff; color:#6d28d9; }
.kpi-card:nth-child(6) .kpi-icon { background:#ecfdf5; color:#047857; }

.kpi-body { min-width:0; flex:1; }
.kpi-value { font-variant-numeric:tabular-nums; }

/* Tabs: sticky + count badges */
.tabs {
    position:sticky;
    top:0;
    z-index:5;
    background:#fff;
    border-bottom:1px solid #edf1f6;
    padding:9px 10px;
    scrollbar-width:thin;
}

.tab-link {
    display:inline-flex;
    align-items:center;
    gap:6px;
    transition:background .13s ease, color .13s ease;
}

.tab-link:hover { background:#f1f5f9; }

.tab-count {
    display:inline-grid;
    place-items:center;
    min-width:20px;
    height:20px;
    padding:0 5px;
    border-radius:999px;
    background:#eef2f7;
    color:#475569;
    font-size:.56rem;
    font-weight:900;
}

.tab-link.active .tab-count {
    background:#fff;
    color:#175cd3;
    box-shadow:inset 0 0 0 1px rgba(23,92,211,.25);
}

.tab-count.alert {
    background:#fef3f2;
    color:#b42318;
}

/* Filters: sticky + focus */
.filters {
    position:sticky;
    top:47px;
    z-index:4;
    background:#fbfcfe;
}

.filters .form-control:focus,
.filters .form-select:focus {
    border-color:#93c5fd;
    box-shadow:0 0 0 3px rgba(37,99,235,.12);
}

.active-pills {
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    padding:8px 11px 0;
}

.active-pills:empty { display:none; }

.pill-remove {
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:3px 4px 3px 8px;
    border-radius:999px;
    background:#eff6ff;
    color:#1d4ed8;
    font-size:.57rem;
    font-weight:800;
    text-decoration:none;
}

.pill-remove:hover { background:#dbeafe; }

.pill-remove i {
    display:inline-grid;
    place-items:center;
    width:16px;
    height:16px;
    border-radius:50%;
    background:rgba(29,78,216,.15);
    font-size:.55rem;
}

/* Job cards */
.job-card {
    transition:background .12s ease, box-shadow .15s ease;
}

.job-card:hover {
    background:#f8fbff;
    box-shadow:inset 0 0 0 1px #dbeafe;
}

.row-publication::before { background:#0f766e; }

.type-publication {
    background:#f0fdfa;
    color:#0f766e;
}

.progress-bar { background:linear-gradient(90deg,#2563eb,#60a5fa); }
.progress-done .progress-bar { background:linear-gradient(90deg,#059669,#34d399); }
.progress-late .progress-bar { background:linear-gradient(90deg,#dc2626,#f87171); }

.job-updated {
    color:#98a2b3;
    font-size:.54rem;
}

.job-actions .btn {
    border-radius:8px;
    box-shadow:0 1px 2px rgba(15,23,42,.06);
}

.job-actions .btn-primary {
    box-shadow:0 4px 12px rgba(37,99,235,.25);
}

/* Empty state */
.empty {
    background:radial-gradient(420px 140px at 50% 0%,#eff6ff 0%,transparent 70%);
    border-radius:0 0 14px 14px;
}

.empty .btn { border-radius:9px; }

/* Back to top */
#toTopBtn {
    position:fixed;
    right:18px;
    bottom:18px;
    z-index:50;
    width:40px;
    height:40px;
    display:none;
    place-items:center;
    border:1px solid #dbe3ef;
    border-radius:12px;
    background:#fff;
    color:#1d4ed8;
    box-shadow:0 10px 24px rgba(15,23,42,.14);
}

#toTopBtn.show { display:grid; }

/* Scrollbars */
.workspace::-webkit-scrollbar { height:8px; width:8px; }
.tabs::-webkit-scrollbar { height:6px; }
.tabs::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:999px; }

@media(max-width:1300px) {
    .filters { top:0; }
}

@media print {
    .ph-actions, .tabs, .filters, .job-actions, #toTopBtn { display:none !important; }
    .job-card { break-inside:avoid; }
}

/* Stacked job request spacing: separated cards instead of flush list */
.job-stack {
    display:flex;
    flex-direction:column;
    gap:12px;
    padding:12px 11px 14px;
    background:#f8fafc;
}

.section-tabs {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-bottom:14px;
}

.section-tab {
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:10px 16px;
    border:1px solid #dfe6ef;
    border-radius:12px;
    background:#fff;
    color:#334155;
    font-size:.82rem;
    font-weight:850;
    text-decoration:none;
    box-shadow:0 4px 14px rgba(15,23,42,.04);
}

.section-tab:hover {
    border-color:#93c5fd;
    color:#1d4ed8;
}

.section-tab.active {
    border-color:#2563eb;
    background:#2563eb;
    color:#fff;
    box-shadow:0 8px 20px rgba(37,99,235,.25);
}

.section-tab .section-count {
    padding:1px 9px;
    border-radius:999px;
    background:#f1f5f9;
    color:#475569;
    font-size:.68rem;
    font-weight:850;
}

.section-tab.active .section-count {
    background:rgba(255,255,255,.22);
    color:#fff;
}

.task-section {
    margin-top:18px;
    border:1px solid #dfe6ef;
    border-radius:14px;
    background:#fff;
    box-shadow:0 8px 24px rgba(15,23,42,.045);
    overflow:hidden;
}

.task-section-head {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    padding:14px 16px;
    border-bottom:1px solid #edf1f6;
    background:linear-gradient(180deg,#ffffff,#f8fbff);
}

.task-section-title {
    display:flex;
    align-items:center;
    gap:9px;
    margin:0;
    font-size:.95rem;
    font-weight:850;
    letter-spacing:-.01em;
}

.task-section-title i {
    color:#0f766e;
}

.task-count {
    padding:2px 10px;
    border-radius:999px;
    background:#ccfbf1;
    color:#0f766e;
    font-size:.68rem;
    font-weight:850;
}

.role-pill {
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:3px 10px;
    border-radius:999px;
    font-size:.62rem;
    font-weight:850;
    white-space:nowrap;
}

.role-layout { background:#ede9fe; color:#5b21b6; }
.role-video { background:#e0e7ff; color:#3730a3; }
.role-drafting { background:#fef3c7; color:#92400e; }
.role-approval { background:#dcfce7; color:#166534; }
.role-default { background:#f1f5f9; color:#475569; }

.job-card {
    border:1px solid #e5ebf3;
    border-bottom:1px solid #e5ebf3;
    border-radius:12px;
    box-shadow:0 2px 8px rgba(15,23,42,.05);
    overflow:hidden;
}

.job-card:last-child {
    border-bottom:1px solid #e5ebf3;
}

.job-card::before {
    border-radius:12px 0 0 12px;
}

.job-stack .empty {
    border:1px dashed #d0d5dd;
    border-radius:12px;
    background:#fff;
}

@media(max-width:800px) {
    .job-stack {
        gap:10px;
        padding:10px 8px 12px;
    }
}
</style>

</head>

<body class="app-shell">

<div class="d-flex min-vh-100">

<?php
include __DIR__ . "/includes/sidebar.php";
?>

<div class="main flex-grow-1">

<main class="content">

<div class="container-fluid">

<div class="ph">

    <div class="ph-copy">
        <div class="ph-icon" aria-hidden="true">
            <i class="bi bi-list-task"></i>
        </div>

        <div class="min-w-0">
            <p class="ph-eyebrow">My Workspace</p>
            <h1 class="ph-title">My Assigned Jobs</h1>
            <p class="ph-subtitle">
                Prioritized work queue for
                <strong><?= e($userName) ?></strong>.
            </p>
        </div>
    </div>

    <div class="ph-actions">

        <span class="scope-chip">
            <i class="bi bi-lock"></i>
            My assignments only
        </span>

        <?php if ((int)($summary["overdue_total"] ?? 0) > 0): ?>
        <a
            class="attention-pill danger"
            href="?tab=overdue"
            title="View overdue assignments"
        >
            <i class="bi bi-exclamation-octagon"></i>
            <?= (int)$summary["overdue_total"] ?> overdue
        </a>
        <?php elseif ((int)($summary["due_today_total"] ?? 0) > 0): ?>
        <a
            class="attention-pill warning"
            href="?tab=due_today"
            title="View items due today"
        >
            <i class="bi bi-alarm"></i>
            <?= (int)$summary["due_today_total"] ?> due today
        </a>
        <?php else: ?>
        <span
            class="attention-pill ok"
            title="Nothing overdue or due today"
        >
            <i class="bi bi-check-circle"></i>
            All clear
        </span>
        <?php endif; ?>

        <a
            class="btn btn-sm btn-outline-primary"
            href="<?= e(BASE_URL) ?>/my_calendar.php"
        >
            <i class="bi bi-calendar3 me-1"></i>
            Calendar
        </a>

        <a
            class="btn btn-sm btn-outline-secondary"
            href="<?= e(BASE_URL) ?>/staff/kanban.php"
        >
            <i class="bi bi-kanban me-1"></i>
            Kanban
        </a>

    </div>

</div>

<div class="kpi-grid">

    <a
        class="kpi-card"
        href="?tab=active"
        title="View active assignments"
    >
        <div class="kpi-icon" aria-hidden="true"><i class="bi bi-lightning-charge"></i></div>
        <div class="kpi-body">
        <div class="kpi-label">Active</div>
        <div class="kpi-value text-primary">
            <?= (int)($summary["active_total"] ?? 0) ?>
        </div>
        <div class="kpi-note">Current work queue</div>
        </div>
    </a>

    <a
        class="kpi-card"
        href="?tab=due_today"
        title="View items due today"
    >
        <div class="kpi-icon" aria-hidden="true"><i class="bi bi-alarm"></i></div>
        <div class="kpi-body">
        <div class="kpi-label">Due Today</div>
        <div class="kpi-value text-danger">
            <?= (int)($summary["due_today_total"] ?? 0) ?>
        </div>
        <div class="kpi-note">Needs action today</div>
        </div>
    </a>

    <a
        class="kpi-card"
        href="?tab=due_soon"
        title="View items due within 3 days"
    >
        <div class="kpi-icon" aria-hidden="true"><i class="bi bi-hourglass-split"></i></div>
        <div class="kpi-body">
        <div class="kpi-label">Due ≤ 3 Days</div>
        <div class="kpi-value text-warning">
            <?= (int)($summary["due_soon_total"] ?? 0) ?>
        </div>
        <div class="kpi-note">Upcoming deadlines</div>
        </div>
    </a>

    <a
        class="kpi-card"
        href="?tab=overdue"
        title="View overdue assignments"
    >
        <div class="kpi-icon" aria-hidden="true"><i class="bi bi-exclamation-octagon"></i></div>
        <div class="kpi-body">
        <div class="kpi-label">Overdue</div>
        <div class="kpi-value text-danger">
            <?= (int)($summary["overdue_total"] ?? 0) ?>
        </div>
        <div class="kpi-note">Past completion date</div>
        </div>
    </a>

    <a
        class="kpi-card"
        href="?tab=active&priority=Urgent"
        title="View urgent active assignments"
    >
        <div class="kpi-icon" aria-hidden="true"><i class="bi bi-flag-fill"></i></div>
        <div class="kpi-body">
        <div class="kpi-label">Urgent</div>
        <div class="kpi-value text-danger">
            <?= (int)($summary["urgent_total"] ?? 0) ?>
        </div>
        <div class="kpi-note">Urgent active assignments</div>
        </div>
    </a>

    <a
        class="kpi-card"
        href="?tab=completed"
        title="View completed assignments"
    >
        <div class="kpi-icon" aria-hidden="true"><i class="bi bi-check2-circle"></i></div>
        <div class="kpi-body">
        <div class="kpi-label">Completed</div>
        <div class="kpi-value text-success">
            <?= (int)($summary["completed_total"] ?? 0) ?>
        </div>
        <div class="kpi-note">
            <?= (int)($summary["total"] ?? 0) ?> total assigned
        </div>
        </div>
    </a>

</div>

<section class="workspace">

<div class="section-tabs" role="tablist" aria-label="Assignment views">

    <a
        class="section-tab <?= $section === "jobs" ? "active" : "" ?>"
        role="tab"
        aria-selected="<?= $section === "jobs" ? "true" : "false" ?>"
        href="?<?= e(http_build_query(["section" => "jobs"])) ?>"
    >
        <i class="bi bi-briefcase-fill"></i>
        My Assigned Job Request Tasks
        <span class="section-count"><?= number_format((int)($summary["total"] ?? 0)) ?></span>
    </a>

    <a
        class="section-tab <?= $section === "production" ? "active" : "" ?>"
        role="tab"
        aria-selected="<?= $section === "production" ? "true" : "false" ?>"
        href="?<?= e(http_build_query(["section" => "production"])) ?>"
    >
        <i class="bi bi-people-fill"></i>
        My Production Tasks
        <span class="section-count"><?= number_format(count($myTasks)) ?></span>
    </a>

</div>

<?php if ($section === "jobs"): ?>

<div class="tabs">

<?php
$tabCounts = [
    "all" => (int)($summary["total"] ?? 0),
    "active" => (int)($summary["active_total"] ?? 0),
    "due_today" => (int)($summary["due_today_total"] ?? 0),
    "due_soon" => (int)($summary["due_soon_total"] ?? 0),
    "overdue" => (int)($summary["overdue_total"] ?? 0),
    "completed" => (int)($summary["completed_total"] ?? 0),
];

foreach ([
    "all" => "All Assigned",
    "active" => "Active",
    "due_today" => "Due Today",
    "due_soon" => "Due Soon",
    "overdue" => "Overdue",
    "completed" => "Completed"
] as $key => $label): ?>

<a
    class="tab-link <?= $tab === $key ? "active" : "" ?>"
    href="?<?= e(http_build_query([
        "section" => $section,
        "tab" => $key,
        "q" => $search,
        "status" => $statusFilter,
        "priority" => $priorityFilter,
        "request_type" => $requestTypeFilter,
        "deadline" => $deadlineFilter,
        "sort" => $sort
    ])) ?>"
    <?= $tab === $key ? 'aria-current="page"' : "" ?>
>
    <?= e($label) ?>
    <span class="tab-count <?= $key === "overdue" && $tabCounts[$key] > 0 ? "alert" : "" ?>">
        <?= number_format($tabCounts[$key]) ?>
    </span>
</a>

<?php endforeach; ?>

</div>

<form
    class="filters"
    method="get"
>

<input
    type="hidden"
    name="tab"
    value="<?= e($tab) ?>"
>

<input
    type="hidden"
    name="section"
    value="<?= e($section) ?>"
>

<div class="row g-2 align-items-end">

    <div class="col-xl-3 col-lg-5">
        <label class="filter-label">
            Search
        </label>

        <input
            class="form-control form-control-sm"
            name="q"
            id="jobSearchInput"
            value="<?= e($search) ?>"
            placeholder="Job no., project, requestor, department or notes...  ( / )"
            autocomplete="off"
        >
    </div>

    <div class="col-xl-2 col-lg-3">
        <label class="filter-label">
            Status
        </label>

        <select
            class="form-select form-select-sm"
            name="status"
        >
            <option value="">
                All Statuses
            </option>

            <?php foreach ($statuses as $status): ?>

            <option
                value="<?= e($status) ?>"
                <?= $statusFilter === $status ? "selected" : "" ?>
            >
                <?= e($status) ?>
            </option>

            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-xl-1 col-lg-2">
        <label class="filter-label">
            Priority
        </label>

        <select
            class="form-select form-select-sm"
            name="priority"
        >
            <option value="">
                All
            </option>

            <?php foreach ($priorities as $priority): ?>

            <option
                value="<?= e($priority) ?>"
                <?= $priorityFilter === $priority ? "selected" : "" ?>
            >
                <?= e($priority) ?>
            </option>

            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-xl-2 col-lg-3">
        <label class="filter-label">
            Request Type
        </label>

        <select
            class="form-select form-select-sm"
            name="request_type"
        >
            <option value="">
                All Request Types
            </option>

            <?php foreach ($requestTypes as $requestType): ?>

            <option
                value="<?= e($requestType) ?>"
                <?= $requestTypeFilter === $requestType ? "selected" : "" ?>
            >
                <?= e($requestType) ?>
            </option>

            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-xl-2 col-lg-3">
        <label class="filter-label">
            Deadline
        </label>

        <select
            class="form-select form-select-sm"
            name="deadline"
        >
            <option value="">
                All Deadlines
            </option>

            <option value="due_today" <?= $deadlineFilter === "due_today" ? "selected" : "" ?>>
                Due Today
            </option>

            <option value="due_soon" <?= $deadlineFilter === "due_soon" ? "selected" : "" ?>>
                Due Within 3 Days
            </option>

            <option value="overdue" <?= $deadlineFilter === "overdue" ? "selected" : "" ?>>
                Overdue
            </option>

            <option value="no_deadline" <?= $deadlineFilter === "no_deadline" ? "selected" : "" ?>>
                No Deadline
            </option>
        </select>
    </div>

    <div class="col-xl-1 col-lg-2">
        <label class="filter-label">
            Sort
        </label>

        <select
            class="form-select form-select-sm"
            name="sort"
        >
            <option value="newest" <?= $sort === "newest" ? "selected" : "" ?>>
                Newest
            </option>

            <option value="priority_due" <?= $sort === "priority_due" ? "selected" : "" ?>>
                Priority
            </option>

            <option value="deadline" <?= $sort === "deadline" ? "selected" : "" ?>>
                Deadline
            </option>

            <option value="oldest" <?= $sort === "oldest" ? "selected" : "" ?>>
                Oldest
            </option>
        </select>
    </div>

    <div class="col-xl-auto d-flex gap-2">

        <button
            class="btn btn-sm btn-primary"
            type="submit"
        >
            <i class="bi bi-funnel me-1"></i>
            Apply
        </button>

        <a
            class="btn btn-sm btn-light"
            href="<?= e(BASE_URL) ?>/my_assigned_jobs.php?tab=<?= e($tab) ?>"
        >
            Reset
        </a>

    </div>

</div>

</form>

<?php
$baseFilterQuery = [
    "tab" => $tab,
    "sort" => $sort,
];
$hasActiveFilters = $search !== "" || $statusFilter !== "" || $priorityFilter !== ""
    || $requestTypeFilter !== "" || $deadlineFilter !== "";
?>

<?php if ($hasActiveFilters): ?>
<div class="active-pills" aria-label="Active filters">
    <?php if ($search !== ""): ?>
    <a class="pill-remove" href="?<?= e(http_build_query(array_merge($_GET, ["q" => ""]))) ?>">
        Search: “<?= e(mb_strimwidth($search, 0, 24, "…")) ?>” <i class="bi bi-x"></i>
    </a>
    <?php endif; ?>
    <?php if ($statusFilter !== ""): ?>
    <a class="pill-remove" href="?<?= e(http_build_query(array_merge($_GET, ["status" => ""]))) ?>">
        <?= e($statusFilter) ?> <i class="bi bi-x"></i>
    </a>
    <?php endif; ?>
    <?php if ($priorityFilter !== ""): ?>
    <a class="pill-remove" href="?<?= e(http_build_query(array_merge($_GET, ["priority" => ""]))) ?>">
        <?= e($priorityFilter) ?> <i class="bi bi-x"></i>
    </a>
    <?php endif; ?>
    <?php if ($requestTypeFilter !== ""): ?>
    <a class="pill-remove" href="?<?= e(http_build_query(array_merge($_GET, ["request_type" => ""]))) ?>">
        <?= e($requestTypeFilter) ?> <i class="bi bi-x"></i>
    </a>
    <?php endif; ?>
    <?php if ($deadlineFilter !== ""): ?>
    <a class="pill-remove" href="?<?= e(http_build_query(array_merge($_GET, ["deadline" => ""]))) ?>">
        Deadline filter <i class="bi bi-x"></i>
    </a>
    <?php endif; ?>
    <a class="pill-remove" href="?section=<?= e($section) ?>&tab=<?= e($tab) ?>" title="Clear all filters">
        Clear all <i class="bi bi-x-circle"></i>
    </a>
</div>
<?php endif; ?>

<div class="results-head">

    <span>
        <?= count($jobs) ?>
        of <?= (int)($summary["total"] ?? count($jobs)) ?>
        assigned job<?= ((int)($summary["total"] ?? count($jobs)) === 1) ? "" : "s" ?>
        in this view
    </span>

    <span>
        Ordered by
        <?= e(
            match ($sort) {
                "newest" => "newest assignment",
                "oldest" => "oldest assignment",
                "deadline" => "nearest deadline",
                default => "priority + deadline"
            }
        ) ?>
    </span>

</div>

<div class="job-stack">

<?php if (!$jobs): ?>

<div class="empty">

    <i class="bi bi-briefcase fs-1 d-block mb-2"></i>

    <h5 class="fw-bold text-dark">
        No assigned jobs found
    </h5>

    <div>
        No job request matches the current view or filters.
    </div>

    <div class="mt-3 d-flex gap-2 justify-content-center flex-wrap">
        <a class="btn btn-sm btn-primary" href="?section=<?= e($section) ?>&tab=<?= e($tab) ?>">
            <i class="bi bi-x-circle me-1"></i>
            Clear filters
        </a>
        <a class="btn btn-sm btn-outline-secondary" href="?section=<?= e($section) ?>&tab=active">
            <i class="bi bi-lightning-charge me-1"></i>
            View active queue
        </a>
    </div>

</div>

<?php endif; ?>

<?php foreach ($jobs as $job): ?>

<?php
$progress =
    assignedStatusProgress(
        (string)
        $job["approval_status"]
    );

$requestTypesDecoded =
    decodeArray(
        $job["request_types"]
        ?? null
    );

$deadline =
    assignedDeadlineMeta(
        $job["date_completion"]
        ?? null,
        (string)
        $job["approval_status"]
    );

$rowClass =
    assignedRowClass(
        $requestTypesDecoded
    );

$progressBarClass =
    $progress >= 100 ? "progress-done" : (($deadline["class"] ?? "") === "deadline-overdue" ? "progress-late" : "");
?>

<article class="job-card <?= e($rowClass) ?>" aria-label="<?= e($job["job_no"] . " — " . $job["project_title"]) ?>">

<div class="job-top">

    <div class="job-main">

        <div class="job-no">
            <?= e($job["job_no"]) ?>
        </div>

        <div class="job-title">
            <?= e($job["project_title"]) ?>
        </div>

        <div class="job-meta">

            <span>
                <i class="bi bi-person me-1"></i>
                <?= e($job["requestor_name"]) ?>
            </span>

            <span>
                <i class="bi bi-building me-1"></i>
                <?= e($job["department"]) ?>
            </span>

            <span>
                <i class="bi bi-calendar3 me-1"></i>
                Requested
                <?= e(
                    date(
                        "M d, Y",
                        strtotime(
                            $job["date_request"]
                        )
                    )
                ) ?>
            </span>

            <?php if (
                !empty(
                    $job["event_datetime"]
                )
            ): ?>

            <span>
                <i class="bi bi-calendar-event me-1"></i>
                Event
                <?= e(
                    formatEventRange(
                        $job["event_datetime"] ?? "",
                        $job["event_datetime_end"] ?? ""
                    )
                ) ?>
            </span>

            <?php endif; ?>

            <?php if (
                !empty(
                    $job["venue_location"]
                )
            ): ?>

            <span>
                <i class="bi bi-geo-alt me-1"></i>
                <?= e($job["venue_location"]) ?>
            </span>

            <?php endif; ?>

        </div>

        <div class="badge-row">

            <span class="status-pill <?= e(assignedStatusClass((string)$job["approval_status"])) ?>">
                <?= e($job["approval_status"]) ?>
            </span>

            <span class="priority-pill <?= e(assignedPriorityClass((string)$job["priority"])) ?>">
                <i class="bi bi-flag-fill"></i>
                <?= e($job["priority"]) ?>
            </span>

            <span class="deadline-pill <?= e($deadline["class"]) ?>">
                <i class="bi bi-clock"></i>
                <?= e($deadline["label"]) ?>
            </span>

            <?php foreach ($requestTypesDecoded as $requestType): ?>

            <span class="type-pill <?= e(assignedRequestTypeClass((string)$requestType)) ?>">
                <i class="bi <?= e(assignedRequestTypeIcon((string)$requestType)) ?>"></i>
                <?= e($requestType) ?>
            </span>

            <?php endforeach; ?>

        </div>

        <?php if (
            !empty(
                $job["assignment_notes"]
            )
        ): ?>

        <div class="assignment-note">
            <strong>
                <i class="bi bi-chat-left-text me-1"></i>
                Assignment instructions:
            </strong>

            <?= nl2br(
                e(
                    (string)
                    $job["assignment_notes"]
                )
            ) ?>
        </div>

        <?php endif; ?>

        <div class="progress-area <?= e($progressBarClass) ?>">

            <div class="progress-label">
                <span>
                    Workflow progress
                </span>

                <span>
                    <?= $progress ?>%
                </span>
            </div>

            <div
                class="progress"
                role="progressbar"
                aria-valuenow="<?= $progress ?>"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div
                    class="progress-bar"
                    style="width:<?= $progress ?>%"
                ></div>
            </div>

        </div>

    </div>

    <div class="job-actions">

        <a
            class="btn btn-sm btn-primary"
            href="<?= e(BASE_URL) ?>/staff/review_request.php?id=<?= (int)$job["id"] ?>"
        >
            <i class="bi bi-box-arrow-up-right me-1"></i>
            Open Job
        </a>

        <a
            class="btn btn-sm btn-outline-secondary"
            href="<?= e(BASE_URL) ?>/my_calendar.php"
        >
            <i class="bi bi-calendar3 me-1"></i>
            Calendar
        </a>

        <div class="assignment-age" title="Last update: <?= e($job["updated_at"] ?? "") ?>">
            <?= e(
                assignedAgeLabel(
                    $job["assigned_at"]
                    ?? null
                )
            ) ?>
        </div>

        <div class="job-updated">
            <?= e(assignedRelativeTime($job["updated_at"] ?? null)) ?>
        </div>

    </div>

</div>

</article>

<?php endforeach; ?>

</div><!-- /.job-stack -->

<?php else: ?>

<section class="task-section" aria-label="My production tasks">

    <div class="task-section-head">

        <h2 class="task-section-title">
            <i class="bi bi-people-fill"></i>
            My Production Tasks
        </h2>

        <span class="task-count">
            <?= count($myTasks) ?> task<?= count($myTasks) === 1 ? "" : "s" ?>
        </span>

    </div>

    <?php if ($myTasks): ?>

    <div class="job-stack">

        <?php foreach ($myTasks as $task): ?>

            <?php
            $taskMeta = pubTaskRoleMeta((string)$task["task_role"]);
            $taskCode = publicationDisplayCode(
                (string)$task["kind"],
                (int)$task["publication_id"]
            );
            $taskProgress = pubTaskStatusProgress((string)$task["status"]);
            $taskViewUrl = rtrim(BASE_URL, "/")
                . "/staff/publication_requests.php?view="
                . urlencode((string)$task["kind"])
                . "&id="
                . (int)$task["publication_id"];
            ?>

            <article class="job-card row-publication" aria-label="<?= e($taskCode . " — " . $task["display_title"]) ?>">

                <div class="job-top">

                    <div class="job-main">

                        <div class="job-no">
                            <?= e($taskCode) ?>
                        </div>

                        <div class="job-title">
                            <?= e($task["display_title"]) ?>
                        </div>

                        <div class="job-meta">

                            <span>
                                <i class="bi bi-easel me-1"></i>
                                <?= e(pubTaskKindLabel((string)$task["kind"])) ?>
                            </span>

                            <?php if (!empty($task["preferred_publication_date"]) && $task["preferred_publication_date"] !== "0000-00-00"): ?>
                                <span>
                                    <i class="bi bi-calendar-event me-1"></i>
                                    Target
                                    <?= e(date("M d, Y", strtotime((string)$task["preferred_publication_date"]))) ?>
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($task["assigned_at"])): ?>
                                <span title="Assigned: <?= e($task["assigned_at"]) ?>">
                                    <i class="bi bi-person-check me-1"></i>
                                    Assigned
                                    <?= e(date("M d, Y", strtotime((string)$task["assigned_at"]))) ?>
                                </span>
                            <?php endif; ?>

                        </div>

                        <div class="badge-row">

                            <span class="role-pill role-<?= e(preg_replace("/[^a-z]/", "", (string)$task["task_role"]) ?: "default") ?>">
                                <i class="bi <?= e((string)($taskMeta["icon"] ?? "bi-person")) ?>"></i>
                                <?= e((string)($taskMeta["label"] ?? $task["task_role"])) ?>
                            </span>

                            <span class="status-pill <?= e(pubTaskStatusClass((string)$task["status"])) ?>">
                                <?= e($task["status"]) ?>
                            </span>

                        </div>

                        <div class="progress-area <?= $taskProgress >= 100 ? "progress-done" : "" ?>">

                            <div class="progress-label">
                                <span>
                                    Publication progress
                                </span>

                                <span>
                                    <?= $taskProgress ?>%
                                </span>
                            </div>

                            <div
                                class="progress"
                                role="progressbar"
                                aria-valuenow="<?= $taskProgress ?>"
                                aria-valuemin="0"
                                aria-valuemax="100"
                            >
                                <div
                                    class="progress-bar"
                                    style="width:<?= $taskProgress ?>%"
                                ></div>
                            </div>

                        </div>

                    </div>

                    <div class="job-actions">

                        <a
                            class="btn btn-sm btn-primary"
                            href="<?= e($taskViewUrl) ?>"
                        >
                            <i class="bi bi-box-arrow-up-right me-1"></i>
                            Open Task
                        </a>

                    </div>

                </div>

            </article>

        <?php endforeach; ?>

    </div>

    <?php else: ?>

    <div class="job-stack">

        <div class="empty">

            <i class="bi bi-people fs-1 d-block mb-2"></i>

            <h5 class="fw-bold text-dark">
                No production tasks
            </h5>

            <div>
                No layout, video, drafting or approval work is assigned to you right now.
            </div>

        </div>

    </div>

    <?php endif; ?>

</section>

<?php endif; ?>

</section>

</div>

</main>

</div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
></script>

<button id="toTopBtn" type="button" title="Back to top" aria-label="Back to top">
    <i class="bi bi-arrow-up"></i>
</button>

<script>
document.addEventListener("DOMContentLoaded", function () {
    // Press "/" to jump to search.
    var searchInput = document.getElementById("jobSearchInput");

    document.addEventListener("keydown", function (ev) {
        if (ev.key === "/" && document.activeElement !== searchInput
            && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) {
            ev.preventDefault();
            if (searchInput) { searchInput.focus(); searchInput.select(); }
        }
    });

    // Back-to-top visibility.
    var toTop = document.getElementById("toTopBtn");

    window.addEventListener("scroll", function () {
        if (window.scrollY > 600) {
            toTop.classList.add("show");
        } else {
            toTop.classList.remove("show");
        }
    }, { passive: true });

    toTop.addEventListener("click", function () {
        window.scrollTo({ top: 0, behavior: "smooth" });
    });
});
</script>

</body>
</html>
