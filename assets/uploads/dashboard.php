<?php
include "includes/config.php";
include "includes/auth.php";
include "includes/helpers.php";

checkLogin();

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

$userId = (int) ($_SESSION["user_id"] ?? 0);
$role = $_SESSION["role"] ?? "user";

$showAllRequests = in_array(
    $role,
    ["admin", "staff", "supervisor"],
    true
);

/* =========================================================
   HELPER FUNCTIONS
========================================================= */
function dashboardStatusCount(
    mysqli $conn,
    string $status,
    int $userId,
    bool $showAllRequests
): int {
    if ($showAllRequests) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM job_requests
             WHERE approval_status = ?"
        );

        $stmt->bind_param(
            "s",
            $status
        );
    } else {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM job_requests
             WHERE requestor = ?
               AND approval_status = ?"
        );

        $stmt->bind_param(
            "is",
            $userId,
            $status
        );
    }

    $stmt->execute();

    $row = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    return (int) (
        $row["total"] ?? 0
    );
}

function dashboardTotalCount(
    mysqli $conn,
    int $userId,
    bool $showAllRequests
): int {
    if ($showAllRequests) {
        $result = $conn->query(
            "SELECT COUNT(*) AS total
             FROM job_requests"
        );

        $row = $result->fetch_assoc();
    } else {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM job_requests
             WHERE requestor = ?"
        );

        $stmt->bind_param(
            "i",
            $userId
        );

        $stmt->execute();

        $row = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();
    }

    return (int) (
        $row["total"] ?? 0
    );
}

function dashboardStatusClass(
    string $status
): string {
    return match ($status) {
        "Pending Review" =>
            "status-pending",

        "Under Review" =>
            "status-review",

        "Approved" =>
            "status-approved",

        "For Revision" =>
            "status-revision",

        "Disapproved" =>
            "status-disapproved",

        "Completed" =>
            "status-completed",

        "For Evaluation" =>
            "status-evaluation",

        "Closed" =>
            "status-closed",

        default =>
            "status-default"
    };
}

function dashboardStatusIcon(
    string $status
): string {
    return match ($status) {
        "Pending Review" =>
            "bi-hourglass-split",

        "Under Review" =>
            "bi-search",

        "Approved" =>
            "bi-check-circle-fill",

        "For Revision" =>
            "bi-arrow-repeat",

        "Disapproved" =>
            "bi-x-circle-fill",

        "Completed" =>
            "bi-check2-all",

        "For Evaluation" =>
            "bi-clipboard2-check",

        "Closed" =>
            "bi-lock-fill",

        default =>
            "bi-info-circle"
    };
}

/* =========================================================
   SUMMARY COUNTS
========================================================= */
$totalRequests = dashboardTotalCount(
    $conn,
    $userId,
    $showAllRequests
);

$pending = dashboardStatusCount(
    $conn,
    "Pending Review",
    $userId,
    $showAllRequests
);

$underReview = dashboardStatusCount(
    $conn,
    "Under Review",
    $userId,
    $showAllRequests
);

$approved = dashboardStatusCount(
    $conn,
    "Approved",
    $userId,
    $showAllRequests
);

$forRevision = dashboardStatusCount(
    $conn,
    "For Revision",
    $userId,
    $showAllRequests
);

$completed = dashboardStatusCount(
    $conn,
    "Completed",
    $userId,
    $showAllRequests
);

$forEvaluation = dashboardStatusCount(
    $conn,
    "For Evaluation",
    $userId,
    $showAllRequests
);

$closed = dashboardStatusCount(
    $conn,
    "Closed",
    $userId,
    $showAllRequests
);

/* =========================================================
   AVERAGE SATISFACTION
========================================================= */
if ($showAllRequests) {
    $ratingResult = $conn->query(
        "SELECT AVG(satisfaction_rating) AS average_rating
         FROM job_requests
         WHERE satisfaction_rating IS NOT NULL"
    );

    $ratingRow = $ratingResult->fetch_assoc();
} else {
    $ratingStmt = $conn->prepare(
        "SELECT AVG(satisfaction_rating) AS average_rating
         FROM job_requests
         WHERE requestor = ?
           AND satisfaction_rating IS NOT NULL"
    );

    $ratingStmt->bind_param(
        "i",
        $userId
    );

    $ratingStmt->execute();

    $ratingRow = $ratingStmt
        ->get_result()
        ->fetch_assoc();

    $ratingStmt->close();
}

