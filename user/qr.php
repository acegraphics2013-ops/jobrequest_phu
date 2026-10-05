<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";
include "../includes/advanced_helpers.php";

checkLogin();
requireModule($conn, "my_requests");

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

/* =========================================================
   VALIDATE REQUEST ID
========================================================= */
$requestId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

if (!$requestId || $requestId <= 0) {
    http_response_code(400);
    die("Invalid request ID.");
}

/* =========================================================
   CHECK REQUIRED DATABASE COLUMN
========================================================= */
$columnCheck = $conn->query(
    "SHOW COLUMNS
     FROM job_requests
     LIKE 'public_token'"
);

if (
    !$columnCheck ||
    $columnCheck->num_rows === 0
) {
    die(
        "The public_token column is missing. " .
        "Run the QR Tracking database update first."
    );
}

/* =========================================================
   LOAD REQUEST
========================================================= */
$stmt = $conn->prepare(
    "SELECT
        id,
        job_no,
        project_title,
        requestor,
        requestor_name,
        department,
        date_request,
        date_completion,
        approval_status,
        public_token
     FROM job_requests
     WHERE id = ?
     LIMIT 1"
);

$stmt->bind_param(
    "i",
    $requestId
);

$stmt->execute();

$request = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if (!$request) {
    http_response_code(404);
    die("Job request not found.");
}

/* =========================================================
   ACCESS CONTROL
========================================================= */
$role = $_SESSION["role"] ?? "user";
$currentUserId = (int) (
    $_SESSION["user_id"] ?? 0
);

if (
    $role === "user" &&
    (int) $request["requestor"] !==
    $currentUserId
) {
    http_response_code(403);
    die("Access denied.");
}

/* =========================================================
   GENERATE OR LOAD PUBLIC TOKEN
========================================================= */
$token = ensurePublicToken(
    $conn,
    $requestId
);

/* =========================================================
   BUILD TRACKING URL
========================================================= */
$trackingUrl =
    rtrim(
        BASE_URL,
        "/"
    ) .
    "/track.php?token=" .
    rawurlencode($token);

/*
 * QR codes containing localhost work only on the same
 * computer. A phone scanner needs the computer's LAN IP
 * or a public domain.
 */
$isLocalhostUrl =
    stripos(
        $trackingUrl,
        "localhost"
    ) !== false ||
    stripos(
        $trackingUrl,
        "127.0.0.1"
    ) !== false;

/* =========================================================
   FORMATTING HELPERS
========================================================= */
function qrFormattedDate(
    ?string $date
): string {
    if (!$date) {
        return "Not specified";
    }

    $timestamp = strtotime(
        $date
    );

    return $timestamp
        ? date(
            "F d, Y",
            $timestamp
        )
        : $date;
}

function qrStatusClass(
    string $status
): string {
    return match ($status) {
        "Pending Review" =>
            "status-pending",

        "Under Review" =>
            "status-review",

        "Approved" =>
            "status-approved",

        "In Development" =>
            "status-development",

        "For Revision" =>
            "status-revision",

        "Disapproved" =>
            "status-disapproved",

        "Completed" =>
            "status-completed",

        "For Evaluation" =>
            "status-evaluation",

        "Closed" =>
            "status-closed",

        default =>
            "status-default"
    };
}

