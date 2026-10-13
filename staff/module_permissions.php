<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - MODULE ACCESS (Supervisor)
|--------------------------------------------------------------------------
| Supervisors may grant/revoke modules for user/staff only,
| only for modules they themselves can access, and never
| admin-only modules (users, departments, backup, upload_settings).
*/

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

requireRole(["supervisor", "admin"]);
requireModule($conn, "module_permissions");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
moduleAccessEnsureTable($conn);

$editorId = (int) ($_SESSION["user_id"] ?? 0);
$editorRole = (string) ($_SESSION["role"] ?? "supervisor");

if (empty($_SESSION["staff_module_perm_csrf"])) {
    $_SESSION["staff_module_perm_csrf"] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION["staff_module_perm_csrf"];

function smpFlash(string $type, string $message): never
{
    $_SESSION["staff_module_perm_flash"] = ["type" => $type, "message" => $message];
    $uid = (int) ($_POST["user_id"] ?? $_GET["user_id"] ?? 0);
    $loc = "module_permissions.php" . ($uid > 0 ? "?user_id=" . $uid : "");
    header("Location: " . $loc);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $sessionToken = (string) ($_SESSION["staff_module_perm_csrf"] ?? "");
    $postedToken = (string) ($_POST["csrf_token"] ?? "");
    if ($sessionToken === "" || $postedToken === "" || !hash_equals($sessionToken, $postedToken)) {
        smpFlash("danger", "Security token expired. Please try again.");
    }

    $targetId = (int) ($_POST["user_id"] ?? 0);
    if ($targetId <= 0) {
        smpFlash("danger", "Select a user first.");
    }

    $tStmt = $conn->prepare("SELECT id, name, role FROM users WHERE id = ? LIMIT 1");
    $tStmt->bind_param("i", $targetId);
    $tStmt->execute();
    $target = $tStmt->get_result()->fetch_assoc();
    $tStmt->close();

    if (!$target) {
        smpFlash("danger", "User not found.");
    }

    $choices = [];
    foreach (moduleSupervisorManagedModules() as $key) {
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

    smpFlash($result["ok"] ? "success" : "danger", $result["message"]);
}

$selectedId = (int) ($_GET["user_id"] ?? 0);

// Supervisors see user/staff only; admins using this page see everyone.
if ($editorRole === "supervisor") {
    $stmt = $conn->prepare(
        "SELECT id, name, email, role, department, status FROM users WHERE role IN ('user','staff') ORDER BY name ASC LIMIT 500"
    );
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $stmt = $conn->prepare(
        "SELECT id, name, email, role, department, status FROM users ORDER BY FIELD(role,'admin','supervisor','staff','user'), name ASC LIMIT 500"
    );
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$targetUser = null;
foreach ($users as $u) {
    if ((int) $u["id"] === $selectedId) {
        $targetUser = $u;
        break;
    }
}

// If admin opens a user not in supervisor list (e.g. another admin), fetch directly.
if (!$targetUser && $selectedId > 0 && $editorRole === "admin") {
    $tStmt = $conn->prepare("SELECT id, name, email, role, department, status FROM users WHERE id = ? LIMIT 1");
    $tStmt->bind_param("i", $selectedId);
    $tStmt->execute();
    $targetUser = $tStmt->get_result()->fetch_assoc() ?: null;
    $tStmt->close();
}

$managedKeys = moduleSupervisorManagedModules();
if ($editorRole === "admin") {
    // Admin using supervisor page still gets full registry for convenience.
    $managedKeys = moduleRegistryKeys();
}

$overrides = [];
$effective = [];
if ($targetUser) {
    $allOverrides = getUserModuleOverrides($conn, (int) $targetUser["id"]);
    foreach ($managedKeys as $k) {
        if (array_key_exists($k, $allOverrides)) {
            $overrides[$k] = $allOverrides[$k];
        }
    }
    $effective = getEffectiveModules($conn, (int) $targetUser["id"], (string) $targetUser["role"]);
}

$grouped = [];
foreach ($managedKeys as $key) {
    $reg = moduleRegistry();
    if (!isset($reg[$key])) {
        continue;
    }
    $grouped[$reg[$key]["section"]][] = [$key, $reg[$key]];
}

$flash = $_SESSION["staff_module_perm_flash"] ?? null;
unset($_SESSION["staff_module_perm_flash"]);
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
body{background:#f4f7fb}
.content{padding:18px}
.perm-card{border:1px solid #dfe6ef;border-radius:12px;background:#fff;padding:14px 16px;margin-bottom:12px}
.perm-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f0f2f5}
.perm-row:last-child{border-bottom:0}
.badge-allow{background:#ecfdf3;color:#027a48}
.badge-deny{background:#fef3f2;color:#b42318}
.badge-inherit{background:#f2f4f7;color:#475467}
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
      <p class="ph-eyebrow">Project Monitoring</p>
      <h1 class="ph-title">Module Access</h1>
      <p class="ph-subtitle">Supervisors can allow or deny modules for <strong>Requestors and Staff only</strong>. Admin-only areas cannot be changed here.</p>
    </div>
  </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= e($flash["type"]) ?> alert-dismissible fade show"><?= e($flash["message"]) ?><button class="btn-close" type="button" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="perm-card">
<form method="get" class="row g-2 align-items-end">
  <div class="col-md-9">
    <label class="form-label fw-bold small">User (Requestor / Staff)</label>
    <select class="form-select" name="user_id" onchange="this.form.submit()">
      <option value="0">— Select user —</option>
      <?php foreach ($users as $u): ?>
      <option value="<?= (int) $u["id"] ?>" <?= $targetUser && (int) $targetUser["id"] === (int) $u["id"] ? "selected" : "" ?>>
        <?= e($u["name"]) ?> (<?= e($u["role"]) ?>, <?= e($u["email"]) ?>)
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3">
    <button class="btn btn-primary w-100" type="submit">Load</button>
  </div>
</form>
</div>

<?php if ($targetUser): ?>
<?php
  $canEdit = canEditUserModules($conn, $editorRole, $editorId, (string) $targetUser["role"], (int) $targetUser["id"]);
?>
<?php if (!$canEdit): ?>
<div class="alert alert-warning">You are not allowed to edit this user. Supervisors can only manage Requestor and Staff accounts.</div>
<?php else: ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
<input type="hidden" name="user_id" value="<?= (int) $targetUser["id"] ?>">
<div class="perm-card d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <div class="fw-bold"><?= e($targetUser["name"]) ?> <span class="badge text-bg-light"><?= e($targetUser["role"]) ?></span></div>
    <div class="small text-muted"><?= e($targetUser["email"]) ?></div>
  </div>
  <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Save Access</button>
</div>
<?php foreach ($grouped as $section => $mods): ?>
<div class="perm-card">
  <div class="fw-bold mb-2"><?= e($section) ?></div>
  <?php foreach ($mods as [$key, $meta]): ?>
    <?php
      $defaultAllowed = in_array($targetUser["role"], $meta["roles"], true);
      $mode = $overrides[$key] ?? null;
      $modeStr = $mode === null ? "inherit" : ($mode === 1 ? "allow" : "deny");
      $editorCan = $editorRole === "admin" ? true : canAccessModule($conn, $editorId, $editorRole, $key);
    ?>
    <div class="perm-row">
      <div class="flex-grow-1">
        <div class="fw-bold small"><?= e($meta["label"]) ?></div>
        <div class="small text-muted">Role default: <?= $defaultAllowed ? "Visible" : "Hidden" ?> · Effective: <?= ($effective[$key] ?? false) ? "Visible" : "Hidden" ?><?= $editorCan ? "" : " · You lack this module, cannot grant" ?></div>
      </div>
      <div class="btn-group">
        <input type="radio" class="btn-check" name="perm[<?= e($key) ?>]" id="sp-<?= e($key) ?>-inherit" value="inherit" <?= $modeStr === "inherit" ? "checked" : "" ?>>
        <label class="btn btn-sm btn-outline-secondary" for="sp-<?= e($key) ?>-inherit">Inherit</label>
        <input type="radio" class="btn-check" name="perm[<?= e($key) ?>]" id="sp-<?= e($key) ?>-allow" value="allow" <?= $modeStr === "allow" ? "checked" : "" ?> <?= !$editorCan ? "disabled" : "" ?>>
        <label class="btn btn-sm btn-outline-success" for="sp-<?= e($key) ?>-allow">Allow</label>
        <input type="radio" class="btn-check" name="perm[<?= e($key) ?>]" id="sp-<?= e($key) ?>-deny" value="deny" <?= $modeStr === "deny" ? "checked" : "" ?>>
        <label class="btn btn-sm btn-outline-danger" for="sp-<?= e($key) ?>-deny">Deny</label>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
<div class="perm-card text-end"><button class="btn btn-primary" type="submit">Save Access</button></div>
</form>
<?php endif; ?>
<?php else: ?>
<div class="perm-card text-muted small">Select a Requestor or Staff account to configure modules.</div>
<?php endif; ?>

</div>
</main>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
