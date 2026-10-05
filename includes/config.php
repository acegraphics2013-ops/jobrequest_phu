<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    // PHP 7.3+ array syntax; Lax blocks cross-site POST CSRF in modern browsers.
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$httpHost = $_SERVER['HTTP_HOST'] ?? '192.168.3.168';
define("BASE_URL", $protocol . $httpHost . "/jobrequest_phu");


define(
    "UPLOADS_DIR",
    dirname(__DIR__) . DIRECTORY_SEPARATOR . "uploads"
);

$host = "127.0.0.1";
$user = "root";
$pass = "";
$db   = "hpu_job_request";

$conn = new mysqli(
    $host,
    $user,
    $pass,
    $db
);

if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    http_response_code(500);
    die(
        "Database connection failed. Please try again later."
    );
}

$conn->set_charset("utf8mb4");

date_default_timezone_set(
    "Asia/Manila"
);

/* Customizable upload location (app_settings overrides). */
require_once __DIR__ . "/uploads.php";

/* Automatically bootstrap Laravel framework across system */
require_once __DIR__ . "/laravel_bootstrap.php";