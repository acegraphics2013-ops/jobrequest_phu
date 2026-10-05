<?php

include "../includes/config.php";
include "../includes/auth.php";
require_once "../includes/module_access.php";

requireRole([
    "supervisor",
    "admin"
]);
requireModule($conn, "evaluation_analytics");

$dateFrom = trim(
    (string) ($_GET["date_from"] ?? "")
);

$dateTo = trim(
    (string) ($_GET["date_to"] ?? "")
);

$department = trim(
    (string) ($_GET["department"] ?? "")
);

$where = [
    "satisfaction_rating IS NOT NULL"
];

$params = [];
$types = "";

if ($dateFrom !== "") {
    $where[] = "date_request >= ?";
    $params[] = $dateFrom;
    $types .= "s";
}

if ($dateTo !== "") {
    $where[] = "date_request <= ?";
    $params[] = $dateTo;
    $types .= "s";
}

if ($department !== "") {
    $where[] = "department = ?";
    $params[] = $department;
    $types .= "s";
}

$sql = "
    SELECT
        job_no,
        project_title,
        requestor_name,
        department,
        date_request,
        approval_status,
        delivered_on_time,
        objectives_met,
        challenges_encountered,
        challenges_details,
        improvement_suggestions,
        satisfaction_rating
    FROM job_requests
    WHERE " .
    implode(" AND ", $where) .
    "
    ORDER BY id DESC
";

$stmt = $conn->prepare($sql);

if ($params) {
    $stmt->bind_param(
        $types,
        ...$params
    );
}

$stmt->execute();

$rows = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

header("Content-Type: text/csv; charset=UTF-8");
header(
    "Content-Disposition: attachment; filename=evaluation_analytics_" .
    date("Ymd_His") .
    ".csv"
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
        "Requestor",
        "Department",
        "Date Requested",
        "Status",
        "Delivered On Time",
        "Objectives Met",
        "Challenges",
        "Challenge Details",
        "Suggestions",
        "Rating"
    ]
);

foreach ($rows as $row) {
    fputcsv(
        $output,
        [
            $row["job_no"],
            $row["project_title"],
            $row["requestor_name"],
            $row["department"],
            $row["date_request"],
            $row["approval_status"],
            (int) $row["delivered_on_time"] === 1
                ? "Yes"
                : "No",
            (int) $row["objectives_met"] === 1
                ? "Yes"
                : "No",
            (int) $row["challenges_encountered"] === 1
                ? "Yes"
                : "No",
            $row["challenges_details"],
            $row["improvement_suggestions"],
            $row["satisfaction_rating"]
        ]
    );
}

fclose($output);
exit;
