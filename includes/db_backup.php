<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - DATABASE BACKUP ENGINE
|--------------------------------------------------------------------------
| Pure-PHP dump / restore for the hpu_job_request database.
| No shell calls, no external binaries, no extra dependencies.
|
| Conventions:
|   hpu_backup_YYYYMMDD_HHMMSS.sql    manual backups (pruned to newest 10)
|   pre_restore_YYYYMMDD_HHMMSS.sql   automatic safety copy before restore
|   pending_<adminid>_<token>.sql     uploaded file awaiting restore confirm
|--------------------------------------------------------------------------
*/

if (!defined("DB_BACKUP_KEEP")) {
    define("DB_BACKUP_KEEP", 10);
}

if (!defined("DB_BACKUP_MAX_UPLOAD")) {
    define("DB_BACKUP_MAX_UPLOAD", 64 * 1024 * 1024);
}

/* Storage directory (created on demand, denied to the web server). */
function dbBackupDir(): string
{
    $dir = dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . "storage"
        . DIRECTORY_SEPARATOR
        . "backups";

    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Unable to create backup directory.");
    }

    $htaccess = $dir . DIRECTORY_SEPARATOR . ".htaccess";

    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n");
    }

    $index = $dir . DIRECTORY_SEPARATOR . "index.html";

    if (!is_file($index)) {
        @file_put_contents($index, "<!doctype html><title>403</title><h1>Forbidden</h1>");
    }

    return $dir;
}

/* Only our own filenames may ever be listed, served or deleted. */
function dbBackupSafeName(string $name): ?string
{
    $base = basename(trim($name));

    if (preg_match('/^(hpu_backup|pre_restore|pending)_[A-Za-z0-9_]+\.sql(\.zip)?$/', $base)) {
        return $base;
    }

    return null;
}

function dbBackupResolve(string $name): ?string
{
    $safe = dbBackupSafeName($name);

    if ($safe === null) {
        return null;
    }

    $path = dbBackupDir() . DIRECTORY_SEPARATOR . $safe;

    if (!is_file($path)) {
        return null;
    }

    $realDir = realpath(dbBackupDir());
    $realPath = realpath($path);

    if ($realDir === false || $realPath === false) {
        return null;
    }

    if (strpos($realPath, $realDir . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }

    return $realPath;
}

function dbBackupFileSize(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . " B";
    }

    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . " KB";
    }

    return number_format($bytes / (1024 * 1024), 2) . " MB";
}

/* Live database stats for the admin dashboard card. */
function dbBackupStats(mysqli $conn): array
{
    $stats = ["tables" => 0, "bytes" => 0, "top" => []];

    try {
        $res = $conn->query(
            "SELECT COUNT(*) AS tables,
                    COALESCE(SUM(data_length + index_length), 0) AS bytes
              FROM information_schema.TABLES
              WHERE table_schema = DATABASE()"
        );

        if ($res && ($row = $res->fetch_assoc())) {
            $stats["tables"] = (int) ($row["tables"] ?? 0);
            $stats["bytes"] = (int) ($row["bytes"] ?? 0);
        }

        $top = $conn->query(
            "SELECT table_name, table_rows
              FROM information_schema.TABLES
              WHERE table_schema = DATABASE()
              ORDER BY (data_length + index_length) DESC
              LIMIT 5"
        );

        if ($top) {
            while ($row = $top->fetch_assoc()) {
                // information_schema labels come back in canonical
                // uppercase regardless of the query's casing.
                $tableName = $row["TABLE_NAME"] ?? $row["table_name"] ?? "";
                $tableRows = $row["TABLE_ROWS"] ?? $row["table_rows"] ?? null;

                $stats["top"][] = [
                    "table" => (string) $tableName,
                    "rows" => $tableRows === null ? null : (int) $tableRows,
                ];
            }
        }
    } catch (Throwable $e) {
        // Stats are informational only; never break the page.
    }

    return $stats;
}

/* List stored backups, newest first. */
function dbBackupFiles(): array
{
    $dir = dbBackupDir();
    $files = [];

    foreach (glob($dir . DIRECTORY_SEPARATOR . "*.sql") ?: [] as $path) {
        $base = basename($path);

        if (dbBackupSafeName($base) === null) {
            continue;
        }

        // Pending uploads are managed by the restore flow, not the history.
        if (strpos($base, "pending_") === 0) {
            continue;
        }

        $files[] = [
            "name" => $base,
            "size" => is_file($path) ? (int) filesize($path) : 0,
            "created_at" => is_file($path) ? (int) filemtime($path) : 0,
            "kind" => strpos($base, "pre_restore_") === 0 ? "safety" : "manual",
        ];
    }

    usort($files, static function (array $a, array $b): int {
        return $b["created_at"] <=> $a["created_at"];
    });

    return $files;
}

