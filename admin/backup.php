<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - ADMIN DATABASE BACKUP & RESTORE
|--------------------------------------------------------------------------
| Admin-only manual backups (download + server history) and
| restore-from-upload with an automatic pre-restore safety copy.
| Engine lives in includes/db_backup.php (pure PHP, no shell).
|--------------------------------------------------------------------------
*/

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
include "../includes/db_backup.php";
require_once "../includes/module_access.php";

requireRole(["admin"]);
requireModule($conn, "backup");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$adminId = (int) ($_SESSION["user_id"] ?? 0);

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION["csrf_token"];

function backupFlash(string $type, string $message): void
{
    $_SESSION["backup_flash"] = ["type" => $type, "message" => $message];
}

function backupRedirect(string $query = ""): void
{
    header("Location: " . BASE_URL . "/admin/backup.php" . $query);
    exit;
}

function backupCsrfOk(): bool
{
    return isset($_POST["csrf_token"], $_SESSION["csrf_token"])
        && hash_equals((string) $_SESSION["csrf_token"], (string) $_POST["csrf_token"]);
}

/* Expired pending uploads are never left behind. */
dbBackupCleanPending();

/* =========================================================
   DOWNLOAD (GET, admin-only, whitelisted filenames only)
========================================================= */
if (isset($_GET["download"])) {
    $path = dbBackupResolve((string) $_GET["download"]);

    if ($path === null) {
        http_response_code(404);
        exit("Backup file not found.");
    }

    $filename = basename($path);
    $size = (int) filesize($path);

    header("Content-Type: application/sql");
    header("Content-Length: " . $size);
    header("X-Content-Type-Options: nosniff");
    header("Cache-Control: private, max-age=0, must-revalidate");
    header(
        "Content-Disposition: attachment; filename=\""
        . addcslashes($filename, "\"\\")
        . "\"; filename*=UTF-8''"
        . rawurlencode($filename)
    );

    readfile($path);
    exit;
}

