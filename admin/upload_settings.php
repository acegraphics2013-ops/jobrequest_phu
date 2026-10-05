<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - UPLOAD LOCATION SETTINGS (admin only)
|--------------------------------------------------------------------------
| Freely customize where uploaded files are stored and how their
| public links are built. One shared root covers job attachments,
| chat files and publication chat files (chat/ + pub_chat/ subfolders
| are created automatically).
|
| - uploads_dir : absolute filesystem path, anywhere (inside or
|                 outside the web root).
| - uploads_url : public base URL for that folder (optional). When
|                 blank, or when the folder is outside the web root,
|                 links are served through download.php (login +
|                 ownership checked).
|--------------------------------------------------------------------------
*/

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

requireRole(["admin"]);
requireModule($conn, "upload_settings");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION["csrf_token"];

function uploadSettingsFlash(string $type, string $message): void
{
    $_SESSION["upload_settings_flash"] = ["type" => $type, "message" => $message];
}

function uploadSettingsRedirect(string $query = ""): void
{
    header("Location: " . BASE_URL . "/admin/upload_settings.php" . $query);
    exit;
}

/** Copy every file (not .htaccess) from $from scope dir to $to scope dir. */
function uploadSettingsMigrate(string $fromRoot, string $toRoot): array
{
    $moved = 0;
    $skipped = 0;

    foreach (["" => "", "chat" => "chat", "pub_chat" => "pub_chat"] as $sub) {
        $from = $sub !== "" ? rtrim($fromRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sub : $fromRoot;
        $to = $sub !== "" ? rtrim($toRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sub : $toRoot;

        if (!is_dir($from) || realpath($from) === realpath($to)) {
            continue;
        }

        if (!is_dir($to) && !@mkdir($to, 0755, true) && !is_dir($to)) {
            $skipped += count(array_diff(@scandir($from) ?: [], [".", "..", ".htaccess"]));
            continue;
        }

        uploadsEnsureHtaccess($to);

        foreach (@scandir($from) ?: [] as $file) {
            if ($file === "." || $file === ".." || $file === ".htaccess" || str_starts_with($file, ".writetest_")) {
                continue;
            }

            $src = $from . DIRECTORY_SEPARATOR . $file;
            $dst = $to . DIRECTORY_SEPARATOR . $file;

            if (!is_file($src)) {
                continue;
            }

            if (is_file($dst)) {
                $moved++; // already present — counts as migrated
                continue;
            }

            if (@copy($src, $dst)) {
                $moved++;
            } else {
                $skipped++;
            }
        }
    }

    return ["moved" => $moved, "skipped" => $skipped];
}

/** Human-readable bytes, e.g. 1.4 MB. */
function uploadSettingsBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . " B";
    }

    $units = ["KB", "MB", "GB", "TB"];
    $value = (float) $bytes;
    $unit = "B";

    foreach ($units as $u) {
        $value /= 1024;
        $unit = $u;

        if ($value < 1024) {
            break;
        }
    }

    return round($value, $value < 10 ? 1 : 0) . " " . $unit;
}

