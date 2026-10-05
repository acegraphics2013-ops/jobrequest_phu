<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

requireRole([
    "staff",
    "supervisor",
    "admin"
]);
requireModule($conn, "evaluation_analytics");

/* =========================================================
   SURVEY TABLE CHECK
========================================================= */
$tableResult = $conn->query(
    "SHOW TABLES LIKE 'job_request_client_experience'"
);

$surveyTableReady =
    $tableResult
    && $tableResult->num_rows > 0;

/* =========================================================
   FILTERS
========================================================= */
$search = trim(
    (string) ($_GET["search"] ?? "")
);

$startDate = trim(
    (string) ($_GET["start_date"] ?? "")
);

$endDate = trim(
    (string) ($_GET["end_date"] ?? "")
);

$departmentFilter = trim(
    (string) ($_GET["department"] ?? "")
);

if (mb_strlen($search) > 120) {
    $search = mb_substr(
        $search,
        0,
        120
    );
}

function surveyAnalyticsValidDate(
    string $date
): bool {
    if ($date === "") {
        return true;
    }

    $parsed = DateTime::createFromFormat(
        "Y-m-d",
        $date
    );

    return $parsed
        && $parsed->format("Y-m-d") === $date;
}

if (!surveyAnalyticsValidDate($startDate)) {
    $startDate = "";
}

if (!surveyAnalyticsValidDate($endDate)) {
    $endDate = "";
}

function bindSurveyAnalyticsParams(
    mysqli_stmt $stmt,
    string $types,
    array &$params
): void {
    if ($types === "") {
        return;
    }

    $arguments = [$types];

    foreach ($params as $index => $value) {
        $arguments[] = &$params[$index];
    }

    call_user_func_array(
        [$stmt, "bind_param"],
        $arguments
    );
}

function surveyAnalyticsPercent(
    float $value
): float {
    return max(
        0,
        min(
            100,
            ($value / 5) * 100
        )
    );
}

/* =========================================================
   DEFAULT DATA
   ========================================================= */
$summary = [
    "total_responses" => 0,
    "average_overall" => 0,
    "average_service" => 0,
    "positive_outcome" => 0,
    "this_month" => 0,
    "timeliness" => 0,
    "responsiveness" => 0,
    "quality_output" => 0,
    "professionalism" => 0,
    "communication" => 0,
    "service_met_needs" => 0
];

$pubSummary = [
    "total_responses" => 0,
    "average_overall" => 0,
    "average_service" => 0,
    "positive_outcome" => 0,
    "this_month" => 0,
    "timeliness" => 0,
    "responsiveness" => 0,
    "quality_output" => 0,
    "professionalism" => 0,
    "communication" => 0,
    "service_met_needs" => 0
];

$ratingDistribution = [];
$outcomeDistribution = [];
$departmentRows = [];
$monthlyRows = [];
$serviceCounts = [];
$commentRows = [];
$responseRows = [];
$departments = [];

if ($surveyTableReady) {
    /* =====================================================
       DEPARTMENT OPTIONS
    ===================================================== */
    $departmentResult = $conn->query(
        "SELECT DISTINCT
            jr.department
         FROM job_request_client_experience s
         INNER JOIN job_requests jr
            ON jr.id = s.request_id
         WHERE jr.department IS NOT NULL
           AND jr.department <> ''
         ORDER BY jr.department"
    );

    if ($departmentResult) {
        $departments = $departmentResult
            ->fetch_all(MYSQLI_ASSOC);
    }

    /* =====================================================
       COMMON FILTER
    ===================================================== */
    $where = [
        "1 = 1"
    ];

    $types = "";
    $params = [];

    if ($search !== "") {
        $searchValue =
            "%" . $search . "%";

        $where[] = "(
            jr.job_no LIKE ?
            OR jr.requestor_name LIKE ?
            OR jr.project_title LIKE ?
            OR jr.department LIKE ?
        )";

        $types .= "ssss";

        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;
    }

    if ($startDate !== "") {
        $where[] =
            "DATE(s.submitted_at) >= ?";

        $types .= "s";
        $params[] = $startDate;
    }

    if ($endDate !== "") {
        $where[] =
            "DATE(s.submitted_at) <= ?";

        $types .= "s";
        $params[] = $endDate;
    }

    if ($departmentFilter !== "") {
        $where[] =
            "jr.department = ?";

        $types .= "s";
        $params[] = $departmentFilter;
    }

    $whereSql = implode(
        " AND ",
        $where
    );

    /* =====================================================
       SUMMARY (jobs)
    ===================================================== */
    $summarySql = "
        SELECT
            COUNT(*) AS total_responses,
            COALESCE(
                AVG(s.overall_satisfaction),
                0
            ) AS average_overall,
            COALESCE(
                AVG(
                    (
                        s.timeliness
                        + s.responsiveness
                        + s.quality_output
                        + s.professionalism
                        + s.communication
                        + s.service_met_needs
                    ) / 6
                ),
                0
            ) AS average_service,
            COALESCE(
                SUM(
                    s.outcome_assessment = 'Yes'
                ),
                0
            ) AS positive_outcome_count,
            COALESCE(
                SUM(
                    YEAR(s.submitted_at) = YEAR(CURDATE())
                    AND MONTH(s.submitted_at) = MONTH(CURDATE())
                ),
                0
            ) AS this_month,
            COALESCE(
                AVG(s.timeliness),
                0
            ) AS timeliness,
            COALESCE(
                AVG(s.responsiveness),
                0
            ) AS responsiveness,
            COALESCE(
                AVG(s.quality_output),
                0
            ) AS quality_output,
            COALESCE(
                AVG(s.professionalism),
                0
            ) AS professionalism,
            COALESCE(
                AVG(s.communication),
                0
            ) AS communication,
            COALESCE(
                AVG(s.service_met_needs),
                0
            ) AS service_met_needs
        FROM job_request_client_experience s
        INNER JOIN job_requests jr
            ON jr.id = s.request_id
        WHERE {$whereSql}
    ";

    $summaryStmt = $conn->prepare(
        $summarySql
    );

    $summaryParams = $params;

    bindSurveyAnalyticsParams(
        $summaryStmt,
        $types,
        $summaryParams
    );

    $summaryStmt->execute();

    $summaryRow = $summaryStmt
        ->get_result()
        ->fetch_assoc() ?: [];

    $summaryStmt->close();

    $totalResponses = (int) (
        $summaryRow["total_responses"] ?? 0
    );

    $positiveOutcomeCount = (int) (
        $summaryRow["positive_outcome_count"] ?? 0
    );

    $summary = [
        "total_responses" =>
            $totalResponses,

        "average_overall" =>
            round(
                (float) (
                    $summaryRow[
                        "average_overall"
                    ] ?? 0
                ),
                2
            ),

        "average_service" =>
            round(
                (float) (
                    $summaryRow[
                        "average_service"
                    ] ?? 0
                ),
                2
            ),

        "positive_outcome" =>
            $totalResponses > 0
                ? round(
                    (
                        $positiveOutcomeCount
                        / $totalResponses
                    ) * 100,
                    1
                )
                : 0,

        "this_month" =>
            (int) (
                $summaryRow[
                    "this_month"
                ] ?? 0
            ),

        "timeliness" =>
            round(
                (float) (
                    $summaryRow[
                        "timeliness"
                    ] ?? 0
                ),
                2
            ),

        "responsiveness" =>
            round(
                (float) (
                    $summaryRow[
                        "responsiveness"
                    ] ?? 0
                ),
                2
            ),

        "quality_output" =>
            round(
                (float) (
                    $summaryRow[
                        "quality_output"
                    ] ?? 0
                ),
                2
            ),

        "professionalism" =>
            round(
                (float) (
                    $summaryRow[
                        "professionalism"
                    ] ?? 0
                ),
                2
            ),

        "communication" =>
            round(
                (float) (
                    $summaryRow[
                        "communication"
                    ] ?? 0
                ),
                2
            ),

        "service_met_needs" =>
            round(
                (float) (
                    $summaryRow[
                        "service_met_needs"
                    ] ?? 0
                ),
                2
            )
    ];

    /* =====================================================
       OVERALL RATING DISTRIBUTION
    ===================================================== */
    $ratingSql = "
        SELECT
            s.overall_satisfaction AS rating,
            COUNT(*) AS total
        FROM job_request_client_experience s
        INNER JOIN job_requests jr
            ON jr.id = s.request_id
        WHERE {$whereSql}
        GROUP BY s.overall_satisfaction
        ORDER BY s.overall_satisfaction DESC
    ";

    $ratingStmt = $conn->prepare(
        $ratingSql
    );

    $ratingParams = $params;

    bindSurveyAnalyticsParams(
        $ratingStmt,
        $types,
        $ratingParams
    );

    $ratingStmt->execute();

    $ratingRaw = $ratingStmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $ratingStmt->close();

    $ratingMap = [];

    foreach ($ratingRaw as $row) {
        $ratingMap[
            (int) $row["rating"]
        ] = (int) $row["total"];
    }

    for ($rating = 5; $rating >= 1; $rating--) {
        $count = $ratingMap[$rating] ?? 0;

        $ratingDistribution[] = [
            "rating" => $rating,
            "count" => $count,
            "percentage" =>
                $summary["total_responses"] > 0
                    ? round(
                        (
                            $count
                            / $summary[
                                "total_responses"
                            ]
                        ) * 100,
                        1
                    )
                    : 0
        ];
    }

    /* =====================================================
       OUTCOME DISTRIBUTION
    ===================================================== */
    $outcomeSql = "
        SELECT
            s.outcome_assessment AS outcome,
            COUNT(*) AS total
        FROM job_request_client_experience s
        INNER JOIN job_requests jr
            ON jr.id = s.request_id
        WHERE {$whereSql}
        GROUP BY s.outcome_assessment
        ORDER BY FIELD(
            s.outcome_assessment,
            'Yes',
            'Partially',
            'No'
        )
    ";

    $outcomeStmt = $conn->prepare(
        $outcomeSql
    );

    $outcomeParams = $params;

    bindSurveyAnalyticsParams(
        $outcomeStmt,
        $types,
        $outcomeParams
    );

    $outcomeStmt->execute();

    $outcomeRaw = $outcomeStmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $outcomeStmt->close();

    $outcomeMap = [];

    foreach ($outcomeRaw as $row) {
        $outcomeMap[
            (string) $row["outcome"]
        ] = (int) $row["total"];
    }

    foreach (
        [
            "Yes",
            "Partially",
            "No"
        ] as $outcome
    ) {
        $count =
            $outcomeMap[$outcome] ?? 0;

        $outcomeDistribution[] = [
            "outcome" => $outcome,
            "count" => $count,
            "percentage" =>
                $summary["total_responses"] > 0
                    ? round(
                        (
                            $count
                            / $summary[
                                "total_responses"
                            ]
                        ) * 100,
                        1
                    )
                    : 0
        ];
    }

    /* =====================================================
       DEPARTMENT SUMMARY
    ===================================================== */
    $departmentSql = "
        SELECT
            COALESCE(
                NULLIF(jr.department, ''),
                'Unspecified'
            ) AS department,
            COUNT(*) AS total_responses,
            ROUND(
                AVG(s.overall_satisfaction),
                2
            ) AS average_rating,
            ROUND(
                AVG(
                    (
                        s.timeliness
                        + s.responsiveness
                        + s.quality_output
                        + s.professionalism
                        + s.communication
                        + s.service_met_needs
                    ) / 6
                ),
                2
            ) AS average_service
        FROM job_request_client_experience s
        INNER JOIN job_requests jr
            ON jr.id = s.request_id
        WHERE {$whereSql}
        GROUP BY
            COALESCE(
                NULLIF(jr.department, ''),
                'Unspecified'
            )
        ORDER BY total_responses DESC,
                 department ASC
    ";

    $departmentStmt = $conn->prepare(
        $departmentSql
    );

    $departmentParams = $params;

    bindSurveyAnalyticsParams(
        $departmentStmt,
        $types,
        $departmentParams
    );

    $departmentStmt->execute();

    $departmentRows = $departmentStmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $departmentStmt->close();

    /* =====================================================
       MONTHLY TREND
    ===================================================== */
    $monthlySql = "
        SELECT
            DATE_FORMAT(
                s.submitted_at,
                '%Y-%m'
            ) AS month_key,
            DATE_FORMAT(
                s.submitted_at,
                '%b %Y'
            ) AS month_label,
            COUNT(*) AS total_responses,
            ROUND(
                AVG(s.overall_satisfaction),
                2
            ) AS average_rating
        FROM job_request_client_experience s
        INNER JOIN job_requests jr
            ON jr.id = s.request_id
        WHERE {$whereSql}
        GROUP BY
            DATE_FORMAT(
                s.submitted_at,
                '%Y-%m'
            ),
            DATE_FORMAT(
                s.submitted_at,
                '%b %Y'
            )
        ORDER BY month_key DESC
        LIMIT 12
    ";

    $monthlyStmt = $conn->prepare(
        $monthlySql
    );

    $monthlyParams = $params;

    bindSurveyAnalyticsParams(
        $monthlyStmt,
        $types,
        $monthlyParams
    );

    $monthlyStmt->execute();

    $monthlyRows = $monthlyStmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $monthlyStmt->close();

    $monthlyRows = array_reverse(
        $monthlyRows
    );

    /* =====================================================
       SERVICE BREAKDOWN
    ===================================================== */
    $serviceSql = "
        SELECT
            s.service_types
        FROM job_request_client_experience s
        INNER JOIN job_requests jr
            ON jr.id = s.request_id
        WHERE {$whereSql}
    ";

    $serviceStmt = $conn->prepare(
        $serviceSql
    );

    $serviceParams = $params;

    bindSurveyAnalyticsParams(
        $serviceStmt,
        $types,
        $serviceParams
    );

    $serviceStmt->execute();

    $serviceRows = $serviceStmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $serviceStmt->close();

    foreach ($serviceRows as $row) {
        $services = array_filter(
            array_map(
                "trim",
                explode(
                    ",",
                    (string) (
                        $row[
                            "service_types"
                        ] ?? ""
                    )
                )
            )
        );

        foreach ($services as $service) {
            if (!isset(
                $serviceCounts[$service]
            )) {
                $serviceCounts[$service] = 0;
            }

            $serviceCounts[$service]++;
        }
    }

    arsort($serviceCounts);

    /* =====================================================
       RECENT COMMENTS
    ===================================================== */
    $commentsSql = "
        SELECT
            s.phu_did_well,
            s.phu_improve,
            s.submitted_at,
            jr.department
        FROM job_request_client_experience s
        INNER JOIN job_requests jr
            ON jr.id = s.request_id
        WHERE {$whereSql}
          AND (
                NULLIF(
                    TRIM(s.phu_did_well),
                    ''
                ) IS NOT NULL
                OR NULLIF(
                    TRIM(s.phu_improve),
                    ''
                ) IS NOT NULL
          )
        ORDER BY s.submitted_at DESC
        LIMIT 12
    ";

    $commentsStmt = $conn->prepare(
        $commentsSql
    );

    $commentsParams = $params;

    bindSurveyAnalyticsParams(
        $commentsStmt,
        $types,
        $commentsParams
    );

    $commentsStmt->execute();

    $commentRows = $commentsStmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $commentsStmt->close();

    /* =====================================================
       SPECIFIC REQUESTOR RESPONSES
    ===================================================== */
    $responsesSql = "
        SELECT
            s.id AS survey_id,
            s.request_id,
            s.overall_satisfaction,
            s.outcome_assessment,
            s.submitted_at,
            jr.job_no,
            jr.requestor_name,
            jr.department,
            jr.project_title
        FROM job_request_client_experience s
        INNER JOIN job_requests jr
            ON jr.id = s.request_id
        WHERE {$whereSql}
        ORDER BY s.submitted_at DESC
        LIMIT 100
    ";

    $responsesStmt = $conn->prepare(
        $responsesSql
    );

    $responsesParams = $params;

    bindSurveyAnalyticsParams(
        $responsesStmt,
        $types,
        $responsesParams
    );

    $responsesStmt->execute();

    $responseRows = $responsesStmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $responsesStmt->close();

    /* =====================================================
       CSV EXPORT (same filters, latest 5000 records)
    ===================================================== */
    if (
        isset($_GET["export"])
        && $_GET["export"] === "csv"
    ) {
        $exportSql = "
            SELECT
                s.id AS survey_id,
                s.request_id,
                jr.job_no,
                jr.requestor_name,
                jr.department,
                jr.project_title,
                s.overall_satisfaction,
                s.timeliness,
                s.responsiveness,
                s.quality_output,
                s.professionalism,
                s.communication,
                s.service_met_needs,
                s.outcome_assessment,
                s.submitted_at
            FROM job_request_client_experience s
            INNER JOIN job_requests jr
                ON jr.id = s.request_id
            WHERE {$whereSql}
            ORDER BY s.submitted_at DESC
            LIMIT 5000
        ";

        $exportStmt = $conn->prepare(
            $exportSql
        );

        $exportParams = $params;

        bindSurveyAnalyticsParams(
            $exportStmt,
            $types,
            $exportParams
        );

        $exportStmt->execute();

        $exportRows = $exportStmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);

        $exportStmt->close();

        $filename =
            "survey_analytics_"
            . date("Ymd_His")
            . ".csv";

        header(
            "Content-Type: text/csv; charset=UTF-8"
        );

        header(
            "Content-Disposition: attachment; filename=\""
            . $filename
            . "\""
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
                "Survey ID",
                "Request ID",
                "Job No.",
                "Requestor",
                "Department",
                "Project Title",
                "Overall Satisfaction",
                "Timeliness",
                "Responsiveness",
                "Quality of Output",
                "Professionalism",
                "Communication",
                "Service Met Needs",
                "Outcome Assessment",
                "Submitted At"
            ]
        );

        foreach ($exportRows as $exportRow) {
            fputcsv(
                $output,
                [
                    $exportRow["survey_id"] ?? "",
                    $exportRow["request_id"] ?? "",
                    $exportRow["job_no"] ?? "",
                    $exportRow["requestor_name"] ?? "",
                    $exportRow["department"] ?? "",
                    $exportRow["project_title"] ?? "",
                    $exportRow["overall_satisfaction"] ?? "",
                    $exportRow["timeliness"] ?? "",
                    $exportRow["responsiveness"] ?? "",
                    $exportRow["quality_output"] ?? "",
                    $exportRow["professionalism"] ?? "",
                    $exportRow["communication"] ?? "",
                    $exportRow["service_met_needs"] ?? "",
                    $exportRow["outcome_assessment"] ?? "",
                    $exportRow["submitted_at"] ?? ""
                ]
            );
        }

        fclose($output);
        exit;
    }
}

