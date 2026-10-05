<?php
declare(strict_types=1);

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

checkLogin();
requireModule($conn, "publication_submission");

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

$userId =
    (int)(
        $_SESSION["user_id"]
        ?? 0
    );

$kind =
    trim(
        (string)(
            $_GET["kind"]
            ?? ""
        )
    );

$id =
    (int)(
        $_GET["id"]
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
    http_response_code(
        400
    );

    exit(
        "Invalid publication request."
    );
}

function viewPubDate(
    ?string $date,
    bool $withTime = false
): string {
    $date =
        trim(
            (string)
            $date
        );

    if ($date === "") {
        return "—";
    }

    $time =
        strtotime(
            $date
        );

    if ($time === false) {
        return $date;
    }

    return
        date(
            $withTime
                ? "M d, Y • g:i A"
                : "M d, Y",
            $time
        );
}

function viewPubTypes(
    ?string $value
): string {
    $value =
        trim(
            (string)
            $value
        );

    if ($value === "") {
        return "—";
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
            implode(
                ", ",
                array_map(
                    "strval",
                    $decoded
                )
            );
    }

    return $value;
}

function viewPubFiles(
    ?string $json
): array {
    $files =
        json_decode(
            (string)
            $json,
            true
        );

    return
        is_array(
            $files
        )
            ? $files
            : [];
}