/* Keep newest $keep manual backups; safety copies are never pruned. */
function dbBackupPrune(int $keep = DB_BACKUP_KEEP): int
{
    $removed = 0;
    $manual = 0;

    foreach (dbBackupFiles() as $file) {
        if ($file["kind"] !== "manual") {
            continue;
        }

        $manual++;

        if ($manual > $keep) {
            $path = dbBackupResolve($file["name"]);

            if ($path !== null && @unlink($path)) {
                $removed++;
            }
        }
    }

    return $removed;
}

/* Delete expired pending uploads (older than 1 hour). */
function dbBackupCleanPending(int $maxAge = 3600): void
{
    $dir = dbBackupDir();

    foreach (glob($dir . DIRECTORY_SEPARATOR . "pending_*.sql") ?: [] as $path) {
        if (is_file($path) && (time() - (int) filemtime($path)) > $maxAge) {
            @unlink($path);
        }
    }
}

function dbBackupEscapeValue(mysqli $conn, $value): string
{
    if ($value === null) {
        return "NULL";
    }

    return "'" . $conn->real_escape_string((string) $value) . "'";
}

/* Generate a full dump. Returns file meta; throws on failure. */
function dbBackupGenerate(mysqli $conn, string $label = "hpu_backup"): array
{
    if (function_exists("set_time_limit")) {
        @set_time_limit(0);
    }

    $started = microtime(true);
    $dir = dbBackupDir();
    $stamp = date("Ymd_His");

    $filename = $label . "_" . $stamp . ".sql";
    $tmpPath = $dir . DIRECTORY_SEPARATOR . $filename . ".part";
    $finalPath = $dir . DIRECTORY_SEPARATOR . $filename;

    // Avoid a same-second collision with an existing file.
    $suffix = 1;
    while (is_file($finalPath)) {
        $suffix++;
        $filename = $label . "_" . $stamp . "_" . $suffix . ".sql";
        $finalPath = $dir . DIRECTORY_SEPARATOR . $filename;
        $tmpPath = $finalPath . ".part";
    }

    $handle = @fopen($tmpPath, "wb");

    if ($handle === false) {
        throw new RuntimeException("Unable to write backup file.");
    }

    $dbName = "hpu_job_request";

    try {
        $nameRes = $conn->query("SELECT DATABASE() AS db");

        if ($nameRes && ($nameRow = $nameRes->fetch_assoc()) && !empty($nameRow["db"])) {
            $dbName = (string) $nameRow["db"];
        }
    } catch (Throwable $e) {
    }

    $write = static function (string $chunk) use ($handle): void {
        if (@fwrite($handle, $chunk) === false) {
            throw new RuntimeException("Unable to write backup file.");
        }
    };

    $tableCount = 0;

    try {
        $write("-- HPU Workbench database backup\n");
        $write("-- Database: " . $dbName . "\n");
        $write("-- Generated: " . date("Y-m-d H:i:s") . "\n\n");
        $write("SET NAMES utf8mb4;\n");
        $write("SET FOREIGN_KEY_CHECKS=0;\n\n");

        $tablesRes = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");

        if (!$tablesRes) {
            throw new RuntimeException("Unable to list database tables.");
        }

        $tables = [];

        while ($row = $tablesRes->fetch_row()) {
            $tables[] = (string) $row[0];
        }

        sort($tables);

        foreach ($tables as $table) {
            $safeTable = str_replace("`", "``", $table);

            $createRes = $conn->query("SHOW CREATE TABLE `{$safeTable}`");

            if (!$createRes || !($createRow = $createRes->fetch_row())) {
                throw new RuntimeException("Unable to read schema for table {$table}.");
            }

            $createSql = (string) ($createRow[1] ?? "");

            $write("-- --------------------------------------------------------\n");
            $write("-- Table structure for table `{$table}`\n");
            $write("-- --------------------------------------------------------\n\n");
            $write("DROP TABLE IF EXISTS `{$table}`;\n");
            $write(rtrim($createSql, " \t\n\r;") . ";\n\n");

            $columnsRes = $conn->query("SHOW COLUMNS FROM `{$safeTable}`");

            if (!$columnsRes) {
                throw new RuntimeException("Unable to read columns for table {$table}.");
            }

            $columns = [];

            while ($colRow = $columnsRes->fetch_assoc()) {
                $columns[] = "`" . str_replace("`", "``", (string) $colRow["Field"]) . "`";
            }

            if (!$columns) {
                continue;
            }

            $columnList = implode(", ", $columns);

            // Short read lock per table for a consistent snapshot.
            $locked = false;

            try {
                $locked = (bool) $conn->query("LOCK TABLES `{$safeTable}` READ");
            } catch (Throwable $e) {
                $locked = false;
            }

            try {
                $offset = 0;
                $batchSize = 500;
                $hasRows = false;

                $write("-- Dumping data for table `{$table}`\n");

                do {
                    $dataRes = $conn->query(
                        "SELECT * FROM `{$safeTable}` LIMIT {$offset}, {$batchSize}"
                    );

                    if (!$dataRes) {
                        throw new RuntimeException("Unable to read data from table {$table}.");
                    }

                    $batch = [];

                    while ($dataRow = $dataRes->fetch_row()) {
                        $hasRows = true;
                        $escaped = [];

                        foreach ($dataRow as $value) {
                            $escaped[] = dbBackupEscapeValue($conn, $value);
                        }

                        $batch[] = "(" . implode(", ", $escaped) . ")";

                        if (count($batch) >= 100) {
                            $write("INSERT INTO `{$table}` ({$columnList}) VALUES\n"
                                . implode(",\n", $batch) . ";\n");
                            $batch = [];
                        }
                    }

                    if ($batch) {
                        $write("INSERT INTO `{$table}` ({$columnList}) VALUES\n"
                            . implode(",\n", $batch) . ";\n");
                    }

                    $fetched = $dataRes->num_rows;
                    $offset += $batchSize;
                } while ($fetched === $batchSize);

                if (!$hasRows) {
                    $write("-- (no rows)\n");
                }

                $write("\n");
                $tableCount++;
            } finally {
                if ($locked) {
                    try {
                        $conn->query("UNLOCK TABLES");
                    } catch (Throwable $e) {
                    }
                }
            }
        }

        $write("SET FOREIGN_KEY_CHECKS=1;\n");
        $write("-- Backup complete: {$tableCount} tables.\n");

        @fflush($handle);
        @fclose($handle);
        $handle = null;

        if (!@rename($tmpPath, $finalPath)) {
            @unlink($tmpPath);
            throw new RuntimeException("Unable to finalize backup file.");
        }

        return [
            "name" => $filename,
            "path" => $finalPath,
            "size" => is_file($finalPath) ? (int) filesize($finalPath) : 0,
            "tables" => $tableCount,
            "duration" => round(microtime(true) - $started, 2),
        ];
    } catch (Throwable $e) {
        if ($handle !== null) {
            @fclose($handle);
        }

        @unlink($tmpPath);
        throw $e;
    }
}

