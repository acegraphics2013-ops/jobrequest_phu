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

$date =
    trim(
        (string)(
            $_GET["date"]
            ?? ""
        )
    );

$services =
    $_GET["services"]
    ?? [];

if (!is_array($services)) {
    $services = [
        (string)$services
    ];
}

$normalizedDate =
    hpuAvailabilityNormalizeDate(
        $date
    );

if ($normalizedDate === null) {
    http_response_code(422);

    echo json_encode(
        [
            "success" => false,
            "blocked" => false,
            "message" => "Please provide a valid date."
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

$services =
    hpuAvailabilityCleanServices(
        $services
    );

if (!$services) {
    echo json_encode(
        [
            "success" => true,
            "blocked" => false,
            "message" => "",
            "date" => $normalizedDate
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

$block =
    hpuAvailabilityFindBlock(
        $conn,
        $normalizedDate,
        $services
    );

echo json_encode(
    [
        "success" => true,
        "blocked" => $block !== null,
        "message" =>
            $block
                ? hpuAvailabilityMessage(
                    $normalizedDate,
                    $block
                )
                : "",
        "date" => $normalizedDate,
        "block" =>
            $block
                ? [
                    "id" => (int)$block["id"],
                    "start_date" => $block["start_date"],
                    "end_date" => $block["end_date"],
                    "service_type" => $block["service_type"],
                    "reason" => $block["reason"]
                ]
                : null
    ],
    JSON_UNESCAPED_UNICODE
);