/* =========================================================
   POST ACTIONS
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!backupCsrfOk()) {
        backupFlash("danger", "Security token expired. Please refresh the page and try again.");
        backupRedirect();
    }

    $action = trim((string) ($_POST["action"] ?? ""));

    /* ---------- Generate a new backup ---------- */
    if ($action === "backup_now") {
        try {
            $meta = dbBackupGenerate($conn);
            dbBackupPrune(DB_BACKUP_KEEP);

            backupFlash(
                "success",
                "Backup completed: {$meta["name"]} ("
                . dbBackupFileSize((int) $meta["size"])
                . ", {$meta["tables"]} tables, {$meta["duration"]}s)."
            );
        } catch (Throwable $e) {
            error_log("DB backup failed: " . $e->getMessage());
            backupFlash("danger", "Backup failed: " . $e->getMessage());
        }

        backupRedirect();
    }

    /* ---------- Delete a stored backup file ---------- */
    if ($action === "delete_file") {
        $path = dbBackupResolve((string) ($_POST["name"] ?? ""));

        if ($path === null) {
            backupFlash("danger", "Backup file not found.");
        } elseif (@unlink($path)) {
            backupFlash("success", "Backup file deleted.");
        } else {
            backupFlash("danger", "The backup file could not be deleted.");
        }

        backupRedirect();
    }

    /* ---------- Upload a backup for restore (step 1: preview) ---------- */
    if ($action === "restore_upload") {
        try {
            $sql = dbBackupReadUpload($_FILES["backup_file"] ?? []);
            $info = dbBackupInspect($sql);

            if (!$info["valid"]) {
                throw new RuntimeException($info["error"] !== "" ? $info["error"] : "Invalid backup file.");
            }

            // Drop any previous pending upload from this admin.
            $dir = dbBackupDir();

            foreach (glob($dir . DIRECTORY_SEPARATOR . "pending_{$adminId}_*.sql") ?: [] as $old) {
                @unlink($old);
            }

            $token = bin2hex(random_bytes(16));
            $pendingPath = $dir . DIRECTORY_SEPARATOR . "pending_{$adminId}_{$token}.sql";

            if (@file_put_contents($pendingPath, $sql) === false) {
                throw new RuntimeException("The uploaded file could not be staged.");
            }

            $_SESSION["backup_restore_token"] = $token;
            $_SESSION["backup_restore_info"] = [
                "tables" => $info["tables"],
                "statements" => $info["statements"],
                "size" => strlen($sql),
                "original" => basename((string) ($_FILES["backup_file"]["name"] ?? "upload.sql")),
            ];

            backupRedirect("?restore_token=" . $token);
        } catch (Throwable $e) {
            backupFlash("danger", "Restore upload failed: " . $e->getMessage());
            backupRedirect();
        }
    }

    /* ---------- Discard a pending restore ---------- */
    if ($action === "restore_cancel") {
        $token = (string) ($_POST["token"] ?? "");

        if ($token !== "" && isset($_SESSION["backup_restore_token"])
            && hash_equals((string) $_SESSION["backup_restore_token"], $token)
        ) {
            $pending = dbBackupDir() . DIRECTORY_SEPARATOR . "pending_{$adminId}_{$token}.sql";
            @unlink($pending);
            unset($_SESSION["backup_restore_token"], $_SESSION["backup_restore_info"]);
        }

        backupRedirect();
    }

    /* ---------- Execute the restore (step 2: confirmed) ---------- */
    if ($action === "restore_confirm") {
        $token = (string) ($_POST["token"] ?? "");
        $understood = isset($_POST["understood"]);

        $sessionToken = (string) ($_SESSION["backup_restore_token"] ?? "");

        if ($sessionToken === "" || !hash_equals($sessionToken, $token)) {
            backupFlash("danger", "Restore session expired. Please upload the file again.");
            backupRedirect();
        }

        if (!$understood) {
            backupFlash("danger", "Please tick the acknowledgement checkbox to proceed with the restore.");
            backupRedirect("?restore_token=" . $token);
        }

        $pendingPath = dbBackupDir() . DIRECTORY_SEPARATOR . "pending_{$adminId}_{$token}.sql";

        if (!is_file($pendingPath)) {
            unset($_SESSION["backup_restore_token"], $_SESSION["backup_restore_info"]);
            backupFlash("danger", "Pending restore file is missing. Please upload again.");
            backupRedirect();
        }

        $sql = @file_get_contents($pendingPath);

        if ($sql === false || trim($sql) === "") {
            @unlink($pendingPath);
            unset($_SESSION["backup_restore_token"], $_SESSION["backup_restore_info"]);
            backupFlash("danger", "Pending restore file is unreadable. Please upload again.");
            backupRedirect();
        }

        try {
            // Safety copy first — this is the rollback story.
            $safety = dbBackupGenerate($conn, "pre_restore");

            $result = dbBackupRestore($conn, $sql);

            @unlink($pendingPath);
            unset($_SESSION["backup_restore_token"], $_SESSION["backup_restore_info"]);

            if ($result["error"] !== "") {
                backupFlash(
                    "danger",
                    "Restore stopped after {$result["executed"]} statement(s): {$result["error"]} "
                    . "A safety copy ({$safety["name"]}) was saved before the restore — "
                    . "failing statement: {$result["statement"]}"
                );
            } else {
                backupFlash(
                    "success",
                    "Restore completed: {$result["executed"]} statements executed. "
                    . "Safety copy {$safety["name"]} retained."
                );
            }
        } catch (Throwable $e) {
            error_log("DB restore failed: " . $e->getMessage());
            @unlink($pendingPath);
            unset($_SESSION["backup_restore_token"], $_SESSION["backup_restore_info"]);
            backupFlash("danger", "Restore failed: " . $e->getMessage());
        }

        backupRedirect();
    }

    backupFlash("danger", "Unknown action.");
    backupRedirect();
}

/* =========================================================
   VIEW DATA (GET)
========================================================= */
$flash = $_SESSION["backup_flash"] ?? null;
unset($_SESSION["backup_flash"]);

$stats = dbBackupStats($conn);
$files = dbBackupFiles();

$backupDirBytes = 0;

foreach ($files as $fileRow) {
    $backupDirBytes += (int) $fileRow["size"];
}

/* Restore preview state. */
$restoreToken = trim((string) ($_GET["restore_token"] ?? ""));
$restorePreview = null;