/* Split SQL into executable statements (quote/comment aware). */
function dbSplitSql(string $sql): array
{
    // Normalize line endings for reliable boundary detection.
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);

    $statements = [];
    $current = "";
    $length = strlen($sql);
    $i = 0;

    $state = "normal"; // normal | squote | dquote | backtick | linecomment | blockcomment

    while ($i < $length) {
        $ch = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : "";

        if ($state === "normal") {
            if ($ch === "-" && $next === "-" && ($i + 2 >= $length || $sql[$i + 2] === " " || $sql[$i + 2] === "\t" || $sql[$i + 2] === "\n")) {
                $state = "linecomment";
                $current .= $ch . $next;
                $i += 2;
                continue;
            }

            if ($ch === "#") {
                $state = "linecomment";
                $current .= $ch;
                $i++;
                continue;
            }

            if ($ch === "/" && $next === "*") {
                $state = "blockcomment";
                $current .= $ch . $next;
                $i += 2;
                continue;
            }

            if ($ch === "'") {
                $state = "squote";
                $current .= $ch;
                $i++;
                continue;
            }

            if ($ch === '"') {
                $state = "dquote";
                $current .= $ch;
                $i++;
                continue;
            }

            if ($ch === "`") {
                $state = "backtick";
                $current .= $ch;
                $i++;
                continue;
            }

            if ($ch === ";") {
                $current .= $ch;
                $trimmed = trim($current);

                if ($trimmed !== "" && $trimmed !== ";") {
                    $statements[] = $current;
                }

                $current = "";
                $i++;
                continue;
            }

            $current .= $ch;
            $i++;
            continue;
        }

        if ($state === "linecomment") {
            $current .= $ch;

            if ($ch === "\n") {
                $state = "normal";
            }

            $i++;
            continue;
        }

        if ($state === "blockcomment") {
            $current .= $ch;

            if ($ch === "*" && $next === "/") {
                $current .= $next;
                $i += 2;
                $state = "normal";
                continue;
            }

            $i++;
            continue;
        }

        // Inside quotes: backslash escapes the next character.
        if ($ch === "\\" && $next !== "") {
            $current .= $ch . $next;
            $i += 2;
            continue;
        }

        // MySQL '' escape inside single-quoted strings.
        if ($state === "squote" && $ch === "'" && $next === "'") {
            $current .= $ch . $next;
            $i += 2;
            continue;
        }

        if (($state === "squote" && $ch === "'")
            || ($state === "dquote" && $ch === '"')
            || ($state === "backtick" && $ch === "`")
        ) {
            $current .= $ch;
            $state = "normal";
            $i++;
            continue;
        }

        $current .= $ch;
        $i++;
    }

    $tail = trim($current);

    if ($tail !== "" && $tail !== ";") {
        $statements[] = $current;
    }

    return array_values($statements);
}

