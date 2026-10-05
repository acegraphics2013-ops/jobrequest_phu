<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - UPGRADED ROLE-AWARE DASHBOARD
|--------------------------------------------------------------------------
| Drop-in replacement:
|   C:\laragon\www\jobrequest_phu\dashboard.php
|
| Uses the actual Workbench architecture:
|   includes/config.php  -> MySQLi $conn
|   includes/auth.php    -> checkLogin()
|   includes/helpers.php -> e(), decodeArray(), etc.
|--------------------------------------------------------------------------
*/

include __DIR__ . "/includes/config.php";
include __DIR__ . "/includes/auth.php";
include __DIR__ . "/includes/helpers.php";
require_once __DIR__ . "/includes/module_access.php";

checkLogin();
requireModule($conn, "dashboard");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$userId = (int) ($_SESSION["user_id"] ?? 0);
$role = (string) ($_SESSION["role"] ?? "user");
$userName = (string) ($_SESSION["name"] ?? "User");

$isUser = $role === "user";
$isStaff = $role === "staff";
$isSupervisor = $role === "supervisor";
$isAdmin = $role === "admin";
$isManager = in_array($role, ["supervisor", "admin"], true);
$isHpuRole = in_array($role, ["staff", "supervisor", "admin"], true);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function dashTableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");

    return $result && $result->num_rows > 0;
}

function dashColumnExists(
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

    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row["total"] ?? 0) > 0;
}

function dashScalar(
    mysqli $conn,
    string $sql,
    string $types = "",
    array $params = []
): int|float {
    $stmt = $conn->prepare($sql);

    if ($types !== "" && $params) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();

    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    if (!$row || !array_key_exists(0, $row)) {
        return 0;
    }

    $value = $row[0];

    if ($value === null || $value === "") {
        return 0;
    }

    if (!is_numeric($value)) {
        return 0;
    }

    $stringValue = (string) $value;

    if (
        str_contains($stringValue, ".")
        || stripos($stringValue, "e") !== false
    ) {
        return (float) $value;
    }

    return (int) $value;
}

function dashRows(
    mysqli $conn,
    string $sql,
    string $types = "",
    array $params = []
): array {
    $stmt = $conn->prepare($sql);

    if ($types !== "" && $params) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();

    $rows = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    return $rows;
}

function dashStatusClass(string $status): string
{
    return match ($status) {
        "Pending Review" => "status-pending",
        "Under Review" => "status-review",
        "Approved" => "status-approved",
        "In Development" => "status-development",
        "For Revision" => "status-revision",
        "Disapproved" => "status-disapproved",
        "Completed" => "status-completed",
        "For Evaluation" => "status-evaluation",
        "Closed" => "status-closed",
        default => "status-default"
    };
}

function dashRequestTypeClass(string $type): string
{
    return match ($type) {
        "Graphic Design" => "type-graphic",
        "Video Production" => "type-video",
        "Photo/Video Coverage" => "type-coverage",
        "IEC Materials" => "type-iec",
        "Publication Submission" => "type-publication",
        default => "type-other"
    };
}

function dashRequestTypeIcon(string $type): string
{
    return match ($type) {
        "Graphic Design" => "bi-palette",
        "Video Production" => "bi-camera-reels",
        "Photo/Video Coverage" => "bi-camera",
        "IEC Materials" => "bi-file-earmark-richtext",
        "Publication Submission" => "bi-newspaper",
        default => "bi-tag"
    };
}

function dashPriorityClass(?string $priority): string
{
    return match ((string) $priority) {
        "Urgent" => "priority-urgent",
        "High" => "priority-high",
        "Low" => "priority-low",
        default => "priority-normal"
    };
}

function dashPublicationStatusClass(string $status): string
{
    return match ($status) {
        "For Review" => "status-review",
        "In Preparation" => "status-development",
        "For Approval" => "status-for-approval",
        "Published" => "status-published",
        "Closed" => "status-closed",
        default => "status-default"
    };
}

function dashPublicationTypeLabel(
    ?string $value,
    string $fallback
): string {
    $value = trim((string) $value);

    if ($value === "") {
        return $fallback;
    }

    $decoded = json_decode($value, true);

    if (is_array($decoded)) {
        $labels = array_values(
            array_filter(
                array_map(
                    static fn($item): string => trim((string) $item),
                    $decoded
                )
            )
        );

        if ($labels) {
            return implode(", ", $labels);
        }
    }

    return $value;
}

function dashDate(?string $date, string $format = "M d, Y"): string
{
    if (!$date) {
        return "—";
    }

    try {
        return (new DateTime($date))->format($format);
    } catch (Throwable $e) {
        return "—";
    }
}

function dashDaysUntil(?string $date): ?int
{
    if (!$date) {
        return null;
    }

    try {
        $today = new DateTime("today");
        $target = new DateTime($date);
        $target->setTime(0, 0, 0);

        return (int) $today
            ->diff($target)
            ->format("%r%a");
    } catch (Throwable $e) {
        return null;
    }
}

function dashGreeting(): string
{
    $hour = (int) date("G");

    if ($hour < 12) {
        return "Good morning";
    }

    if ($hour < 18) {
        return "Good afternoon";
    }

    return "Good evening";
}

function dashInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = "";

    foreach (array_slice($parts, 0, 2) as $part) {
        if ($part !== "") {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }
    }

    return $letters ?: "HP";
}

/*
|--------------------------------------------------------------------------
| Feature Detection
|--------------------------------------------------------------------------
*/

$hasAssignedTo = dashColumnExists(
    $conn,
    "job_requests",
    "assigned_to"
);

$hasAssignedAt = dashColumnExists(
    $conn,
    "job_requests",
    "assigned_at"
);

$hasAssignmentNotes = dashColumnExists(
    $conn,
    "job_requests",
    "assignment_notes"
);

$hasPriority = dashColumnExists(
    $conn,
    "job_requests",
    "priority"
);

$hasArchived = dashColumnExists(
    $conn,
    "job_requests",
    "archived"
);

$hasNotifications = dashTableExists(
    $conn,
    "notifications"
);

$hasCalendar = dashTableExists(
    $conn,
    "hpu_calendar_activities"
);

$hasCalendarScope =
    $hasCalendar
    &&
    dashColumnExists(
        $conn,
        "hpu_calendar_activities",
        "calendar_scope"
    );

$hasCalendarAssignees = dashTableExists(
    $conn,
    "hpu_calendar_assignees"
);

$hasIecLibrary = dashTableExists(
    $conn,
    "iec_materials"
);

$hasClientSurvey = dashTableExists(
    $conn,
    "job_request_client_experience"
);

$hasOfficeActivities = dashTableExists(
    $conn,
    "office_activity_requests"
);


$hasEventPublications = dashTableExists(
    $conn,
    "publication_event_submissions"
);

$hasRecognitionPublications = dashTableExists(
    $conn,
    "publication_recognition_submissions"
);

$hasEventPublicationArchive =
    $hasEventPublications
    &&
    dashColumnExists(
        $conn,
        "publication_event_submissions",
        "archived"
    );

$hasRecognitionPublicationArchive =
    $hasRecognitionPublications
    &&
    dashColumnExists(
        $conn,
        "publication_recognition_submissions",
        "archived"
    );

/*
|--------------------------------------------------------------------------
| Common Status Definitions
|--------------------------------------------------------------------------
*/

$activeStatuses = [
    "Pending Review",
    "Under Review",
    "Approved",
    "In Development",
    "For Revision"
];

$closedStatuses = [
    "Completed",
    "For Evaluation",
    "Closed",
    "Disapproved"
];

/*
|--------------------------------------------------------------------------
| Role Scope
|--------------------------------------------------------------------------
| USER:
|   own submitted requests
|
| STAFF:
|   personal assignment dashboard when assigned_to is available
|   otherwise falls back to general workflow counts
|
| SUPERVISOR / ADMIN:
|   all operational requests
|--------------------------------------------------------------------------
*/

$scopeWhere = "";
$scopeTypes = "";
$scopeParams = [];

if ($isUser) {
    $scopeWhere = "jr.requestor = ?";
    $scopeTypes = "i";
    $scopeParams = [$userId];
} elseif ($isStaff && $hasAssignedTo) {
    $scopeWhere = "jr.assigned_to = ?";
    $scopeTypes = "i";
    $scopeParams = [$userId];
} else {
    $scopeWhere = "1 = 1";
}

if ($hasArchived) {
    $scopeWhere .= " AND COALESCE(jr.archived,0) = 0";
}

/*
|--------------------------------------------------------------------------
| Primary KPI Counts
|--------------------------------------------------------------------------
*/

$totalScoped = (int) dashScalar(
    $conn,
    "SELECT COUNT(*)
     FROM job_requests jr
     WHERE {$scopeWhere}",
    $scopeTypes,
    $scopeParams
);

$activeScoped = (int) dashScalar(
    $conn,
    "SELECT COUNT(*)
     FROM job_requests jr
     WHERE {$scopeWhere}
       AND jr.approval_status NOT IN (
            'Completed',
            'For Evaluation',
            'Closed',
            'Disapproved'
       )",
    $scopeTypes,
    $scopeParams
);

$completedScoped = (int) dashScalar(
    $conn,
    "SELECT COUNT(*)
     FROM job_requests jr
     WHERE {$scopeWhere}
       AND jr.approval_status IN (
            'Completed',
            'For Evaluation',
            'Closed'
       )",
    $scopeTypes,
    $scopeParams
);

$overdueScoped = (int) dashScalar(
    $conn,
    "SELECT COUNT(*)
     FROM job_requests jr
     WHERE {$scopeWhere}
       AND jr.date_completion IS NOT NULL
       AND jr.date_completion < CURDATE()
       AND jr.approval_status IN (
            'Approved',
            'In Development'
       )",
    $scopeTypes,
    $scopeParams
);

$dueSoonScoped = (int) dashScalar(
    $conn,
    "SELECT COUNT(*)
     FROM job_requests jr
     WHERE {$scopeWhere}
       AND jr.date_completion IS NOT NULL
       AND jr.date_completion BETWEEN CURDATE()
           AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
       AND jr.approval_status IN (
            'Approved',
            'In Development'
       )",
    $scopeTypes,
    $scopeParams
);

$pendingScoped = (int) dashScalar(
    $conn,
    "SELECT COUNT(*)
     FROM job_requests jr
     WHERE {$scopeWhere}
       AND jr.approval_status = 'Pending Review'",
    $scopeTypes,
    $scopeParams
);

$revisionScoped = (int) dashScalar(
    $conn,
    "SELECT COUNT(*)
     FROM job_requests jr
     WHERE {$scopeWhere}
       AND jr.approval_status = 'For Revision'",
    $scopeTypes,
    $scopeParams
);


/*
|--------------------------------------------------------------------------
| Publication Submission Summary
|--------------------------------------------------------------------------
| Publication submissions use their own workflow:
|   For Review → In Preparation → For Approval → Published → Closed
|
| Archived publication records are excluded whenever the archived column
| exists. Normal users see only their own submissions; HPU roles see all
| active publication submissions.
|--------------------------------------------------------------------------
*/

$publicationTotalScoped = 0;
$publicationActiveScoped = 0;
$publicationForReview = 0;
$publicationInPreparation = 0;
$publicationForApproval = 0;
$publicationPublishedThisMonth = 0;
$publicationPreferredPassed = 0;
$publicationPreferredSoon = 0;

$publicationScopeWhere = $isUser
    ? "submitted_by = ?"
    : "1 = 1";

$publicationScopeTypes = $isUser
    ? "i"
    : "";

$publicationScopeParams = $isUser
    ? [$userId]
    : [];

$publicationTables = [];

if ($hasEventPublications) {
    $eventPublishedDateColumn =
        dashColumnExists(
            $conn,
            "publication_event_submissions",
            "published_at"
        )
        ? "published_at"
        : (
            dashColumnExists(
                $conn,
                "publication_event_submissions",
                "updated_at"
            )
            ? "updated_at"
            : "created_at"
        );

    $publicationTables[] = [
        "table" => "publication_event_submissions",
        "has_archived" => $hasEventPublicationArchive,
        "published_date_column" => $eventPublishedDateColumn
    ];
}

if ($hasRecognitionPublications) {
    $recognitionPublishedDateColumn =
        dashColumnExists(
            $conn,
            "publication_recognition_submissions",
            "published_at"
        )
        ? "published_at"
        : (
            dashColumnExists(
                $conn,
                "publication_recognition_submissions",
                "updated_at"
            )
            ? "updated_at"
            : "created_at"
        );

    $publicationTables[] = [
        "table" => "publication_recognition_submissions",
        "has_archived" => $hasRecognitionPublicationArchive,
        "published_date_column" => $recognitionPublishedDateColumn
    ];
}

foreach ($publicationTables as $publicationTable) {
    $table = $publicationTable["table"];

    $archiveClause = $publicationTable["has_archived"]
        ? " AND COALESCE(archived,0) = 0"
        : "";

    $publicationTotalScoped += (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM `{$table}`
         WHERE {$publicationScopeWhere}
           {$archiveClause}",
        $publicationScopeTypes,
        $publicationScopeParams
    );

    $publicationActiveScoped += (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM `{$table}`
         WHERE {$publicationScopeWhere}
           {$archiveClause}
           AND COALESCE(status,'For Review') NOT IN (
                'Published',
                'Closed'
           )",
        $publicationScopeTypes,
        $publicationScopeParams
    );

    $publicationForReview += (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM `{$table}`
         WHERE {$publicationScopeWhere}
           {$archiveClause}
           AND COALESCE(status,'For Review') = 'For Review'",
        $publicationScopeTypes,
        $publicationScopeParams
    );

    $publicationInPreparation += (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM `{$table}`
         WHERE {$publicationScopeWhere}
           {$archiveClause}
           AND status = 'In Preparation'",
        $publicationScopeTypes,
        $publicationScopeParams
    );

    $publicationForApproval += (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM `{$table}`
         WHERE {$publicationScopeWhere}
           {$archiveClause}
           AND status = 'For Approval'",
        $publicationScopeTypes,
        $publicationScopeParams
    );

    $publishedDateColumn =
        (string) $publicationTable["published_date_column"];

    $publicationPublishedThisMonth += (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM `{$table}`
         WHERE {$publicationScopeWhere}
           {$archiveClause}
           AND status = 'Published'
           AND `{$publishedDateColumn}` IS NOT NULL
           AND YEAR(`{$publishedDateColumn}`) = YEAR(CURDATE())
           AND MONTH(`{$publishedDateColumn}`) = MONTH(CURDATE())",
        $publicationScopeTypes,
        $publicationScopeParams
    );

    $publicationPreferredPassed += (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM `{$table}`
         WHERE {$publicationScopeWhere}
           {$archiveClause}
           AND preferred_publication_date IS NOT NULL
           AND preferred_publication_date < CURDATE()
           AND COALESCE(status,'For Review') NOT IN (
                'Published',
                'Closed'
           )",
        $publicationScopeTypes,
        $publicationScopeParams
    );

    $publicationPreferredSoon += (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM `{$table}`
         WHERE {$publicationScopeWhere}
           {$archiveClause}
           AND preferred_publication_date IS NOT NULL
           AND preferred_publication_date BETWEEN CURDATE()
               AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
           AND COALESCE(status,'For Review') NOT IN (
                'Published',
                'Closed'
           )",
        $publicationScopeTypes,
        $publicationScopeParams
    );
}

$combinedTotalScoped =
    $totalScoped
    +
    $publicationTotalScoped;

$combinedActiveScoped =
    $activeScoped
    +
    $publicationActiveScoped;

/*
|--------------------------------------------------------------------------
| Manager-specific Operational Counts
|--------------------------------------------------------------------------
*/

$unassignedCount = 0;

if ($isManager && $hasAssignedTo) {
    $unassignedWhere = $hasArchived
        ? "AND COALESCE(archived,0) = 0"
        : "";

    $unassignedCount = (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM job_requests
         WHERE assigned_to IS NULL
           {$unassignedWhere}
           AND approval_status NOT IN (
                'Completed',
                'For Evaluation',
                'Closed',
                'Disapproved'
           )"
    );
}

/*
|--------------------------------------------------------------------------
| Satisfaction
|--------------------------------------------------------------------------
| Client Experience Survey is the authoritative evaluation source.
|--------------------------------------------------------------------------
*/

$averageRating = 0.0;

/*
|--------------------------------------------------------------------------
| Client Experience Survey
|--------------------------------------------------------------------------
| Existing Workbench table:
|   job_request_client_experience
|
| HPU roles:
|   consolidated survey summary + analytics link
|
| Requesting users:
|   outstanding survey reminders for completed / evaluation requests
|--------------------------------------------------------------------------
*/

