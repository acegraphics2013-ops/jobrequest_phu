<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - MONTHLY REPORT (Option 2)
|--------------------------------------------------------------------------
| Standalone printable monthly sheet for one year + month.
| Cohort default: MONTH(date_request) / YEAR(date_request).
| Extra lines show completions (MONTH(final_approval_date)) and
| evaluations (MONTH(submitted_at)) for context.
| Reuses evaluation_analytics.php patterns: alias-merged personnel
| (handled/reviewed/approved), IEC consolidation, CSV/Excel/Print.
|--------------------------------------------------------------------------
*/

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

requireRole([
    "staff",
    "supervisor",
    "admin"
]);
requireModule($conn, "monthly_report");

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

$userId = (int)($_SESSION["user_id"] ?? 0);
$userName = (string)($_SESSION["name"] ?? "User");
$role = (string)($_SESSION["role"] ?? "staff");
$isStaff = $role === "staff";

/* Helpers (mr* prefix, mirrors evaluation_analytics ea*). */

function mrTableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function mrColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row["total"] ?? 0) > 0;
}

function mrDecodeArray(mixed $value): array
{
    if ($value === null || $value === "") {
        return [];
    }
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

function mrValidDateOrNull(mixed $value): ?string
{
    $text = trim((string)($value ?? ""));
    if ($text === "" || $text === "0000-00-00") {
        return null;
    }
    try {
        new DateTime(substr($text, 0, 10));
        return substr($text, 0, 10);
    } catch (Throwable $e) {
        return null;
    }
}

function mrDateDiffDays(?string $start, ?string $end): ?int
{
    if (!$start || !$end) {
        return null;
    }
    try {
        $a = new DateTime(substr((string)$start, 0, 10));
        $b = new DateTime(substr((string)$end, 0, 10));
        if ($b < $a) {
            return null;
        }
        return (int)$a->diff($b)->format("%a");
    } catch (Throwable $e) {
        return null;
    }
}

function mrActualCompletion(array $row): ?string
{
    return mrValidDateOrNull($row["final_approval_date"] ?? null);
}

function mrCombinedRating(array $row): int
{
    $client = (int)($row["client_overall_satisfaction"] ?? 0);
    if ($client >= 1 && $client <= 5) {
        return $client;
    }
    $legacy = (int)($row["satisfaction_rating"] ?? 0);
    return ($legacy >= 1 && $legacy <= 5) ? $legacy : 0;
}

function mrPercent(float|int $part, float|int $whole): float
{
    if ((float)$whole <= 0) {
        return 0.0;
    }
    return ((float)$part / (float)$whole) * 100;
}

function mrMonthShort(int $month): string
{
    return match ($month) {
        1 => "Jan", 2 => "Feb", 3 => "Mar", 4 => "Apr",
        5 => "May", 6 => "Jun", 7 => "Jul", 8 => "Aug",
        9 => "Sep", 10 => "Oct", 11 => "Nov", default => "Dec"
    };
}

function mrMonthLong(int $month): string
{
    return match ($month) {
        1 => "January", 2 => "February", 3 => "March", 4 => "April",
        5 => "May", 6 => "June", 7 => "July", 8 => "August",
        9 => "September", 10 => "October", 11 => "November", default => "December"
    };
}

function mrRatingLabel(int $rating): string
{
    return match ($rating) {
        5 => "Excellent", 4 => "Very Good", 3 => "Good",
        2 => "Fair", 1 => "Poor", default => "Not Rated"
    };
}

function mrStatusClass(string $status): string
{
    return match ($status) {
        "Completed", "For Evaluation", "Closed" => "status-success",
        "For Revision" => "status-warning",
        "Disapproved" => "status-danger",
        default => "status-neutral"
    };
}

function mrNormStaffName(string $name): string
{
    $norm = mb_strtoupper(trim($name));
    $norm = str_replace(".", "", $norm);
    $norm = (string)preg_replace('/\s+/', ' ', $norm);
    return trim($norm);
}

function mrCanonicalStaffDisplay(string $name, array $aliasMap): string
{
    $norm = mrNormStaffName($name);
    return $aliasMap[$norm] ?? trim($name);
}

$staffAliasDisplay = [
    mrNormStaffName("ACE R. CABUGA") => "ACE RAZ CABUGA",
    mrNormStaffName("ACE RAZ CABUGA") => "ACE RAZ CABUGA",
    mrNormStaffName("BELL MANDREW MANLIGUEZ, RN - HEPO II") => "BELL MANDREW L. MANLIGUEZ, RN",
    mrNormStaffName("BELL MANDREW L. MANLIGUEZ, RN") => "BELL MANDREW L. MANLIGUEZ, RN",
    mrNormStaffName("RECKLYN D. RUAZA, RN") => "RECKLYN D. RUAZA, RN",
    mrNormStaffName("RECKLYN D. RUAZA, RN - HEPO III") => "RECKLYN D. RUAZA, RN",
];

$staffIdByNormName = [];
$staffDisplayByNormName = [];
$staffRoleById = [];
$staffStatusById = [];
$staffDisplayById = [];

function mrRegisterStaffMaps(
    mysqli $conn,
    array $aliasMap,
    array &$idByNorm,
    array &$displayByNorm
): void {
    global $staffRoleById, $staffStatusById, $staffDisplayById;
    $res = $conn->query(
        "SELECT id, name, role, status FROM users
          WHERE role IN ('staff','supervisor','admin')"
    );
    if (!$res) {
        return;
    }
    foreach ($res->fetch_all(MYSQLI_ASSOC) as $u) {
        $display = trim((string)($u["name"] ?? ""));
        if ($display === "") {
            continue;
        }
        $uid = (int)$u["id"];
        $norm = mrNormStaffName($display);
        $idByNorm[$norm] = $uid;
        if (!isset($displayByNorm[$norm])) {
            $displayByNorm[$norm] = $display;
        }
        $staffRoleById[$uid] = (string)($u["role"] ?? "");
        $staffStatusById[$uid] = (string)($u["status"] ?? "");
        $staffDisplayById[$uid] = $display;
    }
    foreach ($aliasMap as $aliasNorm => $display) {
        $canonNorm = mrNormStaffName((string)$display);
        if (isset($idByNorm[$canonNorm]) && !isset($idByNorm[$aliasNorm])) {
            $idByNorm[$aliasNorm] = $idByNorm[$canonNorm];
        }
        if (isset($displayByNorm[$canonNorm]) && !isset($displayByNorm[$aliasNorm])) {
            $displayByNorm[$aliasNorm] = $displayByNorm[$canonNorm];
        } elseif (!isset($displayByNorm[$aliasNorm])) {
            $displayByNorm[$aliasNorm] = $display;
        }
    }
}

function mrResolvePersonBucket(
    string $rawName,
    array $aliasMap,
    array $idByNorm,
    array $displayByNorm
): array {
    $rawName = trim($rawName);
    if ($rawName === "") {
        return ["key" => "", "id" => 0, "display" => ""];
    }
    $canonDisplay = mrCanonicalStaffDisplay($rawName, $aliasMap);
    $canonNorm = mrNormStaffName($canonDisplay);
    $norm = mrNormStaffName($rawName);
    if (isset($idByNorm[$canonNorm])) {
        $uid = (int)$idByNorm[$canonNorm];
        $display = (string)($displayByNorm[$canonNorm] ?? $canonDisplay);
        return ["key" => "id:" . $uid, "id" => $uid, "display" => $display];
    }
    if (isset($idByNorm[$norm])) {
        $uid = (int)$idByNorm[$norm];
        $display = (string)($displayByNorm[$norm] ?? $canonDisplay);
        return ["key" => "id:" . $uid, "id" => $uid, "display" => $display];
    }
    return ["key" => "canon:" . $canonNorm, "id" => 0, "display" => $canonDisplay];
}

function mrEnsureStaffBucket(array &$staffStats, string $key, string $display, int $id): void
{
    if ($key === "" || $display === "") {
        return;
    }
    if (!isset($staffStats[$key])) {
        $staffStats[$key] = [
            "name" => $display, "id" => $id,
            "handled" => 0, "completed" => 0, "evaluated" => 0,
            "rating_total" => 0, "rating_count" => 0,
            "turnaround_total" => 0, "turnaround_count" => 0,
            "revisions" => 0, "reviewed" => 0, "approved" => 0,
        ];
    }
    foreach (["reviewed", "approved"] as $k) {
        if (!isset($staffStats[$key][$k])) {
            $staffStats[$key][$k] = 0;
        }
    }
    if ($id > 0 && ($staffStats[$key]["id"] ?? 0) === 0) {
        $staffStats[$key]["id"] = $id;
    }
}

function mrSqlBind(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types !== "" && $params) {
        $stmt->bind_param($types, ...$params);
    }
}