function qrStatusIcon(
    string $status
): string {
    return match ($status) {
        "Pending Review" =>
            "bi-hourglass-split",

        "Under Review" =>
            "bi-search",

        "Approved" =>
            "bi-check-circle-fill",

        "In Development" =>
            "bi-tools",

        "For Revision" =>
            "bi-arrow-repeat",

        "Disapproved" =>
            "bi-x-circle-fill",

        "Completed" =>
            "bi-check2-all",

        "For Evaluation" =>
            "bi-clipboard2-check",

        "Closed" =>
            "bi-lock-fill",

        default =>
            "bi-info-circle"
    };
}
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
        QR Tracking |
        <?= e($request["job_no"]) ?>
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

    <link
        rel="stylesheet"
        href="<?= e(BASE_URL) ?>/assets/css/page_headers.css"
    >

    <link
        rel="stylesheet"
        href="<?= e(BASE_URL) ?>/assets/css/advanced_modules.css"
    >

    <style>
        /* Page header styles now come from page_headers.css (.ph) */
        .qr-layout {
            display: grid;
            grid-template-columns:
                minmax(320px, 440px)
                minmax(0, 1fr);
            gap: 24px;
        }

        .qr-card {
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            background: #ffffff;
            box-shadow:
                0 12px 34px
                rgba(15, 23, 42, .07);
        }

        .qr-code-panel {
            padding: 30px;
            text-align: center;
        }

        .qr-code-frame {
            display: inline-grid;
            min-width: 270px;
            min-height: 270px;
            place-items: center;
            padding: 22px;
            border: 1px solid #dbe3ef;
            border-radius: 18px;
            background: #ffffff;
            box-shadow:
                0 8px 24px
                rgba(15, 23, 42, .08);
        }

        #requestQrCode img,
        #requestQrCode canvas {
            display: block;
            max-width: 100%;
            height: auto;
        }

        .qr-logo {
            display: grid;
            width: 58px;
            height: 58px;
            margin: 0 auto 14px;
            place-items: center;
            border-radius: 16px;
            background: #eff6ff;
            color: #2563eb;
            font-size: 28px;
        }

        .request-summary {
            padding: 26px;
        }

        .request-job-number {
            color: #2563eb;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: .04em;
        }

        .request-project-title {
            margin: 5px 0 15px;
            color: #0f172a;
            font-size: 24px;
            font-weight: 800;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
        }

        .status-pending {
            background: #f1f5f9;
            color: #475569;
        }

        .status-review {
            background: #cffafe;
            color: #155e75;
        }

        .status-approved {
            background: #dcfce7;
            color: #166534;
        }

        .status-development {
            background: #e0e7ff;
            color: #3730a3;
        }

        .status-revision {
            background: #fef3c7;
            color: #92400e;
        }

        .status-disapproved {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-completed {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-evaluation {
            background: #f3e8ff;
            color: #7e22ce;
        }

        .status-closed {
            background: #e2e8f0;
            color: #0f172a;
        }

        .status-default {
            background: #f1f5f9;
            color: #475569;
        }

        .detail-grid {
            display: grid;
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 22px;
        }

        .detail-item {
            padding: 14px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
        }

        .detail-label {
            margin-bottom: 4px;
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .detail-value {
            color: #0f172a;
            font-size: 14px;
            font-weight: 700;
        }

        .tracking-url-box {
            margin-top: 22px;
        }

        .tracking-url-group {
            display: flex;
            gap: 8px;
        }

        .tracking-url-group input {
            min-width: 0;
        }

        .qr-actions {
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .qr-message {
            display: none;
            margin-top: 13px;
        }

        .qr-message.show {
            display: block;
        }

        .local-warning {
            margin-top: 20px;
            border-left: 4px solid #f59e0b;
        }

        .qr-print-heading {
            display: none;
        }

        @media (max-width: 991px) {
            .qr-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 575px) {
            .detail-grid {
                grid-template-columns: 1fr;
            }

            .tracking-url-group {
                flex-direction: column;
            }

            .qr-code-frame {
                min-width: 230px;
                min-height: 230px;
                padding: 15px;
            }
        }

        @page {
            size: A4 portrait;
            margin: 14mm;
        }

        @media print {
            html,
            body,
            .qr-card,
            .status-badge {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            body {
                background: #ffffff !important;
            }

            .sidebar,
            .ph,
            .qr-actions,
            .tracking-url-box,
            .local-warning,
            .qr-message {
                display: none !important;
            }

            .main,
            .content,
            .container-fluid {
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .qr-layout {
                display: block;
            }

            .qr-card {
                border: 0;
                box-shadow: none;
            }

            .qr-code-panel {
                padding: 20px;
            }

            .request-summary {
                text-align: center;
            }

            .detail-grid {
                max-width: 550px;
                margin-right: auto;
                margin-left: auto;
                text-align: left;
            }

            .qr-print-heading {
                display: block;
                margin-bottom: 20px;
                text-align: center;
            }
        }
    </style>

</head>

<body class="app-shell">

<div class="d-flex min-vh-100">

    <?php include "../includes/sidebar.php"; ?>

    <div class="main flex-grow-1">

        <main class="content">

            <div class="container-fluid">

                <!-- PAGE HEADER -->
                <div class="ph">

                    <div class="ph-copy">
                        <div class="ph-icon"><i class="bi bi-qr-code"></i></div>
                        <div class="min-w-0">
                            <p class="ph-eyebrow">HPU Workbench</p>
                            <h1 class="ph-title">QR Code Tracking</h1>
                            <p class="ph-subtitle">
                                Generate, print and share a secure
                                tracking code for this request.
                            </p>
                        </div>
                    </div>

                    <div class="ph-actions">
                        <a
                            class="btn btn-outline-secondary"
                            href="javascript:history.back();"
                        >
                            <i class="bi bi-arrow-left me-1"></i>
                            Back
                        </a>
                    </div>

                </div>

                <div class="qr-print-heading">

                    <h2>
                        HPU Job Request Tracking
                    </h2>

                    <p>
                        Scan the QR code to view the current
                        request status.
                    </p>

                </div>

                <div class="qr-layout">

                    <!-- QR CODE -->
                    <section class="qr-card qr-code-panel">

                        <div class="qr-logo">
                            <i class="bi bi-qr-code"></i>
                        </div>

                        <h3 class="h5 fw-bold">
                            Scan to Track Request
                        </h3>

                        <p class="text-muted">
                            Use a mobile camera or QR scanner.
                        </p>

                        <div class="qr-code-frame">

                            <div id="requestQrCode">

                                <div
                                    id="qrLoading"
                                    class="text-muted"
                                >
                                    <div
                                        class="spinner-border spinner-border-sm me-2"
                                    ></div>

                                    Generating QR code...
                                </div>

                            </div>

                        </div>

                        <div
                            id="qrError"
                            class="alert alert-danger qr-message"
                        >
                            QR code could not be generated.
                            Check your internet connection and
                            reload the page.
                        </div>

                    </section>

                    <!-- REQUEST DETAILS -->
                    <section class="qr-card request-summary">

                        <div class="request-job-number">
                            <?= e($request["job_no"]) ?>
                        </div>

                        <h1 class="request-project-title">
                            <?= e(
                                $request["project_title"]
                            ) ?>
                        </h1>

                        <span
                            class="status-badge <?= qrStatusClass(
                                $request["approval_status"]
                            ) ?>"
                        >
                            <i
                                class="bi <?= qrStatusIcon(
                                    $request[
                                        "approval_status"
                                    ]
                                ) ?>"
                            ></i>

                            <?= e(
                                $request[
                                    "approval_status"
                                ]
                            ) ?>
                        </span>

                        <div class="detail-grid">

                            <div class="detail-item">

                                <div class="detail-label">
                                    Requestor
                                </div>

                                <div class="detail-value">
                                    <?= e(
                                        $request[
                                            "requestor_name"
                                        ]
                                    ) ?>
                                </div>

                            </div>

                            <div class="detail-item">

                                <div class="detail-label">
                                    Department
                                </div>

                                <div class="detail-value">
                                    <?= e(
                                        $request["department"]
                                    ) ?>
                                </div>

                            </div>

                            <div class="detail-item">

                                <div class="detail-label">
                                    Date Requested
                                </div>

                                <div class="detail-value">
                                    <?= e(
                                        qrFormattedDate(
                                            $request[
                                                "date_request"
                                            ]
                                        )
                                    ) ?>
                                </div>

                            </div>

                            <div class="detail-item">

                                <div class="detail-label">
                                    Preferred Completion
                                </div>

                                <div class="detail-value">
                                    <?= e(
                                        qrFormattedDate(
                                            $request[
                                                "date_completion"
                                            ]
                                        )
                                    ) ?>
                                </div>

                            </div>

                        </div>

                        <div class="tracking-url-box">

                            <label
                                class="form-label fw-bold"
                                for="trackingUrl"
                            >
                                Tracking URL
                            </label>

                            <div class="tracking-url-group">

                                <input
                                    class="form-control"
                                    id="trackingUrl"
                                    type="text"
                                    value="<?= e(
                                        $trackingUrl
                                    ) ?>"
                                    readonly
                                >

                                <button
                                    class="btn btn-outline-primary"
                                    id="copyTrackingButton"
                                    type="button"
                                >
                                    <i class="bi bi-copy me-1"></i>
                                    Copy
                                </button>

                            </div>

                        </div>

                        <div class="qr-actions">

                            <button
                                class="btn btn-primary"
                                type="button"
                                onclick="window.print();"
                            >
                                <i class="bi bi-printer me-1"></i>
                                Print QR
                            </button>

                            <button
                                class="btn btn-outline-primary"
                                id="downloadQrButton"
                                type="button"
                            >
                                <i class="bi bi-download me-1"></i>
                                Save QR Image
                            </button>

                            <a
                                class="btn btn-outline-secondary"
                                href="<?= e($trackingUrl) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <i class="bi bi-box-arrow-up-right me-1"></i>
                                Open Tracking Page
                            </a>

                        </div>

                        <div
                            id="copySuccess"
                            class="alert alert-success qr-message"
                        >
                            Tracking URL copied successfully.
                        </div>

                        <?php if ($isLocalhostUrl): ?>

                            <div
                                class="alert alert-warning local-warning"
                            >

                                <strong>
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    Mobile scanning notice
                                </strong>

                                <div class="mt-1">

                                    This QR code currently contains a
                                    <code>localhost</code> address.
                                    It will work only on this computer.

                                    To scan it using a phone, change
                                    <code>BASE_URL</code> to your
                                    computer's local network IP, such as:

                                </div>

                                <code class="d-block mt-2">
                                    http://192.168.1.10/HPU-Job-Request-System
                                </code>

                            </div>

                        <?php endif; ?>

                    </section>

                </div>

            </div>

        </main>

    </div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
></script>

<script
    src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"
></script>

<script>
document.addEventListener(
    "DOMContentLoaded",
    function () {

        const qrContainer =
            document.getElementById(
                "requestQrCode"
            );

        const qrLoading =
            document.getElementById(
                "qrLoading"
            );

        const qrError =
            document.getElementById(
                "qrError"
            );

        const trackingUrl =
            <?= json_encode(
                $trackingUrl,
                JSON_UNESCAPED_SLASHES |
                JSON_UNESCAPED_UNICODE
            ) ?>;

        /*
         * Generate QR code only when the library
         * loaded successfully.
         */
        if (
            typeof QRCode === "undefined"
        ) {
            if (qrLoading) {
                qrLoading.style.display =
                    "none";
            }

            if (qrError) {
                qrError.classList.add(
                    "show"
                );
            }

            return;
        }

        qrContainer.innerHTML = "";

        new QRCode(
            qrContainer,
            {
                text: trackingUrl,
                width: 230,
                height: 230,
                correctLevel:
                    QRCode.CorrectLevel.H
            }
        );

        /*
         * Copy tracking URL.
         */
        const copyButton =
            document.getElementById(
                "copyTrackingButton"
            );

        const copySuccess =
            document.getElementById(
                "copySuccess"
            );

        copyButton.addEventListener(
            "click",
            async function () {

                try {
                    await navigator.clipboard
                        .writeText(
                            trackingUrl
                        );

                    copySuccess.classList.add(
                        "show"
                    );

                    setTimeout(
                        function () {
                            copySuccess.classList
                                .remove("show");
                        },
                        2500
                    );
                } catch (error) {
                    const input =
                        document.getElementById(
                            "trackingUrl"
                        );

                    input.select();
                    input.setSelectionRange(
                        0,
                        99999
                    );

                    document.execCommand(
                        "copy"
                    );

                    copySuccess.classList.add(
                        "show"
                    );
                }
            }
        );

        /*
         * Download the generated QR code as PNG.
         */
        document
            .getElementById(
                "downloadQrButton"
            )
            .addEventListener(
                "click",
                function () {

                    const canvas =
                        qrContainer.querySelector(
                            "canvas"
                        );

                    const image =
                        qrContainer.querySelector(
                            "img"
                        );

                    let imageUrl = "";

                    if (canvas) {
                        imageUrl =
                            canvas.toDataURL(
                                "image/png"
                            );
                    } else if (image) {
                        imageUrl = image.src;
                    }

                    if (!imageUrl) {
                        alert(
                            "QR image is not ready yet."
                        );

                        return;
                    }

                    const link =
                        document.createElement(
                            "a"
                        );

                    link.href = imageUrl;

                    link.download =
                        <?= json_encode(
                            $request["job_no"] .
                            "_QR.png"
                        ) ?>;

                    document.body.appendChild(
                        link
                    );

                    link.click();
                    link.remove();
                }
            );
    }
);
</script>

</body>
</html>