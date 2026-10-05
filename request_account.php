<?php

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - PUBLIC ACCOUNT REQUEST HANDLER
|--------------------------------------------------------------------------
| File:
|   C:\laragon\www\jobrequest_phu\request_account.php
|
| Accepts the "Request Account" form from index.php (no login required).
| Valid requests are stored in `account_requests` with status 'Pending'
| for approval by admin, supervisor or HPU staff on
| staff/account_requests.php.
|--------------------------------------------------------------------------
*/

include __DIR__ . "/includes/config.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function arFail(string $message, array $old = []): never
{
    $_SESSION["account_error"] = $message;
    $_SESSION["account_old"] = $old;
    $_SESSION["account_open"] = true;

    unset($_SESSION["account_success"]);

    header("Location: index.php");
    exit;
}

function arTableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");

    return $result && $result->num_rows > 0;
}

function arEnsureTable(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS account_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(150) NOT NULL,
            contact VARCHAR(50) NULL,
            department VARCHAR(150) NOT NULL,
            position VARCHAR(150) NULL,
            requested_role ENUM('user','staff','supervisor') NOT NULL DEFAULT 'user',
            reason TEXT NULL,
            password_hash VARCHAR(255) NOT NULL,
            status ENUM('Pending','Approved','Declined') NOT NULL DEFAULT 'Pending',
            reviewed_by INT NULL,
            reviewed_by_name VARCHAR(150) NULL,
            review_notes TEXT NULL,
            reviewed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_account_status (status),
            INDEX idx_account_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit;
}

/* Already signed in? No need to request an account. */
if (!empty($_SESSION["user_id"])) {
    header("Location: index.php");
    exit;
}

$name = trim((string) ($_POST["name"] ?? ""));
$email = strtolower(trim((string) ($_POST["email"] ?? "")));
$contact = trim((string) ($_POST["contact"] ?? ""));
$department = trim((string) ($_POST["department"] ?? ""));
$position = trim((string) ($_POST["position"] ?? ""));
/*
 * Public requests are always created as Requestor (user).
 * Elevated access (staff / supervisor) is granted internally
 * by an administrator — never self-selected. The posted value
 * is ignored so tampered forms cannot escalate privileges.
 */
$requestedRole = "user";
$reason = trim((string) ($_POST["reason"] ?? ""));
$password = (string) ($_POST["password"] ?? "");
$confirmPassword = (string) ($_POST["confirm_password"] ?? "");

$old = [
    "name" => $name,
    "email" => $email,
    "contact" => $contact,
    "department" => $department,
    "position" => $position,
    "requested_role" => $requestedRole,
    "reason" => $reason,
];

/* Simple per-session throttle (45 seconds between submissions). */
$lastSubmit = (int) ($_SESSION["account_last_submit"] ?? 0);

if ($lastSubmit > 0 && (time() - $lastSubmit) < 45) {
    arFail("Please wait a moment before submitting another request.", $old);
}

/* Honeypot: bots fill this hidden field, humans never see it. */
if (trim((string) ($_POST["website"] ?? "")) !== "") {
    $_SESSION["account_success"] =
        "Your account request has been received and is pending HPU review.";

    unset($_SESSION["account_error"]);
    unset($_SESSION["account_old"]);
    unset($_SESSION["account_open"]);

    $_SESSION["account_last_submit"] = time();

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
    arFail(
        "Security token expired. Please reopen the form and try again.",
        $old
    );
}

/* Role is fixed to "user" above; no public selection to validate. */

if ($name === "" || $email === "" || $department === "") {
    arFail("Please complete your name, email and department/office.", $old);
}

if (mb_strlen($name) < 3) {
    arFail("Please enter your complete name.", $old);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    arFail("Please enter a valid email address.", $old);
}

if (mb_strlen($department) > 150) {
    arFail("Department/office name is too long.", $old);
}

if ($contact !== "" && mb_strlen($contact) > 50) {
    arFail("Contact number is too long.", $old);
}

if ($position !== "" && mb_strlen($position) > 150) {
    arFail("Position/designation is too long.", $old);
}

