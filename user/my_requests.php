<?php
declare(strict_types=1);

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";
include "../includes/hpu_availability.php";

checkLogin();
requireModule($conn, "my_requests");

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

$userId =
    (int) (
        $_SESSION["user_id"]
        ?? 0
    );


$userDepartment =
    trim(
        (string) (
            $_SESSION["department"]
            ?? ""
        )
    );

if ($userDepartment === "") {
    $departmentStmt =
        $conn->prepare(
            "SELECT department
             FROM users
             WHERE id = ?
             LIMIT 1"
        );

    $departmentStmt->bind_param(
        "i",
        $userId
    );

    $departmentStmt->execute();

    $departmentRow =
        $departmentStmt
            ->get_result()
            ->fetch_assoc();

    $departmentStmt->close();

    $userDepartment =
        trim(
            (string) (
                $departmentRow["department"]
                ?? ""
            )
        );

    $_SESSION["department"] =
        $userDepartment;
}

/*
|--------------------------------------------------------------------------
| HPU archive permission (staff / supervisor / admin can archive job
| requests directly from My Requests). Mirrors staff/archive_request.php
| which already allows these three roles.
|--------------------------------------------------------------------------
*/

$userRole =
    (string) (
        $_SESSION["role"]
        ?? "user"
    );

$isHpuArchiver =
    in_array(
        $userRole,
        [
            "staff",
            "supervisor",
            "admin"
        ],
        true
    );

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    (string) $_SESSION["csrf_token"];

$archiveFlash =
    $_SESSION["request_message"]
    ?? null;

