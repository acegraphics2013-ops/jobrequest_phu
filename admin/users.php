<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - ADMIN USER MANAGEMENT
|--------------------------------------------------------------------------
| Drop-in replacement:
|   C:\laragon\www\jobrequest_phu\admin\users.php
|--------------------------------------------------------------------------
*/

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

requireRole(["admin"]);
requireModule($conn, "users");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$currentAdminId = (int)($_SESSION["user_id"] ?? 0);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function auTableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");

    return $result && $result->num_rows > 0;
}

function auColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?"
    );

    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    return (int)($row[0] ?? 0) > 0;
}

function auScalar(
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

    return (int)($row[0] ?? 0);
}

function auFlash(
    string $type,
    string $message
): never {
    $_SESSION["admin_users_flash"] = [
        "type" => $type,
        "message" => $message
    ];

    header("Location: users.php");
    exit;
}

function auValidateCsrf(): void
{
    $sessionToken =
        (string)($_SESSION["admin_users_csrf"] ?? "");

    $submittedToken =
        (string)($_POST["csrf_token"] ?? "");

    if (
        $sessionToken === ""
        ||
        $submittedToken === ""
        ||
        !hash_equals(
            $sessionToken,
            $submittedToken
        )
    ) {
        auFlash(
            "danger",
            "Security token expired. Please try again."
        );
    }
}

/*
|--------------------------------------------------------------------------
| Admin Audit Log (password resets / account creation)
|--------------------------------------------------------------------------
| Passwords stay one-way hashed and are never written here —
| only who performed the action, on which account, and when.
*/

function auEnsureAuditTable(
    mysqli $conn
): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS admin_audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            actor_id INT NULL,
            actor_name VARCHAR(150) NULL,
            action VARCHAR(50) NOT NULL,
            target_user_id INT NULL,
            target_email VARCHAR(150) NULL,
            target_name VARCHAR(150) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_audit_created (created_at),
            INDEX idx_admin_audit_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function auLogAdminAction(
    mysqli $conn,
    string $action,
    int $targetId,
    string $targetEmail,
    string $targetName
): void {
    try {
        $actorId =
            (int)($_SESSION["user_id"] ?? 0);

        $actorName =
            (string)($_SESSION["name"] ?? "Administrator");

        $stmt =
            $conn->prepare(
                "INSERT INTO admin_audit_log
                 (
                    actor_id,
                    actor_name,
                    action,
                    target_user_id,
                    target_email,
                    target_name,
                    created_at
                 )
                 VALUES (
                    ?, ?, ?, ?, ?, ?, NOW()
                 )"
            );

        $stmt->bind_param(
            "ississ",
            $actorId,
            $actorName,
            $action,
            $targetId,
            $targetEmail,
            $targetName
        );

        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log(
            "Admin audit log failed: "
            . $e->getMessage()
        );
    }
}

function auActionLabel(
    string $action
): string {
    return match ($action) {
        "password_reset" => "Password reset",
        "user_create" => "Account created",
        default => ucfirst(
            str_replace("_", " ", $action)
        )
    };
}

function auLinkedCounts(
    mysqli $conn,
    int $userId
): array {
    $counts = [
        "submitted_requests" => 0,
        "assigned_jobs" => 0,
        "calendar_created" => 0,
        "calendar_assigned" => 0,
        "personal_tasks" => 0,
        "office_submitted" => 0,
        "office_assigned" => 0,
        "client_surveys" => 0,
        "notifications" => 0,
        "iec_uploads" => 0
    ];

    if (auTableExists($conn, "job_requests")) {
        if (
            auColumnExists(
                $conn,
                "job_requests",
                "requestor"
            )
        ) {
            $counts["submitted_requests"] =
                auScalar(
                    $conn,
                    "SELECT COUNT(*)
                     FROM job_requests
                     WHERE requestor = ?",
                    "i",
                    [$userId]
                );
        }

        if (
            auColumnExists(
                $conn,
                "job_requests",
                "assigned_to"
            )
        ) {
            $counts["assigned_jobs"] =
                auScalar(
                    $conn,
                    "SELECT COUNT(*)
                     FROM job_requests
                     WHERE assigned_to = ?",
                    "i",
                    [$userId]
                );
        }
    }

    if (
        auTableExists(
            $conn,
            "hpu_calendar_activities"
        )
    ) {
        if (
            auColumnExists(
                $conn,
                "hpu_calendar_activities",
                "created_by"
            )
        ) {
            $counts["calendar_created"] =
                auScalar(
                    $conn,
                    "SELECT COUNT(*)
                     FROM hpu_calendar_activities
                     WHERE created_by = ?",
                    "i",
                    [$userId]
                );
        }
    }

    if (
        auTableExists(
            $conn,
            "hpu_calendar_assignees"
        )
    ) {
        $counts["calendar_assigned"] =
            auScalar(
                $conn,
                "SELECT COUNT(*)
                 FROM hpu_calendar_assignees
                 WHERE user_id = ?",
                "i",
                [$userId]
            );
    }

    if (
        auTableExists(
            $conn,
            "personal_calendar_tasks"
        )
    ) {
        $counts["personal_tasks"] =
            auScalar(
                $conn,
                "SELECT COUNT(*)
                 FROM personal_calendar_tasks
                 WHERE user_id = ?",
                "i",
                [$userId]
            );
    }

    if (
        auTableExists(
            $conn,
            "office_activity_requests"
        )
    ) {
        if (
            auColumnExists(
                $conn,
                "office_activity_requests",
                "submitted_by"
            )
        ) {
            $counts["office_submitted"] =
                auScalar(
                    $conn,
                    "SELECT COUNT(*)
                     FROM office_activity_requests
                     WHERE submitted_by = ?",
                    "i",
                    [$userId]
                );
        }

        if (
            auColumnExists(
                $conn,
                "office_activity_requests",
                "assigned_to"
            )
        ) {
            $counts["office_assigned"] =
                auScalar(
                    $conn,
                    "SELECT COUNT(*)
                     FROM office_activity_requests
                     WHERE assigned_to = ?",
                    "i",
                    [$userId]
                );
        }
    }

    if (
        auTableExists(
            $conn,
            "job_request_client_experience"
        )
    ) {
        if (
            auColumnExists(
                $conn,
                "job_request_client_experience",
                "user_id"
            )
        ) {
            $counts["client_surveys"] =
                auScalar(
                    $conn,
                    "SELECT COUNT(*)
                     FROM job_request_client_experience
                     WHERE user_id = ?",
                    "i",
                    [$userId]
                );
        }
    }

    if (
        auTableExists(
            $conn,
            "notifications"
        )
        &&
        auColumnExists(
            $conn,
            "notifications",
            "user_id"
        )
    ) {
        $counts["notifications"] =
            auScalar(
                $conn,
                "SELECT COUNT(*)
                 FROM notifications
                 WHERE user_id = ?",
                "i",
                [$userId]
            );
    }

    if (
        auTableExists(
            $conn,
            "iec_materials"
        )
        &&
        auColumnExists(
            $conn,
            "iec_materials",
            "uploaded_by"
        )
    ) {
        $counts["iec_uploads"] =
            auScalar(
                $conn,
                "SELECT COUNT(*)
                 FROM iec_materials
                 WHERE uploaded_by = ?",
                "i",
                [$userId]
            );
    }

    return $counts;
}

