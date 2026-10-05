<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - ACCOUNT REQUEST APPROVALS
|--------------------------------------------------------------------------
| File:
|   C:\laragon\www\jobrequest_phu\staff\account_requests.php
|
| Public visitors request accounts from the login page
| (request_account.php). Admins, supervisors and HPU staff
| review them here:
|   staff      -> may decide 'user' (Requestor) requests only
|   supervisor -> may decide 'user' and 'staff' requests
|   admin      -> may decide any request
|
| Approving creates the real `users` row so the requestor can
| sign in immediately with the password chosen in the request.
|--------------------------------------------------------------------------
*/

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

requireRole(["admin", "supervisor", "staff"]);
requireModule($conn, "account_requests");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$currentUserId = (int) ($_SESSION["user_id"] ?? 0);
$currentRole = (string) ($_SESSION["role"] ?? "");
$currentName = (string) ($_SESSION["name"] ?? "HPU Personnel");

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

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

/**
 * Who may approve which requested access level.
 */
function arCanDecide(string $myRole, string $requestedRole): bool
{
    return match ($myRole) {
        "admin" => in_array(
            $requestedRole,
            ["user", "staff", "supervisor"],
            true
        ),
        "supervisor" => in_array(
            $requestedRole,
            ["user", "staff"],
            true
        ),
        "staff" => $requestedRole === "user",
        default => false,
    };
}

function arRoleLabel(string $role): string
{
    return match ($role) {
        "staff" => "HPU Staff",
        "supervisor" => "Supervisor",
        default => "Requestor",
    };
}

function arRoleClass(string $role): string
{
    return match ($role) {
        "supervisor" => "role-supervisor",
        "staff" => "role-staff",
        default => "role-user",
    };
}

