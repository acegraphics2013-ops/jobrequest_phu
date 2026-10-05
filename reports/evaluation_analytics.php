<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - SERVICE EVALUATION & CLIENT EXPERIENCE ANALYTICS
|--------------------------------------------------------------------------
| Drop-in replacement:
|   C:\laragon\www\jobrequest_phu\reports\evaluation_analytics.php
|
| Uses:
|   - job_requests legacy evaluation fields
|   - job_request_client_experience richer client survey when installed
|   - assigned_to + users when available
|   - reviewed_by fallback for older installations
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
requireModule($conn, "evaluation_analytics");

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

$userId = (int)($_SESSION["user_id"] ?? 0);
$userName = (string)($_SESSION["name"] ?? "User");
$role = (string)($_SESSION["role"] ?? "staff");

$isStaff = $role === "staff";
$isManager = in_array(
    $role,
    ["supervisor", "admin"],
    true
);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function eaTableExists(
    mysqli $conn,
    string $table
): bool {
    $safe = $conn->real_escape_string(
        $table
    );

    $result = $conn->query(
        "SHOW TABLES LIKE '{$safe}'"
    );

    return
        $result
        &&
        $result->num_rows > 0;
}

function eaColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $stmt = $conn->prepare(
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

    $row = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    return (int)($row["total"] ?? 0) > 0;
}

function eaDecodeArray(
    mixed $value
): array {
    if (
        $value === null
        ||
        $value === ""
    ) {
        return [];
    }

    if (is_array($value)) {
        return $value;
    }

    $decoded = json_decode(
        (string)$value,
        true
    );

    return is_array($decoded)
        ? $decoded
        : [];
}

function eaDateDiffDays(
    ?string $start,
    ?string $end
): ?int {
    if (
        !$start
        ||
        !$end
    ) {
        return null;
    }

    try {
        $a = new DateTime(substr((string)$start, 0, 10));
        $b = new DateTime(substr((string)$end, 0, 10));

        // Accuracy: completion before request is a data anomaly.
        // Do NOT take absolute value — return null so it is
        // excluded from averages and counted as an anomaly.
        if ($b < $a) {
            return null;
        }

        return (int)$a
            ->diff($b)
            ->format("%a");

    } catch (Throwable $e) {
        return null;
    }
}

