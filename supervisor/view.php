<?php
include "../includes/config.php";
include "../includes/auth.php";
require_once "../includes/module_access.php";

requireRole(['supervisor', 'admin']);
requireModule($conn, "review_requests");

if (empty($_SESSION['supervisor_csrf'])) {
    $_SESSION['supervisor_csrf'] = bin2hex(random_bytes(32));
}
$supervisorCsrf = (string) $_SESSION['supervisor_csrf'];

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$stmt = $conn->prepare("SELECT * FROM job_requests WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if (!$row) {
    die("Request not found.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Request</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="app-shell">

<div class="sidebar">
    <div class="sidebar-brand">
        <span class="brand-icon"><i class="bi bi-menu-button-wide"></i></span>
        <div>
            <h2>HPU Workbench</h2>
            <p>Supervisor Portal</p>
        </div>
    </div>
    <a class="nav-link" href="<?= BASE_URL ?>/dashboard.php"><i class="bi bi-speedometer2"></i>Dashboard</a>
    <a class="nav-link" href="<?= BASE_URL ?>/supervisor/requests.php"><i class="bi bi-card-checklist"></i>Job Requests</a>
    <a class="nav-link" href="#" onclick="confirmLogout(event)"><i class="bi bi-box-arrow-right"></i> Logout</a>
</div>

<div class="main">

    <div class="card">
        <h2>Job Request Details</h2>

        <p><b>Job No:</b> <?= htmlspecialchars($row['job_no']) ?></p>
        <p><b>Department:</b> <?= htmlspecialchars($row['department']) ?></p>
        <p><b>Contact:</b> <?= htmlspecialchars($row['contact']) ?></p>
        <p><b>Overall Status:</b> <?= htmlspecialchars($row['approval_status']) ?></p>

        <p><b>PHU Personnel:</b> <?= htmlspecialchars($row['phu_personnel_status'] ?? 'Pending') ?></p>
        <?php if (!empty($row['phu_personnel_reason'])): ?>
            <p><b>PHU Personnel Reason:</b><br>
                <?= nl2br(htmlspecialchars($row['phu_personnel_reason'])) ?></p>
        <?php endif; ?>

        <p><b>Unit Head:</b> <?= htmlspecialchars($row['unit_head_status'] ?? 'Pending') ?></p>
        <?php if (!empty($row['unit_head_reason'])): ?>
            <p><b>Unit Head Reason:</b><br>
                <?= nl2br(htmlspecialchars($row['unit_head_reason'])) ?></p>
        <?php endif; ?>

        <?php if (!empty($row['action_log'])): ?>
            <p><b>Action Log:</b></p>
            <pre style="white-space:pre-wrap; word-wrap:break-word; background:#f4f4f4; padding:1rem; border-radius:8px;"><?= htmlspecialchars($row['action_log']) ?></pre>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Save Unit Head Decision</h3>
        <form method="POST" action="<?= BASE_URL ?>/supervisor/update_status.php" class="row g-3">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="target" value="unit_head">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($supervisorCsrf, ENT_QUOTES, 'UTF-8') ?>">

            <div class="col-md-6">
                <label class="form-label">Unit Head Name</label>
                <select name="unit_head_name" class="form-select" required>
                    <option value="">Select Unit Head</option>
                    <option>Recklyn D. Ruaza</option>
                    <option>Bell Mandrew L. Manliguez</option>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Decision</label>
                <select name="decision" class="form-select" required>
                    <option value="Approved">Approved</option>
                    <option value="Disapproved">Disapproved</option>
                </select>
            </div>

            <div class="col-12">
                <label class="form-label">Reason</label>
                <textarea name="reason" class="form-control" placeholder="Reason for disapproval (optional)"></textarea>
            </div>

            <div class="col-12 d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">Save Unit Head Decision</button>
            </div>
        </form>
    </div>

</div>

<script>
function confirmLogout(event) {
    event.preventDefault();
    if (confirm("Are you sure you want to log out?")) {
        window.location.href = "<?= BASE_URL ?>/logout.php";
    }
}
</script>
<script src="<?= BASE_URL ?>/assets/js/logout-confirm.js"></script>
</body>
</html>
