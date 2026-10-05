<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

requireRole([
    "staff",
    "supervisor",
    "admin"
]);
requireModule($conn, "calendar");

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

/* =========================================================
   OPTIONAL COLUMN CHECK
========================================================= */
function calendarColumnExists(
    mysqli $conn,
    string $column
): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'job_requests'
           AND COLUMN_NAME = ?"
    );

    $stmt->bind_param(
        "s",
        $column
    );

    $stmt->execute();

    $row = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    return (int) (
        $row["total"] ?? 0
    ) > 0;
}

$hasPriority = calendarColumnExists(
    $conn,
    "priority"
);

$hasArchived = calendarColumnExists(
    $conn,
    "archived"
);

$hasAssignedTo = calendarColumnExists(
    $conn,
    "assigned_to"
);

$hasEventDatetime = calendarColumnExists(
    $conn,
    "event_datetime"
);

$hasEventEnd = calendarColumnExists(
    $conn,
    "event_datetime_end"
);

$hasInitialDraftDate = calendarColumnExists(
    $conn,
    "initial_draft_date"
);

$hasFinalApprovalDate = calendarColumnExists(
    $conn,
    "final_approval_date"
);

/* Only show deadlines approved by supervisor (unit head) */
$hasUnitHeadStatus = calendarColumnExists(
    $conn,
    "unit_head_status"
);

$unitHeadCondition = $hasUnitHeadStatus
    ? "AND jr.unit_head_status = 'Approved'"
    : "";

/* =========================================================
   FILTERS
========================================================= */
$departmentFilter = trim(
    (string) ($_GET["department"] ?? "")
);

$statusFilter = trim(
    (string) ($_GET["status"] ?? "")
);

$priorityFilter = trim(
    (string) ($_GET["priority"] ?? "")
);

$dateType = trim(
    (string) ($_GET["date_type"] ?? "completion")
);

$allowedDateTypes = [
    "completion",
    "event",
    "request",
    "draft",
    "approval"
];

if (
    !in_array(
        $dateType,
        $allowedDateTypes,
        true
    )
) {
    $dateType = "completion";
}

/* =========================================================
   DATE FIELD
========================================================= */
$dateField = match ($dateType) {
    "event" =>
        $hasEventDatetime
            ? "jr.event_datetime"
            : "jr.date_completion",

    "request" =>
        "jr.date_request",

    "draft" =>
        $hasInitialDraftDate
            ? "jr.initial_draft_date"
            : "jr.date_completion",

    "approval" =>
        $hasFinalApprovalDate
            ? "jr.final_approval_date"
            : "jr.date_completion",

    default =>
        "jr.date_completion"
};

$dateTypeLabel = match ($dateType) {
    "event" =>
        "Event Date",

    "request" =>
        "Request Date",

    "draft" =>
        "Initial Draft Date",

    "approval" =>
        "Final Approval Date",

    default =>
        "Completion Deadline"
};

/* =========================================================
   SAFE SELECT EXPRESSIONS
========================================================= */
$prioritySelect = $hasPriority
    ? "COALESCE(jr.priority, 'Normal') AS priority"
    : "'Normal' AS priority";

$assignedSelect = $hasAssignedTo
    ? "jr.assigned_to, assigned_user.name AS assigned_name"
    : "NULL AS assigned_to, NULL AS assigned_name";

$assignedJoin = $hasAssignedTo
    ? "LEFT JOIN users assigned_user
         ON assigned_user.id = jr.assigned_to"
    : "";

$eventEndSelect = $hasEventEnd
    ? "jr.event_datetime_end AS event_end"
    : "NULL AS event_end";

$archivedCondition = $hasArchived
    ? "AND COALESCE(jr.archived, 0) = 0"
    : "";

/* =========================================================
   REQUEST QUERY
   IMPORTANT: Do not compare DATE columns with an empty string.
========================================================= */
$sql = "
    SELECT
        jr.id,
        jr.job_no,
        jr.project_title,
        jr.requestor_name,
        jr.department,
        jr.approval_status,
        jr.date_request,
        jr.date_completion,
        {$dateField} AS calendar_date,
        {$eventEndSelect},
        {$prioritySelect},
        {$assignedSelect}
    FROM job_requests jr
    {$assignedJoin}
    WHERE {$dateField} IS NOT NULL
      {$archivedCondition}
      {$unitHeadCondition}
";

$params = [];
$types = "";

