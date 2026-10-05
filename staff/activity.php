<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";
include "../includes/activity_log.php";

requireRole([
    "staff",
    "supervisor",
    "admin"
]);
requireModule($conn, "review_requests");

$requestId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

if (!$requestId) {
    die("Invalid request ID.");
}

$stmt = $conn->prepare(
    "SELECT
        id,
        job_no,
        project_title,
        requestor_name,
        department,
        approval_status
     FROM job_requests
     WHERE id = ?
     LIMIT 1"
);

$stmt->bind_param("i", $requestId);
$stmt->execute();

$request = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if (!$request) {
    die("Job request not found.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Timeline | <?= e($request["job_no"]) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
</head>
<body class="app-shell">
<div class="d-flex min-vh-100">
    <?php include "../includes/sidebar.php"; ?>

    <div class="main flex-grow-1">
        <main class="content">
            <div class="container-fluid">

                <div class="card mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                            <div>
                                <div class="text-muted small">
                                    <?= e($request["job_no"]) ?>
                                </div>
                                <h2 class="mb-1">
                                    <?= e($request["project_title"]) ?>
                                </h2>
                                <div class="text-muted">
                                    <?= e($request["requestor_name"]) ?>
                                    ·
                                    <?= e($request["department"]) ?>
                                </div>
                            </div>

                            <span class="badge bg-primary">
                                <?= e($request["approval_status"]) ?>
                            </span>
                        </div>
                    </div>
                </div>

                <?php include "../includes/activity_timeline.php"; ?>

                <a class="btn btn-outline-secondary mb-4" href="javascript:history.back();">
                    <i class="bi bi-arrow-left me-1"></i>
                    Back
                </a>

            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
