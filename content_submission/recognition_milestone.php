<?php
declare(strict_types=1);

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";
include "../includes/hpu_availability.php";

checkLogin();
requireModule($conn, "publication_submission");

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

hpuEnsureBlockedDatesTable($conn);


function contentTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?"
    );
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row["total"] ?? 0) > 0;
}

function contentValidDate(?string $value): ?string
{
    $value = trim((string)$value);

    if ($value === "") {
        return null;
    }

    $date = DateTime::createFromFormat("!Y-m-d", $value);

    if (!$date || $date->format("Y-m-d") !== $value) {
        return null;
    }

    return $value;
}

function contentFlash(string $type, string $message): void
{
    $_SESSION["content_submission_flash"] = [
        "type" => $type,
        "message" => $message
    ];
}

function contentPullFlash(): ?array
{
    $flash = $_SESSION["content_submission_flash"] ?? null;
    unset($_SESSION["content_submission_flash"]);
    return is_array($flash) ? $flash : null;
}

function recognitionEnsurePublicTokenColumn(mysqli $conn): void
{
    try {
        $check = $conn->query(
            "SHOW COLUMNS FROM publication_recognition_submissions LIKE 'public_token'"
        );

        if ($check && $check->num_rows > 0) {
            return;
        }

        $conn->query(
            "ALTER TABLE publication_recognition_submissions
             ADD COLUMN public_token CHAR(64) NULL,
             ADD UNIQUE INDEX uniq_recognition_public_token (public_token)"
        );
    } catch (Throwable $e) {
        try {
            $conn->query(
                "ALTER TABLE publication_recognition_submissions
                 ADD COLUMN public_token CHAR(64) NULL"
            );
        } catch (Throwable $e2) {
        }
    }
}

function recognitionNewPublicToken(mysqli $conn): string
{
    do {
        $token = bin2hex(random_bytes(32));

        $stmt = $conn->prepare(
            "SELECT id FROM publication_recognition_submissions WHERE public_token = ? LIMIT 1"
        );

        if (!$stmt) {
            return $token;
        }

        $stmt->bind_param("s", $token);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exists) {
            try {
                $jobCheck = $conn->prepare(
                    "SELECT id FROM job_requests WHERE public_token = ? LIMIT 1"
                );

                if ($jobCheck) {
                    $jobCheck->bind_param("s", $token);
                    $jobCheck->execute();
                    $exists = $jobCheck->get_result()->fetch_assoc();
                    $jobCheck->close();
                }
            } catch (Throwable $e) {
            }
        }
    } while ($exists);

    return $token;
}


$userId = (int)($_SESSION["user_id"] ?? 0);
$sessionEmail = trim((string)($_SESSION["email"] ?? ""));

$recognitionTypes = [
    "Hospital / Institutional Recognition",
    "Award or Recognition",
    "Training / Course Completion",
    "Certification / Accreditation",
    "Fellowship / Diplomate / Professional Milestone",
    "Appointment / Designation",
    "Others"
];

$conn->query(
    "CREATE TABLE IF NOT EXISTS publication_recognition_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        submitted_by INT NOT NULL,
        email_address VARCHAR(190) NOT NULL,
        recognition_type VARCHAR(180) NOT NULL,
        recognized_entity VARCHAR(255) NOT NULL,
        achievement LONGTEXT NOT NULL,
        supporting_information LONGTEXT NULL,
        supporting_files LONGTEXT NULL,
        preferred_publication_date DATE NULL,
        privacy_confirmed TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(40) NOT NULL DEFAULT 'For Review',
        publication_notes TEXT NULL,
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        published_at DATETIME NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_recognition_submitted_by (submitted_by),
        INDEX idx_recognition_created_at (created_at),
        INDEX idx_recognition_pub_date (preferred_publication_date)
    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci"
);

recognitionEnsurePublicTokenColumn($conn);

/*
|--------------------------------------------------------------------------
| Backward-compatible support for multiple recognition types
|--------------------------------------------------------------------------
| The form now uses checkboxes, so more than one type may be selected.
| Store the selected values as JSON in a TEXT column.
*/
$recognitionTypeColumn = $conn->query(
    "SHOW COLUMNS
     FROM publication_recognition_submissions
     LIKE 'recognition_type'"
)->fetch_assoc();

if (
    $recognitionTypeColumn
    &&
    stripos(
        (string)($recognitionTypeColumn["Type"] ?? ""),
        "text"
    ) === false
) {
    $conn->query(
        "ALTER TABLE publication_recognition_submissions
         MODIFY recognition_type TEXT NOT NULL"
    );
}

$storageDir =
    dirname(__DIR__)
    . DIRECTORY_SEPARATOR
    . "storage"
    . DIRECTORY_SEPARATOR
    . "publication_submissions"
    . DIRECTORY_SEPARATOR
    . "recognition";

if (!is_dir($storageDir)) {
    if (
        !mkdir(
            $storageDir,
            0775,
            true
        )
        &&
        !is_dir($storageDir)
    ) {
        exit("Unable to create recognition submission storage directory.");
    }
}

$denyFile =
    $storageDir
    . DIRECTORY_SEPARATOR
    . ".htaccess";

if (!is_file($denyFile)) {
    @file_put_contents(
        $denyFile,
        "Options -Indexes\nRequire all denied\n"
    );
}

if (empty($_SESSION["content_recognition_csrf"])) {
    $_SESSION["content_recognition_csrf"] = bin2hex(random_bytes(32));
}

$csrf = (string)$_SESSION["content_recognition_csrf"];
$error = "";

