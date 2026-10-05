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

/*
|--------------------------------------------------------------------------
| Publication / Content Contribution
|--------------------------------------------------------------------------
| Channels:
| - Social Media (Facebook)
| - Website
| - Both
|--------------------------------------------------------------------------
*/

function contributionValidDate(?string $value): ?string
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

function contributionFlash(string $type, string $message): void
{
    $_SESSION["content_contribution_flash"] = [
        "type" => $type,
        "message" => $message
    ];
}

function contributionPullFlash(): ?array
{
    $flash = $_SESSION["content_contribution_flash"] ?? null;
    unset($_SESSION["content_contribution_flash"]);

    return is_array($flash) ? $flash : null;
}

function contributionEnsurePublicTokenColumn(mysqli $conn): void
{
    try {
        $check = $conn->query(
            "SHOW COLUMNS FROM publication_content_contributions LIKE 'public_token'"
        );

        if ($check && $check->num_rows > 0) {
            return;
        }

        $conn->query(
            "ALTER TABLE publication_content_contributions
             ADD COLUMN public_token CHAR(64) NULL,
             ADD UNIQUE INDEX uniq_contribution_public_token (public_token)"
        );
    } catch (Throwable $e) {
        try {
            $conn->query(
                "ALTER TABLE publication_content_contributions
                 ADD COLUMN public_token CHAR(64) NULL"
            );
        } catch (Throwable $e2) {
        }
    }
}

function contributionNewPublicToken(mysqli $conn): string
{
    do {
        $token = bin2hex(random_bytes(32));

        $stmt = $conn->prepare(
            "SELECT id FROM publication_content_contributions WHERE public_token = ? LIMIT 1"
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

function contributionValidateFiles(array $files): array
{
    $normalized = [];

    if (!isset($files["name"]) || !is_array($files["name"])) {
        return [];
    }

    $count = count($files["name"]);

    if ($count > 8) {
        throw new RuntimeException(
            "You may upload up to 8 supporting files."
        );
    }

    $allowedExtensions = [
        "pdf",
        "doc",
        "docx",
        "jpg",
        "jpeg",
        "png",
        "webp",
        "mp4",
        "mov"
    ];

    for ($i = 0; $i < $count; $i++) {
        $uploadError =
            (int)(
                $files["error"][$i]
                ?? UPLOAD_ERR_NO_FILE
            );

        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new RuntimeException(
                "One of the supporting files could not be uploaded."
            );
        }

        $size =
            (int)(
                $files["size"][$i]
                ?? 0
            );

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
                "Unsupported file type. Use PDF, Word, JPG, PNG, WEBP, MP4, or MOV."
            );
        }

        $maxBytes =
            in_array(
                $extension,
                ["mp4", "mov"],
                true
            )
                ? 50 * 1024 * 1024
                : 12 * 1024 * 1024;

        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException(
                in_array($extension, ["mp4", "mov"], true)
                    ? "Video files must be 50 MB or smaller."
                    : "Each document or image must be 12 MB or smaller."
            );
        }

        $tmpName =
            (string)(
                $files["tmp_name"][$i]
                ?? ""
            );

        $finfo =
            new finfo(
                FILEINFO_MIME_TYPE
            );

        $mime =
            (string)
            $finfo->file(
                $tmpName
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
            if (stripos($mime, $blocked) !== false) {
                throw new RuntimeException(
                    "One of the selected files is blocked for security reasons."
                );
            }
        }

        $normalized[] = [
            "tmp_name" => $tmpName,
            "original_name" => $originalName,
            "extension" => $extension,
            "size" => $size,
            "mime" => $mime
        ];
    }

    return $normalized;
}

$userId = (int)($_SESSION["user_id"] ?? 0);
$sessionEmail = trim((string)($_SESSION["email"] ?? ""));

$contributionTypes = [
    "News / Feature Story",
    "Health Advisory / Public Information",
    "Announcement / Notice",
    "Event / Activity Update",
    "Program / Service Promotion",
    "Accomplishment / Milestone",
    "IEC / Infographic / Social Media Card",
    "Photo / Video / Reel Content",
    "Others"
];

$publicationChannels = [
    "Social Media (Facebook)",
    "Website",
    "Both"
];

/*
|--------------------------------------------------------------------------
| Storage Table
|--------------------------------------------------------------------------
*/

$conn->query(
    "CREATE TABLE IF NOT EXISTS publication_content_contributions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        submitted_by INT NOT NULL,
        email_address VARCHAR(190) NOT NULL,
        contribution_type VARCHAR(180) NOT NULL,
        publication_channel VARCHAR(80) NOT NULL,
        content_title VARCHAR(255) NOT NULL,
        lead_office VARCHAR(255) NOT NULL,
        focal_person VARCHAR(255) NOT NULL,
        target_audience VARCHAR(255) NULL,
        purpose_objective TEXT NOT NULL,
        key_message LONGTEXT NOT NULL,
        social_media_caption LONGTEXT NULL,
        website_content LONGTEXT NULL,
        call_to_action TEXT NULL,
        hashtags TEXT NULL,
        reference_links LONGTEXT NULL,
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
        INDEX idx_content_contribution_submitted_by (submitted_by),
        INDEX idx_content_contribution_channel (publication_channel),
        INDEX idx_content_contribution_status (status),
        INDEX idx_content_contribution_created_at (created_at),
        INDEX idx_content_contribution_pub_date (preferred_publication_date)
    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci"
);

contributionEnsurePublicTokenColumn($conn);

/*
|--------------------------------------------------------------------------
| Protected File Storage
|--------------------------------------------------------------------------
*/

$storageDir =
    dirname(__DIR__)
    . DIRECTORY_SEPARATOR
    . "storage"
    . DIRECTORY_SEPARATOR
    . "publication_submissions"
    . DIRECTORY_SEPARATOR
    . "content_contributions";

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
        exit(
            "Unable to create content contribution storage directory."
        );
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

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION["content_contribution_csrf"])) {
    $_SESSION["content_contribution_csrf"] =
        bin2hex(
            random_bytes(32)
        );
}

$csrf =
    (string)
    $_SESSION["content_contribution_csrf"];

$error = "";