/* Quick sanity check + table inventory for the restore preview screen. */
function dbBackupInspect(string $sql): array
{
    $info = [
        "valid" => false,
        "tables" => [],
        "statements" => 0,
        "has_create" => false,
        "has_insert" => false,
        "error" => "",
    ];

    if (trim($sql) === "") {
        $info["error"] = "The uploaded file is empty.";
        return $info;
    }

    try {
        $statements = dbSplitSql($sql);
    } catch (Throwable $e) {
        $info["error"] = "The file could not be parsed as SQL.";
        return $info;
    }

    $info["statements"] = count($statements);

    foreach ($statements as $stmt) {
        if (preg_match('/^\s*(?:\/\*.*?\*\/\s*)*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/is', $stmt, $m)) {
            $info["has_create"] = true;
            $table = $m[1];

            if (!in_array($table, $info["tables"], true)) {
                $info["tables"][] = $table;
            }
        } elseif (preg_match('/^\s*(?:\/\*.*?\*\/\s*)*INSERT\s+INTO\s+`?([A-Za-z0-9_]+)`?/is', $stmt, $m)) {
            $info["has_insert"] = true;

            if (!in_array($m[1], $info["tables"], true)) {
                $info["tables"][] = $m[1];
            }
        }
    }

    sort($info["tables"]);

    if (!$info["has_create"] && !$info["has_insert"]) {
        $info["error"] = "No CREATE TABLE or INSERT statements were found. This does not look like a database backup.";
        return $info;
    }

    $info["valid"] = true;
    return $info;
}

/* Execute a dump. Returns [executed, error, failing_excerpt]. */
function dbBackupRestore(mysqli $conn, string $sql): array
{
    if (function_exists("set_time_limit")) {
        @set_time_limit(0);
    }

    $result = ["executed" => 0, "error" => "", "statement" => ""];

    try {
        $statements = dbSplitSql($sql);
    } catch (Throwable $e) {
        $result["error"] = "The file could not be parsed as SQL.";
        return $result;
    }

    if (!$statements) {
        $result["error"] = "No executable statements were found in the file.";
        return $result;
    }

    try {
        $conn->query("SET FOREIGN_KEY_CHECKS=0");
    } catch (Throwable $e) {
    }

    try {
        foreach ($statements as $stmt) {
            if (trim($stmt) === "" || trim($stmt) === ";") {
                continue;
            }

            try {
                $ok = $conn->query($stmt);
            } catch (Throwable $stmtError) {
                $ok = false;
                $result["error"] = $stmtError->getMessage();
            }

            if (!$ok) {
                if ($result["error"] === "") {
                    $result["error"] = $conn->error ?: "A statement failed to execute.";
                }

                $result["statement"] = mb_strimwidth(trim($stmt), 0, 300, "…");
                break;
            }

            $result["executed"]++;

            // Free multi-results to keep the connection in sync.
            while ($conn->more_results() && $conn->next_result()) {
                if ($extra = $conn->store_result()) {
                    $extra->free();
                }
            }
        }
    } finally {
        try {
            $conn->query("SET FOREIGN_KEY_CHECKS=1");
        } catch (Throwable $e) {
        }
    }

    return $result;
}

/* Validate an uploaded restore file; returns raw SQL or throws. */
function dbBackupReadUpload(array $file): string
{
    $error = (int) ($file["error"] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException("Choose a .sql backup file to upload.");
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException("The upload failed. Please try again.");
    }

    $size = (int) ($file["size"] ?? 0);

    if ($size <= 0 || $size > DB_BACKUP_MAX_UPLOAD) {
        throw new RuntimeException("The file must be a .sql backup under 64 MB.");
    }

    $original = basename((string) ($file["name"] ?? ""));

    if (strtolower(pathinfo($original, PATHINFO_EXTENSION)) !== "sql") {
        throw new RuntimeException("Only .sql backup files can be restored.");
    }

    if (!is_uploaded_file((string) ($file["tmp_name"] ?? ""))) {
        throw new RuntimeException("Invalid uploaded file.");
    }

    $sql = @file_get_contents((string) $file["tmp_name"]);

    if ($sql === false || trim($sql) === "") {
        throw new RuntimeException("The uploaded file is empty or unreadable.");
    }

    return $sql;
}