$surveySummary = [
    "total" => 0,
    "average" => 0.0,
    "this_month" => 0,
    "positive" => 0.0,
    "average_service" => 0.0
];

$pendingSurveyCount = 0;
$pendingSurveys = [];

if ($hasClientSurvey) {

    if ($isHpuRole) {
        $surveyRow = dashRows(
            $conn,
            "SELECT
                COUNT(*) AS total,
                COALESCE(
                    AVG(overall_satisfaction),
                    0
                ) AS average,
                COALESCE(
                    AVG(
                        (
                            timeliness
                            + responsiveness
                            + quality_output
                            + professionalism
                            + communication
                            + service_met_needs
                        ) / 6
                    ),
                    0
                ) AS average_service,
                COALESCE(
                    SUM(
                        YEAR(submitted_at) = YEAR(CURDATE())
                        AND MONTH(submitted_at) = MONTH(CURDATE())
                    ),
                    0
                ) AS this_month,
                COALESCE(
                    SUM(
                        outcome_assessment = 'Yes'
                    ),
                    0
                ) AS positive_count
             FROM job_request_client_experience"
        );

        $surveyRow = $surveyRow[0] ?? [];

        $surveyTotal = (int) ($surveyRow["total"] ?? 0);
        $surveyPositiveCount = (int) ($surveyRow["positive_count"] ?? 0);

        $surveySummary = [
            "total" => $surveyTotal,

            "average" => round(
                (float) ($surveyRow["average"] ?? 0),
                2
            ),

            "average_service" => round(
                (float) ($surveyRow["average_service"] ?? 0),
                2
            ),

            "this_month" => (int) ($surveyRow["this_month"] ?? 0),

            "positive" =>
                $surveyTotal > 0
                ? round(
                    ($surveyPositiveCount / $surveyTotal) * 100,
                    1
                )
                : 0
        ];

        $averageRating =
            (float) $surveySummary["average"];
    }

    if ($isUser) {
        $pendingSurveyCount = (int) dashScalar(
            $conn,
            "SELECT COUNT(*)
             FROM job_requests jr
             LEFT JOIN job_request_client_experience s
                ON s.request_id = jr.id
             WHERE jr.requestor = ?
               AND jr.approval_status IN (
                    'Completed',
                    'For Evaluation'
               )
               AND s.id IS NULL",
            "i",
            [$userId]
        );

        $pendingSurveys = dashRows(
            $conn,
            "SELECT
                jr.id,
                jr.job_no,
                jr.project_title,
                jr.approval_status,
                jr.date_completion
             FROM job_requests jr
             LEFT JOIN job_request_client_experience s
                ON s.request_id = jr.id
             WHERE jr.requestor = ?
               AND jr.approval_status IN (
                    'Completed',
                    'For Evaluation'
               )
               AND s.id IS NULL
             ORDER BY
                jr.date_completion DESC,
                jr.id DESC
             LIMIT 3",
            "i",
            [$userId]
        );
    }
}


/*
|--------------------------------------------------------------------------
| USER Dashboard: Office Activities + Upcoming Public Activities
|--------------------------------------------------------------------------
*/

$userOfficePendingCount = 0;
$userOfficeNeedsClarification = 0;
$userOfficeScheduledCount = 0;
$userUpcomingActivities = [];

if ($isUser && $hasOfficeActivities) {
    $userOfficePendingCount = (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM office_activity_requests
         WHERE submitted_by = ?
           AND status IN (
                'Submitted',
                'Under Review',
                'Needs Clarification'
           )",
        "i",
        [$userId]
    );

    $userOfficeNeedsClarification = (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM office_activity_requests
         WHERE submitted_by = ?
           AND status = 'Needs Clarification'",
        "i",
        [$userId]
    );

    $userOfficeScheduledCount = (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM office_activity_requests
         WHERE submitted_by = ?
           AND status = 'Scheduled'
           AND COALESCE(end_datetime,start_datetime) >= NOW()",
        "i",
        [$userId]
    );

}

/*
 * HPU + Hospital shared activities visible to normal USER accounts.
 */
if ($isUser && $hasCalendar) {
    $scopeSelect = $hasCalendarScope
        ? "calendar_scope"
        : "'HPU' AS calendar_scope";

    $sharedUpcoming = dashRows(
        $conn,
        "SELECT
            id,
            title,
            activity_type,
            {$scopeSelect},
            location,
            start_datetime,
            end_datetime,
            all_day
         FROM hpu_calendar_activities
         WHERE COALESCE(end_datetime,start_datetime) >= NOW()
         ORDER BY start_datetime ASC
         LIMIT 8"
    );

    foreach ($sharedUpcoming as $activity) {
        $userUpcomingActivities[] = [
            "source" => (string) ($activity["calendar_scope"] ?? "HPU"),
            "title" => (string) $activity["title"],
            "subtitle" => (string) $activity["activity_type"],
            "location" => (string) ($activity["location"] ?? ""),
            "start_datetime" => (string) $activity["start_datetime"],
            "all_day" => (int) $activity["all_day"],
            "status" => "",
            "url" => BASE_URL . "/my_calendar.php"
        ];
    }
}

/*
 * Add only the requesting user's own Office Activities to their
 * upcoming calendar preview.
 */
if ($isUser && $hasOfficeActivities) {
    $officeUpcoming = dashRows(
        $conn,
        "SELECT
            id,
            activity_title,
            venue,
            start_datetime,
            end_datetime,
            all_day,
            status
         FROM office_activity_requests
         WHERE submitted_by = ?
           AND status <> 'Cancelled'
           AND COALESCE(end_datetime,start_datetime) >= NOW()
         ORDER BY start_datetime ASC
         LIMIT 8",
        "i",
        [$userId]
    );

    foreach ($officeUpcoming as $activity) {
        $userUpcomingActivities[] = [
            "source" => "Office",
            "title" => (string) $activity["activity_title"],
            "subtitle" => "My Office Activity",
            "location" => (string) ($activity["venue"] ?? ""),
            "start_datetime" => (string) $activity["start_datetime"],
            "all_day" => (int) $activity["all_day"],
            "status" => (string) $activity["status"],
            "url" => BASE_URL . "/my_calendar.php"
        ];
    }
}

if ($userUpcomingActivities) {
    usort(
        $userUpcomingActivities,
        static function (array $a, array $b): int {
            return strcmp(
                (string) $a["start_datetime"],
                (string) $b["start_datetime"]
            );
        }
    );

    $userUpcomingActivities = array_slice(
        $userUpcomingActivities,
        0,
        6
    );
}

/*
|--------------------------------------------------------------------------
| Needs Attention
|--------------------------------------------------------------------------
*/

$attention = [];

if ($overdueScoped > 0) {
    $attention[] = [
        "level" => "danger",
        "icon" => "bi-exclamation-octagon",
        "title" => $overdueScoped . " overdue approved job request" . ($overdueScoped === 1 ? "" : "s"),
        "text" => $isStaff
            ? "Review your assigned work that has passed its completion date."
            : ($isUser
                ? "One or more of your requests has passed its target completion date."
                : "Immediate follow-up may be needed for overdue work."),
        "url" => $isStaff
            ? BASE_URL . "/my_assigned_jobs.php?tab=overdue"
            : ($isUser
                ? BASE_URL . "/user/my_requests.php"
                : BASE_URL . "/assign_jobs.php")
    ];
}

if ($isManager && $unassignedCount > 0) {
    $attention[] = [
        "level" => "warning",
        "icon" => "bi-person-exclamation",
        "title" => $unassignedCount . " unassigned request" . ($unassignedCount === 1 ? "" : "s"),
        "text" => "Assign these requests to HPU personnel so work ownership is clear.",
        "url" => BASE_URL . "/assign_jobs.php?assignment=unassigned"
    ];
}

if ($pendingScoped > 0 && !$isUser) {
    $attention[] = [
        "level" => "info",
        "icon" => "bi-hourglass-split",
        "title" => $pendingScoped . " request" . ($pendingScoped === 1 ? "" : "s") . " pending review",
        "text" => "Newly submitted requests are waiting for workflow action.",
        "url" => $isManager
            ? BASE_URL . "/staff/requests.php"
            : BASE_URL . "/staff/requests.php"
    ];
}

if ($revisionScoped > 0) {
    $attention[] = [
        "level" => "warning",
        "icon" => "bi-arrow-repeat",
        "title" => $revisionScoped . " request" . ($revisionScoped === 1 ? "" : "s") . " for revision",
        "text" => $isUser
            ? "Check the revision instructions before resubmitting."
            : "Revision items may need follow-up with the requesting office.",
        "url" => $isUser
            ? BASE_URL . "/user/my_requests.php"
            : ($isStaff && $hasAssignedTo
                ? BASE_URL . "/my_assigned_jobs.php?status=For+Revision"
                : BASE_URL . "/staff/requests.php")
    ];
}


if ($publicationForReview > 0) {
    $attention[] = [
        "level" => "info",
        "icon" => "bi-newspaper",
        "title" =>
            $publicationForReview
            . " publication submission"
            . ($publicationForReview === 1 ? "" : "s")
            . " for review",
        "text" =>
            $isUser
            ? "Your publication submission is waiting for HPU review."
            : "Event, activity or recognition documentation is waiting for review.",
        "url" =>
            $isUser
            ? BASE_URL . "/user/my_requests.php"
            : BASE_URL . "/staff/publication_requests.php?status=For+Review"
    ];
}

if ($publicationForApproval > 0 && !$isUser) {
    $attention[] = [
        "level" => "warning",
        "icon" => "bi-patch-check",
        "title" =>
            $publicationForApproval
            . " publication"
            . ($publicationForApproval === 1 ? "" : "s")
            . " for approval",
        "text" =>
            "Publication items are waiting for final approval before publishing.",
        "url" =>
            BASE_URL . "/staff/publication_requests.php?status=For+Approval"
    ];
}

if ($publicationPreferredPassed > 0) {
    $attention[] = [
        "level" => "danger",
        "icon" => "bi-calendar-x",
        "title" =>
            $publicationPreferredPassed
            . " preferred publication date"
            . ($publicationPreferredPassed === 1 ? " has" : "s have")
            . " passed",
        "text" =>
            "Review publication preparation and scheduling. Publication preferred dates are not treated as job-request overdue deadlines.",
        "url" =>
            $isUser
            ? BASE_URL . "/user/my_requests.php"
            : BASE_URL . "/staff/publication_requests.php"
    ];
}

if ($publicationPreferredSoon > 0) {
    $attention[] = [
        "level" => "primary",
        "icon" => "bi-calendar2-event",
        "title" =>
            $publicationPreferredSoon
            . " publication"
            . ($publicationPreferredSoon === 1 ? "" : "s")
            . " approaching preferred date",
        "text" =>
            "Preferred publication dates within the next three days may need follow-up.",
        "url" =>
            $isUser
            ? BASE_URL . "/user/my_requests.php"
            : BASE_URL . "/staff/publication_requests.php"
    ];
}

if ($dueSoonScoped > 0) {
    $attention[] = [
        "level" => "primary",
        "icon" => "bi-calendar2-event",
        "title" => $dueSoonScoped . " item" . ($dueSoonScoped === 1 ? "" : "s") . " due within 3 days",
        "text" => "Only Approved and In Development jobs are counted as due soon or overdue.",
        "url" => $isStaff && $hasAssignedTo
            ? BASE_URL . "/my_assigned_jobs.php"
            : ($isUser
                ? BASE_URL . "/user/my_requests.php"
                : BASE_URL . "/staff/calendar.php")
    ];
}

if (
    $isUser
    && $hasClientSurvey
    && $pendingSurveyCount > 0
) {
    $firstSurvey = $pendingSurveys[0] ?? null;

    $attention[] = [
        "level" => "primary",
        "icon" => "bi-chat-square-heart",
        "title" =>
            $pendingSurveyCount
            . " Client Experience Survey"
            . ($pendingSurveyCount === 1 ? "" : "s")
            . " pending",

        "text" =>
            "Your feedback helps HPU assess timeliness, responsiveness, quality and overall service experience.",

        "url" =>
            $firstSurvey
            ? BASE_URL
            . "/user/client_experience_survey.php?id="
            . (int) $firstSurvey["id"]
            : BASE_URL
            . "/user/my_requests.php"
    ];
}

if (
    $isUser
    && $hasOfficeActivities
    && $userOfficeNeedsClarification > 0
) {
    $attention[] = [
        "level" => "warning",
        "icon" => "bi-building-exclamation",
        "title" =>
            $userOfficeNeedsClarification
            . " Office Activity "
            . ($userOfficeNeedsClarification === 1 ? "submission needs" : "submissions need")
            . " clarification",

        "text" =>
            "HPU needs additional information before the activity can be scheduled or covered.",

        "url" =>
            BASE_URL . "/my_calendar.php"
    ];
}

$attention = array_slice($attention, 0, 6);

/*
|--------------------------------------------------------------------------
| Latest / Personal Requests
|--------------------------------------------------------------------------
*/

$selectPriority = $hasPriority
    ? "jr.priority"
    : "'Normal' AS priority";

$selectAssigned = $hasAssignedTo
    ? "u.name AS assigned_name"
    : "NULL AS assigned_name";

$joinAssigned = $hasAssignedTo
    ? "LEFT JOIN users u ON u.id = jr.assigned_to"
    : "";

$latestRequests = dashRows(
    $conn,
    "SELECT
        jr.id,
        jr.job_no,
        jr.project_title,
        jr.requestor_name,
        jr.department,
        jr.request_types,
        jr.approval_status,
        jr.date_request,
        jr.date_completion,
        {$selectPriority},
        {$selectAssigned}
     FROM job_requests jr
     {$joinAssigned}
     WHERE {$scopeWhere}
     ORDER BY
        jr.date_request DESC,
        jr.id DESC
     LIMIT 6",
    $scopeTypes,
    $scopeParams
);


$recentWork = [];

foreach ($latestRequests as $request) {
    $recentWork[] = [
        "source" => "job",
        "code" => (string) $request["job_no"],
        "title" => (string) $request["project_title"],
        "subtitle" => (string) $request["department"],
        "requestor" => (string) $request["requestor_name"],
        "types" => decodeArray($request["request_types"] ?? null),
        "status" => (string) $request["approval_status"],
        "date" => (string) ($request["date_request"] ?? ""),
        "target_date" => $request["date_completion"],
        "priority" => (string) ($request["priority"] ?? "Normal"),
        "assigned_name" => (string) ($request["assigned_name"] ?? ""),
        "url" =>
            $isUser
            ? BASE_URL . "/admin/view.php?id=" . (int) $request["id"]
            : BASE_URL . "/staff/review_request.php?id=" . (int) $request["id"]
    ];
}

if ($hasEventPublications) {
    $archiveClause = $hasEventPublicationArchive
        ? " AND COALESCE(p.archived,0) = 0"
        : "";

    $eventScope = $isUser
        ? " AND p.submitted_by = ?"
        : "";

    $eventTypes = $isUser ? "i" : "";
    $eventParams = $isUser ? [$userId] : [];

    $eventRecent = dashRows(
        $conn,
        "SELECT
            p.id,
            p.title,
            p.activity_type,
            p.status,
            p.created_at,
            p.preferred_publication_date,
            u.name AS submitted_name
         FROM publication_event_submissions p
         LEFT JOIN users u
            ON u.id = p.submitted_by
         WHERE 1 = 1
           {$archiveClause}
           {$eventScope}
         ORDER BY
            p.created_at DESC,
            p.id DESC
         LIMIT 6",
        $eventTypes,
        $eventParams
    );

    foreach ($eventRecent as $publication) {
        $recentWork[] = [
            "source" => "event",
            "code" =>
                publicationDisplayCode("event", (int) $publication["id"]),
            "title" =>
                (string) (
                    $publication["title"]
                    ?: "Untitled Event / Activity"
                ),
            "subtitle" =>
                (string) (
                    $publication["activity_type"]
                    ?: "Event / Activity Publication"
                ),
            "requestor" =>
                (string) (
                    $publication["submitted_name"]
                    ?: ""
                ),
            "types" => [],
            "status" =>
                (string) (
                    $publication["status"]
                    ?: "For Review"
                ),
            "date" => (string) $publication["created_at"],
            "target_date" => $publication["preferred_publication_date"],
            "priority" => "",
            "assigned_name" => "",
            "url" =>
                $isUser
                ? BASE_URL . "/user/view_publication.php?kind=event&id=" . (int) $publication["id"]
                : BASE_URL . "/staff/publication_requests.php?kind=event&id=" . (int) $publication["id"]
        ];
    }
}

