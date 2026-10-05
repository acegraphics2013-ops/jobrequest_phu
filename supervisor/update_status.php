<?php
include "../includes/config.php";
include "../includes/auth.php";
require_once "../includes/module_access.php";

requireRole(['supervisor', 'admin']);
requireModule($conn, "review_requests");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/supervisor/requests.php');
    exit();
}

$sessionToken = (string) ($_SESSION['supervisor_csrf'] ?? '');
$submittedToken = (string) ($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    http_response_code(403);
    die('Invalid security token. Please refresh the page and try again.');
}

$id = (int) ($_POST['id'] ?? 0);
$target = $_POST['target'] ?? '';
$decision = $_POST['decision'] ?? '';
$reason = trim($_POST['reason'] ?? '');
$unitHeadActor = trim($_POST['unit_head_name'] ?? '');

$allowedTargets = ['unit_head'];
$allowedDecisions = ['Approved', 'Disapproved'];

if (!$id || !in_array($target, $allowedTargets, true) || !in_array($decision, $allowedDecisions, true) || $unitHeadActor === '') {
    header('Location: ' . BASE_URL . '/supervisor/requests.php');
    exit();
}

$statusColumn = $target . '_status';
$reasonColumn = $target . '_reason';
// Columns are allowlisted via $allowedTargets above, values use prepared statements.
$reason = mb_substr($reason, 0, 2000);
$unitHeadActor = mb_substr($unitHeadActor, 0, 100);

$updateActionLog = $conn->prepare(
    "SELECT action_log FROM job_requests WHERE id = ? LIMIT 1"
);
$updateActionLog->bind_param('i', $id);
$updateActionLog->execute();
$actionRow = $updateActionLog->get_result()->fetch_assoc();
$updateActionLog->close();

if (!$actionRow) {
    http_response_code(404);
    die('Request not found.');
}

$timestamp = date('Y-m-d H:i:s');
$actionDescription = "$timestamp - Unit Head $unitHeadActor $decision";
if ($reason !== '') {
    $actionDescription .= ": $reason";
}

$existingLog = trim($actionRow['action_log'] ?? '');
$newLog = $existingLog !== '' ? $existingLog . "\n" . $actionDescription : $actionDescription;

if ($decision === 'Disapproved') {
    $upd = $conn->prepare("UPDATE job_requests SET unit_head_status='Disapproved', unit_head_reason=?, approved_by=?, action_log=? WHERE id=?");
    $upd->bind_param('sssi', $reason, $unitHeadActor, $newLog, $id);
    $upd->execute();
    $upd->close();
} else {
    $upd = $conn->prepare("UPDATE job_requests SET unit_head_status='Approved', unit_head_reason=NULL, approved_by=?, action_log=? WHERE id=?");
    $upd->bind_param('ssi', $unitHeadActor, $newLog, $id);
    $upd->execute();
    $upd->close();
}

$sel = $conn->prepare("SELECT phu_personnel_status, unit_head_status FROM job_requests WHERE id=?");
$sel->bind_param('i', $id);
$sel->execute();
$approvalRow = $sel->get_result()->fetch_assoc();
$sel->close();
$overallStatus = 'Pending';
if (($approvalRow['phu_personnel_status'] ?? '') === 'Disapproved' || ($approvalRow['unit_head_status'] ?? '') === 'Disapproved') {
    $overallStatus = 'Disapproved';
} elseif (($approvalRow['phu_personnel_status'] ?? '') === 'Approved' && ($approvalRow['unit_head_status'] ?? '') === 'Approved') {
    $overallStatus = 'Approved';
}
$upd2 = $conn->prepare("UPDATE job_requests SET approval_status=? WHERE id=?");
$upd2->bind_param('si', $overallStatus, $id);
$upd2->execute();
$upd2->close();

$message = "Unit Head decision saved.";

echo "<script>
alert('$message');
window.location='requests.php';
</script>";
exit();
?>