if (strlen($password) < 8) {
    arFail("Password must contain at least 8 characters.", $old);
}

if ($password !== $confirmPassword) {
    arFail("Password confirmation does not match.", $old);
}

if (mb_strlen($reason) > 2000) {
    arFail("Justification is too long (2000 characters max).", $old);
}

try {
    arEnsureTable($conn);

    /* Block requests for emails that already own an account. */
    $takenStmt = $conn->prepare(
        "SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1"
    );
    $takenStmt->bind_param("s", $email);
    $takenStmt->execute();
    $taken = $takenStmt->get_result()->fetch_assoc();
    $takenStmt->close();

    if ($taken) {
        arFail(
            "That email already has a Workbench account. Please sign in instead, or contact HPU for help.",
            $old
        );
    }

    /* Block duplicate pending requests for the same email. */
    $dupStmt = $conn->prepare(
        "SELECT id FROM account_requests
          WHERE LOWER(email) = ?
            AND status = 'Pending'
          LIMIT 1"
    );
    $dupStmt->bind_param("s", $email);
    $dupStmt->execute();
    $duplicate = $dupStmt->get_result()->fetch_assoc();
    $dupStmt->close();

    if ($duplicate) {
        arFail(
            "That email already has a pending request. HPU personnel will review it shortly.",
            $old
        );
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($passwordHash === false) {
        arFail("Unable to secure your password. Please try again.", $old);
    }

    $contactValue = $contact !== "" ? $contact : null;
    $positionValue = $position !== "" ? $position : null;
    $reasonValue = $reason !== "" ? $reason : null;
    $pending = "Pending";

    $insertStmt = $conn->prepare(
        "INSERT INTO account_requests
            (name, email, contact, department, position,
             requested_role, reason, password_hash, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $insertStmt->bind_param(
        "sssssssss",
        $name,
        $email,
        $contactValue,
        $department,
        $positionValue,
        $requestedRole,
        $reasonValue,
        $passwordHash,
        $pending
    );
    $insertStmt->execute();
    $insertStmt->close();

    /*
     * Notify every active HPU approver so pending requests
     * do not sit unnoticed.
     */
    try {
        if (arTableExists($conn, "notifications")) {
            $approvers = $conn->query(
                "SELECT id FROM users
                  WHERE role IN ('admin','supervisor','staff')
                    AND status = 'Active'"
            );

            if ($approvers) {
                $rows = $approvers->fetch_all(MYSQLI_ASSOC);

                if ($rows) {
                    $roleLabel = $requestedRole === "user"
                        ? "Requestor"
                        : ucfirst($requestedRole);

                    $notifyTitle = "New Account Request";
                    $notifyMessage =
                        $name . " (" . $email . ") requested "
                        . $roleLabel . " access for "
                        . $department . ". Review it under Account Requests.";
                    $notifyType = "account_request";
                    $notifyUrl =
                        rtrim(BASE_URL, "/")
                        . "/staff/account_requests.php";

                    $notifyStmt = $conn->prepare(
                        "INSERT INTO notifications
                            (user_id, request_id, title, message,
                             notification_type, action_url, is_read)
                         VALUES (?, NULL, ?, ?, ?, ?, 0)"
                    );

                    foreach ($rows as $approver) {
                        $approverId = (int) $approver["id"];

                        if ($approverId <= 0) {
                            continue;
                        }

                        $notifyStmt->bind_param(
                            "issss",
                            $approverId,
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
            "Account request notification failed: "
            . $notifyError->getMessage()
        );
    }

    $_SESSION["account_success"] =
        "Your account request has been received and is pending HPU review. "
        . "You can sign in with this email and password once it is approved.";

    unset($_SESSION["account_error"]);
    unset($_SESSION["account_old"]);
    unset($_SESSION["account_open"]);

    $_SESSION["account_last_submit"] = time();

    header("Location: index.php");
    exit;
} catch (Throwable $e) {
    error_log("Account request failed: " . $e->getMessage());

    arFail(
        "Unable to submit your request right now. Please try again later.",
        $old
    );
}
