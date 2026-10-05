<?php
include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

checkLogin();
requireModule($conn, "my_requests");

$requestId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

if (!$requestId || $requestId <= 0) {
    die("Invalid request ID.");
}

$stmt = $conn->prepare(
    "SELECT *
     FROM job_requests
     WHERE id = ?
     LIMIT 1"
);

$stmt->bind_param("i", $requestId);
$stmt->execute();

$request = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if (!$request) {
    die("Request not found.");
}

$currentRole = $_SESSION["role"] ?? "user";
$currentUserId = (int) ($_SESSION["user_id"] ?? 0);

if (
    $currentRole === "user" &&
    (int) $request["requestor"] !== $currentUserId
) {
    die("Access denied.");
}

$iecItems = [];

$tableCheck = $conn->query(
    "SHOW TABLES LIKE 'job_request_iec_items'"
);

if (
    $tableCheck &&
    $tableCheck->num_rows > 0
) {
    $iecStmt = $conn->prepare(
        "SELECT material_name, quantity
         FROM job_request_iec_items
         WHERE request_id = ?
         ORDER BY id ASC"
    );

    $iecStmt->bind_param("i", $requestId);
    $iecStmt->execute();

    $iecItems = $iecStmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $iecStmt->close();
}

function decoded($value): array
{
    if (!$value) {
        return [];
    }

    $data = json_decode((string) $value, true);
    return is_array($data) ? $data : [];
}

function checked(bool $condition): string
{
    return $condition ? "☑" : "☐";
}

function valueOrLine($value, string $fallback = ""): string
{
    $value = trim((string) ($value ?? ""));

    return $value !== ""
        ? e($value)
        : e($fallback);
}

function formattedDate($value): string
{
    if (!$value) {
        return "";
    }

    $time = strtotime((string) $value);

    return $time
        ? date("m/d/Y", $time)
        : e($value);
}

$requestTypes = decoded(
    $request["request_types"] ?? null
);

$graphicTypes = decoded(
    $request["graphic_design_types"] ?? null
);

$graphicColors = decoded(
    $request["graphic_colors"] ?? null
);

$publicationMethods = decoded(
    $request["publication_methods"] ?? null
);

$videoTypes = decoded(
    $request["video_types"] ?? null
);

$videoFormats = decoded(
    $request["video_formats"] ?? null
);

$coverageFormats = decoded(
    $request["coverage_formats"] ?? null
);