function auTotalLinks(array $counts): int
{
    return array_sum(
        array_map(
            "intval",
            $counts
        )
    );
}

function auRoleClass(string $role): string
{
    return match ($role) {
        "admin" => "role-admin",
        "supervisor" => "role-supervisor",
        "staff" => "role-staff",
        default => "role-user"
    };
}

function auInitials(string $name): string
{
    $parts = preg_split(
        '/\s+/',
        trim($name)
    ) ?: [];

    $letters = "";

    foreach (
        array_slice(
            $parts,
            0,
            2
        )
        as $part
    ) {
        if ($part !== "") {
            $letters .=
                mb_strtoupper(
                    mb_substr(
                        $part,
                        0,
                        1
                    )
                );
        }
    }

    return $letters ?: "U";
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    empty(
        $_SESSION["admin_users_csrf"]
    )
) {
    $_SESSION["admin_users_csrf"] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    (string)
    $_SESSION["admin_users_csrf"];

/*
|--------------------------------------------------------------------------
| Feature Detection
|--------------------------------------------------------------------------
*/

$hasAssignedTo =
    auColumnExists(
        $conn,
        "job_requests",
        "assigned_to"
    );

$hasArchived =
    auColumnExists(
        $conn,
        "job_requests",
        "archived"
    );

auEnsureAuditTable($conn);

/*
|--------------------------------------------------------------------------
| POST Actions
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"]
    === "POST"
) {
    auValidateCsrf();

    $action =
        trim(
            (string)(
                $_POST["action"]
                ?? ""
            )
        );

    $allowedRoles = [
        "admin",
        "supervisor",
        "staff",
        "user"
    ];

    /*
    |--------------------------------------------------------------------------
    | ADD
    |--------------------------------------------------------------------------
    */

    if ($action === "add") {
        $name =
            trim(
                (string)(
                    $_POST["name"]
                    ?? ""
                )
            );

        $email =
            strtolower(
                trim(
                    (string)(
                        $_POST["email"]
                        ?? ""
                    )
                )
            );

        $password =
            (string)(
                $_POST["password"]
                ?? ""
            );

        $confirmPassword =
            (string)(
                $_POST["confirm_password"]
                ?? ""
            );

        $role =
            trim(
                (string)(
                    $_POST["role"]
                    ?? "user"
                )
            );

        $department =
            trim(
                (string)(
                    $_POST["department"]
                    ?? ""
                )
            );

        if (
            $name === ""
            ||
            $email === ""
            ||
            $password === ""
            ||
            $department === ""
        ) {
            auFlash(
                "danger",
                "Please complete all required fields."
            );
        }

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            auFlash(
                "danger",
                "Please enter a valid email address."
            );
        }

        if (
            strlen($password)
            < 8
        ) {
            auFlash(
                "danger",
                "Password must contain at least 8 characters."
            );
        }

        if (
            $password
            !==
            $confirmPassword
        ) {
            auFlash(
                "danger",
                "Password confirmation does not match."
            );
        }

        if (
            !in_array(
                $role,
                $allowedRoles,
                true
            )
        ) {
            auFlash(
                "danger",
                "Invalid user role."
            );
        }

        $emailExists =
            auScalar(
                $conn,
                "SELECT COUNT(*)
                 FROM users
                 WHERE email = ?",
                "s",
                [$email]
            );

        if ($emailExists > 0) {
            auFlash(
                "danger",
                "That email address is already registered."
            );
        }

        $passwordHash =
            password_hash(
                $password,
                PASSWORD_DEFAULT
            );

        $status = "Active";

        $stmt =
            $conn->prepare(
                "INSERT INTO users
                 (
                    name,
                    email,
                    password,
                    role,
                    department,
                    status
                 )
                 VALUES (
                    ?, ?, ?, ?, ?, ?
                 )"
            );

        $stmt->bind_param(
            "ssssss",
            $name,
            $email,
            $passwordHash,
            $role,
            $department,
            $status
        );

        $stmt->execute();

        $newUserId =
            (int)$conn->insert_id;

        $stmt->close();

        auLogAdminAction(
            $conn,
            "user_create",
            $newUserId,
            $email,
            $name
        );

        auFlash(
            "success",
            "User account created successfully."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | EDIT
    |--------------------------------------------------------------------------
    */

    if ($action === "edit") {
        $targetId =
            (int)(
                $_POST["user_id"]
                ?? 0
            );

        $name =
            trim(
                (string)(
                    $_POST["name"]
                    ?? ""
                )
            );

        $email =
            strtolower(
                trim(
                    (string)(
                        $_POST["email"]
                        ?? ""
                    )
                )
            );

        $role =
            trim(
                (string)(
                    $_POST["role"]
                    ?? ""
                )
            );

        $department =
            trim(
                (string)(
                    $_POST["department"]
                    ?? ""
                )
            );

        if (
            $targetId <= 0
            ||
            $name === ""
            ||
            $email === ""
            ||
            $department === ""
        ) {
            auFlash(
                "danger",
                "Please complete all required fields."
            );
        }

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            auFlash(
                "danger",
                "Please enter a valid email address."
            );
        }

        if (
            !in_array(
                $role,
                $allowedRoles,
                true
            )
        ) {
            auFlash(
                "danger",
                "Invalid user role."
            );
        }

        $targetStmt =
            $conn->prepare(
                "SELECT
                    id,
                    role,
                    status
                 FROM users
                 WHERE id = ?
                 LIMIT 1"
            );

        $targetStmt->bind_param(
            "i",
            $targetId
        );

        $targetStmt->execute();

        $target =
            $targetStmt
                ->get_result()
                ->fetch_assoc();

        $targetStmt->close();

        if (!$target) {
            auFlash(
                "danger",
                "User account not found."
            );
        }

        /*
         * Keep the currently logged-in admin from removing
         * their own administrator access.
         */
        if (
            $targetId === $currentAdminId
            &&
            $role !== "admin"
        ) {
            auFlash(
                "danger",
                "You cannot remove your own Administrator role while logged in."
            );
        }

        /*
         * Protect the last active Administrator.
         */
        if (
            $target["role"] === "admin"
            &&
            $target["status"] === "Active"
            &&
            $role !== "admin"
        ) {
            $activeAdmins =
                auScalar(
                    $conn,
                    "SELECT COUNT(*)
                     FROM users
                     WHERE
                        role = 'admin'
                        AND status = 'Active'"
                );

            if ($activeAdmins <= 1) {
                auFlash(
                    "danger",
                    "The last active Administrator cannot be downgraded."
                );
            }
        }

        $emailExists =
            auScalar(
                $conn,
                "SELECT COUNT(*)
                 FROM users
                 WHERE
                    email = ?
                    AND id <> ?",
                "si",
                [
                    $email,
                    $targetId
                ]
            );

        if ($emailExists > 0) {
            auFlash(
                "danger",
                "That email address is already used by another account."
            );
        }

        $stmt =
            $conn->prepare(
                "UPDATE users
                 SET
                    name = ?,
                    email = ?,
                    role = ?,
                    department = ?
                 WHERE id = ?"
            );

        $stmt->bind_param(
            "ssssi",
            $name,
            $email,
            $role,
            $department,
            $targetId
        );

        $stmt->execute();
        $stmt->close();

        if ($targetId === $currentAdminId) {
            $_SESSION["name"] = $name;
            $_SESSION["email"] = $email;
            $_SESSION["role"] = $role;
            $_SESSION["department"] =
                $department;
        }

        auFlash(
            "success",
            "User account updated successfully."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESET PASSWORD
    |--------------------------------------------------------------------------
    */

    if ($action === "reset_password") {
        $targetId =
            (int)(
                $_POST["user_id"]
                ?? 0
            );

        $newPassword =
            (string)(
                $_POST["new_password"]
                ?? ""
            );

        $confirmPassword =
            (string)(
                $_POST["confirm_password"]
                ?? ""
            );

        if (
            $targetId <= 0
            ||
            strlen($newPassword) < 8
        ) {
            auFlash(
                "danger",
                "New password must contain at least 8 characters."
            );
        }

        if (
            $newPassword
            !==
            $confirmPassword
        ) {
            auFlash(
                "danger",
                "Password confirmation does not match."
            );
        }

        $targetStmt =
            $conn->prepare(
                "SELECT
                    id,
                    name,
                    email
                 FROM users
                 WHERE id = ?
                 LIMIT 1"
            );

        $targetStmt->bind_param(
            "i",
            $targetId
        );

        $targetStmt->execute();

        $target =
            $targetStmt
                ->get_result()
                ->fetch_assoc();

        $targetStmt->close();

        if (!$target) {
            auFlash(
                "danger",
                "User account not found."
            );
        }

        $passwordHash =
            password_hash(
                $newPassword,
                PASSWORD_DEFAULT
            );

        $stmt =
            $conn->prepare(
                "UPDATE users
                 SET password = ?
                 WHERE id = ?"
            );

        $stmt->bind_param(
            "si",
            $passwordHash,
            $targetId
        );

        $stmt->execute();
        $stmt->close();

        auLogAdminAction(
            $conn,
            "password_reset",
            (int)$target["id"],
            (string)($target["email"] ?? ""),
            (string)($target["name"] ?? "")
        );

        auFlash(
            "success",
            "Password reset successfully for "
            . (string)($target["email"] ?? "the account")
            . "."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVATE / DEACTIVATE
    |--------------------------------------------------------------------------
    */

    if ($action === "toggle_status") {
        $targetId =
            (int)(
                $_POST["user_id"]
                ?? 0
            );

        $targetStmt =
            $conn->prepare(
                "SELECT
                    id,
                    name,
                    role,
                    status
                 FROM users
                 WHERE id = ?
                 LIMIT 1"
            );

        $targetStmt->bind_param(
            "i",
            $targetId
        );

        $targetStmt->execute();

        $target =
            $targetStmt
                ->get_result()
                ->fetch_assoc();

        $targetStmt->close();

        if (!$target) {
            auFlash(
                "danger",
                "User account not found."
            );
        }

        $newStatus =
            $target["status"] === "Active"
                ? "Inactive"
                : "Active";

        if (
            $targetId === $currentAdminId
            &&
            $newStatus === "Inactive"
        ) {
            auFlash(
                "danger",
                "You cannot deactivate your own current account."
            );
        }

        if (
            $target["role"] === "admin"
            &&
            $target["status"] === "Active"
            &&
            $newStatus === "Inactive"
        ) {
            $activeAdmins =
                auScalar(
                    $conn,
                    "SELECT COUNT(*)
                     FROM users
                     WHERE
                        role = 'admin'
                        AND status = 'Active'"
                );

            if ($activeAdmins <= 1) {
                auFlash(
                    "danger",
                    "The last active Administrator cannot be deactivated."
                );
            }
        }

        $stmt =
            $conn->prepare(
                "UPDATE users
                 SET status = ?
                 WHERE id = ?"
            );

        $stmt->bind_param(
            "si",
            $newStatus,
            $targetId
        );

        $stmt->execute();
        $stmt->close();

        auFlash(
            "success",
            $newStatus === "Active"
                ? "User account reactivated."
                : "User account deactivated."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PERMANENT DELETE
    |--------------------------------------------------------------------------
    */

    if ($action === "delete") {
        $targetId =
            (int)(
                $_POST["user_id"]
                ?? 0
            );

        if ($targetId <= 0) {
            auFlash(
                "danger",
                "Invalid user account."
            );
        }

        if ($targetId === $currentAdminId) {
            auFlash(
                "danger",
                "You cannot delete your own current account."
            );
        }

        $targetStmt =
            $conn->prepare(
                "SELECT
                    id,
                    name,
                    role,
                    status
                 FROM users
                 WHERE id = ?
                 LIMIT 1"
            );

        $targetStmt->bind_param(
            "i",
            $targetId
        );

        $targetStmt->execute();

        $target =
            $targetStmt
                ->get_result()
                ->fetch_assoc();

        $targetStmt->close();

        if (!$target) {
            auFlash(
                "danger",
                "User account not found."
            );
        }

        if (
            $target["role"] === "admin"
            &&
            $target["status"] === "Active"
        ) {
            $activeAdmins =
                auScalar(
                    $conn,
                    "SELECT COUNT(*)
                     FROM users
                     WHERE
                        role = 'admin'
                        AND status = 'Active'"
                );

            if ($activeAdmins <= 1) {
                auFlash(
                    "danger",
                    "The last active Administrator cannot be deleted."
                );
            }
        }

        $linkedCounts =
            auLinkedCounts(
                $conn,
                $targetId
            );

        if (
            auTotalLinks(
                $linkedCounts
            ) > 0
        ) {
            auFlash(
                "danger",
                "This user has linked Workbench records and cannot be permanently deleted. Deactivate the account instead."
            );
        }

        $stmt =
            $conn->prepare(
                "DELETE FROM users
                 WHERE id = ?"
            );

        $stmt->bind_param(
            "i",
            $targetId
        );

        $stmt->execute();
        $stmt->close();

        auFlash(
            "success",
            "Unused user account permanently deleted."
        );
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search =
    trim(
        (string)(
            $_GET["q"]
            ?? ""
        )
    );

$roleFilter =
    trim(
        (string)(
            $_GET["role"]
            ?? ""
        )
    );

$departmentFilter =
    trim(
        (string)(
            $_GET["department"]
            ?? ""
        )
    );

$statusFilter =
    trim(
        (string)(
            $_GET["status"]
            ?? ""
        )
    );

if (
    !in_array(
        $roleFilter,
        [
            "",
            "admin",
            "supervisor",
            "staff",
            "user"
        ],
        true
    )
) {
    $roleFilter = "";
}

if (
    !in_array(
        $statusFilter,
        [
            "",
            "Active",
            "Inactive"
        ],
        true
    )
) {
    $statusFilter = "";
}

/*
|--------------------------------------------------------------------------
| Departments
|--------------------------------------------------------------------------
*/

$departmentHasStatus =
    auColumnExists(
        $conn,
        "departments",
        "status"
    );

$departmentSql =
    "SELECT
        department_name"
    . (
        $departmentHasStatus
            ? ", status"
            : ", 'Active' AS status"
      )
    . "
     FROM departments
     ORDER BY
        department_name ASC";

$departmentRows =
    $conn
        ->query(
            $departmentSql
        )
        ->fetch_all(
            MYSQLI_ASSOC
        );

/*
|--------------------------------------------------------------------------
| KPI Counts
|--------------------------------------------------------------------------
*/

$totalUsers =
    auScalar(
        $conn,
        "SELECT COUNT(*)
         FROM users"
    );

$activeUsers =
    auScalar(
        $conn,
        "SELECT COUNT(*)
         FROM users
         WHERE status = 'Active'"
    );

$inactiveUsers =
    auScalar(
        $conn,
        "SELECT COUNT(*)
         FROM users
         WHERE status = 'Inactive'"
    );

$hpuPersonnelCount =
    auScalar(
        $conn,
        "SELECT COUNT(*)
         FROM users
         WHERE
            role IN (
                'admin',
                'supervisor',
                'staff'
            )
            AND status = 'Active'"
    );

$pendingAccountCount = 0;

if (
    auTableExists(
        $conn,
        "account_requests"
    )
) {
    $pendingAccountCount =
        auScalar(
            $conn,
            "SELECT COUNT(*)
              FROM account_requests
              WHERE status = 'Pending'"
        );
}

if (
    auTableExists(
        $conn,
        "account_help_requests"
    )
) {
    $pendingAccountCount +=
        auScalar(
            $conn,
            "SELECT COUNT(*)
              FROM account_help_requests
              WHERE status = 'Pending'"
        );
}

/*
|--------------------------------------------------------------------------
| User List
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        u.id,
        u.name,
        u.email,
        u.role,
        u.department,
        u.status,
        u.created_at
";

if ($hasAssignedTo) {
    $sql .= ",
        COUNT(
            CASE
                WHEN jr.approval_status NOT IN (
                    'Completed',
                    'For Evaluation',
                    'Closed',
                    'Disapproved'
                )
                THEN jr.id
            END
        ) AS active_assigned_jobs
    ";
} else {
    $sql .= ",
        0 AS active_assigned_jobs
    ";
}

$sql .= "
    FROM users u
";

if ($hasAssignedTo) {
    $sql .= "
        LEFT JOIN job_requests jr
            ON jr.assigned_to = u.id
    ";

    if ($hasArchived) {
        $sql .= "
            AND COALESCE(
                jr.archived,
                0
            ) = 0
        ";
    }
}

$sql .= "
    WHERE 1 = 1
";

$types = "";
$params = [];

if ($search !== "") {
    $sql .= "
        AND (
            u.name LIKE ?
            OR u.email LIKE ?
            OR u.department LIKE ?
        )
    ";

    $like =
        "%"
        . $search
        . "%";

    $types .= "sss";

    array_push(
        $params,
        $like,
        $like,
        $like
    );
}

if ($roleFilter !== "") {
    $sql .= "
        AND u.role = ?
    ";

    $types .= "s";
    $params[] =
        $roleFilter;
}

if ($departmentFilter !== "") {
    $sql .= "
        AND u.department = ?
    ";

    $types .= "s";
    $params[] =
        $departmentFilter;
}

if ($statusFilter !== "") {
    $sql .= "
        AND u.status = ?
    ";

    $types .= "s";
    $params[] =
        $statusFilter;
}

$sql .= "
    GROUP BY
        u.id,
        u.name,
        u.email,
        u.role,
        u.department,
        u.status,
        u.created_at

    ORDER BY
        FIELD(
            u.status,
            'Active',
            'Inactive'
        ),
        FIELD(
            u.role,
            'admin',
            'supervisor',
            'staff',
            'user'
        ),
        u.name ASC
";

$stmt =
    $conn->prepare(
        $sql
    );

if ($params) {
    $stmt->bind_param(
        $types,
        ...$params
    );
}

$stmt->execute();

$users =
    $stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

$stmt->close();

/*
|--------------------------------------------------------------------------
| User Detail Counts
|--------------------------------------------------------------------------
*/

$userDetails = [];

foreach ($users as $row) {
    $linked =
        auLinkedCounts(
            $conn,
            (int)$row["id"]
        );

    $userDetails[
        (int)$row["id"]
    ] = [
        "linked" => $linked,
        "total_links" =>
            auTotalLinks(
                $linked
            )
    ];
}

/*
|--------------------------------------------------------------------------
| Recent Admin Audit Entries (password resets / created accounts)
|--------------------------------------------------------------------------
*/

$recentAudits = [];

try {
    $auditResult =
        $conn->query(
            "SELECT
                actor_name,
                action,
                target_email,
                target_name,
                created_at
             FROM admin_audit_log
             ORDER BY
                created_at DESC,
                id DESC
             LIMIT 8"
        );

    if ($auditResult) {
        while (
            $auditRow =
            $auditResult->fetch_assoc()
        ) {
            $recentAudits[] = $auditRow;
        }

        $auditResult->free();
    }
} catch (Throwable $e) {
    $recentAudits = [];
}

/*
|--------------------------------------------------------------------------
| Flash
|--------------------------------------------------------------------------
*/

$flash =
    $_SESSION["admin_users_flash"]
    ?? null;

unset(
    $_SESSION["admin_users_flash"]
);

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
    User Management | HPU Workbench
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
:root{
    --adm-bg:#f4f7fb;
    --adm-border:#dfe6ef;
    --adm-primary:#1769e0;
    --adm-text:#172033;
    --adm-muted:#667085;
    --adm-shadow:0 8px 24px rgba(28,42,72,.05)
}
body{background:var(--adm-bg);color:var(--adm-text)}
.content{padding:18px}
.container-fluid{max-width:1700px;margin:0 auto}
.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin-bottom:12px}
.kpi-card{padding:11px 12px;border:1px solid var(--adm-border);border-radius:13px;background:#fff;box-shadow:var(--adm-shadow)}
.kpi-label{font-size:.57rem;color:#667085;font-weight:900;text-transform:uppercase;letter-spacing:.04em}
.kpi-value{margin-top:4px;font-size:1.45rem;line-height:1;font-weight:900}
.kpi-note{margin-top:5px;color:#98a2b3;font-size:.59rem}
.panel{border:1px solid var(--adm-border);border-radius:14px;background:#fff;box-shadow:var(--adm-shadow);overflow:hidden}
.panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid var(--hd-row-line,#edf1f6);background:var(--hd-surface,#ffffff)}
.panel-title{margin:0;color:var(--hd-ink,#0f172a);font-size:0.95rem;font-weight:800;letter-spacing:-.015em;line-height:1.25;display:flex;align-items:center;gap:8px}
.panel-title i{color:var(--hd-primary,#2563eb);font-size:1.05em;vertical-align:-1px}
.filter-bar{padding:10px 12px;border-bottom:1px solid #edf1f6;background:#fbfcfe}
.filter-label{display:block;margin-bottom:3px;color:#667085;font-size:.56rem;font-weight:900;text-transform:uppercase;letter-spacing:.04em}
.table-wrap{overflow:auto}
.user-table{width:100%;border-collapse:collapse}
.user-table th{padding:8px 10px;background:#f8fafc;border-bottom:1px solid #eaecf0;color:#667085;font-size:.55rem;font-weight:900;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.user-table td{padding:9px 10px;border-bottom:1px solid #f0f2f5;vertical-align:middle;font-size:.64rem}
.user-table tbody tr:hover{background:#fbfcfe}
.user-identity{display:flex;align-items:center;gap:9px;min-width:230px}
.user-avatar{width:35px;height:35px;display:grid;place-items:center;flex:0 0 35px;border-radius:10px;background:#eff8ff;color:#175cd3;font-size:.63rem;font-weight:900}
.user-name{font-size:.68rem;font-weight:900;color:#1d2939}
.user-email{margin-top:1px;font-size:.58rem;color:#667085}
.role-pill,.status-pill,.load-pill{display:inline-flex;align-items:center;gap:4px;padding:4px 7px;border-radius:999px;font-size:.56rem;font-weight:850;white-space:nowrap}
.role-admin{background:#fef3f2;color:#b42318}
.role-supervisor{background:#f5f3ff;color:#6d28d9}
.role-staff{background:#eff8ff;color:#175cd3}
.role-user{background:#f2f4f7;color:#475467}
.status-active{background:#ecfdf3;color:#027a48}
.status-inactive{background:#f2f4f7;color:#667085}
.load-pill{background:#f8fafc;color:#475467}
.actions{display:flex;justify-content:flex-end;gap:5px;flex-wrap:wrap}
.actions .btn{font-size:.62rem;font-weight:800}
.detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.detail-stat{padding:9px;border:1px solid #eaecf0;border-radius:10px;background:#f8fafc}
.detail-stat-label{font-size:.55rem;color:#667085;font-weight:850;text-transform:uppercase}
.detail-stat-value{margin-top:3px;font-size:1rem;font-weight:900}
.modal-content{border-radius:15px}
.required{color:#d92d20}
.empty-state{padding:38px 12px;text-align:center;color:#667085;font-size:.66rem}
@media(max-width:1000px){.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.content{padding:12px}.detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
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
            <i class="bi bi-people-fill"></i>
        </div>

        <div class="min-w-0">
            <p class="ph-eyebrow">Administration</p>
            <h1 class="ph-title">User Management</h1>
            <p class="ph-subtitle">
                Manage Workbench accounts, roles, departments,
                account status and password resets.
            </p>
        </div>
    </div>

    <div class="ph-actions">
        <a
            class="btn btn-sm btn-outline-primary position-relative"
            href="<?= e(BASE_URL) ?>/staff/account_requests.php"
        >
            <i class="bi bi-person-plus me-1"></i>
            Account Requests

            <?php if ($pendingAccountCount > 0): ?>

            <span
                class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
            >
                <?= $pendingAccountCount > 99
                    ? "99+"
                    : $pendingAccountCount
                    ?>
            </span>

            <?php endif; ?>
        </a>

        <button
            class="btn btn-sm btn-primary"
            type="button"
            data-bs-toggle="modal"
            data-bs-target="#addUserModal"
        >
            <i class="bi bi-person-plus me-1"></i>
            Add User
        </button>
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

<div class="kpi-grid">

    <div class="kpi-card">
        <div class="kpi-label">Total Users</div>
        <div class="kpi-value"><?= $totalUsers ?></div>
        <div class="kpi-note">All registered Workbench accounts</div>
    </div>

    <div class="kpi-card">
        <div class="kpi-label">Active</div>
        <div class="kpi-value text-success"><?= $activeUsers ?></div>
        <div class="kpi-note">Accounts currently allowed to sign in</div>
    </div>

    <div class="kpi-card">
        <div class="kpi-label">Inactive</div>
        <div class="kpi-value text-secondary"><?= $inactiveUsers ?></div>
        <div class="kpi-note">Retained for historical record integrity</div>
    </div>

    <div class="kpi-card">
        <div class="kpi-label">HPU Personnel</div>
        <div class="kpi-value text-primary"><?= $hpuPersonnelCount ?></div>
        <div class="kpi-note">Admin, Supervisor and Staff accounts</div>
    </div>

</div>

<section class="panel mb-3">

<div class="panel-head">
    <div class="panel-title">
        <i class="bi bi-shield-lock me-1 text-primary"></i>
        Password &amp; Account Audit
    </div>

    <button
        class="btn btn-sm btn-outline-primary"
        type="button"
        data-bs-toggle="collapse"
        data-bs-target="#auditTrailCollapse"
        aria-expanded="false"
        aria-controls="auditTrailCollapse"
    >
        <i class="bi bi-clock-history me-1"></i>
        Recent activity (<?= count($recentAudits) ?>)
    </button>
</div>

<div class="collapse" id="auditTrailCollapse">
    <div class="p-3">
        <p class="small text-muted mb-2">
            Only administrators can reset passwords or create accounts.
            Passwords themselves are never shown or stored here — this log
            records who acted, on which account, and when.
        </p>

        <?php if (!$recentAudits): ?>
            <div class="empty-state p-3 small text-muted">
                <i class="bi bi-clock-history"></i>
                No password resets or account creations recorded yet.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Target Account</th>
                            <th>Performed By</th>
                            <th class="text-end">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAudits as $audit): ?>
                            <tr>
                                <td>
                                    <span class="badge <?= e(($audit["action"] ?? "") === "password_reset" ? "bg-warning" : "bg-success") ?>">
                                        <i class="bi <?= e(($audit["action"] ?? "") === "password_reset" ? "bi-key" : "bi-person-plus") ?> me-1"></i>
                                        <?= e(auActionLabel((string)($audit["action"] ?? ""))) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-bold"><?= e((string)($audit["target_name"] ?? "—")) ?></div>
                                    <div class="small text-muted"><?= e((string)($audit["target_email"] ?? "")) ?></div>
                                </td>
                                <td><?= e((string)($audit["actor_name"] ?? "—")) ?></td>
                                <td class="text-end small text-muted">
                                    <?= e((string)($audit["created_at"] ?? "")) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

</section>

<section class="panel">

<div class="panel-head">
    <div class="panel-title">
        <i class="bi bi-people me-1 text-primary"></i>
        Workbench Accounts
    </div>

    <div class="small text-muted">
        <?= count($users) ?> result(s)
    </div>
</div>

<form
    class="filter-bar"
    method="get"
>

<div class="row g-2 align-items-end">

    <div class="col-xl-4 col-lg-4">
        <label class="filter-label">Search</label>
        <input
            class="form-control form-control-sm"
            name="q"
            value="<?= e($search) ?>"
            placeholder="Name, email or department..."
        >
    </div>

    <div class="col-xl-2 col-lg-2">
        <label class="filter-label">Role</label>
        <select
            class="form-select form-select-sm"
            name="role"
        >
            <option value="">All Roles</option>
            <option value="admin" <?= $roleFilter === "admin" ? "selected" : "" ?>>Admin</option>
            <option value="supervisor" <?= $roleFilter === "supervisor" ? "selected" : "" ?>>Supervisor</option>
            <option value="staff" <?= $roleFilter === "staff" ? "selected" : "" ?>>Staff</option>
            <option value="user" <?= $roleFilter === "user" ? "selected" : "" ?>>User</option>
        </select>
    </div>

    <div class="col-xl-3 col-lg-3">
        <label class="filter-label">Department</label>
        <select
            class="form-select form-select-sm"
            name="department"
        >
            <option value="">All Departments</option>

            <?php foreach ($departmentRows as $department): ?>

            <option
                value="<?= e($department["department_name"]) ?>"
                <?= $departmentFilter === $department["department_name"] ? "selected" : "" ?>
            >
                <?= e($department["department_name"]) ?>
            </option>

            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-xl-1 col-lg-2">
        <label class="filter-label">Status</label>
        <select
            class="form-select form-select-sm"
            name="status"
        >
            <option value="">All</option>
            <option value="Active" <?= $statusFilter === "Active" ? "selected" : "" ?>>Active</option>
            <option value="Inactive" <?= $statusFilter === "Inactive" ? "selected" : "" ?>>Inactive</option>
        </select>
    </div>

    <div class="col-xl-auto d-flex gap-2">
        <button
            class="btn btn-sm btn-primary"
            type="submit"
        >
            <i class="bi bi-funnel me-1"></i>
            Apply
        </button>

        <a
            class="btn btn-sm btn-light"
            href="users.php"
        >
            Reset
        </a>
    </div>

</div>

</form>

<div class="table-wrap">

<?php if (!$users): ?>

<div class="empty-state">
    <i class="bi bi-person-x d-block fs-4 mb-2"></i>
    No user account matches the selected filters.
</div>

<?php else: ?>

<table class="user-table">

<thead>
<tr>
    <th>User</th>
    <th>Role</th>
    <th>Department</th>
    <th>Status</th>
    <th>Assigned Jobs</th>
    <th>Date Added</th>
    <th class="text-end">Actions</th>
</tr>
</thead>

<tbody>

<?php foreach ($users as $row): ?>

<?php
$uid = (int)$row["id"];

$details =
    $userDetails[$uid]
    ?? [
        "linked" => [],
        "total_links" => 0
    ];

$isSelf =
    $uid ===
    $currentAdminId;
?>

<tr>

    <td>
        <div class="user-identity">

            <div class="user-avatar">
                <?= e(auInitials($row["name"])) ?>
            </div>

            <div>
                <div class="user-name">
                    <?= e($row["name"]) ?>

                    <?php if ($isSelf): ?>
                        <span class="badge text-bg-light ms-1">
                            You
                        </span>
                    <?php endif; ?>
                </div>

                <div class="user-email">
                    <?= e($row["email"]) ?>
                </div>
            </div>

        </div>
    </td>

    <td>
        <span class="role-pill <?= e(auRoleClass($row["role"])) ?>">
            <?= e(ucfirst($row["role"])) ?>
        </span>
    </td>

    <td>
        <?= e($row["department"] ?: "—") ?>
    </td>

    <td>
        <span class="status-pill <?= $row["status"] === "Active" ? "status-active" : "status-inactive" ?>">
            <i class="bi <?= $row["status"] === "Active" ? "bi-check-circle" : "bi-pause-circle" ?>"></i>
            <?= e($row["status"]) ?>
        </span>
    </td>

    <td>
        <span class="load-pill">
            <i class="bi bi-briefcase"></i>
            <?= (int)$row["active_assigned_jobs"] ?> active
        </span>
    </td>

    <td>
        <?= e(
            date(
                "M d, Y",
                strtotime(
                    $row["created_at"]
                )
            )
        ) ?>
    </td>

    <td>
        <div class="actions">

            <button
                class="btn btn-sm btn-outline-secondary view-user-btn"
                type="button"
                data-user='<?= e(json_encode([
                    "id" => $uid,
                    "name" => $row["name"],
                    "email" => $row["email"],
                    "role" => $row["role"],
                    "department" => $row["department"],
                    "status" => $row["status"],
                    "created_at" => $row["created_at"],
                    "active_assigned_jobs" => (int)$row["active_assigned_jobs"],
                    "linked" => $details["linked"],
                    "total_links" => $details["total_links"]
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
            >
                <i class="bi bi-eye"></i>
                View
            </button>

            <button
                class="btn btn-sm btn-outline-primary edit-user-btn"
                type="button"
                data-user='<?= e(json_encode([
                    "id" => $uid,
                    "name" => $row["name"],
                    "email" => $row["email"],
                    "role" => $row["role"],
                    "department" => $row["department"]
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
            >
                <i class="bi bi-pencil"></i>
                Edit
            </button>

            <a
                class="btn btn-sm btn-outline-info"
                href="module_permissions.php?user_id=<?= $uid ?>"
                title="Configure specific modules for this user"
            >
                <i class="bi bi-sliders"></i>
                Modules
            </a>

            <button
                class="btn btn-sm btn-outline-warning reset-password-btn"
                type="button"
                data-user-id="<?= $uid ?>"
                data-user-name="<?= e($row["name"]) ?>"
            >
                <i class="bi bi-key"></i>
                Password
            </button>

            <?php if (!$isSelf): ?>

            <form
                method="post"
                class="d-inline"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="toggle_status"
                >

                <input
                    type="hidden"
                    name="user_id"
                    value="<?= $uid ?>"
                >

                <button
                    class="btn btn-sm <?= $row["status"] === "Active" ? "btn-outline-secondary" : "btn-outline-success" ?>"
                    type="submit"
                    onclick="return confirm('<?= $row["status"] === "Active" ? "Deactivate" : "Reactivate" ?> this account?')"
                >
                    <i class="bi <?= $row["status"] === "Active" ? "bi-person-dash" : "bi-person-check" ?>"></i>
                    <?= $row["status"] === "Active" ? "Deactivate" : "Reactivate" ?>
                </button>
            </form>

            <?php endif; ?>

            <?php if (!$isSelf && (int)$details["total_links"] === 0): ?>

            <form
                method="post"
                class="d-inline"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="delete"
                >

                <input
                    type="hidden"
                    name="user_id"
                    value="<?= $uid ?>"
                >

                <button
                    class="btn btn-sm btn-outline-danger"
                    type="submit"
                    onclick="return confirm('Permanently delete this unused user account? This action cannot be undone.')"
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

</div>

</main>

</div>

</div>

<!-- ======================================================
     ADD USER
======================================================= -->

<div
    class="modal fade"
    id="addUserModal"
    tabindex="-1"
>

<div class="modal-dialog modal-lg modal-dialog-centered">

<div class="modal-content">

<form method="post">

<div class="modal-header">
    <div>
        <h5 class="modal-title fw-bold">
            Add User Account
        </h5>
        <div class="small text-muted">
            Create a new Workbench account.
        </div>
    </div>

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

<input
    type="hidden"
    name="action"
    value="add"
>

<div class="row g-3">

    <div class="col-md-6">
        <label class="form-label fw-bold">
            Full Name <span class="required">*</span>
        </label>

        <input
            class="form-control"
            name="name"
            maxlength="150"
            required
        >
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold">
            Email Address <span class="required">*</span>
        </label>

        <input
            class="form-control"
            type="email"
            name="email"
            maxlength="150"
            required
        >
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold">
            Role
        </label>

        <select
            class="form-select"
            name="role"
            required
        >
            <option value="user">User</option>
            <option value="staff">Staff</option>
            <option value="supervisor">Supervisor</option>
            <option value="admin">Admin</option>
        </select>
    </div>

    <div class="col-md-8">
        <label class="form-label fw-bold">
            Department / Office <span class="required">*</span>
        </label>

        <select
            class="form-select"
            name="department"
            required
        >
            <option value="">
                Select Department
            </option>

            <?php foreach ($departmentRows as $department): ?>

                <?php
                if (
                    $department["status"]
                    !== "Active"
                ) {
                    continue;
                }
                ?>

                <option value="<?= e($department["department_name"]) ?>">
                    <?= e($department["department_name"]) ?>
                </option>

            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold">
            Temporary Password <span class="required">*</span>
        </label>
        <div class="input-group">
            <input
                class="form-control"
                type="password"
                name="password"
                id="createPassword"
                minlength="8"
                required
            >
            <button
                class="btn btn-outline-secondary toggle-password-btn"
                type="button"
                data-target="createPassword"
                title="Toggle password visibility"
            >
                <i class="bi bi-eye"></i>
            </button>
        </div>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold">
            Confirm Password <span class="required">*</span>
        </label>
        <div class="input-group">
            <input
                class="form-control"
                type="password"
                name="confirm_password"
                id="createConfirmPassword"
                minlength="8"
                required
            >
            <button
                class="btn btn-outline-secondary toggle-password-btn"
                type="button"
                data-target="createConfirmPassword"
                title="Toggle password visibility"
            >
                <i class="bi bi-eye"></i>
            </button>
        </div>
    </div>

</div>

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
        class="btn btn-primary"
        type="submit"
    >
        <i class="bi bi-person-plus me-1"></i>
        Create Account
    </button>
</div>

</form>

</div>

</div>

</div>

<!-- ======================================================
     EDIT USER
======================================================= -->

<div
    class="modal fade"
    id="editUserModal"
    tabindex="-1"
>

<div class="modal-dialog modal-lg modal-dialog-centered">

<div class="modal-content">

<form method="post">

<div class="modal-header">
    <div>
        <h5 class="modal-title fw-bold">
            Edit User
        </h5>
        <div class="small text-muted">
            Update profile, role and department assignment.
        </div>
    </div>

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

<input
    type="hidden"
    name="action"
    value="edit"
>

<input
    type="hidden"
    name="user_id"
    id="editUserId"
>

<div class="row g-3">

    <div class="col-md-6">
        <label class="form-label fw-bold">
            Full Name
        </label>

        <input
            class="form-control"
            name="name"
            id="editUserName"
            required
        >
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold">
            Email Address
        </label>

        <input
            class="form-control"
            type="email"
            name="email"
            id="editUserEmail"
            required
        >
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold">
            Role
        </label>

        <select
            class="form-select"
            name="role"
            id="editUserRole"
        >
            <option value="user">User</option>
            <option value="staff">Staff</option>
            <option value="supervisor">Supervisor</option>
            <option value="admin">Admin</option>
        </select>
    </div>

    <div class="col-md-8">
        <label class="form-label fw-bold">
            Department / Office
        </label>

        <select
            class="form-select"
            name="department"
            id="editUserDepartment"
            required
        >
            <?php foreach ($departmentRows as $department): ?>
                <option value="<?= e($department["department_name"]) ?>">
                    <?= e($department["department_name"]) ?>
                    <?= $department["status"] === "Inactive" ? " (Inactive)" : "" ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

</div>

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
        class="btn btn-primary"
        type="submit"
    >
        Save Changes
    </button>
</div>

</form>

</div>

</div>

</div>

<!-- ======================================================
     PASSWORD RESET
======================================================= -->

<div
    class="modal fade"
    id="passwordModal"
    tabindex="-1"
>

<div class="modal-dialog modal-dialog-centered">

<div class="modal-content">

<form method="post">

<div class="modal-header">
    <div>
        <h5 class="modal-title fw-bold">
            Reset Password
        </h5>
        <div
            class="small text-muted"
            id="passwordUserName"
        ></div>
    </div>

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

<input
    type="hidden"
    name="action"
    value="reset_password"
>

<input
    type="hidden"
    name="user_id"
    id="passwordUserId"
>

<div class="mb-3">
    <label class="form-label fw-bold">
        New Password
    </label>
    <div class="input-group">
        <input
            class="form-control"
            type="password"
            name="new_password"
            id="resetNewPassword"
            minlength="8"
            required
        >
        <button
            class="btn btn-outline-secondary toggle-password-btn"
            type="button"
            data-target="resetNewPassword"
            title="Toggle password visibility"
        >
            <i class="bi bi-eye"></i>
        </button>
    </div>
</div>

<div>
    <label class="form-label fw-bold">
        Confirm Password
    </label>
    <div class="input-group">
        <input
            class="form-control"
            type="password"
            name="confirm_password"
            id="resetConfirmPassword"
            minlength="8"
            required
        >
        <button
            class="btn btn-outline-secondary toggle-password-btn"
            type="button"
            data-target="resetConfirmPassword"
            title="Toggle password visibility"
        >
            <i class="bi bi-eye"></i>
        </button>
    </div>
</div>

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
        class="btn btn-warning"
        type="submit"
    >
        <i class="bi bi-key me-1"></i>
        Reset Password
    </button>
</div>

</form>

</div>

</div>

</div>

<!-- ======================================================
     USER DETAILS
======================================================= -->

<div
    class="modal fade"
    id="viewUserModal"
    tabindex="-1"
>

<div class="modal-dialog modal-lg modal-dialog-centered">

<div class="modal-content">

<div class="modal-header">
    <div>
        <h5
            class="modal-title fw-bold"
            id="viewUserTitle"
        >
            User Profile
        </h5>

        <div
            class="small text-muted"
            id="viewUserEmail"
        ></div>
    </div>

    <button
        class="btn-close"
        type="button"
        data-bs-dismiss="modal"
    ></button>
</div>

<div class="modal-body">

<div class="row g-2 mb-3">

    <div class="col-md-4">
        <div class="detail-stat">
            <div class="detail-stat-label">Role</div>
            <div class="detail-stat-value" id="viewUserRole">—</div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="detail-stat">
            <div class="detail-stat-label">Department</div>
            <div class="detail-stat-value fs-6" id="viewUserDepartment">—</div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="detail-stat">
            <div class="detail-stat-label">Status</div>
            <div class="detail-stat-value fs-6" id="viewUserStatus">—</div>
        </div>
    </div>

</div>

<h6 class="fw-bold mb-2">
    Workbench Activity
</h6>

<div class="detail-grid">

    <div class="detail-stat">
        <div class="detail-stat-label">Requests Submitted</div>
        <div class="detail-stat-value" id="statSubmitted">0</div>
    </div>

    <div class="detail-stat">
        <div class="detail-stat-label">Assigned Jobs</div>
        <div class="detail-stat-value" id="statAssigned">0</div>
    </div>

    <div class="detail-stat">
        <div class="detail-stat-label">Calendar Created</div>
        <div class="detail-stat-value" id="statCalendarCreated">0</div>
    </div>

    <div class="detail-stat">
        <div class="detail-stat-label">Calendar Assigned</div>
        <div class="detail-stat-value" id="statCalendarAssigned">0</div>
    </div>

    <div class="detail-stat">
        <div class="detail-stat-label">Personal Tasks</div>
        <div class="detail-stat-value" id="statPersonal">0</div>
    </div>

    <div class="detail-stat">
        <div class="detail-stat-label">Office Activities</div>
        <div class="detail-stat-value" id="statOffice">0</div>
    </div>

    <div class="detail-stat">
        <div class="detail-stat-label">Client Surveys</div>
        <div class="detail-stat-value" id="statSurveys">0</div>
    </div>

    <div class="detail-stat">
        <div class="detail-stat-label">IEC Uploads</div>
        <div class="detail-stat-value" id="statIec">0</div>
    </div>

    <div class="detail-stat">
        <div class="detail-stat-label">Total Linked Records</div>
        <div class="detail-stat-value" id="statTotalLinks">0</div>
    </div>

</div>

</div>

<div class="modal-footer">
    <button
        class="btn btn-light"
        type="button"
        data-bs-dismiss="modal"
    >
        Close
    </button>
</div>

</div>

</div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
></script>

<script>
document.addEventListener(
    "DOMContentLoaded",
    function () {

        /* Modal opener that keeps working even if the Bootstrap
           CDN bundle failed to load (e.g. offline intranet PC). */
        function openModal(id) {
            var el = document.getElementById(id);
            if (!el) return;

            try {
                if (
                    window.bootstrap
                    &&
                    bootstrap.Modal
                ) {
                    bootstrap.Modal
                        .getOrCreateInstance(el)
                        .show();
                    return;
                }
            } catch (e) {}

            el.classList.add("show");
            el.style.display = "block";
            el.removeAttribute("aria-hidden");
            el.setAttribute("aria-modal", "true");
            document.body.classList.add("modal-open");

            if (
                !document.querySelector(
                    "[data-fallback-backdrop]"
                )
            ) {
                var backdrop =
                    document.createElement("div");
                backdrop.className =
                    "modal-backdrop fade show";
                backdrop.setAttribute(
                    "data-fallback-backdrop",
                    "1"
                );
                backdrop.addEventListener(
                    "click",
                    function () {
                        closeModal(id);
                    }
                );
                document.body.appendChild(backdrop);
            }
        }

        function closeModal(id) {
            var el = document.getElementById(id);
            if (!el) return;

            try {
                if (
                    window.bootstrap
                    &&
                    bootstrap.Modal
                ) {
                    var instance =
                        bootstrap.Modal.getInstance(el);
                    if (instance) {
                        instance.hide();
                        return;
                    }
                }
            } catch (e) {}

            el.classList.remove("show");
            el.style.display = "none";
            document.body.classList.remove(
                "modal-open"
            );

            var backdrop = document.querySelector(
                "[data-fallback-backdrop]"
            );
            if (backdrop) backdrop.remove();
        }

        /* Fallback dismiss: Cancel / X buttons when Bootstrap JS is absent. */
        document.addEventListener(
            "click",
            function (event) {
                var dismissBtn =
                    event.target && event.target.closest
                        ? event.target.closest(
                            '[data-bs-dismiss="modal"]'
                        )
                        : null;

                if (!dismissBtn) return;

                if (
                    window.bootstrap
                    &&
                    bootstrap.Modal
                ) {
                    return;
                }

                var modal =
                    dismissBtn.closest(".modal");

                if (modal && modal.id) {
                    event.preventDefault();
                    closeModal(modal.id);
                }
            }
        );

        document.addEventListener(
            "keydown",
            function (event) {
                if (event.key !== "Escape") return;

                if (
                    window.bootstrap
                    &&
                    bootstrap.Modal
                ) {
                    return;
                }

                ["editUserModal", "passwordModal", "viewUserModal", "addUserModal"].forEach(
                    function (id) {
                        var el =
                            document.getElementById(id);

                        if (
                            el
                            &&
                            el.classList.contains("show")
                        ) {
                            closeModal(id);
                        }
                    }
                );
            }
        );

        document
            .querySelectorAll(
                ".edit-user-btn"
            )
            .forEach(
                function (button) {
                    button.addEventListener(
                        "click",
                        function () {
                            const user =
                                JSON.parse(
                                    button.dataset.user
                                );

                            document.getElementById("editUserId").value =
                                user.id;

                            document.getElementById("editUserName").value =
                                user.name;

                            document.getElementById("editUserEmail").value =
                                user.email;

                            document.getElementById("editUserRole").value =
                                user.role;

                            document.getElementById("editUserDepartment").value =
                                user.department || "";

                            openModal("editUserModal");
                        }
                    );
                }
            );

        document
            .querySelectorAll(
                ".reset-password-btn"
            )
            .forEach(
                function (button) {
                    button.addEventListener(
                        "click",
                        function () {
                            document.getElementById("passwordUserId").value =
                                button.dataset.userId;

                            document.getElementById("passwordUserName").textContent =
                                button.dataset.userName;

                            openModal("passwordModal");
                        }
                    );
                }
            );

        document
            .querySelectorAll(
                ".view-user-btn"
            )
            .forEach(
                function (button) {
                    button.addEventListener(
                        "click",
                        function () {
                            const user =
                                JSON.parse(
                                    button.dataset.user
                                );

                            const linked =
                                user.linked || {};

                            document.getElementById("viewUserTitle").textContent =
                                user.name;

                            document.getElementById("viewUserEmail").textContent =
                                user.email;

                            document.getElementById("viewUserRole").textContent =
                                user.role.charAt(0).toUpperCase()
                                + user.role.slice(1);

                            document.getElementById("viewUserDepartment").textContent =
                                user.department || "—";

                            document.getElementById("viewUserStatus").textContent =
                                user.status;

                            document.getElementById("statSubmitted").textContent =
                                linked.submitted_requests || 0;

                            document.getElementById("statAssigned").textContent =
                                linked.assigned_jobs || 0;

                            document.getElementById("statCalendarCreated").textContent =
                                linked.calendar_created || 0;

                            document.getElementById("statCalendarAssigned").textContent =
                                linked.calendar_assigned || 0;

                            document.getElementById("statPersonal").textContent =
                                linked.personal_tasks || 0;

                            document.getElementById("statOffice").textContent =
                                (linked.office_submitted || 0)
                                + (linked.office_assigned || 0);

                            document.getElementById("statSurveys").textContent =
                                linked.client_surveys || 0;

                            document.getElementById("statIec").textContent =
                                linked.iec_uploads || 0;

                            document.getElementById("statTotalLinks").textContent =
                                user.total_links || 0;

                            openModal("viewUserModal");
                        }
                    );
        document.querySelectorAll(".toggle-password-btn").forEach(function (button) {
            button.addEventListener("click", function () {
                const targetId = button.dataset.target;
                const input = document.getElementById(targetId);
                if (!input) return;
                const icon = button.querySelector("i");
                if (input.type === "password") {
                    input.type = "text";
                    if (icon) {
                        icon.classList.remove("bi-eye");
                        icon.classList.add("bi-eye-slash");
                    }
                } else {
                    input.type = "password";
                    if (icon) {
                        icon.classList.remove("bi-eye-slash");
                        icon.classList.add("bi-eye");
                    }
                }
            });
        });

    }
);
</script>

</body>
</html>
