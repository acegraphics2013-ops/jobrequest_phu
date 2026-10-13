<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - MODULE ACCESS MANAGEMENT (Admin)
|--------------------------------------------------------------------------
| Admin can grant/revoke any module per user.
| Empty = inherit role default (visible by role).
| Self-lockout for users/module_permissions/dashboard is blocked.
*/

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

requireRole(["admin"]);
// NOTE: intentionally no requireModule() here so an admin can always
// recover access even if overrides get misconfigured.

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
moduleAccessEnsureTable($conn);

$editorId = (int) ($_SESSION["user_id"] ?? 0);
$editorRole = (string) ($_SESSION["role"] ?? "admin");

if (empty($_SESSION["module_perm_csrf"])) {
    $_SESSION["module_perm_csrf"] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION["module_perm_csrf"];

function mpFlash(string $type, string $message): never
{
    $_SESSION["module_perm_flash"] = ["type" => $type, "message" => $message];
    $uid = (int) ($_POST["user_id"] ?? $_GET["user_id"] ?? 0);
    $loc = "module_permissions.php" . ($uid > 0 ? "?user_id=" . $uid : "") . "#permissionsForm";
    header("Location: " . $loc);
    exit;
}

// Handle save
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $sessionToken = (string) ($_SESSION["module_perm_csrf"] ?? "");
    $postedToken = (string) ($_POST["csrf_token"] ?? "");
    if ($sessionToken === "" || $postedToken === "" || !hash_equals($sessionToken, $postedToken)) {
        mpFlash("danger", "Security token expired. Please try again.");
    }

    $targetId = (int) ($_POST["user_id"] ?? 0);
    if ($targetId <= 0) {
        mpFlash("danger", "Select a user first.");
    }

    $tStmt = $conn->prepare("SELECT id, name, role FROM users WHERE id = ? LIMIT 1");
    $tStmt->bind_param("i", $targetId);
    $tStmt->execute();
    $target = $tStmt->get_result()->fetch_assoc();
    $tStmt->close();

    if (!$target) {
        mpFlash("danger", "User not found.");
    }

    $choices = [];
    foreach (moduleRegistry() as $key => $meta) {
        $mode = trim((string) ($_POST["perm"][$key] ?? "inherit"));
        if (!in_array($mode, ["inherit", "allow", "deny"], true)) {
            $mode = "inherit";
        }
        $choices[$key] = $mode;
    }

    $result = saveUserModuleOverrides(
        $conn,
        $editorRole,
        $editorId,
        $targetId,
        (string) $target["role"],
        $choices
    );

    mpFlash($result["ok"] ? "success" : "danger", $result["message"]);
}

// User selection
$selectedId = (int) ($_GET["user_id"] ?? 0);
$search = trim((string) ($_GET["q"] ?? ""));

$allUsers = [];
$stmt = $conn->prepare(
    "SELECT id, name, email, role, department, status FROM users ORDER BY FIELD(role,'admin','supervisor','staff','user'), name ASC LIMIT 500"
);
$stmt->execute();
$allUsers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$users = $allUsers;
if ($search !== "") {
    $needle = strtolower($search);
    $users = array_values(array_filter($allUsers, static function ($u) use ($needle): bool {
        return str_contains(strtolower((string) $u["name"]), $needle)
            || str_contains(strtolower((string) $u["email"]), $needle)
            || str_contains(strtolower((string) $u["role"]), $needle)
            || str_contains(strtolower((string) ($u["department"] ?? "")), $needle);
    }));
}

$targetUser = null;
if ($selectedId > 0) {
    foreach ($allUsers as $u) {
        if ((int) $u["id"] === $selectedId) {
            $targetUser = $u;
            break;
        }
    }
    // If target user is selected but filtered out, include them in the options list so they remain visible
    if ($targetUser && !array_filter($users, static function($u) use ($selectedId) { return (int)$u["id"] === $selectedId; })) {
        array_unshift($users, $targetUser);
    }
}

