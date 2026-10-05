<?php

include "includes/config.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit;
}

$email = strtolower(
    trim((string) ($_POST["email"] ?? ""))
);

$password = (string) ($_POST["password"] ?? "");

if ($email === "" || $password === "") {
    $_SESSION["login_error"] =
        "Please enter your email and password.";
    $_SESSION["login_email"] = $email;

    header("Location: index.php");
    exit;
}

$hasPhotoCol = false;
try {
    $colCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'photo'");
    $hasPhotoCol = (bool) ($colCheck && $colCheck->num_rows > 0);
} catch (Throwable $e) {
    $hasPhotoCol = false;
}

$stmt = $conn->prepare(
    "SELECT
        id,
        name,
        email,
        password,
        role,
        department,
        status" . ($hasPhotoCol ? ",
        photo" : "") . "
     FROM users
     WHERE LOWER(email) = ?
     LIMIT 1"
);

$stmt->bind_param(
    "s",
    $email
);

$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

$stmt->close();

if (!$user) {
    $_SESSION["login_error"] =
        "Account not found.";
    $_SESSION["login_email"] = $email;

    header("Location: index.php");
    exit;
}

if ($user["status"] !== "Active") {
    $_SESSION["login_error"] =
        "Your account is inactive.";
    $_SESSION["login_email"] = $email;

    header("Location: index.php");
    exit;
}

if (!password_verify($password, $user["password"])) {
    $_SESSION["login_error"] =
        "Invalid email or password.";
    $_SESSION["login_email"] = $email;

    header("Location: index.php");
    exit;
}

unset($_SESSION["login_email"]);
session_regenerate_id(true);

$_SESSION["user_id"] = (int) $user["id"];
$_SESSION["name"] = $user["name"];
$_SESSION["email"] = $user["email"];
$_SESSION["role"] = $user["role"];
$_SESSION["department"] = $user["department"];
if (!empty($user["photo"])) {
    $_SESSION["photo"] = (string) $user["photo"];
} else {
    unset($_SESSION["photo"]);
}

switch ($_SESSION['role']) {

    case 'admin':
    case 'staff':
    case 'supervisor':
        header("Location: dashboard.php");
        break;

    case 'user':
        header("Location: user/my_requests.php");
        break;

    default:
        header("Location: logout.php");
        break;
}

exit;