function viewPubFileSize(
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS publication_message_reads (
        kind VARCHAR(20) NOT NULL,
        publication_id INT NOT NULL,
        user_id INT NOT NULL,
        last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (kind, publication_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

if (
    $kind
    ===
    "event"
) {
    $stmt =
        $conn->prepare(
            "SELECT
                p.*,
                reviewer.name AS reviewer_name,
                su.department AS submitter_department,
                su.name AS submitter_name
             FROM publication_event_submissions p
             LEFT JOIN users reviewer
                ON reviewer.id = p.reviewed_by
             LEFT JOIN users su
                ON su.id = p.submitted_by
             WHERE p.id = ?
             LIMIT 1"
        );
} elseif (
    $kind
    ===
    "recognition"
) {
    $stmt =
        $conn->prepare(
            "SELECT
                p.*,
                reviewer.name AS reviewer_name,
                su.department AS submitter_department,
                su.name AS submitter_name
             FROM publication_recognition_submissions p
             LEFT JOIN users reviewer
                ON reviewer.id = p.reviewed_by
             LEFT JOIN users su
                ON su.id = p.submitted_by
             WHERE p.id = ?
             LIMIT 1"
        );
} elseif (
    $kind
    ===
    "contribution"
) {
    $stmt =
        $conn->prepare(
            "SELECT
                p.*,
                reviewer.name AS reviewer_name,
                su.department AS submitter_department,
                su.name AS submitter_name
             FROM publication_content_contributions p
             LEFT JOIN users reviewer
                ON reviewer.id = p.reviewed_by
             LEFT JOIN users su
                ON su.id = p.submitted_by
             WHERE p.id = ?
             LIMIT 1"
        );
}

$stmt->bind_param(
    "i",
    $id
);

$stmt->execute();

$record =
    $stmt
        ->get_result()
        ->fetch_assoc();

$stmt->close();

$isOwner =
    (int) ($record["submitted_by"] ?? 0)
    ===
    $userId;

$readOnlyViewer = false;

if ($record && !$isOwner) {
    // Same-office colleagues may view (read-only) but never act.
    $viewerDept = trim((string) ($_SESSION["department"] ?? ""));

    if ($viewerDept === "") {
        $viewerDeptStmt = $conn->prepare(
            "SELECT department FROM users WHERE id = ? LIMIT 1"
        );

        if ($viewerDeptStmt) {
            $viewerDeptStmt->bind_param("i", $userId);
            $viewerDeptStmt->execute();
            $viewerDeptRow = $viewerDeptStmt->get_result()->fetch_assoc();
            $viewerDeptStmt->close();
            $viewerDept = trim((string) ($viewerDeptRow["department"] ?? ""));
        }
    }

    $sameOfficeView = false;

    if ($viewerDept !== "") {
        $normDept = static function ($v) {
            return mb_strtolower(trim((string) $v));
        };

        $submitterDept = (string) ($record["submitter_department"] ?? "");
        $typedOffice = in_array($kind, ["event", "contribution"], true)
            ? (string) ($record["lead_office"] ?? "")
            : "";

        $sameOfficeView =
            ($submitterDept !== "" && $normDept($submitterDept) === $normDept($viewerDept))
            || ($typedOffice !== "" && $normDept($typedOffice) === $normDept($viewerDept));
    }

    if (!$sameOfficeView) {
        $record = null;
    } else {
        $readOnlyViewer = true;
    }
}

if (!$record) {
    http_response_code(
        404
    );

    exit(
        "Publication request not found or you do not have access to it."
    );
}

/*
|--------------------------------------------------------------------------
| Public Tracking Token (same track.php?token= system as job requests)
|--------------------------------------------------------------------------
*/
$viewPubTable = $kind === "event"
    ? "publication_event_submissions"
    : ($kind === "contribution"
        ? "publication_content_contributions"
        : "publication_recognition_submissions");

try {
    $tokenColCheck = $conn->query(
        "SHOW COLUMNS FROM `{$viewPubTable}` LIKE 'public_token'"
    );

    if ($tokenColCheck && $tokenColCheck->num_rows === 0) {
        try {
            $conn->query(
                "ALTER TABLE `{$viewPubTable}`
                 ADD COLUMN `public_token` CHAR(64) NULL,
                 ADD UNIQUE INDEX `uniq_{$viewPubTable}_public_token` (`public_token`)"
            );
        } catch (Throwable $e) {
            try {
                $conn->query(
                    "ALTER TABLE `{$viewPubTable}`
                     ADD COLUMN `public_token` CHAR(64) NULL"
                );
            } catch (Throwable $e2) {
            }
        }
    }
} catch (Throwable $e) {
}

$publicationToken = trim((string) ($record["public_token"] ?? ""));

if (
    strlen($publicationToken) !== 64 ||
    !ctype_xdigit($publicationToken)
) {
    // Generate a globally-unique token (checked against all trackable tables).
    do {
        $publicationToken = bin2hex(random_bytes(32));
        $pubCollision = false;

        foreach (
            [
                "job_requests",
                "publication_event_submissions",
                "publication_recognition_submissions",
                "publication_content_contributions",
            ] as $collisionTable
        ) {
            try {
                $collisionCheck = $conn->prepare(
                    "SELECT id FROM `{$collisionTable}` WHERE public_token = ? LIMIT 1"
                );

                if (!$collisionCheck) {
                    continue;
                }

                $collisionCheck->bind_param("s", $publicationToken);
                $collisionCheck->execute();
                $hit = $collisionCheck->get_result()->fetch_assoc();
                $collisionCheck->close();

                if ($hit) {
                    $pubCollision = true;
                    break;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
    } while ($pubCollision);

    try {
        $tokenUpdate = $conn->prepare(
            "UPDATE `{$viewPubTable}` SET public_token = ? WHERE id = ?"
        );

        if ($tokenUpdate) {
            $tokenUpdate->bind_param("si", $publicationToken, $id);
            $tokenUpdate->execute();
            $tokenUpdate->close();
            $record["public_token"] = $publicationToken;
        }
    } catch (Throwable $e) {
        $publicationToken = "";
    }
}

$publicationTrackingUrl = (
    strlen($publicationToken) === 64 &&
    ctype_xdigit($publicationToken)
)
    ? rtrim(BASE_URL, "/") . "/track.php?token=" . rawurlencode($publicationToken)
    : "";

/*
|--------------------------------------------------------------------------
| Secure Supporting File Download (recognition + contribution)
|--------------------------------------------------------------------------
*/

if (
    in_array($kind, ["recognition", "contribution"], true)
    &&
    isset(
        $_GET["download"]
    )
) {
    $fileIndex =
        (int)
        $_GET["download"];

    $files =
        viewPubFiles(
            $record["supporting_files"]
            ?? null
        );

    if (
        !isset(
            $files[$fileIndex]
        )
    ) {
        http_response_code(
            404
        );

        exit(
            "Supporting file not found."
        );
    }

    $file =
        $files[$fileIndex];

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
        dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . "storage"
        . DIRECTORY_SEPARATOR
        . "publication_submissions"
        . DIRECTORY_SEPARATOR
        . ($kind === "contribution" ? "content_contributions" : "recognition")
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
            "Stored supporting file is missing."
        );
    }

    header(
        "Content-Type: "
        . (
            $file["mime"]
            ?? "application/octet-stream"
        )
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
        "Content-Disposition: attachment; filename=\""
        . addcslashes(
            $originalName,
            "\"\\"
        )
        . "\"; filename*=UTF-8''"
        . rawurlencode(
            $originalName
        )
    );

    readfile(
        $filePath
    );

    exit;
}

$title =
    $kind === "event"
        ? (string)$record["title"]
        : ($kind === "contribution"
            ? (string)$record["content_title"]
            : (string)$record["recognized_entity"]);

$status =
    (string)(
        $record["status"]
        ?? "For Review"
    );

/*
|--------------------------------------------------------------------------
| Hero / Workflow Display Data (admin view style)
|--------------------------------------------------------------------------
*/

$pubCode = publicationDisplayCode($kind, (int) $id);

$pubStages = [
    "For Review" => ["label" => "Submitted", "icon" => "bi-send-check"],
    "In Preparation" => ["label" => "In Preparation", "icon" => "bi-tools"],
    "For Approval" => ["label" => "For Approval", "icon" => "bi-patch-check"],
    "Published" => ["label" => "Published", "icon" => "bi-megaphone-fill"],
    "Closed" => ["label" => "Closed", "icon" => "bi-lock"]
];

$pubStageKeys = array_keys($pubStages);

$pubStageIndex =
    array_search(
        $status,
        $pubStageKeys,
        true
    );

if ($pubStageIndex === false) {
    $pubStageIndex = 0;
}

$pubProgress =
    (int) round(
        (($pubStageIndex + 1) / count($pubStageKeys)) * 100
    );

$pubStatusClass = match ($status) {
    "Published" =>
        "bg-success",

    "Closed" =>
        "bg-dark",

    "For Approval" =>
        "bg-warning text-dark",

    "In Preparation" =>
        "bg-info text-dark",

    default =>
        "bg-secondary"
};

$pubRequestedTs =
    !empty($record["created_at"])
        ? strtotime(
            (string)$record["created_at"]
        )
        : false;

$pubAgeDays =
    $pubRequestedTs !== false
        ? max(
            0,
            (int) floor(
                (time() - $pubRequestedTs) / 86400
            )
        )
        : null;

$pubPrefTs =
    !empty($record["preferred_publication_date"])
        ? strtotime(
            (string)$record["preferred_publication_date"]
        )
        : false;

$pubRemainingDays =
    $pubPrefTs !== false
        ? (int) floor(
            ($pubPrefTs - strtotime("today")) / 86400
        )
        : null;

/*
|--------------------------------------------------------------------------
| Publication Evaluation State (same survey as job requests)
|--------------------------------------------------------------------------
*/

$pubSurveySubmitted = false;
$pubSurveyRating = null;

$pubSurveyCheck =
    $conn->query(
        "SHOW COLUMNS
          FROM job_request_client_experience
          LIKE 'publication_kind'"
    );

if (
    $pubSurveyCheck
    &&
    $pubSurveyCheck->num_rows > 0
) {
    $pubSurveyStmt = $conn->prepare(
        "SELECT overall_satisfaction
          FROM job_request_client_experience
          WHERE publication_kind = ?
            AND publication_id = ?
          LIMIT 1"
    );

    if ($pubSurveyStmt) {
        $pubSurveyStmt->bind_param(
            "si",
            $kind,
            $id
        );

        $pubSurveyStmt->execute();

        $pubSurveyRow =
            $pubSurveyStmt
                ->get_result()
                ->fetch_assoc();

        $pubSurveyStmt->close();

        if ($pubSurveyRow) {
            $pubSurveySubmitted = true;
            $pubSurveyRating =
                $pubSurveyRow["overall_satisfaction"]
                ?? null;
        }
    }
}

$canEvaluatePub =
    $isOwner
    &&
    in_array(
        $status,
        ["Published", "Closed"],
        true
    )
    &&
    !$pubSurveySubmitted;

$pubEvaluationLabel = $pubSurveySubmitted
    ? "Submitted"
    : ($canEvaluatePub
        ? "Pending"
        : "Not Available");

$pubSurveyUrl =
    rtrim(BASE_URL, "/")
    . "/user/client_experience_survey.php?kind="
    . urlencode($kind)
    . "&id="
    . $id;

/*
|--------------------------------------------------------------------------
| Owner Edit
|--------------------------------------------------------------------------
| Users may modify their own submissions while the workflow is still
| open. Published / Closed submissions are locked.
|--------------------------------------------------------------------------
*/

$editableStatuses = [
    "For Review",
    "In Preparation",
    "For Approval"
];

$isEditable =
    $isOwner
    &&
    in_array(
        $status,
        $editableStatuses,
        true
    );

$recognitionTypeOptions = [
    "Hospital / Institutional Recognition",
    "Award or Recognition",
    "Training / Course Completion",
    "Certification / Accreditation",
    "Fellowship / Diplomate / Professional Milestone",
    "Appointment / Designation",
    "Others"
];

function viewPubValidDate($value): ?string
{
    $value = trim((string)$value);

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

function viewPubRecognitionDir(): string
{
    $dir =
        dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . "storage"
        . DIRECTORY_SEPARATOR
        . "publication_submissions"
        . DIRECTORY_SEPARATOR
        . "recognition";

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
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

function viewPubValidateFiles(array $files): array
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
            "You may upload up to 5 supporting files at a time."
        );
    }

    $allowed = [
        "pdf",
        "jpg",
        "jpeg",
        "png",
        "webp",
        "doc",
        "docx"
    ];

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
            $size > 10 * 1024 * 1024
        ) {
            throw new RuntimeException(
                "Each supporting file must be 10 MB or smaller."
            );
        }

        $original =
            basename(
                (string)(
                    $files["name"][$i]
                    ?? ""
                )
            );

        $ext =
            strtolower(
                pathinfo(
                    $original,
                    PATHINFO_EXTENSION
                )
            );

        if (
            !in_array(
                $ext,
                $allowed,
                true
            )
        ) {
            throw new RuntimeException(
                "Unsupported file type. Use PDF, Word, JPG, PNG, or WEBP files."
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
            "tmp_name" => (string)$files["tmp_name"][$i],
            "original_name" => $original,
            "extension" => $ext,
            "size" => $size,
            "mime" => $mime
        ];
    }

    return $normalized;
}

if (empty($_SESSION["view_publication_csrf"])) {
    $_SESSION["view_publication_csrf"] =
        bin2hex(random_bytes(32));
}

$viewCsrf =
    (string)$_SESSION["view_publication_csrf"];

$viewError = "";
$viewSuccess = "";
$justPosted = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $justPosted = true;
    $postedCsrf =
        (string)($_POST["csrf_token"] ?? "");

    if (
        $postedCsrf === ""
        ||
        !hash_equals($viewCsrf, $postedCsrf)
    ) {
        $viewError =
            "Security token expired. Please refresh the page and try again.";
    } elseif (!$isEditable) {
        $viewError =
            "This submission is "
            . $status
            . " and can no longer be modified.";
    } else {
        $ownerAction =
            trim(
                (string)(
                    $_POST["owner_action"]
                    ?? "update"
                )
            );

        if ($ownerAction === "delete_file") {
            $fileIndex =
                (int)($_POST["file_index"] ?? -1);

            $existing =
                viewPubFiles(
                    $record["supporting_files"]
                    ?? null
                );

            if (!isset($existing[$fileIndex])) {
                $viewError =
                    "Supporting file not found.";
            } else {
                $removed = $existing[$fileIndex];
                unset($existing[$fileIndex]);
                $existing = array_values($existing);

                $oldPath =
                    viewPubRecognitionDir()
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
                    is_file($oldPath)
                ) {
                    @unlink($oldPath);
                }

                $stmt = $conn->prepare(
                    "UPDATE publication_recognition_submissions
                      SET supporting_files = ?
                      WHERE id = ?
                        AND submitted_by = ?"
                );

                $filesJson = json_encode(
                    $existing,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                );

                $stmt->bind_param(
                    "sii",
                    $filesJson,
                    $id,
                    $userId
                );

                $stmt->execute();
                $stmt->close();

                $record["supporting_files"] =
                    $filesJson;

                $viewSuccess =
                    "Supporting file deleted.";
            }
        } elseif ($ownerAction === "add_files") {
            try {
                $validated =
                    viewPubValidateFiles(
                        $_FILES["supporting_files"]
                        ?? []
                    );
            } catch (RuntimeException $e) {
                $validated = null;
                $viewError = $e->getMessage();
            }

            if ($viewError === "") {
                if (!$validated) {
                    $viewError =
                        "Please choose at least one file to upload.";
                } else {
                    $existing =
                        viewPubFiles(
                            $record["supporting_files"]
                            ?? null
                        );

                    if (
                        count($existing)
                        + count($validated)
                        > 5
                    ) {
                        $viewError =
                            "Only 5 supporting files are allowed per submission.";
                    } else {
                        $storageDir =
                            viewPubRecognitionDir();

                        $storedPaths = [];

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

                                $storedPaths[] =
                                    $destination;

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

                            $filesJson = json_encode(
                                array_values($existing),
                                JSON_UNESCAPED_UNICODE
                                | JSON_UNESCAPED_SLASHES
                            );

                            $stmt = $conn->prepare(
                                "UPDATE publication_recognition_submissions
                                  SET supporting_files = ?
                                  WHERE id = ?
                                    AND submitted_by = ?"
                            );

                            $stmt->bind_param(
                                "sii",
                                $filesJson,
                                $id,
                                $userId
                            );

                            $stmt->execute();
                            $stmt->close();

                            $record["supporting_files"] =
                                $filesJson;

                            $viewSuccess =
                                "Supporting file(s) added.";
                        } catch (Throwable $e) {
                            foreach ($storedPaths as $path) {
                                if (is_file($path)) {
                                    @unlink($path);
                                }
                            }

                            if (
                                $e instanceof RuntimeException
                            ) {
                                $viewError =
                                    $e->getMessage();
                            } else {
                                throw $e;
                            }
                        }
                    }
                }
            }
        } else {
            $email =
                trim(
                    (string)(
                        $_POST["email_address"]
                        ?? ""
                    )
                );

            $pubDate =
                viewPubValidDate(
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
                $viewError =
                    "Please enter a valid email address.";
            } else {
                if ($kind === "recognition") {
                    $types =
                        $_POST["recognition_types"]
                        ?? [];

                    if (!is_array($types)) {
                        $types = [];
                    }

                    $types = array_values(
                        array_unique(
                            array_filter(
                                array_map(
                                    "trim",
                                    array_map(
                                        "strval",
                                        $types
                                    )
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

                    if (!$types) {
                        $viewError =
                            "Please select at least one recognition / milestone type.";
                    } elseif (
                        count(
                            array_diff(
                                $types,
                                $recognitionTypeOptions
                            )
                        ) > 0
                    ) {
                        $viewError =
                            "One or more selected recognition types are invalid.";
                    } elseif ($entity === "") {
                        $viewError =
                            "Please indicate who or what was recognized.";
                    } elseif ($achievement === "") {
                        $viewError =
                            "Please describe what was achieved.";
                    } else {
                        $typesJson = json_encode(
                            $types,
                            JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES
                        );

                        $stmt = $conn->prepare(
                            "UPDATE publication_recognition_submissions
                              SET email_address = ?,
                                  recognition_type = ?,
                                  recognized_entity = ?,
                                  achievement = ?,
                                  supporting_information = ?,
                                  preferred_publication_date = ?
                              WHERE id = ?
                                AND submitted_by = ?"
                        );

                        $stmt->bind_param(
                            "ssssssii",
                            $email,
                            $typesJson,
                            $entity,
                            $achievement,
                            $supporting,
                            $pubDate,
                            $id,
                            $userId
                        );

                        $stmt->execute();
                        $stmt->close();

                        $record["email_address"] =
                            $email;
                        $record["recognition_type"] =
                            $typesJson;
                        $record["recognized_entity"] =
                            $entity;
                        $record["achievement"] =
                            $achievement;
                        $record["supporting_information"] =
                            $supporting;
                        $record["preferred_publication_date"] =
                            $pubDate;

                        $title = $entity;
                        $viewSuccess =
                            "Submission updated successfully.";
                    }
                } elseif ($kind === "event") {
                    $evTitle =
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

                    $strOrNull =
                        static function ($v) {
                            $v =
                                trim((string)$v);
                            return $v === ""
                                ? null
                                : $v;
                        };

                    if ($evTitle === "") {
                        $viewError =
                            "Please enter the activity title.";
                    } elseif ($activityType === "") {
                        $viewError =
                            "Please enter the type of activity.";
                    } else {
                        $startDate =
                            viewPubValidDate(
                                $_POST["start_date"]
                                ?? null
                            );

                        $endDate =
                            viewPubValidDate(
                                $_POST["end_date"]
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
                                : max(
                                    0,
                                    (int)$participantRaw
                                );

                        $activityTime =
                            $strOrNull(
                                $_POST["activity_time"]
                                ?? null
                            );
                        $venue =
                            $strOrNull(
                                $_POST["venue"]
                                ?? null
                            );
                        $leadOffice =
                            $strOrNull(
                                $_POST["lead_office"]
                                ?? null
                            );
                        $partnerOffices =
                            $strOrNull(
                                $_POST["partner_offices"]
                                ?? null
                            );
                        $personInCharge =
                            $strOrNull(
                                $_POST["person_in_charge"]
                                ?? null
                            );
                        $background =
                            $strOrNull(
                                $_POST["background"]
                                ?? null
                            );
                        $objectives =
                            $strOrNull(
                                $_POST["objectives"]
                                ?? null
                            );
                        $highlights =
                            $strOrNull(
                                $_POST["activity_highlights"]
                                ?? null
                            );
                        $keyOutputs =
                            $strOrNull(
                                $_POST["key_outputs"]
                                ?? null
                            );
                        $impact =
                            $strOrNull(
                                $_POST["impact_significance"]
                                ?? null
                            );
                        $quotable =
                            $strOrNull(
                                $_POST["quotable_statement"]
                                ?? null
                            );
                        $remembrance =
                            $strOrNull(
                                $_POST["remembrance_sentence"]
                                ?? null
                            );

                        $stmt = $conn->prepare(
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
                                  preferred_publication_date = ?
                              WHERE id = ?
                                AND submitted_by = ?"
                        );

                        $stmt->bind_param(
                            "ssssssssssissssssssii",
                            $email,
                            $evTitle,
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
                            $id,
                            $userId
                        );

                        $stmt->execute();
                        $stmt->close();

                        $record["email_address"] =
                            $email;
                        $record["title"] =
                            $evTitle;
                        $record["activity_type"] =
                            $activityType;
                        $record["start_date"] =
                            $startDate;
                        $record["end_date"] =
                            $endDate;
                        $record["activity_time"] =
                            $activityTime;
                        $record["venue"] =
                            $venue;
                        $record["lead_office"] =
                            $leadOffice;
                        $record["partner_offices"] =
                            $partnerOffices;
                        $record["person_in_charge"] =
                            $personInCharge;
                        $record["participant_count"] =
                            $participantCount;
                        $record["background"] =
                            $background;
                        $record["objectives"] =
                            $objectives;
                        $record["activity_highlights"] =
                            $highlights;
                        $record["key_outputs"] =
                            $keyOutputs;
                        $record["impact_significance"] =
                            $impact;
                        $record["quotable_statement"] =
                            $quotable;
                        $record["remembrance_sentence"] =
                            $remembrance;
                        $record["preferred_publication_date"] =
                            $pubDate;

                        $title = $evTitle;
                        $viewSuccess =
                            "Submission updated successfully.";
                    }
                } elseif ($kind === "contribution") {
                    $contribTitle =
                        trim(
                            (string)(
                                $_POST["content_title"]
                                ?? ""
                            )
                        );

                    $contribType =
                        trim(
                            (string)(
                                $_POST["contribution_type"]
                                ?? ""
                            )
                        );

                    $contribChannel =
                        trim(
                            (string)(
                                $_POST["publication_channel"]
                                ?? ""
                            )
                        );

                    $strOrNull =
                        static function ($v) {
                            $v =
                                trim((string)$v);
                            return $v === ""
                                ? null
                                : $v;
                        };

                    if ($contribTitle === "") {
                        $viewError =
                            "Please enter the working title / headline.";
                    } elseif ($contribType === "") {
                        $viewError =
                            "Please enter the type of content.";
                    } elseif (
                        !in_array(
                            $contribChannel,
                            [
                                "Social Media (Facebook)",
                                "Website",
                                "Both"
                            ],
                            true
                        )
                    ) {
                        $viewError =
                            "Please select where the content should be published.";
                    } else {
                        $leadOffice =
                            $strOrNull(
                                $_POST["lead_office"]
                                ?? null
                            );
                        $focalPerson =
                            $strOrNull(
                                $_POST["focal_person"]
                                ?? null
                            );

                        if ($leadOffice === null || $focalPerson === null) {
                            $viewError =
                                "Please provide the lead office and focal person.";
                        } else {
                            $targetAudience =
                                $strOrNull(
                                    $_POST["target_audience"]
                                    ?? null
                                );
                            $purposeObjective =
                                $strOrNull(
                                    $_POST["purpose_objective"]
                                    ?? null
                                );
                            $keyMessage =
                                $strOrNull(
                                    $_POST["key_message"]
                                    ?? null
                                );
                            $socialCaption =
                                $strOrNull(
                                    $_POST["social_media_caption"]
                                    ?? null
                                );
                            $websiteContent =
                                $strOrNull(
                                    $_POST["website_content"]
                                    ?? null
                                );
                            $callToAction =
                                $strOrNull(
                                    $_POST["call_to_action"]
                                    ?? null
                                );
                            $hashtags =
                                $strOrNull(
                                    $_POST["hashtags"]
                                    ?? null
                                );
                            $referenceLinks =
                                $strOrNull(
                                    $_POST["reference_links"]
                                    ?? null
                                );

                            if ($purposeObjective === null || $keyMessage === null) {
                                $viewError =
                                    "Please provide the purpose / objective and key message.";
                            } elseif (
                                in_array(
                                    $contribChannel,
                                    [
                                        "Social Media (Facebook)",
                                        "Both"
                                    ],
                                    true
                                )
                                &&
                                $socialCaption === null
                            ) {
                                $viewError =
                                    "Please provide the proposed social media caption.";
                            } elseif (
                                in_array(
                                    $contribChannel,
                                    [
                                        "Website",
                                        "Both"
                                    ],
                                    true
                                )
                                &&
                                $websiteContent === null
                            ) {
                                $viewError =
                                    "Please provide the proposed website content.";
                            } else {
                                $stmt = $conn->prepare(
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
                                          preferred_publication_date = ?
                                      WHERE id = ?
                                        AND submitted_by = ?"
                                );

                                $stmt->bind_param(
                                    "sssssssssssssssii",
                                    $email,
                                    $contribType,
                                    $contribChannel,
                                    $contribTitle,
                                    $leadOffice,
                                    $focalPerson,
                                    $targetAudience,
                                    $purposeObjective,
                                    $keyMessage,
                                    $socialCaption,
                                    $websiteContent,
                                    $callToAction,
                                    $hashtags,
                                    $referenceLinks,
                                    $pubDate,
                                    $id,
                                    $userId
                                );

                                $stmt->execute();
                                $stmt->close();

                                $record["email_address"] =
                                    $email;
                                $record["contribution_type"] =
                                    $contribType;
                                $record["publication_channel"] =
                                    $contribChannel;
                                $record["content_title"] =
                                    $contribTitle;
                                $record["lead_office"] =
                                    $leadOffice;
                                $record["focal_person"] =
                                    $focalPerson;
                                $record["target_audience"] =
                                    $targetAudience;
                                $record["purpose_objective"] =
                                    $purposeObjective;
                                $record["key_message"] =
                                    $keyMessage;
                                $record["social_media_caption"] =
                                    $socialCaption;
                                $record["website_content"] =
                                    $websiteContent;
                                $record["call_to_action"] =
                                    $callToAction;
                                $record["hashtags"] =
                                    $hashtags;
                                $record["reference_links"] =
                                    $referenceLinks;
                                $record["preferred_publication_date"] =
                                    $pubDate;

                                $title = $contribTitle;
                                $viewSuccess =
                                    "Submission updated successfully.";
                            }
                        }
                    }
                }
            }
        }
    }
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
        Publication Request | HPU Workbench
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
            --ui-primary:#1769ff;
            --ui-primary-dark:#0f4fd6;
            --ui-primary-soft:#eef5ff;
            --ui-success:#16a263;
            --ui-warning:#f59e0b;
            --ui-danger:#dc3545;
            --ui-purple:#7c3aed;
            --ui-text:#10213e;
            --ui-muted:#64748b;
            --ui-border:#dfe7f2;
            --ui-surface:#ffffff;
            --ui-soft:#f7f9fd;
            --ui-shadow:0 14px 36px rgba(16,33,62,.07);
        }
        body{background:#f3f6fb;color:var(--ui-text);}
        .view-page{max-width:1540px;margin:0 auto;}
        .content{padding:20px;}
        .back-link{display:inline-flex;align-items:center;gap:8px;margin-bottom:14px;padding:9px 14px;border:1px solid var(--ui-border);border-radius:10px;background:#fff;color:var(--ui-primary);font-weight:700;text-decoration:none;}
        .back-link:hover{background:var(--ui-primary-soft);color:var(--ui-primary-dark);}
        .request-hero{overflow:hidden;margin-bottom:18px;border:1px solid var(--ui-border);border-radius:18px;background:linear-gradient(135deg,#ffffff 0%,#f7fbff 100%);box-shadow:var(--ui-shadow);}
        .hero-main{display:grid;grid-template-columns:minmax(0,1fr) auto 140px;gap:24px;align-items:center;padding:24px;}
        .hero-eyebrow{margin:0 0 6px;color:var(--ui-primary);font-size:.68rem;font-weight:850;letter-spacing:.07em;text-transform:uppercase;}
        .hero-title{margin:0 0 8px;font-size:clamp(1.4rem,2.4vw,2rem);font-weight:850;letter-spacing:-.025em;line-height:1.15;overflow-wrap:anywhere;}
        .hero-meta{display:flex;align-items:center;flex-wrap:wrap;gap:8px;}
        .job-chip,.type-chip{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:8px;font-size:.75rem;font-weight:800;}
        .job-chip{background:#e8f1ff;color:var(--ui-primary);}
        .type-chip{background:#f2eaff;color:var(--ui-purple);}
        .hero-status{min-width:180px;padding-left:22px;border-left:1px solid var(--ui-border);}
        .eyebrow{color:var(--ui-muted);font-size:.67rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;}
        .hero-status .badge{margin-top:7px;padding:9px 12px;border-radius:999px;}
        .progress-ring{width:106px;height:106px;display:grid;place-items:center;border-radius:50%;background:conic-gradient(var(--ui-primary) calc(var(--progress) * 1%),#e9eef6 0);position:relative;margin-inline:auto;}
        .progress-ring::before{content:"";position:absolute;inset:9px;border-radius:50%;background:#fff;}
        .progress-ring strong{position:relative;z-index:1;font-size:1.4rem;}
        .progress-caption{margin-top:7px;color:var(--ui-muted);font-size:.72rem;font-weight:700;text-align:center;}
        .hero-facts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border-top:1px solid var(--ui-border);background:rgba(255,255,255,.75);}
        .hero-fact{display:grid;grid-template-columns:38px minmax(0,1fr);gap:10px;padding:16px 20px;}
        .hero-fact + .hero-fact{border-left:1px solid var(--ui-border);}
        .fact-icon{width:38px;height:38px;display:grid;place-items:center;border-radius:10px;background:var(--ui-primary-soft);color:var(--ui-primary);}
        .fact-label{color:var(--ui-muted);font-size:.66rem;font-weight:800;text-transform:uppercase;}
        .fact-value{margin-top:2px;font-size:.82rem;font-weight:800;overflow-wrap:anywhere;}
        .fact-note{margin-top:2px;color:var(--ui-success);font-size:.69rem;}
        .workflow-panel{margin-bottom:18px;padding:20px;border:1px solid var(--ui-border);border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(16,33,62,.05);}
        .workflow-track{display:grid;grid-template-columns:repeat(5,minmax(100px,1fr));overflow-x:auto;padding:4px 0 2px;}
        .workflow-step{position:relative;min-width:108px;text-align:center;}
        .workflow-step:not(:last-child)::after{content:"";position:absolute;top:18px;left:58%;right:-42%;height:3px;background:#dce4ef;}
        .workflow-step.done:not(:last-child)::after{background:var(--ui-success);}
        .workflow-node{position:relative;z-index:2;width:38px;height:38px;display:grid;place-items:center;margin:0 auto 8px;border:2px solid #cbd5e1;border-radius:50%;background:#fff;color:#94a3b8;}
        .workflow-step.done .workflow-node{border-color:var(--ui-success);background:var(--ui-success);color:#fff;}
        .workflow-step.current .workflow-node{border-color:var(--ui-primary);background:var(--ui-primary);color:#fff;box-shadow:0 0 0 5px #dbeafe;}
        .workflow-label{font-size:.69rem;font-weight:800;}
        .summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px;}
        .summary-card{display:grid;grid-template-columns:42px minmax(0,1fr);gap:12px;padding:16px;border:1px solid var(--ui-border);border-radius:14px;background:#fff;box-shadow:0 8px 22px rgba(16,33,62,.04);}
        .summary-icon{width:42px;height:42px;display:grid;place-items:center;border-radius:12px;background:var(--ui-primary-soft);color:var(--ui-primary);font-size:1.1rem;}
        .summary-card:nth-child(2) .summary-icon{background:#eafaf2;color:var(--ui-success);}
        .summary-card:nth-child(3) .summary-icon{background:#f3eefe;color:var(--ui-purple);}
        .summary-card:nth-child(4) .summary-icon{background:#fff5e8;color:#ea7200;}
        .summary-label{color:var(--ui-muted);font-size:.66rem;font-weight:800;text-transform:uppercase;}
        .summary-value{margin-top:3px;font-size:.86rem;font-weight:850;overflow-wrap:anywhere;}
        .summary-note{margin-top:3px;color:var(--ui-muted);font-size:.68rem;}
        .card{border:1px solid var(--ui-border);border-radius:16px;background:#fff;box-shadow:0 10px 28px rgba(16,33,62,.05);}
        .section-banner{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--ui-border);border-radius:15px 15px 0 0;background:linear-gradient(90deg,#f0f6ff,#fff);color:#1d4ed8;font-size:.8rem;font-weight:850;letter-spacing:.04em;text-transform:uppercase;}
        .section-heading{display:flex;align-items:center;gap:12px;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid #edf1f6;}
        .section-icon{display:grid;width:38px;height:38px;place-items:center;flex-shrink:0;border:1px solid #cfe0f8;border-radius:10px;background:var(--ui-primary-soft);color:var(--ui-primary);font-size:17px;}
        .detail-box{height:100%;padding:16px;border:1px solid #e5ebf3;border-radius:12px;background:#fafcff;transition:.18s ease;}
        .detail-box:hover{transform:translateY(-1px);border-color:#bdd4ff;background:#fff;box-shadow:0 7px 18px rgba(23,105,255,.06);}
        .detail-label{margin-bottom:7px;color:var(--ui-muted);font-size:.65rem;font-weight:850;letter-spacing:.055em;text-transform:uppercase;}
        .detail-value{color:#10213e;font-size:.82rem;line-height:1.55;white-space:pre-wrap;overflow-wrap:anywhere;}
        .narrative{padding:14px 16px;border:1px solid #e5ebf3;border-radius:12px;background:#fafcff;color:#10213e;font-size:.85rem;line-height:1.6;white-space:pre-wrap;overflow-wrap:anywhere;}
        .status-pill{display:inline-flex;align-items:center;gap:5px;padding:7px 10px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:.66rem;font-weight:850;}
        .file-item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 14px;margin-bottom:8px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;}
        .file-name{font-size:.78rem;font-weight:750;color:#263248;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        .file-meta{font-size:.66rem;color:#98a2b3;}
        #pubConversation{scroll-margin-top:14px;}
        #pubConversation.conv-flash{animation:convFlash 1.8s ease;}
        @keyframes convFlash{
            0%,60%{box-shadow:0 0 0 3px rgba(37,99,235,.35);}
            100%{box-shadow:none;}
        }
        html{scroll-behavior:smooth;}
        .attachment-box{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px;border:1px dashed #8fb4f4;border-radius:12px;background:#f6faff;}
        .action-bar{position:sticky;bottom:12px;z-index:20;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px;border:1px solid var(--ui-border);border-radius:14px;background:rgba(255,255,255,.94);backdrop-filter:blur(12px);box-shadow:0 14px 34px rgba(16,33,62,.14);}
        .action-bar .btn{border-radius:10px;font-weight:750;}
        .locked-note{display:inline-flex;align-items:center;gap:7px;color:var(--ui-muted);font-size:.75rem;font-weight:700;}
        @media(max-width:1100px){
            .hero-main{grid-template-columns:1fr auto;}
            .progress-wrap{grid-column:1 / -1;}
            .hero-facts,.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
            .hero-fact:nth-child(3){border-left:0;border-top:1px solid var(--ui-border);}
            .hero-fact:nth-child(4){border-top:1px solid var(--ui-border);}
        }
        @media(max-width:700px){
            .content{padding:10px;}
            .hero-main{grid-template-columns:1fr;padding:18px;}
            .hero-status{padding-left:0;border-left:0;}
            .hero-facts,.summary-grid{grid-template-columns:1fr;}
            .hero-fact + .hero-fact{border-left:0;border-top:1px solid var(--ui-border);}
            .workflow-panel{padding:15px 10px;}
            .action-bar{position:static;}
        }
        @media print{
            body{background:#fff;}
            .sidebar,.no-print,.back-link,.action-bar{display:none !important;}
            .main{width:100% !important;margin:0 !important;}
            .content{padding:0;}
            .view-page{max-width:none;}
            .card,.request-hero,.workflow-panel,.summary-card{break-inside:avoid;box-shadow:none !important;}
        }
    </style>
</head>

<body class="app-shell">

<div class="d-flex min-vh-100">

<?php include "../includes/sidebar.php"; ?>

<div class="main flex-grow-1">

<main class="content">

<div class="container-fluid">

<div class="view-page">

    <a class="back-link no-print" href="<?= e(BASE_URL) ?>/user/my_requests.php">
        <i class="bi bi-arrow-left"></i>
        Back to My Requests
    </a>

    <section class="request-hero">
        <div class="hero-main">
            <div>
                <p class="hero-eyebrow">
                    My Requests •
                    <?= $kind === "recognition"
                        ? "Recognition / Milestone Publication"
                        : ($kind === "contribution"
                            ? "Content Contribution Publication"
                            : "Event / Activity Publication")
                    ?>
                </p>

                <h1 class="hero-title">
                    <?= e($title) ?>
                </h1>

                <div class="hero-meta">
                    <span class="job-chip" title="<?= e($pubCode) ?>">
                        <i class="bi bi-hash"></i><?= e($pubCode) ?>
                    </span>

                    <span class="type-chip">
                        <i class="bi <?= $kind === "recognition" ? "bi-award" : ($kind === "contribution" ? "bi-megaphone" : "bi-calendar2-event") ?>"></i><?= $kind === "recognition" ? "Recognition / Milestone" : ($kind === "contribution" ? "Content Contribution" : "Event / Activity") ?>
                    </span>

                    <?php if ($readOnlyViewer): ?>
                    <span class="type-chip" title="Submitted by an office colleague — read only">
                        <i class="bi bi-people"></i>Same Office · View only
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="hero-status">
                <div class="eyebrow">Current Status</div>
                <span class="badge <?= e($pubStatusClass) ?>">
                    <i class="bi bi-activity me-1"></i><?= e($status) ?>
                </span>
                <div class="text-muted small mt-2">
                    Request age: <?= $pubAgeDays !== null ? number_format($pubAgeDays) . " day(s)" : "N/A" ?>
                </div>
            </div>

            <div class="progress-wrap">
                <div class="progress-ring" style="--progress: <?= $pubProgress ?>">
                    <strong><?= $pubProgress ?>%</strong>
                </div>
                <div class="progress-caption">Overall Progress</div>
            </div>
        </div>

        <div class="hero-facts">
            <div class="hero-fact">
                <div class="fact-icon"><i class="bi bi-calendar-plus"></i></div>
                <div>
                    <div class="fact-label">Submitted</div>
                    <div class="fact-value"><?= e(viewPubDate($record["created_at"], true)) ?></div>
                </div>
            </div>
            <div class="hero-fact">
                <div class="fact-icon"><i class="bi bi-calendar-check"></i></div>
                <div>
                    <div class="fact-label">Preferred Publication</div>
                    <div class="fact-value"><?= e(viewPubDate($record["preferred_publication_date"] ?? null)) ?></div>
                    <?php if ($pubRemainingDays !== null && !in_array($status, ["Published", "Closed"], true)): ?>
                        <div class="fact-note">
                            <?= $pubRemainingDays >= 0 ? $pubRemainingDays . " day(s) remaining" : abs($pubRemainingDays) . " day(s) past target" ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hero-fact">
                <div class="fact-icon"><i class="bi bi-person"></i></div>
                <div>
                    <div class="fact-label">Submitted By</div>
                    <div class="fact-value"><?= e($_SESSION["name"] ?? "You") ?></div>
                    <div class="text-muted small"><?= e($record["email_address"] ?? "") ?></div>
                </div>
            </div>
            <div class="hero-fact">
                <div class="fact-icon"><i class="bi bi-clipboard2-check"></i></div>
                <div>
                    <div class="fact-label">Evaluation</div>
                    <div class="fact-value"><?= e($pubEvaluationLabel) ?><?= $pubSurveyRating !== null ? " • " . (int)$pubSurveyRating . "/5" : "" ?></div>
                </div>
            </div>
        </div>
    </section>

    <section class="workflow-panel">
        <div class="workflow-track">
            <?php foreach ($pubStages as $stageKey => $stageInfo): ?>
                <?php
                $stageIndex = array_search($stageKey, $pubStageKeys, true);
                $stageDone = $stageIndex < $pubStageIndex || $status === "Closed";
                $stageCurrent = $stageKey === $status;
                ?>
                <div class="workflow-step <?= $stageDone ? "done" : "" ?> <?= $stageCurrent ? "current" : "" ?>">
                    <div class="workflow-node">
                        <i class="bi <?= e($stageInfo["icon"]) ?>"></i>
                    </div>
                    <div class="workflow-label"><?= e($stageInfo["label"]) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($publicationTrackingUrl !== ""): ?>
    <section class="card shadow-sm border-0 mb-3 no-print">
        <div class="card-body d-flex flex-column flex-md-row align-items-md-center gap-2">
            <div class="flex-grow-1">
                <div class="fw-bold">
                    <i class="bi bi-upc-scan me-1"></i>
                    Public Tracking Link
                </div>

                <div class="text-muted small">
                    Share this link — it opens the live publication status without sign-in.
                </div>

                <div class="input-group input-group-sm mt-2">
                    <input
                        type="text"
                        class="form-control"
                        value="<?= e($publicationTrackingUrl) ?>"
                        readonly
                        onclick="this.select()"
                    >

                    <button
                        class="btn btn-outline-primary"
                        type="button"
                        onclick="navigator.clipboard && navigator.clipboard.writeText('<?= e($publicationTrackingUrl) ?>'); this.innerHTML='Copied'; setTimeout(() => { this.innerHTML='Copy'; }, 1600);"
                    >
                        Copy
                    </button>

                    <a
                        class="btn btn-outline-secondary"
                        href="<?= e($publicationTrackingUrl) ?>"
                        target="_blank"
                        rel="noopener"
                    >
                        Open
                    </a>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-icon"><i class="bi bi-person-check"></i></div>
            <div>
                <div class="summary-label">Last Reviewed By</div>
                <div class="summary-value"><?= e($record["reviewer_name"] ?: "Not yet reviewed") ?></div>
                <div class="summary-note">HPU review</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon"><i class="bi bi-paperclip"></i></div>
            <div>
                <div class="summary-label">Supporting Files</div>
                <div class="summary-value"><?= in_array($kind, ["recognition", "contribution"], true) ? count(viewPubFiles($record["supporting_files"] ?? null)) . " file(s)" : "N/A" ?></div>
                <div class="summary-note"><?= $kind === "recognition" ? "Max 5 per submission" : ($kind === "contribution" ? "Max 8 per submission" : "Events carry no files") ?></div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon"><i class="bi bi-calendar-event"></i></div>
            <div>
                <div class="summary-label">Preferred Publication</div>
                <div class="summary-value"><?= e(viewPubDate($record["preferred_publication_date"] ?? null)) ?></div>
                <div class="summary-note"><?= $pubRemainingDays !== null ? ($pubRemainingDays >= 0 ? $pubRemainingDays . " day(s) remaining" : abs($pubRemainingDays) . " day(s) past target") : "No target date" ?></div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon"><i class="bi bi-star"></i></div>
            <div>
                <div class="summary-label">Evaluation Status</div>
                <div class="summary-value"><?= e($pubEvaluationLabel) ?></div>
                <div class="summary-note"><?= $pubSurveySubmitted ? "Feedback received" : "Awaiting user feedback" ?></div>
            </div>
        </div>
    </div>

    <?php if ($viewError !== ""): ?>

    <div class="alert alert-danger alert-dismissible fade show">
        <?= e($viewError) ?>
        <button class="btn-close" type="button" data-bs-dismiss="alert"></button>
    </div>

    <?php endif; ?>

    <?php if ($viewSuccess !== ""): ?>

    <div class="alert alert-success alert-dismissible fade show">
        <?= e($viewSuccess) ?>
        <button class="btn-close" type="button" data-bs-dismiss="alert"></button>
    </div>

    <?php endif; ?>

    <?php if (!$isEditable): ?>

    <div class="alert alert-secondary d-flex align-items-center gap-2">
        <i class="bi bi-lock-fill"></i>
        <span>
            This submission is
            <strong><?= e($status) ?></strong>
            and can no longer be modified.
        </span>
    </div>

    <?php endif; ?>

    <?php if ($isEditable): ?>

    <?php
    $editRecTypes = [];

    if ($kind === "recognition") {
        $decodedTypes = json_decode(
            (string)($record["recognition_type"] ?? ""),
            true
        );

        if (is_array($decodedTypes)) {
            $editRecTypes = array_map(
                "strval",
                $decodedTypes
            );
        } elseif (
            trim(
                (string)(
                    $record["recognition_type"]
                    ?? ""
                )
            ) !== ""
        ) {
            $editRecTypes = [
                (string)$record["recognition_type"]
            ];
        }
    }
    ?>

    <div class="collapse<?= ($isEditable && ((($_GET["edit"] ?? "") === "1") || $justPosted)) ? " show" : "" ?>" id="ownerEditSection">
    <section class="card mb-4 border-primary">
        <div class="section-banner">
            <i class="bi bi-pencil-square"></i>
            Edit Submission
        </div>
        <div class="card-body">

            <form method="post">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($viewCsrf) ?>"
                >

                <input
                    type="hidden"
                    name="owner_action"
                    value="update"
                >

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="detail-label">Email Address *</label>
                        <input
                            type="email"
                            class="form-control form-control-sm"
                            name="email_address"
                            value="<?= e($record["email_address"] ?? "") ?>"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="detail-label">Preferred Publication Date</label>
                        <input
                            type="date"
                            class="form-control form-control-sm"
                            name="preferred_publication_date"
                            value="<?= e(substr((string)($record["preferred_publication_date"] ?? ""), 0, 10)) ?>"
                        >
                    </div>

                    <?php if ($kind === "recognition"): ?>

                    <div class="col-12">
                        <label class="detail-label">Recognition / Milestone Type *</label>

                        <?php foreach ($recognitionTypeOptions as $rtype): ?>
                        <label class="form-check">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="recognition_types[]"
                                value="<?= e($rtype) ?>"
                                <?= in_array($rtype, $editRecTypes, true) ? "checked" : "" ?>
                            >
                            <span class="form-check-label"><?= e($rtype) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="col-12">
                        <label class="detail-label">Who or What Was Recognized? *</label>
                        <input
                            class="form-control form-control-sm"
                            name="recognized_entity"
                            value="<?= e($record["recognized_entity"] ?? "") ?>"
                            required
                        >
                    </div>

                    <div class="col-12">
                        <label class="detail-label">What Was Achieved? *</label>
                        <textarea
                            class="form-control form-control-sm"
                            name="achievement"
                            rows="4"
                            required
                        ><?= e($record["achievement"] ?? "") ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="detail-label">Supporting Information</label>
                        <textarea
                            class="form-control form-control-sm"
                            name="supporting_information"
                            rows="3"
                        ><?= e($record["supporting_information"] ?? "") ?></textarea>
                    </div>

                    <?php elseif ($kind === "event"): ?>

                    <div class="col-12">
                        <label class="detail-label">Activity Title *</label>
                        <input
                            class="form-control form-control-sm"
                            name="title"
                            value="<?= e($record["title"] ?? "") ?>"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="detail-label">Type of Activity *</label>
                        <input
                            class="form-control form-control-sm"
                            name="activity_type"
                            value="<?= e($record["activity_type"] ?? "") ?>"
                            required
                        >
                    </div>

                    <div class="col-md-3">
                        <label class="detail-label">Start Date</label>
                        <input
                            type="date"
                            class="form-control form-control-sm"
                            name="start_date"
                            value="<?= e(substr((string)($record["start_date"] ?? ""), 0, 10)) ?>"
                        >
                    </div>

                    <div class="col-md-3">
                        <label class="detail-label">End Date</label>
                        <input
                            type="date"
                            class="form-control form-control-sm"
                            name="end_date"
                            value="<?= e(substr((string)($record["end_date"] ?? ""), 0, 10)) ?>"
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="detail-label">Time</label>
                        <input
                            class="form-control form-control-sm"
                            name="activity_time"
                            value="<?= e($record["activity_time"] ?? "") ?>"
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="detail-label">Venue</label>
                        <input
                            class="form-control form-control-sm"
                            name="venue"
                            value="<?= e($record["venue"] ?? "") ?>"
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="detail-label">Lead Office / Unit</label>
                        <input
                            class="form-control form-control-sm"
                            name="lead_office"
                            value="<?= e($record["lead_office"] ?? "") ?>"
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="detail-label">Person-in-Charge</label>
                        <input
                            class="form-control form-control-sm"
                            name="person_in_charge"
                            value="<?= e($record["person_in_charge"] ?? "") ?>"
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="detail-label">Partner Offices / Organizations</label>
                        <textarea
                            class="form-control form-control-sm"
                            name="partner_offices"
                            rows="2"
                        ><?= e($record["partner_offices"] ?? "") ?></textarea>
                    </div>

                    <div class="col-md-6">
                        <label class="detail-label">Participants (count)</label>
                        <input
                            type="number"
                            min="0"
                            class="form-control form-control-sm"
                            name="participant_count"
                            value="<?= e((string)($record["participant_count"] ?? "")) ?>"
                        >
                    </div>

                    <?php
                    $ownerNarratives = [
                        "background" => "Background",
                        "objectives" => "Objectives",
                        "activity_highlights" => "Activity Highlights",
                        "key_outputs" => "Key Outputs / Agreements",
                        "impact_significance" => "Impact / Significance",
                        "quotable_statement" => "Quotable Statement",
                        "remembrance_sentence" => "How the Activity Should Be Remembered"
                    ];
                    ?>

                    <?php foreach ($ownerNarratives as $fname => $flabel): ?>
                    <div class="col-12">
                        <label class="detail-label"><?= e($flabel) ?></label>
                        <textarea
                            class="form-control form-control-sm"
                            name="<?= e($fname) ?>"
                            rows="2"
                        ><?= e($record[$fname] ?? "") ?></textarea>
                    </div>
                    <?php endforeach; ?>

                    <?php else: ?>

                    <div class="col-12">
                        <label class="detail-label">Working Title / Headline *</label>
                        <input
                            class="form-control form-control-sm"
                            name="content_title"
                            value="<?= e($record["content_title"] ?? "") ?>"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="detail-label">Type of Content *</label>
                        <input
                            class="form-control form-control-sm"
                            name="contribution_type"
                            value="<?= e($record["contribution_type"] ?? "") ?>"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="detail-label">Publication Channel *</label>
                        <select
                            class="form-select form-select-sm"
                            name="publication_channel"
                            required
                        >
                            <?php foreach (["Social Media (Facebook)", "Website", "Both"] as $channelOption): ?>
                            <option
                                value="<?= e($channelOption) ?>"
                                <?= ($record["publication_channel"] ?? "") === $channelOption ? "selected" : "" ?>
                            >
                                <?= e($channelOption) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="detail-label">Requesting / Lead Office *</label>
                        <input
                            class="form-control form-control-sm"
                            name="lead_office"
                            value="<?= e($record["lead_office"] ?? "") ?>"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="detail-label">Focal Person / Contributor *</label>
                        <input
                            class="form-control form-control-sm"
                            name="focal_person"
                            value="<?= e($record["focal_person"] ?? "") ?>"
                            required
                        >
                    </div>

                    <div class="col-12">
                        <label class="detail-label">Target Audience</label>
                        <input
                            class="form-control form-control-sm"
                            name="target_audience"
                            value="<?= e($record["target_audience"] ?? "") ?>"
                        >
                    </div>

                    <?php
                    $ownerContributionFields = [
                        "purpose_objective" => "Purpose / Objective *",
                        "key_message" => "Key Message / Important Information *",
                        "social_media_caption" => "Proposed Social Media Caption",
                        "website_content" => "Proposed Website Content / Article Body",
                        "call_to_action" => "Call to Action",
                        "hashtags" => "Hashtags",
                        "reference_links" => "Reference Links"
                    ];
                    ?>

                    <?php foreach ($ownerContributionFields as $fname => $flabel): ?>
                    <div class="col-12">
                        <label class="detail-label"><?= e($flabel) ?></label>
                        <textarea
                            class="form-control form-control-sm"
                            name="<?= e($fname) ?>"
                            rows="2"
                        ><?= e($record[$fname] ?? "") ?></textarea>
                    </div>
                    <?php endforeach; ?>

                    <?php endif; ?>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button
                        class="btn btn-sm btn-outline-secondary"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#ownerEditSection"
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
    </section>
    </div>

    <?php endif; ?>

    <section class="card mb-4">
        <div class="section-banner">
            <i class="bi bi-info-circle"></i>
            Submission Information
        </div>
        <div class="card-body">

            <div class="row g-3">

                <div class="col-md-6">
                    <div class="detail-box">
                        <div class="detail-label">
                            Email Address
                        </div>

                        <div class="detail-value">
                            <?= e($record["email_address"]) ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-box">
                        <div class="detail-label">
                            Last Reviewed By
                        </div>

                        <div class="detail-value">
                            <?= e(
                                $record["reviewer_name"]
                                ?: "Not yet reviewed"
                            ) ?>
                        </div>
                    </div>
                </div>

            </div>

            <?php if (
                trim(
                    (string)(
                        $record["publication_notes"]
                        ?? ""
                    )
                )
                !==
                ""
            ): ?>

            <div class="alert alert-info mt-3 mb-0">
                <strong>HPU Publication Notes:</strong><br>
                <?= nl2br(
                    e(
                        $record["publication_notes"]
                    )
                ) ?>
            </div>

            <?php endif; ?>

        </div>
    </section>

    <?php if ($kind === "event"): ?>

    <section class="card mb-4">
        <div class="section-banner">
            <i class="bi bi-calendar2-event"></i>
            Activity Profile
        </div>
        <div class="card-body">

            <div class="row g-3">

                <?php
                $fields = [
                    "Type of Activity" =>
                        $record["activity_type"],

                    "Start Date" =>
                        viewPubDate(
                            $record["start_date"]
                            ?? null
                        ),

                    "End Date" =>
                        viewPubDate(
                            $record["end_date"]
                            ?? null
                        ),

                    "Time" =>
                        $record["activity_time"]
                        ?: "—",

                    "Venue" =>
                        $record["venue"]
                        ?: "—",

                    "Lead Office / Unit / Committee Division" =>
                        $record["lead_office"]
                        ?: "—",

                    "Partner Office(s) / Organization(s)" =>
                        $record["partner_offices"]
                        ?: "—",

                    "Person-in-Charge" =>
                        $record["person_in_charge"]
                        ?: "—",

                    "Participants" =>
                        $record["participant_count"] !== null
                            ? (string)$record["participant_count"]
                            : "—"
                ];
                ?>

                <?php foreach ($fields as $label => $value): ?>

                <div class="col-md-6">
                    <div class="detail-box">
                        <div class="detail-label">
                            <?= e($label) ?>
                        </div>

                        <div class="detail-value">
                            <?= e((string)$value) ?>
                        </div>
                    </div>
                </div>

                <?php endforeach; ?>

            </div>

        </div>
    </section>

    <?php
    $narratives = [
        "Background" =>
            $record["background"],

        "Objectives" =>
            $record["objectives"],

        "Activity Highlights" =>
            $record["activity_highlights"],

        "Key Outputs / Agreements" =>
            $record["key_outputs"],

        "Impact / Significance" =>
            $record["impact_significance"],

        "Quotable Statement" =>
            $record["quotable_statement"],

        "How the Activity Should Be Remembered" =>
            $record["remembrance_sentence"]
    ];
    ?>

    <?php
    $filledNarratives = [];

    foreach ($narratives as $label => $value) {
        if (
            trim(
                (string)$value
            )
            !==
            ""
        ) {
            $filledNarratives[$label] = $value;
        }
    }
    ?>

    <?php if ($filledNarratives): ?>

    <section class="card mb-4">
        <div class="section-banner">
            <i class="bi bi-journal-text"></i>
            Documentation
        </div>
        <div class="card-body">

            <?php foreach ($filledNarratives as $label => $value): ?>

            <div class="section-heading">
                <div class="section-icon">
                    <i class="bi bi-file-text"></i>
                </div>

                <div class="detail-label mb-0">
                    <?= e($label) ?>
                </div>
            </div>

            <div class="narrative mb-3">
                <?= e((string)$value) ?>
            </div>

            <?php endforeach; ?>

        </div>
    </section>

    <?php endif; ?>

    <?php elseif ($kind === "recognition"): ?>

    <section class="card mb-4">
        <div class="section-banner">
            <i class="bi bi-award"></i>
            Recognition / Milestone Details
        </div>
        <div class="card-body">

            <div class="row g-3">

                <div class="col-md-6">
                    <div class="detail-box">
                        <div class="detail-label">
                            Type
                        </div>

                        <div class="detail-value">
                            <?= e(
                                viewPubTypes(
                                    $record["recognition_type"]
                                    ?? null
                                )
                            ) ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="detail-box">
                        <div class="detail-label">
                            Who or What Was Recognized?
                        </div>

                        <div class="detail-value">
                            <?= e($record["recognized_entity"]) ?>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="detail-box">
                        <div class="detail-label">
                            What Was Achieved?
                        </div>

                        <div class="detail-value">
                            <?= e($record["achievement"]) ?>
                        </div>
                    </div>
                </div>

                <?php if (
                    trim(
                        (string)
                        $record["supporting_information"]
                    )
                    !==
                    ""
                ): ?>

                <div class="col-12">
                    <div class="detail-box">
                        <div class="detail-label">
                            Supporting Information
                        </div>

                        <div class="detail-value">
                            <?= e($record["supporting_information"]) ?>
                        </div>
                    </div>
                </div>

                <?php endif; ?>

            </div>

        </div>
    </section>

    <?php
    $files =
        viewPubFiles(
            $record["supporting_files"]
            ?? null
        );
    ?>

    <?php if ($files): ?>

    <section class="card mb-4">
        <div class="section-banner">
            <i class="bi bi-paperclip"></i>
            Supporting Files (<?= count($files) ?>/5)
        </div>
        <div class="card-body">

            <?php foreach ($files as $index => $file): ?>

            <div class="file-item">

                <div class="min-w-0">

                    <div class="file-name">
                        <i class="bi bi-paperclip me-1"></i>
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
                                viewPubFileSize(
                                    (int)
                                    $file["size"]
                                )
                            ) ?>

                        <?php endif; ?>
                    </div>

                </div>

                <div class="d-flex gap-1 flex-shrink-0">
                    <a
                        class="btn btn-sm btn-outline-primary"
                        href="<?= e(BASE_URL) ?>/user/view_publication.php?kind=recognition&id=<?= (int)$record["id"] ?>&download=<?= (int)$index ?>"
                        title="Download file"
                    >
                        <i class="bi bi-download"></i>
                    </a>

                    <?php if ($isEditable): ?>

                    <form
                        method="post"
                        onsubmit="return confirm('Delete this supporting file?');"
                    >
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($viewCsrf) ?>"
                        >

                        <input
                            type="hidden"
                            name="owner_action"
                            value="delete_file"
                        >

                        <input
                            type="hidden"
                            name="file_index"
                            value="<?= (int)$index ?>"
                        >

                        <button
                            class="btn btn-sm btn-outline-danger"
                            type="submit"
                            title="Delete file"
                        >
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>

                    <?php endif; ?>
                </div>

            </div>

            <?php endforeach; ?>

            <?php if ($isEditable && count($files) < 5): ?>

            <div class="attachment-box mt-2">
                <div class="attachment-info flex-grow-1">
                    <div class="attachment-name">
                        Add supporting files
                    </div>
                    <div class="file-meta">
                        PDF, Word, JPG, PNG, WEBP • max 10 MB each
                    </div>
                </div>
            </div>

            <form
                method="post"
                enctype="multipart/form-data"
                class="mt-2"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($viewCsrf) ?>"
                >

                <input
                    type="hidden"
                    name="owner_action"
                    value="add_files"
                >

                <div class="d-flex gap-2 mt-1">
                    <input
                        type="file"
                        class="form-control form-control-sm"
                        name="supporting_files[]"
                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp"
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
    </section>

    <?php endif; ?>

    <?php endif; ?>

    <?php if ($isOwner): ?>
    <!-- Publication Chat Modal -->
    <div class="modal fade" id="pubConversationModal" tabindex="-1" aria-labelledby="pubConversationModalLabel"
        aria-hidden="true" data-bs-backdrop="false">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pubConversationModalLabel"><i class="bi bi-chat-dots me-2"></i>Communication
                        Center
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php
                    $pubKind = $kind;
                    $pubId = (int) $id;
                    include "../includes/publication_chat_box.php";
                    ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="action-bar no-print">
        <div class="d-flex gap-2 flex-wrap">
            <a
                class="btn btn-outline-secondary"
                href="<?= e(BASE_URL) ?>/user/my_requests.php"
            >
                <i class="bi bi-arrow-left me-1"></i>
                My Requests
            </a>

            <?php
            $pubChatUnread = 0;
            try {
                $chkTbl = $conn->query("SHOW TABLES LIKE 'publication_messages'");
                if ($chkTbl && $chkTbl->num_rows > 0) {
                    $lastReadPub = 0;
                    $chkReads = $conn->query("SHOW TABLES LIKE 'publication_message_reads'");
                    if ($chkReads && $chkReads->num_rows > 0) {
                        $rs = $conn->prepare("SELECT last_read_message_id FROM publication_message_reads WHERE kind = ? AND publication_id = ? AND user_id = ? LIMIT 1");
                        if ($rs) {
                            $rs->bind_param("sii", $kind, $id, $userId);
                            $rs->execute();
                            $rr = $rs->get_result()->fetch_assoc();
                            $rs->close();
                            $lastReadPub = (int) ($rr["last_read_message_id"] ?? 0);
                        }
                    }
                    $cs = $conn->prepare("SELECT COUNT(*) AS c FROM publication_messages WHERE kind = ? AND publication_id = ? AND is_internal = 0 AND id > ?");
                    if ($cs) {
                        $cs->bind_param("sii", $kind, $id, $lastReadPub);
                        $cs->execute();
                        $cr = $cs->get_result()->fetch_assoc();
                        $cs->close();
                        $pubChatUnread = (int) ($cr["c"] ?? 0);
                    }
                }
            } catch (Throwable $e) {
                $pubChatUnread = 0;
            }
            ?>
            <?php if ($isOwner): ?>
            <button
                type="button"
                class="btn btn-outline-primary position-relative"
                data-bs-toggle="modal"
                data-bs-target="#pubConversationModal"
            >
                <i class="bi bi-chat-dots me-1"></i>
                Conversation
                <?php if ($pubChatUnread > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                        <?= $pubChatUnread > 9 ? "9+" : (int) $pubChatUnread ?>
                    </span>
                <?php endif; ?>
            </button>
            <?php endif; ?>
            <?php if ($isEditable): ?>

            <button
                class="btn btn-primary"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#ownerEditSection"
            >
                <i class="bi bi-pencil-square me-1"></i>
                Edit Submission
            </button>

            <?php else: ?>

            <span class="locked-note">
                <i class="bi bi-lock-fill"></i>
                <?= e($status) ?> — locked
            </span>

            <?php endif; ?>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <?php if ($canEvaluatePub): ?>

            <a
                class="btn btn-success"
                href="<?= e($pubSurveyUrl) ?>"
            >
                <i class="bi bi-clipboard2-check me-1"></i>
                Evaluate
            </a>

            <?php elseif ($pubSurveySubmitted && $isOwner): ?>

            <a
                class="btn btn-outline-success"
                href="<?= e($pubSurveyUrl) ?>"
            >
                <i class="bi bi-eye me-1"></i>
                View Evaluation
            </a>

            <?php endif; ?>
        </div>
    </div>

</div>

</div>

</main>

</div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
></script>

<script src="<?= e(BASE_URL) ?>/assets/js/publication_chat.js"></script>

<script>
// Deep link: #pubConversation opens the Communication Center modal.
document.addEventListener("DOMContentLoaded", function () {
    if ((window.location.hash || "").toLowerCase() !== "#pubconversation") {
        return;
    }

    const modalEl = document.getElementById("pubConversationModal");

    if (modalEl && window.bootstrap && window.bootstrap.Modal) {
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
});
</script>

</body>
</html>
