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
    ".csv";

header(
    "Content-Type: text/csv; charset=UTF-8"
);

header(
    'Content-Disposition: attachment; filename="' .
    $filename .
    '"'
);

$output = fopen(
    "php://output",
    "w"
);

fwrite(
    $output,
    "\xEF\xBB\xBF"
);

fputcsv(
    $output,
    [
        "Job Number",
        "Project",
        "Department",
        "Services",
        "Date Requested",
        "Completion Date",
        "Status",
        "Reviewed By",
        "Rating",
        "Revisions"
    ]
);

foreach ($rows as $row) {
    fputcsv(
        $output,
        [
            $row["job_no"],
            $row["project_title"],
            $row["department"],
            implode(
                ", ",
                reportDecodeArray(
                    $row["request_types"]
                )
            ),
            $row["date_request"],
            reportCompletionDate(
                $row
            ),
            $row["approval_status"],
            $row["reviewed_by"],
            $row["satisfaction_rating"],
            $row["revision_count"]
        ]
    );
}

fclose($output);
exit;
