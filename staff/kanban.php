<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
include "../includes/advanced_helpers.php";
require_once "../includes/module_access.php";

requireRole([
    "staff",
    "supervisor",
    "admin"
]);
requireModule($conn, "kanban");

$role = $_SESSION["role"] ?? "";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION["csrf_token"];

/*
|--------------------------------------------------------------------------
| Kanban Helpers
|--------------------------------------------------------------------------
*/

function kanbanTableExists(
    mysqli $conn,
    string $table
): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?"
    );

    $stmt->bind_param("s", $table);
    $stmt->execute();

    $row = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    return (int) ($row["total"] ?? 0) > 0;
}

function kanbanColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?"
    );

    $stmt->bind_param(
        "ss",
        $table,
        $column
    );

    $stmt->execute();

    $row = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    return (int) ($row["total"] ?? 0) > 0;
}

function publicationTypeLabel(
    ?string $value,
    string $fallback
): string {
    $value = trim((string) $value);

    if ($value === "") {
        return $fallback;
    }

    $decoded = json_decode($value, true);

    if (is_array($decoded)) {
        $labels = array_values(
            array_filter(
                array_map(
                    static fn ($item): string =>
                        trim((string) $item),
                    $decoded
                )
            )
        );

        if ($labels) {
            return implode(", ", $labels);
        }
    }

    return $value;
}

function publicationBoardStatus(
    string $status
): string {
    return match ($status) {
        "For Review" => "Pending Review",
        "In Preparation" => "In Development",
        "For Approval" => "Approved",
        "Published" => "Completed",
        "Closed" => "Closed",
        default => "Pending Review"
    };
}

function publicationDueInfo(
    ?string $date,
    string $status
): array {
    if ($status === "Published") {
        return [
            "text" => "Published",
            "class" => "due-success"
        ];
    }

    if ($status === "Closed") {
        return [
            "text" => "Publication Closed",
            "class" => "due-success"
        ];
    }

    if (!$date) {
        return [
            "text" => "No Preferred Publication Date",
            "class" => "due-muted"
        ];
    }

    try {
        $today = new DateTime("today");
        $preferred = new DateTime($date);

        $days = (int) $today
            ->diff($preferred)
            ->format("%r%a");
    } catch (Throwable $error) {
        return [
            "text" => "Date Unavailable",
            "class" => "due-muted"
        ];
    }

    if ($days < 0) {
        return [
            "text" =>
                "Preferred Date Passed by "
                . abs($days)
                . " day(s)",
            "class" => "due-danger"
        ];
    }

    if ($days === 0) {
        return [
            "text" => "Preferred Publication Today",
            "class" => "due-warning"
        ];
    }

    if ($days === 1) {
        return [
            "text" => "Preferred Publication Tomorrow",
            "class" => "due-warning"
        ];
    }

    return [
        "text" =>
            $days
            . " days to preferred date",
        "class" =>
            $days <= 7
                ? "due-warning"
                : "due-success"
    ];
}

$statuses = [
    "Pending Review",
    "Under Review",
    "Approved",
    "In Development",
    "For Revision",
    "Completed",
    "For Evaluation",
    "Closed"
];

$search = trim(
    (string) ($_GET["search"] ?? "")
);

/*
|--------------------------------------------------------------------------
| Job Requests
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        jr.id,
        jr.job_no,
        jr.project_title,
        jr.requestor_name,
        jr.department,
        jr.date_request,
        jr.date_completion,
        jr.approval_status,
        COALESCE(jr.priority, 'Normal') AS priority,
        jr.assigned_to,
        jr.request_types,
        u.name AS assigned_name
    FROM job_requests jr
    LEFT JOIN users u
        ON u.id = jr.assigned_to
    WHERE COALESCE(jr.archived, 0) = 0
";

$params = [];
$types = "";

if ($search !== "") {
    $sql .= "
        AND (
            jr.job_no LIKE ?
            OR jr.project_title LIKE ?
            OR jr.requestor_name LIKE ?
            OR jr.department LIKE ?
        )
    ";

    $value = "%" . $search . "%";

    $params = [
        $value,
        $value,
        $value,
        $value
    ];

    $types = "ssss";
}

$sql .= "
    ORDER BY
        FIELD(
            jr.priority,
            'Urgent',
            'High',
            'Normal',
            'Low'
        ),
        jr.date_completion ASC,
        jr.id DESC
";

$stmt = $conn->prepare($sql);

if ($params) {
    $stmt->bind_param(
        $types,
        ...$params
    );
}

$stmt->execute();

$jobRows = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

$rows = [];

foreach ($jobRows as $row) {
    $approvalStatus = (string) $row["approval_status"];

    if (
        in_array(
            $approvalStatus,
            [
                "Pending Review",
                "Under Review",
                "For Revision"
            ],
            true
        )
    ) {
        $due = [
            "text" => "Awaiting Approval",
            "class" => "due-muted"
        ];
    } else {
        $due = dueDateInfo(
            $row["date_completion"],
            $approvalStatus
        );
    }

    $row["source"] = "job";
    $row["source_group"] = "job";
    $row["source_label"] = "Job Request";
    $row["board_status"] = $approvalStatus;
    $row["actual_status"] = $approvalStatus;
    $row["type_label"] =
        publicationTypeLabel(
            $row["request_types"] ?? null,
            "HPU Job Request"
        );
    $row["due_info"] = $due;
    $row["open_url"] =
        rtrim(BASE_URL, "/")
        . "/staff/review_request.php?id="
        . (int) $row["id"];
    $row["activity_url"] =
        rtrim(BASE_URL, "/")
        . "/staff/activity.php?id="
        . (int) $row["id"];
    $row["qr_url"] =
        rtrim(BASE_URL, "/")
        . "/user/qr.php?id="
        . (int) $row["id"];
    $row["draggable"] = true;

    $rows[] = $row;
}

/*
|--------------------------------------------------------------------------
| Event / Activity Publication Submissions
|--------------------------------------------------------------------------
*/