$averageRating = round(
    (float) (
        $ratingRow["average_rating"] ?? 0
    ),
    1
);

/* =========================================================
   MONTHLY REQUESTS - LAST 6 MONTHS
========================================================= */
$monthlyLabels = [];
$monthlyValues = [];

for ($monthOffset = 5; $monthOffset >= 0; $monthOffset--) {
    $monthDate = new DateTime(
        "first day of -{$monthOffset} month"
    );

    $monthKey = $monthDate->format("Y-m");
    $monthLabel = $monthDate->format("M Y");

    $monthlyLabels[] = $monthLabel;

    if ($showAllRequests) {
        $monthStmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM job_requests
             WHERE DATE_FORMAT(created_at, '%Y-%m') = ?"
        );

        $monthStmt->bind_param(
            "s",
            $monthKey
        );
    } else {
        $monthStmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM job_requests
             WHERE requestor = ?
               AND DATE_FORMAT(created_at, '%Y-%m') = ?"
        );

        $monthStmt->bind_param(
            "is",
            $userId,
            $monthKey
        );
    }

    $monthStmt->execute();

    $monthRow = $monthStmt
        ->get_result()
        ->fetch_assoc();

    $monthStmt->close();

    $monthlyValues[] = (int) (
        $monthRow["total"] ?? 0
    );
}

/* =========================================================
   REQUEST TYPE COUNTS
========================================================= */
$requestTypeNames = [
    "Graphic Design",
    "Video Production",
    "Photo/Video Coverage",
    "IEC Materials"
];

$requestTypeCounts = [];

foreach ($requestTypeNames as $requestTypeName) {
    $likeValue =
        "%" .
        $requestTypeName .
        "%";

    if ($showAllRequests) {
        $typeStmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM job_requests
             WHERE request_types LIKE ?"
        );

        $typeStmt->bind_param(
            "s",
            $likeValue
        );
    } else {
        $typeStmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM job_requests
             WHERE requestor = ?
               AND request_types LIKE ?"
        );

        $typeStmt->bind_param(
            "is",
            $userId,
            $likeValue
        );
    }

    $typeStmt->execute();

    $typeRow = $typeStmt
        ->get_result()
        ->fetch_assoc();

    $typeStmt->close();

    $requestTypeCounts[] = (int) (
        $typeRow["total"] ?? 0
    );
}

/* =========================================================
   DEPARTMENT REQUESTS
========================================================= */
$departmentLabels = [];
$departmentValues = [];

if ($showAllRequests) {
    $departmentResult = $conn->query(
        "SELECT
            department,
            COUNT(*) AS total
         FROM job_requests
         WHERE department IS NOT NULL
           AND department <> ''
         GROUP BY department
         ORDER BY total DESC
         LIMIT 6"
    );

    while (
        $departmentRow =
        $departmentResult->fetch_assoc()
    ) {
        $departmentLabels[] =
            $departmentRow["department"];

        $departmentValues[] =
            (int) $departmentRow["total"];
    }
}

/* =========================================================
   RECENT REQUESTS
========================================================= */
if ($showAllRequests) {
    $recentStmt = $conn->prepare(
        "SELECT
            id,
            job_no,
            project_title,
            requestor_name,
            department,
            approval_status,
            date_request
         FROM job_requests
         ORDER BY id DESC
         LIMIT 6"
    );
} else {
    $recentStmt = $conn->prepare(
        "SELECT
            id,
            job_no,
            project_title,
            requestor_name,
            department,
            approval_status,
            date_request
         FROM job_requests
         WHERE requestor = ?
         ORDER BY id DESC
         LIMIT 6"
    );

    $recentStmt->bind_param(
        "i",
        $userId
    );
}

$recentStmt->execute();

$recentRequests = $recentStmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$recentStmt->close();