if ($departmentFilter !== "") {
    $sql .= "
        AND jr.department = ?
    ";

    $params[] = $departmentFilter;
    $types .= "s";
}

if ($statusFilter !== "") {
    $sql .= "
        AND jr.approval_status = ?
    ";

    $params[] = $statusFilter;
    $types .= "s";
}

if (
    $priorityFilter !== "" &&
    $hasPriority
) {
    $sql .= "
        AND jr.priority = ?
    ";

    $params[] = $priorityFilter;
    $types .= "s";
}

$sql .= "
    ORDER BY
        {$dateField} ASC,
        jr.id DESC
";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param(
        $types,
        ...$params
    );
}

$stmt->execute();

$requests = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

/* =========================================================
   DEPARTMENT OPTIONS
========================================================= */
$departments = $conn->query(
    "SELECT DISTINCT department
     FROM job_requests
     WHERE department IS NOT NULL
       AND department <> ''
     ORDER BY department"
)->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   CALENDAR HELPERS
========================================================= */
function calendarStatusColor(
    string $status
): string {
    return match ($status) {
        "Pending Review" =>
            "#64748b",

        "Under Review" =>
            "#0891b2",

        "Approved" =>
            "#16a34a",

        "In Development" =>
            "#4f46e5",

        "For Revision" =>
            "#d97706",

        "Disapproved" =>
            "#dc2626",

        "Completed" =>
            "#2563eb",

        "For Evaluation" =>
            "#9333ea",

        "Closed" =>
            "#334155",

        default =>
            "#475569"
    };
}

function calendarDateValue(
    ?string $value
): ?string {
    if (!$value) {
        return null;
    }

    $timestamp = strtotime(
        $value
    );

    if ($timestamp === false) {
        return null;
    }

    return str_contains(
        $value,
        ":"
    )
        ? date(
            "Y-m-d\TH:i:s",
            $timestamp
        )
        : date(
            "Y-m-d",
            $timestamp
        );
}

/* =========================================================
   BUILD FULLCALENDAR EVENTS
========================================================= */
$events = [];

foreach ($requests as $request) {
    $start = calendarDateValue(
        $request["calendar_date"] ?? null
    );

    if (!$start) {
        continue;
    }

    /*
     * Multi-day events span every day from start to end
     * (FullCalendar end is exclusive: all-day ends shift +1 day).
     */
    $end = null;

    if (
        $dateType === "event"
        &&
        !empty($request["event_end"])
    ) {
        $endValue = calendarDateValue(
            $request["event_end"]
        );

        if (
            $endValue
            &&
            $endValue > $start
        ) {
            $end = str_contains($endValue, "T")
                ? $endValue
                : date(
                    "Y-m-d",
                    strtotime($endValue . " +1 day")
                );
        }
    }

    $status = $request["approval_status"];
    $color = calendarStatusColor(
        $status
    );

    $event = [
        "id" =>
            (string) $request["id"],

        "title" =>
            $request["job_no"] .
            " · " .
            $request["project_title"],

        "start" =>
            $start,

        "allDay" =>
            !str_contains(
                (string) $request["calendar_date"],
                ":"
            ),

        "backgroundColor" =>
            $color,

        "borderColor" =>
            $color,

        "textColor" =>
            "#ffffff",

        "extendedProps" => [
            "job_no" =>
                $request["job_no"],

            "project_title" =>
                $request["project_title"],

            "requestor_name" =>
                $request["requestor_name"],

            "department" =>
                $request["department"],

            "status" =>
                $status,

            "priority" =>
                $request["priority"],

            "assigned_name" =>
                $request["assigned_name"]
                ?: "Unassigned",

            "calendar_date" =>
                $request["calendar_date"],

            "view_url" =>
                rtrim(
                    BASE_URL,
                    "/"
                ) .
                "/admin/view.php?id=" .
                (int) $request["id"],

            "review_url" =>
                rtrim(
                    BASE_URL,
                    "/"
                ) .
                "/staff/review_request.php?id=" .
                (int) $request["id"]
        ]
    ];

    if ($end !== null) {
        $event["end"] = $end;
    }

    $events[] = $event;
}

/* =========================================================
   SUMMARY COUNTS
========================================================= */
$visibleEvents = count(
    $events
);

$pendingCount = count(
    array_filter(
        $requests,
        fn ($row) =>
            $row["approval_status"] ===
            "Pending Review"
    )
);

$developmentCount = count(
    array_filter(
        $requests,
        fn ($row) =>
            $row["approval_status"] ===
            "In Development"
    )
);