if (
    kanbanTableExists(
        $conn,
        "publication_event_submissions"
    )
) {
    $eventArchiveWhere =
        kanbanColumnExists(
            $conn,
            "publication_event_submissions",
            "archived"
        )
            ? "WHERE COALESCE(p.archived, 0) = 0"
            : "";

    $eventSql = "
        SELECT
            p.id,
            p.title,
            p.activity_type,
            p.preferred_publication_date,
            p.status,
            p.created_at,
            p.submitted_by,
            u.name AS submitted_name
        FROM publication_event_submissions p
        LEFT JOIN users u
            ON u.id = p.submitted_by
        {$eventArchiveWhere}
        ORDER BY
            p.preferred_publication_date ASC,
            p.id DESC
    ";

    $eventResult = $conn->query($eventSql);

    if ($eventResult) {
        foreach (
            $eventResult->fetch_all(MYSQLI_ASSOC)
            as $row
        ) {
            $status =
                (string) (
                    $row["status"]
                    ?: "For Review"
                );

            $code =
                publicationDisplayCode("event", (int) $row["id"]);

            $item = [
                "id" => (int) $row["id"],
                "job_no" => $code,
                "project_title" =>
                    (string) (
                        $row["title"]
                        ?: "Untitled Event / Activity"
                    ),
                "requestor_name" =>
                    (string) (
                        $row["submitted_name"]
                        ?: "Publication Submission"
                    ),
                "department" =>
                    "Event / Activity Publication",
                "date_request" =>
                    substr(
                        (string) $row["created_at"],
                        0,
                        10
                    ),
                "date_completion" =>
                    $row["preferred_publication_date"],
                "approval_status" => $status,
                "priority" => "Publication",
                "assigned_to" => 0,
                "assigned_name" =>
                    "Publication Workflow",
                "source" => "event",
                "source_group" => "publication",
                "source_label" => "Publication",
                "board_status" =>
                    publicationBoardStatus($status),
                "actual_status" => $status,
                "type_label" =>
                    (string) (
                        $row["activity_type"]
                        ?: "Event / Activity Publication"
                    ),
                "due_info" =>
                    publicationDueInfo(
                        $row["preferred_publication_date"],
                        $status
                    ),
                "open_url" =>
                    rtrim(BASE_URL, "/")
                    . "/staff/publication_requests.php?view=event&id="
                    . (int) $row["id"],
                "activity_url" =>
                    rtrim(BASE_URL, "/")
                    . "/staff/publication_requests.php?view=event&id="
                    . (int) $row["id"],
                "qr_url" => "",
                "draggable" => false
            ];

            if ($search !== "") {
                $haystack = mb_strtolower(
                    implode(
                        " ",
                        [
                            $item["job_no"],
                            $item["project_title"],
                            $item["requestor_name"],
                            $item["department"],
                            $item["type_label"],
                            $item["actual_status"]
                        ]
                    )
                );

                if (
                    !str_contains(
                        $haystack,
                        mb_strtolower($search)
                    )
                ) {
                    continue;
                }
            }

            $rows[] = $item;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Recognition / Milestone Publication Submissions
|--------------------------------------------------------------------------
*/

if (
    kanbanTableExists(
        $conn,
        "publication_recognition_submissions"
    )
) {
    $recognitionArchiveWhere =
        kanbanColumnExists(
            $conn,
            "publication_recognition_submissions",
            "archived"
        )
            ? "WHERE COALESCE(p.archived, 0) = 0"
            : "";

    $recognitionSql = "
        SELECT
            p.id,
            p.recognized_entity,
            p.recognition_type,
            p.preferred_publication_date,
            p.status,
            p.created_at,
            p.submitted_by,
            u.name AS submitted_name
        FROM publication_recognition_submissions p
        LEFT JOIN users u
            ON u.id = p.submitted_by
        {$recognitionArchiveWhere}
        ORDER BY
            p.preferred_publication_date ASC,
            p.id DESC
    ";

    $recognitionResult =
        $conn->query(
            $recognitionSql
        );

    if ($recognitionResult) {
        foreach (
            $recognitionResult->fetch_all(MYSQLI_ASSOC)
            as $row
        ) {
            $status =
                (string) (
                    $row["status"]
                    ?: "For Review"
                );

            $code =
                publicationDisplayCode("recognition", (int) $row["id"]);

            $item = [
                "id" => (int) $row["id"],
                "job_no" => $code,
                "project_title" =>
                    (string) (
                        $row["recognized_entity"]
                        ?: "Recognition / Milestone"
                    ),
                "requestor_name" =>
                    (string) (
                        $row["submitted_name"]
                        ?: "Publication Submission"
                    ),
                "department" =>
                    "Recognition / Milestone Publication",
                "date_request" =>
                    substr(
                        (string) $row["created_at"],
                        0,
                        10
                    ),
                "date_completion" =>
                    $row["preferred_publication_date"],
                "approval_status" => $status,
                "priority" => "Recognition",
                "assigned_to" => 0,
                "assigned_name" =>
                    "Publication Workflow",
                "source" => "recognition",
                "source_group" => "publication",
                "source_label" => "Recognition",
                "board_status" =>
                    publicationBoardStatus($status),
                "actual_status" => $status,
                "type_label" =>
                    publicationTypeLabel(
                        $row["recognition_type"] ?? null,
                        "Recognition / Milestone Publication"
                    ),
                "due_info" =>
                    publicationDueInfo(
                        $row["preferred_publication_date"],
                        $status
                    ),
                "open_url" =>
                    rtrim(BASE_URL, "/")
                    . "/staff/publication_requests.php?view=recognition&id="
                    . (int) $row["id"],
                "activity_url" =>
                    rtrim(BASE_URL, "/")
                    . "/staff/publication_requests.php?view=recognition&id="
                    . (int) $row["id"],
                "qr_url" => "",
                "draggable" => false
            ];

            if ($search !== "") {
                $haystack = mb_strtolower(
                    implode(
                        " ",
                        [
                            $item["job_no"],
                            $item["project_title"],
                            $item["requestor_name"],
                            $item["department"],
                            $item["type_label"],
                            $item["actual_status"]
                        ]
                    )
                );

                if (
                    !str_contains(
                        $haystack,
                        mb_strtolower($search)
                    )
                ) {
                    continue;
                }
            }

            $rows[] = $item;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Content Contribution Publication Submissions
|--------------------------------------------------------------------------
*/

if (
    kanbanTableExists(
        $conn,
        "publication_content_contributions"
    )
) {
    $contributionArchiveWhere =
        kanbanColumnExists(
            $conn,
            "publication_content_contributions",
            "archived"
        )
            ? "WHERE COALESCE(p.archived, 0) = 0"
            : "";

    $contributionSql = "
        SELECT
            p.id,
            p.content_title,
            p.contribution_type,
            p.preferred_publication_date,
            p.status,
            p.created_at,
            p.submitted_by,
            u.name AS submitted_name
        FROM publication_content_contributions p
        LEFT JOIN users u
            ON u.id = p.submitted_by
        {$contributionArchiveWhere}
        ORDER BY
            p.preferred_publication_date ASC,
            p.id DESC
    ";

    $contributionResult =
        $conn->query(
            $contributionSql
        );

    if ($contributionResult) {
        foreach (
            $contributionResult->fetch_all(MYSQLI_ASSOC)
            as $row
        ) {
            $status =
                (string) (
                    $row["status"]
                    ?: "For Review"
                );

            $code =
                publicationDisplayCode("contribution", (int) $row["id"]);

            $item = [
                "id" => (int) $row["id"],
                "job_no" => $code,
                "project_title" =>
                    (string) (
                        $row["content_title"]
                        ?: "Untitled Content Contribution"
                    ),
                "requestor_name" =>
                    (string) (
                        $row["submitted_name"]
                        ?: "Publication Submission"
                    ),
                "department" =>
                    "Content Contribution Publication",
                "date_request" =>
                    substr(
                        (string) $row["created_at"],
                        0,
                        10
                    ),
                "date_completion" =>
                    $row["preferred_publication_date"],
                "approval_status" => $status,
                "priority" => "Content",
                "assigned_to" => 0,
                "assigned_name" =>
                    "Publication Workflow",
                "source" => "contribution",
                "source_group" => "publication",
                "source_label" => "Content",
                "board_status" =>
                    publicationBoardStatus($status),
                "actual_status" => $status,
                "type_label" =>
                    publicationTypeLabel(
                        $row["contribution_type"] ?? null,
                        "Content Contribution Publication"
                    ),
                "due_info" =>
                    publicationDueInfo(
                        $row["preferred_publication_date"],
                        $status
                    ),
                "open_url" =>
                    rtrim(BASE_URL, "/")
                    . "/staff/publication_requests.php?view=contribution&id="
                    . (int) $row["id"],
                "activity_url" =>
                    rtrim(BASE_URL, "/")
                    . "/staff/publication_requests.php?view=contribution&id="
                    . (int) $row["id"],
                "qr_url" => "",
                "draggable" => false
            ];

            if ($search !== "") {
                $haystack = mb_strtolower(
                    implode(
                        " ",
                        [
                            $item["job_no"],
                            $item["project_title"],
                            $item["requestor_name"],
                            $item["department"],
                            $item["type_label"],
                            $item["actual_status"]
                        ]
                    )
                );

                if (
                    !str_contains(
                        $haystack,
                        mb_strtolower($search)
                    )
                ) {
                    continue;
                }
            }

            $rows[] = $item;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Group Combined Work
|--------------------------------------------------------------------------
*/

$grouped = [];

foreach ($statuses as $status) {
    $grouped[$status] = [];
}

foreach ($rows as $row) {
    $status =
        (string) (
            $row["board_status"]
            ?? ""
        );

    if (!isset($grouped[$status])) {
        continue;
    }

    $grouped[$status][] = $row;
}

$totalJobs = count(
    array_filter(
        $rows,
        static fn (array $row): bool =>
            ($row["source"] ?? "") === "job"
    )
);

$totalPublications =
    count($rows)
    -
    $totalJobs;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Kanban Board | HPU Workbench</title>

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
    <link
        rel="stylesheet"
        href="<?= e(BASE_URL) ?>/assets/css/advanced_modules.css"
    >

    <style>
        :root {
            --kanban-column-width: 255px;
            --kanban-gap: 12px;
            --kanban-border: #e2e8f0;
            --kanban-bg: #f8fafc;
            --kanban-card-bg: #ffffff;
            --kanban-text: #0f172a;
            --kanban-muted: #64748b;
        }

        html,
        body {
            overflow-x: hidden;
        }

        .main {
            min-width: 0;
            overflow: hidden;
        }

        .content {
            padding: 24px;
        }

        .container-fluid {
            min-width: 0;
            padding-right: 0;
            padding-left: 0;
        }

        .kanban-result-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 11px;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            background: #f8fafc;
            color: #64748b;
            font-size: .72rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .kanban-result-pill i {
            color: #2563eb;
        }

        .kanban-summary-pills {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
            align-items: center;
        }

        .source-filter-bar {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .source-filter-btn {
            border: 1px solid #dbe3ee;
            border-radius: 999px;
            padding: 6px 10px;
            background: #ffffff;
            color: #475569;
            font-size: .68rem;
            font-weight: 800;
            transition: .15s ease;
        }

        .source-filter-btn:hover,
        .source-filter-btn.active {
            border-color: #bfdbfe;
            background: #eff6ff;
            color: #1d4ed8;
        }

        .source-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 7px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 800;
        }

        .source-job {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .source-event {
            background: #fdf2f8;
            color: #be185d;
        }

        .source-recognition {
            background: #f3e8ff;
            color: #7e22ce;
        }

        .source-contribution {
            background: #dcfce7;
            color: #166534;
        }

        .type-line {
            display: flex;
            align-items: center;
            gap: 5px;
            min-width: 0;
            margin: 5px 0;
            color: #475569;
            font-size: 10px;
            font-weight: 700;
        }

        .type-line span {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .publication-note {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 7px;
            color: #7c3aed;
            font-size: 9px;
            font-weight: 700;
        }

        .priority-publication {
            background: #fdf2f8;
            color: #be185d;
        }

        .priority-recognition {
            background: #f3e8ff;
            color: #7e22ce;
        }

        .priority-contribution {
            background: #dcfce7;
            color: #166534;
        }

        .due-success {
            background: #dcfce7;
            color: #166534;
        }

        .due-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .due-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .due-muted {
            background: #f1f5f9;
            color: #64748b;
        }

        .status-publication {
            background: #ede9fe;
            color: #6d28d9;
        }

        .kanban-scroll-controls {
            display: flex;
            gap: 6px;
        }

        .kanban-scroll-controls .btn {
            width: 33px;
            height: 33px;
            padding: 0;
            border-radius: 9px;
        }

        .kanban-board-shell {
            position: relative;
            width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            border: 1px solid var(--kanban-border);
            border-radius: 14px;
            background: #eef2f7;
            scroll-behavior: smooth;
        }

        .kanban-board{
            display:grid;
            grid-auto-flow:column;
            grid-auto-columns:var(--kanban-column-width);
            gap:var(--kanban-gap);

            width:max-content;
            min-width:max-content;

            overflow:hidden;
            padding:12px;
        }

        .kanban-board::-webkit-scrollbar {
            height: 10px;
        }

        .kanban-board::-webkit-scrollbar-track {
            background: transparent;
        }

        .kanban-board::-webkit-scrollbar-thumb {
            border-radius: 999px;
            background: #94a3b8;
        }

        .kanban-column {
            display: flex;
            min-width: 0;
            height: calc(100vh - 200px);
            min-height: 470px;
            max-height: 760px;
            flex-direction: column;
            overflow: hidden;
            scroll-snap-align: start;
            border: 1px solid var(--kanban-border);
            border-radius: 12px;
            background: var(--kanban-bg);
        }

        .kanban-column-header {
            position: sticky;
            top: 0;
            z-index: 4;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            min-height: 44px;
            padding: 10px 11px;
            border-bottom: 1px solid var(--kanban-border);
            background: #ffffff;
            color: var(--kanban-text);
            font-size: 11px;
            font-weight: 800;
        }

        .kanban-column-header > span:first-child {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .kanban-card-list {
            min-height: 0;
            flex: 1;
            overflow-y: auto;
            overscroll-behavior: contain;
            padding: 8px;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }

        .kanban-card-list::-webkit-scrollbar {
            width: 6px;
        }

        .kanban-card-list::-webkit-scrollbar-thumb {
            border-radius: 999px;
            background: #cbd5e1;
        }

        .kanban-card {
            margin-bottom: 8px;
            padding: 10px;
            border: 1px solid var(--kanban-border);
            border-radius: 10px;
            background: var(--kanban-card-bg);
            box-shadow: 0 3px 10px rgba(15, 23, 42, .05);
            cursor: grab;
            transition:
                transform .16s ease,
                box-shadow .16s ease,
                border-color .16s ease;
        }

        .kanban-card:hover {
            transform: translateY(-1px);
            border-color: #93c5fd;
            box-shadow: 0 7px 18px rgba(15, 23, 42, .08);
        }

        .kanban-card:active {
            cursor: grabbing;
        }

        .kanban-card.dragging {
            opacity: .55;
            transform: rotate(1deg);
        }

        .kanban-card-title {
            display: -webkit-box;
            overflow: hidden;
            margin: 6px 0;
            color: var(--kanban-text);
            font-size: 12px;
            font-weight: 800;
            line-height: 1.25;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }

        .kanban-meta {
            display: flex;
            align-items: center;
            gap: 5px;
            min-width: 0;
            margin-bottom: 3px;
            color: var(--kanban-muted);
            font-size: 10px;
            line-height: 1.2;
        }

        .kanban-meta i {
            flex: 0 0 auto;
        }

        .kanban-meta-text {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .priority-pill,
        .due-pill,
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            max-width: 100%;
            padding: 4px 7px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 800;
            line-height: 1.15;
        }

        .due-pill {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .kanban-actions {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 4px;
            margin-top: 8px;
        }

        .kanban-actions .btn {
            min-width: 0;
            padding: 4px 5px;
            overflow: hidden;
            font-size: 9px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .drop-highlight {
            outline: 2px dashed #2563eb;
            outline-offset: -5px;
            background: #eff6ff;
        }

        .kanban-empty {
            display: grid;
            min-height: 120px;
            place-items: center;
            padding: 18px;
            color: var(--kanban-muted);
            font-size: 11px;
            text-align: center;
        }

        .kanban-hint {
            margin-top: 8px;
            color: #64748b;
            font-size: 10px;
            text-align: center;
        }

        @media (min-width: 1600px) {
            :root {
                --kanban-column-width: 245px;
            }
        }

        @media (max-width: 1200px) {
            :root {
                --kanban-column-width: 240px;
            }

            .kanban-column {
                height: calc(100vh - 210px);
            }
        }

        @media (max-width: 720px) {
            :root {
                --kanban-column-width: min(86vw, 320px);
            }

            .content {
                padding: 10px 8px;
            }

            .ph-actions {
                align-items: stretch;
            }

            .ph-actions form {
                width: 100%;
            }

            .kanban-scroll-controls {
                justify-content: flex-end;
            }

            .kanban-column {
                height: 62vh;
                min-height: 430px;
            }

            .kanban-board {
                padding: 9px;
            }
        }

        @media (max-width: 420px) {
            .kanban-search-form {
                flex-direction: column;
            }

            .kanban-actions {
                grid-template-columns: 1fr;
            }

            .kanban-actions .btn {
                width: 100%;
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

                <header class="ph">
                    <div class="ph-copy">
                        <div class="ph-icon" aria-hidden="true">
                            <i class="bi bi-kanban"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="ph-eyebrow">HPU Workbench</p>
                            <h1 class="ph-title">Kanban Board</h1>
                            <p class="ph-subtitle">Drag requests between authorized workflow stages.</p>
                        </div>
                    </div>

                    <div class="ph-actions">
                        <div class="kanban-summary-pills">
                            <span class="kanban-result-pill" title="Total active board items">
                                <i class="bi bi-list-check"></i>
                                <?= number_format(count($rows)) ?>
                                active item<?= count($rows) === 1 ? "" : "s" ?>
                            </span>

                            <span class="kanban-result-pill" title="Job requests">
                                <i class="bi bi-briefcase"></i>
                                <?= number_format($totalJobs) ?>
                                jobs
                            </span>

                            <span class="kanban-result-pill" title="Publication submissions">
                                <i class="bi bi-newspaper"></i>
                                <?= number_format($totalPublications) ?>
                                publications
                            </span>
                        </div>

                        <div class="kanban-scroll-controls">
                            <button
                                class="btn btn-sm btn-outline-secondary"
                                id="kanbanScrollLeft"
                                type="button"
                                title="Scroll left"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </button>

                            <button
                                class="btn btn-sm btn-outline-secondary"
                                id="kanbanScrollRight"
                                type="button"
                                title="Scroll right"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </button>
                        </div>

                        <form method="GET" class="d-flex gap-2">
                            <input
                                class="form-control form-control-sm"
                                type="search"
                                name="search"
                                value="<?= e($search) ?>"
                                placeholder="Search requests"
                            >
                            <button class="btn btn-sm btn-primary" type="submit">
                                Search
                            </button>
                        </form>
                    </div>
                </header>

                <div class="source-filter-bar" aria-label="Board source filters">
                    <button
                        class="source-filter-btn active"
                        type="button"
                        data-source-filter="all"
                    >
                        <i class="bi bi-grid me-1"></i>
                        All Work
                    </button>

                    <button
                        class="source-filter-btn"
                        type="button"
                        data-source-filter="job"
                    >
                        <i class="bi bi-briefcase me-1"></i>
                        Job Requests
                    </button>

                    <button
                        class="source-filter-btn"
                        type="button"
                        data-source-filter="publication"
                    >
                        <i class="bi bi-newspaper me-1"></i>
                        Publication Submission
                    </button>

                    <button
                        class="source-filter-btn"
                        type="button"
                        data-source-filter="event"
                    >
                        <i class="bi bi-calendar2-event me-1"></i>
                        Event / Activity
                    </button>

                    <button
                        class="source-filter-btn"
                        type="button"
                        data-source-filter="recognition"
                    >
                        <i class="bi bi-award me-1"></i>
                        Recognition / Milestone
                    </button>

                    <button
                        class="source-filter-btn"
                        type="button"
                        data-source-filter="contribution"
                    >
                        <i class="bi bi-journal-plus me-1"></i>
                        Content Contribution
                    </button>
                </div>

                <div class="kanban-board-shell">

                <div
                    class="kanban-board"
                    id="kanbanBoard"
                    data-kanban-board
                    data-csrf-token="<?= e($csrfToken) ?>"
                    data-update-url="<?= e(BASE_URL) ?>/staff/update_kanban_status.php"
                >
                    <?php foreach ($statuses as $status): ?>

                        <section class="kanban-column">

                            <div class="kanban-column-header">
                                <span><?= e($status) ?></span>

                                <span class="badge bg-secondary">
                                    <?= count(
                                        $grouped[$status] ?? []
                                    ) ?>
                                </span>
                            </div>

                            <div
                                class="kanban-card-list"
                                data-status="<?= e($status) ?>"
                            >
                                <?php foreach (
                                    $grouped[$status] ?? []
                                    as $request
                                ): ?>

                                    <?php
                                    $approvalStatus =
                                        (string) (
                                            $request["actual_status"]
                                            ?? $request["approval_status"]
                                        );

                                    $due =
                                        $request["due_info"]
                                        ?? [
                                            "text" => "Date unavailable",
                                            "class" => "due-muted"
                                        ];

                                    $source =
                                        (string) (
                                            $request["source"]
                                            ?? "job"
                                        );

                                    $sourceGroup =
                                        (string) (
                                            $request["source_group"]
                                            ?? "job"
                                        );

                                    $sourceClass =
                                        match ($source) {
                                            "event" => "source-event",
                                            "recognition" => "source-recognition",
                                            "contribution" => "source-contribution",
                                            default => "source-job"
                                        };

                                    $sourceIcon =
                                        match ($source) {
                                            "event" => "bi-newspaper",
                                            "recognition" => "bi-award",
                                            "contribution" => "bi-journal-plus",
                                            default => "bi-briefcase"
                                        };
                                    ?>

                                    <article
                                        class="kanban-card"
                                        draggable="<?= !empty($request["draggable"]) ? "true" : "false" ?>"
                                        data-request-id="<?= (int) $request["id"] ?>"
                                        data-status="<?= e($request["board_status"]) ?>"
                                        data-source="<?= e($source) ?>"
                                        data-source-group="<?= e($sourceGroup) ?>"
                                    >
                                        <div
                                            class="d-flex justify-content-between align-items-start gap-2"
                                        >
                                            <span class="source-badge <?= e($sourceClass) ?>">
                                                <i class="bi <?= e($sourceIcon) ?>"></i>
                                                <?= e($request["source_label"]) ?>
                                            </span>

                                            <strong class="small text-muted">
                                                <?= e($request["job_no"]) ?>
                                            </strong>
                                        </div>

                                        <div class="kanban-card-title">
                                            <?= e(
                                                $request["project_title"]
                                            ) ?>
                                        </div>

                                        <div class="type-line">
                                            <i class="bi bi-grid"></i>
                                            <span>
                                                <?= e(
                                                    $request["type_label"]
                                                    ?? "HPU Job Request"
                                                ) ?>
                                            </span>
                                        </div>

                                        <div class="kanban-meta">
                                            <i class="bi bi-person"></i>
                                            <span class="kanban-meta-text">
                                                <?= e(
                                                    $request["requestor_name"]
                                                ) ?>
                                            </span>
                                        </div>

                                        <div class="kanban-meta">
                                            <i class="bi bi-building"></i>
                                            <span class="kanban-meta-text">
                                                <?= e(
                                                    $request["department"]
                                                ) ?>
                                            </span>
                                        </div>

                                        <div class="mt-2">
                                            <span
                                                class="due-pill <?= e(
                                                    $due["class"]
                                                ) ?>"
                                            >
                                                <i class="bi <?=
                                                    $due["text"] === "Awaiting Approval"
                                                        ? "bi-hourglass-split"
                                                        : (
                                                            str_contains(
                                                                strtolower($due["text"]),
                                                                "published"
                                                            )
                                                                ? "bi-megaphone"
                                                                : "bi-clock"
                                                        )
                                                ?>"></i>
                                                <?= e($due["text"]) ?>
                                            </span>
                                        </div>

                                        <div class="mt-2">
                                            <?php
                                            $priorityClass =
                                                match ((string) $request["priority"]) {
                                                    "Publication" => "priority-publication",
                                                    "Recognition" => "priority-recognition",
                                                    "Content" => "priority-contribution",
                                                    default => priorityCssClass(
                                                        $request["priority"]
                                                    )
                                                };
                                            ?>
                                            <span class="priority-pill <?= e($priorityClass) ?>">
                                                <?= e($request["priority"]) ?>
                                            </span>
                                        </div>

                                        <div class="kanban-meta mt-2">
                                            <i class="bi bi-person-badge"></i>
                                            <span class="kanban-meta-text">
                                                <?= $source === "job"
                                                    ? "Assigned:"
                                                    : "Workflow:"
                                                ?>
                                                <?= e(
                                                    $request["assigned_name"]
                                                    ?: "Unassigned"
                                                ) ?>
                                            </span>
                                        </div>

                                        <div class="kanban-actions">
                                            <a
                                                class="btn btn-sm btn-outline-primary"
                                                href="<?= e($request["open_url"]) ?>"
                                            >
                                                Open
                                            </a>

                                            <a
                                                class="btn btn-sm btn-outline-secondary"
                                                href="<?= e($request["activity_url"]) ?>"
                                            >
                                                <?= $source === "job"
                                                    ? "Activity"
                                                    : "Workflow"
                                                ?>
                                            </a>

                                            <?php if (
                                                !empty(
                                                    $request["qr_url"]
                                                )
                                            ): ?>
                                                <a
                                                    class="btn btn-sm btn-outline-dark"
                                                    href="<?= e($request["qr_url"]) ?>"
                                                >
                                                    QR
                                                </a>
                                            <?php else: ?>
                                                <span
                                                    class="btn btn-sm btn-light disabled"
                                                    aria-hidden="true"
                                                >
                                                    PUB
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="mt-2">
                                            <span
                                                class="status-pill <?= $source === "job"
                                                    ? statusCssClass(
                                                        $request["approval_status"]
                                                    )
                                                    : "status-publication"
                                                ?>"
                                                data-card-status
                                            >
                                                <?= e(
                                                    $request["actual_status"]
                                                    ?? $request["approval_status"]
                                                ) ?>
                                            </span>
                                        </div>

                                        <?php if (
                                            $source !== "job"
                                        ): ?>
                                            <div class="publication-note">
                                                <i class="bi bi-info-circle"></i>
                                                Update status in Publication Requests.
                                            </div>
                                        <?php endif; ?>
                                    </article>

                                <?php endforeach; ?>

                                <?php if (
                                    empty(
                                        $grouped[$status] ?? []
                                    )
                                ): ?>

                                    <div class="kanban-empty">
                                        <div>
                                            <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                                            No requests in this stage.
                                        </div>
                                    </div>

                                <?php endif; ?>

                            </div>
                        </section>

                    <?php endforeach; ?>
                </div>

                </div>

                <div class="kanban-hint">
                    Active Job Requests and Publication Submissions are shown here. Archived records are excluded. Job Requests remain draggable; Publication workflow changes are managed from Publication Requests.
                </div>

            </div>
        </main>
    </div>
</div>

<script
    src="<?= e(BASE_URL) ?>/assets/js/kanban.js"
></script>


<script>
document.addEventListener("DOMContentLoaded", function () {

    const board = document.getElementById("kanbanBoard");
    const shell = document.querySelector(".kanban-board-shell");

    const left = document.getElementById("kanbanScrollLeft");
    const right = document.getElementById("kanbanScrollRight");

    if (!board || !shell) return;

    const getScrollAmount = () => {
        const column = board.querySelector(".kanban-column");
        return column
            ? column.offsetWidth + 12
            : 270;
    };

    left.addEventListener("click", function () {

        shell.scrollBy({
            left: -getScrollAmount(),
            behavior: "smooth"
        });

    });

    right.addEventListener("click", function () {

        shell.scrollBy({
            left: getScrollAmount(),
            behavior: "smooth"
        });

    });

    const sourceButtons =
        document.querySelectorAll(
            "[data-source-filter]"
        );

    const cards =
        Array.from(
            document.querySelectorAll(
                ".kanban-card"
            )
        );

    function updateColumnCounts() {
        document
            .querySelectorAll(
                ".kanban-column"
            )
            .forEach(function (column) {

                const visible =
                    Array.from(
                        column.querySelectorAll(
                            ".kanban-card"
                        )
                    )
                    .filter(function (card) {
                        return !card.classList.contains(
                            "d-none"
                        );
                    })
                    .length;

                const badge =
                    column.querySelector(
                        ".kanban-column-header .badge"
                    );

                if (badge) {
                    badge.textContent =
                        String(visible);
                }

                const empty =
                    column.querySelector(
                        ".kanban-empty"
                    );

                if (empty) {
                    empty.classList.toggle(
                        "d-none",
                        visible > 0
                    );
                }
            });
    }

    sourceButtons.forEach(function (button) {

        button.addEventListener(
            "click",
            function () {

                sourceButtons.forEach(function (btn) {
                    btn.classList.remove(
                        "active"
                    );
                });

                this.classList.add(
                    "active"
                );

                const filter =
                    this.dataset.sourceFilter
                    || "all";

                cards.forEach(function (card) {

                    const source =
                        card.dataset.source
                        || "";

                    const group =
                        card.dataset.sourceGroup
                        || "";

                    const show =
                        filter === "all"
                        || source === filter
                        || (
                            filter === "publication"
                            && group === "publication"
                        );

                    card.classList.toggle(
                        "d-none",
                        !show
                    );
                });

                updateColumnCounts();
            }
        );
    });

    updateColumnCounts();

});
</script>

</body>
</html>
