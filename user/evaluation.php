<?php
include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

checkLogin();
requireModule($conn, "my_requests");

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$requestId) die('Invalid request ID.');

$userId = (int)$_SESSION['user_id'];

$stmt = $conn->prepare(
    "SELECT id, job_no, project_title
     FROM job_requests
     WHERE id = ? AND requestor = ?
     LIMIT 1"
);
$stmt->bind_param('ii', $requestId, $userId);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) die('Request not found.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post Project Evaluation</title>
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
                <div class="card">
                    <div class="section-banner">IV. Post Project Evaluation</div>
                    <div class="card-body">
                        <h4><?= e($request['job_no']) ?> — <?= e($request['project_title']) ?></h4>

                        <form method="POST" action="save_evaluation.php">
                            <input type="hidden" name="request_id" value="<?= (int)$requestId ?>">

                            <?php
                            $questions = [
                                'delivered_on_time' => 'Was the design delivered on time?',
                                'objectives_met' => 'Did the design meet the objectives?',
                                'challenges_encountered' => 'Were there any challenges encountered?'
                            ];
                            ?>

                            <?php foreach ($questions as $name => $label): ?>
                                <div class="border-bottom py-3">
                                    <label class="form-label fw-bold"><?= e($label) ?></label>
                                    <div>
                                        <label class="form-check form-check-inline">
                                            <input class="form-check-input <?= $name === 'challenges_encountered' ? 'challenge-radio' : '' ?>" type="radio" name="<?= e($name) ?>" value="1" required>
                                            <span class="form-check-label">Yes</span>
                                        </label>
                                        <label class="form-check form-check-inline">
                                            <input class="form-check-input <?= $name === 'challenges_encountered' ? 'challenge-radio' : '' ?>" type="radio" name="<?= e($name) ?>" value="0" required>
                                            <span class="form-check-label">No</span>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <div class="py-3">
                                <label class="form-label">Challenge Details</label>
                                <textarea id="challengeDetails" class="form-control" name="challenges_details" rows="4" disabled></textarea>
                            </div>

                            <div class="py-3">
                                <label class="form-label">Suggestions for Improvement</label>
                                <textarea class="form-control" name="improvement_suggestions" rows="4" placeholder="Enter suggestions or N/A"></textarea>
                            </div>

                            <div class="py-3">
                                <label class="form-label fw-bold d-block">Overall Satisfaction Rating</label>
                                <?php foreach ([1=>'Poor',2=>'Fair',3=>'Good',4=>'Very Good',5=>'Excellent'] as $value => $label): ?>
                                    <label class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="satisfaction_rating" value="<?= $value ?>" required>
                                        <span class="form-check-label"><?= $value ?>. <?= e($label) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <div class="d-flex justify-content-end gap-2">
                                <a class="btn btn-outline-secondary" href="my_requests.php">Cancel</a>
                                <button class="btn btn-primary" type="submit">Submit Evaluation</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
document.querySelectorAll(".challenge-radio").forEach(function (radio) {
    radio.addEventListener("change", function () {
        const yes = document.querySelector('.challenge-radio[value="1"]').checked;
        const details = document.getElementById("challengeDetails");
        details.disabled = !yes;
        details.required = yes;
        if (!yes) details.value = "";
    });
});
</script>
</body>
</html>
