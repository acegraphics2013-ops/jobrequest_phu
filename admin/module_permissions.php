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
<style>
:root {
    --mp-bg: #f8fafc;
    --mp-card-bg: #ffffff;
    --mp-border: #e2e8f0;
    --mp-primary: #2563eb;
    --mp-success: #059669;
    --mp-danger: #dc2626;
    --mp-radius: 16px;
    --mp-shadow: 0 4px 14px rgba(15, 23, 42, 0.04), 0 1px 3px rgba(15, 23, 42, 0.02);
    --mp-shadow-lg: 0 12px 28px -4px rgba(15, 23, 42, 0.09), 0 4px 12px -2px rgba(15, 23, 42, 0.04);
}

body { background: var(--mp-bg); color: #0f172a; font-family: system-ui, -apple-system, sans-serif; }
.content { padding: 22px; }

.mp-card {
    border: 1px solid var(--mp-border);
    border-radius: var(--mp-radius);
    background: var(--mp-card-bg);
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: var(--mp-shadow);
    transition: all 0.2s ease;
}

.mp-user-sticky {
    position: sticky;
    top: 14px;
    z-index: 95;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(226, 232, 240, 0.95);
    box-shadow: var(--mp-shadow-lg);
}

.mp-avatar {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #ffffff;
    font-weight: 800;
    font-size: 1.15rem;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
    flex-shrink: 0;
}

.perm-row {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 14px 18px;
    border-radius: 12px;
    border: 1px solid #f1f5f9;
    background: #fafafa;
    margin-bottom: 10px;
    transition: all 0.2s ease;
}

.perm-row:hover {
    background: #ffffff;
    border-color: #cbd5e1;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
}

.perm-row.is-effective-visible {
    border-left: 4px solid var(--mp-success);
}
.perm-row.is-effective-hidden {
    border-left: 4px solid #cbd5e1;
}

.badge-role-allow { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.badge-role-deny  { background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0; }

.badge-override-allow { background: #dcfce7; color: #15803d; border: 1px solid #86efac; font-weight: 700; }
.badge-override-deny  { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; font-weight: 700; }

.badge-effective-visible { background: #10b981; color: #ffffff; font-weight: 700; box-shadow: 0 2px 6px rgba(16, 185, 129, 0.25); }
.badge-effective-hidden  { background: #94a3b8; color: #ffffff; font-weight: 650; }

.perm-btn-group .btn-check + .btn {
    border-radius: 8px;
    font-size: 0.78rem;
    font-weight: 650;
    padding: 5px 12px;
    transition: all 0.15s ease;
}

.perm-btn-group .btn-check:checked + .btn-outline-secondary {
    background: #475467 !important;
    color: #ffffff !important;
    border-color: #475467 !important;
    box-shadow: 0 2px 6px rgba(71, 84, 103, 0.25);
}

.perm-btn-group .btn-check:checked + .btn-outline-success {
    background: #059669 !important;
    color: #ffffff !important;
    border-color: #059669 !important;
    box-shadow: 0 2px 6px rgba(5, 150, 105, 0.3);
}

.perm-btn-group .btn-check:checked + .btn-outline-danger {
    background: #dc2626 !important;
    color: #ffffff !important;
    border-color: #dc2626 !important;
    box-shadow: 0 2px 6px rgba(220, 38, 38, 0.3);
}

.sec-title {
    font-size: 0.95rem;
    font-weight: 750;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
    flex-wrap: wrap;
}

.section-quick .btn {
    font-size: 0.68rem;
    font-weight: 700;
    padding: 2px 10px;
    border-radius: 999px;
}

.perm-row.is-dirty {
    border-color: #f59e0b;
    background: #fffbeb;
}

.perm-row.is-dirty::after {
    content: "Unsaved";
    position: absolute;
    top: -9px;
    right: 12px;
    padding: 0 8px;
    border-radius: 999px;
    background: #f59e0b;
    color: #ffffff;
    font-size: 0.6rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.save-access-btn {
    min-width: 150px;
}

.save-dirty-count {
    font-weight: 800;
}
</style>
</head>
<body class="app-shell">
<div class="d-flex min-vh-100">
<?php include "../includes/sidebar.php"; ?>
<div class="main flex-grow-1">
<main class="content">
<div class="container-fluid" style="max-width:1120px">

<div class="ph">
  <div class="ph-copy">
    <div class="ph-icon"><i class="bi bi-sliders"></i></div>
    <div>
      <p class="ph-eyebrow">Administration</p>
      <h1 class="ph-title">Module Access Permissions</h1>
      <p class="ph-subtitle">Configure module visibility per user. <strong>Allow</strong> grants additional modules, <strong>Deny</strong> revokes access, and <strong>Inherit</strong> uses role defaults.</p>
    </div>
  </div>
  <div class="ph-actions">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(BASE_URL) ?>/admin/users.php"><i class="bi bi-people me-1"></i>User Management</a>
  </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= e($flash["type"]) ?> alert-dismissible fade show shadow-sm border-0 rounded-3 mb-4">
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
  <form method="get" class="row g-3 align-items-end" id="userPickerForm">
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
<div class="mp-card mp-user-sticky d-flex justify-content-between align-items-center flex-wrap gap-3">
  <div class="d-flex align-items-center gap-3">
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
        <span class="ms-2 fw-semibold text-dark"><i class="bi bi-shield-check me-1 text-success"></i><span id="visibleGauge"><?= $visibleModules ?></span> / <?= $totalModules ?> Modules Visible</span>
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
    <div class="position-relative">
      <input type="text" id="moduleFilter" class="form-control form-control-sm border-secondary-subtle" style="width:170px;" placeholder="Search module...">
    </div>

    <button class="btn btn-primary fw-bold save-access-btn" type="submit">
      <i class="bi bi-check2-circle me-1"></i>Save Access<span class="save-dirty-count"></span>
    </button>
  </div>
</div>

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
<div class="mp-card module-section-card">
  <div class="sec-title">
    <i class="bi <?= $iconClass ?> text-primary fs-5"></i>
    <span><?= e($section) ?></span>
    <span class="ms-auto d-inline-flex gap-1 section-quick">
      <button type="button" class="btn btn-sm btn-outline-success" data-section-allow title="Set all visible modules in this section to Allow">Allow all</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-section-inherit title="Reset this section to role defaults">Reset</button>
    </span>
    <span class="badge bg-light text-muted border rounded-pill small fw-normal"><?= count($mods) ?> modules</span>
  </div>

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
        <div class="d-flex align-items-center gap-2">
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
    <?php if ($isSelfLocked): ?>
    <div class="small text-muted mb-2 ps-2"><i class="bi bi-lock-fill me-1 text-warning"></i>Locked for your own account to prevent lockout.</div>
    <?php endif; ?>
  <?php endforeach; ?>
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

    // Module Live Search & Section Hiding Filter
    const moduleFilter = document.getElementById("moduleFilter");
    if (moduleFilter) {
        moduleFilter.addEventListener("input", function () {
            const q = (this.value || "").toLowerCase().trim();
            document.querySelectorAll(".module-section-card").forEach(function (card) {
                let matchCount = 0;
                card.querySelectorAll(".perm-row").forEach(function (row) {
                    const text = row.textContent.toLowerCase();
                    const isMatch = text.includes(q);
                    row.style.display = isMatch ? "" : "none";
                    if (isMatch) matchCount++;
                });
                card.style.display = matchCount > 0 ? "" : "none";
            });
        });
    }

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
    let formSubmitting = false;
    const permissionsForm = document.getElementById("permissionsForm");
    if (permissionsForm) {
        permissionsForm.addEventListener("submit", function () {
            formSubmitting = true;
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