if ($hasRecognitionPublications) {
    $archiveClause = $hasRecognitionPublicationArchive
        ? " AND COALESCE(p.archived,0) = 0"
        : "";

    $recognitionScope = $isUser
        ? " AND p.submitted_by = ?"
        : "";

    $recognitionTypes = $isUser ? "i" : "";
    $recognitionParams = $isUser ? [$userId] : [];

    $recognitionRecent = dashRows(
        $conn,
        "SELECT
            p.id,
            p.recognized_entity,
            p.recognition_type,
            p.status,
            p.created_at,
            p.preferred_publication_date,
            u.name AS submitted_name
         FROM publication_recognition_submissions p
         LEFT JOIN users u
            ON u.id = p.submitted_by
         WHERE 1 = 1
           {$archiveClause}
           {$recognitionScope}
         ORDER BY
            p.created_at DESC,
            p.id DESC
         LIMIT 6",
        $recognitionTypes,
        $recognitionParams
    );

    foreach ($recognitionRecent as $publication) {
        $recentWork[] = [
            "source" => "recognition",
            "code" =>
                publicationDisplayCode("recognition", (int) $publication["id"]),
            "title" =>
                (string) (
                    $publication["recognized_entity"]
                    ?: "Recognition / Milestone"
                ),
            "subtitle" =>
                dashPublicationTypeLabel(
                    $publication["recognition_type"] ?? null,
                    "Recognition / Milestone Publication"
                ),
            "requestor" =>
                (string) (
                    $publication["submitted_name"]
                    ?: ""
                ),
            "types" => [],
            "status" =>
                (string) (
                    $publication["status"]
                    ?: "For Review"
                ),
            "date" => (string) $publication["created_at"],
            "target_date" => $publication["preferred_publication_date"],
            "priority" => "",
            "assigned_name" => "",
            "url" =>
                $isUser
                ? BASE_URL . "/user/view_publication.php?kind=recognition&id=" . (int) $publication["id"]
                : BASE_URL . "/staff/publication_requests.php?kind=recognition&id=" . (int) $publication["id"]
        ];
    }
}

usort(
    $recentWork,
    static function (array $a, array $b): int {
        return strcmp(
            (string) $b["date"],
            (string) $a["date"]
        );
    }
);

$recentWork = array_slice(
    $recentWork,
    0,
    8
);

/*
|--------------------------------------------------------------------------
| Upcoming Deadlines
|--------------------------------------------------------------------------
*/

$upcomingDeadlines = dashRows(
    $conn,
    "SELECT
        jr.id,
        jr.job_no,
        jr.project_title,
        jr.date_completion,
        jr.approval_status,
        {$selectPriority},
        {$selectAssigned}
     FROM job_requests jr
     {$joinAssigned}
     WHERE {$scopeWhere}
       AND jr.date_completion IS NOT NULL
       AND jr.date_completion >= CURDATE()
       AND jr.approval_status IN (
            'Approved',
            'In Development'
       )
     ORDER BY
        jr.date_completion ASC,
        jr.id DESC
     LIMIT 6",
    $scopeTypes,
    $scopeParams
);

/*
|--------------------------------------------------------------------------
| Request Pipeline
|--------------------------------------------------------------------------
*/

$pipelineStatuses = [
    "Pending Review",
    "Under Review",
    "Approved",
    "In Development",
    "For Revision",
    "Completed",
    "For Evaluation",
    "Closed"
];

$pipelineCounts = [];

foreach ($pipelineStatuses as $pipelineStatus) {
    $types = $scopeTypes . "s";
    $params = array_merge(
        $scopeParams,
        [$pipelineStatus]
    );

    $pipelineCounts[] = (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM job_requests jr
         WHERE {$scopeWhere}
           AND jr.approval_status = ?",
        $types,
        $params
    );
}


$publicationPipelineStatuses = [
    "For Review",
    "In Preparation",
    "For Approval",
    "Published",
    "Closed"
];

$publicationPipelineCounts = [
    $publicationForReview,
    $publicationInPreparation,
    $publicationForApproval,
    0,
    0
];

foreach ($publicationTables as $publicationTable) {
    $table = $publicationTable["table"];

    $archiveClause = $publicationTable["has_archived"]
        ? " AND COALESCE(archived,0) = 0"
        : "";

    $publicationPipelineCounts[3] += (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM `{$table}`
         WHERE {$publicationScopeWhere}
           {$archiveClause}
           AND status = 'Published'",
        $publicationScopeTypes,
        $publicationScopeParams
    );

    $publicationPipelineCounts[4] += (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM `{$table}`
         WHERE {$publicationScopeWhere}
           {$archiveClause}
           AND status = 'Closed'",
        $publicationScopeTypes,
        $publicationScopeParams
    );
}

/*
|--------------------------------------------------------------------------
| Request Type Distribution
|--------------------------------------------------------------------------
*/

$requestTypeNames = [
    "Graphic Design",
    "Video Production",
    "Photo/Video Coverage",
    "IEC Materials",
    "Publication Submission"
];

$requestTypeCounts = [];

foreach ($requestTypeNames as $requestTypeName) {

    /*
    |--------------------------------------------------------------------------
    | Publication Submission Count
    |--------------------------------------------------------------------------
    | Publication submissions are stored in separate tables, not inside
    | job_requests.request_types. Count both Event / Activity and
    | Recognition / Milestone submissions using the already-computed,
    | archive-aware and role-aware publication total.
    |--------------------------------------------------------------------------
    */
    if ($requestTypeName === "Publication Submission") {
        $requestTypeCounts[] = $publicationTotalScoped;
        continue;
    }

    $types = $scopeTypes . "s";
    $params = array_merge(
        $scopeParams,
        ['%"' . $requestTypeName . '"%']
    );

    $requestTypeCounts[] = (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM job_requests jr
         WHERE {$scopeWhere}
           AND jr.request_types LIKE ?",
        $types,
        $params
    );
}

/*
|--------------------------------------------------------------------------
| Request Kind Distribution
|--------------------------------------------------------------------------
| Uses the kind-code mapper from helpers.php (jobRequestTypeCode).
| Kind codes: Graphic Design→GD, Video Production→VP,
|           Photo/Video Coverage→PVC, IEC Materials→IEC.
| Daily per-kind sequence restarts at 1.
|--------------------------------------------------------------------------
*/

$kindCodeNames = [
    "Graphic Design" => "GD",
    "Video Production" => "VP",
    "Photo/Video Coverage" => "PVC",
    "IEC Materials" => "IEC"
];

$kindCodeCounts = [];

foreach ($kindCodeNames as $kindName => $kindCode) {
    $types = $scopeTypes . "s";
    $params = array_merge(
        $scopeParams,
        ['%"' . $kindName . '"%']
    );

    $kindCodeCounts[$kindCode] = (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM job_requests jr
         WHERE {$scopeWhere}
           AND jr.request_types LIKE ?",
        $types,
        $params
    );
}

/*
|--------------------------------------------------------------------------
| Last 6 Months Trend
|--------------------------------------------------------------------------
*/

$monthlyLabels = [];
$monthlyValues = [];

for ($offset = 5; $offset >= 0; $offset--) {
    $monthDate = new DateTime(
        "first day of -{$offset} month"
    );

    $monthKey = $monthDate->format("Y-m");

    $monthlyLabels[] = $monthDate->format("M");

    $types = $scopeTypes . "s";
    $params = array_merge(
        $scopeParams,
        [$monthKey]
    );

    $monthlyValues[] = (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM job_requests jr
         WHERE {$scopeWhere}
           AND DATE_FORMAT(jr.created_at,'%Y-%m') = ?",
        $types,
        $params
    );
}

/*
|--------------------------------------------------------------------------
| HPU Staff Workload - Managers
|--------------------------------------------------------------------------
*/

$staffWorkload = [];

if ($isManager && $hasAssignedTo) {
    $archivedClause = $hasArchived
        ? "AND COALESCE(jr.archived,0) = 0"
        : "";

    $staffWorkload = dashRows(
        $conn,
        "SELECT
            u.id,
            u.name,
            u.role,
            COUNT(jr.id) AS total_assigned,
            SUM(
                jr.approval_status NOT IN (
                    'Completed',
                    'For Evaluation',
                    'Closed',
                    'Disapproved'
                )
            ) AS active_total,
            SUM(
                jr.date_completion IS NOT NULL
                AND jr.date_completion < CURDATE()
                AND jr.approval_status IN (
                    'Approved',
                    'In Development'
                )
            ) AS overdue_total,
            SUM(
                jr.date_completion BETWEEN CURDATE()
                    AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
                AND jr.approval_status IN (
                    'Approved',
                    'In Development'
                )
            ) AS due_soon_total
         FROM users u
         LEFT JOIN job_requests jr
            ON jr.assigned_to = u.id
            {$archivedClause}
         WHERE u.role IN ('staff','supervisor','admin')
           AND u.status = 'Active'
         GROUP BY
            u.id,
            u.name,
            u.role
         ORDER BY
            active_total DESC,
            overdue_total DESC,
            u.name ASC"
    );
}

/*
|--------------------------------------------------------------------------
| Shared Calendar
|--------------------------------------------------------------------------
*/

$calendarTodayCount = 0;
$calendarUpcoming = [];

if ($hasCalendar && $isHpuRole) {
    $calendarTodayCount = (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM hpu_calendar_activities
         WHERE
            DATE(start_datetime) <= CURDATE()
            AND DATE(
                COALESCE(
                    end_datetime,
                    start_datetime
                )
            ) >= CURDATE()"
    );

    $scopeSelect = $hasCalendarScope
        ? "calendar_scope"
        : "'HPU' AS calendar_scope";

    $calendarUpcoming = dashRows(
        $conn,
        "SELECT
            id,
            title,
            activity_type,
            {$scopeSelect},
            location,
            start_datetime,
            end_datetime,
            all_day,
            color
         FROM hpu_calendar_activities
         WHERE
            COALESCE(
                end_datetime,
                start_datetime
            ) >= NOW()
         ORDER BY
            start_datetime ASC,
            id ASC
         LIMIT 6"
    );
}

/*
|--------------------------------------------------------------------------
| IEC Library Summary
|--------------------------------------------------------------------------
*/

$iecAvailable = 0;
$iecDownloads = 0;
$iecNewThisMonth = 0;

if ($hasIecLibrary) {
    $iecAvailable = (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM iec_materials
         WHERE active = 1"
    );

    $iecDownloads = (int) dashScalar(
        $conn,
        "SELECT COALESCE(SUM(download_count),0)
         FROM iec_materials
         WHERE active = 1"
    );

    $iecNewThisMonth = (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM iec_materials
         WHERE active = 1
           AND YEAR(uploaded_at) = YEAR(CURDATE())
           AND MONTH(uploaded_at) = MONTH(CURDATE())"
    );
}

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
*/

$unreadNotifications = 0;
$recentNotifications = [];

if ($hasNotifications) {
    $unreadNotifications = (int) dashScalar(
        $conn,
        "SELECT COUNT(*)
         FROM notifications
         WHERE user_id = ?
           AND is_read = 0",
        "i",
        [$userId]
    );

    $recentNotifications = dashRows(
        $conn,
        "SELECT
            id,
            title,
            message,
            notification_type,
            action_url,
            is_read,
            created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY
            created_at DESC,
            id DESC
         LIMIT 5",
        "i",
        [$userId]
    );
}

/*
|--------------------------------------------------------------------------
| Header Labels
|--------------------------------------------------------------------------
*/

$dashboardTitle = match ($role) {
    "staff" => "My HPU Work Dashboard",
    "supervisor" => "HPU Operations Dashboard",
    "admin" => "HPU Administration Dashboard",
    default => "My Request Dashboard"
};

$dashboardDescription = match ($role) {
    "staff" =>
        $hasAssignedTo
        ? "Your assigned workload, deadlines, shared calendar and HPU resources."
        : "HPU workflow requests, deadlines and shared resources.",
    "supervisor" =>
        "Monitor jobs, publication submissions, assignments, deadlines, HPU activities and service requests.",
    "admin" =>
        "Operational overview of job requests, publication submissions, personnel workload, resources and system activity.",
    default =>
        "Track your requests, HPU updates and actions that need your attention."
};

