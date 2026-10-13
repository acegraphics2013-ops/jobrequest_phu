<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - SHARED REPORT ENGINE (canonical)
|--------------------------------------------------------------------------
| Single source of truth for Reports + Monthly Reports.
| Both evaluation_analytics.php (ea*) and monthly_report.php (mr*)
| delegate to these rep* helpers so numbers stay synchronized.
|
| Canonical semantics:
| - Requests cohort = YEAR(+MONTH when set) of date_request
| - Completions = approval_status IN (Completed, For Evaluation, Closed)
|   + actual date = final_approval_date ONLY (date_completion is TARGET)
| - Evaluations = COALESCE(overall_satisfaction, satisfaction_rating) 1-5
|   moment = submitted_at ?: final_approval_date ?: date_request
| - Turnaround = date_request -> final_approval_date, b<a => NULL excluded
| - Pending = max(0, qty - done); rate = done/qty*100
| - Personnel totals may exceed request count (handler+reviewer+approver)
|--------------------------------------------------------------------------
*/

if (function_exists('repTableExists')) {
    return;
}

function repTableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function repColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row["total"] ?? 0) > 0;
}

function repDecodeArray(mixed $value): array
{
    if ($value === null || $value === "") {
        return [];
    }
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

function repValidDateOrNull(mixed $value): ?string
{
    $text = trim((string)($value ?? ""));
    if ($text === "" || $text === "0000-00-00") {
        return null;
    }
    try {
        new DateTime(substr($text, 0, 10));
        return substr($text, 0, 10);
    } catch (Throwable $e) {
        return null;
    }
}

function repDateDiffDays(?string $start, ?string $end): ?int
{
    if (!$start || !$end) {
        return null;
    }
    try {
        $a = new DateTime(substr((string)$start, 0, 10));
        $b = new DateTime(substr((string)$end, 0, 10));
        if ($b < $a) {
            return null;
        }
        return (int)$a->diff($b)->format("%a");
    } catch (Throwable $e) {
        return null;
    }
}

function repActualCompletion(array $row): ?string
{
    return repValidDateOrNull($row["final_approval_date"] ?? null);
}

function repTargetDate(array $row): ?string
{
    return repValidDateOrNull($row["date_completion"] ?? null);
}

function repEvaluationDate(array $row): ?string
{
    $submitted = repValidDateOrNull($row["client_submitted_at"] ?? null);
    if ($submitted !== null) {
        return $submitted;
    }
    return repActualCompletion($row)
        ?: repValidDateOrNull($row["date_request"] ?? null);
}

function repPercent(float|int $part, float|int $whole): float
{
    if ((float)$whole <= 0) {
        return 0.0;
    }
    return ((float)$part / (float)$whole) * 100;
}

function repCombinedRating(array $row): int
{
    $client = (int)($row["client_overall_satisfaction"] ?? 0);
    if ($client >= 1 && $client <= 5) {
        return $client;
    }
    $legacy = (int)($row["satisfaction_rating"] ?? 0);
    return ($legacy >= 1 && $legacy <= 5) ? $legacy : 0;
}

function repRatingLabel(int $rating): string
{
    return match ($rating) {
        5 => "Excellent", 4 => "Very Good", 3 => "Good",
        2 => "Fair", 1 => "Poor", default => "Not Rated"
    };
}

function repRatingClass(int $rating): string
{
    return match ($rating) {
        5 => "rating-excellent", 4 => "rating-very-good",
        3 => "rating-good", 2 => "rating-fair",
        1 => "rating-poor", default => "rating-none"
    };
}

function repRequestTypeClass(string $type): string
{
    return match ($type) {
        "Graphic Design" => "service-graphic",
        "Video Production" => "service-video",
        "Photo/Video Coverage" => "service-coverage",
        "IEC Materials" => "service-iec",
        "Publication Submission" => "service-publication",
        default => "service-other"
    };
}

function repStatusClass(string $status): string
{
    return match ($status) {
        "Completed", "For Evaluation", "Closed" => "status-success",
        "For Revision" => "status-warning",
        "Disapproved" => "status-danger",
        default => "status-neutral"
    };
}

function repMonthShort(int $month): string
{
    return match ($month) {
        1 => "Jan", 2 => "Feb", 3 => "Mar", 4 => "Apr",
        5 => "May", 6 => "Jun", 7 => "Jul", 8 => "Aug",
        9 => "Sep", 10 => "Oct", 11 => "Nov", default => "Dec"
    };
}

function repMonthLong(int $month): string
{
    return match ($month) {
        1 => "January", 2 => "February", 3 => "March", 4 => "April",
        5 => "May", 6 => "June", 7 => "July", 8 => "August",
        9 => "September", 10 => "October", 11 => "November", default => "December"
    };
}

function repSqlBind(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types !== "" && $params) {
        $stmt->bind_param($types, ...$params);
    }
}

