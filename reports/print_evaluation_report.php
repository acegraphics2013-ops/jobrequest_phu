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

$total = count($rows);
$completed = 0;
$evaluated = 0;
$ratingTotal = 0;

foreach ($rows as $row) {
    if (
        in_array(
            $row["approval_status"],
            [
                "Completed",
                "For Evaluation",
                "Closed"
            ],
            true
        )
    ) {
        $completed++;
    }

    $rating = (int) (
        $row["satisfaction_rating"]
        ?? 0
    );

    if (
        $rating >= 1 &&
        $rating <= 5
    ) {
        $evaluated++;
        $ratingTotal += $rating;
    }
}

$averageRating =
    $evaluated > 0
        ? $ratingTotal /
          $evaluated
        : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>
        Evaluation Report
    </title>

    <style>
        @page {
            size: A4 landscape;
            margin: 10mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #111827;
            font-family: Arial, sans-serif;
            font-size: 10px;
        }

        .toolbar {
            display: flex;
            justify-content: center;
            gap: 8px;
            padding: 12px;
            background: #111827;
        }

        .toolbar button {
            padding: 9px 14px;
            border: 0;
            border-radius: 6px;
            background: #2563eb;
            color: #ffffff;
            cursor: pointer;
        }

        .report {
            max-width: 1200px;
            margin: 18px auto;
        }

        .report-header {
            margin-bottom: 14px;
            text-align: center;
        }

        .report-header h1 {
            margin: 0;
            font-size: 20px;
        }

        .report-header p {
            margin: 4px 0 0;
        }

        .summary {
            display: grid;
            grid-template-columns:
                repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 14px;
        }

        .summary-card {
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
        }

        .summary-card small {
            display: block;
            color: #64748b;
            font-weight: bold;
            text-transform: uppercase;
        }

        .summary-card strong {
            display: block;
            margin-top: 4px;
            font-size: 18px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 5px;
            border: 1px solid #94a3b8;
            vertical-align: top;
        }

        th {
            background: #e2e8f0;
            text-align: left;
        }

        @media print {
            .toolbar {
                display: none;
            }

            .report {
                max-width: none;
                margin: 0;
            }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <button
        type="button"
        onclick="window.print();"
    >
        Print / Save as PDF
    </button>
</div>

<div class="report">

    <div class="report-header">
        <h1>
            HPU Evaluation Analytics Report
        </h1>

        <p>
            Reporting Year:
            <?= (int) $filters["year"] ?>
        </p>
    </div>

    <div class="summary">

        <div class="summary-card">
            <small>Total Requests</small>
            <strong><?= number_format($total) ?></strong>
        </div>

        <div class="summary-card">
            <small>Completed</small>
            <strong><?= number_format($completed) ?></strong>
        </div>

        <div class="summary-card">
            <small>Evaluated</small>
            <strong><?= number_format($evaluated) ?></strong>
        </div>

        <div class="summary-card">
            <small>Average Rating</small>
            <strong><?= number_format($averageRating, 2) ?></strong>
        </div>

    </div>

    <table>

        <thead>
        <tr>
            <th>Job No.</th>
            <th>Project</th>
            <th>Department</th>
            <th>Services</th>
            <th>Requested</th>
            <th>Completed</th>
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

        <?php if (empty($rows)): ?>

            <tr>
                <td
                    colspan="10"
                    style="text-align:center;padding:20px;"
                >
                    No records found.
                </td>
            </tr>

        <?php endif; ?>

        </tbody>

    </table>

</div>

</body>
</html>