$completedCount = count(
    array_filter(
        $requests,
        fn ($row) =>
            in_array(
                $row["approval_status"],
                [
                    "Completed",
                    "For Evaluation",
                    "Closed"
                ],
                true
            )
    )
);
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
        Calendar View | HPU Workbench
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
        href="<?= e(BASE_URL) ?>/assets/css/calendar.css"
    >

</head>

<body class="app-shell">

<div class="d-flex min-vh-100">

    <?php include "../includes/sidebar.php"; ?>

    <div class="main flex-grow-1">

        <main class="content">

            <div class="container-fluid">

                <!-- PAGE HEADER -->
                <div class="ph">

                    <div class="ph-copy">
                        <div class="ph-icon"><i class="bi bi-calendar-check"></i></div>
                        <div class="min-w-0">
                            <p class="ph-eyebrow">HPU Workbench</p>
                            <h1 class="ph-title">Calendar View</h1>
                            <p class="ph-subtitle">
                                Monitor requests by
                                <?= e($dateTypeLabel) ?>.
                            </p>
                        </div>
                    </div>

                    <div class="ph-actions">

                        <button
                            class="btn btn-outline-secondary"
                            id="calendarTodayButton"
                            type="button"
                        >
                            <i class="bi bi-calendar-event me-1"></i>
                            Today
                        </button>

                        <a
                            class="btn btn-primary"
                            href="<?= e(BASE_URL) ?>/user/new_request.php"
                        >
                            <i class="bi bi-plus-circle me-1"></i>
                            New Request
                        </a>

                    </div>

                </div>

                <!-- SUMMARY -->
                <div class="row g-3 mb-4">

                    <div class="col-6 col-md-3">

                        <div class="calendar-summary-card">

                            <div class="calendar-summary-icon">
                                <i class="bi bi-calendar3"></i>
                            </div>

                            <div>

                                <div class="calendar-summary-label">
                                    Visible Events
                                </div>

                                <div class="calendar-summary-value">
                                    <?= $visibleEvents ?>
                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="col-6 col-md-3">

                        <div class="calendar-summary-card">

                            <div class="calendar-summary-icon">
                                <i class="bi bi-hourglass-split"></i>
                            </div>

                            <div>

                                <div class="calendar-summary-label">
                                    Pending
                                </div>

                                <div class="calendar-summary-value">
                                    <?= $pendingCount ?>
                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="col-6 col-md-3">

                        <div class="calendar-summary-card">

                            <div class="calendar-summary-icon">
                                <i class="bi bi-tools"></i>
                            </div>

                            <div>

                                <div class="calendar-summary-label">
                                    Development
                                </div>

                                <div class="calendar-summary-value">
                                    <?= $developmentCount ?>
                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="col-6 col-md-3">

                        <div class="calendar-summary-card">

                            <div class="calendar-summary-icon">
                                <i class="bi bi-check2-all"></i>
                            </div>

                            <div>

                                <div class="calendar-summary-label">
                                    Completed
                                </div>

                                <div class="calendar-summary-value">
                                    <?= $completedCount ?>
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- FILTERS -->
                <section class="card calendar-filter-card mb-4">

                    <div class="card-body">

                        <form method="GET">

                            <div class="row g-3 align-items-end">

                                <div class="col-md-6 col-xl-3">

                                    <label class="form-label">
                                        Calendar Date
                                    </label>

                                    <select
                                        class="form-select"
                                        name="date_type"
                                    >

                                        <option
                                            value="completion"
                                            <?= $dateType === "completion"
                                                ? "selected"
                                                : ""
                                            ?>
                                        >
                                            Completion Deadline
                                        </option>

                                        <option
                                            value="event"
                                            <?= $dateType === "event"
                                                ? "selected"
                                                : ""
                                            ?>
                                            <?= !$hasEventDatetime
                                                ? "disabled"
                                                : ""
                                            ?>
                                        >
                                            Event Date
                                        </option>

                                        <option
                                            value="request"
                                            <?= $dateType === "request"
                                                ? "selected"
                                                : ""
                                            ?>
                                        >
                                            Request Date
                                        </option>

                                        <option
                                            value="draft"
                                            <?= $dateType === "draft"
                                                ? "selected"
                                                : ""
                                            ?>
                                            <?= !$hasInitialDraftDate
                                                ? "disabled"
                                                : ""
                                            ?>
                                        >
                                            Initial Draft Date
                                        </option>

                                        <option
                                            value="approval"
                                            <?= $dateType === "approval"
                                                ? "selected"
                                                : ""
                                            ?>
                                            <?= !$hasFinalApprovalDate
                                                ? "disabled"
                                                : ""
                                            ?>
                                        >
                                            Final Approval Date
                                        </option>

                                    </select>

                                </div>

                                <div class="col-md-6 col-xl-3">

                                    <label class="form-label">
                                        Department
                                    </label>

                                    <select
                                        class="form-select"
                                        name="department"
                                    >

                                        <option value="">
                                            All Departments
                                        </option>

                                        <?php foreach (
                                            $departments as $department
                                        ): ?>

                                            <option
                                                value="<?= e(
                                                    $department["department"]
                                                ) ?>"
                                                <?= $departmentFilter ===
                                                $department["department"]
                                                    ? "selected"
                                                    : ""
                                                ?>
                                            >
                                                <?= e(
                                                    $department["department"]
                                                ) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-md-6 col-xl-2">

                                    <label class="form-label">
                                        Status
                                    </label>

                                    <select
                                        class="form-select"
                                        name="status"
                                    >

                                        <option value="">
                                            All Statuses
                                        </option>

                                        <?php foreach (
                                            [
                                                "Pending Review",
                                                "Under Review",
                                                "Approved",
                                                "In Development",
                                                "For Revision",
                                                "Disapproved",
                                                "Completed",
                                                "For Evaluation",
                                                "Closed"
                                            ] as $status
                                        ): ?>

                                            <option
                                                value="<?= e($status) ?>"
                                                <?= $statusFilter === $status
                                                    ? "selected"
                                                    : ""
                                                ?>
                                            >
                                                <?= e($status) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-md-6 col-xl-2">

                                    <label class="form-label">
                                        Priority
                                    </label>

                                    <select
                                        class="form-select"
                                        name="priority"
                                        <?= !$hasPriority
                                            ? "disabled"
                                            : ""
                                        ?>
                                    >

                                        <option value="">
                                            All Priorities
                                        </option>

                                        <?php foreach (
                                            [
                                                "Low",
                                                "Normal",
                                                "High",
                                                "Urgent"
                                            ] as $priority
                                        ): ?>

                                            <option
                                                value="<?= e($priority) ?>"
                                                <?= $priorityFilter ===
                                                $priority
                                                    ? "selected"
                                                    : ""
                                                ?>
                                            >
                                                <?= e($priority) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-xl-2">

                                    <div class="d-flex gap-2">

                                        <button
                                            class="btn btn-primary flex-grow-1"
                                            type="submit"
                                        >
                                            <i class="bi bi-funnel me-1"></i>
                                            Apply
                                        </button>

                                        <a
                                            class="btn btn-outline-secondary"
                                            href="calendar.php"
                                            title="Reset Filters"
                                        >
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                        </a>

                                    </div>

                                </div>

                            </div>

                        </form>

                    </div>

                </section>

                <!-- LEGEND -->
                <section class="calendar-legend mb-3">

                    <?php foreach (
                        [
                            "Pending Review",
                            "Under Review",
                            "Approved",
                            "In Development",
                            "For Revision",
                            "Completed",
                            "For Evaluation",
                            "Closed"
                        ] as $legendStatus
                    ): ?>

                        <span class="calendar-legend-item">

                            <span
                                class="calendar-legend-dot"
                                style="background: <?= e(
                                    calendarStatusColor(
                                        $legendStatus
                                    )
                                ) ?>;"
                            ></span>

                            <?= e($legendStatus) ?>

                        </span>

                    <?php endforeach; ?>

                </section>

                <!-- CALENDAR -->
                <section class="card calendar-shell">

                    <div class="card-body">

                        <div id="jobRequestCalendar"></div>

                    </div>

                </section>

            </div>

        </main>

    </div>

