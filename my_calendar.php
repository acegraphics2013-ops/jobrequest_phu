<?php
declare(strict_types=1);

include __DIR__ . "/includes/config.php";
include __DIR__ . "/includes/auth.php";
include __DIR__ . "/includes/helpers.php";
require_once __DIR__ . "/includes/module_access.php";

checkLogin();
requireModule($conn, "calendar");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$userId = (int) ($_SESSION["user_id"] ?? 0);
$role = (string) ($_SESSION["role"] ?? "user");
$userName = (string) ($_SESSION["name"] ?? "User");
$department = (string) ($_SESSION["department"] ?? "");
$isUser = $role === "user";
$isHpu = in_array($role, ["staff", "supervisor", "admin"], true);
$isManager = in_array($role, ["supervisor", "admin"], true);
$isAdmin = $role === "admin";

function calJson(array $data, int $status = 200): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function calDate(?string $v): ?string
{
    $v = trim((string) $v);
    if ($v === "")
        return null;
    try {
        return (new DateTime($v))->format("Y-m-d");
    } catch (Throwable $e) {
        return null;
    }
}

function calDateTime(?string $v): ?string
{
    $v = trim((string) $v);
    if ($v === "")
        return null;
    try {
        return (new DateTime($v))->format("Y-m-d H:i:s");
    } catch (Throwable $e) {
        return null;
    }
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $r && $r->num_rows > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row["total"] ?? 0) > 0;
}

function sharedColor(string $type, string $scope): string
{
    if ($scope === "Hospital")
        return "#0f766e";
    return match ($type) {
        "Meeting" => "#2563eb",
        "Training" => "#7c3aed",
        "Event" => "#0891b2",
        "Coverage" => "#ea580c",
        "Field Work" => "#15803d",
        "Deadline" => "#dc2626",
        "Health Promotion Activity" => "#0f766e",
        "Leave / Unavailable" => "#64748b",
        default => "#475569"
    };
}

function personalColor(string $priority): string
{
    return match ($priority) {
        "High" => "#dc2626",
        "Medium" => "#ea580c",
        "Low" => "#64748b",
        default => "#2563eb"
    };
}

function officeColor(string $status): string
{
    return match ($status) {
        "Scheduled" => "#ea580c",
        "Completed" => "#15803d",
        "Needs Clarification" => "#d97706",
        "Cancelled" => "#64748b",
        "Under Review" => "#f59e0b",
        default => "#f59e0b"
    };
}

function jobColor(array $types): string
{
    return match ((string) ($types[0] ?? "")) {
        "Graphic Design" => "#2563eb",
        "Video Production" => "#7c3aed",
        "Photo/Video Coverage" => "#ea580c",
        "IEC Materials" => "#15803d",
        default => "#64748b"
    };
}

function userCanEditOffice(array $row, int $userId): bool
{
    return (int) ($row["submitted_by"] ?? 0) === $userId
        && in_array((string) ($row["status"] ?? ""), ["Submitted", "Needs Clarification"], true);
}