$criteriaRows = [
    [
        "label" =>
            "Timeliness of Service",
        "value" =>
            $summary["timeliness"]
    ],
    [
        "label" =>
            "Responsiveness to Request",
        "value" =>
            $summary["responsiveness"]
    ],
    [
        "label" =>
            "Quality of Output",
        "value" =>
            $summary["quality_output"]
    ],
    [
        "label" =>
            "Professionalism of Staff",
        "value" =>
            $summary["professionalism"]
    ],
    [
        "label" =>
            "Communication and Coordination",
        "value" =>
            $summary["communication"]
    ],
    [
        "label" =>
            "Service Met Client Needs",
        "value" =>
            $summary["service_met_needs"]
    ]
];
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Survey Analytics | HPU Workbench
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="<?= e(BASE_URL) ?>/assets/css/style.css"
    >

    <style>
        :root {
            --ui-bg: #f3f6fa;
            --ui-surface: #ffffff;
            --ui-surface-soft: #f8fafc;
            --ui-border: #dbe3ee;
            --ui-border-strong: #cbd5e1;
            --ui-text: #0f172a;
            --ui-text-soft: #334155;
            --ui-muted: #64748b;
            --ui-primary: #1d5f91;
            --ui-primary-dark: #164c75;
            --ui-primary-soft: #eaf3f9;
            --ui-green: #138a62;
            --ui-green-soft: #e9f8f2;
            --ui-violet: #6d55b5;
            --ui-violet-soft: #f0ecfb;
            --ui-amber: #b7791f;
            --ui-amber-soft: #fff7e6;
            --ui-red: #b42318;
            --ui-red-soft: #fff0ee;
            --ui-shadow:
                0 12px 32px rgba(15, 23, 42, .055);
            --ui-shadow-hover:
                0 16px 40px rgba(15, 23, 42, .085);
        }

        * {
            box-sizing: border-box;
        }

        body {
            background: var(--ui-bg);
            color: var(--ui-text);
            font-family:
                Inter,
                ui-sans-serif,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }

        .content {
            padding-top: 22px;
            padding-bottom: 40px;
        }

        .analytics-page {
            width: min(100%, 1420px);
            margin: 0 auto;
        }

        /* ==================================================
           CLEAN PAGE HEADER
        ================================================== */
        .analytics-hero {
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 22px;
            flex-wrap: wrap;
            margin-bottom: 18px;
            padding: 24px 26px;
            border: 1px solid var(--ui-border);
            border-radius: 18px;
            background: var(--ui-surface);
            color: var(--ui-text);
            box-shadow: var(--ui-shadow);
        }

        .analytics-hero::before {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            width: 5px;
            background:
                linear-gradient(
                    180deg,
                    var(--ui-primary),
                    var(--ui-green)
                );
            content: "";
        }

        .analytics-hero::after {
            position: absolute;
            top: -70px;
            right: -80px;
            width: 240px;
            height: 240px;
            border: 34px solid rgba(29, 95, 145, .045);
            border-radius: 50%;
            content: "";
            pointer-events: none;
        }

        .analytics-hero-copy {
            position: relative;
            z-index: 1;
            max-width: 820px;
        }

        .analytics-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 9px;
            padding: 6px 10px;
            border: 1px solid #cfe3f0;
            border-radius: 999px;
            background: var(--ui-primary-soft);
            color: var(--ui-primary-dark);
            font-size: .7rem;
            font-weight: 800;
            letter-spacing: .055em;
            text-transform: uppercase;
        }

        .analytics-hero h1 {
            margin: 0;
            color: var(--ui-text);
            font-size: clamp(1.45rem, 2vw, 1.85rem);
            font-weight: 800;
            letter-spacing: -.025em;
        }

        .analytics-hero p {
            max-width: 760px;
            margin: 7px 0 0;
            color: var(--ui-muted);
            font-size: .92rem;
            line-height: 1.55;
        }

        .hero-actions {
            position: relative;
            z-index: 2;
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
        }

        .hero-actions .btn {
            min-height: 42px;
            padding: 9px 14px;
            border-radius: 10px;
            font-size: .84rem;
            font-weight: 750;
        }

        .hero-actions .btn-light {
            border-color: var(--ui-primary);
            background: var(--ui-primary);
            color: #ffffff;
        }

        .hero-actions .btn-light:hover,
        .hero-actions .btn-light:focus {
            border-color: var(--ui-primary-dark);
            background: var(--ui-primary-dark);
            color: #ffffff;
        }

        .hero-actions .btn-outline-light {
            border-color: var(--ui-border-strong);
            background: #ffffff;
            color: var(--ui-text-soft);
        }

        .hero-actions .btn-outline-light:hover,
        .hero-actions .btn-outline-light:focus {
            border-color: #b8c5d4;
            background: var(--ui-surface-soft);
            color: var(--ui-text);
        }

        /* ==================================================
           STACKED SURFACES
        ================================================== */
        .analytics-card {
            height: 100%;
            overflow: hidden;
            border: 1px solid var(--ui-border);
            border-radius: 16px;
            background: var(--ui-surface);
            box-shadow: var(--ui-shadow);
            transition:
                transform .18s ease,
                box-shadow .18s ease,
                border-color .18s ease;
        }

        .analytics-card:hover {
            border-color: #c8d4e2;
            box-shadow: var(--ui-shadow-hover);
        }

        .analytics-card > .card-body {
            padding: 20px;
        }

        .filter-card {
            margin-bottom: 18px;
        }

        .filter-card .card-body {
            padding: 18px;
        }

        .filter-card .row {
            --bs-gutter-x: 12px;
            --bs-gutter-y: 12px;
        }

        .form-label {
            margin-bottom: 6px;
            color: var(--ui-text-soft);
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .025em;
            text-transform: uppercase;
        }

        .form-control,
        .form-select,
        .input-group-text {
            min-height: 43px;
            border-color: var(--ui-border);
            background: #ffffff;
            color: var(--ui-text);
            border-radius: 10px;
            font-size: .86rem;
        }

        .input-group .input-group-text {
            border-right: 0;
            color: var(--ui-muted);
        }

        .input-group .form-control {
            border-left: 0;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #7eb0cf;
            box-shadow: 0 0 0 .2rem rgba(29, 95, 145, .10);
        }

        .filter-card .btn {
            min-height: 43px;
            border-radius: 10px;
            font-size: .84rem;
            font-weight: 750;
        }

        .filter-card .btn-primary {
            border-color: var(--ui-primary);
            background: var(--ui-primary);
        }

        .filter-card .btn-primary:hover {
            border-color: var(--ui-primary-dark);
            background: var(--ui-primary-dark);
        }

        /* ==================================================
           METRICS
        ================================================== */
        .metric-card {
            position: relative;
            overflow: hidden;
            min-height: 138px;
            padding: 18px;
        }

        .metric-card::after {
            position: absolute;
            right: -25px;
            bottom: -34px;
            width: 105px;
            height: 105px;
            border-radius: 50%;
            background: rgba(29, 95, 145, .05);
            content: "";
        }

        .metric-icon {
            position: relative;
            z-index: 1;
            display: grid;
            width: 42px;
            height: 42px;
            place-items: center;
            border-radius: 12px;
            background: var(--ui-primary-soft);
            color: var(--ui-primary);
            font-size: 18px;
        }

        .row.g-3.mb-4 > div:nth-child(2) .metric-icon {
            background: var(--ui-amber-soft);
            color: var(--ui-amber);
        }

        .row.g-3.mb-4 > div:nth-child(3) .metric-icon {
            background: var(--ui-violet-soft);
            color: var(--ui-violet);
        }

        .row.g-3.mb-4 > div:nth-child(4) .metric-icon {
            background: var(--ui-green-soft);
            color: var(--ui-green);
        }

        .row.g-3.mb-4 > div:nth-child(5) .metric-icon {
            background: #edf4ff;
            color: #245fa7;
        }

        .metric-label {
            position: relative;
            z-index: 1;
            margin-top: 15px;
            color: var(--ui-muted);
            font-size: .68rem;
            font-weight: 800;
            letter-spacing: .055em;
            text-transform: uppercase;
        }

        .metric-value {
            position: relative;
            z-index: 1;
            margin-top: 3px;
            color: var(--ui-text);
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: -.035em;
        }

        .metric-value small {
            color: var(--ui-muted);
            font-weight: 700;
        }

        /* ==================================================
           SECTION HEADERS
        ================================================== */
        .section-title {
            display: flex;
            align-items: center;
            gap: 11px;
            margin-bottom: 17px;
            padding-bottom: 14px;
            border-bottom: 1px solid #edf1f5;
        }

        .section-title-icon {
            display: grid;
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            place-items: center;
            border-radius: 11px;
            background: var(--ui-primary-soft);
            color: var(--ui-primary);
            font-size: 16px;
        }

        .section-title h2 {
            margin: 0;
            color: var(--ui-text);
            font-size: .98rem;
            font-weight: 800;
            letter-spacing: -.01em;
        }

        .section-title p {
            margin: 2px 0 0;
            color: var(--ui-muted);
            font-size: .76rem;
            line-height: 1.4;
        }

        /* ==================================================
           BARS AND DISTRIBUTIONS
        ================================================== */
        .bar-row {
            margin-bottom: 15px;
        }

        .bar-row:last-child {
            margin-bottom: 0;
        }

        .bar-label {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 7px;
            color: var(--ui-text-soft);
            font-size: .82rem;
            font-weight: 700;
        }

        .bar-label span:last-child {
            color: var(--ui-muted);
            font-size: .76rem;
        }

        .bar-track {
            height: 8px;
            overflow: hidden;
            border-radius: 999px;
            background: #edf2f7;
        }

        .bar-fill {
            height: 100%;
            border-radius: inherit;
            background:
                linear-gradient(
                    90deg,
                    var(--ui-primary),
                    #3b82a8
                );
        }

        /* ==================================================
           TABLES
        ================================================== */
        .table-responsive {
            border: 1px solid #e6ebf1;
            border-radius: 12px;
        }

        .table {
            margin-bottom: 0;
            color: var(--ui-text-soft);
            font-size: .82rem;
        }

        .table > :not(caption) > * > * {
            padding: .78rem .82rem;
            vertical-align: middle;
            border-bottom-color: #e9eef4;
        }

        .table thead th {
            border-bottom: 1px solid #dfe6ee;
            background: #f7f9fc;
            color: #526174;
            font-size: .66rem;
            font-weight: 850;
            letter-spacing: .045em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .table tbody tr:last-child td {
            border-bottom: 0;
        }

        .table-hover > tbody > tr:hover > * {
            background: #f7fafc;
        }

        .response-title {
            color: var(--ui-text);
            font-size: .84rem;
            font-weight: 800;
            line-height: 1.25;
        }

        .response-subtitle {
            margin-top: 3px;
            color: var(--ui-muted);
            font-size: .71rem;
            line-height: 1.35;
        }

        .rating-pill {
            display: inline-flex;
            min-width: 52px;
            justify-content: center;
            padding: 6px 9px;
            border-radius: 999px;
            background: var(--ui-violet-soft);
            color: #5941a0;
            font-size: .75rem;
            font-weight: 850;
        }

        .outcome-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 72px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: .71rem;
            font-weight: 850;
        }

        .outcome-yes {
            background: var(--ui-green-soft);
            color: #0f6e4c;
        }

        .outcome-partially {
            background: var(--ui-amber-soft);
            color: #946114;
        }

        .outcome-no {
            background: var(--ui-red-soft);
            color: var(--ui-red);
        }

        .specific-response-card .btn-outline-primary {
            border-color: #b8d2e3;
            color: var(--ui-primary);
            border-radius: 9px;
            font-size: .75rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .specific-response-card .btn-outline-primary:hover {
            border-color: var(--ui-primary);
            background: var(--ui-primary);
            color: #ffffff;
        }

        /* ==================================================
           COMMENTS
        ================================================== */
        .comment-box {
            padding: 14px;
            border: 1px solid #e3e9f0;
            border-radius: 12px;
            background: var(--ui-surface-soft);
            color: var(--ui-text-soft);
            font-size: .81rem;
            line-height: 1.5;
        }

        .comment-box + .comment-box {
            margin-top: 10px;
        }

        .comment-meta {
            margin-bottom: 8px !important;
            color: var(--ui-muted);
            font-size: .69rem;
            font-weight: 700;
        }

        /* ==================================================
           EMPTY AND ALERT STATES
        ================================================== */
        .empty-data {
            padding: 50px 20px;
            color: var(--ui-muted);
            text-align: center;
        }

        .empty-data i {
            display: grid;
            width: 58px;
            height: 58px;
            margin: 0 auto 13px;
            place-items: center;
            border-radius: 16px;
            background: var(--ui-primary-soft);
            color: var(--ui-primary);
            font-size: 26px;
        }

        .empty-data h4 {
            color: var(--ui-text);
            font-size: 1rem;
            font-weight: 800;
        }

        .alert {
            border-radius: 12px;
            font-size: .84rem;
        }

        .print-only {
            display: none;
        }

        /* ==================================================
           RESPONSIVE
        ================================================== */
        @media (max-width: 1199px) {
            .metric-card {
                min-height: 128px;
            }
        }

        @media (max-width: 768px) {
            .content {
                padding: 14px 10px 30px;
            }

            .analytics-hero {
                align-items: flex-start;
                padding: 20px 18px;
            }

            .hero-actions {
                width: 100%;
            }

            .hero-actions .btn {
                flex: 1 1 150px;
            }

            .analytics-card > .card-body {
                padding: 16px;
            }

            .metric-card {
                min-height: 120px;
                padding: 15px;
            }

            .metric-value {
                font-size: 1.5rem;
            }

            .section-title {
                align-items: flex-start;
            }

            .table {
                min-width: 780px;
            }

            .specific-response-card .table {
                min-width: 1040px;
            }
        }

        @media (max-width: 520px) {
            .analytics-hero h1 {
                font-size: 1.35rem;
            }

            .analytics-eyebrow {
                font-size: .62rem;
            }

            .metric-label {
                font-size: .62rem;
            }

            .metric-value {
                font-size: 1.35rem;
            }
        }

        /* ==================================================
           PRINT
        ================================================== */
        @media print {
            body {
                background: #ffffff;
            }

            .sidebar,
            .hero-actions,
            .filter-card,
            .specific-response-card,
            .no-print {
                display: none !important;
            }

            .main,
            .content,
            .container-fluid,
            .analytics-page {
                width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .analytics-hero {
                margin-bottom: 12px;
                border-color: #b9c4d0;
                box-shadow: none;
            }

            .analytics-card {
                break-inside: avoid;
                box-shadow: none;
            }

            .analytics-card:hover {
                transform: none;
                box-shadow: none;
            }

            .table-responsive {
                overflow: visible;
            }

            .print-only {
                display: block;
            }

            * {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }

            .table-scroll,
            .comments-scroll {
                max-height: none;
                overflow: visible;
            }
        }

        /* ==================================================
           SCROLLABLE DATA + UX UPGRADES (many-data support)
        ================================================== */
        .filter-card {
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        .table-scroll {
            max-height: 440px;
            overflow: auto;
            overscroll-behavior: contain;
        }

        .table-scroll.table-tall {
            max-height: 560px;
        }

        .table-scroll thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            box-shadow: 0 1px 0 #dfe6ee;
        }

        .table-scroll::-webkit-scrollbar {
            height: 10px;
            width: 10px;
        }

        .table-scroll::-webkit-scrollbar-thumb {
            border-radius: 999px;
            background: #c3cfdf;
            border: 2px solid #ffffff;
        }

        .table-scroll::-webkit-scrollbar-track {
            background: #f7f9fc;
        }

        .comments-scroll {
            max-height: 480px;
            overflow: auto;
            overscroll-behavior: contain;
            padding-right: 4px;
        }

        .comments-scroll::-webkit-scrollbar {
            width: 10px;
        }

        .comments-scroll::-webkit-scrollbar-thumb {
            border-radius: 999px;
            background: #c3cfdf;
            border: 2px solid #ffffff;
        }

        .bar-fill {
            transition: width .6s ease;
        }

        .result-count {
            display: inline-flex;
            align-items: center;
            margin-left: auto;
            padding: 5px 11px;
            border-radius: 999px;
            background: var(--ui-primary-soft);
            color: var(--ui-primary-dark);
            font-size: .7rem;
            font-weight: 850;
            white-space: nowrap;
        }

        .section-title > div:last-child {
            min-width: 0;
        }

        #toTopBtn {
            position: fixed;
            right: 18px;
            bottom: 18px;
            z-index: 1050;
            display: none;
            width: 42px;
            height: 42px;
            place-items: center;
            border: 1px solid var(--ui-border);
            border-radius: 12px;
            background: var(--ui-surface);
            color: var(--ui-primary);
            font-size: 1rem;
            box-shadow: var(--ui-shadow);
        }

        #toTopBtn.show {
            display: grid;
        }

        #toTopBtn:hover {
            border-color: var(--ui-primary);
            background: var(--ui-primary);
            color: #ffffff;
        }

        kbd.search-hint {
            padding: 1px 7px;
            border: 1px solid var(--ui-border);
            border-bottom-width: 2px;
            border-radius: 6px;
            background: var(--ui-surface-soft);
            color: var(--ui-muted);
            font-size: .68rem;
            font-weight: 800;
        }

        @media print {
            .filter-card {
                position: static;
            }
        }

        /* ==================================================
           UX UPGRADE (additive — visuals only, no data change)
        ================================================== */
        .rm-crumb{display:flex;align-items:center;flex-wrap:wrap;gap:7px;margin:0 0 12px 2px;color:#94a3b8;font-size:.7rem;font-weight:650;}
        .rm-crumb a{display:inline-flex;align-items:center;gap:5px;color:#64748b;text-decoration:none;font-weight:750;}
        .rm-crumb a:hover{color:#1d5f91;text-decoration:underline;}
        .rm-crumb-sep{color:#cbd5e1;}
        .rm-crumb-current{color:#0f172a;font-weight:800;}
        .hero-pill{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:999px;background:var(--ui-green-soft);color:#0f6e4c;font-size:.72rem;font-weight:850;white-space:nowrap;}
        .search-clear{position:absolute;top:50%;right:9px;transform:translateY(-50%);border:0;background:#eef2f7;color:#64748b;width:22px;height:22px;border-radius:50%;display:none;align-items:center;justify-content:center;font-size:.62rem;cursor:pointer;z-index:3;}
        .search-clear.show{display:inline-flex;}
        .search-clear:hover{background:#dbe3ee;color:#0f172a;}
        .date-presets{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;}
        .date-presets .btn{font-size:.68rem;font-weight:800;padding:4px 10px;border-radius:999px;}
        .active-chips{display:none;gap:6px;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px solid #edf1f6;}
        .active-chips.show{display:flex;}
        .active-chips-label{color:var(--ui-muted);font-size:.66rem;font-weight:800;align-self:center;}
        .filter-chip{display:inline-flex;align-items:center;gap:5px;max-width:280px;padding:4px 5px 4px 10px;border-radius:999px;background:var(--ui-primary-soft);color:var(--ui-primary-dark);font-size:.66rem;font-weight:750;}
        .filter-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        .filter-chip a{display:grid;width:18px;height:18px;place-items:center;border-radius:50%;background:rgba(29,95,145,.14);color:inherit;text-decoration:none;font-size:.6rem;flex:0 0 18px;}
        .filter-chip a:hover{background:var(--ui-primary);color:#fff;}
        .metric-sub{position:relative;z-index:1;margin-top:4px;color:var(--ui-muted);font-size:.68rem;font-weight:700;}
        .donut-wrap{display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:6px;padding:12px;border:1px solid #e6ebf1;border-radius:12px;background:var(--ui-surface-soft);}
        .donut{--size:118px;width:var(--size);height:var(--size);flex:0 0 var(--size);border-radius:50%;background:conic-gradient(#138a62 0 0);position:relative;display:grid;place-items:center;}
        .donut::after{content:"";position:absolute;inset:16px;border-radius:50%;background:#fff;}
        .donut-center{position:relative;z-index:1;text-align:center;line-height:1.1;}
        .donut-center b{display:block;font-size:1.25rem;font-weight:850;letter-spacing:-.02em;}
        .donut-center small{color:var(--ui-muted);font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;}
        .donut-legend{display:grid;gap:6px;min-width:180px;flex:1;font-size:.76rem;}
        .donut-legend .leg-row{display:flex;align-items:center;gap:8px;color:var(--ui-text-soft);font-weight:700;}
        .leg-dot{width:11px;height:11px;flex:0 0 11px;border-radius:4px;}
        .leg-row span:last-child{margin-left:auto;color:var(--ui-muted);font-weight:700;}
        .spark-wrap{display:flex;align-items:flex-end;gap:5px;height:64px;margin-bottom:4px;padding:10px 12px;border:1px solid #e6ebf1;border-radius:12px;background:var(--ui-surface-soft);}
        .spark-bar{flex:1;min-width:8px;border-radius:5px 5px 2px 2px;background:linear-gradient(180deg,#2f7ba8,#1d5f91);min-height:4px;position:relative;transition:height .5s ease;}
        .spark-bar:hover{filter:brightness(1.15);}
        .spark-cap{text-align:center;color:var(--ui-muted);font-size:.64rem;font-weight:700;}
        .resp-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px;padding:10px 12px;border:1px solid #e6ebf1;border-radius:12px;background:var(--ui-surface-soft);}
        .resp-toolbar .mini-search{position:relative;flex:1 1 200px;min-width:170px;}
        .resp-toolbar .mini-search i{position:absolute;top:50%;left:10px;transform:translateY(-50%);color:#98a2b3;font-size:.75rem;}
        .resp-toolbar .mini-search input{width:100%;min-height:36px;padding:7px 10px 7px 30px;border:1px solid var(--ui-border);border-radius:9px;font-size:.76rem;background:#fff;color:var(--ui-text);}
        .pill-group{display:flex;gap:5px;flex-wrap:wrap;}
        .pill-btn{border:1px solid var(--ui-border);background:#fff;color:var(--ui-text-soft);border-radius:999px;font-size:.68rem;font-weight:800;padding:5px 11px;cursor:pointer;transition:.14s;}
        .pill-btn:hover{border-color:#7eb0cf;color:var(--ui-primary-dark);}
        .pill-btn.is-active{background:var(--ui-primary);border-color:var(--ui-primary);color:#fff;}
        .pill-btn.is-active.amber{background:var(--ui-amber);border-color:var(--ui-amber);}
        .pill-btn.is-active.green{background:var(--ui-green);border-color:var(--ui-green);}
        .resp-live{margin-left:auto;font-size:.68rem;font-weight:800;color:var(--ui-primary-dark);white-space:nowrap;}
        .dept-bar{height:6px;border-radius:999px;background:#edf2f7;margin-top:6px;overflow:hidden;}
        .dept-bar i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#1d5f91,#3b82a8);}
        .comment-tools{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:12px;}
        .comment-copy{border:1px solid var(--ui-border);background:#fff;border-radius:8px;font-size:.64rem;font-weight:800;color:var(--ui-muted);padding:3px 9px;cursor:pointer;margin-top:8px;}
        .comment-copy:hover{border-color:var(--ui-primary);color:var(--ui-primary-dark);}
        .toast-mini{position:fixed;left:50%;bottom:22px;transform:translateX(-50%) translateY(10px);background:#0f172a;color:#fff;font-size:.72rem;font-weight:750;padding:9px 14px;border-radius:10px;opacity:0;pointer-events:none;transition:.2s ease;z-index:1080;}
        .toast-mini.show{opacity:1;transform:translateX(-50%) translateY(0);}
        #printSummary{display:none;}
        @media print {
            #printSummary{display:block;margin-bottom:12px;padding:12px 14px;border:1px solid #b9c4d0;border-radius:12px;font-size:.78rem;}
            #printSummary h2{font-size:1rem;margin:0 0 4px;}
            #printSummary p{margin:0;color:#334155;}
        }
        @media (max-width: 768px) {
            .resp-live{margin-left:0;width:100%;}
            .donut{--size:100px;}
        }
    </style>

</head>

<body class="app-shell">

<div class="d-flex min-vh-100">

    <?php include "../includes/sidebar.php"; ?>

    <div class="main flex-grow-1">

        <main class="content">

            <div class="container-fluid">

                <div class="analytics-page">

                    <nav class="rm-crumb" aria-label="Breadcrumb">
                        <a href="<?= e(BASE_URL) ?>/dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a>
                        <span class="rm-crumb-sep">/</span>
                        <span class="rm-crumb-current" aria-current="page">Survey Analytics</span>
                    </nav>

                    <div id="printSummary" aria-hidden="true">
                        <h2>Consolidated Survey Analytics — Summary</h2>
                        <p>
                            Generated <?= e(date("M d, Y h:i A")) ?>
                            · Responses: <?= (int) $summary["total_responses"] ?>
                            · Overall: <?= number_format((float) $summary["average_overall"], 2) ?>/5
                            · Objective achieved: <?= number_format((float) $summary["positive_outcome"], 1) ?>%
                            <?php if ($search !== "" || $startDate !== "" || $endDate !== "" || $departmentFilter !== ""): ?>
                                · Filters:
                                <?= e(trim(($search !== "" ? "search “" . $search . "” " : "") . ($startDate !== "" || $endDate !== "" ? ($startDate !== "" ? $startDate : "…") . " → " . ($endDate !== "" ? $endDate : "…") . " " : "") . ($departmentFilter !== "" ? $departmentFilter : ""))) ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <section class="analytics-hero">

                        <div class="analytics-hero-copy">

                            <div class="analytics-eyebrow">
                                <i class="bi bi-heart-pulse"></i>
                                PHU Client Experience
                            </div>

                            <h1>
                                Consolidated Survey Analytics
                            </h1>

                            <p>
                                Review satisfaction trends, service
                                outcomes and individual submitted
                                survey records for the selected period.
                            </p>

                        </div>

                        <div class="hero-actions">

                            <span class="hero-pill" title="Survey responses in current filter">
                                <i class="bi bi-clipboard-data"></i>
                                <?= (int) $summary["total_responses"] ?> response<?= (int) $summary["total_responses"] === 1 ? "" : "s" ?>
                            </span>

                            <?php if ($summary["total_responses"] > 0): ?>

                                <a
                                    type="button"
                                    class="btn btn-light"
                                    href="?<?= e(
                                        http_build_query(
                                            array_merge(
                                                $_GET,
                                                ["export" => "csv"]
                                            )
                                        )
                                    ) ?>"
                                >
                                    <i class="bi bi-filetype-csv me-1"></i>
                                    Export CSV
                                </a>

                            <?php endif; ?>

                            <button
                                type="button"
                                class="btn btn-light"
                                onclick="window.print()"
                            >
                                <i class="bi bi-printer me-1"></i>
                                Print Summary
                            </button>

                            <a
                                class="btn btn-outline-light"
                                href="<?= e(BASE_URL) ?>/dashboard.php"
                            >
                                <i class="bi bi-arrow-left me-1"></i>
                                Dashboard
                            </a>

                        </div>

                    </section>

                    <?php if (!$surveyTableReady): ?>

                        <div class="alert alert-warning">

                            <strong>
                                Survey table not found.
                            </strong>

                            Import
                            <code>
                                job_request_client_experience.sql
                            </code>
                            before opening this dashboard.

                        </div>

                    <?php else: ?>

                        <!-- FILTERS -->
                        <section
                            class="analytics-card filter-card"
                        >

                            <div class="card-body">

                                <form method="GET">

                                    <div
                                        class="row g-3 align-items-end"
                                    >

                                        <div class="col-xl-4">

                                            <label
                                                class="form-label"
                                            >
                                                Search Requestor or Job
                                            </label>

                                            <div
                                                class="input-group"
                                            >

                                                <span
                                                    class="input-group-text"
                                                >
                                                    <i
                                                        class="bi bi-search"
                                                    ></i>
                                                </span>

                                                <input
                                                    class="form-control"
                                                    id="surveySearchInput"
                                                    name="search"
                                                    value="<?= e(
                                                        $search
                                                    ) ?>"
                                                    placeholder="Name, job number, project or department"
                                                    autocomplete="off"
                                                >

                                                <button type="button" class="search-clear" id="surveySearchClear" title="Clear search" aria-label="Clear search"><i class="bi bi-x-lg"></i></button>

                                            </div>

                                            <div class="form-text mt-1">
                                                Press <kbd class="search-hint">/</kbd> to jump to search.
                                            </div>

                                        </div>

                                        <div class="col-md-3 col-xl-2">

                                            <label
                                                class="form-label"
                                            >
                                                Start Date
                                            </label>

                                            <input
                                                type="date"
                                                class="form-control"
                                                name="start_date"
                                                value="<?= e(
                                                    $startDate
                                                ) ?>"
                                            >

                                        </div>

                                        <div class="col-md-3 col-xl-2">

                                            <label
                                                class="form-label"
                                            >
                                                End Date
                                            </label>

                                            <input
                                                type="date"
                                                class="form-control"
                                                name="end_date"
                                                value="<?= e(
                                                    $endDate
                                                ) ?>"
                                            >

                                        </div>

                                        <div class="col-md-4 col-xl-2">

                                            <label
                                                class="form-label"
                                            >
                                                Department
                                            </label>

                                            <select
                                                class="form-select"
                                                name="department"
                                            >

                                                <option value="">
                                                    All Departments
                                                </option>

                                                <?php foreach (
                                                    $departments
                                                    as $department
                                                ): ?>

                                                    <option
                                                        value="<?= e(
                                                            (string) $department[
                                                                "department"
                                                            ]
                                                        ) ?>"
                                                        <?= $departmentFilter
                                                            ===
                                                            $department[
                                                                "department"
                                                            ]
                                                            ? "selected"
                                                            : ""
                                                        ?>
                                                    >
                                                        <?= e(
                                                            (string) $department[
                                                                "department"
                                                            ]
                                                        ) ?>
                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                        </div>

                                        <div class="col-md-2">

                                            <div class="d-flex gap-2">

                                                <button
                                                    type="submit"
                                                    class="btn btn-primary flex-grow-1"
                                                >
                                                    <i
                                                        class="bi bi-funnel me-1"
                                                    ></i>
                                                    Apply
                                                </button>

                                                <a
                                                    class="btn btn-outline-secondary"
                                                    href="survey_analytics.php"
                                                    title="Reset filters"
                                                >
                                                    <i
                                                        class="bi bi-arrow-counterclockwise"
                                                    ></i>
                                                </a>

                                            </div>

                                        </div>

                                    </div>

                                    <div class="date-presets" role="group" aria-label="Quick date ranges">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-range="7">Last 7 days</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-range="30">Last 30 days</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-range="90">Last 90 days</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-range="month">This month</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-range="clear">Clear dates</button>
                                    </div>

                                    <div class="active-chips" id="surveyActiveChips" aria-label="Active filters"></div>

                                </form>

                            </div>

                        </section>

                        <!-- METRICS -->
                        <div class="row g-3 mb-4">

                            <?php
                            $metrics = [
                                [
                                    "icon" =>
                                        "bi-clipboard-data",
                                    "label" =>
                                        "Total Responses",
                                    "value" =>
                                        (int) $summary[
                                            "total_responses"
                                        ],
                                    "suffix" => ""
                                ],
                                [
                                    "icon" =>
                                        "bi-star-fill",
                                    "label" =>
                                        "Overall Rating",
                                    "value" =>
                                        number_format(
                                            (float) $summary[
                                                "average_overall"
                                            ],
                                            2
                                        ),
                                    "suffix" => "/ 5"
                                ],
                                [
                                    "icon" =>
                                        "bi-speedometer2",
                                    "label" =>
                                        "Service Average",
                                    "value" =>
                                        number_format(
                                            (float) $summary[
                                                "average_service"
                                            ],
                                            2
                                        ),
                                    "suffix" => "/ 5"
                                ],
                                [
                                    "icon" =>
                                        "bi-check-circle",
                                    "label" =>
                                        "Objective Achieved",
                                    "value" =>
                                        number_format(
                                            (float) $summary[
                                                "positive_outcome"
                                            ],
                                            1
                                        ),
                                    "suffix" => "%"
                                ],
                                [
                                    "icon" =>
                                        "bi-calendar-check",
                                    "label" =>
                                        "This Month",
                                    "value" =>
                                        (int) $summary[
                                            "this_month"
                                        ],
                                    "suffix" => ""
                                ]
                            ];
                            ?>

                            <?php foreach (
                                $metrics as $metric
                            ): ?>

                                <div
                                    class="col-6 col-md-4 col-xl"
                                >

                                    <div
                                        class="analytics-card metric-card"
                                    >

                                        <div class="metric-icon">

                                            <i
                                                class="bi <?= e(
                                                    $metric["icon"]
                                                ) ?>"
                                            ></i>

                                        </div>

                                        <div class="metric-label">
                                            <?= e(
                                                $metric["label"]
                                            ) ?>
                                        </div>

                                        <div class="metric-value">

                                            <?= e(
                                                (string) $metric[
                                                    "value"
                                                ]
                                            ) ?>

                                            <?php if (
                                                $metric["suffix"] !== ""
                                            ): ?>

                                                <small class="fs-6">
                                                    <?= e(
                                                        $metric[
                                                            "suffix"
                                                        ]
                                                    ) ?>
                                                </small>

                                            <?php endif; ?>

                                        </div>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                        <?php if (
                            $summary["total_responses"] <= 0
                        ): ?>

                            <div class="analytics-card">

                                <div class="empty-data">

                                    <i class="bi bi-inbox"></i>

                                    <h4>
                                        No survey responses found
                                    </h4>

                                    <p class="mb-0">
                                        Adjust the filters or wait for
                                        requestors to submit surveys.
                                    </p>

                                </div>

                            </div>

                        <?php else: ?>

                            <!-- CRITERIA + RATING -->
                            <div class="row g-3 mb-4">

                                <div class="col-lg-7">

                                    <section
                                        class="analytics-card"
                                    >

                                        <div class="card-body">

                                            <div
                                                class="section-title"
                                            >

                                                <div
                                                    class="section-title-icon"
                                                >
                                                    <i
                                                        class="bi bi-bar-chart"
                                                    ></i>
                                                </div>

                                                <div>

                                                    <h2>
                                                        Service Criteria
                                                        Averages
                                                    </h2>

                                                    <p>
                                                        Mean score for
                                                        each service
                                                        criterion.
                                                    </p>

                                                </div>

                                            </div>

                                            <?php foreach (
                                                $criteriaRows
                                                as $criterion
                                            ): ?>

                                                <div class="bar-row">

                                                    <div
                                                        class="bar-label"
                                                    >

                                                        <span>
                                                            <?= e(
                                                                $criterion[
                                                                    "label"
                                                                ]
                                                            ) ?>
                                                        </span>

                                                        <span>
                                                            <?= number_format(
                                                                (float) $criterion[
                                                                    "value"
                                                                ],
                                                                2
                                                            ) ?>
                                                            / 5
                                                        </span>

                                                    </div>

                                                    <div
                                                        class="bar-track"
                                                    >

                                                        <div
                                                            class="bar-fill"
                                                            style="width: <?= surveyAnalyticsPercent(
                                                                (float) $criterion[
                                                                    "value"
                                                                ]
                                                            ) ?>%"
                                                        ></div>

                                                    </div>

                                                </div>

                                            <?php endforeach; ?>

                                        </div>

                                    </section>

                                </div>

                                <div class="col-lg-5">

                                    <section
                                        class="analytics-card"
                                    >

                                        <div class="card-body">

                                            <div
                                                class="section-title"
                                            >

                                                <div
                                                    class="section-title-icon"
                                                >
                                                    <i
                                                        class="bi bi-star"
                                                    ></i>
                                                </div>

                                                <div>

                                                    <h2>
                                                        Overall Rating
                                                        Distribution
                                                    </h2>

                                                    <p>
                                                        Response count
                                                        and percentage
                                                        per score.
                                                    </p>

                                                </div>

                                            </div>

                                            <?php foreach (
                                                $ratingDistribution
                                                as $ratingRow
                                            ): ?>

                                                <div class="bar-row">

                                                    <div
                                                        class="bar-label"
                                                    >

                                                        <span>
                                                            <?= (int) $ratingRow[
                                                                "rating"
                                                            ] ?>
                                                            Star
                                                        </span>

                                                        <span>
                                                            <?= (int) $ratingRow[
                                                                "count"
                                                            ] ?>
                                                            ·
                                                            <?= number_format(
                                                                (float) $ratingRow[
                                                                    "percentage"
                                                                ],
                                                                1
                                                            ) ?>%
                                                        </span>

                                                    </div>

                                                    <div
                                                        class="bar-track"
                                                    >

                                                        <div
                                                            class="bar-fill"
                                                            style="width: <?= (float) $ratingRow[
                                                                "percentage"
                                                            ] ?>%"
                                                        ></div>

                                                    </div>

                                                </div>

                                            <?php endforeach; ?>

                                        </div>

                                    </section>

                                </div>

                            </div>

                            <!-- OUTCOME + SERVICES -->
                            <div class="row g-3 mb-4">

                                <div class="col-lg-5">

                                    <section
                                        class="analytics-card"
                                    >

                                        <div class="card-body">

                                            <div
                                                class="section-title"
                                            >

                                                <div
                                                    class="section-title-icon"
                                                >
                                                    <i
                                                        class="bi bi-bullseye"
                                                    ></i>
                                                </div>

                                                <div>

                                                    <h2>
                                                        Outcome Assessment
                                                    </h2>

                                                    <p>
                                                        Did the service
                                                        help achieve the
                                                        intended
                                                        objective?
                                                    </p>

                                                </div>

                                            </div>

                                            <div class="donut-wrap" id="outcomeDonut" role="img" aria-label="Outcome distribution chart"></div>

                                            <?php foreach (
                                                $outcomeDistribution
                                                as $outcomeRow
                                            ): ?>

                                                <div class="bar-row">

                                                    <div
                                                        class="bar-label"
                                                    >

                                                        <span>
                                                            <?= e(
                                                                $outcomeRow[
                                                                    "outcome"
                                                                ]
                                                            ) ?>
                                                        </span>

                                                        <span>
                                                            <?= (int) $outcomeRow[
                                                                "count"
                                                            ] ?>
                                                            ·
                                                            <?= number_format(
                                                                (float) $outcomeRow[
                                                                    "percentage"
                                                                ],
                                                                1
                                                            ) ?>%
                                                        </span>

                                                    </div>

                                                    <div
                                                        class="bar-track"
                                                    >

                                                        <div
                                                            class="bar-fill"
                                                            style="width: <?= (float) $outcomeRow[
                                                                "percentage"
                                                            ] ?>%"
                                                        ></div>

                                                    </div>

                                                </div>

                                            <?php endforeach; ?>

                                        </div>

                                    </section>

                                </div>

                                <div class="col-lg-7">

                                    <section
                                        class="analytics-card"
                                    >

                                        <div class="card-body">

                                            <div
                                                class="section-title"
                                            >

                                                <div
                                                    class="section-title-icon"
                                                >
                                                    <i
                                                        class="bi bi-grid"
                                                    ></i>
                                                </div>

                                                <div>

                                                    <h2>
                                                        Services Evaluated
                                                    </h2>

                                                    <p>
                                                        Frequency of
                                                        PHU services
                                                        selected in the
                                                        surveys.
                                                    </p>

                                                </div>

                                            </div>

                                            <div
                                                class="table-responsive table-scroll"
                                            >

                                                <table
                                                    class="table table-hover mb-0"
                                                >

                                                    <thead>
                                                        <tr>
                                                            <th>
                                                                Service
                                                            </th>
                                                            <th
                                                                class="text-end"
                                                            >
                                                                Count
                                                            </th>
                                                        </tr>
                                                    </thead>

                                                    <tbody>

                                                        <?php foreach (
                                                            $serviceCounts
                                                            as $service =>
                                                                $count
                                                        ): ?>

                                                            <tr>

                                                                <td>
                                                                    <?= e(
                                                                        $service
                                                                    ) ?>
                                                                </td>

                                                                <td
                                                                    class="text-end"
                                                                >
                                                                    <span
                                                                        class="rating-pill"
                                                                    >
                                                                        <?= (int) $count ?>
                                                                    </span>
                                                                </td>

                                                            </tr>

                                                        <?php endforeach; ?>

                                                    </tbody>

                                                </table>

                                            </div>

                                        </div>

                                    </section>

                                </div>

                            </div>

                            <!-- SPECIFIC REQUESTOR RESPONSE LIST -->
                            <section
                                class="analytics-card specific-response-card mb-4"
                            >

                                <div class="card-body">

                                    <div
                                        class="section-title"
                                    >

                                        <div
                                            class="section-title-icon"
                                        >
                                            <i
                                                class="bi bi-person-lines-fill"
                                            ></i>
                                        </div>

                                        <div>

                                            <h2>
                                                Submitted Survey Records
                                            </h2>

                                            <p>
                                                Select a specific
                                                requestor and print the
                                                completed survey in the
                                                official form-style
                                                layout.
                                            </p>

                                        </div>

                                        <span class="result-count" id="respServerCount">
                                            <?= count($responseRows) ?> shown · latest 100
                                        </span>

                                    </div>

                                    <div class="resp-toolbar" role="group" aria-label="Filter survey records">
                                        <div class="mini-search">
                                            <i class="bi bi-search"></i>
                                            <input id="respInstantSearch" type="text" placeholder="Filter these records…" autocomplete="off" aria-label="Filter records">
                                        </div>
                                        <div class="pill-group" id="respRatingPills" role="group" aria-label="Filter by rating">
                                            <button type="button" class="pill-btn is-active" data-rating="all">All ratings</button>
                                            <button type="button" class="pill-btn" data-rating="5">5★</button>
                                            <button type="button" class="pill-btn" data-rating="4">4★</button>
                                            <button type="button" class="pill-btn" data-rating="3">3★</button>
                                            <button type="button" class="pill-btn" data-rating="2">2★ & below</button>
                                        </div>
                                        <div class="pill-group" id="respOutcomePills" role="group" aria-label="Filter by outcome">
                                            <button type="button" class="pill-btn is-active green" data-outcome="all">All outcomes</button>
                                            <button type="button" class="pill-btn green" data-outcome="Yes">Yes</button>
                                            <button type="button" class="pill-btn amber" data-outcome="Partially">Partially</button>
                                            <button type="button" class="pill-btn" data-outcome="No">No</button>
                                        </div>
                                        <span class="resp-live" id="respLiveCount" aria-live="polite"></span>
                                    </div>

                                    <div
                                        class="table-responsive table-scroll table-tall"
                                    >

                                        <table
                                            class="table table-hover align-middle mb-0"
                                        >

                                            <thead>
                                                <tr>
                                                    <th>
                                                        Requestor
                                                    </th>
                                                    <th>
                                                        Job Request
                                                    </th>
                                                    <th>
                                                        Department
                                                    </th>
                                                    <th
                                                        class="text-center"
                                                    >
                                                        Rating
                                                    </th>
                                                    <th
                                                        class="text-center"
                                                    >
                                                        Outcome
                                                    </th>
                                                    <th>
                                                        Submitted
                                                    </th>
                                                    <th
                                                        class="text-end"
                                                    >
                                                        Action
                                                    </th>
                                                </tr>
                                            </thead>

                                            <tbody>

                                                <?php foreach (
                                                    $responseRows as $row
                                                ): ?>

                                                    <?php
                                                    $outcomeClass =
                                                        match (
                                                            (string) $row[
                                                                "outcome_assessment"
                                                            ]
                                                        ) {
                                                            "Yes" =>
                                                                "outcome-yes",

                                                            "Partially" =>
                                                                "outcome-partially",

                                                            default =>
                                                                "outcome-no"
                                                        };
                                                    ?>

                                                    <tr>

                                                        <td>

                                                            <div
                                                                class="response-title"
                                                            >
                                                                <?= e(
                                                                    (string) (
                                                                        $row[
                                                                            "requestor_name"
                                                                        ]
                                                                        ?: "Unnamed Requestor"
                                                                    )
                                                                ) ?>
                                                            </div>

                                                            <div
                                                                class="response-subtitle"
                                                            >
                                                                Survey ID:
                                                                <?= (int) $row[
                                                                    "survey_id"
                                                                ] ?>
                                                            </div>

                                                        </td>

                                                        <td>

                                                            <div
                                                                class="response-title"
                                                            >
                                                                <?= e(
                                                                    (string) (
                                                                        $row[
                                                                            "job_no"
                                                                        ]
                                                                        ?: "Request #" .
                                                                            $row[
                                                                                "request_id"
                                                                            ]
                                                                    )
                                                                ) ?>
                                                            </div>

                                                            <div
                                                                class="response-subtitle"
                                                            >
                                                                <?= e(
                                                                    (string) $row[
                                                                        "project_title"
                                                                    ]
                                                                ) ?>
                                                            </div>

                                                        </td>

                                                        <td>
                                                            <?= e(
                                                                (string) (
                                                                    $row[
                                                                        "department"
                                                                    ]
                                                                    ?: "Unspecified"
                                                                )
                                                            ) ?>
                                                        </td>

                                                        <td
                                                            class="text-center"
                                                        >
                                                            <span
                                                                class="rating-pill"
                                                            >
                                                                <?= (int) $row[
                                                                    "overall_satisfaction"
                                                                ] ?>
                                                                / 5
                                                            </span>
                                                        </td>

                                                        <td
                                                            class="text-center"
                                                        >
                                                            <span
                                                                class="outcome-pill <?= e(
                                                                    $outcomeClass
                                                                ) ?>"
                                                            >
                                                                <?= e(
                                                                    (string) $row[
                                                                        "outcome_assessment"
                                                                    ]
                                                                ) ?>
                                                            </span>
                                                        </td>

                                                        <td>
                                                            <?= e(
                                                                date(
                                                                    "M d, Y h:i A",
                                                                    strtotime(
                                                                        (string) $row[
                                                                            "submitted_at"
                                                                        ]
                                                                    )
                                                                )
                                                            ) ?>
                                                        </td>

                                                        <td
                                                            class="text-end"
                                                        >
                                                            <a
                                                                class="btn btn-sm btn-outline-primary"
                                                                href="<?= e(
                                                                    BASE_URL
                                                                ) ?>/staff/print_client_experience.php?survey_id=<?= (int) $row["survey_id"] ?>"
                                                                target="_blank"
                                                            >
                                                                <i
                                                                    class="bi bi-printer me-1"
                                                                ></i>
                                                                Print Form
                                                            </a>
                                                        </td>

                                                    </tr>

                                                <?php endforeach; ?>

                                            </tbody>

                                        </table>

                                    </div>

                                </div>

                            </section>

                            <!-- DEPARTMENT SUMMARY -->
                            <section
                                class="analytics-card mb-4"
                            >

                                <div class="card-body">

                                    <div
                                        class="section-title"
                                    >

                                        <div
                                            class="section-title-icon"
                                        >
                                            <i
                                                class="bi bi-building"
                                            ></i>
                                        </div>

                                        <div>

                                            <h2>
                                                Department Summary
                                            </h2>

                                            <p>
                                                Consolidated responses
                                                and averages by
                                                requestor department.
                                            </p>

                                        </div>

                                        <span class="result-count">
                                            <?= count($departmentRows) ?> department(s)
                                        </span>

                                    </div>

                                    <div
                                        class="table-responsive table-scroll"
                                    >

                                        <table
                                            class="table table-hover mb-0"
                                        >

                                            <thead>
                                                <tr>
                                                    <th>
                                                        Department
                                                    </th>
                                                    <th
                                                        class="text-center"
                                                    >
                                                        Responses
                                                    </th>
                                                    <th
                                                        class="text-center"
                                                    >
                                                        Overall Rating
                                                    </th>
                                                    <th
                                                        class="text-center"
                                                    >
                                                        Service Average
                                                    </th>
                                                </tr>
                                            </thead>

                                            <tbody>

                                                <?php foreach (
                                                    $departmentRows
                                                    as $row
                                                ): ?>

                                                    <tr>

                                                        <td>
                                                            <strong>
                                                                <?= e(
                                                                    (string) $row[
                                                                        "department"
                                                                    ]
                                                                ) ?>
                                                            </strong>
                                                        </td>

                                                        <td
                                                            class="text-center"
                                                        >
                                                            <?= (int) $row[
                                                                "total_responses"
                                                            ] ?>
                                                        </td>

                                                        <td
                                                            class="text-center"
                                                        >
                                                            <span
                                                                class="rating-pill"
                                                            >
                                                                <?= number_format(
                                                                    (float) $row[
                                                                        "average_rating"
                                                                    ],
                                                                    2
                                                                ) ?>
                                                            </span>
                                                        </td>

                                                        <td
                                                            class="text-center"
                                                        >
                                                            <span
                                                                class="rating-pill"
                                                            >
                                                                <?= number_format(
                                                                    (float) $row[
                                                                        "average_service"
                                                                    ],
                                                                    2
                                                                ) ?>
                                                            </span>
                                                        </td>

                                                    </tr>

                                                <?php endforeach; ?>

                                            </tbody>

                                        </table>

                                    </div>

                                </div>

                            </section>

                            <div class="row g-3 mb-4">

                                <!-- MONTHLY TREND -->
                                <div class="col-lg-6">

                                    <section
                                        class="analytics-card"
                                    >

                                        <div class="card-body">

                                            <div
                                                class="section-title"
                                            >

                                                <div
                                                    class="section-title-icon"
                                                >
                                                    <i
                                                        class="bi bi-calendar3"
                                                    ></i>
                                                </div>

                                                <div>

                                                    <h2>
                                                        Monthly Trend
                                                    </h2>

                                                    <p>
                                                        Latest 12 months
                                                        of response
                                                        activity.
                                                    </p>

                                                </div>

                                                <span class="result-count" id="trendPeak"></span>

                                            </div>

                                            <div class="spark-wrap" id="trendSpark" role="img" aria-label="Monthly response volume"></div>
                                            <div class="spark-cap" id="trendSparkCap"></div>

                                            <div
                                                class="table-responsive table-scroll"
                                            >

                                                <table
                                                    class="table table-hover mb-0"
                                                >

                                                    <thead>
                                                        <tr>
                                                            <th>
                                                                Month
                                                            </th>
                                                            <th
                                                                class="text-center"
                                                            >
                                                                Responses
                                                            </th>
                                                            <th
                                                                class="text-center"
                                                            >
                                                                Rating
                                                            </th>
                                                        </tr>
                                                    </thead>

                                                    <tbody>

                                                        <?php foreach (
                                                            $monthlyRows
                                                            as $row
                                                        ): ?>

                                                            <tr>

                                                                <td>
                                                                    <?= e(
                                                                        (string) $row[
                                                                            "month_label"
                                                                        ]
                                                                    ) ?>
                                                                </td>

                                                                <td
                                                                    class="text-center"
                                                                >
                                                                    <?= (int) $row[
                                                                        "total_responses"
                                                                    ] ?>
                                                                </td>

                                                                <td
                                                                    class="text-center"
                                                                >
                                                                    <span
                                                                        class="rating-pill"
                                                                    >
                                                                        <?= number_format(
                                                                            (float) $row[
                                                                                "average_rating"
                                                                            ],
                                                                            2
                                                                        ) ?>
                                                                    </span>
                                                                </td>

                                                            </tr>

                                                        <?php endforeach; ?>

                                                    </tbody>

                                                </table>

                                            </div>

                                        </div>

                                    </section>

                                </div>

                                <!-- COMMENTS -->
                                <div class="col-lg-6">

                                    <section
                                        class="analytics-card"
                                    >

                                        <div class="card-body">

                                            <div
                                                class="section-title"
                                            >

                                                <div
                                                    class="section-title-icon"
                                                >
                                                    <i
                                                        class="bi bi-chat-left-text"
                                                    ></i>
                                                </div>

                                                <div>

                                                    <h2>
                                                        Recent Comments
                                                    </h2>

                                                    <p>
                                                        Consolidated
                                                        service feedback.
                                                    </p>

                                                </div>

                                            </div>

                                            <?php if (
                                                empty($commentRows)
                                            ): ?>

                                                <p
                                                    class="text-muted mb-0"
                                                >
                                                    No comments
                                                    available.
                                                </p>

                                            <?php else: ?>

                                                <div class="comment-tools pill-group" id="commentFilterPills" role="group" aria-label="Filter comments">
                                                    <button type="button" class="pill-btn is-active" data-cfilter="all">All comments</button>
                                                    <button type="button" class="pill-btn green" data-cfilter="praise">Did well</button>
                                                    <button type="button" class="pill-btn amber" data-cfilter="improve">Can improve</button>
                                                </div>

                                                <div class="comments-scroll" id="commentsScroll">

                                                <?php foreach (
                                                    $commentRows
                                                    as $comment
                                                ): ?>

                                                    <div
                                                        class="comment-box"
                                                    >

                                                        <div
                                                            class="comment-meta mb-2"
                                                        >
                                                            <?= e(
                                                                (string) (
                                                                    $comment[
                                                                        "department"
                                                                    ]
                                                                    ?: "Unspecified Department"
                                                                )
                                                            ) ?>
                                                            ·
                                                            <?= e(
                                                                date(
                                                                    "M d, Y",
                                                                    strtotime(
                                                                        (string) $comment[
                                                                            "submitted_at"
                                                                        ]
                                                                    )
                                                                )
                                                            ) ?>
                                                        </div>

                                                        <?php if (
                                                            trim(
                                                                (string) $comment[
                                                                    "phu_did_well"
                                                                ]
                                                            ) !== ""
                                                        ): ?>

                                                            <div
                                                                class="mb-2"
                                                            >
                                                                <strong>
                                                                    PHU did well:
                                                                </strong>

                                                                <?= nl2br(
                                                                    e(
                                                                        (string) $comment[
                                                                            "phu_did_well"
                                                                        ]
                                                                    )
                                                                ) ?>
                                                            </div>

                                                        <?php endif; ?>

                                                        <?php if (
                                                            trim(
                                                                (string) $comment[
                                                                    "phu_improve"
                                                                ]
                                                            ) !== ""
                                                        ): ?>

                                                            <div>

                                                                <strong>
                                                                    PHU can improve:
                                                                </strong>

                                                                <?= nl2br(
                                                                    e(
                                                                        (string) $comment[
                                                                            "phu_improve"
                                                                        ]
                                                                    )
                                                                ) ?>

                                                            </div>

                                                        <?php endif; ?>

                                                    </div>

                                                <?php endforeach; ?>

                                                </div>

                                            <?php endif; ?>

                                        </div>

                                    </section>

                                </div>

                            </div>

                        <?php endif; ?>

                    <?php endif; ?>

                </div>

            </div>

        </main>

    </div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
></script>

<button id="toTopBtn" type="button" title="Back to top" aria-label="Back to top">
    <i class="bi bi-arrow-up"></i>
</button>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        // Press "/" to jump to search.
        var searchInput = document.getElementById("surveySearchInput");

        document.addEventListener("keydown", function (ev) {
            if (
                ev.key === "/"
                && searchInput
                && document.activeElement !== searchInput
                && !/INPUT|TEXTAREA|SELECT/.test(
                    document.activeElement.tagName
                )
            ) {
                ev.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        });

        // Back-to-top visibility.
        var toTop = document.getElementById("toTopBtn");

        window.addEventListener("scroll", function () {
            toTop.classList.toggle(
                "show",
                window.scrollY > 600
            );
        }, { passive: true });

        toTop.addEventListener("click", function () {
            window.scrollTo({
                top: 0,
                behavior: "smooth"
            });
        });

        /* ---------- toast ---------- */
        var toast = document.createElement("div");
        toast.className = "toast-mini";
        document.body.appendChild(toast);
        var toastTimer = null;
        function showToast(msg) {
            toast.textContent = msg;
            toast.classList.add("show");
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function () {
                toast.classList.remove("show");
            }, 1600);
        }

        /* ---------- search clear + esc ---------- */
        var searchClear = document.getElementById("surveySearchClear");
        function syncSearchClear() {
            if (searchClear && searchInput) {
                searchClear.classList.toggle(
                    "show",
                    !!(searchInput.value || "").trim()
                );
            }
        }
        if (searchInput) {
            searchInput.addEventListener("input", syncSearchClear);
            syncSearchClear();
            document.addEventListener("keydown", function (ev) {
                if (
                    ev.key === "Escape"
                    && document.activeElement === searchInput
                ) {
                    searchInput.value = "";
                    syncSearchClear();
                    searchInput.focus();
                }
            });
        }
        if (searchClear && searchInput) {
            searchClear.addEventListener("click", function () {
                searchInput.value = "";
                syncSearchClear();
                searchInput.focus();
            });
        }

        /* ---------- department auto-apply + date presets ---------- */
        var filterForm = searchInput
            ? searchInput.closest("form")
            : document.querySelector(".filter-card form");
        function fmtDate(d) {
            return d.getFullYear() + "-"
                + String(d.getMonth() + 1).padStart(2, "0") + "-"
                + String(d.getDate()).padStart(2, "0");
        }
        if (filterForm) {
            var deptSel = filterForm.querySelector(
                'select[name="department"]'
            );
            if (deptSel) {
                deptSel.addEventListener("change", function () {
                    filterForm.submit();
                });
            }
            document.querySelectorAll(
                ".date-presets [data-range]"
            ).forEach(function (btn) {
                btn.addEventListener("click", function () {
                    var startEl = filterForm.querySelector(
                        'input[name="start_date"]'
                    );
                    var endEl = filterForm.querySelector(
                        'input[name="end_date"]'
                    );
                    if (!startEl || !endEl) return;
                    var mode = btn.getAttribute("data-range");
                    var today = new Date();
                    if (mode === "clear") {
                        startEl.value = "";
                        endEl.value = "";
                    } else if (mode === "month") {
                        startEl.value = fmtDate(
                            new Date(
                                today.getFullYear(),
                                today.getMonth(),
                                1
                            )
                        );
                        endEl.value = fmtDate(today);
                    } else {
                        var days = parseInt(mode, 10) || 30;
                        var from = new Date(today);
                        from.setDate(from.getDate() - (days - 1));
                        startEl.value = fmtDate(from);
                        endEl.value = fmtDate(today);
                    }
                    filterForm.submit();
                });
            });
        }

        /* ---------- active filter chips ---------- */
        try {
            var chipHost = document.getElementById(
                "surveyActiveChips"
            );
            var qp = new URLSearchParams(
                window.location.search
            );
            var chips = [];
            function chipLink(drop) {
                var p2 = new URLSearchParams(
                    window.location.search
                );
                drop.forEach(function (k) { p2.delete(k); });
                return (
                    window.location.pathname
                    + (p2.toString() ? "?" + p2.toString() : "")
                );
            }
            function escHtml(s) {
                return String(s).replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;");
            }
            if (qp.get("search")) {
                chips.push({
                    label: "Search: " + qp.get("search"),
                    href: chipLink(["search"])
                });
            }
            if (qp.get("start_date") || qp.get("end_date")) {
                chips.push({
                    label: (qp.get("start_date") || "…")
                        + " → " + (qp.get("end_date") || "…"),
                    href: chipLink(["start_date", "end_date"])
                });
            }
            if (qp.get("department")) {
                chips.push({
                    label: qp.get("department"),
                    href: chipLink(["department"])
                });
            }
            if (chipHost && chips.length) {
                var html = '<span class="active-chips-label">Active:</span>';
                chips.forEach(function (c) {
                    html += '<span class="filter-chip" title="'
                        + escHtml(c.label) + '"><span>'
                        + escHtml(c.label)
                        + '</span><a href="' + escHtml(c.href)
                        + '" title="Remove">&times;</a></span>';
                });
                if (chips.length > 1) {
                    html += '<span class="filter-chip" style="background:#f1f5f9;color:#475569;">'
                        + '<span>Clear all</span><a href="'
                        + escHtml(window.location.pathname)
                        + '" title="Clear all">&times;</a></span>';
                }
                chipHost.innerHTML = html;
                chipHost.classList.add("show");
            }
        } catch (e) {}

        /* ---------- metric count-up + interpretation ---------- */
        function ratingWord(v) {
            if (v >= 4.5) return "Excellent";
            if (v >= 3.5) return "Very good";
            if (v >= 2.5) return "Good";
            if (v >= 1.5) return "Fair";
            return v > 0 ? "Poor" : "No data";
        }
        document.querySelectorAll(".metric-card").forEach(
            function (card) {
                var valEl = card.querySelector(".metric-value");
                var labEl = card.querySelector(".metric-label");
                if (!valEl || !labEl) return;
                var label = labEl.textContent.trim();
                var raw = valEl.textContent.trim();
                var num = parseFloat(raw.replace(/,/g, ""));
                if (isNaN(num)) return;
                var isInt = Number.isInteger(num)
                    && raw.indexOf("/") === -1
                    && raw.indexOf("%") === -1;
                var sub = document.createElement("div");
                sub.className = "metric-sub";
                if (label === "Overall Rating"
                    || label === "Service Average") {
                    sub.textContent = num > 0
                        ? ratingWord(num)
                        : "No responses yet";
                    card.appendChild(sub);
                } else if (label === "Objective Achieved") {
                    sub.textContent = num >= 80
                        ? "Strong outcome"
                        : (num >= 50 ? "Mixed outcome"
                            : (num > 0 ? "Needs attention"
                                : "No responses yet"));
                    card.appendChild(sub);
                }
                if (!isInt) return;
                var target = num;
                var t0 = null;
                function tick(t) {
                    if (!t0) t0 = t;
                    var p = Math.min(
                        1, (t - t0) / 700
                    );
                    valEl.childNodes[0].nodeValue = Math.round(
                        target * (0.2 + 0.8 * p)
                    ).toLocaleString();
                    if (p < 1) requestAnimationFrame(tick);
                    else valEl.childNodes[0].nodeValue =
                        target.toLocaleString();
                }
                try {
                    if (valEl.childNodes[0]) {
                        requestAnimationFrame(tick);
                    }
                } catch (e) {}
            }
        );

        /* ---------- animate bars on scroll ---------- */
        try {
            var bars = Array.prototype.slice.call(
                document.querySelectorAll(".bar-fill")
            );
            bars.forEach(function (b) {
                b.dataset.w = b.style.width || "0%";
                b.style.width = "0%";
                b.parentElement.previousElementSibling &&
                    b.closest(".bar-row") &&
                    b.closest(".bar-row").setAttribute(
                        "role", "img"
                    );
            });
            var io = new IntersectionObserver(
                function (entries) {
                    entries.forEach(function (en) {
                        if (en.isIntersecting) {
                            en.target.style.width =
                                en.target.dataset.w;
                            io.unobserve(en.target);
                        }
                    });
                },
                { threshold: 0.2 }
            );
            bars.forEach(function (b) { io.observe(b); });
        } catch (e) {}

        /* ---------- table header scope (a11y) ---------- */
        document.querySelectorAll(
            ".table thead th"
        ).forEach(function (th) {
            th.setAttribute("scope", "col");
        });

        /* ---------- outcome donut ---------- */
        try {
            var donutHost = document.getElementById(
                "outcomeDonut"
            );
            if (donutHost) {
                var outcomeCard = donutHost.closest(
                    ".analytics-card"
                );
                var data = [];
                ["Yes", "Partially", "No"].forEach(
                    function (name) {
                        var row = null;
                        Array.prototype.slice.call(
                            outcomeCard.querySelectorAll(
                                ".bar-row"
                            )
                        ).forEach(function (r) {
                            var lab = r.querySelector(
                                ".bar-label span:first-child"
                            );
                            if (lab && lab.textContent.trim()
                                === name) row = r;
                        });
                        var count = 0, pct = 0;
                        if (row) {
                            var val = row.querySelector(
                                ".bar-label span:last-child"
                            );
                            var m = val
                                ? val.textContent.match(
                                    /([\d,]+)\s*[·•]\s*([\d.]+)/
                                )
                                : null;
                            if (m) {
                                count = parseInt(
                                    m[1].replace(/,/g, ""), 10
                                ) || 0;
                                pct = parseFloat(m[2]) || 0;
                            }
                        }
                        data.push({
                            name: name, count: count, pct: pct
                        });
                    }
                );
                var total = data.reduce(function (a, d) {
                    return a + d.count;
                }, 0);
                var colors = {
                    Yes: "#138a62",
                    Partially: "#b7791f",
                    No: "#b42318"
                };
                if (total > 0) {
                    var acc = 0;
                    var stops = data.map(function (d) {
                        var from = acc;
                        acc += (d.count / total) * 100;
                        return colors[d.name] + " " + from
                            + "% " + acc + "%";
                    });
                    var donut = document.createElement("div");
                    donut.className = "donut";
                    donut.style.background =
                        "conic-gradient(" + stops.join(",")
                        + ")";
                    var best = data.slice().sort(
                        function (a, b) {
                            return b.count - a.count;
                        }
                    )[0];
                    donut.innerHTML = '<div class="donut-center"><b>'
                        + Math.round(
                            (best.count / total) * 100
                        ) + '%</b><small>' + best.name
                        + "</small></div>";
                    var legend = document.createElement(
                        "div"
                    );
                    legend.className = "donut-legend";
                    legend.innerHTML = data.map(
                        function (d) {
                            return '<div class="leg-row">'
                                + '<span class="leg-dot" style="background:'
                                + colors[d.name] + '"></span>'
                                + d.name + "<span>"
                                + d.count.toLocaleString()
                                + " · "
                                + (total
                                    ? Math.round(
                                        (d.count / total) * 100
                                    )
                                    : 0)
                                + "%</span></div>";
                        }
                    ).join("");
                    donutHost.appendChild(donut);
                    donutHost.appendChild(legend);
                    donutHost.setAttribute(
                        "aria-label",
                        "Outcomes: " + data.map(function (d) {
                            return d.name + " " + d.count;
                        }).join(", ")
                    );
                } else {
                    donutHost.style.display = "none";
                }
            }
        } catch (e) {}

        /* ---------- monthly sparkline + peak ---------- */
        try {
            var spark = document.getElementById("trendSpark");
            if (spark) {
                var trendCard = spark.closest(
                    ".analytics-card"
                );
                var trows = Array.prototype.slice.call(
                    trendCard.querySelectorAll("tbody tr")
                );
                var points = trows.map(function (tr) {
                    var tds = tr.querySelectorAll("td");
                    return {
                        label: tds[0]
                            ? tds[0].textContent.trim() : "",
                        count: tds[1]
                            ? parseInt(
                                tds[1].textContent.replace(
                                    /,/g, ""
                                ), 10
                            ) || 0
                            : 0
                    };
                }).filter(function (p) { return p.label; });
                var peakEl = document.getElementById(
                    "trendPeak"
                );
                var capEl = document.getElementById(
                    "trendSparkCap"
                );
                if (points.length) {
                    var max = Math.max.apply(null,
                        points.map(function (p) {
                            return p.count;
                        }).concat([1]));
                    points.forEach(function (p) {
                        var b = document.createElement("div");
                        b.className = "spark-bar";
                        b.style.height = Math.max(6,
                            Math.round(
                                (p.count / max) * 100
                            )
                        ) + "%";
                        b.title = p.label + ": "
                            + p.count + " responses";
                        spark.appendChild(b);
                    });
                    var peak = points.slice().sort(
                        function (a, b) {
                            return b.count - a.count;
                        }
                    )[0];
                    if (peakEl) {
                        peakEl.textContent = "Peak: "
                            + peak.label + " ("
                            + peak.count + ")";
                    }
                    if (capEl) {
                        capEl.textContent = points[0].label
                            + " → "
                            + points[points.length - 1]
                                .label;
                    }
                } else {
                    spark.style.display = "none";
                }
            }
        } catch (e) {}

        /* ---------- department mini bars ---------- */
        try {
            var deptH = Array.prototype.slice.call(
                document.querySelectorAll(
                    ".section-title h2"
                )
            ).filter(function (h) {
                return h.textContent.trim()
                    === "Department Summary";
            })[0];
            if (deptH) {
                var dtable = deptH.closest(
                    ".analytics-card"
                ).querySelector("tbody");
                var drows = Array.prototype.slice.call(
                    dtable.querySelectorAll("tr")
                );
                var dmax = 1;
                var vals = drows.map(function (tr) {
                    var c = tr.querySelectorAll("td")[1];
                    var v = c ? parseInt(
                        c.textContent.replace(/,/g, ""),
                        10
                    ) || 0 : 0;
                    dmax = Math.max(dmax, v);
                    return v;
                });
                drows.forEach(function (tr, i) {
                    var cell = tr.querySelectorAll("td")[0];
                    if (!cell) return;
                    var bar = document.createElement("div");
                    bar.className = "dept-bar";
                    bar.innerHTML = "<i></i>";
                    bar.firstChild.style.width =
                        Math.max(4, Math.round(
                            (vals[i] / dmax) * 100
                        )) + "%";
                    cell.appendChild(bar);
                });
            }
        } catch (e) {}

        /* ---------- response instant filter + pills ---------- */
        try {
            var respCard = Array.prototype.slice.call(
                document.querySelectorAll(
                    ".section-title h2"
                )
            ).filter(function (h) {
                return h.textContent.trim()
                    === "Submitted Survey Records";
            })[0];
            if (respCard) {
                var rtable = respCard.closest(
                    ".analytics-card"
                ).querySelector("tbody");
                var rrows = Array.prototype.slice.call(
                    rtable.querySelectorAll("tr")
                );
                var searchBox = document.getElementById(
                    "respInstantSearch"
                );
                var liveEl = document.getElementById(
                    "respLiveCount"
                );
                var ratingPills = document.getElementById(
                    "respRatingPills"
                );
                var outcomePills = document.getElementById(
                    "respOutcomePills"
                );
                var fRating = "all", fOutcome = "all";
                rrows.forEach(function (tr) {
                    var tds = tr.querySelectorAll("td");
                    var ratingTxt = tds[3]
                        ? tds[3].textContent : "";
                    var rm = ratingTxt.match(/(\d+)/);
                    tr.dataset.rating = rm ? rm[1] : "";
                    tr.dataset.outcome = tds[4]
                        ? tds[4].textContent.trim() : "";
                    tr.dataset.search = (
                        tr.textContent || ""
                    ).toLowerCase();
                });
                var noRow = document.createElement("tr");
                noRow.innerHTML = '<td colspan="7" class="text-center text-muted">No records match this filter.</td>';
                noRow.style.display = "none";
                rtable.appendChild(noRow);
                function applyResp() {
                    var q = searchBox
                        ? searchBox.value.trim()
                            .toLowerCase()
                        : "";
                    var shown = 0;
                    rrows.forEach(function (tr) {
                        var okR = fRating === "all"
                            || (fRating === "2"
                                ? (+tr.dataset.rating <= 2)
                                : tr.dataset.rating
                                    === fRating);
                        var okO = fOutcome === "all"
                            || tr.dataset.outcome
                                === fOutcome;
                        var okQ = !q
                            || tr.dataset.search
                                .indexOf(q) !== -1;
                        var hit = okR && okO && okQ;
                        tr.style.display = hit ? "" : "none";
                        if (hit) shown++;
                    });
                    noRow.style.display = shown
                        ? "none" : "";
                    if (liveEl) {
                        liveEl.textContent = shown + " of "
                            + rrows.length + " shown";
                    }
                }
                if (searchBox) {
                    searchBox.addEventListener(
                        "input", applyResp
                    );
                }
                function pillGroup(host, attr, cb) {
                    if (!host) return;
                    host.querySelectorAll(
                        ".pill-btn"
                    ).forEach(function (b) {
                        b.addEventListener(
                            "click",
                            function () {
                                host.querySelectorAll(
                                    ".pill-btn"
                                ).forEach(function (x) {
                                    x.classList.remove(
                                        "is-active"
                                    );
                                });
                                b.classList.add("is-active");
                                cb(b.getAttribute(attr));
                            }
                        );
                    });
                }
                pillGroup(ratingPills, "data-rating",
                    function (v) {
                        fRating = v; applyResp();
                    });
                pillGroup(outcomePills, "data-outcome",
                    function (v) {
                        fOutcome = v; applyResp();
                    });
                applyResp();
            }
        } catch (e) {}

        /* ---------- comments filter + copy ---------- */
        try {
            var cHost = document.getElementById(
                "commentFilterPills"
            );
            var cScroll = document.getElementById(
                "commentsScroll"
            );
            if (cHost && cScroll) {
                var boxes = Array.prototype.slice.call(
                    cScroll.querySelectorAll(
                        ".comment-box"
                    )
                );
                boxes.forEach(function (box) {
                    var hasPraise = !!box.textContent
                        .match(/PHU did well:/);
                    var hasImprove = !!box.textContent
                        .match(/PHU can improve:/);
                    box.dataset.praise = hasPraise
                        ? "1" : "";
                    box.dataset.improve = hasImprove
                        ? "1" : "";
                    var cp = document.createElement(
                        "button"
                    );
                    cp.type = "button";
                    cp.className = "comment-copy";
                    cp.innerHTML = '<i class="bi bi-clipboard me-1"></i>Copy';
                    cp.addEventListener("click",
                        function () {
                            var txt = box.innerText || "";
                            function done() {
                                showToast("Comment copied");
                            }
                            if (navigator.clipboard
                                && navigator.clipboard
                                    .writeText) {
                                navigator.clipboard
                                    .writeText(txt)
                                    .then(done, done);
                            } else { done(); }
                        });
                    box.appendChild(cp);
                });
                cHost.querySelectorAll(
                    ".pill-btn"
                ).forEach(function (b) {
                    b.addEventListener("click",
                        function () {
                            cHost.querySelectorAll(
                                ".pill-btn"
                            ).forEach(function (x) {
                                x.classList.remove(
                                    "is-active"
                                );
                            });
                            b.classList.add("is-active");
                            var mode = b.getAttribute(
                                "data-cfilter"
                            );
                            boxes.forEach(function (box) {
                                var hit = mode === "all"
                                    || (mode === "praise"
                                        && box.dataset
                                            .praise)
                                    || (mode === "improve"
                                        && box.dataset
                                            .improve);
                                box.style.display = hit
                                    ? "" : "none";
                            });
                        });
                });
            }
        } catch (e) {}
    });
</script>

</body>
</html>