function eaValidDateOrNull(
    mixed $value
): ?string {
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

/*
|--------------------------------------------------------------------------
| Actual completion vs target date
|--------------------------------------------------------------------------
| date_completion is the TARGET / deadline entered on the request.
| final_approval_date is the ACTUAL completion signal.
| For accurate turnaround, only the actual date may be used.
| Falling back to the target date inflates/deflates averages
| (26% of completed rows in production have no actual date).
|--------------------------------------------------------------------------
*/
function eaActualCompletion(
    array $row
): ?string {
    return eaValidDateOrNull(
        $row["final_approval_date"] ?? null
    );
}

function eaTargetDate(
    array $row
): ?string {
    return eaValidDateOrNull(
        $row["date_completion"] ?? null
    );
}

function eaEvaluationDate(
    array $row
): ?string {
    // Client survey submission is the true evaluation moment.
    $submitted = eaValidDateOrNull(
        $row["client_submitted_at"] ?? null
    );

    if ($submitted !== null) {
        return $submitted;
    }

    // Legacy evaluations have no submitted_at — use actual
    // completion, never the target date.
    return eaActualCompletion($row)
        ?: eaValidDateOrNull(
            $row["date_request"] ?? null
        );
}

function eaPercent(
    float|int $part,
    float|int $whole
): float {
    if ((float)$whole <= 0) {
        return 0.0;
    }

    return
        ((float)$part / (float)$whole)
        * 100;
}

function eaCombinedRating(
    array $row
): int {
    $client = (int)(
        $row["client_overall_satisfaction"]
        ?? 0
    );

    if (
        $client >= 1
        &&
        $client <= 5
    ) {
        return $client;
    }

    $legacy = (int)(
        $row["satisfaction_rating"]
        ?? 0
    );

    return (
        $legacy >= 1
        &&
        $legacy <= 5
    )
        ? $legacy
        : 0;
}

function eaRatingLabel(
    int $rating
): string {
    return match ($rating) {
        5 => "Excellent",
        4 => "Very Good",
        3 => "Good",
        2 => "Fair",
        1 => "Poor",
        default => "Not Rated"
    };
}

function eaRatingClass(
    int $rating
): string {
    return match ($rating) {
        5 => "rating-excellent",
        4 => "rating-very-good",
        3 => "rating-good",
        2 => "rating-fair",
        1 => "rating-poor",
        default => "rating-none"
    };
}

function eaRequestTypeClass(
    string $type
): string {
    return match ($type) {
        "Graphic Design" =>
            "service-graphic",

        "Video Production" =>
            "service-video",

        "Photo/Video Coverage" =>
            "service-coverage",

        "IEC Materials" =>
            "service-iec",

        "Publication Submission" =>
            "service-publication",

        default =>
            "service-other"
    };
}

function eaRequestTypeIcon(
    string $type
): string {
    return match ($type) {
        "Graphic Design" =>
            "bi-palette",

        "Video Production" =>
            "bi-camera-reels",

        "Photo/Video Coverage" =>
            "bi-camera",

        "IEC Materials" =>
            "bi-file-earmark-richtext",

        "Publication Submission" =>
            "bi-megaphone",

        default =>
            "bi-tag"
    };
}

function eaStatusClass(
    string $status
): string {
    return match ($status) {
        "Completed",
        "For Evaluation",
        "Closed" =>
            "status-success",

        "For Revision" =>
            "status-warning",

        "Disapproved" =>
            "status-danger",

        default =>
            "status-neutral"
    };
}

function eaChange(
    float $current,
    float $previous
): ?float {
    if ($previous == 0.0) {
        return $current == 0.0
            ? 0.0
            : null;
    }

    return
        (($current - $previous) / $previous)
        * 100;
}

function eaChangeText(
    ?float $change
): string {
    if ($change === null) {
        return "New";
    }

    return
        ($change > 0 ? "+" : "")
        . number_format(
            $change,
            1
        )
        . "%";
}

function eaSqlBind(
    mysqli_stmt $stmt,
    string $types,
    array &$params
): void {
    if (
        $types !== ""
        &&
        $params
    ) {
        $stmt->bind_param(
            $types,
            ...$params
        );
    }
}

function eaScalar(
    mysqli $conn,
    string $sql,
    string $types = "",
    array $params = []
): int|float|string {
    $stmt = $conn->prepare(
        $sql
    );

    if (
        $types !== ""
        &&
        $params
    ) {
        $stmt->bind_param(
            $types,
            ...$params
        );
    }

    $stmt->execute();

    $row = $stmt
        ->get_result()
        ->fetch_row();

    $stmt->close();

    return $row[0] ?? 0;
}

function eaMonthShort(
    int $month
): string {
    return match ($month) {
        1 => "Jan",
        2 => "Feb",
        3 => "Mar",
        4 => "Apr",
        5 => "May",
        6 => "Jun",
        7 => "Jul",
        8 => "Aug",
        9 => "Sep",
        10 => "Oct",
        11 => "Nov",
        default => "Dec"
    };
}

/*
|--------------------------------------------------------------------------
| Canonical staff identity — merge name variants
|--------------------------------------------------------------------------
| One person can appear under several spellings across history
| (e.g. "ACE R. CABUGA" in legacy reviewed_by free text vs
| "ACE RAZ CABUGA" in users). Normalization removes periods,
| collapses whitespace and uppercases, so variants map to one
| canonical display name. $staffAliasDisplay seeds known merges;
| every active user also registers their own normalized form.
| Grouping + staff filters use this so ALL derived metrics
| (handled, completed, completion %, evaluated, avg rating,
| avg turnaround, revisions) update from the merged set.
|--------------------------------------------------------------------------
*/

function eaNormStaffName(string $name): string
{
    $norm = mb_strtoupper(trim($name));
    $norm = str_replace(".", "", $norm);
    $norm = (string)preg_replace('/\s+/', ' ', $norm);

    return trim($norm);
}

function eaCanonicalStaffDisplay(
    string $name,
    array $aliasMap
): string {
    $norm = eaNormStaffName($name);

    return $aliasMap[$norm] ?? trim($name);
}

/*
| Known historical aliases (normalized => canonical display).
| Add future variants here; normalization handles new
| period/spacing/case differences automatically.
*/
$staffAliasDisplay = [
    eaNormStaffName("ACE R. CABUGA") => "ACE RAZ CABUGA",
    eaNormStaffName("ACE RAZ CABUGA") => "ACE RAZ CABUGA",
];

/* Normalized name => user id + canonical display (active HPU users). */
$staffIdByNormName = [];
$staffDisplayByNormName = [];

function eaRegisterStaffMaps(
    mysqli $conn,
    array $aliasMap,
    array &$idByNorm,
    array &$displayByNorm
): void {
    $res = $conn->query(
        "SELECT id, name FROM users
          WHERE role IN ('staff','supervisor','admin')
            AND status = 'Active'"
    );

    if (!$res) {
        return;
    }

    foreach ($res->fetch_all(MYSQLI_ASSOC) as $u) {
        $display = trim((string)($u["name"] ?? ""));

        if ($display === "") {
            continue;
        }

        $norm = eaNormStaffName($display);
        $idByNorm[$norm] = (int)$u["id"];

        if (!isset($displayByNorm[$norm])) {
            $displayByNorm[$norm] = $display;
        }
    }

    /*
    | Link alias spellings to the same user id/display, so a legacy
    | variant (e.g. "ACE R CABUGA") resolves straight to the user
    | (e.g. id 10, "ACE RAZ CABUGA") in grouping and filters.
    */
    foreach ($aliasMap as $aliasNorm => $display) {
        $canonNorm = eaNormStaffName((string)$display);

        if (
            isset($idByNorm[$canonNorm])
            &&
            !isset($idByNorm[$aliasNorm])
        ) {
            $idByNorm[$aliasNorm] = $idByNorm[$canonNorm];
        }

        if (
            isset($displayByNorm[$canonNorm])
            &&
            !isset($displayByNorm[$aliasNorm])
        ) {
            $displayByNorm[$aliasNorm] =
                $displayByNorm[$canonNorm];
        } elseif (!isset($displayByNorm[$aliasNorm])) {
            $displayByNorm[$aliasNorm] = $display;
        }
    }
}

/*
| All reviewed_by spellings that belong to one canonical person.
| Used to expand legacy-orphan matching (assigned_to IS NULL rows).
*/
function eaStaffNameVariants(
    string $canonicalDisplay,
    array $aliasMap
): array {
    $wanted = eaNormStaffName($canonicalDisplay);
    $variants = [];

    foreach ($aliasMap as $norm => $display) {
        if (eaNormStaffName($display) === $wanted) {
            $variants[] = $norm;
        }
    }

    return array_values(array_unique($variants));
}

/*
| Resolve a staff filter value (id or free-text name) to a
| canonical user id + display + legacy name variants, if known.
*/
function eaResolveStaffFilter(
    string $staffFilter,
    bool $hasAssignedTo,
    mysqli $conn,
    array $aliasMap,
    array $idByNorm,
    array $displayByNorm
): array {
    $resolved = [
        "id" => 0,
        "display" => "",
        "variants" => []
    ];

    if ($staffFilter === "") {
        return $resolved;
    }

    if ($hasAssignedTo && ctype_digit($staffFilter)) {
        $uid = (int)$staffFilter;

        $stmt = $conn->prepare(
            "SELECT name FROM users WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $urow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $display = trim((string)($urow["name"] ?? ""));

        if ($display === "") {
            return $resolved;
        }

        $resolved["id"] = $uid;
        $resolved["display"] =
            $aliasMap[eaNormStaffName($display)] ?? $display;
        $resolved["variants"] = eaStaffNameVariants(
            $resolved["display"],
            $aliasMap
        );

        return $resolved;
    }

    $norm = eaNormStaffName($staffFilter);

    /* Resolve through the canonical display so alias spellings
    | (e.g. "ACE R. CABUGA") find the user ("ACE RAZ CABUGA"). */
    $canonDisplay = $aliasMap[$norm] ?? $staffFilter;
    $canonNorm = eaNormStaffName($canonDisplay);

    if (isset($idByNorm[$canonNorm])) {
        $uid = (int)$idByNorm[$canonNorm];
        $display = (string)(
            $displayByNorm[$canonNorm] ?? $canonDisplay
        );

        $resolved["id"] = $uid;
        $resolved["display"] = $display;
        $resolved["variants"] = eaStaffNameVariants(
            $display,
            $aliasMap
        );

        return $resolved;
    }

    if (isset($idByNorm[$norm])) {
        $uid = (int)$idByNorm[$norm];
        $display = (string)(
            $displayByNorm[$norm] ?? $staffFilter
        );

        $resolved["id"] = $uid;
        $resolved["display"] = $display;
        $resolved["variants"] = eaStaffNameVariants(
            $display,
            $aliasMap
        );

        return $resolved;
    }

    $resolved["display"] = eaCanonicalStaffDisplay(
        $staffFilter,
        $aliasMap
    );
    $resolved["variants"] = eaStaffNameVariants(
        $resolved["display"],
        $aliasMap
    );

    return $resolved;
}

/*
|--------------------------------------------------------------------------
| Feature Detection
|--------------------------------------------------------------------------
*/

$hasClientSurvey = eaTableExists(
    $conn,
    "job_request_client_experience"
);

$hasAssignedTo = eaColumnExists(
    $conn,
    "job_requests",
    "assigned_to"
);

$hasPriority = eaColumnExists(
    $conn,
    "job_requests",
    "priority"
);

$hasArchived = eaColumnExists(
    $conn,
    "job_requests",
    "archived"
);

$hasPublicToken = eaColumnExists(
    $conn,
    "job_requests",
    "public_token"
);

$hasIecItems = eaTableExists(
    $conn,
    "job_request_iec_items"
);

$hasIecLibrary = eaTableExists(
    $conn,
    "iec_materials"
);

$hasIecLogs = eaTableExists(
    $conn,
    "iec_material_logs"
);

$hasIecFulfilled = $hasIecItems && eaColumnExists(
    $conn,
    "job_request_iec_items",
    "fulfilled_qty"
);

$hasIecLinked = $hasIecItems && eaColumnExists(
    $conn,
    "job_request_iec_items",
    "iec_material_id"
);

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$services = [
    "Graphic Design",
    "Video Production",
    "Photo/Video Coverage",
    "IEC Materials",
    "Publication Submission"
];

$completedStatuses = [
    "Completed",
    "For Evaluation",
    "Closed"
];

$currentYear = (int)date("Y");

$selectedYear = filter_input(
    INPUT_GET,
    "year",
    FILTER_VALIDATE_INT
);

if (!$selectedYear) {
    $selectedYear = $currentYear;
}

$departmentFilter = trim(
    (string)(
        $_GET["department"]
        ?? ""
    )
);

$serviceFilter = trim(
    (string)(
        $_GET["service"]
        ?? ""
    )
);

$staffFilter = trim(
    (string)(
        $_GET["staff"]
        ?? ""
    )
);

$ratingFilter = filter_input(
    INPUT_GET,
    "rating",
    FILTER_VALIDATE_INT
);

if (
    $ratingFilter !== null
    &&
    (
        $ratingFilter < 1
        ||
        $ratingFilter > 5
    )
) {
    $ratingFilter = null;
}

$evaluationFilter = trim(
    (string)(
        $_GET["evaluation"]
        ?? ""
    )
);

if (
    !in_array(
        $evaluationFilter,
        [
            "",
            "evaluated",
            "pending"
        ],
        true
    )
) {
    $evaluationFilter = "";
}

$outcomeFilter = trim(
    (string)(
        $_GET["outcome"]
        ?? ""
    )
);

// Accuracy: outcome values are dynamic (Yes / Partially / No).
// Validate against live DISTINCT values instead of hard-coding Yes/No.
$outcomeOptions = [];

if ($hasClientSurvey) {
    $outcomeStmt = $conn->query(
        "SELECT DISTINCT outcome_assessment
         FROM job_request_client_experience
         WHERE outcome_assessment IS NOT NULL
           AND outcome_assessment <> ''
         ORDER BY outcome_assessment"
    );

    if ($outcomeStmt) {
        foreach (
            $outcomeStmt->fetch_all(MYSQLI_ASSOC)
            as $outcomeRow
        ) {
            $label = trim(
                (string)(
                    $outcomeRow["outcome_assessment"]
                    ?? ""
                )
            );

            if ($label !== "") {
                $outcomeOptions[] = $label;
            }
        }
    }
}

if (
    $outcomeFilter !== ""
    &&
    !in_array(
        $outcomeFilter,
        $outcomeOptions,
        true
    )
) {
    $outcomeFilter = "";
}

if (
    $serviceFilter !== ""
    &&
    !in_array(
        $serviceFilter,
        $services,
        true
    )
) {
    $serviceFilter = "";
}

$trackerStatuses = [
    "Pending Review",
    "Under Review",
    "Approved",
    "In Development",
    "For Revision",
    "Disapproved",
    "Completed",
    "For Evaluation",
    "Closed"
];

$statusFilter = trim(
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
        $trackerStatuses,
        true
    )
) {
    $statusFilter = "";
}

$trackerPriorities = [
    "Low",
    "Normal",
    "High",
    "Urgent"
];

$priorityFilter = trim(
    (string)(
        $_GET["priority"]
        ?? ""
    )
);

if (
    $priorityFilter !== ""
    &&
    !in_array(
        $priorityFilter,
        $trackerPriorities,
        true
    )
) {
    $priorityFilter = "";
}

/*
|--------------------------------------------------------------------------
| Requestor role filter — HPU Staff / Supervisor / User analytics
|--------------------------------------------------------------------------
| requestor is FK to users.id; role comes from the requestor account.
| Deleted accounts fall back to "Unknown".
|--------------------------------------------------------------------------
*/

$requestorRoles = [
    "staff",
    "supervisor",
    "user",
    "admin"
];

$requestorRoleFilter = strtolower(
    trim(
        (string)(
            $_GET["requestor_role"]
            ?? ""
        )
    )
);

if (
    $requestorRoleFilter !== ""
    &&
    !in_array(
        $requestorRoleFilter,
        $requestorRoles,
        true
    )
) {
    $requestorRoleFilter = "";
}

/*
|--------------------------------------------------------------------------
| Archived scope — accuracy-critical
|--------------------------------------------------------------------------
| Archived=1 rows hold 90%+ of historical evaluations
| (all Closed surveys). Excluding them by default makes
| response rates collapse to ~9%. Analytics must include
| archived history by default with an explicit toggle.
|--------------------------------------------------------------------------
*/

$archivedFilter = trim(
    (string)(
        $_GET["archived"]
        ?? "all"
    )
);

if (
    !in_array(
        $archivedFilter,
        ["all", "active", "archived"],
        true
    )
) {
    $archivedFilter = "all";
}

if (!$hasArchived) {
    $archivedFilter = "all";
}

/*
|--------------------------------------------------------------------------
| Filter Options
|--------------------------------------------------------------------------
*/

$departments = $conn->query(
    "SELECT DISTINCT department
     FROM job_requests
     WHERE
        department IS NOT NULL
        AND department <> ''
     ORDER BY department"
)->fetch_all(
    MYSQLI_ASSOC
);

$yearRows = $conn->query(
    "SELECT DISTINCT
        YEAR(date_request) AS y
     FROM job_requests
     WHERE date_request IS NOT NULL
     ORDER BY y DESC"
)->fetch_all(
    MYSQLI_ASSOC
);

$years = [];

foreach ($yearRows as $yearRow) {
    $year = (int)(
        $yearRow["y"]
        ?? 0
    );

    if ($year > 0) {
        $years[] = $year;
    }
}

if (
    !in_array(
        $selectedYear,
        $years,
        true
    )
) {
    array_unshift(
        $years,
        $selectedYear
    );
}

$staffOptions = [];

if ($hasAssignedTo) {
    $staffOptions = $conn->query(
        "SELECT
            id,
            name
         FROM users
         WHERE
            role IN (
                'staff',
                'supervisor',
                'admin'
            )
            AND status = 'Active'
         ORDER BY name"
    )->fetch_all(
        MYSQLI_ASSOC
    );
} else {
    $legacyStaff = $conn->query(
        "SELECT DISTINCT reviewed_by AS name
         FROM job_requests
         WHERE
            reviewed_by IS NOT NULL
            AND reviewed_by <> ''
         ORDER BY reviewed_by"
    )->fetch_all(
        MYSQLI_ASSOC
    );

    foreach ($legacyStaff as $person) {
        $staffOptions[] = [
            "id" => 0,
            "name" =>
                (string)$person["name"]
        ];
    }
}

/* Register canonical staff maps (merge name variants like ACE R. CABUGA). */
eaRegisterStaffMaps(
    $conn,
    $staffAliasDisplay,
    $staffIdByNormName,
    $staffDisplayByNormName
);

/*
|--------------------------------------------------------------------------
| Staff Role Scope — HPU staff have FULL page access
|--------------------------------------------------------------------------
| Staff, supervisors and admins all use the same filter form.
| Staff default to All (team-wide) so the page is fully accessible;
| they can narrow to their own work via the HPU Personnel dropdown.
| Read-only analytics — no edit actions are exposed here.
|--------------------------------------------------------------------------
*/

$forcedStaffName = "";

$staffHasExplicitFilter = array_key_exists("staff", $_GET);

if ($isStaff && !$staffHasExplicitFilter) {
    // First visit: show full team view (accessible), not locked.
    $staffFilter = "";
}

$isStaffViewingMine = false;

if ($isStaff && $staffFilter !== "") {
    if ($hasAssignedTo && ctype_digit($staffFilter)) {
        $isStaffViewingMine = ((int)$staffFilter === $userId);
    } else {
        $isStaffViewingMine = ($staffFilter === $userName);
    }
}

/*
|--------------------------------------------------------------------------
| Main Query Builder
|--------------------------------------------------------------------------
*/

function eaLoadRows(
    mysqli $conn,
    int $year,
    string $departmentFilter,
    string $serviceFilter,
    string $staffFilter,
    ?int $ratingFilter,
    string $evaluationFilter,
    string $outcomeFilter,
    string $statusFilter,
    string $priorityFilter,
    string $archivedFilter,
    string $requestorRoleFilter,
    bool $hasClientSurvey,
    bool $hasAssignedTo,
    bool $hasPriority,
    bool $hasArchived,
    bool $hasPublicToken,
    bool $isStaff,
    int $userId,
    string $userName
): array {
    $clientSelect = $hasClientSurvey
        ? "
            ce.id AS client_survey_id,
            ce.timeliness,
            ce.responsiveness,
            ce.quality_output,
            ce.professionalism,
            ce.communication,
            ce.service_met_needs,
            ce.overall_satisfaction
                AS client_overall_satisfaction,
            ce.outcome_assessment,
            ce.phu_did_well,
            ce.phu_improve,
            ce.submitted_at
                AS client_submitted_at
          "
        : "
            NULL AS client_survey_id,
            NULL AS timeliness,
            NULL AS responsiveness,
            NULL AS quality_output,
            NULL AS professionalism,
            NULL AS communication,
            NULL AS service_met_needs,
            NULL AS client_overall_satisfaction,
            NULL AS outcome_assessment,
            NULL AS phu_did_well,
            NULL AS phu_improve,
            NULL AS client_submitted_at
          ";

    $clientJoin = $hasClientSurvey
        ? "
            LEFT JOIN job_request_client_experience ce
                ON ce.request_id = jr.id
          "
        : "";

    $assignedSelect = $hasAssignedTo
        ? "
            jr.assigned_to,
            assignee.name AS assigned_name,
          "
        : "
            NULL AS assigned_to,
            NULL AS assigned_name,
          ";

    $assignedJoin = $hasAssignedTo
        ? "
            LEFT JOIN users assignee
                ON assignee.id = jr.assigned_to
          "
        : "";

    $prioritySelect = $hasPriority
        ? "jr.priority"
        : "'Normal' AS priority";

    $tokenSelect = $hasPublicToken
        ? "jr.public_token"
        : "NULL AS public_token";

    $sql = "
        SELECT
            jr.id,
            jr.job_no,
            jr.project_title,
            jr.requestor AS requestor_id,
            jr.requestor_name,
            COALESCE(requestor_u.role, '') AS requestor_role,
            jr.contact,
            jr.department,
            jr.request_types,
            jr.date_request,
            jr.date_completion,
            jr.final_approval_date,
            jr.approved_date,
            jr.initial_draft_date,
            jr.approval_status,
            jr.reviewed_by,
            jr.approved_by,
            jr.revision_count,
            jr.delivered_on_time,
            jr.objectives_met,
            jr.challenges_encountered,
            jr.challenges_details,
            jr.improvement_suggestions,
            jr.satisfaction_rating,
            jr.created_at,
            jr.updated_at,
            " . ($hasArchived ? "jr.archived," : "0 AS archived,") . "
            {$tokenSelect},
            {$assignedSelect}
            {$prioritySelect} AS priority,
            {$clientSelect}
        FROM job_requests jr
        LEFT JOIN users requestor_u
            ON requestor_u.id = jr.requestor
        {$assignedJoin}
        {$clientJoin}
        WHERE YEAR(jr.date_request) = ?
    ";

    $types = "i";
    $params = [
        $year
    ];

    // Accuracy: include archived history by default.
    // Only narrow when user explicitly picks active/archived.
    if ($hasArchived && $archivedFilter === "active") {
        $sql .= "
            AND COALESCE(
                jr.archived,
                0
            ) = 0
        ";
    } elseif ($hasArchived && $archivedFilter === "archived") {
        $sql .= "
            AND COALESCE(
                jr.archived,
                0
            ) = 1
        ";
    }

    if ($departmentFilter !== "") {
        $sql .= "
            AND jr.department = ?
        ";

        $types .= "s";
        $params[] =
            $departmentFilter;
    }

    if ($serviceFilter !== "") {
        // Accuracy: match both plain and JSON-escaped slash forms
        // (e.g. Photo/Video vs Photo\/Video) for exact service tokens.
        $sql .= "
            AND (
                jr.request_types LIKE ?
                OR jr.request_types LIKE ?
            )
        ";

        $types .= "ss";
        $params[] =
            '%"'
            . $serviceFilter
            . '"%';
        $params[] =
            '%"'
            . str_replace("/", "\\/", $serviceFilter)
            . '"%';
    }

    if ($staffFilter !== "") {
        global $staffAliasDisplay, $staffIdByNormName, $staffDisplayByNormName;

        $resolved = eaResolveStaffFilter(
            $staffFilter,
            $hasAssignedTo,
            $conn,
            $staffAliasDisplay ?? [],
            $staffIdByNormName ?? [],
            $staffDisplayByNormName ?? []
        );

        /*
        | Legacy-orphan spellings for this person (assigned_to IS NULL
        | rows filed under a name variant, e.g. "ACE R. CABUGA").
        | Matched in PHP against DISTINCT reviewed_by values so SQL
        | needs no normalization functions.
        */
        static $reviewedBySpellings = null;

        if ($reviewedBySpellings === null) {
            $reviewedBySpellings = [];

            $spellRes = $conn->query(
                "SELECT DISTINCT reviewed_by FROM job_requests
                  WHERE reviewed_by IS NOT NULL AND reviewed_by <> ''"
            );

            if ($spellRes) {
                foreach ($spellRes->fetch_all(MYSQLI_ASSOC) as $srow) {
                    $reviewedBySpellings[] = (string)(
                        $srow["reviewed_by"] ?? ""
                    );
                }
            }
        }

        $wantedNorms = [];

        if (!empty($resolved["variants"])) {
            foreach ((array)$resolved["variants"] as $vnorm) {
                $wantedNorms[(string)$vnorm] = true;
            }
        }

        if (($resolved["display"] ?? "") !== "") {
            $wantedNorms[
                eaNormStaffName((string)$resolved["display"])
            ] = true;
        }

        if (empty($wantedNorms) && $staffFilter !== "") {
            $wantedNorms[eaNormStaffName($staffFilter)] = true;
        }

        $orphanLiterals = [];

        foreach ($reviewedBySpellings as $spelling) {
            if (isset($wantedNorms[eaNormStaffName($spelling)])) {
                $orphanLiterals[] = $spelling;
            }
        }

        $orphanLiterals = array_values(array_unique($orphanLiterals));

        if ($hasAssignedTo) {
            // Accuracy: prefer ID-based ownership (names can collide
            // or vary like "ACE R. CABUGA" vs "ACE RAZ CABUGA").
            // Same rule for staff, supervisors and admins.
            // Merged: an ID filter also picks up legacy-orphan rows
            // (assigned_to IS NULL) filed under any name variant.
            if ($resolved["id"] > 0) {
                if ($orphanLiterals) {
                    $placeholders = implode(
                        ",",
                        array_fill(0, count($orphanLiterals), "?")
                    );

                    $sql .= "
                        AND (
                            jr.assigned_to = ?
                            OR (
                                jr.assigned_to IS NULL
                                AND jr.reviewed_by IN ({$placeholders})
                            )
                        )
                    ";

                    $types .= "i" . str_repeat("s", count($orphanLiterals));
                    $params[] = (int)$resolved["id"];

                    foreach ($orphanLiterals as $lit) {
                        $params[] = $lit;
                    }
                } else {
                    $sql .= "
                        AND jr.assigned_to = ?
                    ";

                    $types .= "i";
                    $params[] = (int)$resolved["id"];
                }
            } else {
                $literals = $orphanLiterals;

                if ($staffFilter !== "" && !in_array($staffFilter, $literals, true)) {
                    $literals[] = $staffFilter;
                }

                $placeholders = implode(
                    ",",
                    array_fill(0, count($literals), "?")
                );

                $sql .= "
                    AND (
                        assignee.name IN ({$placeholders})
                        OR jr.reviewed_by IN ({$placeholders})
                    )
                ";

                $types .= str_repeat("s", count($literals) * 2);

                foreach ($literals as $lit) {
                    $params[] = $lit;
                }

                foreach ($literals as $lit) {
                    $params[] = $lit;
                }
            }
        } else {
            $literals = $orphanLiterals;

            if ($staffFilter !== "" && !in_array($staffFilter, $literals, true)) {
                $literals[] = $staffFilter;
            }

            $placeholders = implode(
                ",",
                array_fill(0, count($literals), "?")
            );

            $sql .= "
                AND jr.reviewed_by IN ({$placeholders})
            ";

            $types .= str_repeat("s", count($literals));

            foreach ($literals as $lit) {
                $params[] = $lit;
            }
        }
    }

    if ($ratingFilter) {
        if ($hasClientSurvey) {
            $sql .= "
                AND COALESCE(
                    ce.overall_satisfaction,
                    jr.satisfaction_rating
                ) = ?
            ";
        } else {
            $sql .= "
                AND jr.satisfaction_rating = ?
            ";
        }

        $types .= "i";
        $params[] =
            $ratingFilter;
    }

    if ($evaluationFilter === "evaluated") {
        if ($hasClientSurvey) {
            $sql .= "
                AND (
                    ce.id IS NOT NULL
                    OR jr.satisfaction_rating
                        BETWEEN 1 AND 5
                )
            ";
        } else {
            $sql .= "
                AND jr.satisfaction_rating
                    BETWEEN 1 AND 5
            ";
        }
    }

    if ($evaluationFilter === "pending") {
        $sql .= "
            AND jr.approval_status IN (
                'Completed',
                'For Evaluation',
                'Closed'
            )
        ";

        if ($hasClientSurvey) {
            $sql .= "
                AND ce.id IS NULL
                AND (
                    jr.satisfaction_rating IS NULL
                    OR jr.satisfaction_rating NOT BETWEEN 1 AND 5
                )
            ";
        } else {
            $sql .= "
                AND (
                    jr.satisfaction_rating IS NULL
                    OR jr.satisfaction_rating NOT BETWEEN 1 AND 5
                )
            ";
        }
    }

    if (
        $hasClientSurvey
        &&
        $outcomeFilter !== ""
    ) {
        $sql .= "
            AND ce.outcome_assessment = ?
        ";

        $types .= "s";
        $params[] =
            $outcomeFilter;
    }

    if ($statusFilter !== "") {
        $sql .= "
            AND jr.approval_status = ?
        ";

        $types .= "s";
        $params[] =
            $statusFilter;
    }

    if ($priorityFilter !== "" && $hasPriority) {
        $sql .= "
            AND jr.priority = ?
        ";

        $types .= "s";
        $params[] =
            $priorityFilter;
    }

    if ($requestorRoleFilter !== "") {
        $sql .= "
            AND COALESCE(requestor_u.role, '') = ?
        ";

        $types .= "s";
        $params[] =
            $requestorRoleFilter;
    }

    $sql .= "
        ORDER BY
            jr.date_request DESC,
            jr.id DESC
    ";

    $stmt = $conn->prepare(
        $sql
    );

    eaSqlBind(
        $stmt,
        $types,
        $params
    );

    $stmt->execute();

    $rows = $stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

    $stmt->close();

    return $rows;
}

$rows = eaLoadRows(
    $conn,
    $selectedYear,
    $departmentFilter,
    $serviceFilter,
    $staffFilter,
    $ratingFilter,
    $evaluationFilter,
    $outcomeFilter,
    $statusFilter,
    $priorityFilter,
    $archivedFilter,
    $requestorRoleFilter,
    $hasClientSurvey,
    $hasAssignedTo,
    $hasPriority,
    $hasArchived,
    $hasPublicToken,
    $isStaff,
    $userId,
    $userName
);

/*
|--------------------------------------------------------------------------
| KPI + Analytics
|--------------------------------------------------------------------------
*/

$totalRequests = count($rows);
$completedRequests = 0;
$evaluatedRequests = 0;
$evaluatedCompleted = 0;
$pendingEvaluations = 0;
$ratingTotal = 0;
$ratingCount = 0;
$lowRatingCount = 0;
$outcomeCounts = [];
$outcomeYes = 0;
$outcomePartial = 0;
$outcomeNo = 0;
$challengeCount = 0;
$turnaroundTotal = 0;
$turnaroundCount = 0;
$turnaroundMissing = 0;
$turnaroundAnomaly = 0;
$archivedIncluded = 0;
$crossYearCompletions = 0;
$crossYearEvaluations = 0;

$clientSurveyCount = 0;
$clientCriteria = [
    "timeliness" => [
        "label" => "Timeliness",
        "total" => 0,
        "count" => 0
    ],
    "responsiveness" => [
        "label" => "Responsiveness",
        "total" => 0,
        "count" => 0
    ],
    "quality_output" => [
        "label" => "Quality of Output",
        "total" => 0,
        "count" => 0
    ],
    "professionalism" => [
        "label" => "Professionalism",
        "total" => 0,
        "count" => 0
    ],
    "communication" => [
        "label" => "Communication",
        "total" => 0,
        "count" => 0
    ],
    "service_met_needs" => [
        "label" => "Service Met Needs",
        "total" => 0,
        "count" => 0
    ]
];

$satisfactionDistribution = [
    1 => 0,
    2 => 0,
    3 => 0,
    4 => 0,
    5 => 0
];

$monthlyCompleted = array_fill(
    1,
    12,
    0
);

$monthlyEvaluated = array_fill(
    1,
    12,
    0
);

$monthlyResponse = array_fill(
    1,
    12,
    0.0
);

$serviceStats = [];

foreach ($services as $service) {
    $serviceStats[$service] = [
        "requests" => 0,
        "completed" => 0,
        "evaluated" => 0,
        "rating_total" => 0,
        "rating_count" => 0
    ];
}

// Analytics by requestor account role: HPU Staff / Supervisor / User (+ Admin/Unknown).
$requestorRoleLabels = [
    "staff" => "HPU Staff",
    "supervisor" => "Supervisor",
    "user" => "User",
    "admin" => "Admin",
    "unknown" => "Unknown / Deleted"
];

$requestorRoleStats = [];

foreach ($requestorRoleLabels as $roleKey => $roleLabel) {
    $requestorRoleStats[$roleKey] = [
        "label" => $roleLabel,
        "requests" => 0,
        "completed" => 0,
        "evaluated" => 0,
        "rating_total" => 0,
        "rating_count" => 0
    ];
}

function eaRequestorRoleKey(array $row): string {
    $role = strtolower(trim((string)($row["requestor_role"] ?? "")));

    return match ($role) {
        "staff", "supervisor", "user", "admin" => $role,
        default => "unknown"
    };
}

$staffStats = [];
$lowRatingRows = [];
$feedbackRows = [];

foreach ($rows as $row) {
    $status = (string)(
        $row["approval_status"]
        ?? ""
    );

    $isCompleted = in_array(
        $status,
        $completedStatuses,
        true
    );

    if ($isCompleted) {
        $completedRequests++;
    }

    if ((int)($row["archived"] ?? 0) === 1) {
        $archivedIncluded++;
    }

    $rating = eaCombinedRating(
        $row
    );

    $isEvaluated =
        $rating >= 1
        &&
        $rating <= 5;

    if ($isEvaluated) {
        $evaluatedRequests++;
        $ratingTotal += $rating;
        $ratingCount++;
        $satisfactionDistribution[
            $rating
        ]++;

        if ($isCompleted) {
            $evaluatedCompleted++;
        }

        if ($rating <= 2) {
            $lowRatingCount++;

            $lowRatingRows[] =
                $row;
        }

    }

    // Pending = completed cohort minus completed-with-rating (accurate).
    // Computed after loop to avoid double-counting non-completed ratings.

    if (
        !empty(
            $row["client_survey_id"]
        )
    ) {
        $clientSurveyCount++;

        foreach (
            $clientCriteria
            as $key => &$criterion
        ) {
            $score = (int)(
                $row[$key]
                ?? 0
            );

            if (
                $score >= 1
                &&
                $score <= 5
            ) {
                $criterion["total"] +=
                    $score;

                $criterion["count"]++;
            }
        }
        unset($criterion);

        $outcome = trim(
            (string)(
                $row["outcome_assessment"]
                ?? ""
            )
        );

        if ($outcome !== "") {
            $outcomeCounts[$outcome] =
                ($outcomeCounts[$outcome] ?? 0) + 1;

            if ($outcome === "Yes") {
                $outcomeYes++;
            } elseif (
                $outcome === "Partially"
                || stripos($outcome, "partial") !== false
            ) {
                $outcomePartial++;
            } elseif ($outcome === "No") {
                $outcomeNo++;
            }
        }

        $didWell = trim(
            (string)(
                $row["phu_did_well"]
                ?? ""
            )
        );

        $improve = trim(
            (string)(
                $row["phu_improve"]
                ?? ""
            )
        );

        if (
            $didWell !== ""
            ||
            $improve !== ""
        ) {
            $feedbackRows[] = [
                "job_no" =>
                    $row["job_no"],

                "project_title" =>
                    $row["project_title"],

                "did_well" =>
                    $didWell,

                "improve" =>
                    $improve,

                "rating" =>
                    $rating,

                "submitted_at" =>
                    $row["client_submitted_at"]
                    ?? null
            ];
        }
    }

    if (
        (int)(
            $row["challenges_encountered"]
            ?? 0
        ) === 1
    ) {
        $challengeCount++;
    }

    $actualCompletion = eaActualCompletion($row);
    $targetDate = eaTargetDate($row);

    $turnaround = eaDateDiffDays(
        eaValidDateOrNull($row["date_request"] ?? null),
        $actualCompletion
    );

    // Detect completion-before-request anomalies (excluded from avg).
    if (
        $isCompleted
        && $actualCompletion !== null
        && eaValidDateOrNull($row["date_request"] ?? null) !== null
        && $actualCompletion < substr((string)($row["date_request"] ?? ""), 0, 10)
    ) {
        $turnaroundAnomaly++;
    }

    if ($isCompleted) {
        if ($turnaround !== null) {
            $turnaroundTotal += $turnaround;
            $turnaroundCount++;
        } else {
            $turnaroundMissing++;
        }
    }

    if (
        $isCompleted
        &&
        $actualCompletion !== null
    ) {
        $compYear = (int)date(
            "Y",
            strtotime($actualCompletion)
        );
        $month = (int)date(
            "n",
            strtotime($actualCompletion)
        );

        if (
            $month >= 1
            &&
            $month <= 12
        ) {
            // Accuracy: only bucket completions belonging to the
            // selected year; cross-year completions are counted
            // separately so the trend is not silently inflated.
            if ($compYear === $selectedYear) {
                $monthlyCompleted[$month]++;
            } else {
                $crossYearCompletions++;
            }
        }
    }

    if ($isEvaluated) {
        $evaluationDate = eaEvaluationDate($row);

        if ($evaluationDate) {
            $evalYear = (int)date(
                "Y",
                strtotime($evaluationDate)
            );
            $month = (int)date(
                "n",
                strtotime($evaluationDate)
            );

            if (
                $month >= 1
                &&
                $month <= 12
            ) {
                if ($evalYear === $selectedYear) {
                    $monthlyEvaluated[$month]++;
                } else {
                    $crossYearEvaluations++;
                }
            }
        }
    }

    $requestTypes = eaDecodeArray(
        $row["request_types"]
        ?? null
    );

    foreach (
        $requestTypes
        as $requestType
    ) {
        if (
            !isset(
                $serviceStats[
                    $requestType
                ]
            )
        ) {
            continue;
        }

        $serviceStats[
            $requestType
        ]["requests"]++;

        if ($isCompleted) {
            $serviceStats[
                $requestType
            ]["completed"]++;
        }

        if ($isEvaluated) {
            $serviceStats[
                $requestType
            ]["evaluated"]++;

            $serviceStats[
                $requestType
            ]["rating_total"] +=
                $rating;

            $serviceStats[
                $requestType
            ]["rating_count"]++;
        }
    }

    // Requestor-role analytics (Staff / Supervisor / User).
    $roleKey = eaRequestorRoleKey($row);

    $requestorRoleStats[$roleKey]["requests"]++;

    if ($isCompleted) {
        $requestorRoleStats[$roleKey]["completed"]++;
    }

    if ($isEvaluated) {
        $requestorRoleStats[$roleKey]["evaluated"]++;
        $requestorRoleStats[$roleKey]["rating_total"] += $rating;
        $requestorRoleStats[$roleKey]["rating_count"]++;
    }

    /*
    |--------------------------------------------------------------------------
    | Staff ownership — ID-based + canonical name merge
    |--------------------------------------------------------------------------
    | assigned_to is authoritative. Group by staff ID to avoid
    | splitting one person across name variants
    | ("ACE R. CABUGA" vs "ACE RAZ CABUGA") or merging
    | two people who share a display name.
    | Legacy-orphan rows (assigned_to IS NULL) whose reviewed_by
    | canonicalizes to a known HPU user fold into that user's
    | id:* bucket, so every derived metric updates from the
    | merged set. Unknown spellings merge by normalized name.
    |--------------------------------------------------------------------------
    */

    $staffKey = "";
    $staffName = "";
    $staffId = (int)($row["assigned_to"] ?? 0);

    if ($hasAssignedTo && $staffId > 0) {
        $staffName = trim(
            (string)(
                $row["assigned_name"]
                ?? ""
            )
        );

        if ($staffName === "") {
            $staffName = "Staff #" . $staffId;
        }

        $staffName = eaCanonicalStaffDisplay(
            $staffName,
            $staffAliasDisplay
        );

        $staffKey = "id:" . $staffId;
    } else {
        $rawReviewer = trim(
            (string)(
                $row["reviewed_by"]
                ?? ""
            )
        );

        if ($rawReviewer !== "") {
            $normReviewer = eaNormStaffName($rawReviewer);

            /*
            | Fold known-user variants into their id:* bucket.
            | Resolve through the canonical display so an alias
            | spelling (e.g. "ACE R CABUGA") still finds the user
            | registered under "ACE RAZ CABUGA".
            */
            $canonDisplay = eaCanonicalStaffDisplay(
                $rawReviewer,
                $staffAliasDisplay
            );
            $canonNorm = eaNormStaffName($canonDisplay);

            if (isset($staffIdByNormName[$canonNorm])) {
                $staffId = (int)$staffIdByNormName[$canonNorm];
                $staffName = (string)(
                    $staffDisplayByNormName[$canonNorm]
                    ?? $canonDisplay
                );
                $staffKey = "id:" . $staffId;
            } elseif (isset($staffIdByNormName[$normReviewer])) {
                $staffId = (int)$staffIdByNormName[$normReviewer];
                $staffName = (string)(
                    $staffDisplayByNormName[$normReviewer]
                    ?? $canonDisplay
                );
                $staffKey = "id:" . $staffId;
            } else {
                $staffName = $canonDisplay;
                $staffKey = "canon:" . $canonNorm;
            }
        }
    }

    if ($staffKey !== "" && $staffName !== "") {
        if (
            !isset(
                $staffStats[
                    $staffKey
                ]
            )
        ) {
            $staffStats[
                $staffKey
            ] = [
                "name" => $staffName,
                "id" => $staffId,
                "handled" => 0,
                "completed" => 0,
                "evaluated" => 0,
                "rating_total" => 0,
                "rating_count" => 0,
                "turnaround_total" => 0,
                "turnaround_count" => 0,
                "revisions" => 0
            ];
        }

        $staffStats[
            $staffKey
        ]["handled"]++;

        if ($isCompleted) {
            $staffStats[
                $staffKey
            ]["completed"]++;
        }

        if ($isEvaluated) {
            $staffStats[
                $staffKey
            ]["evaluated"]++;

            $staffStats[
                $staffKey
            ]["rating_total"] +=
                $rating;

            $staffStats[
                $staffKey
            ]["rating_count"]++;
        }

        if (
            $isCompleted
            &&
            $turnaround !== null
        ) {
            $staffStats[
                $staffKey
            ]["turnaround_total"] +=
                $turnaround;

            $staffStats[
                $staffKey
            ]["turnaround_count"]++;
        }

        $staffStats[
            $staffKey
        ]["revisions"] +=
            (int)(
                $row["revision_count"]
                ?? 0
            );
    }
}

/*
|--------------------------------------------------------------------------
| Publication Submissions merge (separate workflow tables)
|--------------------------------------------------------------------------
| Event / Recognition publications live outside job_requests but share
| the Client Experience survey (publication_kind). Merge their volume
| into the "Publication Submission" row so Performance by Request Type
| is complete. Respects year + requestor-role + department (lead_office)
| + status filters where mappable; staff/priority N/A for pubs.
|--------------------------------------------------------------------------
*/

$pubCompletedStatuses = ["Published", "Closed"];

if (
    $serviceFilter === ""
    || $serviceFilter === "Publication Submission"
) {
    foreach (["publication_event_submissions", "publication_recognition_submissions"] as $pubTable) {
        if (!eaTableExists($conn, $pubTable)) {
            continue;
        }

        $isEvent = $pubTable === "publication_event_submissions";

        $pubWhere = ["YEAR(p.created_at) = ?"];
        $pubTypes = "i";
        $pubParams = [$selectedYear];

        if ($departmentFilter !== "") {
            $pubWhere[] = "p.lead_office = ?";
            $pubTypes .= "s";
            $pubParams[] = $departmentFilter;
        }

        if ($requestorRoleFilter !== "") {
            $pubWhere[] = "EXISTS (SELECT 1 FROM users ru WHERE ru.id = p.submitted_by AND ru.role = ?)";
            $pubTypes .= "s";
            $pubParams[] = $requestorRoleFilter;
        }

        if ($statusFilter !== "") {
            if (in_array($statusFilter, $pubCompletedStatuses, true)) {
                $pubWhere[] = "p.status = ?";
                $pubTypes .= "s";
                $pubParams[] = $statusFilter;
            } elseif (in_array($statusFilter, $trackerStatuses, true)) {
                // Job-only status selected → pubs contribute 0 for this filter.
                continue;
            } else {
                $pubWhere[] = "p.status = ?";
                $pubTypes .= "s";
                $pubParams[] = $statusFilter;
            }
        }

        $pubSql = "SELECT p.id, p.status FROM `{$pubTable}` p WHERE " . implode(" AND ", $pubWhere);
        $pubStmt = $conn->prepare($pubSql);
        eaSqlBind($pubStmt, $pubTypes, $pubParams);
        $pubStmt->execute();
        $pubRows = $pubStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $pubStmt->close();

        foreach ($pubRows as $pubRow) {
            $serviceStats["Publication Submission"]["requests"]++;

            $pubCompleted = in_array((string)($pubRow["status"] ?? ""), $pubCompletedStatuses, true);

            if ($pubCompleted) {
                $serviceStats["Publication Submission"]["completed"]++;
            }

            if ($hasClientSurvey) {
                $pubKind = $isEvent ? "event" : "recognition";
                $pubId = (int)$pubRow["id"];

                $surveyStmt = $conn->prepare(
                    "SELECT overall_satisfaction FROM job_request_client_experience
                     WHERE publication_kind = ? AND publication_id = ? LIMIT 1"
                );
                $surveyStmt->bind_param("si", $pubKind, $pubId);
                $surveyStmt->execute();
                $surveyRow = $surveyStmt->get_result()->fetch_assoc();
                $surveyStmt->close();

                $pubRating = (int)($surveyRow["overall_satisfaction"] ?? 0);

                if ($pubRating >= 1 && $pubRating <= 5) {
                    $serviceStats["Publication Submission"]["evaluated"]++;
                    $serviceStats["Publication Submission"]["rating_total"] += $pubRating;
                    $serviceStats["Publication Submission"]["rating_count"]++;
                }
            }
        }
    }
}

$averageRating =
    $ratingCount > 0
        ? $ratingTotal / $ratingCount
        : 0;

// Accuracy: response = completed-with-rating ÷ completed cohort.
// Non-completed ratings (if any) count in evaluatedRequests but
// must not inflate the response rate above 100%.
$pendingEvaluations = max(
    0,
    $completedRequests - $evaluatedCompleted
);

$responseRate =
    eaPercent(
        $evaluatedCompleted,
        $completedRequests
    );

$averageTurnaround =
    $turnaroundCount > 0
        ? $turnaroundTotal / $turnaroundCount
        : 0;

$outcomeTotal =
    $outcomeYes
    + $outcomePartial
    + $outcomeNo;

if ($outcomeTotal === 0) {
    $outcomeTotal = array_sum($outcomeCounts);
}

$objectiveAchievedRate =
    eaPercent(
        $outcomeYes,
        $outcomeTotal
    );

$objectivePartialRate =
    eaPercent(
        $outcomePartial,
        $outcomeTotal
    );

foreach (
    $clientCriteria
    as &$criterion
) {
    $criterion["average"] =
        $criterion["count"] > 0
            ? $criterion["total"]
              / $criterion["count"]
            : 0;
}
unset($criterion);

foreach (
    $serviceStats
    as &$stats
) {
    $stats["average_rating"] =
        $stats["rating_count"] > 0
            ? $stats["rating_total"]
              / $stats["rating_count"]
            : 0;

    $stats["response_rate"] =
        eaPercent(
            $stats["evaluated"],
            $stats["completed"]
        );
}
unset($stats);

foreach (
    $requestorRoleStats
    as &$roleStats
) {
    $roleStats["average_rating"] =
        $roleStats["rating_count"] > 0
            ? $roleStats["rating_total"]
              / $roleStats["rating_count"]
            : 0;

    $roleStats["response_rate"] =
        eaPercent(
            $roleStats["evaluated"],
            $roleStats["completed"]
        );
}
unset($roleStats);

foreach (
    $staffStats
    as &$stats
) {
    $stats["average_rating"] =
        $stats["rating_count"] > 0
            ? $stats["rating_total"]
              / $stats["rating_count"]
            : 0;

    $stats["average_turnaround"] =
        $stats["turnaround_count"] > 0
            ? $stats["turnaround_total"]
              / $stats["turnaround_count"]
            : 0;

    $stats["completion_rate"] =
        eaPercent(
            $stats["completed"],
            $stats["handled"]
        );
}
unset($stats);

uasort(
    $staffStats,
    static fn (
        array $a,
        array $b
    ): int =>
        $b["completed"]
        <=>
        $a["completed"]
);

for (
    $month = 1;
    $month <= 12;
    $month++
) {
    $monthlyResponse[
        $month
    ] = eaPercent(
        $monthlyEvaluated[
            $month
        ],
        $monthlyCompleted[
            $month
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Year Comparison
|--------------------------------------------------------------------------
*/

function eaYearSummary(
    mysqli $conn,
    int $year,
    string $departmentFilter,
    string $serviceFilter,
    string $staffFilter,
    ?int $ratingFilter,
    string $evaluationFilter,
    string $outcomeFilter,
    string $statusFilter,
    string $priorityFilter,
    string $archivedFilter,
    string $requestorRoleFilter,
    bool $hasClientSurvey,
    bool $hasAssignedTo,
    bool $hasPriority,
    bool $hasArchived,
    bool $hasPublicToken,
    bool $isStaff,
    int $userId,
    string $userName
): array {
    $records = eaLoadRows(
        $conn,
        $year,
        $departmentFilter,
        $serviceFilter,
        $staffFilter,
        $ratingFilter,
        $evaluationFilter,
        $outcomeFilter,
        $statusFilter,
        $priorityFilter,
        $archivedFilter,
        $requestorRoleFilter,
        $hasClientSurvey,
        $hasAssignedTo,
        $hasPriority,
        $hasArchived,
        $hasPublicToken,
        $isStaff,
        $userId,
        $userName
    );

    $completed = 0;
    $evaluatedCompleted = 0;
    $ratingTotal = 0;
    $evaluatedAll = 0;
    $turnaroundTotal = 0;
    $turnaroundCount = 0;
    $clientYes = 0;
    $clientOutcome = 0;

    foreach ($records as $row) {
        $status = (string)(
            $row["approval_status"]
            ?? ""
        );

        $isCompleted = in_array(
            $status,
            [
                "Completed",
                "For Evaluation",
                "Closed"
            ],
            true
        );

        if ($isCompleted) {
            $completed++;
        }

        $rating = eaCombinedRating(
            $row
        );

        $isEvaluated = $rating >= 1 && $rating <= 5;

        if ($isEvaluated) {
            $evaluatedAll++;
            $ratingTotal += $rating;

            if ($isCompleted) {
                $evaluatedCompleted++;
            }
        }

        $actual = eaActualCompletion($row);

        $days =
            eaDateDiffDays(
                eaValidDateOrNull($row["date_request"] ?? null),
                $actual
            );

        if (
            $isCompleted
            &&
            $days !== null
        ) {
            $turnaroundTotal +=
                $days;

            $turnaroundCount++;
        }

        $outcome = trim(
            (string)(
                $row["outcome_assessment"]
                ?? ""
            )
        );

        if ($outcome !== "") {
            $clientOutcome++;

            if ($outcome === "Yes") {
                $clientYes++;
            }
        }
    }

    return [
        "completed" =>
            $completed,

        "evaluated" =>
            $evaluatedCompleted,

        "response_rate" =>
            eaPercent(
                $evaluatedCompleted,
                $completed
            ),

        "average_rating" =>
            $evaluatedAll > 0
                ? $ratingTotal
                  / $evaluatedAll
                : 0,

        "average_turnaround" =>
            $turnaroundCount > 0
                ? $turnaroundTotal
                  / $turnaroundCount
                : 0,

        "objective_rate" =>
            eaPercent(
                $clientYes,
                $clientOutcome
            )
    ];
}

$currentSummary = eaYearSummary(
    $conn,
    $selectedYear,
    $departmentFilter,
    $serviceFilter,
    $staffFilter,
    $ratingFilter,
    $evaluationFilter,
    $outcomeFilter,
    $statusFilter,
    $priorityFilter,
    $archivedFilter,
    $requestorRoleFilter,
    $hasClientSurvey,
    $hasAssignedTo,
    $hasPriority,
    $hasArchived,
    $hasPublicToken,
    $isStaff,
    $userId,
    $userName
);

$previousSummary = eaYearSummary(
    $conn,
    $selectedYear - 1,
    $departmentFilter,
    $serviceFilter,
    $staffFilter,
    $ratingFilter,
    $evaluationFilter,
    $outcomeFilter,
    $statusFilter,
    $priorityFilter,
    $archivedFilter,
    $requestorRoleFilter,
    $hasClientSurvey,
    $hasAssignedTo,
    $hasPriority,
    $hasArchived,
    $hasPublicToken,
    $isStaff,
    $userId,
    $userName
);

$comparisonRows = [
    [
        "label" =>
            "Evaluation Response Rate",

        "current" =>
            $currentSummary[
                "response_rate"
            ],

        "previous" =>
            $previousSummary[
                "response_rate"
            ],

        "suffix" => "%",
        "decimals" => 1,
        "higher_better" => true
    ],
    [
        "label" =>
            "Overall Satisfaction",

        "current" =>
            $currentSummary[
                "average_rating"
            ],

        "previous" =>
            $previousSummary[
                "average_rating"
            ],

        "suffix" => " / 5",
        "decimals" => 2,
        "higher_better" => true
    ],
    [
        "label" =>
            "Objective Achieved",

        "current" =>
            $currentSummary[
                "objective_rate"
            ],

        "previous" =>
            $previousSummary[
                "objective_rate"
            ],

        "suffix" => "%",
        "decimals" => 1,
        "higher_better" => true
    ],
    [
        "label" =>
            "Average Turnaround",

        "current" =>
            $currentSummary[
                "average_turnaround"
            ],

        "previous" =>
            $previousSummary[
                "average_turnaround"
            ],

        "suffix" => " days",
        "decimals" => 1,
        "higher_better" => false
    ]
];

/*
|--------------------------------------------------------------------------
| Chart Data
|--------------------------------------------------------------------------
*/

$monthLabels = [];

for (
    $month = 1;
    $month <= 12;
    $month++
) {
    $monthLabels[] =
        date(
            "M",
            mktime(
                0,
                0,
                0,
                $month,
                1
            )
        );
}

$criteriaLabels = [];
$criteriaValues = [];

foreach (
    $clientCriteria
    as $criterion
) {
    $criteriaLabels[] =
        $criterion["label"];

    $criteriaValues[] =
        round(
            (float)$criterion[
                "average"
            ],
            2
        );
}

$requestorRoleChartLabels = [];
$requestorRoleChartRequests = [];
$requestorRoleChartEvaluated = [];
$requestorRoleChartRatings = [];

foreach ($requestorRoleStats as $roleKey => $roleStats) {
    if (($roleStats["requests"] ?? 0) <= 0) {
        continue;
    }

    $requestorRoleChartLabels[] = $roleStats["label"];
    $requestorRoleChartRequests[] = (int)$roleStats["requests"];
    $requestorRoleChartEvaluated[] = (int)$roleStats["evaluated"];
    $requestorRoleChartRatings[] = round((float)$roleStats["average_rating"], 2);
}

/*
|--------------------------------------------------------------------------
| Export Data
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| IEC Materials Consolidation
|--------------------------------------------------------------------------
| Monthly requested vs fulfilled quantities from job_request_iec_items,
| plus library-side stats (uploads, downloads, per-user report).
| Fulfilled/linked columns are optional until the Phase 2 migration.
|--------------------------------------------------------------------------
*/

function eaIecDetail(
    mysqli $conn,
    int $year,
    string $departmentFilter,
    string $serviceFilter,
    string $staffFilter,
    string $statusFilter,
    string $priorityFilter,
    string $archivedFilter,
    string $requestorRoleFilter,
    bool $isStaff,
    int $userId,
    bool $hasAssignedTo,
    bool $hasArchived,
    bool $hasPriority,
    bool $hasFulfilled,
    bool $hasLinked
): array {
    $doneSelect = $hasFulfilled
        ? "COALESCE(SUM(item.fulfilled_qty), 0)"
        : "0";

    $unlinkedSelect = $hasLinked
        ? "SUM(item.iec_material_id IS NULL)"
        : "SUM(1)";

    $sql = "
        SELECT
            MONTH(jr.date_request) AS m,
            item.material_name AS material,
            jr.department AS department,
            COALESCE(SUM(item.quantity), 0) AS qty,
            {$doneSelect} AS done,
            COUNT(DISTINCT jr.id) AS requests,
            GROUP_CONCAT(DISTINCT jr.id) AS request_ids,
            {$unlinkedSelect} AS unlinked
        FROM job_request_iec_items item
        INNER JOIN job_requests jr
            ON jr.id = item.request_id
        WHERE YEAR(jr.date_request) = ?
    ";

    $types = "i";
    $params = [$year];

    if ($hasArchived && $archivedFilter === "active") {
        $sql .= "
            AND COALESCE(
                jr.archived,
                0
            ) = 0
        ";
    } elseif ($hasArchived && $archivedFilter === "archived") {
        $sql .= "
            AND COALESCE(
                jr.archived,
                0
            ) = 1
        ";
    }

    if ($departmentFilter !== "") {
        $sql .= "
            AND jr.department = ?
        ";

        $types .= "s";
        $params[] = $departmentFilter;
    }

    if ($serviceFilter !== "") {
        $sql .= "
            AND (
                jr.request_types LIKE ?
                OR jr.request_types LIKE ?
            )
        ";

        $types .= "ss";
        $params[] = '%"' . $serviceFilter . '"%';
        $params[] = '%"' . str_replace("/", "\\/", $serviceFilter) . '"%';
    }

    if ($statusFilter !== "") {
        $sql .= "
            AND jr.approval_status = ?
        ";

        $types .= "s";
        $params[] = $statusFilter;
    }

    if ($priorityFilter !== "" && $hasPriority) {
        $sql .= "
            AND jr.priority = ?
        ";

        $types .= "s";
        $params[] = $priorityFilter;
    }

    if ($staffFilter !== "" && $hasAssignedTo) {
        if (ctype_digit($staffFilter)) {
            $sql .= "
                AND jr.assigned_to = ?
            ";

            $types .= "i";
            $params[] = (int)$staffFilter;
        } else {
            $sql .= "
                AND EXISTS (
                    SELECT 1 FROM users u
                    WHERE u.id = jr.assigned_to
                      AND u.name = ?
                )
            ";

            $types .= "s";
            $params[] = $staffFilter;
        }
    } elseif ($staffFilter !== "" && !$hasAssignedTo) {
        $sql .= "
            AND jr.reviewed_by = ?
        ";

        $types .= "s";
        $params[] = $staffFilter;
    }

    if ($requestorRoleFilter !== "") {
        $sql .= "
            AND EXISTS (
                SELECT 1 FROM users ru
                WHERE ru.id = jr.requestor
                  AND ru.role = ?
            )
        ";

        $types .= "s";
        $params[] = $requestorRoleFilter;
    }

    $sql .= "
        GROUP BY
            MONTH(jr.date_request),
            item.material_name,
            jr.department
        ORDER BY
            m ASC,
            qty DESC
    ";

    $stmt = $conn->prepare($sql);

    eaSqlBind($stmt, $types, $params);

    $stmt->execute();

    $detail = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    return $detail;
}

$iecMonths = [];

for ($iecM = 1; $iecM <= 12; $iecM++) {
    $iecMonths[$iecM] = [
        "qty" => 0,
        "done" => 0,
        "requests" => [],
        "materials" => []
    ];
}

$iecTopMaterials = [];
$iecTopDepartments = [];
$iecTotalQty = 0;
$iecTotalDone = 0;
$iecUnlinkedLines = 0;
$iecDistinctRequests = 0;

if ($hasIecItems) {
    $iecDetail = eaIecDetail(
        $conn,
        $selectedYear,
        $departmentFilter,
        $serviceFilter,
        $staffFilter,
        $statusFilter,
        $priorityFilter,
        $archivedFilter,
        $requestorRoleFilter,
        $isStaff,
        $userId,
        $hasAssignedTo,
        $hasArchived,
        $hasPriority,
        $hasIecFulfilled,
        $hasIecLinked
    );

    // Accuracy: track DISTINCT requests per month (previous code
    // initialised a requests array but never filled it).
    $iecSeenRequests = [];

    foreach ($iecDetail as $line) {
        $m = (int)($line["m"] ?? 0);

        if ($m < 1 || $m > 12) {
            continue;
        }

        $qty = (int)($line["qty"] ?? 0);
        $done = (int)($line["done"] ?? 0);
        $label = trim((string)($line["material"] ?? ""));
        $dept = trim((string)($line["department"] ?? ""));

        $ids = array_filter(
            array_map(
                "trim",
                explode(
                    ",",
                    (string)($line["request_ids"] ?? "")
                )
            )
        );

        foreach ($ids as $rid) {
            $iecSeenRequests[$m][$rid] = true;
            $iecSeenRequests[0][$rid] = true;
        }

        if ($label === "") {
            $label = "(unnamed item)";
        }

        $key = mb_strtolower($label);

        $iecMonths[$m]["qty"] += $qty;
        $iecMonths[$m]["done"] += $done;

        if (!in_array($label, $iecMonths[$m]["materials"], true)) {
            $iecMonths[$m]["materials"][] = $label;
        }

        if (!isset($iecTopMaterials[$key])) {
            $iecTopMaterials[$key] = [
                "label" => $label,
                "qty" => 0,
                "done" => 0
            ];
        }

        $iecTopMaterials[$key]["qty"] += $qty;
        $iecTopMaterials[$key]["done"] += $done;

        if ($dept === "") {
            $dept = "(no department)";
        }

        if (!isset($iecTopDepartments[$dept])) {
            $iecTopDepartments[$dept] = [
                "qty" => 0,
                "done" => 0
            ];
        }

        $iecTopDepartments[$dept]["qty"] += $qty;
        $iecTopDepartments[$dept]["done"] += $done;

        $iecTotalQty += $qty;
        $iecTotalDone += $done;
        $iecUnlinkedLines += (int)($line["unlinked"] ?? 0);
    }

    foreach ($iecMonths as $mm => $md) {
        if ($mm === 0) {
            continue;
        }

        $iecMonths[$mm]["requests"] = isset($iecSeenRequests[$mm])
            ? array_keys($iecSeenRequests[$mm])
            : [];
    }

    $iecDistinctRequests = isset($iecSeenRequests[0])
        ? count($iecSeenRequests[0])
        : 0;

    uasort(
        $iecTopMaterials,
        static fn (array $a, array $b): int =>
            $b["qty"] <=> $a["qty"]
    );

    uasort(
        $iecTopDepartments,
        static fn (array $a, array $b): int =>
            $b["qty"] <=> $a["qty"]
    );

    $iecTopMaterials = array_slice($iecTopMaterials, 0, 8, true);
    $iecTopDepartments = array_slice($iecTopDepartments, 0, 8, true);
}

$iecFulfillmentRate = eaPercent($iecTotalDone, $iecTotalQty);

$iecLibraryActive = 0;
$iecLibraryDownloads = 0;
$iecUploadsYear = 0;
$iecDownloadsYear = 0;
$iecViewsYear = 0;
$iecTopDownloaded = [];
$iecTopDownloaders = [];
$iecTopViewed = [];
$iecTopViewers = [];

$hasIecViews = $hasIecLibrary && eaColumnExists(
    $conn,
    "iec_materials",
    "view_count"
);

if ($hasIecLibrary) {
    $iecLibraryActive = (int) eaScalar(
        $conn,
        "SELECT COUNT(*)
          FROM iec_materials
          WHERE active = 1"
    );

    $iecLibraryDownloads = (int) eaScalar(
        $conn,
        "SELECT COALESCE(SUM(download_count), 0)
          FROM iec_materials
          WHERE active = 1"
    );

    $iecUploadsYear = (int) eaScalar(
        $conn,
        "SELECT COUNT(*)
          FROM iec_materials
          WHERE YEAR(uploaded_at) = ?",
        "i",
        [$selectedYear]
    );

    $dlStmt = $conn->prepare(
        $hasIecViews
            ? "SELECT title, download_count, view_count
                 FROM iec_materials
                 WHERE active = 1
                 ORDER BY download_count DESC, title ASC
                 LIMIT 5"
            : "SELECT title, download_count
                 FROM iec_materials
                 WHERE active = 1
                 ORDER BY download_count DESC, title ASC
                 LIMIT 5"
    );
    $dlStmt->execute();
    $iecTopDownloaded = $dlStmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
    $dlStmt->close();

    if ($hasIecViews) {
        $vwStmt = $conn->prepare(
            "SELECT title, view_count, download_count
              FROM iec_materials
              WHERE active = 1
              ORDER BY view_count DESC, title ASC
              LIMIT 5"
        );
        $vwStmt->execute();
        $iecTopViewed = $vwStmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);
        $vwStmt->close();
    }
}

if ($hasIecLogs) {
    $iecDownloadsYear = (int) eaScalar(
        $conn,
        "SELECT COUNT(*)
          FROM iec_material_logs
          WHERE action = 'Downloaded'
            AND YEAR(created_at) = ?",
        "i",
        [$selectedYear]
    );

    $duStmt = $conn->prepare(
        "SELECT
            u.name AS name,
            COUNT(*) AS total
          FROM iec_material_logs l
          INNER JOIN users u
            ON u.id = l.user_id
          WHERE l.action = 'Downloaded'
            AND YEAR(l.created_at) = ?
          GROUP BY u.id, u.name
          ORDER BY total DESC, name ASC
          LIMIT 5"
    );
    $duStmt->bind_param("i", $selectedYear);
    $duStmt->execute();
    $iecTopDownloaders = $duStmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
    $duStmt->close();

    $iecViewsYear = (int) eaScalar(
        $conn,
        "SELECT COUNT(*)
          FROM iec_material_logs
          WHERE action = 'Viewed'
            AND YEAR(created_at) = ?",
        "i",
        [$selectedYear]
    );

    $vuStmt = $conn->prepare(
        "SELECT
            u.name AS name,
            COUNT(*) AS total
          FROM iec_material_logs l
          INNER JOIN users u
            ON u.id = l.user_id
          WHERE l.action = 'Viewed'
            AND YEAR(l.created_at) = ?
          GROUP BY u.id, u.name
          ORDER BY total DESC, name ASC
          LIMIT 5"
    );
    $vuStmt->bind_param("i", $selectedYear);
    $vuStmt->execute();
    $iecTopViewers = $vuStmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
    $vuStmt->close();
}

/*
|--------------------------------------------------------------------------
| Data-quality audit (accuracy)
|--------------------------------------------------------------------------
*/

$orphanSurveys = 0;
$archivedTotalYear = 0;
$archivedEvaluatedYear = 0;

if ($hasClientSurvey) {
    $orphanSurveys = (int)eaScalar(
        $conn,
        "SELECT COUNT(*)
         FROM job_request_client_experience ce
         LEFT JOIN job_requests jr ON jr.id = ce.request_id
         WHERE jr.id IS NULL"
    );
}

if ($hasArchived) {
    $archivedTotalYear = (int)eaScalar(
        $conn,
        "SELECT COUNT(*)
         FROM job_requests
         WHERE YEAR(date_request) = ?
           AND COALESCE(archived, 0) = 1",
        "i",
        [$selectedYear]
    );

    if ($hasClientSurvey) {
        $archivedEvaluatedYear = (int)eaScalar(
            $conn,
            "SELECT COUNT(DISTINCT jr.id)
             FROM job_requests jr
             INNER JOIN job_request_client_experience ce
                 ON ce.request_id = jr.id
             WHERE YEAR(jr.date_request) = ?
               AND COALESCE(jr.archived, 0) = 1",
            "i",
            [$selectedYear]
        );
    }
}

$filterQuery = http_build_query(
    array_filter(
        [
            "year" =>
                $selectedYear,

            "department" =>
                $departmentFilter,

            "staff" =>
                $staffFilter,

            "service" =>
                $serviceFilter,

            "rating" =>
                $ratingFilter,

            "evaluation" =>
                $evaluationFilter,

            "outcome" =>
                $outcomeFilter,

            "status" =>
                $statusFilter,

            "priority" =>
                $priorityFilter,

            "archived" =>
                $archivedFilter,

            "requestor_role" =>
                $requestorRoleFilter
        ],
        static fn (
            mixed $value
        ): bool =>
            $value !== ""
            &&
            $value !== null
    )
);

$export = trim(
    (string)(
        $_GET["export"]
        ?? ""
    )
);

if (
    in_array(
        $export,
        [
            "csv",
            "excel"
        ],
        true
    )
) {
    $filename =
        "HPU_Evaluation_Analytics_"
        . $selectedYear
        . "_"
        . date("Ymd_His");

    if ($export === "csv") {
        header(
            "Content-Type: text/csv; charset=UTF-8"
        );

        header(
            "Content-Disposition: attachment; filename=\""
            . $filename
            . ".csv\""
        );

        $output = fopen(
            "php://output",
            "w"
        );

        fwrite(
            $output,
            "\xEF\xBB\xBF"
        );

        fputcsv(
            $output,
            [
                "Job No.",
                "Project Title",
                "Department",
                "Request Type",
                "Status",
                "Assigned / Reviewed By",
                "Rating",
                "Rating Label",
                "Outcome",
                "Turnaround Days (actual)",
                "Actual Completion",
                "Target Date",
                "Challenges",
                "Improvement Suggestions",
                "Client - Did Well",
                "Client - Improve",
                "Requestor",
                "Requestor Role",
                "Contact",
                "Priority",
                "Reviewer",
                "Approver",
                "Date Requested",
                "Initial Draft Date",
                "Created At"
            ]
        );

        foreach ($rows as $row) {
            $actualCompletion = eaActualCompletion($row);

            $owner =
                trim(
                    (string)(
                        $row["assigned_name"]
                        ?? ""
                    )
                );

            if ($owner === "") {
                $owner =
                    (string)(
                        $row["reviewed_by"]
                        ?? ""
                    );
            }

            $rating =
                eaCombinedRating(
                    $row
                );

            fputcsv(
                $output,
                [
                    $row["job_no"],
                    $row["project_title"],
                    $row["department"],
                    implode(
                        ", ",
                        eaDecodeArray(
                            $row["request_types"]
                            ?? null
                        )
                    ),
                    $row["approval_status"],
                    $owner,
                    $rating ?: "",
                    eaRatingLabel($rating),
                    $row["outcome_assessment"]
                        ?? "",
                    eaDateDiffDays(
                        eaValidDateOrNull($row["date_request"] ?? null),
                        $actualCompletion
                    ),
                    $actualCompletion ?? "",
                    $row["date_completion"] ?? "",
                    $row["challenges_details"]
                        ?? "",
                    $row["improvement_suggestions"]
                        ?? "",
                    $row["phu_did_well"]
                        ?? "",
                    $row["phu_improve"]
                        ?? "",
                    $row["requestor_name"]
                        ?? "",
                    ($requestorRoleLabels[eaRequestorRoleKey($row)] ?? eaRequestorRoleKey($row)),
                    $row["contact"]
                        ?? "",
                    $row["priority"]
                        ?? "",
                    $row["reviewed_by"]
                        ?? "",
                    $row["approved_by"]
                        ?? "",
                    $row["date_request"]
                        ?? "",
                    $row["initial_draft_date"]
                        ?? "",
                    $row["created_at"]
                        ?? ""
                ]
            );
        }

        fputcsv($output, []);
        fputcsv(
            $output,
            ["REQUESTOR ROLE BREAKDOWN — " . $selectedYear]
        );
        fputcsv(
            $output,
            ["Role", "Requests", "Completed", "Evaluated", "Response %", "Avg Rating"]
        );

        foreach ($requestorRoleStats as $roleStats) {
            if (($roleStats["requests"] ?? 0) <= 0) {
                continue;
            }

            fputcsv(
                $output,
                [
                    $roleStats["label"],
                    $roleStats["requests"],
                    $roleStats["completed"],
                    $roleStats["evaluated"],
                    round($roleStats["response_rate"], 1),
                    $roleStats["rating_count"] > 0
                        ? round($roleStats["average_rating"], 2)
                        : ""
                ]
            );
        }

        fputcsv($output, []);
        fputcsv(
            $output,
            ["IEC MONTHLY CONSOLIDATION — " . $selectedYear]
        );
        fputcsv(
            $output,
            [
                "Month",
                "Materials",
                "Qty Requested",
                "Qty Fulfilled",
                "Pending",
                "Rate %"
            ]
        );

        foreach ($iecMonths as $iecMonth => $iecData) {
            fputcsv(
                $output,
                [
                    eaMonthShort((int)$iecMonth),
                    count($iecData["materials"]),
                    $iecData["qty"],
                    $iecData["done"],
                    $iecData["qty"] - $iecData["done"],
                    round(
                        eaPercent(
                            $iecData["done"],
                            $iecData["qty"]
                        ),
                        1
                    )
                ]
            );
        }

        fputcsv(
            $output,
            [
                "Total",
                "",
                $iecTotalQty,
                $iecTotalDone,
                $iecTotalQty - $iecTotalDone,
                round($iecFulfillmentRate, 1)
            ]
        );

        fclose($output);
        exit;
    }

    header(
        "Content-Type: application/vnd.ms-excel; charset=UTF-8"
    );

    header(
        "Content-Disposition: attachment; filename=\""
        . $filename
        . ".xls\""
    );

    echo "\xEF\xBB\xBF";
    ?>
    <table border="1">
        <tr>
            <th>Job No.</th>
            <th>Project Title</th>
            <th>Department</th>
            <th>Request Type</th>
            <th>Status</th>
            <th>Assigned / Reviewed By</th>
            <th>Rating</th>
            <th>Outcome</th>
            <th>Turnaround Days</th>
            <th>Challenges</th>
            <th>Improvement Suggestions</th>
            <th>Client - Did Well</th>
            <th>Client - Improve</th>
            <th>Requestor</th>
            <th>Requestor Role</th>
            <th>Contact</th>
            <th>Priority</th>
            <th>Reviewer</th>
            <th>Approver</th>
            <th>Date Requested</th>
            <th>Target Date</th>
            <th>Initial Draft Date</th>
            <th>Created At</th>
        </tr>

        <?php foreach ($rows as $row): ?>
            <?php
            $actualCompletion = eaActualCompletion($row);

            $owner =
                trim(
                    (string)(
                        $row["assigned_name"]
                        ?? ""
                    )
                );

            if ($owner === "") {
                $owner =
                    (string)(
                        $row["reviewed_by"]
                        ?? ""
                    );
            }

            $rating =
                eaCombinedRating(
                    $row
                );
            ?>

            <tr>
                <td><?= e($row["job_no"]) ?></td>
                <td><?= e($row["project_title"]) ?></td>
                <td><?= e($row["department"]) ?></td>
                <td>
                    <?= e(
                        implode(
                            ", ",
                            eaDecodeArray(
                                $row["request_types"]
                                ?? null
                            )
                        )
                    ) ?>
                </td>
                <td><?= e($row["approval_status"]) ?></td>
                <td><?= e($owner) ?></td>
                <td><?= $rating ?: "" ?></td>
                <td><?= e($row["outcome_assessment"] ?? "") ?></td>
                <td>
                    <?= e(
                        (string)(
                            eaDateDiffDays(
                                eaValidDateOrNull($row["date_request"] ?? null),
                                $actualCompletion
                            )
                            ?? ""
                        )
                    ) ?>
                </td>
                <td><?= e($row["challenges_details"] ?? "") ?></td>
                <td><?= e($row["improvement_suggestions"] ?? "") ?></td>
                <td><?= e($row["phu_did_well"] ?? "") ?></td>
                <td><?= e($row["phu_improve"] ?? "") ?></td>
                <td><?= e($row["requestor_name"] ?? "") ?></td>
                <td><?= e($requestorRoleLabels[eaRequestorRoleKey($row)] ?? "") ?></td>
                <td><?= e($row["contact"] ?? "") ?></td>
                <td><?= e($row["priority"] ?? "") ?></td>
                <td><?= e($row["reviewed_by"] ?? "") ?></td>
                <td><?= e($row["approved_by"] ?? "") ?></td>
                <td><?= e($row["date_request"] ?? "") ?></td>
                <td><?= e($row["date_completion"] ?? "") ?></td>
                <td><?= e($row["initial_draft_date"] ?? "") ?></td>
                <td><?= e($row["created_at"] ?? "") ?></td>
            </tr>

        <?php endforeach; ?>
    </table>

    <br>

    <table border="1">
        <tr>
            <th colspan="6">
                IEC MONTHLY CONSOLIDATION — <?= (int)$selectedYear ?>
            </th>
        </tr>
        <tr>
            <th>Month</th>
            <th>Materials</th>
            <th>Qty Requested</th>
            <th>Qty Fulfilled</th>
            <th>Pending</th>
            <th>Rate %</th>
        </tr>

        <?php foreach ($iecMonths as $iecMonth => $iecData): ?>
        <tr>
            <td><?= e(eaMonthShort((int)$iecMonth)) ?></td>
            <td><?= count($iecData["materials"]) ?></td>
            <td><?= (int)$iecData["qty"] ?></td>
            <td><?= (int)$iecData["done"] ?></td>
            <td><?= (int)($iecData["qty"] - $iecData["done"]) ?></td>
            <td>
                <?= number_format(
                    eaPercent(
                        $iecData["done"],
                        $iecData["qty"]
                    ),
                    1
                ) ?>
            </td>
        </tr>
        <?php endforeach; ?>

        <tr>
            <td><strong>Total</strong></td>
            <td></td>
            <td><strong><?= (int)$iecTotalQty ?></strong></td>
            <td><strong><?= (int)$iecTotalDone ?></strong></td>
            <td><strong><?= (int)($iecTotalQty - $iecTotalDone) ?></strong></td>
            <td><strong><?= number_format($iecFulfillmentRate, 1) ?></strong></td>
        </tr>
    </table>
    <?php
    exit;
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
    Service Evaluation Analytics | HPU Workbench
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

<script
    src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"
></script>

<style>
:root {
    --ea-bg:#f4f7fb;
    --ea-border:#dfe6ef;
    --ea-text:#172033;
    --ea-muted:#667085;
    --ea-primary:#1769e0;
    --ea-shadow:0 8px 24px rgba(28,42,72,.05);
}

body {
    background:var(--ea-bg);
    color:var(--ea-text);
}

.content {
    padding:18px;
}

.container-fluid {
    max-width:1750px;
    margin:0 auto;
}

.toolbar-actions {
    display:flex;
    gap:7px;
    flex-wrap:wrap;
}

.filter-card,
.card-shell {
    border:1px solid var(--ea-border);
    border-radius:14px;
    background:#fff;
    box-shadow:var(--ea-shadow);
}

.filter-card {
    padding:11px;
    margin-bottom:12px;
}

.filter-label {
    display:block;
    margin-bottom:3px;
    color:#667085;
    font-size:.57rem;
    font-weight:900;
    letter-spacing:.045em;
    text-transform:uppercase;
}

.filter-card .form-select,
.filter-card .form-control {
    font-size:.71rem;
}

.staff-scope-note {
    display:inline-flex;
    align-items:center;
    gap:5px;
    margin-top:5px;
    color:#175cd3;
    font-size:.61rem;
    font-weight:800;
}

/* KPI */
.kpi-grid {
    display:grid;
    grid-template-columns:repeat(6,minmax(0,1fr));
    gap:9px;
    margin-bottom:12px;
}

.kpi-card {
    min-height:108px;
    padding:12px;
    border:1px solid var(--ea-border);
    border-radius:13px;
    background:#fff;
    box-shadow:var(--ea-shadow);
}

.kpi-icon {
    width:31px;
    height:31px;
    display:grid;
    place-items:center;
    border-radius:9px;
    background:#eff8ff;
    color:#175cd3;
    font-size:.86rem;
}

.kpi-label {
    margin-top:9px;
    color:#667085;
    font-size:.58rem;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.045em;
}

.kpi-value {
    margin-top:3px;
    color:#101828;
    font-size:1.5rem;
    line-height:1;
    font-weight:900;
}

.kpi-value small {
    color:#98a2b3;
    font-size:.62rem;
    font-weight:800;
}

.kpi-note {
    margin-top:6px;
    color:#98a2b3;
    font-size:.59rem;
}

/* Cards */
.card-shell {
    overflow:hidden;
}

.card-head {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    padding:14px 18px;
    border-bottom:1px solid var(--hd-row-line,#edf1f6);
    background:var(--hd-surface,#ffffff);
}

.card-title {
    margin:0;
    color:var(--hd-ink,#0f172a);
    font-size:0.95rem;
    font-weight:800;
    letter-spacing:-.015em;
    line-height:1.25;
    display:flex;
    align-items:center;
    gap:8px;
}

.card-title i {
    color:var(--hd-primary,#2563eb);
    font-size:1.05em;
    vertical-align:-1px;
}

.card-subtitle {
    margin-top:3px;
    margin-bottom:0;
    color:var(--hd-muted,#64748b);
    font-size:0.74rem;
    font-weight:500;
    line-height:1.4;
}

.card-body-pad {
    padding:12px 13px;
}

/* Needs Attention */
.attention-grid {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:8px;
}

.attention-item {
    display:flex;
    gap:9px;
    align-items:flex-start;
    padding:10px;
    border:1px solid #eaecf0;
    border-radius:11px;
    background:#fff;
}

.attention-icon {
    width:31px;
    height:31px;
    flex:0 0 31px;
    display:grid;
    place-items:center;
    border-radius:9px;
}

.attention-danger .attention-icon {
    background:#fef3f2;
    color:#b42318;
}

.attention-warning .attention-icon {
    background:#fffaeb;
    color:#b54708;
}

.attention-primary .attention-icon {
    background:#eff8ff;
    color:#175cd3;
}

.attention-title {
    color:#1d2939;
    font-size:.69rem;
    font-weight:900;
}

.attention-note {
    margin-top:2px;
    color:#667085;
    font-size:.59rem;
    line-height:1.35;
}

/* Analytics grids */
.analytics-grid {
    display:grid;
    grid-template-columns:1.15fr .85fr;
    gap:12px;
    margin-top:12px;
}

.analytics-grid-equal {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px;
    margin-top:12px;
}

.chart-wrap {
    position:relative;
    height:285px;
}

/* Scorecard */
.score-list {
    display:flex;
    flex-direction:column;
    gap:10px;
}

.score-row {
    display:grid;
    grid-template-columns:160px minmax(0,1fr) 55px;
    gap:10px;
    align-items:center;
}

.score-label {
    color:#475467;
    font-size:.65rem;
    font-weight:800;
}

.score-track {
    height:8px;
    overflow:hidden;
    border-radius:999px;
    background:#f0f2f5;
}

.score-fill {
    height:100%;
    border-radius:inherit;
    background:linear-gradient(
        90deg,
        #1769e0,
        #7c3aed
    );
}

.score-value {
    text-align:right;
    color:#1d2939;
    font-size:.68rem;
    font-weight:900;
}

.score-summary {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:8px;
    margin-top:13px;
}

.score-summary-item {
    padding:10px;
    border-radius:10px;
    background:#f8fafc;
    text-align:center;
}

.score-summary-label {
    color:#667085;
    font-size:.57rem;
    font-weight:850;
    text-transform:uppercase;
}

.score-summary-value {
    margin-top:3px;
    color:#1d2939;
    font-size:1.12rem;
    font-weight:900;
}

/* Service table */
.table-wrap {
    overflow:auto;
}

.table-wrap-scroll {
    max-height:480px;
    overflow:auto;
    border-top:1px solid #edf1f6;
}

.table-wrap-scroll thead th {
    position:sticky;
    top:0;
    z-index:2;
    box-shadow:0 1px 0 #eaecf0;
}

/* All Requests Tracker — scrollable viewport */
.tracker-outer {
    overflow:hidden;
}

.tracker-scroll {
    max-height:520px;
    overflow:auto;
    border-top:1px solid #edf1f6;
    overscroll-behavior:contain;
}

.tracker-scroll .tracker-table {
    min-width:1280px;
}

.tracker-scroll::-webkit-scrollbar {
    height:10px;
    width:10px;
}

.tracker-scroll::-webkit-scrollbar-thumb {
    border-radius:999px;
    background:#cbd5e1;
    border:2px solid #fff;
}

.tracker-scroll::-webkit-scrollbar-track {
    background:#f8fafc;
}

.tracker-scroll-hint {
    padding:7px 13px;
    border-top:1px dashed #e5e7eb;
    color:#98a2b3;
    font-size:.58rem;
}

.ea-table {
    width:100%;
    margin:0;
    border-collapse:collapse;
}

.ea-table th {
    padding:8px 9px;
    border-bottom:1px solid #eaecf0;
    background:#f8fafc;
    color:#667085;
    font-size:.56rem;
    font-weight:900;
    letter-spacing:.04em;
    text-transform:uppercase;
    white-space:nowrap;
}

.ea-table td {
    padding:9px;
    border-bottom:1px solid #f0f2f5;
    color:#344054;
    font-size:.64rem;
    vertical-align:middle;
}

.ea-table tbody tr:hover {
    background:#fbfcfe;
}

.service-pill,
.status-pill,
.rating-pill {
    display:inline-flex;
    align-items:center;
    gap:4px;
    padding:3px 6px;
    border-radius:999px;
    font-size:.55rem;
    font-weight:850;
}

.service-graphic {
    background:#eff6ff;
    color:#1d4ed8;
}

.service-video {
    background:#f5f3ff;
    color:#6d28d9;
}

.service-coverage {
    background:#fff7ed;
    color:#c2410c;
}

.service-iec {
    background:#f0fdf4;
    color:#15803d;
}

.service-publication {
    background:#f0fdfa;
    color:#0f766e;
}

.service-other,
.status-neutral,
.rating-none {
    background:#f2f4f7;
    color:#475467;
}

.status-success,
.rating-excellent {
    background:#ecfdf3;
    color:#027a48;
}

.status-warning,
.rating-fair {
    background:#fffaeb;
    color:#b54708;
}

.status-danger,
.rating-poor {
    background:#fef3f2;
    color:#b42318;
}

.rating-very-good {
    background:#eff8ff;
    color:#175cd3;
}

.rating-good {
    background:#ecfdff;
    color:#0e7090;
}

/* Tracker */
.card-tools {
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
    margin-left:auto;
}

.tracker-chips {
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    padding:12px 13px 0;
}

.priority-pill {
    display:inline-flex;
    align-items:center;
    gap:4px;
    padding:3px 6px;
    border-radius:999px;
    font-size:.55rem;
    font-weight:850;
    white-space:nowrap;
}

.priority-low {
    background:#ecfdf3;
    color:#027a48;
}

.priority-normal {
    background:#eff8ff;
    color:#175cd3;
}

.priority-high {
    background:#fffaeb;
    color:#b54708;
}

.priority-urgent {
    background:#fef3f2;
    color:#b42318;
}

/* Feedback */
.feedback-columns {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
}

.feedback-column-title {
    display:flex;
    align-items:center;
    gap:6px;
    margin-bottom:7px;
    color:#344054;
    font-size:.67rem;
    font-weight:900;
}

.feedback-item {
    padding:9px 10px;
    margin-bottom:7px;
    border:1px solid #eaecf0;
    border-radius:10px;
    background:#fff;
}

.feedback-job {
    color:#1769e0;
    font-size:.56rem;
    font-weight:900;
}

.feedback-text {
    margin-top:3px;
    color:#475467;
    font-size:.64rem;
    line-height:1.45;
}

/* Comparison */
.comparison-grid {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:8px;
}

.comparison-item {
    padding:10px;
    border:1px solid #eaecf0;
    border-radius:10px;
    background:#fff;
}

.comparison-label {
    color:#667085;
    font-size:.58rem;
    font-weight:850;
}

.comparison-current {
    margin-top:4px;
    color:#1d2939;
    font-size:1.05rem;
    font-weight:900;
}

.comparison-prev {
    margin-top:3px;
    color:#98a2b3;
    font-size:.56rem;
}

.comparison-change {
    margin-top:4px;
    font-size:.59rem;
    font-weight:900;
}

/* Empty */
.empty-state {
    padding:30px 12px;
    text-align:center;
    color:#667085;
    font-size:.67rem;
}

.empty-state i {
    display:block;
    margin-bottom:6px;
    color:#98a2b3;
    font-size:1.3rem;
}

@media(max-width:1350px) {
    .kpi-grid {
        grid-template-columns:repeat(3,minmax(0,1fr));
    }

    .attention-grid {
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
}

@media(max-width:1000px) {
    .analytics-grid,
    .analytics-grid-equal,
    .feedback-columns {
        grid-template-columns:1fr;
    }

    .comparison-grid {
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
}

@media(max-width:700px) {
    .content {
        padding:12px;
    }

    .kpi-grid {
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .attention-grid,
    .comparison-grid {
        grid-template-columns:1fr;
    }

    .score-row {
        grid-template-columns:120px minmax(0,1fr) 46px;
    }
}

@media print {
    .sidebar,
    .toolbar-actions,
    .filter-card,
    .btn,
    nav {
        display:none !important;
    }

    .content {
        padding:0;
    }

    body {
        background:#fff;
    }

    .card-shell,
    .kpi-card {
        box-shadow:none;
        break-inside:avoid;
    }
}
</style>

</head>

<body class="app-shell">

<div class="d-flex min-vh-100">

<?php
include "../includes/sidebar.php";
?>

<div class="main flex-grow-1">

<main class="content">

<div class="container-fluid">

<!-- ======================================================
     HEADER
======================================================= -->

<div class="ph">

    <div class="ph-copy">
        <div class="ph-icon" aria-hidden="true">
            <i class="bi bi-bar-chart-line-fill"></i>
        </div>

        <div class="min-w-0">
            <p class="ph-eyebrow">HPU Workbench</p>
            <h1 class="ph-title">Service Evaluation & Client Experience Analytics</h1>
            <p class="ph-subtitle">
                <?= ($isStaff && $isStaffViewingMine)
                    ? "Your assigned-work evaluation, client feedback and service performance."
                    : "HPU quality, evaluation response and client-experience performance. Accessible to HPU staff, supervisors and admins."
                ?>
            </p>
        </div>
    </div>

    <div class="ph-actions">

        <a
            class="btn btn-sm btn-outline-success"
            href="?<?= e($filterQuery) ?>&export=excel"
        >
            <i class="bi bi-file-earmark-excel me-1"></i>
            Excel
        </a>

        <a
            class="btn btn-sm btn-outline-secondary"
            href="?<?= e($filterQuery) ?>&export=csv"
        >
            <i class="bi bi-filetype-csv me-1"></i>
            CSV
        </a>

        <button
            class="btn btn-sm btn-primary"
            type="button"
            onclick="window.print()"
        >
            <i class="bi bi-printer me-1"></i>
            Print / Save PDF
        </button>

    </div>

</div>

<?php if (!$hasClientSurvey): ?>

<div class="alert alert-warning small">
    <i class="bi bi-exclamation-triangle me-1"></i>
    The Client Experience Survey table is not installed.
    This page will continue using the legacy Job Request evaluation fields.
    Import <code>assets/job_request_client_experience.sql</code>
    to activate the full Client Experience Scorecard.
</div>

<?php endif; ?>

<!-- ======================================================
     FILTERS
======================================================= -->

<section class="filter-card">

<form method="get">

<div class="row g-2 align-items-end">

    <div class="col-xl-1 col-md-3">

        <label class="filter-label">
            Year
        </label>

        <select
            class="form-select form-select-sm"
            name="year"
        >
            <?php foreach ($years as $year): ?>

            <option
                value="<?= (int)$year ?>"
                <?= $selectedYear === (int)$year ? "selected" : "" ?>
            >
                <?= (int)$year ?>
            </option>

            <?php endforeach; ?>
        </select>

    </div>

    <div class="col-xl-2 col-md-4">

        <label class="filter-label">
            Department
        </label>

        <select
            class="form-select form-select-sm"
            name="department"
        >
            <option value="">
                All Departments
            </option>

            <?php foreach ($departments as $department): ?>

            <option
                value="<?= e($department["department"]) ?>"
                <?= $departmentFilter === $department["department"] ? "selected" : "" ?>
            >
                <?= e($department["department"]) ?>
            </option>

            <?php endforeach; ?>
        </select>

    </div>

    <div class="col-xl-2 col-md-4">

        <label class="filter-label">
            Request Type
        </label>

        <select
            class="form-select form-select-sm"
            name="service"
        >
            <option value="">
                All Request Types
            </option>

            <?php foreach ($services as $service): ?>

            <option
                value="<?= e($service) ?>"
                <?= $serviceFilter === $service ? "selected" : "" ?>
            >
                <?= e($service) ?>
            </option>

            <?php endforeach; ?>
        </select>

    </div>

    <div class="col-xl-2 col-md-4">

        <label class="filter-label">
            HPU Personnel
        </label>

        <select
            class="form-select form-select-sm"
            name="staff"
        >
            <option value="">
                All HPU Personnel
            </option>

            <?php foreach ($staffOptions as $person): ?>
                <?php
                $optValue = $hasAssignedTo
                    ? (string)($person["id"] ?? "")
                    : (string)($person["name"] ?? "");
                $optSelected = $staffFilter === $optValue
                    || (!$hasAssignedTo && $staffFilter === ($person["name"] ?? ""))
                    || ($hasAssignedTo && ctype_digit($staffFilter) && (int)$staffFilter === (int)($person["id"] ?? 0));
                ?>

            <option
                value="<?= e($optValue) ?>"
                <?= $optSelected ? "selected" : "" ?>
            >
                <?= e($person["name"]) ?><?= $isStaff && $hasAssignedTo && (int)($person["id"] ?? 0) === $userId ? " (me)" : "" ?>
            </option>

            <?php endforeach; ?>
        </select>

        <?php if ($isStaff): ?>
        <div class="staff-scope-note">
            <i class="bi bi-people"></i>
            Staff view — select your name for “my performance”.
        </div>
        <?php endif; ?>

    </div>

    <div class="col-xl-1 col-md-3">

        <label class="filter-label">
            Rating
        </label>

        <select
            class="form-select form-select-sm"
            name="rating"
        >
            <option value="">
                All
            </option>

            <?php for ($rating = 5; $rating >= 1; $rating--): ?>

            <option
                value="<?= $rating ?>"
                <?= $ratingFilter === $rating ? "selected" : "" ?>
            >
                <?= $rating ?>/5
            </option>

            <?php endfor; ?>
        </select>

    </div>

    <div class="col-xl-1 col-md-3">

        <label class="filter-label">
            Evaluation
        </label>

        <select
            class="form-select form-select-sm"
            name="evaluation"
        >
            <option value="">
                All
            </option>

            <option
                value="evaluated"
                <?= $evaluationFilter === "evaluated" ? "selected" : "" ?>
            >
                Evaluated
            </option>

            <option
                value="pending"
                <?= $evaluationFilter === "pending" ? "selected" : "" ?>
            >
                Pending
            </option>
        </select>

    </div>

    <?php if ($hasClientSurvey): ?>

    <div class="col-xl-1 col-md-3">

        <label class="filter-label">
            Outcome
        </label>

        <select
            class="form-select form-select-sm"
            name="outcome"
        >
            <option value="">
                All
            </option>

            <?php foreach ($outcomeOptions as $outcomeOption): ?>

            <option
                value="<?= e($outcomeOption) ?>"
                <?= $outcomeFilter === $outcomeOption ? "selected" : "" ?>
            >
                <?= e($outcomeOption) ?>
            </option>

            <?php endforeach; ?>
        </select>

    </div>

    <?php endif; ?>

    <div class="col-xl-2 col-md-4">

        <label class="filter-label">
            Status
        </label>

        <select
            class="form-select form-select-sm"
            name="status"
        >
            <option value="">
                All Statuses
            </option>

            <?php foreach ($trackerStatuses as $trackerStatus): ?>

            <option
                value="<?= e($trackerStatus) ?>"
                <?= $statusFilter === $trackerStatus ? "selected" : "" ?>
            >
                <?= e($trackerStatus) ?>
            </option>

            <?php endforeach; ?>
        </select>

    </div>

    <?php if ($hasPriority): ?>

    <div class="col-xl-1 col-md-3">

        <label class="filter-label">
            Priority
        </label>

        <select
            class="form-select form-select-sm"
            name="priority"
        >
            <option value="">
                All
            </option>

            <?php foreach ($trackerPriorities as $trackerPriority): ?>

            <option
                value="<?= e($trackerPriority) ?>"
                <?= $priorityFilter === $trackerPriority ? "selected" : "" ?>
            >
                <?= e($trackerPriority) ?>
            </option>

            <?php endforeach; ?>
        </select>

    </div>

    <?php endif; ?>

    <?php if ($hasArchived): ?>

    <div class="col-xl-1 col-md-3">

        <label class="filter-label">
            Archive
        </label>

        <select
            class="form-select form-select-sm"
            name="archived"
        >
            <option value="all" <?= $archivedFilter === "all" ? "selected" : "" ?>>
                All (accurate)
            </option>

            <option value="active" <?= $archivedFilter === "active" ? "selected" : "" ?>>
                Active only
            </option>

            <option value="archived" <?= $archivedFilter === "archived" ? "selected" : "" ?>>
                Archived only
            </option>
        </select>

    </div>

    <?php endif; ?>

    <div class="col-xl-2 col-md-4">

        <label class="filter-label">
            Requestor Role
        </label>

        <select
            class="form-select form-select-sm"
            name="requestor_role"
        >
            <option value="">
                All Roles
            </option>

            <option value="staff" <?= $requestorRoleFilter === "staff" ? "selected" : "" ?>>
                HPU Staff
            </option>

            <option value="supervisor" <?= $requestorRoleFilter === "supervisor" ? "selected" : "" ?>>
                Supervisor
            </option>

            <option value="user" <?= $requestorRoleFilter === "user" ? "selected" : "" ?>>
                User
            </option>

            <option value="admin" <?= $requestorRoleFilter === "admin" ? "selected" : "" ?>>
                Admin
            </option>
        </select>

    </div>

    <div class="col-xl-auto col-md-3 d-flex gap-2">

        <button
            class="btn btn-sm btn-primary"
            type="submit"
        >
            <i class="bi bi-funnel me-1"></i>
            Apply
        </button>

        <a
            class="btn btn-sm btn-light"
            href="evaluation_analytics.php"
            title="Reset filters"
        >
            <i class="bi bi-arrow-counterclockwise"></i>
        </a>

    </div>

</div>

</form>

</section>

<!-- ======================================================
     KPI
======================================================= -->

<section class="kpi-grid">

    <article class="kpi-card">

        <div class="kpi-icon">
            <i class="bi bi-check2-circle"></i>
        </div>

        <div class="kpi-label">
            Completed Requests
        </div>

        <div class="kpi-value">
            <?= number_format($completedRequests) ?>
        </div>

        <div class="kpi-note">
            <?= number_format($totalRequests) ?> request(s) in current filter
        </div>

    </article>

    <article class="kpi-card">

        <div class="kpi-icon">
            <i class="bi bi-chat-square-check"></i>
        </div>

        <div class="kpi-label">
            Evaluations Received
        </div>

        <div class="kpi-value">
            <?= number_format($evaluatedCompleted) ?>
        </div>

        <div class="kpi-note">
            <?= number_format($pendingEvaluations) ?> completed item(s) still pending
            <?php if ($evaluatedRequests !== $evaluatedCompleted): ?>
                · <?= number_format($evaluatedRequests) ?> rated overall
            <?php endif; ?>
        </div>

    </article>

    <article class="kpi-card">

        <div class="kpi-icon">
            <i class="bi bi-percent"></i>
        </div>

        <div class="kpi-label">
            Evaluation Response
        </div>

        <div class="kpi-value">
            <?= number_format($responseRate, 1) ?>
            <small>%</small>
        </div>

        <div class="kpi-note">
            Completed-with-rating ÷ completed (<?= number_format($evaluatedCompleted) ?>/<?= number_format($completedRequests) ?>)
        </div>

    </article>

    <article class="kpi-card">

        <div class="kpi-icon">
            <i class="bi bi-star"></i>
        </div>

        <div class="kpi-label">
            Overall Satisfaction
        </div>

        <div class="kpi-value">
            <?= number_format($averageRating, 2) ?>
            <small>/ 5</small>
        </div>

        <div class="kpi-note">
            n=<?= number_format($ratingCount) ?> rated · survey preferred, legacy fallback
        </div>

    </article>

    <article class="kpi-card">

        <div class="kpi-icon">
            <i class="bi bi-bullseye"></i>
        </div>

        <div class="kpi-label">
            Objective Achieved
        </div>

        <div class="kpi-value">
            <?= number_format($objectiveAchievedRate, 1) ?>
            <small>%</small>
        </div>

        <div class="kpi-note">
            Yes <?= number_format($outcomeYes) ?>
            <?php if ($outcomePartial > 0): ?>
                · Partially <?= number_format($outcomePartial) ?>
            <?php endif; ?>
            <?php if ($outcomeNo > 0): ?>
                · No <?= number_format($outcomeNo) ?>
            <?php endif; ?>
            / <?= number_format($outcomeTotal) ?> answered
        </div>

    </article>

    <article class="kpi-card">

        <div class="kpi-icon">
            <i class="bi bi-stopwatch"></i>
        </div>

        <div class="kpi-label">
            Avg. Turnaround
        </div>

        <div class="kpi-value">
            <?= number_format($averageTurnaround, 1) ?>
            <small>days</small>
        </div>

        <div class="kpi-note">
            Actual final-approval date only · n=<?= number_format($turnaroundCount) ?>/<?= number_format($completedRequests) ?>
            <?php if ($turnaroundMissing > 0): ?>
                · <?= number_format($turnaroundMissing) ?> missing date
            <?php endif; ?>
        </div>

    </article>

</section>

<!-- ======================================================
     NEEDS ATTENTION
======================================================= -->

<section class="card-shell">

<div class="card-head">

    <div>
        <h2 class="card-title">
            <i class="bi bi-exclamation-triangle me-1 text-warning"></i>
            Needs Attention
        </h2>

        <div class="card-subtitle">
            Evaluation gaps and client-experience signals that may need follow-up.
        </div>
    </div>

</div>

<div class="card-body-pad">

<div class="attention-grid">

    <div class="attention-item attention-warning">

        <div class="attention-icon">
            <i class="bi bi-hourglass-split"></i>
        </div>

        <div>
            <div class="attention-title">
                <?= number_format($pendingEvaluations) ?>
                pending evaluation<?= $pendingEvaluations === 1 ? "" : "s" ?>
            </div>

            <div class="attention-note">
                Completed requests with no recorded evaluation yet.
            </div>
        </div>

    </div>

    <div class="attention-item attention-danger">

        <div class="attention-icon">
            <i class="bi bi-star-half"></i>
        </div>

        <div>
            <div class="attention-title">
                <?= number_format($lowRatingCount) ?>
                low rating<?= $lowRatingCount === 1 ? "" : "s" ?>
            </div>

            <div class="attention-note">
                Evaluations with an overall satisfaction score of 1–2.
            </div>
        </div>

    </div>

    <div class="attention-item attention-danger">

        <div class="attention-icon">
            <i class="bi bi-x-circle"></i>
        </div>

        <div>
            <div class="attention-title">
                <?= number_format($outcomeNo + $outcomePartial) ?>
                unmet / partial-need response<?= ($outcomeNo + $outcomePartial) === 1 ? "" : "s" ?>
            </div>

            <div class="attention-note">
                No: <?= number_format($outcomeNo) ?> · Partially: <?= number_format($outcomePartial) ?> · did not fully meet needs.
            </div>
        </div>

    </div>

    <div class="attention-item attention-primary">

        <div class="attention-icon">
            <i class="bi bi-chat-left-text"></i>
        </div>

        <div>
            <div class="attention-title">
                <?= number_format($challengeCount) ?>
                challenge report<?= $challengeCount === 1 ? "" : "s" ?>
            </div>

            <div class="attention-note">
                Legacy evaluations where a service challenge was recorded.
            </div>
        </div>

    </div>

</div>

</div>

</section>

<?php if ($orphanSurveys > 0 || $turnaroundMissing > 0 || $crossYearCompletions > 0 || $turnaroundAnomaly > 0): ?>

<div class="alert alert-light border small mt-3 mb-0">
    <i class="bi bi-shield-check me-1"></i>
    <strong>Data accuracy notes (<?= (int)$selectedYear ?>):</strong>
    <?php if ($archivedFilter !== "all" && $archivedTotalYear > 0): ?>
        Archive scope “<?= e($archivedFilter) ?>” active — <?= number_format($archivedTotalYear) ?> archived request(s) exist in <?= (int)$selectedYear ?>
        (<?= number_format($archivedEvaluatedYear) ?> with evaluations). Switch to “All” for complete history.
    <?php else: ?>
        Archive scope “All” — includes <?= number_format($archivedIncluded) ?> archived record(s) in current filter for complete history.
    <?php endif; ?>
    <?php if ($turnaroundMissing > 0): ?>
        Turnaround uses actual final-approval dates only — <?= number_format($turnaroundMissing) ?> completed item(s) have no actual date and are excluded from the average (target dates are not used).
    <?php endif; ?>
    <?php if ($turnaroundAnomaly > 0): ?>
        <?= number_format($turnaroundAnomaly) ?> completion-before-request anomal(ies) excluded.
    <?php endif; ?>
    <?php if ($orphanSurveys > 0): ?>
        <?= number_format($orphanSurveys) ?> orphan survey(s) point to deleted requests and are excluded from request-joined metrics.
    <?php endif; ?>
    <?php if ($crossYearCompletions > 0 || $crossYearEvaluations > 0): ?>
        <?= number_format($crossYearCompletions) ?> completion(s) / <?= number_format($crossYearEvaluations) ?> evaluation(s) from this request cohort fall outside <?= (int)$selectedYear ?> and are counted separately from monthly bars.
    <?php endif; ?>
</div>

<?php endif; ?>

<!-- ======================================================
     SCORECARD + SATISFACTION
======================================================= -->

<div class="analytics-grid">

<section class="card-shell">

    <div class="card-head">

        <div>
            <h2 class="card-title">
                Client Experience Scorecard
            </h2>

            <div class="card-subtitle">
                Average score for each Client Experience criterion.
            </div>
        </div>

        <span class="badge text-bg-light">
            <?= number_format($clientSurveyCount) ?> survey(s)
        </span>

    </div>

    <div class="card-body-pad">

        <?php if (!$hasClientSurvey || $clientSurveyCount === 0): ?>

            <div class="empty-state">
                <i class="bi bi-bar-chart"></i>
                No Client Experience Survey responses in the selected filter.
            </div>

        <?php else: ?>

            <div class="score-list">

            <?php foreach ($clientCriteria as $criterion): ?>

                <?php
                $average =
                    (float)$criterion[
                        "average"
                    ];

                $width =
                    min(
                        100,
                        max(
                            0,
                            ($average / 5)
                            * 100
                        )
                    );
                ?>

                <div class="score-row">

                    <div class="score-label">
                        <?= e($criterion["label"]) ?>
                    </div>

                    <div class="score-track">
                        <div
                            class="score-fill"
                            style="width:<?= $width ?>%"
                        ></div>
                    </div>

                    <div class="score-value">
                        <?= number_format($average, 2) ?>/5
                    </div>

                </div>

            <?php endforeach; ?>

            </div>

            <div class="score-summary">

                <div class="score-summary-item">
                    <div class="score-summary-label">
                        Overall Satisfaction
                    </div>

                    <div class="score-summary-value">
                        <?= number_format($averageRating, 2) ?>/5
                    </div>
                </div>

                <div class="score-summary-item">
                    <div class="score-summary-label">
                        Objective Achieved
                    </div>

                    <div class="score-summary-value">
                        <?= number_format($objectiveAchievedRate, 1) ?>%
                    </div>
                </div>

            </div>

        <?php endif; ?>

    </div>

</section>

<section class="card-shell">

    <div class="card-head">

        <div>
            <h2 class="card-title">
                Satisfaction Distribution
            </h2>

            <div class="card-subtitle">
                Distribution of combined Client Experience / legacy satisfaction ratings.
            </div>
        </div>

    </div>

    <div class="card-body-pad">

        <div class="chart-wrap">
            <canvas id="satisfactionChart"></canvas>
        </div>

    </div>

</section>

</div>

<!-- ======================================================
     MONTHLY TREND + CRITERIA CHART
======================================================= -->

<div class="analytics-grid-equal">

<section class="card-shell">

    <div class="card-head">

        <div>
            <h2 class="card-title">
                Monthly Evaluation Trend
            </h2>

            <div class="card-subtitle">
                Completed requests, evaluations received and response rate.
            </div>
        </div>

    </div>

    <div class="card-body-pad">

        <div class="chart-wrap">
            <canvas id="monthlyTrendChart"></canvas>
        </div>

    </div>

</section>

<section class="card-shell">

    <div class="card-head">

        <div>
            <h2 class="card-title">
                Client Experience Criteria
            </h2>

            <div class="card-subtitle">
                Side-by-side comparison of the six survey dimensions.
            </div>
        </div>

    </div>

    <div class="card-body-pad">

        <div class="chart-wrap">
            <canvas id="criteriaChart"></canvas>
        </div>

    </div>

</section>

</div>

<!-- ======================================================
     SERVICE PERFORMANCE
======================================================= -->

<section class="card-shell mt-3">

<div class="card-head">

    <div>
        <h2 class="card-title">
            Performance by Request Type
        </h2>

        <div class="card-subtitle">
            Request volume, evaluation response and satisfaction by HPU service. Publication Submission merges Event / Recognition publication tables (shared survey).
        </div>
    </div>

</div>

<div class="table-wrap">

<table class="ea-table">

<thead>
<tr>
    <th>Request Type</th>
    <th class="text-end">Requests</th>
    <th class="text-end">Completed</th>
    <th class="text-end">Evaluated</th>
    <th class="text-end">Response</th>
    <th class="text-end">Avg. Rating</th>
</tr>
</thead>

<tbody>

<?php foreach ($serviceStats as $service => $stats): ?>

<tr>

    <td>
        <span class="service-pill <?= e(eaRequestTypeClass($service)) ?>">
            <i class="bi <?= e(eaRequestTypeIcon($service)) ?>"></i>
            <?= e($service) ?>
        </span>
    </td>

    <td class="text-end">
        <?= number_format($stats["requests"]) ?>
    </td>

    <td class="text-end">
        <?= number_format($stats["completed"]) ?>
    </td>

    <td class="text-end">
        <?= number_format($stats["evaluated"]) ?>
    </td>

    <td class="text-end fw-bold">
        <?= number_format($stats["response_rate"], 1) ?>%
    </td>

    <td class="text-end fw-bold">
        <?= $stats["rating_count"] > 0
            ? number_format($stats["average_rating"], 2) . "/5"
            : "—"
        ?>
    </td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</section>

<!-- ======================================================
     REQUESTOR ROLE ANALYTICS (HPU Staff / Supervisor / User)
====================================================== -->

<section class="card-shell mt-3">

<div class="card-head">

    <div>
        <h2 class="card-title">
            <i class="bi bi-people"></i>
            Performance by Requestor Role
        </h2>

        <div class="card-subtitle">
            HPU Staff vs Supervisor vs User requests — volume, evaluation response and satisfaction. Based on the requestor account role.
        </div>
    </div>

</div>

<div class="analytics-grid-equal" style="margin-top:0;">

<div class="table-wrap">

<table class="ea-table">

<thead>
<tr>
    <th>Requestor Role</th>
    <th class="text-end">Requests</th>
    <th class="text-end">Completed</th>
    <th class="text-end">Evaluated</th>
    <th class="text-end">Response</th>
    <th class="text-end">Avg. Rating</th>
</tr>
</thead>

<tbody>

<?php foreach ($requestorRoleStats as $roleKey => $stats): ?>
<?php if (($stats["requests"] ?? 0) <= 0) continue; ?>

<tr>

    <td>
        <span class="service-pill <?= e($roleKey === "staff" ? "service-iec" : ($roleKey === "supervisor" ? "service-video" : ($roleKey === "user" ? "service-graphic" : "service-other"))) ?>">
            <i class="bi <?= e($roleKey === "staff" ? "bi-person-badge" : ($roleKey === "supervisor" ? "bi-person-check" : ($roleKey === "user" ? "bi-person" : "bi-question-circle"))) ?>"></i>
            <?= e($stats["label"]) ?>
        </span>
    </td>

    <td class="text-end">
        <?= number_format($stats["requests"]) ?>
    </td>

    <td class="text-end">
        <?= number_format($stats["completed"]) ?>
    </td>

    <td class="text-end">
        <?= number_format($stats["evaluated"]) ?>
    </td>

    <td class="text-end fw-bold">
        <?= number_format($stats["response_rate"], 1) ?>%
    </td>

    <td class="text-end fw-bold">
        <?= $stats["rating_count"] > 0
            ? number_format($stats["average_rating"], 2) . "/5"
            : "—"
        ?>
    </td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<div class="card-body-pad">

    <div class="chart-wrap" style="height:260px;">
        <canvas id="requestorRoleChart"></canvas>
    </div>

    <div class="small text-muted mt-2">
        Requests by requestor account role in the current filter. Deleted accounts show as Unknown.
    </div>

</div>

</div>

</section>


<!-- ======================================================
     STAFF PERFORMANCE
======================================================= -->

<section class="card-shell mt-3">

<div class="card-head">

    <div>
        <h2 class="card-title">
            <?= ($isStaff && $isStaffViewingMine)
                ? "My Performance Summary"
                : "HPU Personnel Performance"
            ?>
        </h2>

        <div class="card-subtitle">
            <?= $hasAssignedTo
                ? "Uses the actual assigned HPU personnel as work owner. Name variants are merged (e.g. ACE R. CABUGA → ACE RAZ CABUGA), including legacy rows with no assignee."
                : "Older database detected; reviewed_by is used as the available staff reference. Name variants are merged (e.g. ACE R. CABUGA → ACE RAZ CABUGA)."
            ?>
        </div>
    </div>

</div>

<div class="table-wrap">

<?php if (!$staffStats): ?>

    <div class="empty-state">
        <i class="bi bi-people"></i>
        No personnel performance data found for the selected filters.
    </div>

<?php else: ?>

<table class="ea-table">

<thead>
<tr>
    <th>HPU Personnel</th>
    <th class="text-end">Handled</th>
    <th class="text-end">Completed</th>
    <th class="text-end">Completion</th>
    <th class="text-end">Evaluated</th>
    <th class="text-end">Avg. Rating</th>
    <th class="text-end">Avg. Turnaround</th>
    <th class="text-end">Revisions</th>
</tr>
</thead>

<tbody>

<?php foreach ($staffStats as $staffKey => $stats): ?>

<tr>

    <td class="fw-bold">
        <?= e($stats["name"] ?? $staffKey) ?>
    </td>

    <td class="text-end">
        <?= number_format($stats["handled"]) ?>
    </td>

    <td class="text-end">
        <?= number_format($stats["completed"]) ?>
    </td>

    <td class="text-end">
        <?= number_format($stats["completion_rate"], 1) ?>%
    </td>

    <td class="text-end">
        <?= number_format($stats["evaluated"]) ?>
    </td>

    <td class="text-end fw-bold">
        <?= $stats["rating_count"] > 0
            ? number_format($stats["average_rating"], 2) . "/5"
            : "—"
        ?>
    </td>

    <td class="text-end">
        <?= $stats["turnaround_count"] > 0
            ? number_format($stats["average_turnaround"], 1) . " d"
            : "—"
        ?>
    </td>

    <td class="text-end">
        <?= number_format($stats["revisions"]) ?>
    </td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

<?php endif; ?>

</div>

</section>

<!-- ======================================================
     CLIENT FEEDBACK
======================================================= -->

<section class="card-shell mt-3">

<div class="card-head">

    <div>
        <h2 class="card-title">
            Client Feedback
        </h2>

        <div class="card-subtitle">
            Written responses from the Client Experience Survey.
        </div>
    </div>

</div>

<div class="card-body-pad">

<?php if (!$feedbackRows): ?>

    <div class="empty-state">
        <i class="bi bi-chat-left-quote"></i>
        No written Client Experience feedback in the selected filters.
    </div>

<?php else: ?>

<div class="feedback-columns">

    <div>

        <div class="feedback-column-title">
            <i class="bi bi-hand-thumbs-up text-success"></i>
            What clients said HPU did well
        </div>

        <?php
        $didWellShown = 0;
        foreach ($feedbackRows as $feedback):
            if (
                trim(
                    (string)$feedback[
                        "did_well"
                    ]
                )
                === ""
            ) {
                continue;
            }

            if ($didWellShown >= 8) {
                break;
            }

            $didWellShown++;
        ?>

        <div class="feedback-item">

            <div class="feedback-job">
                <?= e($feedback["job_no"]) ?>
                •
                <?= e($feedback["project_title"]) ?>
            </div>

            <div class="feedback-text">
                <?= nl2br(e($feedback["did_well"])) ?>
            </div>

        </div>

        <?php endforeach; ?>

        <?php if ($didWellShown === 0): ?>
            <div class="small text-muted">
                No positive narrative feedback recorded.
            </div>
        <?php endif; ?>

    </div>

    <div>

        <div class="feedback-column-title">
            <i class="bi bi-lightbulb text-warning"></i>
            Areas clients said can improve
        </div>

        <?php
        $improveShown = 0;
        foreach ($feedbackRows as $feedback):
            if (
                trim(
                    (string)$feedback[
                        "improve"
                    ]
                )
                === ""
            ) {
                continue;
            }

            if ($improveShown >= 8) {
                break;
            }

            $improveShown++;
        ?>

        <div class="feedback-item">

            <div class="feedback-job">
                <?= e($feedback["job_no"]) ?>
                •
                <?= e($feedback["project_title"]) ?>
            </div>

            <div class="feedback-text">
                <?= nl2br(e($feedback["improve"])) ?>
            </div>

        </div>

        <?php endforeach; ?>

        <?php if ($improveShown === 0): ?>
            <div class="small text-muted">
                No improvement narrative feedback recorded.
            </div>
        <?php endif; ?>

    </div>

</div>

<?php endif; ?>

</div>

</section>

<!-- ======================================================
     LOW RATINGS
======================================================= -->

<section class="card-shell mt-3">

<div class="card-head">

    <div>
        <h2 class="card-title">
            Low-Rating / Follow-up Items
        </h2>

        <div class="card-subtitle">
            Requests with an overall satisfaction score of 1–2.
        </div>
    </div>

</div>

<div class="table-wrap">

<?php if (!$lowRatingRows): ?>

    <div class="empty-state">
        <i class="bi bi-check2-circle"></i>
        No low-rating evaluation found in the current filters.
    </div>

<?php else: ?>

<table class="ea-table">

<thead>
<tr>
    <th>Job No.</th>
    <th>Project</th>
    <th>Request Type</th>
    <th>Department</th>
    <th>Owner</th>
    <th>Rating</th>
    <th>Outcome</th>
    <th class="text-end">Action</th>
</tr>
</thead>

<tbody>

<?php foreach (array_slice($lowRatingRows, 0, 12) as $row): ?>

    <?php
    $rating =
        eaCombinedRating(
            $row
        );

    $owner =
        trim(
            (string)(
                $row["assigned_name"]
                ?? ""
            )
        );

    if ($owner === "") {
        $owner =
            trim(
                (string)(
                    $row["reviewed_by"]
                    ?? ""
                )
            );
    }

    $types =
        eaDecodeArray(
            $row["request_types"]
            ?? null
        );
    ?>

<tr>

    <td class="fw-bold text-primary">
        <?= e($row["job_no"]) ?>
    </td>

    <td>
        <?= e($row["project_title"]) ?>
    </td>

    <td>

        <?php foreach ($types as $type): ?>

        <span class="service-pill <?= e(eaRequestTypeClass((string)$type)) ?>">
            <?= e($type) ?>
        </span>

        <?php endforeach; ?>

    </td>

    <td>
        <?= e($row["department"]) ?>
    </td>

    <td>
        <?= e($owner ?: "—") ?>
    </td>

    <td>
        <span class="rating-pill <?= e(eaRatingClass($rating)) ?>">
            <?= $rating ?>/5
            •
            <?= e(eaRatingLabel($rating)) ?>
        </span>
    </td>

    <td>
        <?= e($row["outcome_assessment"] ?? "—") ?>
    </td>

    <td class="text-end">

        <a
            class="btn btn-sm btn-outline-primary"
            href="<?= e(BASE_URL) ?>/staff/review_request.php?id=<?= (int)$row["id"] ?>"
        >
            Open
        </a>

    </td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

<?php endif; ?>

</div>

</section>

<!-- ======================================================
     YEAR COMPARISON
======================================================= -->

<section class="card-shell mt-3">

<div class="card-head">

    <div>
        <h2 class="card-title">
            <?= $selectedYear ?> vs <?= $selectedYear - 1 ?>
        </h2>

        <div class="card-subtitle">
            Year-over-year quality and service performance comparison.
        </div>
    </div>

</div>

<div class="card-body-pad">

<div class="comparison-grid">

<?php foreach ($comparisonRows as $comparison): ?>

    <?php
    $change =
        eaChange(
            (float)$comparison["current"],
            (float)$comparison["previous"]
        );

    $improved =
        $change === null
        ||
        $change == 0.0
            ? null
            : (
                $comparison["higher_better"]
                    ? $change > 0
                    : $change < 0
            );
    ?>

    <div class="comparison-item">

        <div class="comparison-label">
            <?= e($comparison["label"]) ?>
        </div>

        <div class="comparison-current">
            <?= number_format(
                (float)$comparison["current"],
                (int)$comparison["decimals"]
            ) ?>
            <?= e($comparison["suffix"]) ?>
        </div>

        <div class="comparison-prev">
            Previous:
            <?= number_format(
                (float)$comparison["previous"],
                (int)$comparison["decimals"]
            ) ?>
            <?= e($comparison["suffix"]) ?>
        </div>

        <div
            class="comparison-change <?= $improved === null ? "text-muted" : ($improved ? "text-success" : "text-danger") ?>"
        >
            <?= e(eaChangeText($change)) ?>
            vs previous year
        </div>

    </div>

<?php endforeach; ?>

</div>

</div>

</section>

<!-- ======================================================
     DETAILED EVALUATIONS
======================================================= -->

<section class="card-shell mt-3">

<div class="card-head">

    <div>
        <h2 class="card-title">
            Detailed Evaluation Records
        </h2>

        <div class="card-subtitle">
            <?= number_format(count($rows)) ?> request record(s) in the current filter.
        </div>
    </div>

</div>

<div class="table-wrap table-wrap-scroll">

<?php if (!$rows): ?>

    <div class="empty-state">
        <i class="bi bi-inbox"></i>
        No records match the selected filters.
    </div>

<?php else: ?>

<table class="ea-table">

<thead>
<tr>
    <th>Job No.</th>
    <th>Project / Department</th>
    <th>Request Type</th>
    <th>Owner</th>
    <th>Status</th>
    <th class="text-end">Turnaround</th>
    <th>Evaluation</th>
    <th>Rating</th>
    <th>Outcome</th>
    <th class="text-end">Action</th>
</tr>
</thead>

<tbody>

<?php foreach ($rows as $row): ?>

    <?php
    $rating =
        eaCombinedRating(
            $row
        );

    $owner =
        trim(
            (string)(
                $row["assigned_name"]
                ?? ""
            )
        );

    if ($owner === "") {
        $owner =
            trim(
                (string)(
                    $row["reviewed_by"]
                    ?? ""
                )
            );
    }

    $completionDate = eaActualCompletion($row);

    $turnaround =
        eaDateDiffDays(
            eaValidDateOrNull($row["date_request"] ?? null),
            $completionDate
        );

    $requestTypes =
        eaDecodeArray(
            $row["request_types"]
            ?? null
        );

    $evaluationSource =
        !empty(
            $row["client_survey_id"]
        )
            ? "Client Survey"
            : (
                $rating > 0
                    ? "Legacy Evaluation"
                    : "Pending"
            );
    ?>

<tr>

    <td class="fw-bold text-primary">
        <?= e($row["job_no"]) ?>
    </td>

    <td>
        <div class="fw-bold">
            <?= e($row["project_title"]) ?>
        </div>

        <div class="text-muted mt-1">
            <?= e($row["department"]) ?>
        </div>

        <div class="mt-1 small">
            <?= e($row["requestor_name"] ?? "") ?>
            <span class="service-pill service-other" style="margin-left:4px;"><?= e($requestorRoleLabels[eaRequestorRoleKey($row)] ?? "") ?></span>
        </div>
    </td>

    <td>

        <?php foreach ($requestTypes as $type): ?>

        <span class="service-pill <?= e(eaRequestTypeClass((string)$type)) ?>">
            <i class="bi <?= e(eaRequestTypeIcon((string)$type)) ?>"></i>
            <?= e($type) ?>
        </span>

        <?php endforeach; ?>

    </td>

    <td>
        <?= e($owner ?: "—") ?>
    </td>

    <td>
        <span class="status-pill <?= e(eaStatusClass((string)$row["approval_status"])) ?>">
            <?= e($row["approval_status"]) ?>
        </span>
    </td>

    <td class="text-end">
        <?= $turnaround !== null
            ? number_format($turnaround) . " d"
            : "—"
        ?>
    </td>

    <td>
        <?= e($evaluationSource) ?>
    </td>

    <td>
        <?php if ($rating > 0): ?>

            <span class="rating-pill <?= e(eaRatingClass($rating)) ?>">
                <?= $rating ?>/5
                •
                <?= e(eaRatingLabel($rating)) ?>
            </span>

        <?php else: ?>

            <span class="rating-pill rating-none">
                Not Rated
            </span>

        <?php endif; ?>
    </td>

    <td>
        <?= e($row["outcome_assessment"] ?? "—") ?>
    </td>

    <td class="text-end">
        <a
            class="btn btn-sm btn-outline-primary"
            href="<?= e(BASE_URL) ?>/staff/review_request.php?id=<?= (int)$row["id"] ?>"
        >
            Open
        </a>
    </td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

<?php endif; ?>

</div>

</section>

<section class="card-shell mt-3">

<div class="card-head">

    <div>
        <h2 class="card-title">
            All Requests Tracker
        </h2>

        <div class="card-subtitle">
            <?= number_format(count($rows)) ?> request(s) across all
            workflow stages in the current filter. Read-only — updates
            stay in the review flow via the Open button.
        </div>
    </div>

    <div class="card-tools">
        <a
            class="btn btn-sm btn-outline-primary"
            href="evaluation_analytics.php?<?= e($filterQuery) ?>&amp;export=csv"
        >
            <i class="bi bi-filetype-csv me-1"></i>
            Export Tracker CSV
        </a>

        <a
            class="btn btn-sm btn-outline-success"
            href="evaluation_analytics.php?<?= e($filterQuery) ?>&amp;export=excel"
        >
            <i class="bi bi-file-earmark-excel me-1"></i>
            Export Tracker Excel
        </a>
    </div>

</div>

<div class="tracker-outer">

<?php if (!$rows): ?>

    <div class="empty-state">
        <i class="bi bi-inbox"></i>
        No requests match the selected filters.
    </div>

<?php else: ?>

<?php
$trackerStatusCounts = [];

foreach ($rows as $countRow) {
    $countStatus = (string)(
        $countRow["approval_status"]
        ?? "Unknown"
    );

    $trackerStatusCounts[$countStatus] =
        ($trackerStatusCounts[$countStatus] ?? 0) + 1;
}

arsort($trackerStatusCounts);
?>

<div class="tracker-chips">
    <?php foreach ($trackerStatusCounts as $chipStatus => $chipTotal): ?>

    <span class="status-pill <?= e(eaStatusClass((string)$chipStatus)) ?>">
        <?= e($chipStatus) ?> · <?= number_format((int)$chipTotal) ?>
    </span>

    <?php endforeach; ?>
</div>

<div class="table-wrap-scroll tracker-scroll">

<table class="ea-table tracker-table">

<thead>
<tr>
    <th>Job No.</th>
    <th>Project / Requestor</th>
    <th>Request Type</th>
    <th>Requested → Target</th>
    <th>Owner</th>
    <th>Priority</th>
    <th>Status</th>
    <th>Reviewer → Approver</th>
    <th>Milestones</th>
    <th>Rating</th>
    <th class="text-end">Action</th>
</tr>
</thead>

<tbody>

<?php foreach ($rows as $row): ?>

    <?php
    $rating =
        eaCombinedRating(
            $row
        );

    $owner =
        trim(
            (string)(
                $row["assigned_name"]
                ?? ""
            )
        );

    if ($owner === "") {
        $owner =
            trim(
                (string)(
                    $row["reviewed_by"]
                    ?? ""
                )
            );
    }

    $requestTypes =
        eaDecodeArray(
            $row["request_types"]
            ?? null
        );

    $trackToken =
        trim(
            (string)(
                $row["public_token"]
                ?? ""
            )
        );
    ?>

<tr>

    <td class="fw-bold text-primary text-nowrap">
        <?= e($row["job_no"]) ?>
    </td>

    <td>
        <div class="fw-bold">
            <?= e($row["project_title"]) ?>
        </div>

        <div class="text-muted mt-1">
            <?= e($row["requestor_name"] ?? "") ?>
            <?php if (!empty($row["contact"])): ?>
                · <?= e($row["contact"]) ?>
            <?php endif; ?>
            <br><?= e($row["department"]) ?>
            <br><span class="service-pill service-other" style="margin-top:3px;"><?= e($requestorRoleLabels[eaRequestorRoleKey($row)] ?? "") ?></span>
        </div>
    </td>

    <td>

        <?php foreach ($requestTypes as $type): ?>

        <span class="service-pill <?= e(eaRequestTypeClass((string)$type)) ?>">
            <i class="bi <?= e(eaRequestTypeIcon((string)$type)) ?>"></i>
            <?= e($type) ?>
        </span>

        <?php endforeach; ?>

    </td>

    <td class="text-nowrap">
        <?= e($row["date_request"] ?? "—") ?>
        <br><span class="text-muted">→ <?= e($row["date_completion"] ?? "—") ?></span>
    </td>

    <td>
        <?= e($owner ?: "—") ?>
    </td>

    <td>
        <span class="priority-pill priority-<?= e(strtolower((string)($row["priority"] ?? "normal"))) ?>">
            <?= e($row["priority"] ?? "Normal") ?>
        </span>
    </td>

    <td>
        <span class="status-pill <?= e(eaStatusClass((string)$row["approval_status"])) ?>">
            <?= e($row["approval_status"]) ?>
        </span>
    </td>

    <td class="text-nowrap">
        <?= e($row["reviewed_by"] ?: "—") ?>
        <br><span class="text-muted">→ <?= e($row["approved_by"] ?: "—") ?></span>
    </td>

    <td class="text-nowrap">
        <span class="text-muted">Draft:</span>
        <?= e($row["initial_draft_date"] ?: "—") ?>
        <br><span class="text-muted">Final:</span>
        <?= e($row["final_approval_date"] ?: "—") ?>
    </td>

    <td>
        <?php if ($rating > 0): ?>

            <span class="rating-pill <?= e(eaRatingClass($rating)) ?>">
                <?= $rating ?>/5
            </span>

        <?php else: ?>

            <span class="rating-pill rating-none">
                —
            </span>

        <?php endif; ?>
    </td>

    <td class="text-end text-nowrap">
        <a
            class="btn btn-sm btn-outline-primary"
            href="<?= e(BASE_URL) ?>/staff/review_request.php?id=<?= (int)$row["id"] ?>"
        >
            Open
        </a>

        <?php if ($trackToken !== ""): ?>

        <a
            class="btn btn-sm btn-outline-secondary"
            href="<?= e(BASE_URL) ?>/track.php?token=<?= e($trackToken) ?>"
            target="_blank"
            rel="noopener"
        >
            Track
        </a>

        <?php endif; ?>
    </td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<div class="tracker-scroll-hint">
    <i class="bi bi-arrows-move me-1"></i>
    Scroll vertically &amp; horizontally to explore all <?= number_format(count($rows)) ?> tracker row(s) — header stays pinned.
</div>

<?php endif; ?>

</div>

</section>

<section class="card-shell mt-3">

<div class="card-head">

    <div>
        <h2 class="card-title">
            IEC Materials Consolidation — <?= (int)$selectedYear ?>
        </h2>

        <div class="card-subtitle">
            Monthly requested vs fulfilled quantities from job requests,
            plus library uploads, views, downloads and per-user activity.
            <?= $hasIecFulfilled ? "" : "Fulfillment tracking activates once the fulfilled-quantity column is installed." ?>
        </div>
    </div>

</div>

<?php if (!$hasIecItems && !$hasIecLibrary): ?>

    <div class="empty-state">
        <i class="bi bi-folder2-open"></i>
        IEC tracking tables are not installed yet.
    </div>

<?php else: ?>

<section class="kpi-grid" style="padding:12px 13px 0;">

    <article class="kpi-card">

        <div class="kpi-icon">
            <i class="bi bi-file-earmark-richtext"></i>
        </div>

        <div class="kpi-label">
            Qty Requested
        </div>

        <div class="kpi-value">
            <?= number_format($iecTotalQty) ?>
        </div>

        <div class="kpi-note">
            Across job requests in current filter
        </div>

    </article>

    <article class="kpi-card">

        <div class="kpi-icon">
            <i class="bi bi-check2-circle"></i>
        </div>

        <div class="kpi-label">
            Qty Fulfilled
        </div>

        <div class="kpi-value">
            <?= number_format($iecTotalDone) ?>
        </div>

        <div class="kpi-note">
            <?= number_format(eaPercent($iecTotalDone, $iecTotalQty), 1) ?>% fulfillment rate
        </div>

    </article>

    <article class="kpi-card">

        <div class="kpi-icon">
            <i class="bi bi-hourglass-split"></i>
        </div>

        <div class="kpi-label">
            Pending Qty
        </div>

        <div class="kpi-value">
            <?= number_format($iecTotalQty - $iecTotalDone) ?>
        </div>

        <div class="kpi-note">
            Requested minus fulfilled
        </div>

    </article>

    <article class="kpi-card">

        <div class="kpi-icon">
            <i class="bi bi-folder2-open"></i>
        </div>

        <div class="kpi-label">
            Active Library Items
        </div>

        <div class="kpi-value">
            <?= number_format($iecLibraryActive) ?>
        </div>

        <div class="kpi-note">
            <?= number_format($iecUploadsYear) ?> uploaded in <?= (int)$selectedYear ?>
        </div>

    </article>

    <article class="kpi-card">

        <div class="kpi-icon">
            <i class="bi bi-download"></i>
        </div>

        <div class="kpi-label">
            Library Downloads
        </div>

        <div class="kpi-value">
            <?= number_format($iecLibraryDownloads) ?>
        </div>

        <div class="kpi-note">
            <?= number_format($iecDownloadsYear) ?> downloads · <?= number_format($iecViewsYear) ?> views in <?= (int)$selectedYear ?>
        </div>

    </article>

    <article class="kpi-card">

        <div class="kpi-icon">
            <i class="bi bi-link-45deg"></i>
        </div>

        <div class="kpi-label">
            Unlinked Lines
        </div>

        <div class="kpi-value">
            <?= number_format($iecUnlinkedLines) ?>
        </div>

        <div class="kpi-note">
            Free-text rows not tied to a library item
        </div>

    </article>

</section>

<div class="table-wrap">

<table class="ea-table">

<thead>
<tr>
    <th>Month</th>
    <th class="text-end">Materials</th>
    <th class="text-end">Qty Requested</th>
    <th class="text-end">Qty Fulfilled</th>
    <th class="text-end">Pending</th>
    <th class="text-end">Rate</th>
</tr>
</thead>

<tbody>

<?php foreach ($iecMonths as $iecMonth => $iecData): ?>

<tr>
    <td class="fw-bold">
        <?= e(eaMonthShort((int)$iecMonth)) ?>
    </td>
    <td class="text-end">
        <?= number_format(count($iecData["materials"])) ?>
    </td>
    <td class="text-end">
        <?= number_format($iecData["qty"]) ?>
    </td>
    <td class="text-end">
        <?= number_format($iecData["done"]) ?>
    </td>
    <td class="text-end">
        <?= number_format($iecData["qty"] - $iecData["done"]) ?>
    </td>
    <td class="text-end">
        <?= number_format(eaPercent($iecData["done"], $iecData["qty"]), 1) ?>%
    </td>
</tr>

<?php endforeach; ?>

<tr>
    <td class="fw-bold">Total</td>
    <td class="text-end">—</td>
    <td class="text-end fw-bold"><?= number_format($iecTotalQty) ?></td>
    <td class="text-end fw-bold"><?= number_format($iecTotalDone) ?></td>
    <td class="text-end fw-bold"><?= number_format($iecTotalQty - $iecTotalDone) ?></td>
    <td class="text-end fw-bold"><?= number_format($iecFulfillmentRate, 1) ?>%</td>
</tr>

</tbody>

</table>

</div>

<div class="feedback-columns" style="padding:0 13px 13px;">

    <div>
        <div class="feedback-column-title">
            <i class="bi bi-trophy"></i>
            Top Requested Materials
        </div>

        <?php if (!$iecTopMaterials): ?>

        <div class="empty-state">No IEC line items in filter.</div>

        <?php else: ?>

        <table class="ea-table">
        <thead><tr><th>Material</th><th class="text-end">Qty</th><th class="text-end">Done</th></tr></thead>
        <tbody>
        <?php foreach ($iecTopMaterials as $topMaterial): ?>
        <tr>
            <td><?= e($topMaterial["label"]) ?></td>
            <td class="text-end"><?= number_format($topMaterial["qty"]) ?></td>
            <td class="text-end"><?= number_format($topMaterial["done"]) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>

        <?php endif; ?>

    </div>

    <div>
        <div class="feedback-column-title">
            <i class="bi bi-building"></i>
            Top Requesting Offices
        </div>

        <?php if (!$iecTopDepartments): ?>

        <div class="empty-state">No IEC line items in filter.</div>

        <?php else: ?>

        <table class="ea-table">
        <thead><tr><th>Department</th><th class="text-end">Qty</th><th class="text-end">Done</th></tr></thead>
        <tbody>
        <?php foreach ($iecTopDepartments as $topDept => $topDeptData): ?>
        <tr>
            <td><?= e($topDept) ?></td>
            <td class="text-end"><?= number_format($topDeptData["qty"]) ?></td>
            <td class="text-end"><?= number_format($topDeptData["done"]) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>

        <?php endif; ?>

    </div>

</div>

<div class="feedback-columns" style="padding:0 13px 13px;">

    <div>
        <div class="feedback-column-title">
            <i class="bi bi-cloud-download"></i>
            Most Downloaded Library Files
        </div>

        <?php if (!$iecTopDownloaded): ?>

        <div class="empty-state">No download activity recorded.</div>

        <?php else: ?>

        <table class="ea-table">
        <thead><tr><th>Material</th><th class="text-end">Downloads</th><th class="text-end">Views</th></tr></thead>
        <tbody>
        <?php foreach ($iecTopDownloaded as $topDl): ?>
        <tr>
            <td><a href="<?= e(BASE_URL) ?>/iec_materials.php" title="Open IEC Materials Library"><?= e($topDl["title"]) ?></a></td>
            <td class="text-end"><?= number_format((int)$topDl["download_count"]) ?></td>
            <td class="text-end"><?= number_format((int)($topDl["view_count"] ?? 0)) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>

        <?php endif; ?>

    </div>

    <div>
        <div class="feedback-column-title">
            <i class="bi bi-people"></i>
            Top Downloaders (<?= (int)$selectedYear ?>)
        </div>

        <?php if (!$iecTopDownloaders): ?>

        <div class="empty-state">No per-user download logs in filter.</div>

        <?php else: ?>

        <table class="ea-table">
        <thead><tr><th>User</th><th class="text-end">Downloads</th></tr></thead>
        <tbody>
        <?php foreach ($iecTopDownloaders as $topUser): ?>
        <tr>
            <td><?= e($topUser["name"]) ?></td>
            <td class="text-end"><?= number_format((int)$topUser["total"]) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>

        <?php endif; ?>

    </div>

</div>

<div class="feedback-columns" style="padding:0 13px 13px;">

    <div>
        <div class="feedback-column-title">
            <i class="bi bi-eye"></i>
            Most Viewed Library Files
        </div>

        <?php if (!$iecTopViewed): ?>

        <div class="empty-state">No file-view activity recorded yet. Views are counted when a user opens a file with the View button.</div>

        <?php else: ?>

        <table class="ea-table">
        <thead><tr><th>Material</th><th class="text-end">Views</th><th class="text-end">Downloads</th></tr></thead>
        <tbody>
        <?php foreach ($iecTopViewed as $topVw): ?>
        <tr>
            <td><a href="<?= e(BASE_URL) ?>/iec_materials.php" title="Open IEC Materials Library"><?= e($topVw["title"]) ?></a></td>
            <td class="text-end"><?= number_format((int)$topVw["view_count"]) ?></td>
            <td class="text-end"><?= number_format((int)($topVw["download_count"] ?? 0)) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>

        <?php endif; ?>

    </div>

    <div>
        <div class="feedback-column-title">
            <i class="bi bi-people"></i>
            Top Viewers (<?= (int)$selectedYear ?>)
        </div>

        <?php if (!$iecTopViewers): ?>

        <div class="empty-state">No per-user file-view logs in filter.</div>

        <?php else: ?>

        <table class="ea-table">
        <thead><tr><th>User</th><th class="text-end">Views</th></tr></thead>
        <tbody>
        <?php foreach ($iecTopViewers as $topViewer): ?>
        <tr>
            <td><?= e($topViewer["name"]) ?></td>
            <td class="text-end"><?= number_format((int)$topViewer["total"]) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>

        <?php endif; ?>

    </div>

</div>

<?php endif; ?>

</section>

</div>

</main>

</div>

</div>

<script>
document.addEventListener(
    "DOMContentLoaded",
    function () {

        Chart.defaults.font.family =
            "system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";

        Chart.defaults.color =
            "#667085";

        const monthLabels =
            <?= json_encode($monthLabels) ?>;

        /*
        |--------------------------------------------------------------------------
        | Satisfaction
        |--------------------------------------------------------------------------
        */

        const satisfactionCanvas =
            document.getElementById(
                "satisfactionChart"
            );

        if (satisfactionCanvas) {
            new Chart(
                satisfactionCanvas,
                {
                    type:"doughnut",
                    data:{
                        labels:[
                            "Poor (1)",
                            "Fair (2)",
                            "Good (3)",
                            "Very Good (4)",
                            "Excellent (5)"
                        ],
                        datasets:[
                            {
                                data:
                                    <?= json_encode(
                                        array_values(
                                            $satisfactionDistribution
                                        )
                                    ) ?>,

                                borderWidth:0
                            }
                        ]
                    },
                    options:{
                        responsive:true,
                        maintainAspectRatio:false,
                        cutout:"68%",
                        plugins:{
                            legend:{
                                position:"bottom",
                                labels:{
                                    boxWidth:10,
                                    boxHeight:10,
                                    font:{
                                        size:10
                                    }
                                }
                            }
                        }
                    }
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Monthly trend
        |--------------------------------------------------------------------------
        */

        const monthlyCanvas =
            document.getElementById(
                "monthlyTrendChart"
            );

        if (monthlyCanvas) {
            new Chart(
                monthlyCanvas,
                {
                    type:"line",
                    data:{
                        labels:monthLabels,
                        datasets:[
                            {
                                label:"Completed",
                                data:
                                    <?= json_encode(
                                        array_values(
                                            $monthlyCompleted
                                        )
                                    ) ?>,

                                tension:.32,
                                yAxisID:"y"
                            },
                            {
                                label:"Evaluations",
                                data:
                                    <?= json_encode(
                                        array_values(
                                            $monthlyEvaluated
                                        )
                                    ) ?>,

                                tension:.32,
                                yAxisID:"y"
                            },
                            {
                                label:"Response Rate %",
                                data:
                                    <?= json_encode(
                                        array_values(
                                            $monthlyResponse
                                        )
                                    ) ?>,

                                tension:.32,
                                borderDash:[4,4],
                                yAxisID:"y1"
                            }
                        ]
                    },
                    options:{
                        responsive:true,
                        maintainAspectRatio:false,
                        interaction:{
                            mode:"index",
                            intersect:false
                        },
                        plugins:{
                            legend:{
                                position:"bottom",
                                labels:{
                                    boxWidth:10,
                                    font:{
                                        size:10
                                    }
                                }
                            }
                        },
                        scales:{
                            y:{
                                beginAtZero:true,
                                ticks:{
                                    precision:0
                                }
                            },
                            y1:{
                                beginAtZero:true,
                                suggestedMax:100,
                                max:100,
                                position:"right",
                                grid:{
                                    drawOnChartArea:false
                                },
                                ticks:{
                                    callback:
                                        value =>
                                            value + "%"
                                }
                            }
                        }
                    }
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Client criteria
        |--------------------------------------------------------------------------
        */

        const criteriaCanvas =
            document.getElementById(
                "criteriaChart"
            );

        if (criteriaCanvas) {
            new Chart(
                criteriaCanvas,
                {
                    type:"bar",
                    data:{
                        labels:
                            <?= json_encode(
                                $criteriaLabels
                            ) ?>,

                        datasets:[
                            {
                                label:"Average Score",
                                data:
                                    <?= json_encode(
                                        $criteriaValues
                                    ) ?>,

                                borderWidth:0,
                                borderRadius:6
                            }
                        ]
                    },
                    options:{
                        indexAxis:"y",
                        responsive:true,
                        maintainAspectRatio:false,
                        plugins:{
                            legend:{
                                display:false
                            }
                        },
                        scales:{
                            x:{
                                beginAtZero:true,
                                max:5,
                                ticks:{
                                    stepSize:1
                                }
                            }
                        }
                    }
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Requestor role (Staff / Supervisor / User)
        |--------------------------------------------------------------------------
        */

        const roleCanvas =
            document.getElementById(
                "requestorRoleChart"
            );

        if (roleCanvas) {
            new Chart(
                roleCanvas,
                {
                    type:"bar",
                    data:{
                        labels:
                            <?= json_encode($requestorRoleChartLabels) ?>,

                        datasets:[
                            {
                                label:"Requests",
                                data:
                                    <?= json_encode($requestorRoleChartRequests) ?>,

                                borderWidth:0,
                                borderRadius:6
                            },
                            {
                                label:"Evaluated",
                                data:
                                    <?= json_encode($requestorRoleChartEvaluated) ?>,

                                borderWidth:0,
                                borderRadius:6
                            }
                        ]
                    },
                    options:{
                        indexAxis:"y",
                        responsive:true,
                        maintainAspectRatio:false,
                        plugins:{
                            legend:{
                                position:"bottom",
                                labels:{
                                    boxWidth:10,
                                    font:{
                                        size:10
                                    }
                                }
                            }
                        },
                        scales:{
                            x:{
                                beginAtZero:true,
                                ticks:{
                                    precision:0
                                }
                            }
                        }
                    }
                }
            );
        }

    }
);
</script>

</body>
</html>
