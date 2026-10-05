<?php
/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - SHARED PUBLICATION PRODUCTION-TEAM HELPERS
|--------------------------------------------------------------------------
| Who makes the layout / video, drafts the final posting, and who
| approves it. One row per (kind, publication_id, task_role) in
| `publication_task_assignments` (auto-created, no migration needed).
|
| Required by:
|   staff/publication_requests.php (assign UI + cards)
|   my_assigned_jobs.php           ("Production Tasks" section)
|--------------------------------------------------------------------------
*/

function pubTaskRoles(): array
{
    return [
        "layout" => [
            "label" => "Layout Artist",
            "icon" => "bi-palette-fill",
            "desc" => "Makes the layout / graphics",
            "approver_only" => false,
        ],

        "video" => [
            "label" => "Video Editor",
            "icon" => "bi-camera-reels-fill",
            "desc" => "Produces / edits the video",
            "approver_only" => false,
        ],

        "drafting" => [
            "label" => "Posting Drafter",
            "icon" => "bi-pencil-square",
            "desc" => "Drafts the final posting / caption",
            "approver_only" => false,
        ],

        "approval" => [
            "label" => "Supervisor Approver",
            "icon" => "bi-patch-check-fill",
            "desc" => "Reviews and approves for posting",
            "approver_only" => true,
        ],
    ];
}

function pubTaskRoleMeta(string $role): array
{
    $roles = pubTaskRoles();

    return $roles[$role] ?? [
        "label" => ucfirst($role),
        "icon" => "bi-person",
        "desc" => "",
        "approver_only" => false,
    ];
}

