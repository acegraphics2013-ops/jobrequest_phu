<?php
include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";
include "../includes/activity_log.php";

checkLogin();
requireModule($conn, "my_requests");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_requests.php');
    exit;
}

$requestId = postInt('request_id');
$userId = (int)$_SESSION['user_id'];
$delivered = postString('delivered_on_time');
$objectives = postString('objectives_met');
$challenges = postString('challenges_encountered');
$challengeDetails = postString('challenges_details');
$suggestions = postString('improvement_suggestions');
$rating = postInt('satisfaction_rating');

if (!in_array($delivered, ['0','1'], true) ||
    !in_array($objectives, ['0','1'], true) ||
    !in_array($challenges, ['0','1'], true)) {
    alertBack('Complete all evaluation questions.');
}

if ($challenges === '1' && $challengeDetails === '') {
    alertBack('Describe the challenges encountered.');
}

if ($rating < 1 || $rating > 5) {
    alertBack('Select a valid rating.');
}

$entry = "\nEvaluation submitted on " . date('Y-m-d H:i:s') . ".";

$stmt = $conn->prepare(
    "UPDATE job_requests SET
        delivered_on_time = ?,
        objectives_met = ?,
        challenges_encountered = ?,
        challenges_details = ?,
        improvement_suggestions = ?,
        satisfaction_rating = ?,
        approval_status = 'Closed',
        action_log = CONCAT(COALESCE(action_log,''), ?)
     WHERE id = ? AND requestor = ?"
);

$deliveredInt = (int)$delivered;
$objectivesInt = (int)$objectives;
$challengesInt = (int)$challenges;

$stmt->bind_param(
    "iiissisii",
    $deliveredInt, $objectivesInt, $challengesInt,
    $challengeDetails, $suggestions, $rating,
    $entry, $requestId, $userId
);

$stmt->execute();
$stmt->close();

echo "<script>alert('Evaluation submitted successfully.');window.location='my_requests.php';</script>";
