<?php
function reportDecodeArray(
    mixed $value
): array {
    if (!$value) {
        return [];
    }

    $decoded = json_decode(
        (string) $value,
        true
    );

    return is_array($decoded)
        ? $decoded
        : [];
}

function reportFilters(): array {
    $year = filter_input(
        INPUT_GET,
        "year",
        FILTER_VALIDATE_INT
    );

    return [
        "year" =>
            $year ?: (int) date("Y"),

        "department" =>
            trim(
                (string) (
                    $_GET["department"]
                    ?? ""
                )
            ),

        "staff" =>
            trim(
                (string) (
                    $_GET["staff"]
                    ?? ""
                )
            ),

        "service" =>
            trim(
                (string) (
                    $_GET["service"]
                    ?? ""
                )
            )
    ];
}

function reportRows(
    mysqli $conn,
    array $filters
): array {
    $sql = "
        SELECT
            job_no,
            project_title,
            department,
            request_types,
            date_request,
            date_completion,
            final_approval_date,
            approval_status,
            reviewed_by,
            satisfaction_rating,
            revision_count
        FROM job_requests
        WHERE YEAR(date_request) = ?
    ";

    $params = [
        $filters["year"]
    ];

    $types = "i";

    if (
        $filters["department"] !== ""
    ) {
        $sql .= "
            AND department = ?
        ";

        $params[] =
            $filters["department"];

        $types .= "s";
    }

    if ($filters["staff"] !== "") {
        $sql .= "
            AND reviewed_by = ?
        ";

        $params[] =
            $filters["staff"];

        $types .= "s";
    }

    $sql .= "
        ORDER BY date_request ASC,
                 job_no ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        $types,
        ...$params
    );
    $stmt->execute();

    $rows = $stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

    $stmt->close();

    if ($filters["service"] !== "") {
        $rows = array_values(
            array_filter(
                $rows,
                fn ($row) =>
                    in_array(
                        $filters[
                            "service"
                        ],
                        reportDecodeArray(
                            $row[
                                "request_types"
                            ] ?? null
                        ),
                        true
                    )
            )
        );
    }

    return $rows;
}

function reportCompletionDate(
    array $row
): string {
    return (string) (
        $row[
            "final_approval_date"
        ]
        ?: $row["date_completion"]
        ?: ""
    );
}