/* =========================================================
   UPCOMING DEADLINES
========================================================= */
if ($showAllRequests) {
    $deadlineStmt = $conn->prepare(
        "SELECT
            id,
            job_no,
            project_title,
            date_completion,
            approval_status
         FROM job_requests
         WHERE date_completion IS NOT NULL
           AND date_completion >= CURDATE()
           AND approval_status NOT IN ('Closed', 'Disapproved')
         ORDER BY date_completion ASC
         LIMIT 5"
    );
} else {
    $deadlineStmt = $conn->prepare(
        "SELECT
            id,
            job_no,
            project_title,
            date_completion,
            approval_status
         FROM job_requests
         WHERE requestor = ?
           AND date_completion IS NOT NULL
           AND date_completion >= CURDATE()
           AND approval_status NOT IN ('Closed', 'Disapproved')
         ORDER BY date_completion ASC
         LIMIT 5"
    );

    $deadlineStmt->bind_param(
        "i",
        $userId
    );
}

$deadlineStmt->execute();

$upcomingDeadlines = $deadlineStmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$deadlineStmt->close();

/* =========================================================
   NOTIFICATION COUNT
========================================================= */
$unreadNotifications = 0;

$tableCheck = $conn->query(
    "SHOW TABLES LIKE 'notifications'"
);

