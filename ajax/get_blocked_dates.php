<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
include "../includes/hpu_availability.php";

checkLogin();

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

header(
    "Content-Type: application/json; charset=utf-8"
);

hpuEnsureBlockedDatesTable(
    $conn
);

$services =
    $_GET["services"]
    ?? [];

if (!is_array($services)) {
    $services = [
        (string)$services
    ];
}

$services =
    hpuAvailabilityCleanServices(
        $services
    );

if (!$services) {
    echo json_encode(
        [
            "success" => true,
            "ranges" => []
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

$placeholders =
    implode(
        ",",
        array_fill(
            0,
            count($services),
            "?"
        )
    );

$sql = "
    SELECT
        id,
        start_date,
        end_date,
        service_type,
        reason
    FROM hpu_blocked_dates
    WHERE
        service_type = 'All HPU Services'
        OR service_type IN ({$placeholders})
    ORDER BY
        start_date ASC,
        end_date ASC,
        id ASC
";

$stmt =
    $conn->prepare(
        $sql
    );

$types =
    str_repeat(
        "s",
        count($services)
    );

$stmt->bind_param(
    $types,
    ...$services
);

$stmt->execute();

$rows =
    $stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

$stmt->close();

$ranges = [];

foreach ($rows as $row) {
    $ranges[] = [
        "from" => $row["start_date"],
        "to" => $row["end_date"],
        "service_type" => $row["service_type"],
        "reason" => $row["reason"]
    ];
}

echo json_encode(
    [
        "success" => true,
        "ranges" => $ranges
    ],
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
);