/*
|--------------------------------------------------------------------------
| Submission
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedCsrf =
        (string)(
            $_POST["csrf_token"]
            ?? ""
        );

    if (
        $postedCsrf === ""
        ||
        !hash_equals(
            $csrf,
            $postedCsrf
        )
    ) {
        $error =
            "Security token expired. Please refresh the page and try again.";
    } else {
        $email =
            trim(
                (string)(
                    $_POST["email_address"]
                    ?? ""
                )
            );

        $contributionType =
            trim(
                (string)(
                    $_POST["contribution_type"]
                    ?? ""
                )
            );

        $publicationChannel =
            trim(
                (string)(
                    $_POST["publication_channel"]
                    ?? ""
                )
            );

        $contentTitle =
            trim(
                (string)(
                    $_POST["content_title"]
                    ?? ""
                )
            );

        $leadOffice =
            trim(
                (string)(
                    $_POST["lead_office"]
                    ?? ""
                )
            );

        $focalPerson =
            trim(
                (string)(
                    $_POST["focal_person"]
                    ?? ""
                )
            );

        $targetAudience =
            trim(
                (string)(
                    $_POST["target_audience"]
                    ?? ""
                )
            );

        $purposeObjective =
            trim(
                (string)(
                    $_POST["purpose_objective"]
                    ?? ""
                )
            );

        $keyMessage =
            trim(
                (string)(
                    $_POST["key_message"]
                    ?? ""
                )
            );

        $socialMediaCaption =
            trim(
                (string)(
                    $_POST["social_media_caption"]
                    ?? ""
                )
            );

        $websiteContent =
            trim(
                (string)(
                    $_POST["website_content"]
                    ?? ""
                )
            );

        $callToAction =
            trim(
                (string)(
                    $_POST["call_to_action"]
                    ?? ""
                )
            );

        $hashtags =
            trim(
                (string)(
                    $_POST["hashtags"]
                    ?? ""
                )
            );

        $referenceLinks =
            trim(
                (string)(
                    $_POST["reference_links"]
                    ?? ""
                )
            );

        $pubDate =
            contributionValidDate(
                $_POST["pub_date"]
                ?? null
            );

        $privacyConfirmed =
            isset(
                $_POST["privacy_confirmed"]
            )
                ? 1
                : 0;

        if (
            $email === ""
            ||
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $error =
                "Please enter a valid email address.";
        } elseif (
            !in_array(
                $contributionType,
                $contributionTypes,
                true
            )
        ) {
            $error =
                "Please select a valid content contribution type.";
        } elseif (
            !in_array(
                $publicationChannel,
                $publicationChannels,
                true
            )
        ) {
            $error =
                "Please select where the content should be published.";
        } elseif ($contentTitle === "") {
            $error =
                "Please provide a working title or headline.";
        } elseif ($leadOffice === "") {
            $error =
                "Please provide the requesting / lead office.";
        } elseif ($focalPerson === "") {
            $error =
                "Please provide the focal person / content contributor.";
        } elseif ($purposeObjective === "") {
            $error =
                "Please provide the purpose / objective of the content.";
        } elseif ($keyMessage === "") {
            $error =
                "Please provide the key message / important information.";
        } elseif (
            in_array(
                $publicationChannel,
                [
                    "Social Media (Facebook)",
                    "Both"
                ],
                true
            )
            &&
            $socialMediaCaption === ""
        ) {
            $error =
                "Please provide the proposed social media caption.";
        } elseif (
            in_array(
                $publicationChannel,
                [
                    "Website",
                    "Both"
                ],
                true
            )
            &&
            $websiteContent === ""
        ) {
            $error =
                "Please provide the proposed website content / article body.";
        } elseif (!$privacyConfirmed) {
            $error =
                "Please confirm the Data Privacy declaration before submitting.";
        }

        if (
            $error === ""
            &&
            $pubDate !== null
        ) {
            $publicationBlock =
                hpuAvailabilityFindBlock(
                    $conn,
                    $pubDate,
                    [
                        "Publication Submission"
                    ]
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
            isset(
                $_FILES["supporting_files"]
            )
        ) {
            try {
                $validatedFiles =
                    contributionValidateFiles(
                        $_FILES["supporting_files"]
                    );
            } catch (RuntimeException $e) {
                $error =
                    $e->getMessage();
            }
        }

        if ($error === "") {
            $storedFiles = [];

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

                $stmt =
                    $conn->prepare(
                        "INSERT INTO publication_content_contributions (
                            submitted_by,
                            email_address,
                            contribution_type,
                            publication_channel,
                            content_title,
                            lead_office,
                            focal_person,
                            target_audience,
                            purpose_objective,
                            key_message,
                            social_media_caption,
                            website_content,
                            call_to_action,
                            hashtags,
                            reference_links,
                            supporting_files,
                            preferred_publication_date,
                            privacy_confirmed,
                            public_token
                         )
                         VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?, ?, ?, ?
                         )"
                    );

                $publicToken = contributionNewPublicToken($conn);

                $stmt->bind_param(
                    "issssssssssssssssis",
                    $userId,
                    $email,
                    $contributionType,
                    $publicationChannel,
                    $contentTitle,
                    $leadOffice,
                    $focalPerson,
                    $targetAudience,
                    $purposeObjective,
                    $keyMessage,
                    $socialMediaCaption,
                    $websiteContent,
                    $callToAction,
                    $hashtags,
                    $referenceLinks,
                    $filesJson,
                    $pubDate,
                    $privacyConfirmed,
                    $publicToken
                );

                $stmt->execute();
                $stmt->close();

                contributionFlash(
                    "success",
                    "Publication / Content Contribution submitted successfully. HPU personnel can review it under Publication Requests."
                );

                header(
                    "Location: "
                    . BASE_URL
                    . "/content_submission/content_contribution.php"
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

$flash =
    contributionPullFlash();
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
        Publication / Content Contribution | HPU Workbench
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
        href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css"
    >

    <link
        rel="stylesheet"
        href="<?= e(BASE_URL) ?>/assets/css/style.css"
    >

    <link
        rel="stylesheet"
        href="<?= e(BASE_URL) ?>/assets/css/page_headers.css"
    >

    <style>
        :root{
            --ui-primary:#2563eb;
            --ui-violet:#7c3aed;
            --ui-text:#0f172a;
            --ui-muted:#64748b;
            --ui-border:#dbe3ef;
            --ui-bg:#f4f7fb;
            --ui-surface:#fff;
        }

        body{
            background:var(--ui-bg);
        }

        .content-submit-page{
            max-width:1120px;
            margin:0 auto;
        }

        .clean-card{
            overflow:hidden;
            margin-bottom:16px;
            border:1px solid var(--ui-border);
            border-radius:16px;
            background:#fff;
            box-shadow:0 8px 24px rgba(15,23,42,.05);
        }

        .stack-section{
            padding:20px;
        }

        .doc-intro{
            display:flex;
            gap:12px;
            margin-bottom:14px;
            padding:14px 15px;
            border:1px solid #bfdbfe;
            border-radius:13px;
            background:#f8fbff;
            color:#334155;
            font-size:.76rem;
            line-height:1.55;
        }

        .doc-intro i{
            color:#2563eb;
            font-size:1.05rem;
            margin-top:1px;
        }

        .privacy-box{
            display:flex;
            gap:11px;
            margin-bottom:18px;
            padding:14px 15px;
            border:1px solid #e2e8f0;
            border-radius:13px;
            background:#f8fafc;
            color:#475569;
            font-size:.69rem;
            line-height:1.55;
        }

        .privacy-box i{
            color:#475569;
            font-size:1rem;
            margin-top:1px;
        }

        .section-caption{
            margin:0 0 13px;
            color:#0f172a;
            font-size:.82rem;
            font-weight:850;
        }

        .form-label{
            color:#334155;
            font-size:.72rem;
            font-weight:800;
        }

        .form-control,
        .form-select{
            min-height:43px;
            border-color:#dbe3ef;
            border-radius:9px;
            font-size:.8rem;
        }

        textarea.form-control{
            min-height:auto;
        }

        .field-help{
            margin-top:4px;
            color:#64748b;
            font-size:.59rem;
            line-height:1.4;
        }

        .required-star{
            color:#b42318;
        }

        .channel-grid{
            display:grid;
            grid-template-columns:repeat(3,minmax(0,1fr));
            gap:10px;
        }

        .channel-option{
            position:relative;
            display:flex;
            gap:10px;
            align-items:flex-start;
            min-height:94px;
            padding:13px;
            border:1px solid #dbe3ef;
            border-radius:12px;
            background:#fff;
            cursor:pointer;
            transition:.18s;
        }

        .channel-option:hover{
            border-color:#93c5fd;
            box-shadow:0 4px 12px rgba(37,99,235,.08);
        }

        .channel-option:has(input:checked){
            border-color:#60a5fa;
            background:#eff6ff;
            box-shadow:0 0 0 3px rgba(37,99,235,.10);
        }

        .channel-option input{
            margin-top:3px;
        }

        .channel-icon{
            display:grid;
            width:34px;
            height:34px;
            flex:0 0 34px;
            place-items:center;
            border-radius:9px;
            background:#eff6ff;
            color:#2563eb;
            font-size:1rem;
        }

        .channel-option:has(input:checked)
        .channel-icon{
            background:#2563eb;
            color:#fff;
        }

        .channel-copy strong{
            display:block;
            color:#0f172a;
            font-size:.75rem;
            line-height:1.3;
        }

        .channel-copy small{
            display:block;
            margin-top:3px;
            color:#64748b;
            font-size:.61rem;
            line-height:1.4;
        }

        .conditional-panel{
            display:none;
            padding:14px;
            border:1px solid #dbe3ef;
            border-radius:12px;
            background:#fbfdff;
        }

        .conditional-panel.show{
            display:block;
        }

        .conditional-label{
            display:flex;
            align-items:center;
            gap:7px;
            margin-bottom:9px;
            color:#1e293b;
            font-size:.74rem;
            font-weight:850;
        }

        .conditional-label i{
            color:#2563eb;
        }

        .privacy-confirm{
            display:flex;
            align-items:flex-start;
            gap:9px;
            padding:12px;
            border:1px solid #dbe3ef;
            border-radius:10px;
            background:#fff;
        }

        .privacy-confirm .form-check-input{
            margin-top:2px;
            flex:0 0 auto;
        }

        .request-actions{
            position:sticky;
            bottom:0;
            z-index:10;
            display:flex;
            justify-content:flex-end;
            gap:8px;
            margin-top:14px;
            padding:12px 0 3px;
            background:
                linear-gradient(
                    to bottom,
                    rgba(244,247,251,0),
                    #f4f7fb 42%
                );
        }

        .request-actions .btn{
            min-width:135px;
            font-weight:750;
        }

        .availability-warning{
            display:none;
            margin-top:5px;
            color:#b42318;
            font-size:.68rem;
            font-weight:750;
        }

        .availability-warning.show{
            display:block;
        }

        .flatpickr-input[readonly]{
            background:#fff;
            cursor:pointer;
        }

        .flatpickr-day.flatpickr-disabled,
        .flatpickr-day.flatpickr-disabled:hover{
            color:#b42318!important;
            background:#fff1f0!important;
            text-decoration:line-through;
            cursor:not-allowed;
        }

        /* Header */
        .rm-crumb{
            display:flex;
            align-items:center;
            flex-wrap:wrap;
            gap:7px;
            margin:0 0 10px 2px;
            color:#94a3b8;
            font-size:.7rem;
            font-weight:650;
        }

        .rm-crumb a{
            display:inline-flex;
            align-items:center;
            gap:5px;
            color:#64748b;
            text-decoration:none;
            font-weight:750;
        }

        .rm-crumb a:hover{
            color:#1d4ed8;
            text-decoration:underline;
        }

        .rm-crumb-sep{
            color:#cbd5e1;
        }

        .rm-crumb-current{
            color:#0f172a;
            font-weight:800;
        }

        .ph--contribution{
            position:relative;
            overflow:hidden;
            padding:20px 22px;
            border:1px solid #dbe6f7;
            background:
                linear-gradient(
                    180deg,
                    #ffffff 0%,
                    #f8fbff 100%
                );
        }

        .ph--contribution::before{
            content:"";
            position:absolute;
            inset:0 auto 0 0;
            width:4px;
            background:
                linear-gradient(
                    180deg,
                    #2563eb,
                    #7c3aed 55%,
                    #06b6d4
                );
        }

        .ph--contribution .ph-copy{
            align-items:flex-start;
        }

        .ph-icon--contribution{
            width:52px;
            height:52px;
            flex:0 0 52px;
            border:0;
            border-radius:14px;
            background:
                linear-gradient(
                    135deg,
                    #2563eb 0%,
                    #4f46e5 55%,
                    #7c3aed 100%
                );
            color:#fff;
            font-size:23px;
            box-shadow:
                0 8px 18px rgba(37,99,235,.28),
                inset 0 1px 0 rgba(255,255,255,.35);
        }

        .ph--contribution .ph-eyebrow{
            display:flex;
            align-items:center;
            gap:7px;
            color:#1d4ed8;
        }

        .eyebrow-dot{
            width:7px;
            height:7px;
            border-radius:50%;
            background:#22c55e;
            box-shadow:
                0 0 0 3px rgba(34,197,94,.18);
        }

        .eyebrow-divider{
            color:#b6c6e3;
        }

        .ph--contribution .ph-title{
            font-size:1.45rem;
            letter-spacing:-.025em;
        }

        .ph--contribution .ph-subtitle{
            max-width:68ch;
        }

        .rm-meta{
            display:flex;
            flex-wrap:wrap;
            gap:6px 14px;
            margin:10px 0 0;
            padding:0;
            list-style:none;
            color:#475569;
            font-size:.7rem;
            font-weight:650;
        }

        .rm-meta li{
            display:inline-flex;
            align-items:center;
            gap:6px;
        }

        .rm-meta i{
            color:#2563eb;
            font-size:.78rem;
        }

        .rm-code{
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding:6px 12px;
            border:1px dashed #93c5fd;
            border-radius:999px;
            background:#eff6ff;
            color:#1d4ed8;
            font-size:.7rem;
            font-weight:850;
            letter-spacing:.06em;
            text-transform:uppercase;
            white-space:nowrap;
        }

        /* Wizard */
        .wizard-on .request-actions{
            display:none!important;
        }

        .wizard-shell{
            margin:0 0 14px;
        }

        .wizard-progress{
            height:6px;
            border-radius:999px;
            background:#e6edf7;
            overflow:hidden;
            margin-bottom:10px;
        }

        .wizard-progress-bar{
            height:100%;
            width:25%;
            border-radius:999px;
            background:
                linear-gradient(
                    90deg,
                    #2563eb,
                    #7c3aed
                );
            transition:width .35s ease;
        }

        .wizard-steps{
            list-style:none;
            display:grid;
            grid-template-columns:
                repeat(
                    4,
                    minmax(0,1fr)
                );
            gap:8px;
            margin:0 0 10px;
            padding:0;
        }

        .wizard-step-btn{
            display:flex;
            gap:10px;
            align-items:center;
            width:100%;
            padding:10px 12px;
            border:1px solid var(--ui-border);
            border-radius:12px;
            background:#fff;
            cursor:pointer;
            text-align:left;
            transition:.18s;
            color:var(--ui-text);
        }

        .wizard-step-btn:hover{
            border-color:#93c5fd;
        }

        .wizard-step-num{
            display:grid;
            width:30px;
            height:30px;
            flex:0 0 30px;
            place-items:center;
            border-radius:50%;
            background:#eef2f7;
            color:#475569;
            font-size:.78rem;
            font-weight:850;
        }

        .wizard-step-copy{
            min-width:0;
        }

        .wizard-step-copy strong{
            display:block;
            font-size:.72rem;
            line-height:1.2;
        }

        .wizard-step-copy small{
            display:block;
            color:var(--ui-muted);
            font-size:.58rem;
            font-weight:650;
        }

        .wizard-step.is-active
        .wizard-step-btn{
            border-color:var(--ui-primary);
            background:#eff6ff;
            box-shadow:
                0 0 0 3px rgba(37,99,235,.12);
        }

        .wizard-step.is-active
        .wizard-step-num{
            background:var(--ui-primary);
            color:#fff;
        }

        .wizard-step.is-done
        .wizard-step-num{
            background:#16a34a;
            color:#fff;
        }

        .wizard-meta{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            margin-bottom:10px;
            color:var(--ui-muted);
            font-size:.68rem;
            font-weight:700;
        }

        .draft-status{
            display:inline-flex;
            align-items:center;
            gap:6px;
        }

        .draft-dot{
            width:7px;
            height:7px;
            border-radius:50%;
            background:#cbd5e1;
        }

        .draft-dot.saved{
            background:#22c55e;
        }

        .draft-banner{
            display:none;
            gap:10px;
            align-items:flex-start;
            margin-bottom:12px;
            padding:11px 13px;
            border:1px solid #bfdbfe;
            border-radius:12px;
            background:#eff6ff;
            color:#1e3a8a;
            font-size:.76rem;
        }

        .draft-banner.show{
            display:flex;
        }

        .wizard-error-summary{
            display:none;
            margin-bottom:14px;
            padding:12px 14px;
            border:1px solid #fecaca;
            border-radius:12px;
            background:#fef2f2;
            color:#7f1d1d;
            font-size:.76rem;
        }

        .wizard-error-summary.show{
            display:block;
        }

        .field-invalid{
            border-color:#f04438!important;
            box-shadow:
                0 0 0 .16rem
                rgba(240,68,56,.12)!important;
            background:#fff7f7!important;
        }

        .char-counter{
            margin-top:4px;
            color:#94a3b8;
            font-size:.6rem;
            font-weight:650;
            text-align:right;
        }

        .char-counter.warn{
            color:#b45309;
        }

        .review-card{
            margin-bottom:12px;
            border:1px solid #dbe3ef;
            border-radius:14px;
            background:#fff;
            overflow:hidden;
        }

        .review-head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            padding:10px 14px;
            border-bottom:1px solid #edf1f5;
            background:#fbfcfe;
        }

        .review-head h5{
            margin:0;
            font-size:.76rem;
            font-weight:850;
            color:#1e293b;
        }

        .review-edit{
            border:1px solid var(--ui-border);
            background:#fff;
            border-radius:8px;
            font-size:.66rem;
            font-weight:800;
            padding:4px 10px;
            cursor:pointer;
            color:#334155;
        }

        .review-grid{
            display:grid;
            grid-template-columns:
                repeat(
                    2,
                    minmax(0,1fr)
                );
            gap:8px 14px;
            padding:12px 14px;
            font-size:.74rem;
        }

        .review-item.full{
            grid-column:1/-1;
        }

        .review-item small{
            display:block;
            color:#94a3b8;
            font-size:.58rem;
            font-weight:800;
            letter-spacing:.05em;
            text-transform:uppercase;
            margin-bottom:2px;
        }

        .review-item div{
            color:#0f172a;
            font-weight:650;
            overflow-wrap:anywhere;
            white-space:pre-line;
        }

        .review-empty{
            color:#94a3b8!important;
            font-weight:500!important;
            font-style:italic;
        }

        .wizard-footer{
            position:sticky;
            bottom:0;
            z-index:20;
            display:flex;
            align-items:center;
            gap:10px;
            margin-top:14px;
            padding:12px 14px;
            border:1px solid var(--ui-border);
            border-radius:14px;
            background:rgba(255,255,255,.96);
            backdrop-filter:blur(8px);
            box-shadow:
                0 10px 28px rgba(15,23,42,.10);
        }

        .wizard-footer .step-count{
            color:var(--ui-muted);
            font-size:.68rem;
            font-weight:800;
            white-space:nowrap;
        }

        .wizard-footer .spacer{
            flex:1;
        }

        .wizard-footer .btn{
            min-height:44px;
            border-radius:10px;
            font-weight:800;
            padding:8px 18px;
        }

        .file-chips{
            display:flex;
            flex-wrap:wrap;
            gap:6px;
            margin-top:8px;
        }

        .file-chip{
            display:inline-flex;
            align-items:center;
            gap:6px;
            max-width:100%;
            padding:5px 9px;
            border:1px solid #dbe3ef;
            border-radius:9px;
            background:#f8fafc;
            color:#334155;
            font-size:.68rem;
            font-weight:650;
        }

        @media(max-width:700px){
            .channel-grid{
                grid-template-columns:1fr;
            }

            .wizard-step-copy small{
                display:none;
            }

            .wizard-step-copy strong{
                font-size:.61rem;
            }

            .review-grid{
                grid-template-columns:1fr;
            }

            .wizard-footer{
                flex-wrap:wrap;
            }

            .wizard-footer .btn{
                flex:1;
            }

            .content{
                padding:12px;
            }

            .stack-section{
                padding:15px;
            }

            .ph--contribution{
                padding:16px;
            }

            .ph--contribution .ph-title{
                font-size:1.2rem;
            }

            .ph-icon--contribution{
                width:44px;
                height:44px;
                flex-basis:44px;
                font-size:19px;
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
<div class="content-submit-page">

    <nav
        class="rm-crumb"
        aria-label="Breadcrumb"
    >
        <a
            href="<?= e(BASE_URL) ?>/dashboard.php"
        >
            <i class="bi bi-house-door"></i>
            Dashboard
        </a>

        <span class="rm-crumb-sep">/</span>

        <span>
            Publication Submission
        </span>

        <span class="rm-crumb-sep">/</span>

        <span
            class="rm-crumb-current"
            aria-current="page"
        >
            Content Contribution
        </span>
    </nav>

    <div class="ph ph--contribution">
        <div class="ph-copy">

            <div
                class="ph-icon ph-icon--contribution"
                aria-hidden="true"
            >
                <i class="bi bi-megaphone-fill"></i>
            </div>

            <div class="min-w-0">
                <p class="ph-eyebrow">
                    <span
                        class="eyebrow-dot"
                        aria-hidden="true"
                    ></span>

                    Publication Submission

                    <span class="eyebrow-divider">
                        •
                    </span>

                    Content Contribution
                </p>

                <h1 class="ph-title">
                    Publication / Content Contribution
                </h1>

                <p class="ph-subtitle">
                    Submit proposed content for ASTMMC social media, official website publication, or both channels.
                </p>

                <ul class="rm-meta">
                    <li>
                        <i class="bi bi-facebook"></i>
                        Facebook / Social Media
                    </li>

                    <li>
                        <i class="bi bi-globe2"></i>
                        Website
                    </li>

                    <li>
                        <i class="bi bi-eye"></i>
                        HPU review before publication
                    </li>
                </ul>
            </div>
        </div>

        <div class="ph-actions">
            <span class="rm-code">
                <i class="bi bi-file-earmark-richtext"></i>
                Form PCCF
            </span>
        </div>
    </div>

    <?php if ($flash): ?>
        <div
            class="alert alert-<?= e($flash["type"]) ?> alert-dismissible fade show"
        >
            <?= e($flash["message"]) ?>

            <button
                class="btn-close"
                type="button"
                data-bs-dismiss="alert"
            ></button>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form
        id="contentContributionForm"
        method="post"
        action="content_contribution.php"
        enctype="multipart/form-data"
    >
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrf) ?>"
        >

        <!-- STEP 1 -->
        <section class="clean-card">
            <div class="stack-section">

                <div class="doc-intro">
                    <i class="bi bi-info-circle-fill"></i>

                    <div>
                        Use this form when your office, unit, or authorized contributor wants HPU to review and publish information through the hospital's social media page, official website, or both.
                    </div>
                </div>

                <div class="privacy-box">
                    <i class="bi bi-shield-lock-fill"></i>

                    <div>
                        <strong>Data Privacy Disclaimer.</strong>
                        Personal data, photographs, names, quotes, and other identifying information included in this submission must have appropriate authority or consent for institutional documentation and publication.
                    </div>
                </div>

                <h2 class="section-caption">
                    A. Contribution Profile
                </h2>

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">
                            Email Address
                            <span class="required-star">*</span>
                        </label>

                        <input
                            type="email"
                            class="form-control"
                            name="email_address"
                            value="<?= e($_POST["email_address"] ?? $sessionEmail) ?>"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">
                            Type of Content
                            <span class="required-star">*</span>
                        </label>

                        <select
                            class="form-select"
                            name="contribution_type"
                            required
                        >
                            <option value="">
                                Select content type
                            </option>

                            <?php foreach ($contributionTypes as $contentType): ?>
                                <option
                                    value="<?= e($contentType) ?>"
                                    <?= ($_POST["contribution_type"] ?? "") === $contentType ? "selected" : "" ?>
                                >
                                    <?= e($contentType) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label">
                            Where should this be published?
                            <span class="required-star">*</span>
                        </label>

                        <div class="channel-grid">

                            <label class="channel-option">
                                <input
                                    class="form-check-input publication-channel"
                                    type="radio"
                                    name="publication_channel"
                                    value="Social Media (Facebook)"
                                    <?= ($_POST["publication_channel"] ?? "") === "Social Media (Facebook)" ? "checked" : "" ?>
                                    required
                                >

                                <span class="channel-icon">
                                    <i class="bi bi-facebook"></i>
                                </span>

                                <span class="channel-copy">
                                    <strong>
                                        Social Media
                                    </strong>

                                    <small>
                                        Facebook post, social media card, reel, photo, or announcement.
                                    </small>
                                </span>
                            </label>

                            <label class="channel-option">
                                <input
                                    class="form-check-input publication-channel"
                                    type="radio"
                                    name="publication_channel"
                                    value="Website"
                                    <?= ($_POST["publication_channel"] ?? "") === "Website" ? "checked" : "" ?>
                                    required
                                >

                                <span class="channel-icon">
                                    <i class="bi bi-globe2"></i>
                                </span>

                                <span class="channel-copy">
                                    <strong>
                                        Website
                                    </strong>

                                    <small>
                                        News, feature, announcement, program update, or official web content.
                                    </small>
                                </span>
                            </label>

                            <label class="channel-option">
                                <input
                                    class="form-check-input publication-channel"
                                    type="radio"
                                    name="publication_channel"
                                    value="Both"
                                    <?= ($_POST["publication_channel"] ?? "") === "Both" ? "checked" : "" ?>
                                    required
                                >

                                <span class="channel-icon">
                                    <i class="bi bi-layers-fill"></i>
                                </span>

                                <span class="channel-copy">
                                    <strong>
                                        Both
                                    </strong>

                                    <small>
                                        Prepare the contribution for both social media and the hospital website.
                                    </small>
                                </span>
                            </label>

                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">
                            Working Title / Headline
                            <span class="required-star">*</span>
                        </label>

                        <input
                            class="form-control"
                            name="content_title"
                            value="<?= e($_POST["content_title"] ?? "") ?>"
                            placeholder="Enter the proposed title or headline"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">
                            Requesting / Lead Office
                            <span class="required-star">*</span>
                        </label>

                        <input
                            class="form-control"
                            name="lead_office"
                            value="<?= e($_POST["lead_office"] ?? "") ?>"
                            placeholder="Office / Unit / Department"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">
                            Focal Person / Content Contributor
                            <span class="required-star">*</span>
                        </label>

                        <input
                            class="form-control"
                            name="focal_person"
                            value="<?= e($_POST["focal_person"] ?? "") ?>"
                            placeholder="Name and position, if applicable"
                            required
                        >
                    </div>

                    <div class="col-12">
                        <label class="form-label">
                            Target Audience
                        </label>

                        <input
                            class="form-control"
                            name="target_audience"
                            value="<?= e($_POST["target_audience"] ?? "") ?>"
                            placeholder="e.g. Patients, employees, community, general public, health partners"
                        >
                    </div>

                </div>
            </div>
        </section>

        <!-- STEP 2 -->
        <section class="clean-card">
            <div class="stack-section">

                <h2 class="section-caption">
                    B. Content Information
                </h2>

                <div class="row g-3">

                    <div class="col-12">
                        <label class="form-label">
                            Purpose / Objective
                            <span class="required-star">*</span>
                        </label>

                        <textarea
                            class="form-control"
                            name="purpose_objective"
                            rows="4"
                            required
                            placeholder="What is the purpose of publishing this content? What should the audience know, understand, or do?"
                        ><?= e($_POST["purpose_objective"] ?? "") ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label">
                            Key Message / Important Information
                            <span class="required-star">*</span>
                        </label>

                        <textarea
                            class="form-control"
                            name="key_message"
                            rows="6"
                            required
                            placeholder="Provide the complete facts, important details, dates, names, figures, context, and other information that HPU should use."
                        ><?= e($_POST["key_message"] ?? "") ?></textarea>
                    </div>

                    <div
                        id="socialMediaPanel"
                        class="col-12 conditional-panel"
                    >
                        <div class="conditional-label">
                            <i class="bi bi-facebook"></i>
                            Proposed Social Media Content
                        </div>

                        <label class="form-label">
                            Proposed Caption
                            <span class="required-star">*</span>
                        </label>

                        <textarea
                            id="socialMediaCaption"
                            class="form-control"
                            name="social_media_caption"
                            rows="6"
                            placeholder="Draft the Facebook caption or social media copy. HPU may edit this during review."
                        ><?= e($_POST["social_media_caption"] ?? "") ?></textarea>

                        <label class="form-label mt-3">
                            Suggested Hashtags
                        </label>

                        <input
                            class="form-control"
                            name="hashtags"
                            value="<?= e($_POST["hashtags"] ?? "") ?>"
                            placeholder="#ASTMMC #HealthInformation"
                        >
                    </div>

                    <div
                        id="websitePanel"
                        class="col-12 conditional-panel"
                    >
                        <div class="conditional-label">
                            <i class="bi bi-globe2"></i>
                            Proposed Website Content
                        </div>

                        <label class="form-label">
                            Article / Website Body
                            <span class="required-star">*</span>
                        </label>

                        <textarea
                            id="websiteContent"
                            class="form-control"
                            name="website_content"
                            rows="10"
                            placeholder="Provide the proposed article, news story, feature, announcement, or website content. HPU may edit this during review."
                        ><?= e($_POST["website_content"] ?? "") ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label">
                            Call to Action
                        </label>

                        <textarea
                            class="form-control"
                            name="call_to_action"
                            rows="3"
                            placeholder="Optional: What should readers do next? Include contact details, registration instructions, clinic schedule, or other next steps."
                        ><?= e($_POST["call_to_action"] ?? "") ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label">
                            References / Source Links
                        </label>

                        <textarea
                            class="form-control"
                            name="reference_links"
                            rows="3"
                            placeholder="Paste source links, official references, related pages, or supporting URLs. Use one per line if possible."
                        ><?= e($_POST["reference_links"] ?? "") ?></textarea>
                    </div>

                </div>
            </div>
        </section>

        <!-- STEP 3 -->
        <section class="clean-card">
            <div class="stack-section">

                <h2 class="section-caption">
                    C. Supporting Materials
                </h2>

                <div class="row g-3">

                    <div class="col-12">
                        <label class="form-label">
                            Photos / Documents / Video / IEC Materials
                        </label>

                        <input
                            id="supportingFiles"
                            type="file"
                            class="form-control"
                            name="supporting_files[]"
                            accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp,.mp4,.mov"
                            multiple
                        >

                        <div class="field-help">
                            Maximum 8 files. Documents/images: up to 12 MB each. MP4/MOV video: up to 50 MB each.
                        </div>

                        <div
                            id="supportingFilesInfo"
                            class="field-help"
                        ></div>

                        <div
                            id="fileChips"
                            class="file-chips"
                        ></div>
                    </div>

                    <div class="col-12">
                        <div class="doc-intro mb-0">
                            <i class="bi bi-image-fill"></i>

                            <div>
                                <strong>Media reminder:</strong>
                                Upload the clearest available files. Photos should be publication-ready whenever possible. Make sure identifiable persons have proper consent or authority for institutional publication.
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </section>

        <!-- STEP 4 -->
        <section class="clean-card">
            <div class="stack-section">

                <h2 class="section-caption">
                    D. Publication Scheduling & Confirmation
                </h2>

                <div
                    id="reviewStack"
                    class="mb-3"
                ></div>

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
                                I confirm that this contribution is authorized for institutional review and that any personal information, photographs, names, quotes, or other identifiable information included has appropriate consent or authority for ASTMMC documentation and publication.
                            </span>
                        </label>
                    </div>

                </div>
            </div>
        </section>

        <div class="request-actions">
            <a
                class="btn btn-outline-secondary"
                href="<?= e(BASE_URL) ?>/dashboard.php"
            >
                Cancel
            </a>

            <button
                class="btn btn-primary"
                type="submit"
            >
                <i class="bi bi-send-check me-1"></i>
                Submit Contribution
            </button>
        </div>

    </form>

</div>
</div>
</main>
</div>
</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
></script>

<script
    src="https://cdn.jsdelivr.net/npm/flatpickr"
></script>

<script>
document.addEventListener(
    "DOMContentLoaded",
    async function () {

        const form =
            document.getElementById(
                "contentContributionForm"
            );

        if (!form) {
            return;
        }

        const sections =
            Array.from(
                form.children
            ).filter(
                function (element) {
                    return (
                        element.tagName === "SECTION"
                        &&
                        element.classList.contains(
                            "clean-card"
                        )
                    );
                }
            );

        if (sections.length < 4) {
            return;
        }

        form.classList.add(
            "wizard-on"
        );

        form.setAttribute(
            "novalidate",
            "novalidate"
        );

        /*
        |--------------------------------------------------------------------------
        | Publication Channel Conditional Fields
        |--------------------------------------------------------------------------
        */

        const channelInputs =
            Array.from(
                document.querySelectorAll(
                    ".publication-channel"
                )
            );

        const socialPanel =
            document.getElementById(
                "socialMediaPanel"
            );

        const websitePanel =
            document.getElementById(
                "websitePanel"
            );

        const socialCaption =
            document.getElementById(
                "socialMediaCaption"
            );

        const websiteContent =
            document.getElementById(
                "websiteContent"
            );

        function selectedChannel() {
            const selected =
                channelInputs.find(
                    function (input) {
                        return input.checked;
                    }
                );

            return selected
                ? selected.value
                : "";
        }

        function updateChannelFields() {
            const channel =
                selectedChannel();

            const needsSocial =
                (
                    channel
                    ===
                    "Social Media (Facebook)"
                    ||
                    channel === "Both"
                );

            const needsWebsite =
                (
                    channel === "Website"
                    ||
                    channel === "Both"
                );

            socialPanel.classList.toggle(
                "show",
                needsSocial
            );

            websitePanel.classList.toggle(
                "show",
                needsWebsite
            );

            socialCaption.required =
                needsSocial;

            websiteContent.required =
                needsWebsite;
        }

        channelInputs.forEach(
            function (input) {
                input.addEventListener(
                    "change",
                    updateChannelFields
                );
            }
        );

        updateChannelFields();

        /*
        |--------------------------------------------------------------------------
        | File Validation
        |--------------------------------------------------------------------------
        */

        const fileInput =
            document.getElementById(
                "supportingFiles"
            );

        const fileInfo =
            document.getElementById(
                "supportingFilesInfo"
            );

        const fileChips =
            document.getElementById(
                "fileChips"
            );

        function formatBytes(bytes) {
            if (bytes >= 1024 * 1024) {
                return (
                    (
                        bytes
                        /
                        (1024 * 1024)
                    ).toFixed(1)
                    +
                    " MB"
                );
            }

            return (
                Math.max(
                    1,
                    Math.round(
                        bytes / 1024
                    )
                )
                +
                " KB"
            );
        }

        function renderFiles() {
            const files =
                Array.from(
                    fileInput.files
                    ||
                    []
                );

            fileChips.innerHTML =
                "";

            files.forEach(
                function (file) {
                    const chip =
                        document.createElement(
                            "span"
                        );

                    chip.className =
                        "file-chip";

                    chip.innerHTML =
                        '<i class="bi bi-paperclip"></i>'
                        +
                        '<span>'
                        +
                        escapeHtml(file.name)
                        +
                        "</span>"
                        +
                        "<small>"
                        +
                        formatBytes(file.size)
                        +
                        "</small>";

                    fileChips.appendChild(
                        chip
                    );
                }
            );

            fileInfo.textContent =
                files.length
                    ? (
                        files.length
                        +
                        " file"
                        +
                        (
                            files.length === 1
                                ? ""
                                : "s"
                        )
                        +
                        " selected."
                    )
                    : "";
        }

        function validateFiles() {
            const files =
                Array.from(
                    fileInput.files
                    ||
                    []
                );

            if (files.length > 8) {
                alert(
                    "You may upload up to 8 supporting files."
                );

                fileInput.value =
                    "";

                renderFiles();

                return false;
            }

            for (const file of files) {
                const extension =
                    (
                        file.name
                            .split(".")
                            .pop()
                            ||
                            ""
                    )
                    .toLowerCase();

                const isVideo =
                    [
                        "mp4",
                        "mov"
                    ].includes(
                        extension
                    );

                const max =
                    isVideo
                        ? 50 * 1024 * 1024
                        : 12 * 1024 * 1024;

                if (file.size > max) {
                    alert(
                        isVideo
                            ? "Video files must be 50 MB or smaller."
                            : "Documents and images must be 12 MB or smaller."
                    );

                    fileInput.value =
                        "";

                    renderFiles();

                    return false;
                }
            }

            renderFiles();

            return true;
        }

        fileInput.addEventListener(
            "change",
            validateFiles
        );

        /*
        |--------------------------------------------------------------------------
        | Blocked Publication Date
        |--------------------------------------------------------------------------
        */

        const dateField =
            document.getElementById(
                "pub_date"
            );

        const dateWarning =
            document.getElementById(
                "pub_date_warning"
            );

        let blockedRanges = [];

        if (dateField && dateWarning) {
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
                        +
                        "?"
                        +
                        params.toString(),
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
                dateField,
                {
                    dateFormat:
                        "Y-m-d",

                    altInput:
                        true,

                    altFormat:
                        "F j, Y",

                    allowInput:
                        false,

                    disableMobile:
                        true,

                    defaultDate:
                        dateField.value
                        ||
                        null,

                    disable:
                        blockedRanges.map(
                            function (range) {
                                return {
                                    from:
                                        range.from,

                                    to:
                                        range.to
                                };
                            }
                        ),

                    onChange:
                        async function () {

                            dateWarning
                                .classList
                                .remove(
                                    "show"
                                );

                            dateWarning
                                .textContent =
                                "";

                            if (
                                !dateField.value
                            ) {
                                return;
                            }

                            const params =
                                new URLSearchParams();

                            params.set(
                                "date",
                                dateField.value
                            );

                            params.append(
                                "services[]",
                                "Publication Submission"
                            );

                            try {
                                const response =
                                    await fetch(
                                        <?= json_encode(BASE_URL . "/ajax/check_blocked_date.php") ?>
                                        +
                                        "?"
                                        +
                                        params.toString(),
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
                                    dateField
                                        ._flatpickr
                                        .clear();

                                    dateWarning
                                        .textContent =
                                        data.message;

                                    dateWarning
                                        .classList
                                        .add(
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

        /*
        |--------------------------------------------------------------------------
        | Wizard
        |--------------------------------------------------------------------------
        */

        const steps = [
            {
                title:
                    "Contribution",

                subtitle:
                    "Type & channel"
            },
            {
                title:
                    "Content",

                subtitle:
                    "Message & draft"
            },
            {
                title:
                    "Materials",

                subtitle:
                    "Files & media"
            },
            {
                title:
                    "Review",

                subtitle:
                    "Schedule & submit"
            }
        ];

        const wizardShell =
            document.createElement(
                "div"
            );

        wizardShell.className =
            "wizard-shell";

        wizardShell.innerHTML =
            '<div class="wizard-progress">'
            +
            '<div class="wizard-progress-bar"></div>'
            +
            "</div>"
            +
            '<ol class="wizard-steps"></ol>'
            +
            '<div class="wizard-meta">'
            +
            '<span class="step-indicator">Step 1 of 4</span>'
            +
            '<span class="draft-status">'
            +
            '<span class="draft-dot"></span>'
            +
            '<span class="draft-text">No unsaved draft</span>'
            +
            "</span>"
            +
            "</div>"
            +
            '<div class="draft-banner"></div>'
            +
            '<div class="wizard-error-summary"></div>';

        form.insertBefore(
            wizardShell,
            form.firstChild
        );

        const stepsList =
            wizardShell.querySelector(
                ".wizard-steps"
            );

        steps.forEach(
            function (step, index) {
                const li =
                    document.createElement(
                        "li"
                    );

                li.className =
                    "wizard-step";

                li.innerHTML =
                    '<button type="button" class="wizard-step-btn" data-step="'
                    +
                    (index + 1)
                    +
                    '">'
                    +
                    '<span class="wizard-step-num">'
                    +
                    (index + 1)
                    +
                    "</span>"
                    +
                    '<span class="wizard-step-copy">'
                    +
                    "<strong>"
                    +
                    escapeHtml(
                        step.title
                    )
                    +
                    "</strong>"
                    +
                    "<small>"
                    +
                    escapeHtml(
                        step.subtitle
                    )
                    +
                    "</small>"
                    +
                    "</span>"
                    +
                    "</button>";

                stepsList.appendChild(
                    li
                );
            }
        );

        const footer =
            document.createElement(
                "div"
            );

        footer.className =
            "wizard-footer";

        const originalCancel =
            form.querySelector(
                ".request-actions a.btn"
            );

        const cancelHref =
            originalCancel
                ? originalCancel.getAttribute(
                    "href"
                )
                : "dashboard.php";

        footer.innerHTML =
            '<button type="button" class="btn btn-outline-secondary btn-back">&larr; Back</button>'
            +
            '<span class="step-count">Step 1 of 4</span>'
            +
            '<span class="spacer"></span>'
            +
            '<a class="btn btn-outline-secondary" href="'
            +
            escapeHtml(
                cancelHref
            )
            +
            '">Cancel</a>'
            +
            '<button type="button" class="btn btn-primary btn-next">Continue &rarr;</button>'
            +
            '<button type="submit" class="btn btn-primary btn-submit" style="display:none;">'
            +
            '<i class="bi bi-send-check me-1"></i>'
            +
            "Submit Contribution"
            +
            "</button>";

        form.appendChild(
            footer
        );

        const backButton =
            footer.querySelector(
                ".btn-back"
            );

        const nextButton =
            footer.querySelector(
                ".btn-next"
            );

        const submitButton =
            footer.querySelector(
                ".btn-submit"
            );

        const stepCount =
            footer.querySelector(
                ".step-count"
            );

        const progressBar =
            wizardShell.querySelector(
                ".wizard-progress-bar"
            );

        const stepIndicator =
            wizardShell.querySelector(
                ".step-indicator"
            );

        const draftDot =
            wizardShell.querySelector(
                ".draft-dot"
            );

        const draftText =
            wizardShell.querySelector(
                ".draft-text"
            );

        const draftBanner =
            wizardShell.querySelector(
                ".draft-banner"
            );

        const errorSummary =
            wizardShell.querySelector(
                ".wizard-error-summary"
            );

        let currentStep = 1;

        function escapeHtml(value) {
            return String(
                value
                ??
                ""
            )
            .replace(
                /&/g,
                "&amp;"
            )
            .replace(
                /</g,
                "&lt;"
            )
            .replace(
                />/g,
                "&gt;"
            )
            .replace(
                /"/g,
                "&quot;"
            );
        }

        function field(name) {
            return form.querySelector(
                '[name="'
                +
                name
                +
                '"]'
            );
        }

        function fieldValue(name) {
            const element =
                field(name);

            return element
                ? (
                    element.value
                    ||
                    ""
                ).trim()
                : "";
        }

        function showErrors(items) {
            form
                .querySelectorAll(
                    ".field-invalid"
                )
                .forEach(
                    function (element) {
                        element
                            .classList
                            .remove(
                                "field-invalid"
                            );
                    }
                );

            if (!items.length) {
                errorSummary
                    .classList
                    .remove(
                        "show"
                    );

                errorSummary.innerHTML =
                    "";

                return;
            }

            items.forEach(
                function (item) {
                    if (
                        item.element
                        &&
                        item.element.classList
                    ) {
                        item.element
                            .classList
                            .add(
                                "field-invalid"
                            );
                    }
                }
            );

            errorSummary.innerHTML =
                "<strong>Please complete the following before continuing:</strong>"
                +
                "<ul>"
                +
                items
                    .map(
                        function (item) {
                            return (
                                "<li>"
                                +
                                escapeHtml(
                                    item.message
                                )
                                +
                                "</li>"
                            );
                        }
                    )
                    .join("")
                +
                "</ul>";

            errorSummary
                .classList
                .add(
                    "show"
                );
        }

        function validateStep(step) {
            const errors = [];

            function required(
                name,
                message
            ) {
                const element =
                    field(name);

                if (
                    !element
                    ||
                    !(
                        element.value
                        ||
                        ""
                    ).trim()
                ) {
                    errors.push(
                        {
                            element:
                                element,

                            message:
                                message
                        }
                    );
                }
            }

            if (step === 1) {
                const email =
                    field(
                        "email_address"
                    );

                if (
                    !email
                    ||
                    !/.+@.+\..+/.test(
                        (
                            email.value
                            ||
                            ""
                        ).trim()
                    )
                ) {
                    errors.push(
                        {
                            element:
                                email,

                            message:
                                "Enter a valid email address."
                        }
                    );
                }

                required(
                    "contribution_type",
                    "Select the type of content."
                );

                if (!selectedChannel()) {
                    errors.push(
                        {
                            element:
                                document.querySelector(
                                    ".channel-grid"
                                ),

                            message:
                                "Select Social Media, Website, or Both."
                        }
                    );
                }

                required(
                    "content_title",
                    "Enter a working title or headline."
                );

                required(
                    "lead_office",
                    "Enter the requesting / lead office."
                );

                required(
                    "focal_person",
                    "Enter the focal person / contributor."
                );
            }

            if (step === 2) {
                required(
                    "purpose_objective",
                    "Enter the purpose / objective."
                );

                required(
                    "key_message",
                    "Enter the key message / important information."
                );

                const channel =
                    selectedChannel();

                if (
                    channel
                    ===
                    "Social Media (Facebook)"
                    ||
                    channel === "Both"
                ) {
                    required(
                        "social_media_caption",
                        "Enter the proposed social media caption."
                    );
                }

                if (
                    channel === "Website"
                    ||
                    channel === "Both"
                ) {
                    required(
                        "website_content",
                        "Enter the proposed website article / content."
                    );
                }
            }

            if (step === 3) {
                if (!validateFiles()) {
                    errors.push(
                        {
                            element:
                                fileInput,

                            message:
                                "Review the supporting file requirements."
                        }
                    );
                }
            }

            if (step === 4) {
                const privacy =
                    field(
                        "privacy_confirmed"
                    );

                if (
                    privacy
                    &&
                    !privacy.checked
                ) {
                    errors.push(
                        {
                            element:
                                privacy,

                            message:
                                "Confirm the Data Privacy declaration."
                        }
                    );
                }
            }

            showErrors(
                errors
            );

            return (
                errors.length === 0
            );
        }

        function renderStep() {
            sections.forEach(
                function (
                    section,
                    index
                ) {
                    section.style.display =
                        (
                            index
                            ===
                            currentStep - 1
                        )
                            ? ""
                            : "none";
                }
            );

            Array.from(
                stepsList.children
            ).forEach(
                function (
                    li,
                    index
                ) {
                    li.classList.toggle(
                        "is-active",
                        index
                        ===
                        currentStep - 1
                    );

                    li.classList.toggle(
                        "is-done",
                        index
                        <
                        currentStep - 1
                    );
                }
            );

            backButton.style.visibility =
                currentStep === 1
                    ? "hidden"
                    : "visible";

            nextButton.style.display =
                currentStep === 4
                    ? "none"
                    : "";

            submitButton.style.display =
                currentStep === 4
                    ? ""
                    : "none";

            stepCount.textContent =
                "Step "
                +
                currentStep
                +
                " of 4";

            stepIndicator.textContent =
                "Step "
                +
                currentStep
                +
                " of 4 — "
                +
                steps[
                    currentStep - 1
                ].title;

            progressBar.style.width =
                (
                    currentStep
                    /
                    4
                    *
                    100
                )
                +
                "%";

            showErrors(
                []
            );

            if (currentStep === 4) {
                buildReview();
            }
        }

        function goToStep(
            step,
            skipValidation
        ) {
            step =
                Math.max(
                    1,
                    Math.min(
                        4,
                        step
                    )
                );

            if (
                step > currentStep
                &&
                !skipValidation
            ) {
                if (
                    !validateStep(
                        currentStep
                    )
                ) {
                    return;
                }
            }

            currentStep =
                step;

            renderStep();

            wizardShell.scrollIntoView(
                {
                    behavior:
                        "smooth",

                    block:
                        "start"
                }
            );
        }

        stepsList
            .querySelectorAll(
                ".wizard-step-btn"
            )
            .forEach(
                function (button) {
                    button.addEventListener(
                        "click",
                        function () {
                            goToStep(
                                parseInt(
                                    button.dataset.step,
                                    10
                                ),
                                false
                            );
                        }
                    );
                }
            );

        backButton.addEventListener(
            "click",
            function () {
                goToStep(
                    currentStep - 1,
                    true
                );
            }
        );

        nextButton.addEventListener(
            "click",
            function () {
                if (
                    validateStep(
                        currentStep
                    )
                ) {
                    goToStep(
                        currentStep + 1,
                        true
                    );
                }
            }
        );

        form.addEventListener(
            "submit",
            function (event) {
                if (
                    currentStep !== 4
                ) {
                    event.preventDefault();
                    goToStep(
                        4,
                        false
                    );
                    return;
                }

                if (!validateStep(4)) {
                    event.preventDefault();
                }
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Review
        |--------------------------------------------------------------------------
        */

        function reviewItem(
            label,
            value,
            full
        ) {
            const empty =
                !value;

            return (
                '<div class="review-item'
                +
                (
                    full
                        ? " full"
                        : ""
                )
                +
                '">'
                +
                "<small>"
                +
                escapeHtml(
                    label
                )
                +
                "</small>"
                +
                '<div class="'
                +
                (
                    empty
                        ? "review-empty"
                        : ""
                )
                +
                '">'
                +
                (
                    empty
                        ? "—"
                        : escapeHtml(
                            value
                        )
                )
                +
                "</div>"
                +
                "</div>"
            );
        }

        function buildReview() {
            const reviewStack =
                document.getElementById(
                    "reviewStack"
                );

            const channel =
                selectedChannel();

            const files =
                Array.from(
                    fileInput.files
                    ||
                    []
                );

            let html = "";

            html +=
                '<div class="review-card">'
                +
                '<div class="review-head">'
                +
                "<h5>"
                +
                '<i class="bi bi-megaphone me-1"></i>'
                +
                "Contribution Profile"
                +
                "</h5>"
                +
                '<button type="button" class="review-edit" data-edit="1">Edit</button>'
                +
                "</div>"
                +
                '<div class="review-grid">'
                +
                reviewItem(
                    "Content Type",
                    fieldValue(
                        "contribution_type"
                    )
                )
                +
                reviewItem(
                    "Publication Channel",
                    channel
                )
                +
                reviewItem(
                    "Title / Headline",
                    fieldValue(
                        "content_title"
                    ),
                    true
                )
                +
                reviewItem(
                    "Lead Office",
                    fieldValue(
                        "lead_office"
                    )
                )
                +
                reviewItem(
                    "Focal Person",
                    fieldValue(
                        "focal_person"
                    )
                )
                +
                reviewItem(
                    "Target Audience",
                    fieldValue(
                        "target_audience"
                    ),
                    true
                )
                +
                "</div>"
                +
                "</div>";

            html +=
                '<div class="review-card">'
                +
                '<div class="review-head">'
                +
                "<h5>"
                +
                '<i class="bi bi-file-earmark-text me-1"></i>'
                +
                "Content"
                +
                "</h5>"
                +
                '<button type="button" class="review-edit" data-edit="2">Edit</button>'
                +
                "</div>"
                +
                '<div class="review-grid">'
                +
                reviewItem(
                    "Purpose / Objective",
                    fieldValue(
                        "purpose_objective"
                    ),
                    true
                )
                +
                reviewItem(
                    "Key Message",
                    fieldValue(
                        "key_message"
                    ),
                    true
                )
                +
                (
                    channel
                    ===
                    "Social Media (Facebook)"
                    ||
                    channel === "Both"
                        ? reviewItem(
                            "Social Media Caption",
                            fieldValue(
                                "social_media_caption"
                            ),
                            true
                        )
                        : ""
                )
                +
                (
                    channel === "Website"
                    ||
                    channel === "Both"
                        ? reviewItem(
                            "Website Content",
                            fieldValue(
                                "website_content"
                            ),
                            true
                        )
                        : ""
                )
                +
                reviewItem(
                    "Call to Action",
                    fieldValue(
                        "call_to_action"
                    ),
                    true
                )
                +
                "</div>"
                +
                "</div>";

            html +=
                '<div class="review-card">'
                +
                '<div class="review-head">'
                +
                "<h5>"
                +
                '<i class="bi bi-paperclip me-1"></i>'
                +
                "Supporting Materials"
                +
                "</h5>"
                +
                '<button type="button" class="review-edit" data-edit="3">Edit</button>'
                +
                "</div>"
                +
                '<div class="review-grid">'
                +
                reviewItem(
                    "Files",
                    files.length
                        ? (
                            files.length
                            +
                            " file(s) selected"
                        )
                        : "No files"
                )
                +
                reviewItem(
                    "References",
                    fieldValue(
                        "reference_links"
                    ),
                    true
                )
                +
                "</div>"
                +
                "</div>";

            reviewStack.innerHTML =
                html;

            reviewStack
                .querySelectorAll(
                    ".review-edit"
                )
                .forEach(
                    function (button) {
                        button.addEventListener(
                            "click",
                            function () {
                                goToStep(
                                    parseInt(
                                        button.dataset.edit,
                                        10
                                    ),
                                    true
                                );
                            }
                        );
                    }
                );
        }

        /*
        |--------------------------------------------------------------------------
        | Character Counters
        |--------------------------------------------------------------------------
        */

        [
            [
                "purpose_objective",
                2500
            ],
            [
                "key_message",
                5000
            ],
            [
                "social_media_caption",
                3000
            ],
            [
                "website_content",
                10000
            ],
            [
                "call_to_action",
                1500
            ]
        ].forEach(
            function (config) {
                const element =
                    field(
                        config[0]
                    );

                if (!element) {
                    return;
                }

                const counter =
                    document.createElement(
                        "div"
                    );

                counter.className =
                    "char-counter";

                element
                    .parentElement
                    .appendChild(
                        counter
                    );

                function update() {
                    const length =
                        (
                            element.value
                            ||
                            ""
                        ).length;

                    counter.textContent =
                        length.toLocaleString()
                        +
                        " characters";

                    counter
                        .classList
                        .toggle(
                            "warn",
                            length
                            >
                            config[1] * .85
                        );
                }

                element.addEventListener(
                    "input",
                    update
                );

                update();
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Draft Autosave
        |--------------------------------------------------------------------------
        */

        const draftKey =
            "hpu_content_contribution_draft_v1";

        let saveTimer =
            null;

        function scheduleDraftSave() {
            draftDot
                .classList
                .remove(
                    "saved"
                );

            clearTimeout(
                saveTimer
            );

            saveTimer =
                setTimeout(
                    function () {
                        try {
                            const data = {};

                            form
                                .querySelectorAll(
                                    "input, select, textarea"
                                )
                                .forEach(
                                    function (element) {
                                        if (
                                            !element.name
                                            ||
                                            element.name
                                            ===
                                            "csrf_token"
                                            ||
                                            element.type
                                            ===
                                            "file"
                                            ||
                                            element.disabled
                                        ) {
                                            return;
                                        }

                                        if (
                                            element.type
                                            ===
                                            "radio"
                                            ||
                                            element.type
                                            ===
                                            "checkbox"
                                        ) {
                                            data[
                                                element.name
                                                +
                                                "||"
                                                +
                                                element.value
                                            ] =
                                                element.checked;
                                        } else {
                                            data[
                                                element.name
                                            ] =
                                                element.value;
                                        }
                                    }
                                );

                            localStorage.setItem(
                                draftKey,
                                JSON.stringify(
                                    {
                                        savedAt:
                                            Date.now(),

                                        data:
                                            data
                                    }
                                )
                            );

                            draftDot
                                .classList
                                .add(
                                    "saved"
                                );

                            draftText.textContent =
                                "Draft saved • "
                                +
                                new Date()
                                    .toLocaleTimeString(
                                        [],
                                        {
                                            hour:
                                                "numeric",

                                            minute:
                                                "2-digit"
                                        }
                                    );
                        } catch (error) {
                            console.error(
                                "Draft save failed:",
                                error
                            );
                        }
                    },
                    700
                );
        }

        form.addEventListener(
            "input",
            scheduleDraftSave
        );

        form.addEventListener(
            "change",
            scheduleDraftSave
        );

        function formIsPristine() {
            return (
                !fieldValue(
                    "content_title"
                )
                &&
                !fieldValue(
                    "key_message"
                )
                &&
                !selectedChannel()
            );
        }

        try {
            const saved =
                localStorage.getItem(
                    draftKey
                );

            if (
                saved
                &&
                formIsPristine()
            ) {
                const parsed =
                    JSON.parse(
                        saved
                    );

                if (
                    parsed
                    &&
                    parsed.data
                ) {
                    draftBanner.innerHTML =
                        '<i class="bi bi-history mt-1"></i>'
                        +
                        '<div style="flex:1">'
                        +
                        "<strong>Unsaved draft found</strong><br>"
                        +
                        escapeHtml(
                            new Date(
                                parsed.savedAt
                            )
                            .toLocaleString()
                        )
                        +
                        "</div>"
                        +
                        '<button type="button" class="btn btn-sm btn-primary" data-action="restore">Restore</button>'
                        +
                        '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="discard">Discard</button>';

                    draftBanner
                        .classList
                        .add(
                            "show"
                        );

                    draftBanner
                        .querySelector(
                            '[data-action="restore"]'
                        )
                        .addEventListener(
                            "click",
                            function () {
                                Object.keys(
                                    parsed.data
                                )
                                .forEach(
                                    function (key) {
                                        if (
                                            key.includes(
                                                "||"
                                            )
                                        ) {
                                            const parts =
                                                key.split(
                                                    "||"
                                                );

                                            const element =
                                                form.querySelector(
                                                    '[name="'
                                                    +
                                                    parts[0]
                                                    +
                                                    '"][value="'
                                                    +
                                                    CSS.escape(
                                                        parts[1]
                                                    )
                                                    +
                                                    '"]'
                                                );

                                            if (element) {
                                                element.checked =
                                                    !!parsed.data[
                                                        key
                                                    ];
                                            }
                                        } else {
                                            const element =
                                                field(
                                                    key
                                                );

                                            if (element) {
                                                element.value =
                                                    parsed.data[
                                                        key
                                                    ];
                                            }
                                        }
                                    }
                                );

                                updateChannelFields();

                                draftBanner
                                    .classList
                                    .remove(
                                        "show"
                                    );

                                renderStep();
                            }
                        );

                    draftBanner
                        .querySelector(
                            '[data-action="discard"]'
                        )
                        .addEventListener(
                            "click",
                            function () {
                                localStorage.removeItem(
                                    draftKey
                                );

                                draftBanner
                                    .classList
                                    .remove(
                                        "show"
                                    );
                            }
                        );
                }
            }
        } catch (error) {
            console.error(
                "Draft restore failed:",
                error
            );
        }

        form.addEventListener(
            "submit",
            function () {
                try {
                    localStorage.removeItem(
                        draftKey
                    );
                } catch (error) {
                    // no-op
                }
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Start Wizard
        |--------------------------------------------------------------------------
        */

        renderStep();
    }
);
</script>

</body>
</html>
