<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - PUBLICATION REQUESTS
|--------------------------------------------------------------------------
| Install:
|   C:\laragon\www\jobrequest_phu\staff\publication_requests.php
|--------------------------------------------------------------------------
| Roles:
|   staff, supervisor, admin
|--------------------------------------------------------------------------
*/

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

requireRole([
    "staff",
    "supervisor",
    "admin"
]);
requireModule($conn, "publication_requests");

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

$userId =
    (int)(
        $_SESSION["user_id"]
        ?? 0
    );

$role =
    (string)(
        $_SESSION["role"]
        ?? "staff"
    );

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function pubTableExists(
    mysqli $conn,
    string $table
): bool {
    $stmt =
        $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?"
        );

    $stmt->bind_param(
        "s",
        $table
    );

    $stmt->execute();

    $row =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    return
        (int)(
            $row["total"]
            ?? 0
        ) > 0;
}

function pubColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $stmt =
        $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?"
        );

    $stmt->bind_param(
        "ss",
        $table,
        $column
    );

    $stmt->execute();

    $row =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    return
        (int)(
            $row["total"]
            ?? 0
        ) > 0;
}

function pubNotificationColumnExists(
    mysqli $conn,
    string $column
): bool {
    $stmt =
        $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'notifications'
               AND COLUMN_NAME = ?"
        );

    $stmt->bind_param(
        "s",
        $column
    );

    $stmt->execute();

    $row =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    return
        (int)(
            $row["total"]
            ?? 0
        ) > 0;
}

/*
|--------------------------------------------------------------------------
| Ensure publication notification metadata columns.
|--------------------------------------------------------------------------
*/
$publicationNotificationColumns = [
    "notification_type" =>
        "VARCHAR(80) NOT NULL DEFAULT 'info'",

    "action_url" =>
        "VARCHAR(500) NULL",

    "publication_kind" =>
        "VARCHAR(30) NULL",

    "publication_id" =>
        "INT NULL",

    "publication_status" =>
        "VARCHAR(40) NULL"
];

foreach (
    $publicationNotificationColumns
    as $column =>
        $definition
) {
    if (
        !pubNotificationColumnExists(
            $conn,
            $column
        )
    ) {
        $conn->query(
            "ALTER TABLE notifications
             ADD COLUMN `{$column}`
             {$definition}"
        );
    }
}

function pubNotifySubmitter(
    mysqli $conn,
    int $userId,
    string $kind,
    int $publicationId,
    string $titleText,
    string $newStatus
): void {
    if ($userId <= 0) {
        return;
    }

    $kindLabel =
        $kind === "recognition"
            ? "Recognition / Milestone"
            : (
                $kind === "contribution"
                    ? "Content Contribution"
                    : "Event / Activity"
            );

    $title =
        "Publication Status Updated";

    $message =
        $kindLabel
        . " submission \""
        . $titleText
        . "\" is now "
        . $newStatus
        . ".";

    $notificationType =
        "publication_status";

    $actionUrl =
        "/user/view_publication.php?kind="
        . rawurlencode(
            $kind
        )
        . "&id="
        . $publicationId;

    $stmt =
        $conn->prepare(
            "INSERT INTO notifications (
                user_id,
                request_id,
                title,
                message,
                notification_type,
                action_url,
                publication_kind,
                publication_id,
                publication_status,
                is_read
             )
             VALUES (
                ?,
                NULL,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                0
             )"
        );

    $stmt->bind_param(
        "isssssis",
        $userId,
        $title,
        $message,
        $notificationType,
        $actionUrl,
        $kind,
        $publicationId,
        $newStatus
    );

    $stmt->execute();
    $stmt->close();
}

function pubRedirect(
    string $query = ""
): never {
    header(
        "Location: "
        . BASE_URL
        . "/staff/publication_requests.php"
        . $query
    );

    exit;
}

function pubModalAnchor(): string
{
    // Keeps the view modal on the section the user acted in after
    // POST-redirect (forms post a return_anchor set by JS).
    $anchor =
        trim(
            (string)(
                $_POST["return_anchor"]
                ?? ""
            )
        );

    if ($anchor === "") {
        return "";
    }

    $allowed = [
        "sec-track",
        "sec-profile",
        "vmSupportingFiles",
        "sec-info",
        "vmMaterialContent",
        "sec-team",
        "sec-workflow",
        "vmMaterialLinks",
    ];

    return
        in_array($anchor, $allowed, true)
            ? "#" . $anchor
            : "";
}

function pubFlash(
    string $type,
    string $message,
    string $query = ""
): never {
    $_SESSION["publication_management_flash"] = [
        "type" =>
            $type,

        "message" =>
            $message
    ];

    // Stay in the modal's specific area after saves that return
    // to a ?view= detail modal (delete/list redirects unaffected).
    if (
        str_contains($query, "view=")
        &&
        str_contains($query, "id=")
    ) {
        $query .= pubModalAnchor();
    }

    pubRedirect(
        $query
    );
}

function pubStatusClass(
    string $status
): string {
    return match ($status) {
        "Published" =>
            "status-published",

        "For Approval" =>
            "status-approval",

        "In Preparation" =>
            "status-preparation",

        "Closed" =>
            "status-closed",

        default =>
            "status-review"
    };
}

/*
| Official publication workflow, in order:
| For Review → In Preparation → For Approval → Published → Closed.
| "Published" marks completed publication work; "Closed" is the final
| administrative status. Never use "Done" for publication stages.
*/
function pubWorkflowStages(): array
{
    return [
        "For Review",
        "In Preparation",
        "For Approval",
        "Published",
        "Closed",
    ];
}

/* Shared status presentation: label, icon, badge class and meaning. */
function pubStatusMeta(string $status): array
{
    return match ($status) {
        "For Review" => [
            "label" => "For Review",
            "icon" => "bi-hourglass-split",
            "class" => "status-review",
            "meaning" => "Newly submitted, waiting for HPU review",
        ],

        "In Preparation" => [
            "label" => "In Preparation",
            "icon" => "bi-pencil-square",
            "class" => "status-preparation",
            "meaning" => "Publication material is being prepared",
        ],

        "For Approval" => [
            "label" => "For Approval",
            "icon" => "bi-patch-check",
            "class" => "status-approval",
            "meaning" => "Draft finished, waiting for approval",
        ],

        "Published" => [
            "label" => "Published",
            "icon" => "bi-megaphone-fill",
            "class" => "status-published",
            "meaning" => "Approved content released / published",
        ],

        "Closed" => [
            "label" => "Closed",
            "icon" => "bi-lock-fill",
            "class" => "status-closed",
            "meaning" => "Fully finalized, no further action required",
        ],

        default => [
            "label" => $status !== "" ? $status : "For Review",
            "icon" => "bi-inbox",
            "class" => "status-review",
            "meaning" => "Awaiting HPU review",
        ],
    };
}

/*
| Allowed moves from a status: stay (notes-only), advance one step,
| or send back one step for rework. Skips (e.g. For Review straight
| to Published) are rejected. Unknown legacy statuses stay flexible.
*/
function pubAllowedTransitions(string $current): array
{
    $stages = pubWorkflowStages();
    $index = array_search($current, $stages, true);

    if ($index === false) {
        return $stages;
    }

    $allowed = [$current];

    if (isset($stages[$index + 1])) {
        $allowed[] = $stages[$index + 1];
    }

    if (isset($stages[$index - 1])) {
        $allowed[] = $stages[$index - 1];
    }

    return array_values($allowed);
}

function pubKindLabel(
    string $kind
): string {
    if ($kind === "recognition") {
        return "Recognition / Milestone";
    }

    if ($kind === "contribution") {
        return "Content Contribution";
    }

    return "Event / Activity";
}

function pubKindIcon(
    string $kind
): string {
    if ($kind === "recognition") {
        return "bi-award";
    }

    if ($kind === "contribution") {
        return "bi-journal-plus";
    }

    return "bi-calendar2-event";
}

/*
 * Kind-driven storage map. Event submissions carry no files;
 * recognition allows 5, content contributions allow 8.
 */
function pubSubmissionTable(
    string $kind
): ?string {
    if ($kind === "recognition") {
        return "publication_recognition_submissions";
    }

    if ($kind === "contribution") {
        return "publication_content_contributions";
    }

    if ($kind === "event") {
        return "publication_event_submissions";
    }

    return null;
}

function pubSubmissionTitleColumn(
    string $kind
): string {
    if ($kind === "recognition") {
        return "recognized_entity";
    }

    if ($kind === "contribution") {
        return "content_title";
    }

    return "title";
}

function pubContributionStorageDir(): string
{
    $dir =
        dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . "storage"
        . DIRECTORY_SEPARATOR
        . "publication_submissions"
        . DIRECTORY_SEPARATOR
        . "content_contributions";

    if (!is_dir($dir)) {
        mkdir(
            $dir,
            0775,
            true
        );
    }

    $deny =
        $dir
        . DIRECTORY_SEPARATOR
        . ".htaccess";

    if (!is_file($deny)) {
        @file_put_contents(
            $deny,
            "Options -Indexes\nRequire all denied\n"
        );
    }

    return $dir;
}

function pubSubmissionMaxFiles(
    string $kind
): int {
    return $kind === "contribution" ? 8 : 5;
}

function pubContributionTypes(): array
{
    return [
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
}

function pubContributionChannels(): array
{
    return [
        "Social Media (Facebook)",
        "Website",
        "Both"
    ];
}

/* Stream one decoded supporting-file entry from a storage directory. */
function pubServeSupportFile(
    array $file,
    string $storageDir
): never {
    $storedName =
        basename(
            (string)(
                $file["stored_name"]
                ?? ""
            )
        );

    $originalName =
        basename(
            (string)(
                $file["original_name"]
                ?? "supporting-file"
            )
        );

    $filePath =
        rtrim(
            $storageDir,
            DIRECTORY_SEPARATOR
        )
        . DIRECTORY_SEPARATOR
        . $storedName;

    if (
        $storedName === ""
        ||
        !is_file(
            $filePath
        )
    ) {
        http_response_code(
            404
        );

        exit(
            "The stored supporting file is missing."
        );
    }

    $mime =
        trim(
            (string)(
                $file["mime"]
                ?? ""
            )
        );

    if ($mime === "") {
        $mime =
            "application/octet-stream";
    }

    header(
        "Content-Type: "
        . $mime
    );

    header(
        "X-Content-Type-Options: nosniff"
    );

    header(
        "Accept-Ranges: bytes"
    );

    $inline =
        isset($_GET["inline"])
        || isset($_GET["preview"]);

    header(
        "Content-Disposition: "
        . ($inline ? "inline" : "attachment")
        . "; filename=\""
        . addcslashes(
            $originalName,
            "\"\\"
        )
        . "\"; filename*=UTF-8''"
        . rawurlencode(
            $originalName
        )
    );

    $fileSize = filesize($filePath);

    /*
    | HTTP Range support: browsers request byte ranges when seeking
    | through video/audio (duration bar jumps). Without 206 Partial
    | Content responses the player can only play from the start.
    */
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $start = 0;
    $end = $fileSize - 1;
    $status = 200;

    if (isset($_SERVER["HTTP_RANGE"])) {
        if (
            preg_match(
                '/bytes\s*=\s*(\d*)-(\d*)/',
                (string) $_SERVER["HTTP_RANGE"],
                $rangeMatch
            )
        ) {
            $reqStart = $rangeMatch[1] ?? "";
            $reqEnd = $rangeMatch[2] ?? "";

            if ($reqStart === "" && $reqEnd === "") {
                header("Content-Range: bytes */" . $fileSize);
                http_response_code(416);
                exit;
            }

            if ($reqStart === "") {
                $suffix = (int) $reqEnd;
                $start = $suffix > 0 ? max(0, $fileSize - $suffix) : 0;
            } else {
                $start = (int) $reqStart;

                if ($reqEnd !== "") {
                    $end = min((int) $reqEnd, $fileSize - 1);
                }
            }

            if (
                $start > $end
                ||
                $start >= $fileSize
            ) {
                header("Content-Range: bytes */" . $fileSize);
                http_response_code(416);
                exit;
            }

            $status = 206;
        }
    }

    $serveLength = $end - $start + 1;

    http_response_code($status);

    header(
        "Content-Length: "
        . $serveLength
    );

    if ($status === 206) {
        header(
            "Content-Range: bytes "
            . $start
            . "-"
            . $end
            . "/"
            . $fileSize
        );
    }

    // Chunked streaming keeps large (500 MB) videos playable
    // without loading the whole file into memory.
    $handle = fopen($filePath, "rb");

    if ($handle === false) {
        http_response_code(404);
        exit(
            "The stored supporting file is missing."
        );
    }

    fseek($handle, $start);

    $remaining = $serveLength;
    $chunkSize = 8192;

    set_time_limit(0);

    while (
        $remaining > 0
        &&
        !feof($handle)
    ) {
        $data = fread(
            $handle,
            (int) min($chunkSize, $remaining)
        );

        if ($data === false || $data === "") {
            break;
        }

        echo $data;

        $remaining -= strlen($data);

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }

    fclose($handle);

    exit;
}

/* Fetch one decoded supporting file by index or 404. */
function pubSubmissionFile(
    mysqli $conn,
    string $table,
    int $submissionId,
    int $fileIndex
): array {
    $stmt =
        $conn->prepare(
            "SELECT supporting_files
              FROM `{$table}`
              WHERE id = ?
              LIMIT 1"
        );

    $stmt->bind_param(
        "i",
        $submissionId
    );

    $stmt->execute();

    $submission =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    if (!$submission) {
        http_response_code(
            404
        );

        exit(
            "Publication submission not found."
        );
    }

    $files =
        pubDecodeFiles(
            $submission["supporting_files"]
            ?? null
        );

    if (
        !isset(
            $files[$fileIndex]
        )
        || !is_array($files[$fileIndex])
    ) {
        http_response_code(
            404
        );

        exit(
            "Supporting file not found."
        );
    }

    return $files[$fileIndex];
}

function pubDecodeRecognitionTypes(
    ?string $value
): array {
    $value =
        trim(
            (string)
            $value
        );

    if ($value === "") {
        return [];
    }

    $decoded =
        json_decode(
            $value,
            true
        );

    if (
        is_array(
            $decoded
        )
    ) {
        return
            array_values(
                array_filter(
                    array_map(
                        "strval",
                        $decoded
                    )
                )
            );
    }

    /*
     * Backward compatibility:
     * Older records may contain one plain-text type.
     */
    return [
        $value
    ];
}

function pubDecodeFiles(
    ?string $json
): array {
    $decoded =
        json_decode(
            (string)
            $json,
            true
        );

    return
        is_array(
            $decoded
        )
            ? $decoded
            : [];
}

function pubValidDate(
    $value
): ?string {
    $value =
        trim(
            (string)
            $value
        );

    if ($value === "") {
        return null;
    }

    $date =
        DateTime::createFromFormat(
            "!Y-m-d",
            $value
        );

    if (
        !$date
        ||
        $date->format("Y-m-d") !== $value
    ) {
        return null;
    }

    return $value;
}

function pubRecognitionStorageDir(): string {
    $dir =
        dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . "storage"
        . DIRECTORY_SEPARATOR
        . "publication_submissions"
        . DIRECTORY_SEPARATOR
        . "recognition";

    if (!is_dir($dir)) {
        mkdir(
            $dir,
            0775,
            true
        );
    }

    $deny =
        $dir
        . DIRECTORY_SEPARATOR
        . ".htaccess";

    if (!is_file($deny)) {
        @file_put_contents(
            $deny,
            "Options -Indexes\nRequire all denied\n"
        );
    }

    return $dir;
}

function pubValidateSupportFiles(
    array $files,
    array $extraExtensions = [],
    int $maxBatch = 8,
    ?array $baseExtensions = null,
    ?int $maxBytes = null
): array {
    $normalized = [];

    if (
        !isset($files["name"])
        ||
        !is_array($files["name"])
    ) {
        return [];
    }

    $count =
        count($files["name"]);

    if ($count > $maxBatch) {
        throw new RuntimeException(
            "You may upload up to {$maxBatch} supporting files at a time."
        );
    }

    $allowedExtensions = array_values(
        array_unique(
            array_merge(
                $baseExtensions
                ?? [
                    "pdf",
                    "jpg",
                    "jpeg",
                    "png",
                    "webp",
                    "doc",
                    "docx"
                ],
                array_map(
                    "strtolower",
                    array_map(
                        "strval",
                        $extraExtensions
                    )
                )
            )
        )
    );

    $maxBytes =
        $maxBytes
        ?? (10
        * 1024
        * 1024);

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
            $maxLabel =
                $maxBytes >= 1024 * 1024
                ? (int) round($maxBytes / (1024 * 1024)) . " MB"
                : (int) round($maxBytes / 1024) . " KB";

            throw new RuntimeException(
                "Each file must be {$maxLabel} or smaller."
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
                $extraExtensions
                    ? "Unsupported file type. Use PDF, Word, JPG, PNG, WEBP, MP4, or MOV files."
                    : "Unsupported file type. Use PDF, Word, JPG, PNG, or WEBP files."
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

        foreach (
            [
                "x-httpd-php",
                "x-php",
                "javascript",
                "text/html",
                "x-sh",
                "x-executable",
                "x-msdownload"
            ]
            as $blocked
        ) {
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

function pubRecognitionTypes(): array {
    return [
        "Hospital / Institutional Recognition",
        "Award or Recognition",
        "Training / Course Completion",
        "Certification / Accreditation",
        "Fellowship / Diplomate / Professional Milestone",
        "Appointment / Designation",
        "Others"
    ];
}

function pubFileSize(
    int $bytes
): string {
    if ($bytes < 1024) {
        return
            $bytes
            . " B";
    }

    if (
        $bytes
        <
        1024 * 1024
    ) {
        return
            number_format(
                $bytes / 1024,
                1
            )
            . " KB";
    }

    return
        number_format(
            $bytes / (1024 * 1024),
            1
        )
        . " MB";
}

function pubDateLabel(
    ?string $value,
    bool $withTime = false
): string {
    $value =
        trim(
            (string)
            $value
        );

    if ($value === "") {
        return "—";
    }

    $time =
        strtotime(
            $value
        );

    if ($time === false) {
        return $value;
    }

    return
        date(
            $withTime
                ? "M d, Y • g:i A"
                : "M d, Y",
            $time
        );
}

/*
|--------------------------------------------------------------------------
| Ensure Publication Tables
|--------------------------------------------------------------------------
| These definitions match the integrated submission pages.
|--------------------------------------------------------------------------
*/

$conn->query(
    "CREATE TABLE IF NOT EXISTS publication_event_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        submitted_by INT NOT NULL,
        email_address VARCHAR(190) NOT NULL,
        title VARCHAR(255) NOT NULL,
        activity_type VARCHAR(120) NOT NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        activity_time VARCHAR(120) NULL,
        venue VARCHAR(255) NULL,
        lead_office VARCHAR(255) NULL,
        partner_offices TEXT NULL,
        person_in_charge VARCHAR(255) NULL,
        participant_count INT NULL,
        background TEXT NULL,
        objectives TEXT NULL,
        activity_highlights LONGTEXT NULL,
        key_outputs LONGTEXT NULL,
        impact_significance LONGTEXT NULL,
        quotable_statement TEXT NULL,
        remembrance_sentence TEXT NULL,
        preferred_publication_date DATE NULL,
        privacy_confirmed TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_event_submitted_by (submitted_by),
        INDEX idx_event_created_at (created_at),
        INDEX idx_event_pub_date (preferred_publication_date)
    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS publication_recognition_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        submitted_by INT NOT NULL,
        email_address VARCHAR(190) NOT NULL,
        recognition_type TEXT NOT NULL,
        recognized_entity VARCHAR(255) NOT NULL,
        achievement LONGTEXT NOT NULL,
        supporting_information LONGTEXT NULL,
        supporting_files LONGTEXT NULL,
        preferred_publication_date DATE NULL,
        privacy_confirmed TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_recognition_submitted_by (submitted_by),
        INDEX idx_recognition_created_at (created_at),
        INDEX idx_recognition_pub_date (preferred_publication_date)
    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci"
);

/*
|--------------------------------------------------------------------------
| Workflow Columns
|--------------------------------------------------------------------------
*/

$workflowColumns = [
    "status" =>
        "VARCHAR(40) NOT NULL DEFAULT 'For Review'",

    "publication_notes" =>
        "TEXT NULL",

    "reviewed_by" =>
        "INT NULL",

    "reviewed_at" =>
        "DATETIME NULL",

    "published_at" =>
        "DATETIME NULL",

    "updated_at" =>
        "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
];

foreach (
    [
        "publication_event_submissions",
        "publication_recognition_submissions",
        "publication_content_contributions"
    ]
    as $table
) {
    foreach (
        $workflowColumns
        as $column =>
            $definition
    ) {
        if (
            !pubColumnExists(
                $conn,
                $table,
                $column
            )
        ) {
            $conn->query(
                "ALTER TABLE `{$table}`
                 ADD COLUMN `{$column}`
                 {$definition}"
            );
        }
    }

    /*
     * Public tracking token: lets track.php?token= resolve
     * Event / Activity, Recognition / Milestone and
     * Content Contribution submissions without sign-in.
     */
    if (
        pubTableExists($conn, $table) &&
        !pubColumnExists($conn, $table, "public_token")
    ) {
        try {
            $conn->query(
                "ALTER TABLE `{$table}`
                 ADD COLUMN `public_token` CHAR(64) NULL,
                 ADD UNIQUE INDEX `uniq_{$table}_public_token` (`public_token`)"
            );
        } catch (Throwable $e) {
            try {
                $conn->query(
                    "ALTER TABLE `{$table}`
                     ADD COLUMN `public_token` CHAR(64) NULL"
                );
            } catch (Throwable $e2) {
            }
        }
    }
}

function pubEnsurePublicToken(
    mysqli $conn,
    string $table,
    int $id
): string {
    $token = "";

    try {
        $stmt = $conn->prepare(
            "SELECT public_token FROM `{$table}` WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $token = trim((string) ($row["public_token"] ?? ""));
    } catch (Throwable $e) {
        return "";
    }

    if (
        strlen($token) === 64 &&
        ctype_xdigit($token)
    ) {
        return $token;
    }

    // Generate a globally-unique token across all trackable tables.
    do {
        $token = bin2hex(random_bytes(32));
        $collision = false;

        foreach (
            [
                "job_requests",
                "publication_event_submissions",
                "publication_recognition_submissions",
                "publication_content_contributions",
            ] as $checkTable
        ) {
            try {
                $check = $conn->prepare(
                    "SELECT id FROM `{$checkTable}` WHERE public_token = ? LIMIT 1"
                );

                if (!$check) {
                    continue;
                }

                $check->bind_param("s", $token);
                $check->execute();
                $hit = $check->get_result()->fetch_assoc();
                $check->close();

                if ($hit) {
                    $collision = true;
                    break;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
    } while ($collision);

    try {
        $update = $conn->prepare(
            "UPDATE `{$table}` SET public_token = ? WHERE id = ?"
        );
        $update->bind_param("si", $token, $id);
        $update->execute();
        $update->close();
    } catch (Throwable $e) {
        return "";
    }

    return $token;
}

/*
|--------------------------------------------------------------------------
| Publication Status History Ledger (trackable progress monitoring)
|--------------------------------------------------------------------------
*/
$conn->query(
    "CREATE TABLE IF NOT EXISTS publication_status_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(20) NOT NULL,
        publication_id INT NOT NULL,
        old_status VARCHAR(40) NULL,
        new_status VARCHAR(40) NOT NULL,
        notes_snapshot TEXT NULL,
        changed_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pub_hist_lookup (kind, publication_id),
        INDEX idx_pub_hist_created (created_at)
    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci"
);

/* Conversation tables (connected to My Requests). */
$conn->query(
    "CREATE TABLE IF NOT EXISTS publication_messages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(20) NOT NULL,
        publication_id INT NOT NULL,
        sender_id INT NOT NULL,
        sender_name VARCHAR(150) NOT NULL,
        sender_role VARCHAR(30) NOT NULL DEFAULT 'user',
        message TEXT NULL,
        attachment VARCHAR(255) NULL,
        attachment_original_name VARCHAR(255) NULL,
        is_internal TINYINT(1) NOT NULL DEFAULT 0,
        reply_to BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pub_msg_lookup (kind, publication_id, id),
        INDEX idx_pub_msg_sender (sender_id)
    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS publication_message_reads (
        kind VARCHAR(20) NOT NULL,
        publication_id INT NOT NULL,
        user_id INT NOT NULL,
        last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (kind, publication_id, user_id)
    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci"
);

function pubLogStatusHistory(
    mysqli $conn,
    string $kind,
    int $publicationId,
    ?string $oldStatus,
    string $newStatus,
    ?string $notesSnapshot,
    int $changedBy
): void {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO publication_status_history
                (kind, publication_id, old_status, new_status, notes_snapshot, changed_by)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        $changedByParam = $changedBy > 0 ? $changedBy : null;

        $stmt->bind_param(
            "sisssi",
            $kind,
            $publicationId,
            $oldStatus,
            $newStatus,
            $notesSnapshot,
            $changedByParam
        );

        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // History must never break the workflow update.
    }
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    empty(
        $_SESSION["publication_management_csrf"]
    )
) {
    $_SESSION["publication_management_csrf"] =
        bin2hex(
            random_bytes(
                32
            )
        );
}

/* Production-team helpers live in includes/publication_tasks.php (shared with my_assigned_jobs.php). */
require_once __DIR__ . "/../includes/publication_tasks.php";

function pubNotifyAssignee(
    mysqli $conn,
    int $assigneeId,
    string $kind,
    int $publicationId,
    string $titleText,
    string $taskLabel
): void {
    if ($assigneeId <= 0) {
        return;
    }

    try {
        $attempts = 0;

        while (true) {
            try {
                $stmt =
                    $conn->prepare(
                        "INSERT INTO notifications (
                            user_id,
                            request_id,
                            title,
                            message,
                            notification_type,
                            action_url,
                            publication_kind,
                            publication_id,
                            publication_status,
                            is_read
                         )
                         VALUES (
                            ?,
                            NULL,
                            ?,
                            ?,
                            'publication_task',
                            ?,
                            ?,
                            ?,
                            NULL,
                            0
                         )"
                    );

                if (!$stmt) {
                    throw new RuntimeException(
                        "Notification prepare failed: "
                        . $conn->error
                    );
                }

                $title = "New Production Task Assigned";
                $message = "You were assigned as {$taskLabel} for \"{$titleText}\".";
                $actionUrl = "/staff/publication_requests.php?view="
                    . rawurlencode($kind)
                    . "&id="
                    . $publicationId;

                $stmt->bind_param(
                    "issssi",
                    $assigneeId,
                    $title,
                    $message,
                    $actionUrl,
                    $kind,
                    $publicationId
                );

                $stmt->execute();
                $stmt->close();
                break;
            } catch (Throwable $retryable) {
                // One retry on transient lock contention (deadlock 1213,
                // lock timeout 1205), then give up loudly in the log.
                $attempts++;

                $msg = $retryable->getMessage();

                $transient =
                    str_contains($msg, "1213")
                    || str_contains($msg, "1205")
                    || str_contains($msg, "Deadlock")
                    || str_contains($msg, "Lock wait");

                if ($attempts > 1 || !$transient) {
                    throw $retryable;
                }

                usleep(150000);
            }
        }
    } catch (Throwable $e) {
        // Notifications must never block the assignment itself.
        error_log(
            "pubNotifyAssignee failed (kind={$kind} id={$publicationId} assignee={$assigneeId}): "
            . $e->getMessage()
        );
    }
}

$csrf =
    (string)
    $_SESSION["publication_management_csrf"];

/*
|--------------------------------------------------------------------------
| Secure Recognition Attachment Download
|--------------------------------------------------------------------------
*/

if (
    isset(
        $_GET["download_recognition"],
        $_GET["file_index"]
    )
) {
    $submissionId =
        (int)
        $_GET["download_recognition"];

    $fileIndex =
        (int)
        $_GET["file_index"];

    $file =
        pubSubmissionFile(
            $conn,
            "publication_recognition_submissions",
            $submissionId,
            $fileIndex
        );

    pubServeSupportFile(
        $file,
        pubRecognitionStorageDir()
    );
}

/*
|--------------------------------------------------------------------------
| Secure Content Contribution Attachment Download
|--------------------------------------------------------------------------
*/

if (
    isset(
        $_GET["download_contribution"],
        $_GET["file_index"]
    )
) {
    $submissionId =
        (int)
        $_GET["download_contribution"];

    $fileIndex =
        (int)
        $_GET["file_index"];

    $file =
        pubSubmissionFile(
            $conn,
            "publication_content_contributions",
            $submissionId,
            $fileIndex
        );

    pubServeSupportFile(
        $file,
        pubContributionStorageDir()
    );
}

/*
|--------------------------------------------------------------------------
| Secure HPU Production File Download (staff uploads, not requestor files)
|--------------------------------------------------------------------------
*/

if (
    isset(
        $_GET["download_material"],
        $_GET["file_index"]
    )
) {
    $materialKind =
        trim(
            (string)(
                $_GET["kind"]
                ?? ""
            )
        );

    $materialId =
        (int)
        $_GET["download_material"];

    $materialIndex =
        (int)
        $_GET["file_index"];

    if (
        !in_array(
            $materialKind,
            [
                "event",
                "recognition",
                "contribution"
            ],
            true
        )
        ||
        $materialId <= 0
        ||
        $materialIndex < 0
    ) {
        http_response_code(404);
        exit("Production file not found.");
    }

    $materialContent =
        pubGetMaterialContent(
            $conn,
            $materialKind,
            $materialId
        );

    $materialFiles =
        $materialContent["material_files"] ?? [];

    if (
        !isset($materialFiles[$materialIndex])
        ||
        !is_array($materialFiles[$materialIndex])
    ) {
        http_response_code(404);
        exit("Production file not found.");
    }

    $materialEntry = $materialFiles[$materialIndex];
    $materialDriveUrl = trim((string) ($materialEntry["drive_url"] ?? ""));

    // Google Drive link entries open externally — nothing stored locally.
    if (
        $materialDriveUrl !== ""
        &&
        filter_var($materialDriveUrl, FILTER_VALIDATE_URL) !== false
    ) {
        header("Location: " . $materialDriveUrl);
        exit;
    }

    pubServeSupportFile(
        $materialEntry,
        pubStaffMaterialStorageDir()
    );
}

/*
|--------------------------------------------------------------------------
| Workflow Update + Production Team Assignments
|--------------------------------------------------------------------------
*/

pubEnsureTaskTable($conn);

$allowedStatuses = [
    "For Review",
    "In Preparation",
    "For Approval",
    "Published",
    "Closed"
];

if (
    $_SERVER["REQUEST_METHOD"]
    ===
    "POST"
) {
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
        pubFlash(
            "danger",
            "Security token expired. Please refresh the page and try again."
        );
    }

    $action =
        trim(
            (string)(
                $_POST["action"]
                ?? ""
            )
        );

    if (
        $action
        ===
        "update_workflow"
    ) {
        $kind =
            trim(
                (string)(
                    $_POST["kind"]
                    ?? ""
                )
            );

        $id =
            (int)(
                $_POST["id"]
                ?? 0
            );

        $status =
            trim(
                (string)(
                    $_POST["status"]
                    ?? ""
                )
            );

        $notes =
            trim(
                (string)(
                    $_POST["publication_notes"]
                    ?? ""
                )
            );

        if (
            !in_array(
                $kind,
                [
                    "event",
                    "recognition",
                    "contribution"
                ],
                true
            )
        ) {
            pubFlash(
                "danger",
                "Invalid publication submission type."
            );
        }

        if (
            !in_array(
                $status,
                $allowedStatuses,
                true
            )
        ) {
            pubFlash(
                "danger",
                "Invalid publication workflow status."
            );
        }

        $table =
            pubSubmissionTable(
                $kind
            );

        if ($table === null) {
            pubFlash(
                "danger",
                "Invalid publication submission type."
            );
        }

        $titleColumn =
            pubSubmissionTitleColumn(
                $kind
            );

        $stmt =
            $conn->prepare(
                "SELECT
                    id,
                    submitted_by,
                    `{$titleColumn}` AS notification_title,
                    status AS old_status
                 FROM `{$table}`
                 WHERE id = ?
                 LIMIT 1"
            );

        $stmt->bind_param(
            "i",
            $id
        );

        $stmt->execute();

        $exists =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$exists) {
            pubFlash(
                "danger",
                "Publication submission not found."
            );
        }

        if (
            $status
            ===
            "Published"
        ) {
            $stmt =
                $conn->prepare(
                    "UPDATE `{$table}`
                     SET
                        status = ?,
                        publication_notes = ?,
                        reviewed_by = ?,
                        reviewed_at = NOW(),
                        published_at = COALESCE(
                            published_at,
                            NOW()
                        )
                     WHERE id = ?"
                );

            $stmt->bind_param(
                "ssii",
                $status,
                $notes,
                $userId,
                $id
            );
        } else {
            $stmt =
                $conn->prepare(
                    "UPDATE `{$table}`
                     SET
                        status = ?,
                        publication_notes = ?,
                        reviewed_by = ?,
                        reviewed_at = NOW()
                     WHERE id = ?"
                );

            $stmt->bind_param(
                "ssii",
                $status,
                $notes,
                $userId,
                $id
            );
        }

        $stmt->execute();
        $stmt->close();

        $oldStatusStr = (string)($exists["old_status"] ?? "");

        /*
         * Trackable progress: ledger every status change with actor + notes.
         */
        if ($oldStatusStr !== $status) {
            pubLogStatusHistory(
                $conn,
                $kind,
                $id,
                $oldStatusStr === "" ? null : $oldStatusStr,
                $status,
                $notes === "" ? null : $notes,
                $userId
            );
        }

        /*
         * Notify the original submitter whenever the workflow status changes.
         * Notes-only updates do not create duplicate status notifications.
         */
        if (
            $oldStatusStr
            !==
            $status
        ) {
            pubNotifySubmitter(
                $conn,
                (int)(
                    $exists["submitted_by"]
                    ?? 0
                ),
                $kind,
                $id,
                (string)(
                    $exists["notification_title"]
                    ?? "Publication Submission"
                ),
                $status
            );
        }

        pubFlash(
            "success",
            "Publication workflow updated and the submitter was notified.",
            "?view="
            . urlencode(
                $kind
            )
            . "&id="
            . $id
        );
    }

    /*
    | Assign / re-assign the production team in one save:
    | layout artist, video editor, posting drafter, supervisor approver.
    | Only supervisors/admins may set the approver, and the approver
    | must be a supervisor/admin.
    */
    if (
        $action
        ===
        "assign_team"
    ) {
        $kind =
            trim(
                (string)(
                    $_POST["kind"]
                    ?? ""
                )
            );

        $id =
            (int)(
                $_POST["id"]
                ?? 0
            );

        if (
            !in_array(
                $kind,
                [
                    "event",
                    "recognition",
                    "contribution"
                ],
                true
            )
            ||
            $id <= 0
        ) {
            pubFlash(
                "danger",
                "Invalid publication submission."
            );
        }

        $viewQuery =
            "?view="
            . urlencode($kind)
            . "&id="
            . $id;

        $table =
            pubSubmissionTable(
                $kind
            );

        if ($table === null) {
            pubFlash(
                "danger",
                "Invalid publication submission type."
            );
        }

        $titleColumn =
            pubSubmissionTitleColumn(
                $kind
            );

        $existsStmt =
            $conn->prepare(
                "SELECT
                    id,
                    `{$titleColumn}` AS notification_title
                 FROM `{$table}`
                 WHERE id = ?
                 LIMIT 1"
            );

        $existsStmt->bind_param(
            "i",
            $id
        );

        $existsStmt->execute();

        $existsRow =
            $existsStmt
                ->get_result()
                ->fetch_assoc();

        $existsStmt->close();

        if (!$existsRow) {
            pubFlash(
                "danger",
                "Publication submission not found."
            );
        }

        $canSetApprover =
            in_array(
                $role,
                ["supervisor", "admin"],
                true
            );

        $postedTeam =
            $_POST["team"]
            ?? [];

        if (!is_array($postedTeam)) {
            $postedTeam = [];
        }

        $changed = 0;

        foreach (
            pubTaskRoles() as $taskRole => $taskMeta
        ) {
            // Staff may not touch the approver seat.
            if (
                ($taskMeta["approver_only"] ?? false)
                &&
                !$canSetApprover
            ) {
                continue;
            }

            $assigneeId =
                (int)(
                    $postedTeam[$taskRole]
                    ?? -1
                );

            // -1 / missing = seat untouched; 0 = unassign.
            if ($assigneeId < 0) {
                continue;
            }

            if ($assigneeId === 0) {
                $delStmt =
                    $conn->prepare(
                        "DELETE FROM publication_task_assignments
                          WHERE kind = ?
                            AND publication_id = ?
                            AND task_role = ?"
                    );

                $delStmt->bind_param(
                    "sis",
                    $kind,
                    $id,
                    $taskRole
                );

                $delStmt->execute();
                $delStmt->close();
                continue;
            }

            // Assignee must be an active HPU member (approver: sup/admin).
            $poolCheck =
                $conn->prepare(
                    ($taskMeta["approver_only"] ?? false)
                        ? "SELECT id, name FROM users
                            WHERE id = ?
                              AND role IN ('supervisor', 'admin')
                              AND status = 'Active'
                            LIMIT 1"
                        : "SELECT id, name FROM users
                            WHERE id = ?
                              AND role IN ('staff', 'supervisor', 'admin')
                              AND status = 'Active'
                            LIMIT 1"
                );

            $poolCheck->bind_param(
                "i",
                $assigneeId
            );

            $poolCheck->execute();

            $poolRow =
                $poolCheck
                    ->get_result()
                    ->fetch_assoc();

            $poolCheck->close();

            if (!$poolRow) {
                pubFlash(
                    "danger",
                    "Invalid staff member for "
                    . (string)($taskMeta["label"] ?? $taskRole)
                    . ".",
                    $viewQuery
                );
            }

            // Skip + stay silent when nothing actually changed.
            $prevStmt =
                $conn->prepare(
                    "SELECT assigned_to
                      FROM publication_task_assignments
                      WHERE kind = ?
                        AND publication_id = ?
                        AND task_role = ?
                      LIMIT 1"
                );

            $prevStmt->bind_param(
                "sis",
                $kind,
                $id,
                $taskRole
            );

            $prevStmt->execute();

            $prevRow =
                $prevStmt
                    ->get_result()
                    ->fetch_assoc();

            $prevStmt->close();

            if (
                $prevRow
                &&
                (int)$prevRow["assigned_to"] === $assigneeId
            ) {
                continue;
            }

            $upStmt =
                $conn->prepare(
                    "INSERT INTO publication_task_assignments
                        (kind, publication_id, task_role,
                         assigned_to, assigned_by, assigned_at)
                     VALUES (?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                        assigned_to = VALUES(assigned_to),
                        assigned_by = VALUES(assigned_by),
                        assigned_at = NOW()"
                );

            $upStmt->bind_param(
                "sisii",
                $kind,
                $id,
                $taskRole,
                $assigneeId,
                $userId
            );

            $upStmt->execute();
            $upStmt->close();

            $changed++;

            pubNotifyAssignee(
                $conn,
                $assigneeId,
                $kind,
                $id,
                (string)(
                    $existsRow["notification_title"]
                    ?? "Publication Submission"
                ),
                (string)($taskMeta["label"] ?? $taskRole)
            );
        }

        pubFlash(
            "success",
            $changed > 0
                ? "Production team updated ({$changed} assignment(s)) and assignees were notified."
                : "No assignment changes — the production team is already up to date.",
            $viewQuery
        );
    }

    /*
    | Per-task material approvals: Layout / Video Reels / Posting Draft.
    | Supervisor + admin only. Tracked with approver + timestamp.
    | Workflow status is intentionally left unchanged.
    */
    if (
        $action
        ===
        "approve_task"
        ||
        $action
        ===
        "unapprove_task"
    ) {
        $kind =
            trim(
                (string)(
                    $_POST["kind"]
                    ?? ""
                )
            );

        $id =
            (int)(
                $_POST["id"]
                ?? 0
            );

        $taskRole =
            trim(
                (string)(
                    $_POST["task_role"]
                    ?? ""
                )
            );

        $viewQuery =
            "?view="
            . urlencode($kind)
            . "&id="
            . $id;

        if (
            !in_array(
                $role,
                ["supervisor", "admin"],
                true
            )
        ) {
            pubFlash(
                "danger",
                "Only a supervisor or admin can approve publication materials.",
                $viewQuery
            );
        }

        if (
            !in_array(
                $kind,
                [
                    "event",
                    "recognition",
                    "contribution"
                ],
                true
            )
            ||
            $id <= 0
            ||
            !in_array(
                $taskRole,
                pubApprovableTaskRoles(),
                true
            )
        ) {
            pubFlash(
                "danger",
                "Invalid material approval request.",
                $viewQuery
            );
        }

        $table =
            pubSubmissionTable(
                $kind
            );

        if ($table === null) {
            pubFlash(
                "danger",
                "Invalid publication submission type.",
                $viewQuery
            );
        }

        $existsStmt =
            $conn->prepare(
                "SELECT id
                  FROM `{$table}`
                  WHERE id = ?
                  LIMIT 1"
            );

        $existsStmt->bind_param(
            "i",
            $id
        );

        $existsStmt->execute();

        $existsRow =
            $existsStmt
                ->get_result()
                ->fetch_assoc();

        $existsStmt->close();

        if (!$existsRow) {
            pubFlash(
                "danger",
                "Publication submission not found.",
                $viewQuery
            );
        }

        pubEnsureTaskTable($conn);

        if ($action === "approve_task") {
            // Only assigned tasks can be approved.
            $assignCheck =
                $conn->prepare(
                    "SELECT assigned_to
                       FROM publication_task_assignments
                       WHERE kind = ?
                         AND publication_id = ?
                         AND task_role = ?
                       LIMIT 1"
                );

            $assignCheck->bind_param(
                "sis",
                $kind,
                $id,
                $taskRole
            );

            $assignCheck->execute();

            $assignRow =
                $assignCheck
                    ->get_result()
                    ->fetch_assoc();

            $assignCheck->close();

            if (
                !$assignRow
                ||
                (int)($assignRow["assigned_to"] ?? 0) <= 0
            ) {
                pubFlash(
                    "danger",
                    "Assign someone to this task first — only assigned tasks can be approved.",
                    $viewQuery
                );
            }

            $upStmt =
                $conn->prepare(
                    "INSERT INTO publication_task_approvals
                        (kind, publication_id, task_role,
                         approved_by, approved_at)
                     VALUES (?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                        approved_by = VALUES(approved_by),
                        approved_at = NOW()"
                );

            $upStmt->bind_param(
                "sisi",
                $kind,
                $id,
                $taskRole,
                $userId
            );

            $upStmt->execute();
            $upStmt->close();

            $taskLabel =
                (string)(
                    pubTaskRoleMeta($taskRole)["label"]
                    ?? ucfirst($taskRole)
                );

            pubFlash(
                "success",
                $taskLabel . " approved.",
                $viewQuery
            );
        }

        $delStmt =
            $conn->prepare(
                "DELETE FROM publication_task_approvals
                  WHERE kind = ?
                    AND publication_id = ?
                    AND task_role = ?"
            );

        $delStmt->bind_param(
            "sis",
            $kind,
            $id,
            $taskRole
        );

        $delStmt->execute();
        $delStmt->close();

        $taskLabel =
            (string)(
                pubTaskRoleMeta($taskRole)["label"]
                ?? ucfirst($taskRole)
            );

        pubFlash(
            "success",
            $taskLabel . " approval removed.",
            $viewQuery
        );
    }

    /*
    | Draft / Final paragraph text (1500 chars max, editable).
    | Staff, supervisor and admin may save. Multiline preserved.
    | Draft and Final save independently via save_draft_text /
    | save_final_text (save_material_text saves both, legacy).
    */
    if (
        $action
        ===
        "save_material_text"
        ||
        $action
        ===
        "save_draft_text"
        ||
        $action
        ===
        "save_final_text"
    ) {
        $kind =
            trim(
                (string)(
                    $_POST["kind"]
                    ?? ""
                )
            );

        $id =
            (int)(
                $_POST["id"]
                ?? 0
            );

        $viewQuery =
            "?view="
            . urlencode($kind)
            . "&id="
            . $id;

        if (
            !in_array(
                $kind,
                [
                    "event",
                    "recognition",
                    "contribution"
                ],
                true
            )
            ||
            $id <= 0
        ) {
            pubFlash(
                "danger",
                "Invalid publication submission.",
                $viewQuery
            );
        }

        $table =
            pubSubmissionTable(
                $kind
            );

        if ($table === null) {
            pubFlash(
                "danger",
                "Invalid publication submission type.",
                $viewQuery
            );
        }

        $maxChars = pubMaterialMaxChars();
        $saveDraftOnly = $action === "save_draft_text";
        $saveFinalOnly = $action === "save_final_text";

        $draftText = trim((string) ($_POST["draft_text"] ?? ""));
        $finalText = trim((string) ($_POST["final_text"] ?? ""));

        if (
            mb_strlen($draftText) > $maxChars
            ||
            mb_strlen($finalText) > $maxChars
        ) {
            pubFlash(
                "danger",
                "Draft and Final text must be {$maxChars} characters or fewer.",
                $viewQuery
            );
        }

        $existsStmt =
            $conn->prepare(
                "SELECT id
                  FROM `{$table}`
                  WHERE id = ?
                  LIMIT 1"
            );

        $existsStmt->bind_param("i", $id);
        $existsStmt->execute();
        $existsRow = $existsStmt->get_result()->fetch_assoc();
        $existsStmt->close();

        if (!$existsRow) {
            pubFlash(
                "danger",
                "Publication submission not found.",
                $viewQuery
            );
        }

        pubEnsureMaterialTable($conn);

        // Single-field saves must preserve the untouched side.
        if ($saveDraftOnly || $saveFinalOnly) {
            $current =
                pubGetMaterialContent(
                    $conn,
                    $kind,
                    $id
                );

            if ($saveDraftOnly) {
                $finalText = (string) ($current["final_text"] ?? "");
            } else {
                $draftText = (string) ($current["draft_text"] ?? "");
            }
        }

        $upStmt =
            $conn->prepare(
                "INSERT INTO publication_material_content
                    (kind, publication_id, draft_text, final_text,
                     updated_by, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    draft_text = VALUES(draft_text),
                    final_text = VALUES(final_text),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()"
            );

        $upStmt->bind_param(
            "sissi",
            $kind,
            $id,
            $draftText,
            $finalText,
            $userId
        );

        $upStmt->execute();
        $upStmt->close();

        pubFlash(
            "success",
            $saveDraftOnly
                ? "Draft text saved ({$maxChars} characters max)."
                : (
                    $saveFinalOnly
                        ? "Final text saved ({$maxChars} characters max)."
                        : "Draft and Final text saved ({$maxChars} characters max each)."
                ),
            $viewQuery
        );
    }

    /*
    | Move Draft text into Final text (1500 chars max), confirmed with
    | the staff member's own account password. The moved content is
    | saved to both fields so nothing is lost.
    */
    if (
        $action
        ===
        "move_draft_to_final"
    ) {
        $kind =
            trim(
                (string)(
                    $_POST["kind"]
                    ?? ""
                )
            );

        $id =
            (int)(
                $_POST["id"]
                ?? 0
            );

        $viewQuery =
            "?view="
            . urlencode($kind)
            . "&id="
            . $id;

        if (
            !in_array(
                $kind,
                [
                    "event",
                    "recognition",
                    "contribution"
                ],
                true
            )
            ||
            $id <= 0
        ) {
            pubFlash(
                "danger",
                "Invalid publication submission.",
                $viewQuery
            );
        }

        $table =
            pubSubmissionTable(
                $kind
            );

        if ($table === null) {
            pubFlash(
                "danger",
                "Invalid publication submission type.",
                $viewQuery
            );
        }

        $maxChars = pubMaterialMaxChars();
        $moveText = trim((string) ($_POST["draft_text"] ?? ""));

        if ($moveText === "") {
            pubFlash(
                "danger",
                "Draft text is empty — write the draft first.",
                $viewQuery
            );
        }

        if (mb_strlen($moveText) > $maxChars) {
            pubFlash(
                "danger",
                "Draft text must be {$maxChars} characters or fewer.",
                $viewQuery
            );
        }

        $confirmPassword =
            (string)(
                $_POST["confirm_password"]
                ?? ""
            );

        if ($confirmPassword === "") {
            pubFlash(
                "danger",
                "Please enter your account password to confirm the move.",
                $viewQuery
            );
        }

        $pwStmt =
            $conn->prepare(
                "SELECT password
                  FROM users
                  WHERE id = ?
                  LIMIT 1"
            );

        $pwStmt->bind_param(
            "i",
            $userId
        );

        $pwStmt->execute();

        $pwRow =
            $pwStmt
                ->get_result()
                ->fetch_assoc();

        $pwStmt->close();

        if (
            !$pwRow
            ||
            !password_verify(
                $confirmPassword,
                (string)(
                    $pwRow["password"]
                    ?? ""
                )
            )
        ) {
            pubFlash(
                "danger",
                "Incorrect account password. Draft was not moved to Final.",
                $viewQuery
            );
        }

        $existsStmt =
            $conn->prepare(
                "SELECT id
                  FROM `{$table}`
                  WHERE id = ?
                  LIMIT 1"
            );

        $existsStmt->bind_param("i", $id);
        $existsStmt->execute();
        $existsRow = $existsStmt->get_result()->fetch_assoc();
        $existsStmt->close();

        if (!$existsRow) {
            pubFlash(
                "danger",
                "Publication submission not found.",
                $viewQuery
            );
        }

        pubEnsureMaterialTable($conn);

        $upStmt =
            $conn->prepare(
                "INSERT INTO publication_material_content
                    (kind, publication_id, draft_text, final_text,
                     updated_by, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    draft_text = VALUES(draft_text),
                    final_text = VALUES(final_text),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()"
            );

        $upStmt->bind_param(
            "sissi",
            $kind,
            $id,
            $moveText,
            $moveText,
            $userId
        );

        $upStmt->execute();
        $upStmt->close();

        pubFlash(
            "success",
            "Draft text moved to Final text.",
            $viewQuery
        );
    }

    /*
    | Website / social links (max 3). Unlocked only when every assigned
    | material is approved, and only supervisor/admin may save.
    */
    if (
        $action
        ===
        "save_material_links"
    ) {
        $kind =
            trim(
                (string)(
                    $_POST["kind"]
                    ?? ""
                )
            );

        $id =
            (int)(
                $_POST["id"]
                ?? 0
            );

        $viewQuery =
            "?view="
            . urlencode($kind)
            . "&id="
            . $id;

        if (
            !in_array(
                $role,
                ["supervisor", "admin"],
                true
            )
        ) {
            pubFlash(
                "danger",
                "Only a supervisor or admin can attach publication links.",
                $viewQuery
            );
        }

        if (
            !in_array(
                $kind,
                [
                    "event",
                    "recognition",
                    "contribution"
                ],
                true
            )
            ||
            $id <= 0
        ) {
            pubFlash(
                "danger",
                "Invalid publication submission.",
                $viewQuery
            );
        }

        $table =
            pubSubmissionTable(
                $kind
            );

        if ($table === null) {
            pubFlash(
                "danger",
                "Invalid publication submission type.",
                $viewQuery
            );
        }

        $existsStmt =
            $conn->prepare(
                "SELECT id
                  FROM `{$table}`
                  WHERE id = ?
                  LIMIT 1"
            );

        $existsStmt->bind_param("i", $id);
        $existsStmt->execute();
        $existsRow = $existsStmt->get_result()->fetch_assoc();
        $existsStmt->close();

        if (!$existsRow) {
            pubFlash(
                "danger",
                "Publication submission not found.",
                $viewQuery
            );
        }

        pubEnsureMaterialTable($conn);

        // Gate: every assigned approvable task must be approved.
        $assignments = pubTaskAssignments($conn, $kind, $id);
        $approvals = pubTaskApprovals($conn, $kind, $id);
        $assignedRoles = [];

        foreach (pubApprovableTaskRoles() as $r) {
            if (
                !empty($assignments[$r])
                &&
                (int)($assignments[$r]["user_id"] ?? 0) > 0
            ) {
                $assignedRoles[] = $r;
            }
        }

        if (count($assignedRoles) === 0) {
            pubFlash(
                "danger",
                "Assign at least one material task before attaching links.",
                $viewQuery
            );
        }

        foreach ($assignedRoles as $r) {
            if (empty($approvals[$r])) {
                pubFlash(
                    "danger",
                    "Attach links only after all assigned materials are approved.",
                    $viewQuery
                );
            }
        }

        $rawTypes = $_POST["link_type"] ?? [];
        $rawUrls = $_POST["link_url"] ?? [];

        if (!is_array($rawTypes)) {
            $rawTypes = [];
        }

        if (!is_array($rawUrls)) {
            $rawUrls = [];
        }

        if (count($rawUrls) > pubMaterialMaxLinks()) {
            pubFlash(
                "danger",
                "Up to " . pubMaterialMaxLinks() . " links only.",
                $viewQuery
            );
        }

        $links = pubNormalizeMaterialLinks(
            array_values(array_map("strval", $rawTypes)),
            array_values(array_map("strval", $rawUrls))
        );

        $linksJson = json_encode(
            $links,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $upStmt =
            $conn->prepare(
                "INSERT INTO publication_material_content
                    (kind, publication_id, links, updated_by, updated_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    links = VALUES(links),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()"
            );

        $upStmt->bind_param(
            "sisi",
            $kind,
            $id,
            $linksJson,
            $userId
        );

        $upStmt->execute();
        $upStmt->close();

        pubFlash(
            "success",
            count($links) > 0
                ? "Publication links saved (" . count($links) . "/" . pubMaterialMaxLinks() . ")."
                : "Publication links cleared.",
            $viewQuery
        );
    }

    /*
    | Single website / social link operations (Add, Edit, Delete).
    | Same gate as bulk save: supervisor/admin only, and only after
    | every assigned material is approved.
    */
    if (
        $action
        ===
        "add_material_link"
        ||
        $action
        ===
        "update_material_link"
        ||
        $action
        ===
        "delete_material_link"
    ) {
        $kind =
            trim(
                (string)(
                    $_POST["kind"]
                    ?? ""
                )
            );

        $id =
            (int)(
                $_POST["id"]
                ?? 0
            );

        $viewQuery =
            "?view="
            . urlencode($kind)
            . "&id="
            . $id;

        if (
            !in_array(
                $role,
                ["supervisor", "admin"],
                true
            )
        ) {
            pubFlash(
                "danger",
                "Only a supervisor or admin can manage publication links.",
                $viewQuery
            );
        }

        if (
            !in_array(
                $kind,
                [
                    "event",
                    "recognition",
                    "contribution"
                ],
                true
            )
            ||
            $id <= 0
        ) {
            pubFlash(
                "danger",
                "Invalid publication submission.",
                $viewQuery
            );
        }

        $table =
            pubSubmissionTable(
                $kind
            );

        if ($table === null) {
            pubFlash(
                "danger",
                "Invalid publication submission type.",
                $viewQuery
            );
        }

        $existsStmt =
            $conn->prepare(
                "SELECT id
                  FROM `{$table}`
                  WHERE id = ?
                  LIMIT 1"
            );

        $existsStmt->bind_param("i", $id);
        $existsStmt->execute();
        $existsRow = $existsStmt->get_result()->fetch_assoc();
        $existsStmt->close();

        if (!$existsRow) {
            pubFlash(
                "danger",
                "Publication submission not found.",
                $viewQuery
            );
        }

        pubEnsureMaterialTable($conn);

        $assignments = pubTaskAssignments($conn, $kind, $id);
        $approvals = pubTaskApprovals($conn, $kind, $id);
        $assignedRoles = [];

        foreach (pubApprovableTaskRoles() as $r) {
            if (
                !empty($assignments[$r])
                &&
                (int)($assignments[$r]["user_id"] ?? 0) > 0
            ) {
                $assignedRoles[] = $r;
            }
        }

        if (count($assignedRoles) === 0) {
            pubFlash(
                "danger",
                "Assign at least one material task before managing links.",
                $viewQuery
            );
        }

        foreach ($assignedRoles as $r) {
            if (empty($approvals[$r])) {
                pubFlash(
                    "danger",
                    "Manage links only after all assigned materials are approved.",
                    $viewQuery
                );
            }
        }

        $content =
            pubGetMaterialContent(
                $conn,
                $kind,
                $id
            );

        $links =
            is_array($content["links"] ?? null)
                ? array_values($content["links"])
                : [];

        if ($action === "add_material_link") {
            if (count($links) >= pubMaterialMaxLinks()) {
                pubFlash(
                    "danger",
                    "Up to " . pubMaterialMaxLinks() . " links only.",
                    $viewQuery
                );
            }

            $normalized = pubNormalizeMaterialLinks(
                [(string) ($_POST["link_type"] ?? "Website")],
                [(string) ($_POST["link_url"] ?? "")]
            );

            if (empty($normalized)) {
                pubFlash(
                    "danger",
                    "Enter a valid link URL (e.g. https://example.com).",
                    $viewQuery
                );
            }

            $links[] = $normalized[0];
            $links = array_slice($links, 0, pubMaterialMaxLinks());
            $flashText = "Link added (" . count($links) . "/" . pubMaterialMaxLinks() . ").";
        } elseif ($action === "update_material_link") {
            $linkIndex = (int) ($_POST["link_index"] ?? -1);

            if (!isset($links[$linkIndex])) {
                pubFlash(
                    "danger",
                    "Link not found.",
                    $viewQuery
                );
            }

            $normalized = pubNormalizeMaterialLinks(
                [(string) ($_POST["link_type"] ?? "Website")],
                [(string) ($_POST["link_url"] ?? "")]
            );

            if (empty($normalized)) {
                pubFlash(
                    "danger",
                    "Enter a valid link URL (e.g. https://example.com).",
                    $viewQuery
                );
            }

            $links[$linkIndex] = $normalized[0];
            $flashText = "Link updated.";
        } else {
            $linkIndex = (int) ($_POST["link_index"] ?? -1);

            if (!isset($links[$linkIndex])) {
                pubFlash(
                    "danger",
                    "Link not found.",
                    $viewQuery
                );
            }

            array_splice($links, $linkIndex, 1);
            $links = array_values($links);
            $flashText = "Link deleted.";
        }

        $linksJson = json_encode(
            $links,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $upStmt =
            $conn->prepare(
                "INSERT INTO publication_material_content
                    (kind, publication_id, links, updated_by, updated_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    links = VALUES(links),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()"
            );

        $upStmt->bind_param(
            "sisi",
            $kind,
            $id,
            $linksJson,
            $userId
        );

        $upStmt->execute();
        $upStmt->close();

        pubFlash(
            "success",
            $flashText,
            $viewQuery
        );
    }

    /*
    | HPU production file uploads (staff outputs — Layout / Video-Reels /
    | Posting Draft). Stored separately from the requestor's files and
    | visible only on this staff page.
    */
    if (
        $action
        ===
        "add_material_file"
    ) {
        $kind =
            trim(
                (string)(
                    $_POST["kind"]
                    ?? ""
                )
            );

        $id =
            (int)(
                $_POST["id"]
                ?? 0
            );

        $viewQuery =
            "?view="
            . urlencode($kind)
            . "&id="
            . $id;

        if (
            !in_array(
                $kind,
                [
                    "event",
                    "recognition",
                    "contribution"
                ],
                true
            )
            ||
            $id <= 0
        ) {
            pubFlash(
                "danger",
                "Invalid publication submission.",
                $viewQuery
            );
        }

        $table =
            pubSubmissionTable(
                $kind
            );

        if ($table === null) {
            pubFlash(
                "danger",
                "Invalid publication submission type.",
                $viewQuery
            );
        }

        $materialKind =
            trim(
                (string)(
                    $_POST["material_kind"]
                    ?? "Other"
                )
            );

        if (
            !in_array(
                $materialKind,
                pubMaterialFileKinds(),
                true
            )
        ) {
            $materialKind = "Other";
        }

        pubEnsureMaterialTable($conn);

        [$ruleExtensions, $ruleMaxBytes, $ruleAllowLink] =
            pubMaterialUploadRules($materialKind);

        $content =
            pubGetMaterialContent(
                $conn,
                $kind,
                $id
            );

        $existing =
            is_array($content["material_files"] ?? null)
                ? $content["material_files"]
                : [];

        $room =
            pubMaterialMaxFiles()
            - count($existing);

        if ($room <= 0) {
            pubFlash(
                "danger",
                "Up to " . pubMaterialMaxFiles() . " production files only.",
                $viewQuery
            );
        }

        $driveUrl =
            trim(
                (string)(
                    $_POST["drive_url"]
                    ?? ""
                )
            );

        if (
            $driveUrl !== ""
            &&
            !preg_match('#^https?://#i', $driveUrl)
        ) {
            $driveUrl = "https://" . $driveUrl;
        }

        $driveValid =
            $driveUrl !== ""
            &&
            strlen($driveUrl) <= 500
            &&
            filter_var($driveUrl, FILTER_VALIDATE_URL) !== false;

        if ($driveUrl !== "" && !$driveValid) {
            pubFlash(
                "danger",
                "That Google Drive link is not a valid URL.",
                $viewQuery
            );
        }

        if ($driveUrl !== "" && !$ruleAllowLink) {
            pubFlash(
                "danger",
                "Drive links are not allowed for this output type.",
                $viewQuery
            );
        }

        $hasUploads =
            isset($_FILES["material_files"])
            &&
            is_array($_FILES["material_files"]["name"] ?? null)
            &&
            count(
                array_filter(
                    array_map("strval", $_FILES["material_files"]["name"]),
                    static fn($n): bool => trim($n) !== ""
                )
            ) > 0;

        if (!$hasUploads && $driveUrl === "") {
            pubFlash(
                "danger",
                $ruleAllowLink
                    ? "Attach a file or paste a Google Drive link."
                    : "Choose at least one file to upload.",
                $viewQuery
            );
        }

        $picked = [];

        if ($hasUploads) {
            try {
                $picked =
                    pubValidateSupportFiles(
                        $_FILES["material_files"] ?? [],
                        [],
                        $room,
                        $ruleExtensions,
                        $ruleMaxBytes
                    );
            } catch (RuntimeException $e) {
                pubFlash(
                    "danger",
                    $e->getMessage(),
                    $viewQuery
                );
            }

            if (empty($picked)) {
                pubFlash(
                    "danger",
                    "Choose at least one file to upload.",
                    $viewQuery
                );
            }
        }

        $storageDir = pubStaffMaterialStorageDir();
        $userName = (string) ($_SESSION["name"] ?? "");
        $nowLabel = date("Y-m-d H:i:s");

        if ($driveValid) {
            $existing[] = [
                "material" => $materialKind,
                "stored_name" => "",
                "original_name" => "Google Drive link",
                "extension" => "drive",
                "drive_url" => $driveUrl,
                "size" => 0,
                "mime" => "text/uri-list",
                "uploaded_by" => $userId,
                "uploader_name" => $userName,
                "uploaded_at" => $nowLabel,
            ];
        }

        foreach ($picked as $upload) {
            $storedName =
                bin2hex(random_bytes(16))
                . "."
                . $upload["extension"];

            $dest =
                rtrim($storageDir, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . $storedName;

            $moved = false;

            if (
                is_uploaded_file($upload["tmp_name"])
            ) {
                $moved = move_uploaded_file(
                    $upload["tmp_name"],
                    $dest
                );
            }

            if (!$moved) {
                continue;
            }

            $existing[] = [
                "material" => $materialKind,
                "stored_name" => $storedName,
                "original_name" => $upload["original_name"],
                "extension" => $upload["extension"],
                "size" => $upload["size"],
                "mime" => $upload["mime"],
                "uploaded_by" => $userId,
                "uploader_name" => $userName,
                "uploaded_at" => $nowLabel,
            ];

            if (count($existing) >= pubMaterialMaxFiles()) {
                break;
            }
        }

        $filesJson = json_encode(
            array_values($existing),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $upStmt =
            $conn->prepare(
                "INSERT INTO publication_material_content
                    (kind, publication_id, material_files, updated_by, updated_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    material_files = VALUES(material_files),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()"
            );

        $upStmt->bind_param(
            "sisi",
            $kind,
            $id,
            $filesJson,
            $userId
        );

        $upStmt->execute();
        $upStmt->close();

        pubFlash(
            "success",
            $driveValid && empty($picked)
                ? "Google Drive link attached (" . $materialKind . ")."
                : "Production output attached (" . $materialKind . ").",
            $viewQuery
        );
    }

    if (
        $action
        ===
        "delete_material_file"
    ) {
        $kind =
            trim(
                (string)(
                    $_POST["kind"]
                    ?? ""
                )
            );

        $id =
            (int)(
                $_POST["id"]
                ?? 0
            );

        $fileIndex =
            (int)(
                $_POST["file_index"]
                ?? -1
            );

        $viewQuery =
            "?view="
            . urlencode($kind)
            . "&id="
            . $id;

        if (
            !in_array(
                $kind,
                [
                    "event",
                    "recognition",
                    "contribution"
                ],
                true
            )
            ||
            $id <= 0
            ||
            $fileIndex < 0
        ) {
            pubFlash(
                "danger",
                "Invalid production file.",
                $viewQuery
            );
        }

        pubEnsureMaterialTable($conn);

        $content =
            pubGetMaterialContent(
                $conn,
                $kind,
                $id
            );

        $existing =
            is_array($content["material_files"] ?? null)
                ? array_values($content["material_files"])
                : [];

        if (!isset($existing[$fileIndex])) {
            pubFlash(
                "danger",
                "Production file not found.",
                $viewQuery
            );
        }

        $removed = $existing[$fileIndex];
        unset($existing[$fileIndex]);
        $existing = array_values($existing);

        $storedName =
            basename(
                (string)(
                    $removed["stored_name"]
                    ?? ""
                )
            );

        if ($storedName !== "") {
            $path =
                rtrim(
                    pubStaffMaterialStorageDir(),
                    DIRECTORY_SEPARATOR
                )
                . DIRECTORY_SEPARATOR
                . $storedName;

            if (is_file($path)) {
                @unlink($path);
            }
        }

        $filesJson = json_encode(
            $existing,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $upStmt =
            $conn->prepare(
                "INSERT INTO publication_material_content
                    (kind, publication_id, material_files, updated_by, updated_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    material_files = VALUES(material_files),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()"
            );

        $upStmt->bind_param(
            "sisi",
            $kind,
            $id,
            $filesJson,
            $userId
        );

        $upStmt->execute();
        $upStmt->close();

        pubFlash(
            "success",
            "Production file removed.",
            $viewQuery
        );
    }

    if (
        $action
        ===
        "update_submission"
    ) {
        $kind =
            trim(
                (string)(
                    $_POST["kind"]
                    ?? ""
                )
            );

        $id =
            (int)(
                $_POST["id"]
                ?? 0
            );

        if (
            !in_array(
                $kind,
                [
                    "event",
                    "recognition",
                    "contribution"
                ],
                true
            )
            ||
            $id <= 0
        ) {
            pubFlash(
                "danger",
                "Invalid publication submission."
            );
        }

        $viewQuery =
            "?view="
            . urlencode($kind)
            . "&id="
            . $id;

        if ($kind === "recognition") {
            $email =
                trim(
                    (string)(
                        $_POST["email_address"]
                        ?? ""
                    )
                );

            $types =
                $_POST["recognition_types"]
                ?? [];

            if (!is_array($types)) {
                $types = [];
            }

            $types =
                array_values(
                    array_unique(
                        array_filter(
                            array_map(
                                "strval",
                                array_map("trim", $types)
                            )
                        )
                    )
                );

            $entity =
                trim(
                    (string)(
                        $_POST["recognized_entity"]
                        ?? ""
                    )
                );

            $achievement =
                trim(
                    (string)(
                        $_POST["achievement"]
                        ?? ""
                    )
                );

            $supporting =
                trim(
                    (string)(
                        $_POST["supporting_information"]
                        ?? ""
                    )
                );

            $pubDate =
                pubValidDate(
                    $_POST["preferred_publication_date"]
                    ?? null
                );

            if (
                $email === ""
                ||
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                pubFlash(
                    "danger",
                    "Please enter a valid email address.",
                    $viewQuery
                );
            }

            if (!$types) {
                pubFlash(
                    "danger",
                    "Please select at least one recognition / milestone type.",
                    $viewQuery
                );
            }

            if (
                count(
                    array_diff(
                        $types,
                        pubRecognitionTypes()
                    )
                ) > 0
            ) {
                pubFlash(
                    "danger",
                    "One or more selected recognition types are invalid.",
                    $viewQuery
                );
            }

            if ($entity === "") {
                pubFlash(
                    "danger",
                    "Please indicate who or what was recognized.",
                    $viewQuery
                );
            }

            if ($achievement === "") {
                pubFlash(
                    "danger",
                    "Please describe what was achieved.",
                    $viewQuery
                );
            }

            $typesJson =
                json_encode(
                    $types,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                );

            $stmt =
                $conn->prepare(
                    "UPDATE publication_recognition_submissions
                      SET email_address = ?,
                          recognition_type = ?,
                          recognized_entity = ?,
                          achievement = ?,
                          supporting_information = ?,
                          preferred_publication_date = ?,
                          reviewed_by = ?,
                          reviewed_at = NOW()
                      WHERE id = ?"
                );

            $stmt->bind_param(
                "ssssssii",
                $email,
                $typesJson,
                $entity,
                $achievement,
                $supporting,
                $pubDate,
                $userId,
                $id
            );

            $stmt->execute();
            $stmt->close();

            pubFlash(
                "success",
                "Recognition / Milestone submission updated.",
                $viewQuery
            );
        }

        if ($kind === "contribution") {
            $strOrNull = static function ($v) {
                $v = trim((string)$v);
                return $v === "" ? null : $v;
            };

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

            $channel =
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

            $pubDate =
                pubValidDate(
                    $_POST["preferred_publication_date"]
                    ?? null
                );

            if (
                $email === ""
                ||
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                pubFlash(
                    "danger",
                    "Please enter a valid email address.",
                    $viewQuery
                );
            }

            if (
                !in_array(
                    $contributionType,
                    pubContributionTypes(),
                    true
                )
            ) {
                pubFlash(
                    "danger",
                    "Please select a valid content contribution type.",
                    $viewQuery
                );
            }

            if (
                !in_array(
                    $channel,
                    pubContributionChannels(),
                    true
                )
            ) {
                pubFlash(
                    "danger",
                    "Please select where the content should be published.",
                    $viewQuery
                );
            }

            if ($contentTitle === "") {
                pubFlash(
                    "danger",
                    "Please provide a working title or headline.",
                    $viewQuery
                );
            }

            if ($leadOffice === "") {
                pubFlash(
                    "danger",
                    "Please provide the requesting / lead office.",
                    $viewQuery
                );
            }

            if ($focalPerson === "") {
                pubFlash(
                    "danger",
                    "Please provide the focal person / content contributor.",
                    $viewQuery
                );
            }

            $stmt =
                $conn->prepare(
                    "UPDATE publication_content_contributions
                      SET email_address = ?,
                          contribution_type = ?,
                          publication_channel = ?,
                          content_title = ?,
                          lead_office = ?,
                          focal_person = ?,
                          target_audience = ?,
                          purpose_objective = ?,
                          key_message = ?,
                          social_media_caption = ?,
                          website_content = ?,
                          call_to_action = ?,
                          hashtags = ?,
                          reference_links = ?,
                          preferred_publication_date = ?,
                          reviewed_by = ?,
                          reviewed_at = NOW()
                      WHERE id = ?"
                );

            $targetAudience = $strOrNull($_POST["target_audience"] ?? null);
            $purposeObjective = $strOrNull($_POST["purpose_objective"] ?? null);
            $keyMessage = $strOrNull($_POST["key_message"] ?? null);
            $caption = $strOrNull($_POST["social_media_caption"] ?? null);
            $websiteBody = $strOrNull($_POST["website_content"] ?? null);
            $callToAction = $strOrNull($_POST["call_to_action"] ?? null);
            $hashtags = $strOrNull($_POST["hashtags"] ?? null);
            $referenceLinks = $strOrNull($_POST["reference_links"] ?? null);

            $stmt->bind_param(
                "sssssssssssssssii",
                $email,
                $contributionType,
                $channel,
                $contentTitle,
                $leadOffice,
                $focalPerson,
                $targetAudience,
                $purposeObjective,
                $keyMessage,
                $caption,
                $websiteBody,
                $callToAction,
                $hashtags,
                $referenceLinks,
                $pubDate,
                $userId,
                $id
            );

            $stmt->execute();
            $stmt->close();

            pubFlash(
                "success",
                "Content Contribution submission updated.",
                $viewQuery
            );
        }

        $email =
            trim(
                (string)(
                    $_POST["email_address"]
                    ?? ""
                )
            );

        $title =
            trim(
                (string)(
                    $_POST["title"]
                    ?? ""
                )
            );

        $activityType =
            trim(
                (string)(
                    $_POST["activity_type"]
                    ?? ""
                )
            );

        $strOrNull = static function ($v) {
            $v = trim((string)$v);
            return $v === "" ? null : $v;
        };

        $startDate =
            pubValidDate(
                $_POST["start_date"]
                ?? null
            );

        $endDate =
            pubValidDate(
                $_POST["end_date"]
                ?? null
            );

        $pubDate =
            pubValidDate(
                $_POST["preferred_publication_date"]
                ?? null
            );

        $participantRaw =
            trim(
                (string)(
                    $_POST["participant_count"]
                    ?? ""
                )
            );

        $participantCount =
            $participantRaw === ""
                ? null
                : max(0, (int)$participantRaw);

        if (
            $email === ""
            ||
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            pubFlash(
                "danger",
                "Please enter a valid email address.",
                $viewQuery
            );
        }

        if ($title === "") {
            pubFlash(
                "danger",
                "Please enter the activity title.",
                $viewQuery
            );
        }

        if ($activityType === "") {
            pubFlash(
                "danger",
                "Please enter the type of activity.",
                $viewQuery
            );
        }

        $stmt =
            $conn->prepare(
                "UPDATE publication_event_submissions
                  SET email_address = ?,
                      title = ?,
                      activity_type = ?,
                      start_date = ?,
                      end_date = ?,
                      activity_time = ?,
                      venue = ?,
                      lead_office = ?,
                      partner_offices = ?,
                      person_in_charge = ?,
                      participant_count = ?,
                      background = ?,
                      objectives = ?,
                      activity_highlights = ?,
                      key_outputs = ?,
                      impact_significance = ?,
                      quotable_statement = ?,
                      remembrance_sentence = ?,
                      preferred_publication_date = ?,
                      reviewed_by = ?,
                      reviewed_at = NOW()
                  WHERE id = ?"
            );

        $activityTime = $strOrNull($_POST["activity_time"] ?? null);
        $venue = $strOrNull($_POST["venue"] ?? null);
        $leadOffice = $strOrNull($_POST["lead_office"] ?? null);
        $partnerOffices = $strOrNull($_POST["partner_offices"] ?? null);
        $personInCharge = $strOrNull($_POST["person_in_charge"] ?? null);
        $background = $strOrNull($_POST["background"] ?? null);
        $objectives = $strOrNull($_POST["objectives"] ?? null);
        $highlights = $strOrNull($_POST["activity_highlights"] ?? null);
        $keyOutputs = $strOrNull($_POST["key_outputs"] ?? null);
        $impact = $strOrNull($_POST["impact_significance"] ?? null);
        $quotable = $strOrNull($_POST["quotable_statement"] ?? null);
        $remembrance = $strOrNull($_POST["remembrance_sentence"] ?? null);

        $stmt->bind_param(
            "ssssssssssissssssssii",
            $email,
            $title,
            $activityType,
            $startDate,
            $endDate,
            $activityTime,
            $venue,
            $leadOffice,
            $partnerOffices,
            $personInCharge,
            $participantCount,
            $background,
            $objectives,
            $highlights,
            $keyOutputs,
            $impact,
            $quotable,
            $remembrance,
            $pubDate,
            $userId,
            $id
        );

        $stmt->execute();
        $stmt->close();

        pubFlash(
            "success",
            "Event / Activity submission updated.",
            $viewQuery
        );
    }

    if (
        $action
        ===
        "add_files"
    ) {
        $id =
            (int)(
                $_POST["id"]
                ?? 0
            );

        $fileKind =
            trim(
                (string)(
                    $_POST["kind"]
                    ?? "recognition"
                )
            );

        if (
            !in_array(
                $fileKind,
                [
                    "recognition",
                    "contribution"
                ],
                true
            )
        ) {
            $fileKind = "recognition";
        }

        $fileTable =
            pubSubmissionTable(
                $fileKind
            );

        if ($fileTable === null) {
            pubFlash(
                "danger",
                "Invalid publication submission."
            );
        }

        $viewQuery =
            "?view="
            . urlencode($fileKind)
            . "&id="
            . $id;

        if ($id <= 0) {
            pubFlash(
                "danger",
                "Invalid publication submission."
            );
        }

        $stmt =
            $conn->prepare(
                "SELECT supporting_files
                  FROM `{$fileTable}`
                  WHERE id = ?
                  LIMIT 1"
            );

        $stmt->bind_param("i", $id);
        $stmt->execute();

        $row =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$row) {
            pubFlash(
                "danger",
                "Publication submission not found."
            );
        }

        try {
            $fileMaxEarly =
                pubSubmissionMaxFiles(
                    $fileKind
                );

            $validated =
                pubValidateSupportFiles(
                    $_FILES["supporting_files"]
                    ?? [],
                    $fileKind === "contribution"
                        ? ["mp4", "mov"]
                        : [],
                    $fileMaxEarly
                );
        } catch (RuntimeException $e) {
            pubFlash(
                "danger",
                $e->getMessage(),
                $viewQuery
            );
        }

        if (!$validated) {
            pubFlash(
                "danger",
                "Please choose at least one file to upload.",
                $viewQuery
            );
        }

        $existing =
            pubDecodeFiles(
                $row["supporting_files"]
                ?? null
            );

        $fileMax =
            pubSubmissionMaxFiles(
                $fileKind
            );

        if (count($existing) + count($validated) > $fileMax) {
            pubFlash(
                "danger",
                "Only {$fileMax} supporting files are allowed per submission.",
                $viewQuery
            );
        }

        $storageDir =
            $fileKind === "contribution"
                ? pubContributionStorageDir()
                : pubRecognitionStorageDir();

        $stored = [];

        try {
            foreach ($validated as $file) {
                $storedName =
                    bin2hex(random_bytes(18))
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

                $stored[] = $destination;

                $existing[] = [
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
                json_encode(
                    array_values($existing),
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                );

            $stmt =
                $conn->prepare(
                    "UPDATE `{$fileTable}`
                      SET supporting_files = ?,
                          reviewed_by = ?,
                          reviewed_at = NOW()
                      WHERE id = ?"
                );

            $stmt->bind_param(
                "sii",
                $filesJson,
                $userId,
                $id
            );

            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            foreach ($stored as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            if ($e instanceof RuntimeException) {
                pubFlash(
                    "danger",
                    $e->getMessage(),
                    $viewQuery
                );
            }

            throw $e;
        }

        pubFlash(
            "success",
            "Supporting file(s) added.",
            $viewQuery
        );
    }

    if (
        $action
        ===
        "delete_file"
    ) {
        $id =
            (int)(
                $_POST["id"]
                ?? 0
            );

        $fileIndex =
            (int)(
                $_POST["file_index"]
                ?? -1
            );

        $fileKind =
            trim(
                (string)(
                    $_POST["kind"]
                    ?? "recognition"
                )
            );

        if (
            !in_array(
                $fileKind,
                [
                    "recognition",
                    "contribution"
                ],
                true
            )
        ) {
            $fileKind = "recognition";
        }

        $fileTable =
            pubSubmissionTable(
                $fileKind
            );

        if ($fileTable === null) {
            pubFlash(
                "danger",
                "Invalid file request."
            );
        }

        $viewQuery =
            "?view="
            . urlencode($fileKind)
            . "&id="
            . $id;

        if ($id <= 0 || $fileIndex < 0) {
            pubFlash(
                "danger",
                "Invalid file request."
            );
        }

        $stmt =
            $conn->prepare(
                "SELECT supporting_files
                  FROM `{$fileTable}`
                  WHERE id = ?
                  LIMIT 1"
            );

        $stmt->bind_param("i", $id);
        $stmt->execute();

        $row =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$row) {
            pubFlash(
                "danger",
                "Publication submission not found."
            );
        }

        $existing =
            pubDecodeFiles(
                $row["supporting_files"]
                ?? null
            );

        if (!isset($existing[$fileIndex])) {
            pubFlash(
                "danger",
                "Supporting file not found.",
                $viewQuery
            );
        }

        $removed =
            $existing[$fileIndex];

        unset($existing[$fileIndex]);

        $existing =
            array_values($existing);

        $path =
            (
                $fileKind === "contribution"
                    ? pubContributionStorageDir()
                    : pubRecognitionStorageDir()
            )
            . DIRECTORY_SEPARATOR
            . basename(
                (string)(
                    $removed["stored_name"]
                    ?? ""
                )
            );

        if (
            basename(
                (string)(
                    $removed["stored_name"]
                    ?? ""
                )
            ) !== ""
            &&
            is_file($path)
        ) {
            @unlink($path);
        }

        $filesJson =
            json_encode(
                $existing,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );

        $stmt =
            $conn->prepare(
                "UPDATE `{$fileTable}`
                  SET supporting_files = ?,
                      reviewed_by = ?,
                      reviewed_at = NOW()
                  WHERE id = ?"
            );

        $stmt->bind_param(
            "sii",
            $filesJson,
            $userId,
            $id
        );

        $stmt->execute();
        $stmt->close();

        pubFlash(
            "success",
            "Supporting file deleted.",
            $viewQuery
        );
    }

    if (
        $action
        ===
        "rename_file"
    ) {
        $id =
            (int)(
                $_POST["id"]
                ?? 0
            );

        $fileIndex =
            (int)(
                $_POST["file_index"]
                ?? -1
            );

        $newName =
            trim(
                (string)(
                    $_POST["new_name"]
                    ?? ""
                )
            );

        $fileKind =
            trim(
                (string)(
                    $_POST["kind"]
                    ?? "recognition"
                )
            );

        if (
            !in_array(
                $fileKind,
                [
                    "recognition",
                    "contribution"
                ],
                true
            )
        ) {
            $fileKind = "recognition";
        }

        $fileTable =
            pubSubmissionTable(
                $fileKind
            );

        if ($fileTable === null) {
            pubFlash(
                "danger",
                "Invalid file request."
            );
        }

        $viewQuery =
            "?view="
            . urlencode($fileKind)
            . "&id="
            . $id;

        if ($id <= 0 || $fileIndex < 0) {
            pubFlash(
                "danger",
                "Invalid file request."
            );
        }

        $newName =
            basename($newName);

        if (
            $newName === ""
            ||
            strlen($newName) > 150
        ) {
            pubFlash(
                "danger",
                "Please enter a valid file name (max 150 characters).",
                $viewQuery
            );
        }

        $stmt =
            $conn->prepare(
                "SELECT supporting_files
                  FROM `{$fileTable}`
                  WHERE id = ?
                  LIMIT 1"
            );

        $stmt->bind_param("i", $id);
        $stmt->execute();

        $row =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$row) {
            pubFlash(
                "danger",
                "Publication submission not found."
            );
        }

        $existing =
            pubDecodeFiles(
                $row["supporting_files"]
                ?? null
            );

        if (!isset($existing[$fileIndex])) {
            pubFlash(
                "danger",
                "Supporting file not found.",
                $viewQuery
            );
        }

        $existing[$fileIndex]["original_name"] =
            $newName;

        $filesJson =
            json_encode(
                array_values($existing),
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );

        $stmt =
            $conn->prepare(
                "UPDATE `{$fileTable}`
                  SET supporting_files = ?,
                      reviewed_by = ?,
                      reviewed_at = NOW()
                  WHERE id = ?"
            );

        $stmt->bind_param(
            "sii",
            $filesJson,
            $userId,
            $id
        );

        $stmt->execute();
        $stmt->close();

        pubFlash(
            "success",
            "File name updated.",
            $viewQuery
        );
    }

    if (
        $action
        ===
        "delete_submission"
    ) {
        $kind =
            trim(
                (string)(
                    $_POST["kind"]
                    ?? ""
                )
            );

        $id =
            (int)(
                $_POST["id"]
                ?? 0
            );

        if (
            !in_array(
                $kind,
                [
                    "event",
                    "recognition",
                    "contribution"
                ],
                true
            )
            ||
            $id <= 0
        ) {
            pubFlash(
                "danger",
                "Invalid publication submission."
            );
        }

        $table =
            pubSubmissionTable(
                $kind
            );

        if ($table === null) {
            pubFlash(
                "danger",
                "Invalid publication submission."
            );
        }

        /*
         * Password confirmation: HPU Staff / Supervisor (and Admin)
         * must re-enter their own account password before a
         * publication submission can be permanently deleted.
         */
        $deleteViewQuery =
            "?view="
            . urlencode($kind)
            . "&id="
            . $id;

        $confirmPassword =
            (string)(
                $_POST["confirm_password"]
                ?? ""
            );

        if ($confirmPassword === "") {
            pubFlash(
                "danger",
                "Please enter your account password to confirm deletion.",
                $deleteViewQuery
            );
        }

        $pwStmt =
            $conn->prepare(
                "SELECT password
                  FROM users
                  WHERE id = ?
                  LIMIT 1"
            );

        $pwStmt->bind_param(
            "i",
            $userId
        );

        $pwStmt->execute();

        $pwRow =
            $pwStmt
                ->get_result()
                ->fetch_assoc();

        $pwStmt->close();

        if (
            !$pwRow
            ||
            !password_verify(
                $confirmPassword,
                (string)(
                    $pwRow["password"]
                    ?? ""
                )
            )
        ) {
            pubFlash(
                "danger",
                "Incorrect account password. The submission was not deleted.",
                $deleteViewQuery
            );
        }

        if (
            $kind === "recognition"
            ||
            $kind === "contribution"
        ) {
            $stmt =
                $conn->prepare(
                    "SELECT supporting_files
                      FROM `{$table}`
                      WHERE id = ?
                      LIMIT 1"
                );

            $stmt->bind_param("i", $id);
            $stmt->execute();

            $row =
                $stmt
                    ->get_result()
                    ->fetch_assoc();

            $stmt->close();

            if (!$row) {
                pubFlash(
                    "danger",
                    "Publication submission not found."
                );
            }

            $fileStorageDir =
                $kind === "contribution"
                    ? pubContributionStorageDir()
                    : pubRecognitionStorageDir();

            foreach (
                pubDecodeFiles(
                    $row["supporting_files"]
                    ?? null
                )
                as $file
            ) {
                $stored =
                    basename(
                        (string)(
                            $file["stored_name"]
                            ?? ""
                        )
                    );

                if ($stored === "") {
                    continue;
                }

                $path =
                    $fileStorageDir
                    . DIRECTORY_SEPARATOR
                    . $stored;

                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        $stmt =
            $conn->prepare(
                "DELETE FROM `{$table}`
                  WHERE id = ?
                  LIMIT 1"
            );

        $stmt->bind_param("i", $id);
        $stmt->execute();

        $deleted =
            $stmt->affected_rows > 0;

        $stmt->close();

        if (!$deleted) {
            pubFlash(
                "danger",
                "Publication submission not found."
            );
        }

        pubFlash(
            "success",
            "Publication submission deleted."
        );
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$tab =
    trim(
        (string)(
            $_GET["tab"]
            ?? "all"
        )
    );

if (
    !in_array(
        $tab,
        [
            "all",
            "event",
            "recognition",
            "contribution"
        ],
        true
    )
) {
    $tab =
        "all";
}

$statusFilter =
    trim(
        (string)(
            $_GET["status"]
            ?? ""
        )
    );

if (
    $statusFilter !== ""
    &&
    !in_array(
        $statusFilter,
        $allowedStatuses,
        true
    )
) {
    $statusFilter =
        "";
}

$q =
    trim(
        (string)(
            $_GET["q"]
            ?? ""
        )
    );

/*
|--------------------------------------------------------------------------
| Detail Record
|--------------------------------------------------------------------------
*/

$viewKind =
    trim(
        (string)(
            $_GET["view"]
            ?? ""
        )
    );

$viewId =
    (int)(
        $_GET["id"]
        ?? 0
    );

$detail =
    null;

$viewMissing =
    false;

if (
    $viewId > 0
    &&
    in_array(
        $viewKind,
        [
            "event",
            "recognition",
            "contribution"
        ],
        true
    )
) {
    if (
        $viewKind
        ===
        "event"
    ) {
        $stmt =
            $conn->prepare(
                "SELECT
                    p.*,
                    u.name AS submitter_name,
                    reviewer.name AS reviewer_name
                 FROM publication_event_submissions p
                 LEFT JOIN users u
                    ON u.id = p.submitted_by
                 LEFT JOIN users reviewer
                    ON reviewer.id = p.reviewed_by
                 WHERE p.id = ?
                 LIMIT 1"
            );
    } elseif (
        $viewKind
        ===
        "recognition"
    ) {
        $stmt =
            $conn->prepare(
                "SELECT
                    p.*,
                    u.name AS submitter_name,
                    reviewer.name AS reviewer_name
                 FROM publication_recognition_submissions p
                 LEFT JOIN users u
                    ON u.id = p.submitted_by
                 LEFT JOIN users reviewer
                    ON reviewer.id = p.reviewed_by
                 WHERE p.id = ?
                 LIMIT 1"
            );
    } else {
        $stmt =
            $conn->prepare(
                "SELECT
                    p.*,
                    u.name AS submitter_name,
                    reviewer.name AS reviewer_name
                 FROM publication_content_contributions p
                 LEFT JOIN users u
                    ON u.id = p.submitted_by
                 LEFT JOIN users reviewer
                    ON reviewer.id = p.reviewed_by
                 WHERE p.id = ?
                 LIMIT 1"
            );
    }

    $stmt->bind_param(
        "i",
        $viewId
    );

    $stmt->execute();

    $detail =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    if (!$detail) {
        $viewKind = "";
        $viewId = 0;
        $viewMissing = true;
    } else {
        // Ensure a shareable public tracking token exists for this record.
        $detailTable = pubSubmissionTable($viewKind);

        if ($detailTable !== null) {
            $detailToken = pubEnsurePublicToken($conn, $detailTable, (int) $detail["id"]);

            if ($detailToken !== "") {
                $detail["public_token"] = $detailToken;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Standalone Chat Record (Communication Center lives outside the view modal)
|--------------------------------------------------------------------------
*/

$chatKind =
    trim(
        (string)(
            $_GET["chat"]
            ?? ""
        )
    );

if (
    !in_array(
        $chatKind,
        [
            "event",
            "recognition",
            "contribution"
        ],
        true
    )
) {
    $chatKind = "";
}

$chatId =
    (int)(
        $_GET["chat_id"]
        ?? 0
    );

$chatDetail =
    null;

$chatMissing =
    false;

if (
    $chatKind !== ""
    &&
    $chatId > 0
) {
    $chatTable =
        pubSubmissionTable(
            $chatKind
        );

    if ($chatTable !== null) {
        $stmt =
            $conn->prepare(
                "SELECT
                    p.*,
                    u.name AS submitter_name
                 FROM `{$chatTable}` p
                 LEFT JOIN users u
                    ON u.id = p.submitted_by
                 WHERE p.id = ?
                 LIMIT 1"
            );

        $stmt->bind_param(
            "i",
            $chatId
        );

        $stmt->execute();

        $chatDetail =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$chatDetail) {
            $chatKind = "";
            $chatId = 0;
            $chatMissing = true;
        }
    } else {
        $chatKind = "";
        $chatId = 0;
        $chatMissing = true;
    }
}

/*
|--------------------------------------------------------------------------
| Tracking Progress Data (history ledger + monitoring metrics)
|--------------------------------------------------------------------------
*/
$trackStages = ["For Review", "In Preparation", "For Approval", "Published", "Closed"];
$trackHistory = [];
$trackProgressPct = 0;
$trackStageIndex = 0;
$trackDaysTotal = 0;
$trackDaysInStatus = 0;
$trackPrefLabel = "—";
$trackPrefClass = "track-neutral";

if ($detail) {
    $trackStageIndex = array_search((string)($detail["status"] ?? ""), $trackStages, true);
    if ($trackStageIndex === false) {
        $trackStageIndex = 0;
    }
    $trackProgressPct = (int)round((($trackStageIndex + 1) / count($trackStages)) * 100);

    try {
        $hstmt = $conn->prepare(
            "SELECT h.old_status, h.new_status, h.notes_snapshot, h.created_at,
                    u.name AS actor_name
             FROM publication_status_history h
             LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.kind = ? AND h.publication_id = ?
             ORDER BY h.created_at ASC, h.id ASC"
        );
        $hstmt->bind_param("si", $viewKind, $viewId);
        $hstmt->execute();
        $trackHistory = $hstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $hstmt->close();
    } catch (Throwable $e) {
        $trackHistory = [];
    }

    try {
        $createdTs = strtotime((string)($detail["created_at"] ?? ""));
        $trackDaysTotal = $createdTs ? max(0, (int)floor((time() - $createdTs) / 86400)) : 0;

        $lastChangeTs = null;
        if ($trackHistory) {
            $lastRow = end($trackHistory);
            $lastChangeTs = strtotime((string)($lastRow["created_at"] ?? ""));
        }
        if (!$lastChangeTs) {
            $lastChangeTs = strtotime((string)($detail["reviewed_at"] ?? $detail["created_at"] ?? ""));
        }
        $trackDaysInStatus = $lastChangeTs ? max(0, (int)floor((time() - $lastChangeTs) / 86400)) : 0;

        $prefRaw = trim((string)($detail["preferred_publication_date"] ?? ""));
        if ($prefRaw !== "") {
            $prefTs = strtotime($prefRaw);
            if ($prefTs !== false) {
                $diffDays = (int)floor(($prefTs - strtotime(date("Y-m-d"))) / 86400);
                if (($detail["status"] ?? "") === "Published" || ($detail["status"] ?? "") === "Closed") {
                    $trackPrefLabel = pubDateLabel($prefRaw) . " • done";
                    $trackPrefClass = "track-done";
                } elseif ($diffDays < 0) {
                    $trackPrefLabel = pubDateLabel($prefRaw) . " • overdue " . abs($diffDays) . "d";
                    $trackPrefClass = "track-overdue";
                } elseif ($diffDays === 0) {
                    $trackPrefLabel = pubDateLabel($prefRaw) . " • due today";
                    $trackPrefClass = "track-soon";
                } else {
                    $trackPrefLabel = pubDateLabel($prefRaw) . " • in " . $diffDays . "d";
                    $trackPrefClass = $diffDays <= 7 ? "track-soon" : "track-neutral";
                }
            }
        }
    } catch (Throwable $e) {
    }
}

/*
|--------------------------------------------------------------------------
| Combined Listing Query
|--------------------------------------------------------------------------
*/

$eventWhere = [];
$eventTypes = "";
$eventParams = [];

$recognitionWhere = [];
$recognitionTypes = "";
$recognitionParams = [];

$contributionWhere = [];
$contributionTypes = "";
$contributionParams = [];

if (
    $statusFilter !== ""
) {
    $eventWhere[] =
        "p.status = ?";

    $eventTypes .=
        "s";

    $eventParams[] =
        $statusFilter;

    $recognitionWhere[] =
        "p.status = ?";

    $recognitionTypes .=
        "s";

    $recognitionParams[] =
        $statusFilter;

    $contributionWhere[] =
        "p.status = ?";

    $contributionTypes .=
        "s";

    $contributionParams[] =
        $statusFilter;
}

if (
    $q !== ""
) {
    $like =
        "%"
        . $q
        . "%";

    $eventWhere[] =
        "(
            p.title LIKE ?
            OR p.activity_type LIKE ?
            OR p.email_address LIKE ?
            OR p.lead_office LIKE ?
            OR u.name LIKE ?
        )";

    $eventTypes .=
        "sssss";

    array_push(
        $eventParams,
        $like,
        $like,
        $like,
        $like,
        $like
    );

    $recognitionWhere[] =
        "(
            p.recognized_entity LIKE ?
            OR p.recognition_type LIKE ?
            OR p.achievement LIKE ?
            OR p.email_address LIKE ?
            OR u.name LIKE ?
        )";

    $recognitionTypes .=
        "sssss";

    array_push(
        $recognitionParams,
        $like,
        $like,
        $like,
        $like,
        $like
    );

    $contributionWhere[] =
        "(
            p.content_title LIKE ?
            OR p.contribution_type LIKE ?
            OR p.email_address LIKE ?
            OR p.lead_office LIKE ?
            OR u.name LIKE ?
        )";

    $contributionTypes .=
        "sssss";

    array_push(
        $contributionParams,
        $like,
        $like,
        $like,
        $like,
        $like
    );
}

$eventSql = "
    SELECT
        'event' AS submission_kind,
        p.id,
        p.title AS display_title,
        p.activity_type AS display_type,
        p.email_address,
        p.preferred_publication_date,
        p.status,
        p.created_at,
        p.updated_at,
        p.submitted_by,
        u.name AS submitter_name,
        p.lead_office AS office_label
    FROM publication_event_submissions p
    LEFT JOIN users u
        ON u.id = p.submitted_by
";

if ($eventWhere) {
    $eventSql .=
        " WHERE "
        . implode(
            " AND ",
            $eventWhere
        );
}

$recognitionSql = "
    SELECT
        'recognition' AS submission_kind,
        p.id,
        p.recognized_entity AS display_title,
        p.recognition_type AS display_type,
        p.email_address,
        p.preferred_publication_date,
        p.status,
        p.created_at,
        p.updated_at,
        p.submitted_by,
        u.name AS submitter_name,
        NULL AS office_label
    FROM publication_recognition_submissions p
    LEFT JOIN users u
        ON u.id = p.submitted_by
";

if ($recognitionWhere) {
    $recognitionSql .=
        " WHERE "
        . implode(
            " AND ",
            $recognitionWhere
        );
}

$contributionSql = "
    SELECT
        'contribution' AS submission_kind,
        p.id,
        p.content_title AS display_title,
        p.contribution_type AS display_type,
        p.email_address,
        p.preferred_publication_date,
        p.status,
        p.created_at,
        p.updated_at,
        p.submitted_by,
        u.name AS submitter_name,
        p.lead_office AS office_label
    FROM publication_content_contributions p
    LEFT JOIN users u
        ON u.id = p.submitted_by
";

if ($contributionWhere) {
    $contributionSql .=
        " WHERE "
        . implode(
            " AND ",
            $contributionWhere
        );
}

$rows = [];

if (
    $tab
    ===
    "event"
) {
    $stmt =
        $conn->prepare(
            $eventSql
            . " ORDER BY p.created_at DESC, p.id DESC"
        );

    if (
        $eventTypes
        !==
        ""
    ) {
        $stmt->bind_param(
            $eventTypes,
            ...$eventParams
        );
    }

    $stmt->execute();

    $rows =
        $stmt
            ->get_result()
            ->fetch_all(
                MYSQLI_ASSOC
            );

    $stmt->close();

} elseif (
    $tab
    ===
    "recognition"
) {
    $stmt =
        $conn->prepare(
            $recognitionSql
            . " ORDER BY p.created_at DESC, p.id DESC"
        );

    if (
        $recognitionTypes
        !==
        ""
    ) {
        $stmt->bind_param(
            $recognitionTypes,
            ...$recognitionParams
        );
    }

    $stmt->execute();

    $rows =
        $stmt
            ->get_result()
            ->fetch_all(
                MYSQLI_ASSOC
            );

    $stmt->close();

} elseif (
    $tab
    ===
    "contribution"
) {
    $stmt =
        $conn->prepare(
            $contributionSql
            . " ORDER BY p.created_at DESC, p.id DESC"
        );

    if (
        $contributionTypes
        !==
        ""
    ) {
        $stmt->bind_param(
            $contributionTypes,
            ...$contributionParams
        );
    }

    $stmt->execute();

    $rows =
        $stmt
            ->get_result()
            ->fetch_all(
                MYSQLI_ASSOC
            );

    $stmt->close();

} else {
    /*
     * UNION with independent parameter lists.
     */
    $unionSql =
        $eventSql
        . " UNION ALL "
        . $recognitionSql
        . " UNION ALL "
        . $contributionSql
        . " ORDER BY created_at DESC, id DESC";

    $stmt =
        $conn->prepare(
            $unionSql
        );

    $allTypes =
        $eventTypes
        . $recognitionTypes
        . $contributionTypes;

    $allParams =
        array_merge(
            $eventParams,
            $recognitionParams,
            $contributionParams
        );

    if (
        $allTypes
        !==
        ""
    ) {
        $stmt->bind_param(
            $allTypes,
            ...$allParams
        );
    }

    $stmt->execute();

    $rows =
        $stmt
            ->get_result()
            ->fetch_all(
                MYSQLI_ASSOC
            );

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Dashboard Counts
|--------------------------------------------------------------------------
*/

$eventStats =
    $conn
        ->query(
            "SELECT
                COUNT(*) AS total,
                COALESCE(
                    SUM(status = 'For Review'),
                    0
                ) AS for_review,
                COALESCE(
                    SUM(status = 'In Preparation'),
                    0
                ) AS in_preparation,
                COALESCE(
                    SUM(status = 'For Approval'),
                    0
                ) AS for_approval,
                COALESCE(
                    SUM(status = 'Published'),
                    0
                ) AS published,
                COALESCE(
                    SUM(status = 'Closed'),
                    0
                ) AS closed
             FROM publication_event_submissions"
        )
        ->fetch_assoc()
    ?: [];

$recognitionStats =
    $conn
        ->query(
            "SELECT
                COUNT(*) AS total,
                COALESCE(
                    SUM(status = 'For Review'),
                    0
                ) AS for_review,
                COALESCE(
                    SUM(status = 'In Preparation'),
                    0
                ) AS in_preparation,
                COALESCE(
                    SUM(status = 'For Approval'),
                    0
                ) AS for_approval,
                COALESCE(
                    SUM(status = 'Published'),
                    0
                ) AS published,
                COALESCE(
                    SUM(status = 'Closed'),
                    0
                ) AS closed
             FROM publication_recognition_submissions"
        )
        ->fetch_assoc()
    ?: [];

$contributionStats =
    $conn
        ->query(
            "SELECT
                COUNT(*) AS total,
                COALESCE(
                    SUM(status = 'For Review'),
                    0
                ) AS for_review,
                COALESCE(
                    SUM(status = 'In Preparation'),
                    0
                ) AS in_preparation,
                COALESCE(
                    SUM(status = 'For Approval'),
                    0
                ) AS for_approval,
                COALESCE(
                    SUM(status = 'Published'),
                    0
                ) AS published,
                COALESCE(
                    SUM(status = 'Closed'),
                    0
                ) AS closed
             FROM publication_content_contributions"
        )
        ->fetch_assoc()
    ?: [];

$stats = [
    "total" =>
        (int)(
            $eventStats["total"]
            ?? 0
        )
        +
        (int)(
            $recognitionStats["total"]
            ?? 0
        )
        +
        (int)(
            $contributionStats["total"]
            ?? 0
        ),

    "for_review" =>
        (int)(
            $eventStats["for_review"]
            ?? 0
        )
        +
        (int)(
            $recognitionStats["for_review"]
            ?? 0
        )
        +
        (int)(
            $contributionStats["for_review"]
            ?? 0
        ),

    "in_preparation" =>
        (int)(
            $eventStats["in_preparation"]
            ?? 0
        )
        +
        (int)(
            $recognitionStats["in_preparation"]
            ?? 0
        )
        +
        (int)(
            $contributionStats["in_preparation"]
            ?? 0
        ),

    "for_approval" =>
        (int)(
            $eventStats["for_approval"]
            ?? 0
        )
        +
        (int)(
            $recognitionStats["for_approval"]
            ?? 0
        )
        +
        (int)(
            $contributionStats["for_approval"]
            ?? 0
        ),

    "published" =>
        (int)(
            $eventStats["published"]
            ?? 0
        )
        +
        (int)(
            $recognitionStats["published"]
            ?? 0
        )
        +
        (int)(
            $contributionStats["published"]
            ?? 0
        ),

    "closed" =>
        (int)(
            $eventStats["closed"]
            ?? 0
        )
        +
        (int)(
            $recognitionStats["closed"]
            ?? 0
        )
        +
        (int)(
            $contributionStats["closed"]
            ?? 0
        )
];

/*
| Scope the KPI cards to the active tab so the numbers always match
| the list below (previously they showed global totals on every tab).
*/
if ($tab !== "all") {
    $tabStats =
        $tab === "event"
            ? $eventStats
            : (
                $tab === "recognition"
                    ? $recognitionStats
                    : $contributionStats
            );

    $stats = [
        "total" =>
            (int)(
                $tabStats["total"]
                ?? 0
            ),

        "for_review" =>
            (int)(
                $tabStats["for_review"]
                ?? 0
            ),

        "in_preparation" =>
            (int)(
                $tabStats["in_preparation"]
                ?? 0
            ),

        "for_approval" =>
            (int)(
                $tabStats["for_approval"]
                ?? 0
            ),

        "published" =>
            (int)(
                $tabStats["published"]
                ?? 0
            ),

        "closed" =>
            (int)(
                $tabStats["closed"]
                ?? 0
            ),
    ];
}

$tabTotals = [
    "all" =>
        (int)($stats["total"] ?? 0),

    "event" =>
        (int)(
            $eventStats["total"]
            ?? 0
        ),

    "recognition" =>
        (int)(
            $recognitionStats["total"]
            ?? 0
        ),

    "contribution" =>
        (int)(
            $contributionStats["total"]
            ?? 0
        ),
];

if ($tab !== "all") {
    $tabTotals["all"] =
        (int)(
            $eventStats["total"]
            ?? 0
        )
        +
        (int)(
            $recognitionStats["total"]
            ?? 0
        )
        +
        (int)(
            $contributionStats["total"]
            ?? 0
        );
}

$flash =
    $_SESSION["publication_management_flash"]
    ?? null;

unset(
    $_SESSION["publication_management_flash"]
);

/*
| Stale view/chat links (e.g. a submission deleted after the link was
| copied) previously showed only the bare list, looking broken.
| Explain what happened instead.
*/
if (
    !$flash
    &&
    (
        $viewMissing
        ||
        $chatMissing
    )
) {
    $flash = [
        "type" =>
            "warning",

        "message" =>
            "That submission is no longer available — it may have been deleted. Showing the current list instead."
    ];
}

/*
|--------------------------------------------------------------------------
| Conversation Unread Counts (one query for every Chat button badge)
|--------------------------------------------------------------------------
*/

$chatUnread = [];

try {
    $hasPubMessages =
        $conn->query(
            "SHOW TABLES LIKE 'publication_messages'"
        );

    if (
        $hasPubMessages
        &&
        $hasPubMessages->num_rows > 0
    ) {
        $unreadStmt =
            $conn->prepare(
                "SELECT
                    m.kind,
                    m.publication_id,
                    COUNT(*) AS unread
                 FROM publication_messages m
                 LEFT JOIN publication_message_reads r
                    ON r.kind = m.kind
                    AND r.publication_id = m.publication_id
                    AND r.user_id = ?
                 WHERE m.id > COALESCE(
                     r.last_read_message_id,
                     0
                 )
                 GROUP BY
                    m.kind,
                    m.publication_id"
            );

        if ($unreadStmt) {
            $unreadStmt->bind_param(
                "i",
                $userId
            );

            $unreadStmt->execute();

            foreach (
                $unreadStmt
                    ->get_result()
                    ->fetch_all(MYSQLI_ASSOC)
                as $unreadRow
            ) {
                $chatUnread[
                    (string)$unreadRow["kind"]
                    . ":"
                    . (int)$unreadRow["publication_id"]
                ] =
                    (int)$unreadRow["unread"];
            }

            $unreadStmt->close();
        }
    }
} catch (Throwable $e) {
    $chatUnread = [];
}

/*
| Production team for every visible card, in one query.
| key "kind:id" => [role => ["user_id", "name"]].
*/
$teamPairs = [];

foreach ($rows as $teamRow) {
    $teamPairs[] = [
        (string)($teamRow["submission_kind"] ?? ""),
        (int)($teamRow["id"] ?? 0),
    ];
}

$teamMap = pubTaskAssignmentsBatch($conn, $teamPairs);
$teamRoleMeta = pubTaskRoles();

/*
| Material approvals for every visible card, in one query.
| key "kind:id" => [role => ["approved_by", "approver_name", "approved_at"]].
*/
$approvalMap = pubTaskApprovalsBatch($conn, $teamPairs);

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
    Publication Requests | HPU Workbench
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
    href="<?= e(BASE_URL) ?>/assets/css/request_chat.css"
>

<style>
:root{
    --pub-bg:#f4f7fb;
    --pub-border:#dfe6ef;
    --pub-text:#172033;
    --pub-muted:#667085;
    --pub-primary:#2563eb;
    --pub-shadow:0 8px 24px rgba(28,42,72,.05);
}

body{
    background:var(--pub-bg);
    color:var(--pub-text);
}

.content{
    padding:18px;
}

.container-fluid{
    max-width:1680px;
    margin:0 auto;
}

.kpi-grid{
    display:grid;
    grid-template-columns:repeat(6,minmax(0,1fr));
    gap:8px;
    margin-bottom:10px;
}

.kpi-card{
    padding:8px 10px;
    border:1px solid var(--pub-border);
    border-radius:11px;
    background:#fff;
    box-shadow:var(--pub-shadow);
}

.kpi-label{
    color:#667085;
    font-size:.53rem;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.04em;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.kpi-value{
    margin-top:2px;
    font-size:1.15rem;
    line-height:1;
    font-weight:900;
}

.kpi-card{
    transition:background .15s ease;
}

.kpi-card.status-for_review{
    border-color:#fecaca;
    background:#fef2f2;
}

.kpi-card.status-in_preparation{
    border-color:#bfdbfe;
    background:#eff6ff;
}

.kpi-card.status-for_approval{
    border-color:#ddd6fe;
    background:#f5f3ff;
}

.kpi-card.status-closed{
    border-color:#d8dee7;
    background:#f3f5f8;
}

.kpi-card.status-published{
    border-color:#d1e7dd;
    background:#d1e7dd;
    color:#0f1721;
}

.kpi-card.status-for_review .kpi-value,
.kpi-card.status-for_review .kpi-label,
.kpi-card.status-for_review .kpi-note{
    color:#b91c1c;
}

.kpi-card.status-in_preparation .kpi-value,
.kpi-card.status-in_preparation .kpi-label,
.kpi-card.status-in_preparation .kpi-note{
    color:#1e40af;
}

.kpi-card.status-for_approval .kpi-value,
.kpi-card.status-for_approval .kpi-label,
.kpi-card.status-for_approval .kpi-note{
    color:#6d28d9;
}

.kpi-card.status-closed .kpi-value,
.kpi-card.status-closed .kpi-label,
.kpi-card.status-closed .kpi-note{
    color:#475569;
}

.kpi-card.status-published .kpi-value,
.kpi-card.status-published .kpi-label,
.kpi-card.status-published .kpi-note{
    color:#0f1721;
}

.kpi-note{
    margin-top:2px;
    color:#98a2b3;
    font-size:.55rem;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.workspace{
    display:grid;
    grid-template-columns:minmax(0,1fr);
    gap:14px;
    align-items:start;
}

.list-card{
    border:1px solid var(--pub-border);
    border-radius:14px;
    background:#fff;
    box-shadow:var(--pub-shadow);
    overflow:hidden;
}

.detail-card{
    border:1px solid var(--pub-border);
    border-radius:14px;
    background:#fff;
    box-shadow:var(--pub-shadow);
    overflow:hidden;
}

/* View popup modal — upgraded: sticky glass header, gradient hero, card sections */
#viewSubmissionModal .modal-dialog{
    max-width:min(1180px, calc(100vw - 24px));
}
#viewSubmissionModal .modal-content{
    border:1px solid #dbe4f0;
    border-radius:20px;
    overflow:hidden;
    box-shadow:0 24px 70px rgba(15,23,42,.22);
}
#viewSubmissionModal .modal-header{
    position:sticky;
    top:0;
    z-index:20;
    padding:12px 18px;
    background:rgba(255,255,255,.92);
    backdrop-filter:blur(10px);
    -webkit-backdrop-filter:blur(10px);
    border-bottom:1px solid #e6ecf4;
}
#viewSubmissionModal .modal-title{
    font-size:.88rem;
    font-weight:850;
    color:#0f172a;
    display:flex;
    align-items:center;
    gap:10px;
    min-width:0;
    flex:1;
}
#viewSubmissionModal .modal-title .vm-title-icon{
    display:grid;
    width:34px;
    height:34px;
    flex:0 0 34px;
    place-items:center;
    border-radius:10px;
    background:linear-gradient(135deg,#2563eb,#4f46e5);
    color:#fff;
    font-size:15px;
    box-shadow:0 4px 12px rgba(37,99,235,.3);
}
#viewSubmissionModal .modal-title .vm-title-icon.is-recognition{
    background:linear-gradient(135deg,#f59e0b,#dc2626);
    box-shadow:0 4px 12px rgba(234,88,12,.3);
}
#viewSubmissionModal .modal-title .vm-title-icon.is-contribution{
    background:linear-gradient(135deg,#10b981,#047857);
    box-shadow:0 4px 12px rgba(5,150,105,.3);
}
#viewSubmissionModal .modal-title .vm-title-text{
    min-width:0;
    line-height:1.3;
}
#viewSubmissionModal .modal-title .vm-title-text small{
    display:block;
    color:#64748b;
    font-size:.66rem;
    font-weight:700;
}
#viewSubmissionModal .modal-title .vm-title-text strong{
    display:block;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    max-width:44ch;
}
#viewSubmissionModal .modal-body{
    padding:0;
    background:#f3f6fb;
    max-height:calc(100vh - 190px);
    overflow-y:auto;
    overscroll-behavior:contain;
    scrollbar-width:thin;
}
#viewSubmissionModal .modal-body::-webkit-scrollbar{width:10px;}
#viewSubmissionModal .modal-body::-webkit-scrollbar-thumb{border-radius:999px;background:#c3cfdf;border:2px solid #f3f6fb;}
#viewSubmissionModal .modal-body::-webkit-scrollbar-track{background:transparent;}
#viewSubmissionModal .detail-hero{
    border-bottom:1px solid #e6ecf4;
    background:
        radial-gradient(700px 160px at 12% -40px, rgba(37,99,235,.10), transparent 60%),
        linear-gradient(180deg,#ffffff 0%,#f6f9ff 100%);
}
#viewSubmissionModal .detail-body{
    padding:16px 18px 18px;
    max-height:none;
    overflow:visible;
    display:flex;
    flex-direction:column;
    gap:14px;
}
/* Quick navigation pill bar inside view modal */
.vm-nav-bar {
    position: sticky;
    top: 0;
    z-index: 15;
    background: rgba(255, 255, 255, 0.94);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    padding: 8px 14px;
    margin: -16px -18px 4px -18px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 6px;
    overflow-x: auto;
    white-space: nowrap;
    scrollbar-width: none;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
}
.vm-nav-bar::-webkit-scrollbar { display: none; }
.vm-nav-item {
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 0.74rem;
    font-weight: 700;
    color: #64748b;
    background: #f1f5f9;
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.vm-nav-item:hover, .vm-nav-item.active {
    color: #2563eb;
    background: #eff6ff;
    border-color: #bfdbfe;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.12);
}
.flash-focus {
    animation: vmFlashFocus 1.4s ease-out;
}
@keyframes vmFlashFocus {
    0% { outline: 3px solid #3b82f6; box-shadow: 0 0 20px rgba(59, 130, 246, 0.4); }
    100% { outline: 3px solid transparent; box-shadow: none; }
}
#viewSubmissionModal .alert{
    margin:14px 18px 0;
    border-radius:12px;
    font-size:.78rem;
}
#viewSubmissionModal .modal-footer{
    position:sticky;
    bottom:0;
    z-index:20;
    background:rgba(255,255,255,.96);
    backdrop-filter:blur(10px);
    -webkit-backdrop-filter:blur(10px);
    border-top:1px solid #e6ecf4;
    padding:10px 18px;
    box-shadow:0 -8px 24px rgba(15,23,42,.06);
}
.vm-code-chip{
    display:inline-flex;
    align-items:center;
    gap:4px;
    padding:4px 9px;
    border-radius:8px;
    background:#0f172a;
    color:#fff;
    font-size:.64rem;
    font-weight:800;
    letter-spacing:.02em;
    white-space:nowrap;
}
.vm-close-btn{
    flex:0 0 auto;
    border-radius:10px;
}
@media(max-width:650px){
    #viewSubmissionModal .modal-title .vm-title-text strong{max-width:22ch;}
    #viewSubmissionModal .modal-body{max-height:calc(100vh - 160px);}
}

/* Tracking workflow progress monitoring */
.track-progress-wrap{
    margin:0 0 12px;
    padding:12px;
    border:1px solid #dbeafe;
    border-radius:12px;
    background:linear-gradient(180deg,#f5f9ff,#fff);
}

.track-progress-top{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:8px;
}

.track-progress-label{
    font-size:.68rem;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.05em;
    color:#1d4ed8;
}

.track-progress-pct{
    font-size:.78rem;
    font-weight:900;
    color:#1d2939;
}

.track-progress-bar{
    height:10px;
    border-radius:999px;
    background:#e5ebf3;
    overflow:hidden;
}

.track-progress-fill{
    height:100%;
    border-radius:999px;
    background:linear-gradient(90deg,#2563eb,#16a34a);
    transition:width .3s ease;
}

.track-monitor-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:8px;
    margin-top:10px;
}

.track-monitor-item{
    padding:8px 10px;
    border:1px solid #edf1f5;
    border-radius:9px;
    background:#fff;
}

.track-monitor-item .detail-label{
    font-size:.58rem;
}

.track-monitor-item .detail-value{
    font-size:.78rem;
}

.track-neutral{color:#475467;}
.track-done{color:#027a48;}
.track-soon{color:#b54708;}
.track-overdue{color:#b42318;font-weight:800;}

.track-timeline{
    display:flex;
    flex-direction:column;
    gap:0;
    margin-top:4px;
}

.track-event{
    position:relative;
    padding:0 0 14px 26px;
}

.track-event:last-child{
    padding-bottom:2px;
}

.track-event::before{
    content:"";
    position:absolute;
    left:8px;
    top:20px;
    bottom:0;
    width:2px;
    background:#e5ebf3;
}

.track-event:last-child::before{
    display:none;
}

.track-dot{
    position:absolute;
    left:0;
    top:2px;
    width:18px;
    height:18px;
    border-radius:50%;
    display:grid;
    place-items:center;
    background:#2563eb;
    color:#fff;
    font-size:.6rem;
}

.track-event.is-first .track-dot{
    background:#98a2b3;
}

.track-event-head{
    display:flex;
    align-items:center;
    gap:6px;
    flex-wrap:wrap;
    font-size:.76rem;
    font-weight:800;
    color:#1d2939;
}

.track-arrow{
    color:#98a2b3;
    font-weight:400;
}

.track-meta{
    margin-top:2px;
    font-size:.68rem;
    color:#667085;
}

.track-notes{
    margin-top:6px;
    padding:8px 10px;
    border-radius:8px;
    background:#fafcff;
    border:1px solid #eef2f6;
    font-size:.74rem;
    color:#344054;
    white-space:pre-wrap;
    overflow-wrap:anywhere;
}

.track-empty{
    padding:10px 12px;
    border:1px dashed #d0d5dd;
    border-radius:9px;
    color:#667085;
    font-size:.76rem;
    background:#fcfcfd;
}

.mini-progress{
    height:6px;
    border-radius:999px;
    background:#eef2f6;
    overflow:hidden;
    margin-top:10px;
}

.mini-progress-fill{
    height:100%;
    border-radius:999px;
    background:linear-gradient(90deg,#2563eb,#16a34a);
}

@media (max-width:700px){
    .track-monitor-grid{grid-template-columns:1fr 1fr;}
}

/* Stacked modals (Edit/Delete/Preview/Chat opened from View popup):
   keep 2nd-level above view + backdrop, avoid flicker/behind-backdrop glitch */
#editSubmissionModal,
#deleteSubmissionModal,
#filePreviewModal,
#renameFileModal,
#chatModal{
    z-index:1070 !important;
}

.modal-backdrop.stacked{
    z-index:1065 !important;
}

.toolbar{
    padding:10px 12px;
    border-bottom:1px solid #edf1f5;
    background:#fff;
}

.tab-row{
    display:flex;
    gap:6px;
    flex-wrap:nowrap;
    overflow-x:auto;
    margin-bottom:10px;
    padding-bottom:2px;
    scrollbar-width:thin;
}

.tab-link{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:6px 11px;
    border:1px solid #dfe6ef;
    border-radius:999px;
    color:#475467;
    text-decoration:none;
    font-size:.66rem;
    font-weight:850;
    background:#fff;
    white-space:nowrap;
    flex-shrink:0;
}

.tab-link:hover,
.tab-link.active{
    border-color:#93c5fd;
    background:#eff6ff;
    color:#1d4ed8;
}

.request-table-wrap{
    overflow-x:auto;
}

.submission-list{
    display:flex;
    flex-direction:column;
    gap:8px;
    padding:10px;
}

.submission-card{
    padding:11px 13px;
    border:1px solid #e5ebf3;
    border-radius:12px;
    background:#fff;
    transition:border-color .15s ease, background .15s ease;
}

.submission-card:hover{
    border-color:#93c5fd;
    background:#fbfdff;
}

.submission-card.active{
    border-color:#2563eb;
    background:#f5f9ff;
    box-shadow:0 0 0 3px rgba(37,99,235,.12);
}

.submission-top{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
    margin-bottom:10px;
}

.submission-top .spacer{
    flex:1;
}

.submission-date{
    color:#98a2b3;
    font-size:.68rem;
    font-weight:650;
    white-space:nowrap;
}

.request-title{
    color:#1d2939;
    font-size:.95rem;
    font-weight:850;
    line-height:1.35;
    overflow-wrap:anywhere;
}

.request-sub{
    margin-top:3px;
    color:#667085;
    font-size:.74rem;
    line-height:1.45;
}

.submission-meta{
    display:flex;
    flex-direction:column;
    gap:6px;
    margin:12px 0 4px;
    padding:12px;
    border:1px solid #eef2f6;
    border-radius:10px;
    background:#fafcff;
    color:#475467;
    font-size:.76rem;
}

.submission-meta > div{
    display:flex;
    gap:8px;
    align-items:flex-start;
    min-width:0;
}

.submission-meta i{
    color:#98a2b3;
    margin-top:2px;
    flex:0 0 auto;
}

.submission-meta span{
    min-width:0;
    overflow-wrap:anywhere;
}

.submission-foot{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-top:12px;
    padding-top:12px;
    border-top:1px dashed #e5ebf3;
}

.submission-foot .btn{
    font-weight:800;
}

.result-count{
    margin:0 14px 0;
    padding:10px 0 0;
    color:#667085;
    font-size:.7rem;
    font-weight:700;
}

.kind-pill,
.status-pill{
    display:inline-flex;
    align-items:center;
    gap:4px;
    padding:5px 10px;
    border-radius:999px;
    font-size:.64rem;
    font-weight:850;
    white-space:nowrap;
}

.kind-event{
    background:#eff6ff;
    color:#1d4ed8;
}

.kind-recognition{
    background:#fff7ed;
    color:#b54708;
}

.kind-contribution{
    background:#f0fdf4;
    color:#065f46;
}

.status-review{
    background:#fff7ed;
    color:#b54708;
}

.status-preparation{
    background:#eff6ff;
    color:#1d4ed8;
}

.status-approval{
    background:#f5f3ff;
    color:#6d28d9;
}

.status-published{
    background:#ecfdf3;
    color:#027a48;
}

.status-closed{
    background:#f2f4f7;
    color:#667085;
}

.detail-card{
    position:sticky;
    top:14px;
}

.detail-hero{
    overflow:hidden;
    border-bottom:1px solid #e6ecf4;
}
.detail-hero-main{
    display:flex;
    gap:14px;
    align-items:flex-start;
    padding:18px 20px 14px;
}
.detail-kind-icon{
    display:grid;
    width:52px;
    height:52px;
    flex:0 0 52px;
    place-items:center;
    border-radius:15px;
    background:linear-gradient(135deg,#2563eb,#4f46e5 60%,#7c3aed);
    color:#fff;
    font-size:22px;
    box-shadow:0 8px 18px rgba(37,99,235,.3), inset 0 1px 0 rgba(255,255,255,.25);
}
.detail-kind-icon.recognition{
    background:linear-gradient(135deg,#f59e0b,#ea580c 60%,#dc2626);
    box-shadow:0 8px 18px rgba(234,88,12,.3), inset 0 1px 0 rgba(255,255,255,.25);
}
.detail-kind-icon.contribution{
    background:linear-gradient(135deg,#10b981,#059669 60%,#047857);
    box-shadow:0 8px 18px rgba(5,150,105,.3), inset 0 1px 0 rgba(255,255,255,.25);
}
.detail-eyebrow{
    margin:0 0 4px;
    color:#1d4ed8;
    font-size:.66rem;
    font-weight:900;
    letter-spacing:.09em;
    text-transform:uppercase;
    display:flex;
    align-items:center;
    gap:6px;
}
.detail-eyebrow::after{
    content:"";
    height:1px;
    width:34px;
    background:linear-gradient(90deg,#93c5fd,transparent);
}
.detail-hero h2{
    margin:0;
    color:#0f172a;
    font-size:1.32rem;
    font-weight:900;
    letter-spacing:-.02em;
    line-height:1.25;
    overflow-wrap:anywhere;
}
.detail-hero-sub{
    margin:6px 0 0;
    color:#64748b;
    font-size:.76rem;
    display:flex;
    align-items:center;
    gap:6px;
    flex-wrap:wrap;
}
.detail-hero-sub strong{color:#334155;font-weight:800;}
.detail-hero-chips{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    margin-top:10px;
}
.detail-code-chip{
    display:inline-flex;
    align-items:center;
    gap:4px;
    padding:5px 10px;
    border-radius:8px;
    background:#e8f1ff;
    border:1px solid #c9dcff;
    color:#1d4ed8;
    font-size:.66rem;
    font-weight:800;
}
.vm-track-card{
    margin:12px 20px 0;
    padding:10px 12px;
    border:1px solid #dbeafe;
    border-radius:13px;
    background:linear-gradient(180deg,#f5f9ff,#fff);
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
}
.vm-track-card .vm-track-icon{
    display:grid;
    width:32px;
    height:32px;
    flex:0 0 32px;
    place-items:center;
    border-radius:10px;
    background:#2563eb;
    color:#fff;
    font-size:14px;
}
.vm-track-card input{
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    font-size:.7rem;
}
.vm-track-hint{
    margin:6px 20px 0;
    color:#64748b;
    font-size:.66rem;
}
.detail-head-actions{
    display:flex;
    align-items:center;
    gap:8px;
    padding:12px 20px 14px 86px;
    flex-wrap:wrap;
}
.detail-head-actions .btn{
    border-radius:10px;
    font-weight:800;
    box-shadow:0 1px 2px rgba(15,23,42,.05);
}
.detail-head-actions .btn:hover{transform:translateY(-1px);}
.detail-meta-strip{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:10px;
    padding:14px 20px;
    border-top:1px solid #e6ecf4;
    background:#fff;
}
.detail-meta-item{
    min-width:0;
    padding:10px 12px;
    border:1px solid #e6ecf4;
    border-radius:12px;
    background:linear-gradient(180deg,#fbfdff,#fff);
    transition:border-color .15s ease, box-shadow .15s ease;
}
.detail-meta-item:hover{border-color:#93c5fd;box-shadow:0 4px 14px rgba(37,99,235,.08);}
.detail-meta-item .detail-label{
    margin-bottom:3px;
    display:flex;
    align-items:center;
    gap:5px;
}
.detail-meta-item .detail-value{
    font-size:.79rem;
    font-weight:750;
}
.detail-stepper{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:0;
    padding:14px 20px 16px;
    border-top:1px solid #e6ecf4;
    background:linear-gradient(180deg,#fbfcfe,#f3f6fb);
}
.detail-step{
    position:relative;
    text-align:center;
    min-width:0;
}
.detail-step::before{
    content:"";
    position:absolute;
    top:14px;
    left:-50%;
    width:100%;
    height:2px;
    background:#dbe3ef;
}
.detail-step:first-child::before{display:none;}
.detail-step.done::before{background:#22c55e;}
.detail-step-dot{
    position:relative;
    z-index:1;
    width:28px;
    height:28px;
    display:grid;
    place-items:center;
    margin:0 auto 6px;
    border:2px solid #cbd5e1;
    border-radius:50%;
    background:#fff;
    color:transparent;
    font-size:.6rem;
    transition:.18s ease;
}
.detail-step.done .detail-step-dot{
    border-color:#16a34a;
    background:#16a34a;
    color:#fff;
    box-shadow:0 2px 8px rgba(22,163,74,.35);
}
.detail-step.current .detail-step-dot{
    border-color:#2563eb;
    background:#2563eb;
    color:#fff;
    box-shadow:0 0 0 5px #dbeafe, 0 4px 12px rgba(37,99,235,.35);
}
.detail-step-label{
    color:#94a3b8;
    font-size:.6rem;
    font-weight:850;
    text-transform:uppercase;
    letter-spacing:.04em;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.detail-step.done .detail-step-label{color:#15803d;}
.detail-step.current .detail-step-label{color:#1d4ed8;}
.detail-body{
    padding:0;
    max-height:none;
    overflow:visible;
}
.detail-section{
    margin-bottom:0;
    border:1px solid #e2e8f0;
    border-radius:15px;
    background:#fff;
    overflow:hidden;
    box-shadow:0 2px 10px rgba(15,23,42,.03);
}

.detail-section-head{
    display:flex;
    align-items:center;
    gap:10px;
    padding:12px 14px;
    border-bottom:1px solid #e6ecf4;
    background:linear-gradient(90deg,#eef4ff,#fff);
}
.detail-section-head i{
    display:grid;
    width:30px;
    height:30px;
    place-items:center;
    flex:0 0 30px;
    border-radius:9px;
    background:#2563eb;
    color:#fff;
    font-size:.85rem;
    box-shadow:0 3px 8px rgba(37,99,235,.25);
}
.detail-section-title{
    margin:0;
    color:#0f172a;
    font-size:.74rem;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.06em;
}
.detail-section-title small{
    display:block;
    color:#64748b;
    font-size:.64rem;
    font-weight:700;
    text-transform:none;
    letter-spacing:0;
}
.detail-section-body{
    padding:14px;
}

/* Hide toggle for the Publication Workflow (status flow) section */
#sec-workflow .section-hide-btn{
    display:inline-flex;
    align-items:center;
    gap:5px;
    border:1px solid #dbe4f0;
    border-radius:8px;
    font-size:.68rem;
    font-weight:800;
    color:#475569;
    padding:4px 10px;
}

#sec-workflow .section-hide-btn:hover{
    border-color:#93c5fd;
    color:#1d4ed8;
    background:#eff6ff;
}

#sec-workflow .section-hide-btn i{
    display:inline;
    width:auto;
    height:auto;
    place-items:normal;
    background:none;
    color:inherit;
    font-size:.7rem;
    box-shadow:none;
}

#sec-workflow.is-hidden .detail-section-body{
    display:none;
}

#sec-workflow.is-hidden .detail-section-head{
    border-bottom:0;
}

#sec-workflow.is-hidden .section-hide-btn i{
    transform:rotate(180deg);
}
.detail-field-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:10px;
}
.detail-field{
    min-width:0;
    padding:10px 12px;
    border:1px solid #e6ecf4;
    border-radius:11px;
    background:linear-gradient(180deg,#fbfdff,#fff);
}
.detail-field.full{
    grid-column:1 / -1;
}
.detail-label{
    color:#8a94a6;
    font-size:.6rem;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.06em;
}
.detail-value{
    margin-top:4px;
    color:#0f172a;
    font-size:.84rem;
    font-weight:600;
    line-height:1.6;
    white-space:pre-wrap;
    overflow-wrap:anywhere;
}
.detail-narrative{
    padding:12px 14px;
    border:1px solid #e2e8f0;
    border-left:4px solid #2563eb;
    border-radius:11px;
    background:linear-gradient(180deg,#fbfdff,#fff);
    margin-bottom:10px;
    box-shadow:0 1px 4px rgba(15,23,42,.03);
}
.detail-narrative:last-child{
    margin-bottom:0;
}
/* Side-by-side Production Team + Workflow on wide modal */
.detail-duo{
    display:grid;
    grid-template-columns:minmax(0,1fr) minmax(0,1fr);
    gap:14px;
    align-items:start;
}
.detail-duo .detail-section{height:100%;}
.detail-duo-col{
    display:flex;
    flex-direction:column;
    gap:14px;
    min-width:0;
}
.detail-duo-col .detail-section{height:auto;}
@media(max-width:900px){
    .detail-duo{grid-template-columns:1fr;}
    .detail-meta-strip{grid-template-columns:1fr 1fr;}
    .detail-field-grid{grid-template-columns:1fr;}
}
@media(max-width:650px){
    .detail-meta-strip{grid-template-columns:1fr;}
    .detail-head-actions{padding:12px 14px 14px;}
    .detail-hero-main{padding:16px 14px 12px;}
    .vm-track-card{margin:10px 14px 0;}
    .vm-track-hint{margin:6px 14px 0;}
    .detail-stepper{padding:12px 10px 14px;overflow-x:auto;}
    .detail-step-label{font-size:.54rem;}
}

.file-item{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    padding:10px;
    margin-bottom:8px;
    border:1px solid #e2e8f0;
    border-radius:11px;
    background:#f8fafc;
    transition:.15s ease;
}

.file-item:hover{
    border-color:#93c5fd;
    background:#fff;
    box-shadow:0 4px 12px rgba(37,99,235,.08);
}

.file-type-badge{
    display:grid;
    width:34px;
    height:34px;
    flex:0 0 34px;
    place-items:center;
    border-radius:9px;
    background:#e8f1ff;
    color:#1d4ed8;
    font-size:1rem;
}

.file-type-badge.is-pdf{background:#fee2e2;color:#b42318;}
.file-type-badge.is-doc{background:#e0eaff;color:#1d4ed8;}
.file-type-badge.is-img{background:#dcfae6;color:#067647;}
.file-type-badge.is-vid{background:#ede9fe;color:#6d28d9;}

.file-main{
    display:flex;
    align-items:center;
    gap:9px;
    min-width:0;
    flex:1;
}

.file-name{
    min-width:0;
    color:#1d2939;
    font-size:.78rem;
    font-weight:750;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.file-meta{
    color:#98a2b3;
    font-size:.66rem;
}

.file-actions{
    display:flex;
    align-items:center;
    gap:4px;
    flex:0 0 auto;
}

.file-actions .btn{
    padding:4px 8px;
    font-size:.7rem;
}

.btn-icon-danger{
    color:#b42318;
    border-color:#fecdca;
    background:#fff;
}

.btn-icon-danger:hover{
    background:#fef3f2;
    border-color:#f04438;
    color:#7a271a;
}

.add-files-box{
    margin-top:8px;
    padding:9px 10px;
    border:1px dashed #bfdbfe;
    border-radius:10px;
    background:#f8fbff;
}

.add-files-box .form-control{
    font-size:.68rem;
}

#editSubmissionModal .modal-body .detail-section{
    margin-bottom:12px;
}

#editSubmissionModal .modal-body .detail-section:last-child{
    margin-bottom:0;
}

#editSubmissionModal textarea[data-autogrow]{
    min-height:110px;
    line-height:1.6;
    field-sizing:content;
}

#editSubmissionModal textarea:not([data-autogrow]){
    line-height:1.55;
}

#editSubmissionModal .modal-body{
    max-height:calc(100vh - 230px);
    overflow-y:auto;
    overscroll-behavior:contain;
}

#editSubmissionModal .modal-body::-webkit-scrollbar{
    width:10px;
}

#editSubmissionModal .modal-body::-webkit-scrollbar-thumb{
    border-radius:999px;
    background:#c3cfdf;
    border:2px solid #fff;
}

#editSubmissionModal .modal-body::-webkit-scrollbar-track{
    background:#f7f9fc;
}

#editSubmissionModal .modal-header,
#editSubmissionModal .modal-footer{
    position:sticky;
    z-index:5;
    background:#fff;
}

#editSubmissionModal .modal-header{
    top:0;
}

#editSubmissionModal .modal-footer{
    bottom:0;
    border-top:1px solid #e5eaf1;
    box-shadow:0 -6px 16px rgba(15,23,42,.06);
}

.edit-context{
    position:sticky;
    top:0;
    z-index:4;
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
    margin-bottom:12px;
    padding:9px 12px;
    border:1px solid #e2e8f0;
    border-radius:10px;
    background:#f8fbff;
    font-size:.74rem;
    color:#475467;
}

.file-preview-frame{
    width:100%;
    height:65vh;
    border:0;
    border-radius:8px;
    background:#f8fafc;
}

.file-preview-img{
    width:100%;
    max-height:65vh;
    object-fit:contain;
    border-radius:8px;
    background:#f8fafc;
}

.file-preview-video-wrap{
    position:relative;
    border-radius:8px;
    overflow:hidden;
    background:#0f172a;
}

.file-preview-video{
    height:auto;
    max-height:62vh;
    background:#0f172a;
}

.file-preview-time{
    position:absolute;
    top:10px;
    right:10px;
    display:inline-flex;
    align-items:center;
    padding:4px 10px;
    border-radius:999px;
    background:rgba(15,23,42,.78);
    color:#fff;
    font-size:.68rem;
    font-weight:750;
    font-variant-numeric:tabular-nums;
    pointer-events:none;
}

.file-preview-time.is-error{
    background:rgba(180,35,24,.92);
}

.workflow-box{
    padding:12px;
    border:1px solid #bfdbfe;
    border-radius:12px;
    background:linear-gradient(180deg,#f8fbff,#fff);
}

.workflow-box .btn-primary{
    font-weight:800;
}

.team-box{
    padding:4px 2px;
}

.team-row{
    display:grid;
    grid-template-columns:38px minmax(0,1fr);
    gap:10px;
    align-items:start;
    padding:10px 4px;
    border-bottom:1px solid #eef2f7;
}

.team-row:last-child{
    border-bottom:0;
}

.team-role-icon{
    display:grid;
    width:38px;
    height:38px;
    place-items:center;
    border-radius:11px;
    background:#eef4ff;
    color:#1d4ed8;
    font-size:1.05rem;
}

.team-role-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    flex-wrap:wrap;
}

.team-role-label{
    font-size:.78rem;
    font-weight:850;
}

.team-role-desc{
    color:#667085;
    font-size:.68rem;
}

.team-assignee{
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:2px 9px;
    border-radius:999px;
    background:#dcfce7;
    color:#166534;
    font-size:.66rem;
    font-weight:850;
    white-space:nowrap;
}

.team-assignee.is-empty{
    background:#f1f5f9;
    color:#64748b;
}

.team-select{
    margin-top:7px;
}

.team-approval-summary{
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom:10px;
    padding:9px 12px;
    border:1px solid #bbf7d0;
    border-radius:11px;
    background:#f0fdf4;
    color:#14532d;
    font-size:.72rem;
    font-weight:800;
}
.team-approval-summary i{color:#16a34a;font-size:.9rem;}
.team-approval-summary.is-none{
    border-color:#dbeafe;
    background:#eff6ff;
    color:#1d4ed8;
}
.team-approval-summary.is-none i{color:#2563eb;}

.team-approval-block{
    margin-top:12px;
    border:1px solid #dbeafe;
    border-radius:13px;
    background:linear-gradient(180deg,#f8fbff,#fff);
    overflow:hidden;
}
.team-approval-head{
    display:flex;
    align-items:center;
    gap:10px;
    padding:11px 12px;
    border-bottom:1px solid #dbeafe;
    background:#eff6ff;
}
.team-approval-head > i{
    display:grid;
    width:32px;
    height:32px;
    flex:0 0 32px;
    place-items:center;
    border-radius:10px;
    background:#16a34a;
    color:#fff;
    font-size:15px;
}
.team-approval-head strong{display:block;color:#0f172a;font-size:.78rem;}
.team-approval-head span{display:block;color:#475569;font-size:.66rem;}

.team-approval-row{
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 12px;
    border-bottom:1px solid #edf1f5;
    background:#fff;
}
.team-approval-row:last-child{border-bottom:0;}
.team-approval-row.is-approved{background:linear-gradient(180deg,#f0fdf4,#fff);}
.team-role-icon.sm{
    width:34px;
    height:34px;
    flex:0 0 34px;
    font-size:.95rem;
    border-radius:10px;
}
.team-approved-badge,
.team-pending-badge{
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:4px 10px;
    border-radius:999px;
    font-size:.64rem;
    font-weight:850;
    white-space:nowrap;
    flex-shrink:0;
}
.team-approved-badge{background:#dcfce7;color:#166534;}
.team-pending-badge{background:#fef3c7;color:#92400e;}
.team-approved-meta{
    display:flex;
    align-items:center;
    gap:5px;
    margin-top:3px;
    color:#15803d;
    font-size:.66rem;
    font-weight:700;
    flex-wrap:wrap;
}
.team-approved-meta.muted{color:#94a3b8;font-weight:650;}
.team-approval-btn{border-radius:9px;font-weight:800;white-space:nowrap;}
.team-approval-btn.btn-success{box-shadow:0 3px 10px rgba(22,163,74,.25);}
.team-approval-btn.btn-success:hover{transform:translateY(-1px);}
.team-approval-row.is-stacked{display:block;}
.team-role-files{border-top:1px solid #edf1f5;margin-top:10px;padding-top:10px;}
.team-role-files-title{
    display:flex;align-items:center;gap:6px;
    margin-bottom:8px;color:#344054;font-size:.7rem;font-weight:850;
    text-transform:uppercase;letter-spacing:.04em;
}
.team-role-files-title i{color:#2563eb;}
.team-role-files .file-item{margin-bottom:8px;}
.team-role-files-empty{
    padding:8px 10px;margin-bottom:8px;
    border:1px dashed #cbd5e1;border-radius:9px;background:#f8fafc;
    color:#94a3b8;font-size:.7rem;font-style:italic;
}
.team-role-upload-form{display:flex;flex-direction:column;gap:8px;}
.team-role-upload{display:flex;gap:8px;align-items:center;}
.team-role-upload .form-control{flex:1;min-width:0;}
.team-role-drive .input-group-text{background:#eff6ff;color:#1d4ed8;}
.file-type-badge.is-drive{background:#e8f1ff;color:#1d4ed8;}
.team-other-files{
    margin-top:12px;padding:10px 12px;
    border:1px dashed #bfdbfe;border-radius:12px;background:#f8fbff;
}
.team-other-files .file-item{background:#fff;}
.workflow-status-desc{
    margin-top:6px;padding:8px 10px;
    border-radius:9px;background:#eff6ff;border:1px solid #dbeafe;
    color:#1e40af;font-size:.7rem;line-height:1.5;min-height:34px;
}
.workflow-notify{
    display:flex;gap:8px;align-items:flex-start;
    margin-bottom:10px;padding:9px 11px;
    border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;
    color:#475569;font-size:.7rem;line-height:1.5;
}
.workflow-notify i{color:#7c3aed;margin-top:1px;}
.workflow-notify strong{color:#0f172a;}
#vmWorkflowSave:disabled{opacity:.55;cursor:not-allowed;}
@media(max-width:640px){
    .team-role-upload{flex-wrap:wrap;}
    .team-role-upload .btn{flex:1;}
}
.team-material-files{
    margin-top:12px;
    border:1px solid #dbeafe;
    border-radius:13px;
    background:linear-gradient(180deg,#f8fbff,#fff);
    overflow:hidden;
}
.team-material-head{
    display:flex;
    align-items:center;
    gap:10px;
    padding:11px 12px;
    border-bottom:1px solid #dbeafe;
    background:#eff6ff;
}
.team-material-head > i{
    display:grid;
    width:32px;
    height:32px;
    flex:0 0 32px;
    place-items:center;
    border-radius:10px;
    background:#2563eb;
    color:#fff;
    font-size:15px;
}
.team-material-head strong{display:block;color:#0f172a;font-size:.78rem;}
.team-material-head span{display:block;color:#475569;font-size:.66rem;margin-top:2px;}
.team-material-files .file-item{margin:8px 12px;}
.team-material-files .material-links-empty{margin:10px 12px;}
.team-material-files .add-files-box{margin:0 12px 12px;}
.team-material-kind{
    display:inline-block;
    padding:1px 8px;
    border-radius:999px;
    background:#e0eaff;
    color:#1d4ed8;
    font-size:.6rem;
    font-weight:850;
    text-transform:uppercase;
    letter-spacing:.04em;
}
.team-head-pill{
    margin-left:auto;
    flex:0 0 auto;
    padding:4px 11px;
    border-radius:999px;
    background:#0f172a;
    color:#fff;
    font-size:.64rem;
    font-weight:850;
    white-space:nowrap;
}
.team-step-label{
    display:flex;
    align-items:center;
    gap:8px;
    margin:2px 0 8px;
    color:#0f172a;
    font-size:.68rem;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.06em;
}
.team-step-label span{
    display:inline-grid;
    place-items:center;
    min-width:52px;
    padding:3px 10px;
    border-radius:999px;
    background:#2563eb;
    color:#fff;
    font-size:.6rem;
    letter-spacing:.08em;
}
.team-step-label.mt-3{margin-top:14px;}
.team-approval-count{
    margin-left:auto;
    flex:0 0 auto;
    padding:3px 10px;
    border-radius:999px;
    background:#dcfce7;
    color:#166534;
    font-size:.64rem;
    font-weight:900;
    white-space:nowrap;
}
.team-approval-empty{
    display:flex;
    gap:10px;
    align-items:flex-start;
    padding:12px;
    color:#64748b;
    font-size:.74rem;
}
.team-approval-empty i{
    display:grid;
    width:32px;
    height:32px;
    flex:0 0 32px;
    place-items:center;
    border-radius:10px;
    background:#f1f5f9;
    color:#94a3b8;
    font-size:15px;
}
.team-approval-empty strong{display:block;color:#334155;font-size:.76rem;}
.team-approval-empty span{display:block;margin-top:2px;font-size:.68rem;line-height:1.5;}
.team-approval-side{
    display:flex;
    flex-direction:column;
    align-items:flex-end;
    gap:6px;
    flex-shrink:0;
}
.team-approval-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:flex-end;}
.team-files-btn{border-radius:9px;font-weight:800;white-space:nowrap;}
#vmSupportingFiles{scroll-margin-top:12px;}
#viewSubmissionModal .detail-section[id]{scroll-margin-top:10px;}
#vmSupportingFiles.flash-focus{animation:vmFlash 1.4s ease;}
#viewSubmissionModal .detail-section.flash-focus{animation:vmFlash 1.4s ease;}
@keyframes vmFlash{
    0%,60%{box-shadow:0 0 0 3px rgba(37,99,235,.45);}
    100%{box-shadow:none;}
}
.material-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.material-textarea{line-height:1.6;min-height:130px;white-space:pre-wrap;}
.material-textarea[readonly]{background:#f8fafc;cursor:default;}
.material-textarea[readonly]:focus{border-color:#dbe3ef;box-shadow:none;}
.char-counter{margin-top:4px;color:#94a3b8;font-size:.64rem;font-weight:700;text-align:right;}
.char-counter.warn{color:#b45309;}
.char-counter.over{color:#b42318;font-weight:800;}
.material-meta{display:flex;align-items:center;gap:6px;margin-top:8px;color:#64748b;font-size:.68rem;}
.material-divider{margin:14px 0;border-top:1px dashed #e2e8f0;}
.material-links-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px;}
.material-links-head strong{display:block;color:#0f172a;font-size:.78rem;}
.material-links-head span{display:block;color:#64748b;font-size:.66rem;margin-top:2px;}
.material-links-list{display:flex;flex-direction:column;gap:8px;margin-bottom:10px;}
.material-link-chip{display:flex;align-items:center;gap:8px;padding:9px 12px;border:1px solid #dbeafe;border-radius:11px;background:#f8fbff;color:#0f172a;text-decoration:none;font-size:.74rem;transition:.15s ease;}
.material-link-chip:hover{border-color:#2563eb;background:#eff6ff;transform:translateY(-1px);}
.material-link-chip strong{flex:0 0 auto;font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.04em;color:#1d4ed8;}
.material-link-chip span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#334155;}
.material-link-chip > i:first-child{color:#2563eb;}
.material-link-chip > i:last-child{color:#94a3b8;font-size:.7rem;}
.material-links-empty{padding:10px 12px;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc;color:#64748b;font-size:.74rem;margin-bottom:10px;}
.material-links-form{background:#fbfdff;border:1px solid #e6ecf4;border-radius:12px;padding:12px;}
.material-link-row{display:grid;grid-template-columns:150px minmax(0,1fr) 34px;gap:8px;align-items:center;margin-bottom:8px;}
.material-link-row:last-child{margin-bottom:0;}
.material-link-remove{border-radius:8px;}
.material-links-locked{display:flex;align-items:center;gap:8px;margin-top:4px;padding:10px 12px;border:1px solid #e2e8f0;border-radius:11px;background:#f8fafc;color:#64748b;font-size:.72rem;}
.material-links-locked i{color:#94a3b8;}
.material-link-rows{display:flex;flex-direction:column;gap:8px;margin-bottom:10px;}
.material-link-item{display:flex;align-items:center;gap:6px;}
.material-link-item > a{flex:1;min-width:0;}
.material-link-edit{border-radius:8px;flex:0 0 auto;}
.material-link-editor{
    display:flex;gap:8px;align-items:center;flex-wrap:wrap;
    margin:-2px 0 8px;padding:10px 12px;
    border:1px solid #bfdbfe;border-radius:11px;background:#f8fbff;
}
.material-link-editor[hidden]{display:none;}
.material-link-editor .form-control{flex:1;min-width:180px;}
@media(max-width:800px){.material-grid{grid-template-columns:1fr;}.material-link-row{grid-template-columns:1fr;}.material-link-remove{justify-self:end;}}
@media(max-width:640px){
    .team-approval-row{flex-wrap:wrap;}
    .team-approval-side{width:100%;align-items:stretch;}
    .team-approval-actions{justify-content:stretch;}
    .team-approval-actions .btn,.team-approval-actions form{flex:1;}
    .team-head-pill{display:none;}
}

.team-chips{
    display:flex;
    align-items:center;
    gap:6px;
    flex-wrap:wrap;
    margin-top:10px;
    padding-top:10px;
    border-top:1px dashed #e2e8f0;
}

.team-chips-label{
    color:#64748b;
    font-size:.8rem;
}

.team-chip{
    display:inline-flex;
    align-items:center;
    gap:5px;
    max-width:100%;
    padding:3px 9px;
    border-radius:999px;
    background:#dcfce7;
    color:#166534;
    font-size:.64rem;
    font-weight:800;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.team-chip.is-empty{
    background:#f1f5f9;
    color:#94a3b8;
    font-weight:700;
}

.empty-state{
    padding:48px 20px;
    text-align:center;
    color:#667085;
}

.empty-detail{
    padding:56px 22px;
    text-align:center;
    color:#667085;
}

.empty-detail .empty-icon{
    display:grid;
    width:60px;
    height:60px;
    place-items:center;
    margin:0 auto 12px;
    border-radius:18px;
    background:#eef4ff;
    color:#2563eb;
    font-size:26px;
}

.empty-detail strong{
    font-size:.92rem;
}

.empty-detail span{
    font-size:.76rem;
    line-height:1.5;
}

.filter-label{
    display:block;
    margin-bottom:3px;
    color:#667085;
    font-size:.64rem;
    font-weight:900;
    text-transform:uppercase;
}

/* Minimal single-row list filter (labels kept screen-reader-only) */
.pub-filter-row{
    display:flex;
    gap:6px;
    align-items:center;
}

.pub-filter-row .search-wrap{
    flex:1 1 auto;
    min-width:140px;
}

.pub-filter-row .search-wrap-inner{
    position:relative;
}

.pub-filter-row .pub-status-wrap{
    flex:0 1 180px;
    min-width:0;
}

.pub-filter-row .filter-label{
    position:absolute;
    width:1px;
    height:1px;
    margin:-1px;
    padding:0;
    overflow:hidden;
    clip:rect(0,0,0,0);
    white-space:nowrap;
    border:0;
}

.pub-filter-row .form-control,
.pub-filter-row .form-select{
    min-height:34px;
    font-size:.75rem;
    border-radius:8px;
}

.pub-filter-actions{
    display:flex;
    gap:6px;
    flex-shrink:0;
}

.pub-filter-actions .btn{
    width:34px;
    min-height:34px;
    padding:0;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:8px;
}

@media(max-width:650px){
    .pub-filter-row{
        flex-wrap:wrap;
    }

    .pub-filter-row .search-wrap{
        flex:1 1 100%;
    }

    .pub-filter-row .pub-status-wrap{
        flex:1 1 140px;
    }
}

@media(max-width:1200px){
    .workspace{
        grid-template-columns:1fr;
    }

    .detail-card{
        position:static;
    }

    .detail-body{
        max-height:none;
    }
}

@media(max-width:1200px){
    .kpi-grid{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }
}

@media(max-width:800px){
    .kpi-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
}

@media(max-width:650px){
    .content{
        padding:12px;
    }

    .kpi-grid{
        grid-template-columns:1fr 1fr;
    }

    .detail-hero-main{
        padding:14px 14px 10px;
    }

    .detail-kind-icon{
        width:40px;
        height:40px;
        flex-basis:40px;
        font-size:17px;
    }

    .detail-hero h2{
        font-size:.88rem;
    }

    .detail-head-actions{
        padding:0 14px 10px 14px;
    }

    .detail-meta-strip{
        grid-template-columns:1fr;
        padding:10px 14px;
    }

    .detail-field-grid{
        grid-template-columns:1fr;
    }

    .detail-body{
        padding:12px 14px;
    }

    .file-actions{
        flex-wrap:wrap;
    }
}
/* ============ PUBLICATION UX UPGRADE (additive, no backend change) ============ */
.rm-crumb{display:flex;align-items:center;flex-wrap:wrap;gap:7px;margin:0 0 10px 2px;color:#94a3b8;font-size:.7rem;font-weight:650;}
.rm-crumb a{display:inline-flex;align-items:center;gap:5px;color:#64748b;text-decoration:none;font-weight:750;}
.rm-crumb a:hover{color:#2563eb;text-decoration:underline;}
.rm-crumb-sep{color:#cbd5e1;}
.rm-crumb-current{color:#0f172a;font-weight:800;}
a.kpi-card-link{display:block;text-decoration:none;color:inherit;border-radius:13px;}
a.kpi-card-link:hover .kpi-card{border-color:#93c5fd;box-shadow:0 6px 18px rgba(37,99,235,.10);}
a.kpi-card-link.kpi-active .kpi-card{outline:2px solid #2563eb;outline-offset:1px;}
.search-wrap{position:relative;}
.search-wrap .form-control{padding-right:28px;}
.search-clear{position:absolute;top:50%;right:7px;transform:translateY(-50%);border:0;background:#f1f5f9;color:#667085;width:20px;height:20px;border-radius:50%;display:none;align-items:center;justify-content:center;font-size:.6rem;cursor:pointer;z-index:3;}
.search-clear.show{display:inline-flex;}
.search-clear:hover{background:#e2e8f0;color:#0f172a;}
.active-chips{display:none;gap:6px;flex-wrap:wrap;padding:0 16px 12px;background:#fff;}
.active-chips.show{display:flex;}
.active-chips-label{color:#667085;font-size:.62rem;font-weight:800;align-self:center;}
.filter-chip{display:inline-flex;align-items:center;gap:5px;max-width:280px;padding:3px 5px 3px 9px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:.64rem;font-weight:750;}
.filter-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.filter-chip a{display:grid;width:17px;height:17px;place-items:center;border-radius:50%;background:rgba(29,78,216,.14);color:inherit;text-decoration:none;font-size:.58rem;flex:0 0 17px;}
.filter-chip a:hover{background:#2563eb;color:#fff;}
.list-meta-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 14px;padding:10px 0 0;color:#667085;font-size:.68rem;}
.live-count{font-weight:900;color:#0f172a;}
.list-meta-bar .spacer{flex:1;}
.list-meta-bar .form-select{width:auto;font-size:.68rem;font-weight:750;padding:4px 26px 4px 9px;border-radius:8px;}
.meta-btn{display:inline-flex;align-items:center;gap:5px;border:1px solid #dfe6ef;background:#fff;color:#475467;border-radius:8px;font-size:.66rem;font-weight:800;padding:5px 10px;cursor:pointer;}
.meta-btn:hover{border-color:#93c5fd;color:#1d4ed8;background:#eff6ff;}
.meta-btn.is-active{border-color:#2563eb;background:#2563eb;color:#fff;}
kbd{padding:1px 6px;border:1px solid #e2e8f0;border-bottom-width:2px;border-radius:6px;background:#fff;color:#94a3b8;font-size:.6rem;}
.copy-link-btn{border:1px solid #e5ebf3;background:#fff;color:#98a2b3;border-radius:8px;font-size:.68rem;padding:5px 8px;cursor:pointer;}
.copy-link-btn:hover{border-color:#93c5fd;color:#1d4ed8;}
.mini-progress-fill{transition:width .7s ease;}
.toast-mini{position:fixed;left:50%;bottom:22px;transform:translateX(-50%) translateY(10px);background:#0f172a;color:#fff;font-size:.72rem;font-weight:750;padding:9px 14px;border-radius:10px;opacity:0;pointer-events:none;transition:.2s ease;z-index:1090;display:flex;gap:7px;align-items:center;box-shadow:0 10px 30px rgba(0,0,0,.25);}
.toast-mini.show{opacity:1;transform:translateX(-50%) translateY(0);}
#pubToTop{position:fixed;right:18px;bottom:18px;z-index:1050;display:none;width:42px;height:42px;place-items:center;border:1px solid var(--pub-border);border-radius:12px;background:#fff;color:#2563eb;font-size:1rem;box-shadow:var(--pub-shadow);cursor:pointer;}
#pubToTop.show{display:grid;}
#pubToTop:hover{border-color:#2563eb;background:#2563eb;color:#fff;}
body.ultra-compact .submission-card{padding:10px 14px;}
body.ultra-compact .submission-meta,body.ultra-compact .request-sub{display:none;}
body.ultra-compact .request-title{font-size:.84rem;}
body.ultra-compact .mini-progress{margin-top:6px;}
@media(max-width:650px){
    .list-meta-bar kbd{display:none;}
    .list-meta-bar .form-select{flex:1;}
}
@media (prefers-reduced-motion: reduce){
    *,*::before,*::after{animation-duration:.001s !important;transition-duration:.001s !important;}
}
/* ============ LIST PAGE UX UPGRADE 2 (additive) ============ */
/* KPI cards with icon tiles */
.kpi-grid {
    display: grid !important;
    grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
    gap: 12px !important;
    margin-bottom: 16px !important;
    align-items: stretch !important;
}

@media(max-width: 1200px) {
    .kpi-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    }
}

@media(max-width: 650px) {
    .kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}

a.kpi-card-link {
    display: block !important;
    height: 100% !important;
    text-decoration: none !important;
    color: inherit !important;
    border-radius: 14px !important;
    outline: none !important;
}

a.kpi-card-link:focus-visible {
    outline: 2px solid #2563eb !important;
    outline-offset: 2px !important;
}

.kpi-card {
    display: flex !important;
    flex-direction: column !important;
    justify-content: space-between !important;
    align-items: stretch !important;
    height: 100% !important;
    min-height: 136px !important;
    padding: 14px 16px !important;
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 14px !important;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 4px 12px rgba(15, 23, 42, 0.02) !important;
    position: relative !important;
    overflow: hidden !important;
    transition: transform 0.18s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.18s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.18s ease !important;
}

.kpi-card::after {
    display: none !important;
}

.kpi-card::before {
    content: "" !important;
    position: absolute !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    height: 3.5px !important;
    background: #cbd5e1 !important;
    transition: height 0.15s ease !important;
}

.kpi-card.status-total::before { background: linear-gradient(90deg, #2563eb, #60a5fa) !important; }
.kpi-card.status-for_review::before { background: linear-gradient(90deg, #dc2626, #f87171) !important; }
.kpi-card.status-in_preparation::before { background: linear-gradient(90deg, #2563eb, #3b82f6) !important; }
.kpi-card.status-for_approval::before { background: linear-gradient(90deg, #7c3aed, #a78bfa) !important; }
.kpi-card.status-published::before { background: linear-gradient(90deg, #16a34a, #4ade80) !important; }
.kpi-card.status-closed::before { background: linear-gradient(90deg, #64748b, #94a3b8) !important; }

a.kpi-card-link:hover .kpi-card {
    transform: translateY(-3px) !important;
    box-shadow: 0 10px 24px -4px rgba(15, 23, 42, 0.08), 0 4px 8px -2px rgba(15, 23, 42, 0.04) !important;
    border-color: #cbd5e1 !important;
}

a.kpi-card-link.kpi-active .kpi-card {
    border-color: #2563eb !important;
    outline: 2px solid #2563eb !important;
    outline-offset: -1px !important;
    box-shadow: 0 8px 20px -2px rgba(37, 99, 235, 0.16) !important;
}

a.kpi-card-link.kpi-active .kpi-card::before {
    height: 4.5px !important;
}

.kpi-top {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    gap: 9px !important;
    margin-bottom: 8px !important;
    width: 100% !important;
}

.kpi-ico {
    display: grid !important;
    width: 34px !important;
    height: 34px !important;
    flex: 0 0 34px !important;
    place-items: center !important;
    border-radius: 9px !important;
    font-size: 0.95rem !important;
    transition: transform 0.15s ease !important;
}

a.kpi-card-link:hover .kpi-ico {
    transform: scale(1.06) !important;
}

.kpi-ico.total { background: #eff6ff !important; color: #1d4ed8 !important; }
.kpi-ico.warn { background: #fef2f2 !important; color: #dc2626 !important; }
.kpi-ico.info { background: #eff6ff !important; color: #1d4ed8 !important; }
.kpi-ico.approval { background: #f5f3ff !important; color: #7c3aed !important; }
.kpi-ico.ok { background: #f0fdf4 !important; color: #16a34a !important; }
.kpi-ico.muted { background: #f8fafc !important; color: #64748b !important; border: 1px solid #e2e8f0 !important; }

.kpi-label {
    color: #475569 !important;
    font-size: 0.7rem !important;
    font-weight: 800 !important;
    letter-spacing: 0.04em !important;
    text-transform: uppercase !important;
    line-height: 1.25 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    word-break: normal !important;
    overflow-wrap: normal !important;
    min-width: 0 !important;
    flex: 1 !important;
}

.kpi-body {
    flex: 1 !important;
    display: flex !important;
    flex-direction: column !important;
    justify-content: center !important;
    margin-bottom: 6px !important;
}

.kpi-value {
    margin: 0 !important;
    color: #0f172a !important;
    font-size: 1.65rem !important;
    line-height: 1.1 !important;
    font-weight: 800 !important;
    letter-spacing: -0.02em !important;
    font-variant-numeric: tabular-nums !important;
}

.kpi-note {
    margin-top: 3px !important;
    color: #64748b !important;
    font-size: 0.68rem !important;
    line-height: 1.35 !important;
    display: -webkit-box !important;
    -webkit-line-clamp: 2 !important;
    -webkit-box-orient: vertical !important;
    overflow: hidden !important;
    word-break: normal !important;
    overflow-wrap: normal !important;
}

.kpi-bottom-bar {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    margin-top: auto !important;
    padding-top: 4px !important;
}

.kpi-share {
    flex: 1 !important;
    height: 5px !important;
    border-radius: 999px !important;
    background: #f1f5f9 !important;
    overflow: hidden !important;
    margin: 0 !important;
}

.kpi-share > span {
    display: block !important;
    height: 100% !important;
    border-radius: 999px !important;
    background: currentColor !important;
    transition: width 0.4s cubic-bezier(0.16, 1, 0.3, 1) !important;
}

.kpi-card.status-total .kpi-share > span { background: #2563eb !important; }
.kpi-card.status-for_review .kpi-share > span { background: #dc2626 !important; }
.kpi-card.status-in_preparation .kpi-share > span { background: #2563eb !important; }
.kpi-card.status-for_approval .kpi-share > span { background: #7c3aed !important; }
.kpi-card.status-published .kpi-share > span { background: #16a34a !important; }
.kpi-card.status-closed .kpi-share > span { background: #64748b !important; }

.kpi-share-num {
    margin: 0 !important;
    color: #64748b !important;
    font-size: 0.64rem !important;
    font-weight: 800 !important;
    white-space: nowrap !important;
}
/* Sticky filter toolbar */
.list-card{overflow:visible;}
.list-card .toolbar{
    position:sticky;
    top:0;
    z-index:30;
    border-radius:14px 14px 0 0;
    background:rgba(255,255,255,.94);
    backdrop-filter:blur(10px);
    -webkit-backdrop-filter:blur(10px);
    box-shadow:0 6px 18px rgba(28,42,72,.06);
}
.tab-row{display:flex;gap:8px;flex-wrap:wrap;}
.tab-link{position:relative;transition:.15s ease;}
.tab-link.active{box-shadow:0 0 0 3px rgba(37,99,235,.12);}
.search-wrap .form-control{transition:.15s ease;}
.search-wrap .form-control:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12);}
/* Cards: accent rail, entrance, meta chips */
.submission-list{padding:10px;}
.submission-card{
    position:relative;
    overflow:hidden;
    padding-left:20px;
    animation:pubCardIn .3s ease both;
}
.submission-card:nth-child(2){animation-delay:.03s;}
.submission-card:nth-child(3){animation-delay:.06s;}
.submission-card:nth-child(4){animation-delay:.09s;}
.submission-card:nth-child(5){animation-delay:.12s;}
.submission-card:nth-child(n+6){animation-delay:.15s;}
@keyframes pubCardIn{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:none;}}
.submission-card::before{
    content:"";
    position:absolute;
    top:0;bottom:0;left:0;
    width:4px;
    background:linear-gradient(180deg,#93c5fd,#2563eb);
}
.submission-card.active{box-shadow:0 0 0 3px rgba(37,99,235,.16);}
.submission-card.active::before{background:linear-gradient(180deg,#2563eb,#7c3aed);}
.request-title{font-size:.98rem;}
.submission-meta{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    margin:12px 0 4px;
    padding:0;
    border:0;
    background:none;
}
.submission-meta > div{
    display:inline-flex;
    align-items:center;
    gap:6px;
    max-width:100%;
    padding:4px 10px;
    border:1px solid #e6ecf4;
    border-radius:999px;
    background:#f8fbff;
    font-size:.7rem;
    color:#344054;
}
.submission-meta i{margin-top:0;color:#2563eb;font-size:.72rem;}
.submission-meta span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.approve-pill{
    display:inline-flex;
    align-items:center;
    gap:4px;
    padding:5px 10px;
    border-radius:999px;
    background:#fef3c7;
    color:#92400e;
    font-size:.62rem;
    font-weight:850;
    white-space:nowrap;
}
.approve-pill.is-done{background:#dcfce7;color:#166534;}
.submission-foot .btn{border-radius:9px;transition:.15s ease;}
.submission-foot .btn-primary{box-shadow:0 3px 10px rgba(37,99,235,.22);}
.submission-foot .btn-primary:hover{transform:translateY(-1px);}
.submission-foot .btn-outline-secondary:hover{border-color:#2563eb;color:#1d4ed8;}
.mini-progress{height:7px;}
/* Empty state */
.pub-empty{padding:60px 24px;}
.pub-empty-icon{
    display:grid;
    width:64px;height:64px;
    place-items:center;
    margin:0 auto 14px;
    border-radius:20px;
    background:linear-gradient(135deg,#eff6ff,#e0eaff);
    color:#2563eb;
    font-size:26px;
    box-shadow:0 8px 20px rgba(37,99,235,.12);
}
/* List meta bar */
.list-meta-bar{
    margin:0;
    padding:10px 14px;
    background:#f8fafc;
    border-bottom:1px solid #edf1f5;
}
@media(max-width:650px){
    .list-card .toolbar{position:static;}
    .list-meta-bar{position:static;}
    .submission-card{padding:11px 12px 11px 15px;}
    .submission-foot{flex-direction:column;align-items:stretch;}
    .submission-foot .d-inline-flex{justify-content:stretch;}
    .submission-foot .d-inline-flex .btn{flex:1;}
}
/* ============ LIST PAGE UX UPGRADE 3 (additive) ============ */
.pub-new-menu{min-width:250px;padding:6px;border-radius:12px;box-shadow:0 16px 40px rgba(15,23,42,.16);z-index:1060 !important;}
/* The shared .ph header uses overflow:hidden, which clips the open menu. */
.ph{overflow:visible !important;position:relative !important;z-index:100 !important;}
.ph::after{border-radius:14px 14px 0 0;}
.ph .dropdown{position:relative !important;z-index:1050 !important;}
.ph .dropdown-menu{z-index:1060 !important;}
.pub-new-menu .dropdown-item{display:flex;align-items:center;padding:9px 12px;border-radius:8px;font-size:.8rem;font-weight:650;}
.pub-new-menu .dropdown-item:hover{background:#eff6ff;}
.pub-new-menu .dropdown-item i{font-size:1rem;}
.status-pills{display:flex;gap:6px;flex-wrap:wrap;margin:12px 0 2px;}
.status-pill-link{
    display:inline-flex;align-items:center;
    padding:5px 12px;border:1px solid #dfe6ef;border-radius:999px;
    background:#fff;color:#475467;font-size:.68rem;font-weight:800;text-decoration:none;
    transition:.15s ease;
}
.status-pill-link:hover{border-color:#93c5fd;color:#1d4ed8;transform:translateY(-1px);}
.status-pill-link.active{background:#0f172a;border-color:#0f172a;color:#fff;}
.status-pill-link.sp-review.active{background:#b45309;border-color:#b45309;}
.status-pill-link.sp-prep.active{background:#1d4ed8;border-color:#1d4ed8;}
.status-pill-link.sp-approval.active{background:#6d28d9;border-color:#6d28d9;}
.status-pill-link.sp-published.active{background:#027a48;border-color:#027a48;}
.status-pill-link.sp-closed.active{background:#475569;border-color:#475569;}
.pref-chip.is-soon{border-color:#fed7aa;background:#fff7ed;color:#9a3412;}
.pref-chip.is-soon i{color:#ea580c;}
.pref-chip.is-overdue{border-color:#fecaca;background:#fef2f2;color:#b42318;}
.pref-chip.is-overdue i{color:#dc2626;}
.tab-count{
    display:inline-grid;place-items:center;
    min-width:22px;height:20px;padding:0 6px;
    border-radius:999px;background:#eef2f7;color:#475569;
    font-size:.62rem;font-weight:900;font-variant-numeric:tabular-nums;
}
.tab-link.active .tab-count{background:#2563eb;color:#fff;}
.tab-link:hover .tab-count{background:#dbeafe;color:#1d4ed8;}
.tab-link.active:hover .tab-count{background:#1d4ed8;color:#fff;}
.sub-progress{display:flex;align-items:center;gap:10px;margin-top:12px;}
.sub-progress .mini-progress{flex:1;margin-top:0;}
.sub-progress-label{flex:0 0 auto;color:#64748b;font-size:.66rem;font-weight:850;font-variant-numeric:tabular-nums;}

/* =========================================================
   EVENT / ACTIVITY CARD V2 REDESIGN (.event-card-v2)
   ========================================================= */
.submission-card.event-card-v2 {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-left: 3px solid #2563eb;
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 0;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    transition: border-color 0.15s ease, background 0.15s ease;
    position: relative;
}
.submission-card.event-card-v2:hover {
    box-shadow: none;
    transform: none;
    border-color: #93c5fd;
    border-left-color: #1d4ed8;
    background: #fbfdff;
}
.submission-card.event-card-v2.active {
    border-color: #93c5fd;
    border-left-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12), 0 8px 24px rgba(15, 23, 42, 0.08);
}
.event-card-v2-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 8px;
    flex-wrap: wrap;
}
.event-card-v2-header .header-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.event-card-v2-approval-counter {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 750;
    background: #f8fafc;
    color: #475569;
    border: 1px solid #e2e8f0;
}
.event-card-v2-approval-counter.is-approved {
    background: #f0fdf4;
    color: #15803d;
    border-color: #bbf7d0;
}
.event-card-v2-main {
    margin-bottom: 10px;
}
.event-card-v2-title {
    font-size: .95rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.3;
    letter-spacing: -.01em;
    margin: 0 0 6px 0;
}
.event-card-v2-badges {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.meta-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    border-radius: 6px;
    font-size: 0.73rem;
    font-weight: 650;
}
.meta-badge.category-badge {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}
.meta-badge.dept-badge {
    background: #f8fafc;
    color: #475569;
    border: 1px solid #e2e8f0;
}
.event-card-v2-info-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px 12px;
    padding: 9px 12px;
    background: #f8fafc;
    border: 1px solid #f1f5f9;
    border-radius: 10px;
    margin-bottom: 10px;
}
.event-card-v2-info-grid .info-col {
    min-width: 0;
}
.event-card-v2-info-grid .info-label {
    font-size: 0.65rem;
    font-weight: 800;
    letter-spacing: 0.05em;
    color: #64748b;
    text-transform: uppercase;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
}
.event-card-v2-info-grid .info-value {
    font-size: 0.78rem;
    font-weight: 700;
    color: #0f172a;
    word-break: break-word;
}
.pref-urgent-badge {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 4px;
    font-size: 0.65rem;
    font-weight: 800;
    margin-left: 4px;
}
.pref-urgent-badge.is-overdue {
    background: #fef2f2;
    color: #dc2626;
    border: 1px solid #fecaca;
}
.pref-urgent-badge.is-soon {
    background: #fff7ed;
    color: #ea580c;
    border: 1px solid #fed7aa;
}
.event-card-v2-assignments {
    display: flex;
    gap: 12px;
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px dashed #e2e8f0;
    flex-wrap: wrap;
}
.event-card-v2-assignments .assignment-block {
    flex: 1;
    min-width: 200px;
}
.event-card-v2-assignments .assignment-title {
    display: block;
    font-size: 0.65rem;
    font-weight: 800;
    letter-spacing: 0.05em;
    color: #64748b;
    text-transform: uppercase;
    margin-bottom: 6px;
}
.event-card-v2-assignments .assignment-chips {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.staff-chip {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 7px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    font-size: 0.68rem;
    color: #1e293b;
}
.staff-chip .staff-role {
    font-weight: 700;
    color: #475569;
    margin-right: 4px;
}
.staff-chip .staff-name {
    font-weight: 600;
    color: #0f172a;
}
.staff-chip.approver-chip {
    background: #f0fdf4;
    border-color: #bbf7d0;
    color: #14532d;
}
.staff-chip.is-empty {
    background: #f8fafc;
    border-color: #e2e8f0;
    color: #94a3b8;
    font-style: italic;
}
.event-card-v2-workflow {
    margin-bottom: 10px;
    padding: 8px 12px;
    background: #ffffff;
    border: 1px solid #f1f5f9;
    border-radius: 10px;
}
.wf-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    margin-bottom:8px;
}
.wf-head-label{
    display:inline-flex;
    align-items:center;
    gap:6px;
    color:#64748b;
    font-size:.62rem;
    font-weight:800;
    letter-spacing:.05em;
    text-transform:uppercase;
}
.wf-head-label i{
    color:#2563eb;
}
.wf-hide-btn{
    display:inline-flex;
    align-items:center;
    gap:4px;
    border:1px solid #e2e8f0;
    border-radius:7px;
    background:#f8fafc;
    color:#64748b;
    font-size:.62rem;
    font-weight:800;
    padding:3px 9px;
    cursor:pointer;
    transition:.15s ease;
}
.wf-hide-btn:hover{
    border-color:#93c5fd;
    color:#1d4ed8;
    background:#eff6ff;
}
.wf-hide-btn i{
    font-size:.62rem;
    transition:transform .15s ease;
}
.event-card-v2-workflow.is-hidden .workflow-steps{
    display:none;
}
.event-card-v2-workflow.is-hidden .wf-head{
    margin-bottom:0;
}
.event-card-v2-workflow.is-hidden .wf-hide-btn i{
    transform:rotate(180deg);
}
.workflow-steps {
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: relative;
}
.wf-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    z-index: 2;
    min-width: 60px;
    text-align: center;
}
.wf-circle {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.62rem;
    font-weight: 800;
    margin-bottom: 4px;
    transition: all 0.2s ease;
}
.wf-step-completed .wf-circle {
    background: #16a34a;
    color: #ffffff;
    box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.15);
}
.wf-step-active .wf-circle {
    background: #2563eb;
    color: #ffffff;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.2);
}
.wf-step-active .wf-pulse {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #ffffff;
    animation: wfPulse 1.8s infinite;
}
.wf-step-pending .wf-circle {
    background: #f1f5f9;
    color: #94a3b8;
    border: 1px solid #cbd5e1;
}
.wf-step-disapproved .wf-circle {
    background: #dc2626;
    color: #ffffff;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
}
.wf-label {
    font-size: 0.62rem;
    font-weight: 700;
    color: #64748b;
}
.wf-step-completed .wf-label {
    color: #16a34a;
}
.wf-step-active .wf-label {
    color: #2563eb;
    font-weight: 800;
}
.wf-step-disapproved .wf-label {
    color: #dc2626;
}
.wf-line {
    flex: 1;
    height: 3px;
    background: #e2e8f0;
    margin: 0 6px 18px 6px;
    position: relative;
    z-index: 1;
    border-radius: 2px;
}
.wf-line-completed {
    background: #16a34a;
}
@keyframes wfPulse {
    0% { transform: scale(0.9); opacity: 0.8; }
    50% { transform: scale(1.2); opacity: 1; }
    100% { transform: scale(0.9); opacity: 0.8; }
}
.event-card-v2-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding-top: 9px;
    border-top: 1px solid #f1f5f9;
    flex-wrap: wrap;
}
.event-card-v2-footer .footer-left {
    font-size: 0.75rem;
    color: #64748b;
    font-weight: 600;
}
.event-card-v2-footer .footer-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.card-action-btn {
    font-size: 0.72rem;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 8px;
    transition: all 0.15s ease;
}
@media (max-width: 991px) {
    .event-card-v2-info-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 575px) {
    .submission-card.event-card-v2 { padding: 11px 12px; }
    .event-card-v2-info-grid { grid-template-columns: 1fr; }
    .event-card-v2-assignments { flex-direction: column; gap: 8px; }
    .event-card-v2-footer { flex-direction: column; align-items: stretch; }
    .event-card-v2-footer .footer-right { justify-content: stretch; }
    .event-card-v2-footer .footer-right .card-action-btn { flex: 1; text-align: center; justify-content: center; }
    .wf-label { font-size: 0.58rem; }
    .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .kpi-note { display: none; }
}
</style>

</head>

<body class="app-shell">

<div class="d-flex min-vh-100">

<?php include "../includes/sidebar.php"; ?>

<div class="main flex-grow-1">

<main class="content">

<div class="container-fluid">

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

<nav class="rm-crumb" aria-label="Breadcrumb">
    <a href="<?= e(BASE_URL) ?>/dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a>
    <span class="rm-crumb-sep">/</span>
    <span class="rm-crumb-current" aria-current="page">Publication Requests</span>
</nav>

<div class="ph">

    <div class="ph-copy">

        <div class="ph-icon" aria-hidden="true">
            <i class="bi bi-newspaper"></i>
        </div>

        <div class="min-w-0">
            <p class="ph-eyebrow">
                HPU Workbench
            </p>

            <h1 class="ph-title">
                Publication Requests
            </h1>

            <p class="ph-subtitle">
                Review Event / Activity, Recognition / Milestone, and Content Contribution submissions, supporting files, and publication workflow.
            </p>
        </div>

    </div>

    <div class="ph-actions">

        <div class="dropdown">
            <button
                class="btn btn-sm btn-primary dropdown-toggle"
                type="button"
                data-bs-toggle="dropdown"
                aria-expanded="false"
            >
                <i class="bi bi-plus-circle me-1"></i>
                New Submission
            </button>

            <ul class="dropdown-menu dropdown-menu-end pub-new-menu">
                <li>
                    <a
                        class="dropdown-item"
                        href="<?= e(BASE_URL) ?>/content_submission/event_activity.php"
                    >
                        <i class="bi bi-calendar2-event me-2 text-primary"></i>
                        Event / Activity
                    </a>
                </li>

                <li>
                    <a
                        class="dropdown-item"
                        href="<?= e(BASE_URL) ?>/content_submission/recognition_milestone.php"
                    >
                        <i class="bi bi-award me-2 text-warning"></i>
                        Recognition / Milestone
                    </a>
                </li>

                <li>
                    <a
                        class="dropdown-item"
                        href="<?= e(BASE_URL) ?>/content_submission/content_contribution.php"
                    >
                        <i class="bi bi-journal-plus me-2 text-success"></i>
                        Content Contribution
                    </a>
                </li>
            </ul>
        </div>

    </div>

</div>

<div class="kpi-grid">

    <a class="kpi-card-link <?= $statusFilter === "" && $q === "" ? "kpi-active" : "" ?>"
        href="?<?= e(http_build_query(["tab" => $tab])) ?>"
        title="Show all submissions in this tab"
        aria-label="Total submissions: <?= number_format((int)($stats["total"] ?? 0)) ?>"
    >
        <div class="kpi-card status-total">
            <div class="kpi-top">
                <span class="kpi-ico total" aria-hidden="true"><i class="bi bi-layers-fill"></i></span>
                <div class="kpi-label">Total Submissions</div>
            </div>

            <div class="kpi-body">
                <div class="kpi-value"><?= number_format((int)($stats["total"] ?? 0)) ?></div>
                <div class="kpi-note" title="<?= $tab === "all" ? "All records, every status" : e(pubKindLabel($tab)) . " records in this tab" ?>">
                    <?= $tab === "all" ? "All records, every status" : e(pubKindLabel($tab)) . " records in this tab" ?>
                </div>
            </div>

            <div class="kpi-bottom-bar">
                <div class="kpi-share" role="img" aria-label="100 percent of total"><span style="width:100%"></span></div>
                <span class="kpi-share-num">100%</span>
            </div>
        </div>
    </a>

    <?php
    $kpiStages = [
        "For Review" => ["key" => "for_review", "tone" => "warn"],
        "In Preparation" => ["key" => "in_preparation", "tone" => "info"],
        "For Approval" => ["key" => "for_approval", "tone" => "approval"],
        "Published" => ["key" => "published", "tone" => "ok"],
        "Closed" => ["key" => "closed", "tone" => "muted"],
    ];

    foreach ($kpiStages as $kpiStatus => $kpiConf):
        $kpiMeta = pubStatusMeta($kpiStatus);
        $kpiClass = "status-" . strtolower(str_replace(" ", "_", $kpiStatus));
        $kpiValue = (int)($stats[$kpiConf["key"]] ?? 0);
        $kpiTotal = (int)($stats["total"] ?? 0);
        $kpiShare = $kpiTotal > 0 ? (int)min(100, round($kpiValue / $kpiTotal * 100)) : 0;
    ?>
        <a class="kpi-card-link <?= $statusFilter === $kpiStatus ? "kpi-active" : "" ?>"
            href="?<?= e(http_build_query(["tab" => $tab, "status" => $kpiStatus])) ?>"
            title="Filter: <?= e($kpiStatus) ?> — <?= e($kpiMeta["meaning"]) ?>"
            aria-label="<?= e($kpiStatus) ?>: <?= number_format($kpiValue) ?> (<?= $kpiShare ?>% of total)"
            <?= $statusFilter === $kpiStatus ? 'aria-current="true"' : "" ?>
        >
            <div class="kpi-card <?= e($kpiClass) ?>">
                <div class="kpi-top">
                    <span class="kpi-ico <?= e($kpiConf["tone"]) ?>" aria-hidden="true"><i class="bi <?= e($kpiMeta["icon"]) ?>"></i></span>
                    <div class="kpi-label"><?= e($kpiStatus) ?></div>
                </div>

                <div class="kpi-body">
                    <div class="kpi-value"><?= number_format($kpiValue) ?></div>
                    <div class="kpi-note" title="<?= e($kpiMeta["meaning"]) ?>">
                        <?= e($kpiMeta["meaning"]) ?>
                    </div>
                </div>

                <div class="kpi-bottom-bar">
                    <div class="kpi-share" role="img" aria-label="<?= $kpiShare ?> percent of total"><span style="width:<?= $kpiShare ?>%"></span></div>
                    <span class="kpi-share-num"><?= $kpiShare ?>%</span>
                </div>
            </div>
        </a>
    <?php endforeach; ?>

</div>

<div class="workspace">

    <section class="list-card">

        <div class="toolbar">

            <div class="tab-row">

                <a
                    class="tab-link <?= $tab === "all" ? "active" : "" ?>"
                    href="?<?= e(http_build_query(array_filter(["tab" => "all", "status" => $statusFilter ?: null, "q" => $q ?: null]))) ?>"
                    <?= $tab === "all" ? 'aria-current="page"' : "" ?>
                >
                    <i class="bi bi-grid"></i>
                    All
                    <span class="tab-count"><?= number_format((int)($tabTotals["all"] ?? 0)) ?></span>
                </a>

<a
                class="tab-link <?= $tab === "event" ? "active" : "" ?>"
                href="?<?= e(http_build_query(array_filter(["tab" => "event", "status" => $statusFilter ?: null, "q" => $q ?: null]))) ?>"
                <?= $tab === "event" ? 'aria-current="page"' : "" ?>
            >
                <i class="bi bi-calendar2-event"></i>
                Event / Activity
                <span class="tab-count"><?= number_format((int)($tabTotals["event"] ?? 0)) ?></span>
            </a>

            <a
                class="tab-link <?= $tab === "recognition" ? "active" : "" ?>"
                href="?<?= e(http_build_query(array_filter(["tab" => "recognition", "status" => $statusFilter ?: null, "q" => $q ?: null]))) ?>"
                <?= $tab === "recognition" ? 'aria-current="page"' : "" ?>
            >
                <i class="bi bi-award"></i>
                Recognition / Milestone
                <span class="tab-count"><?= number_format((int)($tabTotals["recognition"] ?? 0)) ?></span>
            </a>

            <a
                class="tab-link <?= $tab === "contribution" ? "active" : "" ?>"
                href="?<?= e(http_build_query(array_filter(["tab" => "contribution", "status" => $statusFilter ?: null, "q" => $q ?: null]))) ?>"
                <?= $tab === "contribution" ? 'aria-current="page"' : "" ?>
            >
                <i class="bi bi-journal-plus"></i>
                Content Contribution
                <span class="tab-count"><?= number_format((int)($tabTotals["contribution"] ?? 0)) ?></span>
            </a>

            </div>



            <form method="get">

                <input
                    type="hidden"
                    name="tab"
                    value="<?= e($tab) ?>"
                >

                <div class="pub-filter-row">

                    <div class="search-wrap">
                        <label class="filter-label" for="pubSearchInput">
                            Search
                        </label>

                        <div class="search-wrap-inner">
                            <input
                                id="pubSearchInput"
                                class="form-control form-control-sm"
                                name="q"
                                value="<?= e($q) ?>"
                                placeholder="Title, person, office, email...  ( / )"
                                autocomplete="off"
                            >
                            <button type="button" class="search-clear" id="pubSearchClear" title="Clear search" aria-label="Clear search"><i class="bi bi-x-lg"></i></button>
                        </div>
                    </div>

                    <div class="pub-status-wrap">
                        <label class="filter-label" for="pubStatusSelect">
                            Status
                        </label>

                        <select
                            id="pubStatusSelect"
                            class="form-select form-select-sm"
                            name="status"
                            aria-label="Filter by status"
                        >
                            <option value="">
                                All Statuses
                            </option>

                            <?php foreach ($allowedStatuses as $status): ?>

                            <option
                                value="<?= e($status) ?>"
                                <?= $statusFilter === $status ? "selected" : "" ?>
                            >
                                <?= e($status) ?>
                            </option>

                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="pub-filter-actions">

                        <button
                            class="btn btn-sm btn-primary"
                            type="submit"
                            title="Search"
                            aria-label="Search"
                        >
                            <i class="bi bi-search"></i>
                        </button>

                        <a
                            class="btn btn-sm btn-light"
                            href="?<?= e(http_build_query(["tab" => $tab])) ?>"
                            title="Clear search and status, stay in this tab"
                            aria-label="Reset filters"
                        >
                            <i class="bi bi-x-lg"></i>
                        </a>

                    </div>

                </div>

            </form>

            <div class="active-chips" id="pubActiveChips" aria-label="Active filters"></div>

        </div>

        <div class="request-table-wrap">

            <?php if (!$rows): ?>

            <div class="empty-state pub-empty">
                <div class="pub-empty-icon">
                    <i class="bi bi-inbox"></i>
                </div>

                <strong class="d-block text-dark mb-1">
                    No publication requests found
                </strong>

                <span>
                    Submitted Event / Activity, Recognition / Milestone, and Content Contribution forms will appear here.
                </span>

                <div class="mt-3">
                    <a
                        class="btn btn-sm btn-outline-primary"
                        href="?<?= e(http_build_query(["tab" => $tab])) ?>"
                    >
                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                        Clear filters
                    </a>
                </div>
            </div>

            <?php else: ?>

            <div class="list-meta-bar">
                <span class="live-count" id="pubLiveCount" aria-live="polite">Showing <?= count($rows) ?> submission<?= count($rows) === 1 ? "" : "s" ?></span>
                <span class="spacer"></span>
                <span><kbd>/</kbd> search&nbsp;&nbsp;<kbd>esc</kbd> clear</span>
                <label class="visually-hidden" for="pubSort">Sort submissions</label>
                <select id="pubSort" class="form-select form-select-sm">
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                    <option value="title">Title A–Z</option>
                    <option value="status">Status</option>
                </select>
                <button type="button" class="meta-btn" id="pubDensity" aria-pressed="false" title="Toggle compact density">
                    <i class="bi bi-arrows-collapse"></i> Compact
                </button>
            </div>

            <div class="submission-list">

                <?php foreach ($rows as $row): ?>

                <?php
                $kind = (string)$row["submission_kind"];
                $displayType = (string)$row["display_type"];

                if ($kind === "recognition") {
                    $typesDecoded = pubDecodeRecognitionTypes($displayType);
                    $displayType = implode(", ", $typesDecoded);
                }

                $isActive = $detail && $viewKind === $kind && $viewId === (int)$row["id"];
                $cardTeamKey = (string)$kind . ":" . (int)$row["id"];
                $cardTeam = $teamMap[$cardTeamKey] ?? [];

                $cardApprovable = 0;
                $cardApproved = 0;

                foreach (["layout", "video", "drafting"] as $approveKey) {
                    if (!empty($cardTeam[$approveKey]["user_id"])) {
                        $cardApprovable++;
                        if (!empty($approvalMap[$cardTeamKey][$approveKey])) {
                            $cardApproved++;
                        }
                    }
                }

                // Separate assigned staff vs approver seats
                $assignedStaffChips = [];
                $approverChips = [];

                foreach ($teamRoleMeta as $teamRoleKey => $teamMeta) {
                    $chipAssignee = $cardTeam[$teamRoleKey] ?? null;
                    $isApproverRole = !empty($teamMeta["approver_only"]) || $teamRoleKey === "approval";

                    $chipName = "";
                    if ($chipAssignee && (string)($chipAssignee["name"] ?? "") !== "") {
                        $chipName = (string)$chipAssignee["name"];
                    } elseif ($chipAssignee && (int)($chipAssignee["user_id"] ?? 0) > 0) {
                        $chipName = "User #" . (int)$chipAssignee["user_id"];
                    }

                    $chipItem = [
                        "role_key" => $teamRoleKey,
                        "label" => (string)($teamMeta["label"] ?? ucfirst($teamRoleKey)),
                        "icon" => (string)($teamMeta["icon"] ?? "bi-person"),
                        "name" => $chipName,
                    ];

                    if ($isApproverRole) {
                        $approverChips[] = $chipItem;
                    } else {
                        $assignedStaffChips[] = $chipItem;
                    }
                }

                // Format preferred publication date (e.g., September 21, 2026)
                $prefDateRaw = trim((string)($row["preferred_publication_date"] ?? ""));
                $prefDateFormatted = "N/A";
                $prefUrgentClass = "";
                $prefUrgentLabel = "";

                if ($prefDateRaw !== "") {
                    $prefTimestamp = strtotime($prefDateRaw);
                    if ($prefTimestamp !== false) {
                        $prefDateFormatted = date("F j, Y", $prefTimestamp);

                        if (!in_array((string)$row["status"], ["Published", "Closed"], true)) {
                            $prefDays = (int)floor(($prefTimestamp - strtotime("today")) / 86400);
                            if ($prefDays < 0) {
                                $prefUrgentClass = "is-overdue";
                                $prefUrgentLabel = "Overdue";
                            } elseif ($prefDays <= 3) {
                                $prefUrgentClass = "is-soon";
                                $prefUrgentLabel = $prefDays === 0 ? "Due Today" : "Due in " . $prefDays . "d";
                            }
                        }
                    }
                }

                // Dynamic 4-step horizontal workflow tracker: Submitted -> Approved -> Completed -> Closed
                $currStatus = trim((string)($row["status"] ?? ""));
                $currStatusLower = strtolower($currStatus);

                $wStep1 = 'completed'; // Step 1: Submitted
                $wStep2 = 'pending';   // Step 2: Approved
                $wStep3 = 'pending';   // Step 3: Completed
                $wStep4 = 'pending';   // Step 4: Closed

                if (in_array($currStatusLower, ['closed', 'done', 'finalized'], true)) {
                    $wStep1 = 'completed';
                    $wStep2 = 'completed';
                    $wStep3 = 'completed';
                    $wStep4 = 'completed';
                } elseif (in_array($currStatusLower, ['published', 'completed'], true)) {
                    $wStep1 = 'completed';
                    $wStep2 = 'completed';
                    $wStep3 = 'active';
                    $wStep4 = 'pending';
                } elseif (in_array($currStatusLower, ['approved', 'in preparation', 'in development', 'for approval', 'for evaluation', 'under review', 'for revision'], true)) {
                    $wStep1 = 'completed';
                    $wStep2 = 'active';
                    $wStep3 = 'pending';
                    $wStep4 = 'pending';
                } elseif (in_array($currStatusLower, ['disapproved', 'rejected', 'cancelled'], true)) {
                    $wStep1 = 'completed';
                    $wStep2 = 'disapproved';
                    $wStep3 = 'pending';
                    $wStep4 = 'pending';
                } else {
                    // 'For Review', 'Pending Review', etc.
                    $wStep1 = 'active';
                    $wStep2 = 'pending';
                    $wStep3 = 'pending';
                    $wStep4 = 'pending';
                }

                $workflowSteps = [
                    ["key" => "submitted", "label" => "Submitted", "state" => $wStep1],
                    ["key" => "approved", "label" => "Approved", "state" => $wStep2],
                    ["key" => "completed", "label" => "Completed", "state" => $wStep3],
                    ["key" => "closed", "label" => "Closed", "state" => $wStep4],
                ];

                $cardUnread = $chatUnread[(string)$kind . ":" . (int)$row["id"]] ?? 0;
                $viewDetailsUrl = BASE_URL . "/staff/publication_requests.php?" . http_build_query([
                    "tab" => $tab,
                    "status" => $statusFilter,
                    "q" => $q,
                    "view" => $kind,
                    "id" => (int)$row["id"]
                ]);
                $chatUrl = BASE_URL . "/staff/publication_requests.php?" . http_build_query([
                    "tab" => $tab,
                    "status" => $statusFilter,
                    "q" => $q,
                    "chat" => $kind,
                    "chat_id" => (int)$row["id"]
                ]);
                ?>

                <article class="submission-card event-card-v2 <?= $isActive ? "active" : "" ?>"
                    data-kind="<?= e($kind) ?>"
                    data-status="<?= e((string)$row["status"]) ?>"
                    data-date="<?= e((string)($row["created_at"] ?? "")) ?>"
                    data-title="<?= e(mb_strtolower((string)$row["display_title"])) ?>"
                >
                    <!-- HEADER -->
                    <div class="event-card-v2-header">
                        <div class="header-left">
                            <span class="kind-pill <?= $kind === "recognition" ? "kind-recognition" : ($kind === "contribution" ? "kind-contribution" : "kind-event") ?>">
                                <i class="bi <?= e(pubKindIcon($kind)) ?> me-1"></i>
                                <?= e(pubKindLabel($kind)) ?>
                            </span>
                        </div>
                        <div class="header-right">
                            <?php if ($cardApprovable > 0): ?>
                                <span class="event-card-v2-approval-counter <?= $cardApproved >= $cardApprovable ? "is-approved" : "" ?>" title="Material approvals: <?= (int)$cardApproved ?> of <?= (int)$cardApprovable ?> assigned materials approved">
                                    <i class="bi <?= $cardApproved >= $cardApprovable ? "bi-patch-check-fill" : "bi-hourglass-split" ?> me-1"></i>
                                    <?= (int)$cardApproved ?> of <?= (int)$cardApprovable ?> Approved
                                </span>
                            <?php endif; ?>
                            <span class="status-pill <?= e(pubStatusClass((string)$row["status"])) ?>">
                                <?= e($row["status"]) ?>
                            </span>
                        </div>
                    </div>

                    <!-- MAIN TITLE & METADATA BADGES -->
                    <div class="event-card-v2-main">
                        <h3 class="event-card-v2-title"><?= e($row["display_title"]) ?></h3>
                        <div class="event-card-v2-badges">
                            <span class="meta-badge category-badge">
                                <i class="bi bi-tag-fill me-1"></i><?= e(mb_strimwidth($displayType, 0, 80, "…")) ?>
                            </span>
                            <?php if (!empty($row["office_label"])): ?>
                                <span class="meta-badge dept-badge">
                                    <i class="bi bi-building me-1"></i><?= e($row["office_label"]) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 3-COLUMN COMPACT INFORMATION -->
                    <div class="event-card-v2-info-grid">
                        <div class="info-col">
                            <div class="info-label"><i class="bi bi-person-fill text-primary me-1"></i> REQUESTOR</div>
                            <div class="info-value" title="<?= e($row["submitter_name"] ?: "Unknown User") ?>">
                                <?= e(ucwords(strtolower((string)($row["submitter_name"] ?: "Unknown User")))) ?>
                            </div>
                        </div>
                        <div class="info-col">
                            <div class="info-label"><i class="bi bi-envelope-fill text-primary me-1"></i> EMAIL</div>
                            <div class="info-value text-truncate" title="<?= e($row["email_address"]) ?>">
                                <?= e($row["email_address"]) ?>
                            </div>
                        </div>
                        <div class="info-col">
                            <div class="info-label"><i class="bi bi-calendar-event-fill text-primary me-1"></i> PREFERRED DATE</div>
                            <div class="info-value">
                                <?= e($prefDateFormatted) ?>
                                <?php if ($prefUrgentLabel !== ""): ?>
                                    <span class="pref-urgent-badge <?= e($prefUrgentClass) ?>"><?= e($prefUrgentLabel) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ASSIGNMENT SECTION -->
                    <div class="event-card-v2-assignments">
                        <!-- ASSIGNED STAFF -->
                        <div class="assignment-block">
                            <span class="assignment-title"><i class="bi bi-people-fill me-1"></i> ASSIGNED STAFF</span>
                            <div class="assignment-chips">
                                <?php
                                $hasStaffAssigned = false;
                                foreach ($assignedStaffChips as $sChip):
                                    if ($sChip["name"] !== ""):
                                        $hasStaffAssigned = true;
                                ?>
                                    <span class="staff-chip" title="<?= e($sChip["label"]) ?>: <?= e($sChip["name"]) ?>">
                                        <i class="bi <?= e($sChip["icon"]) ?> me-1"></i>
                                        <strong class="staff-role"><?= e($sChip["label"]) ?>:</strong>
                                        <span class="staff-name"><?= e($sChip["name"]) ?></span>
                                    </span>
                                <?php
                                    endif;
                                endforeach;
                                if (!$hasStaffAssigned):
                                ?>
                                    <span class="staff-chip is-empty"><i class="bi bi-dash-circle me-1"></i> None Assigned</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- APPROVER -->
                        <div class="assignment-block">
                            <span class="assignment-title"><i class="bi bi-shield-check me-1"></i> APPROVER</span>
                            <div class="assignment-chips">
                                <?php
                                $hasApproverAssigned = false;
                                foreach ($approverChips as $aChip):
                                    if ($aChip["name"] !== ""):
                                        $hasApproverAssigned = true;
                                ?>
                                    <span class="staff-chip approver-chip" title="Approver: <?= e($aChip["name"]) ?>">
                                        <i class="bi bi-patch-check-fill me-1 text-success"></i>
                                        <span class="staff-name"><?= e($aChip["name"]) ?></span>
                                    </span>
                                <?php
                                    endif;
                                endforeach;
                                if (!$hasApproverAssigned):
                                ?>
                                    <span class="staff-chip is-empty"><i class="bi bi-dash-circle me-1"></i> Unassigned</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- WORKFLOW TRACKER (hidden by default) -->
                    <div class="event-card-v2-workflow is-hidden">
                        <div class="wf-head">
                            <span class="wf-head-label"><i class="bi bi-diagram-3"></i> Status flow</span>
                            <button type="button" class="wf-hide-btn" aria-expanded="false" title="Show status flow">
                                <i class="bi bi-chevron-up"></i><span>Show</span>
                            </button>
                        </div>
                        <div class="workflow-steps">
                            <?php foreach ($workflowSteps as $idx => $ws): ?>
                                <div class="wf-step wf-step-<?= e($ws["state"]) ?>">
                                    <div class="wf-circle">
                                        <?php if ($ws["state"] === "completed"): ?>
                                            <i class="bi bi-check-lg"></i>
                                        <?php elseif ($ws["state"] === "disapproved"): ?>
                                            <i class="bi bi-x-lg"></i>
                                        <?php elseif ($ws["state"] === "active"): ?>
                                            <span class="wf-pulse"></span>
                                        <?php else: ?>
                                            <span class="wf-num"><?= $idx + 1 ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="wf-label"><?= e($ws["label"]) ?></span>
                                </div>
                                <?php if ($idx < count($workflowSteps) - 1): ?>
                                    <div class="wf-line wf-line-<?= $ws["state"] === "completed" ? "completed" : "pending" ?>"></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- FOOTER -->
                    <div class="submission-foot event-card-v2-footer">
                        <div class="footer-left">
                            <i class="bi bi-clock-history me-1 text-muted"></i>
                            <span class="submission-date">
                                Submitted <?= e(pubDateLabel((string)$row["created_at"], true)) ?>
                            </span>
                        </div>
                        <div class="footer-right d-inline-flex gap-2">
                            <a class="btn btn-sm btn-outline-secondary position-relative card-action-btn" href="<?= e($chatUrl) ?>" title="Open Communication Center">
                                <i class="bi bi-chat-dots me-1"></i> Chat
                                <?php if ($cardUnread > 0): ?>
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                        <?= $cardUnread > 99 ? "99+" : (int)$cardUnread ?>
                                    </span>
                                <?php endif; ?>
                            </a>

                            <button type="button" class="btn btn-sm btn-outline-secondary copy-link-btn card-action-btn" data-copy-url="<?= e($viewDetailsUrl) ?>" title="Copy link to this submission" aria-label="Copy link to this submission">
                                <i class="bi bi-link-45deg me-1"></i> Copy Link
                            </button>

                            <a class="btn btn-sm btn-primary card-action-btn" href="<?= e($viewDetailsUrl) ?>" title="View full submission details">
                                <i class="bi bi-eye me-1"></i> View Details
                            </a>
                        </div>
                    </div>
                </article>

                <?php endforeach; ?>

            </div>

            <?php endif; ?>

        </div>

    </section>

    <?php if ($detail): ?>
    <?php
    $listUrl = BASE_URL . "/staff/publication_requests.php?" . http_build_query([
        "tab" => $tab,
        "status" => $statusFilter,
        "q" => $q,
    ]);
    ?>

    <!-- View submission popup modal -->
    <div
        class="modal fade"
        id="viewSubmissionModal"
        tabindex="-1"
        aria-labelledby="viewSubmissionTitle"
        aria-hidden="true"
        data-list-url="<?= e($listUrl) ?>"
    >
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title" id="viewSubmissionTitle">
                        <span class="vm-title-icon <?= $viewKind === "recognition" ? "is-recognition" : ($viewKind === "contribution" ? "is-contribution" : "") ?>">
                            <i class="bi <?= e(pubKindIcon($viewKind)) ?>"></i>
                        </span>
                        <span class="vm-title-text">
                            <small><?= e(pubKindLabel($viewKind)) ?> • Submission details</small>
                            <strong><?= e(
                                $viewKind === "recognition"
                                    ? ($detail["recognized_entity"] ?? "Untitled recognition")
                                    : (
                                        $viewKind === "contribution"
                                            ? ($detail["content_title"] ?? "Untitled contribution")
                                            : ($detail["title"] ?? "Untitled activity")
                                    )
                            ) ?></strong>
                        </span>
                        <span class="vm-code-chip">
                            <i class="bi bi-hash"></i><?= e(publicationDisplayCode($viewKind, (int) $detail["id"])) ?>
                        </span>
                        <span class="status-pill <?= e(pubStatusClass((string)$detail["status"])) ?>">
                            <?= e($detail["status"]) ?>
                        </span>
                    </h5>

                    <button
                        class="btn-close vm-close-btn"
                        type="button"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>

                <div class="modal-body">

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

        <?php
        $detailStages = [
            "For Review",
            "In Preparation",
            "For Approval",
            "Published",
            "Closed"
        ];

        $detailStageLabels = [
            "For Review" => "Review",
            "In Preparation" => "Prepare",
            "For Approval" => "Approval",
            "Published" => "Published",
            "Closed" => "Closed"
        ];

        $detailStageIndex =
            array_search(
                (string)$detail["status"],
                $detailStages,
                true
            );

        if ($detailStageIndex === false) {
            $detailStageIndex = 0;
        }

        /*
         * A Closed submission is terminal: every step is done and
         * no step is "current". Without this, the Closed step gets
         * both .done and .current (green dot + blue ring at once).
         */
        $detailIsClosed =
            (string)$detail["status"] === "Closed";
        ?>

        <div class="detail-hero">

            <div class="detail-hero-main">
                <div class="detail-kind-icon <?= $viewKind === "recognition" ? "recognition" : ($viewKind === "contribution" ? "contribution" : "") ?>" aria-hidden="true">
                    <i class="bi <?= $viewKind === "recognition" ? "bi-trophy-fill" : ($viewKind === "contribution" ? "bi-journal-plus-fill" : "bi-calendar2-event-fill") ?>"></i>
                </div>

                <div class="min-w-0 flex-grow-1">
                    <p class="detail-eyebrow">
                        <?= e(pubKindLabel($viewKind)) ?>
                    </p>

                    <h2>
                        <?= e(
                            $viewKind === "recognition"
                                ? ($detail["recognized_entity"] ?? "")
                                : (
                                    $viewKind === "contribution"
                                        ? ($detail["content_title"] ?? "")
                                        : ($detail["title"] ?? "")
                                )
                        ) ?>
                    </h2>

                    <p class="detail-hero-sub">
                        <i class="bi bi-calendar-check"></i>
                        Submitted
                        <strong><?= e(pubDateLabel((string)$detail["created_at"], true)) ?></strong>
                        <span>by</span>
                        <strong><?= e(
                            $detail["submitter_name"]
                            ?: "Unknown User"
                        ) ?></strong>
                    </p>

                    <div class="detail-hero-chips">
                        <span class="detail-code-chip">
                            <i class="bi bi-hash"></i><?= e(publicationDisplayCode($viewKind, (int) $detail["id"])) ?>
                        </span>

                        <span
                            class="status-pill <?= e(pubStatusClass((string)$detail["status"])) ?>"
                        >
                            <i class="bi bi-flag-fill me-1"></i><?= e($detail["status"]) ?>
                        </span>

                        <span class="kind-pill <?= $viewKind === "recognition" ? "kind-recognition" : ($viewKind === "contribution" ? "kind-contribution" : "kind-event") ?>">
                            <i class="bi <?= e(pubKindIcon($viewKind)) ?>"></i><?= e(pubKindLabel($viewKind)) ?>
                        </span>
                    </div>

                    <?php
                    $detailTrackToken = trim((string) ($detail["public_token"] ?? ""));
                    $detailTrackUrl = (
                        strlen($detailTrackToken) === 64 &&
                        ctype_xdigit($detailTrackToken)
                    )
                        ? rtrim(BASE_URL, "/") . "/track.php?token=" . rawurlencode($detailTrackToken)
                        : "";
                    ?>

                    <?php if ($detailTrackUrl !== ""): ?>
                        <div class="vm-track-card">
                            <span class="vm-track-icon">
                                <i class="bi bi-upc-scan"></i>
                            </span>
                            <div class="min-w-0 flex-grow-1">
                                <div class="detail-label mb-1">Public tracking link • no sign-in required</div>
                                <div class="input-group input-group-sm">
                                    <input
                                        type="text"
                                        class="form-control"
                                        value="<?= e($detailTrackUrl) ?>"
                                        readonly
                                        onclick="this.select()"
                                        title="Public tracking link (no sign-in required)"
                                    >

                                    <button
                                        class="btn btn-primary"
                                        type="button"
                                        onclick="navigator.clipboard && navigator.clipboard.writeText('<?= e($detailTrackUrl) ?>'); this.innerHTML='<i class=&quot;bi bi-check-lg&quot;></i> Copied'; setTimeout(() => { this.innerHTML='<i class=&quot;bi bi-copy&quot;></i> Copy'; }, 1800);"
                                    >
                                        <i class="bi bi-copy"></i> Copy
                                    </button>

                                    <a
                                        class="btn btn-outline-secondary"
                                        href="<?= e($detailTrackUrl) ?>"
                                        target="_blank"
                                        rel="noopener"
                                        title="Open public tracking page"
                                    >
                                        <i class="bi bi-box-arrow-up-right"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="detail-head-actions">
                <button
                    class="btn btn-sm btn-primary"
                    type="button"
                    data-bs-toggle="modal"
                    data-bs-target="#editSubmissionModal"
                >
                    <i class="bi bi-pencil-square me-1"></i>
                    Edit details
                </button>

                <a
                    class="btn btn-sm btn-outline-primary position-relative"
                    href="<?= e(BASE_URL) ?>/staff/publication_requests.php?<?= e(http_build_query([
                        "tab" => $tab,
                        "status" => $statusFilter,
                        "q" => $q,
                        "chat" => $viewKind,
                        "chat_id" => (int)$detail["id"]
                    ])) ?>"
                    title="Open Communication Center"
                >
                    <i class="bi bi-chat-dots me-1"></i>
                    Message

                    <?php
                    $headerUnread =
                        $chatUnread[
                            (string)$viewKind
                            . ":"
                            . (int)$detail["id"]
                        ]
                        ?? 0;
                    ?>

                    <?php if ($headerUnread > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                        <?= $headerUnread > 99 ? "99+" : (int)$headerUnread ?>
                    </span>
                    <?php endif; ?>
                </a>

                <button
                    class="btn btn-sm btn-outline-secondary"
                    type="button"
                    onclick="window.print()"
                    title="Print submission"
                >
                    <i class="bi bi-printer me-1"></i>
                    Print
                </button>

                <button
                    class="btn btn-sm btn-icon-danger"
                    type="button"
                    data-bs-toggle="modal"
                    data-bs-target="#deleteSubmissionModal"
                    title="Delete submission"
                >
                    <i class="bi bi-trash me-1"></i>
                    Delete
                </button>
            </div>

            <div class="detail-meta-strip">
                <div class="detail-meta-item">
                    <div class="detail-label"><i class="bi bi-person-circle"></i> Submitted by</div>
                    <div class="detail-value">
                        <?= e(
                            $detail["submitter_name"]
                            ?: "Unknown User"
                        ) ?>
                    </div>
                </div>

                <div class="detail-meta-item">
                    <div class="detail-label"><i class="bi bi-envelope"></i> Email address</div>
                    <div class="detail-value text-truncate" title="<?= e($detail["email_address"]) ?>">
                        <?= e($detail["email_address"]) ?>
                    </div>
                </div>

                <div class="detail-meta-item">
                    <div class="detail-label"><i class="bi bi-calendar-event"></i> Preferred publication</div>
                    <div class="detail-value">
                        <?= e(
                            pubDateLabel(
                                $detail["preferred_publication_date"]
                                ?? null
                            )
                        ) ?>
                    </div>
                </div>

                <div class="detail-meta-item">
                    <div class="detail-label"><i class="bi bi-person-check"></i> Last reviewed by</div>
                    <div class="detail-value">
                        <?= e(
                            $detail["reviewer_name"]
                            ?: "Not yet reviewed"
                        ) ?>
                        <?php if (!empty($detail["reviewed_at"])): ?>
                            <div class="text-muted fw-normal" style="font-size:.68rem;"><?= e(
                                pubDateLabel(
                                    $detail["reviewed_at"],
                                    true
                                )
                            ) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="detail-stepper">
                <?php
                $detailStageIcons = [
                    "For Review" => "bi-search",
                    "In Preparation" => "bi-pencil-square",
                    "For Approval" => "bi-patch-check",
                    "Published" => "bi-megaphone-fill",
                    "Closed" => "bi-lock-fill",
                ];
                ?>
                <?php foreach ($detailStages as $stageIndex => $stageName): ?>
                <div class="detail-step <?= ($stageIndex < $detailStageIndex || $detailIsClosed) ? "done" : "" ?> <?= ($stageName === (string)$detail["status"] && !$detailIsClosed) ? "current" : "" ?>" title="<?= e($stageName) ?>">
                    <div class="detail-step-dot">
                        <i class="bi <?= e($detailStageIcons[$stageName] ?? "bi-check-lg") ?>"></i>
                    </div>
                    <div class="detail-step-label">
                        <?= e($detailStageLabels[$stageName]) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        </div>

        <div class="detail-body">

            <div class="vm-nav-bar">
                <a class="vm-nav-item active" href="#sec-track" data-target="sec-track"><i class="bi bi-speedometer2"></i> Timeline</a>
                <a class="vm-nav-item" href="#sec-profile" data-target="sec-profile"><i class="bi bi-card-text"></i> Profile</a>
                <a class="vm-nav-item" href="#sec-info" data-target="sec-info"><i class="bi bi-info-circle"></i> Submission Info</a>
                <a class="vm-nav-item" href="#vmMaterialContent" data-target="vmMaterialContent"><i class="bi bi-journal-richtext"></i> Content</a>
                <a class="vm-nav-item" href="#sec-team" data-target="sec-team"><i class="bi bi-people-fill"></i> Production & Approvals</a>
                <a class="vm-nav-item" href="#vmMaterialLinks" data-target="vmMaterialLinks"><i class="bi bi-link-45deg"></i> Links</a>
                <?php if (!empty($supportingFilesKind)): ?>
                    <a class="vm-nav-item" href="#vmSupportingFiles" data-target="vmSupportingFiles"><i class="bi bi-paperclip"></i> Files (<?= count($supportingFiles) ?>)</a>
                <?php endif; ?>
            </div>

            <div class="detail-section" id="sec-track" tabindex="-1">

                <div class="detail-section-head">
                    <i class="bi bi-speedometer2"></i>
                    <p class="detail-section-title">
                        Tracking Workflow • <?= $trackProgressPct ?>% Complete
                        <small>Live progress, monitoring metrics and status history</small>
                    </p>
                </div>

                <div class="detail-section-body">

                    <div class="track-progress-wrap">
                        <div class="track-progress-top">
                            <span class="track-progress-label">
                                <i class="bi bi-flag me-1"></i><?= e((string)$detail["status"]) ?>
                                — Step <?= ($trackStageIndex + 1) ?> of <?= count($trackStages) ?>
                            </span>
                            <span class="track-progress-pct"><?= $trackProgressPct ?>%</span>
                        </div>
                        <div class="track-progress-bar">
                            <div class="track-progress-fill" style="width:<?= $trackProgressPct ?>%"></div>
                        </div>

                        <div class="track-monitor-grid">
                            <div class="track-monitor-item">
                                <div class="detail-label">Submitted</div>
                                <div class="detail-value"><?= e(pubDateLabel((string)$detail["created_at"])) ?></div>
                            </div>
                            <div class="track-monitor-item">
                                <div class="detail-label">Days Tracked</div>
                                <div class="detail-value"><?= $trackDaysTotal ?>d total • <?= $trackDaysInStatus ?>d in stage</div>
                            </div>
                            <div class="track-monitor-item">
                                <div class="detail-label">Preferred Date</div>
                                <div class="detail-value <?= e($trackPrefClass) ?>"><?= e($trackPrefLabel) ?></div>
                            </div>
                            <div class="track-monitor-item">
                                <div class="detail-label">Last Update</div>
                                <div class="detail-value"><?= e(pubDateLabel((string)($detail["reviewed_at"] ?? $detail["updated_at"] ?? $detail["created_at"]), true)) ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="track-timeline">
                        <?php if (!$trackHistory): ?>
                            <div class="track-empty">
                                <i class="bi bi-info-circle me-1"></i>
                                No status changes tracked yet — currently
                                <strong><?= e((string)$detail["status"]) ?></strong>
                                since submission. Future workflow saves will appear here with actor and timestamp.
                            </div>
                        <?php else: ?>
                            <?php foreach ($trackHistory as $hIdx => $h): ?>
                            <div class="track-event <?= $hIdx === 0 ? "is-first" : "" ?>">
                                <div class="track-dot">
                                    <i class="bi <?= $hIdx === 0 ? "bi-flag" : "bi-check-lg" ?>"></i>
                                </div>
                                <div class="track-event-head">
                                    <?php if (!empty($h["old_status"])): ?>
                                        <span><?= e((string)$h["old_status"]) ?></span>
                                        <span class="track-arrow">→</span>
                                    <?php else: ?>
                                        <span>Submitted as</span>
                                    <?php endif; ?>
                                    <span><?= e((string)$h["new_status"]) ?></span>
                                </div>
                                <div class="track-meta">
                                    <?= e((string)($h["actor_name"] ?? "System")) ?> •
                                    <?= e(pubDateLabel((string)($h["created_at"] ?? ""), true)) ?>
                                </div>
                                <?php if (trim((string)($h["notes_snapshot"] ?? "")) !== ""): ?>
                                <div class="track-notes"><?= e((string)$h["notes_snapshot"]) ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                </div>

            </div>

            <?php if ($viewKind === "event"): ?>

            <div class="detail-section" id="sec-profile" tabindex="-1">

                <div class="detail-section-head">
                    <i class="bi bi-calendar2-event"></i>
                    <p class="detail-section-title">
                        Activity Profile
                    </p>
                </div>

                <div class="detail-section-body">

                <div class="detail-field-grid">

                <?php
                $eventFields = [
                    "Type of Activity" =>
                        $detail["activity_type"],

                    "Start Date" =>
                        pubDateLabel(
                            $detail["start_date"]
                            ?? null
                        ),

                    "End Date" =>
                        pubDateLabel(
                            $detail["end_date"]
                            ?? null
                        ),

                    "Time" =>
                        $detail["activity_time"]
                        ?: "—",

                    "Venue" =>
                        $detail["venue"]
                        ?: "—",

                    "Lead Office / Unit / Committee Division" =>
                        $detail["lead_office"]
                        ?: "—",

                    "Partner Office(s) / Organization(s)" =>
                        $detail["partner_offices"]
                        ?: "—",

                    "Person-in-Charge" =>
                        $detail["person_in_charge"]
                        ?: "—",

                    "Estimated / Actual Participants" =>
                        $detail["participant_count"] !== null
                            ? (string)$detail["participant_count"]
                            : "—"
                ];
                ?>

                <?php foreach ($eventFields as $label => $value): ?>

                <div class="detail-field">
                    <div class="detail-label">
                        <?= e($label) ?>
                    </div>

                    <div class="detail-value">
                        <?= e((string)$value) ?>
                    </div>
                </div>

                <?php endforeach; ?>

                </div>

                </div>

            </div>

            <?php
            $eventNarrativeFields = [
                "Background" =>
                    $detail["background"],

                "Objectives" =>
                    $detail["objectives"],

                "Activity Highlights" =>
                    $detail["activity_highlights"],

                "Key Outputs / Agreements" =>
                    $detail["key_outputs"],

                "Impact / Significance" =>
                    $detail["impact_significance"],

                "Quotable Statement" =>
                    $detail["quotable_statement"],

                "How the activity should be remembered" =>
                    $detail["remembrance_sentence"]
            ];
            ?>

            <div class="detail-section">

                <div class="detail-section-head">
                    <i class="bi bi-journal-text"></i>
                    <p class="detail-section-title">
                        Documentation
                    </p>
                </div>

                <div class="detail-section-body">

                <?php foreach ($eventNarrativeFields as $label => $value): ?>

                    <?php if (
                        trim(
                            (string)
                            $value
                        )
                        !==
                        ""
                    ): ?>

                    <div class="detail-narrative">
                        <div class="detail-label">
                            <?= e($label) ?>
                        </div>

                        <div class="detail-value">
                            <?= e((string)$value) ?>
                        </div>
                    </div>

                    <?php endif; ?>

                <?php endforeach; ?>

                </div>

            </div>

            <?php elseif ($viewKind === "recognition"): ?>

            <div class="detail-section" id="sec-profile" tabindex="-1">

                <div class="detail-section-head">
                    <i class="bi bi-trophy"></i>
                    <p class="detail-section-title">
                        Recognition / Milestone
                    </p>
                </div>

                <div class="detail-section-body">

                <div class="detail-field-grid">

                <div class="detail-field full">
                    <div class="detail-label">
                        Type
                    </div>

                    <div class="detail-value">
                        <?= e(
                            implode(
                                ", ",
                                pubDecodeRecognitionTypes(
                                    $detail["recognition_type"]
                                    ?? null
                                )
                            )
                        ) ?>
                    </div>
                </div>

                <div class="detail-field full">
                    <div class="detail-label">
                        Who or What Was Recognized?
                    </div>

                    <div class="detail-value">
                        <?= e($detail["recognized_entity"]) ?>
                    </div>
                </div>

                <div class="detail-field full">
                    <div class="detail-label">
                        What Was Achieved?
                    </div>

                    <div class="detail-value">
                        <?= e($detail["achievement"]) ?>
                    </div>
                </div>

                <?php if (
                    trim(
                        (string)
                        $detail["supporting_information"]
                    )
                    !==
                    ""
                ): ?>

                <div class="detail-field full">
                    <div class="detail-label">
                        Supporting Information
                    </div>

                    <div class="detail-value">
                        <?= e($detail["supporting_information"]) ?>
                    </div>
                </div>

                <?php endif; ?>

                </div>

                </div>

            </div>

            <?php elseif ($viewKind === "contribution"): ?>

            <div class="detail-section" id="sec-profile" tabindex="-1">

                <div class="detail-section-head">
                    <i class="bi bi-journal-plus"></i>
                    <p class="detail-section-title">
                        Content Profile
                    </p>
                </div>

                <div class="detail-section-body">

                <div class="detail-field-grid">

                <?php
                $contributionFields = [
                    "Content Type" =>
                        $detail["contribution_type"]
                        ?: "—",

                    "Publication Channel" =>
                        $detail["publication_channel"]
                        ?: "—",

                    "Lead Office / Unit" =>
                        $detail["lead_office"]
                        ?: "—",

                    "Focal Person / Contributor" =>
                        $detail["focal_person"]
                        ?: "—",

                    "Target Audience" =>
                        $detail["target_audience"]
                        ?: "—"
                ];
                ?>

                <div class="detail-field full">
                    <div class="detail-label">
                        Content Title
                    </div>

                    <div class="detail-value">
                        <?= e($detail["content_title"] ?? "—") ?>
                    </div>
                </div>

                <?php foreach ($contributionFields as $label => $value): ?>

                <div class="detail-field">
                    <div class="detail-label">
                        <?= e($label) ?>
                    </div>

                    <div class="detail-value">
                        <?= e((string)$value) ?>
                    </div>
                </div>

                <?php endforeach; ?>

                </div>

                </div>

            </div>

            <?php
            $contributionNarrativeFields = [
                "Purpose / Objective" =>
                    $detail["purpose_objective"],

                "Key Message" =>
                    $detail["key_message"],

                "Social Media Caption" =>
                    $detail["social_media_caption"],

                "Website Content" =>
                    $detail["website_content"],

                "Call to Action" =>
                    $detail["call_to_action"],

                "Hashtags" =>
                    $detail["hashtags"],

                "Reference Links" =>
                    $detail["reference_links"]
            ];
            ?>

            <div class="detail-section">

                <div class="detail-section-head">
                    <i class="bi bi-journal-text"></i>
                    <p class="detail-section-title">
                        Content Details
                    </p>
                </div>

                <div class="detail-section-body">

                <?php foreach ($contributionNarrativeFields as $label => $value): ?>

                    <?php if (
                        trim(
                            (string)
                            $value
                        )
                        !==
                        ""
                    ): ?>

                    <div class="detail-narrative">
                        <div class="detail-label">
                            <?= e($label) ?>
                        </div>

                        <div class="detail-value">
                            <?= nl2br(e((string)$value)) ?>
                        </div>
                    </div>

                    <?php endif; ?>

                <?php endforeach; ?>

                </div>

            </div>

            <?php endif; ?>

            <?php
            $supportingFiles = [];
            $supportingFilesKind = "";
            $supportingFilesMax = 5;

            /*
             * Event / Activity submissions carry no files;
             * recognition allows 5, content contributions allow 8.
             * Same supporting-files card for both file-carrying kinds.
             */
            if (
                $viewKind === "recognition"
                ||
                $viewKind === "contribution"
            ) {
                $supportingFiles =
                    pubDecodeFiles(
                        $detail["supporting_files"]
                        ?? null
                    );

                $supportingFilesKind = $viewKind;

                $supportingFilesMax =
                    pubSubmissionMaxFiles(
                        $viewKind
                    );
            }
            ?>

            <?php if ($supportingFilesKind !== ""): ?>

            <div class="detail-section" id="vmSupportingFiles" tabindex="-1">

                <div class="detail-section-head">
                    <i class="bi bi-paperclip"></i>
                    <p class="detail-section-title">
                        Supporting Files
                        <span class="text-muted fw-normal">
                            (<?= count($supportingFiles) ?>/<?= (int)$supportingFilesMax ?>)
                        </span>
                    </p>
                </div>

                <div class="detail-section-body">

                <?php if (!$supportingFiles): ?>

                <div class="detail-value text-muted mb-2">
                    No supporting files were uploaded.
                </div>

                <?php else: ?>

                <?php foreach (
                    $supportingFiles
                    as $fileIndex =>
                        $file
                ): ?>

                <?php
                $downloadParam =
                    $supportingFilesKind === "contribution"
                        ? "download_contribution"
                        : "download_recognition";

                $fileInlineUrl =
                    BASE_URL
                    . "/staff/publication_requests.php"
                    . "?" . $downloadParam . "="
                    . (int)$detail["id"]
                    . "&file_index="
                    . (int)$fileIndex
                    . "&inline=1";

                $fileDownloadUrl =
                    BASE_URL
                    . "/staff/publication_requests.php"
                    . "?" . $downloadParam . "="
                    . (int)$detail["id"]
                    . "&file_index="
                    . (int)$fileIndex;
                ?>

                <?php
                $fileExt =
                    strtolower(
                        (string)(
                            $file["extension"]
                            ?? ""
                        )
                    );

                $fileBadgeClass = "file-type-badge";

                if ($fileExt === "pdf") {
                    $fileBadgeClass .= " is-pdf";
                } elseif (
                    in_array(
                        $fileExt,
                        ["doc", "docx"],
                        true
                    )
                ) {
                    $fileBadgeClass .= " is-doc";
                } elseif (
                    in_array(
                        $fileExt,
                        ["jpg", "jpeg", "png", "webp"],
                        true
                    )
                ) {
                    $fileBadgeClass .= " is-img";
                } elseif (
                    in_array(
                        $fileExt,
                        ["mp4", "mov"],
                        true
                    )
                ) {
                    $fileBadgeClass .= " is-vid";
                }

                $fileBadgeIcon =
                    $fileExt === "pdf"
                        ? "bi-file-earmark-pdf-fill"
                        : (
                            in_array(
                                $fileExt,
                                ["doc", "docx"],
                                true
                            )
                            ? "bi-file-earmark-word-fill"
                            : (
                                in_array(
                                    $fileExt,
                                    ["jpg", "jpeg", "png", "webp"],
                                    true
                                )
                                ? "bi-file-earmark-image-fill"
                                : (
                                    in_array(
                                        $fileExt,
                                        ["mp4", "mov"],
                                        true
                                    )
                                    ? "bi-file-earmark-play-fill"
                                    : "bi-paperclip"
                                )
                            )
                        );
                ?>

                <div class="file-item">

                    <div class="file-main">
                        <div class="<?= $fileBadgeClass ?>" aria-hidden="true">
                            <i class="bi <?= $fileBadgeIcon ?>"></i>
                        </div>

                    <div class="min-w-0">
                        <div class="file-name">
                            <?= e(
                                $file["original_name"]
                                ?? "Supporting file"
                            ) ?>
                        </div>

                        <div class="file-meta">
                            <?= e(
                                strtoupper(
                                    (string)(
                                        $file["extension"]
                                        ?? ""
                                    )
                                )
                            ) ?>

                            <?php if (
                                isset(
                                    $file["size"]
                                )
                            ): ?>
                                •
                                <?= e(
                                    pubFileSize(
                                        (int)
                                        $file["size"]
                                    )
                                ) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    </div>

                    <div class="file-actions">
                        <button
                            class="btn btn-sm btn-outline-primary file-view-btn"
                            type="button"
                            data-bs-toggle="modal"
                            data-bs-target="#filePreviewModal"
                            data-file-name="<?= e($file["original_name"] ?? "Supporting file") ?>"
                            data-file-url="<?= e($fileInlineUrl) ?>"
                            data-file-ext="<?= e(strtolower((string)($file["extension"] ?? ""))) ?>"
                            title="View file"
                        >
                            <i class="bi bi-eye"></i>
                        </button>

                        <a
                            class="btn btn-sm btn-outline-secondary"
                            href="<?= e($fileDownloadUrl) ?>"
                            title="Download file"
                        >
                            <i class="bi bi-download"></i>
                        </a>

                        <button
                            class="btn btn-sm btn-outline-secondary file-rename-btn"
                            type="button"
                            data-bs-toggle="modal"
                            data-bs-target="#renameFileModal"
                            data-file-index="<?= (int)$fileIndex ?>"
                            data-file-name="<?= e($file["original_name"] ?? "") ?>"
                            title="Rename file"
                        >
                            <i class="bi bi-pencil"></i>
                        </button>

                        <form
                            method="post"
                            class="d-inline"
                            onsubmit="return confirm('Delete this supporting file?');"
                        >
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= e($csrf) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="delete_file"
                            >

                            <input
                                type="hidden"
                                name="kind"
                                value="<?= e($supportingFilesKind) ?>"
                            >

                            <input
                                type="hidden"
                                name="id"
                                value="<?= (int)$detail["id"] ?>"
                            >

                            <input
                                type="hidden"
                                name="file_index"
                                value="<?= (int)$fileIndex ?>"
                            >

                            <button
                                class="btn btn-sm btn-icon-danger"
                                type="submit"
                                title="Delete file"
                            >
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>

                </div>

                <?php endforeach; ?>

                <?php endif; ?>

                <?php if (count($supportingFiles) < (int)$supportingFilesMax): ?>

                <form
                    class="add-files-box"
                    method="post"
                    enctype="multipart/form-data"
                >
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="add_files"
                    >

                    <input
                        type="hidden"
                        name="kind"
                        value="<?= e($supportingFilesKind) ?>"
                    >

                    <input
                        type="hidden"
                        name="id"
                        value="<?= (int)$detail["id"] ?>"
                    >

                    <label class="filter-label">
                        Add supporting files
                        <?php if ($supportingFilesKind === "contribution"): ?>
                        (PDF, Word, JPG, PNG, WEBP, MP4, MOV • max 10 MB each • up to 8 files)
                        <?php else: ?>
                        (PDF, Word, JPG, PNG, WEBP • max 10 MB each • up to 5 files)
                        <?php endif; ?>
                    </label>

                    <div class="d-flex gap-2">
                        <input
                            type="file"
                            class="form-control form-control-sm"
                            name="supporting_files[]"
                            accept="<?= $supportingFilesKind === "contribution" ? ".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp,.mp4,.mov" : ".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" ?>"
                            multiple
                            required
                        >

                        <button
                            class="btn btn-sm btn-primary flex-shrink-0"
                            type="submit"
                        >
                            <i class="bi bi-upload me-1"></i>
                            Add
                        </button>
                    </div>
                </form>

                <?php endif; ?>

                </div>

            </div>

            <?php endif; ?>

            <div class="detail-section" id="sec-info" tabindex="-1">

                <div class="detail-section-head">
                    <i class="bi bi-info-circle"></i>
                    <p class="detail-section-title">
                        Submission Information
                        <small>Submitter, schedule and consent record</small>
                    </p>
                </div>

                <div class="detail-section-body">

                <div class="detail-field-grid">

                <div class="detail-field">
                    <div class="detail-label">
                        Submitted By
                    </div>

                    <div class="detail-value">
                        <?= e(
                            $detail["submitter_name"] ?? "Unknown User"
                        ) ?>
                    </div>
                </div>

                <div class="detail-field">
                    <div class="detail-label">
                        Email Address
                    </div>

                    <div class="detail-value">
                        <?= e($detail["email_address"] ?? "") ?>
                    </div>
                </div>

                <div class="detail-field">
                    <div class="detail-label">
                        Preferred Publication Date
                    </div>

                    <div class="detail-value">
                        <?= e(
                            pubDateLabel(
                                $detail["preferred_publication_date"]
                                ?? null
                            )
                        ) ?>
                    </div>
                </div>

                <?php if (!empty($detail["reviewer_name"])): ?>

                <div class="detail-field">
                    <div class="detail-label">
                        Last Reviewed By
                    </div>

                    <div class="detail-value">
                        <?= e($detail["reviewer_name"]) ?>
                        <?php if (!empty($detail["reviewed_at"])): ?>
                            •
                            <?= e(
                                pubDateLabel(
                                    $detail["reviewed_at"],
                                    true
                                )
                            ) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php endif; ?>

                <div class="detail-field">
                    <div class="detail-label">
                        Data Privacy Consent
                    </div>

                    <div class="detail-value">
                        <?php if (!empty($detail["privacy_confirmed"])): ?>
                            <span class="status-pill status-published">
                                <i class="bi bi-shield-check"></i>
                                Confirmed
                            </span>
                        <?php else: ?>
                            <span class="status-pill status-review">
                                Not confirmed
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                </div>

                </div>

            </div>

            <?php
            $teamRoles = pubTaskRoles();
            $teamAssignments = pubTaskAssignments(
                $conn,
                $viewKind,
                (int)$detail["id"]
            );
            $canSetApprover = in_array(
                $role,
                ["supervisor", "admin"],
                true
            );
            $canApproveMaterials = $canSetApprover;
            $taskApprovals = pubTaskApprovals(
                $conn,
                $viewKind,
                (int)$detail["id"]
            );
            $assignedApprovalRoles = array_values(
                array_filter(
                    pubApprovableTaskRoles(),
                    static function (string $r) use ($teamAssignments): bool {
                        return
                            !empty($teamAssignments[$r])
                            &&
                            (int)($teamAssignments[$r]["user_id"] ?? 0) > 0;
                    }
                )
            );
            $assignedApprovalTotal = count($assignedApprovalRoles);
            $approvedCount = 0;
            foreach ($assignedApprovalRoles as $countRole) {
                if (!empty($taskApprovals[$countRole])) {
                    $approvedCount++;
                }
            }

            $materialContent = pubGetMaterialContent($conn, $viewKind, (int)$detail["id"]);
            $materialMaxChars = pubMaterialMaxChars();
            $materialMaxLinks = pubMaterialMaxLinks();
            $materialLinkTypes = pubMaterialLinkTypes();
            $materialLinks = is_array($materialContent["links"] ?? null) ? $materialContent["links"] : [];
            // Links unlock only when every assigned material is approved.
            $linksUnlocked = $assignedApprovalTotal > 0 && $approvedCount >= $assignedApprovalTotal;
            $canSaveLinks = $canApproveMaterials && $linksUnlocked;
            ?>

            <!-- Publication Content Section -->
            <div class="detail-section shadow-sm border rounded-3 p-3 bg-white mb-3" id="vmMaterialContent">
                <div class="detail-section-head d-flex align-items-center justify-between pb-2 mb-3 border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-journal-richtext text-primary fs-5"></i>
                        <div>
                            <p class="detail-section-title mb-0 fw-bold text-dark">
                                Publication Content
                            </p>
                            <small class="text-muted">Draft + Final paragraph text (<?= (int)$materialMaxChars ?> chars max)</small>
                        </div>
                    </div>
                </div>

                <div class="detail-section-body">
                    <div class="material-grid">
                        <div>
                            <form method="post" class="material-text-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="save_draft_text">
                                <input type="hidden" name="kind" value="<?= e($viewKind) ?>">
                                <input type="hidden" name="id" value="<?= (int)$detail["id"] ?>">

                                <label class="filter-label fw-bold" for="vmDraftText">
                                    Draft text <span class="text-muted fw-normal">(paragraph, editable)</span>
                                </label>
                                <textarea
                                    id="vmDraftText"
                                    class="form-control form-control-sm material-textarea"
                                    name="draft_text"
                                    rows="5"
                                    maxlength="<?= (int)$materialMaxChars ?>"
                                    data-counter="vmDraftCount"
                                    placeholder="Write the draft paragraph here (up to <?= (int)$materialMaxChars ?> characters)…"
                                ><?= e((string)($materialContent["draft_text"] ?? "")) ?></textarea>
                                <div class="char-counter text-end text-muted small mt-1" id="vmDraftCount">0/<?= (int)$materialMaxChars ?></div>

                                <button class="btn btn-sm btn-primary w-100 mt-2 font-bold" type="submit">
                                    <i class="bi bi-check2-circle me-1"></i>
                                    Save Draft Text
                                </button>
                            </form>

                            <button
                                class="btn btn-sm btn-outline-primary w-100 mt-2"
                                type="button"
                                data-bs-toggle="modal"
                                data-bs-target="#moveDraftModal"
                                id="moveDraftBtn"
                            >
                                <i class="bi bi-arrow-right-circle me-1"></i>
                                Move to Final Text
                            </button>
                        </div>
                        <div>
                            <form method="post" class="material-text-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="save_final_text">
                                <input type="hidden" name="kind" value="<?= e($viewKind) ?>">
                                <input type="hidden" name="id" value="<?= (int)$detail["id"] ?>">

                                <label class="filter-label fw-bold" for="vmFinalText">
                                    Final text <span class="text-muted fw-normal">(paragraph, editable)</span>
                                </label>
                                <textarea
                                    id="vmFinalText"
                                    class="form-control form-control-sm material-textarea"
                                    name="final_text"
                                    rows="5"
                                    maxlength="<?= (int)$materialMaxChars ?>"
                                    data-counter="vmFinalCount"
                                    placeholder="Write the final paragraph here (up to <?= (int)$materialMaxChars ?> characters)…"
                                    readonly
                                ><?= e((string)($materialContent["final_text"] ?? "")) ?></textarea>
                                <div class="char-counter text-end text-muted small mt-1" id="vmFinalCount">0/<?= (int)$materialMaxChars ?></div>

                                <div class="d-flex gap-2 mt-2">
                                    <button class="btn btn-sm btn-outline-success flex-grow-1 font-bold" type="button" id="vmFinalEdit">
                                        <i class="bi bi-pencil-square me-1"></i>
                                        Edit
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary" type="button" id="vmFinalCancel" hidden>
                                        Cancel
                                    </button>
                                </div>

                                <button class="btn btn-sm btn-success w-100 mt-2 font-bold" type="submit" id="vmFinalSave" disabled>
                                    <i class="bi bi-check2-circle me-1"></i>
                                    Save Final Text
                                </button>
                            </form>
                        </div>
                    </div>

                    <?php if (!empty($materialContent["updated_at"])): ?>
                        <div class="material-meta text-muted small mt-2">
                            <i class="bi bi-clock-history"></i>
                            Last saved<?= ($materialContent["updater_name"] ?? "") !== "" ? " by " . e($materialContent["updater_name"]) : "" ?>
                            • <?= e(pubDateLabel((string)$materialContent["updated_at"], true)) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Move Draft to Final (password-confirmed) -->
            <div
                class="modal fade"
                id="moveDraftModal"
                tabindex="-1"
                aria-labelledby="moveDraftTitle"
                aria-hidden="true"
            >
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="post" id="moveDraftForm">
                            <div class="modal-header">
                                <h5 class="modal-title" id="moveDraftTitle">
                                    <i class="bi bi-arrow-right-circle me-1"></i>
                                    Move Draft to Final Text
                                </h5>

                                <button
                                    class="btn-close"
                                    type="button"
                                    data-bs-dismiss="modal"
                                    aria-label="Close"
                                ></button>
                            </div>

                            <div class="modal-body">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrf) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="move_draft_to_final"
                                >

                                <input
                                    type="hidden"
                                    name="kind"
                                    value="<?= e($viewKind) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= (int)$detail["id"] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="draft_text"
                                    id="moveDraftText"
                                    value=""
                                >

                                <div class="alert alert-info py-2 small">
                                    <i class="bi bi-info-circle me-1"></i>
                                    The current Draft text
                                    (<strong id="moveDraftCount">0 characters</strong>)
                                    will overwrite the Final text. The draft itself is kept.
                                </div>

                                <div>
                                    <label
                                        class="filter-label"
                                        for="moveDraftPassword"
                                    >
                                        Confirm with your account password *
                                    </label>

                                    <input
                                        type="password"
                                        class="form-control form-control-sm"
                                        name="confirm_password"
                                        id="moveDraftPassword"
                                        autocomplete="current-password"
                                        placeholder="Enter your login password"
                                        required
                                    >

                                    <div class="form-text">
                                        Type the password you use to log in. The move proceeds only if it matches.
                                    </div>
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button
                                    class="btn btn-sm btn-outline-secondary"
                                    type="button"
                                    data-bs-dismiss="modal"
                                >
                                    Cancel
                                </button>

                                <button
                                    class="btn btn-sm btn-primary"
                                    type="submit"
                                >
                                    <i class="bi bi-arrow-right-circle me-1"></i>
                                    Move to Final
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="detail-duo">
            <div class="detail-section" id="sec-team" tabindex="-1">

                <div class="detail-section-head">
                    <i class="bi bi-people-fill"></i>
                    <p class="detail-section-title">
                        Production Team
                        <small>Assign layout, video, posting and approver seats</small>
                    </p>
                    <?php if (!empty($assignedApprovalTotal)): ?>
                        <span class="team-head-pill"><?= (int)$approvedCount ?>/<?= (int)$assignedApprovalTotal ?> approved</span>
                    <?php endif; ?>
                </div>

                <div class="detail-section-body">

                <?php
                $teamRoles = pubTaskRoles();
                $teamAssignments = pubTaskAssignments(
                    $conn,
                    $viewKind,
                    (int)$detail["id"]
                );
                $teamPool = pubStaffPool($conn, false);
                $approverPool = pubStaffPool($conn, true);
                $canSetApprover = in_array(
                    $role,
                    ["supervisor", "admin"],
                    true
                );
                $canApproveMaterials = $canSetApprover;
                $taskApprovals = pubTaskApprovals(
                    $conn,
                    $viewKind,
                    (int)$detail["id"]
                );
                // Material Approvals reflects ONLY assigned seats.
                $assignedApprovalRoles = array_values(
                    array_filter(
                        pubApprovableTaskRoles(),
                        static function (string $r) use ($teamAssignments): bool {
                            return
                                !empty($teamAssignments[$r])
                                &&
                                (int)($teamAssignments[$r]["user_id"] ?? 0) > 0;
                        }
                    )
                );
                $approvedCount = 0;
                foreach ($assignedApprovalRoles as $countRole) {
                    if (!empty($taskApprovals[$countRole])) {
                        $approvedCount++;
                    }
                }
                $assignedApprovalTotal = count($assignedApprovalRoles);
                $supportingFileTotal = count($supportingFiles ?? []);
                ?>
                <?php if ($assignedApprovalTotal === 0): ?>
                    <div class="team-approval-summary is-none">
                        <i class="bi bi-person-dash"></i>
                        No assigned materials yet — assign Layout / Video / Posting seats above to enable approvals
                    </div>
                <?php elseif ($approvedCount > 0): ?>
                    <div class="team-approval-summary">
                        <i class="bi bi-patch-check-fill"></i>
                        <?= (int)$approvedCount ?>/<?= (int)$assignedApprovalTotal ?> assigned materials approved
                    </div>
                <?php else: ?>
                    <div class="team-approval-summary is-none">
                        <i class="bi bi-hourglass-split"></i>
                        <?= (int)$assignedApprovalTotal ?> assigned material<?= $assignedApprovalTotal === 1 ? "" : "s" ?> awaiting supervisor approval
                    </div>
                <?php endif; ?>

                <div class="team-step-label"><span>Step 1</span> Assign the team</div>

                <form
                    class="team-box"
                    method="post"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="assign_team"
                    >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="assign_team"
                    >

                    <input
                        type="hidden"
                        name="kind"
                        value="<?= e($viewKind) ?>"
                    >

                    <input
                        type="hidden"
                        name="id"
                        value="<?= (int)$detail["id"] ?>"
                    >

                    <?php foreach ($teamRoles as $taskRole => $taskMeta): ?>

                        <?php
                        $currentAssignee =
                            $teamAssignments[$taskRole]
                            ?? null;

                        $isApproverSeat =
                            !empty($taskMeta["approver_only"]);

                        $seatPool =
                            $isApproverSeat
                                ? $approverPool
                                : $teamPool;

                        $seatLocked =
                            $isApproverSeat
                            &&
                            !$canSetApprover;
                        ?>

                        <div class="team-row">

                            <div
                                class="team-role-icon"
                                aria-hidden="true"
                            >
                                <i class="bi <?= e((string)($taskMeta["icon"] ?? "bi-person")) ?>"></i>
                            </div>

                            <div class="min-w-0">

                                <div class="team-role-head">

                                    <div>
                                        <div class="team-role-label">
                                            <?= e((string)($taskMeta["label"] ?? $taskRole)) ?>
                                        </div>

                                        <div class="team-role-desc">
                                            <?= e((string)($taskMeta["desc"] ?? "")) ?>
                                        </div>
                                    </div>

                                    <?php if ($currentAssignee && (int)$currentAssignee["user_id"] > 0): ?>
                                        <span class="team-assignee">
                                            <i class="bi bi-person-check-fill"></i>
                                            <?= e(
                                                $currentAssignee["name"] !== ""
                                                    ? $currentAssignee["name"]
                                                    : "User #"
                                                    . (int)$currentAssignee["user_id"]
                                            ) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="team-assignee is-empty">
                                            <i class="bi bi-person-dash"></i>
                                            Unassigned
                                        </span>
                                    <?php endif; ?>

                                </div>

                                <select
                                    class="form-select form-select-sm team-select"
                                    name="team[<?= e($taskRole) ?>]"
                                    <?= $seatLocked ? "disabled" : "" ?>
                                    <?= $seatLocked
                                        ? 'title="Only supervisors/admins can set the approver"'
                                        : "" ?>
                                >
                                    <option value="0">
                                        — Unassigned —
                                    </option>

                                    <?php foreach ($seatPool as $poolUser): ?>
                                        <option
                                            value="<?= (int)$poolUser["id"] ?>"
                                            <?= $currentAssignee
                                                && (int)$currentAssignee["user_id"]
                                                === (int)$poolUser["id"]
                                                ? "selected"
                                                : "" ?>
                                        >
                                            <?= e((string)$poolUser["name"]) ?>
                                            (<?= e(ucfirst((string)$poolUser["role"])) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <?php if ($seatLocked): ?>
                                    <input
                                        type="hidden"
                                        name="team[<?= e($taskRole) ?>]"
                                        value="-1"
                                    >

                                    <div class="team-role-desc mt-1">
                                        <i class="bi bi-lock-fill"></i>
                                        Only a supervisor/admin can set the approver.
                                    </div>
                                <?php endif; ?>

                                <?php
                                $seatAssignedInline =
                                    !empty($currentAssignee)
                                    &&
                                    (int)($currentAssignee["user_id"] ?? 0) > 0;

                                $seatApprovalInline =
                                    $seatAssignedInline
                                    &&
                                    in_array(
                                        $taskRole,
                                        pubApprovableTaskRoles(),
                                        true
                                    )
                                        ? ($taskApprovals[$taskRole] ?? null)
                                        : null;
                                ?>

                                <?php if (!empty($seatApprovalInline)): ?>
                                    <div class="team-approval-row is-approved">
                                        <span class="team-approved-badge">
                                            <i class="bi bi-check-circle-fill"></i>
                                            Approved
                                        </span>
                                        <span class="team-approved-meta">
                                            <?= e(
                                                ($seatApprovalInline["approver_name"] ?? "") !== ""
                                                    ? $seatApprovalInline["approver_name"]
                                                    : "Supervisor"
                                            ) ?>
                                            <?php if (!empty($seatApprovalInline["approved_at"])): ?>
                                                • <?= e(pubDateLabel((string)$seatApprovalInline["approved_at"], true)) ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                    <button
                        class="btn btn-sm btn-success w-100 mt-2"
                        type="submit"
                    >
                        <i class="bi bi-people me-1"></i>
                        Save Production Team
                    </button>

                </form>

                <div class="team-step-label mt-3"><span>Step 2</span> Review files & approve assigned materials</div>
                    <?php if ($supportingFilesKind !== "" && $supportingFileTotal > 0): ?>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-primary w-100 mb-2"
                            data-scroll-to-files="1"
                        >
                            <i class="bi bi-paperclip me-1"></i>
                            Review <?= (int)$supportingFileTotal ?> attached file<?= $supportingFileTotal === 1 ? "" : "s" ?> before approving
                        </button>
                    <?php elseif ($supportingFilesKind !== ""): ?>
                        <div class="material-links-empty">No attached files yet — approvals can still proceed.</div>
                    <?php endif; ?>
                <?php
                $materialFiles = [];
                try {
                    $materialContentFiles = pubGetMaterialContent($conn, $viewKind, (int)$detail["id"]);
                    $materialFiles = is_array($materialContentFiles["material_files"] ?? null)
                        ? $materialContentFiles["material_files"]
                        : [];
                } catch (Throwable $e) {
                    $materialFiles = [];
                }
                $materialFileKinds = pubMaterialFileKinds();
                ?>

                <div class="team-approval-block">
                    <div class="team-approval-head">
                        <i class="bi bi-patch-check-fill"></i>
                        <div>
                            <strong>Material Approvals & Staff Output Attachments</strong>
                            <span>Assigned tasks only • Layout • Video / Reels • Posting Draft — HPU staff can attach outputs & supervisor approves</span>
                        </div>
                        <?php if ($assignedApprovalTotal > 0): ?>
                            <span class="team-approval-count"><?= (int)$approvedCount ?>/<?= (int)$assignedApprovalTotal ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($assignedApprovalTotal === 0): ?>
                        <div class="team-approval-empty">
                            <i class="bi bi-person-dash"></i>
                            <div>
                                <strong>No assigned tasks to approve</strong>
                                <span>Assign someone to Layout, Video / Reels, or Posting Draft above — only assigned tasks appear here.</span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($assignedApprovalRoles as $approveRole): ?>
                        <?php
                        $approveMeta = pubTaskRoleMeta($approveRole);
                        $approveState = $taskApprovals[$approveRole] ?? null;
                        $isApproved = !empty($approveState);
                        $approveAssignee = $teamAssignments[$approveRole] ?? null;

                        $roleMaterialKind = match ($approveRole) {
                            'layout' => 'Layout',
                            'video' => 'Video/Reels',
                            'drafting' => 'Posting Draft',
                            default => 'Other'
                        };

                        $roleFiles = [];
                        foreach ($materialFiles as $mIdx => $mFile) {
                            if (($mFile['material'] ?? 'Other') === $roleMaterialKind) {
                                $mFile['_index'] = $mIdx;
                                $roleFiles[] = $mFile;
                            }
                        }
                        ?>
                        <div class="team-approval-row is-stacked <?= $isApproved ? "is-approved" : "" ?>">
                            <div class="d-flex align-items-center justify-content-between w-100 gap-2 flex-wrap">
                                <div class="d-flex align-items-center gap-2 min-w-0 flex-grow-1">
                                    <div class="team-role-icon sm" aria-hidden="true">
                                        <i class="bi <?= e((string)($approveMeta["icon"] ?? "bi-person")) ?>"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="team-role-label text-dark fw-bold">
                                            <?= e((string)($approveMeta["label"] ?? ucfirst($approveRole))) ?>
                                            <span class="badge bg-light text-secondary border ms-1 fw-normal"><?= e($roleMaterialKind) ?></span>
                                        </div>
                                        <div class="team-role-desc">
                                            Assigned: <strong><?= e(
                                                ($approveAssignee["name"] ?? "") !== ""
                                                    ? $approveAssignee["name"]
                                                    : "User #" . (int)($approveAssignee["user_id"] ?? 0)
                                            ) ?></strong>
                                        </div>
                                        <?php if ($isApproved): ?>
                                            <div class="team-approved-meta text-success">
                                                <i class="bi bi-check-circle-fill"></i>
                                                Approved by <?= e(
                                                    ($approveState["approver_name"] ?? "") !== ""
                                                        ? $approveState["approver_name"]
                                                        : "Supervisor"
                                                ) ?>
                                                <?php if (!empty($approveState["approved_at"])): ?>
                                                    • <?= e(pubDateLabel((string)$approveState["approved_at"], true)) ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif (!$canApproveMaterials): ?>
                                            <div class="team-approved-meta muted">
                                                <i class="bi bi-lock-fill"></i>
                                                Supervisor approval required
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="team-approval-side d-flex align-items-center gap-2">
                                    <?php if ($isApproved): ?>
                                        <span class="team-approved-badge">
                                            <i class="bi bi-check-circle-fill"></i>
                                            Approved
                                        </span>
                                    <?php else: ?>
                                        <span class="team-pending-badge">
                                            <i class="bi bi-hourglass-split"></i>
                                            Pending
                                        </span>
                                    <?php endif; ?>

                                    <div class="team-approval-actions">
                                        <?php if ($canApproveMaterials): ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="<?= $isApproved ? "unapprove_task" : "approve_task" ?>"
                                                >
                                                <input type="hidden" name="kind" value="<?= e($viewKind) ?>">
                                                <input type="hidden" name="id" value="<?= (int)$detail["id"] ?>">
                                                <input type="hidden" name="task_role" value="<?= e($approveRole) ?>">
                                                <?php if ($isApproved): ?>
                                                    <button
                                                        class="btn btn-sm btn-outline-secondary team-approval-btn"
                                                        type="submit"
                                                        title="Remove <?= e((string)($approveMeta["label"] ?? $approveRole)) ?> approval"
                                                    >
                                                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                                                        Undo
                                                    </button>
                                                <?php else: ?>
                                                    <button
                                                        class="btn btn-sm btn-success team-approval-btn"
                                                        type="submit"
                                                        title="Approve <?= e((string)($approveMeta["label"] ?? $approveRole)) ?> material"
                                                    >
                                                        <i class="bi bi-patch-check-fill me-1"></i>
                                                        Approve
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Attached Outputs for this specific Task Role -->
                            <div class="w-100 mt-2 pt-2 border-top team-role-files">
                                <div class="team-role-files-title">
                                    <i class="bi bi-paperclip"></i>
                                    <?= e($roleMaterialKind) ?> outputs
                                    <span class="text-muted fw-normal">(<?= count($roleFiles) ?>)</span>
                                </div>
                                <?php if (!empty($roleFiles)): ?>
                                    <div class="d-flex flex-column gap-2 mb-2">
                                        <?php foreach ($roleFiles as $rFile): ?>
                                            <?php
                                            $rIdx = $rFile['_index'];
                                            $matInlineUrl = BASE_URL . "/staff/publication_requests.php?kind=" . urlencode($viewKind) . "&download_material=" . (int)$detail["id"] . "&file_index=" . (int)$rIdx . "&inline=1";
                                            $matDownloadUrl = BASE_URL . "/staff/publication_requests.php?kind=" . urlencode($viewKind) . "&download_material=" . (int)$detail["id"] . "&file_index=" . (int)$rIdx;
                                            $matExt = strtolower((string)($rFile["extension"] ?? ""));
                                            $rDriveUrl = trim((string)($rFile["drive_url"] ?? ""));

                                            if ($rDriveUrl !== "") {
                                                $rBadge = "file-type-badge is-drive";
                                                $rIcon = "bi-google";
                                            } elseif ($matExt === "pdf") {
                                                $rBadge = "file-type-badge is-pdf";
                                                $rIcon = "bi-file-earmark-pdf-fill";
                                            } elseif (in_array($matExt, ["doc", "docx"], true)) {
                                                $rBadge = "file-type-badge is-doc";
                                                $rIcon = "bi-file-earmark-word-fill";
                                            } elseif (in_array($matExt, ["jpg", "jpeg", "png", "webp"], true)) {
                                                $rBadge = "file-type-badge is-img";
                                                $rIcon = "bi-file-earmark-image-fill";
                                            } elseif (in_array($matExt, ["mp4", "mov"], true)) {
                                                $rBadge = "file-type-badge is-vid";
                                                $rIcon = "bi-file-earmark-play-fill";
                                            } else {
                                                $rBadge = "file-type-badge";
                                                $rIcon = "bi-paperclip";
                                            }
                                            ?>
                                            <div class="file-item">
                                                <div class="file-main">
                                                    <div class="<?= $rBadge ?>" aria-hidden="true">
                                                        <i class="bi <?= $rIcon ?>"></i>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="file-name" title="<?= e($rFile["original_name"]) ?>">
                                                            <?= e($rFile["original_name"]) ?>
                                                        </div>
                                                        <div class="file-meta">
                                                            <?php if ($rDriveUrl !== ""): ?>
                                                                <span class="team-material-kind">Drive link</span>
                                                            <?php else: ?>
                                                                <?= e(pubFileSize((int)($rFile["size"] ?? 0))) ?>
                                                            <?php endif; ?>
                                                            <?php if (!empty($rFile["uploader_name"])): ?>
                                                                • <?= e($rFile["uploader_name"]) ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="file-actions">
                                                    <?php if ($rDriveUrl !== ""): ?>
                                                        <a class="btn btn-sm btn-outline-primary" href="<?= e($rDriveUrl) ?>" target="_blank" rel="noopener" title="Open Google Drive link">
                                                            <i class="bi bi-box-arrow-up-right"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <button
                                                            class="btn btn-sm btn-outline-primary file-view-btn"
                                                            type="button"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#filePreviewModal"
                                                            data-file-name="<?= e($rFile["original_name"] ?? "Production file") ?>"
                                                            data-file-url="<?= e($matInlineUrl) ?>"
                                                            data-file-ext="<?= e($matExt) ?>"
                                                            title="View file"
                                                        >
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <a class="btn btn-sm btn-outline-secondary" href="<?= e($matDownloadUrl) ?>" title="Download file">
                                                            <i class="bi bi-download"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this production file?');">
                                                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                        <input type="hidden" name="action" value="delete_material_file">
                                                        <input type="hidden" name="kind" value="<?= e($viewKind) ?>">
                                                        <input type="hidden" name="id" value="<?= (int)$detail["id"] ?>">
                                                        <input type="hidden" name="file_index" value="<?= (int)$rIdx ?>">
                                                        <button class="btn btn-sm btn-icon-danger" type="submit" title="Delete file">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="team-role-files-empty">No <?= e(strtolower($roleMaterialKind)) ?> outputs attached yet.</div>
                                <?php endif; ?>

                                <!-- Upload Form directly under Task Role -->
                                <?php if (count($materialFiles) < (int)pubMaterialMaxFiles()): ?>
                                    <?php
                                    [$roleAccept, $roleMaxBytes, $roleAllowLink, $roleAcceptAttr, $roleHelp] =
                                        pubMaterialUploadRules($roleMaterialKind);
                                    ?>
                                    <form method="post" enctype="multipart/form-data" class="team-role-upload-form">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="add_material_file">
                                        <input type="hidden" name="kind" value="<?= e($viewKind) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$detail["id"] ?>">
                                        <input type="hidden" name="material_kind" value="<?= e($roleMaterialKind) ?>">
                                        <div class="team-role-upload">
                                            <input
                                                type="file"
                                                class="form-control form-control-sm"
                                                name="material_files[]"
                                                accept="<?= e($roleAcceptAttr) ?>"
                                                multiple
                                                title="Attach <?= e($roleMaterialKind) ?> output file"
                                            >
                                            <button class="btn btn-sm btn-outline-primary flex-shrink-0" type="submit">
                                                <i class="bi bi-upload me-1"></i>Attach
                                            </button>
                                        </div>
                                        <?php if ($roleAllowLink): ?>
                                            <div class="team-role-drive">
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text" title="Google Drive link">
                                                        <i class="bi bi-google"></i>
                                                    </span>
                                                    <input
                                                        type="url"
                                                        inputmode="url"
                                                        class="form-control"
                                                        name="drive_url"
                                                        placeholder="…or paste Google Drive link, then Attach"
                                                    >
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <div class="date-picker-help"><?= e($roleHelp) ?></div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php
                // Files tagged "Other" (or from unassigned seats) live here so
                // nothing is orphaned. Per-task outputs show under their row.
                $knownMats = [];
                foreach ($assignedApprovalRoles as $ar) {
                    $knownMats[] = match ($ar) {
                        'layout' => 'Layout',
                        'video' => 'Video/Reels',
                        'drafting' => 'Posting Draft',
                        default => 'Other'
                    };
                }
                $otherFiles = [];
                foreach ($materialFiles as $oIdx => $oFile) {
                    if (!in_array((string)($oFile["material"] ?? "Other"), $knownMats, true)) {
                        $oFile['_index'] = $oIdx;
                        $otherFiles[] = $oFile;
                    }
                }
                ?>

                <?php if (!empty($otherFiles)): ?>
                    <div class="team-other-files">
                        <div class="team-role-files-title">
                            <i class="bi bi-paperclip"></i>
                            Other outputs
                            <span class="text-muted fw-normal">(<?= count($otherFiles) ?>)</span>
                        </div>
                        <?php foreach ($otherFiles as $oFile): ?>
                            <?php
                            $oIdx = $oFile['_index'];
                            $oInlineUrl = BASE_URL . "/staff/publication_requests.php?kind=" . urlencode($viewKind) . "&download_material=" . (int)$detail["id"] . "&file_index=" . (int)$oIdx . "&inline=1";
                            $oDownloadUrl = BASE_URL . "/staff/publication_requests.php?kind=" . urlencode($viewKind) . "&download_material=" . (int)$detail["id"] . "&file_index=" . (int)$oIdx;
                            $oExt = strtolower((string)($oFile["extension"] ?? ""));
                            $oDriveUrl = trim((string)($oFile["drive_url"] ?? ""));

                            if ($oDriveUrl !== "") {
                                $oBadge = "file-type-badge is-drive";
                                $oIcon = "bi-google";
                            } elseif ($oExt === "pdf") {
                                $oBadge = "file-type-badge is-pdf";
                                $oIcon = "bi-file-earmark-pdf-fill";
                            } elseif (in_array($oExt, ["doc", "docx"], true)) {
                                $oBadge = "file-type-badge is-doc";
                                $oIcon = "bi-file-earmark-word-fill";
                            } elseif (in_array($oExt, ["jpg", "jpeg", "png", "webp"], true)) {
                                $oBadge = "file-type-badge is-img";
                                $oIcon = "bi-file-earmark-image-fill";
                            } elseif (in_array($oExt, ["mp4", "mov"], true)) {
                                $oBadge = "file-type-badge is-vid";
                                $oIcon = "bi-file-earmark-play-fill";
                            } else {
                                $oBadge = "file-type-badge";
                                $oIcon = "bi-paperclip";
                            }
                            ?>
                            <div class="file-item">
                                <div class="file-main">
                                    <div class="<?= $oBadge ?>" aria-hidden="true">
                                        <i class="bi <?= $oIcon ?>"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="file-name" title="<?= e($oFile["original_name"]) ?>">
                                            <?= e($oFile["original_name"]) ?>
                                        </div>
                                        <div class="file-meta">
                                            <span class="team-material-kind"><?= e((string)($oFile["material"] ?? "Other")) ?></span>
                                            <?php if ($oDriveUrl !== ""): ?>
                                                <span class="team-material-kind">Drive link</span>
                                            <?php else: ?>
                                                <?= e(pubFileSize((int)($oFile["size"] ?? 0))) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($oFile["uploader_name"])): ?>
                                                • <?= e($oFile["uploader_name"]) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="file-actions">
                                    <?php if ($oDriveUrl !== ""): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= e($oDriveUrl) ?>" target="_blank" rel="noopener" title="Open Google Drive link">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    <?php else: ?>
                                        <button
                                            class="btn btn-sm btn-outline-primary file-view-btn"
                                            type="button"
                                            data-bs-toggle="modal"
                                            data-bs-target="#filePreviewModal"
                                            data-file-name="<?= e($oFile["original_name"] ?? "Production file") ?>"
                                            data-file-url="<?= e($oInlineUrl) ?>"
                                            data-file-ext="<?= e($oExt) ?>"
                                            title="View file"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= e($oDownloadUrl) ?>" title="Download file">
                                            <i class="bi bi-download"></i>
                                        </a>
                                    <?php endif; ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this production file?');">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="delete_material_file">
                                        <input type="hidden" name="kind" value="<?= e($viewKind) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$detail["id"] ?>">
                                        <input type="hidden" name="file_index" value="<?= (int)$oIdx ?>">
                                        <button class="btn btn-sm btn-icon-danger" type="submit" title="Delete file">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                </div>

            </div>

            <div class="detail-duo-col">
            <div class="detail-section" id="sec-workflow" tabindex="-1">

                <div class="detail-section-head">
                    <i class="bi bi-arrow-repeat"></i>
                    <p class="detail-section-title">
                        Publication Workflow
                        <small>Update status and notify the submitter</small>
                    </p>
                    <button type="button" class="btn btn-sm btn-light section-hide-btn ms-auto" id="workflowHideBtn" aria-expanded="true" aria-controls="sec-workflow-body" title="Hide status flow">
                        <i class="bi bi-chevron-up"></i><span>Hide</span>
                    </button>
                </div>

                <div class="detail-section-body" id="sec-workflow-body">

                <form
                    class="workflow-box"
                    method="post"
                    id="vmWorkflowForm"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="update_workflow"
                    >

                    <input
                        type="hidden"
                        name="kind"
                        value="<?= e($viewKind) ?>"
                    >

                    <input
                        type="hidden"
                        name="id"
                        value="<?= (int)$detail["id"] ?>"
                    >

                    <div class="mb-2">

                        <label class="filter-label" for="vmWorkflowStatus">
                            Status
                        </label>

                        <select
                            id="vmWorkflowStatus"
                            class="form-select form-select-sm"
                            name="status"
                            required
                        >
                            <?php foreach ($allowedStatuses as $status): ?>

                            <option
                                value="<?= e($status) ?>"
                                <?= $detail["status"] === $status ? "selected" : "" ?>
                            >
                                <?= e($status) ?>
                            </option>

                            <?php endforeach; ?>
                        </select>

                        <div class="workflow-status-desc" id="vmWorkflowDesc" aria-live="polite"></div>

                    </div>

                    <div class="mb-2">

                        <label class="filter-label" for="vmWorkflowNotes">
                            HPU Notes
                        </label>

                        <textarea
                            id="vmWorkflowNotes"
                            class="form-control form-control-sm"
                            name="publication_notes"
                            rows="4"
                            placeholder="Internal publication notes, revisions, posting details..."
                        ><?= e($detail["publication_notes"] ?? "") ?></textarea>

                    </div>

                    <div class="workflow-notify">
                        <i class="bi bi-bell-fill"></i>
                        <span>
                            Saving with a changed status notifies
                            <strong><?= e($detail["submitter_name"] ?: "the submitter") ?></strong>.
                            Notes-only saves do not send notifications.
                        </span>
                    </div>

                    <button
                        id="vmWorkflowSave"
                        class="btn btn-sm btn-primary w-100"
                        type="submit"
                    >
                        <i class="bi bi-check2-circle me-1"></i>
                        Save Workflow
                    </button>

                </form>

                </div>

            </div>

            <!-- Website & Social Media Links Section (under Publication Workflow, 2nd column) -->
            <div class="detail-section shadow-sm border rounded-3 p-3 bg-white mb-3" id="vmMaterialLinks">
                <div class="detail-section-head d-flex align-items-center justify-between pb-2 mb-3 border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-link-45deg text-primary fs-5"></i>
                        <div>
                            <p class="detail-section-title mb-0 fw-bold text-dark">
                                Website & Social Media Links
                            </p>
                            <small class="text-muted">Up to <?= (int)$materialMaxLinks ?> links • enabled after all assigned materials are approved</small>
                        </div>
                    </div>
                    <?php if ($linksUnlocked): ?>
                        <span class="badge bg-success-subtle text-success border border-success px-2 py-1"><i class="bi bi-unlock-fill me-1"></i> Links Unlocked</span>
                    <?php else: ?>
                        <span class="badge bg-warning-subtle text-warning border border-warning px-2 py-1"><i class="bi bi-lock-fill me-1"></i> Links Locked</span>
                    <?php endif; ?>
                </div>

                <div class="detail-section-body">
                    <?php if (!$linksUnlocked): ?>
                        <div class="alert alert-warning py-2 text-xs mb-2">
                            <i class="bi bi-lock-fill me-1"></i>
                            Approve all materials first — links unlock after every assigned material is approved.
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($materialLinks)): ?>
                        <div class="material-link-rows">
                            <?php foreach ($materialLinks as $li => $link): ?>
                                <div class="material-link-item" data-link-item="<?= (int)$li ?>">
                                    <a class="btn btn-xs btn-outline-primary d-inline-flex align-items-center gap-1 flex-grow-1 text-start" href="<?= e((string)($link["url"] ?? "#")) ?>" target="_blank" rel="noopener" title="<?= e((string)($link["url"] ?? "")) ?>">
                                        <i class="bi bi-link-45deg"></i>
                                        <strong><?= e((string)($link["type"] ?? "Website")) ?>:</strong>
                                        <span class="text-truncate" style="max-width:180px;"><?= e((string)($link["url"] ?? "")) ?></span>
                                        <i class="bi bi-box-arrow-up-right ms-1"></i>
                                    </a>
                                    <?php if ($canSaveLinks): ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-secondary material-link-edit"
                                            data-edit-link="<?= (int)$li ?>"
                                            title="Edit this link"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this link?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="action" value="delete_material_link">
                                            <input type="hidden" name="kind" value="<?= e($viewKind) ?>">
                                            <input type="hidden" name="id" value="<?= (int)$detail["id"] ?>">
                                            <input type="hidden" name="link_index" value="<?= (int)$li ?>">
                                            <button
                                                class="btn btn-sm btn-icon-danger"
                                                type="submit"
                                                title="Delete this link"
                                            >
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <?php if ($canSaveLinks): ?>
                                    <form method="post" class="material-link-editor" data-editor-row="<?= (int)$li ?>" hidden>
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="update_material_link">
                                        <input type="hidden" name="kind" value="<?= e($viewKind) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$detail["id"] ?>">
                                        <input type="hidden" name="link_index" value="<?= (int)$li ?>">
                                        <select class="form-select form-select-sm" name="link_type" aria-label="Link type" style="max-width: 140px;">
                                            <?php foreach ($materialLinkTypes as $lt): ?>
                                                <option value="<?= e($lt) ?>" <?= (($link["type"] ?? "Website") === $lt) ? "selected" : "" ?>><?= e($lt) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input
                                            type="url"
                                            inputmode="url"
                                            class="form-control form-control-sm flex-grow-1"
                                            name="link_url"
                                            placeholder="https://…"
                                            value="<?= e((string)($link["url"] ?? "")) ?>"
                                            required
                                        >
                                        <button class="btn btn-sm btn-success" type="submit" title="Save changes">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary" type="button" data-edit-cancel title="Cancel editing">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-muted italic small mb-2">No publication links attached yet.</div>
                    <?php endif; ?>

                    <?php if ($canSaveLinks && count($materialLinks) < (int)$materialMaxLinks): ?>
                        <form method="post" class="material-links-form" id="vmLinksForm">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="add_material_link">
                            <input type="hidden" name="kind" value="<?= e($viewKind) ?>">
                            <input type="hidden" name="id" value="<?= (int)$detail["id"] ?>">

                            <div class="material-link-row d-flex gap-2 mb-2">
                                <select class="form-select form-select-sm" name="link_type" aria-label="Link type" style="max-width: 140px;">
                                    <?php foreach ($materialLinkTypes as $lt): ?>
                                        <option value="<?= e($lt) ?>"><?= e($lt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input
                                    type="url"
                                    inputmode="url"
                                    class="form-control form-control-sm flex-grow-1"
                                    name="link_url"
                                    placeholder="https://…"
                                    required
                                >
                                <button class="btn btn-sm btn-primary" type="submit" title="Add this link">
                                    <i class="bi bi-plus-circle me-1"></i>
                                    Add
                                </button>
                            </div>
                            <div class="date-picker-help mt-1"><?= count($materialLinks) ?>/<?= (int)$materialMaxLinks ?> links used.</div>
                        </form>
                    <?php elseif ($canSaveLinks): ?>
                        <div class="date-picker-help mt-1">Maximum <?= (int)$materialMaxLinks ?> links reached — edit or delete one to add another.</div>
                    <?php endif; ?>

                    <?php if ($linksUnlocked): ?>
                        <div class="alert alert-info py-2 text-xs mb-0">
                            <i class="bi bi-lock-fill me-1"></i>
                            All materials are approved — only a supervisor or admin can attach links.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-secondary py-2 text-xs mb-0">
                            <i class="bi bi-lock-fill me-1"></i>
                            Links unlock after every assigned material (Layout • Video / Reels • Posting Draft) is approved.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            </div><!-- /.detail-duo-col -->
            </div><!-- /.detail-duo -->

        </div>

                </div><!-- /.modal-body -->

                <div class="modal-footer">
                    <span class="text-muted small me-auto d-none d-md-inline">
                        <i class="bi bi-hash"></i><?= e(publicationDisplayCode($viewKind, (int) $detail["id"])) ?>
                        • <?= e($detail["status"]) ?>
                    </span>
                    <button
                        class="btn btn-sm btn-outline-secondary"
                        type="button"
                        data-bs-toggle="modal"
                        data-bs-target="#editSubmissionModal"
                    >
                        <i class="bi bi-pencil-square me-1"></i>
                        Edit
                    </button>
                    <a
                        class="btn btn-sm btn-outline-primary"
                        href="<?= e(BASE_URL) ?>/staff/publication_requests.php?<?= e(http_build_query([
                            "tab" => $tab,
                            "status" => $statusFilter,
                            "q" => $q,
                            "chat" => $viewKind,
                            "chat_id" => (int)$detail["id"]
                        ])) ?>"
                    >
                        <i class="bi bi-chat-dots me-1"></i>
                        Message
                    </a>
                    <button
                        class="btn btn-sm btn-secondary"
                        type="button"
                        data-bs-dismiss="modal"
                    >
                        Close
                    </button>
                </div>

            </div>
        </div>
</div>

    <?php endif; ?>

    <?php if ($chatDetail): ?>
    <?php
    $chatTitle =
        $chatKind === "recognition"
            ? ($chatDetail["recognized_entity"] ?? "")
            : (
                $chatKind === "contribution"
                    ? ($chatDetail["content_title"] ?? "")
                    : ($chatDetail["title"] ?? "")
            );

    $chatListUrl = BASE_URL . "/staff/publication_requests.php?" . http_build_query([
        "tab" => $tab,
        "status" => $statusFilter,
        "q" => $q,
    ]);

    $chatViewUrl = BASE_URL . "/staff/publication_requests.php?" . http_build_query([
        "tab" => $tab,
        "status" => $statusFilter,
        "q" => $q,
        "view" => $chatKind,
        "id" => (int)$chatDetail["id"]
    ]);
    ?>

    <!-- Communication Center standalone modal (outside the view modal) -->
    <div
        class="modal fade"
        id="chatModal"
        tabindex="-1"
        aria-labelledby="chatModalTitle"
        aria-hidden="true"
        data-list-url="<?= e($chatListUrl) ?>"
    >
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title" id="chatModalTitle">
                        <i class="bi bi-chat-dots-fill me-1"></i>
                        Communication Center
                        <span class="text-muted fw-normal ms-1">
                            <?= e(publicationDisplayCode($chatKind, (int) $chatDetail["id"])) ?>
                        </span>
                    </h5>

                    <button
                        class="btn-close"
                        type="button"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>

                <div class="modal-body">

                    <div class="edit-context">
                        <span
                            class="kind-pill <?= $chatKind === "recognition" ? "kind-recognition" : ($chatKind === "contribution" ? "kind-contribution" : "kind-event") ?>"
                        >
                            <i class="bi <?= e(pubKindIcon($chatKind)) ?>"></i>
                            <?= e(pubKindLabel($chatKind)) ?>
                        </span>

                        <span class="text-muted text-truncate" style="max-width:46ch;">
                            <?= e($chatTitle) ?>
                        </span>

                        <span
                            class="status-pill <?= e(pubStatusClass((string)$chatDetail["status"])) ?>"
                        >
                            <?= e($chatDetail["status"]) ?>
                        </span>
                    </div>

                    <?php
                    $pubKind = $chatKind;
                    $pubId = (int) ($chatDetail["id"] ?? 0);
                    include "../includes/publication_chat_box.php";
                    ?>

                </div><!-- /.modal-body -->

                <div class="modal-footer">
                    <a
                        class="btn btn-sm btn-outline-primary"
                        href="<?= e($chatViewUrl) ?>"
                    >
                        <i class="bi bi-eye me-1"></i>
                        View Details
                    </a>

                    <button
                        class="btn btn-sm btn-secondary"
                        type="button"
                        data-bs-dismiss="modal"
                    >
                        Close
                    </button>
                </div>

            </div>
        </div>
    </div>

    <?php endif; ?>

</div>

</main>

</div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
></script>

<?php if ($detail): ?>

<!-- Edit submission modal -->
<div
    class="modal fade"
    id="editSubmissionModal"
    tabindex="-1"
    aria-hidden="true"
>
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil-square me-1"></i>
                        Edit
                        <?= e(pubKindLabel($viewKind)) ?>
                        Submission
                    </h5>

                    <button
                        class="btn-close"
                        type="button"
                        data-bs-dismiss="modal"
                    ></button>
                </div>

                <div class="modal-body">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="update_submission"
                    >

                    <input
                        type="hidden"
                        name="kind"
                        value="<?= e($viewKind) ?>"
                    >

                    <input
                        type="hidden"
                        name="id"
                        value="<?= (int)$detail["id"] ?>"
                    >

                    <div class="edit-context">
                        <span class="detail-code-chip">
                            <i class="bi bi-hash"></i><?= e(publicationDisplayCode($viewKind, (int) $detail["id"])) ?>
                        </span>

                        <span
                            class="status-pill <?= e(pubStatusClass((string)$detail["status"])) ?>"
                        >
                            <?= e($detail["status"]) ?>
                        </span>

                        <span class="text-muted">
                            Submitted <?= e(pubDateLabel((string)$detail["created_at"], true)) ?>
                        </span>
                    </div>

                    <?php if ($viewKind === "recognition"): ?>

                    <?php
                    $editTypes =
                        pubDecodeRecognitionTypes(
                            $detail["recognition_type"]
                            ?? null
                        );
                    ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="filter-label">Email Address *</label>
                            <input
                                type="email"
                                class="form-control form-control-sm"
                                name="email_address"
                                value="<?= e($detail["email_address"] ?? "") ?>"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="filter-label">Preferred Publication Date</label>
                            <input
                                type="date"
                                class="form-control form-control-sm"
                                name="preferred_publication_date"
                                value="<?= e(substr((string)($detail["preferred_publication_date"] ?? ""), 0, 10)) ?>"
                            >
                        </div>

                        <div class="col-12">
                            <label class="filter-label">Recognition / Milestone Type *</label>

                            <?php foreach (pubRecognitionTypes() as $rtype): ?>
                            <label class="form-check">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="recognition_types[]"
                                    value="<?= e($rtype) ?>"
                                    <?= in_array($rtype, $editTypes, true) ? "checked" : "" ?>
                                >
                                <span class="form-check-label"><?= e($rtype) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="col-12">
                            <label class="filter-label">Who or What Was Recognized? *</label>
                            <input
                                class="form-control form-control-sm"
                                name="recognized_entity"
                                value="<?= e($detail["recognized_entity"] ?? "") ?>"
                                required
                            >
                        </div>

                        <div class="col-12">
                            <label class="filter-label">What Was Achieved? *</label>
                            <textarea
                                class="form-control form-control-sm"
                                name="achievement"
                                rows="5"
                                data-autogrow
                                required
                            ><?= e($detail["achievement"] ?? "") ?></textarea>
                        </div>

                        <div class="col-12">
                            <label class="filter-label">Supporting Information</label>
                            <textarea
                                class="form-control form-control-sm"
                                name="supporting_information"
                                rows="4"
                                data-autogrow
                            ><?= e($detail["supporting_information"] ?? "") ?></textarea>
                        </div>
                    </div>

                    <?php elseif ($viewKind === "event"): ?>

                    <div class="detail-section">

                        <div class="detail-section-head">
                            <i class="bi bi-envelope"></i>
                            <p class="detail-section-title">
                                Contact & Schedule
                            </p>
                        </div>

                        <div class="detail-section-body">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="filter-label">Email Address *</label>
                            <input
                                type="email"
                                class="form-control form-control-sm"
                                name="email_address"
                                value="<?= e($detail["email_address"] ?? "") ?>"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="filter-label">Preferred Publication Date</label>
                            <input
                                type="date"
                                class="form-control form-control-sm"
                                name="preferred_publication_date"
                                value="<?= e(substr((string)($detail["preferred_publication_date"] ?? ""), 0, 10)) ?>"
                            >
                        </div>
                    </div>

                        </div>

                    </div>

                    <div class="detail-section">

                        <div class="detail-section-head">
                            <i class="bi bi-calendar2-event"></i>
                            <p class="detail-section-title">
                                Activity Profile
                            </p>
                        </div>

                        <div class="detail-section-body">

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="filter-label">Activity Title *</label>
                            <input
                                class="form-control form-control-sm"
                                name="title"
                                value="<?= e($detail["title"] ?? "") ?>"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="filter-label">Type of Activity *</label>
                            <input
                                class="form-control form-control-sm"
                                name="activity_type"
                                value="<?= e($detail["activity_type"] ?? "") ?>"
                                required
                            >
                        </div>

                        <div class="col-md-3">
                            <label class="filter-label">Start Date</label>
                            <input
                                type="date"
                                class="form-control form-control-sm"
                                name="start_date"
                                value="<?= e(substr((string)($detail["start_date"] ?? ""), 0, 10)) ?>"
                            >
                        </div>

                        <div class="col-md-3">
                            <label class="filter-label">End Date</label>
                            <input
                                type="date"
                                class="form-control form-control-sm"
                                name="end_date"
                                value="<?= e(substr((string)($detail["end_date"] ?? ""), 0, 10)) ?>"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="filter-label">Time</label>
                            <input
                                class="form-control form-control-sm"
                                name="activity_time"
                                value="<?= e($detail["activity_time"] ?? "") ?>"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="filter-label">Venue</label>
                            <input
                                class="form-control form-control-sm"
                                name="venue"
                                value="<?= e($detail["venue"] ?? "") ?>"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="filter-label">Lead Office / Unit</label>
                            <input
                                class="form-control form-control-sm"
                                name="lead_office"
                                value="<?= e($detail["lead_office"] ?? "") ?>"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="filter-label">Person-in-Charge</label>
                            <input
                                class="form-control form-control-sm"
                                name="person_in_charge"
                                value="<?= e($detail["person_in_charge"] ?? "") ?>"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="filter-label">Partner Offices / Organizations</label>
                            <textarea
                                class="form-control form-control-sm"
                                name="partner_offices"
                                rows="3"
                                data-autogrow
                            ><?= e($detail["partner_offices"] ?? "") ?></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="filter-label">Participants (count)</label>
                            <input
                                type="number"
                                min="0"
                                class="form-control form-control-sm"
                                name="participant_count"
                                value="<?= e((string)($detail["participant_count"] ?? "")) ?>"
                            >
                        </div>
                    </div>

                        </div>

                    </div>

                    <div class="detail-section">

                        <div class="detail-section-head">
                            <i class="bi bi-journal-text"></i>
                            <p class="detail-section-title">
                                Documentation
                            </p>
                        </div>

                        <div class="detail-section-body">

                        <?php
                        $eventEditFields = [
                            "background" => "Background",
                            "objectives" => "Objectives",
                            "activity_highlights" => "Activity Highlights",
                            "key_outputs" => "Key Outputs / Agreements",
                            "impact_significance" => "Impact / Significance",
                            "quotable_statement" => "Quotable Statement",
                            "remembrance_sentence" => "How the activity should be remembered"
                        ];
                        ?>

                        <?php foreach ($eventEditFields as $fname => $flabel): ?>
                        <div class="mb-3">
                            <label class="filter-label"><?= e($flabel) ?></label>
                            <textarea
                                class="form-control form-control-sm"
                                name="<?= e($fname) ?>"
                                rows="5"
                                data-autogrow
                            ><?= e($detail[$fname] ?? "") ?></textarea>
                        </div>
                        <?php endforeach; ?>

                        </div>

                    </div>

                    <?php elseif ($viewKind === "contribution"): ?>

                    <div class="detail-section">

                        <div class="detail-section-head">
                            <i class="bi bi-journal-plus"></i>
                            <p class="detail-section-title">
                                Content Contribution
                            </p>
                        </div>

                        <div class="detail-section-body">

                        <div class="row g-3">

                            <div class="col-md-6">
                                <label class="filter-label">Email Address *</label>
                                <input
                                    type="email"
                                    class="form-control form-control-sm"
                                    name="email_address"
                                    value="<?= e($detail["email_address"] ?? "") ?>"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="filter-label">Preferred Publication Date</label>
                                <input
                                    type="date"
                                    class="form-control form-control-sm"
                                    name="preferred_publication_date"
                                    value="<?= e(substr((string)($detail["preferred_publication_date"] ?? ""), 0, 10)) ?>"
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="filter-label">Content Type *</label>
                                <select
                                    class="form-select form-select-sm"
                                    name="contribution_type"
                                    required
                                >
                                    <?php foreach (pubContributionTypes() as $ctype): ?>
                                    <option
                                        value="<?= e($ctype) ?>"
                                        <?= ($detail["contribution_type"] ?? "") === $ctype ? "selected" : "" ?>
                                    >
                                        <?= e($ctype) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="filter-label">Channel *</label>
                                <select
                                    class="form-select form-select-sm"
                                    name="publication_channel"
                                    required
                                >
                                    <?php foreach (pubContributionChannels() as $chan): ?>
                                    <option
                                        value="<?= e($chan) ?>"
                                        <?= ($detail["publication_channel"] ?? "") === $chan ? "selected" : "" ?>
                                    >
                                        <?= e($chan) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="filter-label">Content Title *</label>
                                <input
                                    class="form-control form-control-sm"
                                    name="content_title"
                                    value="<?= e($detail["content_title"] ?? "") ?>"
                                    required
                                >
                            </div>

                            <div class="col-12">
                                <label class="filter-label">Lead Office / Unit *</label>
                                <input
                                    class="form-control form-control-sm"
                                    name="lead_office"
                                    value="<?= e($detail["lead_office"] ?? "") ?>"
                                    required
                                >
                            </div>

                            <div class="col-12">
                                <label class="filter-label">Focal Person *</label>
                                <input
                                    class="form-control form-control-sm"
                                    name="focal_person"
                                    value="<?= e($detail["focal_person"] ?? "") ?>"
                                    required
                                >
                            </div>

                            <div class="col-12">
                                <label class="filter-label">Target Audience</label>
                                <input
                                    class="form-control form-control-sm"
                                    name="target_audience"
                                    value="<?= e($detail["target_audience"] ?? "") ?>"
                                >
                            </div>

                            <div class="col-12">
                                <label class="filter-label">Purpose / Objective *</label>
                                <textarea
                                    class="form-control form-control-sm"
                                    name="purpose_objective"
                                    rows="4"
                                    data-autogrow
                                    required
                                ><?= e($detail["purpose_objective"] ?? "") ?></textarea>
                            </div>

                            <div class="col-12">
                                <label class="filter-label">Key Message *</label>
                                <textarea
                                    class="form-control form-control-sm"
                                    name="key_message"
                                    rows="4"
                                    data-autogrow
                                    required
                                ><?= e($detail["key_message"] ?? "") ?></textarea>
                            </div>

                            <div class="col-12">
                                <label class="filter-label">Social Media Caption</label>
                                <textarea
                                    class="form-control form-control-sm"
                                    name="social_media_caption"
                                    rows="4"
                                    data-autogrow
                                ><?= e($detail["social_media_caption"] ?? "") ?></textarea>
                            </div>

                            <div class="col-12">
                                <label class="filter-label">Website Content</label>
                                <textarea
                                    class="form-control form-control-sm"
                                    name="website_content"
                                    rows="4"
                                    data-autogrow
                                ><?= e($detail["website_content"] ?? "") ?></textarea>
                            </div>

                            <div class="col-12">
                                <label class="filter-label">Call to Action</label>
                                <textarea
                                    class="form-control form-control-sm"
                                    name="call_to_action"
                                    rows="3"
                                    data-autogrow
                                ><?= e($detail["call_to_action"] ?? "") ?></textarea>
                            </div>

                            <div class="col-12">
                                <label class="filter-label">Hashtags</label>
                                <input
                                    class="form-control form-control-sm"
                                    name="hashtags"
                                    value="<?= e($detail["hashtags"] ?? "") ?>"
                                >
                            </div>

                            <div class="col-12">
                                <label class="filter-label">Reference Links</label>
                                <textarea
                                    class="form-control form-control-sm"
                                    name="reference_links"
                                    rows="3"
                                    data-autogrow
                                ><?= e($detail["reference_links"] ?? "") ?></textarea>
                            </div>

                        </div>

                        </div>

                    </div>

                    <?php endif; ?>
                    <button
                        class="btn btn-sm btn-outline-secondary"
                        type="button"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        class="btn btn-sm btn-primary"
                        type="submit"
                    >
                        <i class="bi bi-check2-circle me-1"></i>
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($viewKind === "recognition" || $viewKind === "contribution"): ?>

<!-- File preview modal -->
<div
    class="modal fade"
    id="filePreviewModal"
    tabindex="-1"
    aria-hidden="true"
>
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-truncate" id="filePreviewTitle">
                    Supporting file
                </h5>

                <button
                    class="btn-close"
                    type="button"
                    data-bs-dismiss="modal"
                ></button>
            </div>

            <div class="modal-body" id="filePreviewBody">
            </div>

            <div class="modal-footer">
                <a
                    class="btn btn-sm btn-outline-secondary"
                    id="filePreviewDownload"
                    href="#"
                >
                    <i class="bi bi-download me-1"></i>
                    Download
                </a>

                <button
                    class="btn btn-sm btn-secondary"
                    type="button"
                    data-bs-dismiss="modal"
                >
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Rename file modal -->
<div
    class="modal fade"
    id="renameFileModal"
    tabindex="-1"
    aria-hidden="true"
>
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil me-1"></i>
                        Rename file
                    </h5>

                    <button
                        class="btn-close"
                        type="button"
                        data-bs-dismiss="modal"
                    ></button>
                </div>

                <div class="modal-body">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="rename_file"
                    >

                    <input
                        type="hidden"
                        name="kind"
                        value="<?= e($viewKind) ?>"
                    >

                    <input
                        type="hidden"
                        name="id"
                        value="<?= (int)$detail["id"] ?>"
                    >

                    <input
                        type="hidden"
                        name="file_index"
                        id="renameFileIndex"
                        value="0"
                    >

                    <label class="filter-label">File name</label>

                    <input
                        type="text"
                        class="form-control form-control-sm"
                        name="new_name"
                        id="renameFileName"
                        maxlength="150"
                        required
                    >

                    <div class="form-text">
                        Only the displayed file name is changed.
                    </div>
                </div>

                <div class="modal-footer">
                    <button
                        class="btn btn-sm btn-outline-secondary"
                        type="button"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        class="btn btn-sm btn-primary"
                        type="submit"
                    >
                        Save Name
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- Delete submission confirmation -->
<div
    class="modal fade"
    id="deleteSubmissionModal"
    tabindex="-1"
    aria-hidden="true"
>
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-trash me-1"></i>
                        Delete submission
                    </h5>

                    <button
                        class="btn-close"
                        type="button"
                        data-bs-dismiss="modal"
                    ></button>
                </div>

                <div class="modal-body">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="delete_submission"
                    >

                    <input
                        type="hidden"
                        name="kind"
                        value="<?= e($viewKind) ?>"
                    >

                    <input
                        type="hidden"
                        name="id"
                        value="<?= (int)$detail["id"] ?>"
                    >

                    <p class="mb-0">
                        Permanently delete this
                        <?= e(pubKindLabel($viewKind)) ?>
                        submission
                        <strong>
                            “<?= e(
                                $viewKind === "recognition"
                                    ? ($detail["recognized_entity"] ?? "")
                                    : (
                                        $viewKind === "contribution"
                                            ? ($detail["content_title"] ?? "")
                                            : ($detail["title"] ?? "")
                                    )
                            ) ?>”
                        </strong>?
                        <?php if ($viewKind === "recognition" || $viewKind === "contribution"): ?>
                        Its supporting files will also be removed.
                        <?php endif; ?>
                        This cannot be undone.
                    </p>

                    <div class="mt-3">
                        <label
                            class="filter-label"
                            for="deleteConfirmPassword"
                        >
                            Confirm with your account password *
                        </label>

                        <input
                            type="password"
                            class="form-control form-control-sm"
                            name="confirm_password"
                            id="deleteConfirmPassword"
                            autocomplete="current-password"
                            placeholder="Enter your login password"
                            required
                        >

                        <div class="form-text">
                            Type the password you use to log in. The request is deleted only if it matches.
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button
                        class="btn btn-sm btn-outline-secondary"
                        type="button"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        class="btn btn-sm btn-danger"
                        type="submit"
                    >
                        <i class="bi bi-trash me-1"></i>
                        Delete Permanently
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
document.addEventListener("DOMContentLoaded", function () {
    var previewModal =
        document.getElementById("filePreviewModal");

    if (previewModal) {
        previewModal.addEventListener("show.bs.modal", function (event) {
            var btn =
                event.relatedTarget;

            if (!btn) {
                return;
            }

            var url =
                btn.getAttribute("data-file-url") || "#";

            var name =
                btn.getAttribute("data-file-name") || "Supporting file";

            var ext =
                (btn.getAttribute("data-file-ext") || "").toLowerCase();

            document.getElementById("filePreviewTitle").textContent =
                name;

            document.getElementById("filePreviewDownload").href =
                url.replace("&inline=1", "").replace("?inline=1", "");

            var body =
                document.getElementById("filePreviewBody");

            body.innerHTML = "";

            if (
                ["jpg", "jpeg", "png", "webp"].indexOf(ext) !== -1
            ) {
                var img =
                    document.createElement("img");

                img.src = url;
                img.alt = name;
                img.className = "file-preview-img";

                body.appendChild(img);
            } else if (ext === "pdf") {
                var frame =
                    document.createElement("iframe");

                frame.src = url;
                frame.className = "file-preview-frame";
                frame.title = name;

                body.appendChild(frame);
            } else if (ext === "mp4" || ext === "mov") {
                var videoWrap =
                    document.createElement("div");

                videoWrap.className = "file-preview-video-wrap";

                var video =
                    document.createElement("video");

                video.src = url;
                video.controls = true;
                video.preload = "metadata";
                video.playsInline = true;
                video.className = "file-preview-frame file-preview-video";
                video.setAttribute("title", name);

                var timeBadge =
                    document.createElement("div");

                timeBadge.className = "file-preview-time";
                timeBadge.textContent = "Loading video…";

                var fmtTime = function (secs) {
                    if (!isFinite(secs) || secs < 0) return "0:00";
                    secs = Math.floor(secs);
                    var h = Math.floor(secs / 3600);
                    var m = Math.floor((secs % 3600) / 60);
                    var s = secs % 60;
                    var mm = h > 0 ? String(m).padStart(2, "0") : String(m);
                    return (h > 0 ? h + ":" : "") + mm + ":" + String(s).padStart(2, "0");
                };

                video.addEventListener("loadedmetadata", function () {
                    timeBadge.innerHTML = '<i class="bi bi-clock-history me-1"></i>0:00 / ' + fmtTime(video.duration);
                });

                video.addEventListener("timeupdate", function () {
                    if (isFinite(video.duration)) {
                        timeBadge.innerHTML = '<i class="bi bi-clock-history me-1"></i>' + fmtTime(video.currentTime) + " / " + fmtTime(video.duration);
                    }
                });

                video.addEventListener("error", function () {
                    timeBadge.className = "file-preview-time is-error";
                    timeBadge.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Cannot play here — use Download below.';
                });

                videoWrap.appendChild(video);
                videoWrap.appendChild(timeBadge);

                body.appendChild(videoWrap);
            } else {
                var note =
                    document.createElement("div");

                note.className = "text-center text-muted py-4";
                note.innerHTML =
                    "Preview is not available for this file type.<br>Use Download to open it.";

                body.appendChild(note);
            }
        });

        previewModal.addEventListener("hidden.bs.modal", function () {
            document.getElementById("filePreviewBody").innerHTML = "";
        });
    }

    var renameModal =
        document.getElementById("renameFileModal");

    if (renameModal) {
        renameModal.addEventListener("show.bs.modal", function (event) {
            var btn =
                event.relatedTarget;

            if (!btn) {
                return;
            }

            document.getElementById("renameFileIndex").value =
                btn.getAttribute("data-file-index") || "0";

            document.getElementById("renameFileName").value =
                btn.getAttribute("data-file-name") || "";
        });
    }

    /* Auto-grow edit-modal textareas so full content stays visible. */
    var editModal =
        document.getElementById("editSubmissionModal");

    function autogrowTextarea(field) {
        field.style.height = "auto";
        field.style.height = (field.scrollHeight + 2) + "px";
    }

    if (editModal) {
        editModal.addEventListener("shown.bs.modal", function () {
            editModal.querySelectorAll("textarea[data-autogrow]").forEach(autogrowTextarea);
        });

        editModal.addEventListener("input", function (event) {
            if (
                event.target
                && event.target.matches("textarea[data-autogrow]")
            ) {
                autogrowTextarea(event.target);
            }
        });
    }

    /* View popup: auto-open when ?view&id present, clean URL on close. */
    // Reparent popups to <body>: nested inside .content (which is animated
    // with transform) breaks Bootstrap fixed positioning and lets the
    // backdrop cover the modal so it cannot be clicked.
    ["viewSubmissionModal", "editSubmissionModal", "deleteSubmissionModal", "filePreviewModal", "renameFileModal", "chatModal", "moveDraftModal"].forEach(function (id) {
        var m = document.getElementById(id);

        if (m && m.parentElement !== document.body) {
            document.body.appendChild(m);
        }
    });

    var viewModal = document.getElementById("viewSubmissionModal");

    if (viewModal && window.bootstrap && window.bootstrap.Modal) {
        // Drop any stray backdrop left from a previous state.
        document.querySelectorAll(".modal-backdrop").forEach(function (bd) {
            bd.parentNode && bd.parentNode.removeChild(bd);
        });

        var viewInstance = window.bootstrap.Modal.getOrCreateInstance(viewModal);

        viewInstance.show();

        viewModal.addEventListener("hidden.bs.modal", function () {
            cleanListUrl(viewModal);
        });
    }

    /* Chat popup: auto-open when ?chat&chat_id present, clean URL on close. */
    var chatModal = document.getElementById("chatModal");

    if (chatModal && window.bootstrap && window.bootstrap.Modal) {
        var chatInstance = window.bootstrap.Modal.getOrCreateInstance(chatModal);

        chatInstance.show();

        chatModal.addEventListener("hidden.bs.modal", function () {
            cleanListUrl(chatModal);
        });
    }

    /* Stacked-modal glitch fix: tag extra backdrops, keep scroll locked
       while any popup is still visible. */
    function cleanListUrl(modal) {
        // Don't clean URL / highlight if another popup is still open
        // (Edit/Delete/Preview/Chat opened from inside View).
        if (document.querySelectorAll(".modal.show").length > 0) {
            return;
        }

        var listUrl = modal.getAttribute("data-list-url");

        if (listUrl) {
            try {
                window.history.replaceState(null, "", listUrl);
            } catch (e) { }
        }

        document.querySelectorAll(".submission-card.active").forEach(function (card) {
            card.classList.remove("active");
        });
    }
    document.addEventListener("shown.bs.modal", function () {
        var openCount = document.querySelectorAll(".modal.show").length;

        if (openCount > 1) {
            var backdrops = document.querySelectorAll(".modal-backdrop");

            if (backdrops.length > 0) {
                backdrops[backdrops.length - 1].classList.add("stacked");
            }
        }
    });

    document.addEventListener("hidden.bs.modal", function () {
        if (document.querySelectorAll(".modal.show").length > 0) {
            document.body.classList.add("modal-open");
        }
    });

    /* View modal quick navigation pill bar smooth scroll and active highlight */
    document.addEventListener("click", function (e) {
        var navItem = e.target && e.target.closest ? e.target.closest(".vm-nav-item") : null;
        if (!navItem) return;
        var targetId = navItem.getAttribute("data-target");
        if (!targetId) return;
        var target = document.getElementById(targetId);
        if (!target) return;
        e.preventDefault();
        try {
            target.scrollIntoView({ behavior: "smooth", block: "start" });
        } catch (err) {
            target.scrollIntoView();
        }
        target.classList.remove("flash-focus");
        void target.offsetWidth;
        target.classList.add("flash-focus");
        document.querySelectorAll(".vm-nav-item").forEach(function (n) { n.classList.remove("active"); });
        navItem.classList.add("active");
        try { target.focus({ preventScroll: true }); } catch (err) {}
    });

    /* Workflow form: live status help + enable Save only when dirty. */
    (function initWorkflowForm() {
        var form = document.getElementById("vmWorkflowForm");
        if (!form) return;

        var status = document.getElementById("vmWorkflowStatus");
        var notes = document.getElementById("vmWorkflowNotes");
        var desc = document.getElementById("vmWorkflowDesc");
        var save = document.getElementById("vmWorkflowSave");

        var help = {
            "For Review": "Back to the review queue. The submitter is notified of the change.",
            "In Preparation": "Content is being prepared by the production team. The submitter is notified.",
            "For Approval": "Ready for final supervisor approval. The submitter is notified.",
            "Published": "Marks the material as published and stamps the publish date. The submitter is notified.",
            "Closed": "Terminal state — no further edits expected. The submitter is notified."
        };

        var initialStatus = status ? status.value : "";
        var initialNotes = notes ? notes.value : "";

        var refresh = function () {
            if (desc && status) {
                desc.textContent = help[status.value] || "";
            }

            if (save) {
                var dirty = status
                    && (status.value !== initialStatus
                        || (notes && notes.value !== initialNotes));

                save.disabled = !dirty;
                save.title = dirty
                    ? "Save workflow changes"
                    : "Change the status or notes to enable saving";
            }
        };

        if (status) status.addEventListener("change", refresh);
        if (notes) notes.addEventListener("input", refresh);
        refresh();
    })();

    /* Hide / show toggle for the Publication Workflow (status flow) section. */
    (function initWorkflowHide() {
        var section = document.getElementById("sec-workflow");
        var btn = document.getElementById("workflowHideBtn");
        if (!section || !btn) return;

        var label = btn.querySelector("span");

        btn.addEventListener("click", function () {
            var hidden = section.classList.toggle("is-hidden");
            btn.setAttribute("aria-expanded", hidden ? "false" : "true");
            btn.setAttribute("title", hidden ? "Show status flow" : "Hide status flow");
            if (label) label.textContent = hidden ? "Show" : "Hide";
        });
    })();

    /* Stay in place: every view-modal save returns to its own section. */
    var vmAnchorSections = [
        "sec-track",
        "sec-profile",
        "vmSupportingFiles",
        "sec-info",
        "vmMaterialContent",
        "sec-team",
        "sec-workflow",
        "vmMaterialLinks"
    ];

    document.addEventListener("submit", function (e) {
        var form = e.target && e.target.closest ? e.target.closest("form") : null;
        if (!form) return;

        var viewModal = document.getElementById("viewSubmissionModal");
        if (!viewModal || !viewModal.contains(form)) return;

        // Stacked popups (edit/delete/preview) post on their own; the
        // view modal reopens at top for those.
        var nested = form.closest(".modal");
        if (nested && nested.id !== "viewSubmissionModal") return;

        var section = form.closest(".detail-section[id]");
        if (!section || vmAnchorSections.indexOf(section.id) === -1) return;

        var input = form.querySelector('input[name="return_anchor"]');
        if (!input) {
            input = document.createElement("input");
            input.type = "hidden";
            input.name = "return_anchor";
            form.appendChild(input);
        }
        input.value = section.id;
    });

    /* On reopen with #section, scroll the modal body straight to it. */
    document.addEventListener("shown.bs.modal", function (e) {
        if (!e.target || e.target.id !== "viewSubmissionModal") return;

        var hash = (window.location.hash || "").replace("#", "");
        if (!hash || vmAnchorSections.indexOf(hash) === -1) return;

        var target = document.getElementById(hash);
        if (!target || !e.target.contains(target)) return;

        try {
            target.scrollIntoView({ behavior: "auto", block: "start" });
        } catch (err) {
            target.scrollIntoView();
        }
        target.classList.remove("flash-focus");
        void target.offsetWidth;
        target.classList.add("flash-focus");
    });

    /* Scroll from Material Approvals to the Supporting Files section. */
    document.addEventListener("click", function (e) {
        var btn = e.target && e.target.closest
            ? e.target.closest('[data-scroll-to-files="1"]')
            : null;
        if (!btn) return;
        var target = document.getElementById("vmSupportingFiles");
        if (!target) return;
        e.preventDefault();
        try {
            target.scrollIntoView({ behavior: "smooth", block: "start" });
        } catch (err) {
            target.scrollIntoView();
        }
        target.classList.remove("flash-focus");
        void target.offsetWidth;
        target.classList.add("flash-focus");
        try { target.setAttribute("tabindex", "-1"); target.focus({ preventScroll: true }); } catch (err) {}
    });
});

    /* Draft / Final 1500-char counters (paragraph textareas). */
    document.querySelectorAll(".material-textarea[data-counter]").forEach(function (ta) {
        var counter = document.getElementById(ta.getAttribute("data-counter"));
        var max = parseInt(ta.getAttribute("maxlength") || "1500", 10) || 1500;
        var update = function () {
            var len = (ta.value || "").length;
            if (counter) {
                counter.textContent = len + "/" + max;
                counter.classList.toggle("warn", len >= max * 0.9 && len < max);
                counter.classList.toggle("over", len >= max);
            }
        };
        ta.addEventListener("input", update);
        update();
    });

    /* Move Draft to Final: sync the live draft text + count into the modal. */
    var moveDraftModal = document.getElementById("moveDraftModal");

    if (moveDraftModal) {
        moveDraftModal.addEventListener("show.bs.modal", function () {
            var draft = document.getElementById("vmDraftText");
            var hidden = document.getElementById("moveDraftText");
            var count = document.getElementById("moveDraftCount");
            var pass = document.getElementById("moveDraftPassword");
            var text = draft ? draft.value : "";

            if (hidden) hidden.value = text;
            if (count) count.textContent = text.length + " character" + (text.length === 1 ? "" : "s");
            if (pass) {
                pass.value = "";
                setTimeout(function () { pass.focus(); }, 150);
            }
        });
    }

    /* Final text: locked until Edit is clicked (prevents accidents). */
    (function initFinalEdit() {
        var ta = document.getElementById("vmFinalText");
        if (!ta) return;

        var edit = document.getElementById("vmFinalEdit");
        var cancel = document.getElementById("vmFinalCancel");
        var save = document.getElementById("vmFinalSave");
        var original = ta.value;

        var setLocked = function (locked) {
            if (locked) {
                ta.setAttribute("readonly", "");
            } else {
                ta.removeAttribute("readonly");
            }
            if (save) save.disabled = locked;
            if (edit) edit.hidden = !locked;
            if (cancel) cancel.hidden = locked;
        };

        if (edit) {
            edit.addEventListener("click", function () {
                original = ta.value;
                setLocked(false);
                ta.focus();
            });
        }

        if (cancel) {
            cancel.addEventListener("click", function () {
                ta.value = original;
                ta.dispatchEvent(new Event("input", { bubbles: true }));
                setLocked(true);
            });
        }

        setLocked(true);
    })();

    /* Publication links: per-link inline edit toggles. */
    document.addEventListener("click", function (e) {
        var editBtn = e.target && e.target.closest
            ? e.target.closest("[data-edit-link]")
            : null;

        if (editBtn) {
            var idx = editBtn.getAttribute("data-edit-link");
            var editor = document.querySelector('[data-editor-row="' + idx + '"]');

            if (editor) {
                var opening = editor.hasAttribute("hidden");
                document.querySelectorAll("[data-editor-row]").forEach(function (ed) {
                    ed.setAttribute("hidden", "");
                });

                if (opening) {
                    editor.removeAttribute("hidden");
                    var input = editor.querySelector('input[name="link_url"]');
                    if (input) input.focus();
                }
            }

            return;
        }

        var cancelBtn = e.target && e.target.closest
            ? e.target.closest("[data-edit-cancel]")
            : null;

        if (cancelBtn) {
            var row = cancelBtn.closest("[data-editor-row]");
            if (row) row.setAttribute("hidden", "");
        }
    });
</script>


<script>
/* =====================================================
   PUBLICATION REQUESTS — UX UPGRADE (additive, no backend change)
===================================================== */
document.addEventListener("DOMContentLoaded", function () {
    function $all(sel, root) {
        return Array.prototype.slice.call(
            (root || document).querySelectorAll(sel)
        );
    }
    function escHtml(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

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

    /* ---------- density ---------- */
    var densityBtn = document.getElementById("pubDensity");
    function setDensity(on, save) {
        document.body.classList.toggle("ultra-compact", !!on);
        if (densityBtn) {
            densityBtn.classList.toggle("is-active", !!on);
            densityBtn.setAttribute(
                "aria-pressed", on ? "true" : "false"
            );
        }
        try {
            if (save !== false) {
                localStorage.setItem(
                    "pub_density", on ? "1" : "0"
                );
            }
        } catch (e) {}
    }
    var savedDensity = null;
    try {
        savedDensity = localStorage.getItem("pub_density");
    } catch (e) {}
    setDensity(savedDensity === "1", false);
    if (densityBtn) {
        densityBtn.addEventListener("click", function () {
            setDensity(
                !document.body.classList.contains(
                    "ultra-compact"
                )
            );
        });
    }

    /* ---------- per-card status-flow hide / show ---------- */
    document.querySelectorAll(".submission-list").forEach(function (list) {
        list.addEventListener("click", function (e) {
            var btn = e.target && e.target.closest
                ? e.target.closest(".wf-hide-btn")
                : null;
            if (!btn || !list.contains(btn)) return;
            var flow = btn.closest(".event-card-v2-workflow");
            if (!flow) return;
            var hidden = flow.classList.toggle("is-hidden");
            var label = btn.querySelector("span");
            btn.setAttribute("aria-expanded", hidden ? "false" : "true");
            btn.setAttribute("title", hidden ? "Show status flow" : "Hide status flow");
            if (label) label.textContent = hidden ? "Show" : "Hide";
        });
    });

    /* ---------- search: clear, shortcuts, auto-submit status ---------- */
    var searchInput = document.getElementById("pubSearchInput");
    var searchClear = document.getElementById("pubSearchClear");
    var statusSelect = document.getElementById("pubStatusSelect");
    var filterForm = searchInput
        ? searchInput.closest("form")
        : null;
    function syncClear() {
        if (searchClear && searchInput) {
            searchClear.classList.toggle("show",
                !!(searchInput.value || "").trim());
        }
    }
    if (searchInput) {
        searchInput.addEventListener("input", syncClear);
        syncClear();
    }
    if (searchClear && searchInput) {
        searchClear.addEventListener("click", function () {
            searchInput.value = "";
            syncClear();
            applyInstant("");
            searchInput.focus();
        });
    }
    if (statusSelect && filterForm) {
        statusSelect.addEventListener("change", function () {
            filterForm.submit();
        });
    }
    document.addEventListener("keydown", function (ev) {
        var tag = (
            (document.activeElement || {}).tagName || ""
        ).toUpperCase();
        var typing = tag === "INPUT" || tag === "TEXTAREA"
            || tag === "SELECT";
        if (ev.key === "/" && !typing && searchInput) {
            ev.preventDefault();
            searchInput.focus();
            searchInput.select();
        }
        if (ev.key === "Escape"
            && document.activeElement === searchInput
            && searchInput) {
            searchInput.value = "";
            syncClear();
            applyInstant("");
        }
    });

    /* ---------- active filter chips ---------- */
    try {
        var chipHost = document.getElementById(
            "pubActiveChips"
        );
        var qp = new URLSearchParams(window.location.search);
        var chips = [];
        function chipLink(drop) {
            var p2 = new URLSearchParams(
                window.location.search
            );
            drop.forEach(function (k) { p2.delete(k); });
            return window.location.pathname
                + (p2.toString() ? "?" + p2.toString() : "");
        }
        if (qp.get("tab") && qp.get("tab") !== "all") {
            chips.push({
                label: "Tab: " + qp.get("tab"),
                href: chipLink(["tab", "view", "id"])
            });
        }
        if (qp.get("status")) {
            chips.push({
                label: qp.get("status"),
                href: chipLink(["status", "view", "id"])
            });
        }
        if (qp.get("q")) {
            chips.push({
                label: "“" + qp.get("q") + "”",
                href: chipLink(["q", "view", "id"])
            });
        }
        if (chipHost && chips.length) {
            var html = '<span class="active-chips-label">'
                + "Active:</span>";
            chips.forEach(function (c) {
                html += '<span class="filter-chip" title="'
                    + escHtml(c.label) + '"><span>'
                    + escHtml(c.label)
                    + '</span><a href="' + escHtml(c.href)
                    + '" title="Remove">&times;</a></span>';
            });
            if (chips.length > 1) {
                var keepTab = new URLSearchParams();
                if (qp.get("tab")) {
                    keepTab.set("tab", qp.get("tab"));
                }
                html += '<span class="filter-chip" style="background:#f1f5f9;color:#475569;">'
                    + "<span>Clear all</span>"
                    + '<a href="' + escHtml(
                        window.location.pathname
                        + (keepTab.toString()
                            ? "?" + keepTab.toString() : "")
                    ) + '" title="Clear all">&times;</a></span>';
            }
            chipHost.innerHTML = html;
            chipHost.classList.add("show");
        }
    } catch (e) {}

    /* ---------- cards: instant filter + client sort ---------- */
    var list = document.querySelector(".submission-list");
    var cards = list
        ? $all(".submission-card", list)
        : [];
    var liveCount = document.getElementById("pubLiveCount");
    var sortSelect = document.getElementById("pubSort");
    var serverTotal = cards.length;
    var statusOrder = [
        "For Review", "In Preparation", "For Approval",
        "Published", "Closed"
    ];
    var noMatch = null;
    if (list) {
        noMatch = document.createElement("div");
        noMatch.className = "empty-state";
        noMatch.style.display = "none";
        noMatch.innerHTML = "<i>"
            + "No cards match your typing.</i>"
            + "<span>Server returned " + serverTotal
            + " submission(s) — clear the search box to see them all.</span>";
        list.appendChild(noMatch);
    }
    var instantT = null;
    function currentQuery() {
        return searchInput
            ? (searchInput.value || "").trim().toLowerCase()
            : "";
    }
    function applyInstant(q) {
        if (typeof q !== "string") q = currentQuery();
        var shown = 0;
        cards.forEach(function (c) {
            var hit = !q
                || (c.textContent || "").toLowerCase()
                    .indexOf(q) !== -1;
            c.style.display = hit ? "" : "none";
            if (hit) shown++;
        });
        if (liveCount) {
            liveCount.textContent = q
                ? shown + " of " + serverTotal + " shown • typing"
                : "Showing " + serverTotal + " submission"
                    + (serverTotal === 1 ? "" : "s");
        }
        if (noMatch) {
            noMatch.style.display =
                (q && shown === 0) ? "" : "none";
        }
    }
    function applySort() {
        if (!list || !sortSelect) return;
        var mode = sortSelect.value;
        var ordered = cards.slice();
        ordered.sort(function (a, b) {
            if (mode === "title") {
                return (a.dataset.title || "").localeCompare(
                    b.dataset.title || ""
                );
            }
            if (mode === "status") {
                return statusOrder.indexOf(a.dataset.status)
                    - statusOrder.indexOf(b.dataset.status);
            }
            var da = a.dataset.date || "";
            var db = b.dataset.date || "";
            if (da === db) return 0;
            return mode === "oldest"
                ? (da < db ? -1 : 1)
                : (da > db ? -1 : 1);
        });
        ordered.forEach(function (c) { list.appendChild(c); });
        if (noMatch) list.appendChild(noMatch);
        applyInstant(currentQuery());
    }
    if (searchInput) {
        searchInput.addEventListener("input", function () {
            syncClear();
            clearTimeout(instantT);
            instantT = setTimeout(function () {
                applyInstant(currentQuery());
            }, 140);
        });
    }
    if (sortSelect) {
        sortSelect.addEventListener("change", applySort);
    }
    /* Default newest-first to mirror server order. */
    if (list && sortSelect) applySort();

    /* ---------- KPI count-up ---------- */
    try {
        var reduceMotion = window.matchMedia &&
            window.matchMedia(
                "(prefers-reduced-motion: reduce)"
            ).matches;
        $all(".kpi-value").forEach(function (el) {
            var raw = (el.textContent || "")
                .replace(/,/g, "").trim();
            var target = parseInt(raw, 10);
            if (isNaN(target) || reduceMotion) return;
            var t0 = null;
            function tick(t) {
                if (!t0) t0 = t;
                var p = Math.min(1, (t - t0) / 650);
                el.textContent = Math.round(
                    target * (1 - Math.pow(1 - p, 3))
                ).toLocaleString();
                if (p < 1) requestAnimationFrame(tick);
            }
            requestAnimationFrame(tick);
        });
    } catch (e) {}

    /* ---------- mini progress reveal ---------- */
    try {
        var fills = $all(".mini-progress-fill");
        fills.forEach(function (f) {
            f.dataset.w = f.style.width || "0%";
            f.style.width = "0%";
        });
        if ("IntersectionObserver" in window) {
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
                { threshold: 0.3 }
            );
            fills.forEach(function (f) { io.observe(f); });
        } else {
            fills.forEach(function (f) {
                f.style.width = f.dataset.w;
            });
        }
    } catch (e) {}

    /* ---------- copy view-link per card ---------- */
    $all(".submission-card").forEach(function (card) {
        var foot = card.querySelector(".submission-foot");
        var view = card.querySelector(
            '.submission-foot a.btn[href*="view="]'
        );
        if (!foot || !view || card.querySelector(
            ".copy-link-btn"
        )) return;
        var btn = document.createElement("button");
        btn.type = "button";
        btn.className = "copy-link-btn";
        btn.title = "Copy link to this submission";
        btn.setAttribute("aria-label",
            "Copy link to this submission");
        btn.innerHTML = '<i class="bi bi-link-45deg"></i>';
        btn.addEventListener("click", function () {
            var href = view.getAttribute("href") || "";
            var abs = href;
            try {
                abs = new URL(
                    href, window.location.origin
                ).toString();
            } catch (e) {}
            function done() {
                showToast("Link copied to clipboard");
            }
            if (navigator.clipboard
                && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(abs).then(
                    done, done
                );
            } else { done(); }
        });
        view.parentElement.insertBefore(btn, view);
    });

    document.addEventListener("click", function (ev) {
        var btn = ev.target && ev.target.closest ? ev.target.closest(".copy-link-btn") : null;
        if (!btn) return;
        var url = btn.getAttribute("data-copy-url");
        if (!url) return;
        ev.preventDefault();
        var abs = url;
        try { abs = new URL(url, window.location.origin).toString(); } catch (e) {}
        function done() {
            if (typeof showToast === "function") showToast("Link copied to clipboard");
            else alert("Link copied to clipboard");
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(abs).then(done, done);
        } else {
            done();
        }
    });

    /* ---------- confirm before Closing (detail modal) ---------- */
    $all("form").forEach(function (form) {
        var action = form.querySelector(
            'input[name="action"][value="update_workflow"]'
        );
        var status = form.querySelector(
            'select[name="status"]'
        );
        if (!action || !status) return;
        form.addEventListener("submit", function (ev) {
            if (status.value === "Closed"
                && !window.confirm(
                    "Close this submission? Closed items are terminal "
                    + "and leave the active workflow."
                )) {
                ev.preventDefault();
            }
        });
    });

    /* ---------- back to top ---------- */
    var toTop = document.createElement("button");
    toTop.id = "pubToTop";
    toTop.type = "button";
    toTop.title = "Back to top";
    toTop.setAttribute("aria-label", "Back to top");
    toTop.innerHTML = '<i class="bi bi-arrow-up"></i>';
    document.body.appendChild(toTop);
    window.addEventListener("scroll", function () {
        toTop.classList.toggle(
            "show", window.scrollY > 600
        );
    }, { passive: true });
    toTop.addEventListener("click", function () {
        window.scrollTo({ top: 0, behavior: "smooth" });
    });
});
</script>

<script src="<?= e(BASE_URL) ?>/assets/js/publication_chat.js"></script>

<div class="modal fade" id="phase3PreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content phase3-preview-modal">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-paperclip me-2"></i>Attachment Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="phase3PreviewBody"></div>
        </div>
    </div>
</div>

<div class="modal fade" id="filePreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content phase3-preview-modal">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-paperclip me-2"></i>File Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/mammoth@1.6.0/mammoth.browser.min.js"></script>
<script src="<?= e(BASE_URL) ?>/assets/js/request_view_phase3.js?v=3.2"></script>

</body>
</html>

