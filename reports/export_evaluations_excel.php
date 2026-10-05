<?php
include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";
include "evaluation_report_helpers.php";

requireRole([
    "staff",
    "supervisor",
    "admin"
]);
requireModule($conn, "evaluation_analytics");

$filters = reportFilters();
$rows = reportRows(
    $conn,
    $filters
);

$filename =
    "evaluation-report-" .
    $filters["year"] .
    ".xls";

header(
    "Content-Type: application/vnd.ms-excel; charset=UTF-8"
);

header(
    'Content-Disposition: attachment; filename="' .
    $filename .
    '"'
);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        table {
            border-collapse: collapse;
        }

        th,
        td {
            padding: 7px;
            border: 1px solid #888;
        }

        th {
            background: #dbeafe;
            font-weight: bold;
        }
    </style>
</head>
<body>

<table>
    <thead>
    <tr>
        <th>Job Number</th>
        <th>Project</th>
        <th>Department</th>
        <th>Services</th>
        <th>Date Requested</th>
        <th>Completion Date</th>
        <th>Status</th>
        <th>Reviewed By</th>
        <th>Rating</th>
        <th>Revisions</th>
    </tr>
    </thead>

    <tbody>

    <?php foreach ($rows as $row): ?>

        <tr>
            <td><?= e($row["job_no"]) ?></td>
            <td><?= e($row["project_title"]) ?></td>
            <td><?= e($row["department"]) ?></td>
            <td>
                <?= e(
                    implode(
                        ", ",
                        reportDecodeArray(
                            $row["request_types"]
                        )
                    )
                ) ?>
            </td>
            <td><?= e($row["date_request"]) ?></td>
            <td><?= e(reportCompletionDate($row)) ?></td>
            <td><?= e($row["approval_status"]) ?></td>
            <td><?= e($row["reviewed_by"]) ?></td>
            <td><?= e($row["satisfaction_rating"]) ?></td>
            <td><?= e($row["revision_count"]) ?></td>
        </tr>

    <?php endforeach; ?>

    </tbody>
</table>

</body>
</html>