function recognitionValidateFiles(array $files): array
{
    $normalized = [];

    if (
        !isset($files["name"])
        ||
        !is_array($files["name"])
    ) {
        return [];
    }

    $count = count($files["name"]);

    if ($count > 5) {
        throw new RuntimeException(
            "You may upload up to 5 supporting files."
        );
    }

    $allowedExtensions = [
        "pdf",
        "jpg",
        "jpeg",
        "png",
        "webp",
        "doc",
        "docx"
    ];

    $maxBytes =
        10
        * 1024
        * 1024;

    for ($i = 0; $i < $count; $i++) {
        $error =
            (int)(
                $files["error"][$i]
                ?? UPLOAD_ERR_NO_FILE
            );

        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(
                "One of the supporting files could not be uploaded."
            );
        }

        $size =
            (int)(
                $files["size"][$i]
                ?? 0
            );

        if (
            $size <= 0
            ||
            $size > $maxBytes
        ) {
            throw new RuntimeException(
                "Each supporting file must be 10 MB or smaller."
            );
        }

        $originalName =
            basename(
                (string)(
                    $files["name"][$i]
                    ?? ""
                )
            );

        $extension =
            strtolower(
                pathinfo(
                    $originalName,
                    PATHINFO_EXTENSION
                )
            );

        if (
            !in_array(
                $extension,
                $allowedExtensions,
                true
            )
        ) {
            throw new RuntimeException(
                "Unsupported supporting file type. Use PDF, Word, JPG, PNG, or WEBP files."
            );
        }

        $finfo =
            new finfo(
                FILEINFO_MIME_TYPE
            );

        $mime =
            (string)
            $finfo->file(
                (string)
                $files["tmp_name"][$i]
            );

        $blockedMimeParts = [
            "x-httpd-php",
            "x-php",
            "javascript",
            "text/html",
            "x-sh",
            "x-executable",
            "x-msdownload"
        ];

        foreach ($blockedMimeParts as $blocked) {
            if (
                stripos(
                    $mime,
                    $blocked
                )
                !== false
            ) {
                throw new RuntimeException(
                    "One of the selected files is blocked for security reasons."
                );
            }
        }

        $normalized[] = [
            "tmp_name" =>
                (string)
                $files["tmp_name"][$i],

            "original_name" =>
                $originalName,

            "extension" =>
                $extension,

            "size" =>
                $size,

            "mime" =>
                $mime
        ];
    }

    return $normalized;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedCsrf = (string)($_POST["csrf_token"] ?? "");

    if (
        $postedCsrf === ""
        ||
        !hash_equals($csrf, $postedCsrf)
    ) {
        $error = "Security token expired. Please refresh the page and try again.";
    } else {
        $email = trim((string)($_POST["email_address"] ?? ""));

        $typesSelected =
            $_POST["types"]
            ?? [];

        if (!is_array($typesSelected)) {
            $typesSelected = [];
        }

        $typesSelected =
            array_values(
                array_unique(
                    array_filter(
                        array_map(
                            static fn($value) => trim((string)$value),
                            $typesSelected
                        ),
                        static fn($value) => $value !== ""
                    )
                )
            );

        $recognizedEntity = trim((string)($_POST["recognized_entity"] ?? ""));
        $achievement = trim((string)($_POST["achievement"] ?? ""));
        $supportingInformation = trim((string)($_POST["supporting_information"] ?? ""));
        $pubDate = contentValidDate($_POST["pub_date"] ?? null);
        $privacyConfirmed = isset($_POST["privacy_confirmed"]) ? 1 : 0;

        if (
            $email === ""
            ||
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $error = "Please enter a valid email address.";
        } elseif (!$typesSelected) {
            $error = "Please select at least one recognition / milestone type.";
        } elseif (
            count(
                array_diff(
                    $typesSelected,
                    $recognitionTypes
                )
            ) > 0
        ) {
            $error = "One or more selected recognition / milestone types are invalid.";
        } elseif ($recognizedEntity === "") {
            $error = "Please indicate who or what was recognized.";
        } elseif ($achievement === "") {
            $error = "Please describe what was achieved.";
        } elseif (!$privacyConfirmed) {
            $error = "Please confirm the Data Privacy declaration before submitting.";
        }

        if ($error === "" && $pubDate !== null) {
            $publicationBlock =
                hpuAvailabilityFindBlock(
                    $conn,
                    $pubDate,
                    ["Publication Submission"]
                );

            if ($publicationBlock) {
                $error =
                    hpuAvailabilityMessage(
                        $pubDate,
                        $publicationBlock
                    );
            }
        }

        $validatedFiles = [];

        if (
            $error === ""
            &&
            isset($_FILES["supporting_files"])
        ) {
            try {
                $validatedFiles =
                    recognitionValidateFiles(
                        $_FILES["supporting_files"]
                    );
            } catch (RuntimeException $e) {
                $error =
                    $e->getMessage();
            }
        }

        if ($error === "") {
            $storedFiles = [];

            $recognitionTypesJson =
                json_encode(
                    $typesSelected,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                );

            try {
                foreach ($validatedFiles as $file) {
                    $storedName =
                        bin2hex(
                            random_bytes(18)
                        )
                        . "."
                        . $file["extension"];

                    $destination =
                        $storageDir
                        . DIRECTORY_SEPARATOR
                        . $storedName;

                    if (
                        !move_uploaded_file(
                            $file["tmp_name"],
                            $destination
                        )
                    ) {
                        throw new RuntimeException(
                            "A supporting file could not be saved."
                        );
                    }

                    $storedFiles[] = [
                        "original_name" =>
                            $file["original_name"],

                        "stored_name" =>
                            $storedName,

                        "extension" =>
                            $file["extension"],

                        "size" =>
                            $file["size"],

                        "mime" =>
                            $file["mime"]
                    ];
                }

                $filesJson =
                    $storedFiles
                        ? json_encode(
                            $storedFiles,
                            JSON_UNESCAPED_UNICODE |
                            JSON_UNESCAPED_SLASHES
                        )
                        : null;

                $publicToken = recognitionNewPublicToken($conn);

                $stmt = $conn->prepare(
                    "INSERT INTO publication_recognition_submissions (
                        submitted_by,
                        email_address,
                        recognition_type,
                        recognized_entity,
                        achievement,
                        supporting_information,
                        supporting_files,
                        preferred_publication_date,
                        privacy_confirmed,
                        public_token
                     )
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );

                $stmt->bind_param(
                    "isssssssis",
                    $userId,
                    $email,
                    $recognitionTypesJson,
                    $recognizedEntity,
                    $achievement,
                    $supportingInformation,
                    $filesJson,
                    $pubDate,
                    $privacyConfirmed,
                    $publicToken
                );

                $stmt->execute();
                $stmt->close();

                contentFlash(
                    "success",
                    "Recognition / Milestone Documentation submitted successfully. HPU personnel can review it under Publication Requests."
                );

                header(
                    "Location: "
                    . BASE_URL
                    . "/content_submission/recognition_milestone.php"
                );
                exit;

            } catch (Throwable $e) {
                foreach ($storedFiles as $storedFile) {
                    $path =
                        $storageDir
                        . DIRECTORY_SEPARATOR
                        . basename(
                            (string)
                            $storedFile["stored_name"]
                        );

                    if (is_file($path)) {
                        @unlink($path);
                    }
                }

                if ($e instanceof RuntimeException) {
                    $error =
                        $e->getMessage();
                } else {
                    throw $e;
                }
            }
        }
    }
}

