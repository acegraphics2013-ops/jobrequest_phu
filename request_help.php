<?php

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - PUBLIC ACCOUNT HELP HANDLER
|--------------------------------------------------------------------------
| File:
|   C:\laragon\www\jobrequest_phu\request_help.php
|
| Accepts the "Need account help?" form from index.php
| (no login required). Valid tickets are stored in
| `account_help_requests` with status 'Pending' for resolution
| by admin, supervisor or HPU staff on
| staff/account_requests.php (Help tab).
|--------------------------------------------------------------------------
*/

include __DIR__ . "/includes/config.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function ahFail(string $message, array $old = []): never
{
    $_SESSION["help_error"] = $message;
    $_SESSION["help_old"] = $old;
    $_SESSION["help_open"] = true;

    unset($_SESSION["help_success"]);

    header("Location: index.php");
    exit;
}

function ahEnsureTable(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS account_help_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(150) NOT NULL,
            contact VARCHAR(50) NULL,
            issue_type ENUM('forgot_password','inactive','correction','other') NOT NULL DEFAULT 'other',
            details TEXT NULL,
            status ENUM('Pending','Resolved','Declined') NOT NULL DEFAULT 'Pending',
            reviewed_by INT NULL,
            reviewed_by_name VARCHAR(150) NULL,
            review_notes TEXT NULL,
            reviewed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_help_status (status),
            INDEX idx_help_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ahTableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");

    return $result && $result->num_rows > 0;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit;
}

/* Already signed in? Use the in-app routes instead. */
if (!empty($_SESSION["user_id"])) {
    header("Location: index.php");
    exit;
}

$email = strtolower(trim((string) ($_POST["email"] ?? "")));
$contact = trim((string) ($_POST["contact"] ?? ""));
$issueType = trim((string) ($_POST["issue_type"] ?? "other"));
$details = trim((string) ($_POST["details"] ?? ""));

$old = [
    "email" => $email,
    "contact" => $contact,
    "issue_type" => $issueType,
    "details" => $details,
];

/* Simple per-session throttle (45 seconds between submissions). */
$lastSubmit = (int) ($_SESSION["help_last_submit"] ?? 0);

if ($lastSubmit > 0 && (time() - $lastSubmit) < 45) {
    ahFail("Please wait a moment before submitting another request.", $old);
}

/* Honeypot: bots fill this hidden field, humans never see it. */
if (trim((string) ($_POST["website"] ?? "")) !== "") {
    $_SESSION["help_success"] =
        "Your help request has been received. HPU personnel will assist you shortly.";

    unset($_SESSION["help_error"]);
    unset($_SESSION["help_old"]);
    unset($_SESSION["help_open"]);

    $_SESSION["help_last_submit"] = time();

    header("Location: index.php");
    exit;
}

/* CSRF check (token is issued on the public login page). */
$sessionToken = (string) ($_SESSION["account_csrf"] ?? "");
$postedToken = (string) ($_POST["csrf_token"] ?? "");

if (
    $sessionToken === ""
    || $postedToken === ""
    || !hash_equals($sessionToken, $postedToken)
) {
    ahFail(
        "Security token expired. Please reopen the form and try again.",
        $old
    );
}

$allowedTypes = ["forgot_password", "inactive", "correction", "other"];

if (!in_array($issueType, $allowedTypes, true)) {
    ahFail("Please choose a valid issue type.", $old);
}

if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ahFail("Please enter the valid email address of your account.", $old);
}

if ($contact !== "" && mb_strlen($contact) > 50) {
    ahFail("Contact number is too long.", $old);
}

if (mb_strlen($details) < 10) {
    ahFail(
        "Please describe the problem in at least 10 characters so HPU can help faster.",
        $old
    );
}

if (mb_strlen($details) > 2000) {
    ahFail("Details are too long (2000 characters max).", $old);
}

try {
    ahEnsureTable($conn);

    /* Help tickets presuppose an account; otherwise point to signup. */
    $userStmt = $conn->prepare(
        "SELECT id, status FROM users WHERE LOWER(email) = ? LIMIT 1"
    );
    $userStmt->bind_param("s", $email);
    $userStmt->execute();
    $account = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();

    if (!$account) {
        ahFail(
            "No Workbench account uses that email. Use Request Account to create one instead.",
            $old
        );
    }

    /* One open help ticket per email at a time. */
    $dupStmt = $conn->prepare(
        "SELECT id FROM account_help_requests
          WHERE LOWER(email) = ?
            AND status = 'Pending'
          LIMIT 1"
    );
    $dupStmt->bind_param("s", $email);
    $dupStmt->execute();
    $duplicate = $dupStmt->get_result()->fetch_assoc();
    $dupStmt->close();

    if ($duplicate) {
        ahFail(
            "That email already has an open help ticket. HPU personnel will assist you shortly.",
            $old
        );
    }

    $contactValue = $contact !== "" ? $contact : null;
    $pending = "Pending";

    $insertStmt = $conn->prepare(
        "INSERT INTO account_help_requests
            (email, contact, issue_type, details, status)
         VALUES (?, ?, ?, ?, ?)"
    );
    $insertStmt->bind_param(
        "sssss",
        $email,
        $contactValue,
        $issueType,
        $details,
        $pending
    );
    $insertStmt->execute();
    $insertStmt->close();

    /* Notify every active HPU resolver. */
    try {
        if (ahTableExists($conn, "notifications")) {
            $resolvers = $conn->query(
                "SELECT id FROM users
                  WHERE role IN ('admin','supervisor','staff')
                    AND status = 'Active'"
            );

            if ($resolvers) {
                $rows = $resolvers->fetch_all(MYSQLI_ASSOC);

                if ($rows) {
                    $typeLabels = [
                        "forgot_password" => "password reset",
                        "inactive" => "reactivation",
                        "correction" => "account correction",
                        "other" => "account help",
                    ];

                    $notifyTitle = "New Account Help Request";
                    $notifyMessage =
                        $email . " requested "
                        . ($typeLabels[$issueType] ?? "account help")
                        . ". Review it under Account Requests (Help tab).";
                    $notifyType = "account_help";
                    $notifyUrl =
                        rtrim(BASE_URL, "/")
                        . "/staff/account_requests.php?tab=help";

                    $notifyStmt = $conn->prepare(
                        "INSERT INTO notifications
                            (user_id, request_id, title, message,
                             notification_type, action_url, is_read)
                         VALUES (?, NULL, ?, ?, ?, ?, 0)"
                    );

                    foreach ($rows as $resolver) {
                        $resolverId = (int) $resolver["id"];

                        if ($resolverId <= 0) {
                            continue;
                        }

                        $notifyStmt->bind_param(
                            "issss",
                            $resolverId,
                            $notifyTitle,
                            $notifyMessage,
                            $notifyType,
                            $notifyUrl
                        );
                        $notifyStmt->execute();
                    }

                    $notifyStmt->close();
                }
            }
        }
    } catch (Throwable $notifyError) {
        error_log(
            "Account help notification failed: "
            . $notifyError->getMessage()
        );
    }

    $_SESSION["help_success"] =
        "Your help request has been received. HPU personnel will assist you shortly.";

    unset($_SESSION["help_error"]);
    unset($_SESSION["help_old"]);
    unset($_SESSION["help_open"]);

    $_SESSION["help_last_submit"] = time();

    header("Location: index.php");
    exit;
} catch (Throwable $e) {
    error_log("Account help request failed: " . $e->getMessage());

    ahFail(
        "Unable to submit your request right now. Please try again later.",
        $old
    );
}
