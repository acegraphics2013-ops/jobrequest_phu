<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
include "../includes/advanced_helpers.php";
require_once "../includes/module_access.php";

requireRole(["admin"]);
requireModule($conn, "archived_requests");

$role = (string)($_SESSION["role"] ?? "admin");
$canRestore = true;

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION["csrf_token"];

/* =========================================================
   Helpers
========================================================= */
function arArchivedAgo(?string $datetime): string
{
    if (!$datetime) {
        return "—";
    }

    try {
        $then = new DateTime($datetime);
        $now = new DateTime();
        $diff = $now->diff($then);

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
        return $datetime;
    }
}

function arStatusClass(string $status): string
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

function arStatusIcon(string $status): string
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
        default => "bi-archive-fill"
    };
}

function arInitials(string $name): string
{
    $name = trim($name);
    if ($name === "" || $name === "Unknown") {
        return "—";
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    $ini = "";
    foreach (array_slice($parts, 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $ini !== "" ? $ini : "—";
}

function arFilterUrl(string $search, string $status, string $department, string $sort): string
{
    $q = [];
    if ($search !== "") {
        $q["search"] = $search;
    }
    if ($status !== "") {
        $q["status"] = $status;
    }
    if ($department !== "") {
        $q["department"] = $department;
    }
    if ($sort !== "" && $sort !== "newest") {
        $q["sort"] = $sort;
    }
    return "archived_requests.php" . ($q ? "?" . http_build_query($q) : "");
}

function arCompactDate(?string $value): string
{
    if (!$value) {
        return "—";
    }
    $ts = strtotime($value);
    return $ts !== false ? date("M d, Y", $ts) : "—";
}

/* =========================================================
   Filters (server-side, shareable URLs)
========================================================= */
$message = $_SESSION["request_message"] ?? null;
unset($_SESSION["request_message"]);

$search = trim((string)($_GET["search"] ?? ""));
$statusFilter = trim((string)($_GET["status"] ?? ""));
$departmentFilter = trim((string)($_GET["department"] ?? ""));
$sortParam = trim((string)($_GET["sort"] ?? "newest"));

$allowedSort = ["newest", "oldest", "title", "job_no"];
if (!in_array($sortParam, $allowedSort, true)) {
    $sortParam = "newest";
}

$orderSql = match ($sortParam) {
    "oldest" => "jr.archived_at ASC, jr.id ASC",
    "title" => "jr.project_title ASC, jr.id DESC",
    "job_no" => "jr.job_no ASC, jr.id DESC",
    default => "jr.archived_at DESC, jr.id DESC",
};

$sortLabels = [
    "newest" => "Newest first",
    "oldest" => "Oldest first",
    "title" => "Title A–Z",
    "job_no" => "Job no. A–Z",
];

/* Summary for stat cards (unfiltered — whole vault) */
$vaultTotal = 0;
$vaultThisMonth = 0;
$vaultDepartments = 0;
$vaultOldest = null;

$summaryStmt = $conn->query(
    "SELECT COUNT(*) AS total,
            SUM(DATE_FORMAT(archived_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')) AS this_month,
            COUNT(DISTINCT NULLIF(TRIM(department), '')) AS departments,
            MIN(archived_at) AS oldest
     FROM job_requests WHERE archived = 1"
);
if ($summaryStmt && ($srow = $summaryStmt->fetch_assoc())) {
    $vaultTotal = (int)($srow["total"] ?? 0);
    $vaultThisMonth = (int)($srow["this_month"] ?? 0);
    $vaultDepartments = (int)($srow["departments"] ?? 0);
    $vaultOldest = $srow["oldest"] ?? null;
}

/* Status counts for filter options (whole vault) */
$statusCounts = [];
$scRes = $conn->query(
    "SELECT COALESCE(NULLIF(TRIM(approval_status), ''), 'Closed') AS st, COUNT(*) AS c
     FROM job_requests WHERE archived = 1 GROUP BY st ORDER BY c DESC, st ASC"
);
if ($scRes) {
    while ($r = $scRes->fetch_assoc()) {
        $statusCounts[(string)$r["st"]] = (int)$r["c"];
    }
}

/* Department options (whole vault) */
$departments = [];
$deptRes = $conn->query(
    "SELECT DISTINCT TRIM(department) AS d FROM job_requests
     WHERE archived = 1 AND department IS NOT NULL AND TRIM(department) <> ''
     ORDER BY d ASC"
);
if ($deptRes) {
    while ($r = $deptRes->fetch_assoc()) {
        $departments[] = (string)$r["d"];
    }
}

/* Main query (filtered) */
$sql = "
    SELECT
        jr.id,
        jr.job_no,
        jr.project_title,
        jr.requestor_name,
        jr.department,
        jr.approval_status,
        jr.archived_at,
        u.name AS archived_by_name
    FROM job_requests jr
    LEFT JOIN users u ON u.id = jr.archived_by
    WHERE jr.archived = 1
";

$params = [];
$types = "";

if ($search !== "") {
    $sql .= " AND (jr.job_no LIKE ? OR jr.project_title LIKE ? OR jr.requestor_name LIKE ? OR jr.department LIKE ?)";
    $v = "%" . $search . "%";
    $params = [$v, $v, $v, $v];
    $types = "ssss";
}

if ($statusFilter !== "") {
    $sql .= " AND jr.approval_status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($departmentFilter !== "") {
    $sql .= " AND jr.department = ?";
    $params[] = $departmentFilter;
    $types .= "s";
}

$sql .= " ORDER BY " . $orderSql;

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$filteredTotal = count($requests);
$activeFilterCount = ($search !== "" ? 1 : 0) + ($statusFilter !== "" ? 1 : 0) + ($departmentFilter !== "" ? 1 : 0);
$hasFilters = $activeFilterCount > 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Archived Requests</title>

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
        :root{
            --ar-bg:#f4f7fb; --ar-border:#dfe6ef; --ar-text:#172033; --ar-muted:#667085;
            --ar-shadow:0 8px 24px rgba(28,42,72,.06);
        }
        body{background:var(--ar-bg);color:var(--ar-text)}
        .content{padding:18px}
        .container-fluid{max-width:1500px;margin:0 auto}
        .kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
        .kpi-card{display:flex;gap:12px;align-items:center;padding:13px 14px;border:1px solid var(--ar-border);border-radius:14px;background:#fff;box-shadow:var(--ar-shadow)}
        .kpi-ic{width:42px;height:42px;flex:0 0 42px;display:grid;place-items:center;border-radius:12px;font-size:18px}
        .kpi-ic.vault{background:#eff8ff;color:#175cd3}
        .kpi-ic.month{background:#ecfdf3;color:#027a48}
        .kpi-ic.dept{background:#f5f3ff;color:#6d28d9}
        .kpi-ic.time{background:#fff7ed;color:#b54708}
        .kpi-label{font-size:.58rem;color:var(--ar-muted);font-weight:900;text-transform:uppercase;letter-spacing:.05em}
        .kpi-value{font-size:1.4rem;font-weight:900;line-height:1.1}
        .kpi-note{font-size:.6rem;color:#98a2b3}
        .panel{border:1px solid var(--ar-border);border-radius:14px;background:#fff;box-shadow:var(--ar-shadow);overflow:hidden}
        .panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:13px 16px;border-bottom:1px solid #edf1f6;flex-wrap:wrap}
        .panel-title{margin:0;font-size:.92rem;font-weight:800;display:flex;align-items:center;gap:8px}
        .panel-title i{color:#2563eb}
        .result-meta{font-size:.66rem;color:var(--ar-muted)}
        .filter-bar{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:8px;padding:12px 14px;border-bottom:1px solid #edf1f6;background:#fbfcfe}
        .filter-label{display:block;margin-bottom:3px;color:#667085;font-size:.56rem;font-weight:900;text-transform:uppercase;letter-spacing:.04em}
        .search-wrap{position:relative}
        .search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#98a2b3;font-size:.8rem}
        .search-wrap input{padding-left:30px}
        .active-filters{display:flex;gap:6px;flex-wrap:wrap;padding:10px 14px;border-bottom:1px solid #edf1f6;background:#fff}
        .f-chip{display:inline-flex;align-items:center;gap:6px;font-size:.62rem;font-weight:800;padding:4px 6px 4px 10px;border-radius:999px;background:#eff6ff;color:#175cd3;border:1px solid #bfdbfe}
        .f-chip a{color:inherit;text-decoration:none;display:grid;place-items:center;width:18px;height:18px;border-radius:50%;background:rgba(23,92,211,.12)}
        .table-wrap{overflow:auto}
        table.ar-table{width:100%;border-collapse:collapse;min-width:900px}
        .ar-table th{padding:9px 12px;background:#f8fafc;border-bottom:1px solid #eaecf0;color:#667085;font-size:.56rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;text-align:left}
        .ar-table td{padding:11px 12px;border-bottom:1px solid #f0f2f5;vertical-align:middle;font-size:.68rem}
        .ar-table tbody tr{transition:background .12s ease}
        .ar-table tbody tr:hover{background:#f8fbff}
        .req-id{display:flex;align-items:center;gap:10px;min-width:260px}
        .avatar{width:36px;height:36px;flex:0 0 36px;display:grid;place-items:center;border-radius:10px;background:#eef2ff;color:#3538cd;font-size:.62rem;font-weight:900}
        .job-no{font-size:.6rem;font-weight:900;color:#175cd3;letter-spacing:.02em}
        .req-title{font-weight:800;color:#1d2939;line-height:1.3}
        .req-sub{font-size:.62rem;color:var(--ar-muted);margin-top:1px}
        .status-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 9px;border-radius:999px;font-size:.6rem;font-weight:850;white-space:nowrap}
        .status-pending{background:#fef9e7;color:#92400e}
        .status-review{background:#eff8ff;color:#175cd3}
        .status-approved{background:#eef4ff;color:#3538cd}
        .status-development{background:#f5f3ff;color:#6d28d9}
        .status-revision{background:#fff7ed;color:#b54708}
        .status-disapproved{background:#fef3f2;color:#b42318}
        .status-completed{background:#ecfdf3;color:#027a48}
        .status-evaluation{background:#f0fdfa;color:#0f766e}
        .status-closed{background:#f2f4f7;color:#475467}
        .status-default{background:#f2f4f7;color:#475467}
        .arch-when{font-size:.64rem;font-weight:700}
        .arch-by{font-size:.6rem;color:var(--ar-muted)}
        .row-actions{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}
        .row-actions .btn{font-size:.64rem;font-weight:800}
        .empty-state{padding:52px 16px;text-align:center;color:var(--ar-muted)}
        .empty-state i{font-size:2.4rem;color:#d0d5dd}
        @media(max-width:1100px){.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-bar{grid-template-columns:1fr 1fr}}
        @media(max-width:640px){.content{padding:12px}.kpi-grid{grid-template-columns:1fr}.filter-bar{grid-template-columns:1fr}}
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
                        <div class="ph-icon" aria-hidden="true">
                            <i class="bi bi-archive-fill"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="ph-eyebrow">Administration · Records Vault</p>
                            <h1 class="ph-title">Archived Requests</h1>
                            <p class="ph-subtitle">Closed requests retained for audit and reporting. Restore any record to return it to the active workflow.</p>
                        </div>
                    </div>

                    <div class="ph-actions">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/admin/requests.php">
                            <i class="bi bi-briefcase me-1"></i>Active Requests
                        </a>
                        <a class="btn btn-sm btn-outline-secondary" href="archived_requests.php" title="Reload vault">
                            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                        </a>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div
                        class="alert alert-<?= e(
                            $message["type"] ?? "success"
                        ) ?> alert-dismissible fade show"
                    >
                        <?= e(
                            $message["text"] ?? ""
                        ) ?>
                        <button class="btn-close" type="button" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-ic vault"><i class="bi bi-archive-fill"></i></div>
                        <div>
                            <div class="kpi-label">Total in Vault</div>
                            <div class="kpi-value"><?= number_format($vaultTotal) ?></div>
                            <div class="kpi-note"><?= $filteredTotal ?> shown by current filters</div>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-ic month"><i class="bi bi-calendar2-check"></i></div>
                        <div>
                            <div class="kpi-label">Archived This Month</div>
                            <div class="kpi-value"><?= number_format($vaultThisMonth) ?></div>
                            <div class="kpi-note"><?= e(date("F Y")) ?></div>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-ic dept"><i class="bi bi-building"></i></div>
                        <div>
                            <div class="kpi-label">Departments</div>
                            <div class="kpi-value"><?= number_format($vaultDepartments) ?></div>
                            <div class="kpi-note"><?= count($departments) ?> in vault</div>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-ic time"><i class="bi bi-hourglass-split"></i></div>
                        <div>
                            <div class="kpi-label">Oldest Record</div>
                            <div class="kpi-value" style="font-size:1.05rem"><?= e(arCompactDate($vaultOldest)) ?></div>
                            <div class="kpi-note"><?= e(arArchivedAgo($vaultOldest)) ?></div>
                        </div>
                    </div>
                </div>

                <section class="panel">
                    <div class="panel-head">
                        <h2 class="panel-title"><i class="bi bi-funnel"></i>Browse Vault</h2>
                        <span class="result-meta" id="resultMeta">
                            Showing <?= number_format($filteredTotal) ?> of <?= number_format($vaultTotal) ?> archived
                            <?= $hasFilters ? "· " . (int) $activeFilterCount . " filter(s) active" : "" ?>
                        </span>
                    </div>

                    <form method="GET" id="vaultFilters">
                        <div class="filter-bar">
                            <div>
                                <label class="filter-label" for="vaultSearch">Search</label>
                                <div class="search-wrap">
                                    <i class="bi bi-search"></i>
                                    <input
                                        class="form-control form-control-sm"
                                        id="vaultSearch"
                                        name="search"
                                        value="<?= e($search) ?>"
                                        placeholder="Job no., title, requestor, department…  ( / )"
                                        autocomplete="off"
                                    >
                                </div>
                            </div>
                            <div>
                                <label class="filter-label" for="vaultStatus">Status</label>
                                <select class="form-select form-select-sm" id="vaultStatus" name="status">
                                    <option value="">All statuses (<?= number_format($vaultTotal) ?>)</option>
                                    <?php foreach ($statusCounts as $st => $c): ?>
                                    <option value="<?= e($st) ?>" <?= $statusFilter === (string) $st ? "selected" : "" ?>>
                                        <?= e($st) ?> (<?= number_format($c) ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="filter-label" for="vaultDept">Department</label>
                                <select class="form-select form-select-sm" id="vaultDept" name="department">
                                    <option value="">All departments</option>
                                    <?php foreach ($departments as $d): ?>
                                    <option value="<?= e($d) ?>" <?= $departmentFilter === $d ? "selected" : "" ?>><?= e($d) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="filter-label" for="vaultSort">Sort</label>
                                <select class="form-select form-select-sm" id="vaultSort" name="sort">
                                    <?php foreach ($sortLabels as $k => $label): ?>
                                    <option value="<?= e($k) ?>" <?= $sortParam === $k ? "selected" : "" ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="d-flex align-items-end gap-2">
                                <button class="btn btn-sm btn-primary" type="submit">
                                    <i class="bi bi-funnel me-1"></i>Apply
                                </button>
                                <?php if ($hasFilters): ?>
                                <a class="btn btn-sm btn-light" href="archived_requests.php">Clear</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>

                    <?php if ($hasFilters): ?>
                    <div class="active-filters">
                        <?php if ($search !== ""): ?>
                        <span class="f-chip">Search: “<?= e($search) ?>”
                            <a href="<?= e(arFilterUrl("", $statusFilter, $departmentFilter, $sortParam)) ?>" title="Remove search">×</a>
                        </span>
                        <?php endif; ?>
                        <?php if ($statusFilter !== ""): ?>
                        <span class="f-chip">Status: <?= e($statusFilter) ?>
                            <a href="<?= e(arFilterUrl($search, "", $departmentFilter, $sortParam)) ?>" title="Remove status">×</a>
                        </span>
                        <?php endif; ?>
                        <?php if ($departmentFilter !== ""): ?>
                        <span class="f-chip">Dept: <?= e($departmentFilter) ?>
                            <a href="<?= e(arFilterUrl($search, $statusFilter, "", $sortParam)) ?>" title="Remove department">×</a>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="table-wrap">
                        <?php if ($requests): ?>
                        <table class="ar-table" id="vaultTable">
                            <thead>
                                <tr>
                                    <th>Request</th>
                                    <th>Status</th>
                                    <th>Archived</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $request): ?>
                                <?php
                                    $st = (string) ($request["approval_status"] ?? "Closed");
                                    $archBy = trim((string) ($request["archived_by_name"] ?? ""));
                                    if ($archBy === "") { $archBy = "Unknown"; }
                                ?>
                                <tr>
                                    <td>
                                        <div class="req-id">
                                            <div class="avatar"><?= e(arInitials((string) ($request["requestor_name"] ?? ""))) ?></div>
                                            <div class="min-w-0">
                                                <div class="job-no"><?= e($request["job_no"]) ?></div>
                                                <div class="req-title"><?= e($request["project_title"]) ?></div>
                                                <div class="req-sub">
                                                    <?= e($request["requestor_name"] ?: "—") ?> · <?= e($request["department"] ?: "—") ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-pill <?= e(arStatusClass($st)) ?>">
                                            <i class="bi <?= e(arStatusIcon($st)) ?>"></i><?= e($st) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="arch-when" title="<?= e($request["archived_at"] ?? "") ?>">
                                            <?= e(arArchivedAgo($request["archived_at"] ?? null)) ?>
                                        </div>
                                        <div class="arch-by">
                                            <?= e(arCompactDate($request["archived_at"] ?? null)) ?> · by <?= e($archBy) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="row-actions">
                                            <a
                                                class="btn btn-sm btn-outline-primary"
                                                href="<?= e(BASE_URL) ?>/admin/view.php?id=<?= (int) $request["id"] ?>"
                                                title="Open record"
                                            >
                                                <i class="bi bi-eye me-1"></i>View
                                            </a>
                                            <a
                                                class="btn btn-sm btn-outline-secondary"
                                                href="<?= e(BASE_URL) ?>/staff/activity.php?id=<?= (int) $request["id"] ?>"
                                                title="View audit trail"
                                            >
                                                <i class="bi bi-clock-history me-1"></i>Activity
                                            </a>
                                            <?php if ($canRestore): ?>
                                            <form
                                                method="POST"
                                                action="<?= e(BASE_URL) ?>/staff/restore_request.php"
                                                class="d-inline restore-form"
                                                data-title="<?= e($request["project_title"]) ?>"
                                                data-job="<?= e($request["job_no"]) ?>"
                                            >
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="request_id" value="<?= (int) $request["id"] ?>">
                                                <button class="btn btn-sm btn-success" type="submit" title="Return to active workflow">
                                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-archive d-block mb-2"></i>
                            <div class="fw-bold text-dark">No archived requests found</div>
                            <div class="small mb-3"><?= $hasFilters ? "Try widening your filters or clearing the search." : "Closed requests will appear here once archived." ?></div>
                            <?php if ($hasFilters): ?>
                            <a class="btn btn-sm btn-outline-primary" href="archived_requests.php">
                                <i class="bi bi-x-circle me-1"></i>Clear all filters
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </section>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var search = document.getElementById("vaultSearch");
    document.addEventListener("keydown", function (ev) {
        if (ev.key === "/" && document.activeElement !== search && search) {
            var tag = (document.activeElement && document.activeElement.tagName) || "";
            if (tag !== "INPUT" && tag !== "TEXTAREA" && tag !== "SELECT") {
                ev.preventDefault();
                search.focus();
            }
        }
    });
    ["vaultStatus", "vaultDept", "vaultSort"].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.addEventListener("change", function () { document.getElementById("vaultFilters").submit(); }); }
    });
    var timer = null;
    if (search) {
        search.addEventListener("input", function () {
            if (timer) { clearTimeout(timer); }
            timer = setTimeout(function () { document.getElementById("vaultFilters").submit(); }, 900);
        });
    }
    document.querySelectorAll(".restore-form").forEach(function (form) {
        form.addEventListener("submit", function (ev) {
            var job = form.getAttribute("data-job") || "";
            var title = form.getAttribute("data-title") || "";
            var label = job ? job + (title ? " — " + title : "") : (title || "this request");
            if (!confirm("Restore " + label + " to the active workflow?")) { ev.preventDefault(); }
        });
    });
})();
</script>

</body>
</html>