/* Shared HPU/Hospital calendar */
$conn->query("CREATE TABLE IF NOT EXISTS hpu_calendar_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    activity_type VARCHAR(80) NOT NULL DEFAULT 'Other',
    calendar_scope ENUM('HPU','Hospital') NOT NULL DEFAULT 'HPU',
    location VARCHAR(180) NULL,
    description TEXT NULL,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    color VARCHAR(20) NOT NULL DEFAULT '#475569',
    created_by INT NOT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_hpu_cal_date(start_datetime),
    INDEX idx_hpu_cal_scope(calendar_scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if (!columnExists($conn, "hpu_calendar_activities", "calendar_scope")) {
    $conn->query("ALTER TABLE hpu_calendar_activities ADD COLUMN calendar_scope ENUM('HPU','Hospital') NOT NULL DEFAULT 'HPU' AFTER activity_type");
}

$conn->query("CREATE TABLE IF NOT EXISTS hpu_calendar_assignees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activity_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hpu_cal_assignee(activity_id,user_id),
    INDEX idx_hpu_cal_assignee_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* Private personal tasks */
$conn->query("CREATE TABLE IF NOT EXISTS personal_calendar_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    priority ENUM('Low','Normal','Medium','High') NOT NULL DEFAULT 'Normal',
    task_status ENUM('To Do','In Progress','Done','Cancelled') NOT NULL DEFAULT 'To Do',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_personal_cal_user_date(user_id,start_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* Office Activity/Event requests */
$conn->query("CREATE TABLE IF NOT EXISTS office_activity_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submitted_by INT NOT NULL,
    office_name VARCHAR(180) NOT NULL,
    activity_title VARCHAR(220) NOT NULL,
    venue VARCHAR(180) NULL,
    description TEXT NULL,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    support_types TEXT NULL,
    status ENUM('Submitted','Under Review','Scheduled','Needs Clarification','Completed','Cancelled') NOT NULL DEFAULT 'Submitted',
    assigned_to INT NULL,
    hpu_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_office_cal_submitter(submitted_by),
    INDEX idx_office_cal_date(start_datetime),
    INDEX idx_office_cal_status(status),
    INDEX idx_office_cal_assignee(assigned_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$hpuPeople = [];
$r = $conn->query("SELECT id,name,role,department FROM users WHERE role IN ('staff','supervisor','admin') AND status='Active' ORDER BY FIELD(role,'supervisor','staff','admin'), name");
while ($row = $r->fetch_assoc())
    $hpuPeople[] = $row;

if (empty($_SESSION["multi_calendar_csrf"]))
    $_SESSION["multi_calendar_csrf"] = bin2hex(random_bytes(32));
$csrf = (string) $_SESSION["multi_calendar_csrf"];

function csrfOk(): bool
{
    return isset($_POST["csrf_token"], $_SESSION["multi_calendar_csrf"])
        && hash_equals((string) $_SESSION["multi_calendar_csrf"], (string) $_POST["csrf_token"]);
}

/* =========================================================
   FEED
========================================================= */
if (isset($_GET["feed"])) {
    $start = calDate($_GET["start"] ?? "") ?: date("Y-m-01");
    $end = calDate($_GET["end"] ?? "") ?: date("Y-m-t");
    $showHpu = ($_GET["show_hpu"] ?? "1") !== "0";
    $showHospital = ($_GET["show_hospital"] ?? "1") !== "0";
    $showPersonal = $isHpu && (($_GET["show_personal"] ?? "1") !== "0");
    $showOffice = !$isUser && (($_GET["show_office"] ?? "1") !== "0");
    $showJobs = $isHpu && (($_GET["show_jobs"] ?? "1") !== "0");
    $showPubs = $isHpu && (($_GET["show_pubs"] ?? "1") !== "0");
    $activityType = trim((string) ($_GET["activity_type"] ?? ""));
    $personId = (int) ($_GET["person_id"] ?? 0);
    $officeStatus = trim((string) ($_GET["office_status"] ?? ""));
    $events = [];

    if ($showHpu || $showHospital) {
        $sql = "SELECT DISTINCT a.*, creator.name creator_name, updater.name updater_name
                FROM hpu_calendar_activities a
                LEFT JOIN users creator ON creator.id=a.created_by
                LEFT JOIN users updater ON updater.id=a.updated_by
                LEFT JOIN hpu_calendar_assignees ca ON ca.activity_id=a.id
                WHERE a.start_datetime < DATE_ADD(?,INTERVAL 1 DAY)
                  AND (a.end_datetime IS NULL OR a.end_datetime >= ?)";
        $types = "ss";
        $params = [$end, $start];
        if ($showHpu && !$showHospital)
            $sql .= " AND COALESCE(a.calendar_scope,'HPU')='HPU'";
        elseif (!$showHpu && $showHospital)
            $sql .= " AND a.calendar_scope='Hospital'";
        if ($activityType !== "") {
            $sql .= " AND a.activity_type=?";
            $types .= "s";
            $params[] = $activityType;
        }
        if ($isHpu && $personId > 0) {
            $sql .= " AND ca.user_id=?";
            $types .= "i";
            $params[] = $personId;
        }
        $sql .= " ORDER BY a.start_datetime,a.id";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as $row) {
            $assignees = [];
            $assigneeIds = [];
            $s = $conn->prepare("SELECT u.id,u.name FROM hpu_calendar_assignees ca JOIN users u ON u.id=ca.user_id WHERE ca.activity_id=? ORDER BY u.name");
            $aid = (int) $row["id"];
            $s->bind_param("i", $aid);
            $s->execute();
            $rr = $s->get_result();
            while ($p = $rr->fetch_assoc()) {
                $assignees[] = $p["name"];
                $assigneeIds[] = (int) $p["id"];
            }
            $s->close();
            $allDay = (bool) $row["all_day"];
            $color = trim((string) ($row["color"] ?? ""));
            if ($color === "") {
                $color = sharedColor((string) $row["activity_type"], (string) ($row["calendar_scope"] ?? "HPU"));
            }
            $events[] = [
                "id" => "activity-" . $aid,
                "title" => $row["title"],
                "start" => $allDay ? date("Y-m-d", strtotime($row["start_datetime"])) : date("Y-m-d\\TH:i:s", strtotime($row["start_datetime"])),
                "end" => !empty($row["end_datetime"]) ? ($allDay ? date("Y-m-d", strtotime($row["end_datetime"] . " +1 day")) : date("Y-m-d\\TH:i:s", strtotime($row["end_datetime"]))) : null,
                "allDay" => $allDay,
                "backgroundColor" => $color,
                "borderColor" => $color,
                "extendedProps" => [
                    "source_type" => "activity",
                    "activity_id" => $aid,
                    "calendar_scope" => $row["calendar_scope"] ?? "HPU",
                    "activity_type" => $row["activity_type"],
                    "location" => $row["location"] ?? "",
                    "description" => $row["description"] ?? "",
                    "raw_start" => $row["start_datetime"],
                    "raw_end" => $row["end_datetime"] ?? "",
                    "assignees" => $assignees,
                    "assignee_ids" => $assigneeIds,
                    "creator_name" => $row["creator_name"] ?? "",
                    "updated_by_name" => $row["updater_name"] ?? "",
                    "can_edit" => $isHpu && ($isManager || (int) $row["created_by"] === $userId)
                ]
            ];
        }
    }

    if ($showPersonal) {
        $stmt = $conn->prepare("SELECT * FROM personal_calendar_tasks WHERE user_id=? AND start_datetime<DATE_ADD(?,INTERVAL 1 DAY) AND (end_datetime IS NULL OR end_datetime>=?) ORDER BY start_datetime,id");
        $stmt->bind_param("iss", $userId, $end, $start);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $row) {
            $allDay = (bool) $row["all_day"];
            $color = personalColor((string) $row["priority"]);
            $prefix = $row["task_status"] === "Done" ? "✓ " : "";
            $events[] = [
                "id" => "personal-" . (int) $row["id"],
                "title" => $prefix . $row["title"],
                "start" => $allDay ? date("Y-m-d", strtotime($row["start_datetime"])) : date("Y-m-d\\TH:i:s", strtotime($row["start_datetime"])),
                "end" => !empty($row["end_datetime"]) ? ($allDay ? date("Y-m-d", strtotime($row["end_datetime"] . " +1 day")) : date("Y-m-d\\TH:i:s", strtotime($row["end_datetime"]))) : null,
                "allDay" => $allDay,
                "backgroundColor" => $color,
                "borderColor" => $color,
                "classNames" => ["personal-task-event"],
                "extendedProps" => ["source_type" => "personal", "task_id" => (int) $row["id"], "description" => $row["description"] ?? "", "priority" => $row["priority"], "task_status" => $row["task_status"], "raw_start" => $row["start_datetime"], "raw_end" => $row["end_datetime"] ?? ""]
            ];
        }
    }

    if ($showOffice) {
        $sql = "SELECT o.*, submitter.name submitted_by_name, assigned.name assigned_name
              FROM office_activity_requests o
              LEFT JOIN users submitter ON submitter.id=o.submitted_by
              LEFT JOIN users assigned ON assigned.id=o.assigned_to
              WHERE o.start_datetime<DATE_ADD(?,INTERVAL 1 DAY) AND (o.end_datetime IS NULL OR o.end_datetime>=?)";
        $types = "ss";
        $params = [$end, $start];
        if ($isUser) {
            $sql .= " AND o.submitted_by=?";
            $types .= "i";
            $params[] = $userId;
        }
        if ($officeStatus !== "") {
            $sql .= " AND o.status=?";
            $types .= "s";
            $params[] = $officeStatus;
        }
        if ($isHpu && $personId > 0) {
            $sql .= " AND o.assigned_to=?";
            $types .= "i";
            $params[] = $personId;
        }
        $sql .= " ORDER BY o.start_datetime,o.id";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $row) {
            $supports = json_decode((string) ($row["support_types"] ?? "[]"), true);
            if (!is_array($supports))
                $supports = [];
            $allDay = (bool) $row["all_day"];
            $color = officeColor((string) $row["status"]);
            $events[] = [
                "id" => "office-" . (int) $row["id"],
                "title" => $row["activity_title"],
                "start" => $allDay ? date("Y-m-d", strtotime($row["start_datetime"])) : date("Y-m-d\\TH:i:s", strtotime($row["start_datetime"])),
                "end" => !empty($row["end_datetime"]) ? ($allDay ? date("Y-m-d", strtotime($row["end_datetime"] . " +1 day")) : date("Y-m-d\\TH:i:s", strtotime($row["end_datetime"]))) : null,
                "allDay" => $allDay,
                "backgroundColor" => $color,
                "borderColor" => $color,
                "classNames" => [$row["status"] === "Scheduled" ? "office-scheduled" : "office-pending"],
                "extendedProps" => [
                    "source_type" => "office",
                    "office_id" => (int) $row["id"],
                    "office_name" => $row["office_name"],
                    "venue" => $row["venue"] ?? "",
                    "description" => $row["description"] ?? "",
                    "support_types" => $supports,
                    "status" => $row["status"],
                    "assigned_to" => (int) ($row["assigned_to"] ?? 0),
                    "assigned_name" => $row["assigned_name"] ?? "",
                    "hpu_notes" => $row["hpu_notes"] ?? "",
                    "submitted_by" => (int) $row["submitted_by"],
                    "submitted_by_name" => $row["submitted_by_name"] ?? "",
                    "raw_start" => $row["start_datetime"],
                    "raw_end" => $row["end_datetime"] ?? "",
                    "can_user_edit" => $isUser && userCanEditOffice($row, $userId)
                ]
            ];
        }
    }

    if ($showJobs && tableExists($conn, "job_requests") && columnExists($conn, "job_requests", "assigned_to") && columnExists($conn, "job_requests", "priority")) {
        /* Only show job deadlines that have been approved by the supervisor (unit head) */
        $sql = "SELECT jr.id,jr.job_no,jr.project_title,jr.request_types,jr.date_completion,jr.approval_status,jr.priority,jr.assigned_to,jr.assignment_notes,u.name assigned_name FROM job_requests jr LEFT JOIN users u ON u.id=jr.assigned_to WHERE jr.date_completion IS NOT NULL AND jr.date_completion>=? AND jr.date_completion<=?";
        if (columnExists($conn, "job_requests", "archived"))
            $sql .= " AND COALESCE(jr.archived,0)=0";
        /* Filter: only supervisor-approved (unit_head_status = 'Approved') job requests */
        if (columnExists($conn, "job_requests", "unit_head_status"))
            $sql .= " AND jr.unit_head_status='Approved'";
        $types = "ss";
        $params = [$start, $end];
        if ($personId > 0) {
            $sql .= " AND jr.assigned_to=?";
            $types .= "i";
            $params[] = $personId;
        }
        $sql .= " ORDER BY jr.date_completion,jr.id";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $row) {
            $rtypes = decodeArray($row["request_types"] ?? null);
            $color = jobColor($rtypes);
            $events[] = [
                "id" => "job-" . (int) $row["id"],
                "title" => $row["project_title"],
                "start" => $row["date_completion"],
                "allDay" => true,
                "backgroundColor" => $color,
                "borderColor" => $color,
                "classNames" => ["job-deadline-event"],
                "extendedProps" => [
                    "source_type" => "job",
                    "job_id" => (int) $row["id"],
                    "job_no" => $row["job_no"],
                    "request_types" => $rtypes,
                    "status" => $row["approval_status"],
                    "priority" => $row["priority"],
                    "assigned_name" => $row["assigned_name"] ?? "",
                    "assignment_notes" => $row["assignment_notes"] ?? ""
                ]
            ];
        }

    }


    /* Publication submissions by the requestor's preferred date.
       HPU-only source (like job deadlines). Person filter narrows to
       submissions where that person holds a production task. */
    if ($showPubs) {
        $pubSources = [
            "event" => [
                "table" => "publication_event_submissions",
                "title" => "title",
                "label" => "Event / Activity"
            ],
            "recognition" => [
                "table" => "publication_recognition_submissions",
                "title" => "recognized_entity",
                "label" => "Recognition / Milestone"
            ],
            "contribution" => [
                "table" => "publication_content_contributions",
                "title" => "content_title",
                "label" => "Content Contribution"
            ]
        ];

        $taskTableOk = false;
        try {
            $t = $conn->query("SHOW TABLES LIKE 'publication_task_assignments'");
            $taskTableOk = $t && $t->num_rows > 0;
        } catch (Throwable $e) {
            $taskTableOk = false;
        }

        foreach ($pubSources as $pubKind => $pubSource) {
            if (!tableExists($conn, $pubSource["table"])) {
                continue;
            }

            $psql = "SELECT p.id, p.`{$pubSource["title"]}` AS display_title,
                            p.preferred_publication_date, p.status
                     FROM `{$pubSource["table"]}` p
                     WHERE p.preferred_publication_date IS NOT NULL
                       AND p.preferred_publication_date >= ?
                       AND p.preferred_publication_date <= ?";
            $ptypes = "ss";
            $pparams = [$start, $end];

            if ($personId > 0 && $taskTableOk) {
                $psql .= " AND EXISTS (
                               SELECT 1 FROM publication_task_assignments a
                               WHERE a.kind = '" . $conn->real_escape_string($pubKind) . "'
                                 AND a.publication_id = p.id
                                 AND a.assigned_to = ?)";
                $ptypes .= "i";
                $pparams[] = $personId;
            }

            $psql .= " ORDER BY p.preferred_publication_date, p.id";

            try {
                $pstmt = $conn->prepare($psql);
                $pstmt->bind_param($ptypes, ...$pparams);
                $pstmt->execute();
                $prows = $pstmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $pstmt->close();
            } catch (Throwable $e) {
                continue;
            }

            foreach ($prows as $prow) {
                $events[] = [
                    "id" => "pub-" . $pubKind . "-" . (int) $prow["id"],
                    "title" => (string) ($prow["display_title"] ?: $pubSource["label"]),
                    "start" => substr((string) $prow["preferred_publication_date"], 0, 10),
                    "allDay" => true,
                    "backgroundColor" => "#db2777",
                    "borderColor" => "#db2777",
                    "classNames" => ["pub-date-event"],
                    "extendedProps" => [
                        "source_type" => "publication",
                        "pub_kind" => $pubKind,
                        "publication_id" => (int) $prow["id"],
                        "kind_label" => $pubSource["label"],
                        "status" => (string) ($prow["status"] ?? ""),
                        "preferred_date" => substr((string) $prow["preferred_publication_date"], 0, 10),
                        "raw_start" => substr((string) $prow["preferred_publication_date"], 0, 10)
                    ]
                ];
            }
        }
    }

    calJson($events);
}

/* =========================================================
   POST ACTIONS
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["calendar_ajax"])) {
    if (!csrfOk())
        calJson(["success" => false, "message" => "Security token expired. Refresh and try again."], 419);
    $action = trim((string) ($_POST["action"] ?? ""));

    /* Shared activities */
    if (in_array($action, ["shared_create", "shared_update", "shared_delete"], true)) {
        if (!$isHpu)
            calJson(["success" => false, "message" => "Only HPU personnel can manage shared activities."], 403);
        $id = (int) ($_POST["id"] ?? 0);
        if ($action === "shared_delete") {
            $s = $conn->prepare("SELECT created_by FROM hpu_calendar_activities WHERE id=? LIMIT 1");
            $s->bind_param("i", $id);
            $s->execute();
            $row = $s->get_result()->fetch_assoc();
            $s->close();
            if (!$row)
                calJson(["success" => false, "message" => "Activity not found."], 404);
            if (!$isManager && (int) $row["created_by"] !== $userId)
                calJson(["success" => false, "message" => "You can delete only activities you created."], 403);
            $conn->begin_transaction();
            try {
                $s = $conn->prepare("DELETE FROM hpu_calendar_assignees WHERE activity_id=?");
                $s->bind_param("i", $id);
                $s->execute();
                $s->close();
                $s = $conn->prepare("DELETE FROM hpu_calendar_activities WHERE id=?");
                $s->bind_param("i", $id);
                $s->execute();
                $s->close();
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
            calJson(["success" => true, "message" => "Shared activity deleted."]);
        }
        $title = trim((string) ($_POST["title"] ?? ""));
        $type = trim((string) ($_POST["activity_type"] ?? "Other"));
        $scope = trim((string) ($_POST["calendar_scope"] ?? "HPU"));
        $location = trim((string) ($_POST["location"] ?? ""));
        $description = trim((string) ($_POST["description"] ?? ""));
        $allDay = ((string) ($_POST["all_day"] ?? "0") === "1") ? 1 : 0;
        $allowed = ["Meeting", "Training", "Event", "Coverage", "Field Work", "Deadline", "Health Promotion Activity", "Leave / Unavailable", "Other"];
        if (!in_array($type, $allowed, true))
            $type = "Other";
        if (!in_array($scope, ["HPU", "Hospital"], true))
            $scope = "HPU";
        if ($title === "")
            calJson(["success" => false, "message" => "Activity title is required."], 422);

        // Month-long activity: auto-set to 1st and last day of the month
        $monthLong = ((string) ($_POST["month_long"] ?? "0") === "1") ? 1 : 0;

        if ($allDay || $monthLong) {
            $sd = calDate($_POST["start_date"] ?? "");
            // If month-long and no start date provided, default to current month
            if ($monthLong && !$sd) {
                $sd = date("Y-m-01");
            }
            if (!$sd)
                calJson(["success" => false, "message" => "Start date is required."], 422);

            if ($monthLong) {
                // Calculate last day of the month from start date
                $ed = date("Y-m-t", strtotime($sd));
            } else {
                $ed = calDate($_POST["end_date"] ?? "");
                if (!$ed)
                    $ed = $sd;
            }
            if ($ed < $sd)
                calJson(["success" => false, "message" => "End date cannot be earlier than start date."], 422);
            $startDt = $sd . " 00:00:00";
            $endDt = $ed . " 23:59:59";
        } else {
            $startDt = calDateTime($_POST["start_datetime"] ?? "");
            $endDt = calDateTime($_POST["end_datetime"] ?? "");
            if (!$startDt)
                calJson(["success" => false, "message" => "Start date/time is required."], 422);
            if ($endDt && $endDt < $startDt)
                calJson(["success" => false, "message" => "End date/time cannot be earlier than start."], 422);
        }
        $assignees = $_POST["assigned_users"] ?? [];
        if (!is_array($assignees))
            $assignees = [];
        $assignees = array_values(array_unique(array_filter(array_map('intval', $assignees), fn($v) => $v > 0)));
        $loc = $location !== "" ? $location : null;
        $desc = $description !== "" ? $description : null;
        $customColor = trim((string) ($_POST["color"] ?? ""));
        $color = $customColor !== "" ? $customColor : sharedColor($type, $scope);
        if ($action === "shared_create") {
            $conn->begin_transaction();
            try {
                $s = $conn->prepare("INSERT INTO hpu_calendar_activities(title,activity_type,calendar_scope,location,description,start_datetime,end_datetime,all_day,color,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
                $s->bind_param("sssssssisii", $title, $type, $scope, $loc, $desc, $startDt, $endDt, $allDay, $color, $userId, $userId);
                $s->execute();
                $id = $s->insert_id;
                $s->close();
                if ($assignees) {
                    $a = $conn->prepare("INSERT IGNORE INTO hpu_calendar_assignees(activity_id,user_id) VALUES(?,?)");
                    foreach ($assignees as $uid) {
                        $a->bind_param("ii", $id, $uid);
                        $a->execute();
                    }
                    $a->close();
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
            calJson(["success" => true, "message" => $scope . " activity added."]);
        }
        $s = $conn->prepare("SELECT created_by FROM hpu_calendar_activities WHERE id=? LIMIT 1");
        $s->bind_param("i", $id);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$row)
            calJson(["success" => false, "message" => "Activity not found."], 404);
        if (!$isManager && (int) $row["created_by"] !== $userId)
            calJson(["success" => false, "message" => "You can edit only activities you created."], 403);
        $conn->begin_transaction();
        try {
            $customColor = trim((string) ($_POST["color"] ?? ""));
            $color = $customColor !== "" ? $customColor : sharedColor($type, $scope);
            $s = $conn->prepare("UPDATE hpu_calendar_activities SET title=?,activity_type=?,calendar_scope=?,location=?,description=?,start_datetime=?,end_datetime=?,all_day=?,color=?,updated_by=? WHERE id=?");
            $s->bind_param("sssssssisii", $title, $type, $scope, $loc, $desc, $startDt, $endDt, $allDay, $color, $userId, $id);
            $s->execute();
            $s->close();
            $s = $conn->prepare("DELETE FROM hpu_calendar_assignees WHERE activity_id=?");
            $s->bind_param("i", $id);
            $s->execute();
            $s->close();
            if ($assignees) {
                $a = $conn->prepare("INSERT IGNORE INTO hpu_calendar_assignees(activity_id,user_id) VALUES(?,?)");
                foreach ($assignees as $uid) {
                    $a->bind_param("ii", $id, $uid);
                    $a->execute();
                }
                $a->close();
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        calJson(["success" => true, "message" => "Shared activity updated."]);
    }

    /* Personal tasks */
    if (in_array($action, ["personal_create", "personal_update", "personal_delete"], true)) {
        if (!$isHpu)
            calJson(["success" => false, "message" => "Personal Tasks are available only to HPU personnel."], 403);
        $id = (int) ($_POST["id"] ?? 0);
        if ($action === "personal_delete") {
            $s = $conn->prepare("DELETE FROM personal_calendar_tasks WHERE id=? AND user_id=?");
            $s->bind_param("ii", $id, $userId);
            $s->execute();
            $ok = $s->affected_rows > 0;
            $s->close();
            calJson(["success" => $ok, "message" => $ok ? "Personal task deleted." : "Task not found."], $ok ? 200 : 404);
        }
        $title = trim((string) ($_POST["title"] ?? ""));
        $desc = trim((string) ($_POST["description"] ?? ""));
        $priority = trim((string) ($_POST["priority"] ?? "Normal"));
        $status = trim((string) ($_POST["task_status"] ?? "To Do"));
        $allDay = ((string) ($_POST["all_day"] ?? "0") === "1") ? 1 : 0;
        if (!in_array($priority, ["Low", "Normal", "Medium", "High"], true))
            $priority = "Normal";
        if (!in_array($status, ["To Do", "In Progress", "Done", "Cancelled"], true))
            $status = "To Do";
        if ($title === "")
            calJson(["success" => false, "message" => "Task title is required."], 422);
        if ($allDay) {
            $sd = calDate($_POST["start_date"] ?? "");
            $ed = calDate($_POST["end_date"] ?? "");
            if (!$sd)
                calJson(["success" => false, "message" => "Task date is required."], 422);
            if (!$ed)
                $ed = $sd;
            $startDt = $sd . " 00:00:00";
            $endDt = $ed . " 23:59:59";
        } else {
            $startDt = calDateTime($_POST["start_datetime"] ?? "");
            $endDt = calDateTime($_POST["end_datetime"] ?? "");
            if (!$startDt)
                calJson(["success" => false, "message" => "Task start date/time is required."], 422);
        }
        $descDb = $desc !== "" ? $desc : null;
        if ($action === "personal_create") {
            $s = $conn->prepare("INSERT INTO personal_calendar_tasks(user_id,title,description,start_datetime,end_datetime,all_day,priority,task_status) VALUES(?,?,?,?,?,?,?,?)");
            $s->bind_param("issssiss", $userId, $title, $descDb, $startDt, $endDt, $allDay, $priority, $status);
            $s->execute();
            $s->close();
            calJson(["success" => true, "message" => "Personal task added."]);
        }
        $s = $conn->prepare("UPDATE personal_calendar_tasks SET title=?,description=?,start_datetime=?,end_datetime=?,all_day=?,priority=?,task_status=? WHERE id=? AND user_id=?");
        $s->bind_param("ssssissii", $title, $descDb, $startDt, $endDt, $allDay, $priority, $status, $id, $userId);
        $s->execute();
        $s->close();
        calJson(["success" => true, "message" => "Personal task updated."]);
    }

    /* Office activities */
    if (in_array($action, ["office_create", "office_update_user", "office_update_hpu", "office_manage_hpu", "office_delete_admin", "office_delete_user"], true)) {
        $id = (int) ($_POST["id"] ?? 0);
        if ($action === "office_manage_hpu") {
            if (!$isHpu)
                calJson(["success" => false, "message" => "Only HPU personnel can update office activity handling."], 403);
            $status = trim((string) ($_POST["status"] ?? "Under Review"));
            $assigned = (int) ($_POST["assigned_to"] ?? 0);
            $notes = trim((string) ($_POST["hpu_notes"] ?? ""));
            if (!in_array($status, ["Submitted", "Under Review", "Scheduled", "Needs Clarification", "Completed", "Cancelled"], true))
                $status = "Under Review";
            $assignedDb = $assigned > 0 ? $assigned : null;
            $notesDb = $notes !== "" ? $notes : null;
            $s = $conn->prepare("UPDATE office_activity_requests SET status=?,assigned_to=?,hpu_notes=? WHERE id=?");
            $s->bind_param("sisi", $status, $assignedDb, $notesDb, $id);
            $s->execute();
            $s->close();
            calJson(["success" => true, "message" => "Office activity handling updated."]);
        }
        if ($action === "office_delete_user") {
            calJson(
                [
                    "success" => false,
                    "message" => "Only an Administrator can delete an Office Activity / Event submission."
                ],
                403
            );
        }

        if ($action === "office_delete_admin") {
            if (!$isAdmin) {
                calJson(
                    [
                        "success" => false,
                        "message" => "Only an Administrator can delete an Office Activity / Event submission."
                    ],
                    403
                );
            }

            $s = $conn->prepare(
                "SELECT id,activity_title,status
                 FROM office_activity_requests
                 WHERE id=?
                 LIMIT 1"
            );
            $s->bind_param("i", $id);
            $s->execute();
            $row = $s->get_result()->fetch_assoc();
            $s->close();

            if (!$row) {
                calJson(
                    [
                        "success" => false,
                        "message" => "Office Activity / Event submission not found."
                    ],
                    404
                );
            }

            $s = $conn->prepare(
                "DELETE FROM office_activity_requests
                 WHERE id=?"
            );
            $s->bind_param("i", $id);
            $s->execute();
            $deleted = $s->affected_rows;
            $s->close();

            calJson(
                [
                    "success" => $deleted > 0,
                    "message" => $deleted > 0
                        ? "Office Activity / Event submission permanently deleted."
                        : "Unable to delete the Office Activity / Event submission."
                ],
                $deleted > 0 ? 200 : 500
            );
        }
        $office = trim((string) ($_POST["office_name"] ?? "")) !== ""
            ? trim((string) $_POST["office_name"])
            : $department;
        $title = trim((string) ($_POST["activity_title"] ?? ""));
        $venue = trim((string) ($_POST["venue"] ?? ""));
        $desc = trim((string) ($_POST["description"] ?? ""));
        $allDay = ((string) ($_POST["all_day"] ?? "0") === "1") ? 1 : 0;
        $allowedSupports = ["Photo Coverage", "Video Coverage", "Photo/Video Coverage", "Graphic Design", "IEC Materials", "Publication / Social Media", "Documentation", "Calendar Information Only", "Other"];
        $supports = $_POST["support_types"] ?? [];
        if (!is_array($supports))
            $supports = [];
        $supports = array_values(array_unique(array_values(array_filter(array_map('strval', $supports), fn($v) => in_array($v, $allowedSupports, true)))));
        if ($office === "" || $title === "")
            calJson(["success" => false, "message" => "Office/Unit and Activity Title are required."], 422);
        if ($allDay) {
            $sd = calDate($_POST["start_date"] ?? "");
            $ed = calDate($_POST["end_date"] ?? "");
            if (!$sd)
                calJson(["success" => false, "message" => "Activity date is required."], 422);
            if (!$ed)
                $ed = $sd;
            $startDt = $sd . " 00:00:00";
            $endDt = $ed . " 23:59:59";
        } else {
            $startDt = calDateTime($_POST["start_datetime"] ?? "");
            $endDt = calDateTime($_POST["end_datetime"] ?? "");
            if (!$startDt)
                calJson(["success" => false, "message" => "Activity start date/time is required."], 422);
        }
        $venueDb = $venue !== "" ? $venue : null;
        $descDb = $desc !== "" ? $desc : null;
        $supportJson = json_encode($supports, JSON_UNESCAPED_UNICODE);
        if ($action === "office_create") {
            $s = $conn->prepare("INSERT INTO office_activity_requests(submitted_by,office_name,activity_title,venue,description,start_datetime,end_datetime,all_day,support_types,status) VALUES(?,?,?,?,?,?,?,?,?,'Submitted')");
            $s->bind_param("issssssis", $userId, $office, $title, $venueDb, $descDb, $startDt, $endDt, $allDay, $supportJson);
            $s->execute();
            $s->close();
            calJson(["success" => true, "message" => "Office Activity / Event submitted to HPU."]);
        }
        if ($action === "office_update_user") {
            if (!$isUser)
                calJson(["success" => false, "message" => "Invalid action."], 403);
            $s = $conn->prepare("SELECT * FROM office_activity_requests WHERE id=? AND submitted_by=? LIMIT 1");
            $s->bind_param("ii", $id, $userId);
            $s->execute();
            $row = $s->get_result()->fetch_assoc();
            $s->close();
            if (!$row || !userCanEditOffice($row, $userId))
                calJson(["success" => false, "message" => "This office activity can no longer be edited."], 403);
            $s = $conn->prepare("UPDATE office_activity_requests SET office_name=?,activity_title=?,venue=?,description=?,start_datetime=?,end_datetime=?,all_day=?,support_types=?,status='Submitted' WHERE id=? AND submitted_by=?");
            $s->bind_param("ssssssisii", $office, $title, $venueDb, $descDb, $startDt, $endDt, $allDay, $supportJson, $id, $userId);
            $s->execute();
            $s->close();
            calJson(["success" => true, "message" => "Office activity updated and returned to Submitted status."]);
        }
        if ($action === "office_update_hpu") {
            if (!$isHpu)
                calJson(["success" => false, "message" => "Only HPU personnel can update office activities."], 403);
            $s = $conn->prepare("SELECT id,status FROM office_activity_requests WHERE id=? LIMIT 1");
            $s->bind_param("i", $id);
            $s->execute();
            $row = $s->get_result()->fetch_assoc();
            $s->close();
            if (!$row)
                calJson(["success" => false, "message" => "Office Activity / Event submission not found."], 404);
            $s = $conn->prepare("UPDATE office_activity_requests SET office_name=?,activity_title=?,venue=?,description=?,start_datetime=?,end_datetime=?,all_day=?,support_types=? WHERE id=?");
            $s->bind_param("ssssssisi", $office, $title, $venueDb, $descDb, $startDt, $endDt, $allDay, $supportJson, $id);
            $s->execute();
            $s->close();
            calJson(["success" => true, "message" => "Office Activity / Event details updated."]);
        }
    }

    calJson(["success" => false, "message" => "Invalid calendar action."], 400);
}

$activityTypes = ["Meeting", "Training", "Event", "Coverage", "Field Work", "Deadline", "Health Promotion Activity", "Leave / Unavailable", "Other"];
$officeStatuses = ["Submitted", "Under Review", "Scheduled", "Needs Clarification", "Completed", "Cancelled"];
$supportOptions = ["Photo Coverage", "Video Coverage", "Photo/Video Coverage", "Graphic Design", "IEC Materials", "Publication / Social Media", "Documentation", "Calendar Information Only", "Other"];

$personalOpen = 0;
$officePending = 0;
if ($isHpu) {
    $s = $conn->prepare("SELECT COUNT(*) total FROM personal_calendar_tasks WHERE user_id=? AND task_status IN ('To Do','In Progress')");
    $s->bind_param("i", $userId);
    $s->execute();
    $personalOpen = (int) ($s->get_result()->fetch_assoc()["total"] ?? 0);
    $s->close();
    $r = $conn->query("SELECT COUNT(*) total FROM office_activity_requests WHERE status IN ('Submitted','Under Review','Needs Clarification')");
    $officePending = (int) ($r->fetch_assoc()["total"] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar | HPU Workbench</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">
    <style>
        body {
            background: #f4f7fb;
            color: #172033
        }

        .content {
            padding: 18px
        }

        .container-fluid {
            max-width: 1700px;
            margin: auto
        }

        .calendar-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 290px;
            gap: 13px
        }

        .calendar-card,
        .side-card,
        .filter-card {
            background: #fff;
            border: 1px solid #dfe6ef;
            border-radius: 15px;
            box-shadow: 0 8px 24px rgba(28, 42, 72, .05)
        }

        .calendar-card {
            padding: 13px
        }

        .side-card {
            padding: 13px
        }

        .filter-card {
            padding: 9px 11px;
            margin-bottom: 11px
        }

        .filter-label {
            display: block;
            margin-bottom: 3px;
            color: #667085;
            font-size: .58rem;
            font-weight: 850;
            text-transform: uppercase;
            letter-spacing: .04em
        }

        .source-toggle {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px;
            padding: 4px;
            border: 1px solid #e4e7ec;
            border-radius: 9px;
            background: #f8fafc
        }

        .source-check {
            position: relative;
            display: inline-flex
        }

        .source-check input {
            position: absolute;
            opacity: 0;
            pointer-events: none
        }

        .source-check label {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 7px;
            margin: 0;
            border-radius: 7px;
            color: #667085;
            font-size: .65rem;
            font-weight: 850;
            cursor: pointer
        }

        .source-check label:before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #98a2b3
        }

        .source-check input:checked+label {
            background: #fff;
            color: #1d2939;
            box-shadow: 0 1px 3px rgba(16, 24, 40, .12)
        }

        .source-hpu input:checked+label:before {
            background: #1769e0
        }

        .source-hospital input:checked+label:before {
            background: #0f766e
        }

        .source-personal input:checked+label:before {
            background: #64748b
        }

        .source-office input:checked+label:before {
            background: #ea580c
        }

        .source-job input:checked+label:before {
            background: #7c3aed
        }

        .source-pub input:checked+label:before {
            background: #db2777
        }

        .compact-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 9px;
            border-radius: 999px;
            font-size: .63rem;
            font-weight: 850
        }

        .chip-gray {
            background: #f2f4f7;
            color: #475467
        }

        .chip-orange {
            background: #fff7ed;
            color: #c2410c
        }

        .user-view-banner {
            padding: 9px 11px;
            margin-bottom: 10px;
            border-radius: 11px;
            background: #eff8ff;
            color: #175cd3;
            font-size: .69rem
        }

        .fc {
            font-size: .83rem
        }

        .fc .fc-toolbar {
            margin-bottom: 10px !important
        }

        .fc .fc-toolbar-title {
            font-size: 1.06rem;
            font-weight: 850
        }

        .fc .fc-button {
            padding: .33rem .54rem;
            font-size: .72rem;
            border-radius: 8px !important
        }

        .fc .fc-button-primary {
            background: #1769e0;
            border-color: #1769e0
        }

        .fc .fc-col-header-cell {
            background: #f8fafc
        }

        .fc .fc-col-header-cell-cushion {
            padding: 8px 4px;
            color: #475467;
            text-decoration: none;
            font-size: .68rem;
            font-weight: 850;
            text-transform: uppercase
        }

        .fc .fc-daygrid-day-number {
            padding: 5px;
            color: #344054;
            text-decoration: none;
            font-size: .74rem;
            font-weight: 750
        }

        .fc .fc-day-today {
            background: #f5f9ff !important
        }

        .fc-event {
            cursor: pointer;
            border-radius: 6px
        }

        .fc .fc-daygrid-event {
            margin: 1px 3px;
            padding: 2px 4px;
            font-size: .66rem;
            font-weight: 750
        }

        .personal-task-event {
            border-style: dotted !important
        }

        .office-pending,
        .job-deadline-event {
            border-style: dashed !important
        }

        .side-title {
            font-size: .82rem;
            font-weight: 850;
            margin-bottom: 8px
        }

        .legend {
            display: flex;
            flex-direction: column;
            gap: 6px
        }

        .legend-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 7px 0;
            border-bottom: 1px solid #f1f3f5
        }

        .legend-row:last-child {
            border-bottom: 0
        }

        .legend-name {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: .67rem;
            font-weight: 800
        }

        .legend-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%
        }

        .legend-note {
            font-size: .58rem;
            color: #98a2b3
        }

        .assignee-grid,
        .support-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 7px;
            max-height: 190px;
            overflow: auto;
            padding: 8px;
            border: 1px solid #d0d5dd;
            border-radius: 10px;
            background: #f8fafc
        }

        .check-option {
            display: flex;
            align-items: flex-start;
            gap: 7px;
            font-size: .73rem
        }

        .detail-box {
            padding: 10px;
            border: 1px solid #eaecf0;
            border-radius: 10px;
            background: #f8fafc;
            font-size: .71rem
        }

        .detail-row {
            display: grid;
            grid-template-columns: 135px minmax(0, 1fr);
            gap: 8px;
            padding: 4px 0
        }

        .detail-label {
            color: #667085;
            font-weight: 800
        }

        .status-note {
            display: inline-flex;
            padding: 3px 7px;
            border-radius: 999px;
            font-size: .59rem;
            font-weight: 850;
            background: #fffaeb;
            color: #b54708
        }

        .status-note.scheduled {
            background: #fff7ed;
            color: #c2410c
        }

        .status-note.completed {
            background: #ecfdf3;
            color: #027a48
        }

        @media(max-width:1100px) {
            .calendar-layout {
                grid-template-columns: 1fr
            }
        }

        @media(max-width:700px) {
            .content {
                padding: 12px
            }

            .fc .fc-toolbar {
                flex-direction: column;
                gap: 7px
            }

            .assignee-grid,
            .support-grid {
                grid-template-columns: 1fr
            }

            .detail-row {
                grid-template-columns: 1fr;
                gap: 2px
            }
        }

        .side-card {
            position: sticky;
            top: 14px;
            max-height: calc(100vh - 28px);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .side-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 8px
        }

        .side-title-row .side-title {
            margin: 0
        }

        .upcoming-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 24px;
            height: 22px;
            padding: 0 7px;
            border-radius: 999px;
            background: #eff8ff;
            color: #175cd3;
            font-size: .59rem;
            font-weight: 900
        }

        .upcoming-scroll {
            overflow-y: auto;
            min-height: 0;
            padding-right: 2px
        }

        .upcoming-group {
            margin-top: 10px
        }

        .upcoming-group:first-child {
            margin-top: 0
        }

        .upcoming-group-title {
            display: flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 4px;
            color: #667085;
            font-size: .56rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em
        }

        .upcoming-group-title:after {
            content: "";
            flex: 1;
            height: 1px;
            background: #eaecf0
        }

        .upcoming-item {
            display: flex;
            gap: 8px;
            padding: 7px 6px;
            margin-bottom: 4px;
            border: 1px solid transparent;
            border-radius: 9px;
            cursor: pointer;
            transition: .15s ease
        }

        .upcoming-item:hover {
            background: #f8fafc;
            border-color: #eaecf0
        }

        .upcoming-time {
            flex: 0 0 55px;
            color: #475467;
            font-size: .58rem;
            font-weight: 850;
            line-height: 1.3
        }

        .upcoming-info {
            min-width: 0;
            flex: 1
        }

        .upcoming-title {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #1d2939;
            font-size: .67rem;
            font-weight: 850
        }

        .upcoming-meta {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
            margin-top: 2px;
            color: #667085;
            font-size: .56rem
        }

        .upcoming-source-dot {
            width: 7px;
            height: 7px;
            flex: 0 0 7px;
            border-radius: 50%
        }

        .dot-hpu {
            background: #1769e0
        }

        .dot-hospital {
            background: #0f766e
        }

        .dot-personal {
            background: #64748b
        }

        .dot-office {
            background: #ea580c
        }

        .dot-job {
            background: #7c3aed
        }

        .dot-pub {
            background: #db2777
        }

        .upcoming-empty {
            padding: 30px 10px;
            text-align: center;
            color: #667085;
            font-size: .66rem
        }

        .upcoming-empty i {
            display: block;
            margin-bottom: 7px;
            color: #98a2b3;
            font-size: 1.35rem
        }

        @media(max-width:1100px) {
            .side-card {
                position: static;
                max-height: none;
                overflow: visible
            }

            .upcoming-scroll {
                max-height: 360px
            }
        }

        /* ============================================================
           MY CALENDAR — MODERN UI/UX REFRESH (blocked_dates style)
           Override layer: keeps PHP/JS logic intact.
           ============================================================ */

        .ph {
            position: relative;
            overflow: hidden;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        }

        .ph::after {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 3px;
            background: linear-gradient(90deg, #2563eb, #7c3aed, #0ea5e9);
            opacity: .9;
        }

        .ph-title { letter-spacing: -.025em; }

        .compact-chip {
            box-shadow: inset 0 0 0 1px rgba(15, 23, 42, .05);
            font-variant-numeric: tabular-nums;
        }

        .date-chip {
            background: #eff6ff;
            color: #1d4ed8;
        }

        /* Sticky filter bar */
        .filter-card {
            position: sticky;
            top: 0;
            z-index: 30;
            background: #fbfcfe;
        }

        .filter-card .form-control:focus,
        .filter-card .form-select:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
        }

        .source-toggle { transition: border-color .15s ease; }

        .source-check label { transition: background .13s ease, color .13s ease; }

        .source-check label:hover { background: #eef2f7; }

        .source-check input:focus-visible + label {
            outline: 2px solid #93c5fd;
            outline-offset: 2px;
        }

        .sources-allnone {
            display: flex;
            gap: 8px;
            margin-top: 6px;
            font-size: .6rem;
            font-weight: 800;
        }

        .sources-allnone button {
            border: 0;
            background: none;
            padding: 0;
            color: #1d4ed8;
            font: inherit;
            cursor: pointer;
            text-decoration: underline;
            text-underline-offset: 2px;
        }

        .sources-allnone button:hover { color: #1e40af; }

        /* Calendar card */
        .calendar-card {
            overflow: hidden;
            transition: box-shadow .15s ease;
        }

        .fc .fc-toolbar-title {
            letter-spacing: -.02em;
            font-variant-numeric: tabular-nums;
        }

        .fc .fc-button {
            font-weight: 800;
            transition: background .13s ease, border-color .13s ease;
        }

        .fc .fc-button-primary:not(:disabled):hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        .fc .fc-button:focus-visible {
            outline: 2px solid #93c5fd;
            outline-offset: 2px;
        }

        .fc .fc-day-today .fc-daygrid-day-number {
            display: inline-grid;
            place-items: center;
            min-width: 24px;
            height: 24px;
            border-radius: 999px;
            background: #1769e0;
            color: #fff;
        }

        .fc .fc-day-sat,
        .fc .fc-day-sun { background: #f8fafc; }

        .fc-event { transition: filter .12s ease, transform .12s ease; }

        .fc-event:hover {
            filter: brightness(.96);
            box-shadow: 0 3px 10px rgba(15, 23, 42, .16);
        }

        .fc .fc-list-event:hover td { background: #f8fbff; }

        /* ---------- List-view duration UX ---------- */
        .fc-listMonth .fc-list-event.ev-long-week td { background: #fffbeb; }
        .fc-listMonth .fc-list-event.ev-long-week:hover td { background: #fef3c7; }
        .fc-listMonth .fc-list-event.ev-long-month td { background: #eff6ff; }
        .fc-listMonth .fc-list-event.ev-long-month:hover td { background: #dbeafe; }
        .ev-list-main b { font-weight: 800; }
        .ev-dur-badge {
            display: inline-flex;
            align-items: center;
            margin-left: 8px;
            padding: 2px 8px;
            border-radius: 999px;
            background: #eef2f7;
            color: #475569;
            font-size: .6rem;
            font-weight: 850;
            vertical-align: 1px;
            white-space: nowrap;
        }
        .ev-dur-badge.is-week { background: #fef0c7; color: #b54708; }
        .ev-dur-badge.is-month { background: #1d4ed8; color: #fff; }
        .ev-range {
            display: block;
            margin-top: 2px;
            color: #667085;
            font-size: .62rem;
            font-weight: 650;
        }
        .fc-list-day-text, .fc-list-day-side-text { font-weight: 800; }
        .fc-list-day.fc-day-today { background: #f5f9ff; }
        .duration-legend {
            display: flex;
            gap: 6px 14px;
            flex-wrap: wrap;
            margin-top: 8px;
            padding: 8px 10px;
            border: 1px solid #edf1f6;
            border-radius: 10px;
            background: #f8fafc;
            font-size: .6rem;
            font-weight: 750;
            color: #475467;
        }
        .duration-legend span { display: inline-flex; align-items: center; gap: 5px; }
        .duration-legend i { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
        .duration-legend .dot-multi { background: #98a2b3; }
        .duration-legend .dot-week { background: #f59e0b; }
        .duration-legend .dot-month { background: #1d4ed8; }

        .calendar-hint {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 4px 0;
            color: #94a3b8;
            font-size: .6rem;
        }

        /* Side legend */
        .cal-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 12px;
            margin-bottom: 10px;
            padding: 8px 10px;
            border: 1px solid #edf1f6;
            border-radius: 10px;
            background: #f8fafc;
            font-size: .59rem;
            font-weight: 750;
            color: #475467;
        }

        .cal-legend span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .cal-legend i {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            display: inline-block;
        }

        .upcoming-item:focus-visible {
            outline: 2px solid #93c5fd;
            outline-offset: 1px;
        }

        /* Modal icon headers */
        .modal-title i { vertical-align: -1px; }

        .modal-content {
            border: 0;
            border-radius: 16px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, .22);
        }

        .modal-header {
            background: linear-gradient(180deg, #ffffff, #f8fafc);
        }

        /* Back to top */
        #calTopBtn {
            position: fixed;
            right: 18px;
            bottom: 18px;
            z-index: 50;
            width: 40px;
            height: 40px;
            display: none;
            place-items: center;
            border: 1px solid #dbe3ef;
            border-radius: 12px;
            background: #fff;
            color: #1d4ed8;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .14);
        }

        #calTopBtn.show { display: grid; }

        @media print {
            .ph-actions, .filter-card, #calTopBtn { display: none !important; }
            .calendar-layout { grid-template-columns: 1fr; }
            .side-card { position: static; max-height: none; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .001s !important;
                transition-duration: .001s !important;
            }
        }
    </style>
</head>

<body class="app-shell">
    <div class="d-flex min-vh-100">
        <?php include __DIR__ . "/includes/sidebar.php"; ?>
        <div class="main flex-grow-1">
            <main class="content">
                <div class="container-fluid">

                    <div class="ph">
                        <div class="ph-copy">
                            <div class="ph-icon"><i class="bi bi-calendar3"></i></div>
                            <div class="min-w-0">
                                <p class="ph-eyebrow">HPU Workbench</p>
                                <h1 class="ph-title">
                                    <?= $isHpu ? "All HPU Calendar" : "Hospital & HPU Activities Calendar" ?>
                                </h1>
                                <p class="ph-subtitle"><?php if ($isHpu): ?>Shared activities, private tasks, office
                                        activity requests and job deadlines.<?php else: ?>View HPU/Hospital activities
                                        in a read-only calendar.<?php endif; ?></p>
                            </div>
                        </div>
                        <div class="ph-actions">
                            <span class="compact-chip chip-gray date-chip" title="Today"><i
                                    class="bi bi-calendar-date"></i><?= date("M d, Y") ?></span>
                            <?php if ($isHpu): ?>
                                <span class="compact-chip chip-gray"><i class="bi bi-check2-square"></i><?= $personalOpen ?>
                                    personal task(s)</span>
                                <span class="compact-chip chip-orange"><i
                                        class="bi bi-building-exclamation"></i><?= $officePending ?> office
                                    request(s)</span>
                                <button class="btn btn-sm btn-outline-primary" id="addPersonalBtn"><i
                                        class="bi bi-check2-square me-1"></i>Personal Task</button>
                                <button class="btn btn-sm btn-primary" id="addSharedBtn"><i
                                        class="bi bi-plus-lg me-1"></i>Add Activity</button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-warning"
                                id="addOfficeBtn"><i class="bi bi-camera me-1"></i>Office Activity / Event</button>
                        </div>
                    </div>

                    <?php if ($isUser): ?>
                        <div class="user-view-banner"><i class="bi bi-info-circle me-1"></i>HPU and Hospital activities are
                            view-only.</div><?php endif; ?>

                    <div class="filter-card">
                        <div class="row g-2 align-items-end">
                            <?php if ($isHpu): ?>
                                <div class="col-lg-2"><label class="filter-label">Activity Type</label><select
                                        class="form-select form-select-sm" id="typeFilter">
                                        <option value="">All Activity Types</option><?php foreach ($activityTypes as $t): ?>
                                            <option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
                                    </select></div>
                                <div class="col-lg-2"><label class="filter-label">HPU Personnel</label><select
                                        class="form-select form-select-sm" id="personFilter">
                                        <option value="0">All HPU Personnel</option><?php foreach ($hpuPeople as $p): ?>
                                            <option value="<?= (int) $p["id"] ?>"><?= e($p["name"]) ?></option>
                                        <?php endforeach; ?>
                                    </select></div>
                                <div class="col-lg-2"><label class="filter-label">Office Status</label><select
                                        class="form-select form-select-sm" id="officeStatusFilter">
                                        <option value="">All Office Statuses</option>
                                        <?php foreach ($officeStatuses as $s): ?>
                                            <option value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?>
                                    </select></div>
                            <?php endif; ?>
                            <div class="<?= $isHpu ? "col-lg-5" : "col-lg-11" ?>"><label class="filter-label">Calendar
                                    Sources</label>
                                <div class="source-toggle">
                                    <div class="source-check source-hpu"><input type="checkbox" id="showHpu"
                                            checked><label for="showHpu">HPU Activities</label></div>
                                    <div class="source-check source-hospital"><input type="checkbox" id="showHospital"
                                            checked><label for="showHospital">Hospital Activities</label></div>
                                    <?php if ($isHpu): ?>
                                        <div class="source-check source-personal"><input type="checkbox" id="showPersonal"
                                                checked><label for="showPersonal">Personal Tasks</label></div>
                                    <?php endif; ?>
                                    <?php if ($isHpu): ?>
                                    <div class="source-check source-office"><input type="checkbox" id="showOffice"
                                            checked><label
                                            for="showOffice">Office Activities</label>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($isHpu): ?>
                                        <div class="source-check source-job"><input type="checkbox" id="showJobs"
                                                checked><label for="showJobs">Job Deadlines</label></div><?php endif; ?>
                                    <?php if ($isHpu): ?>
                                        <div class="source-check source-pub"><input type="checkbox" id="showPubs"
                                                checked><label for="showPubs">Publication Dates</label></div>
                                    <?php endif; ?>
                                </div>
                                <div class="sources-allnone" aria-label="Toggle all calendar sources">
                                    <button type="button" id="sourcesAll">Select all</button>
                                    <span aria-hidden="true">·</span>
                                    <button type="button" id="sourcesNone">Clear</button>
                                </div>
                            </div>
                            <div class="col-lg-1 d-grid"><button class="btn btn-sm btn-outline-secondary"
                                    id="resetFilters" title="Reset filters"><i
                                        class="bi bi-arrow-counterclockwise"></i></button></div>
                        </div>
                    </div>

                    <div class="calendar-layout">
                        <div class="calendar-card">
                            <div id="calendar"></div>
                            <div class="calendar-hint">
                                <i class="bi bi-cursor"></i>
                                <span>Click a date to add an entry · Click an event for details · Drag to scroll on mobile</span>
                            </div>
                            <div class="duration-legend" aria-label="Event duration legend">
                                <span><i class="dot-multi"></i>2–6 days</span>
                                <span><i class="dot-week"></i>Week or more (7+ days)</span>
                                <span><i class="dot-month"></i>Month-long (28+ days)</span>
                            </div>
                        </div>
                        <aside class="side-card">
                            <div class="side-title-row">
                                <div class="side-title"><i class="bi bi-clock me-1 text-primary"></i>Upcoming Events
                                </div>
                                <span class="upcoming-count" id="upcomingCount">0</span>
                            </div>
                            <div class="cal-legend" aria-label="Calendar source colors">
                                <span><i style="background:#1769e0"></i>HPU</span>
                                <span><i style="background:#0f766e"></i>Hospital</span>
                                <?php if ($isHpu): ?>
                                    <span><i style="background:#64748b"></i>Personal</span>
                                    <span><i style="background:#ea580c"></i>Office</span>
                                    <span><i style="background:#7c3aed"></i>Jobs</span>
                                    <span><i style="background:#db2777"></i>Publications</span>
                                <?php endif; ?>
                            </div>
                            <div class="upcoming-scroll" id="upcomingList">
                                <div class="upcoming-empty"><i class="bi bi-calendar2-check"></i>Loading upcoming
                                    events...</div>
                            </div>
                        </aside>
                    </div>

                </div>
            </main>
        </div>
    </div>

    <?php if ($isHpu): ?>
        <!-- Shared modal -->
        <div class="modal fade" id="sharedModal" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <form id="sharedForm">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title fw-bold"><i class="bi bi-calendar-plus text-primary me-1"></i>HPU / Hospital Activity</h5>
                                <div class="small text-muted">Shared operational calendar activity.</div>
                            </div><button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body"><input type="hidden" name="calendar_ajax" value="1"><input type="hidden"
                                name="action" id="sharedAction" value="shared_create"><input type="hidden" name="id"
                                id="sharedId"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input
                                type="hidden" name="all_day" id="sharedAllDayValue" value="0">
                            <div class="row g-3">
                                <div class="col-md-8"><label class="form-label fw-bold">Activity Title *</label><input
                                        class="form-control" name="title" id="sharedTitle" required></div>
                                <div class="col-md-4"><label class="form-label fw-bold">Calendar Source</label><select
                                        class="form-select" name="calendar_scope" id="sharedScope">
                                        <option value="HPU">HPU Activity</option>
                                        <option value="Hospital">Hospital Activity</option>
                                    </select></div>
                                <div class="col-md-4"><label class="form-label fw-bold">Activity Type</label><select
                                        class="form-select" name="activity_type"
                                        id="sharedType"><?php foreach ($activityTypes as $t): ?>
                                            <option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
                                    </select></div>
                                <div class="col-md-8"><label class="form-label fw-bold">Location / Venue</label><input
                                        class="form-control" name="location" id="sharedLocation"></div>
                                <div class="col-12"><label class="form-label fw-bold">Assigned HPU Personnel</label>
                                    <div class="assignee-grid"><?php foreach ($hpuPeople as $p): ?><label
                                                class="check-option"><input class="form-check-input sharedAssignee"
                                                    type="checkbox" name="assigned_users[]"
                                                    value="<?= (int) $p["id"] ?>"><span><?= e($p["name"]) ?> <small
                                                        class="text-muted">(<?= e(ucfirst($p["role"])) ?>)</small></span></label><?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox"
                                            id="sharedAllDay"><label class="form-check-label fw-bold"
                                            for="sharedAllDay">All-day activity</label></div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox"
                                            name="month_long" id="sharedMonthLong"><label class="form-check-label fw-bold"
                                            for="sharedMonthLong">Month-long activity (auto-fills 1st to last day)</label></div>
                                </div>
                                <div class="col-md-6 sharedTimed"><label class="form-label fw-bold">Start Date & Time
                                        *</label><input class="form-control" type="datetime-local" name="start_datetime"
                                        id="sharedStartDt"></div>
                                <div class="col-md-6 sharedTimed"><label class="form-label fw-bold">End Date &
                                        Time</label><input class="form-control" type="datetime-local" name="end_datetime"
                                        id="sharedEndDt"></div>
                                <div class="col-md-6 sharedAll d-none"><label class="form-label fw-bold">Start Date
                                        *</label><input class="form-control" type="date" name="start_date"
                                        id="sharedStartDate"></div>
                                <div class="col-md-6 sharedAll d-none"><label class="form-label fw-bold">End
                                        Date</label><input class="form-control" type="date" name="end_date"
                                        id="sharedEndDate"></div>
                                <div class="col-12"><label class="form-label fw-bold">Description / Notes</label><textarea
                                        class="form-control" name="description" id="sharedDesc" rows="3"></textarea></div>
                                <div class="col-md-6"><label class="form-label fw-bold">Event Color</label><input
                                        class="form-control form-control-color" type="color" name="color" id="sharedColor"
                                        value="#2563eb" title="Choose a custom color for this activity"></div>
                            </div>
                        </div>
                        <div class="modal-footer"><button class="btn btn-outline-danger me-auto d-none" type="button"
                                id="deleteShared">Delete</button><button class="btn btn-light" type="button"
                                data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit"
                                id="saveShared">Save Activity</button></div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Personal modal -->
        <div class="modal fade" id="personalModal" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <form id="personalForm">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title fw-bold"><i class="bi bi-check2-square text-secondary me-1"></i>Personal Task</h5>
                                <div class="small text-muted">Private to your account.</div>
                            </div><button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body"><input type="hidden" name="calendar_ajax" value="1"><input type="hidden"
                                name="action" id="personalAction" value="personal_create"><input type="hidden" name="id"
                                id="personalId"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input
                                type="hidden" name="all_day" id="personalAllDayValue" value="0">
                            <div class="row g-3">
                                <div class="col-md-8"><label class="form-label fw-bold">Task Title *</label><input
                                        class="form-control" name="title" id="personalTitle" required></div>
                                <div class="col-md-4"><label class="form-label fw-bold">Priority</label><select
                                        class="form-select" name="priority" id="personalPriority">
                                        <option>Low</option>
                                        <option selected>Normal</option>
                                        <option>Medium</option>
                                        <option>High</option>
                                    </select></div>
                                <div class="col-md-4"><label class="form-label fw-bold">Status</label><select
                                        class="form-select" name="task_status" id="personalStatus">
                                        <option>To Do</option>
                                        <option>In Progress</option>
                                        <option>Done</option>
                                        <option>Cancelled</option>
                                    </select></div>
                                <div class="col-md-8 d-flex align-items-end">
                                    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox"
                                            id="personalAllDay"><label class="form-check-label fw-bold"
                                            for="personalAllDay">All-day task</label></div>
                                </div>
                                <div class="col-md-6 personalTimed"><label class="form-label fw-bold">Start Date & Time
                                        *</label><input class="form-control" type="datetime-local" name="start_datetime"
                                        id="personalStartDt"></div>
                                <div class="col-md-6 personalTimed"><label class="form-label fw-bold">End Date &
                                        Time</label><input class="form-control" type="datetime-local" name="end_datetime"
                                        id="personalEndDt"></div>
                                <div class="col-md-6 personalAll d-none"><label class="form-label fw-bold">Start Date
                                        *</label><input class="form-control" type="date" name="start_date"
                                        id="personalStartDate"></div>
                                <div class="col-md-6 personalAll d-none"><label class="form-label fw-bold">End
                                        Date</label><input class="form-control" type="date" name="end_date"
                                        id="personalEndDate"></div>
                                <div class="col-12"><label class="form-label fw-bold">Notes</label><textarea
                                        class="form-control" name="description" id="personalDesc" rows="3"></textarea></div>
                            </div>
                        </div>
                        <div class="modal-footer"><button class="btn btn-outline-danger me-auto d-none" type="button"
                                id="deletePersonal">Delete</button><button class="btn btn-light" type="button"
                                data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save
                                Task</button></div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Office Activity modal for all roles -->
    <div class="modal fade" id="officeModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form id="officeForm">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title fw-bold"><i class="bi bi-camera text-warning me-1"></i>Office Activity / Event for HPU Coverage</h5>
                            <div class="small text-muted">Use this for photo/video coverage, documentation, publication,
                                design, IEC or calendar information.</div>
                        </div><button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body"><input type="hidden" name="calendar_ajax" value="1"><input type="hidden"
                            name="action" id="officeAction" value="office_create"><input type="hidden" name="id"
                            id="officeId"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input
                            type="hidden" name="all_day" id="officeAllDayValue" value="0">
                        <div class="row g-3">
                            <div class="col-md-5"><label class="form-label fw-bold">Office / Unit *</label><input
                                    class="form-control" name="office_name" id="officeName"
                                    value="<?= e($department) ?>" required></div>
                            <div class="col-md-7"><label class="form-label fw-bold">Activity / Event Title
                                    *</label><input class="form-control" name="activity_title" id="officeTitle"
                                    required></div>
                            <div class="col-md-6"><label class="form-label fw-bold">Venue</label><input
                                    class="form-control" name="venue" id="officeVenue"></div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox"
                                        id="officeAllDay"><label class="form-check-label fw-bold"
                                        for="officeAllDay">All-day activity</label></div>
                            </div>
                            <div class="col-md-6 officeTimed"><label class="form-label fw-bold">Start Date & Time
                                    *</label><input class="form-control" type="datetime-local" name="start_datetime"
                                    id="officeStartDt"></div>
                            <div class="col-md-6 officeTimed"><label class="form-label fw-bold">End Date &
                                    Time</label><input class="form-control" type="datetime-local" name="end_datetime"
                                    id="officeEndDt"></div>
                            <div class="col-md-6 officeAll d-none"><label class="form-label fw-bold">Start Date
                                    *</label><input class="form-control" type="date" name="start_date"
                                    id="officeStartDate"></div>
                            <div class="col-md-6 officeAll d-none"><label class="form-label fw-bold">End
                                    Date</label><input class="form-control" type="date" name="end_date"
                                    id="officeEndDate"></div>
                            <div class="col-12"><label class="form-label fw-bold">HPU Support Requested</label>
                                <div class="support-grid"><?php foreach ($supportOptions as $s): ?><label
                                            class="check-option"><input class="form-check-input officeSupport"
                                                type="checkbox" name="support_types[]"
                                                value="<?= e($s) ?>"><span><?= e($s) ?></span></label><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12"><label class="form-label fw-bold">Activity Details /
                                    Notes</label><textarea class="form-control" name="description" id="officeDesc"
                                    rows="3"></textarea></div>
                            <div class="col-12 d-none" id="officeStatusBox">
                                <div class="detail-box">
                                    <div class="detail-row">
                                        <div class="detail-label">Current Status</div>
                                        <div id="officeStatusText"></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Assigned HPU</div>
                                        <div id="officeAssignedText"></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">HPU Notes</div>
                                        <div id="officeHpuNotesText"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer"><button class="btn btn-light" type="button"
                            data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit"
                            id="saveOffice">Submit to HPU</button></div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($isHpu): ?>
        <!-- HPU office management modal -->
        <div class="modal fade" id="officeManageModal" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <form id="officeManageForm">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title fw-bold"><i class="bi bi-clipboard-check text-primary me-1"></i>Office Activity / Event</h5>
                                <div class="small text-muted">Review HPU coverage or documentation handling.</div>
                            </div><button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body"><input type="hidden" name="calendar_ajax" value="1"><input type="hidden"
                                name="action" value="office_manage_hpu"><input type="hidden" name="id"
                                id="manageOfficeId"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <div class="detail-box mb-3">
                                <div class="detail-row">
                                    <div class="detail-label">Office / Unit</div>
                                    <div id="manageOfficeName"></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Activity</div>
                                    <div id="manageOfficeTitle"></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Date / Time</div>
                                    <div id="manageOfficeDate"></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Venue</div>
                                    <div id="manageOfficeVenue"></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">HPU Support</div>
                                    <div id="manageOfficeSupport"></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Submitted By</div>
                                    <div id="manageOfficeBy"></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Details</div>
                                    <div id="manageOfficeDesc"></div>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label fw-bold">Status</label><select
                                        class="form-select" name="status"
                                        id="manageOfficeStatus"><?php foreach ($officeStatuses as $s): ?>
                                            <option value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?>
                                    </select></div>
                                <div class="col-md-8"><label class="form-label fw-bold">Assigned HPU
                                        Personnel</label><select class="form-select" name="assigned_to"
                                        id="manageOfficeAssigned">
                                        <option value="0">— Not yet assigned —</option><?php foreach ($hpuPeople as $p): ?>
                                            <option value="<?= (int) $p["id"] ?>"><?= e($p["name"]) ?></option>
                                        <?php endforeach; ?>
                                    </select></div>
                                <div class="col-12"><label class="form-label fw-bold">HPU Notes /
                                        Instructions</label><textarea class="form-control" name="hpu_notes"
                                        id="manageOfficeNotes" rows="3"></textarea></div>
                            </div>
                        </div>
                        <div class="modal-footer"><?php if ($isAdmin): ?><button class="btn btn-outline-danger me-auto"
                                    type="button" id="adminDeleteOffice"><i class="bi bi-trash me-1"></i>Delete
                                    Submission</button><?php endif; ?><button class="btn btn-outline-primary <?php if (!$isAdmin): ?>me-auto<?php endif; ?>" type="button"
                                id="editOfficeDetails"><i class="bi bi-pencil-square me-1"></i>Edit Details</button><button class="btn btn-light" type="button"
                                data-bs-dismiss="modal">Close</button><button class="btn btn-primary" type="submit">Update
                                Handling</button></div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Generic detail modal -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" id="detailTitle"><i class="bi bi-info-circle text-primary me-1"></i>Calendar Item</h5>
                        <div class="small text-muted" id="detailSubtitle"></div>
                    </div><button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="detail-box" id="detailBody"></div>
                </div>
                <div class="modal-footer"><button class="btn btn-light" type="button"
                        data-bs-dismiss="modal">Close</button><a class="btn btn-primary d-none" href="#"
                        id="detailAction">Open</a></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const baseUrl = <?= json_encode(rtrim(BASE_URL, "/")) ?>, csrf = <?= json_encode($csrf) ?>, isHpu = <?= $isHpu ? "true" : "false" ?>, isUser = <?= $isUser ? "true" : "false" ?>, isAdmin = <?= $isAdmin ? "true" : "false" ?>, defaultOffice = <?= json_encode($department) ?>;
            const q = id => document.getElementById(id), fmt = v => v ? v.replace(" ", "T").slice(0, 16) : "";
            async function send(fd) { const r = await fetch(baseUrl + "/my_calendar.php", { method: "POST", body: fd, credentials: "same-origin" }); let d; try { d = await r.json() } catch (e) { throw new Error("Invalid server response.") } if (!r.ok || !d.success) throw new Error(d.message || "Unable to process calendar action."); return d }
            function toggle(allDay, allVal, timedSel, allSel, startDt, startDate) { allVal.value = allDay.checked ? "1" : "0"; document.querySelectorAll(timedSel).forEach(el => el.classList.toggle("d-none", allDay.checked)); document.querySelectorAll(allSel).forEach(el => el.classList.toggle("d-none", !allDay.checked)); startDt.required = !allDay.checked; startDate.required = allDay.checked }

            /* Shared */
            let sharedModal;
            if (isHpu) {
                sharedModal = new bootstrap.Modal(q("sharedModal"));
                const f = q("sharedForm"),
                    all = q("sharedAllDay"),
                    monthLong = q("sharedMonthLong"),
                    allVal = q("sharedAllDayValue");

                const tog = () => {
                    toggle(all, allVal, ".sharedTimed", ".sharedAll", q("sharedStartDt"), q("sharedStartDate"));
                    // If month-long is checked, force all-day and set dates to month range
                    if (monthLong.checked) {
                        all.checked = true;
                        allVal.value = "1";
                        document.querySelectorAll(".sharedTimed").forEach(el => el.classList.add("d-none"));
                        document.querySelectorAll(".sharedAll").forEach(el => el.classList.remove("d-none"));
                        q("sharedStartDt").required = false;
                        q("sharedStartDate").required = true;
                        // Set to 1st and last day of current month
                        const now = new Date();
                        const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
                        const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                        q("sharedStartDate").value = firstDay.toISOString().slice(0, 10);
                        q("sharedEndDate").value = lastDay.toISOString().slice(0, 10);
                    }
                };
                all.addEventListener("change", tog);
                monthLong.addEventListener("change", tog);

                function resetShared() {
                    f.reset();
                    q("sharedAction").value = "shared_create";
                    q("sharedId").value = "";
                    q("sharedScope").value = "HPU";
                    q("sharedType").value = "Meeting";
                    q("sharedColor").value = "#2563eb";
                    all.checked = false;
                    monthLong.checked = false;
                    document.querySelectorAll(".sharedAssignee").forEach(x => x.checked = false);
                    q("deleteShared").classList.add("d-none");
                    q("saveShared").classList.remove("d-none");
                    f.querySelectorAll("input,select,textarea").forEach(x => x.disabled = false);
                    tog()
                }
                q("addSharedBtn").onclick = () => { resetShared(); sharedModal.show() };
                f.onsubmit = async e => { e.preventDefault(); try { const d = await send(new FormData(f)); sharedModal.hide(); alert(d.message); location.reload() } catch (err) { alert(err.message) } };
                q("deleteShared").onclick = async () => { if (!q("sharedId").value || !confirm("Delete this shared activity?")) return; const fd = new FormData(); fd.append("calendar_ajax", "1"); fd.append("action", "shared_delete"); fd.append("id", q("sharedId").value); fd.append("csrf_token", csrf); try { const d = await send(fd); sharedModal.hide(); alert(d.message); location.reload() } catch (err) { alert(err.message) } };
                window.openShared = ev => { resetShared(); const p = ev.extendedProps || {}; q("sharedAction").value = "shared_update"; q("sharedId").value = p.activity_id || ""; q("sharedTitle").value = ev.title || ""; q("sharedScope").value = p.calendar_scope || "HPU"; q("sharedType").value = p.activity_type || "Other"; q("sharedLocation").value = p.location || ""; q("sharedDesc").value = p.description || ""; q("sharedColor").value = ev.backgroundColor || "#2563eb"; all.checked = !!ev.allDay; monthLong.checked = false; if (ev.allDay) { q("sharedStartDate").value = (p.raw_start || "").slice(0, 10); q("sharedEndDate").value = (p.raw_end || p.raw_start || "").slice(0, 10) } else { q("sharedStartDt").value = fmt(p.raw_start); q("sharedEndDt").value = fmt(p.raw_end) }; const ids = (p.assignee_ids || []).map(String); document.querySelectorAll(".sharedAssignee").forEach(x => x.checked = ids.includes(x.value)); tog(); if (p.can_edit) { q("deleteShared").classList.remove("d-none") } else { f.querySelectorAll("input,select,textarea").forEach(x => { if (!["calendar_ajax", "action", "id", "csrf_token"].includes(x.name)) x.disabled = true }); q("saveShared").classList.add("d-none") }; sharedModal.show() }
            }

            /* Personal */
            let personalModal;
            if (isHpu) { personalModal = new bootstrap.Modal(q("personalModal")); const f = q("personalForm"), all = q("personalAllDay"); const tog = () => toggle(all, q("personalAllDayValue"), ".personalTimed", ".personalAll", q("personalStartDt"), q("personalStartDate")); all.addEventListener("change", tog); function resetPersonal() { f.reset(); q("personalAction").value = "personal_create"; q("personalId").value = ""; q("personalPriority").value = "Normal"; q("personalStatus").value = "To Do"; all.checked = false; q("deletePersonal").classList.add("d-none"); tog() } q("addPersonalBtn").onclick = () => { resetPersonal(); personalModal.show() }; f.onsubmit = async e => { e.preventDefault(); try { const d = await send(new FormData(f)); personalModal.hide(); alert(d.message); location.reload() } catch (err) { alert(err.message) } }; q("deletePersonal").onclick = async () => { if (!q("personalId").value || !confirm("Delete this personal task?")) return; const fd = new FormData(); fd.append("calendar_ajax", "1"); fd.append("action", "personal_delete"); fd.append("id", q("personalId").value); fd.append("csrf_token", csrf); try { const d = await send(fd); personalModal.hide(); alert(d.message); location.reload() } catch (err) { alert(err.message) } }; window.openPersonal = ev => { resetPersonal(); const p = ev.extendedProps || {}; q("personalAction").value = "personal_update"; q("personalId").value = p.task_id || ""; q("personalTitle").value = (ev.title || "").replace(/^✓\s*/, ""); q("personalDesc").value = p.description || ""; q("personalPriority").value = p.priority || "Normal"; q("personalStatus").value = p.task_status || "To Do"; all.checked = !!ev.allDay; if (ev.allDay) { q("personalStartDate").value = (p.raw_start || "").slice(0, 10); q("personalEndDate").value = (p.raw_end || p.raw_start || "").slice(0, 10) } else { q("personalStartDt").value = fmt(p.raw_start); q("personalEndDt").value = fmt(p.raw_end) }; q("deletePersonal").classList.remove("d-none"); tog(); personalModal.show() } }

            /* Office */
            const officeModal = new bootstrap.Modal(q("officeModal")), officeForm = q("officeForm"), officeAll = q("officeAllDay"); const officeToggle = () => toggle(officeAll, q("officeAllDayValue"), ".officeTimed", ".officeAll", q("officeStartDt"), q("officeStartDate")); officeAll.addEventListener("change", officeToggle);
            function clearSupports() { document.querySelectorAll(".officeSupport").forEach(x => x.checked = false) } function resetOffice() { officeForm.reset(); q("officeAction").value = "office_create"; q("officeId").value = ""; q("officeName").value = defaultOffice; officeAll.checked = false; clearSupports(); q("officeStatusBox").classList.add("d-none"); officeForm.querySelectorAll("input,textarea").forEach(x => x.disabled = false); q("saveOffice").classList.remove("d-none"); q("saveOffice").textContent = "Submit to HPU"; officeToggle() }
            if (q("addOfficeBtn")) q("addOfficeBtn").onclick = () => { resetOffice(); officeModal.show() }; officeForm.onsubmit = async e => { e.preventDefault(); try { const d = await send(new FormData(officeForm)); officeModal.hide(); alert(d.message); location.reload() } catch (err) { alert(err.message) } };
            /* Deep link: ?new=office opens a blank Office Activity submission (used by the dashboard quick action). */
            try { if (new URLSearchParams(window.location.search).get("new") === "office" && q("addOfficeBtn")) { resetOffice(); officeModal.show(); } } catch (e) {}

            window.openOfficeUser = ev => { resetOffice(); const p = ev.extendedProps || {}; q("officeId").value = p.office_id || ""; q("officeName").value = p.office_name || ""; q("officeTitle").value = ev.title || ""; q("officeVenue").value = p.venue || ""; q("officeDesc").value = p.description || ""; officeAll.checked = !!ev.allDay; if (ev.allDay) { q("officeStartDate").value = (p.raw_start || "").slice(0, 10); q("officeEndDate").value = (p.raw_end || p.raw_start || "").slice(0, 10) } else { q("officeStartDt").value = fmt(p.raw_start); q("officeEndDt").value = fmt(p.raw_end) }; const supports = p.support_types || []; document.querySelectorAll(".officeSupport").forEach(x => x.checked = supports.includes(x.value)); q("officeStatusText").textContent = p.status || "Submitted"; q("officeAssignedText").textContent = p.assigned_name || "Not yet assigned"; q("officeHpuNotesText").textContent = p.hpu_notes || "No HPU notes yet."; q("officeStatusBox").classList.remove("d-none"); const dateRangeEl = document.createElement("div"); dateRangeEl.className = "detail-row"; dateRangeEl.innerHTML = `<div class="detail-label">Date Range</div><div>${dateRangeLabel(ev) || "—"}</div>`; const statusBox = q("officeStatusBox").querySelector(".detail-box"); if (statusBox && !Array.from(statusBox.querySelectorAll(".detail-label")).some(el => el.textContent === "Date Range")) { statusBox.appendChild(dateRangeEl) } if (p.can_user_edit) { q("officeAction").value = "office_update_user"; q("saveOffice").textContent = "Update Submission" } else { officeForm.querySelectorAll("input,textarea").forEach(x => { if (!["calendar_ajax", "action", "id", "csrf_token"].includes(x.name)) x.disabled = true }); q("saveOffice").classList.add("d-none") }; officeToggle(); officeModal.show() };

            /* HPU manage office */
            let officeManageModal;
            if (isHpu) {
                officeManageModal = new bootstrap.Modal(q("officeManageModal"));

                q("officeManageForm").onsubmit = async e => {
                    e.preventDefault();
                    try {
                        const d = await send(new FormData(q("officeManageForm")));
                        officeManageModal.hide();
                        alert(d.message);
                        location.reload()
                    } catch (err) {
                        alert(err.message)
                    }
                };

                if (isAdmin && q("adminDeleteOffice")) {
                    q("adminDeleteOffice").onclick = async () => {
                        const officeId = q("manageOfficeId").value;

                        if (
                            !officeId
                            ||
                            !confirm(
                                "Permanently delete this Office Activity / Event submission? This action cannot be undone."
                            )
                        ) {
                            return;
                        }

                        const fd = new FormData();
                        fd.append("calendar_ajax", "1");
                        fd.append("action", "office_delete_admin");
                        fd.append("id", officeId);
                        fd.append("csrf_token", csrf);

                        try {
                            const d = await send(fd);
                            officeManageModal.hide();
                            alert(d.message);
                            location.reload()
                        } catch (err) {
                            alert(err.message)
                        }
                    };
                }

                window.openOfficeHpu = ev => {
                    const p = ev.extendedProps || {};
                    window._hpuOfficeEvent = ev;
                    q("manageOfficeId").value = p.office_id || "";
                    q("manageOfficeName").textContent = p.office_name || "—";
                    q("manageOfficeTitle").textContent = ev.title || "—";
                    q("manageOfficeDate").textContent = dateRangeLabel(ev) || "—";
                    q("manageOfficeVenue").textContent = p.venue || "—";
                    q("manageOfficeSupport").textContent = (p.support_types || []).join(", ") || "Calendar Information Only";
                    q("manageOfficeBy").textContent = p.submitted_by_name || "—";
                    q("manageOfficeDesc").textContent = p.description || "—";
                    q("manageOfficeStatus").value = p.status || "Submitted";
                    q("manageOfficeAssigned").value = String(p.assigned_to || 0);
                    q("manageOfficeNotes").value = p.hpu_notes || "";
                    officeManageModal.show()
                }

                /* HPU Staff / Supervisor: edit full office activity details */
                window.openOfficeHpuEdit = ev => {
                    const p = ev.extendedProps || {};
                    resetOffice();
                    q("officeAction").value = "office_update_hpu";
                    q("officeId").value = p.office_id || "";
                    q("officeName").value = p.office_name || "";
                    q("officeTitle").value = ev.title || "";
                    q("officeVenue").value = p.venue || "";
                    q("officeDesc").value = p.description || "";
                    officeAll.checked = !!ev.allDay;
                    if (ev.allDay) {
                        q("officeStartDate").value = (p.raw_start || "").slice(0, 10);
                        q("officeEndDate").value = (p.raw_end || p.raw_start || "").slice(0, 10);
                    } else {
                        q("officeStartDt").value = fmt(p.raw_start);
                        q("officeEndDt").value = fmt(p.raw_end);
                    }
                    const supports = p.support_types || [];
                    document.querySelectorAll(".officeSupport").forEach(x => x.checked = supports.includes(x.value));
                    q("officeStatusBox").classList.add("d-none");
                    q("saveOffice").classList.remove("d-none");
                    q("saveOffice").textContent = "Save Changes";
                    officeToggle();
                    officeModal.show();
                }

                if (q("editOfficeDetails")) q("editOfficeDetails").onclick = () => {
                    const ev = window._hpuOfficeEvent;
                    if (!ev) return;
                    officeManageModal.hide();
                    setTimeout(() => window.openOfficeHpuEdit(ev), 200);
                };
            }

            /* Detail modal */
            const detailModal = new bootstrap.Modal(q("detailModal")); function showDetail(title, subtitle, rows, actionUrl = "") { q("detailTitle").textContent = title; q("detailSubtitle").textContent = subtitle; q("detailBody").innerHTML = rows.map(r => `<div class="detail-row"><div class="detail-label">${r[0]}</div><div>${r[1] || "—"}</div></div>`).join(""); const a = q("detailAction"); if (actionUrl) { a.href = actionUrl; a.classList.remove("d-none") } else a.classList.add("d-none"); detailModal.show() }

            /* Calendar */
            const showHpu = q("showHpu"), showHospital = q("showHospital"), showOffice = q("showOffice"), showPersonal = q("showPersonal"), showJobs = q("showJobs"), showPubs = q("showPubs"), typeFilter = q("typeFilter"), personFilter = q("personFilter"), officeStatusFilter = q("officeStatusFilter");
            const upcomingList = q("upcomingList"), upcomingCount = q("upcomingCount");

            function sourceMeta(ev) {
                const p = ev.extendedProps || {};
                if (p.source_type === "activity") {
                    return (p.calendar_scope || "HPU") === "Hospital"
                        ? { label: "Hospital", dot: "dot-hospital" }
                        : { label: "HPU", dot: "dot-hpu" };
                }
                if (p.source_type === "personal") return { label: "Personal", dot: "dot-personal" };
                if (p.source_type === "office") return { label: "Office Activity", dot: "dot-office" };
                if (p.source_type === "job") return { label: "Job Deadline", dot: "dot-job" };
                if (p.source_type === "publication") return { label: "Publication Date", dot: "dot-pub" };
                return { label: "Activity", dot: "dot-hpu" };
            }
            function startOfDay(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()) }
            function groupLabel(d) {
                const t = startOfDay(new Date()), x = startOfDay(d), diff = Math.round((x - t) / 86400000);
                if (diff === 0) return "Today";
                if (diff === 1) return "Tomorrow";
                return d.toLocaleDateString(undefined, { month: "short", day: "numeric", year: d.getFullYear() !== t.getFullYear() ? "numeric" : undefined });
            }
            function timeLabel(ev) {
                if (ev.allDay) return "All day";
                return ev.start ? ev.start.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" }) : "";
            }
            function dateRangeLabel(ev) {
                const p = ev.extendedProps || {};
                const rawStart = p.raw_start;
                const rawEnd = p.raw_end;
                if (!rawStart) return "";
                const start = new Date(rawStart.replace(" ", "T"));
                const end = rawEnd ? new Date(rawEnd.replace(" ", "T")) : start;
                const startStr = start.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
                const endStr = end.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
                if (startStr === endStr) return startStr;
                return startStr + " \u2013 " + endStr;
            }
            function activateCalendarEvent(ev) {
                const p = ev.extendedProps || {};
                const dateRange = dateRangeLabel(ev);
                if (p.source_type === "activity") {
                    if (isHpu) window.openShared(ev);
                    else showDetail(ev.title, (p.calendar_scope || "HPU") + " Activity", [["Type", p.activity_type], ["Venue", p.location], ["Date Range", dateRange], ["Assigned HPU", (p.assignees || []).join(", ")], ["Details", p.description]]);
                } else if (p.source_type === "personal" && isHpu) {
                    window.openPersonal(ev);
                } else if (p.source_type === "office") {
                    if (isHpu) window.openOfficeHpu(ev); else window.openOfficeUser(ev);
                } else if (p.source_type === "job" && isHpu) {
                    showDetail(
                        ev.title,
                        "Job Request Deadline",
                        [
                            ["Request Type", (p.request_types || []).join(", ")],
                            ["Assigned To", p.assigned_name],
                            ["Priority", p.priority],
                            ["Status", p.status],
                            ["Deadline", dateRange],
                            ["Instructions", p.assignment_notes]
                        ],
                        baseUrl + "/staff/review_request.php?id=" + p.job_id
                    );
                } else if (p.source_type === "publication" && isHpu) {
                    showDetail(
                        ev.title,
                        "Publication Preferred Date",
                        [
                            ["Submission Type", p.kind_label],
                            ["Preferred Date", dateRange],
                            ["Status", p.status]
                        ],
                        baseUrl + "/staff/publication_requests.php?view=" + encodeURIComponent(p.pub_kind || "") + "&id=" + (p.publication_id || 0)
                    );
                }
            }
            function renderUpcoming(cal) {
                if (!upcomingList || !upcomingCount) return;
                const today = startOfDay(new Date());
                let events = cal.getEvents().filter(ev => {
                    if (!ev.start) return false;
                    const end = ev.end || ev.start;
                    return end >= today;
                }).sort((a, b) => a.start - b.start).slice(0, 12);

                upcomingCount.textContent = String(events.length);

                if (!events.length) {
                    upcomingList.innerHTML = '<div class="upcoming-empty"><i class="bi bi-calendar2-check"></i>No upcoming events for the selected sources.</div>';
                    return;
                }

                const groups = new Map();
                events.forEach(ev => {
                    const key = ev.start.toISOString().slice(0, 10);
                    if (!groups.has(key)) groups.set(key, []);
                    groups.get(key).push(ev);
                });

                upcomingList.innerHTML = "";
                groups.forEach(items => {
                    const section = document.createElement("section");
                    section.className = "upcoming-group";
                    const head = document.createElement("div");
                    head.className = "upcoming-group-title";
                    head.textContent = groupLabel(items[0].start);
                    section.appendChild(head);

                    items.forEach(ev => {
                        const meta = sourceMeta(ev), p = ev.extendedProps || {};
                        const item = document.createElement("div");
                        item.className = "upcoming-item";
                        item.tabIndex = 0;
                        item.setAttribute("role", "button");

                        const tm = document.createElement("div");
                        tm.className = "upcoming-time";
                        tm.textContent = timeLabel(ev);

                        const inf = document.createElement("div");
                        inf.className = "upcoming-info";

                        const ttl = document.createElement("div");
                        ttl.className = "upcoming-title";
                        ttl.textContent = ev.title;

                        const det = document.createElement("div");
                        det.className = "upcoming-meta";

                        const dot = document.createElement("span");
                        dot.className = "upcoming-source-dot " + meta.dot;

                        const src = document.createElement("span");
                        src.textContent = meta.label;

                        det.appendChild(dot);
                        det.appendChild(src);

                        if (p.source_type === "office" && p.status) {
                            const st = document.createElement("span");
                            st.textContent = "• " + p.status;
                            det.appendChild(st);
                        }

                        const dateRange = document.createElement("div");
                        dateRange.className = "upcoming-date-range";
                        dateRange.style.cssText = "margin-top:2px;color:#667085;font-size:.56rem;";
                        dateRange.textContent = dateRangeLabel(ev);

                        inf.appendChild(ttl);
                        inf.appendChild(det);
                        inf.appendChild(dateRange);
                        item.appendChild(tm);
                        item.appendChild(inf);

                        item.addEventListener("click", () => activateCalendarEvent(ev));
                        item.addEventListener("keydown", e => {
                            if (e.key === "Enter" || e.key === " ") {
                                e.preventDefault();
                                activateCalendarEvent(ev);
                            }
                        });

                        section.appendChild(item);
                    });

                    upcomingList.appendChild(section);
                });
            }

            /* Calendar-day span of an event (all-day ends are exclusive). */
            function eventSpanDays(ev) {
                if (!ev.start) return 1;
                const s = new Date(ev.start); s.setHours(0, 0, 0, 0);
                if (!ev.end) return 1;
                const e = new Date(ev.end); e.setHours(0, 0, 0, 0);
                const diff = Math.round((e - s) / 86400000);
                return ev.allDay ? Math.max(1, diff) : diff + 1;
            }
            function durationLabel(days) {
                if (days >= 28) return "Month-long · " + days + "d";
                if (days >= 7) return days + " days · Week+";
                if (days >= 2) return days + " days";
                return "1 day";
            }
            function eventRangeLabel(ev) {
                if (!ev.start) return "";
                const opts = { month: "short", day: "numeric", year: "numeric" };
                const s = new Date(ev.start);
                if (!ev.end) return s.toLocaleDateString(undefined, opts);
                const e = new Date(ev.end);
                if (ev.allDay) e.setDate(e.getDate() - 1); // exclusive end
                const sStr = s.toLocaleDateString(undefined, opts);
                const eStr = e.toLocaleDateString(undefined, opts);
                return sStr === eStr ? sStr : sStr + " – " + eStr;
            }

            const calendar = new FullCalendar.Calendar(q("calendar"), {
                initialView: "dayGridMonth",
                firstDay: 0,
                height: "auto",
                dayMaxEvents: true,
                nowIndicator: true,
                headerToolbar: { left: "prev,next today", center: "title", right: "dayGridMonth,timeGridWeek,listMonth" },
                buttonText: { today: "Today", month: "Month", week: "Week", list: "List" },
                views: {
                    listMonth: {
                        displayEventEnd: true,
                        dayMaxEvents: false,
                        noEventsContent: "No activities scheduled in this period.",
                        listDayFormat: { weekday: "long", month: "long", day: "numeric" },
                        eventClassNames: info => {
                            const days = eventSpanDays(info.event);
                            if (days >= 28) return ["ev-long-month"];
                            if (days >= 7) return ["ev-long-week"];
                            if (days >= 2) return ["ev-multi-day"];
                            return [];
                        },
                        eventContent: info => {
                            const days = eventSpanDays(info.event);
                            const wrap = document.createElement("span");
                            wrap.className = "ev-list-main";
                            const title = document.createElement("b");
                            title.textContent = info.event.title || "(No title)";
                            wrap.appendChild(title);
                            if (days >= 2) {
                                const badge = document.createElement("span");
                                badge.className = "ev-dur-badge"
                                    + (days >= 28 ? " is-month" : days >= 7 ? " is-week" : "");
                                badge.textContent = durationLabel(days);
                                wrap.appendChild(badge);
                                const range = document.createElement("span");
                                range.className = "ev-range";
                                range.textContent = eventRangeLabel(info.event);
                                wrap.appendChild(range);
                            }
                            return { domNodes: [wrap] };
                        }
                    }
                },
                events: async (info, ok, fail) => {
                    const p = new URLSearchParams({
                        feed: "1",
                        start: info.startStr.slice(0, 10),
                        end: info.endStr.slice(0, 10),
                        show_hpu: showHpu.checked ? "1" : "0",
                        show_hospital: showHospital.checked ? "1" : "0",
                        show_office: (showOffice && showOffice.checked) ? "1" : "0"
                    });
                    if (showPersonal) p.set("show_personal", showPersonal.checked ? "1" : "0");
                    if (showJobs) p.set("show_jobs", showJobs.checked ? "1" : "0");
                    if (showPubs) p.set("show_pubs", showPubs.checked ? "1" : "0");
                    if (typeFilter) p.set("activity_type", typeFilter.value);
                    if (personFilter) p.set("person_id", personFilter.value);
                    if (officeStatusFilter) p.set("office_status", officeStatusFilter.value);
                    try {
                        const r = await fetch(baseUrl + "/my_calendar.php?" + p.toString(), { credentials: "same-origin" });
                        if (!r.ok) throw new Error("Unable to load calendar.");
                        ok(await r.json());
                    } catch (e) { fail(e) }
                },
                eventClick: info => activateCalendarEvent(info.event),
                eventsSet: () => renderUpcoming(calendar),
                eventDidMount: info => {
                    const p = info.event.extendedProps || {};
                    info.el.title = p.source_type === "personal"
                        ? "Personal Task • " + (p.priority || "Normal")
                        : p.source_type === "office"
                            ? "Office Activity • " + (p.status || "Submitted")
                            : p.source_type === "job"
                                ? "Job Request Deadline"
                                : p.source_type === "publication"
                                    ? "Publication Preferred Date"
                                : (p.calendar_scope || "HPU") + " Activity";
                    const days = eventSpanDays(info.event);
                    if (days >= 2) {
                        info.el.title += " • " + durationLabel(days)
                            + " (" + eventRangeLabel(info.event) + ")";
                    }
                }
            });
            calendar.render();
            setTimeout(() => renderUpcoming(calendar), 100);

            [typeFilter, personFilter, officeStatusFilter, showHpu, showHospital, showOffice, showPersonal, showJobs, showPubs]
                .filter(Boolean)
                .forEach(x => x.addEventListener("change", () => calendar.refetchEvents()));

            q("resetFilters").onclick = () => {
                showHpu.checked = showHospital.checked = true;
                if (showOffice) showOffice.checked = true;
                if (showPersonal) showPersonal.checked = true;
                if (showJobs) showJobs.checked = true;
                if (showPubs) showPubs.checked = true;
                if (typeFilter) typeFilter.value = "";
                if (personFilter) personFilter.value = "0";
                if (officeStatusFilter) officeStatusFilter.value = "";
                calendar.refetchEvents();
            };
        });
    </script>

    <button id="calTopBtn" type="button" title="Back to top" aria-label="Back to top">
        <i class="bi bi-arrow-up"></i>
    </button>

    <script>
        /* Calendar UX: persist sources/filters/view, select-all, back-to-top. */
        (function () {
            var LS_KEY = "hpu-calendar-prefs-v1";
            var BOXES = ["showHpu", "showHospital", "showPersonal", "showOffice", "showJobs", "showPubs"];
            var SELECTS = ["typeFilter", "personFilter", "officeStatusFilter"];

            function readPrefs() {
                try { return JSON.parse(localStorage.getItem(LS_KEY) || "{}"); }
                catch (e) { return {}; }
            }

            function writePrefs(p) {
                try { localStorage.setItem(LS_KEY, JSON.stringify(p)); }
                catch (e) { /* private mode */ }
            }

            /* Restore immediately so FullCalendar's first fetch uses saved values. */
            var prefs = readPrefs();

            BOXES.forEach(function (id) {
                var el = document.getElementById(id);
                if (el && prefs[id] !== undefined) el.checked = !!prefs[id];
            });

            SELECTS.forEach(function (id) {
                var el = document.getElementById(id);
                if (el && prefs[id] !== undefined && prefs[id] !== null) el.value = prefs[id];
            });

            document.addEventListener("DOMContentLoaded", function () {
                BOXES.concat(SELECTS).forEach(function (id) {
                    var el = document.getElementById(id);
                    if (!el) return;
                    el.addEventListener("change", function () {
                        var p = readPrefs();
                        p[id] = (el.type === "checkbox") ? el.checked : el.value;
                        writePrefs(p);
                    });
                });

                var reset = document.getElementById("resetFilters");
                if (reset) reset.addEventListener("click", function () {
                    try { localStorage.removeItem(LS_KEY); } catch (e) {}
                });

                function setAllSources(v) {
                    var p = readPrefs();
                    BOXES.forEach(function (id) {
                        var el = document.getElementById(id);
                        if (!el) return;
                        el.checked = v;
                        p[id] = v;
                        el.dispatchEvent(new Event("change"));
                    });
                    writePrefs(p);
                }

                var allBtn = document.getElementById("sourcesAll");
                var noneBtn = document.getElementById("sourcesNone");
                if (allBtn) allBtn.addEventListener("click", function () { setAllSources(true); });
                if (noneBtn) noneBtn.addEventListener("click", function () { setAllSources(false); });

                /* Restore saved calendar view after first render. */
                if (prefs.view && prefs.view !== "dayGridMonth") {
                    setTimeout(function () {
                        var btn = document.querySelector(".fc-" + prefs.view + "-button");
                        if (btn) btn.click();
                    }, 600);
                }

                /* Persist view switches (delegate on the toolbar). */
                var cal = document.getElementById("calendar");
                if (cal) cal.addEventListener("click", function (ev) {
                    var b = ev.target.closest("button");
                    if (!b) return;
                    var m = /fc-(\w+)-button/.exec(b.className || "");
                    if (m && ["dayGridMonth", "timeGridWeek", "listMonth"].indexOf(m[1]) >= 0) {
                        var p = readPrefs();
                        p.view = m[1];
                        writePrefs(p);
                    }
                });

                /* Back to top. */
                var toTop = document.getElementById("calTopBtn");
                window.addEventListener("scroll", function () {
                    toTop.classList.toggle("show", window.scrollY > 600);
                }, { passive: true });
                toTop.addEventListener("click", function () {
                    window.scrollTo({ top: 0, behavior: "smooth" });
                });
            });
        })();
    </script>
</body>

</html>