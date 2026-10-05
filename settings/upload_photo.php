<?php
declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | HPU WORKBENCH - PROFILE PHOTO UPLOAD / REMOVE (self-service)
 |--------------------------------------------------------------------------
 | Any logged-in user may set or remove their OWN photo.
 | Admins continue to manage all accounts via User Management.
 */

include "../includes/config.php";
include "../includes/auth.php";

checkLogin();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header("Location: profile.php");
    exit;
}

/* CSRF check (token issued by profile.php). */
$sessionToken = (string) ($_SESSION['csrf_profile'] ?? '');
$postedToken = (string) ($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
    header("Location: profile.php?error=" . urlencode("Security check failed. Please try again."));
    exit;
}

$action = trim((string) ($_POST['photo_action'] ?? 'upload'));

function profilePhotoColumn(mysqli $conn): void
{
    try {
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'photo'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN photo VARCHAR(255) NULL AFTER department");
        }
    } catch (Throwable $e) {
        throw new RuntimeException("Photo storage is unavailable.");
    }
}

function profilePhotoCurrent(mysqli $conn, int $userId): string
{
    try {
        $stmt = $conn->prepare("SELECT photo FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (string) ($row['photo'] ?? '');
    } catch (Throwable $e) {
        return '';
    }
}

function profilePhotoDeleteFile(string $stored, mysqli $conn): void
{
    $stored = basename($stored);
    if ($stored === '') {
        return;
    }
    try {
        $dir = uploadsSubDir("avatars", $conn);
        $candidate = $dir . DIRECTORY_SEPARATOR . $stored;
        $realDir = realpath($dir);
        $realFile = realpath($candidate);
        if (
            $realDir !== false && $realFile !== false && is_file($realFile)
            && stripos($realFile, rtrim($realDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0
        ) {
            @unlink($realFile);
        }
    } catch (Throwable $e) {
    }
}

try {
    profilePhotoColumn($conn);

    if ($action === 'remove') {
        $current = profilePhotoCurrent($conn, $userId);
        $stmt = $conn->prepare("UPDATE users SET photo = NULL WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
        if ($current !== '') {
            profilePhotoDeleteFile($current, $conn);
        }
        unset($_SESSION['photo']);
        header("Location: profile.php?success=1");
        exit;
    }

    /* Default: upload. */
    if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
        header("Location: profile.php?error=" . urlencode("Choose a photo to upload."));
        exit;
    }
    $file = $_FILES['photo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $msg = ($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE
            ? "Photo is too large. Maximum is 5 MB."
            : "Photo upload failed. Please try again.";
        header("Location: profile.php?error=" . urlencode($msg));
        exit;
    }
    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        header("Location: profile.php?error=" . urlencode("Photo must be 1 byte to 5 MB."));
        exit;
    }
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($extension, $allowed, true)) {
        header("Location: profile.php?error=" . urlencode("Unsupported photo type. Use JPG, PNG, GIF or WEBP."));
        exit;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($file['tmp_name']);
    $mimeAllow = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
    ];
    if (!in_array($mime, $mimeAllow[$extension] ?? [], true) || stripos($mime, 'text/html') !== false) {
        header("Location: profile.php?error=" . urlencode("That file is not a valid photo."));
        exit;
    }

    $storedName = 'u' . $userId . '_' . bin2hex(random_bytes(12)) . '.' . $extension;
    $uploadDir = uploadsSubDir("avatars", $conn);
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        header("Location: profile.php?error=" . urlencode("Upload folder could not be created."));
        exit;
    }
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . DIRECTORY_SEPARATOR . $storedName)) {
        header("Location: profile.php?error=" . urlencode("Could not save photo."));
        exit;
    }

    $previous = profilePhotoCurrent($conn, $userId);
    $stmt = $conn->prepare("UPDATE users SET photo = ? WHERE id = ?");
    $stmt->bind_param("si", $storedName, $userId);
    $stmt->execute();
    $stmt->close();
    if ($previous !== '' && $previous !== $storedName) {
        profilePhotoDeleteFile($previous, $conn);
    }

    $_SESSION['photo'] = $storedName;
    header("Location: profile.php?success=1");
    exit;
} catch (Throwable $e) {
    error_log("Profile photo failed for user {$userId}: " . $e->getMessage());
    header("Location: profile.php?error=" . urlencode("Unable to update photo. Please try again."));
    exit;
}