function pubEnsureTaskTable(
    mysqli $conn
): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS publication_task_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(20) NOT NULL,
            publication_id INT NOT NULL,
            task_role VARCHAR(20) NOT NULL,
            assigned_to INT NOT NULL,
            assigned_by INT NOT NULL DEFAULT 0,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pub_task (kind, publication_id, task_role),
            INDEX idx_pub_task_lookup (kind, publication_id),
            INDEX idx_pub_task_user (assigned_to)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS publication_task_approvals (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(20) NOT NULL,
            publication_id INT NOT NULL,
            task_role VARCHAR(20) NOT NULL,
            approved_by INT NOT NULL,
            approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pub_approval (kind, publication_id, task_role),
            INDEX idx_pub_approval_lookup (kind, publication_id),
            INDEX idx_pub_approval_by (approved_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS publication_material_content (
            kind VARCHAR(20) NOT NULL,
            publication_id INT NOT NULL,
            draft_text TEXT NOT NULL,
            final_text TEXT NOT NULL,
            links JSON NULL,
            material_files LONGTEXT NULL,
            updated_by INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (kind, publication_id),
            INDEX idx_pub_material_lookup (kind, publication_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/*
| Approvable production tasks: Layout, Video/Reels, Posting Draft.
| The Supervisor Approver seat itself is not approvable here.
*/
function pubApprovableTaskRoles(): array
{
    return ["layout", "video", "drafting"];
}

/*
| role => ["approved_by" => int, "approver_name" => string, "approved_at" => string]
| for one submission. Empty array when nothing approved yet.
*/
function pubTaskApprovals(
    mysqli $conn,
    string $kind,
    int $publicationId
): array {
    $out = [];

    if (
        !in_array(
            $kind,
            ["event", "recognition", "contribution"],
            true
        )
        || $publicationId <= 0
    ) {
        return $out;
    }

    try {
        $stmt =
            $conn->prepare(
                "SELECT a.task_role, a.approved_by, a.approved_at,
                        u.name AS approver_name
                   FROM publication_task_approvals a
                   LEFT JOIN users u
                     ON u.id = a.approved_by
                   WHERE a.kind = ?
                     AND a.publication_id = ?"
            );

        if (!$stmt) {
            return $out;
        }

        $stmt->bind_param(
            "si",
            $kind,
            $publicationId
        );

        $stmt->execute();

        foreach (
            $stmt
                ->get_result()
                ->fetch_all(MYSQLI_ASSOC) as $row
        ) {
            $out[(string) $row["task_role"]] = [
                "approved_by" =>
                    (int) $row["approved_by"],

                "approver_name" =>
                    (string) (
                        $row["approver_name"]
                        ?? ""
                    ),

                "approved_at" =>
                    (string) (
                        $row["approved_at"]
                        ?? ""
                    ),
            ];
        }

        $stmt->close();
    } catch (Throwable $e) {
        // Table missing (very old install): treat as nothing approved.
    }

    return $out;
}

/*
| role => ["user_id" => int, "name" => string] for one submission.
*/
function pubTaskAssignments(
    mysqli $conn,
    string $kind,
    int $publicationId
): array {
    $out = [];

    if (
        !in_array(
            $kind,
            ["event", "recognition", "contribution"],
            true
        )
        || $publicationId <= 0
    ) {
        return $out;
    }

    try {
        $stmt =
            $conn->prepare(
                "SELECT a.task_role, a.assigned_to, u.name AS assignee_name
                  FROM publication_task_assignments a
                  LEFT JOIN users u
                    ON u.id = a.assigned_to
                  WHERE a.kind = ?
                    AND a.publication_id = ?"
            );

        if (!$stmt) {
            return $out;
        }

        $stmt->bind_param(
            "si",
            $kind,
            $publicationId
        );

        $stmt->execute();

        foreach (
            $stmt
                ->get_result()
                ->fetch_all(MYSQLI_ASSOC) as $taskRow
        ) {
            $out[(string) $taskRow["task_role"]] = [
                "user_id" =>
                    (int) $taskRow["assigned_to"],

                "name" =>
                    (string) (
                        $taskRow["assignee_name"]
                        ?? ""
                    ),
            ];
        }

        $stmt->close();
    } catch (Throwable $e) {
        // Table missing (very old install): treat as unassigned.
    }

    return $out;
}

/*
| All task assignments for a batch of (kind, id) pairs in ONE query,
| keyed "kind:id" => [role => ["user_id", "name"]]. Used by list cards.
*/
function pubTaskAssignmentsBatch(
    mysqli $conn,
    array $pairs
): array {
    $out = [];

    $conds = [];

    foreach ($pairs as $pair) {
        [$pairKind, $pairId] = $pair;
        $pairId = (int) $pairId;

        if (
            !in_array(
                $pairKind,
                ["event", "recognition", "contribution"],
                true
            )
            || $pairId <= 0
        ) {
            continue;
        }

        $conds[] =
            "(a.kind = '"
            . $conn->real_escape_string($pairKind)
            . "' AND a.publication_id = "
            . $pairId
            . ")";
    }

    if (!$conds) {
        return $out;
    }

    try {
        $res = $conn->query(
            "SELECT a.kind, a.publication_id, a.task_role,
                    a.assigned_to, u.name AS assignee_name
              FROM publication_task_assignments a
              LEFT JOIN users u
                ON u.id = a.assigned_to
              WHERE "
            . implode(" OR ", $conds)
        );

        if ($res) {
            foreach (
                $res->fetch_all(MYSQLI_ASSOC) as $taskRow
            ) {
                $key =
                    (string) $taskRow["kind"]
                    . ":"
                    . (int) $taskRow["publication_id"];

                $out[$key][(string) $taskRow["task_role"]] = [
                    "user_id" =>
                        (int) $taskRow["assigned_to"],

                    "name" =>
                        (string) (
                            $taskRow["assignee_name"]
                            ?? ""
                        ),
                ];
            }
        }
    } catch (Throwable $e) {
        // Table missing: treat everything as unassigned.
    }

    return $out;
}

/*
| All task approvals for a batch of (kind, id) pairs in ONE query,
| keyed "kind:id" => [role => ["approved_by", "approver_name", "approved_at"]].
| Used by list cards for the approval progress pill.
*/
function pubTaskApprovalsBatch(
    mysqli $conn,
    array $pairs
): array {
    $out = [];

    $conds = [];

    foreach ($pairs as $pair) {
        [$pairKind, $pairId] = $pair;
        $pairId = (int) $pairId;

        if (
            !in_array(
                $pairKind,
                ["event", "recognition", "contribution"],
                true
            )
            || $pairId <= 0
        ) {
            continue;
        }

        $conds[] =
            "(a.kind = '"
            . $conn->real_escape_string($pairKind)
            . "' AND a.publication_id = "
            . $pairId
            . ")";
    }

    if (!$conds) {
        return $out;
    }

    try {
        $res = $conn->query(
            "SELECT a.kind, a.publication_id, a.task_role,
                    a.approved_by, a.approved_at, u.name AS approver_name
               FROM publication_task_approvals a
               LEFT JOIN users u
                 ON u.id = a.approved_by
               WHERE "
            . implode(" OR ", $conds)
        );

        if ($res) {
            foreach (
                $res->fetch_all(MYSQLI_ASSOC) as $taskRow
            ) {
                $key =
                    (string) $taskRow["kind"]
                    . ":"
                    . (int) $taskRow["publication_id"];

                $out[$key][(string) $taskRow["task_role"]] = [
                    "approved_by" =>
                        (int) $taskRow["approved_by"],

                    "approver_name" =>
                        (string) (
                            $taskRow["approver_name"]
                            ?? ""
                        ),

                    "approved_at" =>
                        (string) (
                            $taskRow["approved_at"]
                            ?? ""
                        ),
                ];
            }
        }
    } catch (Throwable $e) {
        // Table missing: treat everything as unapproved.
    }

    return $out;
}
function pubStaffPool(
    mysqli $conn,
    bool $approversOnly = false
): array {
    $pool = [];

    try {
        $res = $conn->query(
            $approversOnly
                ? "SELECT id, name, role
                    FROM users
                    WHERE role IN ('supervisor', 'admin')
                      AND status = 'Active'
                    ORDER BY name"
                : "SELECT id, name, role
                    FROM users
                    WHERE role IN ('staff', 'supervisor', 'admin')
                      AND status = 'Active'
                    ORDER BY name"
        );

        if ($res) {
            $pool =
                $res->fetch_all(
                    MYSQLI_ASSOC
                );
        }
    } catch (Throwable $e) {
        $pool = [];
    }

    return $pool;
}

/*
| Every production task assigned to one user, newest first, each row:
| kind, publication_id, task_role, assigned_at, display_title, status,
| preferred_publication_date, created_at.
*/
function pubMyTasks(
    mysqli $conn,
    int $userId
): array {
    $tasks = [];

    if ($userId <= 0) {
        return $tasks;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT kind, publication_id, task_role, assigned_at
              FROM publication_task_assignments
              WHERE assigned_to = ?
              ORDER BY assigned_at DESC, id DESC"
        );

        if (!$stmt) {
            return $tasks;
        }

        $stmt->bind_param("i", $userId);
        $stmt->execute();

        $rows =
            $stmt
                ->get_result()
                ->fetch_all(MYSQLI_ASSOC);

        $stmt->close();
    } catch (Throwable $e) {
        // Table missing: no production tasks yet.
        return $tasks;
    }

    if (!$rows) {
        return $tasks;
    }

    $idsByKind = [
        "event" => [],
        "recognition" => [],
        "contribution" => [],
    ];

    foreach ($rows as $row) {
        $kind = (string) ($row["kind"] ?? "");
        $pid = (int) ($row["publication_id"] ?? 0);

        if (
            isset($idsByKind[$kind])
            && $pid > 0
        ) {
            $idsByKind[$kind][$pid] = true;
        }
    }

    $meta = [];

    $sources = [
        "event" => [
            "table" => "publication_event_submissions",
            "title" => "title",
        ],
        "recognition" => [
            "table" => "publication_recognition_submissions",
            "title" => "recognized_entity",
        ],
        "contribution" => [
            "table" => "publication_content_contributions",
            "title" => "content_title",
        ],
    ];

    foreach ($sources as $kind => $source) {
        if (empty($idsByKind[$kind])) {
            continue;
        }

        $idList =
            implode(
                ",",
                array_map("intval", array_keys($idsByKind[$kind]))
            );

        try {
            $res = $conn->query(
                "SELECT id,
                        `{$source["title"]}` AS display_title,
                        status,
                        preferred_publication_date,
                        created_at
                  FROM `{$source["table"]}`
                  WHERE id IN ({$idList})"
            );

            if ($res) {
                foreach (
                    $res->fetch_all(MYSQLI_ASSOC) as $detailRow
                ) {
                    $meta[$kind . ":" . (int) $detailRow["id"]] =
                        $detailRow;
                }
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    foreach ($rows as $row) {
        $kind = (string) ($row["kind"] ?? "");
        $pid = (int) ($row["publication_id"] ?? 0);
        $info = $meta[$kind . ":" . $pid] ?? null;

        // Submission deleted after assignment: skip the orphan.
        if (!$info) {
            continue;
        }

        $tasks[] = [
            "kind" => $kind,
            "publication_id" => $pid,
            "task_role" => (string) ($row["task_role"] ?? ""),
            "assigned_at" => (string) ($row["assigned_at"] ?? ""),
            "display_title" =>
                (string) ($info["display_title"] ?? "Untitled Submission"),
            "status" =>
                (string) ($info["status"] ?? ""),
            "preferred_publication_date" =>
                $info["preferred_publication_date"] ?? null,
            "created_at" =>
                (string) ($info["created_at"] ?? ""),
        ];
    }

    return $tasks;
}

/* Draft / Final paragraph text (500 chars max) + up to 3 publication links. */
function pubMaterialMaxChars(): int
{
    return 10000;
}

function pubMaterialMaxLinks(): int
{
    return 3;
}

function pubMaterialLinkTypes(): array
{
    return [
        "Website",
        "Facebook",
        "YouTube",
        "Instagram",
        "TikTok",
        "Other",
    ];
}

/* HPU production file outputs (staff uploads, separate from requestor files). */
function pubMaterialFileKinds(): array
{
    return [
        "Layout",
        "Video/Reels",
        "Posting Draft",
        "Other",
    ];
}

/* Per-output upload rules: [extensions, maxBytes, allowDriveLink, acceptAttr, helpText]. */
function pubMaterialUploadRules(string $material): array
{
    switch ($material) {
        case "Video/Reels":
            return [
                ["mp4", "mov"],
                500 * 1024 * 1024,
                true,
                ".mp4,.mov",
                "MP4, MOV • up to 500 MB — or paste a Google Drive link below",
            ];

        case "Posting Draft":
            return [
                ["doc", "docx", "xls", "xlsx", "pdf"],
                10 * 1024 * 1024,
                true,
                ".doc,.docx,.xls,.xlsx,.pdf",
                "Word, Excel, PDF • up to 10 MB — or paste a Google Drive link below",
            ];

        case "Layout":
            return [
                ["pdf", "jpg", "jpeg", "png", "webp"],
                500 * 1024 * 1024,
                true,
                ".pdf,.jpg,.jpeg,.png,.webp",
                "PDF, JPG, PNG, WEBP • up to 500 MB — or paste a Google Drive link below",
            ];

        default:
            return [
                ["pdf", "doc", "docx", "jpg", "jpeg", "png", "webp", "mp4", "mov"],
                10 * 1024 * 1024,
                false,
                ".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp,.mp4,.mov",
                "PDF, Word, JPG, PNG, WEBP, MP4, MOV • up to 10 MB each",
            ];
    }
}

function pubMaterialMaxFiles(): int
{
    return 10;
}

function pubStaffMaterialStorageDir(): string
{
    $dir =
        dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . "storage"
        . DIRECTORY_SEPARATOR
        . "publication_submissions"
        . DIRECTORY_SEPARATOR
        . "staff_materials";

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

function pubEnsureMaterialTable(mysqli $conn): void
{
    pubEnsureTaskTable($conn);

    try {
        $check = $conn->query(
            "SHOW COLUMNS FROM publication_material_content LIKE 'material_files'"
        );

        if (!$check || $check->num_rows === 0) {
            $conn->query(
                "ALTER TABLE publication_material_content
                  ADD COLUMN material_files LONGTEXT NULL"
            );
        }

        // Draft/Final capacity: TEXT holds 10000+ characters
        // (VARCHAR is capped by the 64 KB row-size limit).
        $lenCheck = $conn->query(
            "SELECT DATA_TYPE
               FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = 'publication_material_content'
                 AND COLUMN_NAME = 'draft_text'"
        );

        $lenRow = $lenCheck ? $lenCheck->fetch_assoc() : null;
        $dataType = strtolower((string) ($lenRow["DATA_TYPE"] ?? ""));

        if (
            $dataType !== ""
            &&
            !in_array(
                $dataType,
                ["text", "mediumtext", "longtext"],
                true
            )
        ) {
            // Expression default keeps links-only / files-only
            // upserts (which omit these columns) working.
            $conn->query(
                "ALTER TABLE publication_material_content
                  MODIFY COLUMN draft_text TEXT NOT NULL DEFAULT (''),
                  MODIFY COLUMN final_text TEXT NOT NULL DEFAULT ('')"
            );
        }
    } catch (Throwable $e) {
        // Table may not exist yet on very old installs; the
        // CREATE TABLE in pubEnsureTaskTable covers fresh ones.
    }
}

function pubGetMaterialContent(
    mysqli $conn,
    string $kind,
    int $publicationId
): array {
    $out = [
        "draft_text" => "",
        "final_text" => "",
        "links" => [],
        "material_files" => [],
        "updated_by" => 0,
        "updater_name" => "",
        "updated_at" => "",
    ];

    if (
        !in_array(
            $kind,
            ["event", "recognition", "contribution"],
            true
        )
        || $publicationId <= 0
    ) {
        return $out;
    }

    try {
        pubEnsureMaterialTable($conn);

        $stmt =
            $conn->prepare(
                "SELECT m.draft_text, m.final_text, m.links, m.material_files,
                        m.updated_by, m.updated_at,
                        u.name AS updater_name
                   FROM publication_material_content m
                   LEFT JOIN users u
                     ON u.id = m.updated_by
                   WHERE m.kind = ?
                     AND m.publication_id = ?
                   LIMIT 1"
            );

        if (!$stmt) {
            return $out;
        }

        $stmt->bind_param("si", $kind, $publicationId);
        $stmt->execute();

        $row =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$row) {
            return $out;
        }

        $links = [];
        if (isset($row["links"]) && $row["links"] !== null && $row["links"] !== "") {
            $decoded = json_decode((string) $row["links"], true);
            if (is_array($decoded)) {
                foreach (array_slice($decoded, 0, pubMaterialMaxLinks()) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $url = trim((string) ($item["url"] ?? ""));
                    if ($url === "") {
                        continue;
                    }
                    $links[] = [
                        "type" => trim((string) ($item["type"] ?? "Website")),
                        "url" => $url,
                    ];
                }
            }
        }

        $out["draft_text"] = (string) ($row["draft_text"] ?? "");
        $out["final_text"] = (string) ($row["final_text"] ?? "");
        $out["links"] = $links;
        $out["material_files"] = pubDecodeMaterialFiles(
            isset($row["material_files"]) ? (string) $row["material_files"] : ""
        );
        $out["updated_by"] = (int) ($row["updated_by"] ?? 0);
        $out["updater_name"] = (string) ($row["updater_name"] ?? "");
        $out["updated_at"] = (string) ($row["updated_at"] ?? "");
    } catch (Throwable $e) {
        // Table missing: treat as empty content.
    }

    return $out;
}

/* Decode HPU material-file entries (staff uploads, not requestor files). */
function pubDecodeMaterialFiles(string $json): array
{
    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        return [];
    }

    $kinds = pubMaterialFileKinds();
    $out = [];

    foreach (array_slice($decoded, 0, pubMaterialMaxFiles()) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $stored = basename((string) ($item["stored_name"] ?? ""));
        $original = trim((string) ($item["original_name"] ?? ""));
        $driveUrl = trim((string) ($item["drive_url"] ?? ""));

        // Google Drive link entries carry no stored file.
        $isDriveLink =
            $driveUrl !== ""
            &&
            filter_var($driveUrl, FILTER_VALIDATE_URL) !== false;

        if (
            !$isDriveLink
            &&
            ($stored === "" || $original === "")
        ) {
            continue;
        }

        if ($isDriveLink && $original === "") {
            $original = "Google Drive link";
        }

        $material = trim((string) ($item["material"] ?? "Other"));

        if (!in_array($material, $kinds, true)) {
            $material = "Other";
        }

        $out[] = [
            "material" => $material,
            "stored_name" => $stored,
            "original_name" => $original,
            "extension" => $isDriveLink ? "drive" : strtolower((string) ($item["extension"] ?? "")),
            "drive_url" => $isDriveLink ? $driveUrl : "",
            "size" => (int) ($item["size"] ?? 0),
            "mime" => (string) ($item["mime"] ?? ""),
            "uploaded_by" => (int) ($item["uploaded_by"] ?? 0),
            "uploader_name" => (string) ($item["uploader_name"] ?? ""),
            "uploaded_at" => (string) ($item["uploaded_at"] ?? ""),
        ];
    }

    return $out;
}

function pubNormalizeMaterialLinks(array $types, array $urls): array
{
    $links = [];
    $max = pubMaterialMaxLinks();
    $allowed = pubMaterialLinkTypes();
    $count = min(count($types), count($urls), $max);

    for ($i = 0; $i < $count; $i++) {
        $url = trim((string) ($urls[$i] ?? ""));
        if ($url === "") {
            continue;
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = "https://" . $url;
        }
        if (strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            continue;
        }
        $type = trim((string) ($types[$i] ?? "Website"));
        if (!in_array($type, $allowed, true)) {
            $type = "Website";
        }
        $links[] = ["type" => $type, "url" => $url];
        if (count($links) >= $max) {
            break;
        }
    }

    return array_values($links);
}
