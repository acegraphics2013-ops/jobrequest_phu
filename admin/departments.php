<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - ADMIN DEPARTMENT MANAGEMENT
|--------------------------------------------------------------------------
| Drop-in replacement:
|   C:\laragon\www\jobrequest_phu\admin\departments.php
|--------------------------------------------------------------------------
*/

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

requireRole(["admin"]);
requireModule($conn, "departments");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function adTableExists(
    mysqli $conn,
    string $table
): bool {
    $safe =
        $conn->real_escape_string(
            $table
        );

    $result =
        $conn->query(
            "SHOW TABLES LIKE '{$safe}'"
        );

    return
        $result
        &&
        $result->num_rows > 0;
}

function adColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $stmt =
        $conn->prepare(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?"
        );

    $stmt->bind_param(
        "ss",
        $table,
        $column
    );

    $stmt->execute();

    $row =
        $stmt
            ->get_result()
            ->fetch_row();

    $stmt->close();

    return
        (int)(
            $row[0]
            ?? 0
        ) > 0;
}

function adScalar(
    mysqli $conn,
    string $sql,
    string $types = "",
    array $params = []
): int {
    $stmt =
        $conn->prepare(
            $sql
        );

    if (
        $types !== ""
        &&
        $params
    ) {
        $stmt->bind_param(
            $types,
            ...$params
        );
    }

    $stmt->execute();

    $row =
        $stmt
            ->get_result()
            ->fetch_row();

    $stmt->close();

    return
        (int)(
            $row[0]
            ?? 0
        );
}

function adFlash(
    string $type,
    string $message
): never {
    $_SESSION["admin_departments_flash"] = [
        "type" => $type,
        "message" => $message
    ];

    header(
        "Location: departments.php"
    );

    exit;
}

function adValidateCsrf(): void
{
    $sessionToken =
        (string)(
            $_SESSION["admin_departments_csrf"]
            ?? ""
        );

    $submittedToken =
        (string)(
            $_POST["csrf_token"]
            ?? ""
        );

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
        adFlash(
            "danger",
            "Security token expired. Please try again."
        );
    }
}

function adUsageCounts(
    mysqli $conn,
    string $departmentName
): array {
    $counts = [
        "users" => 0,
        "job_requests" => 0,
        "office_activities" => 0,
        "client_surveys" => 0
    ];

    if (
        adTableExists(
            $conn,
            "users"
        )
        &&
        adColumnExists(
            $conn,
            "users",
            "department"
        )
    ) {
        $counts["users"] =
            adScalar(
                $conn,
                "SELECT COUNT(*)
                 FROM users
                 WHERE department = ?",
                "s",
                [$departmentName]
            );
    }

    if (
        adTableExists(
            $conn,
            "job_requests"
        )
        &&
        adColumnExists(
            $conn,
            "job_requests",
            "department"
        )
    ) {
        $counts["job_requests"] =
            adScalar(
                $conn,
                "SELECT COUNT(*)
                 FROM job_requests
                 WHERE department = ?",
                "s",
                [$departmentName]
            );
    }

    if (
        adTableExists(
            $conn,
            "office_activity_requests"
        )
        &&
        adColumnExists(
            $conn,
            "office_activity_requests",
            "office_name"
        )
    ) {
        $counts["office_activities"] =
            adScalar(
                $conn,
                "SELECT COUNT(*)
                 FROM office_activity_requests
                 WHERE office_name = ?",
                "s",
                [$departmentName]
            );
    }

    if (
        adTableExists(
            $conn,
            "job_request_client_experience"
        )
        &&
        adColumnExists(
            $conn,
            "job_request_client_experience",
            "department_visited"
        )
    ) {
        $counts["client_surveys"] =
            adScalar(
                $conn,
                "SELECT COUNT(*)
                 FROM job_request_client_experience
                 WHERE department_visited = ?",
                "s",
                [$departmentName]
            );
    }

    return $counts;
}

function adTotalUsage(
    array $counts
): int {
    return
        array_sum(
            array_map(
                "intval",
                $counts
            )
        );
}

/*
|--------------------------------------------------------------------------
| Upgrade Existing Department Table
|--------------------------------------------------------------------------
| Keep department_name intact for compatibility with existing forms.
|--------------------------------------------------------------------------
*/

