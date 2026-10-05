<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - SECURE FILE DOWNLOAD PROXY
|--------------------------------------------------------------------------
| Serves uploaded files when the configured upload root is NOT directly
| web-accessible (custom path outside docroot, blank public URL, CDN).
|
|   download.php?scope=file&file=<stored_name>       job attachments
|   download.php?scope=chat&file=<stored_name>       job chat files
|   download.php?scope=pub_chat&file=<stored_name>   publication chat files
|   download.php?scope=avatars&file=<stored_name>    profile photos (any login)
|
| Login is always required. Staff/supervisor/admin can open any file;
| requestors (role=user) can only open files attached to their own
| requests / submissions. Internal chat notes never leak: the file is
| only reachable when the user can already see the message that
| references it.
|--------------------------------------------------------------------------
*/

include __DIR__ . "/includes/config.php";
include __DIR__ . "/includes/auth.php";
include __DIR__ . "/includes/helpers.php";

checkLogin();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$userId = (int) ($_SESSION["user_id"] ?? 0);
$role = (string) ($_SESSION["role"] ?? "user");
$isPrivileged = in_array($role, ["staff", "supervisor", "admin"], true);

$scope = strtolower(trim((string) ($_GET["scope"] ?? "file")));

if (!in_array($scope, ["file", "chat", "pub_chat", "avatars"], true)) {
    http_response_code(404);
    exit("File not found.");
}

$filename = uploadsSafeFilename((string) ($_GET["file"] ?? ""));

if ($filename === "" || $userId <= 0) {
    http_response_code(404);
    exit("File not found.");
}

/* Drop a stale avatar filename from the session so initials render
   directly instead of a broken image on the next page load. */
function clearStaleAvatarSession(string $filename): void
{
    if (
        isset($_SESSION["photo"])
        && basename((string) $_SESSION["photo"]) === basename($filename)
        && basename($filename) !== ""
    ) {
        unset($_SESSION["photo"]);
        $_SESSION["photo_checked"] = true;
    }
}

/* =========================================================
   AUTHORIZATION — file must belong to something the user
   is allowed to see.
========================================================= */
$allowed = false;

try {
    if ($scope === "avatars") {
        // Profile photos are visible to any logged-in user.
        $stmt = $conn->prepare("SELECT id FROM users WHERE photo = ? LIMIT 1");
        $stmt->bind_param("s", $filename);
        $stmt->execute();
        $allowed = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } elseif ($isPrivileged) {
        $allowed = true;
    } elseif ($scope === "file") {
        // Requestor owns a job whose attachment list contains this file.
        $like = "%" . $filename . "%";
        $stmt = $conn->prepare(
            "SELECT id FROM job_requests
              WHERE requestor = ? AND attachment LIKE ?
              LIMIT 1"
        );
        $stmt->bind_param("is", $userId, $like);
        $stmt->execute();
        $allowed = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } elseif ($scope === "chat") {
        // File is referenced by a visible (non-internal) message in
        // one of the requestor's own threads.
        $stmt = $conn->prepare(
            "SELECT m.id
               FROM request_messages m
               INNER JOIN job_requests jr
                       ON jr.id = m.request_id AND jr.requestor = ?
              WHERE m.attachment = ? AND m.is_internal = 0
              LIMIT 1"
        );
        $stmt->bind_param("is", $userId, $filename);
        $stmt->execute();
        $allowed = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        // pub_chat: same rule against the user's own submissions.
        $stmt = $conn->prepare(
            "SELECT m.id
               FROM publication_messages m
              WHERE m.attachment = ? AND m.is_internal = 0
                AND (
                      EXISTS (SELECT 1 FROM publication_event_submissions e
                               WHERE e.id = m.publication_id AND e.submitted_by = ?
                                 AND m.kind IN ('event','activity','event/activity'))
                   OR EXISTS (SELECT 1 FROM publication_recognition_submissions r
                               WHERE r.id = m.publication_id AND r.submitted_by = ?
                                 AND m.kind IN ('recognition','milestone','recognition/milestone'))
                   OR EXISTS (SELECT 1 FROM publication_content_contributions c
                               WHERE c.id = m.publication_id AND c.submitted_by = ?
                                 AND m.kind IN ('contribution','content','content contribution'))
                )
              LIMIT 1"
        );

        if ($stmt) {
            $stmt->bind_param("siii", $filename, $userId, $userId, $userId);
            $stmt->execute();
            $allowed = (bool) $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } else {
            $allowed = false;
        }
    }
} catch (Throwable $e) {
    error_log("download.php auth check failed: " . $e->getMessage());
    $allowed = false;
}

if (!$allowed) {
    if ($scope === "avatars") {
        clearStaleAvatarSession($filename);
    }
    http_response_code(403);
    exit("Access denied.");
}

/* =========================================================
   RESOLVE PATH — realpath must stay inside the scope dir
========================================================= */
$sub = $scope === "file" ? "" : $scope;
$dir = uploadsSubDir($sub);

$realDir = realpath($dir);
$candidate = $dir . DIRECTORY_SEPARATOR . basename($filename);
$realFile = realpath($candidate);

if ($realDir === false || $realFile === false || !is_file($realFile)) {
    if ($scope === "avatars") {
        clearStaleAvatarSession($filename);
    }
    http_response_code(404);
    exit("File not found.");
}

if (stripos($realFile, rtrim($realDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403);
    exit("Access denied.");
}

/* =========================================================
   STREAM
========================================================= */
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) @$finfo->file($realFile);

$inline = [
    "image/jpeg", "image/png", "image/gif", "image/webp",
    "application/pdf",
];

$isInline = !empty($_GET["inline"]) || in_array(strtolower($mime), $inline, true);

if ($mime === "" || $mime === "application/octet-stream" && !$isInline) {
    $mime = "application/octet-stream";
}

header("Content-Type: " . $mime);
header("Content-Length: " . filesize($realFile));
header("X-Content-Type-Options: nosniff");
header("Cache-Control: private, max-age=3600");
header(
    ($isInline ? "Content-Disposition: inline" : "Content-Disposition: attachment")
    . "; filename=\"" . addcslashes(basename($realFile), "\"\\") . "\""
);

$handle = @fopen($realFile, "rb");

if ($handle === false) {
    http_response_code(404);
    exit("File not found.");
}

fpassthru($handle);
fclose($handle);
exit;