if (
    $tableCheck &&
    $tableCheck->num_rows > 0
) {
    $notificationStmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM notifications
         WHERE user_id = ?
           AND is_read = 0"
    );

    $notificationStmt->bind_param(
        "i",
        $userId
    );

    $notificationStmt->execute();

    $notificationRow = $notificationStmt
        ->get_result()
        ->fetch_assoc();

    $notificationStmt->close();

    $unreadNotifications = (int) (
        $notificationRow["total"] ?? 0
    );
}
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
        Dashboard | HPU Workbench
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

    <style>
        .dashboard-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .dashboard-header h2 {
            margin-bottom: 4px;
            font-weight: 800;
        }

        .role-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 12px;
            border-radius: 999px;
            background: #e0e7ff;
            color: #3730a3;
            font-size: 12px;
            font-weight: 800;
            text-transform: capitalize;
        }

        .summary-card {
            position: relative;
            overflow: hidden;
            min-height: 142px;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: #fff;
            transition:
                transform .18s ease,
                box-shadow .18s ease;
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 32px rgba(15, 23, 42, .08);
        }

        .summary-card::after {
            position: absolute;
            right: -18px;
            bottom: -32px;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(37, 99, 235, .05);
            content: "";
        }

        .summary-icon {
            display: grid;
            width: 46px;
            height: 46px;
            place-items: center;
            border-radius: 13px;
            font-size: 21px;
        }

        .icon-total {
            background: #e0e7ff;
            color: #3730a3;
        }

        .icon-pending {
            background: #f1f5f9;
            color: #475569;
        }

        .icon-review {
            background: #cffafe;
            color: #155e75;
        }

        .icon-approved {
            background: #dcfce7;
            color: #166534;
        }

        .icon-completed {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .icon-evaluation {
            background: #ede9fe;
            color: #6d28d9;
        }

        .summary-label {
            margin-top: 15px;
            color: #64748b;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .summary-value {
            margin-top: 3px;
            font-size: 31px;
            font-weight: 800;
        }

        .dashboard-card-title {
            display: flex;
            align-items: center;
            gap: 9px;
            margin-bottom: 18px;
            font-size: 18px;
            font-weight: 800;
        }

        .dashboard-card-title i {
            color: #2563eb;
        }

        .chart-container {
            position: relative;
            min-height: 290px;
        }

        .quick-action {
            display: flex;
            align-items: center;
            gap: 12px;
            height: 100%;
            padding: 16px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
            color: #0f172a;
            text-decoration: none;
            transition:
                transform .18s ease,
                border-color .18s ease,
                background .18s ease;
        }

        .quick-action:hover {
            transform: translateY(-2px);
            border-color: #2563eb;
            background: #eff6ff;
            color: #1d4ed8;
        }

        .quick-action-icon {
            display: grid;
            width: 42px;
            height: 42px;
            place-items: center;
            flex-shrink: 0;
            border-radius: 11px;
            background: #2563eb;
            color: #fff;
            font-size: 18px;
        }

        .quick-action strong {
            display: block;
        }

        .quick-action small {
            color: #64748b;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
        }

        .status-pending {
            background: #f1f5f9;
            color: #475569;
        }

        .status-review {
            background: #cffafe;
            color: #155e75;
        }

        .status-approved {
            background: #dcfce7;
            color: #166534;
        }

        .status-revision {
            background: #fef3c7;
            color: #92400e;
        }

        .status-disapproved {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-completed {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-evaluation {
            background: #ede9fe;
            color: #6d28d9;
        }

        .status-closed {
            background: #e2e8f0;
            color: #0f172a;
        }

        .status-default {
            background: #f1f5f9;
            color: #475569;
        }

        .recent-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 14px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .recent-item:last-child {
            border-bottom: 0;
        }

        .recent-job {
            font-size: 12px;
            font-weight: 800;
            color: #2563eb;
        }

        .recent-project {
            font-weight: 700;
        }

        .recent-meta {
            color: #64748b;
            font-size: 12px;
        }

        .deadline-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .deadline-item:last-child {
            border-bottom: 0;
        }

        .deadline-date {
            display: grid;
            width: 50px;
            min-width: 50px;
            height: 50px;
            place-items: center;
            border-radius: 11px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 11px;
            font-weight: 800;
            text-align: center;
        }

        .rating-box {
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 18px;
            border-radius: 14px;
            background: #fffbeb;
        }

        .rating-value {
            font-size: 38px;
            font-weight: 800;
            color: #92400e;
        }

        .rating-stars {
            color: #f59e0b;
            letter-spacing: 2px;
        }

        .empty-small {
            padding: 24px 0;
            color: #64748b;
            text-align: center;
        }

        @media (max-width: 767px) {
            .recent-item {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>

</head>

<body class="app-shell">

<div class="d-flex min-vh-100">

    <?php include "includes/sidebar.php"; ?>

    <div class="main flex-grow-1">

        <main class="content">

            <div class="container-fluid">

                <!-- HEADER -->
                <div class="dashboard-header">

                    <div>

                        <h2>
                            Welcome back,
                            <?= e(
                                $_SESSION["name"] ?? "User"
                            ) ?>
                        </h2>

                        <p class="text-muted mb-2">
                            <?= e(
                                $_SESSION["department"]
                                ?? "No department assigned"
                            ) ?>
                        </p>

                        <span class="role-badge">
                            <i class="bi bi-person-badge"></i>
                            <?= e($role) ?> Portal
                        </span>

                    </div>

                    <?php if (
                        $role === "user"
                    ): ?>

                        <a
                            class="btn btn-primary"
                            href="<?= e(BASE_URL) ?>/user/new_request.php"
                        >
                            <i class="bi bi-plus-circle me-1"></i>
                            New Request
                        </a>

                    <?php elseif (
                        in_array(
                            $role,
                            ["staff", "supervisor"],
                            true
                        )
                    ): ?>

                        <a
                            class="btn btn-primary"
                            href="<?= e(BASE_URL) ?>/staff/requests.php"
                        >
                            <i class="bi bi-clipboard-check me-1"></i>
                            Open Request Queue
                        </a>

                    <?php elseif (
                        $role === "admin"
                    ): ?>

                        <a
                            class="btn btn-primary"
                            href="<?= e(BASE_URL) ?>/admin/requests.php"
                        >
                            <i class="bi bi-briefcase me-1"></i>
                            Manage Requests
                        </a>

                    <?php endif; ?>

                </div>

                <!-- SUMMARY CARDS -->
                <div class="row g-3 mb-4">

                    <div class="col-6 col-md-4 col-xl-2">

                        <div class="summary-card p-3">

                            <div class="summary-icon icon-total">
                                <i class="bi bi-files"></i>
                            </div>

                            <div class="summary-label">
                                Total Requests
                            </div>

                            <div class="summary-value">
                                <?= $totalRequests ?>
                            </div>

                        </div>

                    </div>

                    <div class="col-6 col-md-4 col-xl-2">

                        <div class="summary-card p-3">

                            <div class="summary-icon icon-pending">
                                <i class="bi bi-hourglass-split"></i>
                            </div>

                            <div class="summary-label">
                                Pending Review
                            </div>

                            <div class="summary-value">
                                <?= $pending ?>
                            </div>

                        </div>

                    </div>

                    <div class="col-6 col-md-4 col-xl-2">

                        <div class="summary-card p-3">

                            <div class="summary-icon icon-review">
                                <i class="bi bi-search"></i>
                            </div>

                            <div class="summary-label">
                                Under Review
                            </div>

                            <div class="summary-value">
                                <?= $underReview ?>
                            </div>

                        </div>

                    </div>

                    <div class="col-6 col-md-4 col-xl-2">

                        <div class="summary-card p-3">

                            <div class="summary-icon icon-approved">
                                <i class="bi bi-check-circle"></i>
                            </div>

                            <div class="summary-label">
                                Approved
                            </div>

                            <div class="summary-value">
                                <?= $approved ?>
                            </div>

                        </div>

                    </div>

                    <div class="col-6 col-md-4 col-xl-2">

                        <div class="summary-card p-3">

                            <div class="summary-icon icon-completed">
                                <i class="bi bi-check2-all"></i>
                            </div>

                            <div class="summary-label">
                                Completed
                            </div>

                            <div class="summary-value">
                                <?= $completed ?>
                            </div>

                        </div>

                    </div>

                    <div class="col-6 col-md-4 col-xl-2">

                        <div class="summary-card p-3">

                            <div class="summary-icon icon-evaluation">
                                <i class="bi bi-clipboard2-check"></i>
                            </div>

                            <div class="summary-label">
                                For Evaluation
                            </div>

                            <div class="summary-value">
                                <?= $forEvaluation ?>
                            </div>

                        </div>

                    </div>

                </div>

                <!-- QUICK ACTIONS -->
                <section class="card mb-4">

                    <div class="card-body">

                        <div class="dashboard-card-title">
                            <i class="bi bi-lightning-charge"></i>
                            Quick Actions
                        </div>

                        <div class="row g-3">

                            <?php if (
                                in_array(
                                    $role,
                                    [
                                        "user",
                                        "staff",
                                        "supervisor"
                                    ],
                                    true
                                )
                            ): ?>

                                <div class="col-md-6 col-xl-3">

                                    <a
                                        class="quick-action"
                                        href="<?= e(BASE_URL) ?>/user/new_request.php"
                                    >
                                        <span class="quick-action-icon">
                                            <i class="bi bi-plus-circle"></i>
                                        </span>

                                        <span>
                                            <strong>New Request</strong>
                                            <small>Create a job request</small>
                                        </span>
                                    </a>

                                </div>

                                <div class="col-md-6 col-xl-3">

                                    <a
                                        class="quick-action"
                                        href="<?= e(BASE_URL) ?>/user/my_requests.php"
                                    >
                                        <span class="quick-action-icon">
                                            <i class="bi bi-card-checklist"></i>
                                        </span>

                                        <span>
                                            <strong>My Requests</strong>
                                            <small>Track submitted work</small>
                                        </span>
                                    </a>

                                </div>

                            <?php endif; ?>

                            <?php if (
                                in_array(
                                    $role,
                                    ["staff", "supervisor"],
                                    true
                                )
                            ): ?>

                                <div class="col-md-6 col-xl-3">

                                    <a
                                        class="quick-action"
                                        href="<?= e(BASE_URL) ?>/staff/requests.php"
                                    >
                                        <span class="quick-action-icon">
                                            <i class="bi bi-clipboard-check"></i>
                                        </span>

                                        <span>
                                            <strong>Review Queue</strong>
                                            <small>Review and monitor requests</small>
                                        </span>
                                    </a>

                                </div>

                            <?php endif; ?>

                            <?php if (
                                $role === "admin"
                            ): ?>

                                <div class="col-md-6 col-xl-3">

                                    <a
                                        class="quick-action"
                                        href="<?= e(BASE_URL) ?>/admin/requests.php"
                                    >
                                        <span class="quick-action-icon">
                                            <i class="bi bi-briefcase"></i>
                                        </span>

                                        <span>
                                            <strong>Manage Requests</strong>
                                            <small>Open all job requests</small>
                                        </span>
                                    </a>

                                </div>

                                <div class="col-md-6 col-xl-3">

                                    <a
                                        class="quick-action"
                                        href="<?= e(BASE_URL) ?>/admin/users.php"
                                    >
                                        <span class="quick-action-icon">
                                            <i class="bi bi-people"></i>
                                        </span>

                                        <span>
                                            <strong>User Management</strong>
                                            <small>Add and update users</small>
                                        </span>
                                    </a>

                                </div>

                                <div class="col-md-6 col-xl-3">

                                    <a
                                        class="quick-action"
                                        href="<?= e(BASE_URL) ?>/admin/departments.php"
                                    >
                                        <span class="quick-action-icon">
                                            <i class="bi bi-building"></i>
                                        </span>

                                        <span>
                                            <strong>Departments</strong>
                                            <small>Manage department list</small>
                                        </span>
                                    </a>

                                </div>

                            <?php endif; ?>

                            <?php if (
                                $role === "user"
                            ): ?>

                                <div class="col-md-6 col-xl-3">

                                    <a
                                        class="quick-action"
                                        href="<?= e(BASE_URL) ?>/user/notifications.php"
                                    >
                                        <span class="quick-action-icon">
                                            <i class="bi bi-bell"></i>
                                        </span>

                                        <span>
                                            <strong>Notifications</strong>
                                            <small>
                                                <?= $unreadNotifications ?>
                                                unread notification(s)
                                            </small>
                                        </span>
                                    </a>

                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                </section>

                <!-- CHARTS -->
                <div class="row g-4 mb-4">

                    <div class="col-xl-7">

                        <section class="card h-100">

                            <div class="card-body">

                                <div class="dashboard-card-title">
                                    <i class="bi bi-bar-chart"></i>
                                    Monthly Requests
                                </div>

                                <div class="chart-container">
                                    <canvas id="monthlyRequestsChart"></canvas>
                                </div>

                            </div>

                        </section>

                    </div>

                    <div class="col-xl-5">

                        <section class="card h-100">

                            <div class="card-body">

                                <div class="dashboard-card-title">
                                    <i class="bi bi-pie-chart"></i>
                                    Request Status
                                </div>

                                <div class="chart-container">
                                    <canvas id="statusChart"></canvas>
                                </div>

                            </div>

                        </section>

                    </div>

                </div>

                <div class="row g-4 mb-4">

                    <div class="col-xl-6">

                        <section class="card h-100">

                            <div class="card-body">

                                <div class="dashboard-card-title">
                                    <i class="bi bi-grid-3x3-gap"></i>
                                    Request Types
                                </div>

                                <div class="chart-container">
                                    <canvas id="requestTypeChart"></canvas>
                                </div>

                            </div>

                        </section>

                    </div>

                    <div class="col-xl-6">

                        <section class="card h-100">

                            <div class="card-body">

                                <div class="dashboard-card-title">
                                    <i class="bi bi-building"></i>
                                    <?= $showAllRequests
                                        ? "Top Departments"
                                        : "Satisfaction Summary"
                                    ?>
                                </div>

                                <?php if (
                                    $showAllRequests
                                ): ?>

                                    <?php if (
                                        !empty(
                                            $departmentLabels
                                        )
                                    ): ?>

                                        <div class="chart-container">
                                            <canvas id="departmentChart"></canvas>
                                        </div>

                                    <?php else: ?>

                                        <div class="empty-small">
                                            No department data available.
                                        </div>

                                    <?php endif; ?>

                                <?php else: ?>

                                    <div class="rating-box">

                                        <div class="rating-value">
                                            <?= number_format(
                                                $averageRating,
                                                1
                                            ) ?>
                                        </div>

                                        <div>
                                            <div class="rating-stars">
                                                ★★★★★
                                            </div>

                                            <div class="fw-bold">
                                                Average Satisfaction
                                            </div>

                                            <small class="text-muted">
                                                Based on submitted evaluations
                                            </small>
                                        </div>

                                    </div>

                                <?php endif; ?>

                            </div>

                        </section>

                    </div>

                </div>

                <!-- RECENT REQUESTS / DEADLINES -->
                <div class="row g-4 mb-4">

                    <div class="col-xl-7">

                        <section class="card h-100">

                            <div class="card-body">

                                <div
                                    class="d-flex justify-content-between align-items-center gap-3 mb-2"
                                >

                                    <div class="dashboard-card-title mb-0">
                                        <i class="bi bi-clock-history"></i>
                                        Recent Requests
                                    </div>

                                    <a
                                        class="btn btn-sm btn-outline-primary"
                                        href="<?= e(BASE_URL) ?>/<?= $showAllRequests
                                            ? (
                                                $role === "admin"
                                                    ? "admin/requests.php"
                                                    : "staff/requests.php"
                                            )
                                            : "user/my_requests.php"
                                        ?>"
                                    >
                                        View All
                                    </a>

                                </div>

                                <?php if (
                                    !empty(
                                        $recentRequests
                                    )
                                ): ?>

                                    <?php foreach (
                                        $recentRequests as $recentRequest
                                    ): ?>

                                        <div class="recent-item">

                                            <div>

                                                <div class="recent-job">
                                                    <?= e(
                                                        $recentRequest[
                                                            "job_no"
                                                        ]
                                                    ) ?>
                                                </div>

                                                <div class="recent-project">
                                                    <?= e(
                                                        $recentRequest[
                                                            "project_title"
                                                        ]
                                                    ) ?>
                                                </div>

                                                <div class="recent-meta">
                                                    <?= e(
                                                        $recentRequest[
                                                            "requestor_name"
                                                        ]
                                                    ) ?>

                                                    ·

                                                    <?= e(
                                                        $recentRequest[
                                                            "department"
                                                        ]
                                                    ) ?>
                                                </div>

                                            </div>

                                            <div class="text-end">

                                                <span
                                                    class="status-badge <?= dashboardStatusClass(
                                                        $recentRequest[
                                                            "approval_status"
                                                        ]
                                                    ) ?>"
                                                >
                                                    <i
                                                        class="bi <?= dashboardStatusIcon(
                                                            $recentRequest[
                                                                "approval_status"
                                                            ]
                                                        ) ?>"
                                                    ></i>

                                                    <?= e(
                                                        $recentRequest[
                                                            "approval_status"
                                                        ]
                                                    ) ?>
                                                </span>

                                                <div class="mt-2">

                                                    <a
                                                        class="btn btn-sm btn-outline-primary"
                                                        href="<?= e(BASE_URL) ?>/admin/view.php?id=<?= (int) $recentRequest["id"] ?>"
                                                    >
                                                        View
                                                    </a>

                                                </div>

                                            </div>

                                        </div>

                                    <?php endforeach; ?>

                                <?php else: ?>

                                    <div class="empty-small">
                                        No requests available.
                                    </div>

                                <?php endif; ?>

                            </div>

                        </section>

                    </div>

                    <div class="col-xl-5">

                        <section class="card h-100">

                            <div class="card-body">

                                <div class="dashboard-card-title">
                                    <i class="bi bi-calendar-event"></i>
                                    Upcoming Deadlines
                                </div>

                                <?php if (
                                    !empty(
                                        $upcomingDeadlines
                                    )
                                ): ?>

                                    <?php foreach (
                                        $upcomingDeadlines as $deadline
                                    ): ?>

                                        <?php
                                        $deadlineDate = new DateTime(
                                            $deadline[
                                                "date_completion"
                                            ]
                                        );
                                        ?>

                                        <div class="deadline-item">

                                            <div class="deadline-date">
                                                <?= $deadlineDate->format("M") ?>
                                                <br>
                                                <?= $deadlineDate->format("d") ?>
                                            </div>

                                            <div class="flex-grow-1">

                                                <div class="fw-bold">
                                                    <?= e(
                                                        $deadline[
                                                            "project_title"
                                                        ]
                                                    ) ?>
                                                </div>

                                                <small class="text-muted">
                                                    <?= e(
                                                        $deadline[
                                                            "job_no"
                                                        ]
                                                    ) ?>
                                                </small>

                                            </div>

                                            <span
                                                class="status-badge <?= dashboardStatusClass(
                                                    $deadline[
                                                        "approval_status"
                                                    ]
                                                ) ?>"
                                            >
                                                <?= e(
                                                    $deadline[
                                                        "approval_status"
                                                    ]
                                                ) ?>
                                            </span>

                                        </div>

                                    <?php endforeach; ?>

                                <?php else: ?>

                                    <div class="empty-small">
                                        No upcoming deadlines.
                                    </div>

                                <?php endif; ?>

                            </div>

                        </section>

                    </div>

                </div>

                <!-- PERFORMANCE -->
                <div class="row g-3 mb-4">

                    <div class="col-md-6 col-xl-3">

                        <div class="card h-100">

                            <div class="card-body">

                                <div class="text-muted small fw-bold">
                                    FOR REVISION
                                </div>

                                <div class="fs-2 fw-bold mt-2">
                                    <?= $forRevision ?>
                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="col-md-6 col-xl-3">

                        <div class="card h-100">

                            <div class="card-body">

                                <div class="text-muted small fw-bold">
                                    CLOSED REQUESTS
                                </div>

                                <div class="fs-2 fw-bold mt-2">
                                    <?= $closed ?>
                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="col-md-6 col-xl-3">

                        <div class="card h-100">

                            <div class="card-body">

                                <div class="text-muted small fw-bold">
                                    COMPLETION RATE
                                </div>

                                <?php
                                $completionRate =
                                    $totalRequests > 0
                                        ? round(
                                            (
                                                (
                                                    $completed +
                                                    $closed
                                                ) /
                                                $totalRequests
                                            ) *
                                            100,
                                            1
                                        )
                                        : 0;
                                ?>

                                <div class="fs-2 fw-bold mt-2">
                                    <?= $completionRate ?>%
                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="col-md-6 col-xl-3">

                        <div class="card h-100">

                            <div class="card-body">

                                <div class="text-muted small fw-bold">
                                    AVERAGE RATING
                                </div>

                                <div class="fs-2 fw-bold mt-2">
                                    <?= number_format(
                                        $averageRating,
                                        1
                                    ) ?>
                                    / 5
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </main>

    </div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
></script>

<script
    src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"
></script>

<script>
document.addEventListener(
    "DOMContentLoaded",
    function () {

        const monthlyCanvas =
            document.getElementById(
                "monthlyRequestsChart"
            );

        if (monthlyCanvas) {
            new Chart(
                monthlyCanvas,
                {
                    type: "bar",
                    data: {
                        labels: <?= json_encode(
                            $monthlyLabels
                        ) ?>,
                        datasets: [
                            {
                                label: "Requests",
                                data: <?= json_encode(
                                    $monthlyValues
                                ) ?>,
                                borderWidth: 1,
                                borderRadius: 8
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0
                                }
                            }
                        }
                    }
                }
            );
        }

        const statusCanvas =
            document.getElementById(
                "statusChart"
            );

        if (statusCanvas) {
            new Chart(
                statusCanvas,
                {
                    type: "doughnut",
                    data: {
                        labels: [
                            "Pending",
                            "Under Review",
                            "Approved",
                            "Completed",
                            "For Evaluation",
                            "Closed"
                        ],
                        datasets: [
                            {
                                data: [
                                    <?= $pending ?>,
                                    <?= $underReview ?>,
                                    <?= $approved ?>,
                                    <?= $completed ?>,
                                    <?= $forEvaluation ?>,
                                    <?= $closed ?>
                                ],
                                borderWidth: 2
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: "62%"
                    }
                }
            );
        }

        const typeCanvas =
            document.getElementById(
                "requestTypeChart"
            );

        if (typeCanvas) {
            new Chart(
                typeCanvas,
                {
                    type: "bar",
                    data: {
                        labels: <?= json_encode(
                            $requestTypeNames
                        ) ?>,
                        datasets: [
                            {
                                label: "Requests",
                                data: <?= json_encode(
                                    $requestTypeCounts
                                ) ?>,
                                borderWidth: 1,
                                borderRadius: 8
                            }
                        ]
                    },
                    options: {
                        indexAxis: "y",
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0
                                }
                            }
                        }
                    }
                }
            );
        }

        const departmentCanvas =
            document.getElementById(
                "departmentChart"
            );

        if (departmentCanvas) {
            new Chart(
                departmentCanvas,
                {
                    type: "bar",
                    data: {
                        labels: <?= json_encode(
                            $departmentLabels
                        ) ?>,
                        datasets: [
                            {
                                label: "Requests",
                                data: <?= json_encode(
                                    $departmentValues
                                ) ?>,
                                borderWidth: 1,
                                borderRadius: 8
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0
                                }
                            }
                        }
                    }
                }
            );
        }
    }
);
</script>

</body>
</html>