$flash = contentPullFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recognition / Milestone Documentation | HPU Workbench</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">

    <style>
        :root{
            --ui-primary:#2563eb;
            --ui-text:#0f172a;
            --ui-muted:#64748b;
            --ui-border:#dbe3ef;
            --ui-bg:#f4f7fb;
            --ui-surface:#fff;
        }
        body{background:var(--ui-bg);}
        .content-submit-page{max-width:1040px;margin:0 auto;}
        .clean-card{overflow:hidden;margin-bottom:16px;border:1px solid var(--ui-border);border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.05);}
        .stack-section{padding:20px;}
        .doc-intro{display:flex;gap:12px;margin-bottom:14px;padding:14px 15px;border:1px solid #bfdbfe;border-radius:13px;background:#f8fbff;color:#334155;font-size:.76rem;line-height:1.55;}
        .privacy-box{display:flex;gap:11px;margin-bottom:18px;padding:14px 15px;border:1px solid #e2e8f0;border-radius:13px;background:#f8fafc;color:#475569;font-size:.69rem;line-height:1.55;}
        .doc-intro i,.privacy-box i{font-size:1rem;margin-top:1px;}
        .doc-intro i{color:#2563eb;}
        .section-caption{margin:0 0 13px;color:#0f172a;font-size:.82rem;font-weight:850;}
        .form-label{color:#334155;font-size:.72rem;font-weight:800;}
        .form-control,.form-select{min-height:43px;border-color:#dbe3ef;border-radius:9px;font-size:.8rem;}
        textarea.form-control{min-height:auto;}
        .field-help{margin-top:4px;color:#64748b;font-size:.59rem;line-height:1.4;}
        .required-star{color:#b42318;}
        .type-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;}
        .type-option{display:flex;align-items:flex-start;gap:8px;padding:10px;border:1px solid #dbe3ef;border-radius:10px;background:#fff;cursor:pointer;font-size:.69rem;font-weight:720;color:#334155;}
        .type-option:has(input:checked){border-color:#93c5fd;background:#eff6ff;color:#1d4ed8;}
        .type-option input{margin-top:2px;}
        .request-actions{position:sticky;bottom:0;z-index:10;display:flex;justify-content:flex-end;gap:8px;margin-top:14px;padding:12px 0 3px;background:linear-gradient(to bottom,rgba(244,247,251,0),#f4f7fb 42%);}
        .request-actions .btn{min-width:135px;font-weight:750;}
        .availability-warning{display:none;margin-top:5px;color:#b42318;font-size:.68rem;font-weight:750;}
        .availability-warning.show{display:block;}
        .flatpickr-input[readonly]{background:#fff;cursor:pointer;}
        .flatpickr-day.flatpickr-disabled,.flatpickr-day.flatpickr-disabled:hover{color:#b42318!important;background:#fff1f0!important;text-decoration:line-through;cursor:not-allowed;}
        .privacy-confirm{display:flex;align-items:flex-start;gap:9px;padding:12px;border:1px solid #dbe3ef;border-radius:10px;background:#fff;}
        .privacy-confirm .form-check-input{margin-top:2px;flex:0 0 auto;}
        /* ---- Polished recognition page header ---- */
        .rm-crumb{display:flex;align-items:center;flex-wrap:wrap;gap:7px;margin:0 0 10px 2px;color:#94a3b8;font-size:.7rem;font-weight:650;}
        .rm-crumb a{display:inline-flex;align-items:center;gap:5px;color:#64748b;text-decoration:none;font-weight:750;}
        .rm-crumb a:hover{color:#1d4ed8;text-decoration:underline;}
        .rm-crumb-sep{color:#cbd5e1;font-weight:400;}
        .rm-crumb-current{color:#0f172a;font-weight:800;}
        .ph--recognition{position:relative;overflow:hidden;padding:20px 22px;border:1px solid #dbe6f7;background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%);}
        .ph--recognition::before{content:"";position:absolute;inset:0 auto 0 0;width:4px;background:linear-gradient(180deg,#2563eb,#7c3aed 55%,#f59e0b);}
        .ph--recognition .ph-copy{align-items:flex-start;}
        .ph-icon--recognition{width:52px;height:52px;flex:0 0 52px;border:0;border-radius:14px;background:linear-gradient(135deg,#2563eb 0%,#4f46e5 55%,#7c3aed 100%);color:#fff;font-size:23px;box-shadow:0 8px 18px rgba(37,99,235,.28),inset 0 1px 0 rgba(255,255,255,.35);}
        .ph--recognition .ph-eyebrow{display:flex;align-items:center;gap:7px;color:#1d4ed8;}
        .ph--recognition .eyebrow-dot{width:7px;height:7px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.18);}
        .ph--recognition .eyebrow-divider{color:#b6c6e3;font-weight:400;}
        .ph--recognition .ph-title{font-size:1.45rem;letter-spacing:-.025em;}
        .ph--recognition .ph-subtitle{max-width:62ch;}
        .rm-meta{display:flex;flex-wrap:wrap;gap:6px 14px;margin:10px 0 0;padding:0;list-style:none;color:#475569;font-size:.7rem;font-weight:650;}
        .rm-meta li{display:inline-flex;align-items:center;gap:6px;}
        .rm-meta i{color:#2563eb;font-size:.78rem;}
        .rm-code{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1px dashed #93c5fd;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:.7rem;font-weight:850;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap;}
        .ph--recognition .ph-actions{align-items:center;}
        .ph--recognition .ph-actions .btn{box-shadow:0 2px 8px rgba(37,99,235,.12);}
        /* ============ WIZARD UX (additive, no backend change) ============ */
        .wizard-on .request-actions{display:none !important;}
        .wizard-shell{margin:0 0 14px;}
        .wizard-progress{height:6px;border-radius:999px;background:#e6edf7;overflow:hidden;margin-bottom:10px;box-shadow:inset 0 1px 2px rgba(15,23,42,.06);}
        .wizard-progress-bar{height:100%;width:33%;border-radius:999px;background:linear-gradient(90deg,#2563eb,#7c3aed);transition:width .35s ease;}
        .wizard-steps{list-style:none;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:0 0 10px;padding:0;}
        .wizard-step{margin:0;}
        .wizard-step-btn{display:flex;gap:10px;align-items:center;width:100%;padding:10px 12px;border:1px solid var(--ui-border);border-radius:12px;background:#fff;cursor:pointer;text-align:left;transition:.18s;color:var(--ui-text);}
        .wizard-step-btn:hover{border-color:#93c5fd;box-shadow:0 4px 12px rgba(37,99,235,.08);}
        .wizard-step-num{display:grid;width:30px;height:30px;flex:0 0 30px;place-items:center;border-radius:50%;background:#eef2f7;color:#475569;font-size:.78rem;font-weight:850;}
        .wizard-step-copy{min-width:0;}
        .wizard-step-copy strong{display:block;font-size:.74rem;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .wizard-step-copy small{display:block;color:var(--ui-muted);font-size:.6rem;font-weight:650;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .wizard-step.is-active .wizard-step-btn{border-color:var(--ui-primary);background:#eff6ff;box-shadow:0 0 0 3px rgba(37,99,235,.12);}
        .wizard-step.is-active .wizard-step-num{background:var(--ui-primary);color:#fff;}
        .wizard-step.is-done .wizard-step-num{background:#16a34a;color:#fff;}
        .wizard-step.is-done .wizard-step-btn{border-color:#bbf7d0;}
        .wizard-step.is-invalid .wizard-step-btn{border-color:#f04438;background:#fff6f5;}
        .wizard-step.is-invalid .wizard-step-num{background:#f04438;color:#fff;}
        .wizard-meta{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;color:var(--ui-muted);font-size:.68rem;font-weight:700;}
        .wizard-meta .draft-status{display:inline-flex;align-items:center;gap:6px;}
        .wizard-meta .draft-dot{width:7px;height:7px;border-radius:50%;background:#cbd5e1;}
        .wizard-meta .draft-dot.saved{background:#22c55e;}
        .wizard-panel{display:none;}
        .wizard-panel.is-active{display:block;animation:wizardIn .28s ease both;}
        @keyframes wizardIn{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:none;}}
        .draft-banner{display:none;gap:10px;align-items:flex-start;margin-bottom:12px;padding:11px 13px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;color:#1e3a8a;font-size:.76rem;line-height:1.45;}
        .draft-banner.show{display:flex;}
        .wizard-error-summary{display:none;margin-bottom:14px;padding:12px 14px;border:1px solid #fecaca;border-radius:12px;background:#fef2f2;color:#7f1d1d;font-size:.76rem;}
        .wizard-error-summary.show{display:block;}
        .wizard-error-summary strong{display:block;margin-bottom:6px;}
        .wizard-error-summary ul{margin:0;padding-left:18px;}
        .wizard-error-summary a{color:#b42318;font-weight:700;cursor:pointer;}
        .field-invalid{border-color:#f04438 !important;box-shadow:0 0 0 .16rem rgba(240,68,56,.12) !important;background:#fff7f7 !important;}
        .char-counter{margin-top:4px;color:#94a3b8;font-size:.6rem;font-weight:650;text-align:right;}
        .char-counter.warn{color:#b45309;}
        .type-option{position:relative;transition:.16s;}
        .type-option:hover{border-color:#93c5fd;box-shadow:0 4px 12px rgba(37,99,235,.08);}
        .type-option .type-icon{display:grid;width:30px;height:30px;flex:0 0 30px;place-items:center;border-radius:8px;background:#f1f5f9;color:#475569;font-size:.85rem;}
        .type-option:has(input:checked) .type-icon{background:var(--ui-primary);color:#fff;}
        .dropzone{position:relative;border:1.5px dashed #b6c6e3;border-radius:12px;background:#fbfdff;padding:14px;transition:.18s;cursor:pointer;}
        .dropzone:hover,.dropzone.dragover{border-color:var(--ui-primary);background:#eff6ff;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
        .dropzone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;}
        .dropzone-hint{display:flex;gap:9px;align-items:center;color:#475569;font-size:.7rem;font-weight:650;pointer-events:none;}
        .dropzone-hint i{font-size:1.2rem;color:var(--ui-primary);}
        .dropzone-hint small{display:block;color:#94a3b8;font-weight:500;}
        .file-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
        .file-chip{display:inline-flex;align-items:center;gap:6px;max-width:100%;padding:5px 6px 5px 10px;border:1px solid #dbe3ef;border-radius:9px;background:#f8fafc;color:#334155;font-size:.68rem;font-weight:650;}
        .file-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:220px;}
        .file-chip small{color:#94a3b8;}
        .file-chip button{display:grid;width:20px;height:20px;place-items:center;border:0;border-radius:50%;background:#fee2e2;color:#b42318;cursor:pointer;flex:0 0 20px;}
        .file-chip button:hover{background:#fecaca;}
        .review-card{margin-bottom:12px;border:1px solid #dbe3ef;border-radius:14px;background:#fff;overflow:hidden;}
        .review-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 14px;border-bottom:1px solid #edf1f5;background:#fbfcfe;}
        .review-head h5{margin:0;font-size:.76rem;font-weight:850;color:#1e293b;display:flex;gap:8px;align-items:center;}
        .review-head h5 i{color:var(--ui-primary);}
        .review-edit{border:1px solid var(--ui-border);background:#fff;border-radius:8px;font-size:.66rem;font-weight:800;padding:4px 10px;cursor:pointer;color:#334155;}
        .review-edit:hover{border-color:#93c5fd;color:#1d4ed8;}
        .review-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 14px;padding:12px 14px;font-size:.74rem;}
        .review-item{min-width:0;}
        .review-item.full{grid-column:1/-1;}
        .review-item small{display:block;color:#94a3b8;font-size:.58rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;margin-bottom:2px;}
        .review-item div{color:#0f172a;font-weight:650;overflow-wrap:anywhere;}
        .review-empty{color:#94a3b8 !important;font-weight:500 !important;font-style:italic;}
        .review-chips{display:flex;flex-wrap:wrap;gap:5px;}
        .review-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:.64rem;font-weight:750;}
        .wizard-footer{position:sticky;bottom:0;z-index:20;display:flex;align-items:center;gap:10px;margin-top:14px;padding:12px 14px;border:1px solid var(--ui-border);border-radius:14px;background:rgba(255,255,255,.96);backdrop-filter:blur(8px);box-shadow:0 10px 28px rgba(15,23,42,.10);}
        .wizard-footer .step-count{color:var(--ui-muted);font-size:.68rem;font-weight:800;white-space:nowrap;}
        .wizard-footer .spacer{flex:1;}
        .wizard-footer .btn{min-height:44px;border-radius:10px;font-weight:800;padding:8px 18px;}
        .shake{animation:wshake .3s ease;}
        @keyframes wshake{25%{transform:translateX(-4px);}50%{transform:translateX(4px);}75%{transform:translateX(-2px);}}
        @media(max-width:650px){
            .wizard-step-copy strong{font-size:.62rem;}
            .wizard-step-copy small{display:none;}
            .review-grid{grid-template-columns:1fr;}
            .wizard-footer{flex-wrap:wrap;}
            .wizard-footer .btn{flex:1;}
            .content{padding:12px;}
            .stack-section{padding:15px;}
            .type-grid{grid-template-columns:1fr;}
            .request-actions{flex-direction:column-reverse;}
            .request-actions .btn{width:100%;}
            .ph--recognition{padding:16px;}
            .ph--recognition .ph-title{font-size:1.2rem;}
            .ph-icon--recognition{width:44px;height:44px;flex-basis:44px;font-size:19px;}
            .rm-meta{gap:5px 12px;}
        }
    </style>
</head>

<body class="app-shell">
<div class="d-flex min-vh-100">

<?php include "../includes/sidebar.php"; ?>

<div class="main flex-grow-1">
<main class="content">
<div class="container-fluid">
<div class="content-submit-page">

    <nav class="rm-crumb" aria-label="Breadcrumb">
        <a href="<?= e(BASE_URL) ?>/dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a>
        <span class="rm-crumb-sep">/</span>
        <span>Publication Submission</span>
        <span class="rm-crumb-sep">/</span>
        <span class="rm-crumb-current" aria-current="page">Recognition / Milestone</span>
    </nav>

    <div class="ph ph--recognition">
        <div class="ph-copy">
            <div class="ph-icon ph-icon--recognition" aria-hidden="true">
                <i class="bi bi-trophy-fill"></i>
            </div>
            <div class="min-w-0">
                <p class="ph-eyebrow"><span class="eyebrow-dot" aria-hidden="true"></span> Publication Submission <span class="eyebrow-divider">•</span> Recognition &amp; Milestones</p>
                <h1 class="ph-title">Recognition / Milestone Documentation</h1>
                <p class="ph-subtitle">Celebrate excellence — submit awards, certifications, trainings, and professional milestones for official ASTMMC publication.</p>
                <ul class="rm-meta">
                    <li><i class="bi bi-clock-history"></i> ~5 mins to complete</li>
                    <li><i class="bi bi-paperclip"></i> Up to 5 supporting files</li>
                    <li><i class="bi bi-eye"></i> HPU review before publishing</li>
                </ul>
            </div>
        </div>
        <div class="ph-actions">
            <span class="rm-code">
                <i class="bi bi-file-earmark-text"></i>
                Form RMDF
            </span>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/content_submission/event_activity.php">
                <i class="bi bi-arrow-left-right"></i>
                Event / Activity form
            </a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash["type"]) ?> alert-dismissible fade show">
            <?= e($flash["message"]) ?>
            <button class="btn-close" type="button" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form
        id="recognitionForm"
        method="post"
        action="recognition_milestone.php"
        enctype="multipart/form-data"
    >
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

        <section class="clean-card">
            <div class="stack-section">

                <div class="doc-intro">
                    <i class="bi bi-trophy-fill"></i>
                    <div>
                        Use this form for recognitions, awards, certifications, training completions, professional milestones, and other accomplishments proposed for institutional publication.
                    </div>
                </div>

                <div class="privacy-box">
                    <i class="bi bi-shield-lock-fill"></i>
                    <div>
                        <strong>Data Privacy Disclaimer.</strong>
                        Personal data submitted through this form shall be processed for legitimate institutional purposes such as documentation, reporting, publication, and knowledge management. By submitting, the office confirms that personal data included has been collected with proper consent and may be processed for the stated purposes.
                    </div>
                </div>

                <div class="row g-3">

                    <div class="col-12">
                        <label class="form-label">
                            Email Address <span class="required-star">*</span>
                        </label>
                        <input
                            type="email"
                            class="form-control"
                            name="email_address"
                            value="<?= e($_POST["email_address"] ?? $sessionEmail) ?>"
                            required
                        >
                    </div>

                    <div class="col-12">
                        <label class="form-label">
                            Type of Activity <span class="required-star">*</span>
                        </label>

                        <div class="type-grid">
                            <?php
                            $postedRecognitionTypes =
                                $_POST["types"]
                                ?? [];

                            if (!is_array($postedRecognitionTypes)) {
                                $postedRecognitionTypes = [];
                            }
                            ?>
                            <?php foreach ($recognitionTypes as $recognitionType): ?>
                                <label class="type-option">
                                    <input
                                        class="form-check-input recognition-type-check"
                                        type="checkbox"
                                        name="types[]"
                                        value="<?= e($recognitionType) ?>"
                                        <?= in_array($recognitionType, $postedRecognitionTypes, true) ? "checked" : "" ?>
                                    >
                                    <span><?= e($recognitionType) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div
                            id="recognitionTypeWarning"
                            class="field-help text-danger d-none"
                        >
                            Please select at least one recognition / milestone type.
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">
                            Who or What Was Recognized? <span class="required-star">*</span>
                        </label>

                        <input
                            class="form-control"
                            name="recognized_entity"
                            value="<?= e($_POST["recognized_entity"] ?? "") ?>"
                            placeholder="Name of Hospital / Employee with position / Team / Unit"
                            required
                        >
                    </div>

                    <div class="col-12">
                        <label class="form-label">
                            What Was Achieved? <span class="required-star">*</span>
                        </label>

                        <textarea
                            class="form-control"
                            name="achievement"
                            rows="5"
                            required
                            placeholder="State the award, training, certification, recognition, recognizing organization, date, and venue if applicable."
                        ><?= e($_POST["achievement"] ?? "") ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label">
                            Supporting Information
                        </label>

                        <textarea
                            class="form-control"
                            name="supporting_information"
                            rows="5"
                            placeholder="Indicate relevant competencies gained, certification level, distinction received, validity period, or other important details."
                        ><?= e($_POST["supporting_information"] ?? "") ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label">
                            Supporting Materials
                        </label>

                        <input
                            id="supportingFiles"
                            type="file"
                            class="form-control"
                            name="supporting_files[]"
                            accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp"
                            multiple
                        >

                        <div class="field-help">
                            Photo of certificate, plaque, event, or person. Upload up to 5 supported files, maximum 10 MB per file.
                        </div>

                        <div
                            id="supportingFilesInfo"
                            class="field-help"
                        ></div>
                    </div>

                </div>
            </div>
        </section>

        <section class="clean-card">
            <div class="stack-section">

                <h2 class="section-caption">
                    Publication Scheduling
                </h2>

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">
                            Preferred Publication Date
                        </label>

                        <input
                            id="pub_date"
                            type="text"
                            inputmode="none"
                            autocomplete="off"
                            class="form-control"
                            name="pub_date"
                            value="<?= e($_POST["pub_date"] ?? "") ?>"
                        >

                        <div class="field-help">
                            Dates blocked for Publication Submission cannot be selected.
                        </div>

                        <div
                            id="pub_date_warning"
                            class="availability-warning"
                            role="alert"
                        ></div>
                    </div>

                    <div class="col-12">
                        <label class="privacy-confirm">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="privacy_confirmed"
                                value="1"
                                required
                                <?= isset($_POST["privacy_confirmed"]) ? "checked" : "" ?>
                            >
                            <span>
                                I confirm that any personal data included in this submission has been collected with proper consent and may be processed for ASTMMC documentation, reporting, publication, and knowledge-management purposes.
                            </span>
                        </label>
                    </div>

                </div>
            </div>
        </section>

        <div class="request-actions">
            <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/dashboard.php">
                Cancel
            </a>

            <button class="btn btn-primary" type="submit">
                <i class="bi bi-send-check me-1"></i>
                Submit Documentation
            </button>
        </div>

    </form>

</div>
</div>
</main>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
document.addEventListener(
    "DOMContentLoaded",
    async function () {

        const recognitionForm =
            document.getElementById(
                "recognitionForm"
            );

        const recognitionTypeChecks =
            Array.from(
                document.querySelectorAll(
                    ".recognition-type-check"
                )
            );

        const recognitionTypeWarning =
            document.getElementById(
                "recognitionTypeWarning"
            );

        function validateRecognitionTypes() {
            const hasSelection =
                recognitionTypeChecks.some(
                    function (checkbox) {
                        return checkbox.checked;
                    }
                );

            recognitionTypeWarning.classList.toggle(
                "d-none",
                hasSelection
            );

            return hasSelection;
        }

        recognitionTypeChecks.forEach(
            function (checkbox) {
                checkbox.addEventListener(
                    "change",
                    validateRecognitionTypes
                );
            }
        );

        recognitionForm.addEventListener(
            "submit",
            function (event) {
                if (!validateRecognitionTypes()) {
                    event.preventDefault();

                    recognitionTypeChecks[0]?.focus();
                }
            }
        );

        const fileInput =
            document.getElementById(
                "supportingFiles"
            );

        const fileInfo =
            document.getElementById(
                "supportingFilesInfo"
            );

        fileInput.addEventListener(
            "change",
            function () {
                const files =
                    Array.from(
                        fileInput.files || []
                    );

                if (files.length > 5) {
                    alert(
                        "You may upload up to 5 supporting files."
                    );

                    fileInput.value =
                        "";

                    fileInfo.textContent =
                        "";

                    return;
                }

                const oversized =
                    files.find(
                        function (file) {
                            return (
                                file.size
                                >
                                10 * 1024 * 1024
                            );
                        }
                    );

                if (oversized) {
                    alert(
                        "Each supporting file must be 10 MB or smaller."
                    );

                    fileInput.value =
                        "";

                    fileInfo.textContent =
                        "";

                    return;
                }

                if (files.length) {
                    fileInfo.textContent =
                        files.length
                        + " file"
                        + (
                            files.length === 1
                                ? ""
                                : "s"
                          )
                        + " selected.";
                } else {
                    fileInfo.textContent =
                        "";
                }
            }
        );

        const field =
            document.getElementById(
                "pub_date"
            );

        const warning =
            document.getElementById(
                "pub_date_warning"
            );

        if (!field || !warning) {
            return;
        }

        let blockedRanges = [];

        try {
            const params =
                new URLSearchParams();

            params.append(
                "services[]",
                "Publication Submission"
            );

            const response =
                await fetch(
                    <?= json_encode(BASE_URL . "/ajax/get_blocked_dates.php") ?>
                    + "?"
                    + params.toString(),
                    {
                        credentials:
                            "same-origin"
                    }
                );

            const data =
                await response.json();

            if (
                data.success
                &&
                Array.isArray(
                    data.ranges
                )
            ) {
                blockedRanges =
                    data.ranges;
            }
        } catch (error) {
            console.error(
                "Blocked publication dates load failed:",
                error
            );
        }

        flatpickr(
            field,
            {
                dateFormat: "Y-m-d",
                altInput: true,
                altFormat: "F j, Y",
                allowInput: false,
                disableMobile: true,
                defaultDate:
                    field.value || null,
                disable:
                    blockedRanges.map(
                        function (range) {
                            return {
                                from: range.from,
                                to: range.to
                            };
                        }
                    ),
                onChange:
                    async function () {

                        warning.classList.remove(
                            "show"
                        );

                        warning.textContent =
                            "";

                        if (!field.value) {
                            return;
                        }

                        const params =
                            new URLSearchParams();

                        params.set(
                            "date",
                            field.value
                        );

                        params.append(
                            "services[]",
                            "Publication Submission"
                        );

                        try {
                            const response =
                                await fetch(
                                    <?= json_encode(BASE_URL . "/ajax/check_blocked_date.php") ?>
                                    + "?"
                                    + params.toString(),
                                    {
                                        credentials:
                                            "same-origin"
                                    }
                                );

                            const data =
                                await response.json();

                            if (
                                data.success
                                &&
                                data.blocked
                            ) {
                                field._flatpickr.clear();

                                warning.textContent =
                                    data.message;

                                warning.classList.add(
                                    "show"
                                );
                            }
                        } catch (error) {
                            console.error(
                                "Publication availability re-check failed:",
                                error
                            );
                        }
                    }
            }
        );

    }
);
</script>
<script>
/* =====================================================
   RECOGNITION / MILESTONE — WIZARD UX (additive, no backend change)
===================================================== */
document.addEventListener("DOMContentLoaded", function () {
    var form = document.getElementById("recognitionForm");
    if (!form) return;
    function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function esc(s) { return String(s == null ? "" : s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;"); }
    function val(name) { var el = form.querySelector('[name="' + name + '"]'); return el ? (el.value || "").trim() : ""; }

    var cards = Array.prototype.filter.call(form.children, function (el) {
        return el.tagName === "SECTION" && el.classList.contains("clean-card");
    });
    if (cards.length < 2) return;
    var mainCard = cards[0], schedCard = cards[1];
    var stack = mainCard.querySelector(".stack-section");
    var row = mainCard.querySelector(".row");
    var cols = row ? Array.prototype.slice.call(row.children) : [];
    /* cols order: 0 email, 1 types, 2 entity, 3 achievement, 4 supporting info, 5 files */
    var docIntro = mainCard.querySelector(".doc-intro");
    var privacyBox = mainCard.querySelector(".privacy-box");
    if (cols.length < 6) return;

    form.classList.add("wizard-on");
    form.setAttribute("novalidate", "novalidate");

    /* type option icons */
    var iconFor = function (t) {
        t = (t || "").toLowerCase();
        if (t.indexOf("hospital") !== -1) return "bi-hospital";
        if (t.indexOf("award") !== -1) return "bi-award-fill";
        if (t.indexOf("training") !== -1) return "bi-mortarboard-fill";
        if (t.indexOf("certification") !== -1) return "bi-patch-check-fill";
        if (t.indexOf("fellowship") !== -1 || t.indexOf("diplomate") !== -1) return "bi-person-badge-fill";
        if (t.indexOf("appointment") !== -1) return "bi-briefcase-fill";
        return "bi-three-dots";
    };
    $all(".type-option", form).forEach(function (opt) {
        if (opt.querySelector(".type-icon")) return;
        var inp = opt.querySelector("input");
        var ic = document.createElement("span");
        ic.className = "type-icon";
        ic.innerHTML = '<i class="bi ' + iconFor(inp ? inp.value : "") + '"></i>';
        opt.insertBefore(ic, opt.firstChild);
    });

    var steps = [
        { t: "Recognition", s: "Who & what" },
        { t: "Evidence", s: "Details & files" },
        { t: "Schedule & Review", s: "Publish & send" }
    ];
    var shell = document.createElement("div");
    shell.className = "wizard-shell";
    shell.innerHTML =
        '<div class="wizard-progress" aria-hidden="true"><div class="wizard-progress-bar"></div></div>' +
        '<ol class="wizard-steps" aria-label="Form progress"></ol>' +
        '<div class="wizard-meta"><span class="step-indicator">Step 1 of 3</span>' +
        '<span class="draft-status"><span class="draft-dot"></span><span class="draft-text">No unsaved draft</span></span></div>' +
        '<div class="draft-banner" role="status"></div>' +
        '<div class="wizard-error-summary" role="alert"></div>';
    form.insertBefore(shell, form.firstChild);
    var stepsOl = shell.querySelector(".wizard-steps");
    steps.forEach(function (st, i) {
        var li = document.createElement("li");
        li.className = "wizard-step";
        li.innerHTML = '<button type="button" class="wizard-step-btn" data-goto="' + (i + 1) + '">' +
            '<span class="wizard-step-num">' + (i + 1) + '</span>' +
            '<span class="wizard-step-copy"><strong>' + esc(st.t) + '</strong><small>' + esc(st.s) + '</small></span></button>';
        stepsOl.appendChild(li);
    });
    var errBox = shell.querySelector(".wizard-error-summary");
    var banner = shell.querySelector(".draft-banner");

    var footer = document.createElement("div");
    footer.className = "wizard-footer";
    footer.innerHTML =
        '<button type="button" class="btn btn-outline-secondary btn-back">&larr; Back</button>' +
        '<span class="step-count">Step 1 of 3</span><span class="spacer"></span>' +
        '<a class="btn btn-outline-secondary" href="dashboard.php">Cancel</a>' +
        '<button type="button" class="btn btn-primary btn-next">Continue &rarr;</button>' +
        '<button type="submit" class="btn btn-primary btn-submit" style="display:none;"><i class="bi bi-send-check me-1"></i>Submit Documentation</button>';
    (function () {
        var cancel = form.querySelector(".request-actions a.btn");
        if (cancel) footer.querySelector("a.btn").setAttribute("href", cancel.getAttribute("href"));
    })();
    form.appendChild(footer);
    var backBtn = footer.querySelector(".btn-back");
    var nextBtn = footer.querySelector(".btn-next");
    var submitBtn = footer.querySelector(".btn-submit");
    var stepCount = footer.querySelector(".step-count");
    var progressBar = shell.querySelector(".wizard-progress-bar");
    var stepIndicator = shell.querySelector(".step-indicator");
    var current = 1;

    function paintStep(n) {
        var showMain = n === 1 || n === 2;
        mainCard.style.display = showMain ? "" : "none";
        schedCard.style.display = n === 3 ? "" : "none";
        if (docIntro) docIntro.style.display = n === 1 ? "" : "none";
        if (privacyBox) privacyBox.style.display = n === 1 ? "" : "none";
        cols.forEach(function (c, i) {
            if (i <= 3) c.style.display = n === 1 ? "" : "none";
            else c.style.display = n === 2 ? "" : "none";
        });
    }
    function showErrors(items) {
        $all(".field-invalid", form).forEach(function (el) { el.classList.remove("field-invalid"); });
        if (!items.length) { errBox.classList.remove("show"); errBox.innerHTML = ""; return; }
        items.forEach(function (it) { if (it.el && it.el.classList) it.el.classList.add("field-invalid"); });
        errBox.innerHTML = "<strong>Please fix " + items.length + " item" + (items.length > 1 ? "s" : "") + " to continue:</strong><ul>" +
            items.map(function (it, i) { return '<li><a data-err="' + i + '">' + esc(it.label) + " — " + esc(it.msg) + "</a></li>"; }).join("") + "</ul>";
        errBox.classList.add("show");
        $all("a[data-err]", errBox).forEach(function (a) {
            a.addEventListener("click", function () {
                var it = items[+a.getAttribute("data-err")];
                if (it && it.el && it.el.focus) { try { it.el.focus(); } catch (e) {} it.el.scrollIntoView({ behavior: "smooth", block: "center" }); }
            });
        });
    }
    function validateStep(n) {
        var items = [];
        if (n === 1) {
            var em = form.querySelector('[name="email_address"]');
            if (!em || !/.+@.+\..+/.test((em.value || "").trim())) items.push({ el: em, label: "Email address", msg: "enter a valid email" });
            if (!$all('input[name="types[]"]:checked', form).length) {
                var tg = form.querySelector(".type-grid");
                items.push({ el: tg, label: "Recognition type", msg: "select at least one" });
            }
            var en = form.querySelector('[name="recognized_entity"]');
            if (!en || !(en.value || "").trim()) items.push({ el: en, label: "Who was recognized", msg: "required" });
            var ac = form.querySelector('[name="achievement"]');
            if (!ac || !(ac.value || "").trim()) items.push({ el: ac, label: "What was achieved", msg: "required" });
        } else if (n === 2) {
            var fi = document.getElementById("supportingFiles");
            if (fi && fi.files && fi.files.length > 5) items.push({ el: fi, label: "Supporting files", msg: "up to 5 files only" });
            if (fi && fi.files) {
                var big = Array.prototype.some.call(fi.files, function (f) { return f.size > 10 * 1024 * 1024; });
                if (big) items.push({ el: fi, label: "Supporting files", msg: "each file must be 10 MB or smaller" });
            }
        } else if (n === 3) {
            var pc = form.querySelector('[name="privacy_confirmed"]');
            if (pc && !pc.checked) items.push({ el: pc, label: "Data privacy", msg: "confirmation is required" });
        }
        showErrors(items);
        var li = stepsOl.children[n - 1];
        if (li) li.classList.toggle("is-invalid", items.length > 0);
        return items.length === 0;
    }
    function goTo(n, opts) {
        opts = opts || {};
        if (n < 1) n = 1; if (n > 3) n = 3;
        if (n > current && !opts.skipValidation) {
            for (var s = current; s < n; s++) { if (!validateStep(s)) { goTo(s, { skipValidation: true }); return false; } }
        }
        current = n;
        paintStep(n);
        $all(".wizard-step", stepsOl).forEach(function (li, i) {
            li.classList.toggle("is-active", i === n - 1);
            li.classList.toggle("is-done", i < n - 1);
            var b = li.querySelector("button");
            if (b) { if (i === n - 1) b.setAttribute("aria-current", "step"); else b.removeAttribute("aria-current"); }
        });
        backBtn.style.visibility = n === 1 ? "hidden" : "visible";
        nextBtn.style.display = n === 3 ? "none" : "";
        submitBtn.style.display = n === 3 ? "" : "none";
        stepCount.textContent = "Step " + n + " of 3";
        stepIndicator.textContent = "Step " + n + " of 3 — " + steps[n - 1].t;
        progressBar.style.width = (n / 3 * 100) + "%";
        showErrors([]);
        if (n === 3) buildReview();
        updateProgress();
        try { shell.scrollIntoView({ behavior: opts.instant ? "auto" : "smooth", block: "start" }); } catch (e) { window.scrollTo(0, 0); }
        return true;
    }
    $all(".wizard-step-btn", stepsOl).forEach(function (btn) {
        btn.addEventListener("click", function () { goTo(+btn.getAttribute("data-goto")); });
    });
    backBtn.addEventListener("click", function () { goTo(current - 1, { skipValidation: true }); });
    nextBtn.addEventListener("click", function () {
        if (!validateStep(current)) {
            shell.classList.remove("shake"); void shell.offsetWidth; shell.classList.add("shake");
            return;
        }
        goTo(current + 1);
    });
    form.addEventListener("keydown", function (ev) {
        if (ev.key !== "Enter" || current === 3) return;
        var t = ev.target; if (!t) return;
        var tag = (t.tagName || "").toUpperCase();
        if (tag === "TEXTAREA" || tag === "BUTTON" || tag === "SELECT") return;
        ev.preventDefault(); nextBtn.click();
    });

    function updateProgress() {
        var reqs = $all("[required]", form).filter(function (el) { return !el.disabled && el.type !== "hidden" && el.name !== "csrf_token"; });
        var filled = reqs.filter(function (el) {
            if (el.type === "checkbox") {
                if (el.name === "types[]") return $all('input[name="types[]"]:checked', form).length > 0;
                return el.checked;
            }
            return (el.value || "").trim() !== "";
        }).length;
        /* de-dupe types[] group */
        var seen = {}, uniq = 0, ufilled = 0;
        reqs.forEach(function (el) {
            var k = el.name;
            if (seen[k]) return; seen[k] = 1; uniq++;
            if (el.type === "checkbox") { if (el.checked || (el.name === "types[]" && $all('input[name="types[]"]:checked', form).length)) ufilled++; }
            else if ((el.value || "").trim()) ufilled++;
        });
        var pct = uniq ? Math.round(ufilled / uniq * 100) : 0;
        var dt = shell.querySelector(".draft-text");
        if (dt && !dt.dataset.locked) dt.textContent = pct === 100 ? "Ready to submit" : pct + "% complete";
        void filled;
    }
    form.addEventListener("input", function () { updateProgress(); scheduleSave(); });
    form.addEventListener("change", function () { updateProgress(); scheduleSave(); });

    [["recognized_entity", 160], ["achievement", 3000], ["supporting_information", 3000]].forEach(function (c) {
        var el = form.querySelector('[name="' + c[0] + '"]');
        if (!el || el.parentElement.querySelector(".char-counter")) return;
        var div = document.createElement("div");
        div.className = "char-counter";
        el.parentElement.appendChild(div);
        function upd() {
            var len = (el.value || "").length;
            div.textContent = len.toLocaleString() + " characters";
            div.classList.toggle("warn", len > c[1] * 0.85);
        }
        el.addEventListener("input", upd); upd();
    });

    /* dropzone + chips (coexists with existing validation) */
    try {
        var fileInput = document.getElementById("supportingFiles");
        if (fileInput && !fileInput.closest(".dropzone")) {
            var dz = document.createElement("div");
            dz.className = "dropzone";
            fileInput.parentElement.insertBefore(dz, fileInput);
            dz.appendChild(fileInput);
            var hint = document.createElement("div");
            hint.className = "dropzone-hint";
            hint.innerHTML = '<i class="bi bi-cloud-arrow-up"></i><div>Drag &amp; drop or click to browse<small>Up to 5 files • PDF, Word, JPG, PNG, WEBP • 10 MB each</small></div>';
            dz.appendChild(hint);
            var chips = document.createElement("div");
            chips.className = "file-chips";
            dz.parentElement.appendChild(chips);
            ["dragenter", "dragover"].forEach(function (e2) { dz.addEventListener(e2, function (e) { e.preventDefault(); dz.classList.add("dragover"); }); });
            ["dragleave", "drop"].forEach(function (e2) { dz.addEventListener(e2, function (e) { e.preventDefault(); dz.classList.remove("dragover"); }); });
            var store = new DataTransfer();
            var syncing = false;
            function fmtSize(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + " MB" : Math.max(1, Math.round(b / 1024)) + " KB"; }
            function renderChips() {
                chips.innerHTML = "";
                Array.prototype.slice.call(store.files).forEach(function (f, i) {
                    var c = document.createElement("span");
                    c.className = "file-chip";
                    c.innerHTML = '<i class="bi bi-paperclip"></i>';
                    var nm = document.createElement("span"); nm.textContent = f.name;
                    var sz = document.createElement("small"); sz.textContent = fmtSize(f.size);
                    var rm = document.createElement("button"); rm.type = "button"; rm.title = "Remove " + f.name; rm.innerHTML = '<i class="bi bi-x"></i>';
                    rm.addEventListener("click", function (ev) {
                        ev.stopPropagation();
                        store.items.remove(i);
                        syncing = true; fileInput.files = store.files; syncing = false;
                        try { fileInput.dispatchEvent(new Event("change", { bubbles: true })); } catch (e) {}
                        renderChips(); updateProgress();
                    });
                    c.appendChild(nm); c.appendChild(sz); c.appendChild(rm);
                    chips.appendChild(c);
                });
            }
            fileInput.addEventListener("change", function () {
                if (syncing) return;
                var picked = Array.prototype.slice.call(fileInput.files || []);
                fileInput.value = "";
                var remaining = 5 - store.files.length;
                if (picked.length > remaining) { alert("You may upload up to 5 supporting files."); return; }
                var oversized = picked.some(function (f) { return f.size > 10 * 1024 * 1024; });
                if (oversized) { alert("Each supporting file must be 10 MB or smaller."); return; }
                picked.forEach(function (f) { store.items.add(f); });
                syncing = true; fileInput.files = store.files; syncing = false;
                renderChips(); updateProgress();
            });
        }
    } catch (e) {}

    /* draft */
    var DKEY = "hpu_recognition_draft_v1";
    var dot = shell.querySelector(".draft-dot"), dtext = shell.querySelector(".draft-text");
    var saveT = null;
    function scheduleSave() {
        if (dot) dot.classList.remove("saved");
        clearTimeout(saveT);
        saveT = setTimeout(function () {
            try {
                var data = {};
                $all("input, select, textarea", form).forEach(function (el) {
                    if (!el.name || el.name === "csrf_token" || el.type === "file" || el.disabled) return;
                    if (el.type === "checkbox") data[el.name + "||" + el.value] = el.checked;
                    else data[el.name] = el.value;
                });
                localStorage.setItem(DKEY, JSON.stringify({ at: Date.now(), data: data }));
                if (dot) dot.classList.add("saved");
                if (dtext) { dtext.textContent = "Draft saved • " + new Date().toLocaleTimeString([], { hour: "numeric", minute: "2-digit" }); dtext.dataset.locked = "1"; setTimeout(function () { delete dtext.dataset.locked; updateProgress(); }, 4000); }
            } catch (e) {}
        }, 800);
    }
    function isPristine() { return !val("recognized_entity") && !val("achievement") && !$all('input[name="types[]"]:checked', form).length; }
    try {
        var raw = localStorage.getItem(DKEY);
        if (raw && isPristine()) {
            var parsed = JSON.parse(raw);
            if (parsed && parsed.data) {
                banner.innerHTML = '<i class="bi bi-history mt-1"></i><div style="flex:1;"><strong>Unsaved draft found</strong> from ' +
                    esc(new Date(parsed.at).toLocaleString()) + ".</div>" +
                    '<button type="button" class="btn btn-sm btn-primary" data-act="restore">Restore</button>' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="discard">Discard</button>';
                banner.classList.add("show");
                banner.querySelector('[data-act="restore"]').addEventListener("click", function () {
                    Object.keys(parsed.data).forEach(function (k) {
                        if (k.indexOf("||") !== -1) {
                            var p = k.split("||");
                            var box = form.querySelector('input[name="' + p[0] + '"][value="' + p[1] + '"]');
                            if (box) box.checked = !!parsed.data[k];
                        } else {
                            var el = form.querySelector('[name="' + k + '"]');
                            if (el) el.value = parsed.data[k];
                        }
                    });
                    $all("input", form).forEach(function (el) { try { el.dispatchEvent(new Event("change", { bubbles: true })); } catch (e) {} });
                    banner.classList.remove("show"); updateProgress(); goTo(1, { instant: true, skipValidation: true });
                });
                banner.querySelector('[data-act="discard"]').addEventListener("click", function () {
                    try { localStorage.removeItem(DKEY); } catch (e) {}
                    banner.classList.remove("show");
                });
            }
        }
    } catch (e) {}
    form.addEventListener("submit", function () { try { localStorage.removeItem(DKEY); } catch (e) {} });

    /* review */
    function row(label, value, full) {
        var empty = !value;
        return '<div class="review-item' + (full ? " full" : "") + '"><small>' + esc(label) + '</small><div class="' + (empty ? "review-empty" : "") + '">' + (empty ? "—" : esc(value)) + "</div></div>";
    }
    function buildReview() {
        var old = form.querySelector(".review-stack");
        if (old) old.remove();
        var types = $all('input[name="types[]"]:checked', form).map(function (b) { return b.value; });
        var stack = document.createElement("div");
        stack.className = "review-stack";
        var h = "";
        h += '<div class="review-card"><div class="review-head"><h5><i class="bi bi-trophy"></i>Recognition</h5><button type="button" class="review-edit" data-edit="1">Edit</button></div><div class="review-grid">' +
            row("Email", val("email_address")) + row("Recognized", val("recognized_entity")) +
            '<div class="review-item full"><small>Types</small><div class="review-chips">' +
            (types.length ? types.map(function (t) { return '<span class="review-chip">' + esc(t) + "</span>"; }).join("") : '<span class="review-empty">—</span>') +
            "</div></div>" + row("Achievement", val("achievement"), true) + "</div></div>";
        var fc = 0;
        try { var fi2 = document.getElementById("supportingFiles"); fc = fi2 && fi2.files ? fi2.files.length : 0; } catch (e) {}
        h += '<div class="review-card"><div class="review-head"><h5><i class="bi bi-paperclip"></i>Evidence</h5><button type="button" class="review-edit" data-edit="2">Edit</button></div><div class="review-grid">' +
            row("Supporting info", val("supporting_information"), true) + row("Files", fc ? fc + " file(s) selected" : "No files") + "</div></div>";
        var pc = form.querySelector('[name="privacy_confirmed"]');
        h += '<div class="review-card"><div class="review-head"><h5><i class="bi bi-send-check"></i>Publishing</h5><button type="button" class="review-edit" data-edit="3">Edit</button></div><div class="review-grid">' +
            row("Preferred publication", val("pub_date") || "(as soon as reviewed)") + row("Privacy confirmed", pc && pc.checked ? "Yes" : "No") + "</div></div>";
        stack.innerHTML = h;
        var ss = schedCard.querySelector(".stack-section");
        if (ss) ss.insertBefore(stack, ss.firstChild);
        $all(".review-edit", stack).forEach(function (b) { b.addEventListener("click", function () { goTo(+b.getAttribute("data-edit")); }); });
    }

    goTo(1, { instant: true, skipValidation: true });
});
</script>

</body>
</html>