if ($restoreToken !== "") {
    $sessionToken = (string) ($_SESSION["backup_restore_token"] ?? "");
    $pendingPath = dbBackupDir() . DIRECTORY_SEPARATOR . "pending_{$adminId}_{$restoreToken}.sql";

    if ($sessionToken !== "" && hash_equals($sessionToken, $restoreToken) && is_file($pendingPath)) {
        $restorePreview = [
            "token" => $restoreToken,
            "info" => $_SESSION["backup_restore_info"] ?? ["tables" => [], "statements" => 0, "size" => 0, "original" => "upload.sql"],
        ];
    } else {
        $restoreToken = "";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Backup | HPU Workbench</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">
    <style>
        :root {
            --bk-primary: #1d4ed8;
            --bk-border: #dfe6ef;
            --bk-muted: #64748b;
            --bk-text: #0f172a;
            --bk-danger: #b42318;
            --bk-success: #15803d;
        }

        body {
            background: #f4f7fb;
            color: var(--bk-text);
        }

        .backup-page {
            max-width: 1200px;
            margin: 0 auto;
        }

        .ph--backup::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: linear-gradient(180deg, #0ea5e9, #1d4ed8 55%, #0f172a);
        }

        .ph-icon--backup {
            background: linear-gradient(135deg, #0ea5e9 0%, #1d4ed8 60%, #0f172a 100%);
            color: #fff;
            border: 0;
            box-shadow: 0 8px 18px rgba(29, 78, 216, .28), inset 0 1px 0 rgba(255, 255, 255, .35);
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .stat-card {
            display: grid;
            grid-template-columns: 38px minmax(0, 1fr);
            gap: 11px;
            align-items: center;
            padding: 14px;
            border: 1px solid var(--bk-border);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .045);
        }

        .stat-icon {
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            border-radius: 11px;
            background: #eff6ff;
            color: var(--bk-primary);
            font-size: 1.05rem;
        }

        .stat-label {
            color: var(--bk-muted);
            font-size: .62rem;
            font-weight: 850;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .stat-value {
            margin-top: 2px;
            font-size: 1.25rem;
            font-weight: 900;
            line-height: 1.1;
        }

        .stat-note {
            margin-top: 2px;
            color: var(--bk-muted);
            font-size: .66rem;
        }

        .panel {
            overflow: hidden;
            margin-bottom: 14px;
            border: 1px solid var(--bk-border);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .045);
        }

        .panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            padding: 13px 16px;
            border-bottom: 1px solid #edf1f6;
        }

        .panel-title {
            display: flex;
            align-items: center;
            gap: 9px;
            margin: 0;
            font-size: .85rem;
            font-weight: 900;
            color: #1d2939;
        }

        .panel-title i {
            color: var(--bk-primary);
        }

        .panel-body {
            padding: 16px;
        }

        .kind-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: .62rem;
            font-weight: 850;
            white-space: nowrap;
        }

        .kind-manual {
            background: #eef4ff;
            color: #1d4ed8;
        }

        .kind-safety {
            background: #ecfdf3;
            color: #027a48;
        }

        .file-name {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .74rem;
            font-weight: 750;
            color: #1d2939;
            overflow-wrap: anywhere;
        }

        .history-table {
            margin: 0;
            font-size: .78rem;
        }

        .history-table thead th {
            background: #f5f7fa;
            border-bottom: 1px solid #e6ebf2;
            color: #65748c;
            font-size: .6rem;
            font-weight: 850;
            letter-spacing: .05em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .restore-warn {
            border: 1px solid #fecdca;
            border-left: 4px solid var(--bk-danger);
            border-radius: 12px;
            background: #fffbfa;
            padding: 14px 16px;
            color: #7a271a;
            font-size: .8rem;
            line-height: 1.55;
        }

        .table-chip {
            display: inline-block;
            margin: 0 5px 6px 0;
            padding: 3px 10px;
            border-radius: 999px;
            background: #f1f5f9;
            color: #334155;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .68rem;
        }

        .empty-history {
            padding: 34px 16px;
            text-align: center;
            color: var(--bk-muted);
        }

        .empty-history i {
            display: block;
            margin-bottom: 8px;
            font-size: 1.7rem;
            color: #94a3b8;
        }

        @media (max-width: 820px) {
            .stat-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 560px) {
            .stat-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body class="app-shell">
    <div class="d-flex min-vh-100">
        <?php include "../includes/sidebar.php"; ?>
        <div class="main flex-grow-1">
            <main class="content">
                <div class="container-fluid px-0">
                    <div class="backup-page">

                        <nav class="rm-crumb" aria-label="Breadcrumb" style="display:flex;align-items:center;flex-wrap:wrap;gap:7px;margin:0 0 10px 2px;color:#94a3b8;font-size:.7rem;font-weight:650;">
                            <a href="<?= e(BASE_URL) ?>/dashboard.php" style="display:inline-flex;align-items:center;gap:5px;color:#64748b;text-decoration:none;font-weight:750;"><i class="bi bi-house-door"></i> Dashboard</a>
                            <span style="color:#cbd5e1;">/</span>
                            <span aria-current="page" style="color:#0f172a;font-weight:800;">Database Backup</span>
                        </nav>

                        <header class="ph ph--backup mb-4">
                            <div class="ph-copy">
                                <div class="ph-icon ph-icon--backup" aria-hidden="true">
                                    <i class="bi bi-database-down"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="ph-eyebrow">HPU Workbench &bull; Administration</p>
                                    <h1 class="ph-title">Database Backup</h1>
                                    <p class="ph-subtitle">Manual backups with download, server history, and guarded restore.</p>
                                </div>
                            </div>
                            <div class="ph-actions">
                                <form method="POST" action="<?= e(BASE_URL) ?>/admin/backup.php" class="d-inline" onsubmit="return confirm('Generate a full database backup now?');">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="backup_now">
                                    <button class="btn btn-sm btn-primary" type="submit">
                                        <i class="bi bi-database-add me-1"></i> Backup Now
                                    </button>
                                </form>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= e(BASE_URL) ?>/dashboard.php">
                                    <i class="bi bi-speedometer2 me-1"></i> Dashboard
                                </a>
                            </div>
                        </header>

                        <?php if ($flash): ?>
                            <div class="alert alert-<?= e($flash["type"] ?? "success") ?> alert-dismissible fade show" role="alert">
                                <?= e($flash["message"] ?? "") ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <div class="stat-grid">
                            <div class="stat-card">
                                <div class="stat-icon"><i class="bi bi-tables"></i></div>
                                <div class="min-w-0">
                                    <div class="stat-label">Tables</div>
                                    <div class="stat-value"><?= number_format((int) $stats["tables"]) ?></div>
                                    <div class="stat-note">in hpu_job_request</div>
                                </div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon"><i class="bi bi-hdd"></i></div>
                                <div class="min-w-0">
                                    <div class="stat-label">Database size</div>
                                    <div class="stat-value"><?= e(dbBackupFileSize((int) $stats["bytes"])) ?></div>
                                    <div class="stat-note">data + indexes</div>
                                </div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon"><i class="bi bi-archive"></i></div>
                                <div class="min-w-0">
                                    <div class="stat-label">Stored backups</div>
                                    <div class="stat-value"><?= number_format(count($files)) ?></div>
                                    <div class="stat-note"><?= e(dbBackupFileSize($backupDirBytes)) ?> on disk</div>
                                </div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon"><i class="bi bi-shield-check"></i></div>
                                <div class="min-w-0">
                                    <div class="stat-label">Retention</div>
                                    <div class="stat-value">10</div>
                                    <div class="stat-note">newest manual backups kept</div>
                                </div>
                            </div>
                        </div>

                        <?php if ($restorePreview !== null): ?>
                            <section class="panel" aria-label="Confirm restore">
                                <div class="panel-head">
                                    <h2 class="panel-title"><i class="bi bi-exclamation-triangle-fill text-danger"></i> Confirm Database Restore</h2>
                                    <form method="POST" action="<?= e(BASE_URL) ?>/admin/backup.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="restore_cancel">
                                        <input type="hidden" name="token" value="<?= e($restorePreview["token"]) ?>">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Discard</button>
                                    </form>
                                </div>
                                <div class="panel-body">
                                    <div class="restore-warn mb-3">
                                        <strong>Restore “<?= e((string) ($restorePreview["info"]["original"] ?? "upload.sql")) ?>”?</strong><br>
                                        This will <strong>replace the entire current database</strong> (<?= number_format((int) $stats["tables"]) ?> tables).
                                        A pre-restore safety copy is saved automatically first, but verify you selected the right file.
                                    </div>
                                    <div class="mb-2 text-muted" style="font-size:.72rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase;">
                                        Tables in this file (<?= count((array) ($restorePreview["info"]["tables"] ?? [])) ?>)
                                    </div>
                                    <div class="mb-3">
                                        <?php foreach ((array) ($restorePreview["info"]["tables"] ?? []) as $previewTable): ?>
                                            <span class="table-chip"><?= e((string) $previewTable) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <form method="POST" action="<?= e(BASE_URL) ?>/admin/backup.php" onsubmit="return confirm('Final check: restore this backup and replace the live database?');">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="restore_confirm">
                                        <input type="hidden" name="token" value="<?= e($restorePreview["token"]) ?>">
                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" value="1" id="restoreUnderstood" name="understood" required>
                                            <label class="form-check-label" for="restoreUnderstood" style="font-size:.8rem;">
                                                I understand this replaces the live database. A safety copy will be saved first.
                                            </label>
                                        </div>
                                        <button class="btn btn-danger" type="submit">
                                            <i class="bi bi-arrow-counterclockwise me-1"></i>
                                            Restore Database
                                        </button>
                                    </form>
                                </div>
                            </section>
                        <?php endif; ?>

                        <section class="panel" aria-label="Backup history">
                            <div class="panel-head">
                                <h2 class="panel-title"><i class="bi bi-clock-history"></i> Backup History</h2>
                                <span class="text-muted" style="font-size:.72rem;">Newest first · safety copies are never auto-deleted</span>
                            </div>
                            <?php if ($files): ?>
                                <div class="table-responsive">
                                    <table class="table align-middle history-table">
                                        <thead>
                                            <tr>
                                                <th>File</th>
                                                <th>Type</th>
                                                <th>Size</th>
                                                <th>Created</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($files as $fileRow): ?>
                                                <tr>
                                                    <td><span class="file-name"><?= e($fileRow["name"]) ?></span></td>
                                                    <td>
                                                        <?php if ($fileRow["kind"] === "safety"): ?>
                                                            <span class="kind-badge kind-safety"><i class="bi bi-shield-check"></i> Safety</span>
                                                        <?php else: ?>
                                                            <span class="kind-badge kind-manual"><i class="bi bi-person"></i> Manual</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="white-space:nowrap;"><?= e(dbBackupFileSize((int) $fileRow["size"])) ?></td>
                                                    <td style="white-space:nowrap;"><?= e(date("M d, Y g:i A", (int) $fileRow["created_at"])) ?></td>
                                                    <td>
                                                        <div class="d-flex gap-2 justify-content-end">
                                                            <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/admin/backup.php?download=<?= rawurlencode($fileRow["name"]) ?>" title="Download <?= e($fileRow["name"]) ?>">
                                                                <i class="bi bi-download"></i>
                                                            </a>
                                                            <form method="POST" action="<?= e(BASE_URL) ?>/admin/backup.php" class="d-inline" onsubmit="return confirm('Delete <?= e($fileRow["name"]) ?>? This cannot be undone.');">
                                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                                <input type="hidden" name="action" value="delete_file">
                                                                <input type="hidden" name="name" value="<?= e($fileRow["name"]) ?>">
                                                                <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete <?= e($fileRow["name"]) ?>">
                                                                    <i class="bi bi-trash"></i>
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="empty-history">
                                    <i class="bi bi-database"></i>
                                    <strong class="d-block text-dark mb-1">No backups yet</strong>
                                    Click “Backup Now” to generate the first one.
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="panel" aria-label="Restore from upload">
                            <div class="panel-head">
                                <h2 class="panel-title"><i class="bi bi-upload"></i> Restore from File</h2>
                            </div>
                            <div class="panel-body">
                                <p class="text-muted mb-3" style="font-size:.8rem;">
                                    Upload a <code>.sql</code> backup (max 64 MB). You will preview its tables and
                                    confirm before anything changes — a pre-restore safety copy is always saved first.
                                </p>
                                <form method="POST" action="<?= e(BASE_URL) ?>/admin/backup.php" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="restore_upload">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-8">
                                            <label class="form-label fw-bold" style="font-size:.75rem;" for="restoreFile">Backup file</label>
                                            <input class="form-control" type="file" id="restoreFile" name="backup_file" accept=".sql" required>
                                        </div>
                                        <div class="col-md-4">
                                            <button class="btn btn-outline-danger w-100" type="submit">
                                                <i class="bi bi-cloud-upload me-1"></i> Upload &amp; Preview
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </section>

                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
