<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";

requireRole([
    "staff",
    "supervisor",
    "admin"
]);

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
        SUM(approval_status = 'In Development') AS development_total,
        SUM(approval_status = 'Completed') AS completed_total,
        SUM(approval_status = 'For Evaluation') AS evaluation_total,
        SUM(approval_status = 'Closed') AS closed_total
     FROM job_requests
     WHERE COALESCE(archived, 0) = 0"
);

$summary = $summaryResult->fetch_assoc() ?: [];

$totalRequests = (int) ($summary["total"] ?? 0);
$pendingTotal = (int) ($summary["pending_total"] ?? 0);
$reviewTotal = (int) ($summary["review_total"] ?? 0);
$approvedTotal = (int) ($summary["approved_total"] ?? 0);
$developmentTotal = (int) ($summary["development_total"] ?? 0);
$completedTotal = (int) ($summary["completed_total"] ?? 0);
$evaluationTotal = (int) ($summary["evaluation_total"] ?? 0);
$closedTotal = (int) ($summary["closed_total"] ?? 0);

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
        id,
        job_no,
        requestor_name,
        department,
        project_title,
        date_request,
        date_completion,
        reviewed_by,
        reviewed_date,
        approved_by,
        approved_date,
        approval_status,
        satisfaction_rating,
        created_at
    FROM job_requests
    WHERE 1 = 1
      AND COALESCE(archived, 0) = 0
";

$types = "";
$params = [];

if ($search !== "") {
    $sql .= "
        AND (
            job_no LIKE ?
            OR requestor_name LIKE ?
            OR department LIKE ?
            OR project_title LIKE ?
            OR approval_status LIKE ?
        )
    ";

    $searchValue = "%" . $search . "%";
    $types .= "sssss";

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
        default => "bi-info-circle"
    };
}

function reviewButtonLabel(string $role, string $status): string
{
    if (in_array($status, ["Completed", "For Evaluation", "Closed"], true)) {
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
    if (in_array($status, ["Completed", "For Evaluation", "Closed"], true)) {
        return "bi-tools";
    }

    return $role === "supervisor"
        ? "bi-patch-check"
        : "bi-clipboard-check";
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
        default => 0
    };
}

function requestWorkflowState(
    int $stepIndex,
    int $currentIndex,
    string $status
): string {
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
    "supervisor" => "Approve reviewed requests and monitor development and evaluation.",
    "admin" => "Review, approve, monitor and manage all job requests.",
    default => "Review submissions, update development details and monitor evaluations."
};

$activeFilterCount = ($search !== "" ? 1 : 0)
    + ($statusFilter !== "" ? 1 : 0)
    + ($departmentFilter !== "" ? 1 : 0);

$filteredTotal = count($requests);

