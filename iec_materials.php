<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - IEC MATERIALS GOVERNANCE LIBRARY
|--------------------------------------------------------------------------
| Drop-in replacement:
|   C:\laragon\www\jobrequest_phu\iec_materials.php
|--------------------------------------------------------------------------
| Adds:
| - Status / approval workflow
| - Material type / health topic / target audience
| - Featured materials
| - Version history on file replacement
| - Review / validity dates
| - Role-aware editing
| - Audit trail
| - Image thumbnails
| - Pagination
|--------------------------------------------------------------------------
*/

include __DIR__ . "/includes/config.php";
include __DIR__ . "/includes/auth.php";
include __DIR__ . "/includes/helpers.php";
require_once __DIR__ . "/includes/module_access.php";

checkLogin();
requireModule($conn, "iec_materials");

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

$userId =
    (int) (
        $_SESSION["user_id"]
        ?? 0
    );

$role =
    (string) (
        $_SESSION["role"]
        ?? "user"
    );

$isStaff =
    $role === "staff";

$isSupervisor =
    $role === "supervisor";

$isAdmin =
    $role === "admin";

$isHpuRole =
    in_array(
        $role,
        [
            "staff",
            "supervisor",
            "admin"
        ],
        true
    );

$isManager =
    in_array(
        $role,
        [
            "supervisor",
            "admin"
        ],
        true
    );

$canUpload =
    $isHpuRole;

$canApprove =
    $isManager;

$canArchive =
    $isManager;

$canDelete =
    $isAdmin;

/*
|--------------------------------------------------------------------------
| Reference Options
|--------------------------------------------------------------------------
*/

$statusOptions = [
    "Draft",
    "For Review",
    "Approved",
    "Superseded"
];

$materialTypes = [
    "Poster / Flyer",
    "Infographic",
    "Brochure",
    "Presentation",
    "Video",
    "Handbook / Guide",
    "Form / Card",
    "Other"
];

$audienceOptions = [
    "Patients",
    "Watchers / Families",
    "Hospital Staff",
    "BHWs",
    "General Public",
    "Healthcare Professionals"
];

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function iecTableExists(
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
        (int) (
            $row["total"]
            ?? 0
        ) > 0;
}

function iecColumnExists(
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
        (int) (
            $row["total"]
            ?? 0
        ) > 0;
}

function iecFileSize(
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

    if (
        $bytes
        <
        1024 * 1024 * 1024
    ) {
        return
            number_format(
                $bytes / (1024 * 1024),
                1
            )
            . " MB";
    }

    return
        number_format(
            $bytes / (1024 * 1024 * 1024),
            1
        )
        . " GB";
}

function iecFileIcon(
    string $extension
): string {
    return match (
    strtolower(
        $extension
    )
    ) {
        "pdf" =>
        "bi-file-earmark-pdf",

        "doc",
        "docx" =>
        "bi-file-earmark-word",

        "ppt",
        "pptx" =>
        "bi-file-earmark-slides",

        "xls",
        "xlsx",
        "csv" =>
        "bi-file-earmark-spreadsheet",

        "jpg",
        "jpeg",
        "png",
        "webp" =>
        "bi-file-earmark-image",

        "mp4",
        "mov",
        "webm" =>
        "bi-file-earmark-play",

        default =>
        "bi-file-earmark"
    };
}

function iecFileTypeLabel(
    string $extension
): string {
    $extension =
        strtolower(
            $extension
        );

    return match ($extension) {
        "pdf" =>
        "PDF",

        "doc",
        "docx" =>
        "Document",

        "ppt",
        "pptx" =>
        "Presentation",

        "xls",
        "xlsx",
        "csv" =>
        "Spreadsheet",

        "jpg",
        "jpeg",
        "png",
        "webp" =>
        "Image",

        "mp4",
        "mov",
        "webm" =>
        "Video",

        default =>
        strtoupper(
            $extension
        )
    };
}

function iecFileTypeClass(
    string $extension
): string {
    $extension =
        strtolower(
            $extension
        );

    return match ($extension) {
        "pdf" =>
        "file-pdf",

        "doc",
        "docx" =>
        "file-doc",

        "ppt",
        "pptx" =>
        "file-ppt",

        "xls",
        "xlsx",
        "csv" =>
        "file-sheet",

        "jpg",
        "jpeg",
        "png",
        "webp" =>
        "file-image",

        "mp4",
        "mov",
        "webm" =>
        "file-video",

        default =>
        "file-other"
    };
}

function iecInlineAllowed(
    string $extension
): bool {
    return
        in_array(
            strtolower(
                $extension
            ),
            [
                "pdf",
                "jpg",
                "jpeg",
                "png",
                "webp"
            ],
            true
        );
}

function iecImageExtension(
    string $extension
): bool {
    return
        in_array(
            strtolower(
                $extension
            ),
            [
                "jpg",
                "jpeg",
                "png",
                "webp"
            ],
            true
        );
}

function iecStatusClass(
    string $status
): string {
    return match ($status) {
        "Approved" =>
        "status-approved",

        "For Review" =>
        "status-review",

        "Superseded" =>
        "status-superseded",

        default =>
        "status-draft"
    };
}

function iecCanEditMaterial(
    array $material,
    int $userId,
    bool $isManager
): bool {
    if ($isManager) {
        return true;
    }

    return
        (int) (
            $material["uploaded_by"]
            ?? 0
        )
        ===
        $userId;
}

function iecRedirect(
    string $query = ""
): never {
    header(
        "Location: "
        . BASE_URL
        . "/iec_materials.php"
        . $query
    );

    exit;
}

function iecFlash(
    string $type,
    string $message,
    string $query = ""
): never {
    $_SESSION["iec_flash"] = [
        "type" =>
            $type,

        "message" =>
            $message
    ];

    iecRedirect(
        $query
    );
}

function iecValidateCsrf(
    string $csrf
): void {
    $posted =
        (string) (
            $_POST["csrf_token"]
            ?? ""
        );

    if (
        $posted === ""
        ||
        !hash_equals(
            $csrf,
            $posted
        )
    ) {
        iecFlash(
            "danger",
            "Security token expired. Please refresh the page and try again."
        );
    }
}

function iecCleanAudience(
    array $values,
    array $allowed
): array {
    $clean = [];

    foreach ($values as $value) {
        $value =
            trim(
                (string) 
                $value
            );

        if (
            $value !== ""
            &&
            in_array(
                $value,
                $allowed,
                true
            )
        ) {
            $clean[] =
                $value;
        }
    }

    return
        array_values(
            array_unique(
                $clean
            )
        );
}

