<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";
include "../includes/advanced_helpers.php";

requireRole([
    "staff",
    "supervisor",
    "admin"
]);
requireModule($conn, "archived_requests");

$role = (string)($_SESSION["role"] ?? "staff");

$canRestore = in_array(
    $role,
    ["supervisor", "admin"],
    true
);

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

function arRequestTypes($value): array
{
    if (is_array($value)) {
        $list = $value;
    } else {
        $raw = trim((string)($value ?? ""));
        if ($raw === "") {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $list = $decoded;
        } else {
            $list = preg_split('/[,|]+/', $raw) ?: [];
        }
    }
    $out = [];
    foreach ($list as $item) {
        $item = trim((string)$item);
        if ($item !== "" && !in_array($item, $out, true)) {
            $out[] = $item;
        }
    }
    return $out;
}

function arTypeClass(string $type): string
{
    return match ($type) {
        "Graphic Design" => "type-graphic",
        "Video Production" => "type-video",
        "Photo/Video Coverage" => "type-coverage",
        "IEC Materials" => "type-iec",
        default => "type-other"
    };
}

function arTypeIcon(string $type): string
{
    return match ($type) {
        "Graphic Design" => "bi-palette-fill",
        "Video Production" => "bi-camera-reels-fill",
        "Photo/Video Coverage" => "bi-camera-fill",
        "IEC Materials" => "bi-file-earmark-richtext",
        default => "bi-tag-fill"
    };
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
        jr.request_types,
        jr.date_request,
        jr.reviewed_date,
        jr.reviewed_by,
        jr.approved_date,
        jr.approved_by,
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archived Requests | HPU Workbench</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">
    <style>
        :root{
            --ar-primary:#1d4ed8; --ar-primary-dark:#1e40af; --ar-soft:#eff6ff;
            --ar-text:#0f172a; --ar-muted:#64748b; --ar-border:#dfe6ef; --ar-border-strong:#cbd5e1;
            --ar-page:#f4f7fb; --ar-success:#15803d; --ar-danger:#b42318; --ar-warning:#b45309;
            --ar-shadow:0 8px 24px rgba(15,23,42,.06);
        }
        body{background:var(--ar-page);color:var(--ar-text);}
        .min-w-0{min-width:0;}
        .archive-page{max-width:1680px;margin:0 auto;}
        .archive-page .content-inner{padding:2px 2px 28px;}
        .rm-crumb{display:flex;align-items:center;flex-wrap:wrap;gap:7px;margin:0 0 10px 2px;color:#94a3b8;font-size:.7rem;font-weight:650;}
        .rm-crumb a{display:inline-flex;align-items:center;gap:5px;color:#64748b;text-decoration:none;font-weight:750;}
        .rm-crumb a:hover{color:var(--ar-primary);text-decoration:underline;}
        .rm-crumb-sep{color:#cbd5e1;}
        .rm-crumb-current{color:#0f172a;font-weight:800;}

        /* Header extras */
        .ph--archive::before{content:"";position:absolute;inset:0 auto 0 0;width:4px;background:linear-gradient(180deg,#64748b,#334155 55%,#0f172a);}
        .ph-icon--archive{width:52px;height:52px;flex:0 0 52px;border:0;border-radius:14px;background:linear-gradient(135deg,#475569 0%,#334155 55%,#0f172a 100%);color:#fff;font-size:23px;box-shadow:0 8px 18px rgba(51,65,85,.28),inset 0 1px 0 rgba(255,255,255,.25);}
        .result-pill{display:inline-flex;min-height:34px;align-items:center;gap:6px;padding:7px 11px;border-radius:8px;background:#eef2f7;color:#334155;font-size:.75rem;font-weight:800;white-space:nowrap;}
        .compact-tool-btn{display:inline-flex;min-height:34px;align-items:center;justify-content:center;gap:6px;padding:7px 10px;border:1px solid var(--ar-border-strong);border-radius:8px;background:#fff;color:#42506a;font-size:.75rem;font-weight:750;text-decoration:none;transition:.16s ease;cursor:pointer;}
        .compact-tool-btn:hover{border-color:#93b4e5;background:var(--ar-soft);color:var(--ar-primary-dark);}
        .compact-tool-btn.is-active{border-color:var(--ar-primary);background:var(--ar-primary);color:#fff;}
        .view-toggle{display:inline-flex;padding:3px;border:1px solid var(--ar-border-strong);border-radius:9px;background:#f1f5f9;gap:2px;}
        .view-toggle button{display:inline-flex;align-items:center;gap:5px;border:0;border-radius:6px;background:transparent;color:#64748b;font-size:.72rem;font-weight:800;padding:5px 10px;cursor:pointer;}
        .view-toggle button.is-active{background:#fff;color:var(--ar-primary-dark);box-shadow:0 1px 4px rgba(15,23,42,.14);}

        /* Metric strip */
        .metrics-strip{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:8px;margin-bottom:10px;}
        .metric-card{--mc:#475569;--mbg:#f1f5f9;display:grid;grid-template-columns:36px minmax(0,1fr);align-items:center;gap:10px;padding:11px 12px;border:1px solid var(--ar-border);border-radius:12px;background:#fff;text-decoration:none;color:inherit;box-shadow:0 3px 12px rgba(15,23,42,.035);transition:.16s ease;position:relative;overflow:hidden;}
        .metric-card::after{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--mc);opacity:.9;}
        .metric-card:hover{transform:translateY(-1px);border-color:var(--mc);}
        .metric-card.active{outline:2px solid var(--mc);outline-offset:1px;}
        .metric-icon{display:grid;width:36px;height:36px;place-items:center;border-radius:10px;background:var(--mbg);color:var(--mc);font-size:1rem;}
        .metric-label{color:var(--ar-muted);font-size:.62rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .metric-value{color:var(--ar-text);font-size:1.15rem;font-weight:900;line-height:1.1;}
        .metric-sub{color:var(--ar-muted);font-size:.66rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .metric-all{--mc:#334155;--mbg:#eef2f7;}
        .metric-month{--mc:#1d4ed8;--mbg:#e8effe;}
        .metric-dept{--mc:#0e7490;--mbg:#e0f7fb;}
        .metric-access{--mc:#15803d;--mbg:#e7f8ef;}
        .metric-access.locked{--mc:#94a3b8;--mbg:#f1f5f9;}

        /* Filter panel */
        .filter-panel{margin-bottom:10px;border:1px solid var(--ar-border);border-radius:12px;background:#fff;box-shadow:var(--ar-shadow);}
        .filter-panel .card-body{padding:10px;}
        .filter-grid{display:grid;grid-template-columns:minmax(260px,1.7fr) minmax(160px,.7fr) minmax(170px,.75fr) minmax(150px,.6fr) auto;gap:8px;align-items:end;}
        .compact-label{margin-bottom:4px;color:var(--ar-muted);font-size:.63rem;font-weight:850;letter-spacing:.045em;text-transform:uppercase;display:flex;align-items:center;gap:5px;}
        .request-search{position:relative;}
        .request-search>i{position:absolute;top:50%;left:11px;z-index:2;color:#8390a4;font-size:.82rem;transform:translateY(-50%);}
        .request-search .form-control{padding-left:34px;padding-right:30px;}
        .search-clear{position:absolute;top:50%;right:7px;transform:translateY(-50%);border:0;background:#f1f5f9;color:#64748b;width:22px;height:22px;border-radius:50%;display:none;align-items:center;justify-content:center;font-size:.7rem;cursor:pointer;}
        .search-clear.show{display:inline-flex;}
        .search-clear:hover{background:#e2e8f0;color:#0f172a;}
        .filter-panel .form-control,.filter-panel .form-select{min-height:36px;border-color:var(--ar-border-strong);border-radius:8px;font-size:.78rem;}
        .filter-panel .form-control:focus,.filter-panel .form-select:focus{border-color:#8ab6ed;box-shadow:0 0 0 .15rem rgba(29,78,216,.10);}
        .filter-actions{display:flex;gap:6px;}
        .filter-actions .btn{min-height:36px;border-radius:8px;font-size:.76rem;font-weight:800;white-space:nowrap;}
        .active-filters{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:8px;padding-top:8px;border-top:1px solid #edf1f6;}
        .active-filters:empty{display:none;}
        .active-filter-label{color:var(--ar-muted);font-size:.67rem;font-weight:800;}
        .filter-chip{display:inline-flex;align-items:center;gap:5px;max-width:320px;padding:4px 6px 4px 10px;border-radius:999px;background:var(--ar-soft);color:var(--ar-primary-dark);font-size:.68rem;font-weight:750;}
        .filter-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        .filter-chip a{display:grid;width:18px;height:18px;place-items:center;border-radius:50%;background:rgba(29,78,216,.12);color:inherit;text-decoration:none;font-size:.6rem;}
        .filter-chip a:hover{background:var(--ar-primary);color:#fff;}

        /* List shell */
        .list-shell{overflow:hidden;border:1px solid var(--ar-border);border-radius:12px;background:#fff;box-shadow:var(--ar-shadow);}
        .list-toolbar{display:flex;min-height:44px;align-items:center;justify-content:space-between;gap:12px;padding:8px 12px;border-bottom:1px solid var(--ar-border);background:#fafbfd;flex-wrap:wrap;}
        .list-toolbar-title{display:flex;align-items:center;gap:8px;font-size:.78rem;font-weight:850;}
        .list-toolbar-title i{color:#475569;}
        .live-count{font-weight:800;color:#334155;}
        .list-toolbar-note{color:var(--ar-muted);font-size:.68rem;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
        kbd{padding:1px 6px;border:1px solid #e2e8f0;border-bottom-width:2px;border-radius:6px;background:#fff;color:#94a3b8;font-size:.65rem;}

        /* Cards */
        .archive-body{padding:10px;background:#eef2f7;}
        .archive-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:10px;}
        .archive-card{--st:#64748b;--st-soft:#f1f5f9;--st-border:#dbe2ec;position:relative;display:flex;flex-direction:column;overflow:hidden;border:1px solid var(--st-border);border-radius:12px;background:linear-gradient(180deg,var(--st-soft) 0, #fff 34%, #fff 100%);box-shadow:0 2px 8px rgba(15,23,42,.04);transition:transform .14s ease,box-shadow .14s ease,border-color .14s ease;}
        .archive-card::before{content:"";position:absolute;top:0;bottom:0;left:0;width:3px;background:var(--st);}
        .archive-card:hover{transform:translateY(-2px);border-color:var(--st);box-shadow:0 10px 24px rgba(15,23,42,.10);}
        .archive-card.st-pending{--st:#64748b;--st-soft:#f3f5f8;--st-border:#d8dee7;}
        .archive-card.st-review{--st:#07869b;--st-soft:#ecfbfd;--st-border:#bfe7ed;}
        .archive-card.st-approved{--st:#19905b;--st-soft:#edf9f2;--st-border:#bee6cf;}
        .archive-card.st-development{--st:#d17a0c;--st-soft:#fff8ed;--st-border:#f0d3aa;}
        .archive-card.st-revision{--st:#b96c0a;--st-soft:#fff7e6;--st-border:#ebc98e;}
        .archive-card.st-disapproved{--st:#d43b45;--st-soft:#fff1f2;--st-border:#efbec3;}
        .archive-card.st-completed{--st:#2b69dc;--st-soft:#f0f5ff;--st-border:#bfd0f5;}
        .archive-card.st-evaluation{--st:#7148c8;--st-soft:#f6f2ff;--st-border:#d1c4ef;}
        .archive-card.st-closed{--st:#475569;--st-soft:#f3f5f7;--st-border:#ccd3dc;}
        .archive-card-inner{padding:12px 12px 11px 15px;display:flex;flex-direction:column;gap:8px;height:100%;}
        .archive-top{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
        .job-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:7px;background:#0f172a;color:#fff;font-size:.66rem;font-weight:850;letter-spacing:.02em;max-width:100%;}
        .job-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        .copy-btn{border:0;background:rgba(255,255,255,.16);color:#fff;border-radius:5px;width:20px;height:20px;display:grid;place-items:center;font-size:.62rem;cursor:pointer;flex:0 0 auto;}
        .copy-btn:hover{background:rgba(255,255,255,.32);}
        .status-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:999px;font-size:.61rem;font-weight:850;white-space:nowrap;}
        .status-pending.status-badge{background:#eef1f5;color:#566174;}
        .status-review.status-badge{background:#dff7fa;color:#08697a;}
        .status-approved.status-badge{background:#e1f7ea;color:#126b43;}
        .status-development.status-badge{background:#fff0d9;color:#8a4d04;}
        .status-revision.status-badge{background:#fff1d7;color:#8a4d04;}
        .status-disapproved.status-badge{background:#ffe6e8;color:#a61e2c;}
        .status-completed.status-badge{background:#e4edff;color:#1e54ba;}
        .status-evaluation.status-badge{background:#eee8ff;color:#5d36ad;}
        .status-closed.status-badge{background:#e8ecf1;color:#263244;}
        .status-default.status-badge{background:#eef1f5;color:#566174;}
        .archived-flag{margin-left:auto;display:inline-flex;align-items:center;gap:4px;color:#94a3b8;font-size:.62rem;font-weight:750;}
        .archive-title{margin:0;color:#0f172a;font-size:.92rem;font-weight:850;letter-spacing:-.01em;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere;min-height:2.5em;}
        .archive-sub{display:flex;align-items:center;gap:6px;color:var(--ar-muted);font-size:.7rem;flex-wrap:wrap;}
        .archive-sub i{color:#94a3b8;}
        .archive-sub strong{color:#334155;font-weight:800;}
        .archive-meta{display:grid;grid-template-columns:1fr 1fr;gap:6px;}
        .meta-box{display:flex;gap:7px;align-items:flex-start;padding:8px 9px;border:1px solid #eef2f6;border-radius:10px;background:rgba(255,255,255,.85);font-size:.7rem;line-height:1.4;color:#475569;min-width:0;}
        .meta-box i{color:#94a3b8;margin-top:1px;}
        .meta-box small{display:block;color:#94a3b8;font-size:.58rem;font-weight:850;letter-spacing:.05em;text-transform:uppercase;}
        .meta-box b{color:#0f172a;font-weight:800;overflow-wrap:anywhere;}
        .meta-box .avatar{display:grid;width:24px;height:24px;flex:0 0 24px;place-items:center;border-radius:50%;background:#334155;color:#fff;font-size:.56rem;font-weight:900;}
        .date-strip{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px;}
        .date-cell{padding:7px 9px;border:1px solid #eef2f6;border-radius:10px;background:rgba(255,255,255,.85);font-size:.7rem;line-height:1.4;color:#475569;min-width:0;}
        .date-cell small{display:flex;align-items:center;gap:4px;color:#94a3b8;font-size:.56rem;font-weight:850;letter-spacing:.05em;text-transform:uppercase;white-space:nowrap;}
        .date-cell b{display:block;color:#0f172a;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .date-cell .by{display:block;color:#94a3b8;font-size:.6rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .type-row{display:flex;gap:5px;flex-wrap:wrap;}
        .type-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:999px;font-size:.6rem;font-weight:850;white-space:nowrap;}
        .type-badge i{font-size:.62rem;}
        .type-graphic{background:#e4edff;color:#1e54ba;}
        .type-video{background:#f1ebff;color:#5d36ad;}
        .type-coverage{background:#fff0d9;color:#8a4d04;}
        .type-iec{background:#e1f7ea;color:#126b43;}
        .type-other{background:#eef1f5;color:#566174;}
        .type-legend{display:flex;gap:12px;flex-wrap:wrap;align-items:center;}
        .type-legend span{display:inline-flex;align-items:center;gap:5px;font-size:.66rem;font-weight:750;color:#475569;}
        .type-legend i{width:9px;height:9px;border-radius:50%;display:inline-block;}
        .tbl-types{display:flex;gap:4px;flex-wrap:wrap;margin-top:4px;}
        .archive-foot{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:auto;padding-top:10px;border-top:1px dashed #e2e8f0;}
        .archive-foot .btn{display:inline-flex;min-height:31px;align-items:center;gap:4px;padding:5px 10px;border-radius:7px;font-size:.68rem;font-weight:800;}
        .archive-foot .btn-icon{width:31px;padding:5px;justify-content:center;}
        .restore-locked{display:inline-flex;align-items:center;gap:5px;color:#94a3b8;font-size:.66rem;font-weight:700;}

        /* Table view */
        .table-wrap{display:none;background:#fff;}
        .list-shell[data-view="table"] .table-wrap{display:block;}
        .list-shell[data-view="table"] .archive-body{display:none;}
        .table-wrap .table{margin:0;font-size:.76rem;}
        .table-wrap thead th{background:#f5f7fa;color:#768399;font-size:.6rem;font-weight:850;letter-spacing:.05em;text-transform:uppercase;border-bottom:1px solid var(--ar-border);white-space:nowrap;padding:9px 10px;}
        .table-wrap tbody td{padding:9px 10px;vertical-align:middle;border-bottom:1px solid #eef2f6;}
        .table-wrap tbody tr:hover{background:#f8fbff;}
        .tbl-title{font-weight:800;color:#0f172a;line-height:1.3;display:block;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        .tbl-sub{color:#64748b;font-size:.68rem;white-space:nowrap;}
        .tbl-job{font-weight:850;color:#334155;font-size:.7rem;white-space:nowrap;}
        .row-actions{display:flex;gap:4px;justify-content:flex-end;}
        .row-actions .btn{min-height:29px;padding:4px 8px;font-size:.66rem;font-weight:800;border-radius:7px;display:inline-flex;align-items:center;gap:4px;}

        .empty-state{padding:52px 20px;text-align:center;color:var(--ar-muted);background:#fff;border-radius:10px;border:1px dashed var(--ar-border-strong);}
        .empty-icon{display:grid;width:56px;height:56px;place-items:center;margin:0 auto 12px;border-radius:16px;background:#f1f5f9;color:#64748b;font-size:1.5rem;}
        .empty-state h4{margin-bottom:5px;color:var(--ar-text);font-size:1rem;font-weight:850;}
        .empty-state p{font-size:.78rem;margin-bottom:12px;}

        .toast-copy{position:fixed;left:50%;bottom:22px;transform:translateX(-50%) translateY(10px);background:#0f172a;color:#fff;font-size:.74rem;font-weight:750;padding:9px 14px;border-radius:10px;opacity:0;pointer-events:none;transition:.2s ease;z-index:1080;box-shadow:0 10px 30px rgba(0,0,0,.25);display:flex;gap:7px;align-items:center;}
        .toast-copy.show{opacity:1;transform:translateX(-50%) translateY(0);}

        body.ultra-compact .archive-card-inner{padding:9px 10px 9px 13px;gap:5px;}
        body.ultra-compact .archive-title{-webkit-line-clamp:1;min-height:0;}
        body.ultra-compact .archive-meta,.archive-sub{display:none;}
        body.ultra-compact .archive-body{padding:8px;}

        @media(max-width:1200px){
            .filter-grid{grid-template-columns:minmax(230px,1.4fr) minmax(150px,.7fr) minmax(160px,.75fr);}
            .filter-sort-field{grid-column:auto;}
            .filter-actions{grid-column:1/-1;justify-content:flex-end;}
            .metrics-strip{grid-template-columns:repeat(4,minmax(150px,1fr));overflow-x:auto;padding-bottom:2px;}
        }
        @media(max-width:820px){
            .metrics-strip{grid-template-columns:repeat(2,1fr);}
            .filter-grid{grid-template-columns:1fr 1fr;}
            .filter-search-field{grid-column:1/-1;}
            .filter-actions{grid-column:1/-1;}
            .filter-actions .btn-primary{flex:1;}
            .archive-grid{grid-template-columns:1fr;}
            .ph-actions .compact-tool-btn span,.ph-actions .result-pill span.txt{display:none;}
            .compact-tool-btn{width:34px;padding:7px;}
            .view-toggle button span{display:none;}
            .view-toggle button{padding:5px 9px;}
        }
        @media(max-width:560px){
            .metrics-strip{grid-template-columns:1fr 1fr;}
            .filter-grid{grid-template-columns:1fr;}
            .archive-meta{grid-template-columns:1fr;}
            .list-toolbar-note kbd{display:none;}
        }
        @media print{
            .sidebar,.ph-actions,.filter-panel,.archive-foot,.row-actions,.list-toolbar-note{display:none !important;}
            body{background:#fff;}
            .archive-body{background:#fff;}
        }
    </style>
</head>
<body class="app-shell">
<div class="d-flex min-vh-100">
    <?php include "../includes/sidebar.php"; ?>
    <div class="main flex-grow-1">
        <main class="content">
            <div class="container-fluid px-0">
            <div class="archive-page">
            <div class="content-inner">

                <nav class="rm-crumb" aria-label="Breadcrumb">
                    <a href="<?= e(BASE_URL) ?>/dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a>
                    <span class="rm-crumb-sep">/</span>
                    <a href="<?= e(BASE_URL) ?>/staff/requests.php">Job Requests</a>
                    <span class="rm-crumb-sep">/</span>
                    <span class="rm-crumb-current" aria-current="page">Archived</span>
                </nav>

                <div class="ph ph--archive">
                    <div class="ph-copy">
                        <div class="ph-icon ph-icon--archive" aria-hidden="true"><i class="bi bi-archive-fill"></i></div>
                        <div class="min-w-0">
                            <p class="ph-eyebrow">HPU Workbench • Records Vault</p>
                            <h1 class="ph-title">Archived Requests</h1>
                            <p class="ph-subtitle">Retired requests kept for audit and reporting. Search, filter, preview and restore when needed.</p>
                        </div>
                    </div>
                    <div class="ph-actions no-print">
                        <span class="result-pill" title="Matching records"><i class="bi bi-list-check"></i> <span id="phResultCount"><?= number_format($filteredTotal) ?></span> <span class="txt">result<?= $filteredTotal === 1 ? "" : "s" ?></span></span>
                        <div class="view-toggle" role="group" aria-label="Change layout">
                            <button type="button" id="viewCardsBtn" title="Card view"><i class="bi bi-grid-3x3-gap"></i> <span>Cards</span></button>
                            <button type="button" id="viewTableBtn" title="Table view"><i class="bi bi-table"></i> <span>Table</span></button>
                        </div>
                        <button type="button" class="compact-tool-btn" id="densityToggle" aria-pressed="false" title="Toggle compact density"><i class="bi bi-arrows-collapse"></i> <span>Compact</span></button>
                        <a class="compact-tool-btn" href="<?= e(BASE_URL) ?>/staff/requests.php" title="Back to active requests"><i class="bi bi-arrow-left"></i> <span>Active</span></a>
                        <a class="compact-tool-btn" href="archived_requests.php<?= ($hasFilters || $sortParam !== "newest") ? "?" . e(http_build_query(array_filter(["search" => $search ?: null, "status" => $statusFilter ?: null, "department" => $departmentFilter ?: null, "sort" => $sortParam !== "newest" ? $sortParam : null]))) : "" ?>" title="Refresh"><i class="bi bi-arrow-clockwise"></i> <span>Refresh</span></a>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?= e($message["type"] ?? "success") ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?= (($message["type"] ?? "success") === "success") ? "check-circle-fill" : "info-circle-fill" ?> me-1"></i>
                        <?= e($message["text"] ?? "") ?>
                        <button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Stat strip -->
                <div class="metrics-strip" aria-label="Archive summary">
                    <a class="metric-card metric-all <?= $statusFilter === "" && !$hasFilters ? "" : "" ?>" href="<?= e(arFilterUrl("", "", "", "newest")) ?>" title="Show everything in the vault">
                        <div class="metric-icon"><i class="bi bi-archive-fill"></i></div>
                        <div class="min-w-0"><div class="metric-label">Total archived</div><div class="metric-value"><?= number_format($vaultTotal) ?></div><div class="metric-sub"><?= $vaultOldest ? "Since " . e(date("M Y", strtotime($vaultOldest))) : "Vault" ?></div></div>
                    </a>
                    <div class="metric-card metric-month" title="Archived in <?= e(date("F Y")) ?>">
                        <div class="metric-icon"><i class="bi bi-calendar2-check"></i></div>
                        <div class="min-w-0"><div class="metric-label">This month</div><div class="metric-value"><?= number_format($vaultThisMonth) ?></div><div class="metric-sub"><?= e(date("F Y")) ?></div></div>
                    </div>
                    <div class="metric-card metric-dept" title="Departments represented">
                        <div class="metric-icon"><i class="bi bi-diagram-3"></i></div>
                        <div class="min-w-0"><div class="metric-label">Departments</div><div class="metric-value"><?= number_format($vaultDepartments) ?></div><div class="metric-sub"><?= $departmentFilter !== "" ? e($departmentFilter) : "Across vault" ?></div></div>
                    </div>
                    <div class="metric-card metric-access <?= $canRestore ? "" : "locked" ?>" title="<?= $canRestore ? "You can restore records" : "Restoring requires supervisor or admin" ?>">
                        <div class="metric-icon"><i class="bi bi-<?= $canRestore ? "arrow-counterclockwise" : "lock-fill" ?>"></i></div>
                        <div class="min-w-0"><div class="metric-label">Restore access</div><div class="metric-value" style="font-size:.95rem;"><?= $canRestore ? "Enabled" : "Restricted" ?></div><div class="metric-sub"><?= e(ucfirst($role)) ?> role</div></div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-panel">
                    <div class="card-body">
                        <form id="archiveFilterForm" method="GET" action="archived_requests.php">
                            <div class="filter-grid">
                                <div class="filter-search-field">
                                    <label class="compact-label" for="archiveSearchInput"><i class="bi bi-search"></i> Search vault</label>
                                    <div class="request-search">
                                        <i class="bi bi-search"></i>
                                        <input id="archiveSearchInput" class="form-control form-control-sm" name="search" value="<?= e($search) ?>" placeholder="Job no., title, requestor, department…" autocomplete="off">
                                        <button type="button" class="search-clear" id="searchClearBtn" title="Clear search" aria-label="Clear search"><i class="bi bi-x-lg"></i></button>
                                    </div>
                                </div>
                                <div>
                                    <label class="compact-label" for="statusSelect">Status</label>
                                    <select id="statusSelect" name="status" class="form-select form-select-sm">
                                        <option value="">All statuses (<?= number_format($vaultTotal) ?>)</option>
                                        <?php foreach ($statusCounts as $st => $c): ?>
                                            <option value="<?= e($st) ?>" <?= $statusFilter === $st ? "selected" : "" ?>><?= e($st) ?> (<?= (int)$c ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="compact-label" for="deptSelect">Department</label>
                                    <select id="deptSelect" name="department" class="form-select form-select-sm">
                                        <option value="">All departments</option>
                                        <?php foreach ($departments as $d): ?>
                                            <option value="<?= e($d) ?>" <?= $departmentFilter === $d ? "selected" : "" ?>><?= e($d) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-sort-field">
                                    <label class="compact-label" for="archiveSort">Sort</label>
                                    <select id="archiveSort" name="sort" class="form-select form-select-sm">
                                        <?php foreach ($sortLabels as $val => $lbl): ?>
                                            <option value="<?= e($val) ?>" <?= $sortParam === $val ? "selected" : "" ?>><?= e($lbl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-actions">
                                    <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel me-1"></i> Filter</button>
                                    <?php if ($hasFilters || $sortParam !== "newest"): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="archived_requests.php">Reset</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                        <?php if ($hasFilters): ?>
                        <div class="active-filters" aria-label="Active filters">
                            <span class="active-filter-label">Active:</span>
                            <?php if ($search !== ""): ?>
                                <span class="filter-chip"><i class="bi bi-search"></i> <span>“<?= e($search) ?>”</span> <a href="<?= e(arFilterUrl("", $statusFilter, $departmentFilter, $sortParam)) ?>" title="Remove search">✕</a></span>
                            <?php endif; ?>
                            <?php if ($statusFilter !== ""): ?>
                                <span class="filter-chip"><i class="bi bi-tag"></i> <span><?= e($statusFilter) ?></span> <a href="<?= e(arFilterUrl($search, "", $departmentFilter, $sortParam)) ?>" title="Remove status">✕</a></span>
                            <?php endif; ?>
                            <?php if ($departmentFilter !== ""): ?>
                                <span class="filter-chip"><i class="bi bi-building"></i> <span><?= e($departmentFilter) ?></span> <a href="<?= e(arFilterUrl($search, $statusFilter, "", $sortParam)) ?>" title="Remove department">✕</a></span>
                            <?php endif; ?>
                            <span class="filter-chip" style="background:#f1f5f9;color:#475569;"><i class="bi bi-sort-down"></i> <span><?= e($sortLabels[$sortParam] ?? $sortParam) ?></span></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- List -->
                <div class="list-shell" id="listShell" data-view="cards">
                    <div class="list-toolbar">
                        <div class="list-toolbar-title"><i class="bi bi-archive"></i> Archived records <span class="live-count" id="archiveLiveCount" aria-live="polite"><?= number_format($filteredTotal) ?> shown</span></div>
                        <div class="list-toolbar-note">
                            <span><i class="bi bi-sort-down me-1"></i><?= e($sortLabels[$sortParam] ?? "") ?></span>
                            <span class="d-none d-md-inline">•</span>
                            <span class="type-legend" aria-label="Request type colors">
                                <span><i style="background:#1e54ba"></i>Graphic Design</span>
                                <span><i style="background:#5d36ad"></i>Video</span>
                                <span><i style="background:#8a4d04"></i>Coverage</span>
                                <span><i style="background:#126b43"></i>IEC</span>
                            </span>
                            <span class="d-none d-md-inline">•</span>
                            <span class="d-none d-md-inline">Instant filter as you type</span>
                            <kbd title="Press / to focus search">/</kbd>
                            <kbd title="Press Esc to clear">esc</kbd>
                        </div>
                    </div>

                    <?php if ($requests): ?>
                    <div class="archive-body">
                        <div class="archive-grid" id="archiveGrid">
                            <?php foreach ($requests as $request):
                                $cardStatus = trim((string)($request["approval_status"] ?? "Closed"));
                                if ($cardStatus === "") { $cardStatus = "Closed"; }
                                $stClass = arStatusClass($cardStatus);
                                $stIcon = arStatusIcon($cardStatus);
                                $slug = ["Pending Review"=>"pending","Under Review"=>"review","Approved"=>"approved","In Development"=>"development","For Revision"=>"revision","Disapproved"=>"disapproved","Completed"=>"completed","For Evaluation"=>"evaluation","Closed"=>"closed"];
                                $stSlug = $slug[$cardStatus] ?? "default";
                                $cardTitle = (string)($request["project_title"] ?? "Untitled request");
                                $jobNo = (string)($request["job_no"] ?? "—");
                                $reqName = trim((string)($request["requestor_name"] ?? "")) !== "" ? (string)$request["requestor_name"] : "Unknown requestor";
                                $dept = trim((string)($request["department"] ?? "")) !== "" ? (string)$request["department"] : "No department";
                                $archBy = trim((string)($request["archived_by_name"] ?? "")) !== "" ? (string)$request["archived_by_name"] : "Unknown";
                                $archAt = (string)($request["archived_at"] ?? "");
                                $reqDate = (string)($request["date_request"] ?? "");
                                $revDate = (string)($request["reviewed_date"] ?? "");
                                $revBy = trim((string)($request["reviewed_by"] ?? ""));
                                $appDate = (string)($request["approved_date"] ?? "");
                                $appBy = trim((string)($request["approved_by"] ?? ""));
                                $reqTypes = arRequestTypes($request["request_types"] ?? null);
                                $hay = mb_strtolower($jobNo . " " . $cardTitle . " " . $reqName . " " . $dept . " " . $cardStatus . " " . $archBy . " " . $reqDate . " " . $revDate . " " . $appDate . " " . implode(" ", $reqTypes));
                            ?>
                            <div class="archive-cell" data-search="<?= e($hay) ?>" data-status="<?= e($cardStatus) ?>" data-title="<?= e(mb_strtolower($cardTitle)) ?>" data-date="<?= e($archAt) ?>" data-job="<?= e(mb_strtolower($jobNo)) ?>">
                                <article class="archive-card st-<?= e($stSlug) ?> h-100">
                                    <div class="archive-card-inner">
                                        <div class="archive-top">
                                            <span class="job-chip" title="<?= e($jobNo) ?>"><i class="bi bi-hash"></i><span><?= e($jobNo) ?></span><button type="button" class="copy-btn" data-copy="<?= e($jobNo) ?>" title="Copy job no." aria-label="Copy <?= e($jobNo) ?>"><i class="bi bi-clipboard"></i></button></span>
                                            <span class="status-badge <?= e($stClass) ?>"><i class="bi <?= e($stIcon) ?>"></i><?= e($cardStatus) ?></span>
                                            <span class="archived-flag" title="Archived <?= e($archAt) ?>"><i class="bi bi-archive"></i><?= e(arArchivedAgo($archAt)) ?></span>
                                        </div>
                                        <h3 class="archive-title" title="<?= e($cardTitle) ?>"><?= e($cardTitle) ?></h3>
                                        <div class="archive-sub"><i class="bi bi-person"></i> <strong><?= e($reqName) ?></strong><span>•</span><span><?= e($dept) ?></span></div>
                                        <?php if ($reqTypes): ?>
                                        <div class="type-row" aria-label="Request types">
                                            <?php foreach ($reqTypes as $rt): ?>
                                            <span class="type-badge <?= e(arTypeClass($rt)) ?>"><i class="bi <?= e(arTypeIcon($rt)) ?>"></i><?= e($rt) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                        <div class="date-strip" aria-label="Key request dates">
                                            <div class="date-cell" title="Date the request was filed"><small><i class="bi bi-calendar-plus"></i>Requested</small><b><?= e(arCompactDate($reqDate)) ?></b></div>
                                            <div class="date-cell" title="Staff review date<?= $revBy !== "" ? " • Reviewed by " . e($revBy) : "" ?>"><small><i class="bi bi-clipboard-check"></i>Reviewed</small><b><?= e(arCompactDate($revDate)) ?></b><?php if ($revBy !== ""): ?><span class="by"><?= e($revBy) ?></span><?php endif; ?></div>
                                            <div class="date-cell" title="Supervisor approval date<?= $appBy !== "" ? " • Approved by " . e($appBy) : "" ?>"><small><i class="bi bi-patch-check"></i>Approved</small><b><?= e(arCompactDate($appDate)) ?></b><?php if ($appBy !== ""): ?><span class="by"><?= e($appBy) ?></span><?php endif; ?></div>
                                        </div>
                                        <div class="archive-meta">
                                            <div class="meta-box"><i class="bi bi-calendar2-check"></i><div class="min-w-0"><small>Archived on</small><b><?= e(arCompactDate($archAt)) ?></b></div></div>
                                            <div class="meta-box"><div class="avatar" aria-hidden="true"><?= e(arInitials($archBy)) ?></div><div class="min-w-0"><small>Archived by</small><b><?= e($archBy) ?></b></div></div>
                                        </div>
                                        <div class="archive-foot">
                                            <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/admin/view.php?id=<?= (int)$request["id"] ?>" title="Open full record"><i class="bi bi-eye me-1"></i> View</a>
                                            <a class="btn btn-sm btn-outline-secondary btn-icon" href="<?= e(BASE_URL) ?>/staff/activity.php?id=<?= (int)$request["id"] ?>" title="Activity timeline" aria-label="Activity for <?= e($jobNo) ?>"><i class="bi bi-clock-history"></i></a>
                                            <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" data-copy="<?= e($jobNo) ?>" title="Copy job no." aria-label="Copy job number"><i class="bi bi-clipboard"></i></button>
                                            <?php if ($canRestore): ?>
                                                <button type="button" class="btn btn-sm btn-success ms-auto" data-restore-id="<?= (int)$request["id"] ?>" data-restore-job="<?= e($jobNo) ?>" data-restore-title="<?= e($cardTitle) ?>" data-bs-toggle="modal" data-bs-target="#restoreModal"><i class="bi bi-arrow-counterclockwise me-1"></i> Restore</button>
                                            <?php else: ?>
                                                <span class="restore-locked ms-auto" title="Only supervisors and admins can restore"><i class="bi bi-lock-fill"></i> Restore restricted</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </article>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div id="archiveNoMatch" style="display:none;margin-top:10px;">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-funnel"></i></div>
                                <h4>No cards match your typing</h4>
                                <p>The server returned <?= number_format($filteredTotal) ?> record(s), but none match this instant filter.</p>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="clearInstantBtn">Clear typing</button>
                            </div>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <div class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr>
                                <th>Job no.</th><th>Title / Requestor</th><th>Status</th><th>Requested</th><th>Reviewed</th><th>Approved</th><th>Archived</th><th>Archived by</th><th class="text-end">Actions</th>
                            </tr></thead>
                            <tbody id="archiveTableBody">
                            <?php foreach ($requests as $request):
                                $cardStatus = trim((string)($request["approval_status"] ?? "Closed"));
                                if ($cardStatus === "") { $cardStatus = "Closed"; }
                                $stClass = arStatusClass($cardStatus);
                                $stIcon = arStatusIcon($cardStatus);
                                $cardTitle = (string)($request["project_title"] ?? "Untitled request");
                                $jobNo = (string)($request["job_no"] ?? "—");
                                $reqName = trim((string)($request["requestor_name"] ?? "")) !== "" ? (string)$request["requestor_name"] : "Unknown";
                                $dept = trim((string)($request["department"] ?? "")) !== "" ? (string)$request["department"] : "—";
                                $archBy = trim((string)($request["archived_by_name"] ?? "")) !== "" ? (string)$request["archived_by_name"] : "Unknown";
                                $archAt = (string)($request["archived_at"] ?? "");
                                $reqDate = (string)($request["date_request"] ?? "");
                                $revDate = (string)($request["reviewed_date"] ?? "");
                                $revBy = trim((string)($request["reviewed_by"] ?? ""));
                                $appDate = (string)($request["approved_date"] ?? "");
                                $appBy = trim((string)($request["approved_by"] ?? ""));
                                $reqTypes = arRequestTypes($request["request_types"] ?? null);
                                $hay = mb_strtolower($jobNo . " " . $cardTitle . " " . $reqName . " " . $dept . " " . $cardStatus . " " . $archBy . " " . $reqDate . " " . $revDate . " " . $appDate . " " . implode(" ", $reqTypes));
                            ?>
                                <tr class="archive-row" data-search="<?= e($hay) ?>">
                                    <td><span class="tbl-job"><?= e($jobNo) ?></span><br><span class="tbl-sub"><?= e(arArchivedAgo($archAt)) ?></span></td>
                                    <td><span class="tbl-title" title="<?= e($cardTitle) ?>"><?= e($cardTitle) ?></span><span class="tbl-sub"><?= e($reqName) ?> • <?= e($dept) ?></span><?php if ($reqTypes): ?><span class="tbl-types"><?php foreach ($reqTypes as $rt): ?><span class="type-badge <?= e(arTypeClass($rt)) ?>"><i class="bi <?= e(arTypeIcon($rt)) ?>"></i><?= e($rt) ?></span><?php endforeach; ?></span><?php endif; ?></td>
                                    <td><span class="status-badge <?= e($stClass) ?>"><i class="bi <?= e($stIcon) ?>"></i><?= e($cardStatus) ?></span></td>
                                    <td style="white-space:nowrap;"><?= e(arCompactDate($reqDate)) ?></td>
                                    <td style="white-space:nowrap;" title="<?= $revBy !== "" ? e("Reviewed by " . $revBy) : "" ?>"><?= e(arCompactDate($revDate)) ?><?php if ($revBy !== ""): ?><br><span class="tbl-sub"><?= e($revBy) ?></span><?php endif; ?></td>
                                    <td style="white-space:nowrap;" title="<?= $appBy !== "" ? e("Approved by " . $appBy) : "" ?>"><?= e(arCompactDate($appDate)) ?><?php if ($appBy !== ""): ?><br><span class="tbl-sub"><?= e($appBy) ?></span><?php endif; ?></td>
                                    <td style="white-space:nowrap;"><?= e(arCompactDate($archAt)) ?></td>
                                    <td style="white-space:nowrap;"><?= e($archBy) ?></td>
                                    <td><div class="row-actions">
                                        <a class="btn btn-outline-primary" href="<?= e(BASE_URL) ?>/admin/view.php?id=<?= (int)$request["id"] ?>"><i class="bi bi-eye"></i> View</a>
                                        <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/staff/activity.php?id=<?= (int)$request["id"] ?>" title="Activity"><i class="bi bi-clock-history"></i></a>
                                        <?php if ($canRestore): ?>
                                        <button type="button" class="btn btn-success" data-restore-id="<?= (int)$request["id"] ?>" data-restore-job="<?= e($jobNo) ?>" data-restore-title="<?= e($cardTitle) ?>" data-bs-toggle="modal" data-bs-target="#restoreModal"><i class="bi bi-arrow-counterclockwise"></i></button>
                                        <?php endif; ?>
                                    </div></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <div id="archiveNoMatchTable" style="display:none;padding:12px;">
                            <div class="empty-state"><div class="empty-icon"><i class="bi bi-funnel"></i></div><h4>No rows match your typing</h4><p>Clear the search box to see all server results.</p></div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div style="padding:12px;background:#eef2f7;">
                        <div class="empty-state">
                            <div class="empty-icon"><i class="bi bi-archive"></i></div>
                            <h4><?= $hasFilters ? "No archived requests match these filters" : "Vault is empty" ?></h4>
                            <p><?= $hasFilters ? "Try removing a filter or searching something less specific." : "Archived job requests will appear here once requests are retired." ?></p>
                            <?php if ($hasFilters): ?>
                                <a class="btn btn-sm btn-primary" href="archived_requests.php"><i class="bi bi-x-circle me-1"></i> Clear all filters</a>
                            <?php else: ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/staff/requests.php"><i class="bi bi-arrow-left me-1"></i> Back to active requests</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <p class="text-muted mt-2 mb-0" style="font-size:.68rem;"><i class="bi bi-shield-check me-1"></i> Archived records are read-only and retained for audit. Restoring returns a request to the active list and logs the action.</p>

            </div>
            </div>
        </main>
    </div>
</div>

<!-- Restore confirm modal -->
<div class="modal fade" id="restoreModal" tabindex="-1" aria-labelledby="restoreModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="restoreModalLabel"><i class="bi bi-arrow-counterclockwise text-success me-2"></i>Restore archived request?</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex gap-2 align-items-start p-2 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0;">
            <i class="bi bi-info-circle-fill text-success mt-1"></i>
            <div style="font-size:.8rem;">
                <div class="fw-bold" id="restoreJobLabel">—</div>
                <div class="text-muted" id="restoreTitleLabel">—</div>
            </div>
        </div>
        <p class="mb-0 mt-2 text-muted" style="font-size:.78rem;">This moves the request back to the active list. The restore is recorded in the activity timeline.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Keep archived</button>
        <form id="restoreForm" method="POST" action="<?= e(BASE_URL) ?>/staff/restore_request.php" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="request_id" id="restoreRequestId" value="">
            <button class="btn btn-success btn-sm" type="submit"><i class="bi bi-arrow-counterclockwise me-1"></i> Restore request</button>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="toast-copy" id="copyToast" role="status"><i class="bi bi-clipboard-check"></i> <span id="copyToastText">Copied</span></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
    var shell = document.getElementById("listShell");
    var grid = document.getElementById("archiveGrid");
    var tbody = document.getElementById("archiveTableBody");
    var search = document.getElementById("archiveSearchInput");
    var clearBtn = document.getElementById("searchClearBtn");
    var live = document.getElementById("archiveLiveCount");
    var phCount = document.getElementById("phResultCount");
    var noMatch = document.getElementById("archiveNoMatch");
    var noMatchTable = document.getElementById("archiveNoMatchTable");
    var cardsBtn = document.getElementById("viewCardsBtn");
    var tableBtn = document.getElementById("viewTableBtn");
    var densityBtn = document.getElementById("densityToggle");
    var statusSel = document.getElementById("statusSelect");
    var deptSel = document.getElementById("deptSelect");
    var sortSel = document.getElementById("archiveSort");
    var form = document.getElementById("archiveFilterForm");

    var cells = grid ? Array.from(grid.querySelectorAll(".archive-cell")) : [];
    var rows = tbody ? Array.from(tbody.querySelectorAll(".archive-row")) : [];
    var serverTotal = cells.length || rows.length;

    /* ---- view toggle (persisted) ---- */
    function setView(v, save){
        if(!shell) return;
        shell.setAttribute("data-view", v);
        if(cardsBtn) cardsBtn.classList.toggle("is-active", v === "cards");
        if(tableBtn) tableBtn.classList.toggle("is-active", v === "table");
        try{ if(save !== false) localStorage.setItem("ar_view", v); }catch(e){}
    }
    var savedView = null;
    try{ savedView = localStorage.getItem("ar_view"); }catch(e){}
    var params = new URLSearchParams(window.location.search);
    var initialView = params.get("view") || savedView || "cards";
    if(initialView !== "cards" && initialView !== "table") initialView = "cards";
    setView(initialView, false);
    if(cardsBtn) cardsBtn.addEventListener("click", function(){ setView("cards"); });
    if(tableBtn) tableBtn.addEventListener("click", function(){ setView("table"); });

    /* ---- density ---- */
    function setDensity(on, save){
        document.body.classList.toggle("ultra-compact", !!on);
        if(densityBtn){
            densityBtn.classList.toggle("is-active", !!on);
            densityBtn.setAttribute("aria-pressed", on ? "true" : "false");
        }
        try{ if(save !== false) localStorage.setItem("ar_density", on ? "1" : "0"); }catch(e){}
    }
    var savedDensity = null;
    try{ savedDensity = localStorage.getItem("ar_density"); }catch(e){}
    setDensity(savedDensity === "1", false);
    if(densityBtn) densityBtn.addEventListener("click", function(){ setDensity(!document.body.classList.contains("ultra-compact")); });

    /* ---- server filters auto-submit ---- */
    if(statusSel) statusSel.addEventListener("change", function(){ form.submit(); });
    if(deptSel) deptSel.addEventListener("change", function(){ form.submit(); });
    if(sortSel) sortSel.addEventListener("change", function(){ form.submit(); });

    /* ---- instant client filter ---- */
    function norm(s){ return (s || "").toLowerCase(); }
    function applyInstant(){
        var q = search ? norm(search.value.trim()) : "";
        if(clearBtn) clearBtn.classList.toggle("show", !!(search && search.value));
        var shown = 0;
        cells.forEach(function(c){
            var hit = !q || norm(c.getAttribute("data-search")).indexOf(q) !== -1;
            c.style.display = hit ? "" : "none";
            if(hit) shown++;
        });
        var shownRows = 0;
        rows.forEach(function(r){
            var hit = !q || norm(r.getAttribute("data-search")).indexOf(q) !== -1;
            r.style.display = hit ? "" : "none";
            if(hit) shownRows++;
        });
        var visible = Math.max(shown, shownRows);
        if(!cells.length && !rows.length) visible = 0;
        // when no typing, show server total
        if(!q) visible = serverTotal;
        if(live) live.textContent = visible + " shown" + (q ? " • typing filter" : "");
        if(phCount && !q) phCount.textContent = serverTotal.toLocaleString();
        if(noMatch) noMatch.style.display = (cells.length && shown === 0) ? "" : "none";
        if(noMatchTable) noMatchTable.style.display = (rows.length && shownRows === 0) ? "" : "none";
    }
    var t = null;
    if(search){
        search.addEventListener("input", function(){ clearTimeout(t); t = setTimeout(applyInstant, 120); });
        // Enter submits to server for persistent/shareable search
    }
    function clearTyping(){
        if(search){ search.value = ""; search.focus(); }
        applyInstant();
    }
    if(clearBtn) clearBtn.addEventListener("click", clearTyping);
    var clearInstantBtn = document.getElementById("clearInstantBtn");
    if(clearInstantBtn) clearInstantBtn.addEventListener("click", clearTyping);

    document.addEventListener("keydown", function(ev){
        var tag = ((document.activeElement || {}).tagName || "").toUpperCase();
        var typing = tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT";
        if(ev.key === "/" && !typing && search){ ev.preventDefault(); search.focus(); }
        if(ev.key === "Escape" && document.activeElement === search){ clearTyping(); }
    });

    /* ---- copy job no ---- */
    var toast = document.getElementById("copyToast");
    var toastText = document.getElementById("copyToastText");
    var toastTimer = null;
    function showToast(msg){
        if(!toast) return;
        if(toastText) toastText.textContent = msg;
        toast.classList.add("show");
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function(){ toast.classList.remove("show"); }, 1600);
    }
    document.addEventListener("click", function(ev){
        var btn = ev.target.closest("[data-copy]");
        if(!btn) return;
        var text = btn.getAttribute("data-copy") || "";
        if(!text) return;
        function done(){ showToast("Copied " + text); }
        if(navigator.clipboard && navigator.clipboard.writeText){
            navigator.clipboard.writeText(text).then(done, function(){ fallback(); });
        } else { fallback(); }
        function fallback(){
            var ta = document.createElement("textarea");
            ta.value = text; document.body.appendChild(ta); ta.select();
            try{ document.execCommand("copy"); done(); }catch(e){}
            ta.remove();
        }
    });

    /* ---- restore modal ---- */
    var restoreModal = document.getElementById("restoreModal");
    if(restoreModal){
        restoreModal.addEventListener("show.bs.modal", function(ev){
            var btn = ev.relatedTarget;
            if(!btn) return;
            var id = btn.getAttribute("data-restore-id") || "";
            var job = btn.getAttribute("data-restore-job") || "";
            var title = btn.getAttribute("data-restore-title") || "";
            var idInput = document.getElementById("restoreRequestId");
            var jobLabel = document.getElementById("restoreJobLabel");
            var titleLabel = document.getElementById("restoreTitleLabel");
            if(idInput) idInput.value = id;
            if(jobLabel) jobLabel.textContent = job;
            if(titleLabel) titleLabel.textContent = title;
        });
    }

    applyInstant();
})();
</script>
</body>
</html>