$todayText = date("l, F j, Y");

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Dashboard | HPU Workbench
    </title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">

    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

    <style>
        :root {
            --db-primary: #3b82f6;
            --db-primary-soft: #eff6ff;
            --db-primary-ring: rgba(59, 130, 246, .18);
            --db-primary-on: #0f172a;
            --db-success: #10b981;
            --db-success-soft: #ecfdf5;
            --db-success-ring: rgba(16, 185, 129, .18);
            --db-warning: #f59e0b;
            --db-warning-soft: #fffbeb;
            --db-warning-ring: rgba(245, 158, 11, .18);
            --db-danger: #ef4444;
            --db-danger-soft: #fef2f2;
            --db-danger-ring: rgba(239, 68, 68, .18);
            --db-purple: #8b5cf6;
            --db-purple-soft: #f5f3ff;
            --db-orange: #f97316;
            --db-orange-soft: #fff7ed;
            --db-cyan: #06b6d4;
            --db-cyan-soft: #ecfeff;
            --db-text: #0f172a;
            --db-muted: #64748b;
            --db-border: rgba(226, 232, 240, 0.85);
            --db-bg: #f8fafc;
            --db-card: #ffffff;
            --db-shadow: 0 10px 30px -5px rgba(15, 23, 42, 0.05), 0 4px 10px -2px rgba(15, 23, 42, 0.02);
            --db-shadow-hover: 0 20px 35px -5px rgba(15, 23, 42, 0.1);
            --db-radius: 18px;
            --db-radius-sm: 12px;
            --db-transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }


        body {
            background: var(--db-bg);
            color: var(--db-text);
        }

        .content {
            padding: 20px;
        }

        .container-fluid {
            max-width: 1650px;
            margin: 0 auto;
        }

        /* Header */
        .dashboard-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .dashboard-eyebrow {
            color: var(--db-primary);
            font-size: .72rem;
            font-weight: 850;
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-bottom: 3px;
        }

        .dashboard-title {
            margin: 0;
            font-size: 1.65rem;
            font-weight: 850;
            letter-spacing: -.035em;
        }

        .dashboard-desc {
            margin: 5px 0 0;
            color: var(--db-muted);
            font-size: .84rem;
        }

        .date-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 11px;
            border-radius: 10px;
            border: 1px solid var(--db-border);
            background: #fff;
            color: #475467;
            font-size: .75rem;
            font-weight: 750;
        }

        /* KPI COMPACT METRIC PILLS */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }

        .kpi-card {
            position: relative;
            overflow: hidden;
            min-height: 84px;
            padding: 14px 16px;
            border: 1px solid var(--db-border);
            border-radius: var(--db-radius);
            background: var(--db-card);
            box-shadow: var(--db-shadow);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            transition: transform .22s ease, box-shadow .25s ease, border-color .25s ease;
            animation: kpiPop .45s ease both;
        }

        .kpi-card:nth-child(1) { animation-delay: .05s; }
        .kpi-card:nth-child(2) { animation-delay: .10s; }
        .kpi-card:nth-child(3) { animation-delay: .15s; }
        .kpi-card:nth-child(4) { animation-delay: .20s; }
        .kpi-card:nth-child(5) { animation-delay: .25s; }

        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--db-shadow-hover);
            border-color: rgba(59, 130, 246, 0.4);
        }

        /* Accent left stripe */
        .kpi-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            border-radius: var(--db-radius) 0 0 var(--db-radius);
            background: var(--db-primary);
            opacity: .9;
            transition: width .2s ease;
        }

        .kpi-card:hover::before {
            width: 6px;
        }

        .kpi-card.kpi-warning::before { background: var(--db-warning); }
        .kpi-card.kpi-danger::before  { background: var(--db-danger); }
        .kpi-card.kpi-success::before { background: var(--db-success); }
        .kpi-card.kpi-purple::before  { background: var(--db-purple); }

        .kpi-body {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            flex: 1;
        }

        .kpi-icon {
            width: 44px;
            height: 44px;
            min-width: 44px;
            display: grid;
            place-items: center;
            border-radius: var(--db-radius-sm);
            font-size: 1.15rem;
            flex-shrink: 0;
            transition: transform .2s ease;
        }

        .kpi-card:hover .kpi-icon {
            transform: scale(1.08);
        }

        .kpi-icon.primary {
            background: linear-gradient(135deg, #e8f2ff, #dbeafe);
            color: var(--db-primary);
            box-shadow: 0 0 0 1px rgba(23, 105, 224, .15), 0 4px 10px rgba(23, 105, 224, .12);
        }

        .kpi-icon.warning {
            background: linear-gradient(135deg, #fffdf0, #fffaeb);
            color: var(--db-warning);
            box-shadow: 0 0 0 1px rgba(181, 71, 8, .15), 0 4px 10px rgba(181, 71, 8, .10);
        }

        .kpi-icon.danger {
            background: linear-gradient(135deg, #fff5f5, #fef3f2);
            color: var(--db-danger);
            box-shadow: 0 0 0 1px rgba(180, 35, 24, .15), 0 4px 10px rgba(180, 35, 24, .10);
        }

        .kpi-icon.success {
            background: linear-gradient(135deg, #f0fdf6, #ecfdf3);
            color: var(--db-success);
            box-shadow: 0 0 0 1px rgba(2, 122, 72, .15), 0 4px 10px rgba(2, 122, 72, .10);
        }

        .kpi-icon.purple {
            background: linear-gradient(135deg, #f5f3ff, #ede9fe);
            color: var(--db-purple);
            box-shadow: 0 0 0 1px rgba(139, 92, 246, .15), 0 4px 10px rgba(139, 92, 246, .10);
        }

        .kpi-details {
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-width: 0;
        }

        .kpi-label {
            font-size: .81rem;
            font-weight: 750;
            color: var(--db-text);
            line-height: 1.2;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .kpi-note {
            font-size: .67rem;
            color: var(--db-muted);
            margin-top: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .kpi-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: center;
            gap: 4px;
            flex-shrink: 0;
            margin-left: 6px;
        }

        .kpi-value {
            font-size: 1.65rem;
            line-height: 1;
            font-weight: 900;
            letter-spacing: -.03em;
            color: var(--db-text);
            margin: 0;
        }

        .kpi-pill-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 7px;
            border-radius: 999px;
            font-size: .6rem;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .04em;
            transition: all .2s ease;
        }

        .kpi-pill-badge i {
            font-size: .65rem;
            transition: transform .15s ease;
        }

        .kpi-card:hover .kpi-pill-badge i {
            transform: translateX(2px);
        }

        .kpi-pill-badge.primary { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .kpi-pill-badge.warning { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .kpi-pill-badge.danger  { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .kpi-pill-badge.success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .kpi-pill-badge.purple  { background: #f5f3ff; color: #6d28d9; border: 1px solid #ddd6fe; }

        .kpi-link {
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .kpi-link:hover .kpi-card {
            transform: translateY(-3px);
            box-shadow: var(--db-shadow-hover);
        }

        /* General cards */
        .db-card {
            background: #fff;
            border: 1px solid var(--db-border);
            border-radius: 16px;
            box-shadow: var(--db-shadow);
            overflow: hidden;
            transition: box-shadow .2s ease;
        }

        .db-card:hover {
            box-shadow: var(--db-shadow-hover);
        }

        .db-card-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-bottom: 1px solid #edf1f6;
            background: #ffffff;
        }

        .db-card-title {
            margin: 0;
            color: #0f172a;
            font-size: 0.9rem;
            font-weight: 800;
            letter-spacing: -.018em;
            line-height: 1.25;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .db-card-title i {
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: var(--db-primary-soft);
            color: var(--db-primary);
            font-size: .85em;
        }

        .db-card-subtitle {
            margin-top: 2px;
            margin-bottom: 0;
            color: var(--db-muted);
            font-size: 0.72rem;
            font-weight: 500;
            line-height: 1.4;
        }

        .db-card-body {
            padding: 14px 18px;
        }

        .section-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(320px, .85fr);
            gap: 14px;
            margin-bottom: 14px;
        }

        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 14px;
        }

        .bottom-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(300px, .75fr);
            gap: 14px;
            margin-bottom: 14px;
        }

        /* ======================================================
   DASHBOARD LAYOUT — clean bento flow
   Reorders the natural (DOM) stack into a logical flow:
   header → KPI → attention/calendar → charts → trend
   → latest+rail → role sections.
   ====================================================== */
        .container-fluid {
            display: flex;
            flex-direction: column;
        }

        .container-fluid>.ph {
            order: 0;
        }

        .container-fluid>.kpi-grid {
            order: 1;
        }

        .container-fluid>.section-grid {
            order: 2;
        }

        .container-fluid>.analytics-grid {
            order: 3;
        }

        .container-fluid>.bottom-grid {
            order: 5;
        }

        .container-fluid>section.db-card.mb-3 {
            order: 6;
        }

        .container-fluid>section.db-chart-trend {
            order: 4;
        }

        /* Attention */
        .attention-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .attention-item {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 11px 13px;
            border-radius: 12px;
            border: 1px solid #eaecf0;
            background: #fff;
            text-decoration: none;
            color: inherit;
            transition: transform .15s ease, box-shadow .2s ease, border-color .2s ease;
        }

        .attention-item:hover {
            border-color: #bfd6ff;
            background: #f8fbff;
            transform: translateX(3px);
            box-shadow: 0 4px 14px rgba(23, 105, 224, .08);
        }

        .attention-item.danger:hover {
            border-color: #fca5a5;
            background: #fff8f8;
        }

        .attention-item.warning:hover {
            border-color: #fcd34d;
            background: #fffdf5;
        }

        .attention-icon {
            flex: 0 0 auto;
            width: 34px;
            height: 34px;
            display: grid;
            place-items: center;
            border-radius: 10px;
        }

        .attention-item.danger .attention-icon {
            background: var(--db-danger-soft);
            color: var(--db-danger);
        }

        .attention-item.warning .attention-icon {
            background: var(--db-warning-soft);
            color: var(--db-warning);
        }

        .attention-item.info .attention-icon {
            background: var(--db-cyan-soft);
            color: var(--db-cyan);
        }

        .attention-item.primary .attention-icon {
            background: var(--db-primary-soft);
            color: var(--db-primary);
        }

        .attention-title {
            font-size: .8rem;
            font-weight: 850;
        }

        .attention-text {
            margin-top: 2px;
            color: var(--db-muted);
            font-size: .7rem;
            line-height: 1.4;
        }

        .all-clear {
            text-align: center;
            padding: 24px 15px;
            color: var(--db-muted);
        }

        .all-clear-icon {
            width: 48px;
            height: 48px;
            display: grid;
            place-items: center;
            margin: 0 auto 8px;
            border-radius: 15px;
            background: var(--db-success-soft);
            color: var(--db-success);
            font-size: 1.2rem;
        }

        /* Calendar */
        .calendar-item {
            display: flex;
            gap: 10px;
            padding: 9px 0;
            border-bottom: 1px solid #f0f2f5;
        }

        .calendar-item:last-child {
            border-bottom: 0;
        }

        a.calendar-item-link {
            margin: 0 -8px;
            padding: 9px 8px;
            border-radius: 10px;
            border-bottom: 1px solid #f0f2f5;
            transition: background .15s ease;
        }

        a.calendar-item-link:hover {
            background: #f8fafc;
        }

        a.calendar-item-link:hover .calendar-title {
            color: var(--db-primary);
        }

        a.workload-row-link {
            border-radius: 10px;
            transition: background .15s ease;
        }

        a.workload-row-link:hover {
            background: #f8fafc;
        }

        a.workload-row-link:hover .staff-name-text strong {
            color: var(--db-primary);
        }

        .calendar-date {
            flex: 0 0 46px;
            text-align: center;
            border-radius: 10px;
            background: #f8fafc;
            padding: 6px 4px;
        }

        .calendar-date .month {
            color: var(--db-primary);
            font-size: .61rem;
            font-weight: 900;
            text-transform: uppercase;
        }

        .calendar-date .day {
            color: #1d2939;
            font-size: 1rem;
            line-height: 1.05;
            font-weight: 900;
        }

        .calendar-info {
            min-width: 0;
            flex: 1;
        }

        .calendar-title {
            font-size: .79rem;
            font-weight: 850;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .calendar-meta {
            margin-top: 2px;
            color: var(--db-muted);
            font-size: .68rem;
        }

        .scope-pill {
            display: inline-flex;
            align-items: center;
            margin-left: 4px;
            padding: 2px 5px;
            border-radius: 999px;
            font-size: .58rem;
            font-weight: 850;
        }

        .scope-hpu {
            background: #eff8ff;
            color: #175cd3;
        }

        .scope-hospital {
            background: #ecfdf3;
            color: #027a48;
        }

        .scope-office {
            background: #fff7ed;
            color: #c2410c;
        }

        .user-office-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .user-office-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 10px 11px;
            border: 1px solid #eaecf0;
            border-radius: 11px;
            background: #fff;
        }

        .user-office-main {
            min-width: 0;
        }

        .user-office-no {
            color: #ea580c;
            font-size: .59rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .user-office-title {
            margin-top: 2px;
            color: #1d2939;
            font-size: .76rem;
            font-weight: 850;
        }

        .user-office-meta {
            margin-top: 3px;
            color: #667085;
            font-size: .63rem;
        }

        .user-office-status {
            flex: 0 0 auto;
            padding: 4px 7px;
            border-radius: 999px;
            font-size: .59rem;
            font-weight: 850;
        }

        .office-submitted,
        .office-review {
            background: #fffaeb;
            color: #b54708;
        }

        .office-scheduled {
            background: #fff7ed;
            color: #c2410c;
        }

        .office-clarification {
            background: #fef3f2;
            color: #b42318;
        }

        .office-completed {
            background: #ecfdf3;
            color: #027a48;
        }

        .office-cancelled {
            background: #f2f4f7;
            color: #475467;
        }

        /* Charts */
        .chart-wrap {
            position: relative;
            height: 230px;
        }

        .chart-wrap.small {
            height: 230px;
        }

        /* Request type */
        .type-list {
            display: flex;
            flex-direction: column;
            gap: 9px;
            margin-top: 10px;
        }

        .type-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
            align-items: center;
        }

        .type-label {
            display: flex;
            gap: 7px;
            align-items: center;
            font-size: .72rem;
            font-weight: 780;
        }

        .type-dot {
            width: 9px;
            height: 9px;
            border-radius: 999px;
        }

        .type-dot.graphic {
            background: #2563eb;
        }

        .type-dot.video {
            background: #7c3aed;
        }

        .type-dot.coverage {
            background: #ea580c;
        }

        .type-dot.iec {
            background: #15803d;
        }

        .type-dot.publication {
            background: #7c3aed;
        }

        .type-count {
            color: #475467;
            font-size: .71rem;
            font-weight: 850;
        }

        /* Workload */
        .workload-list {
            display: flex;
            flex-direction: column;
        }

        .workload-row {
            display: grid;
            grid-template-columns: minmax(170px, 1fr) auto minmax(120px, .65fr);
            gap: 12px;
            align-items: center;
            padding: 10px 15px;
            border-top: 1px solid #f0f2f5;
        }

        .workload-row:first-child {
            border-top: 0;
        }

        .staff-name {
            display: flex;
            align-items: center;
            gap: 9px;
            min-width: 0;
        }

        .staff-avatar {
            width: 33px;
            height: 33px;
            flex: 0 0 33px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            background: var(--db-primary-soft);
            color: var(--db-primary);
            font-size: .7rem;
            font-weight: 900;
        }

        .staff-name-text {
            min-width: 0;
        }

        .staff-name-text strong {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: .77rem;
        }

        .staff-name-text small {
            color: var(--db-muted);
            font-size: .64rem;
        }

        .workload-numbers {
            text-align: right;
            white-space: nowrap;
            font-size: .66rem;
            color: var(--db-muted);
        }

        .workload-numbers strong {
            color: #344054;
        }

        .workload-bar {
            height: 7px;
            overflow: hidden;
            border-radius: 999px;
            background: #edf1f6;
        }

        .workload-fill {
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #1769e0, #7c3aed);
        }

        /* Requests */
        .request-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            padding: 11px 15px;
            border-top: 1px solid #f0f2f5;
        }

        .request-row:first-child {
            border-top: 0;
        }

        .request-no {
            color: var(--db-primary);
            font-size: .64rem;
            font-weight: 900;
        }

        .work-source {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-right: 6px;
            padding: 3px 6px;
            border-radius: 999px;
            font-size: .56rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        .work-source-job {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .work-source-event {
            background: #fdf2f8;
            color: #be185d;
        }

        .work-source-recognition {
            background: #f5f3ff;
            color: #7e22ce;
        }

        .request-row-link {
            color: inherit;
            text-decoration: none;
        }

        .request-row-link:hover .request-title {
            color: var(--db-primary);
        }

        .request-title {
            margin-top: 2px;
            font-size: .79rem;
            line-height: 1.3;
            font-weight: 850;
        }

        .request-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 5px 10px;
            margin-top: 4px;
            color: var(--db-muted);
            font-size: .65rem;
        }

        .type-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 6px;
        }

        .type-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 6px;
            border-radius: 999px;
            font-size: .59rem;
            font-weight: 850;
        }

        .type-graphic {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .type-video {
            background: #f5f3ff;
            color: #6d28d9;
        }

        .type-coverage {
            background: #fff7ed;
            color: #c2410c;
        }

        .type-iec {
            background: #f0fdf4;
            color: #15803d;
        }

        .type-publication {
            background: #f5f3ff;
            color: #7c3aed;
        }

        .type-other {
            background: #f2f4f7;
            color: #475467;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            padding: 4px 7px;
            border-radius: 999px;
            white-space: nowrap;
            font-size: .6rem;
            font-weight: 850;
        }

        .status-pending,
        .status-review {
            background: #fffaeb;
            color: #b54708;
        }

        .status-approved {
            background: #ecfdf3;
            color: #027a48;
        }

        .status-development {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .status-for-approval {
            background: #f5f3ff;
            color: #6d28d9;
        }

        .status-published {
            background: #ecfdf3;
            color: #027a48;
        }

        .status-revision {
            background: #fff4ed;
            color: #c4320a;
        }

        .status-disapproved {
            background: #fef3f2;
            color: #b42318;
        }

        .status-completed,
        .status-evaluation,
        .status-closed {
            background: #ecfdf3;
            color: #027a48;
        }

        .status-default {
            background: #f2f4f7;
            color: #475467;
        }

        .priority-pill {
            font-size: .61rem;
            font-weight: 850;
        }

        .priority-urgent {
            color: #b42318;
        }

        .priority-high {
            color: #b54708;
        }

        .priority-normal {
            color: #175cd3;
        }

        .priority-low {
            color: #027a48;
        }

        /* IEC */
        .iec-box {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }

        .iec-stat {
            text-align: center;
            padding: 10px 6px;
            border-radius: 11px;
            background: #f8fafc;
        }

        .iec-stat strong {
            display: block;
            font-size: 1.18rem;
            font-weight: 900;
        }

        .iec-stat span {
            color: var(--db-muted);
            font-size: .62rem;
        }

        /* Client Experience Survey */
        .survey-card {
            background: linear-gradient(135deg,
                    #ffffff 0%,
                    #fbfaff 100%);
        }

        .survey-head-icon {
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            border-radius: 11px;
            background: #f5f3ff;
            color: #7c3aed;
            font-size: 1rem;
        }

        .survey-metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
        }

        .survey-metric {
            padding: 11px 10px;
            border: 1px solid #e7e2f6;
            border-radius: 11px;
            background: #fff;
        }

        .survey-metric-label {
            color: #667085;
            font-size: .60rem;
            font-weight: 850;
            text-transform: uppercase;
            letter-spacing: .045em;
        }

        .survey-metric-value {
            margin-top: 4px;
            color: #1d2939;
            font-size: 1.28rem;
            line-height: 1;
            font-weight: 900;
        }

        .survey-metric-value small {
            color: #98a2b3;
            font-size: .62rem;
            font-weight: 750;
        }

        .survey-progress {
            height: 6px;
            overflow: hidden;
            margin-top: 7px;
            border-radius: 999px;
            background: #f0ecfa;
        }

        .survey-progress-fill {
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg,
                    #7c3aed,
                    #2563eb);
        }

        .pending-survey-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .pending-survey-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 11px;
            border: 1px solid #e7e2f6;
            border-radius: 11px;
            background: #fff;
        }

        .pending-survey-job {
            color: #7c3aed;
            font-size: .62rem;
            font-weight: 900;
        }

        .pending-survey-title {
            margin-top: 2px;
            color: #1d2939;
            font-size: .75rem;
            font-weight: 830;
        }

        .pending-survey-meta {
            margin-top: 2px;
            color: #667085;
            font-size: .63rem;
        }

        .survey-empty {
            padding: 18px 12px;
            text-align: center;
            color: #667085;
            font-size: .72rem;
        }

        .survey-empty i {
            display: block;
            margin-bottom: 6px;
            color: #7c3aed;
            font-size: 1.3rem;
        }

        /* Quick actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .quick-action {
            display: flex;
            gap: 8px;
            align-items: center;
            min-height: 52px;
            padding: 10px 12px;
            border: 1.5px solid #eaecf0;
            border-radius: 12px;
            background: linear-gradient(135deg, #fff 70%, #f8f9ff 100%);
            color: #344054;
            text-decoration: none;
            font-size: .7rem;
            font-weight: 800;
            transition: border-color .18s ease, background .18s ease, transform .15s ease, box-shadow .2s ease;
        }

        .quick-action:hover {
            border-color: var(--db-primary);
            background: linear-gradient(135deg, #f0f6ff 0%, #edf5ff 100%);
            color: var(--db-primary);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(23, 105, 224, .12);
        }

        .quick-action i {
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: .95rem;
            background: var(--db-primary-soft);
            color: var(--db-primary);
            transition: background .18s, transform .15s;
        }

        .quick-action:hover i {
            background: var(--db-primary);
            color: #fff;
            transform: scale(1.1);
        }

        /* Notifications */
        .notification-item {
            display: flex;
            gap: 9px;
            padding: 9px 0;
            border-bottom: 1px solid #f0f2f5;
        }

        .notification-item:last-child {
            border-bottom: 0;
        }

        .notification-dot {
            flex: 0 0 8px;
            width: 8px;
            height: 8px;
            margin-top: 5px;
            border-radius: 999px;
            background: #98a2b3;
        }

        .notification-item.unread .notification-dot {
            background: var(--db-primary);
        }

        .notification-title {
            font-size: .73rem;
            font-weight: 800;
        }

        .notification-text {
            margin-top: 2px;
            color: var(--db-muted);
            font-size: .65rem;
            line-height: 1.35;
        }

        .notification-time {
            margin-top: 3px;
            color: #98a2b3;
            font-size: .6rem;
        }

        /* Empty */
        .empty-state {
            padding: 24px 14px;
            text-align: center;
            color: var(--db-muted);
            font-size: .72rem;
        }

        .empty-state i {
            display: block;
            margin-bottom: 6px;
            color: #98a2b3;
            font-size: 1.35rem;
        }

        /* responsive */
        @media(max-width:1200px) {
            .kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .section-grid,
            .analytics-grid,
            .bottom-grid {
                grid-template-columns: 1fr;
            }
        }

        @media(max-width:760px) {
            .content {
                padding: 12px;
            }

            .survey-metrics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .kpi-card {
                min-height: 108px;
                padding: 11px;
            }

            .kpi-value {
                font-size: 1.45rem;
            }

            .workload-row {
                grid-template-columns: minmax(0, 1fr) auto;
            }

            .workload-bar {
                grid-column: 1/-1;
            }

            .request-row {
                grid-template-columns: 1fr;
            }

            .request-row>div:last-child {
                text-align: left !important;
            }
        }

        /* ==================================================
           DASHBOARD UX UPGRADE (additive — no data change)
        ================================================== */
        html {
            scroll-behavior: smooth;
        }

        .kpi-value {
            font-variant-numeric: tabular-nums;
        }

        .db-card-head .collapse-btn {
            flex: 0 0 auto;
            display: inline-grid;
            width: 28px;
            height: 28px;
            place-items: center;
            padding: 0;
            border: 1px solid var(--db-border);
            border-radius: 8px;
            background: #fff;
            color: var(--db-muted);
            font-size: .72rem;
            cursor: pointer;
            transition: .16s ease;
        }

        .db-card-head .collapse-btn:hover {
            border-color: var(--db-primary);
            background: var(--db-primary-soft);
            color: var(--db-primary);
        }

        .db-card-head .collapse-btn i {
            transition: transform .2s ease;
        }

        .db-card.is-collapsed>.db-card-head .collapse-btn i {
            transform: rotate(-90deg);
        }

        .db-card.is-collapsed> :not(.db-card-head) {
            display: none;
        }

        .db-card.is-collapsed>.db-card-head {
            border-bottom: 0;
        }

        .recent-filter-wrap {
            position: relative;
            flex: 1 1 180px;
            max-width: 240px;
            min-width: 150px;
        }

        .recent-filter-wrap i {
            position: absolute;
            top: 50%;
            left: 9px;
            transform: translateY(-50%);
            color: #98a2b3;
            font-size: .7rem;
        }

        .recent-filter {
            width: 100%;
            min-height: 31px;
            padding: 5px 9px 5px 28px;
            border: 1px solid var(--db-border);
            border-radius: 8px;
            font-size: .72rem;
            background: #fff;
            color: var(--db-text);
        }

        .recent-filter:focus {
            outline: none;
            border-color: var(--db-primary);
            box-shadow: 0 0 0 3px var(--db-primary-ring);
        }

        .recent-live {
            font-size: .64rem;
            font-weight: 800;
            color: var(--db-muted);
            white-space: nowrap;
        }

        .survey-progress-fill,
        .workload-fill {
            transition: width .7s ease;
        }

        #dashToTop {
            position: fixed;
            right: 18px;
            bottom: 18px;
            z-index: 1050;
            display: none;
            width: 42px;
            height: 42px;
            place-items: center;
            border: 1px solid var(--db-border);
            border-radius: 12px;
            background: #fff;
            color: var(--db-primary);
            font-size: 1rem;
            box-shadow: var(--db-shadow);
            cursor: pointer;
        }

        #dashToTop.show {
            display: grid;
        }

        #dashToTop:hover {
            border-color: var(--db-primary);
            background: var(--db-primary);
            color: #fff;
        }

        @media (max-width: 700px) {
            .recent-filter-wrap {
                max-width: none;
                flex-basis: 100%;
                order: 3;
            }

            .db-card-head {
                flex-wrap: wrap;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                animation-duration: .001s !important;
                transition-duration: .001s !important;
            }

            html {
                scroll-behavior: auto;
            }
        }
    </style>

</head>

<body class="app-shell">

    <div class="d-flex min-vh-100">

        <?php include __DIR__ . "/includes/sidebar.php"; ?>

        <div class="main flex-grow-1">

            <main class="content">

                <div class="container-fluid">

                    <?php if (!empty($_SESSION["module_denied"])): ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <i class="bi bi-shield-lock me-1"></i><?= e((string) $_SESSION["module_denied"]) ?>
                        <button class="btn-close" type="button" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION["module_denied"]); ?>
                    <?php elseif (isset($_GET["denied"])): ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <i class="bi bi-shield-lock me-1"></i>You do not have access to that module. Contact your administrator.
                        <button class="btn-close" type="button" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <!-- ======================================================
     HEADER
======================================================= -->

                    <div class="ph">

                        <div class="ph-copy">
                            <div class="ph-icon" aria-hidden="true">
                                <i class="bi bi-speedometer2"></i>
                            </div>

                            <div class="min-w-0">
                                <p class="ph-eyebrow"><?= e(dashGreeting()) ?>, <?= e($userName) ?></p>
                                <h1 class="ph-title"><?= e($dashboardTitle) ?></h1>
                                <p class="ph-subtitle"><?= e($dashboardDescription) ?></p>
                            </div>
                        </div>

                        <div class="ph-actions">

                            <?php if ($unreadNotifications > 0): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/user/notifications.php">
                                    <i class="bi bi-bell-fill text-danger me-1"></i>
                                    <?= $unreadNotifications ?> unread
                                </a>
                            <?php endif; ?>

                            <button type="button" class="btn btn-sm btn-outline-primary" id="dashRefreshBtn" title="Reload dashboard data">
                                <i class="bi bi-arrow-clockwise me-1"></i>
                                Refresh
                            </button>

                            <span class="btn btn-sm btn-outline-primary disabled text-dark"
                                style="opacity:1; background:#ffffff;">
                                <i class="bi bi-calendar3 me-1 text-primary"></i>
                                <?= e($todayText) ?>
                            </span>

                        </div>

                    </div>

                    <!-- ======================================================
     KPIs
======================================================= -->

                    <div class="kpi-grid">

                        <?php if ($isUser): ?>

                            <a class="kpi-link" href="<?= e(BASE_URL) ?>/user/my_requests.php">
                                <div class="kpi-card">
                                    <div class="kpi-body">
                                        <div class="kpi-icon primary">
                                            <i class="bi bi-card-checklist"></i>
                                        </div>
                                        <div class="kpi-details">
                                            <div class="kpi-label">My Requests</div>
                                            <div class="kpi-note">Jobs + publication submissions</div>
                                        </div>
                                    </div>
                                    <div class="kpi-meta">
                                        <div class="kpi-value"><?= $combinedTotalScoped ?></div>
                                        <span class="kpi-pill-badge primary">All Submissions <i class="bi bi-chevron-right"></i></span>
                                    </div>
                                </div>
                            </a>

                            <a class="kpi-link" href="<?= e(BASE_URL) ?>/user/my_requests.php">
                                <div class="kpi-card kpi-warning">
                                    <div class="kpi-body">
                                        <div class="kpi-icon warning">
                                            <i class="bi bi-hourglass-split"></i>
                                        </div>
                                        <div class="kpi-details">
                                            <div class="kpi-label">In Progress</div>
                                            <div class="kpi-note">Jobs + publications in workflow</div>
                                        </div>
                                    </div>
                                    <div class="kpi-meta">
                                        <div class="kpi-value"><?= $combinedActiveScoped ?></div>
                                        <span class="kpi-pill-badge warning">Active Workflow <i class="bi bi-chevron-right"></i></span>
                                    </div>
                                </div>
                            </a>

                            <a class="kpi-link" href="<?= e(BASE_URL) ?>/user/my_requests.php">
                                <div class="kpi-card kpi-danger">
                                    <div class="kpi-body">
                                        <div class="kpi-icon danger">
                                            <i class="bi bi-arrow-repeat"></i>
                                        </div>
                                        <div class="kpi-details">
                                            <div class="kpi-label">For Revision</div>
                                            <div class="kpi-note">Requests requiring action</div>
                                        </div>
                                    </div>
                                    <div class="kpi-meta">
                                        <div class="kpi-value"><?= $revisionScoped ?></div>
                                        <span class="kpi-pill-badge danger"><?= $revisionScoped > 0 ? "Action Needed" : "Clean" ?> <i class="bi bi-chevron-right"></i></span>
                                    </div>
                                </div>
                            </a>

                            <a class="kpi-link" href="<?= e(BASE_URL) ?>/user/my_requests.php">
                                <div class="kpi-card kpi-success">
                                    <div class="kpi-body">
                                        <div class="kpi-icon success">
                                            <i class="bi bi-clipboard2-check"></i>
                                        </div>
                                        <div class="kpi-details">
                                            <div class="kpi-label">Evaluation Pending</div>
                                            <div class="kpi-note">Completed jobs awaiting feedback</div>
                                        </div>
                                    </div>
                                    <div class="kpi-meta">
                                        <div class="kpi-value"><?= $pendingSurveyCount ?></div>
                                        <span class="kpi-pill-badge success"><?= $pendingSurveyCount > 0 ? "Feedback Ready" : "Up to Date" ?> <i class="bi bi-chevron-right"></i></span>
                                    </div>
                                </div>
                            </a>

                        <?php elseif ($isStaff): ?>

                            <a class="kpi-link" href="<?= e(BASE_URL) ?>/my_assigned_jobs.php">
                                <div class="kpi-card">
                                    <div class="kpi-body">
                                        <div class="kpi-icon primary">
                                            <i class="bi bi-person-workspace"></i>
                                        </div>
                                        <div class="kpi-details">
                                            <div class="kpi-label"><?= $hasAssignedTo ? "My Active Jobs" : "Active Requests" ?></div>
                                            <div class="kpi-note"><?= $hasAssignedTo ? "Currently assigned to you" : "Active workflow requests" ?></div>
                                        </div>
                                    </div>
                                    <div class="kpi-meta">
                                        <div class="kpi-value"><?= $activeScoped ?></div>
                                        <span class="kpi-pill-badge primary"><?= $hasAssignedTo ? "Assigned" : "Active" ?> <i class="bi bi-chevron-right"></i></span>
                                    </div>
                                </div>
                            </a>

                            <a class="kpi-link" href="<?= e(BASE_URL) ?>/my_assigned_jobs.php">
                                <div class="kpi-card kpi-warning">
                                    <div class="kpi-body">
                                        <div class="kpi-icon warning">
                                            <i class="bi bi-clock-history"></i>
                                        </div>
                                        <div class="kpi-details">
                                            <div class="kpi-label">Due Within 3 Days</div>
                                            <div class="kpi-note">Approved / in-development jobs</div>
                                        </div>
                                    </div>
                                    <div class="kpi-meta">
                                        <div class="kpi-value"><?= $dueSoonScoped ?></div>
                                        <span class="kpi-pill-badge warning"><?= $dueSoonScoped > 0 ? "Upcoming Due" : "On Schedule" ?> <i class="bi bi-chevron-right"></i></span>
                                    </div>
                                </div>
                            </a>

                            <a class="kpi-link" href="<?= e(BASE_URL) ?>/my_assigned_jobs.php?tab=overdue">
                                <div class="kpi-card kpi-danger">
                                    <div class="kpi-body">
                                        <div class="kpi-icon danger">
                                            <i class="bi bi-exclamation-octagon"></i>
                                        </div>
                                        <div class="kpi-details">
                                            <div class="kpi-label">Overdue Approved Jobs</div>
                                            <div class="kpi-note">Approved / in-development jobs</div>
                                        </div>
                                    </div>
                                    <div class="kpi-meta">
                                        <div class="kpi-value"><?= $overdueScoped ?></div>
                                        <span class="kpi-pill-badge danger"><?= $overdueScoped > 0 ? "Urgent Action" : "No Overdue" ?> <i class="bi bi-chevron-right"></i></span>
                                    </div>
                                </div>
                            </a>

                            <a class="kpi-link" href="<?= e(BASE_URL) ?>/my_assigned_jobs.php">
                                <div class="kpi-card kpi-success">
                                    <div class="kpi-body">
                                        <div class="kpi-icon success">
                                            <i class="bi bi-check2-circle"></i>
                                        </div>
                                        <div class="kpi-details">
                                            <div class="kpi-label">Completed</div>
                                            <div class="kpi-note">Your completed assignments</div>
                                        </div>
                                    </div>
                                    <div class="kpi-meta">
                                        <div class="kpi-value"><?= $completedScoped ?></div>
                                        <span class="kpi-pill-badge success">Finished <i class="bi bi-chevron-right"></i></span>
                                    </div>
                                </div>
                            </a>

                            <a class="kpi-link" href="<?= e(BASE_URL) ?>/staff/publication_requests.php">
                                <div class="kpi-card kpi-purple">
                                    <div class="kpi-body">
                                        <div class="kpi-icon purple">
                                            <i class="bi bi-newspaper"></i>
                                        </div>
                                        <div class="kpi-details">
                                            <div class="kpi-label">Publication Submissions</div>
                                            <div class="kpi-note"><?= $publicationTotalScoped ?> total • <?= $publicationForReview ?> review • <?= $publicationForApproval ?> approval</div>
                                        </div>
                                    </div>
                                    <div class="kpi-meta">
                                        <div class="kpi-value"><?= $publicationActiveScoped ?></div>
                                        <span class="kpi-pill-badge purple">Active <i class="bi bi-chevron-right"></i></span>
                                    </div>
                                </div>
                            </a>

                        <?php else: ?>

                            <a class="kpi-link" href="<?= e(BASE_URL) ?>/staff/requests.php">
                                <div class="kpi-card">
                                    <div class="kpi-body">
                                        <div class="kpi-icon primary">
                                            <i class="bi bi-briefcase"></i>
                                        </div>
                                        <div class="kpi-details">
                                            <div class="kpi-label">Active Requests</div>
                                            <div class="kpi-note"><?= $combinedTotalScoped ?> total (jobs + publications)</div>
                                        </div>
                                    </div>
                                    <div class="kpi-meta">
                                        <div class="kpi-value"><?= $combinedActiveScoped ?></div>
                                        <span class="kpi-pill-badge primary">Active <i class="bi bi-chevron-right"></i></span>
                                    </div>
                                </div>
                            </a>

                            <a class="kpi-link" href="<?= e(BASE_URL) ?>/staff/requests.php">
                                <div class="kpi-card kpi-warning">
                                    <div class="kpi-body">
                                        <div class="kpi-icon warning">
                                            <i class="bi bi-hourglass-split"></i>
                                        </div>
                                        <div class="kpi-details">
                                            <div class="kpi-label">Pending Review</div>
                                            <div class="kpi-note">Waiting for workflow action</div>
                                        </div>
                                    </div>
                                    <div class="kpi-meta">
                                        <div class="kpi-value"><?= $pendingScoped ?></div>
                                        <span class="kpi-pill-badge warning"><?= $pendingScoped > 0 ? "Review Needed" : "Reviewed" ?> <i class="bi bi-chevron-right"></i></span>
                                    </div>
                                </div>
                            </a>

                            <a class="kpi-link" href="<?= e(BASE_URL) ?>/assign_jobs.php?assignment=unassigned">
                                <div class="kpi-card kpi-danger">
                                    <div class="kpi-body">
                                        <div class="kpi-icon danger">
                                            <i class="bi bi-person-exclamation"></i>
                                        </div>
                                        <div class="kpi-details">
                                            <div class="kpi-label">Unassigned</div>
                                            <div class="kpi-note">Requests without work owner</div>
                                        </div>
                                    </div>
                                    <div class="kpi-meta">
                                        <div class="kpi-value"><?= $unassignedCount ?></div>
                                        <span class="kpi-pill-badge danger"><?= $unassignedCount > 0 ? "Needs Owner" : "Assigned" ?> <i class="bi bi-chevron-right"></i></span>
                                    </div>
                                </div>
                            </a>

                            <a class="kpi-link" href="<?= e(BASE_URL) ?>/staff/publication_requests.php">
                                <div class="kpi-card kpi-purple">
                                    <div class="kpi-body">
                                        <div class="kpi-icon purple">
                                            <i class="bi bi-newspaper"></i>
                                        </div>
                                        <div class="kpi-details">
                                            <div class="kpi-label">Publication Submissions</div>
                                            <div class="kpi-note"><?= $publicationTotalScoped ?> total • <?= $publicationForApproval ?> approval • <?= $publicationPreferredPassed ?> date passed</div>
                                        </div>
                                    </div>
                                    <div class="kpi-meta">
                                        <div class="kpi-value"><?= $publicationActiveScoped ?></div>
                                        <span class="kpi-pill-badge purple">Active <i class="bi bi-chevron-right"></i></span>
                                    </div>
                                </div>
                            </a>

                        <?php endif; ?>

                    </div>

                    <!-- ======================================================
     NEEDS ATTENTION + CALENDAR
======================================================= -->

                    <div class="section-grid">

                        <section class="db-card">

                            <div class="db-card-head">
                                <div>
                                    <h2 class="db-card-title">
                                        <i class="bi bi-exclamation-diamond me-2 text-warning"></i>
                                        Needs Attention
                                    </h2>
                                    <div class="db-card-subtitle">
                                        Items that may need action today.
                                    </div>
                                </div>
                            </div>

                            <div class="db-card-body">

                                <?php if ($attention): ?>

                                    <div class="attention-list">

                                        <?php foreach ($attention as $item): ?>

                                            <a class="attention-item <?= e($item["level"]) ?>" href="<?= e($item["url"]) ?>">
                                                <div class="attention-icon">
                                                    <i class="bi <?= e($item["icon"]) ?>"></i>
                                                </div>

                                                <div>
                                                    <div class="attention-title">
                                                        <?= e($item["title"]) ?>
                                                    </div>

                                                    <div class="attention-text">
                                                        <?= e($item["text"]) ?>
                                                    </div>
                                                </div>
                                            </a>

                                        <?php endforeach; ?>

                                    </div>

                                <?php else: ?>

                                    <div class="all-clear">
                                        <div class="all-clear-icon">
                                            <i class="bi bi-check2-circle"></i>
                                        </div>

                                        <div class="fw-bold text-dark">
                                            No urgent items right now
                                        </div>

                                        <div class="small mt-1">
                                            Nothing currently meets the dashboard attention rules.
                                        </div>
                                    </div>

                                <?php endif; ?>

                            </div>

                        </section>

                        <section class="db-card">

                            <div class="db-card-head">
                                <div>
                                    <h2 class="db-card-title">
                                        <i class="bi bi-calendar3-week me-2 text-primary"></i>
                                        <?= $isHpuRole
                                            ? "Upcoming HPU Calendar"
                                            : ($isUser
                                                ? "Upcoming Activities"
                                                : "Upcoming Deadlines")
                                            ?>
                                    </h2>

                                    <div class="db-card-subtitle">
                                        <?php if ($isHpuRole && $hasCalendar): ?>
                                            <?= $calendarTodayCount ?> shared calendar item(s) active today.
                                        <?php elseif ($isUser): ?>
                                            HPU, Hospital and your Office Activities.
                                        <?php else: ?>
                                            Nearest request completion dates.
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if (($isHpuRole && $hasCalendar) || $isUser): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/my_calendar.php">
                                        View Calendar
                                    </a>
                                <?php endif; ?>
                            </div>

                            <div class="db-card-body">

                                <?php if ($isHpuRole && $hasCalendar): ?>

                                    <?php if (!$calendarUpcoming): ?>

                                        <div class="empty-state">
                                            <i class="bi bi-calendar2-check"></i>
                                            No upcoming shared activities.
                                        </div>

                                    <?php endif; ?>

                                    <?php foreach ($calendarUpcoming as $event): ?>

                                        <?php
                                        $eventDate = new DateTime($event["start_datetime"]);
                                        $scope = (string) ($event["calendar_scope"] ?? "HPU");
                                        ?>

                                        <a class="calendar-item calendar-item-link text-decoration-none text-dark"
                                            href="<?= e(BASE_URL) ?>/my_calendar.php">

                                            <div class="calendar-date">
                                                <div class="month">
                                                    <?= e($eventDate->format("M")) ?>
                                                </div>
                                                <div class="day">
                                                    <?= e($eventDate->format("d")) ?>
                                                </div>
                                            </div>

                                            <div class="calendar-info">

                                                <div class="calendar-title">
                                                    <?= e($event["title"]) ?>
                                                </div>

                                                <div class="calendar-meta">
                                                    <?= e($event["activity_type"]) ?>

                                                    <span
                                                        class="scope-pill <?= $scope === "Hospital" ? "scope-hospital" : "scope-hpu" ?>">
                                                        <?= e($scope) ?>
                                                    </span>

                                                    <?php if (!empty($event["location"])): ?>
                                                        • <?= e($event["location"]) ?>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="calendar-meta">
                                                    <?= (int) $event["all_day"] === 1
                                                        ? "All day"
                                                        : e($eventDate->format("g:i A"))
                                                        ?>
                                                </div>

                                            </div>

                                        </a>

                                    <?php endforeach; ?>

                                <?php elseif ($isUser): ?>

                                    <?php if (!$userUpcomingActivities): ?>

                                        <div class="empty-state">
                                            <i class="bi bi-calendar2-check"></i>
                                            No upcoming HPU, Hospital or Office activities.
                                        </div>

                                    <?php endif; ?>

                                    <?php foreach ($userUpcomingActivities as $activity): ?>

                                        <?php
                                        $eventDate = new DateTime(
                                            $activity["start_datetime"]
                                        );

                                        $source = (string) $activity["source"];

                                        $scopeClass = match ($source) {
                                            "Hospital" => "scope-hospital",
                                            "Office" => "scope-office",
                                            default => "scope-hpu"
                                        };
                                        ?>

                                        <a class="calendar-item text-decoration-none text-dark"
                                            href="<?= e($activity["url"]) ?>">

                                            <div class="calendar-date">
                                                <div class="month">
                                                    <?= e($eventDate->format("M")) ?>
                                                </div>

                                                <div class="day">
                                                    <?= e($eventDate->format("d")) ?>
                                                </div>
                                            </div>

                                            <div class="calendar-info">

                                                <div class="calendar-title">
                                                    <?= e($activity["title"]) ?>
                                                </div>

                                                <div class="calendar-meta">
                                                    <?= e($activity["subtitle"]) ?>

                                                    <span class="scope-pill <?= e($scopeClass) ?>">
                                                        <?= e($source) ?>
                                                    </span>

                                                    <?php if ($activity["status"] !== ""): ?>
                                                        • <?= e($activity["status"]) ?>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="calendar-meta">
                                                    <?= (int) $activity["all_day"] === 1
                                                        ? "All day"
                                                        : e($eventDate->format("g:i A"))
                                                        ?>

                                                    <?php if ($activity["location"] !== ""): ?>
                                                        • <?= e($activity["location"]) ?>
                                                    <?php endif; ?>
                                                </div>

                                            </div>

                                        </a>

                                    <?php endforeach; ?>

                                <?php else: ?>

                                    <?php if (!$upcomingDeadlines): ?>

                                        <div class="empty-state">
                                            <i class="bi bi-calendar2-check"></i>
                                            No upcoming deadlines.
                                        </div>

                                    <?php endif; ?>

                                    <?php foreach ($upcomingDeadlines as $deadline): ?>

                                        <?php
                                        $days = dashDaysUntil($deadline["date_completion"]);
                                        ?>

                                        <a class="calendar-item calendar-item-link text-decoration-none text-dark"
                                            href="<?= e(BASE_URL) ?>/staff/review_request.php?id=<?= (int) $deadline["id"] ?>">

                                            <div class="calendar-date">
                                                <div class="month">
                                                    <?= e(dashDate($deadline["date_completion"], "M")) ?>
                                                </div>
                                                <div class="day">
                                                    <?= e(dashDate($deadline["date_completion"], "d")) ?>
                                                </div>
                                            </div>

                                            <div class="calendar-info">
                                                <div class="calendar-title">
                                                    <?= e($deadline["project_title"]) ?>
                                                </div>

                                                <div class="calendar-meta">
                                                    <?= e($deadline["job_no"]) ?>
                                                    • <?= e($deadline["approval_status"]) ?>
                                                </div>

                                                <div class="calendar-meta">
                                                    <?php if ($days === 0): ?>
                                                        Due today
                                                    <?php elseif ($days === 1): ?>
                                                        Due tomorrow
                                                    <?php elseif ($days !== null): ?>
                                                        Due in <?= $days ?> days
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                        </a>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </div>

                        </section>

                    </div>

                    <?php if (!$isUser): ?>

                        <!-- ======================================================
     ANALYTICS
======================================================= -->

                        <div class="analytics-grid">

                            <section class="db-card">

                                <div class="db-card-head">
                                    <div>
                                        <h2 class="db-card-title">
                                            Job Request Pipeline
                                        </h2>

                                        <div class="db-card-subtitle">
                                            Current job-request workflow distribution.
                                        </div>
                                    </div>

                                    <?php if ($averageRating > 0): ?>
                                        <span class="date-chip">
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <?= number_format($averageRating, 1) ?>/5 avg.
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="db-card-body">
                                    <div class="chart-wrap">
                                        <canvas id="pipelineChart" role="img" aria-label="Bar chart of job requests by workflow status"></canvas>
                                    </div>
                                </div>

                            </section>

                            <section class="db-card">

                                <div class="db-card-head">
                                    <div>
                                        <h2 class="db-card-title">
                                            Request Type
                                        </h2>

                                        <div class="db-card-subtitle">
                                            Job services plus actual Publication Submissions.
                                        </div>
                                    </div>
                                </div>

                                <div class="db-card-body">

                                    <div class="chart-wrap small">
                                        <canvas id="requestTypeChart" role="img" aria-label="Doughnut chart of requests by service type"></canvas>
                                    </div>

                                    <div class="type-list">

                                        <?php
                                        foreach ($requestTypeNames as $index => $requestType):
                                            $dotClass = match ($requestType) {
                                                "Graphic Design" => "graphic",
                                                "Video Production" => "video",
                                                "Photo/Video Coverage" => "coverage",
                                                "IEC Materials" => "iec",
                                                "Publication Submission" => "publication",
                                                default => ""
                                            };
                                            ?>
                                            <div class="type-row">
                                                <div class="type-label">
                                                    <span class="type-dot <?= e($dotClass) ?>"></span>
                                                    <?= e($requestType) ?>
                                                </div>

                                                <div class="type-count">
                                                    <?= (int) $requestTypeCounts[$index] ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>

                                    </div>

                                </div>

                            </section>

                            <section class="db-card">

                                <div class="db-card-head">
                                    <div>
                                        <h2 class="db-card-title">
                                            <i class="bi bi-newspaper"></i>
                                            Publication Pipeline
                                        </h2>

                                        <div class="db-card-subtitle">
                                            Event / Activity and Recognition / Milestone publication workflow.
                                        </div>
                                    </div>

                                    <a class="btn btn-sm btn-outline-primary"
                                        href="<?= e(BASE_URL) ?>/staff/publication_requests.php">
                                        View
                                    </a>
                                </div>

                                <div class="db-card-body">
                                    <div class="chart-wrap small">
                                        <canvas id="publicationPipelineChart" role="img" aria-label="Bar chart of publication submissions by workflow stage"></canvas>
                                    </div>

                                    <div class="small text-muted mt-2">
                                        <?= $publicationPublishedThisMonth ?> published this month
                                        • <?= $publicationPreferredSoon ?> approaching preferred date
                                    </div>
                                </div>

                            </section>

                        </div>


                    <?php endif; ?>

                    <?php if ($isUser): ?>

                        <!-- ======================================================
     MY REQUESTS BY TYPE - REQUESTOR
======================================================= -->

                        <section class="db-card mb-3">

                            <div class="db-card-head">
                                <div>
                                    <h2 class="db-card-title">
                                        Request Type
                                    </h2>

                                    <div class="db-card-subtitle">
                                        Your job services and Publication Submissions.
                                    </div>
                                </div>
                            </div>

                            <div class="db-card-body">

                                <div class="chart-wrap small">
                                    <canvas id="requestTypeChart" role="img" aria-label="Doughnut chart of requests by service type"></canvas>
                                </div>

                                <div class="type-list">

                                    <?php
                                    foreach ($requestTypeNames as $index => $requestType):
                                        $dotClass = match ($requestType) {
                                            "Graphic Design" => "graphic",
                                            "Video Production" => "video",
                                            "Photo/Video Coverage" => "coverage",
                                            "IEC Materials" => "iec",
                                            "Publication Submission" => "publication",
                                            default => ""
                                        };
                                        ?>
                                        <div class="type-row">
                                            <div class="type-label">
                                                <span class="type-dot <?= e($dotClass) ?>"></span>
                                                <?= e($requestType) ?>
                                            </div>

                                            <div class="type-count">
                                                <?= (int) $requestTypeCounts[$index] ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>

                                </div>

                            </div>

                        </section>

                    <?php endif; ?>

                    <!-- ======================================================
     STAFF WORKLOAD - MANAGER
======================================================= -->

                    <?php if ($isManager && $hasAssignedTo): ?>

                        <section class="db-card mb-3">

                            <div class="db-card-head">
                                <div>
                                    <h2 class="db-card-title">
                                        <i class="bi bi-people me-2 text-primary"></i>
                                        HPU Staff Workload
                                    </h2>

                                    <div class="db-card-subtitle">
                                        Active assignments and deadline pressure by HPU personnel.
                                    </div>
                                </div>

                                <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/assign_jobs.php">
                                    Manage Assignments
                                </a>
                            </div>

                            <div class="workload-list">

                                <?php if (!$staffWorkload): ?>

                                    <div class="empty-state">
                                        <i class="bi bi-people"></i>
                                        No active HPU personnel accounts found.
                                    </div>

                                <?php endif; ?>

                                <?php
                                $maxWorkload = 1;

                                foreach ($staffWorkload as $staffItem) {
                                    $maxWorkload = max(
                                        $maxWorkload,
                                        (int) $staffItem["active_total"]
                                    );
                                }

                                foreach ($staffWorkload as $staffItem):

                                    $active = (int) $staffItem["active_total"];
                                    $overdue = (int) $staffItem["overdue_total"];
                                    $dueSoon = (int) $staffItem["due_soon_total"];
                                    $percent = min(
                                        100,
                                        (int) round(
                                            ($active / $maxWorkload) * 100
                                        )
                                    );
                                    ?>

                                    <a class="workload-row workload-row-link text-decoration-none text-dark"
                                        href="<?= e(BASE_URL) ?>/assign_jobs.php?assigned_to=<?= (int) $staffItem["id"] ?>"
                                        title="View <?= e($staffItem["name"]) ?>'s queue in Assign Jobs">

                                        <div class="staff-name">

                                            <div class="staff-avatar">
                                                <?= e(dashInitials($staffItem["name"])) ?>
                                            </div>

                                            <div class="staff-name-text">
                                                <strong>
                                                    <?= e($staffItem["name"]) ?>
                                                </strong>
                                                <small>
                                                    <?= e(ucfirst($staffItem["role"])) ?>
                                                </small>
                                            </div>

                                        </div>

                                        <div class="workload-numbers">
                                            <strong><?= $active ?></strong> active
                                            • <?= $dueSoon ?> due soon
                                            <?php if ($overdue > 0): ?>
                                                • <span class="text-danger fw-bold"><?= $overdue ?> overdue</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="workload-bar">
                                            <div class="workload-fill" style="width:<?= $percent ?>%"></div>
                                        </div>

                                    </a>

                                <?php endforeach; ?>

                            </div>

                        </section>

                    <?php endif; ?>

                    <!-- ======================================================
     CLIENT EXPERIENCE SURVEY
======================================================= -->

                    <?php if ($hasClientSurvey): ?>

                        <?php if ($isHpuRole): ?>

                            <section class="db-card survey-card mb-3">

                                <div class="db-card-head">

                                    <div class="d-flex align-items-center gap-2">

                                        <div class="survey-head-icon">
                                            <i class="bi bi-chat-square-heart"></i>
                                        </div>

                                        <div>
                                            <h2 class="db-card-title">
                                                Client Experience Survey
                                            </h2>

                                            <div class="db-card-subtitle">
                                                Consolidated HPU service feedback from completed job requests.
                                            </div>
                                        </div>

                                    </div>

                                    <a class="btn btn-sm btn-outline-primary"
                                        href="<?= e(BASE_URL) ?>/staff/survey_analytics.php">
                                        <i class="bi bi-bar-chart me-1"></i>
                                        View Analytics
                                    </a>

                                </div>

                                <div class="db-card-body">

                                    <div class="survey-metrics">

                                        <div class="survey-metric">

                                            <div class="survey-metric-label">
                                                Total Responses
                                            </div>

                                            <div class="survey-metric-value">
                                                <?= (int) $surveySummary["total"] ?>
                                            </div>

                                        </div>

                                        <div class="survey-metric">

                                            <div class="survey-metric-label">
                                                Overall Rating
                                            </div>

                                            <div class="survey-metric-value">
                                                <?= number_format(
                                                    (float) $surveySummary["average"],
                                                    2
                                                ) ?>
                                                <small>/ 5</small>
                                            </div>

                                            <div class="survey-progress">
                                                <div class="survey-progress-fill" style="width:<?= min(
                                                    100,
                                                    max(
                                                        0,
                                                        ((float) $surveySummary["average"] / 5) * 100
                                                    )
                                                ) ?>%"></div>
                                            </div>

                                        </div>

                                        <div class="survey-metric">

                                            <div class="survey-metric-label">
                                                This Month
                                            </div>

                                            <div class="survey-metric-value">
                                                <?= (int) $surveySummary["this_month"] ?>
                                            </div>

                                        </div>

                                        <div class="survey-metric">

                                            <div class="survey-metric-label">
                                                Objective Achieved
                                            </div>

                                            <div class="survey-metric-value">
                                                <?= number_format(
                                                    (float) $surveySummary["positive"],
                                                    1
                                                ) ?>
                                                <small>%</small>
                                            </div>

                                            <div class="survey-progress">
                                                <div class="survey-progress-fill" style="width:<?= min(
                                                    100,
                                                    max(
                                                        0,
                                                        (float) $surveySummary["positive"]
                                                    )
                                                ) ?>%"></div>
                                            </div>

                                        </div>

                                    </div>

                                    <div class="small text-muted mt-3">

                                        Average service criteria score:
                                        <strong class="text-dark">
                                            <?= number_format(
                                                (float) $surveySummary["average_service"],
                                                2
                                            ) ?>/5
                                        </strong>

                                        • Includes timeliness, responsiveness, quality of output,
                                        professionalism, communication and whether the service met
                                        client needs.

                                    </div>

                                </div>

                            </section>

                        <?php elseif ($isUser): ?>

                            <section class="db-card survey-card mb-3">

                                <div class="db-card-head">

                                    <div class="d-flex align-items-center gap-2">

                                        <div class="survey-head-icon">
                                            <i class="bi bi-chat-square-heart"></i>
                                        </div>

                                        <div>
                                            <h2 class="db-card-title">
                                                Client Experience Survey
                                            </h2>

                                            <div class="db-card-subtitle">
                                                Share your experience after a completed HPU job request.
                                            </div>
                                        </div>

                                    </div>

                                    <?php if ($pendingSurveyCount > 0): ?>

                                        <span class="date-chip">
                                            <i class="bi bi-hourglass-split text-warning"></i>
                                            <?= $pendingSurveyCount ?> pending
                                        </span>

                                    <?php endif; ?>

                                </div>

                                <div class="db-card-body">

                                    <?php if ($pendingSurveys): ?>

                                        <div class="pending-survey-list">

                                            <?php foreach ($pendingSurveys as $surveyRequest): ?>

                                                <div class="pending-survey-item">

                                                    <div class="min-w-0">

                                                        <div class="pending-survey-job">
                                                            <?= e($surveyRequest["job_no"]) ?>
                                                        </div>

                                                        <div class="pending-survey-title">
                                                            <?= e($surveyRequest["project_title"]) ?>
                                                        </div>

                                                        <div class="pending-survey-meta">
                                                            <?= e($surveyRequest["approval_status"]) ?>

                                                            <?php if ($surveyRequest["date_completion"]): ?>
                                                                • Completed
                                                                <?= e(
                                                                    dashDate(
                                                                        $surveyRequest["date_completion"]
                                                                    )
                                                                ) ?>
                                                            <?php endif; ?>
                                                        </div>

                                                    </div>

                                                    <a class="btn btn-sm btn-primary flex-shrink-0"
                                                        href="<?= e(BASE_URL) ?>/user/client_experience_survey.php?id=<?= (int) $surveyRequest["id"] ?>">
                                                        <i class="bi bi-chat-square-heart me-1"></i>
                                                        Complete Survey
                                                    </a>

                                                </div>

                                            <?php endforeach; ?>

                                        </div>

                                        <?php if ($pendingSurveyCount > count($pendingSurveys)): ?>

                                            <div class="text-end mt-2">

                                                <a class="small fw-bold text-decoration-none"
                                                    href="<?= e(BASE_URL) ?>/user/my_requests.php">
                                                    View all eligible requests
                                                    <i class="bi bi-arrow-right"></i>
                                                </a>

                                            </div>

                                        <?php endif; ?>

                                    <?php else: ?>

                                        <div class="survey-empty">
                                            <i class="bi bi-check2-circle"></i>

                                            <strong class="d-block text-dark">
                                                No pending Client Experience Survey
                                            </strong>

                                            Your survey is available when a request reaches
                                            Completed or For Evaluation status.

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </section>

                        <?php endif; ?>

                    <?php elseif ($isHpuRole): ?>

                        <section class="db-card survey-card mb-3">

                            <div class="db-card-head">

                                <div class="d-flex align-items-center gap-2">

                                    <div class="survey-head-icon">
                                        <i class="bi bi-chat-square-heart"></i>
                                    </div>

                                    <div>
                                        <h2 class="db-card-title">
                                            Client Experience Survey
                                        </h2>

                                        <div class="db-card-subtitle">
                                            Survey analytics is not yet active in this database.
                                        </div>
                                    </div>

                                </div>

                            </div>

                            <div class="db-card-body">

                                <div class="alert alert-warning mb-0 small">
                                    The table
                                    <code>job_request_client_experience</code>
                                    was not found. Import the existing
                                    <code>assets/job_request_client_experience.sql</code>
                                    file included in your Workbench to activate this dashboard section.
                                </div>

                            </div>

                        </section>

                    <?php endif; ?>

                    <!-- ======================================================
     LATEST REQUESTS + RESOURCES
