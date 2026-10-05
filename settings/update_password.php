<?php
include "../includes/config.php";
include "../includes/auth.php";

checkLogin();

$user_id = $_SESSION['user_id'];

$current = $_POST['current_password'];
$new = $_POST['new_password'];
$confirm = $_POST['confirm_password'];

/* Get user password from DB */
$stmt = $conn->prepare("SELECT password FROM users WHERE id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

/* Check current password */
if(!password_verify($current, $user['password'])){
    header("Location: change_password.php?error=Current password is incorrect");
    exit;
}

/* Check new password match */
if($new !== $confirm){
    header("Location: change_password.php?error=New passwords do not match");
    exit;
}

/* Optional: password strength check */
if(strlen($new) < 6){
    header("Location: change_password.php?error=Password must be at least 6 characters");
    exit;
}

/* Hash new password */
$hashed = password_hash($new, PASSWORD_DEFAULT);

/* Update password */
$stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
$stmt->bind_param("si", $hashed, $user_id);
$stmt->execute();

header("Location: change_password.php?success=1");
exit;