function repScalar(mysqli $conn, string $sql, string $types = "", array $params = []): int|float|string
{
    $stmt = $conn->prepare($sql);
    if ($types !== "" && $params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row[0] ?? 0;
}

/* Staff identity (canonical merge). */

function repNormStaffName(string $name): string
{
    $norm = mb_strtoupper(trim($name));
    $norm = str_replace(".", "", $norm);
    $norm = (string)preg_replace('/\s+/', ' ', $norm);
    return trim($norm);
}

function repCanonicalStaffDisplay(string $name, array $aliasMap): string
{
    $norm = repNormStaffName($name);
    return $aliasMap[$norm] ?? trim($name);
}

function repDefaultAliases(): array
{
    return [
        repNormStaffName("ACE R. CABUGA") => "ACE RAZ CABUGA",
        repNormStaffName("ACE RAZ CABUGA") => "ACE RAZ CABUGA",
        repNormStaffName("BELL MANDREW MANLIGUEZ, RN - HEPO II") => "BELL MANDREW L. MANLIGUEZ, RN",
        repNormStaffName("BELL MANDREW L. MANLIGUEZ, RN") => "BELL MANDREW L. MANLIGUEZ, RN",
        repNormStaffName("RECKLYN D. RUAZA, RN") => "RECKLYN D. RUAZA, RN",
        repNormStaffName("RECKLYN D. RUAZA, RN - HEPO III") => "RECKLYN D. RUAZA, RN",
    ];
}

function repRegisterStaffMaps(
    mysqli $conn,
    array $aliasMap,
    array &$idByNorm,
    array &$displayByNorm,
    array &$roleById,
    array &$statusById,
    array &$displayById
): void {
    $res = $conn->query(
        "SELECT id, name, role, status FROM users
          WHERE role IN ('staff','supervisor','admin')"
    );
    if (!$res) {
        return;
    }
    foreach ($res->fetch_all(MYSQLI_ASSOC) as $u) {
        $display = trim((string)($u["name"] ?? ""));
        if ($display === "") {
            continue;
        }
        $uid = (int)$u["id"];
        $norm = repNormStaffName($display);
        $idByNorm[$norm] = $uid;
        if (!isset($displayByNorm[$norm])) {
            $displayByNorm[$norm] = $display;
        }
        $roleById[$uid] = (string)($u["role"] ?? "");
        $statusById[$uid] = (string)($u["status"] ?? "");
        $displayById[$uid] = $display;
    }
    foreach ($aliasMap as $aliasNorm => $display) {
        $canonNorm = repNormStaffName((string)$display);
        if (isset($idByNorm[$canonNorm]) && !isset($idByNorm[$aliasNorm])) {
            $idByNorm[$aliasNorm] = $idByNorm[$canonNorm];
        }
        if (isset($displayByNorm[$canonNorm]) && !isset($displayByNorm[$aliasNorm])) {
            $displayByNorm[$aliasNorm] = $displayByNorm[$canonNorm];
        } elseif (!isset($displayByNorm[$aliasNorm])) {
            $displayByNorm[$aliasNorm] = $display;
        }
    }
}

function repResolvePersonBucket(
    string $rawName,
    array $aliasMap,
    array $idByNorm,
    array $displayByNorm
): array {
    $rawName = trim($rawName);
    if ($rawName === "") {
        return ["key" => "", "id" => 0, "display" => ""];
    }
    $canonDisplay = repCanonicalStaffDisplay($rawName, $aliasMap);
    $canonNorm = repNormStaffName($canonDisplay);
    $norm = repNormStaffName($rawName);
    if (isset($idByNorm[$canonNorm])) {
        $uid = (int)$idByNorm[$canonNorm];
        $display = (string)($displayByNorm[$canonNorm] ?? $canonDisplay);
        return ["key" => "id:" . $uid, "id" => $uid, "display" => $display];
    }
    if (isset($idByNorm[$norm])) {
        $uid = (int)$idByNorm[$norm];
        $display = (string)($displayByNorm[$norm] ?? $canonDisplay);
        return ["key" => "id:" . $uid, "id" => $uid, "display" => $display];
    }
    return ["key" => "canon:" . $canonNorm, "id" => 0, "display" => $canonDisplay];
}

function repEnsureStaffBucket(array &$staffStats, string $key, string $display, int $id): void
{
    if ($key === "" || $display === "") {
        return;
    }
    if (!isset($staffStats[$key])) {
        $staffStats[$key] = [
            "name" => $display, "id" => $id,
            "handled" => 0, "completed" => 0, "evaluated" => 0,
            "rating_total" => 0, "rating_count" => 0,
            "turnaround_total" => 0, "turnaround_count" => 0,
            "revisions" => 0, "reviewed" => 0, "approved" => 0,
        ];
    }
    foreach (["reviewed", "approved"] as $k) {
        if (!isset($staffStats[$key][$k])) {
            $staffStats[$key][$k] = 0;
        }
    }
    if ($id > 0 && ($staffStats[$key]["id"] ?? 0) === 0) {
        $staffStats[$key]["id"] = $id;
    }
}

function repStaffVariants(string $canonicalDisplay, array $aliasMap): array
{
    $wanted = repNormStaffName($canonicalDisplay);
    $variants = [];
    foreach ($aliasMap as $norm => $display) {
        if (repNormStaffName($display) === $wanted) {
            $variants[] = $norm;
        }
    }
    return array_values(array_unique($variants));
}

/*
| Build staff WHERE fragment with orphan-merge (EA logic, canonical).
| Returns [sqlFragment, typesSuffix, paramsSuffix].
| When $staffFilter is a digit ID: assigned_to=? OR (assigned NULL AND reviewed IN variants).
| When text: assignee.name IN (...) OR reviewed IN (...).
*/
function repStaffWhere(
    mysqli $conn,
    string $staffFilter,
    bool $hasAssignedTo,
    array $aliasMap,
    array $idByNorm,
    array $displayByNorm
): array {
    if ($staffFilter === "") {
        return ["", "", []];
    }
    // Resolve to id + display + variants.
    $resolvedId = 0;
    $resolvedDisplay = "";
    $wantedNorms = [];
    if ($hasAssignedTo && ctype_digit($staffFilter)) {
        $uid = (int)$staffFilter;
        $stmt = $conn->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $urow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $display = trim((string)($urow["name"] ?? ""));
        if ($display === "") {
            return ["", "", []];
        }
        $resolvedId = $uid;
        $resolvedDisplay = $aliasMap[repNormStaffName($display)] ?? $display;
        foreach ((array)repStaffVariants($resolvedDisplay, $aliasMap) as $v) {
            $wantedNorms[(string)$v] = true;
        }
        $wantedNorms[repNormStaffName($resolvedDisplay)] = true;
    } else {
        $norm = repNormStaffName($staffFilter);
        $canonDisplay = $aliasMap[$norm] ?? $staffFilter;
        $canonNorm = repNormStaffName($canonDisplay);
        if (isset($idByNorm[$canonNorm])) {
            $resolvedId = (int)$idByNorm[$canonNorm];
            $resolvedDisplay = (string)($displayByNorm[$canonNorm] ?? $canonDisplay);
        } elseif (isset($idByNorm[$norm])) {
            $resolvedId = (int)$idByNorm[$norm];
            $resolvedDisplay = (string)($displayByNorm[$norm] ?? $staffFilter);
        } else {
            $resolvedDisplay = repCanonicalStaffDisplay($staffFilter, $aliasMap);
        }
        foreach ((array)repStaffVariants($resolvedDisplay, $aliasMap) as $v) {
            $wantedNorms[(string)$v] = true;
        }
        if ($resolvedDisplay !== "") {
            $wantedNorms[repNormStaffName($resolvedDisplay)] = true;
        }
        if (empty($wantedNorms)) {
            $wantedNorms[repNormStaffName($staffFilter)] = true;
        }
    }

    // All reviewed_by spellings belonging to this person.
    static $spellCache = null;
    if ($spellCache === null) {
        $spellCache = [];
        $spellRes = $conn->query(
            "SELECT DISTINCT reviewed_by FROM job_requests
              WHERE reviewed_by IS NOT NULL AND reviewed_by <> ''"
        );
        if ($spellRes) {
            foreach ($spellRes->fetch_all(MYSQLI_ASSOC) as $srow) {
                $spellCache[] = (string)($srow["reviewed_by"] ?? "");
            }
        }
    }
    $orphans = [];
    foreach ($spellCache as $spelling) {
        if (isset($wantedNorms[repNormStaffName($spelling)])) {
            $orphans[] = $spelling;
        }
    }
    $orphans = array_values(array_unique($orphans));

    if ($hasAssignedTo) {
        if ($resolvedId > 0) {
            if ($orphans) {
                $ph = implode(",", array_fill(0, count($orphans), "?"));
                return [
                    " AND (jr.assigned_to = ? OR (jr.assigned_to IS NULL AND jr.reviewed_by IN ({$ph})))",
                    "i" . str_repeat("s", count($orphans)),
                    array_merge([$resolvedId], $orphans),
                ];
            }
            return [" AND jr.assigned_to = ?", "i", [$resolvedId]];
        }
        $lits = $orphans;
        if ($staffFilter !== "" && !in_array($staffFilter, $lits, true)) {
            $lits[] = $staffFilter;
        }
        $ph = implode(",", array_fill(0, count($lits), "?"));
        return [
            " AND (assignee.name IN ({$ph}) OR jr.reviewed_by IN ({$ph}))",
            str_repeat("s", count($lits) * 2),
            array_merge($lits, $lits),
        ];
    }
    $lits = $orphans;
    if ($staffFilter !== "" && !in_array($staffFilter, $lits, true)) {
        $lits[] = $staffFilter;
    }
    $ph = implode(",", array_fill(0, count($lits), "?"));
    return [
        " AND jr.reviewed_by IN ({$ph})",
        str_repeat("s", count($lits)),
        $lits,
    ];
}

function repDetect(mysqli $conn): array
{
    return [
        "hasClientSurvey" => repTableExists($conn, "job_request_client_experience"),
        "hasAssignedTo" => repColumnExists($conn, "job_requests", "assigned_to"),
        "hasPriority" => repColumnExists($conn, "job_requests", "priority"),
        "hasArchived" => repColumnExists($conn, "job_requests", "archived"),
        "hasPublicToken" => repColumnExists($conn, "job_requests", "public_token"),
        "hasIecItems" => repTableExists($conn, "job_request_iec_items"),
        "hasIecLibrary" => repTableExists($conn, "iec_materials"),
        "hasIecLogs" => repTableExists($conn, "iec_material_logs"),
        "hasIecFulfilled" => repTableExists($conn, "job_request_iec_items")
            && repColumnExists($conn, "job_request_iec_items", "fulfilled_qty"),
        "hasIecLinked" => repTableExists($conn, "job_request_iec_items")
            && repColumnExists($conn, "job_request_iec_items", "iec_material_id"),
        "hasIecViews" => repTableExists($conn, "iec_materials")
            && repColumnExists($conn, "iec_materials", "view_count"),
    ];
}