$overrides = [];
$effective = [];
if ($targetUser) {
    $overrides = getUserModuleOverrides($conn, (int) $targetUser["id"]);
    $effective = getEffectiveModules($conn, (int) $targetUser["id"], (string) $targetUser["role"]);
}

$grouped = [];
foreach (moduleRegistry() as $key => $meta) {
    $grouped[$meta["section"]][] = [$key, $meta];
}

$flash = $_SESSION["module_perm_flash"] ?? null;
unset($_SESSION["module_perm_flash"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Module Access | HPU Workbench</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">
<!-- Simple tech stack: plain Bootstrap 5.3 + Icons + minimal CSS + vanilla JS (no build, no framework). -->
<style>
:root {
    --mp-bg: #f1f5f9;
    --mp-border: #e2e8f0;
    --mp-radius: 12px;
    --sp-1: 4px;
    --sp-2: 8px;
    --sp-3: 12px;
    --sp-4: 16px;
    --sp-5: 20px;
}
body { background: var(--mp-bg); font-size: 13px; }
.content { padding: var(--sp-3); }
.container-fluid { width: 100%; max-width: 100% !important; margin: 0; padding-left: var(--sp-3); padding-right: var(--sp-3); }
.ph { margin-bottom: var(--sp-3); }
.ph-actions { display: flex; gap: var(--sp-2); flex-wrap: wrap; }
.mp-card + .mp-card { margin-top: var(--sp-3); }
.ph-title { font-size: 1.05rem !important; }
.ph-subtitle { font-size: 0.75rem !important; }
.ph-icon { width: 32px !important; height: 32px !important; font-size: 1rem !important; }
.mp-card {
    border: 1px solid var(--mp-border);
    border-radius: var(--mp-radius);
    background: #fff;
    padding: 12px 16px;
    margin-bottom: 12px;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
}
.mp-user-sticky {
    position: sticky;
    top: 8px;
    z-index: 50;
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(8px);
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
}
.mp-avatar {
    width: 34px; height: 34px; border-radius: 8px; font-size: 0.85rem;
    background: #2563eb; color: #fff; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.module-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
}
.module-grid > .perm-row {
    margin-bottom: 0;
    height: 100%;
}
.module-grid > .perm-row:only-child {
    grid-column: 1 / -1;
}
.perm-row {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 8px 12px; border: 1px solid var(--mp-border); border-radius: 10px;
    background: #f8fafc; margin-bottom: 0; font-size: 12.5px;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}
.perm-row:hover {
    border-color: #cbd5e1;
    background: #ffffff;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
}
.perm-row.is-effective-visible { border-left: 4px solid #059669; background: #fff; }
.perm-row.is-effective-hidden { border-left: 4px solid #cbd5e1; }
.badge-role-allow { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.badge-role-deny { background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0; }
.badge-override-allow { background: #dcfce7; color: #15803d; border: 1px solid #86efac; font-weight: 700; }
.badge-override-deny { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; font-weight: 700; }
.badge-effective-visible { background: #059669; color: #fff; font-weight: 700; }
.badge-effective-hidden { background: #94a3b8; color: #fff; }
.sec-title { font-size: 0.82rem; font-weight: 700; display: flex; align-items: center; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
.sec-title .fs-5 { font-size: 0.95rem !important; }
.section-quick .btn { font-size: 0.65rem; padding: 2px 10px; border-radius: 999px; }
.perm-row.is-dirty { border-color: #f59e0b; background: #fffbeb; }
.save-access-btn { min-width: 110px; font-size: 0.75rem; padding: 4px 10px; }
.save-dirty-count { font-weight: 800; }
.module-label-text { font-size: 12.5px; }
.perm-row .badge { font-size: 0.62rem; padding: 2px 6px; }
.perm-btn-group { flex-shrink: 0; }
.perm-btn-group .btn { font-size: 0.68rem !important; padding: 2px 8px !important; }
.form-label { font-size: 0.65rem !important; margin-bottom: 2px !important; }
.form-control, .form-select { font-size: 0.78rem !important; padding: 4px 8px !important; }
.form-control-sm { font-size: 0.72rem !important; }
.btn { font-size: 0.75rem; }
.btn-sm { font-size: 0.7rem; padding: 3px 8px; }
.alert { font-size: 0.75rem; padding: 8px 12px; margin-bottom: 10px !important; }
h5.fw-bold { font-size: 0.85rem; }
.small, small { font-size: 0.7rem; }
/* ---- Spacing rhythm: one consistent vertical scale ---- */
.ph, .mp-card, .module-section-card { margin-bottom: var(--sp-3); }
.module-section-card:last-of-type { margin-bottom: var(--sp-3); }
.sec-title { margin-bottom: var(--sp-2); }
.perm-row .mt-2 { margin-top: var(--sp-2) !important; }
/* ---- Responsive: mobile-first stacking, desktop breathing room ---- */
@media (max-width: 576px) {
    .content { padding: var(--sp-2); }
    .container-fluid { padding-left: var(--sp-2); padding-right: var(--sp-2); }
    .mp-card { padding: var(--sp-2); border-radius: 10px; }
    .ph { flex-direction: column; align-items: stretch !important; gap: var(--sp-2); }
    .ph-actions { width: 100%; }
    .ph-actions .btn { flex: 1 1 auto; }
    .perm-row { flex-direction: column; align-items: stretch; gap: var(--sp-2); }
    .perm-btn-group { display: flex; width: 100%; }
    .perm-btn-group .btn { flex: 1 1 0; text-align: center; }
    .mp-user-sticky { top: 6px; }
    .mp-user-sticky .save-access-btn { width: 100%; }
    .mp-user-sticky .position-relative, .mp-user-sticky #moduleFilter { width: 100% !important; }
    .btn-group.btn-group-sm { width: 100%; }
    .btn-group.btn-group-sm .btn { flex: 1 1 0; }
}
@media (min-width: 992px) {
    .content { padding: 18px 24px; }
    .container-fluid { padding-left: 0; padding-right: 0; }
    .mp-card { padding: 14px 18px; }
    .module-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
}
@media (min-width: 1600px) {
    .content { padding: 22px 32px; }
    .module-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
}
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { transition: none !important; }
}
/* ---- UX: focus, steps, chips, feedback ---- */
:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; border-radius: 6px; }
.mp-steps { display: flex; align-items: center; gap: 6px; margin-bottom: 10px; font-size: 0.7rem; color: #64748b; flex-wrap: wrap; }
.mp-step { display: inline-flex; align-items: center; gap: 5px; padding: 2px 9px; border: 1px solid var(--mp-border); border-radius: 999px; background: #fff; }
.mp-step.is-active { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; font-weight: 700; }
.mp-step.is-done { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
.mp-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
.mp-chips a { font-size: 0.68rem; padding: 2px 9px; border: 1px solid var(--mp-border); border-radius: 999px; background: #fff; color: #334155; text-decoration: none; }
.mp-chips a:hover { border-color: #2563eb; color: #1d4ed8; }
#moduleFilterCount { font-size: 0.65rem; color: #64748b; white-space: nowrap; }
.btn.is-saving { pointer-events: none; opacity: 0.75; }
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
    <div class="ph-icon"><i class="bi bi-sliders"></i></div>
    <div>
      <p class="ph-eyebrow">Administration</p>
      <h1 class="ph-title">Module Access Permissions</h1>
      <p class="ph-subtitle">Configure module visibility per user. <strong>Allow</strong> grants, <strong>Deny</strong> revokes, <strong>Inherit</strong> uses role defaults. New: <strong>Monthly Report</strong> (Reports) can be granted per user.</p>
    </div>
  </div>
  <div class="ph-actions">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(BASE_URL) ?>/admin/users.php"><i class="bi bi-people me-1"></i>User Management</a>
  </div>
</div>

<div class="mp-steps" aria-label="Progress">
  <span class="mp-step <?= $targetUser ? "is-done" : "is-active" ?>"><i class="bi bi-person"></i>1. Pick user</span>
  <span aria-hidden="true">→</span>
  <span class="mp-step <?= $targetUser ? "is-active" : "" ?>"><i class="bi bi-sliders"></i>2. Review access</span>
  <span aria-hidden="true">→</span>
  <span class="mp-step"><i class="bi bi-check2-circle"></i>3. Save</span>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= e($flash["type"]) ?> alert-dismissible fade show shadow-sm border-0 rounded-3 mb-4" role="alert">
  <i class="bi bi-info-circle me-2"></i><?= e($flash["message"]) ?>
  <button class="btn-close" type="button" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION["module_denied"])): ?>
<div class="alert alert-warning alert-dismissible fade show shadow-sm border-0 rounded-3 mb-4">
  <i class="bi bi-exclamation-triangle me-2"></i><?= e((string) $_SESSION["module_denied"]) ?>
  <button class="btn-close" type="button" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION["module_denied"]); ?>
<?php endif; ?>

<!-- User Selection Form Card -->
<div class="mp-card">
  <form method="get" class="row g-2 g-md-3 align-items-end" id="userPickerForm">
    <div class="col-md-5">
      <label class="form-label fw-bold small text-muted text-uppercase mb-1">Search User</label>
      <div class="input-group">
        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
        <input class="form-control" name="q" id="userSearchQuery" value="<?= e($search) ?>" placeholder="Filter by name or email...">
      </div>
    </div>
    <div class="col-md-5">
      <label class="form-label fw-bold small text-muted text-uppercase mb-1">Select User Account</label>
      <select class="form-select" name="user_id" id="userSelectDropdown">
        <option value="0">— Choose a user —</option>
        <?php foreach ($users as $u): ?>
        <option value="<?= (int) $u["id"] ?>" <?= $targetUser && (int) $targetUser["id"] === (int) $u["id"] ? "selected" : "" ?>>
          <?= e($u["name"]) ?> (<?= e(ucfirst($u["role"])) ?><?= $u["department"] ? " • " . e($u["department"]) : "" ?>)
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <button class="btn btn-primary w-100 fw-semibold" type="submit">
        <i class="bi bi-arrow-right-circle me-1"></i>Load User
      </button>
    </div>
  </form>
</div>

<?php if ($targetUser): ?>
<?php
  $totalModules = count($effective);
  $visibleModules = count(array_filter($effective));
  $userInitials = strtoupper(substr((string) $targetUser["name"], 0, 2));
?>
<form method="post" id="permissionsForm">
<input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
<input type="hidden" name="user_id" value="<?= (int) $targetUser["id"] ?>">

<!-- Sticky User Header & Control Bar -->
<div class="mp-card mp-user-sticky d-flex justify-content-between align-items-center flex-wrap gap-2 gap-md-3">
  <div class="d-flex align-items-center gap-2 gap-md-3">
    <div class="mp-avatar"><?= e($userInitials) ?></div>
    <div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <h5 class="fw-bold mb-0 text-dark"><?= e($targetUser["name"]) ?></h5>
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill text-uppercase px-2 py-1 fs-xs fw-bold"><?= e(ucfirst($targetUser["role"])) ?></span>
        <?php if (!empty($targetUser["department"])): ?>
        <span class="badge bg-light text-secondary border rounded-pill px-2 py-1 fs-xs fw-normal"><?= e($targetUser["department"]) ?></span>
        <?php endif; ?>
      </div>
      <div class="small text-muted mt-1">
        <i class="bi bi-envelope me-1"></i><?= e($targetUser["email"]) ?>
        <span class="ms-2">|</span>
        <span class="ms-2 fw-semibold text-dark" aria-live="polite"><i class="bi bi-shield-check me-1 text-success"></i><span id="visibleGauge"><?= $visibleModules ?></span> / <?= $totalModules ?> Modules Visible</span>
      </div>
    </div>
  </div>

  <div class="d-flex align-items-center gap-2 flex-wrap">
    <!-- Quick Batch Control Toolbar -->
    <div class="btn-group btn-group-sm me-2" role="group" aria-label="Batch Controls">
      <button type="button" class="btn btn-outline-success" id="btnBatchAllow" title="Set all to Allow">
        <i class="bi bi-check-all me-1"></i>Grant All
      </button>
      <button type="button" class="btn btn-outline-danger" id="btnBatchDeny" title="Set all to Deny">
        <i class="bi bi-slash-circle me-1"></i>Revoke All
      </button>
      <button type="button" class="btn btn-outline-secondary" id="btnBatchInherit" title="Reset all to Inherit">
        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Default
      </button>
    </div>

    <!-- Live Filter Input -->
    <div class="position-relative d-flex align-items-center gap-1">
      <input type="text" id="moduleFilter" class="form-control form-control-sm border-secondary-subtle" style="width:170px;" placeholder="Search module...  ( / )" aria-label="Search modules" autocomplete="off">
      <button type="button" id="moduleFilterClear" class="btn btn-sm btn-outline-secondary d-none" aria-label="Clear module search" title="Clear search"><i class="bi bi-x"></i></button>
      <span id="moduleFilterCount" class="d-none d-md-inline" aria-live="polite"></span>
    </div>

    <button class="btn btn-primary fw-bold save-access-btn" type="submit">
      <i class="bi bi-check2-circle me-1"></i>Save Access<span class="save-dirty-count"></span>
    </button>
  </div>
</div>

<!-- Section jump links -->
<?php if ($targetUser): ?>
<nav class="mp-chips no-print" aria-label="Jump to section">
  <?php foreach (array_keys($grouped) as $chipSection): ?>
  <a href="#sec-<?= e(preg_replace('/[^a-z0-9]+/i', '-', strtolower($chipSection))) ?>"><?= e($chipSection) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<!-- Category Sections -->
<?php foreach ($grouped as $section => $mods): ?>
<?php
  $secIcons = [
      "General" => "bi-grid-1x2",
      "Requests" => "bi-inbox",
      "Project Monitoring" => "bi-kanban",
      "My Workspace" => "bi-laptop",
      "Communication" => "bi-chat-dots",
      "Health Education" => "bi-heart-pulse",
      "Reports" => "bi-bar-chart-line",
      "Administration" => "bi-shield-check",
      "Settings" => "bi-gear"
  ];
  $iconClass = $secIcons[$section] ?? "bi-folder2-open";
?>
<div class="mp-card module-section-card" id="sec-<?= e(preg_replace('/[^a-z0-9]+/i', '-', strtolower($section))) ?>">
  <div class="sec-title">
    <i class="bi <?= $iconClass ?> text-primary fs-5"></i>
    <span><?= e($section) ?></span>
    <span class="badge bg-light text-muted border rounded-pill small fw-normal"><?= count($mods) ?> modules</span>
    <span class="ms-auto d-inline-flex gap-1 section-quick">
      <button type="button" class="btn btn-sm btn-outline-success" data-section-allow title="Set all visible modules in this section to Allow">Allow all</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-section-inherit title="Reset this section to role defaults">Reset</button>
    </span>
  </div>

  <div class="module-grid">
  <?php foreach ($mods as [$key, $meta]): ?>
    <?php
      $defaultAllowed = in_array($targetUser["role"], $meta["roles"], true);
      $mode = "inherit";
      if (array_key_exists($key, $overrides)) {
          $mode = $overrides[$key] === 1 ? "allow" : "deny";
      }
      $effectiveVisible = $effective[$key] ?? false;
      $isSelfLocked = ((int) $targetUser["id"] === $editorId && in_array($key, ["users", "module_permissions", "dashboard"], true));
    ?>
    <div class="perm-row <?= $effectiveVisible ? "is-effective-visible" : "is-effective-hidden" ?> <?= $mode !== "inherit" ? "is-overridden-" . $mode : "" ?>" data-module-key="<?= e($key) ?>" data-role-default="<?= $defaultAllowed ? "1" : "0" ?>" data-initial="<?= e($mode) ?>">
      <div class="flex-grow-1 min-w-0">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="fw-bold text-dark module-label-text"><?= e($meta["label"]) ?></span>
          <span class="badge bg-light text-muted border font-monospace small px-2 py-0.5"><?= e($key) ?></span>
        </div>
        <div class="mt-2 d-flex gap-1 flex-wrap align-items-center">
          <span class="badge <?= $defaultAllowed ? "badge-role-allow" : "badge-role-deny" ?>">
            Role default: <?= $defaultAllowed ? "Visible" : "Hidden" ?>
          </span>

          <span class="badge badge-override-status <?= $mode === "inherit" ? "d-none" : ($mode === "allow" ? "badge-override-allow" : "badge-override-deny") ?>">
            Override: <span class="override-text"><?= e(ucfirst($mode)) ?></span>
          </span>

          <span class="badge badge-effective-status <?= $effectiveVisible ? "badge-effective-visible" : "badge-effective-hidden" ?>">
            Effective: <span class="effective-text"><?= $effectiveVisible ? "Visible" : "Hidden" ?></span>
          </span>
        </div>
        <?php if ($isSelfLocked): ?>
        <div class="small text-warning-emphasis mt-1"><i class="bi bi-lock-fill me-1 text-warning"></i>Locked for your own account to prevent lockout.</div>
        <?php endif; ?>
      </div>

      <div class="btn-group perm-btn-group" role="group" aria-label="<?= e($key) ?>">
        <input type="radio" class="btn-check perm-radio" name="perm[<?= e($key) ?>]" id="perm-<?= e($key) ?>-inherit" value="inherit" <?= $mode === "inherit" ? "checked" : "" ?> <?= $isSelfLocked ? "disabled" : "" ?>>
        <label class="btn btn-sm btn-outline-secondary" for="perm-<?= e($key) ?>-inherit">Inherit</label>

        <input type="radio" class="btn-check perm-radio" name="perm[<?= e($key) ?>]" id="perm-<?= e($key) ?>-allow" value="allow" <?= $mode === "allow" ? "checked" : "" ?> <?= $isSelfLocked ? "disabled" : "" ?>>
        <label class="btn btn-sm btn-outline-success" for="perm-<?= e($key) ?>-allow">Allow</label>

        <input type="radio" class="btn-check perm-radio" name="perm[<?= e($key) ?>]" id="perm-<?= e($key) ?>-deny" value="deny" <?= $mode === "deny" ? "checked" : "" ?> <?= $isSelfLocked ? "disabled" : "" ?>>
        <label class="btn btn-sm btn-outline-danger" for="perm-<?= e($key) ?>-deny">Deny</label>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<div class="mp-card d-flex justify-content-between align-items-center flex-wrap gap-2">
  <span class="text-muted small"><i class="bi bi-shield-lock me-1"></i>Changes will take effect immediately upon saving.</span>
  <button class="btn btn-primary fw-bold save-access-btn" type="submit"><i class="bi bi-check2-circle me-1"></i>Save Access<span class="save-dirty-count"></span></button>
</div>
</form>
<?php else: ?>
<div class="mp-card text-center py-5">
  <i class="bi bi-person-gear display-4 text-muted mb-3 d-block"></i>
  <h5 class="fw-bold">No User Account Selected</h5>
  <p class="text-muted small mx-auto" style="max-width:450px;">Select a user from the dropdown above to view and configure their custom module access permissions.</p>
</div>
<?php endif; ?>

</div>
</main>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    // Live User Search Dropdown Filtering
    const userSearchQuery = document.getElementById("userSearchQuery");
    const userSelectDropdown = document.getElementById("userSelectDropdown");
    if (userSearchQuery && userSelectDropdown) {
        userSearchQuery.addEventListener("input", function () {
            const q = (this.value || "").toLowerCase().trim();
            Array.from(userSelectDropdown.options).forEach(function (opt) {
                if (opt.value === "0") return;
                const text = opt.textContent.toLowerCase();
                const isMatch = text.includes(q);
                opt.hidden = !isMatch;
                opt.disabled = !isMatch;
            });
        });
    }

    // Module Live Search & Section Hiding Filter (+ count, clear, "/" shortcut)
    const moduleFilter = document.getElementById("moduleFilter");
    const moduleFilterClear = document.getElementById("moduleFilterClear");
    const moduleFilterCount = document.getElementById("moduleFilterCount");
    function applyModuleFilter() {
        const q = ((moduleFilter && moduleFilter.value) || "").toLowerCase().trim();
        let total = 0, shown = 0;
        document.querySelectorAll(".module-section-card").forEach(function (card) {
            let matchCount = 0;
            card.querySelectorAll(".perm-row").forEach(function (row) {
                total++;
                const text = row.textContent.toLowerCase();
                const isMatch = text.includes(q);
                row.style.display = isMatch ? "" : "none";
                if (isMatch) { matchCount++; shown++; }
            });
            card.style.display = matchCount > 0 ? "" : "none";
        });
        if (moduleFilterClear) moduleFilterClear.classList.toggle("d-none", q === "");
        if (moduleFilterCount) moduleFilterCount.textContent = q === "" ? "" : shown + " of " + total + " shown";
    }
    if (moduleFilter) {
        moduleFilter.addEventListener("input", applyModuleFilter);
        applyModuleFilter();
    }
    if (moduleFilterClear && moduleFilter) {
        moduleFilterClear.addEventListener("click", function () {
            moduleFilter.value = "";
            applyModuleFilter();
            moduleFilter.focus();
        });
    }
    document.addEventListener("keydown", function (ev) {
        if (ev.key === "/" && moduleFilter && document.activeElement !== moduleFilter
            && !/INPUT|TEXTAREA|SELECT/.test((document.activeElement && document.activeElement.tagName) || "")) {
            ev.preventDefault();
            moduleFilter.focus();
        }
    });

    // Dynamic State & Gauge Update
    function updateRowState(row) {
        const roleDefault = row.dataset.roleDefault === "1";
        const selectedRadio = row.querySelector(".perm-radio:checked");
        if (!selectedRadio) return;
        const val = selectedRadio.value; // inherit, allow, deny

        let effective = roleDefault;
        if (val === "allow") effective = true;
        if (val === "deny") effective = false;

        // Row border left style
        row.classList.toggle("is-effective-visible", effective);
        row.classList.toggle("is-effective-hidden", !effective);
        row.classList.toggle("is-overridden-allow", val === "allow");
        row.classList.toggle("is-overridden-deny", val === "deny");

        // Override badge
        const overrideBadge = row.querySelector(".badge-override-status");
        const overrideText = row.querySelector(".override-text");
        if (overrideBadge && overrideText) {
            if (val === "inherit") {
                overrideBadge.classList.add("d-none");
            } else {
                overrideBadge.classList.remove("d-none");
                overrideBadge.className = "badge badge-override-status " + (val === "allow" ? "badge-override-allow" : "badge-override-deny");
                overrideText.textContent = val.charAt(0).toUpperCase() + val.slice(1);
            }
        }

        // Effective badge
        const effectiveBadge = row.querySelector(".badge-effective-status");
        const effectiveText = row.querySelector(".effective-text");
        if (effectiveBadge && effectiveText) {
            effectiveBadge.className = "badge badge-effective-status " + (effective ? "badge-effective-visible" : "badge-effective-hidden");
            effectiveText.textContent = effective ? "Visible" : "Hidden";
        }
    }

    // Rows hidden by the module search filter are never touched by
    // batch or section actions. Clear the search to affect everything.
    function rowVisible(row) {
        if (row.style.display === "none") return false;
        const card = row.closest(".module-section-card");
        if (card && card.style.display === "none") return false;
        return true;
    }

    function visibleRows(scope) {
        const root = scope || document;
        return Array.from(root.querySelectorAll(".perm-row")).filter(rowVisible);
    }

    function dirtyCount() {
        let n = 0;
        document.querySelectorAll(".perm-row").forEach(function (row) {
            const selected = row.querySelector(".perm-radio:checked");
            if (selected && selected.value !== row.dataset.initial) n++;
        });
        return n;
    }

    function refreshSaveButtons() {
        const n = dirtyCount();
        document.querySelectorAll(".save-dirty-count").forEach(function (el) {
            el.textContent = n > 0 ? " (" + n + ")" : "";
        });
        document.querySelectorAll(".perm-row").forEach(function (row) {
            const selected = row.querySelector(".perm-radio:checked");
            row.classList.toggle("is-dirty", !!selected && selected.value !== row.dataset.initial);
        });
        return n;
    }

    function updateGauge() {
        let visibleCount = 0;
        const rows = document.querySelectorAll(".perm-row");
        rows.forEach(function (row) {
            updateRowState(row);
            if (row.classList.contains("is-effective-visible")) {
                visibleCount++;
            }
        });
        const gauge = document.getElementById("visibleGauge");
        if (gauge) gauge.textContent = visibleCount;
        refreshSaveButtons();
    }

    document.querySelectorAll(".perm-radio").forEach(function (radio) {
        radio.addEventListener("change", updateGauge);
    });

    function setRows(rows, value, confirmText) {
        const targets = rows.filter(function (row) {
            const radio = row.querySelector('.perm-radio[value="' + value + '"]:not([disabled])');
            return radio && !radio.checked;
        });
        if (targets.length === 0) return;
        if (confirmText && !window.confirm(confirmText(targets.length))) return;
        targets.forEach(function (row) {
            row.querySelector('.perm-radio[value="' + value + '"]:not([disabled])').checked = true;
        });
        updateGauge();
    }

    // Batch Actions (visible rows only, with confirmation)
    const btnBatchAllow = document.getElementById("btnBatchAllow");
    if (btnBatchAllow) {
        btnBatchAllow.addEventListener("click", function () {
            setRows(visibleRows(), "allow", function (n) {
                return "Set " + n + " visible module(s) to Allow?";
            });
        });
    }

    const btnBatchDeny = document.getElementById("btnBatchDeny");
    if (btnBatchDeny) {
        btnBatchDeny.addEventListener("click", function () {
            setRows(visibleRows(), "deny", function (n) {
                return "Set " + n + " visible module(s) to Deny?";
            });
        });
    }

    const btnBatchInherit = document.getElementById("btnBatchInherit");
    if (btnBatchInherit) {
        btnBatchInherit.addEventListener("click", function () {
            setRows(visibleRows(), "inherit", function (n) {
                return "Reset " + n + " visible module(s) to role defaults? Custom overrides will be removed on save.";
            });
        });
    }

    // Per-section quick actions (visible rows in that section only)
    document.querySelectorAll("[data-section-allow]").forEach(function (btn) {
        btn.addEventListener("click", function () {
            const card = btn.closest(".module-section-card");
            setRows(visibleRows(card), "allow", function (n) {
                return "Set " + n + " visible module(s) in this section to Allow?";
            });
        });
    });

    document.querySelectorAll("[data-section-inherit]").forEach(function (btn) {
        btn.addEventListener("click", function () {
            const card = btn.closest(".module-section-card");
            setRows(visibleRows(card), "inherit", function (n) {
                return "Reset " + n + " visible module(s) in this section to role defaults?";
            });
        });
    });

    // Guard against losing unsaved changes when switching users
    const userSelect = document.getElementById("userSelectDropdown");
    if (userSelect) {
        userSelect.dataset.last = userSelect.value;
        userSelect.addEventListener("change", function () {
            if (dirtyCount() > 0 && !window.confirm("You have unsaved permission changes. Switch user and discard them?")) {
                userSelect.value = userSelect.dataset.last;
                return;
            }
            document.getElementById("userPickerForm").submit();
        });
    }

    // Warn on accidental navigation with unsaved changes (not on save)
    // + disable save buttons with feedback to prevent double-submit
    let formSubmitting = false;
    const permissionsForm = document.getElementById("permissionsForm");
    if (permissionsForm) {
        permissionsForm.addEventListener("submit", function () {
            formSubmitting = true;
            document.querySelectorAll(".save-access-btn").forEach(function (btn) {
                btn.classList.add("is-saving");
                btn.disabled = true;
                if (!btn.dataset.orig) btn.dataset.orig = btn.innerHTML;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Saving…';
            });
        });
    }
    window.addEventListener("beforeunload", function (event) {
        if (formSubmitting || dirtyCount() === 0) return;
        event.preventDefault();
        event.returnValue = "";
    });

    // Initial update
    updateGauge();
});
</script>
</body>
</html>