/** Total bytes per scope (job root, chat/, pub_chat/). */
function uploadSettingsScopeBytes(string $root): array
{
    $bytes = ["file" => 0, "chat" => 0, "pub_chat" => 0];

    foreach (["file" => "", "chat" => "chat", "pub_chat" => "pub_chat"] as $scope => $sub) {
        $dir = $sub !== ""
            ? rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sub
            : $root;

        if (!is_dir($dir)) {
            continue;
        }

        foreach (@scandir($dir) ?: [] as $file) {
            if ($file === "." || $file === ".." || $file === ".htaccess" || str_starts_with($file, ".writetest_")) {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $file;

            if (is_file($path)) {
                $bytes[$scope] += (int) @filesize($path);
            }
        }
    }

    return $bytes;
}

/* =========================================================
   POST ACTIONS
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submitted = (string) ($_POST["csrf_token"] ?? "");
    $session = (string) ($_SESSION["csrf_token"] ?? "");

    if ($session === "" || !hash_equals($session, $submitted)) {
        uploadSettingsFlash("danger", "Invalid security token. Please refresh and try again.");
        uploadSettingsRedirect();
    }

    $action = (string) ($_POST["action"] ?? "save");
    $newDir = uploadsNormalizeDir((string) ($_POST["uploads_dir"] ?? ""));
    $newUrl = trim((string) ($_POST["uploads_url"] ?? ""));

    $dirCheck = uploadsValidateDir($newDir);

    if (!$dirCheck["ok"]) {
        uploadSettingsFlash("danger", $dirCheck["message"]);
        uploadSettingsRedirect();
    }

    $newDir = $dirCheck["path"] ?? $newDir;

    $urlCheck = uploadsValidateUrl($newUrl);

    if (!$urlCheck["ok"]) {
        uploadSettingsFlash("danger", $urlCheck["message"]);
        uploadSettingsRedirect();
    }

    $newUrl = $urlCheck["url"] ?? "";

    if ($action === "reset") {
        if (!appSettingSet("uploads_dir", "") || !appSettingSet("uploads_url", "")) {
            uploadSettingsFlash("danger", "Settings could not be reset (database error).");
            uploadSettingsRedirect();
        }

        ensureUploadsTree();

        uploadSettingsFlash("success", "Upload location reset to the default folder. Existing files were left in place.");
        uploadSettingsRedirect();
    }

    if ($action === "test") {
        $docRoot = uploadsDocRoot();
        $inside = $newUrl !== "" || ($docRoot !== "" && stripos(
            rtrim($newDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
            rtrim($docRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        ) === 0);

        uploadSettingsFlash(
            "info",
            "Folder is valid and writable. Links would use: "
            . ($inside
                ? "direct URLs (" . ($newUrl !== "" ? $newUrl : "auto from web root") . ")"
                : "secure proxy (download.php — login + ownership checked)")
            . ". Nothing was saved."
        );
        uploadSettingsRedirect();
    }

    // Save (and optionally migrate existing files into the new root).
    $oldRoot = uploadsRootDir();
    $wantMigrate = isset($_POST["migrate_files"]);

    uploadsEnsureHtaccess($newDir);

    if (!appSettingSet("uploads_dir", $newDir) || !appSettingSet("uploads_url", $newUrl)) {
        uploadSettingsFlash("danger", "Settings could not be saved (database error).");
        uploadSettingsRedirect();
    }

    ensureUploadsTree();

    $message = "Upload location saved. New files go to: " . $newDir;

    if ($wantMigrate && realpath($oldRoot) !== realpath($newDir)) {
        $result = uploadSettingsMigrate($oldRoot, $newDir);

        $message .= " Migrated {$result["moved"]} file(s)";
        $message .= $result["skipped"] > 0 ? ", {$result["skipped"]} skipped (copy failed)." : ".";
        $message .= " Old files were left in place as a safety copy.";
    } else {
        $message .= " Existing files were left in place.";
    }

    uploadSettingsFlash("success", $message);
    uploadSettingsRedirect();
}

/* =========================================================
   VIEW DATA (GET)
========================================================= */
$flash = $_SESSION["upload_settings_flash"] ?? null;
unset($_SESSION["upload_settings_flash"]);

appSettingsEnsureTable();

$currentDir = uploadsRootDir();
$currentUrl = uploadsRootUrl();
$isDirect = uploadsIsDirect();
$storedDir = trim(appSettingGet("uploads_dir", ""));
$storedUrl = trim(appSettingGet("uploads_url", ""));
$isCustom = $storedDir !== "";

$counts = uploadsScopeCounts($currentDir);
$totalFiles = $counts["file"] + $counts["chat"] + $counts["pub_chat"];

$scopeBytes = uploadSettingsScopeBytes($currentDir);
$totalBytes = $scopeBytes["file"] + $scopeBytes["chat"] + $scopeBytes["pub_chat"];

$defaultDir = uploadsDefaultDir();
$docRootHint = uploadsDocRoot();

$dirWritable = is_dir($currentDir) && is_writable($currentDir);
$chatWritable = is_dir($currentDir . DIRECTORY_SEPARATOR . "chat") && is_writable($currentDir . DIRECTORY_SEPARATOR . "chat");
$pubWritable = is_dir($currentDir . DIRECTORY_SEPARATOR . "pub_chat") && is_writable($currentDir . DIRECTORY_SEPARATOR . "pub_chat");

$sampleFile = uploadFileUrl("", "example.pdf");
$sampleChat = uploadFileUrl("chat", "1_abc123.pdf");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Location | HPU Workbench</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">
    <style>
        body { background: #f4f7fb; color: #0f172a; }
        .settings-page { max-width: 1100px; margin: 0 auto; }
        .settings-card {
            border: 1px solid #dfe6ef; border-radius: 14px;
            background: #fff; box-shadow: 0 8px 24px rgba(15,23,42,.045);
        }
        .kv { display: grid; gap: 0; }
        .kv-row {
            display: flex; gap: 10px; align-items: flex-start;
            padding: 10px 2px; border-bottom: 1px solid #eef2f7;
            font-size: .8rem;
        }
        .kv-row:last-child { border-bottom: 0; }
        .kv-label { min-width: 170px; color: #64748b; font-weight: 800; font-size: .68rem; text-transform: uppercase; letter-spacing: .05em; padding-top: 2px; }
        .kv-value { font-weight: 600; overflow-wrap: anywhere; min-width: 0; }
        .mode-badge { font-size: .72rem; }
        code.inline { background: #f1f5f9; padding: 1px 6px; border-radius: 6px; font-size: .75rem; overflow-wrap: anywhere; }
        .copy-btn {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 8px; border: 1px solid #dfe6ef; border-radius: 999px;
            background: #fff; color: #1d4ed8; font-size: .68rem; font-weight: 800;
            cursor: pointer; white-space: nowrap;
        }
        .copy-btn:hover { background: #eff6ff; border-color: #bfdbfe; }
        .writable-pill {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 9px; border-radius: 999px; font-size: .7rem; font-weight: 800;
        }
        .writable-yes { background: #dcfce7; color: #166534; }
        .writable-no { background: #fee2e2; color: #991b1b; }
        .scope-bar { height: 6px; border-radius: 999px; background: #eef2f7; overflow: hidden; margin-top: 5px; }
        .scope-bar > span { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #2563eb, #60a5fa); }
        .scope-row + .scope-row { margin-top: 12px; }
        .live-hint { display: none; margin-top: 8px; font-size: .74rem; font-weight: 700; }
        .live-hint.show { display: block; }
        .live-hint.direct { color: #166534; }
        .live-hint.proxy { color: #1d4ed8; }
        #saveBtn.is-dirty { box-shadow: 0 0 0 3px rgba(37, 99, 235, .25); }
        #saveBtn .dirty-dot { display: none; }
        #saveBtn.is-dirty .dirty-dot { display: inline; }
        .toast-float {
            position: fixed; left: 50%; bottom: 18px; z-index: 1080;
            transform: translate(-50%, 12px); opacity: 0; pointer-events: none;
            display: flex; align-items: center; gap: 8px;
            padding: 10px 15px; border-radius: 12px;
            background: #0f172a; color: #fff; font-size: .8rem; font-weight: 700;
            box-shadow: 0 14px 30px rgba(0,0,0,.25);
            transition: opacity .18s ease, transform .18s ease;
            max-width: min(92vw, 420px);
        }
        .toast-float.show { opacity: 1; transform: translate(-50%, 0); }
        :focus-visible { outline: 3px solid rgba(37, 99, 235, .45); outline-offset: 2px; }
        @media (max-width: 575px) {
            .kv-row { flex-direction: column; gap: 3px; }
            .kv-label { min-width: 0; padding-top: 0; }
            .ph-actions { width: 100%; }
        }
    </style>
</head>

<body class="app-shell">
<div class="d-flex min-vh-100">
    <?php include "../includes/sidebar.php"; ?>
    <div class="main flex-grow-1">
        <main class="content">
            <div class="container-fluid settings-page">

                <div class="ph">
                    <div class="ph-copy">
                        <div class="ph-icon" aria-hidden="true">
                            <i class="bi bi-folder-symlink-fill"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="ph-eyebrow">Administration</p>
                            <h1 class="ph-title">Upload Location</h1>
                            <p class="ph-subtitle">Choose freely where uploaded files are stored and how their links are built. One shared root covers job attachments, chat files and publication chat files.</p>
                        </div>
                    </div>
                    <div class="ph-actions">
                        <span class="badge mode-badge <?= $isDirect ? "bg-success" : "bg-primary" ?>">
                            <i class="bi <?= $isDirect ? "bi-link-45deg" : "bi-shield-lock" ?> me-1"></i>
                            <?= $isDirect ? "Direct links" : "Secure proxy" ?>
                        </span>
                        <?php if ($isCustom): ?>
                            <span class="badge mode-badge bg-info text-dark">Custom location</span>
                        <?php else: ?>
                            <span class="badge mode-badge bg-secondary">Default location</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($flash): ?>
                    <div class="alert alert-<?= e($flash["type"] === "danger" ? "danger" : ($flash["type"] === "success" ? "success" : "info")) ?> alert-dismissible fade show" role="alert" aria-live="polite">
                        <?= e($flash["message"]) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="alert alert-light border d-flex gap-2 align-items-start small mb-3" role="note">
                    <i class="bi bi-diagram-3 text-primary mt-1" aria-hidden="true"></i>
                    <div>
                        <strong>How links behave.</strong>
                        Inside the web root (or with a public URL) files open via fast <strong>direct links</strong>.
                        Anywhere else they open via the <strong>secure proxy</strong> — login + ownership are checked first.
                        <span class="text-muted">Type a path below to preview the mode instantly.</span>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-lg-7">
                        <div class="settings-card p-3 p-md-4">
                            <h2 class="h6 fw-bold mb-1"><i class="bi bi-gear me-1"></i> Storage settings</h2>
                            <p class="text-muted small mb-3">Use any absolute path — inside or outside the web root, e.g. <code class="inline">C:\uploads\hpu</code> or <code class="inline">/var/www/uploads</code>. Leave the public URL blank to serve files through the secure proxy.</p>

                            <form method="post" action="<?= e(BASE_URL) ?>/admin/upload_settings.php">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                                <div class="mb-3">
                                    <label class="form-label fw-bold small" for="uploads_dir">Upload folder (filesystem path)</label>
                                    <div class="input-group">
                                        <input class="form-control" id="uploads_dir" name="uploads_dir" required
                                               value="<?= e($storedDir !== "" ? $storedDir : $currentDir) ?>"
                                               placeholder="C:\uploads\hpu" autocomplete="off" spellcheck="false">
                                        <button type="button" class="btn btn-outline-secondary" id="fillDefaultBtn" title="Fill in the default folder">
                                            <i class="bi bi-arrow-counterclockwise me-1"></i>Default
                                        </button>
                                    </div>
                                    <div class="form-text">chat/ and pub_chat/ subfolders are created automatically, including anti-script .htaccess files.</div>
                                    <div class="live-hint" id="modeHint" aria-live="polite"></div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold small" for="uploads_url">Public base URL (optional)</label>
                                    <input class="form-control" id="uploads_url" name="uploads_url"
                                           value="<?= e($storedUrl) ?>"
                                           placeholder="https://files.example.com/uploads — blank = secure proxy" autocomplete="off" spellcheck="false">
                                    <div class="form-text">Only needed when the folder is web-served under a custom domain or CDN.</div>
                                    <div class="live-hint" id="sampleHint" aria-live="polite"></div>
                                </div>

                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="migrate_files" name="migrate_files" checked>
                                    <label class="form-check-label small" for="migrate_files">
                                        Copy existing files into the new folder on save (old files kept as safety copy)
                                    </label>
                                </div>

                                <div class="d-flex flex-wrap gap-2 align-items-center">
                                    <button type="submit" name="action" value="save" class="btn btn-primary" id="saveBtn">
                                        <i class="bi bi-save me-1"></i> Save location <span class="dirty-dot" title="Unsaved changes">•</span>
                                    </button>
                                    <button type="submit" name="action" value="test" class="btn btn-outline-secondary" formnovalidate>
                                        <i class="bi bi-check-circle me-1"></i> Test without saving
                                    </button>
                                    <?php if ($isCustom): ?>
                                        <button type="submit" name="action" value="reset" class="btn btn-outline-danger ms-lg-auto"
                                                onclick="return confirm('Reset to the default upload folder? Existing files stay where they are.');">
                                            <i class="bi bi-arrow-counterclockwise me-1"></i> Reset to default
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="col-12 col-lg-5">
                        <div class="settings-card p-3 p-md-4 mb-3">
                            <h2 class="h6 fw-bold mb-2"><i class="bi bi-info-circle me-1"></i> Current status</h2>
                            <div class="kv">
                                <div class="kv-row">
                                    <div class="kv-label">Storage folder</div>
                                    <div class="kv-value">
                                        <code class="inline"><?= e($currentDir) ?></code>
                                        <button type="button" class="copy-btn mt-1" data-copy="<?= e($currentDir) ?>">
                                            <i class="bi bi-copy"></i> Copy
                                        </button>
                                    </div>
                                </div>
                                <div class="kv-row">
                                    <div class="kv-label">Link base</div>
                                    <div class="kv-value">
                                        <code class="inline"><?= e($currentUrl) ?></code>
                                        <button type="button" class="copy-btn mt-1" data-copy="<?= e($currentUrl) ?>">
                                            <i class="bi bi-copy"></i> Copy
                                        </button>
                                    </div>
                                </div>
                                <div class="kv-row">
                                    <div class="kv-label">Link mode</div>
                                    <div class="kv-value"><?= $isDirect ? "Direct URLs" : "Secure proxy (download.php)" ?></div>
                                </div>
                                <div class="kv-row">
                                    <div class="kv-label">Writable</div>
                                    <div class="kv-value d-flex flex-wrap gap-1">
                                        <span class="writable-pill <?= $dirWritable ? "writable-yes" : "writable-no" ?>">
                                            <i class="bi <?= $dirWritable ? "bi-check-circle-fill" : "bi-x-circle-fill" ?>"></i> root
                                        </span>
                                        <span class="writable-pill <?= $chatWritable ? "writable-yes" : "writable-no" ?>">
                                            <i class="bi <?= $chatWritable ? "bi-check-circle-fill" : "bi-x-circle-fill" ?>"></i> chat
                                        </span>
                                        <span class="writable-pill <?= $pubWritable ? "writable-yes" : "writable-no" ?>">
                                            <i class="bi <?= $pubWritable ? "bi-check-circle-fill" : "bi-x-circle-fill" ?>"></i> pub_chat
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-3">

                            <h3 class="h6 fw-bold mb-1"><i class="bi bi-hdd me-1"></i> Storage use <span class="text-muted fw-normal">· <?= (int) $totalFiles ?> files · <?= e(uploadSettingsBytes($totalBytes)) ?></span></h3>
                            <?php
                            $scopes = [
                                ["key" => "file", "label" => "Job files", "icon" => "bi-file-earmark"],
                                ["key" => "chat", "label" => "Chat", "icon" => "bi-chat-dots"],
                                ["key" => "pub_chat", "label" => "Pub chat", "icon" => "bi-megaphone"],
                            ];
                            foreach ($scopes as $scope):
                                $pct = $totalFiles > 0 ? round($counts[$scope["key"]] / $totalFiles * 100) : 0;
                            ?>
                                <div class="scope-row">
                                    <div class="d-flex justify-content-between align-items-center small">
                                        <span class="fw-bold"><i class="bi <?= $scope["icon"] ?> me-1"></i><?= $scope["label"] ?></span>
                                        <span class="text-muted"><?= (int) $counts[$scope["key"]] ?> files · <?= e(uploadSettingsBytes((int) $scopeBytes[$scope["key"]])) ?></span>
                                    </div>
                                    <div class="scope-bar" role="progressbar" aria-valuenow="<?= (int) $pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= $scope["label"] ?> share of files">
                                        <span style="width: <?= (int) $pct ?>%;"></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="settings-card p-3 p-md-4">
                            <h2 class="h6 fw-bold mb-2"><i class="bi bi-link-45deg me-1"></i> Link preview</h2>
                            <div class="kv">
                                <div class="kv-row">
                                    <div class="kv-label">Job file</div>
                                    <div class="kv-value">
                                        <code class="inline" id="previewJob"><?= e($sampleFile) ?></code>
                                        <button type="button" class="copy-btn mt-1" data-copy-target="previewJob">
                                            <i class="bi bi-copy"></i> Copy
                                        </button>
                                    </div>
                                </div>
                                <div class="kv-row">
                                    <div class="kv-label">Chat file</div>
                                    <div class="kv-value">
                                        <code class="inline" id="previewChat"><?= e($sampleChat) ?></code>
                                        <button type="button" class="copy-btn mt-1" data-copy-target="previewChat">
                                            <i class="bi bi-copy"></i> Copy
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <p class="text-muted small mb-0 mt-2">Job links open from request views; chat links are returned by the chat APIs. Proxy links check login + ownership before streaming.</p>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<div class="toast-float" id="usToast" role="status" aria-live="polite">
    <i class="bi bi-check-circle-fill"></i><span id="usToastText">Copied</span>
</div>
<script>
(function () {
    var BASE_URL = <?= json_encode(rtrim(BASE_URL, "/"), JSON_UNESCAPED_SLASHES) ?>;
    var DEFAULT_DIR = <?= json_encode($defaultDir, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var DOC_ROOT = <?= json_encode($docRootHint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var SAVED_DIR = <?= json_encode($storedDir !== "" ? $storedDir : $currentDir, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var SAVED_URL = <?= json_encode($storedUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var FILE_COUNT = <?= (int) $totalFiles ?>;

    var dirInput = document.getElementById("uploads_dir");
    var urlInput = document.getElementById("uploads_url");
    var modeHint = document.getElementById("modeHint");
    var sampleHint = document.getElementById("sampleHint");
    var previewJob = document.getElementById("previewJob");
    var previewChat = document.getElementById("previewChat");
    var saveBtn = document.getElementById("saveBtn");

    function norm(p) { return String(p || "").replace(/\//g, "\\").replace(/\\+$/, "") + "\\"; }

    function isDirect(dir, url) {
        if (String(url || "").trim() !== "") return true;
        var doc = norm(DOC_ROOT), d = norm(dir);
        return doc.length > 1 && d.toLowerCase().indexOf(doc.toLowerCase()) === 0;
    }

    function sampleFor(sub, dir, url) {
        var name = sub === "chat" ? "1_abc123.pdf" : "example.pdf";
        if (isDirect(dir, url)) {
            var root = String(url || "").trim().replace(/\/+$/, "");
            if (root === "") {
                // Mirror server default: <app>/uploads is web-served.
                root = BASE_URL + "/uploads";
            }
            return root + (sub !== "" ? "/" + sub : "") + "/" + name;
        }
        var scope = sub !== "" ? sub : "file";
        return BASE_URL + "/download.php?scope=" + scope + "&file=" + name;
    }

    function refresh() {
        var dir = dirInput.value, url = urlInput.value;
        var direct = isDirect(dir, url);

        modeHint.className = "live-hint show " + (direct ? "direct" : "proxy");
        modeHint.innerHTML = direct
            ? '<i class="bi bi-link-45deg"></i> New links would use direct URLs.'
            : '<i class="bi bi-shield-lock"></i> New links would use the secure proxy (login + ownership checked).';

        var job = sampleFor("", dir, url), chat = sampleFor("chat", dir, url);
        sampleHint.className = "live-hint show " + (direct ? "direct" : "proxy");
        sampleHint.textContent = "Preview: " + job;
        if (previewJob) previewJob.textContent = job;
        if (previewChat) previewChat.textContent = chat;

        var dirty = (dir !== SAVED_DIR || String(url || "") !== SAVED_URL);
        saveBtn.classList.toggle("is-dirty", dirty);
    }

    dirInput.addEventListener("input", refresh);
    urlInput.addEventListener("input", refresh);

    document.getElementById("fillDefaultBtn").addEventListener("click", function () {
        dirInput.value = DEFAULT_DIR;
        urlInput.value = "";
        refresh();
        dirInput.focus();
    });

    // Confirm before migrating a large library to a new folder.
    document.querySelector('form[action$="upload_settings.php"]').addEventListener("submit", function (e) {
        var btn = e.submitter;
        if (!btn || btn.value !== "save") return;
        if (norm(dirInput.value).toLowerCase() === norm(SAVED_DIR).toLowerCase()) return;
        if (FILE_COUNT <= 0) return;
        if (!document.getElementById("migrate_files").checked) return;
        if (!confirm("Move " + FILE_COUNT + " existing file(s) into the new folder? The old files are kept as a safety copy.")) {
            e.preventDefault();
        }
    });

    // Copy buttons + toast.
    var toast = document.getElementById("usToast");
    var toastText = document.getElementById("usToastText");
    var timer = null;
    function showToast(msg) {
        toastText.textContent = msg;
        toast.classList.add("show");
        clearTimeout(timer);
        timer = setTimeout(function () { toast.classList.remove("show"); }, 2000);
    }
    function copyText(text, okMsg) {
        function fallback() {
            var ta = document.createElement("textarea");
            ta.value = text;
            ta.style.position = "fixed";
            ta.style.opacity = "0";
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand("copy"); showToast(okMsg); }
            catch (err) { showToast("Copy failed — select the text manually"); }
            document.body.removeChild(ta);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { showToast(okMsg); }, fallback);
        } else { fallback(); }
    }
    document.querySelectorAll("[data-copy]").forEach(function (el) {
        el.addEventListener("click", function () { copyText(el.getAttribute("data-copy") || "", "Copied to clipboard"); });
    });
    document.querySelectorAll("[data-copy-target]").forEach(function (el) {
        el.addEventListener("click", function () {
            var target = document.getElementById(el.getAttribute("data-copy-target") || "");
            copyText(target ? target.textContent : "", "Link copied");
        });
    });

    refresh();
})();
</script>
</body>
</html>
