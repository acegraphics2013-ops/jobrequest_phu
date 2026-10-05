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
requireModule($conn, "review_requests");

$surveyId = filter_input(
    INPUT_GET,
    "survey_id",
    FILTER_VALIDATE_INT
);

if (!$surveyId || $surveyId <= 0) {
    die("Invalid survey ID.");
}

/*
 * Change this path to the hospital logo used in your system.
 */
$logoUrl =
    rtrim(BASE_URL, "/") .
    "/assets/images/astmmc-logo.png";

/* =========================================================
   LOAD SPECIFIC SUBMITTED SURVEY
========================================================= */
$stmt = $conn->prepare(
    "SELECT
        s.*,
        jr.job_no,
        jr.requestor_name,
        jr.department,
        jr.contact,
        jr.project_title,
        jr.date_request,
        jr.date_completion,
        u.email AS requestor_email,
        u.name AS account_name
     FROM job_request_client_experience s
     INNER JOIN job_requests jr
        ON jr.id = s.request_id
     LEFT JOIN users u
        ON u.id = jr.requestor
     WHERE s.id = ?
     LIMIT 1"
);

$stmt->bind_param(
    "i",
    $surveyId
);

$stmt->execute();

$survey = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if (!$survey) {
    http_response_code(404);
    die("Submitted survey not found.");
}

function printSurveyChecked(
    bool $checked
): string {
    return $checked
        ? "☒"
        : "☐";
}

function printSurveyRatingMark(
    int $selected,
    int $rating
): string {
    return $selected === $rating
        ? "✓"
        : "";
}

$serviceOptions = [
    "Event Coverage (Photo)",
    "Event Coverage (Video)",
    "Graphic Design / Layout",
    "IEC Development",
    "Technical Assistance",
    "Health Education Support",
    "Coordination / Facilitation",
    "Policy / Document Review",
    "Others"
];

$selectedServices = array_values(
    array_filter(
        array_map(
            "trim",
            explode(
                ",",
                (string) (
                    $survey["service_types"] ?? ""
                )
            )
        )
    )
);

$criteria = [
    "timeliness" =>
        "Timeliness of Service",

    "responsiveness" =>
        "Responsiveness to Request",

    "quality_output" =>
        "Quality of Output",

    "professionalism" =>
        "Professionalism of Staff",

    "communication" =>
        "Communication and Coordination",

    "service_met_needs" =>
        "Service Met Your Needs"
];

$requestorName =
    trim(
        (string) (
            $survey["requestor_name"]
            ?: $survey["account_name"]
            ?: ""
        )
    );

$controlNumber =
    trim(
        (string) (
            $survey["job_no"]
            ?: "SURVEY-" .
                str_pad(
                    (string) $surveyId,
                    5,
                    "0",
                    STR_PAD_LEFT
                )
        )
    );

$submittedTimestamp = strtotime(
    (string) $survey["submitted_at"]
);