======================================================= -->

                    <div class="bottom-grid">

                        <section class="db-card">

                            <div class="db-card-head">
                                <div>
                                    <h2 class="db-card-title">
                                        <?= $isUser
                                            ? "My Recent Work"
                                            : "Recent Work"
                                            ?>
                                    </h2>

                                    <div class="db-card-subtitle">
                                        Job Requests and Publication Submissions, newest first.
                                    </div>
                                </div>

                                <a class="btn btn-sm btn-outline-primary" href="<?=
                                    $isUser
                                    ? e(BASE_URL . "/user/my_requests.php")
                                    : e(BASE_URL . "/staff/kanban.php")
                                    ?>">
                                    View All
                                </a>
                            </div>

                            <div>

                                <?php if (!$recentWork): ?>

                                    <div class="empty-state">
                                        <i class="bi bi-inbox"></i>
                                        No recent work found.
                                    </div>

                                <?php endif; ?>

                                <?php foreach ($recentWork as $work): ?>

                                    <?php
                                    $sourceClass = match ($work["source"]) {
                                        "event" => "work-source-event",
                                        "recognition" => "work-source-recognition",
                                        default => "work-source-job"
                                    };

                                    $sourceLabel = match ($work["source"]) {
                                        "event" => "Publication",
                                        "recognition" => "Recognition",
                                        default => "Job"
                                    };

                                    $statusClass =
                                        $work["source"] === "job"
                                        ? dashStatusClass((string) $work["status"])
                                        : dashPublicationStatusClass((string) $work["status"]);
                                    ?>

                                    <a class="request-row request-row-link" href="<?= e($work["url"]) ?>">

                                        <div>

                                            <div class="request-no">
                                                <span class="work-source <?= e($sourceClass) ?>">
                                                    <?= e($sourceLabel) ?>
                                                </span>

                                                <?= e($work["code"]) ?>
                                            </div>

                                            <div class="request-title">
                                                <?= e($work["title"]) ?>
                                            </div>

                                            <div class="request-meta">

                                                <span>
                                                    <i class="bi bi-calendar3 me-1"></i>
                                                    <?= e(dashDate($work["date"])) ?>
                                                </span>

                                                <?php if ($work["subtitle"] !== ""): ?>
                                                    <span>
                                                        <i class="bi bi-tag me-1"></i>
                                                        <?= e($work["subtitle"]) ?>
                                                    </span>
                                                <?php endif; ?>

                                                <?php if (!$isUser && $work["requestor"] !== ""): ?>
                                                    <span>
                                                        <i class="bi bi-person me-1"></i>
                                                        <?= e($work["requestor"]) ?>
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ($work["assigned_name"] !== ""): ?>
                                                    <span>
                                                        <i class="bi bi-person-check me-1"></i>
                                                        <?= e($work["assigned_name"]) ?>
                                                    </span>
                                                <?php endif; ?>

                                            </div>

                                            <?php if ($work["types"]): ?>
                                                <div class="type-badges">

                                                    <?php foreach ($work["types"] as $type): ?>
                                                        <span class="type-badge <?= e(dashRequestTypeClass((string) $type)) ?>">
                                                            <i class="bi <?= e(dashRequestTypeIcon((string) $type)) ?>"></i>
                                                            <?= e($type) ?>
                                                        </span>
                                                    <?php endforeach; ?>

                                                </div>
                                            <?php endif; ?>

                                        </div>

                                        <div class="text-end">

                                            <span class="status-pill <?= e($statusClass) ?>">
                                                <?= e($work["status"]) ?>
                                            </span>

                                            <?php if ($work["priority"] !== ""): ?>
                                                <div
                                                    class="priority-pill <?= e(dashPriorityClass((string) $work["priority"])) ?> mt-2">
                                                    <?= e($work["priority"]) ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($work["target_date"]): ?>
                                                <div class="small text-muted mt-1">
                                                    <?= $work["source"] === "job"
                                                        ? "Due "
                                                        : "Preferred "
                                                        ?>
                                                    <?= e(dashDate($work["target_date"], "M d")) ?>
                                                </div>
                                            <?php endif; ?>

                                        </div>

                                    </a>

                                <?php endforeach; ?>

                            </div>

                        </section>

                        <div class="d-flex flex-column gap-3">

                            <!-- QUICK ACTIONS -->
                            <section class="db-card">

                                <div class="db-card-head">
                                    <div>
                                        <h2 class="db-card-title">
                                            Quick Actions
                                        </h2>
                                        <div class="db-card-subtitle">
                                            Common Workbench tasks.
                                        </div>
                                    </div>
                                </div>

                                <div class="db-card-body">

                                    <div class="quick-actions">

                                        <?php if ($isUser): ?>

                                            <a class="quick-action" href="<?= e(BASE_URL) ?>/user/new_request.php">
                                                <i class="bi bi-file-earmark-plus"></i>
                                                New Request
                                            </a>

                                            <a class="quick-action" href="<?= e(BASE_URL) ?>/user/my_requests.php">
                                                <i class="bi bi-card-checklist"></i>
                                                My Requests
                                            </a>

                                            <a class="quick-action" href="<?= e(BASE_URL) ?>/my_calendar.php">
                                                <i class="bi bi-calendar3-week"></i>
                                                Activities Calendar
                                            </a>

                                            <a class="quick-action" href="<?= e(BASE_URL) ?>/my_calendar.php?new=office">
                                                <i class="bi bi-camera"></i>
                                                Office Activity / Event
                                            </a>

                                            <a class="quick-action"
                                                href="<?= e(BASE_URL) ?>/content_submission/event_activity.php">
                                                <i class="bi bi-newspaper"></i>
                                                Event / Activity Publication
                                            </a>

                                            <a class="quick-action"
                                                href="<?= e(BASE_URL) ?>/content_submission/recognition_milestone.php">
                                                <i class="bi bi-award"></i>
                                                Recognition / Milestone
                                            </a>

                                            <?php if ($hasClientSurvey && $pendingSurveys): ?>
                                                <a class="quick-action"
                                                    href="<?= e(BASE_URL) ?>/user/client_experience_survey.php?id=<?= (int) $pendingSurveys[0]["id"] ?>">
                                                    <i class="bi bi-chat-square-heart"></i>
                                                    Client Survey
                                                </a>
                                            <?php endif; ?>

                                        <?php elseif ($isStaff): ?>

                                            <a class="quick-action" href="<?= e(BASE_URL) ?>/my_assigned_jobs.php">
                                                <i class="bi bi-person-workspace"></i>
                                                My Assigned Jobs
                                            </a>

                                            <a class="quick-action" href="<?= e(BASE_URL) ?>/staff/requests.php">
                                                <i class="bi bi-clipboard-check"></i>
                                                Review Requests
                                            </a>

                                            <a class="quick-action"
                                                href="<?= e(BASE_URL) ?>/staff/publication_requests.php">
                                                <i class="bi bi-newspaper"></i>
                                                Publication Requests
                                            </a>

                                            <?php if ($hasCalendar): ?>
                                                <a class="quick-action" href="<?= e(BASE_URL) ?>/my_calendar.php">
                                                    <i class="bi bi-calendar3-week"></i>
                                                    HPU Calendar
                                                </a>
                                            <?php endif; ?>

                                        <?php else: ?>

                                            <a class="quick-action" href="<?= e(BASE_URL) ?>/assign_jobs.php">
                                                <i class="bi bi-person-check"></i>
                                                Assign Jobs
                                            </a>

                                            <a class="quick-action" href="<?= e(BASE_URL) ?>/staff/requests.php">
                                                <i class="bi bi-clipboard2-check"></i>
                                                Request Approval
                                            </a>

                                            <a class="quick-action"
                                                href="<?= e(BASE_URL) ?>/staff/publication_requests.php">
                                                <i class="bi bi-newspaper"></i>
                                                Publication Requests
                                            </a>

                                            <?php if ($hasCalendar): ?>
                                                <a class="quick-action" href="<?= e(BASE_URL) ?>/my_calendar.php">
                                                    <i class="bi bi-calendar3-week"></i>
                                                    HPU Calendar
                                                </a>
                                            <?php endif; ?>

                                        <?php endif; ?>

                                        <?php if ($isHpuRole && $hasClientSurvey): ?>
                                            <a class="quick-action" href="<?= e(BASE_URL) ?>/staff/survey_analytics.php">
                                                <i class="bi bi-chat-square-heart"></i>
                                                Survey Analytics
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($hasIecLibrary): ?>
                                            <a class="quick-action" href="<?= e(BASE_URL) ?>/iec_materials.php">
                                                <i class="bi bi-folder2-open"></i>
                                                IEC Materials
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($hasNotifications): ?>
                                            <a class="quick-action" href="<?= e(BASE_URL) ?>/user/notifications.php">
                                                <i class="bi bi-bell"></i>
                                                Notifications
                                            </a>
                                        <?php endif; ?>

                                    </div>

                                </div>

                            </section>

                            <!-- IEC SUMMARY -->
                            <?php if ($hasIecLibrary): ?>

                                <section class="db-card">

                                    <div class="db-card-head">
                                        <div>
                                            <h2 class="db-card-title">
                                                IEC Materials
                                            </h2>
                                            <div class="db-card-subtitle">
                                                Shared HPU resource library.
                                            </div>
                                        </div>

                                        <a class="btn btn-sm btn-outline-primary"
                                            href="<?= e(BASE_URL) ?>/iec_materials.php">
                                            Open
                                        </a>
                                    </div>

                                    <div class="db-card-body">

                                        <div class="iec-box">

                                            <div class="iec-stat">
                                                <strong><?= $iecAvailable ?></strong>
                                                <span>Available</span>
                                            </div>

                                            <div class="iec-stat">
                                                <strong><?= $iecNewThisMonth ?></strong>
                                                <span>New This Month</span>
                                            </div>

                                            <div class="iec-stat">
                                                <strong><?= $iecDownloads ?></strong>
                                                <span>Downloads</span>
                                            </div>

                                        </div>

                                    </div>

                                </section>

                            <?php endif; ?>

                            <!-- NOTIFICATIONS -->
                            <?php if ($hasNotifications): ?>

                                <section class="db-card">

                                    <div class="db-card-head">
                                        <div>
                                            <h2 class="db-card-title">
                                                Recent Notifications
                                            </h2>
                                            <div class="db-card-subtitle">
                                                <?= $unreadNotifications ?> unread notification(s).
                                            </div>
                                        </div>

                                        <a class="btn btn-sm btn-outline-primary"
                                            href="<?= e(BASE_URL) ?>/user/notifications.php">
                                            View All
                                        </a>
                                    </div>

                                    <div class="db-card-body">

                                        <?php if (!$recentNotifications): ?>
                                            <div class="empty-state p-2">
                                                <i class="bi bi-bell-slash"></i>
                                                No notifications.
                                            </div>
                                        <?php endif; ?>

                                        <?php foreach ($recentNotifications as $notification): ?>

                                            <a class="notification-item <?= (int) $notification["is_read"] === 0 ? "unread" : "" ?> text-decoration-none text-dark"
                                                href="<?= e($notification["action_url"] ?: BASE_URL . "/user/notifications.php") ?>">
                                                <span class="notification-dot"></span>

                                                <div>
                                                    <div class="notification-title">
                                                        <?= e($notification["title"]) ?>
                                                    </div>

                                                    <div class="notification-text">
                                                        <?= e(
                                                            mb_strimwidth(
                                                                strip_tags(
                                                                    (string) $notification["message"]
                                                                ),
                                                                0,
                                                                105,
                                                                "..."
                                                            )
                                                        ) ?>
                                                    </div>

                                                    <div class="notification-time">
                                                        <?= e(dashDate($notification["created_at"], "M d • g:i A")) ?>
                                                    </div>
                                                </div>
                                            </a>

                                        <?php endforeach; ?>

                                    </div>

                                </section>

                            <?php endif; ?>

                        </div>

                    </div>

                    <?php if (!$isUser): ?>

                        <!-- ======================================================
     6-MONTH TREND
