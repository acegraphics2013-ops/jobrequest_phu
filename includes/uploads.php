<?php
/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - CUSTOMIZABLE UPLOAD LOCATION
|--------------------------------------------------------------------------
| Single source of truth for where uploaded files live on disk and how
| their public links are built.
|
| Admin UI: admin/upload_settings.php stores overrides in `app_settings`:
|   uploads_dir  -> absolute filesystem path (one shared root for job
|                   files + chat/ + pub_chat/ subfolders)
|   uploads_url  -> public base URL for that root (optional; blank means
|                   "serve everything through download.php proxy")
|
| Falls back to UPLOADS_DIR / BASE_URL/uploads when no override exists,
| so existing installs keep working with zero migration.
|
| Loaded automatically at the end of includes/config.php (needs $conn).
| Every helper accepts an optional mysqli so AJAX bootstraps and CLI
| scripts can pass their own connection explicitly.
|--------------------------------------------------------------------------
*/

if (!defined("UPLOADS_DIR")) {
    define(
        "UPLOADS_DIR",
        dirname(__DIR__) . DIRECTORY_SEPARATOR . "uploads"
    );
}

function uploadsDb(mysqli $conn = null): ?mysqli
{
    if ($conn instanceof mysqli) {
        return $conn;
    }

    if (isset($GLOBALS["conn"]) && $GLOBALS["conn"] instanceof mysqli) {
        return $GLOBALS["conn"];
    }

    return null;
}