/* Feature detection. */
$hasClientSurvey = mrTableExists($conn, "job_request_client_experience");
$hasAssignedTo = mrColumnExists($conn, "job_requests", "assigned_to");
$hasPriority = mrColumnExists($conn, "job_requests", "priority");
$hasArchived = mrColumnExists($conn, "job_requests", "archived");
$hasPublicToken = mrColumnExists($conn, "job_requests", "public_token");
$hasIecItems = mrTableExists($conn, "job_request_iec_items");
$hasIecLibrary = mrTableExists($conn, "iec_materials");
$hasIecLogs = mrTableExists($conn, "iec_material_logs");
$hasIecFulfilled = $hasIecItems && mrColumnExists($conn, "job_request_iec_items", "fulfilled_qty");

/* Filters. */
$services = ["Graphic Design", "Video Production", "Photo/Video Coverage", "IEC Materials", "Publication Submission"];
$completedStatuses = ["Completed", "For Evaluation", "Closed"];
$trackerStatuses = ["Pending Review", "Under Review", "Approved", "In Development", "For Revision", "Disapproved", "Completed", "For Evaluation", "Closed"];

$currentYear = (int)date("Y");
$currentMonth = (int)date("n");
$selectedYear = filter_input(INPUT_GET, "year", FILTER_VALIDATE_INT) ?: $currentYear;
$selectedMonth = filter_input(INPUT_GET, "month", FILTER_VALIDATE_INT) ?: $currentMonth;
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = $currentMonth;
}
$departmentFilter = trim((string)($_GET["department"] ?? ""));
$serviceFilter = trim((string)($_GET["service"] ?? ""));
$staffFilter = trim((string)($_GET["staff"] ?? ""));
$statusFilter = trim((string)($_GET["status"] ?? ""));
if ($serviceFilter !== "" && !in_array($serviceFilter, $services, true)) {
    $serviceFilter = "";
}
if ($statusFilter !== "" && !in_array($statusFilter, $trackerStatuses, true)) {
    $statusFilter = "";
}
$archivedFilter = trim((string)($_GET["archived"] ?? "all"));
if (!in_array($archivedFilter, ["all", "active", "archived"], true)) {
    $archivedFilter = "all";
}
if (!$hasArchived) {
    $archivedFilter = "all";
}

/* Filter options. */
$departments = $conn->query(
    "SELECT DISTINCT department FROM job_requests
      WHERE department IS NOT NULL AND department <> '' ORDER BY department"
)->fetch_all(MYSQLI_ASSOC);

$yearRows = $conn->query(
    "SELECT DISTINCT YEAR(date_request) AS y FROM job_requests
      WHERE date_request IS NOT NULL ORDER BY y DESC"
)->fetch_all(MYSQLI_ASSOC);
$years = [];
foreach ($yearRows as $yearRow) {
    $y = (int)($yearRow["y"] ?? 0);
    if ($y > 0) {
        $years[] = $y;
    }
}
if (!in_array($selectedYear, $years, true)) {
    array_unshift($years, $selectedYear);
}

$staffOptions = [];
if ($hasAssignedTo) {
    $staffOptions = $conn->query(
        "SELECT id, name FROM users
          WHERE role IN ('staff','supervisor','admin') AND status = 'Active'
          ORDER BY name"
    )->fetch_all(MYSQLI_ASSOC);
} else {
    $legacyStaff = $conn->query(
        "SELECT DISTINCT reviewed_by AS name FROM job_requests
          WHERE reviewed_by IS NOT NULL AND reviewed_by <> '' ORDER BY reviewed_by"
    )->fetch_all(MYSQLI_ASSOC);
    foreach ($legacyStaff as $person) {
        $staffOptions[] = ["id" => 0, "name" => (string)$person["name"]];
    }
}

mrRegisterStaffMaps($conn, $staffAliasDisplay, $staffIdByNormName, $staffDisplayByNormName);