======================================================= -->

                        <section class="db-card db-chart-trend mb-3">

                            <div class="db-card-head">
                                <div>
                                    <h2 class="db-card-title">
                                        Six-Month Request Trend
                                    </h2>

                                    <div class="db-card-subtitle">
                                        Request volume over the most recent six months.
                                    </div>
                                </div>
                            </div>

                            <div class="db-card-body">
                                <div class="chart-wrap">
                                        <canvas id="monthlyChart" role="img" aria-label="Line chart of request volume over the last six months"></canvas>
                                </div>
                            </div>

                        </section>

                    <?php endif; ?>

                </div>

            </main>

        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener("DOMContentLoaded", function () {

            Chart.defaults.font.family =
                '"Inter","Segoe UI",Arial,sans-serif';

            Chart.defaults.color =
                "#667085";

            /*
            |--------------------------------------------------------------------------
            | Pipeline
            |--------------------------------------------------------------------------
            */

            const pipelineCanvas =
                document.getElementById(
                    "pipelineChart"
                );

            if (pipelineCanvas) {
                new Chart(
                    pipelineCanvas,
                    {
                        type: "bar",

                        data: {
                            labels:
                                <?= json_encode($pipelineStatuses, JSON_UNESCAPED_UNICODE) ?>,

                            datasets: [{
                                label: "Requests",

                                data:
                                    <?= json_encode($pipelineCounts) ?>,

                                borderRadius: 7,
                                borderSkipped: false,

                                backgroundColor: [
                                    "#f59e0b",
                                    "#0891b2",
                                    "#16a34a",
                                    "#2563eb",
                                    "#ea580c",
                                    "#0ea5e9",
                                    "#7c3aed",
                                    "#475569"
                                ]
                            }]
                        },

                        options: {
                            responsive: true,
                            maintainAspectRatio: false,

                            plugins: {
                                legend: {
                                    display: false
                                }
                            },

                            scales: {
                                x: {
                                    grid: {
                                        display: false
                                    },

                                    ticks: {
                                        font: {
                                            size: 10
                                        }
                                    }
                                },

                                y: {
                                    beginAtZero: true,

                                    ticks: {
                                        precision: 0,
                                        font: {
                                            size: 10
                                        }
                                    },

                                    grid: {
                                        color: "#f0f2f5"
                                    }
                                }
                            }
                        }
                    }
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Publication Pipeline
            |--------------------------------------------------------------------------
            */

            const publicationCanvas =
                document.getElementById(
                    "publicationPipelineChart"
                );

            if (publicationCanvas) {
                new Chart(
                    publicationCanvas,
                    {
                        type: "bar",

                        data: {
                            labels:
                                <?= json_encode($publicationPipelineStatuses, JSON_UNESCAPED_UNICODE) ?>,

                            datasets: [{
                                label: "Publications",

                                data:
                                    <?= json_encode($publicationPipelineCounts) ?>,

                                borderRadius: 7,
                                borderSkipped: false,

                                backgroundColor: [
                                    "#0891b2",
                                    "#2563eb",
                                    "#7c3aed",
                                    "#16a34a",
                                    "#475569"
                                ]
                            }]
                        },

                        options: {
                            responsive: true,
                            maintainAspectRatio: false,

                            plugins: {
                                legend: {
                                    display: false
                                }
                            },

                            scales: {
                                x: {
                                    grid: {
                                        display: false
                                    },

                                    ticks: {
                                        font: {
                                            size: 9
                                        }
                                    }
                                },

                                y: {
                                    beginAtZero: true,

                                    ticks: {
                                        precision: 0,
                                        font: {
                                            size: 10
                                        }
                                    },

                                    grid: {
                                        color: "#f0f2f5"
                                    }
                                }
                            }
                        }
                    }
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Request Type
            |--------------------------------------------------------------------------
            */

            const typeCanvas =
                document.getElementById(
                    "requestTypeChart"
                );

            if (typeCanvas) {
                new Chart(
                    typeCanvas,
                    {
                        type: "doughnut",

                        data: {
                            labels:
                                <?= json_encode($requestTypeNames, JSON_UNESCAPED_UNICODE) ?>,

                            datasets: [{
                                data:
                                    <?= json_encode($requestTypeCounts) ?>,

                                backgroundColor: [
                                    "#2563eb",
                                    "#7c3aed",
                                    "#ea580c",
                                    "#15803d",
                                    "#0891b2"
                                ],

                                borderWidth: 0,
                                hoverOffset: 4
                            }]
                        },

                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: "68%",

                            plugins: {
                                legend: {
                                    display: false
                                }
                            }
                        }
                    }
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Monthly Trend
            |--------------------------------------------------------------------------
            */

            const monthlyCanvas =
                document.getElementById(
                    "monthlyChart"
                );

            if (monthlyCanvas) {
                new Chart(
                    monthlyCanvas,
                    {
                        type: "line",

                        data: {
                            labels:
                                <?= json_encode($monthlyLabels, JSON_UNESCAPED_UNICODE) ?>,

                            datasets: [{
                                label: "Requests",

                                data:
                                    <?= json_encode($monthlyValues) ?>,

                                borderColor: "#1769e0",
                                backgroundColor: "rgba(23,105,224,.08)",
                                fill: true,
                                tension: .35,
                                borderWidth: 2.4,
                                pointRadius: 3,
                                pointHoverRadius: 5
                            }]
                        },

                        options: {
                            responsive: true,
                            maintainAspectRatio: false,

                            plugins: {
                                legend: {
                                    display: false
                                }
                            },

                            scales: {
                                x: {
                                    grid: {
                                        display: false
                                    }
                                },

                                y: {
                                    beginAtZero: true,

                                    ticks: {
                                        precision: 0
                                    },

                                    grid: {
                                        color: "#f0f2f5"
                                    }
                                }
                            }
                        }
                    }
                );
            }

        });
    </script>

    <script>
        /* =====================================================
           DASHBOARD UX (additive — no data change)
           - Collapsible cards (persisted)
           - KPI count-up
           - Recent-work instant filter
           - Progress-bar reveal on scroll
           - Back to top
        ===================================================== */
        document.addEventListener("DOMContentLoaded", function () {
            var reduceMotion = window.matchMedia &&
                window.matchMedia(
                    "(prefers-reduced-motion: reduce)"
                ).matches;

            /* ---------- collapsible cards ---------- */
            var collapseKey = "dash_collapsed_v1";
            var collapsed = {};
            try {
                collapsed = JSON.parse(
                    localStorage.getItem(collapseKey) || "{}"
                ) || {};
            } catch (e) { collapsed = {}; }
            function saveCollapsed() {
                try {
                    localStorage.setItem(
                        collapseKey,
                        JSON.stringify(collapsed)
                    );
                } catch (e) {}
            }
            Array.prototype.slice.call(
                document.querySelectorAll("section.db-card")
            ).forEach(function (card, idx) {
                var head = card.querySelector(
                    ":scope > .db-card-head"
                );
                var titleEl = card.querySelector(
                    ".db-card-title"
                );
                if (!head || !titleEl) return;
                var key = (titleEl.textContent || "")
                    .trim().replace(/\s+/g, " ")
                    .substring(0, 60) || ("card-" + idx);
                var btn = document.createElement("button");
                btn.type = "button";
                btn.className = "collapse-btn";
                btn.title = "Collapse / expand section";
                btn.setAttribute("aria-label",
                    "Collapse / expand: " + key);
                btn.innerHTML =
                    '<i class="bi bi-chevron-down"></i>';
                btn.addEventListener("click", function () {
                    var isCollapsed = card.classList.toggle(
                        "is-collapsed"
                    );
                    btn.setAttribute(
                        "aria-expanded",
                        isCollapsed ? "false" : "true"
                    );
                    if (isCollapsed) collapsed[key] = 1;
                    else delete collapsed[key];
                    saveCollapsed();
                });
                head.appendChild(btn);
                if (collapsed[key]) {
                    card.classList.add("is-collapsed");
                    btn.setAttribute(
                        "aria-expanded", "false"
                    );
                } else {
                    btn.setAttribute(
                        "aria-expanded", "true"
                    );
                }
            });

            /* ---------- KPI count-up ---------- */
            function countUp(el, target) {
                if (reduceMotion || !isFinite(target)) {
                    el.textContent =
                        target.toLocaleString();
                    return;
                }
                var t0 = null;
                var dur = 700;
                function tick(t) {
                    if (!t0) t0 = t;
                    var p = Math.min(
                        1, (t - t0) / dur
                    );
                    var eased = 1 - Math.pow(1 - p, 3);
                    el.textContent = Math.round(
                        target * eased
                    ).toLocaleString();
                    if (p < 1) requestAnimationFrame(tick);
                }
                requestAnimationFrame(tick);
            }
            Array.prototype.slice.call(
                document.querySelectorAll(".kpi-value")
            ).forEach(function (el) {
                var raw = (el.textContent || "")
                    .replace(/,/g, "").trim();
                var num = parseInt(raw, 10);
                if (!isNaN(num)) countUp(el, num);
            });

            /* ---------- progress-bar reveal ---------- */
            try {
                var fills = Array.prototype.slice.call(
                    document.querySelectorAll(
                        ".survey-progress-fill, .workload-fill"
                    )
                );
                fills.forEach(function (f) {
                    f.dataset.w = f.style.width || "0%";
                    if (!reduceMotion) f.style.width = "0%";
                });
                if (!reduceMotion && "IntersectionObserver" in window) {
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
                        { threshold: 0.25 }
                    );
                    fills.forEach(function (f) {
                        io.observe(f);
                    });
                } else {
                    fills.forEach(function (f) {
                        f.style.width = f.dataset.w;
                    });
                }
            } catch (e) {}

            /* ---------- recent-work instant filter ---------- */
            try {
                var recentHead = null;
                Array.prototype.slice.call(
                    document.querySelectorAll(
                        ".db-card .db-card-title"
                    )
                ).forEach(function (h) {
                    var t = (h.textContent || "").trim();
                    if (t === "My Recent Work"
                        || t === "Recent Work") {
                        recentHead = h.closest(
                            ".db-card-head"
                        );
                    }
                });
                if (recentHead) {
                    var card = recentHead.closest(
                        ".db-card"
                    );
                    var rows = Array.prototype.slice.call(
                        card.querySelectorAll(
                            ".request-row-link"
                        )
                    );
                    if (rows.length > 2) {
                        var wrap = document.createElement(
                            "div"
                        );
                        wrap.className =
                            "recent-filter-wrap";
                        wrap.innerHTML = '<i class="bi bi-search"></i>'
                            + '<input class="recent-filter" type="text" '
                            + 'placeholder="Filter recent work…" '
                            + 'aria-label="Filter recent work">';
                        var live = document.createElement(
                            "span"
                        );
                        live.className = "recent-live";
                        var viewAll = recentHead.querySelector(
                            "a.btn"
                        );
                        if (viewAll) {
                            recentHead.insertBefore(
                                wrap, viewAll
                            );
                            recentHead.insertBefore(
                                live, viewAll
                            );
                        } else {
                            recentHead.appendChild(wrap);
                            recentHead.appendChild(live);
                        }
                        var input = wrap.querySelector(
                            "input"
                        );
                        var noRow = document.createElement(
                            "div"
                        );
                        noRow.className =
                            "empty-state p-2 small text-muted";
                        noRow.style.display = "none";
                        noRow.textContent =
                            "No recent items match this filter.";
                        rows[rows.length - 1].after(noRow);
                        rows.forEach(function (r) {
                            r.dataset.search = (
                                r.textContent || ""
                            ).toLowerCase();
                        });
                        input.addEventListener(
                            "input",
                            function () {
                                var q = input.value.trim()
                                    .toLowerCase();
                                var shown = 0;
                                rows.forEach(function (r) {
                                    var hit = !q
                                        || r.dataset.search
                                            .indexOf(q) !== -1;
                                    r.style.display = hit
                                        ? "" : "none";
                                    if (hit) shown++;
                                });
                                noRow.style.display = shown
                                    ? "none" : "";
                                live.textContent = q
                                    ? shown + " of "
                                        + rows.length
                                    : "";
                            }
                        );
                    }
                }
            } catch (e) {}

            /* ---------- refresh ---------- */
            var refreshBtn =
                document.getElementById("dashRefreshBtn");
            if (refreshBtn) {
                refreshBtn.addEventListener(
                    "click",
                    function () {
                        refreshBtn.disabled = true;
                        refreshBtn.innerHTML =
                            '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Refreshing…';
                        window.location.reload();
                    }
                );
            }

            /* ---------- back to top ---------- */
            var toTop = document.createElement("button");
            toTop.id = "dashToTop";
            toTop.type = "button";
            toTop.title = "Back to top";
            toTop.setAttribute("aria-label", "Back to top");
            toTop.innerHTML =
                '<i class="bi bi-arrow-up"></i>';
            document.body.appendChild(toTop);
            window.addEventListener("scroll", function () {
                toTop.classList.toggle(
                    "show", window.scrollY > 600
                );
            }, { passive: true });
            toTop.addEventListener("click", function () {
                window.scrollTo({
                    top: 0,
                    behavior: reduceMotion
                        ? "auto" : "smooth"
                });
            });
        });
    </script>

</body>

</html>