function appSettingsEnsureTable(mysqli $conn = null): bool
{
    $db = uploadsDb($conn);

    if (!$db) {
        return false;
    }

    try {
        return (bool) $db->query(
            "CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
                setting_value TEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log("app_settings ensure failed: " . $e->getMessage());
        return false;
    }
}

function appSettingGet(string $key, string $default = "", mysqli $conn = null): string
{
    $db = uploadsDb($conn);

    if (!$db) {
        return $default;
    }

    try {
        $stmt = $db->prepare(
            "SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1"
        );

        if (!$stmt) {
            return $default;
        }

        $stmt->bind_param("s", $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !array_key_exists("setting_value", $row) || $row["setting_value"] === null) {
            return $default;
        }

        return (string) $row["setting_value"];
    } catch (Throwable $e) {
        // Table may not exist yet on first run — fall back silently.
        if (
            stripos($e->getMessage(), "doesn't exist") !== false
            || stripos($e->getMessage(), "Table") !== false
        ) {
            return $default;
        }

        error_log("appSettingGet failed ({$key}): " . $e->getMessage());
        return $default;
    }
}

function appSettingSet(string $key, string $value, mysqli $conn = null): bool
{
    $db = uploadsDb($conn);

    if (!$db) {
        return false;
    }

    if (!appSettingsEnsureTable($db)) {
        return false;
    }

    try {
        $stmt = $db->prepare(
            "INSERT INTO app_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("ss", $key, $value);
        $ok = $stmt->execute();
        $stmt->close();

        return (bool) $ok;
    } catch (Throwable $e) {
        error_log("appSettingSet failed ({$key}): " . $e->getMessage());
        return false;
    }
}

/* -------------------------------------------------------------------------
| Path helpers
------------------------------------------------------------------------- */

function uploadsNormalizeDir(string $path): string
{
    $path = trim($path);

    if ($path === "") {
        return "";
    }

    // Accept forward slashes everywhere; normalize to OS separator.
    $path = str_replace("/", DIRECTORY_SEPARATOR, $path);
    $path = str_replace("\\\\", DIRECTORY_SEPARATOR, $path);

    return rtrim($path, DIRECTORY_SEPARATOR);
}

function uploadsDefaultDir(): string
{
    return uploadsNormalizeDir((string) UPLOADS_DIR);
}

function uploadsRootDir(mysqli $conn = null): string
{
    $custom = uploadsNormalizeDir(
        appSettingGet("uploads_dir", "", $conn)
    );

    return $custom !== "" ? $custom : uploadsDefaultDir();
}

function uploadsDefaultUrl(): string
{
    return rtrim((string) BASE_URL, "/") . "/uploads";
}

function uploadsRootUrl(mysqli $conn = null): string
{
    $custom = rtrim(
        trim(appSettingGet("uploads_url", "", $conn)),
        "/"
    );

    return $custom !== "" ? $custom : uploadsDefaultUrl();
}

function uploadsDocRoot(): string
{
    $docRoot = (string) ($_SERVER["DOCUMENT_ROOT"] ?? "");

    if ($docRoot !== "") {
        $real = realpath($docRoot);
        if ($real !== false) {
            return rtrim($real, DIRECTORY_SEPARATOR);
        }

        return rtrim($docRoot, DIRECTORY_SEPARATOR);
    }

    // CLI fallback: Laragon-style <drive>:\laragon\www.
    return rtrim((string) dirname(__DIR__, 3), DIRECTORY_SEPARATOR);
}

/**
 * True when the configured root lives inside the web root, so direct
 * <uploads_url>/... links can work. Outside docroot => proxy only.
 */
function uploadsIsDirect(mysqli $conn = null): bool
{
    if (trim(appSettingGet("uploads_url", "", $conn)) !== "") {
        return true;
    }

    $root = uploadsRootDir($conn);
    $real = realpath($root);

    if ($real === false) {
        // Not created yet — judge by configured/default path text.
        $real = $root;
    }

    $docRoot = uploadsDocRoot();

    if ($docRoot === "" || $real === "") {
        return false;
    }

    return stripos(
        rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
        rtrim($docRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    ) === 0;
}

function uploadsHtaccessTemplate(): string
{
    return "Options -Indexes -ExecCGI\r\n"
        . "RemoveHandler .php .phtml .phar .cgi .pl .py .sh\r\n"
        . "RemoveType .php .phtml .phar .cgi .pl .py .sh\r\n"
        . "<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh|php5|php7|phps|pht|shtml|htaccess)$\">\r\n"
        . "    Require all denied\r\n"
        . "</FilesMatch>\r\n"
        . "<IfModule mod_headers.c>\r\n"
        . "    Header set X-Content-Type-Options \"nosniff\"\r\n"
        . "</IfModule>\r\n";
}

function uploadsEnsureHtaccess(string $dir): void
{
    $file = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ".htaccess";

    if (is_file($file)) {
        return;
    }

    @file_put_contents($file, uploadsHtaccessTemplate());
}

/**
 * Resolve (and create) a subfolder of the uploads root.
 * $sub: "" (job files), "chat", "pub_chat".
 */
function uploadsSubDir(string $sub = "", mysqli $conn = null): string
{
    $root = uploadsRootDir($conn);
    $sub = trim($sub, "/\\");

    $dir = $sub !== ""
        ? $root . DIRECTORY_SEPARATOR . $sub
        : $root;

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    if (is_dir($dir)) {
        uploadsEnsureHtaccess($dir);
    }

    return $dir;
}

/** Create root + chat/ + pub_chat/ with .htaccess files. */
function ensureUploadsTree(mysqli $conn = null): string
{
    $root = uploadsRootDir($conn);

    foreach (["", "chat", "pub_chat"] as $sub) {
        uploadsSubDir($sub, $conn);
    }

    return $root;
}

/**
 * Build the public link for a stored file.
 * Direct URL when the root is web-served, otherwise download.php proxy
 * (which enforces login + ownership before streaming the bytes).
 */
function uploadFileUrl(string $sub, string $filename, mysqli $conn = null): string
{
    $filename = (string) $filename;

    if ($filename === "") {
        return "";
    }

    $base = rtrim((string) BASE_URL, "/");
    $sub = trim($sub, "/\\");

    if (uploadsIsDirect($conn)) {
        $root = uploadsRootUrl($conn);
        $path = $sub !== "" ? $root . "/" . $sub : $root;

        return $path . "/" . rawurlencode(basename($filename));
    }

    $scope = $sub !== "" ? $sub : "file";

    return $base . "/download.php?scope=" . rawurlencode($scope)
        . "&file=" . rawurlencode(basename($filename));
}

/* -------------------------------------------------------------------------
| Validation (shared by the admin UI and the proxy)
------------------------------------------------------------------------- */

function uploadsAllowedExtensions(): array
{
    return [
        "jpg",
        "jpeg",
        "png",
        "gif",
        "webp",
        "pdf",
        "doc",
        "docx",
        "xls",
        "xlsx",
        "ppt",
        "pptx",
        "zip",
    ];
}

function uploadsValidateDir(string $path): array
{
    $path = uploadsNormalizeDir($path);

    if ($path === "") {
        return ["ok" => false, "message" => "Upload folder path is required."];
    }

    if (preg_match('/[\*\?\"<>\|]/', $path)) {
        return ["ok" => false, "message" => "Path contains invalid characters (* ? \" < > |)."];
    }

    // Must be absolute: Windows drive/UNC or Unix root.
    $absolute = (bool) preg_match('/^[A-Za-z]:\\\\/', $path . "\\")
        || str_starts_with($path, "\\\\")
        || str_starts_with(str_replace(DIRECTORY_SEPARATOR, "/", $path), "/");

    if (!$absolute) {
        return ["ok" => false, "message" => "Use an absolute path, e.g. C:\\uploads\\hpu or /var/www/uploads."];
    }

    if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
        return ["ok" => false, "message" => "Folder could not be created. Check the path and permissions."];
    }

    $probe = $path . DIRECTORY_SEPARATOR . ".writetest_" . bin2hex(random_bytes(4));

    if (@file_put_contents($probe, "ok") === false) {
        return ["ok" => false, "message" => "Folder is not writable by the web server."];
    }

    @unlink($probe);

    return ["ok" => true, "message" => "Folder is valid and writable.", "path" => $path];
}

function uploadsValidateUrl(string $url): array
{
    $url = trim($url);

    if ($url === "") {
        return ["ok" => true, "message" => "Empty — files will be served via the secure proxy.", "url" => ""];
    }

    if (!preg_match('#^https?://#i', $url)) {
        return ["ok" => false, "message" => "Public URL must start with http:// or https://."];
    }

    return ["ok" => true, "message" => "URL looks valid.", "url" => rtrim($url, "/")];
}

function uploadsSafeFilename(string $name): string
{
    $name = basename(trim((string) $name));

    if ($name === "" || $name === "." || $name === "..") {
        return "";
    }

    if (str_contains($name, "\0")) {
        return "";
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (!in_array($ext, uploadsAllowedExtensions(), true)) {
        return "";
    }

    return $name;
}

/** Count files in each scope of a root (for the admin UI + migration check). */
function uploadsScopeCounts(string $root): array
{
    $counts = ["file" => 0, "chat" => 0, "pub_chat" => 0];

    foreach (["file" => "", "chat" => "chat", "pub_chat" => "pub_chat"] as $scope => $sub) {
        $dir = $sub !== ""
            ? rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sub
            : $root;

        if (!is_dir($dir)) {
            continue;
        }

        $files = @scandir($dir);

        if (!is_array($files)) {
            continue;
        }

        foreach ($files as $f) {
            if ($f === "." || $f === ".." || $f === ".htaccess" || str_starts_with($f, ".writetest_")) {
                continue;
            }

            if (is_file($dir . DIRECTORY_SEPARATOR . $f)) {
                $counts[$scope]++;
            }
        }
    }

    return $counts;
}