/* Main query: single-month cohort on date_request. */
function mrLoadRows(
    mysqli $conn,
    int $year,
    int $month,
    string $departmentFilter,
    string $serviceFilter,
    string $staffFilter,
    string $statusFilter,
    string $archivedFilter,
    bool $hasClientSurvey,
    bool $hasAssignedTo,
    bool $hasPriority,
    bool $hasArchived,
    bool $hasPublicToken
): array {
    $clientSelect = $hasClientSurvey
        ? "ce.id AS client_survey_id, ce.overall_satisfaction AS client_overall_satisfaction,
           ce.outcome_assessment, ce.phu_did_well, ce.phu_improve,
           ce.submitted_at AS client_submitted_at"
        : "NULL AS client_survey_id, NULL AS client_overall_satisfaction,
           NULL AS outcome_assessment, NULL AS phu_did_well, NULL AS phu_improve,
           NULL AS client_submitted_at";
    $clientJoin = $hasClientSurvey
        ? "LEFT JOIN job_request_client_experience ce ON ce.request_id = jr.id" : "";
    $assignedSelect = $hasAssignedTo
        ? "jr.assigned_to, assignee.name AS assigned_name,"
        : "NULL AS assigned_to, NULL AS assigned_name,";
    $assignedJoin = $hasAssignedTo
        ? "LEFT JOIN users assignee ON assignee.id = jr.assigned_to" : "";
    $prioritySelect = $hasPriority ? "jr.priority" : "'Normal' AS priority";
    $tokenSelect = $hasPublicToken ? "jr.public_token" : "NULL AS public_token";

    $sql = "SELECT jr.id, jr.job_no, jr.project_title, jr.requestor AS requestor_id,
            jr.requestor_name, COALESCE(requestor_u.role, '') AS requestor_role,
            jr.contact, jr.department, jr.request_types, jr.date_request,
            jr.date_completion, jr.final_approval_date, jr.approved_date,
            jr.initial_draft_date, jr.approval_status, jr.reviewed_by, jr.approved_by,
            jr.revision_count, jr.satisfaction_rating, jr.created_at, jr.updated_at, "
        . ($hasArchived ? "jr.archived," : "0 AS archived,")
        . " {$tokenSelect}, {$assignedSelect} {$prioritySelect} AS priority, {$clientSelect}
        FROM job_requests jr
        LEFT JOIN users requestor_u ON requestor_u.id = jr.requestor
        {$assignedJoin} {$clientJoin}
        WHERE YEAR(jr.date_request) = ? AND MONTH(jr.date_request) = ?";
    $types = "ii";
    $params = [$year, $month];

    if ($hasArchived && $archivedFilter === "active") {
        $sql .= " AND COALESCE(jr.archived, 0) = 0";
    } elseif ($hasArchived && $archivedFilter === "archived") {
        $sql .= " AND COALESCE(jr.archived, 0) = 1";
    }
    if ($departmentFilter !== "") {
        $sql .= " AND jr.department = ?";
        $types .= "s";
        $params[] = $departmentFilter;
    }
    if ($serviceFilter !== "") {
        $sql .= " AND (jr.request_types LIKE ? OR jr.request_types LIKE ?)";
        $types .= "ss";
        $params[] = '%"' . $serviceFilter . '"%';
        $params[] = '%"' . str_replace("/", "\\/", $serviceFilter) . '"%';
    }
    if ($statusFilter !== "") {
        $sql .= " AND jr.approval_status = ?";
        $types .= "s";
        $params[] = $statusFilter;
    }
    if ($staffFilter !== "") {
        global $staffAliasDisplay, $staffIdByNormName, $staffDisplayByNormName;
        if ($hasAssignedTo && ctype_digit($staffFilter)) {
            $sql .= " AND jr.assigned_to = ?";
            $types .= "i";
            $params[] = (int)$staffFilter;
        } elseif ($hasAssignedTo) {
            $sql .= " AND EXISTS (SELECT 1 FROM users u WHERE u.id = jr.assigned_to AND u.name = ?)";
            $types .= "s";
            $params[] = $staffFilter;
        } else {
            $sql .= " AND jr.reviewed_by = ?";
            $types .= "s";
            $params[] = $staffFilter;
        }
    }
    $sql .= " ORDER BY jr.date_request DESC, jr.id DESC";

    $stmt = $conn->prepare($sql);
    mrSqlBind($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$rows = mrLoadRows(
    $conn, $selectedYear, $selectedMonth, $departmentFilter, $serviceFilter,
    $staffFilter, $statusFilter, $archivedFilter,
    $hasClientSurvey, $hasAssignedTo, $hasPriority, $hasArchived, $hasPublicToken
);

/* Aggregate. */
$totalRequests = count($rows);
$completedRequests = 0;
$evaluatedRequests = 0;
$evaluatedCompleted = 0;
$ratingTotal = 0;
$ratingCount = 0;
$turnaroundTotal = 0;
$turnaroundCount = 0;
$turnaroundMissing = 0;
$serviceStats = [];
foreach ($services as $svc) {
    $serviceStats[$svc] = ["requests" => 0, "completed" => 0, "evaluated" => 0, "rating_total" => 0, "rating_count" => 0];
}
$statusCounts = [];
$staffStats = [];
$lowRatingRows = [];
$feedbackRows = [];

foreach ($rows as $row) {
    $status = (string)($row["approval_status"] ?? "");
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
    $isCompleted = in_array($status, $completedStatuses, true);
    if ($isCompleted) {
        $completedRequests++;
    }
    $rating = mrCombinedRating($row);
    $isEvaluated = $rating >= 1 && $rating <= 5;
    if ($isEvaluated) {
        $evaluatedRequests++;
        $ratingTotal += $rating;
        $ratingCount++;
        if ($isCompleted) {
            $evaluatedCompleted++;
        }
        if ($rating <= 2) {
            $lowRatingRows[] = $row;
        }
    }
    $actual = mrActualCompletion($row);
    $turnaround = mrDateDiffDays(mrValidDateOrNull($row["date_request"] ?? null), $actual);
    if ($isCompleted) {
        if ($turnaround !== null) {
            $turnaroundTotal += $turnaround;
            $turnaroundCount++;
        } else {
            $turnaroundMissing++;
        }
    }
    foreach (mrDecodeArray($row["request_types"] ?? null) as $t) {
        if (!isset($serviceStats[$t])) {
            continue;
        }
        $serviceStats[$t]["requests"]++;
        if ($isCompleted) {
            $serviceStats[$t]["completed"]++;
        }
        if ($isEvaluated) {
            $serviceStats[$t]["evaluated"]++;
            $serviceStats[$t]["rating_total"] += $rating;
            $serviceStats[$t]["rating_count"]++;
        }
    }
    if (!empty($row["client_survey_id"])) {
        $didWell = trim((string)($row["phu_did_well"] ?? ""));
        $improve = trim((string)($row["phu_improve"] ?? ""));
        if ($didWell !== "" || $improve !== "") {
            $feedbackRows[] = [
                "job_no" => $row["job_no"], "project_title" => $row["project_title"],
                "did_well" => $didWell, "improve" => $improve,
                "rating" => $rating, "submitted_at" => $row["client_submitted_at"] ?? null,
            ];
        }
    }

    /* Ownership: assigned_to authoritative, legacy reviewed_by fallback. */
    $staffKey = "";
    $staffName = "";
    $staffId = (int)($row["assigned_to"] ?? 0);
    if ($hasAssignedTo && $staffId > 0) {
        $staffName = trim((string)($row["assigned_name"] ?? ""));
        if ($staffName === "") {
            $staffName = "Staff #" . $staffId;
        }
        $staffName = mrCanonicalStaffDisplay($staffName, $staffAliasDisplay);
        $staffKey = "id:" . $staffId;
    } else {
        $rawReviewer = trim((string)($row["reviewed_by"] ?? ""));
        if ($rawReviewer !== "") {
            $b = mrResolvePersonBucket($rawReviewer, $staffAliasDisplay, $staffIdByNormName, $staffDisplayByNormName);
            $staffKey = $b["key"];
            $staffName = $b["display"];
            $staffId = $b["id"];
        }
    }
    if ($staffKey !== "" && $staffName !== "") {
        mrEnsureStaffBucket($staffStats, $staffKey, $staffName, $staffId);
        $staffStats[$staffKey]["handled"]++;
        if ($isCompleted) {
            $staffStats[$staffKey]["completed"]++;
        }
        if ($isEvaluated) {
            $staffStats[$staffKey]["evaluated"]++;
            $staffStats[$staffKey]["rating_total"] += $rating;
            $staffStats[$staffKey]["rating_count"]++;
        }
        if ($isCompleted && $turnaround !== null) {
            $staffStats[$staffKey]["turnaround_total"] += $turnaround;
            $staffStats[$staffKey]["turnaround_count"]++;
        }
        $staffStats[$staffKey]["revisions"] += (int)($row["revision_count"] ?? 0);
    }

    /* Reviewer + approver attribution. */
    $rawReviewer = trim((string)($row["reviewed_by"] ?? ""));
    if ($rawReviewer !== "") {
        $b = mrResolvePersonBucket($rawReviewer, $staffAliasDisplay, $staffIdByNormName, $staffDisplayByNormName);
        if ($b["key"] !== "") {
            mrEnsureStaffBucket($staffStats, $b["key"], $b["display"], $b["id"]);
            $staffStats[$b["key"]]["reviewed"]++;
        }
    }
    $rawApprover = trim((string)($row["approved_by"] ?? ""));
    if ($rawApprover !== "") {
        $b = mrResolvePersonBucket($rawApprover, $staffAliasDisplay, $staffIdByNormName, $staffDisplayByNormName);
        if ($b["key"] !== "") {
            mrEnsureStaffBucket($staffStats, $b["key"], $b["display"], $b["id"]);
            $staffStats[$b["key"]]["approved"]++;
        }
    }
}

foreach ($staffDisplayById as $hpuId => $hpuDisplay) {
    mrEnsureStaffBucket($staffStats, "id:" . (int)$hpuId, (string)$hpuDisplay, (int)$hpuId);
}

foreach ($staffStats as &$st) {
    foreach (["reviewed", "approved"] as $rk) {
        if (!isset($st[$rk])) {
            $st[$rk] = 0;
        }
    }
    $st["average_rating"] = $st["rating_count"] > 0 ? $st["rating_total"] / $st["rating_count"] : 0;
    $st["average_turnaround"] = $st["turnaround_count"] > 0 ? $st["turnaround_total"] / $st["turnaround_count"] : 0;
    $st["completion_rate"] = mrPercent($st["completed"], $st["handled"]);
    $sid = (int)($st["id"] ?? 0);
    $st["role"] = $sid > 0 ? (string)($staffRoleById[$sid] ?? "") : "";
    $st["status"] = $sid > 0 ? (string)($staffStatusById[$sid] ?? "") : "";
}
unset($st);
uasort($staffStats, static function (array $a, array $b): int {
    $cmp = (($b["approved"] ?? 0) + ($b["completed"] ?? 0) + ($b["handled"] ?? 0))
        <=> (($a["approved"] ?? 0) + ($a["completed"] ?? 0) + ($a["handled"] ?? 0));
    if ($cmp !== 0) {
        return $cmp;
    }
    return strcmp((string)($a["name"] ?? ""), (string)($b["name"] ?? ""));
});
$staffTotals = ["handled" => 0, "completed" => 0, "reviewed" => 0, "approved" => 0, "evaluated" => 0, "revisions" => 0];
foreach ($staffStats as $st) {
    $staffTotals["handled"] += (int)($st["handled"] ?? 0);
    $staffTotals["completed"] += (int)($st["completed"] ?? 0);
    $staffTotals["reviewed"] += (int)($st["reviewed"] ?? 0);
    $staffTotals["approved"] += (int)($st["approved"] ?? 0);
    $staffTotals["evaluated"] += (int)($st["evaluated"] ?? 0);
    $staffTotals["revisions"] += (int)($st["revisions"] ?? 0);
}

$averageRating = $ratingCount > 0 ? $ratingTotal / $ratingCount : 0;
$pendingEvaluations = max(0, $completedRequests - $evaluatedCompleted);
$responseRate = mrPercent($evaluatedCompleted, $completedRequests);
$averageTurnaround = $turnaroundCount > 0 ? $turnaroundTotal / $turnaroundCount : 0;
arsort($statusCounts);

/* Context lines: completions/evaluations whose actual month equals selected month. */
$completedInMonth = 0;
$evaluatedInMonth = 0;
foreach ($rows as $row) {
    $status = (string)($row["approval_status"] ?? "");
    if (in_array($status, $completedStatuses, true) && mrActualCompletion($row) !== null) {
        $m = (int)date("n", strtotime((string)mrActualCompletion($row)));
        $y = (int)date("Y", strtotime((string)mrActualCompletion($row)));
        if ($m === $selectedMonth && $y === $selectedYear) {
            $completedInMonth++;
        }
    }
    $r = mrCombinedRating($row);
    if ($r >= 1 && $r <= 5) {
        $evalDate = mrValidDateOrNull($row["client_submitted_at"] ?? null)
            ?: mrActualCompletion($row)
            ?: mrValidDateOrNull($row["date_request"] ?? null);
        if ($evalDate !== null) {
            $m = (int)date("n", strtotime($evalDate));
            $y = (int)date("Y", strtotime($evalDate));
            if ($m === $selectedMonth && $y === $selectedYear) {
                $evaluatedInMonth++;
            }
        }
    }
}

/* IEC consolidation for the month (distinct requests, qty, fulfilled). */
$iecLines = [];
$iecTotalQty = 0;
$iecTotalDone = 0;
$iecDistinctRequests = 0;
$iecTopMaterials = [];
if ($hasIecItems) {
    $doneSelect = $hasIecFulfilled ? "COALESCE(SUM(item.fulfilled_qty), 0)" : "0";
    $sql = "SELECT item.material_name AS material, jr.department AS department,
            COALESCE(SUM(item.quantity), 0) AS qty, {$doneSelect} AS done,
            COUNT(DISTINCT jr.id) AS requests, GROUP_CONCAT(DISTINCT jr.id) AS request_ids
        FROM job_request_iec_items item
        INNER JOIN job_requests jr ON jr.id = item.request_id
        WHERE YEAR(jr.date_request) = ? AND MONTH(jr.date_request) = ?";
    $types = "ii";
    $params = [$selectedYear, $selectedMonth];
    if ($hasArchived && $archivedFilter === "active") {
        $sql .= " AND COALESCE(jr.archived, 0) = 0";
    } elseif ($hasArchived && $archivedFilter === "archived") {
        $sql .= " AND COALESCE(jr.archived, 0) = 1";
    }
    if ($departmentFilter !== "") {
        $sql .= " AND jr.department = ?";
        $types .= "s";
        $params[] = $departmentFilter;
    }
    if ($serviceFilter !== "") {
        $sql .= " AND (jr.request_types LIKE ? OR jr.request_types LIKE ?)";
        $types .= "ss";
        $params[] = '%"' . $serviceFilter . '"%';
        $params[] = '%"' . str_replace("/", "\\/", $serviceFilter) . '"%';
    }
    if ($statusFilter !== "") {
        $sql .= " AND jr.approval_status = ?";
        $types .= "s";
        $params[] = $statusFilter;
    }
    if ($staffFilter !== "" && $hasAssignedTo && ctype_digit($staffFilter)) {
        $sql .= " AND jr.assigned_to = ?";
        $types .= "i";
        $params[] = (int)$staffFilter;
    } elseif ($staffFilter !== "" && $hasAssignedTo) {
        $sql .= " AND EXISTS (SELECT 1 FROM users u WHERE u.id = jr.assigned_to AND u.name = ?)";
        $types .= "s";
        $params[] = $staffFilter;
    } elseif ($staffFilter !== "") {
        $sql .= " AND jr.reviewed_by = ?";
        $types .= "s";
        $params[] = $staffFilter;
    }
    $sql .= " GROUP BY item.material_name, jr.department ORDER BY qty DESC";
    $stmt = $conn->prepare($sql);
    mrSqlBind($stmt, $types, $params);
    $stmt->execute();
    $iecLines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $seen = [];
    foreach ($iecLines as $line) {
        $qty = (int)($line["qty"] ?? 0);
        $done = (int)($line["done"] ?? 0);
        $label = trim((string)($line["material"] ?? "")) ?: "(unnamed item)";
        $key = mb_strtolower($label);
        $iecTotalQty += $qty;
        $iecTotalDone += $done;
        if (!isset($iecTopMaterials[$key])) {
            $iecTopMaterials[$key] = ["label" => $label, "qty" => 0, "done" => 0];
        }
        $iecTopMaterials[$key]["qty"] += $qty;
        $iecTopMaterials[$key]["done"] += $done;
        foreach (array_filter(array_map("trim", explode(",", (string)($line["request_ids"] ?? "")))) as $rid) {
            $seen[$rid] = true;
        }
    }
    $iecDistinctRequests = count($seen);
    uasort($iecTopMaterials, static fn (array $a, array $b): int => $b["qty"] <=> $a["qty"]);
    $iecTopMaterials = array_slice($iecTopMaterials, 0, 8, true);
}
$iecFulfillmentRate = mrPercent($iecTotalDone, $iecTotalQty);
$iecPending = max(0, $iecTotalQty - $iecTotalDone);
$monthTitle = mrMonthLong($selectedMonth) . " " . $selectedYear;

$filterQuery = http_build_query(array_filter([
    "year" => $selectedYear, "month" => $selectedMonth,
    "department" => $departmentFilter, "service" => $serviceFilter,
    "staff" => $staffFilter, "status" => $statusFilter, "archived" => $archivedFilter,
], static fn (mixed $v): bool => $v !== "" && $v !== null));

$export = trim((string)($_GET["export"] ?? ""));
if (in_array($export, ["csv", "excel"], true)) {
    $filename = "HPU_Monthly_Report_" . $selectedYear . "_" . str_pad((string)$selectedMonth, 2, "0", STR_PAD_LEFT) . "_" . date("Ymd_His");
    if ($export === "csv") {
        header("Content-Type: text/csv; charset=UTF-8");
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $output = fopen("php://output", "w");
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ["HPU MONTHLY REPORT — " . $monthTitle]);
        fputcsv($output, ["Total Requests", $totalRequests, "Completed", $completedRequests, "Evaluated", $evaluatedRequests, "Response %", round($responseRate, 1), "Avg Rating", $ratingCount > 0 ? round($averageRating, 2) : "", "Avg Turnaround", $turnaroundCount > 0 ? round($averageTurnaround, 1) : ""]);
        fputcsv($output, []);
        fputcsv($output, ["HPU PERSONNEL PERFORMANCE — " . $monthTitle]);
        fputcsv($output, ["Personnel", "Role", "Status", "Handled", "Reviewed", "Approved", "Completed", "Completion %", "Evaluated", "Avg Rating", "Avg Turnaround", "Revisions"]);
        foreach ($staffStats as $srow) {
            fputcsv($output, [
                $srow["name"] ?? "", $srow["role"] ?? "", $srow["status"] ?? "",
                $srow["handled"] ?? 0, $srow["reviewed"] ?? 0, $srow["approved"] ?? 0,
                $srow["completed"] ?? 0, round((float)($srow["completion_rate"] ?? 0), 1),
                $srow["evaluated"] ?? 0,
                ($srow["rating_count"] ?? 0) > 0 ? round((float)$srow["average_rating"], 2) : "",
                ($srow["turnaround_count"] ?? 0) > 0 ? round((float)$srow["average_turnaround"], 1) : "",
                $srow["revisions"] ?? 0,
            ]);
        }
        fputcsv($output, []);
        fputcsv($output, ["IEC CONSOLIDATION — " . $monthTitle]);
        fputcsv($output, ["Material", "Department", "Qty Requested", "Qty Fulfilled", "Pending", "Requests"]);
        foreach ($iecLines as $line) {
            fputcsv($output, [
                $line["material"] ?? "", $line["department"] ?? "",
                $line["qty"] ?? 0, $line["done"] ?? 0,
                max(0, (int)($line["qty"] ?? 0) - (int)($line["done"] ?? 0)),
                $line["requests"] ?? 0,
            ]);
        }
        fputcsv($output, ["Total", "", $iecTotalQty, $iecTotalDone, $iecPending, $iecDistinctRequests]);
        fputcsv($output, []);
        fputcsv($output, ["Job No.", "Project Title", "Department", "Request Type", "Status", "Assigned / Reviewed By", "Reviewer", "Approver", "Rating", "Date Requested", "Actual Completion"]);
        foreach ($rows as $row) {
            $owner = trim((string)($row["assigned_name"] ?? ""));
            if ($owner === "") {
                $owner = (string)($row["reviewed_by"] ?? "");
            }
            fputcsv($output, [
                $row["job_no"], $row["project_title"], $row["department"],
                implode(", ", mrDecodeArray($row["request_types"] ?? null)),
                $row["approval_status"], $owner,
                $row["reviewed_by"] ?? "", $row["approved_by"] ?? "",
                mrCombinedRating($row) ?: "", $row["date_request"] ?? "",
                mrActualCompletion($row) ?? "",
            ]);
        }
        fclose($output);
        exit;
    }
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    echo "\xEF\xBB\xBF";
    ?>
    <table border="1">
        <tr><th colspan="11">HPU MONTHLY REPORT — <?= e($monthTitle) ?></th></tr>
        <tr><th>Total</th><td><?= (int)$totalRequests ?></td><th>Completed</th><td><?= (int)$completedRequests ?></td><th>Evaluated</th><td><?= (int)$evaluatedRequests ?></td><th>Response %</th><td><?= number_format($responseRate, 1) ?></td><th>Avg Rating</th><td colspan="2"><?= $ratingCount > 0 ? number_format($averageRating, 2) : "" ?></td></tr>
    </table><br>
    <table border="1">
        <tr><th colspan="12">HPU PERSONNEL PERFORMANCE — <?= e($monthTitle) ?></th></tr>
        <tr><th>Personnel</th><th>Role</th><th>Status</th><th>Handled</th><th>Reviewed</th><th>Approved</th><th>Completed</th><th>Completion %</th><th>Evaluated</th><th>Avg Rating</th><th>Avg Turnaround</th><th>Revisions</th></tr>
        <?php foreach ($staffStats as $srow): ?>
        <tr>
            <td><?= e($srow["name"] ?? "") ?></td><td><?= e($srow["role"] ?? "") ?></td><td><?= e($srow["status"] ?? "") ?></td>
            <td><?= (int)($srow["handled"] ?? 0) ?></td><td><?= (int)($srow["reviewed"] ?? 0) ?></td><td><?= (int)($srow["approved"] ?? 0) ?></td>
            <td><?= (int)($srow["completed"] ?? 0) ?></td><td><?= number_format((float)($srow["completion_rate"] ?? 0), 1) ?></td>
            <td><?= (int)($srow["evaluated"] ?? 0) ?></td>
            <td><?= ($srow["rating_count"] ?? 0) > 0 ? number_format((float)$srow["average_rating"], 2) : "" ?></td>
            <td><?= ($srow["turnaround_count"] ?? 0) > 0 ? number_format((float)$srow["average_turnaround"], 1) : "" ?></td>
            <td><?= (int)($srow["revisions"] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
    </table><br>
    <table border="1">
        <tr><th colspan="6">IEC CONSOLIDATION — <?= e($monthTitle) ?></th></tr>
        <tr><th>Material</th><th>Department</th><th>Qty Requested</th><th>Qty Fulfilled</th><th>Pending</th><th>Requests</th></tr>
        <?php foreach ($iecLines as $line): ?>
        <tr>
            <td><?= e($line["material"] ?? "") ?></td><td><?= e($line["department"] ?? "") ?></td>
            <td><?= (int)($line["qty"] ?? 0) ?></td><td><?= (int)($line["done"] ?? 0) ?></td>
            <td><?= max(0, (int)($line["qty"] ?? 0) - (int)($line["done"] ?? 0)) ?></td>
            <td><?= (int)($line["requests"] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr><td><strong>Total</strong></td><td></td><td><strong><?= (int)$iecTotalQty ?></strong></td><td><strong><?= (int)$iecTotalDone ?></strong></td><td><strong><?= (int)$iecPending ?></strong></td><td><strong><?= (int)$iecDistinctRequests ?></strong></td></tr>
    </table>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monthly Report — <?= e($monthTitle) ?> | HPU Workbench</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">
<style>
:root { --mr-bg:#f4f7fb; --mr-border:#dfe6ef; --mr-text:#172033; --mr-muted:#667085; }
body { background:var(--mr-bg); color:var(--mr-text); }
.content { padding:18px; }
.container-fluid { max-width:1750px; margin:0 auto; }
.filter-card, .card-shell { border:1px solid var(--mr-border); border-radius:14px; background:#fff; box-shadow:0 8px 24px rgba(28,42,72,.05); }
.filter-card { padding:11px; margin-bottom:12px; }
.filter-label { display:block; margin-bottom:3px; color:#667085; font-size:.57rem; font-weight:900; letter-spacing:.045em; text-transform:uppercase; }
.kpi-grid { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:9px; margin-bottom:12px; }
.kpi-card { min-height:108px; padding:12px; border:1px solid var(--mr-border); border-radius:13px; background:#fff; }
.kpi-label { margin-top:9px; color:#667085; font-size:.58rem; font-weight:900; text-transform:uppercase; letter-spacing:.045em; }
.kpi-value { margin-top:3px; font-size:1.5rem; line-height:1; font-weight:900; }
.kpi-note { margin-top:6px; color:#98a2b3; font-size:.59rem; }
.card-head { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:14px 18px; border-bottom:1px solid #edf1f6; }
.card-title { margin:0; font-size:.95rem; font-weight:800; }
.card-subtitle { color:#64748b; font-size:.74rem; }
.card-body-pad { padding:12px 13px; }
.table-wrap { overflow-x:auto; }
.table-wrap-scroll { max-height:480px; overflow:auto; border-top:1px solid #edf1f6; overscroll-behavior:contain; }
.table-wrap-scroll thead th { position:sticky; top:0; z-index:2; box-shadow:0 1px 0 #eaecf0; }
.mr-scroll { max-height:520px; }
.mr-scroll .mr-table { min-width:1080px; }
.mr-scroll::-webkit-scrollbar { height:10px; width:10px; }
.mr-scroll::-webkit-scrollbar-thumb { border-radius:999px; background:#cbd5e1; border:2px solid #fff; }
.mr-scroll::-webkit-scrollbar-track { background:#f8fafc; }
.mr-scroll-hint { padding:7px 13px; border-top:1px dashed #e5e7eb; color:#98a2b3; font-size:.68rem; }
.mr-table { width:100%; border-collapse:collapse; font-size:.76rem; }
.mr-table th, .mr-table td { padding:8px 10px; border-bottom:1px solid #edf1f6; vertical-align:top; }
.mr-table thead th { background:#f8fafc; font-size:.62rem; text-transform:uppercase; letter-spacing:.04em; color:#475569; }
.service-pill, .status-pill { display:inline-block; padding:2px 8px; border-radius:999px; font-size:.65rem; font-weight:700; background:#eef2ff; margin:1px 2px 1px 0; }
.status-success { background:#dcfce7; color:#166534; }
.status-warning { background:#fef9c3; color:#854d0e; }
.status-danger { background:#fee2e2; color:#991b1b; }
.status-neutral { background:#e2e8f0; color:#475569; }
.empty-state { padding:22px; text-align:center; color:#98a2b3; }
@media print {
    .filter-card, .ph-actions, .toolbar-actions, .no-print, .mr-scroll-hint { display:none !important; }
    body { background:#fff; }
    .card-shell, .kpi-card { box-shadow:none; break-inside:avoid; }
    .table-wrap-scroll, .mr-scroll { max-height:none; overflow:visible; border-top:0; }
    .table-wrap-scroll thead th { position:static; box-shadow:none; }
}
</style>
</head>
<body class="app-shell">
<div class="d-flex min-vh-100">
<?php include "../includes/sidebar.php"; ?>
<div class="main flex-grow-1">
<main class="content">
<div class="container-fluid">

<div class="ph">
    <div class="ph-copy">
        <div class="ph-icon" aria-hidden="true"><i class="bi bi-calendar-month-fill"></i></div>
        <div class="min-w-0">
            <p class="ph-eyebrow">HPU Workbench</p>
            <h1 class="ph-title">Monthly Report — <?= e($monthTitle) ?></h1>
            <p class="ph-subtitle">
                Request cohort: MONTH(date_request). Completions use actual MONTH(final_approval_date); evaluations use MONTH(submitted_at). Pending = max(0, requested − fulfilled).
            </p>
        </div>
    </div>
    <div class="ph-actions no-print">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(BASE_URL) ?>/reports/evaluation_analytics.php?year=<?= (int)$selectedYear ?>">
            <i class="bi bi-bar-chart me-1"></i> Annual Analytics
        </a>
        <a class="btn btn-sm btn-outline-success" href="?<?= e($filterQuery) ?>&amp;export=excel">
            <i class="bi bi-file-earmark-excel me-1"></i> Excel
        </a>
        <a class="btn btn-sm btn-outline-secondary" href="?<?= e($filterQuery) ?>&amp;export=csv">
            <i class="bi bi-filetype-csv me-1"></i> CSV
        </a>
        <button class="btn btn-sm btn-primary" type="button" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print / Save PDF
        </button>
    </div>
</div>

<section class="filter-card no-print">
<form method="get">
<div class="row g-2 align-items-end">
    <div class="col-xl-1 col-md-3">
        <label class="filter-label">Year</label>
        <select class="form-select form-select-sm" name="year">
            <?php foreach ($years as $y): ?>
            <option value="<?= (int)$y ?>" <?= $selectedYear === (int)$y ? "selected" : "" ?>><?= (int)$y ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-xl-2 col-md-3">
        <label class="filter-label">Month</label>
        <select class="form-select form-select-sm" name="month">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $selectedMonth === $m ? "selected" : "" ?>><?= e(mrMonthLong($m)) ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="col-xl-2 col-md-4">
        <label class="filter-label">Department</label>
        <select class="form-select form-select-sm" name="department">
            <option value="">All Departments</option>
            <?php foreach ($departments as $d): ?>
            <option value="<?= e($d["department"]) ?>" <?= $departmentFilter === $d["department"] ? "selected" : "" ?>><?= e($d["department"]) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-xl-2 col-md-4">
        <label class="filter-label">Request Type</label>
        <select class="form-select form-select-sm" name="service">
            <option value="">All Request Types</option>
            <?php foreach ($services as $svc): ?>
            <option value="<?= e($svc) ?>" <?= $serviceFilter === $svc ? "selected" : "" ?>><?= e($svc) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-xl-2 col-md-4">
        <label class="filter-label">HPU Personnel</label>
        <select class="form-select form-select-sm" name="staff">
            <option value="">All HPU Personnel</option>
            <?php foreach ($staffOptions as $person): ?>
                <?php
                $optValue = $hasAssignedTo ? (string)($person["id"] ?? "") : (string)($person["name"] ?? "");
                $optSelected = $staffFilter === $optValue;
                ?>
            <option value="<?= e($optValue) ?>" <?= $optSelected ? "selected" : "" ?>><?= e($person["name"]) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-xl-1 col-md-3">
        <label class="filter-label">Status</label>
        <select class="form-select form-select-sm" name="status">
            <option value="">All</option>
            <?php foreach ($trackerStatuses as $ts): ?>
            <option value="<?= e($ts) ?>" <?= $statusFilter === $ts ? "selected" : "" ?>><?= e($ts) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($hasArchived): ?>
    <div class="col-xl-1 col-md-3">
        <label class="filter-label">Archive</label>
        <select class="form-select form-select-sm" name="archived">
            <option value="all" <?= $archivedFilter === "all" ? "selected" : "" ?>>All</option>
            <option value="active" <?= $archivedFilter === "active" ? "selected" : "" ?>>Active</option>
            <option value="archived" <?= $archivedFilter === "archived" ? "selected" : "" ?>>Archived</option>
        </select>
    </div>
    <?php endif; ?>
    <div class="col-xl-1 col-md-3">
        <button class="btn btn-sm btn-primary w-100" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
    </div>
</div>
</form>
</section>

<section class="kpi-grid">
    <article class="kpi-card"><div class="kpi-label">Requests (cohort)</div><div class="kpi-value"><?= number_format($totalRequests) ?></div><div class="kpi-note"><?= e($monthTitle) ?> · date_request</div></article>
    <article class="kpi-card"><div class="kpi-label">Completed</div><div class="kpi-value"><?= number_format($completedRequests) ?></div><div class="kpi-note"><?= number_format($completedInMonth) ?> completed in-month (actual)</div></article>
    <article class="kpi-card"><div class="kpi-label">Evaluated</div><div class="kpi-value"><?= number_format($evaluatedRequests) ?></div><div class="kpi-note"><?= number_format($responseRate, 1) ?>% response · <?= number_format($pendingEvaluations) ?> pending</div></article>
    <article class="kpi-card"><div class="kpi-label">Avg Rating</div><div class="kpi-value"><?= $ratingCount > 0 ? number_format($averageRating, 2) : "—" ?></div><div class="kpi-note"><?= number_format($evaluatedInMonth) ?> evaluated in-month</div></article>
    <article class="kpi-card"><div class="kpi-label">Avg Turnaround</div><div class="kpi-value"><?= $turnaroundCount > 0 ? number_format($averageTurnaround, 1) . " d" : "—" ?></div><div class="kpi-note"><?= number_format($turnaroundMissing) ?> completed w/o actual date</div></article>
    <article class="kpi-card"><div class="kpi-label">IEC Qty</div><div class="kpi-value"><?= number_format($iecTotalQty) ?></div><div class="kpi-note"><?= number_format($iecTotalDone) ?> done · <?= number_format($iecPending) ?> pending · <?= number_format($iecDistinctRequests) ?> reqs</div></article>
</section>

<section class="card-shell mt-3">
<div class="card-head"><div><h2 class="card-title">Requests by Status — <?= e($monthTitle) ?></h2><div class="card-subtitle"><?= number_format($totalRequests) ?> request(s) in cohort.</div></div></div>
<div class="table-wrap">
<?php if (!$statusCounts): ?>
<div class="empty-state">No requests in this month/filter.</div>
<?php else: ?>
<table class="mr-table"><thead><tr><th>Status</th><th class="text-end">Count</th></tr></thead><tbody>
<?php foreach ($statusCounts as $st => $ct): ?>
<tr><td><span class="status-pill <?= e(mrStatusClass((string)$st)) ?>"><?= e($st) ?></span></td><td class="text-end"><?= number_format((int)$ct) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</div>
</section>

<section class="card-shell mt-3">
<div class="card-head"><div><h2 class="card-title">Performance by Request Type — <?= e($monthTitle) ?></h2></div></div>
<div class="table-wrap"><table class="mr-table"><thead><tr><th>Service</th><th class="text-end">Requests</th><th class="text-end">Completed</th><th class="text-end">Evaluated</th><th class="text-end">Avg Rating</th></tr></thead><tbody>
<?php foreach ($serviceStats as $svc => $ss): ?>
<tr><td class="fw-bold"><?= e($svc) ?></td><td class="text-end"><?= number_format($ss["requests"]) ?></td><td class="text-end"><?= number_format($ss["completed"]) ?></td><td class="text-end"><?= number_format($ss["evaluated"]) ?></td><td class="text-end"><?= $ss["rating_count"] > 0 ? number_format($ss["rating_total"] / $ss["rating_count"], 2) . "/5" : "—" ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</section>

<section class="card-shell mt-3">
<div class="card-head"><div><h2 class="card-title">HPU Personnel Performance — <?= e($monthTitle) ?></h2><div class="card-subtitle">Handled = assigned owner · Reviewed = reviewed_by · Approved = approved_by (supervisors). Variants merged. All HPU listed.</div></div></div>
<div class="table-wrap">
<table class="mr-table"><thead><tr><th>Personnel</th><th>Role</th><th class="text-end">Handled</th><th class="text-end">Reviewed</th><th class="text-end">Approved</th><th class="text-end">Completed</th><th class="text-end">Completion</th><th class="text-end">Evaluated</th><th class="text-end">Avg Rating</th><th class="text-end">Turnaround</th><th class="text-end">Revisions</th></tr></thead><tbody>
<?php foreach ($staffStats as $skey => $st): ?>
<?php
$prole = (string)($st["role"] ?? "");
$pstatus = (string)($st["status"] ?? "");
$roleLabel = match ($prole) { "supervisor" => "Supervisor", "staff" => "HPU Staff", "admin" => "Admin", default => "—" };
?>
<tr>
<td class="fw-bold"><?= e($st["name"] ?? $skey) ?><?php if ($pstatus !== "" && strtolower($pstatus) !== "active"): ?> <span class="service-pill"><?= e($pstatus) ?></span><?php endif; ?></td>
<td><?= e($roleLabel) ?></td>
<td class="text-end"><?= number_format((int)($st["handled"] ?? 0)) ?></td>
<td class="text-end"><?= number_format((int)($st["reviewed"] ?? 0)) ?></td>
<td class="text-end fw-bold"><?= number_format((int)($st["approved"] ?? 0)) ?></td>
<td class="text-end"><?= number_format((int)($st["completed"] ?? 0)) ?></td>
<td class="text-end"><?= number_format((float)($st["completion_rate"] ?? 0), 1) ?>%</td>
<td class="text-end"><?= number_format((int)($st["evaluated"] ?? 0)) ?></td>
<td class="text-end"><?= ($st["rating_count"] ?? 0) > 0 ? number_format((float)$st["average_rating"], 2) . "/5" : "—" ?></td>
<td class="text-end"><?= ($st["turnaround_count"] ?? 0) > 0 ? number_format((float)$st["average_turnaround"], 1) . " d" : "—" ?></td>
<td class="text-end"><?= number_format((int)($st["revisions"] ?? 0)) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr><td class="fw-bold">Total (<?= count($staffStats) ?>)</td><td></td><td class="text-end fw-bold"><?= number_format((int)$staffTotals["handled"]) ?></td><td class="text-end fw-bold"><?= number_format((int)$staffTotals["reviewed"]) ?></td><td class="text-end fw-bold"><?= number_format((int)$staffTotals["approved"]) ?></td><td class="text-end fw-bold"><?= number_format((int)$staffTotals["completed"]) ?></td><td></td><td class="text-end fw-bold"><?= number_format((int)$staffTotals["evaluated"]) ?></td><td></td><td></td><td class="text-end fw-bold"><?= number_format((int)$staffTotals["revisions"]) ?></td></tr></tfoot>
</table>
</div>
</section>

<section class="card-shell mt-3">
<div class="card-head"><div><h2 class="card-title">IEC Materials Consolidation — <?= e($monthTitle) ?></h2><div class="card-subtitle"><?= number_format($iecDistinctRequests) ?> request(s) · <?= number_format($iecTotalQty) ?> requested · <?= number_format($iecTotalDone) ?> fulfilled · <?= number_format(mrPercent($iecTotalDone, $iecTotalQty), 1) ?>% rate.</div></div></div>
<div class="table-wrap">
<?php if (!$hasIecItems): ?>
<div class="empty-state">IEC tracking table not installed.</div>
<?php elseif (!$iecLines): ?>
<div class="empty-state">No IEC line items in this month/filter.</div>
<?php else: ?>
<table class="mr-table"><thead><tr><th>Material</th><th>Department</th><th class="text-end">Qty Req</th><th class="text-end">Qty Done</th><th class="text-end">Pending</th><th class="text-end">Requests</th></tr></thead><tbody>
<?php foreach ($iecLines as $line): ?>
<tr><td><?= e($line["material"] ?? "") ?></td><td><?= e($line["department"] ?? "") ?></td><td class="text-end"><?= number_format((int)($line["qty"] ?? 0)) ?></td><td class="text-end"><?= number_format((int)($line["done"] ?? 0)) ?></td><td class="text-end"><?= number_format(max(0, (int)($line["qty"] ?? 0) - (int)($line["done"] ?? 0))) ?></td><td class="text-end"><?= number_format((int)($line["requests"] ?? 0)) ?></td></tr>
<?php endforeach; ?>
</tbody><tfoot><tr><td class="fw-bold">Total</td><td></td><td class="text-end fw-bold"><?= number_format($iecTotalQty) ?></td><td class="text-end fw-bold"><?= number_format($iecTotalDone) ?></td><td class="text-end fw-bold"><?= number_format($iecPending) ?></td><td class="text-end fw-bold"><?= number_format($iecDistinctRequests) ?></td></tr></tfoot></table>
<?php endif; ?>
</div>
<?php if ($iecTopMaterials): ?>
<div class="card-body-pad"><strong class="small">Top materials:</strong>
<?php foreach ($iecTopMaterials as $tm): ?>
<span class="service-pill"><?= e($tm["label"]) ?> · <?= number_format($tm["qty"]) ?></span>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>

<?php if ($lowRatingRows): ?>
<section class="card-shell mt-3">
<div class="card-head"><div><h2 class="card-title">Low Ratings (1–2) — <?= e($monthTitle) ?></h2></div></div>
<div class="table-wrap"><table class="mr-table"><thead><tr><th>Job No.</th><th>Project</th><th>Owner</th><th>Rating</th><th class="text-end">Action</th></tr></thead><tbody>
<?php foreach (array_slice($lowRatingRows, 0, 12) as $lr): ?>
<?php $lrOwner = trim((string)($lr["assigned_name"] ?? "")) ?: trim((string)($lr["reviewed_by"] ?? "")); ?>
<tr><td class="fw-bold"><?= e($lr["job_no"]) ?></td><td><?= e($lr["project_title"]) ?></td><td><?= e($lrOwner ?: "—") ?></td><td><?= mrCombinedRating($lr) ?>/5 · <?= e(mrRatingLabel(mrCombinedRating($lr))) ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/staff/review_request.php?id=<?= (int)$lr["id"] ?>">Open</a></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</section>
<?php endif; ?>

<section class="card-shell mt-3">
<div class="card-head"><div><h2 class="card-title">Detailed Records — <?= e($monthTitle) ?></h2><div class="card-subtitle"><?= number_format(count($rows)) ?> record(s). Scroll vertically &amp; horizontally — header stays pinned.</div></div></div>
<div class="table-wrap table-wrap-scroll mr-scroll">
<?php if (!$rows): ?>
<div class="empty-state">No records match the selected filters.</div>
<?php else: ?>
<table class="mr-table"><thead><tr><th>Job No.</th><th>Project / Dept</th><th>Type</th><th>Owner</th><th>Status</th><th class="text-end">Turnaround</th><th>Rating</th><th class="text-end">Action</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<?php
$rating = mrCombinedRating($row);
$owner = trim((string)($row["assigned_name"] ?? "")) ?: trim((string)($row["reviewed_by"] ?? ""));
$ta = mrDateDiffDays(mrValidDateOrNull($row["date_request"] ?? null), mrActualCompletion($row));
?>
<tr>
<td class="fw-bold"><?= e($row["job_no"]) ?></td>
<td><div class="fw-bold"><?= e($row["project_title"]) ?></div><div class="text-muted small"><?= e($row["department"]) ?> · <?= e($row["requestor_name"] ?? "") ?></div></td>
<td><?php foreach (mrDecodeArray($row["request_types"] ?? null) as $t): ?><span class="service-pill"><?= e($t) ?></span><?php endforeach; ?></td>
<td><?= e($owner ?: "—") ?></td>
<td><span class="status-pill <?= e(mrStatusClass((string)$row["approval_status"])) ?>"><?= e($row["approval_status"]) ?></span></td>
<td class="text-end"><?= $ta !== null ? number_format($ta) . " d" : "—" ?></td>
<td><?= $rating > 0 ? $rating . "/5" : "—" ?></td>
<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/staff/review_request.php?id=<?= (int)$row["id"] ?>">Open</a></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<div class="mr-scroll-hint no-print">
    <i class="bi bi-arrows-move me-1"></i>
    Scroll vertically &amp; horizontally to explore all <?= number_format(count($rows)) ?> record(s) — header stays pinned.
</div>
<?php endif; ?>
</div>
</section>

</div>
</main>
</div>
</div>
</body>
</html>