$summaryCards = [
    ["label" => "All", "status" => "", "count" => $totalRequests, "icon" => "bi-inbox", "class" => "metric-all"],
    ["label" => "Pending", "status" => "Pending Review", "count" => $pendingTotal, "icon" => "bi-hourglass-split", "class" => "metric-pending"],
    ["label" => "Review", "status" => "Under Review", "count" => $reviewTotal, "icon" => "bi-search", "class" => "metric-review"],
    ["label" => "Approved", "status" => "Approved", "count" => $approvedTotal, "icon" => "bi-check-circle", "class" => "metric-approved"],
    ["label" => "Development", "status" => "In Development", "count" => $developmentTotal, "icon" => "bi-tools", "class" => "metric-development"],
    ["label" => "Completed", "status" => "Completed", "count" => $completedTotal, "icon" => "bi-check2-all", "class" => "metric-completed"],
    ["label" => "Evaluation", "status" => "For Evaluation", "count" => $evaluationTotal, "icon" => "bi-clipboard2-check", "class" => "metric-evaluation"],
    ["label" => "Closed", "status" => "Closed", "count" => $closedTotal, "icon" => "bi-lock", "class" => "metric-closed"]
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
            grid-template-columns: repeat(8, minmax(105px, 1fr));
            gap: 8px;
            margin-bottom: 10px;
            overflow-x: auto;
            padding-bottom: 2px;
        }

        .metric-card {
            --metric-color: #55708f;
            --metric-bg: #f3f6fa;
            display: grid;
            min-width: 105px;
            grid-template-columns: 30px minmax(0, 1fr);
            align-items: center;
            gap: 8px;
            padding: 9px 10px;
            border: 1px solid var(--request-border);
            border-radius: 10px;
            background: #fff;
            color: inherit;
            text-decoration: none;
            box-shadow: 0 3px 12px rgba(28, 42, 72, .035);
            transition: .16s ease;
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

        .filter-panel {
            margin-bottom: 10px;
            border: 1px solid var(--request-border);
            border-radius: 11px;
            background: #fff;
            box-shadow: var(--request-shadow);
        }

        .filter-panel .card-body {
            padding: 10px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: minmax(260px, 1.6fr) minmax(170px, .65fr) minmax(180px, .75fr) auto;
            gap: 8px;
            align-items: end;
        }

        .compact-label {
            margin-bottom: 4px;
            color: var(--request-muted);
            font-size: .63rem;
            font-weight: 850;
            letter-spacing: .045em;
            text-transform: uppercase;
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
            min-height: 36px;
            border-color: var(--request-border-strong);
            border-radius: 8px;
            font-size: .78rem;
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
        }

        .filter-actions .btn {
            min-height: 36px;
            border-radius: 8px;
            font-size: .76rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .active-filters {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 8px;
            padding-top: 8px;
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

        .request-list-header,
        .request-row {
            display: grid;
            grid-template-columns: minmax(290px, 2.05fr) minmax(160px, 1fr) minmax(145px, .85fr) minmax(150px, .9fr) minmax(220px, auto);
            gap: 12px;
            align-items: center;
        }

        .request-list-header {
            padding: 7px 12px;
            border-bottom: 1px solid var(--request-border);
            background: #f5f7fa;
            color: #768399;
            font-size: .61rem;
            font-weight: 850;
            letter-spacing: .045em;
            text-transform: uppercase;
        }

        .request-list-body {
            display: grid;
            gap: 8px;
            padding: 8px;
            background: #f2f5f9;
        }

        .request-row {
            --status-accent: #8793a4;
            --status-border: #d9e0e9;
            --status-soft: #f7f9fc;
            position: relative;
            min-height: 86px;
            padding: 10px 12px 10px 15px;
            border: 1px solid var(--status-border);
            border-radius: 11px;
            background: linear-gradient(90deg, var(--status-soft) 0, #fff 25%, #fff 100%);
            box-shadow: 0 2px 8px rgba(35, 48, 72, .035);
            transition: transform .14s ease, border-color .14s ease, box-shadow .14s ease;
        }

        .request-row:last-child {
            border-bottom: 1px solid var(--status-border);
        }

        .request-row::before {
            position: absolute;
            top: 12px;
            bottom: 12px;
            left: 0;
            width: 3px;
            border-radius: 0 4px 4px 0;
            background: var(--status-accent);
            content: "";
        }

        .request-row:hover {
            z-index: 1;
            transform: translateY(-1px);
            border-color: var(--status-accent);
            box-shadow: 0 8px 20px rgba(35, 48, 72, .09);
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

        .request-row.status-default {
            --status-accent: #8793a4;
            --status-border: #d9e0e9;
            --status-soft: #f7f9fc;
        }

        .request-primary {
            min-width: 0;
        }

        .request-kicker {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 3px;
        }

        .job-number {
            color: #45536b;
            font-size: .69rem;
            font-weight: 900;
            letter-spacing: .035em;
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

        .status-default.status-badge {
            background: #eef1f5;
            color: #566174;
        }

        .project-title {
            overflow: hidden;
            margin: 0;
            color: #182135;
            font-size: .88rem;
            font-weight: 850;
            line-height: 1.32;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .request-subline {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 4px;
            color: var(--request-muted);
            font-size: .67rem;
        }

        .evaluation-complete {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: var(--request-success);
            font-weight: 800;
        }

        .cell-label {
            display: none;
            margin-bottom: 3px;
            color: var(--request-muted);
            font-size: .59rem;
            font-weight: 850;
            letter-spacing: .04em;
            text-transform: uppercase;
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

        body.ultra-compact .request-subline,
        body.ultra-compact .cell-note,
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

            .request-list-header,
            .request-row {
                grid-template-columns: minmax(270px, 1.9fr) minmax(145px, .9fr) minmax(135px, .75fr) minmax(135px, .8fr) minmax(185px, auto);
                gap: 10px;
            }

            .request-actions .action-label-secondary {
                display: none;
            }
        }

        @media (max-width: 1120px) {
            .metrics-strip {
                grid-template-columns: repeat(8, 112px);
            }

            .filter-grid {
                grid-template-columns: minmax(240px, 1fr) minmax(160px, .7fr) minmax(180px, .8fr);
            }

            .filter-actions {
                grid-column: 1 / -1;
                justify-content: flex-end;
            }

            .request-list-header {
                display: none;
            }

            .request-row {
                grid-template-columns: minmax(260px, 1.4fr) repeat(2, minmax(140px, .75fr)) minmax(195px, auto);
            }

            .request-row .review-cell {
                display: none;
            }

            .cell-label {
                display: block;
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
                grid-template-columns: 1fr 1fr;
            }

            .filter-search-field,
            .filter-actions {
                grid-column: 1 / -1;
            }

            .request-row {
                grid-template-columns: 1fr auto;
                gap: 9px;
                padding: 10px 10px 10px 14px;
            }

            .request-row .person-cell,
            .request-row .date-cell,
            .request-row .review-cell {
                display: none;
            }

            .request-actions .quick-review-btn {
                display: none;
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

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-search-field,
            .filter-actions {
                grid-column: auto;
            }

            .filter-actions .btn-primary {
                flex: 1;
            }

            .request-row {
                grid-template-columns: minmax(0, 1fr) auto;
            }

            .project-title {
                white-space: normal;
            }

            .request-actions .quick-view-btn .action-label {
                display: none;
            }

            .request-actions .quick-view-btn {
                width: 31px;
                padding: 5px;
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

                    <header class="ph">
                        <div class="ph-copy">
                            <div class="ph-icon" aria-hidden="true">
                                <i class="bi bi-kanban"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="ph-eyebrow">HPU Workbench</p>
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
                                <?= number_format($filteredTotal) ?> result
                                <?= $filteredTotal === 1 ? "" : "s" ?>
                            </span>

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
                                                placeholder="Job no., project, requestor or department"
                                                autocomplete="off">
                                        </div>
                                    </div>

                                    <div>
                                        <label class="compact-label" for="statusFilter">Status</label>
                                        <select id="statusFilter" class="form-select" name="status">
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
                                                "Closed"
                                            ] as $filterStatus): ?>
                                                <option value="<?= e($filterStatus) ?>" <?= $statusFilter === $filterStatus ? "selected" : "" ?>
                                                    >
                                                    <?= e($filterStatus) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="compact-label" for="departmentFilter">Department</label>
                                        <select id="departmentFilter" class="form-select" name="department">
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
                                        <button class="btn btn-primary" type="submit">
                                            <i class="bi bi-funnel"></i>
                                            Apply
                                        </button>

                                        <a class="btn btn-outline-secondary" href="requests.php"
                                            title="Clear all filters">
                                            <i class="bi bi-x-lg"></i>
                                            Clear
                                        </a>
                                    </div>
                                </div>

                                <?php if ($activeFilterCount > 0): ?>
                                    <div class="active-filters">
                                        <span class="active-filter-label">
                                            <i class="bi bi-funnel-fill me-1"></i>
                                            Active filters:
                                        </span>

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
                                <span class="badge text-bg-light border">
                                    <?= number_format($filteredTotal) ?>
                                </span>
                            </div>

                            <div class="list-toolbar-note d-none d-md-block">
                                Color-coded by status · newest requests first
                            </div>
                        </div>

                        <div class="request-list-header" aria-hidden="true">
                            <div>Request / Project</div>
                            <div>Requestor</div>
                            <div>Schedule</div>
                            <div>Review / Approval</div>
                            <div class="text-end">Actions</div>
                        </div>

                        <div class="request-list-body" id="requestListBody">
                            <?php foreach ($requests as $row): ?>
                                <?php
                                $status = (string) ($row["approval_status"] ?? "");
                                $evaluationSubmitted = $row["satisfaction_rating"] !== null;

                                $canNotifyAnytime = in_array(
                                    $role,
                                    ["staff", "supervisor"],
                                    true
                                );

                                $showEvaluationButton = $canNotifyAnytime
                                    || in_array(
                                        $status,
                                        ["Completed", "For Evaluation", "Closed"],
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

                                $completionTimestamp = !empty($row["date_completion"])
                                    ? strtotime((string) $row["date_completion"])
                                    : false;

                                $daysRemaining = $completionTimestamp
                                    ? (int) floor(($completionTimestamp - strtotime("today")) / 86400)
                                    : null;

                                $isFinished = in_array(
                                    $status,
                                    ["Completed", "For Evaluation", "Closed"],
                                    true
                                );

                                $workflowCurrentIndex = requestWorkflowIndex($status);
                                $workflowCollapseId = "workflow-tracking-" . (int) $row["id"];
                                ?>

                                <article class="request-row <?= e(requestStatusClass($status)) ?>">
                                    <div class="request-primary">
                                        <div class="request-kicker">
                                            <span class="job-number">
                                                <i class="bi bi-hash"></i>
                                                <?= e($row["job_no"] ?? "") ?>
                                            </span>

                                            <span class="status-badge <?= e(requestStatusClass($status)) ?>">
                                                <i class="bi <?= e(requestStatusIcon($status)) ?>"></i>
                                                <?= e($status ?: "Unknown") ?>
                                            </span>
                                        </div>

                                        <h2 class="project-title" title="<?= e($row["project_title"] ?? "") ?>">
                                            <?= e($row["project_title"] ?: "Untitled Project") ?>
                                        </h2>

                                        <div class="request-subline">
                                            <span>
                                                <i class="bi bi-clock-history me-1"></i>
                                                <?= $requestAgeDays !== null
                                                    ? number_format($requestAgeDays) . " day" . ($requestAgeDays === 1 ? "" : "s") . " old"
                                                    : "Age unavailable"
                                                    ?>
                                            </span>

                                            <?php if ($evaluationSubmitted): ?>
                                                <span class="evaluation-complete">
                                                    <i class="bi bi-check-circle-fill"></i>
                                                    Evaluated
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="person-cell">
                                        <div class="cell-label">Requestor</div>
                                        <div class="cell-value" title="<?= e($row["requestor_name"] ?? "") ?>">
                                            <i class="bi bi-person me-1 text-muted"></i>
                                            <?= e($row["requestor_name"] ?: "Not specified") ?>
                                        </div>
                                        <div class="cell-note" title="<?= e($row["department"] ?? "") ?>">
                                            <i class="bi bi-building"></i>
                                            <?= e($row["department"] ?: "No department") ?>
                                        </div>
                                    </div>

                                    <div class="date-cell">
                                        <div class="cell-label">Schedule</div>
                                        <div class="cell-value">
                                            <i class="bi bi-calendar-plus me-1 text-muted"></i>
                                            <?= e(compactDate($row["date_request"] ?? null)) ?>
                                        </div>

                                        <?php if ($daysRemaining !== null && !$isFinished): ?>
                                            <div class="cell-note <?= $daysRemaining < 0 ? "danger" : ($daysRemaining <= 3 ? "warning" : "") ?>">
                                                <i class="bi <?= $daysRemaining < 0 ? "bi-exclamation-circle" : "bi-calendar-check" ?>"></i>
                                                <?= $daysRemaining < 0
                                                    ? number_format(abs($daysRemaining)) . " day" . (abs($daysRemaining) === 1 ? "" : "s") . " overdue"
                                                    : number_format($daysRemaining) . " day" . ($daysRemaining === 1 ? "" : "s") . " remaining"
                                                    ?>
                                            </div>
                                        <?php elseif ($completionTimestamp): ?>
                                            <div class="cell-note positive">
                                                <i class="bi bi-calendar-check"></i>
                                                Target
                                                <?= e(compactDate($row["date_completion"] ?? null)) ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="cell-note">
                                                <i class="bi bi-calendar-x"></i>
                                                No target date
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="review-cell">
                                        <div class="cell-label">Review / Approval</div>
                                        <div class="cell-value" title="<?= e($row["reviewed_by"] ?? "") ?>">
                                            <i class="bi bi-person-check me-1 text-muted"></i>
                                            <?= e($row["reviewed_by"] ?: "Not reviewed") ?>
                                        </div>
                                        <div class="cell-note <?= !empty($row["approved_by"]) ? "positive" : "" ?>" title="
                                        <?= e($row["approved_by"] ?? "") ?>">
                                            <i class="bi bi-patch-check"></i>
                                            Approved by:
                                            <?= e($row["approved_by"] ?: "Pending") ?>
                                            <?php if (!empty($row["approved_date"])): ?>
                                                ·
                                                <?= e(compactDate($row["approved_date"])) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="request-actions no-print">
                                        <a class="btn btn-outline-primary quick-view-btn"
                                            href="<?= e(BASE_URL) ?>/admin/view.php?id=<?= (int) $row["id"] ?>"
                                            title="Open request details"
                                            >
                                            <i class="bi bi-eye"></i>
                                            <span class="action-label">View</span>
                                        </a>

                                        <button class="btn workflow-toggle-btn" type="button" data-bs-toggle="collapse"
                                            data-bs-target="#<?= e($workflowCollapseId) ?>" aria-expanded="false"
                                            aria-controls="<?= e($workflowCollapseId) ?>" title="Show workflow tracking">
                                            <i class="bi bi-diagram-3"></i>
                                            <span class="action-label workflow-toggle-label">Track</span>
                                            <i class="bi bi-chevron-down"></i>
                                        </button>

                                        <a class="btn btn-primary quick-review-btn"
                                            href="<?= e(BASE_URL) ?>/staff/review_request.php?id=<?= (int) $row["id"] ?>"
                                            title="
                                        <?= e(reviewButtonLabel($role, $status)) ?>"
                                            >
                                            <i class="bi <?= e(reviewButtonIcon($role, $status)) ?>"></i>
                                            <span class="action-label-secondary">
                                                <?= e(reviewButtonLabel($role, $status)) ?>
                                            </span>
                                        </a>

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
    <?php endif; ?>

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
        });
    </script>
</body>

</html>