</div>

<!-- EVENT DETAILS MODAL -->
<div
    class="modal fade"
    id="calendarEventModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <div>

                    <div
                        class="calendar-modal-job-number"
                        id="calendarModalJobNumber"
                    ></div>

                    <h5
                        class="modal-title"
                        id="calendarModalTitle"
                    >
                        Request Details
                    </h5>

                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <div class="modal-body">

                <div class="calendar-modal-status-row">

                    <span
                        class="calendar-modal-status"
                        id="calendarModalStatus"
                    ></span>

                    <span
                        class="calendar-modal-priority"
                        id="calendarModalPriority"
                    ></span>

                </div>

                <div class="calendar-modal-grid">

                    <div class="calendar-modal-item">
                        <div class="calendar-modal-label">
                            Requestor
                        </div>
                        <div id="calendarModalRequestor"></div>
                    </div>

                    <div class="calendar-modal-item">
                        <div class="calendar-modal-label">
                            Department
                        </div>
                        <div id="calendarModalDepartment"></div>
                    </div>

                    <div class="calendar-modal-item">
                        <div class="calendar-modal-label">
                            Calendar Date
                        </div>
                        <div id="calendarModalDate"></div>
                    </div>

                    <div class="calendar-modal-item">
                        <div class="calendar-modal-label">
                            Assigned Staff
                        </div>
                        <div id="calendarModalAssigned"></div>
                    </div>

                </div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    data-bs-dismiss="modal"
                >
                    Close
                </button>

                <a
                    class="btn btn-outline-primary"
                    id="calendarModalViewButton"
                    href="#"
                >
                    <i class="bi bi-eye me-1"></i>
                    View
                </a>

                <a
                    class="btn btn-primary"
                    id="calendarModalReviewButton"
                    href="#"
                >
                    <i class="bi bi-clipboard-check me-1"></i>
                    Review
                </a>

            </div>

        </div>

    </div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