function iecDecodeAudience(
    ?string $json
): array {
    if (
        $json === null
        ||
        trim(
            $json
        ) === ""
    ) {
        return [];
    }

    $decoded =
        json_decode(
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

function iecValidDateOrNull(
    ?string $value
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
        $date->format(
            "Y-m-d"
        )
        !==
        $value
    ) {
        return null;
    }

    return $value;
}

function iecValidateUpload(
    array $file
): array {
    if (
        (
            $file["error"]
            ?? UPLOAD_ERR_NO_FILE
        )
        !==
        UPLOAD_ERR_OK
    ) {
        throw new RuntimeException(
            "Upload failed. Check the selected file and your PHP upload size limit."
        );
    }

    $maxBytes =
        50
        * 1024
        * 1024;

    $size =
        (int) (
            $file["size"]
            ?? 0
        );

    if (
        $size <= 0
        ||
        $size > $maxBytes
    ) {
        throw new RuntimeException(
            "The file must be larger than 0 bytes and must not exceed 50 MB."
        );
    }

    $originalName =
        basename(
            (string) (
                $file["name"]
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

    $allowedExtensions = [
        "pdf",
        "doc",
        "docx",
        "ppt",
        "pptx",
        "xls",
        "xlsx",
        "csv",
        "jpg",
        "jpeg",
        "png",
        "webp",
        "mp4",
        "mov",
        "webm"
    ];

    if (
        !in_array(
            $extension,
            $allowedExtensions,
            true
        )
    ) {
        throw new RuntimeException(
            "Unsupported file type."
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
            $file["tmp_name"]
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

    foreach (
        $blockedMimeParts
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
                "The selected file is blocked for security reasons."
            );
        }
    }

    return [
        "original_name" =>
            $originalName,

        "extension" =>
            $extension,

        "mime" =>
            $mime,

        "size" =>
            $size
    ];
}

function iecLog(
    mysqli $conn,
    int $materialId,
    int $userId,
    string $action,
    string $details = ""
): void {
    $stmt =
        $conn->prepare(
            "INSERT INTO iec_material_logs (
                material_id,
                user_id,
                action,
                details
             )
             VALUES (?, ?, ?, ?)"
        );

    $stmt->bind_param(
        "iiss",
        $materialId,
        $userId,
        $action,
        $details
    );

    $stmt->execute();
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Base Table
|--------------------------------------------------------------------------
*/

$conn->query(
    "CREATE TABLE IF NOT EXISTS iec_materials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(220) NOT NULL,
        category VARCHAR(100) NOT NULL DEFAULT 'General IEC',
        description TEXT NULL,
        original_filename VARCHAR(255) NOT NULL,
        stored_filename VARCHAR(255) NOT NULL,
        file_extension VARCHAR(20) NOT NULL,
        mime_type VARCHAR(150) NULL,
        file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        uploaded_by INT NOT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP,
        download_count INT UNSIGNED NOT NULL DEFAULT 0,
        view_count INT UNSIGNED NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        INDEX idx_iec_active (active),
        INDEX idx_iec_category (category),
        INDEX idx_iec_uploader (uploaded_by)
    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci"
);

/*
|--------------------------------------------------------------------------
| Backward-Compatible Governance Columns
|--------------------------------------------------------------------------
*/

$columnsToAdd = [
    "material_type" =>
        "VARCHAR(80) NULL",

    "health_topic" =>
        "VARCHAR(180) NULL",

    "target_audience" =>
        "TEXT NULL",

    "status" =>
        "VARCHAR(40) NOT NULL DEFAULT 'Draft'",

    "version_number" =>
        "VARCHAR(30) NOT NULL DEFAULT '1.0'",

    "version_notes" =>
        "VARCHAR(255) NULL",

    "review_date" =>
        "DATE NULL",

    "valid_until" =>
        "DATE NULL",

    "featured" =>
        "TINYINT(1) NOT NULL DEFAULT 0",

    "approved_by" =>
        "INT NULL",

    "approved_at" =>
        "DATETIME NULL",

    "updated_by" =>
        "INT NULL",

    "view_count" =>
        "INT UNSIGNED NOT NULL DEFAULT 0"
];

foreach (
    $columnsToAdd
    as $column =>
    $definition
) {
    if (
        !iecColumnExists(
            $conn,
            "iec_materials",
            $column
        )
    ) {
        $conn->query(
            "ALTER TABLE iec_materials
             ADD COLUMN `{$column}`
             {$definition}"
        );
    }
}

/*
|--------------------------------------------------------------------------
| Version History
|--------------------------------------------------------------------------
*/

$conn->query(
    "CREATE TABLE IF NOT EXISTS iec_material_versions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        material_id INT NOT NULL,
        version_number VARCHAR(30) NOT NULL,
        version_notes VARCHAR(255) NULL,
        original_filename VARCHAR(255) NOT NULL,
        stored_filename VARCHAR(255) NOT NULL,
        file_extension VARCHAR(20) NOT NULL,
        mime_type VARCHAR(150) NULL,
        file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        replaced_by INT NOT NULL,
        replaced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_iec_version_material (material_id),
        INDEX idx_iec_version_replaced_by (replaced_by)
    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci"
);

/*
|--------------------------------------------------------------------------
| Audit Logs
|--------------------------------------------------------------------------
*/

$conn->query(
    "CREATE TABLE IF NOT EXISTS iec_material_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        material_id INT NOT NULL,
        user_id INT NOT NULL,
        action VARCHAR(80) NOT NULL,
        details TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_iec_log_material (material_id),
        INDEX idx_iec_log_user (user_id),
        INDEX idx_iec_log_created (created_at)
    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci"
);

/*
|--------------------------------------------------------------------------
| Storage
|--------------------------------------------------------------------------
*/

$storageDir =
    __DIR__
    . DIRECTORY_SEPARATOR
    . "storage"
    . DIRECTORY_SEPARATOR
    . "iec_materials";

if (
    !is_dir(
        $storageDir
    )
) {
    if (
        !mkdir(
            $storageDir,
            0775,
            true
        )
        &&
        !is_dir(
            $storageDir
        )
    ) {
        exit(
            "Unable to create storage/iec_materials directory."
        );
    }
}

$denyFile =
    $storageDir
    . DIRECTORY_SEPARATOR
    . ".htaccess";

if (
    !is_file(
        $denyFile
    )
) {
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

if (
    empty(
    $_SESSION["iec_csrf"]
)
) {
    $_SESSION["iec_csrf"] =
        bin2hex(
            random_bytes(
                32
            )
        );
}

$csrf =
    (string) 
    $_SESSION["iec_csrf"];

/*
|--------------------------------------------------------------------------
| Authenticated File View / Download
|--------------------------------------------------------------------------
*/

if (
    isset(
    $_GET["file"],
    $_GET["mode"]
)
) {
    $id =
        (int) 
        $_GET["file"];

    $mode =
        $_GET["mode"]
        ===
        "view"
        ? "view"
        : "download";

    $stmt =
        $conn->prepare(
            "SELECT *
             FROM iec_materials
             WHERE id = ?
               AND active = 1
             LIMIT 1"
        );

    $stmt->bind_param(
        "i",
        $id
    );

    $stmt->execute();

    $material =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    if (!$material) {
        http_response_code(
            404
        );

        exit(
            "IEC material not found."
        );
    }

    $filePath =
        $storageDir
        . DIRECTORY_SEPARATOR
        . basename(
            (string) 
            $material["stored_filename"]
        );

    if (
        !is_file(
            $filePath
        )
    ) {
        http_response_code(
            404
        );

        exit(
            "Stored IEC file is missing."
        );
    }

    if (
        $mode
        ===
        "download"
    ) {
        $stmt =
            $conn->prepare(
                "UPDATE iec_materials
                 SET download_count =
                    download_count + 1
                 WHERE id = ?"
            );

        $stmt->bind_param(
            "i",
            $id
        );

        $stmt->execute();
        $stmt->close();

        iecLog(
            $conn,
            $id,
            $userId,
            "Downloaded",
            (string) 
            $material["original_filename"]
        );
    }

    /*
     * Count explicit file views (the View button links with
     * &count=1). Inline image previews load without the flag so
     * card rendering does not inflate the view counter.
     */
    if (
        $mode
        ===
        "view"
        &&
        (
            $_GET["count"]
            ??
            ""
        )
        ===
        "1"
    ) {
        $stmt =
            $conn->prepare(
                "UPDATE iec_materials
                 SET view_count =
                    view_count + 1
                 WHERE id = ?"
            );

        $stmt->bind_param(
            "i",
            $id
        );

        $stmt->execute();
        $stmt->close();

        iecLog(
            $conn,
            $id,
            $userId,
            "Viewed",
            (string)
            $material["original_filename"]
        );
    }

    $mime =
        $material["mime_type"]
        ?:
        "application/octet-stream";

    $disposition =
        $mode
        ===
        "view"
        &&
        iecInlineAllowed(
            (string) 
            $material["file_extension"]
        )
        ? "inline"
        : "attachment";

    $filename =
        (string) 
        $material["original_filename"];

    header(
        "Content-Type: "
        . $mime
    );

    header(
        "Content-Length: "
        . filesize(
            $filePath
        )
    );

    header(
        "X-Content-Type-Options: nosniff"
    );

    header(
        "Cache-Control: private, max-age=0, must-revalidate"
    );

    header(
        "Content-Disposition: "
        . $disposition
        . "; filename=\""
        . addcslashes(
            $filename,
            "\"\\"
        )
        . "\"; filename*=UTF-8''"
        . rawurlencode(
            $filename
        )
    );

    readfile(
        $filePath
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Version File Download
|--------------------------------------------------------------------------
*/

if (
    isset(
    $_GET["version_file"]
)
) {
    if (!$isHpuRole) {
        http_response_code(
            403
        );

        exit(
            "Only HPU personnel can access previous IEC versions."
        );
    }

    $versionId =
        (int) 
        $_GET["version_file"];

    $stmt =
        $conn->prepare(
            "SELECT *
             FROM iec_material_versions
             WHERE id = ?
             LIMIT 1"
        );

    $stmt->bind_param(
        "i",
        $versionId
    );

    $stmt->execute();

    $version =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    if (!$version) {
        http_response_code(
            404
        );

        exit(
            "IEC version not found."
        );
    }

    $filePath =
        $storageDir
        . DIRECTORY_SEPARATOR
        . basename(
            (string) 
            $version["stored_filename"]
        );

    if (
        !is_file(
            $filePath
        )
    ) {
        http_response_code(
            404
        );

        exit(
            "Stored IEC version file is missing."
        );
    }

    header(
        "Content-Type: "
        . (
            $version["mime_type"]
            ?:
            "application/octet-stream"
        )
    );

    header(
        "X-Content-Type-Options: nosniff"
    );

    header(
        "Content-Disposition: attachment; filename=\""
        . addcslashes(
            (string) 
            $version["original_filename"],
            "\"\\"
        )
        . "\""
    );

    readfile(
        $filePath
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| POST Actions
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"]
    ===
    "POST"
) {
    iecValidateCsrf(
        $csrf
    );

    $action =
        trim(
            (string) (
                $_POST["action"]
                ?? ""
            )
        );

    /*
    |--------------------------------------------------------------------------
    | Upload
    |--------------------------------------------------------------------------
    */

    if (
        $action
        ===
        "upload"
    ) {
        if (!$canUpload) {
            http_response_code(
                403
            );

            exit(
                "Only HPU personnel can upload IEC materials."
            );
        }

        $title =
            trim(
                (string) (
                    $_POST["title"]
                    ?? ""
                )
            );

        $category =
            trim(
                (string) (
                    $_POST["category"]
                    ?? "General IEC"
                )
            );

        $materialType =
            trim(
                (string) (
                    $_POST["material_type"]
                    ?? ""
                )
            );

        $healthTopic =
            trim(
                (string) (
                    $_POST["health_topic"]
                    ?? ""
                )
            );

        $description =
            trim(
                (string) (
                    $_POST["description"]
                    ?? ""
                )
            );

        $versionNumber =
            trim(
                (string) (
                    $_POST["version_number"]
                    ?? "1.0"
                )
            );

        $versionNotes =
            trim(
                (string) (
                    $_POST["version_notes"]
                    ?? ""
                )
            );

        $reviewDate =
            iecValidDateOrNull(
                $_POST["review_date"]
                ?? null
            );

        $validUntil =
            iecValidDateOrNull(
                $_POST["valid_until"]
                ?? null
            );

        $featured =
            isset(
            $_POST["featured"]
        )
            ? 1
            : 0;

        $targetAudience =
            $_POST["target_audience"]
            ?? [];

        if (
            !is_array(
                $targetAudience
            )
        ) {
            $targetAudience = [];
        }

        $targetAudience =
            iecCleanAudience(
                $targetAudience,
                $audienceOptions
            );

        if (
            $title
            ===
            ""
        ) {
            iecFlash(
                "danger",
                "Material title is required."
            );
        }

        if (
            !in_array(
                $materialType,
                $materialTypes,
                true
            )
        ) {
            iecFlash(
                "danger",
                "Please select a valid material type."
            );
        }

        if (
            !isset(
            $_FILES["iec_file"]
        )
        ) {
            iecFlash(
                "danger",
                "Please choose a file to upload."
            );
        }

        try {
            $validated =
                iecValidateUpload(
                    $_FILES["iec_file"]
                );
        } catch (
            RuntimeException $e
        ) {
            iecFlash(
                "danger",
                $e->getMessage()
            );
        }

        $storedName =
            bin2hex(
                random_bytes(
                    18
                )
            )
            . "."
            . $validated["extension"];

        $destination =
            $storageDir
            . DIRECTORY_SEPARATOR
            . $storedName;

        if (
            !move_uploaded_file(
                (string) 
                $_FILES["iec_file"]["tmp_name"],
                $destination
            )
        ) {
            iecFlash(
                "danger",
                "The server could not save the uploaded file."
            );
        }

        $categoryDb =
            $category !== ""
            ? $category
            : "General IEC";

        $descriptionDb =
            $description !== ""
            ? $description
            : null;

        $healthTopicDb =
            $healthTopic !== ""
            ? $healthTopic
            : null;

        $versionNotesDb =
            $versionNotes !== ""
            ? $versionNotes
            : null;

        $targetAudienceJson =
            json_encode(
                $targetAudience,
                JSON_UNESCAPED_UNICODE
            );

        /*
         * New uploads begin as Draft.
         * Supervisor/Admin can approve later.
         */
        $status =
            "Draft";

        try {
            $stmt =
                $conn->prepare(
                    "INSERT INTO iec_materials (
                        title,
                        category,
                        description,
                        original_filename,
                        stored_filename,
                        file_extension,
                        mime_type,
                        file_size,
                        uploaded_by,
                        active,
                        material_type,
                        health_topic,
                        target_audience,
                        status,
                        version_number,
                        version_notes,
                        review_date,
                        valid_until,
                        featured,
                        updated_by
                     )
                     VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, 1,
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                     )"
                );

            $stmt->bind_param(
                "sssssssii" . "ssssssssii",
                $title,
                $categoryDb,
                $descriptionDb,
                $validated["original_name"],
                $storedName,
                $validated["extension"],
                $validated["mime"],
                $validated["size"],
                $userId,
                $materialType,
                $healthTopicDb,
                $targetAudienceJson,
                $status,
                $versionNumber,
                $versionNotesDb,
                $reviewDate,
                $validUntil,
                $featured,
                $userId
            );

            $stmt->execute();

            $materialId =
                $stmt->insert_id;

            $stmt->close();

            iecLog(
                $conn,
                $materialId,
                $userId,
                "Uploaded",
                "Initial version "
                . $versionNumber
            );

        } catch (
            Throwable $e
        ) {
            @unlink(
                $destination
            );

            throw $e;
        }

        iecFlash(
            "success",
            "IEC material uploaded as Draft."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Edit / Optional File Replacement
    |--------------------------------------------------------------------------
    */

    if (
        $action
        ===
        "edit"
    ) {
        $id =
            (int) (
                $_POST["id"]
                ?? 0
            );

        $stmt =
            $conn->prepare(
                "SELECT *
                 FROM iec_materials
                 WHERE id = ?
                 LIMIT 1"
            );

        $stmt->bind_param(
            "i",
            $id
        );

        $stmt->execute();

        $existing =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$existing) {
            iecFlash(
                "danger",
                "IEC material not found."
            );
        }

        if (
            !iecCanEditMaterial(
                $existing,
                $userId,
                $isManager
            )
        ) {
            http_response_code(
                403
            );

            exit(
                "Staff may edit only IEC materials they uploaded."
            );
        }

        $title =
            trim(
                (string) (
                    $_POST["title"]
                    ?? ""
                )
            );

        $category =
            trim(
                (string) (
                    $_POST["category"]
                    ?? "General IEC"
                )
            );

        $materialType =
            trim(
                (string) (
                    $_POST["material_type"]
                    ?? ""
                )
            );

        $healthTopic =
            trim(
                (string) (
                    $_POST["health_topic"]
                    ?? ""
                )
            );

        $description =
            trim(
                (string) (
                    $_POST["description"]
                    ?? ""
                )
            );

        $versionNumber =
            trim(
                (string) (
                    $_POST["version_number"]
                    ?? "1.0"
                )
            );

        $versionNotes =
            trim(
                (string) (
                    $_POST["version_notes"]
                    ?? ""
                )
            );

        $reviewDate =
            iecValidDateOrNull(
                $_POST["review_date"]
                ?? null
            );

        $validUntil =
            iecValidDateOrNull(
                $_POST["valid_until"]
                ?? null
            );

        $featured =
            isset(
            $_POST["featured"]
        )
            ? 1
            : 0;

        $targetAudience =
            $_POST["target_audience"]
            ?? [];

        if (
            !is_array(
                $targetAudience
            )
        ) {
            $targetAudience = [];
        }

        $targetAudience =
            iecCleanAudience(
                $targetAudience,
                $audienceOptions
            );

        if (
            $title
            ===
            ""
        ) {
            iecFlash(
                "danger",
                "Material title is required."
            );
        }

        if (
            !in_array(
                $materialType,
                $materialTypes,
                true
            )
        ) {
            iecFlash(
                "danger",
                "Please select a valid material type."
            );
        }

        $categoryDb =
            $category !== ""
            ? $category
            : "General IEC";

        $healthTopicDb =
            $healthTopic !== ""
            ? $healthTopic
            : null;

        $descriptionDb =
            $description !== ""
            ? $description
            : null;

        $versionNotesDb =
            $versionNotes !== ""
            ? $versionNotes
            : null;

        $targetAudienceJson =
            json_encode(
                $targetAudience,
                JSON_UNESCAPED_UNICODE
            );

        $hasReplacement =
            isset(
            $_FILES["replacement_file"]
        )
            &&
            (
                $_FILES["replacement_file"]["error"]
                ?? UPLOAD_ERR_NO_FILE
            )
            !==
            UPLOAD_ERR_NO_FILE;

        if (
            !$hasReplacement
        ) {
            $stmt =
                $conn->prepare(
                    "UPDATE iec_materials
                     SET
                        title = ?,
                        category = ?,
                        material_type = ?,
                        health_topic = ?,
                        target_audience = ?,
                        description = ?,
                        version_number = ?,
                        version_notes = ?,
                        review_date = ?,
                        valid_until = ?,
                        featured = ?,
                        updated_by = ?
                     WHERE id = ?"
                );

            $stmt->bind_param(
                "ssssssssssiii",
                $title,
                $categoryDb,
                $materialType,
                $healthTopicDb,
                $targetAudienceJson,
                $descriptionDb,
                $versionNumber,
                $versionNotesDb,
                $reviewDate,
                $validUntil,
                $featured,
                $userId,
                $id
            );

            $stmt->execute();
            $stmt->close();

            iecLog(
                $conn,
                $id,
                $userId,
                "Edited",
                "Metadata updated."
            );

            iecFlash(
                "success",
                "IEC material updated successfully."
            );
        }

        try {
            $validated =
                iecValidateUpload(
                    $_FILES["replacement_file"]
                );
        } catch (
            RuntimeException $e
        ) {
            iecFlash(
                "danger",
                $e->getMessage()
            );
        }

        $newStoredName =
            bin2hex(
                random_bytes(
                    18
                )
            )
            . "."
            . $validated["extension"];

        $newDestination =
            $storageDir
            . DIRECTORY_SEPARATOR
            . $newStoredName;

        if (
            !move_uploaded_file(
                (string) 
                $_FILES["replacement_file"]["tmp_name"],
                $newDestination
            )
        ) {
            iecFlash(
                "danger",
                "The replacement file could not be saved."
            );
        }

        $conn->begin_transaction();

        try {
            /*
             * Preserve the old physical file as a version.
             */
            $versionStmt =
                $conn->prepare(
                    "INSERT INTO iec_material_versions (
                        material_id,
                        version_number,
                        version_notes,
                        original_filename,
                        stored_filename,
                        file_extension,
                        mime_type,
                        file_size,
                        replaced_by
                     )
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );

            $oldVersion =
                (string) (
                    $existing["version_number"]
                    ?? "1.0"
                );

            $oldVersionNotes =
                $existing["version_notes"]
                ?? null;

            $versionStmt->bind_param(
                "issssssii",
                $id,
                $oldVersion,
                $oldVersionNotes,
                $existing["original_filename"],
                $existing["stored_filename"],
                $existing["file_extension"],
                $existing["mime_type"],
                $existing["file_size"],
                $userId
            );

            $versionStmt->execute();
            $versionStmt->close();

            /*
             * Replacing a file moves it back to Draft unless
             * Supervisor/Admin later approves the new version.
             */
            $newStatus =
                "Draft";

            $stmt =
                $conn->prepare(
                    "UPDATE iec_materials
                     SET
                        title = ?,
                        category = ?,
                        material_type = ?,
                        health_topic = ?,
                        target_audience = ?,
                        description = ?,
                        version_number = ?,
                        version_notes = ?,
                        review_date = ?,
                        valid_until = ?,
                        featured = ?,
                        original_filename = ?,
                        stored_filename = ?,
                        file_extension = ?,
                        mime_type = ?,
                        file_size = ?,
                        status = ?,
                        approved_by = NULL,
                        approved_at = NULL,
                        updated_by = ?
                     WHERE id = ?"
                );

            $stmt->bind_param(
                "ssssssssssissssissi",
                $title,
                $categoryDb,
                $materialType,
                $healthTopicDb,
                $targetAudienceJson,
                $descriptionDb,
                $versionNumber,
                $versionNotesDb,
                $reviewDate,
                $validUntil,
                $featured,
                $validated["original_name"],
                $newStoredName,
                $validated["extension"],
                $validated["mime"],
                $validated["size"],
                $newStatus,
                $userId,
                $id
            );

            $stmt->execute();
            $stmt->close();

            $conn->commit();

            iecLog(
                $conn,
                $id,
                $userId,
                "New Version",
                "Replaced file with version "
                . $versionNumber
                . ". Previous file preserved in version history."
            );

        } catch (
            Throwable $e
        ) {
            $conn->rollback();

            @unlink(
                $newDestination
            );

            throw $e;
        }

        iecFlash(
            "success",
            "New IEC version uploaded and returned to Draft status."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Workflow Status
    |--------------------------------------------------------------------------
    */

    if (
        $action
        ===
        "set_status"
    ) {
        $id =
            (int) (
                $_POST["id"]
                ?? 0
            );

        $newStatus =
            trim(
                (string) (
                    $_POST["status"]
                    ?? ""
                )
            );

        if (
            !in_array(
                $newStatus,
                $statusOptions,
                true
            )
        ) {
            iecFlash(
                "danger",
                "Invalid IEC status."
            );
        }

        $stmt =
            $conn->prepare(
                "SELECT *
                 FROM iec_materials
                 WHERE id = ?
                 LIMIT 1"
            );

        $stmt->bind_param(
            "i",
            $id
        );

        $stmt->execute();

        $material =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$material) {
            iecFlash(
                "danger",
                "IEC material not found."
            );
        }

        if (
            $newStatus
            ===
            "For Review"
        ) {
            if (
                !iecCanEditMaterial(
                    $material,
                    $userId,
                    $isManager
                )
            ) {
                http_response_code(
                    403
                );

                exit(
                    "You cannot submit this IEC material for review."
                );
            }
        } elseif (!$canApprove) {
            http_response_code(
                403
            );

            exit(
                "Only Supervisor or Admin can approve or supersede IEC materials."
            );
        }

        if (
            $newStatus
            ===
            "Approved"
        ) {
            $stmt =
                $conn->prepare(
                    "UPDATE iec_materials
                     SET
                        status = 'Approved',
                        approved_by = ?,
                        approved_at = NOW(),
                        updated_by = ?
                     WHERE id = ?"
                );

            $stmt->bind_param(
                "iii",
                $userId,
                $userId,
                $id
            );

            $stmt->execute();
            $stmt->close();

            iecLog(
                $conn,
                $id,
                $userId,
                "Approved",
                "IEC material approved for use."
            );

            iecFlash(
                "success",
                "IEC material approved."
            );
        }

        $stmt =
            $conn->prepare(
                "UPDATE iec_materials
                 SET
                    status = ?,
                    updated_by = ?
                 WHERE id = ?"
            );

        $stmt->bind_param(
            "sii",
            $newStatus,
            $userId,
            $id
        );

        $stmt->execute();
        $stmt->close();

        iecLog(
            $conn,
            $id,
            $userId,
            "Status Changed",
            "Status changed to "
            . $newStatus
            . "."
        );

        iecFlash(
            "success",
            "IEC status updated to "
            . $newStatus
            . "."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Archive / Restore
    |--------------------------------------------------------------------------
    */

    if (
        in_array(
            $action,
            [
                "archive",
                "restore"
            ],
            true
        )
    ) {
        if (!$canArchive) {
            http_response_code(
                403
            );

            exit(
                "Only Supervisor and Admin can archive or restore IEC materials."
            );
        }

        $id =
            (int) (
                $_POST["id"]
                ?? 0
            );

        $active =
            $action
            ===
            "restore"
            ? 1
            : 0;

        $stmt =
            $conn->prepare(
                "UPDATE iec_materials
                 SET
                    active = ?,
                    updated_by = ?
                 WHERE id = ?"
            );

        $stmt->bind_param(
            "iii",
            $active,
            $userId,
            $id
        );

        $stmt->execute();
        $stmt->close();

        iecLog(
            $conn,
            $id,
            $userId,
            $active
            ? "Restored"
            : "Archived",
            ""
        );

        iecFlash(
            "success",
            $active
            ? "IEC material restored."
            : "IEC material archived.",
            $active
            ? "?show=archived"
            : ""
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Delete
    |--------------------------------------------------------------------------
    */

    if (
        $action
        ===
        "delete"
    ) {
        if (!$canDelete) {
            http_response_code(
                403
            );

            exit(
                "Only an Administrator can permanently delete IEC materials."
            );
        }

        $id =
            (int) (
                $_POST["id"]
                ?? 0
            );

        $stmt =
            $conn->prepare(
                "SELECT
                    stored_filename
                 FROM iec_materials
                 WHERE id = ?
                 LIMIT 1"
            );

        $stmt->bind_param(
            "i",
            $id
        );

        $stmt->execute();

        $material =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$material) {
            iecFlash(
                "danger",
                "IEC material not found."
            );
        }

        $versionStmt =
            $conn->prepare(
                "SELECT stored_filename
                 FROM iec_material_versions
                 WHERE material_id = ?"
            );

        $versionStmt->bind_param(
            "i",
            $id
        );

        $versionStmt->execute();

        $versionFiles =
            $versionStmt
                ->get_result()
                ->fetch_all(
                    MYSQLI_ASSOC
                );

        $versionStmt->close();

        $conn->begin_transaction();

        try {
            $stmt =
                $conn->prepare(
                    "DELETE FROM iec_material_logs
                     WHERE material_id = ?"
                );

            $stmt->bind_param(
                "i",
                $id
            );

            $stmt->execute();
            $stmt->close();

            $stmt =
                $conn->prepare(
                    "DELETE FROM iec_material_versions
                     WHERE material_id = ?"
                );

            $stmt->bind_param(
                "i",
                $id
            );

            $stmt->execute();
            $stmt->close();

            $stmt =
                $conn->prepare(
                    "DELETE FROM iec_materials
                     WHERE id = ?"
                );

            $stmt->bind_param(
                "i",
                $id
            );

            $stmt->execute();
            $stmt->close();

            $conn->commit();

        } catch (
            Throwable $e
        ) {
            $conn->rollback();
            throw $e;
        }

        $allFiles = [
            [
                "stored_filename" =>
                    $material["stored_filename"]
            ],
            ...$versionFiles
        ];

        foreach (
            $allFiles
            as $file
        ) {
            $filePath =
                $storageDir
                . DIRECTORY_SEPARATOR
                . basename(
                    (string) 
                    $file["stored_filename"]
                );

            if (
                is_file(
                    $filePath
                )
            ) {
                @unlink(
                    $filePath
                );
            }
        }

        iecFlash(
            "success",
            "IEC material and all stored versions permanently deleted."
        );
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$q =
    trim(
        (string) (
            $_GET["q"]
            ?? ""
        )
    );

$categoryFilter =
    trim(
        (string) (
            $_GET["category"]
            ?? ""
        )
    );

$typeFilter =
    trim(
        (string) (
            $_GET["type"]
            ?? ""
        )
    );

$statusFilter =
    trim(
        (string) (
            $_GET["status"]
            ?? ""
        )
    );

$audienceFilter =
    trim(
        (string) (
            $_GET["audience"]
            ?? ""
        )
    );

$featuredOnly =
    (
        $_GET["featured"]
        ?? ""
    )
    ===
    "1";

$sort =
    trim(
        (string) (
            $_GET["sort"]
            ?? "newest"
        )
    );

$showArchived =
    $canArchive
    &&
    (
        (
            $_GET["show"]
            ?? ""
        )
        ===
        "archived"
    );

$allowedTypeFilters = [
    "",
    "PDF",
    "Document",
    "Presentation",
    "Spreadsheet",
    "Image",
    "Video"
];

if (
    !in_array(
        $typeFilter,
        $allowedTypeFilters,
        true
    )
) {
    $typeFilter = "";
}

$allowedSort = [
    "newest",
    "oldest",
    "downloads",
    "views",
    "title"
];

if (
    !in_array(
        $sort,
        $allowedSort,
        true
    )
) {
    $sort = "newest";
}

if (
    $statusFilter !== ""
    &&
    !in_array(
        $statusFilter,
        $statusOptions,
        true
    )
) {
    $statusFilter = "";
}

if (
    $audienceFilter !== ""
    &&
    !in_array(
        $audienceFilter,
        $audienceOptions,
        true
    )
) {
    $audienceFilter = "";
}

/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/

$categories = [];

$result =
    $conn->query(
        "SELECT DISTINCT category
         FROM iec_materials
         WHERE
            category IS NOT NULL
            AND category <> ''
         ORDER BY category"
    );

while (
    $row =
    $result->fetch_assoc()
) {
    $categories[] =
        $row["category"];
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$perPage =
    (int) (
        $_GET["per_page"]
        ?? 24
    );

if (
    !in_array(
        $perPage,
        [
            12,
            24,
            48
        ],
        true
    )
) {
    $perPage = 24;
}

$page =
    max(
        1,
        (int) (
            $_GET["page"]
            ?? 1
        )
    );

$offset =
    ($page - 1)
    * $perPage;

/*
|--------------------------------------------------------------------------
| Query Builder
|--------------------------------------------------------------------------
*/

$where = [
    "m.active = ?"
];

$activeValue =
    $showArchived
    ? 0
    : 1;

$types = "i";

$params = [
    $activeValue
];

if (
    !$isHpuRole
    &&
    !$showArchived
) {
    /*
     * Normal authenticated users see approved materials only.
     */
    $where[] =
        "m.status = 'Approved'";
}

if ($q !== "") {
    $where[] = "(
        m.title LIKE ?
        OR m.category LIKE ?
        OR m.health_topic LIKE ?
        OR m.description LIKE ?
        OR m.original_filename LIKE ?
        OR u.name LIKE ?
    )";

    $like =
        "%"
        . $q
        . "%";

    $types .=
        "ssssss";

    array_push(
        $params,
        $like,
        $like,
        $like,
        $like,
        $like,
        $like
    );
}

if (
    $categoryFilter
    !==
    ""
) {
    $where[] =
        "m.category = ?";

    $types .= "s";
    $params[] =
        $categoryFilter;
}

if (
    $statusFilter
    !==
    ""
) {
    $where[] =
        "m.status = ?";

    $types .= "s";
    $params[] =
        $statusFilter;
}

if ($featuredOnly) {
    $where[] =
        "m.featured = 1";
}

if (
    $audienceFilter
    !==
    ""
) {
    $where[] =
        "m.target_audience LIKE ?";

    $types .= "s";
    $params[] =
        '%"'
        . $audienceFilter
        . '"%';
}

if (
    $typeFilter
    !==
    ""
) {
    $extensions =
        match (
        $typeFilter
        ) {
            "PDF" =>
            ["pdf"],

            "Document" =>
            [
                "doc",
                "docx"
            ],

            "Presentation" =>
            [
                "ppt",
                "pptx"
            ],

            "Spreadsheet" =>
            [
                "xls",
                "xlsx",
                "csv"
            ],

            "Image" =>
            [
                "jpg",
                "jpeg",
                "png",
                "webp"
            ],

            "Video" =>
            [
                "mp4",
                "mov",
                "webm"
            ],

            default =>
            []
        };

    if ($extensions) {
        $placeholders =
            implode(
                ",",
                array_fill(
                    0,
                    count(
                        $extensions
                    ),
                    "?"
                )
            );

        $where[] =
            "m.file_extension
             IN ({$placeholders})";

        $types .=
            str_repeat(
                "s",
                count(
                    $extensions
                )
            );

        foreach (
            $extensions
            as $extension
        ) {
            $params[] =
                $extension;
        }
    }
}

$orderSql =
    match (
    $sort
    ) {
        "oldest" =>
        "m.uploaded_at ASC, m.id ASC",

        "downloads" =>
        "m.download_count DESC, m.uploaded_at DESC",

        "views" =>
        "m.view_count DESC, m.uploaded_at DESC",

        "title" =>
        "m.title ASC, m.id DESC",

        default =>
        "m.featured DESC, m.uploaded_at DESC, m.id DESC"
    };

$fromSql = "
    FROM iec_materials m
    LEFT JOIN users u
        ON u.id = m.uploaded_by
    LEFT JOIN users approver
        ON approver.id = m.approved_by
    LEFT JOIN users updater
        ON updater.id = m.updated_by
    WHERE
        "
    . implode(
        " AND ",
        $where
    );

/*
|--------------------------------------------------------------------------
| Count
|--------------------------------------------------------------------------
*/

$countStmt =
    $conn->prepare(
        "SELECT COUNT(*) AS total "
        . $fromSql
    );

$countStmt->bind_param(
    $types,
    ...$params
);

$countStmt->execute();

$totalMaterials =
    (int) (
        $countStmt
            ->get_result()
            ->fetch_assoc()["total"]
        ?? 0
    );

$countStmt->close();

$totalPages =
    max(
        1,
        (int) 
        ceil(
            $totalMaterials
            /
            $perPage
        )
    );

if (
    $page
    >
    $totalPages
) {
    $page =
        $totalPages;

    $offset =
        ($page - 1)
        * $perPage;
}

/*
|--------------------------------------------------------------------------
| Materials
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        m.*,
        u.name AS uploader_name,
        approver.name AS approver_name,
        updater.name AS updater_name
    "
    . $fromSql
    . "
    ORDER BY
        {$orderSql}
    LIMIT ?
    OFFSET ?
";

$queryTypes =
    $types
    . "ii";

$queryParams =
    array_merge(
        $params,
        [
            $perPage,
            $offset
        ]
    );

$stmt =
    $conn->prepare(
        $sql
    );

$stmt->bind_param(
    $queryTypes,
    ...$queryParams
);

$stmt->execute();

$materials =
    $stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

$stmt->close();

/*
|--------------------------------------------------------------------------
| Stats
|--------------------------------------------------------------------------
*/

$visibilityCondition =
    $isHpuRole
    ? ""
    : "AND status = 'Approved'";

$stats =
    $conn->query(
        "SELECT
            COUNT(*) AS total,
            COALESCE(
                SUM(
                    status = 'Approved'
                ),
                0
            ) AS approved,
            COALESCE(
                SUM(
                    status = 'For Review'
                ),
                0
            ) AS for_review,
            COALESCE(
                SUM(
                    featured = 1
                ),
                0
            ) AS featured,
            COALESCE(
                SUM(download_count),
                0
            ) AS downloads,
            COALESCE(
                SUM(view_count),
                0
            ) AS views,
            COALESCE(
                SUM(file_size),
                0
            ) AS storage_bytes
         FROM iec_materials
         WHERE active = 1
         {$visibilityCondition}"
    )->fetch_assoc() ?: [];

$uploadsThisMonth =
    (int) (
        $conn
            ->query(
                "SELECT COUNT(*) AS total
                 FROM iec_materials
                 WHERE
                    active = 1
                    {$visibilityCondition}
                    AND YEAR(uploaded_at)
                        = YEAR(CURDATE())
                    AND MONTH(uploaded_at)
                        = MONTH(CURDATE())"
            )
            ->fetch_assoc()["total"]
        ?? 0
    );

$flash =
    $_SESSION["iec_flash"]
    ?? null;

unset(
    $_SESSION["iec_flash"]
);

/*
|--------------------------------------------------------------------------
| Preserve filters for pagination
|--------------------------------------------------------------------------
*/

$paginationParams =
    $_GET;

unset(
    $paginationParams["page"]
);

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        IEC Materials | HPU Workbench
    </title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">

    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">

    <style>
        :root {
            --iec-bg: #f4f7fb;
            --iec-border: #dfe6ef;
            --iec-primary: #1769e0;
            --iec-text: #172033;
            --iec-muted: #667085;
            --iec-shadow: 0 8px 24px rgba(28, 42, 72, .05);
        }

        body {
            background: var(--iec-bg);
            color: var(--iec-text);
        }

        .content {
            padding: 18px;
        }

        .container-fluid {
            max-width: 1720px;
            margin: 0 auto;
        }

        .access-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 9px;
            border-radius: 999px;
            background: #eff8ff;
            color: #175cd3;
            font-size: .62rem;
            font-weight: 850;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 9px;
            margin-bottom: 12px;
        }

        .kpi-card {
            padding: 11px 12px;
            border: 1px solid var(--iec-border);
            border-radius: 13px;
            background: #fff;
            box-shadow: var(--iec-shadow);
        }

        .kpi-label {
            color: #667085;
            font-size: .56rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .kpi-value {
            margin-top: 4px;
            font-size: 1.4rem;
            line-height: 1;
            font-weight: 900;
        }

        .kpi-note {
            margin-top: 5px;
            color: #98a2b3;
            font-size: .58rem;
        }

        .toolbar {
            padding: 10px 11px;
            margin-bottom: 12px;
            border: 1px solid var(--iec-border);
            border-radius: 13px;
            background: #fff;
            box-shadow: var(--iec-shadow);
        }

        .filter-label {
            display: block;
            margin-bottom: 3px;
            color: #667085;
            font-size: .55rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .quick-filters {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .quick-filter {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 9px;
            border: 1px solid #dfe6ef;
            border-radius: 999px;
            background: #fff;
            color: #475467;
            text-decoration: none;
            font-size: .58rem;
            font-weight: 850;
        }

        .quick-filter:hover,
        .quick-filter.active {
            border-color: #93c5fd;
            background: #eff6ff;
            color: #1d4ed8;
        }

        .materials {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .material-card {
            position: relative;
            min-height: 360px;
            display: flex;
            flex-direction: column;
            border: 1px solid var(--iec-border);
            border-radius: 14px;
            background: #fff;
            box-shadow: var(--iec-shadow);
            overflow: hidden;
        }

        .material-card.archived {
            opacity: .78;
        }

        .material-preview {
            position: relative;
            min-height: 145px;
            display: grid;
            place-items: center;
            overflow: hidden;
            background: #f8fafc;
            border-bottom: 1px solid #edf1f5;
        }

        .material-preview img {
            width: 100%;
            height: 190px;
            object-fit: cover;
            display: block;
        }

        .material-preview-icon {
            width: 58px;
            height: 58px;
            display: grid;
            place-items: center;
            border-radius: 14px;
            font-size: 1.55rem;
        }

        .featured-flag {
            position: absolute;
            top: 9px;
            left: 9px;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 7px;
            border-radius: 999px;
            background: #fff7ed;
            color: #b54708;
            font-size: .53rem;
            font-weight: 900;
            box-shadow: 0 2px 8px rgba(15, 23, 42, .08);
        }

        .card-body-main {
            padding: 13px 14px 14px;
            display: flex;
            flex: 1;
            flex-direction: column;
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: flex-start;
        }

        .file-icon {
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            flex: 0 0 38px;
            border-radius: 10px;
            font-size: 1rem;
        }

        .file-pdf {
            background: #fef3f2;
            color: #b42318;
        }

        .file-doc {
            background: #eff8ff;
            color: #175cd3;
        }

        .file-ppt {
            background: #fff7ed;
            color: #c2410c;
        }

        .file-sheet {
            background: #ecfdf3;
            color: #027a48;
        }

        .file-image {
            background: #f5f3ff;
            color: #6d28d9;
        }

        .file-video {
            background: #fdf2fa;
            color: #c11574;
        }

        .file-other {
            background: #f2f4f7;
            color: #475467;
        }

        .status-pill,
        .category-pill,
        .file-pill,
        .audience-pill,
        .version-pill {
            display: inline-flex;
            align-items: center;
            padding: 3px 6px;
            border-radius: 999px;
            font-size: .51rem;
            font-weight: 850;
        }

        .status-approved {
            background: #ecfdf3;
            color: #027a48;
        }

        .status-review {
            background: #fff7ed;
            color: #b54708;
        }

        .status-draft {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .status-superseded {
            background: #f2f4f7;
            color: #667085;
        }

        .category-pill {
            background: #ecfeff;
            color: #0e7490;
        }

        .file-pill {
            background: #f8fafc;
            color: #475467;
        }

        .version-pill {
            background: #f5f3ff;
            color: #6d28d9;
        }

        .audience-pill {
            background: #f8fafc;
            color: #475467;
        }

        .material-title {
            margin-top: 10px;
            color: #1d2939;
            font-size: .91rem;
            font-weight: 900;
        }

        .material-subtitle {
            margin-top: 3px;
            color: #667085;
            font-size: .61rem;
            font-weight: 750;
        }

        .material-description {
            margin-top: 6px;
            color: #667085;
            font-size: .63rem;
            line-height: 1.45;
        }

        .audience-list {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            margin-top: 7px;
        }

        .meta {
            margin-top: 9px;
            color: #667085;
            font-size: .58rem;
            line-height: 1.65;
        }

        .meta div {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .validity-note {
            margin-top: 7px;
            padding: 7px 8px;
            border-radius: 8px;
            font-size: .57rem;
            line-height: 1.4;
        }

        .validity-ok {
            background: #ecfdf3;
            color: #027a48;
        }

        .validity-review {
            background: #fff7ed;
            color: #b54708;
        }

        .validity-expired {
            background: #fef3f2;
            color: #b42318;
        }

        .card-actions {
            margin-top: auto;
            padding-top: 11px;
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        .card-actions .btn {
            font-size: .58rem;
            font-weight: 800;
        }

        .empty {
            grid-column: 1/-1;
            padding: 50px 20px;
            text-align: center;
            border: 1px dashed #cfd9e7;
            border-radius: 14px;
            background: #fff;
            color: #667085;
        }

        .pagination-wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 14px;
            padding: 10px 12px;
            border: 1px solid #dfe6ef;
            border-radius: 12px;
            background: #fff;
        }

        .audience-check-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .audience-check {
            display: flex;
            gap: 7px;
            align-items: flex-start;
            padding: 8px 9px;
            border: 1px solid #dfe6ef;
            border-radius: 9px;
            background: #f8fafc;
            font-size: .68rem;
            cursor: pointer;
        }

        .modal-content {
            border-radius: 15px;
        }

        @media(max-width:1150px) {
            .materials {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media(max-width:800px) {
            .kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media(max-width:650px) {

            .materials,
            .audience-check-grid {
                grid-template-columns: 1fr;
            }

            .content {
                padding: 12px;
            }

            .pagination-wrap {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        /* ============================================================
           IEC LIBRARY — MODERN UI/UX REFRESH (blocked_dates style)
           Override layer: keeps PHP/HTML structure intact.
           ============================================================ */

        .ph {
            position: relative;
            overflow: hidden;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        }

        .ph::after {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 3px;
            background: linear-gradient(90deg, #2563eb, #7c3aed, #0ea5e9);
            opacity: .9;
        }

        .count-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: .61rem;
            font-weight: 850;
        }

        /* KPI with icon + accent bar */
        .kpi-card {
            position: relative;
            display: flex;
            gap: 11px;
            align-items: flex-start;
            overflow: hidden;
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        }

        .kpi-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 3px;
            background: #e2e8f0;
        }

        .kpi-card:nth-child(1)::before { background: linear-gradient(90deg, #2563eb, #60a5fa); }
        .kpi-card:nth-child(2)::before { background: linear-gradient(90deg, #059669, #34d399); }
        .kpi-card:nth-child(3)::before { background: linear-gradient(90deg, #d97706, #fbbf24); }
        .kpi-card:nth-child(4)::before { background: linear-gradient(90deg, #7c3aed, #a78bfa); }

        .kpi-card:hover {
            transform: translateY(-2px);
            border-color: #b7c9e4;
            box-shadow: 0 12px 28px rgba(15, 23, 42, .10);
        }

        .kpi-icon {
            width: 36px;
            height: 36px;
            flex: 0 0 36px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            font-size: .95rem;
        }

        .kpi-card:nth-child(1) .kpi-icon { background: #eff6ff; color: #1d4ed8; }
        .kpi-card:nth-child(2) .kpi-icon { background: #ecfdf5; color: #047857; }
        .kpi-card:nth-child(3) .kpi-icon { background: #fffbeb; color: #b45309; }
        .kpi-card:nth-child(4) .kpi-icon { background: #f5f3ff; color: #6d28d9; }

        .kpi-body { min-width: 0; flex: 1; }
        .kpi-value { font-variant-numeric: tabular-nums; }

        /* Toolbar: sticky + focus */
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 4;
            background: #fbfcfe;
        }

        .toolbar .form-control:focus,
        .toolbar .form-select:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
        }

        .quick-filter { transition: all .13s ease; }
        .quick-filter:hover { transform: translateY(-1px); }

        /* Cards */
        .material-card {
            transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease;
        }

        .material-card:hover {
            transform: translateY(-3px);
            border-color: #b7c9e4;
            box-shadow: 0 16px 34px rgba(15, 23, 42, .11);
        }

        .material-preview img {
            transition: transform .3s ease;
        }

        .material-card:hover .material-preview img {
            transform: scale(1.04);
        }

        .download-badge {
            position: absolute;
            top: 9px;
            right: 9px;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 999px;
            background: rgba(15, 23, 42, .72);
            backdrop-filter: blur(4px);
            color: #fff;
            font-size: .53rem;
            font-weight: 850;
            font-variant-numeric: tabular-nums;
        }

        .featured-flag { left: 9px; }
        .material-preview:has(.download-badge):has(.featured-flag) .featured-flag {
            top: 9px;
        }

        .material-title {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            letter-spacing: -.01em;
        }

        .card-actions .btn {
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .06);
        }

        /* Empty state */
        .empty {
            background: radial-gradient(420px 140px at 50% 0%, #eff6ff 0%, transparent 70%), #fff;
        }

        .empty .btn { border-radius: 9px; }

        /* Results + pagination */
        .results-line {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .pagination .page-link {
            border-radius: 8px;
            font-weight: 700;
        }

        .pagination .page-item.active .page-link {
            background: linear-gradient(180deg, #2f6fed, #1d4ed8);
            border-color: #1d4ed8;
        }

        /* Back to top */
        #toTopBtn {
            position: fixed;
            right: 18px;
            bottom: 18px;
            z-index: 50;
            width: 40px;
            height: 40px;
            display: none;
            place-items: center;
            border: 1px solid #dbe3ef;
            border-radius: 12px;
            background: #fff;
            color: #1d4ed8;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .14);
        }

        #toTopBtn.show { display: grid; }

        @media(max-width: 800px) {
            .toolbar { top: 0; }
        }

        @media print {
            .ph-actions, .toolbar, .quick-filters, .card-actions,
            .pagination-wrap, #toTopBtn { display: none !important; }
            .material-card { break-inside: avoid; }
        }
    </style>

</head>

<body class="app-shell">

    <div class="d-flex min-vh-100">

        <?php
        include __DIR__ . "/includes/sidebar.php";
        ?>

        <div class="main flex-grow-1">

            <main class="content">

                <div class="container-fluid">

                    <?php if ($flash): ?>

                        <div class="alert alert-<?= e($flash["type"]) ?> alert-dismissible fade show">
                            <?= e($flash["message"]) ?>

                            <button class="btn-close" type="button" data-bs-dismiss="alert"></button>
                        </div>

                    <?php endif; ?>

                    <div class="ph">

                        <div class="ph-copy">

                            <div class="ph-icon" aria-hidden="true">
                                <i class="bi bi-folder2-open"></i>
                            </div>

                            <div class="min-w-0">
                                <p class="ph-eyebrow">
                                    HPU Workbench
                                </p>

                                <h1 class="ph-title">
                                    IEC Materials Library
                                </h1>

                                <p class="ph-subtitle">
                                    Controlled Information, Education and Communication materials for approved hospital
                                    use.
                                </p>
                            </div>

                        </div>

                        <div class="ph-actions">

                            <span class="access-chip">
                                <i class="bi bi-shield-check"></i>
                                <?= $isHpuRole ? "HPU management access" : "Approved materials only" ?>
                            </span>

                            <span class="count-pill" title="Materials matching current filters">
                                <i class="bi bi-collection"></i>
                                <?= number_format($totalMaterials) ?> found
                            </span>

                            <?php if ($isHpuRole): ?>

                                <a class="btn btn-sm btn-outline-primary"
                                    href="<?= e(BASE_URL) ?>/reports/evaluation_analytics.php"
                                    title="Open service evaluation analytics">
                                    <i class="bi bi-bar-chart-line me-1"></i>
                                    Analytics
                                </a>

                            <?php endif; ?>

                            <?php if ($canUpload): ?>

                                <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal"
                                    data-bs-target="#uploadModal">
                                    <i class="bi bi-cloud-arrow-up me-1"></i>
                                    Upload Material
                                </button>

                            <?php endif; ?>

                        </div>

                    </div>

                    <div class="kpi-grid">

                        <div class="kpi-card">
                            <div class="kpi-icon" aria-hidden="true"><i class="bi bi-folder2-open"></i></div>
                            <div class="kpi-body">
                            <div class="kpi-label">
                                Available Materials
                            </div>

                            <div class="kpi-value">
                                <?= (int) ($stats["total"] ?? 0) ?>
                            </div>

                            <div class="kpi-note">
                                Active IEC files
                            </div>
                            </div>
                        </div>

                        <div class="kpi-card">
                            <div class="kpi-icon" aria-hidden="true"><i class="bi bi-patch-check"></i></div>
                            <div class="kpi-body">
                            <div class="kpi-label">
                                Approved
                            </div>

                            <div class="kpi-value text-success">
                                <?= (int) ($stats["approved"] ?? 0) ?>
                            </div>

                            <div class="kpi-note">
                                Approved for use
                            </div>
                            </div>
                        </div>

                        <div class="kpi-card">
                            <div class="kpi-icon" aria-hidden="true"><i class="bi bi-hourglass-split"></i></div>
                            <div class="kpi-body">
                            <div class="kpi-label">
                                For Review
                            </div>

                            <div class="kpi-value text-warning">
                                <?= (int) ($stats["for_review"] ?? 0) ?>
                            </div>

                            <div class="kpi-note">
                                Awaiting review
                            </div>
                            </div>
                        </div>

                        <div class="kpi-card">
                            <div class="kpi-icon" aria-hidden="true"><i class="bi bi-cloud-arrow-up"></i></div>
                            <div class="kpi-body">
                            <div class="kpi-label">
                                Added This Month
                            </div>

                            <div class="kpi-value text-primary">
                                <?= $uploadsThisMonth ?>
                            </div>

                            <div class="kpi-note">
                                <?= iecFileSize((int) ($stats["storage_bytes"] ?? 0)) ?> stored
                            </div>
                            </div>
                        </div>

                    </div>

                    <div class="quick-filters">

                        <a class="quick-filter <?= !$featuredOnly && $statusFilter === "" ? "active" : "" ?>"
                            href="<?= e(BASE_URL) ?>/iec_materials.php">
                            <i class="bi bi-grid"></i>
                            All
                        </a>

                        <a class="quick-filter <?= $featuredOnly ? "active" : "" ?>"
                            href="<?= e(BASE_URL) ?>/iec_materials.php?featured=1">
                            <i class="bi bi-star-fill"></i>
                            Featured
                        </a>

                        <?php if ($isHpuRole): ?>

                            <a class="quick-filter <?= $statusFilter === "For Review" ? "active" : "" ?>"
                                href="<?= e(BASE_URL) ?>/iec_materials.php?status=For+Review">
                                <i class="bi bi-hourglass-split"></i>
                                For Review
                            </a>

                        <?php endif; ?>

                        <a class="quick-filter <?= $statusFilter === "Approved" ? "active" : "" ?>"
                            href="<?= e(BASE_URL) ?>/iec_materials.php?status=Approved">
                            <i class="bi bi-patch-check"></i>
                            Approved
                        </a>

                    </div>

                    <form class="toolbar" method="get">

                        <div class="row g-2 align-items-end">

                            <div class="col-xl-3 col-lg-4">
                                <label class="filter-label">
                                    Search
                                </label>

                                <input class="form-control form-control-sm" name="q" id="iecSearchInput" value="<?= e($q) ?>"
                                    placeholder="Title, topic, description, filename...  ( / )" autocomplete="off">
                            </div>

                            <div class="col-xl-2 col-lg-2">
                                <label class="filter-label">
                                    Category
                                </label>

                                <select class="form-select form-select-sm" name="category">
                                    <option value="">
                                        All Categories
                                    </option>

                                    <?php foreach ($categories as $category): ?>

                                        <option value="<?= e($category) ?>" <?= $categoryFilter === $category ? "selected" : "" ?>>
                                            <?= e($category) ?>
                                        </option>

                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-xl-2 col-lg-2">
                                <label class="filter-label">
                                    File Type
                                </label>

                                <select class="form-select form-select-sm" name="type">
                                    <option value="">
                                        All Types
                                    </option>

                                    <?php foreach ($allowedTypeFilters as $type): ?>

                                        <?php if ($type === "")
                                            continue; ?>

                                        <option value="<?= e($type) ?>" <?= $typeFilter === $type ? "selected" : "" ?>>
                                            <?= e($type) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>
                            </div>

                            <div class="col-xl-2 col-lg-2">
                                <label class="filter-label">
                                    Audience
                                </label>

                                <select class="form-select form-select-sm" name="audience">
                                    <option value="">
                                        All Audiences
                                    </option>

                                    <?php foreach ($audienceOptions as $audience): ?>

                                        <option value="<?= e($audience) ?>" <?= $audienceFilter === $audience ? "selected" : "" ?>>
                                            <?= e($audience) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>
                            </div>

                            <?php if ($isHpuRole): ?>

                                <div class="col-xl-1 col-lg-2">
                                    <label class="filter-label">
                                        Status
                                    </label>

                                    <select class="form-select form-select-sm" name="status">
                                        <option value="">
                                            All
                                        </option>

                                        <?php foreach ($statusOptions as $status): ?>

                                            <option value="<?= e($status) ?>" <?= $statusFilter === $status ? "selected" : "" ?>>
                                                <?= e($status) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>
                                </div>

                            <?php endif; ?>

                            <div class="col-xl-1 col-lg-2">
                                <label class="filter-label">
                                    Sort
                                </label>

                                <select class="form-select form-select-sm" name="sort">
                                    <option value="newest" <?= $sort === "newest" ? "selected" : "" ?>>
                                        Newest
                                    </option>

                                    <option value="downloads" <?= $sort === "downloads" ? "selected" : "" ?>>
                                        Downloads
                                    </option>

                                    <option value="views" <?= $sort === "views" ? "selected" : "" ?>>
                                        Most viewed
                                    </option>

                                    <option value="title" <?= $sort === "title" ? "selected" : "" ?>>
                                        A–Z
                                    </option>

                                    <option value="oldest" <?= $sort === "oldest" ? "selected" : "" ?>>
                                        Oldest
                                    </option>
                                </select>
                            </div>

                            <div class="col-xl-auto d-flex gap-2 flex-wrap">

                                <button class="btn btn-sm btn-primary" type="submit">
                                    <i class="bi bi-funnel me-1"></i>
                                    Apply
                                </button>

                                <a class="btn btn-sm btn-light" href="<?= e(BASE_URL) ?>/iec_materials.php">
                                    Reset
                                </a>

                                <?php if ($canArchive): ?>

                                    <?php if ($showArchived): ?>

                                        <a class="btn btn-sm btn-outline-success" href="<?= e(BASE_URL) ?>/iec_materials.php">
                                            Active
                                        </a>

                                    <?php else: ?>

                                        <a class="btn btn-sm btn-outline-secondary"
                                            href="<?= e(BASE_URL) ?>/iec_materials.php?show=archived">
                                            Archived
                                        </a>

                                    <?php endif; ?>

                                <?php endif; ?>

                            </div>

                        </div>

                    </form>

                    <div class="d-flex justify-content-between align-items-center mb-2">

                        <div class="small text-muted results-line">
                            <span>
                                <?php if ($totalMaterials > 0): ?>
                                    Showing <?= number_format(min($totalMaterials, $offset + 1)) ?>–<?= number_format(min($totalMaterials, $offset + count($materials))) ?>
                                    of <?= number_format($totalMaterials) ?> material(s)
                                <?php else: ?>
                                    0 material(s) found
                                <?php endif; ?>
                            </span>
                        </div>

                        <?php if ($showArchived): ?>

                            <span class="badge text-bg-secondary">
                                Archived View
                            </span>

                        <?php endif; ?>

                    </div>

                    <div class="materials">

                        <?php if (!$materials): ?>

                            <div class="empty">

                                <i class="bi bi-folder-x fs-1 d-block mb-2"></i>

                                <h5 class="fw-bold text-dark">
                                    No IEC materials found
                                </h5>

                                <div>
                                    No materials match the selected filters.
                                </div>

                                <div class="mt-3">
                                    <a class="btn btn-sm btn-primary" href="<?= e(BASE_URL) ?>/iec_materials.php">
                                        <i class="bi bi-x-circle me-1"></i>
                                        Clear filters
                                    </a>
                                </div>

                            </div>

                        <?php endif; ?>

                        <?php foreach ($materials as $material): ?>

                            <?php
                            $audiences =
                                iecDecodeAudience(
                                    $material["target_audience"]
                                    ?? null
                                );

                            $canEditThis =
                                iecCanEditMaterial(
                                    $material,
                                    $userId,
                                    $isManager
                                );

                            $validityClass =
                                "validity-ok";

                            $validityText =
                                "";

                            if (
                                !empty(
                                $material["valid_until"]
                            )
                            ) {
                                if (
                                    $material["valid_until"]
                                    <
                                    date("Y-m-d")
                                ) {
                                    $validityClass =
                                        "validity-expired";

                                    $validityText =
                                        "Validity expired on "
                                        . date(
                                            "M d, Y",
                                            strtotime(
                                                $material["valid_until"]
                                            )
                                        )
                                        . ". Review required.";
                                } else {
                                    $validityText =
                                        "Valid until "
                                        . date(
                                            "M d, Y",
                                            strtotime(
                                                $material["valid_until"]
                                            )
                                        )
                                        . ".";
                                }
                            } elseif (
                                !empty(
                                $material["review_date"]
                            )
                            ) {
                                if (
                                    $material["review_date"]
                                    <=
                                    date("Y-m-d")
                                ) {
                                    $validityClass =
                                        "validity-review";

                                    $validityText =
                                        "Review date reached. Please verify this material.";
                                } else {
                                    $validityText =
                                        "Review on "
                                        . date(
                                            "M d, Y",
                                            strtotime(
                                                $material["review_date"]
                                            )
                                        )
                                        . ".";
                                }
                            }
                            ?>

                            <article class="material-card <?= (int) $material["active"] === 0 ? "archived" : "" ?>">

                                <div class="material-preview">

                                    <span class="download-badge" title="<?= number_format((int) ($material["view_count"] ?? 0)) ?> view(s) · <?= number_format((int) $material["download_count"]) ?> download(s)">
                                        <i class="bi bi-eye"></i>
                                        <?= number_format((int) ($material["view_count"] ?? 0)) ?>
                                        ·
                                        <i class="bi bi-download"></i>
                                        <?= number_format((int) $material["download_count"]) ?>
                                    </span>

                                    <?php if ((int) $material["featured"] === 1): ?>

                                        <div class="featured-flag">
                                            <i class="bi bi-star-fill"></i>
                                            Featured
                                        </div>

                                    <?php endif; ?>

                                    <?php if (
                                        (int) $material["active"] === 1
                                        &&
                                        iecImageExtension(
                                            (string) 
                                            $material["file_extension"]
                                        )
                                    ): ?>

                                        <img src="<?= e(BASE_URL) ?>/iec_materials.php?file=<?= (int) $material["id"] ?>&mode=view"
                                            alt="<?= e($material["title"]) ?>" loading="lazy"
                                            data-fallback="<?= e(iecFileTypeClass((string) $material["file_extension"])) ?>"
                                            data-icon="<?= e(iecFileIcon((string) $material["file_extension"])) ?>"
                                            onerror="var p=this.closest('.material-preview');var d=document.createElement('div');d.className='material-preview-icon '+this.dataset.fallback;var i=document.createElement('i');i.className='bi '+this.dataset.icon;d.appendChild(i);this.replaceWith(d);">

                                    <?php else: ?>

                                        <div
                                            class="material-preview-icon <?= e(iecFileTypeClass((string) $material["file_extension"])) ?>">
                                            <i class="bi <?= e(iecFileIcon((string) $material["file_extension"])) ?>"></i>
                                        </div>

                                    <?php endif; ?>

                                </div>

                                <div class="card-body-main">

                                    <div class="card-top">

                                        <div class="d-flex gap-2 flex-wrap">
                                            <span
                                                class="status-pill <?= e(iecStatusClass((string) $material["status"])) ?>">
                                                <?= e($material["status"]) ?>
                                            </span>

                                            <span class="version-pill">
                                                v<?= e($material["version_number"] ?: "1.0") ?>
                                            </span>
                                        </div>

                                        <div class="text-end">

                                            <span class="category-pill">
                                                <?= e($material["category"]) ?>
                                            </span>

                                        </div>

                                    </div>

                                    <div class="material-title" title="<?= e($material["title"]) ?>">
                                        <?= e($material["title"]) ?>
                                    </div>

                                    <div class="material-subtitle">
                                        <?= e($material["material_type"] ?: "IEC Material") ?>

                                        <?php if (!empty($material["health_topic"])): ?>
                                            • <?= e($material["health_topic"]) ?>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($material["description"]): ?>

                                        <div class="material-description">
                                            <?= nl2br(
                                                e(
                                                    mb_strimwidth(
                                                        (string) 
                                                        $material["description"],
                                                        0,
                                                        210,
                                                        "…"
                                                    )
                                                )
                                            ) ?>
                                        </div>

                                    <?php endif; ?>

                                    <?php if ($audiences): ?>

                                        <div class="audience-list">

                                            <?php foreach (
                                                array_slice(
                                                    $audiences,
                                                    0,
                                                    4
                                                )
                                                as $audience
                                            ): ?>

                                                <span class="audience-pill">
                                                    <?= e($audience) ?>
                                                </span>

                                            <?php endforeach; ?>

                                            <?php if (
                                                count($audiences)
                                                >
                                                4
                                            ): ?>

                                                <span class="audience-pill">
                                                    +<?= count($audiences) - 4 ?>
                                                </span>

                                            <?php endif; ?>

                                        </div>

                                    <?php endif; ?>

                                    <?php if ($validityText !== ""): ?>

                                        <div class="validity-note <?= e($validityClass) ?>">
                                            <i class="bi bi-calendar-check me-1"></i>
                                            <?= e($validityText) ?>
                                        </div>

                                    <?php endif; ?>

                                    <div class="meta">

                                        <div>
                                            <i class="bi bi-file-earmark me-1"></i>
                                            <?= e($material["original_filename"]) ?>
                                        </div>

                                        <div>
                                            <i class="bi bi-hdd me-1"></i>
                                            <?= e(
                                                iecFileSize(
                                                    (int) 
                                                    $material["file_size"]
                                                )
                                            ) ?>
                                            •
                                            <?= e(
                                                iecFileTypeLabel(
                                                    (string) 
                                                    $material["file_extension"]
                                                )
                                            ) ?>
                                        </div>

                                        <div>
                                            <i class="bi bi-person me-1"></i>
                                            Uploaded by
                                            <?= e(
                                                $material["uploader_name"]
                                                ?: "Unknown User"
                                            ) ?>
                                        </div>

                                        <?php if (
                                            $material["status"]
                                            ===
                                            "Approved"
                                        ): ?>

                                            <div>
                                                <i class="bi bi-patch-check me-1"></i>
                                                Approved by
                                                <?= e(
                                                    $material["approver_name"]
                                                    ?: "Supervisor / Admin"
                                                ) ?>
                                            </div>

                                        <?php endif; ?>

                                        <div>
                                            <i class="bi bi-eye me-1"></i>
                                            <?= number_format((int) ($material["view_count"] ?? 0)) ?>
                                            view(s)
                                            •
                                            <i class="bi bi-download me-1"></i>
                                            <?= number_format((int) $material["download_count"]) ?>
                                            download(s)
                                        </div>

                                    </div>

                                    <div class="card-actions">

                                        <?php if (
                                            (int) $material["active"] === 1
                                            &&
                                            iecInlineAllowed(
                                                (string) 
                                                $material["file_extension"]
                                            )
                                        ): ?>

                                            <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"
                                                href="<?= e(BASE_URL) ?>/iec_materials.php?file=<?= (int) $material["id"] ?>&mode=view&count=1">
                                                <i class="bi bi-eye me-1"></i>
                                                View
                                            </a>

                                        <?php endif; ?>

                                        <?php if (
                                            (int) $material["active"] === 1
                                        ): ?>

                                            <a class="btn btn-sm btn-primary"
                                                href="<?= e(BASE_URL) ?>/iec_materials.php?file=<?= (int) $material["id"] ?>&mode=download">
                                                <i class="bi bi-download me-1"></i>
                                                Download
                                            </a>

                                        <?php endif; ?>

                                        <?php if ($canEditThis): ?>

                                            <button class="btn btn-sm btn-outline-secondary edit-material-btn" type="button"
                                                data-material='<?= e(json_encode([
                                                    "id" => (int) $material["id"],
                                                    "title" => $material["title"],
                                                    "category" => $material["category"],
                                                    "material_type" => $material["material_type"],
                                                    "health_topic" => $material["health_topic"],
                                                    "target_audience" => $audiences,
                                                    "description" => $material["description"],
                                                    "version_number" => $material["version_number"],
                                                    "version_notes" => $material["version_notes"],
                                                    "review_date" => $material["review_date"],
                                                    "valid_until" => $material["valid_until"],
                                                    "featured" => (int) $material["featured"],
                                                    "filename" => $material["original_filename"]
                                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
                                                <i class="bi bi-pencil me-1"></i>
                                                Edit
                                            </button>

                                        <?php endif; ?>

                                        <?php if (
                                            $isHpuRole
                                            &&
                                            $canEditThis
                                            &&
                                            $material["status"]
                                            ===
                                            "Draft"
                                        ): ?>

                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="action" value="set_status">
                                                <input type="hidden" name="id" value="<?= (int) $material["id"] ?>">
                                                <input type="hidden" name="status" value="For Review">

                                                <button class="btn btn-sm btn-outline-warning" type="submit">
                                                    <i class="bi bi-send-check me-1"></i>
                                                    Submit for Review
                                                </button>
                                            </form>

                                        <?php endif; ?>

                                        <?php if (
                                            $canApprove
                                            &&
                                            $material["status"]
                                            ===
                                            "For Review"
                                        ): ?>

                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="action" value="set_status">
                                                <input type="hidden" name="id" value="<?= (int) $material["id"] ?>">
                                                <input type="hidden" name="status" value="Approved">

                                                <button class="btn btn-sm btn-outline-success" type="submit"
                                                    onclick="return confirm('Approve this IEC material for use?')">
                                                    <i class="bi bi-patch-check me-1"></i>
                                                    Approve
                                                </button>
                                            </form>

                                        <?php endif; ?>

                                        <?php if (
                                            $canApprove
                                            &&
                                            $material["status"]
                                            ===
                                            "Approved"
                                        ): ?>

                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="action" value="set_status">
                                                <input type="hidden" name="id" value="<?= (int) $material["id"] ?>">
                                                <input type="hidden" name="status" value="Superseded">

                                                <button class="btn btn-sm btn-outline-secondary" type="submit"
                                                    onclick="return confirm('Mark this approved IEC material as superseded?')">
                                                    Supersede
                                                </button>
                                            </form>

                                        <?php endif; ?>

                                        <?php if ($canArchive): ?>

                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $material["id"] ?>">
                                                <input type="hidden" name="action"
                                                    value="<?= (int) $material["active"] === 1 ? "archive" : "restore" ?>">

                                                <button
                                                    class="btn btn-sm <?= (int) $material["active"] === 1 ? "btn-outline-warning" : "btn-outline-success" ?>"
                                                    type="submit"
                                                    onclick="return confirm('<?= (int) $material["active"] === 1 ? "Archive this IEC material?" : "Restore this IEC material?" ?>')">
                                                    <i
                                                        class="bi <?= (int) $material["active"] === 1 ? "bi-archive" : "bi-arrow-counterclockwise" ?> me-1"></i>
                                                    <?= (int) $material["active"] === 1 ? "Archive" : "Restore" ?>
                                                </button>
                                            </form>

                                        <?php endif; ?>

                                        <?php if ($canDelete): ?>

                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $material["id"] ?>">
                                                <input type="hidden" name="action" value="delete">

                                                <button class="btn btn-sm btn-outline-danger" type="submit"
                                                    title="Permanently delete"
                                                    onclick="return confirm('Permanently delete this IEC material, audit logs, and all stored versions? This cannot be undone.')">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                    <?php if ($totalPages > 1): ?>

                        <div class="pagination-wrap">

                            <div class="small text-muted">
                                Page <?= $page ?> of <?= $totalPages ?>
                                • <?= $totalMaterials ?> materials
                            </div>

                            <nav>
                                <ul class="pagination pagination-sm mb-0">

                                    <li class="page-item <?= $page <= 1 ? "disabled" : "" ?>">
                                        <a class="page-link"
                                            href="?<?= e(http_build_query(array_merge($paginationParams, ["page" => max(1, $page - 1)]))) ?>">
                                            Previous
                                        </a>
                                    </li>

                                    <?php
                                    $startPage =
                                        max(
                                            1,
                                            $page - 2
                                        );

                                    $endPage =
                                        min(
                                            $totalPages,
                                            $page + 2
                                        );
                                    ?>

                                    <?php for ($p = $startPage; $p <= $endPage; $p++): ?>

                                        <li class="page-item <?= $p === $page ? "active" : "" ?>">
                                            <a class="page-link"
                                                href="?<?= e(http_build_query(array_merge($paginationParams, ["page" => $p]))) ?>">
                                                <?= $p ?>
                                            </a>
                                        </li>

                                    <?php endfor; ?>

                                    <li class="page-item <?= $page >= $totalPages ? "disabled" : "" ?>">
                                        <a class="page-link"
                                            href="?<?= e(http_build_query(array_merge($paginationParams, ["page" => min($totalPages, $page + 1)]))) ?>">
                                            Next
                                        </a>
                                    </li>

                                </ul>
                            </nav>

                        </div>

                    <?php endif; ?>

                </div>

            </main>

        </div>

    </div>

    <?php if ($canUpload): ?>

        <!-- =========================================================
     UPLOAD MODAL
========================================================= -->
        <div class="modal fade" id="uploadModal" tabindex="-1">

            <div class="modal-dialog modal-xl modal-dialog-centered">

                <div class="modal-content">

                    <form method="post" enctype="multipart/form-data">

                        <div class="modal-header">

                            <div>
                                <h5 class="modal-title fw-bold">
                                    Upload IEC Material
                                </h5>

                                <div class="small text-muted">
                                    New materials are saved as Draft and may be submitted for review.
                                </div>
                            </div>

                            <button class="btn-close" type="button" data-bs-dismiss="modal"></button>

                        </div>

                        <div class="modal-body">

                            <input type="hidden" name="action" value="upload">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

                            <div class="row g-3">

                                <div class="col-md-7">
                                    <label class="form-label fw-bold">
                                        Material Title *
                                    </label>

                                    <input class="form-control" name="title" maxlength="220" required>
                                </div>

                                <div class="col-md-5">
                                    <label class="form-label fw-bold">
                                        Category *
                                    </label>

                                    <input class="form-control" name="category" value="General IEC" list="iecCategories"
                                        required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-bold">
                                        Material Type *
                                    </label>

                                    <select class="form-select" name="material_type" required>
                                        <option value="">
                                            Select type
                                        </option>

                                        <?php foreach ($materialTypes as $materialType): ?>

                                            <option value="<?= e($materialType) ?>">
                                                <?= e($materialType) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>
                                </div>

                                <div class="col-md-5">
                                    <label class="form-label fw-bold">
                                        Health Topic / Program
                                    </label>

                                    <input class="form-control" name="health_topic"
                                        placeholder="e.g. Dengue Prevention, Seven Healthy Habits">
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-bold">
                                        Version
                                    </label>

                                    <input class="form-control" name="version_number" value="1.0" maxlength="30">
                                </div>

                                <div class="col-12">
                                    <label class="form-label fw-bold">
                                        Target Audience
                                    </label>

                                    <div class="audience-check-grid">

                                        <?php foreach ($audienceOptions as $audience): ?>

                                            <label class="audience-check">

                                                <input class="form-check-input" type="checkbox" name="target_audience[]"
                                                    value="<?= e($audience) ?>">

                                                <span>
                                                    <?= e($audience) ?>
                                                </span>

                                            </label>

                                        <?php endforeach; ?>

                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label fw-bold">
                                        Description / Notes
                                    </label>

                                    <textarea class="form-control" name="description" rows="3"></textarea>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">
                                        Version Notes
                                    </label>

                                    <input class="form-control" name="version_notes" maxlength="255"
                                        placeholder="e.g. Initial release">
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-bold">
                                        Review Date
                                    </label>

                                    <input class="form-control" type="date" name="review_date">
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-bold">
                                        Valid Until
                                    </label>

                                    <input class="form-control" type="date" name="valid_until">
                                </div>

                                <div class="col-12">
                                    <label class="form-label fw-bold">
                                        File *
                                    </label>

                                    <input class="form-control" type="file" name="iec_file"
                                        accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.csv,.jpg,.jpeg,.png,.webp,.mp4,.mov,.webm"
                                        required>

                                    <div class="form-text">
                                        PDF, Office files, images, CSV and supported videos.
                                        Maximum: 50 MB.
                                    </div>
                                </div>

                                <div class="col-12">

                                    <label class="form-check">

                                        <input class="form-check-input" type="checkbox" name="featured" value="1">

                                        <span class="form-check-label">
                                            Mark as Featured / Recommended
                                        </span>

                                    </label>

                                </div>

                            </div>

                        </div>

                        <div class="modal-footer">

                            <button class="btn btn-light" type="button" data-bs-dismiss="modal">
                                Cancel
                            </button>

                            <button class="btn btn-primary" type="submit">
                                <i class="bi bi-cloud-arrow-up me-1"></i>
                                Upload as Draft
                            </button>

                        </div>

                    </form>

                </div>

            </div>

        </div>

        <datalist id="iecCategories">
            <option value="General IEC">
            <option value="Communicable Diseases">
            <option value="Noncommunicable Diseases">
            <option value="Maternal and Child Health">
            <option value="Nutrition">
            <option value="Mental Health">
            <option value="Environmental Health">
            <option value="Hospital Services">
            <option value="Employee Health">
            <option value="Emergency Preparedness">
            <option value="Posters and Infographics">
            <option value="Presentation Materials">
        </datalist>

    <?php endif; ?>

    <?php if ($isHpuRole): ?>

        <!-- =========================================================
     EDIT MODAL
========================================================= -->
        <div class="modal fade" id="editModal" tabindex="-1">

            <div class="modal-dialog modal-xl modal-dialog-centered">

                <div class="modal-content">

                    <form method="post" enctype="multipart/form-data">

                        <div class="modal-header">

                            <div>
                                <h5 class="modal-title fw-bold">
                                    Edit IEC Material
                                </h5>

                                <div class="small text-muted">
                                    Replacing the file preserves the previous version in version history.
                                </div>
                            </div>

                            <button class="btn-close" type="button" data-bs-dismiss="modal"></button>

                        </div>

                        <div class="modal-body">

                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="id" id="editMaterialId">

                            <div class="row g-3">

                                <div class="col-md-7">
                                    <label class="form-label fw-bold">
                                        Material Title *
                                    </label>

                                    <input class="form-control" name="title" id="editMaterialTitle" required>
                                </div>

                                <div class="col-md-5">
                                    <label class="form-label fw-bold">
                                        Category *
                                    </label>

                                    <input class="form-control" name="category" id="editMaterialCategory"
                                        list="iecCategories" required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-bold">
                                        Material Type *
                                    </label>

                                    <select class="form-select" name="material_type" id="editMaterialType" required>
                                        <?php foreach ($materialTypes as $materialType): ?>

                                            <option value="<?= e($materialType) ?>">
                                                <?= e($materialType) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>
                                </div>

                                <div class="col-md-5">
                                    <label class="form-label fw-bold">
                                        Health Topic / Program
                                    </label>

                                    <input class="form-control" name="health_topic" id="editHealthTopic">
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-bold">
                                        Version
                                    </label>

                                    <input class="form-control" name="version_number" id="editVersionNumber">
                                </div>

                                <div class="col-12">
                                    <label class="form-label fw-bold">
                                        Target Audience
                                    </label>

                                    <div class="audience-check-grid">

                                        <?php foreach ($audienceOptions as $audience): ?>

                                            <label class="audience-check">

                                                <input class="form-check-input edit-audience" type="checkbox"
                                                    name="target_audience[]" value="<?= e($audience) ?>">

                                                <span>
                                                    <?= e($audience) ?>
                                                </span>

                                            </label>

                                        <?php endforeach; ?>

                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label fw-bold">
                                        Description / Notes
                                    </label>

                                    <textarea class="form-control" name="description" id="editMaterialDescription"
                                        rows="3"></textarea>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">
                                        Version Notes
                                    </label>

                                    <input class="form-control" name="version_notes" id="editVersionNotes" maxlength="255">
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-bold">
                                        Review Date
                                    </label>

                                    <input class="form-control" type="date" name="review_date" id="editReviewDate">
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-bold">
                                        Valid Until
                                    </label>

                                    <input class="form-control" type="date" name="valid_until" id="editValidUntil">
                                </div>

                                <div class="col-12">
                                    <label class="form-label fw-bold">
                                        Replace File
                                    </label>

                                    <input class="form-control" type="file" name="replacement_file"
                                        accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.csv,.jpg,.jpeg,.png,.webp,.mp4,.mov,.webm">

                                    <div class="form-text">
                                        Leave blank to keep the current file.
                                        Current:
                                        <strong id="editMaterialFilename"></strong>
                                    </div>
                                </div>

                                <div class="col-12">

                                    <label class="form-check">

                                        <input class="form-check-input" type="checkbox" name="featured" value="1"
                                            id="editFeatured">

                                        <span class="form-check-label">
                                            Featured / Recommended
                                        </span>

                                    </label>

                                </div>

                            </div>

                        </div>

                        <div class="modal-footer">

                            <button class="btn btn-light" type="button" data-bs-dismiss="modal">
                                Cancel
                            </button>

                            <button class="btn btn-primary" type="submit">
                                Save Changes
                            </button>

                        </div>

                    </form>

                </div>

            </div>

        </div>

    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <button id="toTopBtn" type="button" title="Back to top" aria-label="Back to top">
        <i class="bi bi-arrow-up"></i>
    </button>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            // Press "/" to jump to search.
            var searchInput = document.getElementById("iecSearchInput");

            document.addEventListener("keydown", function (ev) {
                if (ev.key === "/" && searchInput
                    && document.activeElement !== searchInput
                    && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) {
                    ev.preventDefault();
                    searchInput.focus();
                    searchInput.select();
                }
            });

            // Back-to-top visibility.
            var toTop = document.getElementById("toTopBtn");

            window.addEventListener("scroll", function () {
                if (window.scrollY > 600) {
                    toTop.classList.add("show");
                } else {
                    toTop.classList.remove("show");
                }
            }, { passive: true });

            toTop.addEventListener("click", function () {
                window.scrollTo({ top: 0, behavior: "smooth" });
            });
        });
    </script>

    <?php if ($isHpuRole): ?>

        <script>
            document.addEventListener(
                "DOMContentLoaded",
                function () {

                    const editModalElement =
                        document.getElementById(
                            "editModal"
                        );

                    if (!editModalElement) {
                        return;
                    }

                    const modal =
                        new bootstrap.Modal(
                            editModalElement
                        );

                    document
                        .querySelectorAll(
                            ".edit-material-btn"
                        )
                        .forEach(
                            function (button) {
                                button.addEventListener(
                                    "click",
                                    function () {

                                        const material =
                                            JSON.parse(
                                                button.dataset.material
                                            );

                                        document
                                            .getElementById(
                                                "editMaterialId"
                                            )
                                            .value =
                                            material.id;

                                        document
                                            .getElementById(
                                                "editMaterialTitle"
                                            )
                                            .value =
                                            material.title
                                            || "";

                                        document
                                            .getElementById(
                                                "editMaterialCategory"
                                            )
                                            .value =
                                            material.category
                                            || "General IEC";

                                        document
                                            .getElementById(
                                                "editMaterialType"
                                            )
                                            .value =
                                            material.material_type
                                            || "Other";

                                        document
                                            .getElementById(
                                                "editHealthTopic"
                                            )
                                            .value =
                                            material.health_topic
                                            || "";

                                        document
                                            .getElementById(
                                                "editVersionNumber"
                                            )
                                            .value =
                                            material.version_number
                                            || "1.0";

                                        document
                                            .getElementById(
                                                "editVersionNotes"
                                            )
                                            .value =
                                            material.version_notes
                                            || "";

                                        document
                                            .getElementById(
                                                "editReviewDate"
                                            )
                                            .value =
                                            material.review_date
                                            || "";

                                        document
                                            .getElementById(
                                                "editValidUntil"
                                            )
                                            .value =
                                            material.valid_until
                                            || "";

                                        document
                                            .getElementById(
                                                "editMaterialDescription"
                                            )
                                            .value =
                                            material.description
                                            || "";

                                        document
                                            .getElementById(
                                                "editMaterialFilename"
                                            )
                                            .textContent =
                                            material.filename
                                            || "Unknown";

                                        document
                                            .getElementById(
                                                "editFeatured"
                                            )
                                            .checked =
                                            Number(
                                                material.featured
                                                || 0
                                            )
                                            ===
                                            1;

                                        const selectedAudience =
                                            Array.isArray(
                                                material.target_audience
                                            )
                                                ? material.target_audience
                                                : [];

                                        document
                                            .querySelectorAll(
                                                ".edit-audience"
                                            )
                                            .forEach(
                                                function (checkbox) {
                                                    checkbox.checked =
                                                        selectedAudience.includes(
                                                            checkbox.value
                                                        );
                                                }
                                            );

                                        modal.show();
                                    }
                                );
                            }
                        );

                }
            );
        </script>

    <?php endif; ?>

</body>

</html>