$submittedDate = $submittedTimestamp
    ? date(
        "F d, Y h:i A",
        $submittedTimestamp
    )
    : (string) $survey["submitted_at"];
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
        Print Survey |
        <?= e($controlNumber) ?>
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <style>
        @page {
    size: 210mm 297mm;
    margin: 0;
}

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #eef2f7;
            color: #111827;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
            font-size: 12px;
            line-height: 1.25;
        }

        .toolbar {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            justify-content: center;
            gap: 10px;
            padding: 12px;
            background: #172554;
            box-shadow:
                0 4px 18px
                rgba(15, 23, 42, .22);
        }

        .toolbar button,
        .toolbar a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 14px;
            border: 1px solid
                rgba(255, 255, 255, .35);
            border-radius: 8px;
            background: #ffffff;
            color: #172554;
            font: inherit;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .paper {
            width: 210mm;
            min-height: 297mm;
            margin: 18px auto;
            padding: 8mm;
            background: #ffffff;
            box-shadow:
                0 12px 34px
                rgba(15, 23, 42, .15);
        }

        .official-header {
            display: grid;
            grid-template-columns: 28mm 1fr;
            min-height: 27mm;
            border: 1.5px solid #111827;
        }

        .logo-cell {
            display: grid;
            place-items: center;
            padding: 3mm;
            border-right: 1.5px solid #111827;
        }

        .logo-cell img {
            max-width: 21mm;
            max-height: 21mm;
            object-fit: contain;
        }

        .logo-fallback {
            display: none;
            width: 20mm;
            height: 20mm;
            place-items: center;
            border: 2px solid #166534;
            border-radius: 50%;
            color: #166534;
            font-size: 12px;
            font-weight: 800;
            text-align: center;
        }

        .header-copy {
            display: grid;
            place-items: center;
            padding: 3mm 5mm;
            text-align: center;
        }

        .header-copy .agency {
            font-size: 12px;
        }

        .header-copy .hospital {
            font-size: 14px;
            font-weight: 800;
            letter-spacing: .02em;
        }

        .header-copy .form-name {
            margin-top: 1mm;
            font-size: 13px;
            font-weight: 900;
        }

        .document-meta {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 2mm;
            color: #334155;
            font-size: 12px;
        }

        .service-heading {
            margin: 4mm 0 2mm;
            text-align: center;
            font-size: 16px;
            font-weight: 800;
            text-decoration: underline;
        }

        .control-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 2mm;
            font-weight: 700;
        }

        .privacy-note {
            margin-bottom: 3mm;
            text-align: justify;
            font-size: 10px;
        }

        .info-table,
        .rating-table,
        .simple-table {
            width: 100%;
            border-collapse: collapse;
        }

        .info-table td,
        .rating-table th,
        .rating-table td,
        .simple-table th,
        .simple-table td {
            border: 1px solid #111827;
            padding: 2mm;
        }

        .info-label {
            font-size: 20px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .section-label {
            margin: 4mm 0 1.5mm;
            font-size: 14px;
            font-weight: 900;
        }

        .service-options {
            display: grid;
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
            gap: 1.5mm 6mm;
            padding: 2.5mm;
            border: 1px solid #111827;
        }

        .service-option {
            min-height: 5mm;
        }

        .rating-table {
            table-layout: fixed;
        }

        .rating-table th {
            background: #f1f5f9;
            text-align: center;
            font-size: 12px;
        }

        .rating-table th:first-child,
        .rating-table td:first-child {
            width: 48%;
            text-align: left;
        }

        .rating-table td {
            height: 8mm;
            text-align: center;
        }

        .rating-check {
            font-size: 17px;
            font-weight: 900;
        }

        .overall-box {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 5mm;
            align-items: center;
            padding: 2.5mm;
            border: 1px solid #111827;
        }

        .overall-options {
            display: flex;
            gap: 4mm;
            font-size: 12px;
            font-weight: 700;
        }

        .outcome-box {
            padding: 2.5mm;
            border: 1px solid #111827;
        }

        .comment-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            border: 1px solid #111827;
        }

        .comment-panel {
            min-height: 31mm;
            padding: 2.5mm;
        }

        .comment-panel + .comment-panel {
            border-left: 1px solid #111827;
        }

        .comment-title {
            margin-bottom: 2mm;
            font-weight: 800;
        }

        .comment-text {
            white-space: pre-wrap;
        }

        .respondent-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12mm;
            margin-top: 8mm;
        }

        .signature-line {
            padding-top: 1.5mm;
            border-top: 1px solid #111827;
            text-align: center;
        }

        .signature-value {
            min-height: 6mm;
            font-weight: 700;
        }

        .footer-meta {
            display: flex;
            justify-content: space-between;
            margin-top: 5mm;
            color: #475569;
            font-size: 12px;
        }

        .submission-note {
            margin-top: 3mm;
            text-align: right;
            color: #475569;
            font-size: 12px;
        }

        @media print {
            html,
            body {
                width: auto;
                height: auto;
                margin: 0;
                padding: 0;
                background: #ffffff;
            }

            body {
                overflow: visible;
                color: #000000;
                font-size: 12px;
                line-height: 1.15;
            }

            .toolbar {
                display: none !important;
            }

            .paper {
                width: 100%;
                min-height: 0;
                height: auto;
                margin: 0;
                padding: 0;
                overflow: visible;
                box-shadow: none;
                zoom: 1 !important;
                transform: none !important;
            }

            .official-header {
                grid-template-columns: 24mm 1fr;
                min-height: 23mm;
                border-width: 1px;
            }

            .logo-cell {
                padding: 2mm;
                border-right-width: 1px;
            }

            .logo-cell img {
                max-width: 18mm;
                max-height: 18mm;
            }

            .logo-fallback {
                width: 17mm;
                height: 17mm;
                font-size: 7px;
            }

            .header-copy {
                padding: 2mm 4mm;
            }

            .header-copy .agency {
                font-size: 10px;
            }

            .header-copy .hospital {
                font-size: 12px;
            }

            .header-copy .form-name {
                margin-top: .6mm;
                font-size: 12px;
            }

            .document-meta {
                gap: 10px;
                margin-top: 1mm;
                font-size: 12px;
            }

            .service-heading {
                margin: 2mm 0 1mm;
                font-size: 20px;
            }

            .control-row {
                margin-bottom: 1mm;
                font-size: 12px;
            }

            .privacy-note {
                margin-bottom: 1.5mm;
                font-size: 12px;
                line-height: 1.12;
            }

            .info-table td,
            .rating-table th,
            .rating-table td,
            .simple-table th,
            .simple-table td {
                padding: 1mm;
                border-width: .8px;
            }

            .info-label {
                font-size: 12px;
            }

            .section-label {
                margin: 2mm 0 .8mm;
                font-size: 12px;
            }

            .service-options {
                grid-template-columns:
                    repeat(3, minmax(0, 1fr));
                gap: .6mm 4mm;
                padding: 1.2mm 1.5mm;
            }

            .service-option {
                min-height: 3.5mm;
                font-size: 12px;
            }

            .rating-table th {
                font-size: 12px;
                line-height: 1.05;
            }

            .rating-table td {
                height: 5.8mm;
            }

            .rating-check {
                font-size: 12px;
            }

            .overall-box {
                gap: 3mm;
                padding: 1.2mm 1.5mm;
            }

            .overall-options {
                gap: 2.5mm;
                font-size: 8.5px;
            }

            .outcome-box {
                padding: 1.2mm 1.5mm;
            }

            .comment-panel {
                min-height: 23mm;
                padding: 1.5mm;
            }

            .comment-title {
                margin-bottom: 1mm;
            }

            .comment-text {
                overflow-wrap: anywhere;
                font-size: 8px;
                line-height: 1.12;
            }

            .respondent-row {
                gap: 9mm;
                margin-top: 5mm;
            }

            .signature-line {
                padding-top: 1mm;
            }

            .signature-value {
                min-height: 4mm;
            }

            .submission-note {
                margin-top: 1.5mm;
                font-size: 6.8px;
            }

            .footer-meta {
                margin-top: 2.5mm;
                font-size: 6.8px;
            }

            .official-header,
            .rating-table th {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }

            .official-header,
            .info-table,
            .service-options,
            .rating-table,
            .overall-box,
            .outcome-box,
            .comment-grid,
            .respondent-row {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }
    
        /* =================================================
           UNIFIED A4 LAYOUT
           The screen preview and printed output use the
           exact same dimensions and typography.
        ================================================= */
        @page {
            size: 210mm 297mm;
            margin: 0;
        }

        body {
            font-size: 12px;
            line-height: 1.15;
        }

        .paper {
            width: 210mm;
            height: 297mm;
            min-height: 297mm;
            margin: 18px auto;
            padding: 8mm;
            overflow: hidden;
            background: #ffffff;
            box-sizing: border-box;
        }

        .official-header {
            grid-template-columns: 24mm 1fr;
            min-height: 23mm;
            border-width: 1px;
        }

        .logo-cell {
            padding: 2mm;
            border-right-width: 1px;
        }

        .logo-cell img {
            max-width: 18mm;
            max-height: 18mm;
        }

        .logo-fallback {
            width: 17mm;
            height: 17mm;
            font-size: 7px;
        }

        .header-copy {
            padding: 2mm 4mm;
        }

        .header-copy .agency {
            font-size: 8px;
        }

        .header-copy .hospital {
            font-size: 12px;
        }

        .header-copy .form-name {
            margin-top: .6mm;
            font-size: 12px;
        }

        .document-meta {
            gap: 10px;
            margin-top: 1mm;
            font-size: 12px;
        }

        .service-heading {
            margin: 2mm 0 1mm;
            font-size: 9px;
        }

        .control-row {
            margin-bottom: 1mm;
            font-size: 12px;
        }

        .privacy-note {
            margin-bottom: 1.5mm;
            font-size: 12px;
            line-height: 1.12;
        }

        .info-table td,
        .rating-table th,
        .rating-table td,
        .simple-table th,
        .simple-table td {
            padding: 1mm;
            border-width: .8px;
        }

        .info-label {
            font-size: 10px;
        }

        .section-label {
            margin: 2mm 0 .8mm;
            font-size: 12px;
        }

        .service-options {
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
            gap: .6mm 4mm;
            padding: 1.2mm 1.5mm;
        }

        .service-option {
            min-height: 3.5mm;
            font-size: 12px;
        }

        .rating-table th {
            font-size: 12px;
            line-height: 1.05;
        }

        .rating-table td {
            height: 5.8mm;
        }

        .rating-check {
            font-size: 12px;
        }

        .overall-box {
            gap: 3mm;
            padding: 1.2mm 1.5mm;
        }

        .overall-options {
            gap: 2.5mm;
            font-size: 8.5px;
        }

        .outcome-box {
            padding: 1.2mm 1.5mm;
        }

        .comment-panel {
            min-height: 23mm;
            padding: 1.5mm;
        }

        .comment-title {
            margin-bottom: 1mm;
        }

        .comment-text {
            overflow-wrap: anywhere;
            font-size: 8px;
            line-height: 1.12;
        }

        .respondent-row {
            gap: 9mm;
            margin-top: 5mm;
        }

        .signature-line {
            padding-top: 1mm;
        }

        .signature-value {
            min-height: 4mm;
        }

        .submission-note {
            margin-top: 1.5mm;
            font-size: 6.8px;
        }

        .footer-meta {
            margin-top: 2.5mm;
            font-size: 6.8px;
        }

        @media print {
            html,
            body {
                width: 210mm !important;
                height: 297mm !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
            }

            body {
                overflow: hidden !important;
            }

            .toolbar {
                display: none !important;
            }

            .paper {
                width: 210mm !important;
                height: 297mm !important;
                min-height: 297mm !important;
                margin: 0 !important;
                padding: 8mm !important;
                overflow: hidden !important;
                box-shadow: none !important;
                zoom: 1 !important;
                transform: none !important;
            }

            .official-header,
            .info-table,
            .service-options,
            .rating-table,
            .overall-box,
            .outcome-box,
            .comment-grid,
            .respondent-row {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            * {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
        }

    </style>

</head>

<body>

<div class="toolbar">

    <button
        type="button"
        onclick="window.print()"
    >
        <i class="bi bi-printer"></i>
        Print Survey
    </button>

    <a
        href="<?= e(BASE_URL) ?>/staff/survey_analytics.php"
    >
        <i class="bi bi-arrow-left"></i>
        Back to Analytics
    </a>

</div>

<main class="paper" id="printPaper">

    <header class="official-header">

        <div class="logo-cell">

            <img
                src="<?= e($logoUrl) ?>"
                alt="ASTMMC Logo"
                onerror="
                    this.style.display='none';
                    this.nextElementSibling.style.display='grid';
                "
            >

            <div class="logo-fallback">
                ASTMMC
            </div>

        </div>

        <div class="header-copy">

            <div class="agency">
                Department of Health
            </div>

            <div class="hospital">
                ADELA SERRA TY MEMORIAL MEDICAL CENTER
            </div>

            <div class="form-name">
                HOSPITAL CLIENT EXPERIENCE SURVEY
                FORM (INTERNAL)
            </div>

        </div>

    </header>

    <div class="document-meta">

        <span>
            OMCC-OSM-PACS-F-02
        </span>

        <span>
            Rev. 2
        </span>

        <span>
            Effectivity Date:
            September 22, 2025
        </span>

    </div>

    <div class="service-heading">
        Administrative and Other Frontline Services
    </div>

    <div class="control-row">

        <span>
            Control No.:
            <?= e($controlNumber) ?>
        </span>

        <span>
            Survey Record:
            <?= (int) $surveyId ?>
        </span>

    </div>

    <div class="privacy-note">

        The following questions talk about your overall
        experience in this hospital. We wish to improve the
        level of service we deliver because you are important
        to us. Comments and suggestions are treated with
        utmost confidentiality in accordance with Republic
        Act No. 10173, also known as the Data Privacy Act of
        2012.

    </div>

    <table class="info-table">

        <tr>

            <td>
                <div class="info-label">
                    Age
                </div>
                <?= (int) $survey["age"] ?>
            </td>

            <td>
                <div class="info-label">
                    Sex
                </div>
                <?= e(
                    (string) $survey["sex"]
                ) ?>
            </td>

            <td>
                <div class="info-label">
                    Date of Visit
                </div>
                <?= e(
                    date(
                        "F d, Y",
                        strtotime(
                            (string) $survey[
                                "date_of_visit"
                            ]
                        )
                    )
                ) ?>
            </td>

            <td>
                <div class="info-label">
                    Time of Visit
                </div>
                <?= !empty(
                    $survey["time_of_visit"]
                )
                    ? e(
                        date(
                            "h:i A",
                            strtotime(
                                (string) $survey[
                                    "time_of_visit"
                                ]
                            )
                        )
                    )
                    : "N/A"
                ?>
            </td>

        </tr>

        <tr>

            <td colspan="2">
                <div class="info-label">
                    Department / Office / Unit Visited
                </div>
                <?= e(
                    (string) $survey[
                        "department_visited"
                    ]
                ) ?>
            </td>

            <td colspan="2">
                <div class="info-label">
                    Job Request / Project
                </div>
                <?= e($controlNumber) ?>
                —
                <?= e(
                    (string) $survey[
                        "project_title"
                    ]
                ) ?>
            </td>

        </tr>

    </table>

    <div class="section-label">
        A. Type of Service Requested
    </div>

    <div class="service-options">

        <?php foreach (
            $serviceOptions as $service
        ): ?>

            <div class="service-option">

                <?= printSurveyChecked(
                    in_array(
                        $service,
                        $selectedServices,
                        true
                    )
                ) ?>

                <?= e($service) ?>

                <?php if (
                    $service === "Others"
                    && in_array(
                        "Others",
                        $selectedServices,
                        true
                    )
                    && !empty(
                        $survey["service_other"]
                    )
                ): ?>

                    :
                    <?= e(
                        (string) $survey[
                            "service_other"
                        ]
                    ) ?>

                <?php endif; ?>

            </div>

        <?php endforeach; ?>

    </div>

    <div class="section-label">
        B. Service Evaluation
    </div>

    <table class="rating-table">

        <thead>

            <tr>

                <th>
                    Criteria
                </th>

                <th>
                    5<br>
                    Very Satisfied
                </th>

                <th>
                    4<br>
                    Satisfied
                </th>

                <th>
                    3<br>
                    Neutral
                </th>

                <th>
                    2<br>
                    Dissatisfied
                </th>

                <th>
                    1<br>
                    Very Dissatisfied
                </th>

            </tr>

        </thead>

        <tbody>

            <?php foreach (
                $criteria as $field => $label
            ): ?>

                <tr>

                    <td>
                        <?= e($label) ?>
                    </td>

                    <?php for (
                        $rating = 5;
                        $rating >= 1;
                        $rating--
                    ): ?>

                        <td class="rating-check">

                            <?= printSurveyRatingMark(
                                (int) $survey[$field],
                                $rating
                            ) ?>

                        </td>

                    <?php endfor; ?>

                </tr>

            <?php endforeach; ?>

        </tbody>

    </table>

    <div class="section-label">
        C. Overall Satisfaction
    </div>

    <div class="overall-box">

        <div>
            How satisfied are you with the service
            provided by the Public Health Unit?
        </div>

        <div class="overall-options">

            <?php for (
                $rating = 5;
                $rating >= 1;
                $rating--
            ): ?>

                <span>
                    <?= printSurveyChecked(
                        (int) $survey[
                            "overall_satisfaction"
                        ] === $rating
                    ) ?>
                    <?= $rating ?>
                </span>

            <?php endfor; ?>

        </div>

    </div>

    <div class="section-label">
        D. Outcome Assessment
    </div>

    <div class="outcome-box">

        Did the service help your office achieve its
        intended objective?

        &nbsp;&nbsp;

        <?php foreach (
            [
                "Yes",
                "Partially",
                "No"
            ] as $outcome
        ): ?>

            <span style="margin-right: 8mm;">

                <?= printSurveyChecked(
                    (string) $survey[
                        "outcome_assessment"
                    ] === $outcome
                ) ?>

                <?= e($outcome) ?>

            </span>

        <?php endforeach; ?>

    </div>

    <div class="section-label">
        E. Comments and Suggestions
    </div>

    <div class="comment-grid">

        <div class="comment-panel">

            <div class="comment-title">
                What did PHU do well?
            </div>

            <div class="comment-text"><?= e(
                (string) (
                    $survey["phu_did_well"]
                    ?: "No response"
                )
            ) ?></div>

        </div>

        <div class="comment-panel">

            <div class="comment-title">
                What can PHU improve?
            </div>

            <div class="comment-text"><?= e(
                (string) (
                    $survey["phu_improve"]
                    ?: "No response"
                )
            ) ?></div>

        </div>

    </div>

    <div class="respondent-row">

        <div class="signature-line">

            <div class="signature-value">
                <?= e(
                    $requestorName
                ) ?>
            </div>

            Name / Office / Unit of the Respondent

            <div>
                <?= e(
                    (string) (
                        $survey["department"]
                        ?: $survey[
                            "department_visited"
                        ]
                    )
                ) ?>
            </div>

        </div>

        <div class="signature-line">

            <div class="signature-value">
                <?= e(
                    (string) (
                        $survey["contact"]
                        ?: $survey[
                            "requestor_email"
                        ]
                        ?: "Not provided"
                    )
                ) ?>
            </div>

            Contact Number / Email Address

        </div>

    </div>

    <div class="submission-note">

        Electronically submitted:
        <?= e($submittedDate) ?>

    </div>

    <div class="footer-meta">

        <span>
            OMCC-OSM-PACS-F-02
        </span>

        <span>
            Rev. 2
        </span>

        <span>
            Effectivity Date:
            September 22, 2025
        </span>

    </div>

</main>


</body>
</html>