$rating = (int) (
    $request["satisfaction_rating"] ?? 0
);
$finalDesignDriveLink = trim(
    (string) (
        $request["final_design_drive_link"]
        ?? ""
    )
);

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
        <?= e($request["job_no"]) ?>
        - Printable Job Request
    </title>

    <style>
        @page {
            size: A4 portrait;
            margin: 8mm;
        }

        * {
            box-sizing: border-box;
        }


        html,
        body,
        .sheet,
        table,
        td,
        th,
        .green-title,
        .yellow-title,
        .requestor-title,
        .job-number-box,
        .important,
        .print-toolbar {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
        }

        .signature-block {
            text-align: center;
        }

        .signature-title{
            margin-bottom:7px;
            font-size:11px;
            font-weight:700;
            text-align:left;
            padding-left:5px;
        }

        .signature-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 72px;
            align-items: end;
            gap: 9px;
        }

        .signature-name {
            min-width: 0;
            padding: 0 6px 3px;
            border-bottom: 1px solid #111;
            font-size: 11px;
            font-weight: 800;
            line-height: 1.2;
            text-align: center;
            text-transform: uppercase;
            overflow-wrap: anywhere;
        }

        .signature-date {
            padding: 0 2px 3px;
            border-bottom: 1px solid #111;
            font-size: 9px;
            font-weight: 600;
            line-height: 1.2;
            text-align: center;
            white-space: nowrap;
        }

        .signature-caption {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 72px;
            gap: 9px;
            margin-top: 3px;
            font-size: 8px;
            line-height: 1.2;
        }

        .signature-caption span:last-child {
            text-align: center;
        }

        body {
            margin: 0;
            background: #e5e7eb;
            color: #111;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
        }

        .print-toolbar {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            justify-content: center;
            gap: 10px;
            padding: 12px;
            background: #111827;
        }

        .print-toolbar button,
        .print-toolbar a {
            padding: 9px 15px;
            border: 0;
            border-radius: 6px;
            background: #2563eb;
            color: #fff;
            font: inherit;
            text-decoration: none;
            cursor: pointer;
        }

        .print-toolbar a {
            background: #475569;
        }

        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 12px auto;
            padding: 8mm;
            background: #fff;
            box-shadow: 0 4px 20px rgba(0, 0, 0, .15);
        }

        .page-break {
            break-after: page;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        td,
        th {
            padding: 3px 5px;
            border: 1px solid #222;
            vertical-align: top;
        }

        .header-table td {
            height: 25mm;
            text-align: center;
            vertical-align: middle;
        }

        .logo-cell {
            width: 24mm;
        }

        .logo-cell img {
            max-width: 20mm;
            max-height: 20mm;
        }

        .hospital-name {
            font-size: 13px;
            font-weight: 700;
        }

        .form-title {
            margin-top: 2px;
            font-size: 13px;
            font-weight: 800;
        }

        .green-title {
            padding: 3px 6px;
            background: #00662d;
            color: #fff;
            font-weight: 700;
            text-align: center;
        }

        .yellow-title {
            padding: 3px 6px;
            border: 1px solid #222;
            background: #fff600;
            font-weight: 800;
        }

        .requestor-title {
            background: #00662d;
            color: #fff;
            font-weight: 700;
        }

        .important {
            padding: 5px 7px;
            background: #f2f2f2;
            font-size: 9px;
            font-style: italic;
        }

        .important strong {
            color: #c62828;
        }

        .job-number-box {
            background: #00662d;
            color: #fff;
            font-weight: 700;
        }

        .service-table th {
            font-size: 10px;
            text-align: center;
        }

        .service-table td {
            width: 25%;
        }

        .check-line {
            margin-bottom: 2px;
            line-height: 1.25;
        }

        .small-line {
            display: inline-block;
            min-width: 45px;
            border-bottom: 1px solid #111;
        }

        .signature-area {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-top: 14px;
            align-items: start;
        }

        .signature-column {
            display: grid;
            gap: 18px;
        }

        .disapproval-box {
            min-height: 38px;
            margin-top: 4px;
            padding: 8px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #f8fafc;
            font-size: 9px;
            line-height: 1.35;
        }

        .disapproval-box strong {
            color: #c62828;
        }

        .document-footer {
            clear: both;
            margin-top: 18px;
            padding-top: 6px;
            border-top: 1px solid #cbd5e1;
            text-align: right;
            font-size: 7px;
            line-height: 1.35;
        }

        .draft-area {
            height: 175mm;
            border: 1px solid #222;
            border-top: 0;
            position: relative;
        }

        .draft-label {
            position: absolute;
            right: 0;
            bottom: 2px;
            left: 0;
            text-align: center;
        }

        .evaluation-list {
            padding: 8px 20px;
            font-size: 11px;
        }

        .evaluation-list li {
            margin-bottom: 16px;
        }

        .footer {
            margin-top: 10px;
            padding-top: 6px;
            border-top: 1px solid #111;
            text-align: center;
            font-size: 10px;
        }

        .document-code {
            float: right;
            text-align: right;
            font-size: 8px;
        }

        .no-border {
            border: 0 !important;
        }

        @media print {
            html,
            body,
            .sheet,
            table,
            td,
            th,
            .green-title,
            .yellow-title,
            .requestor-title,
            .job-number-box,
            .important {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }

            body {
                background: #fff !important;
            }

            .print-toolbar {
                display: none !important;
            }

            .sheet {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 0;
                background: #fff !important;
                box-shadow: none;
            }

            .green-title,
            .requestor-title,
            .job-number-box {
                background: #00662d !important;
                color: #fff !important;
            }

            .yellow-title {
                background: #fff600 !important;
                color: #111 !important;
            }

            .important {
                background: #f2f2f2 !important;
            }
        }
            .draft-area{
                position:relative;
                width:100%;
                height:175mm;
                border:1px solid #222;
                border-top:0;
                overflow:hidden;
                background:#fff;
            }

            

             

            .drive-link-box{
                position:absolute;
                left:10px;
                right:10px;
                bottom:18px;
                font-size:9px;
                text-align:center;
                word-break:break-all;
            }

           

            .draft-label{
                position:absolute;
                left:0;
                right:0;
                bottom:2px;
                text-align:center;
                font-size:10px;
            }

            @media print{

                .drive-preview{
                    display:none;
                }

                .drive-placeholder{
                    display:flex;
                }

            }
    </style>

</head>

<body>

<div class="print-toolbar">

    <button
        type="button"
        onclick="window.print();"
    >
        Print / Save as PDF
    </button>

    <a href="javascript:window.close();">
        Close
    </a>

    <span
        style="
            align-self:center;
            color:#e2e8f0;
            font-size:12px;
        "
    >
        
    </span>

</div>

<!-- PAGE 1 -->
<section class="sheet page-break">

    <table class="header-table">

        <tr>

            <td class="logo-cell">

                <img
                    src="<?= e(BASE_URL) ?>/assets/images/astmmc-logo.png"
                    alt="ASTMMC"
                    onerror="this.style.display='none';"
                >

            </td>

            <td>

                <div>Department of Health</div>

                <div class="hospital-name">
                    ADELA SERRA TY MEMORIAL MEDICAL CENTER
                </div>

                <div class="form-title">
                    GRAPHICS DESIGN / VIDEO PRODUCTION /
                    PHOTO/VIDEO COVERAGE / IEC MATERIALS
                    JOB REQUEST FORM
                </div>

            </td>

        </tr>

    </table>

    <table>

        <tr>
            <td
                colspan="2"
                class="requestor-title"
            >
                Requestor's Information
            </td>

            <td class="job-number-box">
                JOB REQUEST NO:
                <?= e($request["job_no"]) ?>
            </td>
        </tr>

        <tr>
            <td>
                Name:
                <?= e($request["requestor_name"]) ?>
            </td>

            <td>
                Contact Number / Email Address:
                <?= e($request["contact"]) ?>
            </td>

            <td>
                Date of Request:
                <?= formattedDate(
                    $request["date_request"]
                ) ?>
            </td>
        </tr>

        <tr>
            <td colspan="2">
                Department/Office:
                <?= e($request["department"]) ?>
            </td>

            <td>
                Date of Completion:
                <?= formattedDate(
                    $request["date_completion"]
                ) ?>
            </td>
        </tr>

    </table>

    <div class="important">

        <strong>IMPORTANT:</strong>

        Project completion is as follows:
        Graphic Design / Video Production (5-15 days),
        Photo & Video Coverage / IEC Materials (3-5 days)
        and will be worked after approval. Please plan
        accordingly and be as thorough as possible when
        filling out this request.

    </div>

    <div class="green-title">
        Creation and Approval of Activity Design
    </div>

    <div class="yellow-title">
        I. Job Details
    </div>

    <table>

        <tr>

            <td colspan="3">

                Type of Request:

                &nbsp;&nbsp;
                <?= checked(
                    in_array(
                        "Graphic Design",
                        $requestTypes,
                        true
                    )
                ) ?>
                Graphic Design

                &nbsp;&nbsp;
                <?= checked(
                    in_array(
                        "Video Production",
                        $requestTypes,
                        true
                    )
                ) ?>
                Video Production

                &nbsp;&nbsp;
                <?= checked(
                    in_array(
                        "Photo/Video Coverage",
                        $requestTypes,
                        true
                    )
                ) ?>
                Photo/Video Coverage

                &nbsp;&nbsp;
                <?= checked(
                    in_array(
                        "IEC Materials",
                        $requestTypes,
                        true
                    )
                ) ?>
                IEC Materials

            </td>

        </tr>

        <tr>
            <td width="30%">
                Title / Project Name:
            </td>

            <td colspan="2">
                <?= e($request["project_title"]) ?>
            </td>
        </tr>

        <tr>
            <td>
                Purpose / Objective:
            </td>

            <td colspan="2">
                <?= e($request["purpose_objective"]) ?>
            </td>
        </tr>

        <tr>
            <td>
                Event Date & Time:
            </td>

            <td>
                <?= !empty(
                    $request["event_not_applicable"]
                )
                    ? "N/A"
                    : e(
                        formatEventRange(
                            $request["event_datetime"] ?? "",
                            $request["event_datetime_end"] ?? ""
                        )
                    )
                ?>
            </td>

            <td width="36%">
                <?= checked(
                    !empty(
                        $request["event_not_applicable"]
                    )
                ) ?>
                Check if non-applicable
            </td>
        </tr>

        <tr>
            <td>
                Venue / Location:
            </td>

            <td>
                <?= !empty(
                    $request["venue_not_applicable"]
                )
                    ? "N/A"
                    : e($request["venue_location"])
                ?>
            </td>

            <td>
                <?= checked(
                    !empty(
                        $request["venue_not_applicable"]
                    )
                ) ?>
                Check if non-applicable
            </td>
        </tr>

        <tr>
            <td>
                Send them to:
            </td>

            <td>
                <?= !empty(
                    $request["send_to_not_applicable"]
                )
                    ? "N/A"
                    : e($request["send_to"])
                ?>
            </td>

            <td>
                <?= checked(
                    !empty(
                        $request["send_to_not_applicable"]
                    )
                ) ?>
                Check if non-applicable
            </td>
        </tr>

    </table>

    <div class="green-title">
        Processing Materials and Activity Equipment
    </div>

    <div class="yellow-title">
        II. Job Description & Specifications
    </div>

    <table class="service-table">

        <thead>

            <tr>
                <th>GRAPHIC DESIGN</th>
                <th>VIDEO PRODUCTION</th>
                <th>PHOTO/VIDEO COVERAGE</th>
                <th>IEC MATERIALS</th>
            </tr>

        </thead>

        <tbody>

            <tr>

                <td>

                    <strong>Type of Design:</strong>

                    <?php foreach (
                        [
                            "Digital Ad",
                            "Print Ad / Tarpaulin",
                            "Brochure",
                            "Poster / Flyer",
                            "Invitation",
                            "Forms / Cards",
                            "Logo Design",
                            "Others"
                        ] as $item
                    ): ?>

                        <div class="check-line">
                            <?= checked(
                                in_array(
                                    $item,
                                    $graphicTypes,
                                    true
                                )
                            ) ?>
                            <?= e($item) ?>

                            <?php if (
                                $item === "Others" &&
                                !empty(
                                    $request[
                                        "graphic_design_other"
                                    ]
                                )
                            ): ?>

                                :
                                <?= e(
                                    $request[
                                        "graphic_design_other"
                                    ]
                                ) ?>

                            <?php endif; ?>
                        </div>

                    <?php endforeach; ?>

                    <br>

                    <strong>Color:</strong>

                    <?php foreach (
                        [
                            "Full Color",
                            "Single Color",
                            "Grayscale"
                        ] as $item
                    ): ?>

                        <div class="check-line">
                            <?= checked(
                                in_array(
                                    $item,
                                    $graphicColors,
                                    true
                                )
                            ) ?>
                            <?= e($item) ?>
                        </div>

                    <?php endforeach; ?>

                    <br>

                    <strong>Publication Method:</strong>

                    <?php foreach (
                        [
                            "Digital File",
                            "Tarpaulin",
                            "Photo Paper",
                            "Others"
                        ] as $item
                    ): ?>

                        <div class="check-line">
                            <?= checked(
                                in_array(
                                    $item,
                                    $publicationMethods,
                                    true
                                )
                            ) ?>
                            <?= e($item) ?>

                            <?php if (
                                $item === "Others" &&
                                !empty(
                                    $request[
                                        "publication_other"
                                    ]
                                )
                            ): ?>

                                :
                                <?= e(
                                    $request[
                                        "publication_other"
                                    ]
                                ) ?>

                            <?php endif; ?>
                        </div>

                    <?php endforeach; ?>

                    <br>

                    <strong>Quantity:</strong>
                    <?= (int) (
                        $request["graphic_quantity"] ?? 0
                    ) ?>

                </td>

                <td>

                    <strong>Type of Video:</strong>

                    <?php foreach (
                        [
                            "Digital Video Ads",
                            "Music Video",
                            "Promotional",
                            "Documentary",
                            "Instructional",
                            "Informative",
                            "DOH Videos",
                            "Others"
                        ] as $item
                    ): ?>

                        <div class="check-line">
                            <?= checked(
                                in_array(
                                    $item,
                                    $videoTypes,
                                    true
                                )
                            ) ?>
                            <?= e($item) ?>

                            <?php if (
                                $item === "Others" &&
                                !empty(
                                    $request[
                                        "video_type_other"
                                    ]
                                )
                            ): ?>

                                :
                                <?= e(
                                    $request[
                                        "video_type_other"
                                    ]
                                ) ?>

                            <?php endif; ?>
                        </div>

                    <?php endforeach; ?>

                    <br>

                    <strong>Format:</strong>

                    <?php foreach (
                        [
                            "MP4",
                            "MP3 (Audio Only)",
                            "Others"
                        ] as $item
                    ): ?>

                        <div class="check-line">
                            <?= checked(
                                in_array(
                                    $item,
                                    $videoFormats,
                                    true
                                )
                            ) ?>
                            <?= e($item) ?>

                            <?php if (
                                $item === "Others" &&
                                !empty(
                                    $request[
                                        "video_format_other"
                                    ]
                                )
                            ): ?>

                                :
                                <?= e(
                                    $request[
                                        "video_format_other"
                                    ]
                                ) ?>

                            <?php endif; ?>
                        </div>

                    <?php endforeach; ?>

                    <br>

                    <strong>Quantity:</strong>
                    <?= (int) (
                        $request["video_quantity"] ?? 0
                    ) ?>

                </td>

                <td>

                    <strong>
                        Number of Photographers Needed:
                    </strong>

                    <div>
                        <?= (int) (
                            $request[
                                "photographers_needed"
                            ] ?? 0
                        ) ?>
                    </div>

                    <br>

                    <strong>
                        Specific Shots Required:
                    </strong>

                    <div>
                        <?= nl2br(
                            e(
                                $request[
                                    "specific_shots"
                                ] ?? ""
                            )
                        ) ?>
                    </div>

                    <br>

                    <strong>
                        Image/Video Format:
                    </strong>

                    <?php foreach (
                        [
                            "JPEG",
                            "PNG",
                            "RAW",
                            "MP4",
                            "MKV",
                            "Others"
                        ] as $item
                    ): ?>

                        <div class="check-line">
                            <?= checked(
                                in_array(
                                    $item,
                                    $coverageFormats,
                                    true
                                )
                            ) ?>
                            <?= e($item) ?>

                            <?php if (
                                $item === "Others" &&
                                !empty(
                                    $request[
                                        "coverage_format_other"
                                    ]
                                )
                            ): ?>

                                :
                                <?= e(
                                    $request[
                                        "coverage_format_other"
                                    ]
                                ) ?>

                            <?php endif; ?>
                        </div>

                    <?php endforeach; ?>

                    <br>

                    <strong>Quantity:</strong>
                    <?= (int) (
                        $request["coverage_quantity"] ?? 0
                    ) ?>

                </td>

                <td>

                    <table>

                        <thead>
                            <tr>
                                <th>Requested IEC</th>
                                <th>Qty.</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach (
                                [
                                    "5S Dengue",
                                    "ASTMMC Services",
                                    "Breastfeeding",
                                    "Diphtheria",
                                    "DOH Materials",
                                    "Earthquake",
                                    "Handwashing",
                                    "HIV / AIDS",
                                    "Leptospirosis",
                                    "Mental Health",
                                    "MPOX",
                                    "Patient Rights",
                                    "Patient and Watcher Handbook",
                                    "Others"
                                ] as $material
                            ): ?>

                                <?php
                                $foundQuantity = "";

                                foreach (
                                    $iecItems as $iecItem
                                ) {
                                    if (
                                        strcasecmp(
                                            $iecItem[
                                                "material_name"
                                            ],
                                            $material
                                        ) === 0
                                    ) {
                                        $foundQuantity =
                                            (int) $iecItem[
                                                "quantity"
                                            ];

                                        break;
                                    }
                                }
                                ?>

                                <tr>
                                    <td>
                                        <?= e($material) ?>
                                    </td>

                                    <td>
                                        <?= e($foundQuantity) ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </td>

            </tr>

        </tbody>

    </table>

    <div class="signature-area">

        <div class="signature-column">

            <div class="signature-block">
                <div class="signature-title">Requested by:</div>
                <div class="signature-row">

                    <div class="signature-name">
                        <?= e(
                            strtoupper(
                                $request["requestor_name"] ?? ""
                            )
                        ) ?>
                    </div>

                    <div class="signature-date">
                        <?= formattedDate(
                            $request["requestor_signature_date"]
                            ?? $request["date_request"]
                            ?? ""
                        ) ?>
                    </div>

                </div>

                <div class="signature-caption">
                    <span>Name & Signature of Requestor</span>
                    <span>Date</span>
                </div>

            </div>

            <div class="signature-block">

                <div class="signature-title">
                    Reviewed by:
                </div>

                <div class="signature-row">

                    <div class="signature-name">
                        <?= e(
                            strtoupper(
                                $request["reviewed_by"]
                                ?: "NOT YET REVIEWED"
                            )
                        ) ?>
                    </div>

                    <div class="signature-date">
                        <?= formattedDate(
                            $request["reviewed_date"] ?? ""
                        ) ?>
                    </div>

                </div>

                <div class="signature-caption">
                    <span>Name & Signature of HPU Personnel</span>
                    <span>Date</span>
                </div>

            </div>

        </div>

        <div class="signature-column">

            <div class="signature-block">

                <div class="signature-title">
                    Approved by:
                </div>

                <div class="signature-row">

                    <div class="signature-name">
                        <?= e(
                            strtoupper(
                                $request["approved_by"]
                                ?: "PENDING APPROVAL"
                            )
                        ) ?>
                    </div>

                    <div class="signature-date">
                        <?= formattedDate(
                            $request["approved_date"] ?? ""
                        ) ?>
                    </div>

                </div>

                <div class="signature-caption">
                    <span>Name & Signature of Unit Supervisor</span>
                    <span>Date</span>
                </div>

            </div>

            <div class="disapproval-box">

                <?= checked(
                    !empty(
                        $request["disapproved"]
                    )
                ) ?>

                <strong>Disapproved</strong>

                <?php if (
                    !empty(
                        $request["disapproved_reason"]
                    )
                ): ?>

                    <div style="margin-top:4px;">
                        <strong>Reason:</strong>
                        <?= e(
                            $request["disapproved_reason"]
                        ) ?>
                    </div>

                <?php else: ?>

                    <span>
                        (Reason: ________________________________)
                    </span>

                <?php endif; ?>

            </div>

        </div>

    </div>

    <div class="document-footer">
        OMCC-PHU-HPU-F-13<br>
        Rev. 2<br>
        Effectivity Date: September 15, 2025
    </div>

</section>

<!-- PAGE 2 -->
<section class="sheet">

    <table class="header-table">

        <tr>

            <td class="logo-cell">

                <img
                    src="<?= e(BASE_URL) ?>/assets/images/astmmc-logo.png"
                    alt="ASTMMC"
                    onerror="this.style.display='none';"
                >

            </td>

            <td>

                <div>Department of Health</div>

                <div class="hospital-name">
                    ADELA SERRA TY MEMORIAL MEDICAL CENTER
                </div>

                <div class="form-title">
                    GRAPHICS DESIGN / VIDEO PRODUCTION /
                    PHOTO/VIDEO COVERAGE / IEC MATERIALS
                    JOB REQUEST FORM
                </div>

            </td>

        </tr>

    </table>

    <div class="green-title">
        Implementation and Monitoring Activity
    </div>

    <div class="yellow-title">
        III. Development and Review
        <em>(to be filled out by HPU Personnel)</em>
    </div>

    <table>

        <tr>
            <td width="38%">
                Date of Initial Draft Submission:
                <?= formattedDate(
                    $request["initial_draft_date"]
                ) ?>
            </td>

            <td>
                Revision Required?

                <?= checked(
                    (int) (
                        $request[
                            "revision_required"
                        ] ?? -1
                    ) === 1
                ) ?>
                Yes

                <?= checked(
                    (int) (
                        $request[
                            "revision_required"
                        ] ?? -1
                    ) === 0
                ) ?>
                No,

                Number of Revisions:
                <?= (int) (
                    $request["revision_count"] ?? 0
                ) ?>
            </td>
        </tr>

        <tr>
            <td>
                Final Design Approval Date:
                <?= formattedDate(
                    $request["final_approval_date"]
                ) ?>
            </td>

            <td>
                Mode of Distribution:
                <?= e(
                    $request["distribution_mode"]
                    ?: "N/A"
                ) ?>
            </td>
        </tr>

    </table>

    <div class="draft-area">

        <?php if (!empty($request["final_design_drive_link"])): ?>

        <div
            style="
                display:flex;
                flex-direction:column;
                justify-content:center;
                align-items:center;
                height:100%;
                text-align:center;
                padding:30px;
            "
        >

            <div
                style="
                    font-size:18px;
                    font-weight:bold;
                    color:#1e40af;
                    margin-bottom:15px;
                "
            >
                FINAL DESIGN
            </div>

            <div
                style="
                    font-size:13px;
                    margin-bottom:15px;
                "
            >
                The final artwork is stored in Google Drive.
            </div>

            <a
                href="<?= e($request["final_design_drive_link"]) ?>"
                target="_blank"
                style="
                    color:#2563eb;
                    font-size:12px;
                    word-break:break-all;
                    text-decoration:none;
                "
            >
                <?= e($request["final_design_drive_link"]) ?>
            </a>

            <div
                style="
                    margin-top:20px;
                    font-size:11px;
                    color:#777;
                "
            >
                Scan or click the link to view the latest design.
            </div>

        </div>

        <?php else: ?>

        <div
            style="
                display:flex;
                justify-content:center;
                align-items:center;
                height:100%;
                font-size:14px;
                color:#666;
            "
        >
            No Google Drive link available.
        </div>

    <?php endif; ?>

    <div class="draft-label">
        Draft / Final Design
    </div>

            </div>

         <div
        class="green-title"
        style="margin-top: 10px;"
        >
        Creation and Approval of Post Activity Report
    </div>

    <div class="yellow-title">
        IV. Post Project Evaluation
    </div>

    <ul class="evaluation-list">

        <li>

            Was the design delivered on time?

            <?= checked(
                (int) (
                    $request[
                        "delivered_on_time"
                    ] ?? -1
                ) === 1
            ) ?>
            Yes

            <?= checked(
                (int) (
                    $request[
                        "delivered_on_time"
                    ] ?? -1
                ) === 0
            ) ?>
            No

        </li>

        <li>

            Did the design meet the objectives?

            <?= checked(
                (int) (
                    $request[
                        "objectives_met"
                    ] ?? -1
                ) === 1
            ) ?>
            Yes

            <?= checked(
                (int) (
                    $request[
                        "objectives_met"
                    ] ?? -1
                ) === 0
            ) ?>
            No

        </li>

        <li>

            Were there any challenges encountered?

            <?= checked(
                (int) (
                    $request[
                        "challenges_encountered"
                    ] ?? -1
                ) === 1
            ) ?>
            Yes

            <?= checked(
                (int) (
                    $request[
                        "challenges_encountered"
                    ] ?? -1
                ) === 0
            ) ?>
            No

            <div style="margin-top: 8px;">
                If yes, please specify:
                <?= e(
                    $request["challenges_details"]
                    ?? ""
                ) ?>
            </div>

        </li>

        <li>

            Suggestions for Improvement:

            <?= e(
                $request["improvement_suggestions"]
                ?: "N/A"
            ) ?>

        </li>

        <li>

            Overall Satisfaction Rating:

            &nbsp;
            <?= checked($rating === 1) ?>
            1. Poor

            &nbsp;
            <?= checked($rating === 2) ?>
            2. Fair

            &nbsp;
            <?= checked($rating === 3) ?>
            3. Good

            &nbsp;
            <?= checked($rating === 4) ?>
            4. Very Good

            &nbsp;
            <?= checked($rating === 5) ?>
            5. Excellent

        </li>

    </ul>

    <div class="footer">

        <em>
            Email Address:
            <strong>
                astmmchealthpromounit@gmail.com
            </strong>

            |
            Fanvil: 1002
        </em>

        <span class="document-code">
            OMCC-PHU-HPU-F-13<br>
            Rev. 2<br>
            Effectivity Date: September 15, 2025
        </span>

    </div>

</section>

</body>
</html>
