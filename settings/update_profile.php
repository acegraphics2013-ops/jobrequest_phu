<?php
declare(strict_types=1);

include "../includes/config.php";
include "../includes/auth.php";

checkLogin();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header("Location: profile.php");
    exit;
}

$id = (int) ($_SESSION['user_id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

/* CSRF check (token issued by profile.php). */
$sessionToken = (string) ($_SESSION['csrf_profile'] ?? '');
$postedToken = (string) ($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
    header("Location: profile.php?error=" . urlencode("Security check failed. Please try again."));
    exit;
}

$fullname = trim((string) ($_POST['fullname'] ?? ''));
$department = trim((string) ($_POST['department'] ?? ''));

if ($fullname === '') {
    header("Location: profile.php?error=" . urlencode("Full name is required."));
    exit;
}

if (mb_strlen($fullname) > 150) {
    header("Location: profile.php?error=" . urlencode("Full name must not exceed 150 characters."));
    exit;
}

if (mb_strlen($department) > 150) {
    header("Location: profile.php?error=" . urlencode("Department must not exceed 150 characters."));
    exit;
}

if ($department === '') {
    $department = null;
}

try {
    /* Detect the actual name column (current schema: `name`; legacy: `fullname`). */
    $hasName = false;
    $hasFullname = false;
    $colRes = $conn->query("SHOW COLUMNS FROM users");
    if ($colRes) {
        while ($col = $colRes->fetch_assoc()) {
            $field = strtolower((string) ($col['Field'] ?? ''));
            if ($field === 'name') {
                $hasName = true;
            }
            if ($field === 'fullname') {
                $hasFullname = true;
            }
        }
        $colRes->free();
    }

    if ($hasName) {
        if ($department === null) {
            $stmt = $conn->prepare("UPDATE users SET name = ? WHERE id = ?");
            $stmt->bind_param("si", $fullname, $id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET name = ?, department = ? WHERE id = ?");
            $stmt->bind_param("ssi", $fullname, $department, $id);
        }
        $stmt->execute();
        $stmt->close();
    } elseif ($hasFullname) {
        if ($department === null) {
            $stmt = $conn->prepare("UPDATE users SET fullname = ? WHERE id = ?");
            $stmt->bind_param("si", $fullname, $id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET fullname = ?, department = ? WHERE id = ?");
            $stmt->bind_param("ssi", $fullname, $department, $id);
        }
        $stmt->execute();
        $stmt->close();
    } else {
        throw new RuntimeException("User name column not found.");
    }

    $_SESSION['name'] = $fullname;
    $_SESSION['department'] = $department ?? '';

    /* Rotate CSRF token after a successful update. */
    $_SESSION['csrf_profile'] = bin2hex(random_bytes(32));

    header("Location: profile.php?success=1");
    exit;
} catch (Throwable $e) {
    error_log(sprintf(
        "Profile update failed for user %d (name_len=%d dept_len=%d hasName=%d hasFullname=%d mysql_errno=%d mysql_error=%s): %s in %s:%d",
        $id,
        mb_strlen($fullname),
        $department === null ? -1 : mb_strlen($department),
        isset($hasName) ? (int) $hasName : -1,
        isset($hasFullname) ? (int) $hasFullname : -1,
        (int) $conn->errno,
        $conn->error,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    header("Location: profile.php?error=" . urlencode("Unable to update profile. Please try again."));
    exit;
}
