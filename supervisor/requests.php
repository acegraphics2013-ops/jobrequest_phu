<?php
include "../includes/config.php";
include "../includes/auth.php";
require_once "../includes/module_access.php";

requireRole(['supervisor', 'admin']);
requireModule($conn, "review_requests");

// NOTE: Destructive delete via GET (?delete=) was removed here.
// Use staff/delete_request.php (POST + CSRF + transaction) for deletes.

$result = $conn->query("SELECT * FROM job_requests ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Job Requests</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="app-shell">

<div class="sidebar">
    <div class="sidebar-brand">
        <span class="brand-icon"><i class="bi bi-menu-button-wide"></i></span>
        <div>
            <h2>HPU Workbench</h2>
            <p>Modern Portal</p>
        </div>
    </div>
    <a class="nav-link" href="<?= BASE_URL ?>/dashboard.php"><i class="bi bi-speedometer2"></i>Dashboard</a>
    <a class="nav-link" href="<?= BASE_URL ?>/user/new_request.php"><i class="bi bi-file-earmark-plus"></i>New Request</a>
    <a class="nav-link active" href="<?= BASE_URL ?>/supervisor/requests.php"><i class="bi bi-journal-text"></i>Job Requests</a>
    <a class="nav-link" href="https://drive.google.com/file/d/1E0KF_ZV0JfSCBDqQwsHW48FfsPTQxLjJ/view?usp=sharing" target="_blank"><i class="bi bi-download"></i>Download Job Request Forms</a>
          
    <a class="nav-link" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-right"></i>Logout</a>
</div>

<div class="main">

<div class="card">
    <h2>All Job Requests</h2>
</div>

<div class="card">

<div class="table-responsive">
<table class="table table-hover">
<tr>
    <th>Job No</th>
    <th>Department</th>
    <th>Date</th>
    <th>PHU Personnel</th>
    <th>Unit Head</th>
    <th>Status</th>
    <th>Manage</th>
</tr>

<?php while($row = $result->fetch_assoc()){ ?>

<tr>
    <td><?php echo htmlspecialchars($row['job_no']); ?></td>
    <td><?php echo htmlspecialchars($row['department']); ?></td>
    <td><?php echo htmlspecialchars($row['date_request']); ?></td>
    <td><?php echo htmlspecialchars($row['phu_personnel_status'] ?? 'Pending'); ?></td>
    <td><?php echo htmlspecialchars($row['unit_head_status'] ?? 'Pending'); ?></td>
    <td><?php echo htmlspecialchars($row['approval_status']); ?></td>
    <td>
        <a href="view.php?id=<?php echo $row['id']; ?>">View</a>
    </td>
</tr>

<?php } ?>

</table>

</div>

</div>

</body>
</html>