function arStatusClass(string $status): string
{
    return match ($status) {
        "Pending" => "status-pending",
        "Approved" => "status-approved",
        "Declined" => "status-declined",
        default => "status-default",
    };
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

function ahIssueLabel(string $type): string
{
    return match ($type) {
        "forgot_password" => "Password Reset",
        "inactive" => "Reactivation",
        "correction" => "Correction",
        default => "Other Help",
    };
}

function ahIssueClass(string $type): string
{
    return match ($type) {
        "forgot_password" => "role-supervisor",
        "inactive" => "status-pending",
        "correction" => "role-staff",
        default => "role-user",
    };
}

/**
 * Temporary password resets are privileged: only admin and
 * supervisor may issue them. Every other help action is open
 * to staff, supervisor and admin alike.
 */
function ahCanResetPassword(string $myRole): bool
{
    return in_array($myRole, ["admin", "supervisor"], true);
}

function ahFlash(string $type, string $message): never
{
    $_SESSION["ar_flash"] = [
        "type" => $type,
        "message" => $message,
    ];

    header("Location: account_requests.php?tab=help");
    exit;
}

function arFlash(string $type, string $message): never
{
    $_SESSION["ar_flash"] = [
        "type" => $type,
        "message" => $message,
    ];

    header("Location: account_requests.php");
    exit;
}

function arValidateCsrf(): void
{
    $sessionToken = (string) ($_SESSION["csrf_token"] ?? "");
    $postedToken = (string) ($_POST["csrf_token"] ?? "");

    if (
        $sessionToken === ""
        || $postedToken === ""
        || !hash_equals($sessionToken, $postedToken)
    ) {
        arFlash("danger", "Security token expired. Please try again.");
    }
}

function arScalar(
    mysqli $conn,
    string $sql,
    string $types = "",
    array $params = []
): int {
    $stmt = $conn->prepare($sql);

    if ($types !== "" && $params) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    return (int) ($row[0] ?? 0);
}

/*
|--------------------------------------------------------------------------
| CSRF + Table
|--------------------------------------------------------------------------
*/

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION["csrf_token"];

arEnsureTable($conn);
ahEnsureTable($conn);

/*
|--------------------------------------------------------------------------
| POST Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    arValidateCsrf();

    $action = trim((string) ($_POST["action"] ?? ""));
    $requestId = (int) ($_POST["request_id"] ?? 0);

    if ($requestId <= 0) {
        arFlash("danger", "Invalid account request.");
    }

    /* --------------------------------------------------
       APPROVE: create the real user account.
    -------------------------------------------------- */
    if ($action === "approve") {
        $reviewNotes = trim((string) ($_POST["review_notes"] ?? ""));

        try {
            $conn->begin_transaction();

            $lockStmt = $conn->prepare(
                "SELECT
                    id, name, email, department,
                    requested_role, password_hash, status
                  FROM account_requests
                  WHERE id = ?
                  FOR UPDATE"
            );
            $lockStmt->bind_param("i", $requestId);
            $lockStmt->execute();
            $request = $lockStmt->get_result()->fetch_assoc();
            $lockStmt->close();

            if (!$request) {
                $conn->rollback();
                arFlash("danger", "Account request not found.");
            }

            if (($request["status"] ?? "") !== "Pending") {
                $conn->rollback();
                arFlash(
                    "warning",
                    "That request was already decided by another reviewer."
                );
            }

            $requestedRole = (string) ($request["requested_role"] ?? "user");

            if (!arCanDecide($currentRole, $requestedRole)) {
                $conn->rollback();
                arFlash(
                    "danger",
                    "Your role cannot approve "
                    . arRoleLabel($requestedRole)
                    . " access. An administrator must decide this request."
                );
            }

            $email = strtolower(trim((string) ($request["email"] ?? "")));

            $existsStmt = $conn->prepare(
                "SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1"
            );
            $existsStmt->bind_param("s", $email);
            $existsStmt->execute();
            $exists = $existsStmt->get_result()->fetch_assoc();
            $existsStmt->close();

            if ($exists) {
                $conn->rollback();
                arFlash(
                    "danger",
                    "That email already owns an account. Decline the request instead."
                );
            }

            $active = "Active";

            $userStmt = $conn->prepare(
                "INSERT INTO users
                    (name, email, password, role, department, status)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $userStmt->bind_param(
                "ssssss",
                $request["name"],
                $email,
                $request["password_hash"],
                $requestedRole,
                $request["department"],
                $active
            );
            $userStmt->execute();
            $newUserId = (int) $userStmt->insert_id;
            $userStmt->close();

            $approved = "Approved";

            $updateStmt = $conn->prepare(
                "UPDATE account_requests
                  SET status = ?,
                      reviewed_by = ?,
                      reviewed_by_name = ?,
                      review_notes = ?,
                      reviewed_at = NOW()
                  WHERE id = ?"
            );
            $noteValue = $reviewNotes !== "" ? $reviewNotes : null;
            $updateStmt->bind_param(
                "sissi",
                $approved,
                $currentUserId,
                $currentName,
                $noteValue,
                $requestId
            );
            $updateStmt->execute();
            $updateStmt->close();

            /* Welcome notification for the newly created account. */
            try {
                if (arTableExists($conn, "notifications")) {
                    $welcomeTitle = "Account Approved — Welcome to HPU Workbench";
                    $welcomeMessage =
                        "Your " . arRoleLabel($requestedRole)
                        . " account was approved by " . $currentName
                        . ". Sign in with your email and chosen password to start filing job requests.";
                    $welcomeType = "account_request";
                    $welcomeUrl =
                        rtrim(BASE_URL, "/") . "/index.php";

                    $welcomeStmt = $conn->prepare(
                        "INSERT INTO notifications
                            (user_id, request_id, title, message,
                             notification_type, action_url, is_read)
                         VALUES (?, NULL, ?, ?, ?, ?, 0)"
                    );
                    $welcomeStmt->bind_param(
                        "issss",
                        $newUserId,
                        $welcomeTitle,
                        $welcomeMessage,
                        $welcomeType,
                        $welcomeUrl
                    );
                    $welcomeStmt->execute();
                    $welcomeStmt->close();
                }
            } catch (Throwable $notifyError) {
                error_log(
                    "Account approval notification failed: "
                    . $notifyError->getMessage()
                );
            }

            $conn->commit();

            arFlash(
                "success",
                "Account approved. " . $request["name"]
                . " can now sign in as " . arRoleLabel($requestedRole) . "."
            );
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            error_log("Account approval failed: " . $e->getMessage());
            arFlash("danger", "Unable to approve the request. Please try again.");
        }
    }

    /* --------------------------------------------------
       DECLINE: keep the record for audit, with a reason.
    -------------------------------------------------- */
    if ($action === "decline") {
        $reviewNotes = trim((string) ($_POST["review_notes"] ?? ""));

        if (mb_strlen($reviewNotes) < 3) {
            arFlash(
                "danger",
                "Please give a short reason so the requestor knows what to fix."
            );
        }

        $rowStmt = $conn->prepare(
            "SELECT id, name, requested_role, status
              FROM account_requests
              WHERE id = ?
              LIMIT 1"
        );
        $rowStmt->bind_param("i", $requestId);
        $rowStmt->execute();
        $request = $rowStmt->get_result()->fetch_assoc();
        $rowStmt->close();

        if (!$request) {
            arFlash("danger", "Account request not found.");
        }

        if (($request["status"] ?? "") !== "Pending") {
            arFlash(
                "warning",
                "That request was already decided by another reviewer."
            );
        }

        if (!arCanDecide($currentRole, (string) $request["requested_role"])) {
            arFlash(
                "danger",
                "Your role cannot decline that access level. An administrator must decide it."
            );
        }

        $declined = "Declined";

        $declineStmt = $conn->prepare(
            "UPDATE account_requests
              SET status = ?,
                  reviewed_by = ?,
                  reviewed_by_name = ?,
                  review_notes = ?,
                  reviewed_at = NOW()
              WHERE id = ?"
        );
        $declineStmt->bind_param(
            "sissi",
            $declined,
            $currentUserId,
            $currentName,
            $reviewNotes,
            $requestId
        );
        $declineStmt->execute();
        $declineStmt->close();

        arFlash(
            "success",
            "Request from " . $request["name"] . " was declined."
        );
    }

    /* --------------------------------------------------
       DELETE: administrators can clean up decided/spam rows.
    -------------------------------------------------- */
    if ($action === "delete") {
        if ($currentRole !== "admin") {
            arFlash("danger", "Only administrators can delete requests.");
        }

        $delStmt = $conn->prepare(
            "DELETE FROM account_requests WHERE id = ?"
        );
        $delStmt->bind_param("i", $requestId);
        $delStmt->execute();
        $delStmt->close();

        arFlash("success", "Account request record deleted.");
    }

    /* --------------------------------------------------
       HELP RESOLVE: password reset / reactivate / note.
    -------------------------------------------------- */
    if ($action === "help_resolve") {
        $reviewNotes = trim((string) ($_POST["review_notes"] ?? ""));
        $tempPassword = (string) ($_POST["temp_password"] ?? "");

        if (mb_strlen($reviewNotes) < 3) {
            ahFlash(
                "danger",
                "Please record a short note (e.g. how the temporary password was relayed)."
            );
        }

        try {
            $conn->begin_transaction();

            $lockStmt = $conn->prepare(
                "SELECT id, email, issue_type, status
                  FROM account_help_requests
                  WHERE id = ?
                  FOR UPDATE"
            );
            $lockStmt->bind_param("i", $requestId);
            $lockStmt->execute();
            $ticket = $lockStmt->get_result()->fetch_assoc();
            $lockStmt->close();

            if (!$ticket) {
                $conn->rollback();
                ahFlash("danger", "Help ticket not found.");
            }

            if (($ticket["status"] ?? "") !== "Pending") {
                $conn->rollback();
                ahFlash(
                    "warning",
                    "That ticket was already handled by another reviewer."
                );
            }

            $issueType = (string) ($ticket["issue_type"] ?? "other");
            $ticketEmail = strtolower(trim((string) ($ticket["email"] ?? "")));

            $userStmt = $conn->prepare(
                "SELECT id, status FROM users
                  WHERE LOWER(email) = ?
                  LIMIT 1
                  FOR UPDATE"
            );
            $userStmt->bind_param("s", $ticketEmail);
            $userStmt->execute();
            $targetUser = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();

            if ($issueType === "forgot_password") {
                if (!ahCanResetPassword($currentRole)) {
                    $conn->rollback();
                    ahFlash(
                        "danger",
                        "Temporary passwords may only be issued by a supervisor or administrator."
                    );
                }

                if (strlen($tempPassword) < 8) {
                    $conn->rollback();
                    ahFlash(
                        "danger",
                        "Temporary password must contain at least 8 characters."
                    );
                }

                if (!$targetUser) {
                    $conn->rollback();
                    ahFlash(
                        "danger",
                        "The account for that email no longer exists. Decline the ticket instead."
                    );
                }

                $tempHash = password_hash($tempPassword, PASSWORD_DEFAULT);

                $pwStmt = $conn->prepare(
                    "UPDATE users SET password = ? WHERE id = ?"
                );
                $pwStmt->bind_param(
                    "si",
                    $tempHash,
                    $targetUser["id"]
                );
                $pwStmt->execute();
                $pwStmt->close();
            }

            if ($issueType === "inactive" && $targetUser) {
                $activeStmt = $conn->prepare(
                    "UPDATE users SET status = 'Active' WHERE id = ?"
                );
                $activeStmt->bind_param("i", $targetUser["id"]);
                $activeStmt->execute();
                $activeStmt->close();
            }

            $resolved = "Resolved";

            $updateStmt = $conn->prepare(
                "UPDATE account_help_requests
                  SET status = ?,
                      reviewed_by = ?,
                      reviewed_by_name = ?,
                      review_notes = ?,
                      reviewed_at = NOW()
                  WHERE id = ?"
            );
            $updateStmt->bind_param(
                "sissi",
                $resolved,
                $currentUserId,
                $currentName,
                $reviewNotes,
                $requestId
            );
            $updateStmt->execute();
            $updateStmt->close();

            /* Tell the account owner the ticket was resolved. */
            try {
                if ($targetUser && arTableExists($conn, "notifications")) {
                    $doneTitle = "Account Help Resolved";
                    $doneMessage =
                        "Your " . ahIssueLabel($issueType)
                        . " request was resolved by " . $currentName
                        . ". " . $reviewNotes;
                    $doneType = "account_help";
                    $doneUrl = rtrim(BASE_URL, "/") . "/index.php";

                    $doneStmt = $conn->prepare(
                        "INSERT INTO notifications
                            (user_id, request_id, title, message,
                             notification_type, action_url, is_read)
                         VALUES (?, NULL, ?, ?, ?, ?, 0)"
                    );
                    $doneStmt->bind_param(
                        "issss",
                        $targetUser["id"],
                        $doneTitle,
                        $doneMessage,
                        $doneType,
                        $doneUrl
                    );
                    $doneStmt->execute();
                    $doneStmt->close();
                }
            } catch (Throwable $notifyError) {
                error_log(
                    "Help resolution notification failed: "
                    . $notifyError->getMessage()
                );
            }

            $conn->commit();

            ahFlash("success", "Help ticket marked as resolved.");
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            error_log("Help ticket resolution failed: " . $e->getMessage());
            ahFlash("danger", "Unable to resolve the ticket. Please try again.");
        }
    }

    /* --------------------------------------------------
       HELP DECLINE: invalid or spam tickets.
    -------------------------------------------------- */
    if ($action === "help_decline") {
        $reviewNotes = trim((string) ($_POST["review_notes"] ?? ""));

        if (mb_strlen($reviewNotes) < 3) {
            ahFlash(
                "danger",
                "Please give a short reason for declining the ticket."
            );
        }

        $rowStmt = $conn->prepare(
            "SELECT id, email, status
              FROM account_help_requests
              WHERE id = ?
              LIMIT 1"
        );
        $rowStmt->bind_param("i", $requestId);
        $rowStmt->execute();
        $ticket = $rowStmt->get_result()->fetch_assoc();
        $rowStmt->close();

        if (!$ticket) {
            ahFlash("danger", "Help ticket not found.");
        }

        if (($ticket["status"] ?? "") !== "Pending") {
            ahFlash(
                "warning",
                "That ticket was already handled by another reviewer."
            );
        }

        $declined = "Declined";

        $declineStmt = $conn->prepare(
            "UPDATE account_help_requests
              SET status = ?,
                  reviewed_by = ?,
                  reviewed_by_name = ?,
                  review_notes = ?,
                  reviewed_at = NOW()
              WHERE id = ?"
        );
        $declineStmt->bind_param(
            "sissi",
            $declined,
            $currentUserId,
            $currentName,
            $reviewNotes,
            $requestId
        );
        $declineStmt->execute();
        $declineStmt->close();

        ahFlash(
            "success",
            "Help ticket from " . $ticket["email"] . " was declined."
        );
    }

    /* --------------------------------------------------
       HELP DELETE: administrators can clean up tickets.
    -------------------------------------------------- */
    if ($action === "help_delete") {
        if ($currentRole !== "admin") {
            ahFlash("danger", "Only administrators can delete tickets.");
        }

        $delStmt = $conn->prepare(
            "DELETE FROM account_help_requests WHERE id = ?"
        );
        $delStmt->bind_param("i", $requestId);
        $delStmt->execute();
        $delStmt->close();

        ahFlash("success", "Help ticket deleted.");
    }

    arFlash("danger", "Unknown action.");
}

/*
|--------------------------------------------------------------------------
| Filters + Lists
|--------------------------------------------------------------------------
*/

$statusFilter = trim((string) ($_GET["status"] ?? "Pending"));
$search = trim((string) ($_GET["q"] ?? ""));
$tab = trim((string) ($_GET["tab"] ?? "requests"));

if ($tab !== "help") {
    $tab = "requests";
}

if (
    !in_array(
        $statusFilter,
        ["", "Pending", "Approved", "Declined", "Resolved"],
        true
    )
) {
    $statusFilter = "Pending";
}

if ($tab === "help" && $statusFilter === "Approved") {
    $statusFilter = "Resolved";
}

if ($tab === "requests" && $statusFilter === "Resolved") {
    $statusFilter = "Approved";
}

$pendingCount = arScalar(
    $conn,
    "SELECT COUNT(*) FROM account_requests WHERE status = 'Pending'"
);
$approvedCount = arScalar(
    $conn,
    "SELECT COUNT(*) FROM account_requests WHERE status = 'Approved'"
);
$declinedCount = arScalar(
    $conn,
    "SELECT COUNT(*) FROM account_requests WHERE status = 'Declined'"
);
$totalCount = arScalar($conn, "SELECT COUNT(*) FROM account_requests");

$sql = "SELECT * FROM account_requests WHERE 1 = 1";
$types = "";
$params = [];

if ($statusFilter !== "") {
    $sql .= " AND status = ?";
    $types .= "s";
    $params[] = $statusFilter;
}

if ($search !== "") {
    $sql .= " AND (name LIKE ? OR email LIKE ? OR department LIKE ?)";
    $like = "%" . $search . "%";
    $types .= "sss";
    array_push($params, $like, $like, $like);
}

$sql .= " ORDER BY
    FIELD(status, 'Pending', 'Approved', 'Declined'),
    created_at DESC, id DESC";

$stmt = $conn->prepare($sql);

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/*
|--------------------------------------------------------------------------
| Help Ticket List
|--------------------------------------------------------------------------
*/

$helpPendingCount = arScalar(
    $conn,
    "SELECT COUNT(*) FROM account_help_requests WHERE status = 'Pending'"
);
$helpResolvedCount = arScalar(
    $conn,
    "SELECT COUNT(*) FROM account_help_requests WHERE status = 'Resolved'"
);
$helpDeclinedCount = arScalar(
    $conn,
    "SELECT COUNT(*) FROM account_help_requests WHERE status = 'Declined'"
);
$helpTotalCount = arScalar($conn, "SELECT COUNT(*) FROM account_help_requests");

$helpSql = "SELECT h.*,
    u.status AS account_status,
    u.role AS account_role
  FROM account_help_requests h
  LEFT JOIN users u ON LOWER(u.email) = LOWER(h.email)
  WHERE 1 = 1";
$helpTypes = "";
$helpParams = [];

if ($statusFilter !== "") {
    $helpSql .= " AND h.status = ?";
    $helpTypes .= "s";
    $helpParams[] = $statusFilter;
}

if ($search !== "") {
    $helpSql .= " AND (h.email LIKE ? OR h.contact LIKE ? OR h.details LIKE ?)";
    $helpLike = "%" . $search . "%";
    $helpTypes .= "sss";
    array_push($helpParams, $helpLike, $helpLike, $helpLike);
}

$helpSql .= " ORDER BY
    FIELD(h.status, 'Pending', 'Resolved', 'Declined'),
    h.created_at DESC, h.id DESC";

$helpStmt = $conn->prepare($helpSql);

if ($helpParams) {
    $helpStmt->bind_param($helpTypes, ...$helpParams);
}

$helpStmt->execute();
$helpTickets = $helpStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$helpStmt->close();

$pendingBadgeCount = $pendingCount + $helpPendingCount;

$flash = $_SESSION["ar_flash"] ?? null;
unset($_SESSION["ar_flash"]);

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Access Control | HPU Workbench
</title>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="<?= e(BASE_URL) ?>/assets/css/style.css"
>

<link
    rel="stylesheet"
    href="<?= e(BASE_URL) ?>/assets/css/page_headers.css"
>

<style>
body{background:#f4f7fb}
.content{padding:18px}
.container-fluid{max-width:1500px;margin:0 auto}
.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin-bottom:12px}
.kpi-card{display:block;padding:11px 12px;border:1px solid #dfe6ef;border-radius:13px;background:#fff;box-shadow:0 8px 24px rgba(28,42,72,.05);text-decoration:none;color:inherit;transition:transform .15s ease,box-shadow .2s ease}
.kpi-card:hover{transform:translateY(-1px);box-shadow:0 12px 28px rgba(28,42,72,.09)}
.kpi-card.current{outline:2px solid #2563eb;outline-offset:-2px}
.kpi-label{font-size:.57rem;color:#667085;font-weight:900;text-transform:uppercase;letter-spacing:.04em}
.kpi-value{margin-top:4px;font-size:1.45rem;line-height:1;font-weight:900}
.kpi-note{margin-top:5px;color:#98a2b3;font-size:.59rem}
.panel{border:1px solid #dfe6ef;border-radius:14px;background:#fff;box-shadow:0 8px 24px rgba(28,42,72,.05);overflow:hidden}
.panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid #edf1f6}
.panel-title{margin:0;font-size:.95rem;font-weight:800;display:flex;align-items:center;gap:8px}
.panel-title i{color:#2563eb}
.filter-bar{padding:10px 12px;border-bottom:1px solid #edf1f6;background:#fbfcfe}
.filter-label{display:block;margin-bottom:3px;color:#667085;font-size:.56rem;font-weight:900;text-transform:uppercase;letter-spacing:.04em}
.table-wrap{overflow:auto}
.req-table{width:100%;border-collapse:collapse}
.req-table th{padding:8px 10px;background:#f8fafc;border-bottom:1px solid #eaecf0;color:#667085;font-size:.55rem;font-weight:900;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.req-table td{padding:10px;border-bottom:1px solid #f0f2f5;vertical-align:top;font-size:.66rem}
.req-table tbody tr:hover{background:#fbfcfe}
.req-identity{display:flex;align-items:center;gap:9px;min-width:230px}
.req-avatar{width:36px;height:36px;display:grid;place-items:center;flex:0 0 36px;border-radius:10px;background:#e7f3ec;color:#00662d;font-size:.63rem;font-weight:900}
.req-name{font-size:.7rem;font-weight:900;color:#1d2939}
.req-email{margin-top:1px;font-size:.6rem;color:#667085;word-break:break-all}
.role-pill,.status-pill{display:inline-flex;align-items:center;gap:4px;padding:4px 8px;border-radius:999px;font-size:.58rem;font-weight:850;white-space:nowrap}
.role-user{background:#f2f4f7;color:#475467}
.role-staff{background:#eff8ff;color:#175cd3}
.role-supervisor{background:#f5f3ff;color:#6d28d9}
.status-pending{background:#fef3c7;color:#92400e}
.status-approved{background:#ecfdf3;color:#027a48}
.status-declined{background:#fee2e2;color:#991b1b}
.status-default{background:#f2f4f7;color:#475467}
.reason-box{max-width:260px;color:#475467;font-size:.62rem;line-height:1.5;white-space:pre-wrap}
.notes-box{max-width:230px;color:#667085;font-size:.6rem;line-height:1.5}
.actions{display:flex;justify-content:flex-end;gap:5px;flex-wrap:wrap;min-width:170px}
.actions .btn{font-size:.62rem;font-weight:800}
.perm-note{font-size:.58rem;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:5px 8px}
.empty-state{padding:38px 12px;text-align:center;color:#667085;font-size:.68rem}
.modal-content{border-radius:15px}
.required{color:#d92d20}
.summary-box{background:#f8fafc;border:1px solid #eaecf0;border-radius:10px;padding:10px 12px;font-size:.68rem}
@media(max-width:1000px){.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.content{padding:12px}}
</style>

</head>

<body class="app-shell">

<div class="d-flex min-vh-100">

<?php include "../includes/sidebar.php"; ?>

<div class="main flex-grow-1">

<main class="content">

<div class="container-fluid">

<div class="ph">

    <div class="ph-copy">
        <div class="ph-icon" aria-hidden="true">
            <i class="bi bi-person-plus-fill"></i>
        </div>

        <div class="min-w-0">
            <p class="ph-eyebrow">Access Control</p>
            <h1 class="ph-title">Account Requests &amp; Help</h1>
            <p class="ph-subtitle">
                Review public account requests and tracked account-help
                tickets. Approvals create the sign-in account instantly.
                Your authority:
                <strong>
                    <?= $currentRole === "admin"
                        ? "all access levels"
                        : ($currentRole === "supervisor"
                            ? "Requestor + HPU Staff (password resets included)"
                            : "Requestor approvals + help tickets (password resets need a supervisor)") ?>
                </strong>
            </p>
        </div>
    </div>

    <div class="ph-actions">
        <a
            class="btn btn-sm btn-outline-primary"
            href="<?= e(BASE_URL) ?>/admin/users.php"
        >
            <i class="bi bi-people me-1"></i>
            Users
        </a>
    </div>

</div>

<?php if ($flash): ?>

<div
    class="alert alert-<?= e($flash["type"]) ?> alert-dismissible fade show"
>
    <?= e($flash["message"]) ?>

    <button
        class="btn-close"
        type="button"
        data-bs-dismiss="alert"
    ></button>
</div>

<?php endif; ?>

<ul class="nav nav-pills mb-3" style="font-size:.72rem;font-weight:800;">
    <li class="nav-item">
        <a
            class="nav-link <?= $tab === "requests" ? "active" : "" ?>"
            href="account_requests.php"
        >
            <i class="bi bi-person-plus me-1"></i>
            Requests

            <?php if ($pendingCount > 0): ?>
                <span class="badge <?= $tab === "requests" ? "text-bg-light" : "text-bg-danger" ?> ms-1">
                    <?= $pendingCount ?>
                </span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a
            class="nav-link <?= $tab === "help" ? "active" : "" ?>"
            href="account_requests.php?tab=help"
        >
            <i class="bi bi-question-circle me-1"></i>
            Help Tickets

            <?php if ($helpPendingCount > 0): ?>
                <span class="badge <?= $tab === "help" ? "text-bg-light" : "text-bg-danger" ?> ms-1">
                    <?= $helpPendingCount ?>
                </span>
            <?php endif; ?>
        </a>
    </li>
</ul>

<?php if ($tab === "requests"): ?>

<div class="kpi-grid">

    <a class="kpi-card <?= $statusFilter === "Pending" ? "current" : "" ?>" href="account_requests.php?status=Pending">
        <div class="kpi-label">Pending Review</div>
        <div class="kpi-value text-warning"><?= $pendingCount ?></div>
        <div class="kpi-note">Waiting for HPU decision</div>
    </a>

    <a class="kpi-card <?= $statusFilter === "Approved" ? "current" : "" ?>" href="account_requests.php?status=Approved">
        <div class="kpi-label">Approved</div>
        <div class="kpi-value text-success"><?= $approvedCount ?></div>
        <div class="kpi-note">Accounts created</div>
    </a>

    <a class="kpi-card <?= $statusFilter === "Declined" ? "current" : "" ?>" href="account_requests.php?status=Declined">
        <div class="kpi-label">Declined</div>
        <div class="kpi-value text-danger"><?= $declinedCount ?></div>
        <div class="kpi-note">With reviewer reason</div>
    </a>

    <a class="kpi-card <?= $statusFilter === "" ? "current" : "" ?>" href="account_requests.php?status=">
        <div class="kpi-label">All Requests</div>
        <div class="kpi-value"><?= $totalCount ?></div>
        <div class="kpi-note">Full audit trail</div>
    </a>

</div>

<section class="panel">

<div class="panel-head">
    <div class="panel-title">
        <i class="bi bi-inbox"></i>
        <?= $statusFilter === "" ? "All Requests" : e($statusFilter) . " Requests" ?>
    </div>

    <div class="small text-muted">
        <?= count($requests) ?> result(s)
    </div>
</div>

<form
    class="filter-bar"
    method="get"
>

<div class="row g-2 align-items-end">

    <div class="col-lg-5">
        <label class="filter-label">Search</label>
        <input
            class="form-control form-control-sm"
            name="q"
            value="<?= e($search) ?>"
            placeholder="Name, email or department..."
        >
    </div>

    <div class="col-lg-3">
        <label class="filter-label">Status</label>
        <select
            class="form-select form-select-sm"
            name="status"
        >
            <option value="Pending" <?= $statusFilter === "Pending" ? "selected" : "" ?>>Pending</option>
            <option value="Approved" <?= $statusFilter === "Approved" ? "selected" : "" ?>>Approved</option>
            <option value="Declined" <?= $statusFilter === "Declined" ? "selected" : "" ?>>Declined</option>
            <option value="" <?= $statusFilter === "" ? "selected" : "" ?>>All</option>
        </select>
    </div>

    <div class="col-lg-auto d-flex gap-2">
        <button
            class="btn btn-sm btn-primary"
            type="submit"
        >
            <i class="bi bi-funnel me-1"></i>
            Apply
        </button>

        <a
            class="btn btn-sm btn-light"
            href="account_requests.php"
        >
            Reset
        </a>
    </div>

</div>

</form>

<div class="table-wrap">

<?php if (!$requests): ?>

<div class="empty-state">
    <i class="bi bi-inbox d-block fs-4 mb-2"></i>
    No account requests found. New public requests from the
    login page will appear here.
</div>

<?php else: ?>

<table class="req-table">

<thead>
<tr>
    <th>Requestor</th>
    <th>Office / Position</th>
    <th>Requested Access</th>
    <th>Justification</th>
    <th>Submitted</th>
    <th>Decision</th>
    <th class="text-end">Actions</th>
</tr>
</thead>

<tbody>

<?php foreach ($requests as $row): ?>

<?php
$rid = (int) $row["id"];
$reqRole = (string) $row["requested_role"];
$reqStatus = (string) $row["status"];
$isPending = $reqStatus === "Pending";
$canDecide = $isPending && arCanDecide($currentRole, $reqRole);
$needsAdmin = $isPending && !$canDecide;

$initials = "";
$parts = preg_split('/\s+/', trim((string) $row["name"])) ?: [];

foreach (array_slice($parts, 0, 2) as $part) {
    if ($part !== "") {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
}

if ($initials === "") {
    $initials = "U";
}
?>

<tr>

    <td>
        <div class="req-identity">
            <div class="req-avatar"><?= e($initials) ?></div>
            <div>
                <div class="req-name"><?= e($row["name"]) ?></div>
                <div class="req-email">
                    <?= e($row["email"]) ?>
                    <?php if (!empty($row["contact"])): ?>
                        <br><?= e($row["contact"]) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </td>

    <td>
        <div class="fw-bold"><?= e($row["department"]) ?></div>
        <div class="text-muted"><?= e($row["position"] ?: "—") ?></div>
    </td>

    <td>
        <span class="role-pill <?= e(arRoleClass($reqRole)) ?>">
            <?= e(arRoleLabel($reqRole)) ?>
        </span>
    </td>

    <td>
        <div class="reason-box"><?= e($row["reason"] ?: "—") ?></div>
    </td>

    <td class="text-nowrap">
        <?= e(date("M d, Y", strtotime((string) $row["created_at"]))) ?>
        <div class="text-muted">
            <?= e(date("g:i A", strtotime((string) $row["created_at"]))) ?>
        </div>
    </td>

    <td>
        <span class="status-pill <?= e(arStatusClass($reqStatus)) ?>">
            <?= e($reqStatus) ?>
        </span>

        <?php if (!$isPending): ?>
            <div class="notes-box mt-1">
                by <?= e($row["reviewed_by_name"] ?: "—") ?>
                <?php if (!empty($row["reviewed_at"])): ?>
                    <br><?= e(date("M d, Y g:i A", strtotime((string) $row["reviewed_at"]))) ?>
                <?php endif; ?>
                <?php if (!empty($row["review_notes"])): ?>
                    <br><em>"<?= e($row["review_notes"]) ?>"</em>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </td>

    <td>
        <div class="actions">

            <?php if ($canDecide): ?>

            <button
                class="btn btn-sm btn-success approve-btn"
                type="button"
                data-bs-toggle="modal"
                data-bs-target="#approveModal"
                data-id="<?= $rid ?>"
                data-name="<?= e($row["name"]) ?>"
                data-email="<?= e($row["email"]) ?>"
                data-role="<?= e(arRoleLabel($reqRole)) ?>"
            >
                <i class="bi bi-check-circle"></i>
                Approve
            </button>

            <button
                class="btn btn-sm btn-outline-danger decline-btn"
                type="button"
                data-bs-toggle="modal"
                data-bs-target="#declineModal"
                data-id="<?= $rid ?>"
                data-name="<?= e($row["name"]) ?>"
            >
                <i class="bi bi-x-circle"></i>
                Decline
            </button>

            <?php elseif ($needsAdmin): ?>

            <span class="perm-note">
                <i class="bi bi-shield-lock me-1"></i>
                Requires admin
            </span>

            <?php endif; ?>

            <?php if ($currentRole === "admin" && !$isPending): ?>

            <form method="post" class="d-inline">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="request_id" value="<?= $rid ?>">

                <button
                    class="btn btn-sm btn-outline-secondary"
                    type="submit"
                    onclick="return confirm('Delete this record permanently?')"
                >
                    <i class="bi bi-trash"></i>
                </button>
            </form>

            <?php endif; ?>

        </div>
    </td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

<?php endif; ?>

</div>

</section>

<div class="alert alert-light border mt-3" style="font-size:.68rem;color:#667085;">
    <i class="bi bi-info-circle me-1"></i>
    Approving instantly creates the sign-in account with the password the
    visitor chose. Staff may approve <strong>Requestor</strong> access only;
    supervisors may also approve <strong>HPU Staff</strong>;
    <strong>Supervisor</strong> access needs an administrator.
</div>

<?php else: ?>

<div class="kpi-grid">

    <a class="kpi-card <?= $statusFilter === "Pending" ? "current" : "" ?>" href="account_requests.php?tab=help&amp;status=Pending">
        <div class="kpi-label">Open Tickets</div>
        <div class="kpi-value text-warning"><?= $helpPendingCount ?></div>
        <div class="kpi-note">Waiting for HPU assistance</div>
    </a>

    <a class="kpi-card <?= $statusFilter === "Resolved" ? "current" : "" ?>" href="account_requests.php?tab=help&amp;status=Resolved">
        <div class="kpi-label">Resolved</div>
        <div class="kpi-value text-success"><?= $helpResolvedCount ?></div>
        <div class="kpi-note">Assistance completed</div>
    </a>

    <a class="kpi-card <?= $statusFilter === "Declined" ? "current" : "" ?>" href="account_requests.php?tab=help&amp;status=Declined">
        <div class="kpi-label">Declined</div>
        <div class="kpi-value text-danger"><?= $helpDeclinedCount ?></div>
        <div class="kpi-note">Invalid or spam tickets</div>
    </a>

    <a class="kpi-card <?= $statusFilter === "" ? "current" : "" ?>" href="account_requests.php?tab=help&amp;status=">
        <div class="kpi-label">All Tickets</div>
        <div class="kpi-value"><?= $helpTotalCount ?></div>
        <div class="kpi-note">Full support audit trail</div>
    </a>

</div>

<section class="panel">

<div class="panel-head">
    <div class="panel-title">
        <i class="bi bi-question-circle"></i>
        <?= $statusFilter === "" ? "All Help Tickets" : e($statusFilter) . " Tickets" ?>
    </div>

    <div class="small text-muted">
        <?= count($helpTickets) ?> result(s)
    </div>
</div>

<form
    class="filter-bar"
    method="get"
>

<input type="hidden" name="tab" value="help">

<div class="row g-2 align-items-end">

    <div class="col-lg-5">
        <label class="filter-label">Search</label>
        <input
            class="form-control form-control-sm"
            name="q"
            value="<?= e($search) ?>"
            placeholder="Email, contact or details..."
        >
    </div>

    <div class="col-lg-3">
        <label class="filter-label">Status</label>
        <select
            class="form-select form-select-sm"
            name="status"
        >
            <option value="Pending" <?= $statusFilter === "Pending" ? "selected" : "" ?>>Pending</option>
            <option value="Resolved" <?= $statusFilter === "Resolved" ? "selected" : "" ?>>Resolved</option>
            <option value="Declined" <?= $statusFilter === "Declined" ? "selected" : "" ?>>Declined</option>
            <option value="" <?= $statusFilter === "" ? "selected" : "" ?>>All</option>
        </select>
    </div>

    <div class="col-lg-auto d-flex gap-2">
        <button
            class="btn btn-sm btn-primary"
            type="submit"
        >
            <i class="bi bi-funnel me-1"></i>
            Apply
        </button>

        <a
            class="btn btn-sm btn-light"
            href="account_requests.php?tab=help"
        >
            Reset
        </a>
    </div>

</div>

</form>

<div class="table-wrap">

<?php if (!$helpTickets): ?>

<div class="empty-state">
    <i class="bi bi-question-circle d-block fs-4 mb-2"></i>
    No help tickets found. New "Need account help?" submissions
    from the login page will appear here.
</div>

<?php else: ?>

<table class="req-table">

<thead>
<tr>
    <th>Account</th>
    <th>Issue</th>
    <th>Details</th>
    <th>Submitted</th>
    <th>Decision</th>
    <th class="text-end">Actions</th>
</tr>
</thead>

<tbody>

<?php foreach ($helpTickets as $ticket): ?>

<?php
$hid = (int) $ticket["id"];
$hType = (string) $ticket["issue_type"];
$hStatus = (string) $ticket["status"];
$hPending = $hStatus === "Pending";
$hNeedsReset = $hType === "forgot_password";
$hCanReset = $hPending && (!$hNeedsReset || ahCanResetPassword($currentRole));
$hResetBlocked = $hPending && $hNeedsReset && !ahCanResetPassword($currentRole);
?>

<tr>

    <td>
        <div class="req-identity">
            <div class="req-avatar">
                <i class="bi bi-question-circle"></i>
            </div>
            <div>
                <div class="req-name"><?= e($ticket["email"]) ?></div>
                <div class="req-email">
                    <?php if (!empty($ticket["contact"])): ?>
                        <?= e($ticket["contact"]) ?><br>
                    <?php endif; ?>
                    Account:
                    <?php if (!empty($ticket["account_role"])): ?>
                        <?= e(ucfirst((string) $ticket["account_role"])) ?>
                        · <?= e((string) ($ticket["account_status"] ?? "")) ?>
                    <?php else: ?>
                        not found
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </td>

    <td>
        <span class="role-pill <?= e(ahIssueClass($hType)) ?>">
            <?= e(ahIssueLabel($hType)) ?>
        </span>
    </td>

    <td>
        <div class="reason-box"><?= e($ticket["details"] ?: "—") ?></div>
    </td>

    <td class="text-nowrap">
        <?= e(date("M d, Y", strtotime((string) $ticket["created_at"]))) ?>
        <div class="text-muted">
            <?= e(date("g:i A", strtotime((string) $ticket["created_at"]))) ?>
        </div>
    </td>

    <td>
        <span class="status-pill <?= e(arStatusClass($hStatus === "Resolved" ? "Approved" : $hStatus)) ?>">
            <?= e($hStatus) ?>
        </span>

        <?php if (!$hPending): ?>
            <div class="notes-box mt-1">
                by <?= e($ticket["reviewed_by_name"] ?: "—") ?>
                <?php if (!empty($ticket["reviewed_at"])): ?>
                    <br><?= e(date("M d, Y g:i A", strtotime((string) $ticket["reviewed_at"]))) ?>
                <?php endif; ?>
                <?php if (!empty($ticket["review_notes"])): ?>
                    <br><em>"<?= e($ticket["review_notes"]) ?>"</em>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </td>

    <td>
        <div class="actions">

            <?php if ($hCanReset): ?>

            <button
                class="btn btn-sm btn-success help-resolve-btn"
                type="button"
                data-bs-toggle="modal"
                data-bs-target="#helpResolveModal"
                data-id="<?= $hid ?>"
                data-email="<?= e($ticket["email"]) ?>"
                data-type="<?= e(ahIssueLabel($hType)) ?>"
                data-kind="<?= e($hType) ?>"
            >
                <i class="bi bi-check-circle"></i>
                Resolve
            </button>

            <button
                class="btn btn-sm btn-outline-danger help-decline-btn"
                type="button"
                data-bs-toggle="modal"
                data-bs-target="#helpDeclineModal"
                data-id="<?= $hid ?>"
                data-email="<?= e($ticket["email"]) ?>"
            >
                <i class="bi bi-x-circle"></i>
                Decline
            </button>

            <?php elseif ($hResetBlocked): ?>

            <span class="perm-note">
                <i class="bi bi-shield-lock me-1"></i>
                Password reset needs a supervisor
            </span>

            <button
                class="btn btn-sm btn-outline-danger help-decline-btn"
                type="button"
                data-bs-toggle="modal"
                data-bs-target="#helpDeclineModal"
                data-id="<?= $hid ?>"
                data-email="<?= e($ticket["email"]) ?>"
            >
                <i class="bi bi-x-circle"></i>
                Decline
            </button>

            <?php endif; ?>

            <?php if ($currentRole === "admin" && !$hPending): ?>

            <form method="post" class="d-inline">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >
                <input type="hidden" name="action" value="help_delete">
                <input type="hidden" name="request_id" value="<?= $hid ?>">

                <button
                    class="btn btn-sm btn-outline-secondary"
                    type="submit"
                    onclick="return confirm('Delete this ticket permanently?')"
                >
                    <i class="bi bi-trash"></i>
                </button>
            </form>

            <?php endif; ?>

        </div>
    </td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

<?php endif; ?>

</div>

</section>

<div class="alert alert-light border mt-3" style="font-size:.68rem;color:#667085;">
    <i class="bi bi-info-circle me-1"></i>
    Resolving a <strong>Password Reset</strong> sets a new temporary password
    (supervisor or admin only) — relay it to the owner in person or by phone
    and record how in the note. <strong>Reactivation</strong> re-enables the
    account instantly. For <strong>corrections</strong>, update the account on
    the Users page first, then resolve with a note.
</div>

<?php endif; ?>

</div>

</main>

</div>

</div>

<!-- APPROVE MODAL -->
<div
    class="modal fade"
    id="approveModal"
    tabindex="-1"
    aria-hidden="true"
>
    <div class="modal-dialog">
        <form
            class="modal-content"
            method="post"
        >
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-check-circle text-success me-1"></i>
                    Approve Account
                </h5>
                <button
                    class="btn-close"
                    type="button"
                    data-bs-dismiss="modal"
                ></button>
            </div>

            <div class="modal-body">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="request_id" id="approveId" value="">

                <div class="summary-box mb-3" id="approveSummary"></div>

                <label
                    class="form-label fw-bold"
                    for="approveNotes"
                >
                    Welcome note <span class="text-muted">(optional)</span>
                </label>
                <textarea
                    class="form-control"
                    id="approveNotes"
                    name="review_notes"
                    rows="2"
                    maxlength="500"
                    placeholder="e.g. Welcome aboard! Please complete your profile after signing in."
                ></textarea>
            </div>

            <div class="modal-footer">
                <button
                    class="btn btn-light"
                    type="button"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>
                <button
                    class="btn btn-success"
                    type="submit"
                >
                    <i class="bi bi-check-circle me-1"></i>
                    Create Account
                </button>
            </div>
        </form>
    </div>
</div>

<!-- DECLINE MODAL -->
<div
    class="modal fade"
    id="declineModal"
    tabindex="-1"
    aria-hidden="true"
>
    <div class="modal-dialog">
        <form
            class="modal-content"
            method="post"
        >
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-x-circle text-danger me-1"></i>
                    Decline Request
                </h5>
                <button
                    class="btn-close"
                    type="button"
                    data-bs-dismiss="modal"
                ></button>
            </div>

            <div class="modal-body">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >
                <input type="hidden" name="action" value="decline">
                <input type="hidden" name="request_id" id="declineId" value="">

                <p class="mb-2">
                    Decline the request from
                    <strong id="declineName"></strong>?
                </p>

                <label
                    class="form-label fw-bold"
                    for="declineNotes"
                >
                    Reason <span class="required">*</span>
                </label>
                <textarea
                    class="form-control"
                    id="declineNotes"
                    name="review_notes"
                    rows="3"
                    maxlength="1000"
                    required
                    placeholder="e.g. Please resubmit using your official hospital email."
                ></textarea>
            </div>

            <div class="modal-footer">
                <button
                    class="btn btn-light"
                    type="button"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>
                <button
                    class="btn btn-danger"
                    type="submit"
                >
                    <i class="bi bi-x-circle me-1"></i>
                    Decline
                </button>
            </div>
        </form>
    </div>
</div>

<!-- HELP RESOLVE MODAL -->
<div
    class="modal fade"
    id="helpResolveModal"
    tabindex="-1"
    aria-hidden="true"
>
    <div class="modal-dialog">
        <form
            class="modal-content"
            method="post"
        >
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-check-circle text-success me-1"></i>
                    Resolve Help Ticket
                </h5>
                <button
                    class="btn-close"
                    type="button"
                    data-bs-dismiss="modal"
                ></button>
            </div>

            <div class="modal-body">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >
                <input type="hidden" name="action" value="help_resolve">
                <input type="hidden" name="request_id" id="helpResolveId" value="">

                <div class="summary-box mb-3" id="helpResolveSummary"></div>

                <div id="tempPasswordWrap" style="display:none;">
                    <label
                        class="form-label fw-bold"
                        for="tempPassword"
                    >
                        Temporary password <span class="required">*</span>
                    </label>
                    <input
                        class="form-control"
                        type="text"
                        id="tempPassword"
                        name="temp_password"
                        minlength="8"
                        maxlength="72"
                        autocomplete="off"
                        placeholder="Min. 8 characters — relay in person or by phone"
                    >
                    <div class="form-text">
                        Replaces the owner's password immediately.
                    </div>
                </div>

                <label
                    class="form-label fw-bold mt-2"
                    for="helpResolveNotes"
                >
                    Resolution note <span class="required">*</span>
                </label>
                <textarea
                    class="form-control"
                    id="helpResolveNotes"
                    name="review_notes"
                    rows="2"
                    maxlength="1000"
                    required
                    placeholder="e.g. Temp password handed over in person at HPU office."
                ></textarea>
            </div>

            <div class="modal-footer">
                <button
                    class="btn btn-light"
                    type="button"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>
                <button
                    class="btn btn-success"
                    type="submit"
                >
                    <i class="bi bi-check-circle me-1"></i>
                    Mark Resolved
                </button>
            </div>
        </form>
    </div>
</div>

<!-- HELP DECLINE MODAL -->
<div
    class="modal fade"
    id="helpDeclineModal"
    tabindex="-1"
    aria-hidden="true"
>
    <div class="modal-dialog">
        <form
            class="modal-content"
            method="post"
        >
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-x-circle text-danger me-1"></i>
                    Decline Help Ticket
                </h5>
                <button
                    class="btn-close"
                    type="button"
                    data-bs-dismiss="modal"
                ></button>
            </div>

            <div class="modal-body">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >
                <input type="hidden" name="action" value="help_decline">
                <input type="hidden" name="request_id" id="helpDeclineId" value="">

                <p class="mb-2">
                    Decline the help ticket from
                    <strong id="helpDeclineEmail"></strong>?
                </p>

                <label
                    class="form-label fw-bold"
                    for="helpDeclineNotes"
                >
                    Reason <span class="required">*</span>
                </label>
                <textarea
                    class="form-control"
                    id="helpDeclineNotes"
                    name="review_notes"
                    rows="3"
                    maxlength="1000"
                    required
                    placeholder="e.g. Duplicate ticket — the earlier one is being processed."
                ></textarea>
            </div>

            <div class="modal-footer">
                <button
                    class="btn btn-light"
                    type="button"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>
                <button
                    class="btn btn-danger"
                    type="submit"
                >
                    <i class="bi bi-x-circle me-1"></i>
                    Decline
                </button>
            </div>
        </form>
    </div>
</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
></script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".approve-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            document.getElementById("approveId").value =
                btn.getAttribute("data-id") || "";

            var box = document.getElementById("approveSummary");
            box.innerHTML = "";

            var strong = document.createElement("strong");
            strong.textContent = btn.getAttribute("data-name") || "";
            box.appendChild(strong);
            box.appendChild(document.createElement("br"));
            box.appendChild(
                document.createTextNode(
                    (btn.getAttribute("data-email") || "")
                    + " · "
                    + (btn.getAttribute("data-role") || "")
                )
            );
        });
    });

    document.querySelectorAll(".decline-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            document.getElementById("declineId").value =
                btn.getAttribute("data-id") || "";

            document.getElementById("declineName").textContent =
                btn.getAttribute("data-name") || "this request";
        });
    });

    document.querySelectorAll(".help-resolve-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            document.getElementById("helpResolveId").value =
                btn.getAttribute("data-id") || "";

            var box = document.getElementById("helpResolveSummary");
            box.innerHTML = "";
            box.appendChild(
                document.createTextNode(
                    (btn.getAttribute("data-email") || "")
                    + " · "
                    + (btn.getAttribute("data-type") || "")
                )
            );

            var needsPw = btn.getAttribute("data-kind") === "forgot_password";
            var wrap = document.getElementById("tempPasswordWrap");
            var pw = document.getElementById("tempPassword");
            if (wrap) wrap.style.display = needsPw ? "" : "none";
            if (pw) {
                pw.required = needsPw;
                if (!needsPw) pw.value = "";
            }
        });
    });

    document.querySelectorAll(".help-decline-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            document.getElementById("helpDeclineId").value =
                btn.getAttribute("data-id") || "";

            document.getElementById("helpDeclineEmail").textContent =
                btn.getAttribute("data-email") || "this ticket";
        });
    });
});
</script>

</body>

</html>