></script>

<script
    src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.21/index.global.min.js"
></script>

<script>
document.addEventListener(
    "DOMContentLoaded",
    function () {

        const calendarElement =
            document.getElementById(
                "jobRequestCalendar"
            );

        const modalElement =
            document.getElementById(
                "calendarEventModal"
            );

        const eventModal =
            new bootstrap.Modal(
                modalElement
            );

        const calendar =
            new FullCalendar.Calendar(
                calendarElement,
                {
                    initialView:
                        window.innerWidth < 768
                            ? "listMonth"
                            : "dayGridMonth",

                    height: "auto",

                    firstDay: 1,

                    nowIndicator: true,

                    dayMaxEvents: 3,

                    eventDisplay: "block",

                    displayEventTime: true,

                    eventTimeFormat: {
                        hour: "numeric",
                        minute: "2-digit",
                        meridiem: "short"
                    },

                    headerToolbar: {
                        left:
                            "prev,next today",

                        center:
                            "title",

                        right:
                            "dayGridMonth,timeGridWeek,listMonth"
                    },

                    buttonText: {
                        today:
                            "Today",

                        month:
                            "Month",

                        week:
                            "Week",

                        list:
                            "List"
                    },

                    events:
                        <?= json_encode(
                            $events,
                            JSON_UNESCAPED_UNICODE |
                            JSON_UNESCAPED_SLASHES
                        ) ?>,

                    eventClick:
                        function (info) {
                            info.jsEvent
                                .preventDefault();

                            const props =
                                info.event
                                    .extendedProps;

                            document
                                .getElementById(
                                    "calendarModalJobNumber"
                                )
                                .textContent =
                                props.job_no;

                            document
                                .getElementById(
                                    "calendarModalTitle"
                                )
                                .textContent =
                                props.project_title;

                            document
                                .getElementById(
                                    "calendarModalRequestor"
                                )
                                .textContent =
                                props.requestor_name;

                            document
                                .getElementById(
                                    "calendarModalDepartment"
                                )
                                .textContent =
                                props.department;

                            document
                                .getElementById(
                                    "calendarModalStatus"
                                )
                                .textContent =
                                props.status;

                            document
                                .getElementById(
                                    "calendarModalPriority"
                                )
                                .textContent =
                                props.priority +
                                " Priority";

                            document
                                .getElementById(
                                    "calendarModalAssigned"
                                )
                                .textContent =
                                props.assigned_name;

                            const eventDate =
                                new Date(
                                    props.calendar_date
                                );

                            document
                                .getElementById(
                                    "calendarModalDate"
                                )
                                .textContent =
                                Number.isNaN(
                                    eventDate.getTime()
                                )
                                    ? props.calendar_date
                                    : eventDate
                                        .toLocaleString();

                            document
                                .getElementById(
                                    "calendarModalViewButton"
                                )
                                .href =
                                props.view_url;

                            document
                                .getElementById(
                                    "calendarModalReviewButton"
                                )
                                .href =
                                props.review_url;

                            eventModal.show();
                        }
                }
            );

        calendar.render();

        document
            .getElementById(
                "calendarTodayButton"
            )
            .addEventListener(
                "click",
                function () {
                    calendar.today();
                }
            );
    }
);
</script>

</body>
</html>