unset($_SESSION["request_message"]);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function myReqTableExists(
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

function myReqColumnExists(
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

/*
|--------------------------------------------------------------------------
| Same-office matching (registered department OR typed office name).
| Both sides are compared case-insensitively after trimming, so
| free-text variations still reflect in lists and the Office tab.
|--------------------------------------------------------------------------
*/

function myReqDeptMatch(
    ?string $typed,
    ?string $registered,
    string $userDepartment
): bool {
    if ($userDepartment === "") {
        return false;
    }

    $userNorm =
        mb_strtolower(
            trim(
                $userDepartment
            )
        );

    foreach (
        [$typed, $registered]
        as $candidate
    ) {
        if (
            trim(
                (string) $candidate
            )
            !==
            ""
            &&
            mb_strtolower(
                trim(
                    (string) $candidate
                )
            )
            ===
            $userNorm
        ) {
            return true;
        }
    }

    return false;
}

function requestStatusClass(
    string $status
): string {
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

        "For Review" => "status-for-review",
        "In Preparation" => "status-in-preparation",
        "For Approval" => "status-for-approval",
        "Published" => "status-published",

        default => "status-default"
    };
}

function requestStatusIcon(
    string $status
): string {
    return match ($status) {
        "Pending Review" => "bi-hourglass-split",
        "Under Review" => "bi-search",
        "Approved" => "bi-patch-check-fill",
        "In Development" => "bi-tools",
        "For Revision" => "bi-arrow-repeat",
        "Disapproved" => "bi-x-circle-fill",
        "Completed" => "bi-check2-all",
        "For Evaluation" => "bi-clipboard2-check",
        "Closed" => "bi-lock-fill",

        "For Review" => "bi-search",
        "In Preparation" => "bi-pencil-square",
        "For Approval" => "bi-patch-check",
        "Published" => "bi-megaphone-fill",

        default => "bi-info-circle"
    };
}

function canEvaluate(
    array $request
): bool {
    return
        ($request["source"] ?? "")
        ===
        "job"
        &&
        !empty(
        $request["is_owner"]
    )
        &&
        in_array(
            $request["status"],
            [
                "Completed",
                "For Evaluation"
            ],
            true
        )
        &&
        empty(
        $request["survey_submitted"]
    );
}

function canViewEvaluation(
    array $request
): bool {
    return
        ($request["source"] ?? "")
        ===
        "job"
        &&
        !empty(
        $request["is_owner"]
    )
        &&
        !empty(
        $request["survey_submitted"]
    );
}

/*
 * Users may modify their own requests while the workflow is open.
 * Jobs: Pending Review / Under Review / For Revision.
 * Publications: For Review / In Preparation / For Approval.
 * Completed, Published, Closed (and other terminal statuses)
 * are locked.
 */
function canEditRequest(
    array $request
): bool {
    $source =
        (string) ($request["source"] ?? "");

    $status =
        (string) ($request["status"] ?? "");

    if ($source === "job") {
        return
            !empty(
            $request["is_owner"]
        )
            &&
            in_array(
                $status,
                [
                    "Pending Review",
                    "Under Review",
                    "For Revision"
                ],
                true
            );
    }

    if (
        $source === "event"
        ||
        $source === "recognition"
        ||
        $source === "contribution"
    ) {
        return
            !empty(
            $request["is_owner"]
        )
            &&
            in_array(
                $status,
                [
                    "For Review",
                    "In Preparation",
                    "For Approval"
                ],
                true
            );
    }

    return false;
}

/*
 * HPU staff / supervisor / admin may archive any visible job request
 * directly from My Requests. This mirrors staff/requests.php where the
 * same three roles see the "Archive request" action. Publications are
 * excluded — only job_requests rows are archived.
 */
function canArchiveJobRequest(
    array $request,
    bool $isHpuArchiver
): bool {
    if (!$isHpuArchiver) {
        return false;
    }

    return
        (string) ($request["source"] ?? "")
        ===
        "job";
}

/*
 * Publications use the same Client Experience survey once they
 * reach a terminal stage. Evaluating a Published submission
 * closes it, mirroring the job Completed -> Closed flow.
 */
function canEvaluatePublication(
    array $request
): bool {
    $source =
        (string) ($request["source"] ?? "");

    if (
        $source !== "event"
        &&
        $source !== "recognition"
        &&
        $source !== "contribution"
    ) {
        return false;
    }

    return
        in_array(
            (string) ($request["status"] ?? ""),
            [
                "Published",
                "Closed"
            ],
            true
        )
        &&
        !empty(
        $request["is_owner"]
    )
        &&
        empty(
        $request["survey_submitted"]
    );
}

function canViewPubEvaluation(
    array $request
): bool {
    $source =
        (string) ($request["source"] ?? "");

    return
        (
            $source === "event"
            ||
            $source === "recognition"
            ||
            $source === "contribution"
        )
        &&
        !empty(
        $request["is_owner"]
    )
        &&
        !empty(
        $request["survey_submitted"]
    );
}

function jobProgress(
    string $status
): int {
    return match ($status) {
        "Pending Review" => 10,
        "Under Review" => 25,
        "Approved" => 45,
        "In Development" => 70,
        "For Revision" => 35,
        "Disapproved" => 0,
        "Completed" => 88,
        "For Evaluation" => 94,
        "Closed" => 100,
        default => 5
    };
}

function jobStageIndex(
    string $status
): int {
    return match ($status) {
        "Pending Review" => 0,
        "Under Review", "For Revision" => 1,
        "Approved" => 2,
        "In Development" => 3,
        "Completed" => 4,
        "For Evaluation" => 5,
        "Closed" => 6,
        "Disapproved" => -1,
        default => 0
    };
}

function publicationProgress(
    string $status
): int {
    return match ($status) {
        "For Review" => 20,
        "In Preparation" => 45,
        "For Approval" => 70,
        "Published" => 92,
        "Closed" => 100,
        default => 10
    };
}

function publicationStageIndex(
    string $status
): int {
    return match ($status) {
        "For Review" => 1,
        "In Preparation" => 2,
        "For Approval" => 3,
        "Published" => 4,
        "Closed" => 5,
        default => 0
    };
}

/*
|--------------------------------------------------------------------------
| Job Request Processing Lead Time
|--------------------------------------------------------------------------
| Processing starts only after supervisor approval.
|
| Minimum lead time (working days only — Mon to Fri,
| weekends and blocked HPU dates are NOT counted):
| - Graphic Design / Video Production: 5 working days
| - Photo/Video Coverage / IEC Materials: 3 working days
|
| When more than one service type is selected, the longest applicable
| minimum lead time is used.
|
| The Processing Target itself is always snapped forward to the next
| working, unblocked day — it is never placed on a weekend or a
| blocked HPU date.
|--------------------------------------------------------------------------
*/

function jobRequestTypes(
    mixed $value
): array {
    if (is_array($value)) {
        return array_values(
            array_filter(
                array_map(
                    static fn($item): string =>
                        trim((string) $item),
                    $value
                )
            )
        );
    }

    $raw =
        trim(
            (string) $value
        );

    if ($raw === "") {
        return [];
    }

    $decoded =
        json_decode(
            $raw,
            true
        );

    if (is_array($decoded)) {
        return array_values(
            array_filter(
                array_map(
                    static fn($item): string =>
                        trim((string) $item),
                    $decoded
                )
            )
        );
    }

    /*
     * Backward-compatible fallback for older rows stored as
     * comma-separated values.
     */
    return array_values(
        array_filter(
            array_map(
                "trim",
                preg_split(
                    '/[,|]+/',
                    $raw
                )
                ?: []
            )
        )
    );
}

function jobLeadDays(
    mixed $requestTypes
): int {
    $types =
        jobRequestTypes(
            $requestTypes
        );

    $leadDays = 0;

    foreach ($types as $type) {
        $normalized =
            mb_strtolower(
                trim(
                    $type
                )
            );

        if (
            str_contains(
                $normalized,
                "graphic design"
            )
            ||
            str_contains(
                $normalized,
                "video production"
            )
        ) {
            $leadDays =
                max(
                    $leadDays,
                    5
                );

            continue;
        }

        if (
            str_contains(
                $normalized,
                "photo/video coverage"
            )
            ||
            str_contains(
                $normalized,
                "photo / video coverage"
            )
            ||
            str_contains(
                $normalized,
                "iec materials"
            )
        ) {
            $leadDays =
                max(
                    $leadDays,
                    3
                );
        }
    }

    return $leadDays;
}

function jobServicesForAvailability(
    mixed $requestTypes
): array {
    $types =
        jobRequestTypes(
            $requestTypes
        );

    $services = [];

    foreach ($types as $type) {
        $normalized =
            mb_strtolower(
                trim(
                    (string) $type
                )
            );

        if (
            str_contains(
                $normalized,
                "graphic design"
            )
        ) {
            $services[] =
                "Graphic Design";

            continue;
        }

        if (
            str_contains(
                $normalized,
                "video production"
            )
        ) {
            $services[] =
                "Video Production";

            continue;
        }

        if (
            str_contains(
                $normalized,
                "photo"
            )
            &&
            str_contains(
                $normalized,
                "coverage"
            )
        ) {
            $services[] =
                "Photo/Video Coverage";

            continue;
        }

        if (
            str_contains(
                $normalized,
                "iec"
            )
        ) {
            $services[] =
                "IEC Materials";
        }
    }

    return array_values(
        array_unique(
            $services
        )
    );
}

function jobIsWorkingDayLocal(
    string $ymd
): bool {
    if (function_exists("hpuIsWorkingDay")) {
        return hpuIsWorkingDay($ymd);
    }

    $dow =
        (int) date(
            "N",
            strtotime($ymd)
        );

    return $dow >= 1 && $dow <= 5;
}

function jobIsBlockedDayLocal(
    $conn,
    string $ymd,
    array $services
): bool {
    if (
        $conn instanceof mysqli
        &&
        function_exists("hpuAvailabilityFindBlock")
        &&
        !empty($services)
    ) {
        try {
            return hpuAvailabilityFindBlock(
                $conn,
                $ymd,
                $services
            ) !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    return false;
}

function jobSnapToWorkingDay(
    $conn,
    string $ymd,
    array $services
): string {
    $cur =
        substr(
            trim($ymd),
            0,
            10
        );

    for ($i = 0; $i < 730; $i++) {
        if (
            !jobIsWorkingDayLocal($cur)
            ||
            jobIsBlockedDayLocal(
                $conn,
                $cur,
                $services
            )
        ) {
            $cur =
                date(
                    "Y-m-d",
                    strtotime($cur . " +1 day")
                );

            continue;
        }

        return $cur;
    }

    return $cur;
}

function jobAddWorkingDays(
    $conn,
    string $baseYmd,
    int $leadDays,
    array $services
): ?string {
    $base =
        substr(
            trim($baseYmd),
            0,
            10
        );

    if (
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $base
        )
    ) {
        return null;
    }

    /*
     * Prefer the shared HPU helper so the counting matches
     * new_request / save_request (working days only, blocked
     * HPU dates do not count, counting starts day AFTER base).
     */
    if (
        $conn instanceof mysqli
        &&
        function_exists("hpuEarliestAvailableDate")
        &&
        $leadDays > 0
    ) {
        try {
            $viaHelper =
                hpuEarliestAvailableDate(
                    $conn,
                    $base,
                    $leadDays,
                    $services
                );

            if (is_string($viaHelper) && $viaHelper !== "") {
                return jobSnapToWorkingDay(
                    $conn,
                    substr($viaHelper, 0, 10),
                    $services
                );
            }
        } catch (Throwable $e) {
            // Fall through to local working-day counting below.
        }
    }

    if ($leadDays <= 0) {
        return jobSnapToWorkingDay(
            $conn,
            $base,
            $services
        );
    }

    $cur = $base;
    $counted = 0;

    for ($i = 0; $i < 730; $i++) {
        $cur =
            date(
                "Y-m-d",
                strtotime($cur . " +1 day")
            );

        if (!jobIsWorkingDayLocal($cur)) {
            continue;
        }

        if (
            jobIsBlockedDayLocal(
                $conn,
                $cur,
                $services
            )
        ) {
            continue;
        }

        $counted++;

        if ($counted >= $leadDays) {
            return $cur;
        }
    }

    return null;
}

function jobProcessingTarget(
    ?string $preferredDate,
    ?string $approvedDate,
    int $leadDays,
    $conn = null,
    mixed $requestTypes = null
): ?string {
    if (!$approvedDate) {
        return null;
    }

    try {
        $approvedYmd =
            substr(
                trim((string) $approvedDate),
                0,
                10
            );

        $approvedCheck =
            DateTime::createFromFormat(
                "!Y-m-d",
                $approvedYmd
            );

        if (
            !$approvedCheck
            ||
            $approvedCheck->format("Y-m-d") !== $approvedYmd
        ) {
            return null;
        }

        $services =
            jobServicesForAvailability(
                $requestTypes
            );

        /*
         * Minimum target = approved date + leadDays WORKING days.
         * Weekends are skipped and blocked HPU dates do not count.
         * Counting starts the day AFTER approval.
         */
        $minimumTarget =
            jobAddWorkingDays(
                $conn,
                $approvedYmd,
                $leadDays,
                $services
            );

        if ($minimumTarget === null) {
            return null;
        }

        /*
         * Use the requestor's preferred completion date when it is later
         * than the minimum processing target. The HPU target can therefore
         * never be earlier than the approved-date lead-time requirement.
         * The final target is always snapped forward so it never lands
         * on a weekend or a blocked HPU date.
         */
        if ($preferredDate) {
            $preferredYmd =
                substr(
                    trim((string) $preferredDate),
                    0,
                    10
                );

            $preferredCheck =
                DateTime::createFromFormat(
                    "!Y-m-d",
                    $preferredYmd
                );

            if (
                $preferredCheck
                &&
                $preferredCheck->format("Y-m-d") === $preferredYmd
                &&
                $preferredYmd > $minimumTarget
            ) {
                return jobSnapToWorkingDay(
                    $conn,
                    $preferredYmd,
                    $services
                );
            }
        }

        return $minimumTarget;
    } catch (Throwable $e) {
        return null;
    }
}

function requestDueInfo(
    ?string $date,
    string $status,
    string $source,
    ?string $approvedDate = null,
    int $leadDays = 0,
    $conn = null,
    mixed $requestTypes = null
): array {

    /*
    |--------------------------------------------------------------------------
    | Job Request deadline rule
    |--------------------------------------------------------------------------
    | The HPU processing clock starts only after supervisor approval.
    |
    | Pending Review / Under Review / For Revision:
    | - Never show overdue.
    | - Never start the lead-time countdown.
    |
    | Approved / In Development:
    | - Calculate minimum processing target from approved_date + lead
    |   WORKING days only (Mon–Fri, blocked HPU dates do not count).
    | - If the requestor's preferred completion date is later, use that
    |   later date as the effective target (snapped forward to the next
    |   working, unblocked day).
    | - The final Processing Target never lands on a weekend/blocked date.
    |--------------------------------------------------------------------------
    */
    if ($source === "job") {

        if (
            in_array(
                $status,
                [
                    "Pending Review",
                    "Under Review",
                    "For Revision"
                ],
                true
            )
        ) {
            return [
                "text" =>
                    "Awaiting supervisor approval — processing lead time has not started.",
                "class" => "due-muted",
                "icon" => "bi-hourglass-split",
                "target_date" => null
            ];
        }

        if ($status === "Disapproved") {
            return [
                "text" => "Request disapproved",
                "class" => "due-muted",
                "icon" => "bi-x-circle",
                "target_date" => null
            ];
        }

        if (
            in_array(
                $status,
                [
                    "Completed",
                    "For Evaluation",
                    "Closed"
                ],
                true
            )
        ) {
            return match ($status) {
                "Completed" => [
                    "text" => "Waiting for evaluation",
                    "class" => "due-info",
                    "icon" => "bi-chat-square-text",
                    "target_date" => null
                ],

                "For Evaluation" => [
                    "text" => "Evaluation pending",
                    "class" => "due-warning",
                    "icon" => "bi-clipboard2-check",
                    "target_date" => null
                ],

                default => [
                    "text" => "Request closed",
                    "class" => "due-success",
                    "icon" => "bi-check-circle-fill",
                    "target_date" => null
                ]
            };
        }

        /*
         * A status may already say Approved while approved_date has not
         * yet been written because of old/legacy data. Do not punish the
         * requestor/HPU by starting an overdue clock without an approval
         * timestamp.
         */
        if (!$approvedDate) {
            return [
                "text" =>
                    "Approved status recorded — waiting for approval date before the processing countdown starts.",
                "class" => "due-muted",
                "icon" => "bi-calendar2-check",
                "target_date" => null
            ];
        }

        $effectiveTarget =
            jobProcessingTarget(
                $date,
                $approvedDate,
                $leadDays,
                $conn,
                $requestTypes
            );

        if (!$effectiveTarget) {
            return [
                "text" => "Processing target unavailable",
                "class" => "due-muted",
                "icon" => "bi-calendar-x",
                "target_date" => null
            ];
        }

        try {
            $today =
                new DateTime(
                    "today"
                );

            $due =
                new DateTime(
                    $effectiveTarget
                );

            $due->setTime(
                0,
                0,
                0
            );

            $days =
                (int) 
                $today
                    ->diff(
                        $due
                    )
                    ->format(
                        "%r%a"
                    );
        } catch (Throwable $e) {
            return [
                "text" => "Processing target unavailable",
                "class" => "due-muted",
                "icon" => "bi-calendar-x",
                "target_date" => null
            ];
        }

        if ($days < 0) {
            return [
                "text" =>
                    "Overdue by "
                    . abs(
                        $days
                    )
                    . " day(s)",
                "class" => "due-danger",
                "icon" => "bi-exclamation-triangle-fill",
                "target_date" => $effectiveTarget
            ];
        }

        if ($days === 0) {
            return [
                "text" => "Processing target today",
                "class" => "due-warning",
                "icon" => "bi-alarm",
                "target_date" => $effectiveTarget
            ];
        }

        if ($days === 1) {
            return [
                "text" => "Processing target tomorrow",
                "class" => "due-warning",
                "icon" => "bi-clock",
                "target_date" => $effectiveTarget
            ];
        }

        return [
            "text" =>
                $days
                . " days remaining",
            "class" => "due-success",
            "icon" => "bi-calendar-check",
            "target_date" => $effectiveTarget
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Publication preferred-date behavior
    |--------------------------------------------------------------------------
    */
    if ($status === "Published") {
        return [
            "text" => "Published",
            "class" => "due-success",
            "icon" => "bi-megaphone-fill",
            "target_date" => null
        ];
    }

    if ($status === "Closed") {
        return [
            "text" => "Publication closed",
            "class" => "due-success",
            "icon" => "bi-check-circle-fill",
            "target_date" => null
        ];
    }

    if (!$date) {
        return [
            "text" => "No preferred publication date",
            "class" => "due-muted",
            "icon" => "bi-calendar-minus",
            "target_date" => null
        ];
    }

    try {
        $today =
            new DateTime(
                "today"
            );

        $due =
            new DateTime(
                $date
            );

        $days =
            (int) 
            $today
                ->diff(
                    $due
                )
                ->format(
                    "%r%a"
                );
    } catch (Throwable $e) {
        return [
            "text" => "Date unavailable",
            "class" => "due-muted",
            "icon" => "bi-calendar-x",
            "target_date" => null
        ];
    }

    if ($days < 0) {
        return [
            "text" =>
                "Preferred date passed by "
                . abs(
                    $days
                )
                . " day(s)",
            "class" => "due-danger",
            "icon" => "bi-exclamation-triangle-fill",
            "target_date" => $date
        ];
    }

    if ($days === 0) {
        return [
            "text" => "Preferred publication today",
            "class" => "due-warning",
            "icon" => "bi-alarm",
            "target_date" => $date
        ];
    }

    if ($days === 1) {
        return [
            "text" => "Preferred publication tomorrow",
            "class" => "due-warning",
            "icon" => "bi-clock",
            "target_date" => $date
        ];
    }

    return [
        "text" =>
            $days
            . " days to preferred date",
        "class" => "due-success",
        "icon" => "bi-calendar-check",
        "target_date" => $date
    ];
}


function publicationTypesLabel(
    ?string $value
): string {
    $value =
        trim(
            (string) 
            $value
        );

    if ($value === "") {
        return
            "Recognition / Milestone";
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

    return
        $value;
}

/*
|--------------------------------------------------------------------------
| Ensure Publication Workflow Columns
|--------------------------------------------------------------------------
*/

foreach (
    [
        "publication_event_submissions",
        "publication_recognition_submissions"
    ]
    as $table
) {
    $tableCheck =
        $conn->query(
            "SHOW TABLES LIKE '"
            . $conn->real_escape_string(
                $table
            )
            . "'"
        );

    if (
        !$tableCheck
        ||
        $tableCheck->num_rows === 0
    ) {
        continue;
    }

    $columns = [
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
        $columns
        as $column =>
        $definition
    ) {
        if (
            !myReqColumnExists(
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
}

/*
|--------------------------------------------------------------------------
| Public tracking tokens for publications (same track.php?token= system)
|--------------------------------------------------------------------------
*/

function myReqEnsurePubTokenColumn(
    mysqli $conn,
    string $table
): void {
    if (
        !myReqTableExists($conn, $table) ||
        myReqColumnExists($conn, $table, "public_token")
    ) {
        return;
    }

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

function myReqPubToken(
    mysqli $conn,
    string $table,
    int $id
): string {
    if (!myReqColumnExists($conn, $table, "public_token")) {
        return "";
    }

    try {
        $stmt = $conn->prepare(
            "SELECT public_token FROM `{$table}` WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $token = trim((string) ($row["public_token"] ?? ""));

        if (strlen($token) === 64 && ctype_xdigit($token)) {
            return $token;
        }
    } catch (Throwable $e) {
        return "";
    }

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

function myReqPubTrackUrl(
    mysqli $conn,
    string $table,
    int $id
): string {
    $token = myReqPubToken($conn, $table, $id);

    if (strlen($token) !== 64 || !ctype_xdigit($token)) {
        return "";
    }

    return rtrim(BASE_URL, "/") . "/track.php?token=" . rawurlencode($token);
}

foreach (
    [
        "publication_event_submissions",
        "publication_recognition_submissions",
        "publication_content_contributions",
    ] as $pubTokenTable
) {
    myReqEnsurePubTokenColumn($conn, $pubTokenTable);
}

/*
|--------------------------------------------------------------------------
| Client Experience Survey Availability
|--------------------------------------------------------------------------
*/

$surveyTableResult =
    $conn->query(
        "SHOW TABLES LIKE 'job_request_client_experience'"
    );

$surveyTableReady =
    $surveyTableResult
    &&
    $surveyTableResult->num_rows > 0;

$pubSurveyReady = false;

if ($surveyTableReady) {
    $pubSurveyColumns =
        $conn->query(
            "SHOW COLUMNS
              FROM job_request_client_experience
              LIKE 'publication_kind'"
        );

    $pubSurveyReady =
        $pubSurveyColumns
        &&
        $pubSurveyColumns->num_rows > 0;
}

/*
|--------------------------------------------------------------------------
| Job Requests
|--------------------------------------------------------------------------
*/

$jobSql =
    "SELECT
        jr.id,
        jr.job_no,
        jr.project_title,
        jr.date_request,
        jr.date_completion,
        jr.request_types,
        jr.approval_status,
        jr.satisfaction_rating,
        jr.reviewed_by,
        jr.reviewed_date,
        jr.approved_by,
        jr.approved_date,
        jr.created_at,
        jr.requestor AS owner_id,
        jr.department AS typed_department,
        req.department AS requestor_department,
        req.name AS owner_name";

if ($surveyTableReady) {
    $jobSql .=
        ",
        CASE
            WHEN ce.request_id IS NOT NULL
            THEN 1
            ELSE 0
        END AS survey_submitted,
        ce.submitted_at AS survey_submitted_at,
        ce.overall_satisfaction AS survey_overall_rating";

    $jobSql .=
        "
        FROM job_requests jr
        LEFT JOIN users req
            ON req.id = jr.requestor
        LEFT JOIN job_request_client_experience ce
            ON ce.request_id = jr.id";
} else {
    $jobSql .=
        ",
        0 AS survey_submitted,
        NULL AS survey_submitted_at,
        NULL AS survey_overall_rating
        FROM job_requests jr
        LEFT JOIN users req
            ON req.id = jr.requestor";
}

if ($userDepartment !== "") {
    $jobSql .=
        "
        WHERE (
            jr.requestor = ?
            OR LOWER(TRIM(jr.department)) = LOWER(TRIM(?))
            OR LOWER(TRIM(req.department)) = LOWER(TRIM(?))
        )
        ORDER BY jr.id DESC";

    $stmt =
        $conn->prepare(
            $jobSql
        );

    $stmt->bind_param(
        "iss",
        $userId,
        $userDepartment,
        $userDepartment
    );
} else {
    $jobSql .=
        "
        WHERE jr.requestor = ?
        ORDER BY jr.id DESC";

    $stmt =
        $conn->prepare(
            $jobSql
        );

    $stmt->bind_param(
        "i",
        $userId
    );
}

$stmt->execute();

$jobRows =
    $stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

$stmt->close();

/*
|--------------------------------------------------------------------------
| Normalize All Requests
|--------------------------------------------------------------------------
*/

$allRequests = [];

foreach (
    $jobRows
    as $row
) {
    $jobIsOwner =
        (int) ($row["owner_id"] ?? $row["requestor"] ?? 0)
        ===
        $userId;

    $jobSameOffice =
        !$jobIsOwner
        &&
        myReqDeptMatch(
            $row["typed_department"] ?? null,
            $row["requestor_department"] ?? null,
            $userDepartment
        );

    $allRequests[] = [
        "source" => "job",
        "id" => (int) $row["id"],
        "code" => (string) $row["job_no"],
        "title" => (string) $row["project_title"],
        "type_label" => "HPU Job Request",
        "date_request" => $row["date_request"],
        "preferred_date" => $row["date_completion"],
        "approved_date" =>
            $row["approved_date"]
            ?? null,
        "request_types" =>
            $row["request_types"]
            ?? null,
        "lead_days" =>
            jobLeadDays(
                $row["request_types"]
                ?? null
            ),
        "status" => (string) $row["approval_status"],
        "created_at" => (string) $row["created_at"],
        "satisfaction_rating" => $row["satisfaction_rating"],

        "survey_submitted" =>
            (int) (
                $row["survey_submitted"]
                ?? 0
            ),

        "survey_submitted_at" =>
            $row["survey_submitted_at"]
            ?? null,

        "survey_overall_rating" =>
            $row["survey_overall_rating"]
            ?? null,

        "survey_url" =>
            $jobIsOwner
            ? rtrim(
                BASE_URL,
                "/"
            )
            . "/user/client_experience_survey.php?id="
            . (int) $row["id"]
            : "",

        "view_url" =>
            rtrim(
                BASE_URL,
                "/"
            )
            . "/admin/view.php?id="
            . (int) $row["id"],

        "print_url" =>
            $jobIsOwner
            ? rtrim(
                BASE_URL,
                "/"
            )
            . "/user/print_request.php?id="
            . (int) $row["id"]
            : "",

        "is_owner" =>
            $jobIsOwner
            ? 1
            : 0,

        "same_office" =>
            $jobSameOffice
            ? 1
            : 0,

        "submitted_by" =>
            (int) ($row["owner_id"] ?? 0),

        "submitted_name" =>
            (string) (
                $row["owner_name"]
                ?? ""
            ),

        "office_name" =>
            (string) (
                $row["typed_department"]
                ?? $row["requestor_department"]
                ?? ""
            )
    ];
}

/*
|--------------------------------------------------------------------------
| Event / Activity Publication Submissions
|--------------------------------------------------------------------------
*/

$tableResult =
    $conn->query(
        "SHOW TABLES LIKE 'publication_event_submissions'"
    );

if (
    $tableResult
    &&
    $tableResult->num_rows > 0
) {
    $eventSql =
        $pubSurveyReady
        ? "SELECT
                    p.id,
                    p.title,
                    p.activity_type,
                    p.preferred_publication_date,
                    p.status,
                    p.created_at,
                    p.submitted_by AS owner_id,
                    p.lead_office AS typed_office,
                    su.department AS submitter_department,
                    su.name AS submitter_name,
                    CASE
                        WHEN ce.id IS NOT NULL
                        THEN 1
                        ELSE 0
                    END AS survey_submitted,
                    ce.submitted_at AS survey_submitted_at,
                    ce.overall_satisfaction AS survey_overall_rating
                FROM publication_event_submissions p
                LEFT JOIN users su
                    ON su.id = p.submitted_by
                LEFT JOIN job_request_client_experience ce
                    ON ce.publication_kind = 'event'
                    AND ce.publication_id = p.id
                WHERE p.submitted_by = ?"
        : "SELECT
                    p.id,
                    p.title,
                    p.activity_type,
                    p.preferred_publication_date,
                    p.status,
                    p.created_at,
                    p.submitted_by AS owner_id,
                    p.lead_office AS typed_office,
                    su.department AS submitter_department,
                    su.name AS submitter_name,
                    0 AS survey_submitted,
                    NULL AS survey_submitted_at,
                    NULL AS survey_overall_rating
                FROM publication_event_submissions p
                LEFT JOIN users su
                    ON su.id = p.submitted_by
                WHERE p.submitted_by = ?";

    if ($userDepartment !== "") {
        $eventSql .=
            " OR (
                LOWER(TRIM(p.lead_office)) = LOWER(TRIM(?))
                OR LOWER(TRIM(su.department)) = LOWER(TRIM(?))
            )";
    }

    $eventSql .= " ORDER BY p.id DESC";

    if ($userDepartment !== "") {
        $stmt =
            $conn->prepare(
                $eventSql
            );

        $stmt->bind_param(
            "iss",
            $userId,
            $userDepartment,
            $userDepartment
        );
    } else {
        $stmt =
            $conn->prepare(
                $eventSql
            );

        $stmt->bind_param(
            "i",
            $userId
        );
    }

    $stmt->execute();

    $eventRows =
        $stmt
            ->get_result()
            ->fetch_all(
                MYSQLI_ASSOC
            );

    $stmt->close();

    foreach (
        $eventRows
        as $row
    ) {
        $eventIsOwner =
            (int) ($row["owner_id"] ?? 0)
            ===
            $userId;

        $eventSameOffice =
            !$eventIsOwner
            &&
            myReqDeptMatch(
                $row["typed_office"] ?? null,
                $row["submitter_department"] ?? null,
                $userDepartment
            );

        $allRequests[] = [
            "source" => "event",
            "id" => (int) $row["id"],
            "code" =>
                publicationDisplayCode("event", (int) $row["id"]),

            "title" => (string) $row["title"],
            "type_label" =>
                (string) (
                    $row["activity_type"]
                    ?: "Event / Activity"
                ),

            "date_request" =>
                substr(
                    (string) $row["created_at"],
                    0,
                    10
                ),

            "preferred_date" =>
                $row["preferred_publication_date"],

            "approved_date" => null,
            "request_types" => null,
            "lead_days" => 0,

            "status" =>
                (string) (
                    $row["status"]
                    ?: "For Review"
                ),

            "created_at" =>
                (string) $row["created_at"],

            "satisfaction_rating" =>
                null,

            "survey_submitted" =>
                (int) (
                    $row["survey_submitted"]
                    ?? 0
                ),

            "survey_submitted_at" =>
                $row["survey_submitted_at"]
                ?? null,

            "survey_overall_rating" =>
                $row["survey_overall_rating"]
                ?? null,

            "survey_url" =>
                $eventIsOwner
                ? rtrim(
                    BASE_URL,
                    "/"
                )
                . "/user/client_experience_survey.php?kind=event&id="
                . (int) $row["id"]
                : "",

            "view_url" =>
                rtrim(
                    BASE_URL,
                    "/"
                )
                . "/user/view_publication.php?kind=event&id="
                . (int) $row["id"],

            "track_url" =>
                myReqPubTrackUrl(
                    $conn,
                    "publication_event_submissions",
                    (int) $row["id"]
                ),

            "print_url" =>
                "",

            "is_owner" =>
                $eventIsOwner
                ? 1
                : 0,

            "same_office" =>
                $eventSameOffice
                ? 1
                : 0,

            "submitted_by" =>
                (int) ($row["owner_id"] ?? 0),

            "submitted_name" =>
                (string) (
                    $row["submitter_name"]
                    ?? ""
                ),

            "office_name" =>
                (string) (
                    $row["typed_office"]
                    ?? $row["submitter_department"]
                    ?? ""
                )
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Recognition / Milestone Publication Submissions
|--------------------------------------------------------------------------
*/

$tableResult =
    $conn->query(
        "SHOW TABLES LIKE 'publication_recognition_submissions'"
    );

if (
    $tableResult
    &&
    $tableResult->num_rows > 0
) {
    $recognitionSql =
        $pubSurveyReady
        ? "SELECT
                    p.id,
                    p.recognition_type,
                    p.recognized_entity,
                    p.preferred_publication_date,
                    p.status,
                    p.created_at,
                    p.submitted_by AS owner_id,
                    su.department AS submitter_department,
                    su.name AS submitter_name,
                    CASE
                        WHEN ce.id IS NOT NULL
                        THEN 1
                        ELSE 0
                    END AS survey_submitted,
                    ce.submitted_at AS survey_submitted_at,
                    ce.overall_satisfaction AS survey_overall_rating
                FROM publication_recognition_submissions p
                LEFT JOIN users su
                    ON su.id = p.submitted_by
                LEFT JOIN job_request_client_experience ce
                    ON ce.publication_kind = 'recognition'
                    AND ce.publication_id = p.id
                WHERE p.submitted_by = ?"
        : "SELECT
                    p.id,
                    p.recognition_type,
                    p.recognized_entity,
                    p.preferred_publication_date,
                    p.status,
                    p.created_at,
                    p.submitted_by AS owner_id,
                    su.department AS submitter_department,
                    su.name AS submitter_name,
                    0 AS survey_submitted,
                    NULL AS survey_submitted_at,
                    NULL AS survey_overall_rating
                FROM publication_recognition_submissions p
                LEFT JOIN users su
                    ON su.id = p.submitted_by
                WHERE p.submitted_by = ?";

    if ($userDepartment !== "") {
        $recognitionSql .=
            " OR LOWER(TRIM(su.department)) = LOWER(TRIM(?))";
    }

    $recognitionSql .= " ORDER BY p.id DESC";

    if ($userDepartment !== "") {
        $stmt =
            $conn->prepare(
                $recognitionSql
            );

        $stmt->bind_param(
            "is",
            $userId,
            $userDepartment
        );
    } else {
        $stmt =
            $conn->prepare(
                $recognitionSql
            );

        $stmt->bind_param(
            "i",
            $userId
        );
    }

    $stmt->execute();

    $recognitionRows =
        $stmt
            ->get_result()
            ->fetch_all(
                MYSQLI_ASSOC
            );

    $stmt->close();

    foreach (
        $recognitionRows
        as $row
    ) {
        $recognitionIsOwner =
            (int) ($row["owner_id"] ?? 0)
            ===
            $userId;

        $recognitionSameOffice =
            !$recognitionIsOwner
            &&
            myReqDeptMatch(
                null,
                $row["submitter_department"] ?? null,
                $userDepartment
            );

        $allRequests[] = [
            "source" => "recognition",
            "id" => (int) $row["id"],
            "code" =>
                publicationDisplayCode("recognition", (int) $row["id"]),

            "title" =>
                (string) $row["recognized_entity"],

            "type_label" =>
                publicationTypesLabel(
                    $row["recognition_type"]
                    ?? null
                ),

            "date_request" =>
                substr(
                    (string) $row["created_at"],
                    0,
                    10
                ),

            "preferred_date" =>
                $row["preferred_publication_date"],

            "approved_date" => null,
            "request_types" => null,
            "lead_days" => 0,

            "status" =>
                (string) (
                    $row["status"]
                    ?: "For Review"
                ),

            "created_at" =>
                (string) $row["created_at"],

            "satisfaction_rating" =>
                null,

            "survey_submitted" =>
                (int) (
                    $row["survey_submitted"]
                    ?? 0
                ),

            "survey_submitted_at" =>
                $row["survey_submitted_at"]
                ?? null,

            "survey_overall_rating" =>
                $row["survey_overall_rating"]
                ?? null,

            "survey_url" =>
                $recognitionIsOwner
                ? rtrim(
                    BASE_URL,
                    "/"
                )
                . "/user/client_experience_survey.php?kind=recognition&id="
                . (int) $row["id"]
                : "",

            "view_url" =>
                rtrim(
                    BASE_URL,
                    "/"
                )
                . "/user/view_publication.php?kind=recognition&id="
                . (int) $row["id"],

            "track_url" =>
                myReqPubTrackUrl(
                    $conn,
                    "publication_recognition_submissions",
                    (int) $row["id"]
                ),

            "print_url" =>
                "",

            "is_owner" =>
                $recognitionIsOwner
                ? 1
                : 0,

            "same_office" =>
                $recognitionSameOffice
                ? 1
                : 0,

            "submitted_by" =>
                (int) ($row["owner_id"] ?? 0),

            "submitted_name" =>
                (string) (
                    $row["submitter_name"]
                    ?? ""
                ),

            "office_name" =>
                (string) (
                    $row["submitter_department"]
                    ?? ""
                )
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Content Contribution Publication Submissions
|--------------------------------------------------------------------------
*/

$tableResult =
    $conn->query(
        "SHOW TABLES LIKE 'publication_content_contributions'"
    );

if (
    $tableResult
    &&
    $tableResult->num_rows > 0
) {
    $contributionSql =
        $pubSurveyReady
        ? "SELECT
                    p.id,
                    p.contribution_type,
                    p.publication_channel,
                    p.content_title,
                    p.lead_office,
                    p.preferred_publication_date,
                    p.status,
                    p.created_at,
                    p.submitted_by AS owner_id,
                    p.lead_office AS typed_office,
                    su.department AS submitter_department,
                    su.name AS submitter_name,
                    CASE
                        WHEN ce.id IS NOT NULL
                        THEN 1
                        ELSE 0
                    END AS survey_submitted,
                    ce.submitted_at AS survey_submitted_at,
                    ce.overall_satisfaction AS survey_overall_rating
                FROM publication_content_contributions p
                LEFT JOIN users su
                    ON su.id = p.submitted_by
                LEFT JOIN job_request_client_experience ce
                    ON ce.publication_kind = 'contribution'
                    AND ce.publication_id = p.id
                WHERE p.submitted_by = ?"
        : "SELECT
                    p.id,
                    p.contribution_type,
                    p.publication_channel,
                    p.content_title,
                    p.lead_office,
                    p.preferred_publication_date,
                    p.status,
                    p.created_at,
                    p.submitted_by AS owner_id,
                    p.lead_office AS typed_office,
                    su.department AS submitter_department,
                    su.name AS submitter_name,
                    0 AS survey_submitted,
                    NULL AS survey_submitted_at,
                    NULL AS survey_overall_rating
                FROM publication_content_contributions p
                LEFT JOIN users su
                    ON su.id = p.submitted_by
                WHERE p.submitted_by = ?";

    if ($userDepartment !== "") {
        $contributionSql .=
            " OR (
                LOWER(TRIM(p.lead_office)) = LOWER(TRIM(?))
                OR LOWER(TRIM(su.department)) = LOWER(TRIM(?))
            )";
    }

    $contributionSql .= " ORDER BY p.id DESC";

    if ($userDepartment !== "") {
        $stmt =
            $conn->prepare(
                $contributionSql
            );

        $stmt->bind_param(
            "iss",
            $userId,
            $userDepartment,
            $userDepartment
        );
    } else {
        $stmt =
            $conn->prepare(
                $contributionSql
            );

        $stmt->bind_param(
            "i",
            $userId
        );
    }

    $stmt->execute();

    $contributionRows =
        $stmt
            ->get_result()
            ->fetch_all(
                MYSQLI_ASSOC
            );

    $stmt->close();

    foreach (
        $contributionRows
        as $row
    ) {
        $contributionIsOwner =
            (int) ($row["owner_id"] ?? 0)
            ===
            $userId;

        $contributionSameOffice =
            !$contributionIsOwner
            &&
            myReqDeptMatch(
                $row["typed_office"] ?? null,
                $row["submitter_department"] ?? null,
                $userDepartment
            );

        $contributionTypeLabel =
            trim((string) ($row["contribution_type"] ?? ""));

        if ($contributionTypeLabel === "") {
            $contributionTypeLabel = "Content Contribution";
        }

        if (
            trim((string) ($row["publication_channel"] ?? "")) !== ""
        ) {
            $contributionTypeLabel .=
                " • " . $row["publication_channel"];
        }

        $allRequests[] = [
            "source" => "contribution",
            "id" => (int) $row["id"],
            "code" =>
                publicationDisplayCode("contribution", (int) $row["id"]),

            "title" => (string) $row["content_title"],
            "type_label" => $contributionTypeLabel,

            "date_request" =>
                substr(
                    (string) $row["created_at"],
                    0,
                    10
                ),

            "preferred_date" =>
                $row["preferred_publication_date"],

            "approved_date" => null,
            "request_types" => null,
            "lead_days" => 0,

            "status" =>
                (string) (
                    $row["status"]
                    ?: "For Review"
                ),

            "created_at" =>
                (string) $row["created_at"],

            "satisfaction_rating" =>
                null,

            "survey_submitted" =>
                (int) (
                    $row["survey_submitted"]
                    ?? 0
                ),

            "survey_submitted_at" =>
                $row["survey_submitted_at"]
                ?? null,

            "survey_overall_rating" =>
                $row["survey_overall_rating"]
                ?? null,

            "survey_url" =>
                $contributionIsOwner
                ? rtrim(
                    BASE_URL,
                    "/"
                )
                . "/user/client_experience_survey.php?kind=contribution&id="
                . (int) $row["id"]
                : "",

            "view_url" =>
                rtrim(
                    BASE_URL,
                    "/"
                )
                . "/user/view_publication.php?kind=contribution&id="
                . (int) $row["id"],

            "track_url" =>
                myReqPubTrackUrl(
                    $conn,
                    "publication_content_contributions",
                    (int) $row["id"]
                ),

            "print_url" =>
                "",

            "is_owner" =>
                $contributionIsOwner
                ? 1
                : 0,

            "same_office" =>
                $contributionSameOffice
                ? 1
                : 0,

            "submitted_by" =>
                (int) ($row["owner_id"] ?? 0),

            "submitted_name" =>
                (string) (
                    $row["submitter_name"]
                    ?? ""
                ),

            "office_name" =>
                (string) (
                    $row["typed_office"]
                    ?? $row["submitter_department"]
                    ?? ""
                )
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Newest First Across ALL Request Types
|--------------------------------------------------------------------------
*/

usort(
    $allRequests,
    static function (array $a, array $b): int {
        return
            strcmp(
                (string) $b["created_at"],
                (string) $a["created_at"]
            );
    }
);

/*
|--------------------------------------------------------------------------
| Grouped Sections: My Requests first, Same-Office second.
| Newest-first order is preserved within each group.
|--------------------------------------------------------------------------
*/

$myRequestRows = [];
$sameOfficeRows = [];

foreach (
    $allRequests
    as $groupRow
) {
    if (
        !empty(
        $groupRow["same_office"]
    )
    ) {
        $sameOfficeRows[] =
            $groupRow;
    } else {
        $myRequestRows[] =
            $groupRow;
    }
}

$allRequests =
    array_merge(
        $myRequestRows,
        $sameOfficeRows
    );

$myRequestsCount =
    count(
        $myRequestRows
    );

$sameOfficeCount =
    count(
        $sameOfficeRows
    );

/*
|--------------------------------------------------------------------------
| Publication conversation unread counts (connected to view_publication)
|--------------------------------------------------------------------------
*/
$pubUnread = [];

try {
    $checkPubMsg = $conn->query("SHOW TABLES LIKE 'publication_messages'");

    if ($checkPubMsg && $checkPubMsg->num_rows > 0) {
        $msgStmt = $conn->prepare(
            "SELECT kind, publication_id, MAX(id) AS max_id, COUNT(*) AS total
             FROM publication_messages
             WHERE is_internal = 0
             GROUP BY kind, publication_id"
        );

        if ($msgStmt) {
            $msgStmt->execute();
            $msgRows = $msgStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $msgStmt->close();

            $readMap = [];
            $checkReads = $conn->query("SHOW TABLES LIKE 'publication_message_reads'");

            if ($checkReads && $checkReads->num_rows > 0) {
                $readStmt = $conn->prepare(
                    "SELECT kind, publication_id, last_read_message_id
                     FROM publication_message_reads
                     WHERE user_id = ?"
                );

                if ($readStmt) {
                    $readStmt->bind_param("i", $userId);
                    $readStmt->execute();
                    $readRows = $readStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $readStmt->close();

                    foreach ($readRows as $rr) {
                        $readMap[$rr["kind"] . ":" . (int) $rr["publication_id"]] =
                            (int) $rr["last_read_message_id"];
                    }
                }
            }

            foreach ($msgRows as $mr) {
                $key = $mr["kind"] . ":" . (int) $mr["publication_id"];
                $lastRead = $readMap[$key] ?? 0;
                $unread = (int) $mr["total"];

                if ($lastRead > 0) {
                    $cntStmt = $conn->prepare(
                        "SELECT COUNT(*) AS c FROM publication_messages
                         WHERE kind = ? AND publication_id = ?
                           AND is_internal = 0 AND id > ?"
                    );

                    if ($cntStmt) {
                        $cntStmt->bind_param(
                            "sii",
                            $mr["kind"],
                            $mr["publication_id"],
                            $lastRead
                        );
                        $cntStmt->execute();
                        $cntRow = $cntStmt->get_result()->fetch_assoc();
                        $cntStmt->close();
                        $unread = (int) ($cntRow["c"] ?? 0);
                    }
                }

                if ($unread > 0) {
                    $pubUnread[$key] = $unread;
                }
            }
        }
    }
} catch (Throwable $e) {
    $pubUnread = [];
}

/*
|--------------------------------------------------------------------------
| Job conversation unread counts (request_messages, non-internal only
| for requestors, excluding own messages)
|--------------------------------------------------------------------------
*/
$jobUnread = [];
$jobLatest = [];

try {
    $checkJobMsg = $conn->query("SHOW TABLES LIKE 'request_messages'");

    if ($checkJobMsg && $checkJobMsg->num_rows > 0) {
        $jobUnreadStmt = $conn->prepare(
            "SELECT m.request_id, COUNT(*) AS unread
             FROM request_messages m
             LEFT JOIN request_message_reads r
               ON r.request_id = m.request_id AND r.user_id = ?
             WHERE m.id > COALESCE(r.last_read_message_id, 0)
               AND m.sender_id <> ?
               AND m.is_internal = 0
             GROUP BY m.request_id"
        );

        if ($jobUnreadStmt) {
            $jobUnreadStmt->bind_param("ii", $userId, $userId);
            $jobUnreadStmt->execute();

            foreach (
                $jobUnreadStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $jr
            ) {
                $rid = (int) ($jr["request_id"] ?? 0);
                $cnt = (int) ($jr["unread"] ?? 0);

                if ($rid > 0 && $cnt > 0) {
                    $jobUnread[$rid] = $cnt;
                }
            }

            $jobUnreadStmt->close();
        }

        // Latest visible message per request — for ALL of this user's
        // visible requests (not just unread ones) so each request always
        // reflects its conversation, read or unread.
        $allJobIds = [];

        foreach ($allRequests as $ar) {
            if (
                ($ar["source"] ?? "") === "job"
                && (int) ($ar["id"] ?? 0) > 0
            ) {
                $allJobIds[(int) $ar["id"]] = true;
            }
        }

        if (!empty($allJobIds)) {
            $jobIds = implode(",", array_map("intval", array_keys($allJobIds)));

            $jobLatestRes = $conn->query(
                "SELECT m.request_id, m.sender_name, m.message, m.created_at
                 FROM request_messages m
                 INNER JOIN (
                   SELECT request_id, MAX(id) AS max_id
                   FROM request_messages
                   WHERE request_id IN ({$jobIds}) AND is_internal = 0
                   GROUP BY request_id
                 ) t ON t.request_id = m.request_id AND t.max_id = m.id"
            );

            if ($jobLatestRes) {
                while ($lr = $jobLatestRes->fetch_assoc()) {
                    $preview = trim((string) ($lr["message"] ?? ""));

                    if ($preview === "") {
                        $preview = "(attachment)";
                    } elseif (mb_strlen($preview) > 120) {
                        $preview = mb_substr($preview, 0, 120) . "…";
                    }

                    $jobLatest[(int) $lr["request_id"]] = [
                        "sender" => (string) ($lr["sender_name"] ?? ""),
                        "message" => $preview,
                        "created_at" => (string) ($lr["created_at"] ?? ""),
                    ];
                }
            }
        }
    }
} catch (Throwable $e) {
    $jobUnread = [];
    $jobLatest = [];
}

/*
|--------------------------------------------------------------------------
| Publication latest-message preview (for readable clickable snippet)
|--------------------------------------------------------------------------
*/
$pubLatest = [];

// Latest message for EVERY visible publication thread (read or unread)
// so each request always reflects its conversation.
$allPubKeys = [];

foreach ($allRequests as $ar) {
    $src = (string) ($ar["source"] ?? "");
    $pid = (int) ($ar["id"] ?? 0);

    if (
        in_array($src, ["event", "recognition", "contribution"], true)
        && $pid > 0
    ) {
        $allPubKeys[$src . ":" . $pid] = [$src, $pid];
    }
}

try {
    $checkPubLatest = $conn->query("SHOW TABLES LIKE 'publication_messages'");

    if ($checkPubLatest && $checkPubLatest->num_rows > 0 && !empty($allPubKeys)) {
        foreach ($allPubKeys as $pubKey => [$pubKind, $pubId]) {

            $latestStmt = $conn->prepare(
                "SELECT sender_name, message, created_at
                 FROM publication_messages
                 WHERE kind = ? AND publication_id = ? AND is_internal = 0
                 ORDER BY id DESC
                 LIMIT 1"
            );

            if (!$latestStmt) {
                continue;
            }

            $latestStmt->bind_param("si", $pubKind, $pubId);
            $latestStmt->execute();
            $latestRow = $latestStmt->get_result()->fetch_assoc();
            $latestStmt->close();

            if (!$latestRow) {
                continue;
            }

            $preview = trim((string) ($latestRow["message"] ?? ""));

            if ($preview === "") {
                $preview = "(attachment)";
            } elseif (mb_strlen($preview) > 120) {
                $preview = mb_substr($preview, 0, 120) . "…";
            }

            $pubLatest[$pubKey] = [
                "sender" => (string) ($latestRow["sender_name"] ?? ""),
                "message" => $preview,
                "created_at" => (string) ($latestRow["created_at"] ?? ""),
            ];
        }
    }
} catch (Throwable $e) {
    $pubLatest = [];
}

$msgTotalUnread = count($jobUnread) + count($pubUnread);

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$totalRequests =
    count(
        $allRequests
    );

$activeRequests = 0;
$completedRequests = 0;
$evaluationPending = 0;
$publicationRequests = 0;

foreach (
    $allRequests
    as $request
) {
    $status =
        $request["status"];

    if (
        $request["source"]
        ===
        "event"
        ||
        $request["source"]
        ===
        "recognition"
        ||
        $request["source"]
        ===
        "contribution"
    ) {
        $publicationRequests++;
    }

    if (
        !in_array(
            $status,
            [
                "Closed",
                "Disapproved"
            ],
            true
        )
    ) {
        $activeRequests++;
    }

    if (
        in_array(
            $status,
            [
                "Completed",
                "For Evaluation",
                "Published",
                "Closed"
            ],
            true
        )
    ) {
        $completedRequests++;
    }

    if (
        canEvaluate(
            $request
        )
        ||
        canEvaluatePublication(
            $request
        )
    ) {
        $evaluationPending++;
    }
}

$workflowStages = [
    [
        "label" => "Submitted",
        "icon" => "bi-send-check"
    ],
    [
        "label" => "Review",
        "icon" => "bi-search"
    ],
    [
        "label" => "Approved",
        "icon" => "bi-patch-check"
    ],
    [
        "label" => "Development",
        "icon" => "bi-tools"
    ],
    [
        "label" => "Completed",
        "icon" => "bi-check2-all"
    ],
    [
        "label" => "Evaluation",
        "icon" => "bi-clipboard2-check"
    ],
    [
        "label" => "Closed",
        "icon" => "bi-lock"
    ]
];

$publicationStages = [
    [
        "label" => "Submitted",
        "icon" => "bi-send-check"
    ],
    [
        "label" => "For Review",
        "icon" => "bi-search"
    ],
    [
        "label" => "Preparation",
        "icon" => "bi-pencil-square"
    ],
    [
        "label" => "Approval",
        "icon" => "bi-patch-check"
    ],
    [
        "label" => "Published",
        "icon" => "bi-megaphone"
    ],
    [
        "label" => "Closed",
        "icon" => "bi-lock"
    ]
];


?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Requests | HPU Workbench</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">

    <style>
        :root {
            --page-bg: #f4f7fb;
            --surface: #ffffff;
            --surface-soft: #f8fafc;
            --border: #e2e8f0;
            --text: #0f172a;
            --muted: #64748b;
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-soft: #eff6ff;
            --success: #16a34a;
            --warning: #d97706;
            --danger: #dc2626;
            --purple: #7c3aed;
            --shadow: 0 14px 38px rgba(15, 23, 42, .07);
        }

        body {
            background: var(--page-bg);
            color: var(--text);
        }

        .main {
            min-width: 0;
        }

        .content {
            padding: 24px;
        }

        .requests-page {
            max-width: 1500px;
            margin: 0 auto;
        }

        /* Page header styles moved to assets/css/page_headers.css (.ph) */
        .today-card {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 13px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: var(--surface);
            color: var(--muted);
            font-size: .78rem;
        }

        .today-card i {
            color: var(--primary);
            font-size: 18px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 8px;
            margin-bottom: 12px;
        }

        .summary-card {
            display: grid;
            grid-template-columns: 36px 1fr auto;
            align-items: center;
            gap: 9px;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--surface);
            box-shadow: 0 3px 12px rgba(15, 23, 42, .04);
        }

        .summary-icon {
            width: 36px;
            height: 36px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            font-size: 16px;
        }

        .summary-card.total .summary-icon {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .summary-card.active .summary-icon {
            background: #dcfce7;
            color: #15803d;
        }

        .summary-card.completed .summary-icon {
            background: #ede9fe;
            color: #6d28d9;
        }

        .summary-card.evaluation .summary-icon {
            background: #ffedd5;
            color: #c2410c;
        }

        .summary-card.evaluation-pending .summary-icon {
            background: #fef3c7;
            color: #b45309;
        }

        .evaluation-status-note {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 5px;
            color: #166534;
            font-size: .65rem;
            font-weight: 800;
        }

        .request-actions .btn-view-evaluation {
            width: auto;
            padding: 0 11px;
            display: inline-flex;
            gap: 5px;
            font-size: .68rem;
            font-weight: 800;
        }

        .summary-label {
            color: var(--muted);
            font-size: .66rem;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .summary-value {
            margin-top: 1px;
            font-size: 1.2rem;
            font-weight: 850;
            line-height: 1;
        }

        .summary-note {
            margin-top: 3px;
            color: var(--muted);
            font-size: .62rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .summary-ring {
            --value: 0deg;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: conic-gradient(var(--primary) var(--value), #e9eef5 0);
            position: relative;
            font-size: .55rem;
            font-weight: 800;
        }

        .summary-ring::before {
            content: "";
            position: absolute;
            inset: 4px;
            border-radius: 50%;
            background: white;
        }

        .summary-ring span {
            position: relative;
            z-index: 1;
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            padding: 8px 10px;
            margin-bottom: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--surface);
            box-shadow: 0 3px 12px rgba(15, 23, 42, .035);
        }

        .filter-tabs {
            display: flex;
            gap: 4px;
            flex-wrap: nowrap;
            overflow-x: auto;
            max-width: 100%;
            padding-bottom: 2px;
            scrollbar-width: thin;
        }

        .filter-tab {
            border: 0;
            border-radius: 8px;
            padding: 6px 10px;
            background: transparent;
            color: #475569;
            font-size: .7rem;
            font-weight: 750;
            white-space: nowrap;
            flex-shrink: 0;
            transition: .15s ease;
        }

        .filter-tab:hover {
            background: #f1f5f9;
            color: var(--text);
        }

        .filter-tab.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 6px 15px rgba(37, 99, 235, .22);
        }

        .toolbar-actions {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
        }

        .request-search {
            position: relative;
            width: min(280px, 70vw);
        }

        .request-search i {
            position: absolute;
            top: 50%;
            left: 11px;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: .8rem;
        }

        .request-search input {
            height: 34px;
            padding-left: 33px;
            border-color: var(--border);
            border-radius: 9px;
            font-size: .75rem;
        }

        .toolbar .btn {
            height: 34px;
            border-radius: 9px;
            font-size: .72rem;
            font-weight: 750;
            display: inline-flex;
            align-items: center;
        }

        .request-list-stack {
            display: grid;
            gap: 8px;
        }

        .request-group-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 4px;
            padding: 7px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: linear-gradient(90deg, #f0f6ff, #fff);
        }

        .request-group-head:first-child {
            margin-top: 0;
        }

        .request-group-head strong {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #1d4ed8;
            font-size: .82rem;
            font-weight: 850;
        }

        .request-group-head span {
            display: block;
            margin-top: 2px;
            color: var(--muted);
            font-size: .7rem;
        }

        .request-group-empty {
            padding: 12px 14px;
            border: 1px dashed #cbd5e1;
            border-radius: 13px;
            background: #fcfcfd;
            color: var(--muted);
            font-size: .76rem;
        }

        .request-card {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--surface);
            box-shadow: 0 2px 8px rgba(15, 23, 42, .035);
            transition: border-color .13s ease, background .13s ease;
        }

        .request-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: #94a3b8;
        }

        .request-card.status-pending-card::before {
            background: #94a3b8;
        }

        .request-card.status-review-card::before {
            background: #0891b2;
        }

        .request-card.status-approved-card::before {
            background: #16a34a;
        }

        .request-card.status-development-card::before {
            background: #2563eb;
        }

        .request-card.status-revision-card::before {
            background: #d97706;
        }

        .request-card.status-disapproved-card::before {
            background: #dc2626;
        }

        .request-card.status-completed-card::before {
            background: #2563eb;
        }

        .request-card.status-evaluation-card::before {
            background: #ea580c;
        }

        .request-card.status-closed-card::before {
            background: #16a34a;
        }

        .request-card:hover {
            border-color: #b9c6d8;
            background: #fbfdff;
        }

        .request-card-body {
            padding: 10px 12px 10px 14px;
        }

        .request-layout {
            display: grid;
            grid-template-columns: minmax(250px, 1fr) minmax(420px, 1.5fr) minmax(210px, .65fr);
            gap: 14px;
            align-items: center;
        }

        .request-identity {
            display: grid;
            grid-template-columns: 40px minmax(0, 1fr);
            gap: 10px;
            align-items: start;
        }

        .request-type-icon {
            width: 40px;
            height: 40px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: var(--primary-soft);
            color: var(--primary);
            font-size: 17px;
        }

        .job-number-row {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-wrap: wrap;
        }

        .job-number {
            color: var(--text);
            font-size: .78rem;
            font-weight: 850;
            letter-spacing: .01em;
        }

        .copy-job {
            border: 0;
            padding: 0;
            background: none;
            color: #94a3b8;
            line-height: 1;
        }

        .copy-job:hover {
            color: var(--primary);
        }

        .project-title {
            margin: 3px 0 4px;
            color: #1e293b;
            font-size: .82rem;
            font-weight: 800;
            line-height: 1.3;
            letter-spacing: -.01em;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .request-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 12px;
            color: var(--muted);
            font-size: .67rem;
        }

        .request-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .workflow-area {
            min-width: 0;
        }

        .workflow-track {
            display: grid;
            grid-template-columns: repeat(7, minmax(56px, 1fr));
            align-items: start;
        }

        .workflow-step {
            position: relative;
            text-align: center;
            min-width: 0;
        }

        .workflow-step::before {
            content: "";
            position: absolute;
            top: 13px;
            left: -50%;
            width: 100%;
            height: 2px;
            background: #dbe3ef;
        }

        .workflow-step:first-child::before {
            display: none;
        }

        .workflow-step.done::before,
        .workflow-step.active::before {
            background: #22c55e;
        }

        .workflow-dot {
            position: relative;
            z-index: 2;
            width: 22px;
            height: 22px;
            margin: 0 auto 4px;
            display: grid;
            place-items: center;
            border: 2px solid #cbd5e1;
            border-radius: 50%;
            background: white;
            color: #94a3b8;
            font-size: 9px;
        }

        .workflow-step.done .workflow-dot {
            border-color: #22c55e;
            background: #22c55e;
            color: white;
        }

        .workflow-step.active .workflow-dot {
            border-color: var(--primary);
            background: var(--primary);
            color: white;
            box-shadow: 0 0 0 4px #dbeafe;
        }

        .workflow-label {
            overflow: hidden;
            color: #64748b;
            font-size: .58rem;
            font-weight: 750;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .workflow-step.active .workflow-label,
        .workflow-step.done .workflow-label {
            color: #334155;
        }

        .progress-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 8px;
        }

        .request-progress {
            flex: 1;
            height: 5px;
            overflow: hidden;
            border-radius: 999px;
            background: #e2e8f0;
        }

        .request-progress>span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #22c55e, #2563eb);
        }

        .progress-label {
            color: var(--muted);
            font-size: .65rem;
            white-space: nowrap;
        }

        .request-side {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 13px;
            align-items: center;
            padding-left: 17px;
            border-left: 1px solid #edf1f6;
        }

        .side-data {
            min-width: 0;
        }

        .side-label {
            color: var(--muted);
            font-size: .63rem;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .side-date {
            margin-top: 3px;
            color: #334155;
            font-size: .8rem;
            font-weight: 750;
        }

        .due-text {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 5px;
            font-size: .68rem;
            font-weight: 750;
        }

        .due-success {
            color: #15803d;
        }

        .due-warning {
            color: #c2410c;
        }

        .due-danger {
            color: #dc2626;
        }

        .due-info {
            color: #1d4ed8;
        }

        .due-muted {
            color: #64748b;
        }


        .date-stack {
            display: grid;
            gap: 8px;
            margin-top: 3px;
        }

        .date-stack-item {
            min-width: 0;
        }

        .date-stack-label {
            color: var(--muted);
            font-size: .58rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .date-stack-value {
            margin-top: 2px;
            color: #334155;
            font-size: .76rem;
            font-weight: 750;
        }

        .date-stack-value.processing {
            color: #1d4ed8;
        }

        .approval-meta {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 4px;
            color: #64748b;
            font-size: .61rem;
            font-weight: 700;
        }

        .approval-meta i {
            color: #16a34a;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 9px;
            border-radius: 999px;
            font-size: .6rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .status-pending {
            background: #f1f5f9;
            color: #475569;
        }

        .status-review {
            background: #cffafe;
            color: #155e75;
        }

        .status-approved {
            background: #dcfce7;
            color: #166534;
        }

        .status-development {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-revision {
            background: #fef3c7;
            color: #92400e;
        }

        .status-disapproved {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-completed {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-evaluation {
            background: #ffedd5;
            color: #c2410c;
        }

        .status-closed {
            background: #dcfce7;
            color: #166534;
        }

        .status-default {
            background: #f1f5f9;
            color: #475569;
        }

        .request-actions {
            display: flex;
            gap: 5px;
            justify-content: flex-end;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .request-actions .btn {
            width: 31px;
            height: 31px;
            display: grid;
            place-items: center;
            padding: 0;
            border-radius: 8px;
            font-size: .75rem;
        }

        .request-actions .btn-evaluate {
            width: auto;
            padding: 0 11px;
            display: inline-flex;
            gap: 5px;
            font-size: .68rem;
            font-weight: 800;
        }

        .empty-state {
            padding: 70px 20px;
            text-align: center;
            color: var(--muted);
            border: 1px dashed #cbd5e1;
            border-radius: 17px;
            background: white;
        }

        .empty-state .empty-icon {
            width: 68px;
            height: 68px;
            margin: 0 auto 14px;
            display: grid;
            place-items: center;
            border-radius: 20px;
            background: #eff6ff;
            color: var(--primary);
            font-size: 29px;
        }

        .empty-state h4 {
            color: var(--text);
            font-weight: 800;
        }

        .empty-state p {
            font-size: .82rem;
        }

        .results-footer {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-top: 14px;
            color: var(--muted);
            font-size: .72rem;
        }

        .toast-copy {
            position: fixed;
            right: 22px;
            bottom: 22px;
            z-index: 1080;
            display: none;
            padding: 11px 14px;
            border-radius: 11px;
            background: #0f172a;
            color: white;
            font-size: .75rem;
            box-shadow: 0 14px 30px rgba(15, 23, 42, .25);
        }

        @media (max-width: 1400px) {
            .summary-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .request-layout {
                grid-template-columns: minmax(240px, 1fr) minmax(380px, 1.4fr);
            }

            .request-side {
                grid-column: 1 / -1;
                border-left: 0;
                border-top: 1px solid #edf1f6;
                padding: 9px 0 0;
                grid-template-columns: 1fr auto;
            }
        }

        @media (max-width: 1250px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .request-layout {
                grid-template-columns: minmax(250px, .85fr) minmax(480px, 1.5fr);
            }

            .request-side {
                grid-column: 1 / -1;
                border-left: 0;
                border-top: 1px solid #edf1f6;
                padding: 13px 0 0;
                grid-template-columns: 1fr auto;
            }
        }

        @media (max-width: 1100px) {
            .workflow-label {
                display: none;
            }

            .workflow-track {
                grid-template-columns: repeat(7, minmax(30px, 1fr));
            }

            .workflow-dot {
                margin-bottom: 0;
            }
        }

        @media (max-width: 900px) {
            .content {
                padding: 15px;
            }

            .request-layout {
                grid-template-columns: 1fr;
            }

            .workflow-area {
                overflow-x: auto;
                padding-bottom: 5px;
            }

            .workflow-track {
                min-width: 650px;
            }

            .request-side {
                grid-column: auto;
            }

            .toolbar {
                align-items: stretch;
            }

            .filter-tabs,
            .toolbar-actions {
                width: 100%;
            }

            .request-search {
                width: 100%;
                flex: 1;
            }
        }

        @media (max-width: 640px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .summary-note {
                display: none;
            }

            .ph {
                align-items: flex-start;
            }

            .today-card {
                width: 100%;
            }

            .toolbar-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .toolbar-actions .btn,
            .request-search {
                width: 100%;
            }

            .request-side {
                grid-template-columns: 1fr;
            }

            .request-actions {
                justify-content: flex-start;
                flex-wrap: wrap;
            }

            .request-actions .btn {
                flex: 1;
                min-width: 42px;
            }

            .request-actions .btn-evaluate {
                flex: 2;
            }

            .summary-card {
                grid-template-columns: 48px 1fr;
            }

            .summary-ring {
                display: none;
            }
        }

        .request-kind-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 7px;
            margin-top: 6px;
            border-radius: 999px;
            font-size: .58rem;
            font-weight: 800;
        }

        .kind-job {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .kind-event {
            background: #ecfeff;
            color: #0e7490;
        }

        .kind-recognition {
            background: #fff7ed;
            color: #b54708;
        }

        .kind-contribution {
            background: #f5f3ff;
            color: #6d28d9;
        }

        .ownership-office {
            background: #f1f5f9;
            color: #475569;
        }

        .office-context {
            display: flex;
            flex-wrap: wrap;
            gap: 7px 11px;
            margin-top: 6px;
            color: #64748b;
            font-size: .65rem;
        }

        .office-context span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .ownership-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-left: 5px;
            padding: 3px 6px;
            border-radius: 999px;
            font-size: .56rem;
            font-weight: 850;
        }

        .ownership-me {
            background: #dcfce7;
            color: #166534;
        }

        /* ---- New-message notify ---- */
        .msg-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-left: 5px;
            padding: 3px 8px;
            border-radius: 999px;
            background: #eef2ff;
            color: #3730a3;
            font-size: .58rem;
            font-weight: 850;
            white-space: nowrap;
        }

        .msg-pill.has-unread {
            background: #fee4e2;
            color: #b42318;
            animation: msgPulse 1.6s ease-in-out infinite;
        }

        .msg-pill.has-thread {
            background: #e0eaff;
            color: #1d4ed8;
        }

        @keyframes msgPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(180, 35, 24, .25); }
            50% { box-shadow: 0 0 0 4px rgba(180, 35, 24, 0); }
        }

        .msg-badge {
            position: absolute;
            top: -7px;
            right: -7px;
            display: inline-grid;
            min-width: 19px;
            height: 19px;
            place-items: center;
            padding: 0 5px;
            border: 2px solid #fff;
            border-radius: 999px;
            background: #d92d20;
            color: #fff;
            font-size: .6rem;
            font-weight: 900;
            line-height: 1;
        }

        .msg-badge.d-none {
            display: none !important;
        }

        .btn-has-badge {
            position: relative;
        }

        .msg-preview {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 5px;
            color: #7a2e0e;
            font-size: .66rem;
            font-weight: 600;
        }

        .msg-preview span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .msg-snippet {
            display: flex;
            align-items: center;
            gap: 7px;
            margin-top: 5px;
            padding: 5px 9px;
            border: 1px solid #fecaca;
            border-radius: 8px;
            background: #fef2f2;
            color: inherit;
            font-size: .72rem;
            text-decoration: none;
            transition: border-color .15s ease, background .15s ease;
        }

        .msg-snippet:hover {
            border-color: #f87171;
            background: #fef6f6;
        }

        /* Read conversation: calm slate so unread (red) still pops. */
        .msg-snippet.is-read {
            border-color: #dbe3ef;
            background: #f8fafc;
        }

        .msg-snippet.is-read:hover {
            border-color: #93c5fd;
            box-shadow: 0 6px 16px rgba(37, 99, 235, .10);
        }

        .msg-snippet.is-read > i {
            color: #64748b;
        }

        .msg-snippet.is-read .msg-snippet-text {
            color: #475569;
        }

        .msg-snippet.is-read .msg-snippet-go {
            color: #1d4ed8;
        }

        .msg-snippet-eyebrow {
            display: block;
            font-size: .58rem;
            font-weight: 850;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: #b42318;
        }

        .msg-snippet.is-read .msg-snippet-eyebrow {
            color: #64748b;
        }

        .msg-snippet > i {
            flex: 0 0 auto;
            color: #b42318;
            font-size: .85rem;
        }

        .msg-snippet-body {
            min-width: 0;
            flex: 1 1 auto;
            font-size: .7rem;
            line-height: 1.4;
        }

        .msg-snippet-text {
            display: block;
            overflow: hidden;
            color: #7a2e0e;
            font-weight: 600;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .msg-snippet-time {
            color: #94a3b8;
            font-weight: 500;
            white-space: nowrap;
        }

        .msg-snippet-go {
            flex: 0 0 auto;
            color: #b42318;
            font-size: .66rem;
            font-weight: 850;
            white-space: nowrap;
        }

        .filter-tab.msg-tab {
            border-style: dashed;
        }

        .filter-tab.msg-tab.is-active {
            border-color: #4f46e5;
            background: #4f46e5;
            color: #fff;
        }

        .request-card.status-for-review-card::before {
            background: #0891b2;
        }

        .request-card.status-in-preparation-card::before {
            background: #2563eb;
        }

        .request-card.status-for-approval-card::before {
            background: #7c3aed;
        }

        .request-card.status-published-card::before {
            background: #16a34a;
        }

        .status-for-review {
            background: #cffafe;
            color: #155e75;
        }

        .status-in-preparation {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-for-approval {
            background: #ede9fe;
            color: #6d28d9;
        }

        .status-published {
            background: #dcfce7;
            color: #166534;
        }

        .workflow-track.publication-track {
            grid-template-columns: repeat(6, minmax(62px, 1fr));
        }

        .publication-note {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 5px;
            color: #7c3aed;
            font-size: .65rem;
            font-weight: 750;
        }

        .toolbar-secondary {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
        }

        .toolbar-secondary .btn {
            height: 42px;
            border-radius: 11px;
            font-size: .76rem;
            font-weight: 750;
            display: inline-flex;
            align-items: center;
        }
    </style>
</head>

<body class="app-shell">

    <div class="d-flex min-vh-100">

        <?php include "../includes/sidebar.php"; ?>

        <div class="main flex-grow-1">

            <main class="content">

                <div class="container-fluid px-0">

                    <div class="requests-page">

                        <header class="ph">

                            <div class="ph-copy">

                                <div class="ph-icon">
                                    <i class="bi bi-card-checklist"></i>
                                </div>

                                <div class="min-w-0">
                                    <p class="ph-eyebrow">
                                        HPU Workbench
                                    </p>

                                    <h1 class="ph-title">
                                        My Requests
                                    </h1>

                                    <p class="ph-subtitle">
                                        Track your HPU Job Requests and Publication Submissions in one
                                        place.
                                    </p>
                                </div>

                            </div>

                            <div class="today-card">
                                <i class="bi bi-calendar3"></i>

                                <div>
                                    <strong id="currentDate">
                                        <?= date("F d, Y") ?>
                                    </strong>
                                    <br>
                                    <span id="currentTime">
                                        Loading time…
                                    </span>
                                </div>
                            </div>

                        </header>

                        <?php if (!empty($archiveFlash)): ?>
                            <div class="alert alert-<?= e($archiveFlash["type"] ?? "success") ?> alert-dismissible fade show" role="alert">
                                <i class="bi bi-<?= (($archiveFlash["type"] ?? "success") === "success") ? "check-circle-fill" : "info-circle-fill" ?> me-1"></i>
                                <?= e($archiveFlash["text"] ?? "") ?>
                                <button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <section class="summary-grid" aria-label="Request summary">

                            <?php
                            $activePercent =
                                $totalRequests > 0
                                ? (int) round(
                                    (
                                        $activeRequests
                                        /
                                        $totalRequests
                                    )
                                    * 100
                                )
                                : 0;

                            $completePercent =
                                $totalRequests > 0
                                ? (int) round(
                                    (
                                        $completedRequests
                                        /
                                        $totalRequests
                                    )
                                    * 100
                                )
                                : 0;

                            $publicationPercent =
                                $totalRequests > 0
                                ? (int) round(
                                    (
                                        $publicationRequests
                                        /
                                        $totalRequests
                                    )
                                    * 100
                                )
                                : 0;

                            $evaluationPercent =
                                $totalRequests > 0
                                ? (int) round(
                                    (
                                        $evaluationPending
                                        /
                                        $totalRequests
                                    )
                                    * 100
                                )
                                : 0;


                            ?>

                            <article class="summary-card total">
                                <div class="summary-icon">
                                    <i class="bi bi-files"></i>
                                </div>

                                <div>
                                    <div class="summary-label">
                                        Total Requests
                                    </div>

                                    <div class="summary-value">
                                        <?= $totalRequests ?>
                                    </div>

                                    <div class="summary-note">
                                        Job + publication requests
                                    </div>
                                </div>

                                <div class="summary-ring" style="--value:360deg">
                                    <span>100%</span>
                                </div>
                            </article>

                            <article class="summary-card active">
                                <div class="summary-icon">
                                    <i class="bi bi-lightning-charge"></i>
                                </div>

                                <div>
                                    <div class="summary-label">
                                        Active Requests
                                    </div>

                                    <div class="summary-value">
                                        <?= $activeRequests ?>
                                    </div>

                                    <div class="summary-note">
                                        Currently in workflow
                                    </div>
                                </div>

                                <div class="summary-ring" style="--value:<?= $activePercent * 3.6 ?>deg">
                                    <span><?= $activePercent ?>%</span>
                                </div>
                            </article>

                            <article class="summary-card completed">
                                <div class="summary-icon">
                                    <i class="bi bi-check2-square"></i>
                                </div>

                                <div>
                                    <div class="summary-label">
                                        Completed / Published
                                    </div>

                                    <div class="summary-value">
                                        <?= $completedRequests ?>
                                    </div>

                                    <div class="summary-note">
                                        Finished requests
                                    </div>
                                </div>

                                <div class="summary-ring" style="--value:<?= $completePercent * 3.6 ?>deg">
                                    <span><?= $completePercent ?>%</span>
                                </div>
                            </article>

                            <article class="summary-card evaluation">

                                <div class="summary-icon">
                                    <i class="bi bi-newspaper"></i>
                                </div>

                                <div>
                                    <div class="summary-label">
                                        Publication Submissions
                                    </div>

                                    <div class="summary-value">
                                        <?= $publicationRequests ?>
                                    </div>

                                    <div class="summary-note">
                                        Event + recognition
                                    </div>
                                </div>

                                <div class="summary-ring" style="--value:<?= $publicationPercent * 3.6 ?>deg">
                                    <span><?= $publicationPercent ?>%</span>
                                </div>

                            </article>

                            <article class="summary-card evaluation-pending">

                                <div class="summary-icon">
                                    <i class="bi bi-clipboard2-check"></i>
                                </div>

                                <div>
                                    <div class="summary-label">
                                        Evaluation Pending
                                    </div>

                                    <div class="summary-value">
                                        <?= $evaluationPending ?>
                                    </div>

                                    <div class="summary-note">
                                        Completed jobs awaiting survey
                                    </div>
                                </div>

                                <div class="summary-ring" style="--value:<?= $evaluationPercent * 3.6 ?>deg">
                                    <span><?= $evaluationPercent ?>%</span>
                                </div>

                            </article>

                        </section>

                        <section class="toolbar">

                            <div class="filter-tabs" role="group" aria-label="Filter requests">
                                <button class="filter-tab active" type="button" data-filter="all">
                                    All
                                </button>

                                <button class="filter-tab" type="button" data-filter="job">
                                    Job Requests
                                </button>

                                <button class="filter-tab" type="button" data-filter="publication">
                                    Publication
                                </button>

                                <button class="filter-tab" type="button" data-filter="pending">
                                    Pending
                                </button>

                                <button class="filter-tab" type="button" data-filter="progress">
                                    In Progress
                                </button>

                                <button class="filter-tab" type="button" data-filter="completed">
                                    Completed
                                </button>

                                <button class="filter-tab" type="button" data-filter="evaluation">
                                    Evaluation
                                </button>

                                <button class="filter-tab" type="button" data-filter="closed">
                                    Closed
                                </button>

                                <button class="filter-tab msg-tab" type="button" data-filter="unread" id="unreadFilterTab" title="Show only requests with new messages">
                                    <i class="bi bi-chat-dots-fill me-1"></i>New messages (<span id="unreadFilterCount"><?= (int) $msgTotalUnread ?></span>)
                                </button>
                            </div>

                            <div class="toolbar-actions">

                                <div class="request-search">
                                    <i class="bi bi-search"></i>

                                    <input id="requestSearch" type="search" class="form-control"
                                        placeholder="Search request no., title, type, or status" autocomplete="off">
                                </div>

                                <div class="toolbar-secondary">

                                    <a class="btn btn-primary px-3" href="new_request.php">
                                        <i class="bi bi-plus-lg me-2"></i>
                                        New Job Request
                                    </a>

                                    <a class="btn btn-outline-primary px-3"
                                        href="<?= e(BASE_URL) ?>/content_submission/event_activity.php">
                                        <i class="bi bi-newspaper me-2"></i>
                                        Publication
                                    </a>

                                    <a class="btn btn-outline-secondary px-3"
                                        href="<?= e(BASE_URL) ?>/my_calendar.php"
                                        title="View your Office Activities / Events in the Calendar of Activities">
                                        <i class="bi bi-calendar3 me-2"></i>
                                        Office Activities
                                    </a>

                                </div>

                            </div>

                        </section>

                        <div id="requestList" class="request-list-stack">

                            <?php
                            $renderedGroup = null;
                            ?>

                            <?php if (
                                $myRequestsCount === 0
                                &&
                                $sameOfficeCount > 0
                            ): ?>

                                <div class="request-group-head mine">
                                    <div>
                                        <strong>
                                            <i class="bi bi-person-check-fill"></i>
                                            My Requests
                                        </strong>

                                        <span>
                                            No requests submitted by me yet
                                        </span>
                                    </div>
                                </div>

                                <div class="request-group-empty">
                                    You have not submitted any requests yet. New submissions will appear here.
                                </div>

                                <?php
                                $renderedGroup = "mine";
                                ?>

                            <?php endif; ?>

                            <?php foreach ($allRequests as $row): ?>

                                <?php
                                $rowGroupKey =
                                    !empty(
                                    $row["same_office"]
                                )
                                    ? "same"
                                    : "mine";

                                if ($rowGroupKey !== $renderedGroup) {
                                    $renderedGroup = $rowGroupKey;
                                    ?>

                                    <div class="request-group-head <?= e($rowGroupKey) ?>">
                                        <div>
                                            <strong>
                                                <?php if ($rowGroupKey === "mine"): ?>
                                                    <i class="bi bi-person-check-fill"></i>
                                                    My Requests
                                                <?php else: ?>
                                                    <i class="bi bi-people"></i>
                                                    Same-Office Requests
                                                <?php endif; ?>
                                            </strong>

                                            <span>
                                                <?php if ($rowGroupKey === "mine"): ?>
                                                    <?= $myRequestsCount ?>
                                                    request<?= $myRequestsCount === 1 ? "" : "s" ?>
                                                    submitted by me
                                                <?php else: ?>
                                                    <?= $sameOfficeCount ?>
                                                    request<?= $sameOfficeCount === 1 ? "" : "s" ?>
                                                    from my office · view only
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>

                                    <?php
                                }
                                ?>

                                <?php
                                $source =
                                    (string) $row["source"];

                                $status =
                                    (string) $row["status"];

                                $isJob =
                                    $source === "job";

                                $isPublication =
                                    $source === "event"
                                    ||
                                    $source === "recognition"
                                    ||
                                    $source === "contribution";

                                if ($isJob) {
                                    $progress =
                                        jobProgress(
                                            $status
                                        );

                                    $activeStage =
                                        jobStageIndex(
                                            $status
                                        );

                                    $stages =
                                        $workflowStages;
                                } else {
                                    $progress =
                                        publicationProgress(
                                            $status
                                        );

                                    $activeStage =
                                        publicationStageIndex(
                                            $status
                                        );

                                    $stages =
                                        $publicationStages;
                                }

                                $due =
                                    requestDueInfo(
                                        $row["preferred_date"],
                                        $status,
                                        $source,
                                        $row["approved_date"] ?? null,
                                        (int) ($row["lead_days"] ?? 0),
                                        $conn,
                                        $row["request_types"] ?? null
                                    );

                                $filterGroup =
                                    match (true) {
                                        $isJob
                                        &&
                                        canEvaluate(
                                            $row
                                        ) =>
                                            "evaluation",

                                        $isJob
                                        &&
                                        $status === "Pending Review" =>
                                            "pending",

                                        $isJob
                                        &&
                                        in_array(
                                            $status,
                                            [
                                                "Under Review",
                                                "Approved",
                                                "In Development",
                                                "For Revision"
                                            ],
                                            true
                                        ) =>
                                            "progress",

                                        $isJob
                                        &&
                                        $status === "Completed" =>
                                            "completed",

                                        $isJob
                                        &&
                                        $status === "For Evaluation" =>
                                            "evaluation",

                                        $isJob
                                        &&
                                        $status === "Disapproved" =>
                                            "closed",

                                        $isPublication
                                        &&
                                        $status === "For Review" =>
                                            "pending",

                                        $isPublication
                                        &&
                                        in_array(
                                            $status,
                                            [
                                                "In Preparation",
                                                "For Approval"
                                            ],
                                            true
                                        ) =>
                                            "progress",

                                        $isPublication
                                        &&
                                        $status === "Published" =>
                                            "completed",

                                        $status === "Closed" =>
                                            "closed",

                                        default =>
                                            "other"
                                    };

                                $kindFilter =
                                    $isJob
                                    ? "job"
                                    : "publication";

                                $kindClass =
                                    match ($source) {
                                        "event" =>
                                            "kind-event",

                                        "recognition" =>
                                            "kind-recognition",

                                        "contribution" =>
                                            "kind-contribution",

                                        default =>
                                            "kind-job"
                                    };

                                $kindLabel =
                                    match ($source) {
                                        "event" =>
                                            "Event / Activity Publication",

                                        "recognition" =>
                                            "Recognition / Milestone Publication",

                                        "contribution" =>
                                            "Content Contribution Publication",

                                        default =>
                                            "HPU Job Request"
                                    };

                                $kindIcon =
                                    match ($source) {
                                        "event" =>
                                            "bi-calendar2-event",

                                        "recognition" =>
                                            "bi-award",

                                        "contribution" =>
                                            "bi-megaphone",

                                        default =>
                                            "bi-file-earmark-text"
                                    };

                                $rowUnread = 0;
                                $rowPreview = null;

                                if ($isJob) {
                                    $rowUnread = (int) ($jobUnread[(int) $row["id"]] ?? 0);
                                    $rowPreview = $jobLatest[(int) $row["id"]] ?? null;
                                    $rowConversationUrl = rtrim(BASE_URL, "/") . "/admin/view.php?id=" . (int) $row["id"] . "#conversation";
                                } elseif ($isPublication) {
                                    $pubKey = $source . ":" . (int) $row["id"];
                                    $rowUnread = (int) ($pubUnread[$pubKey] ?? 0);
                                    $rowPreview = $pubLatest[$pubKey] ?? null;
                                    $rowConversationUrl = (string) $row["view_url"] . "#pubConversation";
                                } else {
                                    $rowUnread = 0;
                                    $rowPreview = null;
                                    $rowConversationUrl = (string) $row["view_url"];
                                }

                                $rowPreviewTitle = "";

                                if ($rowPreview) {
                                    $rowPreviewTitle = (($rowPreview["sender"] ?? "") !== ""
                                        ? $rowPreview["sender"] . ": "
                                        : "") . ($rowPreview["message"] ?? "");
                                }

                                $hasThread = $rowPreview !== null;

                                $rowPreviewTime = "";

                                if (!empty($rowPreview["created_at"])) {
                                    $previewTs = strtotime((string) $rowPreview["created_at"]);

                                    if ($previewTs !== false) {
                                        $previewDiff = time() - $previewTs;

                                        if ($previewDiff < 60) {
                                            $rowPreviewTime = "just now";
                                        } elseif ($previewDiff < 3600) {
                                            $rowPreviewTime = ((int) floor($previewDiff / 60)) . " min ago";
                                        } elseif ($previewDiff < 86400) {
                                            $rowPreviewTime = ((int) floor($previewDiff / 3600)) . " hr ago";
                                        } else {
                                            $rowPreviewTime = date("M d, g:i A", $previewTs);
                                        }
                                    }
                                }
                                ?>

                                <div class="request-item" data-filter="<?= e($filterGroup) ?>"
                                    data-kind="<?= e($kindFilter) ?>" data-unread="<?= (int) $rowUnread ?>"
                                    data-source="<?= e($source) ?>" data-id="<?= (int) $row["id"] ?>" data-search="<?= e(strtolower(
                                          $row["code"]
                                          . " "
                                          . $row["title"]
                                          . " "
                                          . $row["type_label"]
                                          . " "
                                          . $status
                                          . " "
                                          . $kindLabel
                                          . " "
                                          . (string) ($row["extra_search"] ?? "")
                                          . " "
                                          . $rowPreviewTitle
                                      )) ?>">

                                    <article class="request-card status-<?= e(
                                        str_replace(
                                            " ",
                                            "-",
                                            strtolower(
                                                $status
                                            )
                                        )
                                    ) ?>-card">

                                        <div class="request-card-body">

                                            <div class="request-layout">

                                                <div class="request-identity">

                                                    <div class="request-type-icon">
                                                        <i class="bi <?= e($kindIcon) ?>"></i>
                                                    </div>

                                                    <div>

                                                        <div class="job-number-row">

                                                            <span class="job-number">
                                                                <?= e($row["code"]) ?>
                                                            </span>

                                                            <button class="copy-job" type="button"
                                                                data-copy="<?= e($row["code"]) ?>"
                                                                title="Copy request number">
                                                                <i class="bi bi-copy"></i>
                                                            </button>

                                                        </div>

                                                        <div class="project-title">
                                                            <?= e($row["title"]) ?>
                                                        </div>

                                                        <?php if ($rowPreview): ?>
                                                            <a class="msg-snippet <?= $rowUnread > 0 ? "" : "is-read" ?>" href="<?= e($rowConversationUrl) ?>" title="<?= $rowUnread > 0 ? "Read message and open the conversation for " . $row["code"] : "Open the conversation for " . $row["code"] ?>">
                                                                <i class="bi <?= $rowUnread > 0 ? "bi-chat-left-text-fill" : "bi-chat-left-text" ?>"></i>
                                                                <span class="msg-snippet-body">
                                                                    <span class="msg-snippet-eyebrow"><?= $rowUnread > 0 ? "New message" : "Latest message" ?></span>
                                                                    <span class="msg-snippet-text"><?= e($rowPreviewTitle) ?></span>
                                                                    <?php if ($rowPreviewTime !== ""): ?>
                                                                        <span class="msg-snippet-time">· <?= e($rowPreviewTime) ?></span>
                                                                    <?php endif; ?>
                                                                </span>
                                                                <span class="msg-snippet-go">
                                                                    Open <i class="bi bi-arrow-right"></i>
                                                                </span>
                                                            </a>
                                                        <?php endif; ?>

                                                        <div class="request-meta">

                                                            <span>
                                                                <i class="bi bi-calendar3"></i>
                                                                Submitted
                                                                <?= !empty($row["date_request"])
                                                                    ? date(
                                                                        "M d, Y",
                                                                        strtotime(
                                                                            $row["date_request"]
                                                                        )
                                                                    )
                                                                    : "Not specified"
                                                                    ?>
                                                            </span>

                                                            <?php if (
                                                                (
                                                                    $isJob
                                                                    &&
                                                                    canViewEvaluation(
                                                                        $row
                                                                    )
                                                                )
                                                                ||
                                                                (
                                                                    $isPublication
                                                                    &&
                                                                    canViewPubEvaluation(
                                                                        $row
                                                                    )
                                                                )
                                                            ): ?>

                                                                <span class="text-success">
                                                                    <i class="bi bi-check-circle-fill"></i>
                                                                    Evaluation submitted
                                                                    <?php if (
                                                                        !empty(
                                                                        $row["survey_overall_rating"]
                                                                    )
                                                                    ): ?>
                                                                        •
                                                                        <?= (int) $row["survey_overall_rating"] ?>/5
                                                                    <?php endif; ?>
                                                                </span>

                                                            <?php endif; ?>

                                                        </div>

                                                        <span class="request-kind-badge <?= e($kindClass) ?>">
                                                            <i class="bi <?= e($kindIcon) ?>"></i>
                                                            <?= e($kindLabel) ?>
                                                        </span>

                                                        <span class="msg-pill <?= $rowUnread > 0 ? "has-unread" : ($hasThread ? "has-thread" : "") ?>" data-msg-pill data-has-thread="<?= $hasThread ? "1" : "0" ?>" title="<?= e($rowPreviewTitle !== "" ? $rowPreviewTitle : ($rowUnread > 0 ? $rowUnread . " new message(s)" : "No new messages")) ?>">
                                                            <i class="bi <?= $rowUnread > 0 ? "bi-chat-dots-fill" : ($hasThread ? "bi-chat-text" : "bi-chat") ?>"></i>
                                                            <span data-msg-pill-label><?= $rowUnread > 0 ? ("New message" . ($rowUnread > 1 ? "s (" . $rowUnread . ")" : "")) : ($hasThread ? "Messages" : "No new messages") ?></span>
                                                        </span>

                                                        <?php if (!empty($row["same_office"])): ?>
                                                            <span class="ownership-badge ownership-office">
                                                                <i class="bi bi-people"></i>
                                                                Same Office · View only
                                                            </span>

                                                            <div class="office-context">
                                                                <?php if (!empty($row["office_name"])): ?>
                                                                    <span>
                                                                        <i class="bi bi-building"></i>
                                                                        <?= e((string) ($row["office_name"] ?? "")) ?>
                                                                    </span>
                                                                <?php endif; ?>

                                                                <span>
                                                                    <i class="bi bi-person"></i>
                                                                    Submitted by
                                                                    <?= e((string) ($row["submitted_name"] ?? "Office colleague")) ?>
                                                                </span>
                                                            </div>
                                                        <?php endif; ?>

                                                        <div class="request-meta mt-1">
                                                            <span>
                                                                <?= e(
                                                                    mb_strimwidth(
                                                                        (string) $row["type_label"],
                                                                        0,
                                                                        100,
                                                                        "…"
                                                                    )
                                                                ) ?>
                                                            </span>
                                                        </div>

                                                    </div>

                                                </div>

                                                <div class="workflow-area">

                                                    <div
                                                        class="workflow-track <?= $isPublication ? "publication-track" : "" ?>">

                                                        <?php foreach (
                                                            $stages
                                                            as $index =>
                                                            $stage
                                                        ): ?>

                                                            <?php
                                                            $stepClass =
                                                                "";

                                                            if (
                                                                $activeStage
                                                                >=
                                                                0
                                                            ) {
                                                                if (
                                                                    $index
                                                                    <
                                                                    $activeStage
                                                                ) {
                                                                    $stepClass =
                                                                        "done";
                                                                } elseif (
                                                                    $index
                                                                    ===
                                                                    $activeStage
                                                                ) {
                                                                    $stepClass =
                                                                        "active";
                                                                }
                                                            }
                                                            ?>

                                                            <div class="workflow-step <?= e($stepClass) ?>">
                                                                <div class="workflow-dot">
                                                                    <i class="bi <?= e($stage["icon"]) ?>"></i>
                                                                </div>

                                                                <div class="workflow-label">
                                                                    <?= e($stage["label"]) ?>
                                                                </div>
                                                            </div>

                                                        <?php endforeach; ?>

                                                    </div>

                                                    <div class="progress-row">

                                                        <div class="request-progress">
                                                            <span style="width: <?= $progress ?>%"></span>
                                                        </div>

                                                        <div class="progress-label">
                                                            <?= $progress ?>% complete
                                                        </div>

                                                    </div>

                                                    <?php if ($isPublication): ?>

                                                        <?php if (
                                                            canEvaluatePublication(
                                                                $row
                                                            )
                                                        ): ?>

                                                            <div class="publication-note text-warning">
                                                                <i class="bi bi-clipboard2-check"></i>
                                                                Client Experience Evaluation is ready to complete.
                                                            </div>

                                                        <?php else: ?>

                                                            <div class="publication-note">
                                                                <i class="bi bi-bell"></i>
                                                                Status changes are sent to Notifications.
                                                            </div>

                                                        <?php endif; ?>

                                                    <?php elseif (
                                                        $isJob
                                                        &&
                                                        in_array(
                                                            $status,
                                                            [
                                                                "Pending Review",
                                                                "Under Review",
                                                                "For Revision"
                                                            ],
                                                            true
                                                        )
                                                    ): ?>

                                                        <div class="publication-note">
                                                            <i class="bi bi-hourglass-split"></i>
                                                            Processing lead time starts only after supervisor approval.
                                                        </div>

                                                    <?php elseif (
                                                        $isJob
                                                        &&
                                                        canEvaluate(
                                                            $row
                                                        )
                                                    ): ?>

                                                        <div class="publication-note text-warning">
                                                            <i class="bi bi-clipboard2-check"></i>
                                                            Client Experience Evaluation is ready to complete.
                                                        </div>

                                                    <?php elseif (
                                                        $isJob
                                                        &&
                                                        canViewEvaluation(
                                                            $row
                                                        )
                                                    ): ?>

                                                        <div class="evaluation-status-note">
                                                            <i class="bi bi-check-circle-fill"></i>
                                                            Client Experience Evaluation submitted.
                                                        </div>

                                                    <?php endif; ?>

                                                </div>

                                                <div class="request-side">

                                                    <div class="side-data">

                                                        <?php if ($isJob): ?>

                                                            <div class="date-stack">

                                                                <div class="date-stack-item">
                                                                    <div class="date-stack-label">
                                                                        Preferred Completion
                                                                    </div>

                                                                    <div class="date-stack-value">
                                                                        <i class="bi bi-calendar-event me-1"></i>

                                                                        <?= !empty($row["preferred_date"])
                                                                            ? date(
                                                                                "M d, Y",
                                                                                strtotime(
                                                                                    $row["preferred_date"]
                                                                                )
                                                                            )
                                                                            : "Not specified"
                                                                            ?>
                                                                    </div>
                                                                </div>

                                                                <?php if (!empty($due["target_date"])): ?>
                                                                    <div class="date-stack-item">
                                                                        <div class="date-stack-label">
                                                                            Processing Target
                                                                        </div>

                                                                        <div class="date-stack-value processing">
                                                                            <i class="bi bi-calendar2-check me-1"></i>
                                                                            <?= date(
                                                                                "M d, Y",
                                                                                strtotime(
                                                                                    $due["target_date"]
                                                                                )
                                                                            ) ?>
                                                                        </div>

                                                                        <?php if (!empty($row["approved_date"])): ?>
                                                                            <div class="approval-meta">
                                                                                <i class="bi bi-patch-check-fill"></i>
                                                                                Approved
                                                                                <?= date(
                                                                                    "M d, Y",
                                                                                    strtotime(
                                                                                        $row["approved_date"]
                                                                                    )
                                                                                ) ?>

                                                                                <?php if (
                                                                                    (int) ($row["lead_days"] ?? 0)
                                                                                    >
                                                                                    0
                                                                                ): ?>
                                                                                    •
                                                                                    <?= (int) $row["lead_days"] ?>
                                                                                    working day minimum lead time
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                <?php endif; ?>

                                                            </div>

                                                        <?php else: ?>

                                                            <div class="side-label">
                                                                Preferred publication
                                                            </div>

                                                            <div class="side-date">
                                                                <i class="bi bi-calendar-event me-1"></i>

                                                                <?= !empty($row["preferred_date"])
                                                                    ? date(
                                                                        "M d, Y",
                                                                        strtotime(
                                                                            $row["preferred_date"]
                                                                        )
                                                                    )
                                                                    : "Not specified"
                                                                    ?>
                                                            </div>

                                                        <?php endif; ?>

                                                        <div class="due-text <?= e($due["class"]) ?>">
                                                            <i class="bi <?= e($due["icon"]) ?>"></i>
                                                            <?= e($due["text"]) ?>
                                                        </div>

                                                    </div>

                                                    <div>

                                                        <span class="status-badge <?= e(requestStatusClass($status)) ?>">
                                                            <i class="bi <?= e(requestStatusIcon($status)) ?>"></i>
                                                            <?= e($status) ?>
                                                        </span>

                                                        <div class="request-actions">

                                                            <a class="btn btn-outline-primary btn-has-badge"
                                                                href="<?= e($row["view_url"]) ?>" title="View request<?= $rowUnread > 0 ? " — " . $rowUnread . " new message(s)" : "" ?>">
                                                                <i class="bi bi-eye"></i>
                                                                <span class="msg-badge <?= $rowUnread > 0 ? "" : "d-none" ?>" data-msg-badge><?= $rowUnread > 99 ? "99+" : (int) $rowUnread ?></span>
                                                            </a>

                                                            <?php if (
                                                                $isPublication &&
                                                                !empty($row["track_url"])
                                                            ): ?>

                                                                <a class="btn btn-outline-secondary"
                                                                    href="<?= e($row["track_url"]) ?>"
                                                                    target="_blank"
                                                                    rel="noopener"
                                                                    title="Open public tracking page (no sign-in required)">
                                                                    <i class="bi bi-upc-scan"></i>
                                                                </a>

                                                            <?php endif; ?>

                                                            <?php if ($isPublication && !empty($row["is_owner"])): ?>
                                                                <?php
                                                                $pubChatKey = $source . ":" . (int) $row["id"];
                                                                $pubChatUnread = $pubUnread[$pubChatKey] ?? 0;
                                                                ?>
                                                                <a class="btn btn-outline-info position-relative"
                                                                    href="<?= e($row["view_url"]) ?>#pubConversation"
                                                                    title="Open conversation">
                                                                    <i class="bi bi-chat-dots"></i>
                                                                    <?php if ($pubChatUnread > 0): ?>
                                                                        <span
                                                                            class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                                                            <?= $pubChatUnread > 9 ? "9+" : (int) $pubChatUnread ?>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </a>
                                                            <?php endif; ?>

                                                            <?php if (
                                                                canEditRequest(
                                                                    $row
                                                                )
                                                            ): ?>

                                                                <?php if (
                                                                    $isJob
                                                                ): ?>

                                                                    <a class="btn btn-outline-warning"
                                                                        href="<?= e(rtrim(BASE_URL, "/") . "/user/edit_request.php?id=" . (int) $row["id"]) ?>"
                                                                        title="Edit request">
                                                                        <i class="bi bi-pencil-square"></i>
                                                                    </a>

                                                                <?php else: ?>

                                                                    <a class="btn btn-outline-warning"
                                                                        href="<?= e($row["view_url"] . "&edit=1") ?>"
                                                                        title="Edit submission">
                                                                        <i class="bi bi-pencil-square"></i>
                                                                    </a>

                                                                <?php endif; ?>

                                                            <?php endif; ?>

                                                            <?php if (
                                                                $isJob
                                                                &&
                                                                $row["print_url"]
                                                                !==
                                                                ""
                                                            ): ?>

                                                                <a class="btn btn-outline-dark"
                                                                    href="<?= e($row["print_url"]) ?>" target="_blank"
                                                                    title="Print request">
                                                                    <i class="bi bi-printer"></i>
                                                                </a>

                                                            <?php endif; ?>

                                                            <?php if (
                                                                canArchiveJobRequest(
                                                                    $row,
                                                                    $isHpuArchiver
                                                                )
                                                            ): ?>

                                                                <button type="button"
                                                                    class="btn btn-outline-secondary archive-request-button"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#archiveRequestModal"
                                                                    data-id="<?= (int) $row["id"] ?>"
                                                                    data-job-no="<?= e($row["code"] ?? "") ?>"
                                                                    data-project="<?= e($row["title"] ?? "") ?>"
                                                                    title="Archive job request (HPU)">
                                                                    <i class="bi bi-archive"></i>
                                                                </button>

                                                            <?php endif; ?>

                                                            <?php if (
                                                                canEvaluate(
                                                                    $row
                                                                )
                                                                ||
                                                                canEvaluatePublication(
                                                                    $row
                                                                )
                                                            ): ?>

                                                                <a class="btn btn-success btn-evaluate"
                                                                    href="<?= e($row["survey_url"]) ?>"
                                                                    title="Complete Client Experience Evaluation">
                                                                    <i class="bi bi-clipboard2-check"></i>
                                                                    Evaluate
                                                                </a>

                                                            <?php elseif (
                                                                canViewEvaluation(
                                                                    $row
                                                                )
                                                                ||
                                                                canViewPubEvaluation(
                                                                    $row
                                                                )
                                                            ): ?>

                                                                <a class="btn btn-outline-success btn-view-evaluation"
                                                                    href="<?= e($row["survey_url"]) ?>"
                                                                    title="View submitted Client Experience Evaluation">
                                                                    <i class="bi bi-clipboard-check"></i>

                                                                </a>

                                                            <?php endif; ?>

                                                        </div>

                                                    </div>

                                                </div>

                                            </div>

                                        </div>

                                    </article>

                                </div>

                            <?php endforeach; ?>

                            <?php if (
                                $sameOfficeCount === 0
                                &&
                                $myRequestsCount > 0
                            ): ?>

                                <div class="request-group-head same">
                                    <div>
                                        <strong>
                                            <i class="bi bi-people"></i>
                                            Same-Office Requests
                                        </strong>

                                        <span>
                                            No requests from my office yet
                                        </span>
                                    </div>
                                </div>

                                <div class="request-group-empty">
                                    Requests submitted by colleagues in your office will appear here (view only).
                                </div>

                            <?php endif; ?>

                            <?php if (
                                empty(
                                $allRequests
                            )
                            ): ?>

                                <div class="empty-state">

                                    <div class="empty-icon">
                                        <i class="bi bi-inbox"></i>
                                    </div>

                                    <h4>
                                        No requests yet
                                    </h4>

                                    <p>
                                        Your HPU Job Requests and Publication Submissions
                                        will appear here.
                                    </p>

                                    <div class="d-flex gap-2 justify-content-center flex-wrap">

                                        <a class="btn btn-primary" href="new_request.php">
                                            <i class="bi bi-plus-circle me-1"></i>
                                            New Job Request
                                        </a>

                                        <a class="btn btn-outline-primary"
                                            href="<?= e(BASE_URL) ?>/content_submission/event_activity.php">
                                            <i class="bi bi-newspaper me-1"></i>
                                            Publication Submission
                                        </a>

                                    </div>

                                </div>

                            <?php endif; ?>

                        </div>

                        <div id="noSearchResults" class="empty-state d-none">
                            <div class="empty-icon">
                                <i class="bi bi-search"></i>
                            </div>

                            <h4>
                                No matching requests
                            </h4>

                            <p>
                                Try another request number, title, type, status, or filter.
                            </p>
                        </div>

                        <?php if (
                            !empty(
                            $allRequests
                        )
                        ): ?>

                            <div class="results-footer">
                                <span id="resultsCount">
                                    Showing <?= $totalRequests ?> request(s)
                                </span>

                                <span>
                                    Job, publication, and visible same-office records are combined by newest submission. Office activities are managed in the Calendar of Activities.
                                </span>
                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            </main>

        </div>

    </div>

    <div id="copyToast" class="toast-copy">
        <i class="bi bi-check-circle me-1"></i>
        Request number copied
    </div>

    <?php if ($isHpuArchiver): ?>
        <div class="modal fade" id="archiveRequestModal" tabindex="-1" aria-labelledby="archiveRequestModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="<?= e(BASE_URL) ?>/staff/archive_request.php">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                        <input type="hidden" id="archive_request_id" name="request_id">
                        <input type="hidden" name="return_to" value="my_requests">

                        <div class="modal-header">
                            <h5 class="modal-title" id="archiveRequestModalLabel">
                                <i class="bi bi-archive me-2 text-warning"></i>
                                Archive Job Request
                            </h5>

                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>

                        <div class="modal-body">
                            <p class="mb-3">
                                Move this job request to the archive?
                            </p>

                            <div class="alert alert-light border py-2">
                                <strong id="archive_job_number"></strong>
                                <div id="archive_project_title" class="text-muted small mt-1"></div>
                            </div>

                            <div class="alert alert-warning mb-0 small">
                                <i class="bi bi-info-circle me-1"></i>
                                The request will be removed from the active list and kept in the
                                Archived Requests vault for record-keeping.
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                Cancel
                            </button>

                            <button type="submit" class="btn btn-warning">
                                <i class="bi bi-archive me-1"></i>
                                Archive Request
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener(
            "DOMContentLoaded",
            function () {

                const searchInput =
                    document.getElementById(
                        "requestSearch"
                    );

                const items =
                    Array.from(
                        document.querySelectorAll(
                            ".request-item"
                        )
                    );

                const filterButtons =
                    document.querySelectorAll(
                        ".filter-tab"
                    );

                const noResults =
                    document.getElementById(
                        "noSearchResults"
                    );

                const resultsCount =
                    document.getElementById(
                        "resultsCount"
                    );

                let activeFilter =
                    "all";

                function applyFilters() {

                    const query =
                        (
                            searchInput?.value
                            ||
                            ""
                        )
                            .trim()
                            .toLowerCase();

                    let visible = 0;

                    // Unread-first: sort items so unread float to top when active.
                    let ordered = items.slice();

                    if (activeFilter === "unread") {
                        ordered.sort(function (a, b) {
                            return ((+b.dataset.unread) || 0) - (((+a.dataset.unread)) || 0);
                        });

                        const list = document.getElementById("requestList");

                        if (list) {
                            ordered.forEach(function (el) { list.appendChild(el); });
                        }
                    }

                    items.forEach(
                        function (item) {

                            const matchesSearch =
                                (
                                    item.dataset.search
                                    ||
                                    ""
                                )
                                    .includes(
                                        query
                                    );

                            let matchesFilter =
                                true;

                            if (activeFilter === "unread") {
                                matchesFilter = ((+item.dataset.unread) || 0) > 0;
                            } else if (
                                activeFilter
                                ===
                                "job"
                                ||
                                activeFilter
                                ===
                                "publication"
                            ) {
                                matchesFilter =
                                    item.dataset.kind
                                    ===
                                    activeFilter;
                            } else if (
                                activeFilter
                                !==
                                "all"
                            ) {
                                matchesFilter =
                                    item.dataset.filter
                                    ===
                                    activeFilter;
                            }

                            const show =
                                matchesSearch
                                &&
                                matchesFilter;

                            item.classList.toggle(
                                "d-none",
                                !show
                            );

                            if (show) {
                                visible++;
                            }
                        }
                    );

                    if (noResults) {
                        noResults.classList.toggle(
                            "d-none",
                            visible > 0
                            ||
                            items.length === 0
                        );
                    }

                    if (resultsCount) {
                        resultsCount.textContent =
                            `Showing ${visible} of ${items.length} request(s)`;
                    }
                }

                searchInput?.addEventListener(
                    "input",
                    applyFilters
                );

                filterButtons.forEach(
                    function (button) {

                        button.addEventListener(
                            "click",
                            function () {

                                filterButtons.forEach(
                                    function (btn) {
                                        btn.classList.remove(
                                            "active"
                                        );
                                    }
                                );

                                this.classList.add(
                                    "active"
                                );

                                activeFilter =
                                    this.dataset.filter
                                    ||
                                    "all";

                                applyFilters();
                            }
                        );
                    }
                );

                document
                    .querySelectorAll(
                        "[data-copy]"
                    )
                    .forEach(
                        function (button) {

                            button.addEventListener(
                                "click",
                                async function () {

                                    try {
                                        await navigator.clipboard.writeText(
                                            this.dataset.copy
                                            ||
                                            ""
                                        );

                                        const toast =
                                            document.getElementById(
                                                "copyToast"
                                            );

                                        if (toast) {
                                            toast.style.display =
                                                "block";

                                            clearTimeout(
                                                window.copyToastTimer
                                            );

                                            window.copyToastTimer =
                                                setTimeout(
                                                    function () {
                                                        toast.style.display =
                                                            "none";
                                                    },
                                                    1800
                                                );
                                        }

                                    } catch (error) {
                                        console.error(
                                            "Copy failed",
                                            error
                                        );
                                    }
                                }
                            );
                        }
                    );

                function updateClock() {
                    const now =
                        new Date();

                    const el =
                        document.getElementById(
                            "currentTime"
                        );

                    if (el) {
                        el.textContent =
                            now.toLocaleTimeString(
                                [],
                                {
                                    hour:
                                        "2-digit",

                                    minute:
                                        "2-digit"
                                }
                            );
                    }
                }

                updateClock();

                setInterval(
                    updateClock,
                    30000
                );

                // Archive modal wiring (HPU staff / supervisor / admin).
                document.querySelectorAll(".archive-request-button").forEach(function (button) {
                    button.addEventListener("click", function () {
                        const requestIdField = document.getElementById("archive_request_id");
                        const jobNumberField = document.getElementById("archive_job_number");
                        const projectTitleField = document.getElementById("archive_project_title");

                        if (requestIdField) requestIdField.value = this.dataset.id || "";
                        if (jobNumberField) jobNumberField.textContent = this.dataset.jobNo || this.dataset.job_no || "";
                        if (projectTitleField) projectTitleField.textContent = this.dataset.project || "";
                    });
                });

                /* ===== New-message live polling (30s, jobs + pubs) ===== */
                (function initMsgPolling() {
                    const baseUrl = <?= json_encode(rtrim(BASE_URL, "/"), JSON_UNESCAPED_SLASHES) ?>;
                    const countEl = document.getElementById("unreadFilterCount");

                    /* BroadcastChannel: listen for "marked_read" from other tabs */
                    if (typeof BroadcastChannel !== "undefined") {
                        const bc = new BroadcastChannel("hpu_chat_read");
                        bc.onmessage = function (e) {
                            if (e.data && e.data.type === "marked_read") {
                                poll();
                            }
                        };
                    }

                    function updateSnippet(item, count, prev) {
                        let snippet = item.querySelector(".msg-snippet");

                        if (prev && prev.message) {
                            const text = (prev.sender ? prev.sender + ": " : "") + prev.message;
                            const isUnread = count > 0;

                            if (!snippet) {
                                snippet = document.createElement("a");
                                snippet.className = "msg-snippet" + (isUnread ? "" : " is-read");
                                const viewBtn = item.querySelector('.request-actions a[href*="view"]');
                                if (viewBtn && viewBtn.href) {
                                    const src = item.getAttribute("data-source");
                                    const rid = item.getAttribute("data-id");
                                    snippet.href = (src === "job")
                                        ? viewBtn.href.split("#")[0] + "#conversation"
                                        : viewBtn.href.split("#")[0] + "#pubConversation";
                                    void rid;
                                }
                                snippet.innerHTML = '<i class="bi bi-chat-left-text-fill"></i>'
                                    + '<span class="msg-snippet-body"><span class="msg-snippet-eyebrow"></span><span class="msg-snippet-text"></span></span>'
                                    + '<span class="msg-snippet-go">Open <i class="bi bi-arrow-right"></i></span>';
                                const titleEl = item.querySelector(".project-title");
                                if (titleEl && titleEl.parentNode) {
                                    titleEl.parentNode.insertBefore(snippet, titleEl.nextSibling);
                                } else {
                                    item.appendChild(snippet);
                                }
                            }

                            snippet.style.display = "";
                            snippet.classList.toggle("is-read", !isUnread);
                            const icon = snippet.querySelector(":scope > i");
                            if (icon) icon.className = "bi " + (isUnread ? "bi-chat-left-text-fill" : "bi-chat-left-text");
                            const eyebrow = snippet.querySelector(".msg-snippet-eyebrow");
                            if (eyebrow) eyebrow.textContent = isUnread ? "New message" : "Latest message";
                            snippet.querySelector(".msg-snippet-text").textContent = text;
                            snippet.title = isUnread
                                ? "Read message and open the conversation"
                                : "Open the conversation";
                        } else if (snippet) {
                            snippet.style.display = "none";
                        }
                    }

                    function applyJobMap(unread, latest) {
                        unread = unread || {};
                        latest = latest || {};
                        let distinct = 0;

                        document.querySelectorAll('.request-item[data-source="job"]').forEach(function (item) {
                            const id = item.getAttribute("data-id");
                            const count = Math.max(0, parseInt(unread[id] || unread[+id] || 0, 10) || 0);
                            if (count > 0) distinct++;
                            item.setAttribute("data-unread", String(count));

                            const badge = item.querySelector("[data-msg-badge]");
                            if (badge) {
                                badge.textContent = count > 99 ? "99+" : String(count);
                                badge.classList.toggle("d-none", count <= 0);
                            }

                            const pill = item.querySelector("[data-msg-pill]");
                            if (pill) {
                                pill.classList.toggle("has-unread", count > 0);
                                const label = pill.querySelector("[data-msg-pill-label]");
                                const prev = latest[id] || latest[+id];
                                const hasThread = !!(prev && prev.message) || pill.getAttribute("data-has-thread") === "1";
                                if (hasThread) pill.setAttribute("data-has-thread", "1");
                                pill.classList.toggle("has-thread", count <= 0 && hasThread);
                                const icon = pill.querySelector(":scope > i");
                                if (icon) icon.className = "bi " + (count > 0 ? "bi-chat-dots-fill" : (hasThread ? "bi-chat-text" : "bi-chat"));
                                if (label) label.textContent = count > 0
                                    ? (count > 1 ? ("New messages (" + count + ")") : "New message")
                                    : (hasThread ? "Messages" : "No new messages");
                                pill.title = prev && prev.message
                                    ? ((prev.sender ? prev.sender + ": " : "") + prev.message)
                                    : (count > 0 ? (count + " new message(s)") : "No new messages");
                                updateSnippet(item, count, prev);
                            }
                        });

                        return distinct;
                    }

                    function applyPubMap(pubUnread, pubLatest) {
                        pubUnread = pubUnread || {};
                        pubLatest = pubLatest || {};
                        let distinct = 0;

                        document.querySelectorAll('.request-item[data-source="event"], .request-item[data-source="recognition"], .request-item[data-source="contribution"]').forEach(function (item) {
                            const key = item.getAttribute("data-source") + ":" + item.getAttribute("data-id");
                            const count = Math.max(0, parseInt(pubUnread[key] || 0, 10) || 0);
                            if (count > 0) distinct++;
                            item.setAttribute("data-unread", String(count));

                            const badge = item.querySelector("[data-msg-badge]");
                            if (badge) {
                                badge.textContent = count > 99 ? "99+" : String(count);
                                badge.classList.toggle("d-none", count <= 0);
                            }

                            const pill = item.querySelector("[data-msg-pill]");
                            const prev = pubLatest[key];
                            if (pill) {
                                pill.classList.toggle("has-unread", count > 0);
                                const hasThread = !!(prev && prev.message) || pill.getAttribute("data-has-thread") === "1";
                                if (hasThread) pill.setAttribute("data-has-thread", "1");
                                pill.classList.toggle("has-thread", count <= 0 && hasThread);
                                const icon = pill.querySelector(":scope > i");
                                if (icon) icon.className = "bi " + (count > 0 ? "bi-chat-dots-fill" : (hasThread ? "bi-chat-text" : "bi-chat"));
                                const label = pill.querySelector("[data-msg-pill-label]");
                                if (label) label.textContent = count > 0
                                    ? (count > 1 ? ("New messages (" + count + ")") : "New message")
                                    : (hasThread ? "Messages" : "No new messages");
                                pill.title = prev && prev.message
                                    ? ((prev.sender ? prev.sender + ": " : "") + prev.message)
                                    : (count > 0 ? (count + " new message(s)") : "No new messages");
                            }
                            updateSnippet(item, count, prev);
                        });

                        return distinct;
                    }

                    async function poll() {
                        try {
                            const jobIds = [];
                            document.querySelectorAll('.request-item[data-source="job"]').forEach(function (item) {
                                const id = parseInt(item.getAttribute("data-id") || "0", 10);
                                if (id > 0) jobIds.push(id);
                            });
                            const pubKeys = [];
                            document.querySelectorAll('.request-item[data-source="event"], .request-item[data-source="recognition"], .request-item[data-source="contribution"]').forEach(function (item) {
                                pubKeys.push(item.getAttribute("data-source") + ":" + item.getAttribute("data-id"));
                            });
                            const qs = new URLSearchParams({ scope: "all" });
                            if (jobIds.length) qs.set("ids", jobIds.join(","));
                            if (pubKeys.length) qs.set("keys", pubKeys.join(","));
                            const res = await fetch(baseUrl + "/ajax/chat_unread.php?" + qs.toString(), { credentials: "same-origin" });
                            const data = await res.json();
                            if (!data || !data.ok) return;
                            const j = applyJobMap(data.unread, data.latest);
                            const p = applyPubMap(data.pub_unread, data.pub_latest);
                            if (countEl) countEl.textContent = String(j + p);
                        } catch (e) { /* keep old badges */ }
                    }

                    setInterval(poll, 30000);
                    document.addEventListener("visibilitychange", function () {
                        if (!document.hidden) poll();
                    });
                })();

            }
        );
    </script>

</body>

</html>