$departmentColumns = [
    "short_name" =>
        "VARCHAR(60) NULL",

    "parent_office" =>
        "VARCHAR(180) NULL",

    "description" =>
        "TEXT NULL",

    "status" =>
        "ENUM(
            'Active',
            'Inactive'
         )
         NOT NULL DEFAULT 'Active'",

    "updated_at" =>
        "TIMESTAMP NULL
         DEFAULT CURRENT_TIMESTAMP
         ON UPDATE CURRENT_TIMESTAMP"
];

foreach (
    $departmentColumns
    as $column => $definition
) {
    if (
        !adColumnExists(
            $conn,
            "departments",
            $column
        )
    ) {
        $conn->query(
            "ALTER TABLE departments
             ADD COLUMN `{$column}`
             {$definition}"
        );
    }
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    empty(
        $_SESSION["admin_departments_csrf"]
    )
) {
    $_SESSION["admin_departments_csrf"] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    (string)
    $_SESSION["admin_departments_csrf"];

/*
|--------------------------------------------------------------------------
| POST Actions
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"]
    === "POST"
) {
    adValidateCsrf();

    $action =
        trim(
            (string)(
                $_POST["action"]
                ?? ""
            )
        );

    /*
    |--------------------------------------------------------------------------
    | ADD
    |--------------------------------------------------------------------------
    */

    if ($action === "add") {
        $name =
            trim(
                (string)(
                    $_POST["department_name"]
                    ?? ""
                )
            );

        $shortName =
            trim(
                (string)(
                    $_POST["short_name"]
                    ?? ""
                )
            );

        $parentOffice =
            trim(
                (string)(
                    $_POST["parent_office"]
                    ?? ""
                )
            );

        $description =
            trim(
                (string)(
                    $_POST["description"]
                    ?? ""
                )
            );

        if ($name === "") {
            adFlash(
                "danger",
                "Department / Office name is required."
            );
        }

        $duplicate =
            adScalar(
                $conn,
                "SELECT COUNT(*)
                 FROM departments
                 WHERE LOWER(department_name)
                    = LOWER(?)",
                "s",
                [$name]
            );

        if ($duplicate > 0) {
            adFlash(
                "danger",
                "That Department / Office already exists."
            );
        }

        $status = "Active";

        $shortNameDb =
            $shortName !== ""
                ? $shortName
                : null;

        $parentOfficeDb =
            $parentOffice !== ""
                ? $parentOffice
                : null;

        $descriptionDb =
            $description !== ""
                ? $description
                : null;

        $stmt =
            $conn->prepare(
                "INSERT INTO departments
                 (
                    department_name,
                    short_name,
                    parent_office,
                    description,
                    status
                 )
                 VALUES (
                    ?, ?, ?, ?, ?
                 )"
            );

        $stmt->bind_param(
            "sssss",
            $name,
            $shortNameDb,
            $parentOfficeDb,
            $descriptionDb,
            $status
        );

        $stmt->execute();
        $stmt->close();

        adFlash(
            "success",
            "Department / Office added successfully."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | EDIT + OPTIONAL RENAME PROPAGATION
    |--------------------------------------------------------------------------
    */

    if ($action === "edit") {
        $departmentId =
            (int)(
                $_POST["department_id"]
                ?? 0
            );

        $name =
            trim(
                (string)(
                    $_POST["department_name"]
                    ?? ""
                )
            );

        $shortName =
            trim(
                (string)(
                    $_POST["short_name"]
                    ?? ""
                )
            );

        $parentOffice =
            trim(
                (string)(
                    $_POST["parent_office"]
                    ?? ""
                )
            );

        $description =
            trim(
                (string)(
                    $_POST["description"]
                    ?? ""
                )
            );

        $updateReferences =
            isset(
                $_POST["update_references"]
            )
            &&
            (string)
            $_POST["update_references"]
            === "1";

        if (
            $departmentId <= 0
            ||
            $name === ""
        ) {
            adFlash(
                "danger",
                "Department / Office name is required."
            );
        }

        $oldStmt =
            $conn->prepare(
                "SELECT *
                 FROM departments
                 WHERE id = ?
                 LIMIT 1"
            );

        $oldStmt->bind_param(
            "i",
            $departmentId
        );

        $oldStmt->execute();

        $existing =
            $oldStmt
                ->get_result()
                ->fetch_assoc();

        $oldStmt->close();

        if (!$existing) {
            adFlash(
                "danger",
                "Department / Office not found."
            );
        }

        $duplicate =
            adScalar(
                $conn,
                "SELECT COUNT(*)
                 FROM departments
                 WHERE
                    LOWER(department_name)
                        = LOWER(?)
                    AND id <> ?",
                "si",
                [
                    $name,
                    $departmentId
                ]
            );

        if ($duplicate > 0) {
            adFlash(
                "danger",
                "Another Department / Office already uses that name."
            );
        }

        $oldName =
            (string)
            $existing["department_name"];

        $nameChanged =
            $oldName !==
            $name;

        if (
            $nameChanged
            &&
            !$updateReferences
        ) {
            $usage =
                adUsageCounts(
                    $conn,
                    $oldName
                );

            if (
                adTotalUsage(
                    $usage
                ) > 0
            ) {
                adFlash(
                    "warning",
                    "This Department / Office is already used by Workbench records. Check “Update existing references” when renaming it."
                );
            }
        }

        $shortNameDb =
            $shortName !== ""
                ? $shortName
                : null;

        $parentOfficeDb =
            $parentOffice !== ""
                ? $parentOffice
                : null;

        $descriptionDb =
            $description !== ""
                ? $description
                : null;

        $conn->begin_transaction();

        try {
            $stmt =
                $conn->prepare(
                    "UPDATE departments
                     SET
                        department_name = ?,
                        short_name = ?,
                        parent_office = ?,
                        description = ?
                     WHERE id = ?"
                );

            $stmt->bind_param(
                "ssssi",
                $name,
                $shortNameDb,
                $parentOfficeDb,
                $descriptionDb,
                $departmentId
            );

            $stmt->execute();
            $stmt->close();

            if (
                $nameChanged
                &&
                $updateReferences
            ) {
                if (
                    adTableExists(
                        $conn,
                        "users"
                    )
                    &&
                    adColumnExists(
                        $conn,
                        "users",
                        "department"
                    )
                ) {
                    $stmt =
                        $conn->prepare(
                            "UPDATE users
                             SET department = ?
                             WHERE department = ?"
                        );

                    $stmt->bind_param(
                        "ss",
                        $name,
                        $oldName
                    );

                    $stmt->execute();
                    $stmt->close();
                }

                if (
                    adTableExists(
                        $conn,
                        "job_requests"
                    )
                    &&
                    adColumnExists(
                        $conn,
                        "job_requests",
                        "department"
                    )
                ) {
                    $stmt =
                        $conn->prepare(
                            "UPDATE job_requests
                             SET department = ?
                             WHERE department = ?"
                        );

                    $stmt->bind_param(
                        "ss",
                        $name,
                        $oldName
                    );

                    $stmt->execute();
                    $stmt->close();
                }

                if (
                    adTableExists(
                        $conn,
                        "office_activity_requests"
                    )
                    &&
                    adColumnExists(
                        $conn,
                        "office_activity_requests",
                        "office_name"
                    )
                ) {
                    $stmt =
                        $conn->prepare(
                            "UPDATE office_activity_requests
                             SET office_name = ?
                             WHERE office_name = ?"
                        );

                    $stmt->bind_param(
                        "ss",
                        $name,
                        $oldName
                    );

                    $stmt->execute();
                    $stmt->close();
                }

                if (
                    adTableExists(
                        $conn,
                        "job_request_client_experience"
                    )
                    &&
                    adColumnExists(
                        $conn,
                        "job_request_client_experience",
                        "department_visited"
                    )
                ) {
                    $stmt =
                        $conn->prepare(
                            "UPDATE job_request_client_experience
                             SET department_visited = ?
                             WHERE department_visited = ?"
                        );

                    $stmt->bind_param(
                        "ss",
                        $name,
                        $oldName
                    );

                    $stmt->execute();
                    $stmt->close();
                }
            }

            $conn->commit();

        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        adFlash(
            "success",
            $nameChanged
                ? "Department / Office updated. Existing references were kept consistent."
                : "Department / Office updated successfully."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVATE / DEACTIVATE
    |--------------------------------------------------------------------------
    */

    if ($action === "toggle_status") {
        $departmentId =
            (int)(
                $_POST["department_id"]
                ?? 0
            );

        $stmt =
            $conn->prepare(
                "SELECT
                    id,
                    department_name,
                    status
                 FROM departments
                 WHERE id = ?
                 LIMIT 1"
            );

        $stmt->bind_param(
            "i",
            $departmentId
        );

        $stmt->execute();

        $department =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$department) {
            adFlash(
                "danger",
                "Department / Office not found."
            );
        }

        $newStatus =
            $department["status"]
            === "Active"
                ? "Inactive"
                : "Active";

        $stmt =
            $conn->prepare(
                "UPDATE departments
                 SET status = ?
                 WHERE id = ?"
            );

        $stmt->bind_param(
            "si",
            $newStatus,
            $departmentId
        );

        $stmt->execute();
        $stmt->close();

        adFlash(
            "success",
            $newStatus === "Active"
                ? "Department / Office reactivated."
                : "Department / Office deactivated. Historical records were retained."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PERMANENT DELETE - UNUSED ONLY
    |--------------------------------------------------------------------------
    */

    if ($action === "delete") {
        $departmentId =
            (int)(
                $_POST["department_id"]
                ?? 0
            );

        $stmt =
            $conn->prepare(
                "SELECT
                    id,
                    department_name
                 FROM departments
                 WHERE id = ?
                 LIMIT 1"
            );

        $stmt->bind_param(
            "i",
            $departmentId
        );

        $stmt->execute();

        $department =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$department) {
            adFlash(
                "danger",
                "Department / Office not found."
            );
        }

        $usage =
            adUsageCounts(
                $conn,
                $department["department_name"]
            );

        if (
            adTotalUsage(
                $usage
            ) > 0
        ) {
            adFlash(
                "danger",
                "This Department / Office is already referenced by Workbench records and cannot be permanently deleted. Deactivate it instead."
            );
        }

        $stmt =
            $conn->prepare(
                "DELETE FROM departments
                 WHERE id = ?"
            );

        $stmt->bind_param(
            "i",
            $departmentId
        );

        $stmt->execute();
        $stmt->close();

        adFlash(
            "success",
            "Unused Department / Office permanently deleted."
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

$statusFilter =
    trim(
        (string)(
            $_GET["status"]
            ?? ""
        )
    );

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
| Load Departments
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        id,
        department_name,
        short_name,
        parent_office,
        description,
        status,
        created_at,
        updated_at
     FROM departments
     WHERE 1 = 1
";

$types = "";
$params = [];

if ($search !== "") {
    $sql .= "
        AND (
            department_name LIKE ?
            OR short_name LIKE ?
            OR parent_office LIKE ?
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

if ($statusFilter !== "") {
    $sql .= "
        AND status = ?
    ";

    $types .= "s";
    $params[] =
        $statusFilter;
}

$sql .= "
    ORDER BY
        FIELD(
            status,
            'Active',
            'Inactive'
        ),
        department_name ASC
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

$departments =
    $stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

$stmt->close();

$departmentUsage = [];

foreach ($departments as $row) {
    $usage =
        adUsageCounts(
            $conn,
            $row["department_name"]
        );

    $departmentUsage[
        (int)$row["id"]
    ] = $usage;
}

/*
|--------------------------------------------------------------------------
| KPI
|--------------------------------------------------------------------------
*/

$totalDepartments =
    adScalar(
        $conn,
        "SELECT COUNT(*)
         FROM departments"
    );

$activeDepartments =
    adScalar(
        $conn,
        "SELECT COUNT(*)
         FROM departments
         WHERE status = 'Active'"
    );

$inactiveDepartments =
    adScalar(
        $conn,
        "SELECT COUNT(*)
         FROM departments
         WHERE status = 'Inactive'"
    );

$usersAssigned =
    adScalar(
        $conn,
        "SELECT COUNT(*)
         FROM users
         WHERE
            department IS NOT NULL
            AND department <> ''"
    );

/*
|--------------------------------------------------------------------------
| Flash
|--------------------------------------------------------------------------
*/

$flash =
    $_SESSION["admin_departments_flash"]
    ?? null;

unset(
    $_SESSION["admin_departments_flash"]
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
    Department Management | HPU Workbench
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
    --dep-bg:#f4f7fb;
    --dep-border:#dfe6ef;
    --dep-primary:#1769e0;
    --dep-text:#172033;
    --dep-muted:#667085;
    --dep-shadow:0 8px 24px rgba(28,42,72,.05)
}
body{background:var(--dep-bg);color:var(--dep-text)}
.content{padding:18px}
.container-fluid{max-width:1700px;margin:0 auto}
.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin-bottom:12px}
.kpi-card{padding:11px 12px;border:1px solid var(--dep-border);border-radius:13px;background:#fff;box-shadow:var(--dep-shadow)}
.kpi-label{font-size:.57rem;color:#667085;font-weight:900;text-transform:uppercase;letter-spacing:.04em}
.kpi-value{margin-top:4px;font-size:1.45rem;line-height:1;font-weight:900}
.kpi-note{margin-top:5px;color:#98a2b3;font-size:.59rem}
.panel{border:1px solid var(--dep-border);border-radius:14px;background:#fff;box-shadow:var(--dep-shadow);overflow:hidden}
.panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid var(--hd-row-line,#edf1f6);background:var(--hd-surface,#ffffff)}
.panel-title{margin:0;color:var(--hd-ink,#0f172a);font-size:0.95rem;font-weight:800;letter-spacing:-.015em;line-height:1.25;display:flex;align-items:center;gap:8px}
.panel-title i{color:var(--hd-primary,#2563eb);font-size:1.05em;vertical-align:-1px}
.filter-bar{padding:10px 12px;border-bottom:1px solid #edf1f6;background:#fbfcfe}
.filter-label{display:block;margin-bottom:3px;color:#667085;font-size:.56rem;font-weight:900;text-transform:uppercase;letter-spacing:.04em}
.table-wrap{overflow:auto}
.dep-table{width:100%;border-collapse:collapse}
.dep-table th{padding:8px 10px;background:#f8fafc;border-bottom:1px solid #eaecf0;color:#667085;font-size:.55rem;font-weight:900;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.dep-table td{padding:9px 10px;border-bottom:1px solid #f0f2f5;vertical-align:middle;font-size:.64rem}
.dep-table tbody tr:hover{background:#fbfcfe}
.dep-name{font-size:.68rem;font-weight:900;color:#1d2939}
.dep-meta{margin-top:2px;color:#667085;font-size:.58rem}
.acronym{display:inline-flex;padding:4px 7px;border-radius:7px;background:#eff8ff;color:#175cd3;font-size:.57rem;font-weight:900}
.status-pill{display:inline-flex;align-items:center;gap:4px;padding:4px 7px;border-radius:999px;font-size:.56rem;font-weight:850}
.status-active{background:#ecfdf3;color:#027a48}
.status-inactive{background:#f2f4f7;color:#667085}
.usage{display:flex;gap:5px;flex-wrap:wrap}
.usage-pill{display:inline-flex;align-items:center;gap:4px;padding:4px 6px;border-radius:999px;background:#f8fafc;color:#475467;font-size:.55rem;font-weight:800}
.actions{display:flex;justify-content:flex-end;gap:5px;flex-wrap:wrap}
.actions .btn{font-size:.62rem;font-weight:800}
.modal-content{border-radius:15px}
.usage-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
.usage-stat{padding:10px;border:1px solid #eaecf0;border-radius:10px;background:#f8fafc}
.usage-label{font-size:.54rem;color:#667085;font-weight:850;text-transform:uppercase}
.usage-value{margin-top:3px;font-size:1.05rem;font-weight:900}
.rename-note{padding:9px 10px;border-radius:10px;background:#fffaeb;color:#7a2e0e;font-size:.65rem}
@media(max-width:1000px){.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.usage-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
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
            <i class="bi bi-diagram-3"></i>
        </div>

        <div class="min-w-0">
            <p class="ph-eyebrow">Administration</p>
            <h1 class="ph-title">Department Management</h1>
            <p class="ph-subtitle">
                Maintain the Office / Department directory used by
                accounts, Job Requests and Office Activities.
            </p>
        </div>
    </div>

    <div class="ph-actions">
        <button
            class="btn btn-sm btn-primary"
            type="button"
            data-bs-toggle="modal"
            data-bs-target="#addDepartmentModal"
        >
            <i class="bi bi-building-add me-1"></i>
            Add Department
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
        <div class="kpi-label">Total Departments</div>
        <div class="kpi-value"><?= $totalDepartments ?></div>
        <div class="kpi-note">Directory records</div>
    </div>

    <div class="kpi-card">
        <div class="kpi-label">Active</div>
        <div class="kpi-value text-success"><?= $activeDepartments ?></div>
        <div class="kpi-note">Available for new user/request selection</div>
    </div>

    <div class="kpi-card">
        <div class="kpi-label">Inactive</div>
        <div class="kpi-value text-secondary"><?= $inactiveDepartments ?></div>
        <div class="kpi-note">Retained for historical references</div>
    </div>

    <div class="kpi-card">
        <div class="kpi-label">Users Assigned</div>
        <div class="kpi-value text-primary"><?= $usersAssigned ?></div>
        <div class="kpi-note">Accounts with a Department / Office</div>
    </div>

</div>

<section class="panel">

<div class="panel-head">
    <div class="panel-title">
        <i class="bi bi-buildings me-1 text-primary"></i>
        Department / Office Directory
    </div>

    <div class="small text-muted">
        <?= count($departments) ?> result(s)
    </div>
</div>

<form
    class="filter-bar"
    method="get"
>

<div class="row g-2 align-items-end">

    <div class="col-lg-6">
        <label class="filter-label">Search</label>

        <input
            class="form-control form-control-sm"
            name="q"
            value="<?= e($search) ?>"
            placeholder="Department name, acronym or parent office..."
        >
    </div>

    <div class="col-lg-2">
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
            href="departments.php"
        >
            Reset
        </a>
    </div>

</div>

</form>

<div class="table-wrap">

<table class="dep-table">

<thead>
<tr>
    <th>Department / Office</th>
    <th>Acronym</th>
    <th>Parent Office / Division</th>
    <th>Usage</th>
    <th>Status</th>
    <th class="text-end">Actions</th>
</tr>
</thead>

<tbody>

<?php foreach ($departments as $row): ?>

<?php
$did =
    (int)$row["id"];

$usage =
    $departmentUsage[$did]
    ?? [
        "users" => 0,
        "job_requests" => 0,
        "office_activities" => 0,
        "client_surveys" => 0
    ];

$totalUsage =
    adTotalUsage(
        $usage
    );
?>

<tr>

    <td>
        <div class="dep-name">
            <?= e($row["department_name"]) ?>
        </div>

        <?php if ($row["description"]): ?>
        <div class="dep-meta">
            <?= e(
                mb_strimwidth(
                    $row["description"],
                    0,
                    90,
                    "…"
                )
            ) ?>
        </div>
        <?php endif; ?>
    </td>

    <td>
        <?php if ($row["short_name"]): ?>
            <span class="acronym">
                <?= e($row["short_name"]) ?>
            </span>
        <?php else: ?>
            <span class="text-muted">—</span>
        <?php endif; ?>
    </td>

    <td>
        <?= e($row["parent_office"] ?: "—") ?>
    </td>

    <td>
        <div class="usage">

            <span class="usage-pill">
                <i class="bi bi-people"></i>
                <?= (int)$usage["users"] ?> users
            </span>

            <span class="usage-pill">
                <i class="bi bi-briefcase"></i>
                <?= (int)$usage["job_requests"] ?> jobs
            </span>

            <?php if (adTableExists($conn, "office_activity_requests")): ?>
            <span class="usage-pill">
                <i class="bi bi-calendar-event"></i>
                <?= (int)$usage["office_activities"] ?> activities
            </span>
            <?php endif; ?>

        </div>
    </td>

    <td>
        <span class="status-pill <?= $row["status"] === "Active" ? "status-active" : "status-inactive" ?>">
            <i class="bi <?= $row["status"] === "Active" ? "bi-check-circle" : "bi-pause-circle" ?>"></i>
            <?= e($row["status"]) ?>
        </span>
    </td>

    <td>
        <div class="actions">

            <button
                class="btn btn-sm btn-outline-secondary view-department-btn"
                type="button"
                data-department='<?= e(json_encode([
                    "id" => $did,
                    "department_name" => $row["department_name"],
                    "short_name" => $row["short_name"],
                    "parent_office" => $row["parent_office"],
                    "description" => $row["description"],
                    "status" => $row["status"],
                    "usage" => $usage,
                    "total_usage" => $totalUsage
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
            >
                <i class="bi bi-eye"></i>
                View
            </button>

            <button
                class="btn btn-sm btn-outline-primary edit-department-btn"
                type="button"
                data-department='<?= e(json_encode([
                    "id" => $did,
                    "department_name" => $row["department_name"],
                    "short_name" => $row["short_name"],
                    "parent_office" => $row["parent_office"],
                    "description" => $row["description"],
                    "status" => $row["status"],
                    "total_usage" => $totalUsage
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
            >
                <i class="bi bi-pencil"></i>
                Edit
            </button>

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
                    name="department_id"
                    value="<?= $did ?>"
                >

                <button
                    class="btn btn-sm <?= $row["status"] === "Active" ? "btn-outline-secondary" : "btn-outline-success" ?>"
                    type="submit"
                    onclick="return confirm('<?= $row["status"] === "Active" ? "Deactivate" : "Reactivate" ?> this Department / Office?')"
                >
                    <i class="bi <?= $row["status"] === "Active" ? "bi-pause-circle" : "bi-play-circle" ?>"></i>
                    <?= $row["status"] === "Active" ? "Deactivate" : "Reactivate" ?>
                </button>
            </form>

            <?php if ($totalUsage === 0): ?>

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
                    name="department_id"
                    value="<?= $did ?>"
                >

                <button
                    class="btn btn-sm btn-outline-danger"
                    type="submit"
                    onclick="return confirm('Permanently delete this unused Department / Office?')"
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

</div>

</section>

</div>

</main>

</div>

</div>

<!-- ======================================================
     ADD DEPARTMENT
======================================================= -->

<div
    class="modal fade"
    id="addDepartmentModal"
    tabindex="-1"
>

<div class="modal-dialog modal-lg modal-dialog-centered">

<div class="modal-content">

<form method="post">

<div class="modal-header">
    <div>
        <h5 class="modal-title fw-bold">
            Add Department / Office
        </h5>

        <div class="small text-muted">
            Add a directory entry used throughout the Workbench.
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

    <div class="col-md-8">
        <label class="form-label fw-bold">
            Department / Office Name *
        </label>

        <input
            class="form-control"
            name="department_name"
            maxlength="150"
            required
        >
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold">
            Short Name / Acronym
        </label>

        <input
            class="form-control"
            name="short_name"
            maxlength="60"
            placeholder="e.g. HPU"
        >
    </div>

    <div class="col-12">
        <label class="form-label fw-bold">
            Parent Office / Division
        </label>

        <input
            class="form-control"
            name="parent_office"
            maxlength="180"
            placeholder="e.g. Office of the Medical Center Chief"
        >
    </div>

    <div class="col-12">
        <label class="form-label fw-bold">
            Description
        </label>

        <textarea
            class="form-control"
            name="description"
            rows="3"
        ></textarea>
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
        <i class="bi bi-building-add me-1"></i>
        Add Department
    </button>
</div>

</form>

</div>

</div>

</div>

<!-- ======================================================
     EDIT DEPARTMENT
======================================================= -->

<div
    class="modal fade"
    id="editDepartmentModal"
    tabindex="-1"
>

<div class="modal-dialog modal-lg modal-dialog-centered">

<div class="modal-content">

<form method="post">

<div class="modal-header">
    <div>
        <h5 class="modal-title fw-bold">
            Edit Department / Office
        </h5>

        <div class="small text-muted">
            Renames can also update existing Workbench references.
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
    name="department_id"
    id="editDepartmentId"
>

<div class="row g-3">

    <div class="col-md-8">
        <label class="form-label fw-bold">
            Department / Office Name *
        </label>

        <input
            class="form-control"
            name="department_name"
            id="editDepartmentName"
            required
        >
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold">
            Short Name / Acronym
        </label>

        <input
            class="form-control"
            name="short_name"
            id="editDepartmentShort"
        >
    </div>

    <div class="col-12">
        <label class="form-label fw-bold">
            Parent Office / Division
        </label>

        <input
            class="form-control"
            name="parent_office"
            id="editDepartmentParent"
        >
    </div>

    <div class="col-12">
        <label class="form-label fw-bold">
            Description
        </label>

        <textarea
            class="form-control"
            name="description"
            id="editDepartmentDescription"
            rows="3"
        ></textarea>
    </div>

    <div
        class="col-12"
        id="renameReferenceSection"
    >
        <div class="rename-note">
            <div class="form-check">

                <input
                    class="form-check-input"
                    type="checkbox"
                    name="update_references"
                    value="1"
                    id="updateReferences"
                    checked
                >

                <label
                    class="form-check-label fw-bold"
                    for="updateReferences"
                >
                    Update existing references if the department name changes
                </label>

            </div>

            <div class="mt-1">
                Updates matching names in User accounts, Job Requests,
                Office Activities and Client Experience records.
            </div>
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
        Save Changes
    </button>
</div>

</form>

</div>

</div>

</div>

<!-- ======================================================
     VIEW DEPARTMENT
======================================================= -->

<div
    class="modal fade"
    id="viewDepartmentModal"
    tabindex="-1"
>

<div class="modal-dialog modal-lg modal-dialog-centered">

<div class="modal-content">

<div class="modal-header">
    <div>
        <h5
            class="modal-title fw-bold"
            id="viewDepartmentTitle"
        >
            Department / Office
        </h5>

        <div
            class="small text-muted"
            id="viewDepartmentParent"
        ></div>
    </div>

    <button
        class="btn-close"
        type="button"
        data-bs-dismiss="modal"
    ></button>
</div>

<div class="modal-body">

<div class="mb-3">
    <span
        class="acronym"
        id="viewDepartmentShort"
    ></span>

    <span
        class="status-pill ms-1"
        id="viewDepartmentStatus"
    ></span>
</div>

<p
    class="text-muted small"
    id="viewDepartmentDescription"
></p>

<h6 class="fw-bold mb-2">
    Workbench Usage
</h6>

<div class="usage-grid">

    <div class="usage-stat">
        <div class="usage-label">Users</div>
        <div class="usage-value" id="usageUsers">0</div>
    </div>

    <div class="usage-stat">
        <div class="usage-label">Job Requests</div>
        <div class="usage-value" id="usageJobs">0</div>
    </div>

    <div class="usage-stat">
        <div class="usage-label">Office Activities</div>
        <div class="usage-value" id="usageActivities">0</div>
    </div>

    <div class="usage-stat">
        <div class="usage-label">Client Surveys</div>
        <div class="usage-value" id="usageSurveys">0</div>
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

        const editModal =
            new bootstrap.Modal(
                document.getElementById(
                    "editDepartmentModal"
                )
            );

        const viewModal =
            new bootstrap.Modal(
                document.getElementById(
                    "viewDepartmentModal"
                )
            );

        document
            .querySelectorAll(
                ".edit-department-btn"
            )
            .forEach(
                function (button) {
                    button.addEventListener(
                        "click",
                        function () {
                            const department =
                                JSON.parse(
                                    button.dataset.department
                                );

                            document.getElementById("editDepartmentId").value =
                                department.id;

                            document.getElementById("editDepartmentName").value =
                                department.department_name || "";

                            document.getElementById("editDepartmentShort").value =
                                department.short_name || "";

                            document.getElementById("editDepartmentParent").value =
                                department.parent_office || "";

                            document.getElementById("editDepartmentDescription").value =
                                department.description || "";

                            document.getElementById("updateReferences").checked =
                                true;

                            editModal.show();
                        }
                    );
                }
            );

        document
            .querySelectorAll(
                ".view-department-btn"
            )
            .forEach(
                function (button) {
                    button.addEventListener(
                        "click",
                        function () {
                            const department =
                                JSON.parse(
                                    button.dataset.department
                                );

                            const usage =
                                department.usage || {};

                            document.getElementById("viewDepartmentTitle").textContent =
                                department.department_name || "Department / Office";

                            document.getElementById("viewDepartmentParent").textContent =
                                department.parent_office || "No parent office specified";

                            const shortElement =
                                document.getElementById(
                                    "viewDepartmentShort"
                                );

                            shortElement.textContent =
                                department.short_name || "No acronym";

                            const statusElement =
                                document.getElementById(
                                    "viewDepartmentStatus"
                                );

                            statusElement.textContent =
                                department.status || "Active";

                            statusElement.className =
                                "status-pill ms-1 "
                                + (
                                    department.status === "Active"
                                        ? "status-active"
                                        : "status-inactive"
                                  );

                            document.getElementById("viewDepartmentDescription").textContent =
                                department.description || "No description provided.";

                            document.getElementById("usageUsers").textContent =
                                usage.users || 0;

                            document.getElementById("usageJobs").textContent =
                                usage.job_requests || 0;

                            document.getElementById("usageActivities").textContent =
                                usage.office_activities || 0;

                            document.getElementById("usageSurveys").textContent =
                                usage.client_surveys || 0;

                            viewModal.show();
                        }
                    );
                }
            );

    }
);
</script>

</body>
</html>
