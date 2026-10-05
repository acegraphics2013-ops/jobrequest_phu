<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - UPGRADED NOTIFICATIONS
|--------------------------------------------------------------------------
| Replace:
|   C:\laragon\www\jobrequest_phu\user\notifications.php
|
| Supports:
|   - ordinary requesting users
|   - HPU Staff
|   - Supervisor
|   - Admin
|   - request status notifications
|   - assignment notifications
|   - evaluation / client experience survey notifications
|   - all / unread / type filters
|   - mark one as read by opening
|   - mark all as read
|--------------------------------------------------------------------------
*/

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

checkLogin();
requireModule($conn, "notifications");

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

$userId = (int)($_SESSION["user_id"] ?? 0);
$role = (string)($_SESSION["role"] ?? "user");
$userName = (string)($_SESSION["name"] ?? "User");

$isHpuRole = in_array(
    $role,
    ["staff", "supervisor", "admin"],
    true
);


/*
|--------------------------------------------------------------------------
| Backward-Compatible Notification Columns
|--------------------------------------------------------------------------
*/

function notificationColumnExists(
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

$notificationColumns = [
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
    $notificationColumns
    as $column =>
        $definition
) {
    if (
        !notificationColumnExists(
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

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function notificationTypeMeta(string $type): array
{
    return match ($type) {
        "assignment" => [
            "label" => "Assignment",
            "icon" => "bi-person-check",
            "class" => "type-assignment"
        ],

        "evaluation" => [
            "label" => "Evaluation",
            "icon" => "bi-clipboard2-check",
            "class" => "type-evaluation"
        ],

        "client_experience_survey" => [
            "label" => "Survey",
            "icon" => "bi-chat-square-heart",
            "class" => "type-survey"
        ],

        "publication_status" => [
            "label" => "Publication",
            "icon" => "bi-newspaper",
            "class" => "type-publication"
        ],

        "revision" => [
            "label" => "Revision",
            "icon" => "bi-arrow-repeat",
            "class" => "type-revision"
        ],

        "success" => [
            "label" => "Update",
            "icon" => "bi-check2-circle",
            "class" => "type-success"
        ],

        "warning" => [
            "label" => "Attention",
            "icon" => "bi-exclamation-triangle",
            "class" => "type-warning"
        ],

        default => [
            "label" => "Notification",
            "icon" => "bi-info-circle",
            "class" => "type-info"
        ]
    };
}

function notificationStatusClass(?string $status): string
{
    return match ((string)$status) {
        "Pending Review" => "status-pending",
        "Under Review" => "status-review",
        "Approved" => "status-approved",
        "For Revision" => "status-revision",
        "Disapproved" => "status-disapproved",
        "Completed" => "status-completed",
        "For Evaluation" => "status-evaluation",
        "Closed" => "status-closed",

        "For Review" => "status-review",
        "In Preparation" => "status-approved",
        "For Approval" => "status-evaluation",
        "Published" => "status-completed",

        default => "status-default"
    };
}

function notificationInternalUrl(string $url): string
{
    $url = trim($url);

    if ($url === "") {
        return "";
    }

    /*
     * Accept only URLs within this Workbench or root-relative paths.
     * This prevents a database value from becoming an external redirect.
     */
    if (str_starts_with($url, rtrim(BASE_URL, "/"))) {
        return $url;
    }

    if (str_starts_with($url, "/")) {
        return rtrim(BASE_URL, "/") . $url;
    }

    return "";
}

function notificationAction(
    array $notification,
    string $role
): array {
    $requestId = (int)($notification["request_id"] ?? 0);
    $status = trim((string)($notification["approval_status"] ?? ""));
    $type = trim((string)($notification["notification_type"] ?? "info"));
    $storedUrl = notificationInternalUrl(
        (string)($notification["action_url"] ?? "")
    );

    $publicationKind =
        trim(
            (string)(
                $notification["publication_kind"]
                ?? ""
            )
        );

    $publicationId =
        (int)(
            $notification["publication_id"]
            ?? 0
        );

    /*
    |--------------------------------------------------------------------------
    | Publication status notification
    |--------------------------------------------------------------------------
    */
    if (
        $type === "publication_status"
        &&
        $publicationId > 0
        &&
        in_array(
            $publicationKind,
            [
                "event",
                "recognition"
            ],
            true
        )
    ) {
        return [
            "url" =>
                rtrim(BASE_URL, "/")
                . "/user/view_publication.php?kind="
                . rawurlencode(
                    $publicationKind
                )
                . "&id="
                . $publicationId,

            "label" => "View Publication Request",
            "icon" => "bi-newspaper",
            "class" => "btn-primary"
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | HPU assignment notification
    |--------------------------------------------------------------------------
    */
    if (
        in_array($role, ["staff", "supervisor", "admin"], true)
        && $type === "assignment"
    ) {
        return [
            "url" => rtrim(BASE_URL, "/") . "/my_assigned_jobs.php",
            "label" => "View Assigned Jobs",
            "icon" => "bi-person-workspace",
            "class" => "btn-primary"
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | HPU personnel request-related notification
    |--------------------------------------------------------------------------
    */
    if (
        in_array($role, ["staff", "supervisor", "admin"], true)
        && $requestId > 0
    ) {
        return [
            "url" =>
                rtrim(BASE_URL, "/")
                . "/staff/review_request.php?id="
                . $requestId,

            "label" => "Open Job Request",
            "icon" => "bi-box-arrow-up-right",
            "class" => "btn-primary"
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Requesting user: explicit survey/evaluation notification types
    |--------------------------------------------------------------------------
    */
    if ($role === "user" && $requestId > 0) {
        if ($type === "client_experience_survey") {
            return [
                "url" =>
                    rtrim(BASE_URL, "/")
                    . "/user/client_experience_survey.php?id="
                    . $requestId,

                "label" => "Complete Survey",
                "icon" => "bi-chat-square-heart",
                "class" => "btn-success"
            ];
        }

        if ($type === "evaluation") {
            return [
                "url" =>
                    rtrim(BASE_URL, "/")
                    . "/user/evaluation.php?id="
                    . $requestId,

                "label" => "Proceed to Evaluation",
                "icon" => "bi-clipboard2-check",
                "class" => "btn-success"
            ];
        }

        /*
         * Pending Review / Under Review:
         * retain the behavior of the current system allowing the
         * requesting user to update the editable request sections.
         */
        if (
            in_array(
                $status,
                ["Pending Review", "Under Review"],
                true
            )
        ) {
            return [
                "url" =>
                    rtrim(BASE_URL, "/")
                    . "/user/edit_request.php?id="
                    . $requestId,

                "label" => "Update Request",
                "icon" => "bi-pencil-square",
                "class" => "btn-warning"
            ];
        }

        /*
         * Completed / For Evaluation:
         * where no more specific notification type exists,
         * lead the user to the client experience survey.
         */
        if (
            in_array(
                $status,
                ["Completed", "For Evaluation"],
                true
            )
        ) {
            return [
                "url" =>
                    rtrim(BASE_URL, "/")
                    . "/user/client_experience_survey.php?id="
                    . $requestId,

                "label" => "Proceed to Evaluation",
                "icon" => "bi-clipboard2-check",
                "class" => "btn-success"
            ];
        }

        /*
         * Approved / In Development are intentionally read-only
         * from the notification page.
         */
        if (
            in_array(
                $status,
                ["Approved", "In Development"],
                true
            )
        ) {
            return [
                "url" => "",
                "label" => "",
                "icon" => "",
                "class" => ""
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Safe stored action URL fallback
    |--------------------------------------------------------------------------
    */
    if ($storedUrl !== "") {
        return [
            "url" => $storedUrl,
            "label" => "Open",
            "icon" => "bi-arrow-right-circle",
            "class" => "btn-primary"
        ];
    }

    return [
        "url" => "",
        "label" => "",
        "icon" => "",
        "class" => ""
    ];
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION["notification_csrf"])) {
    $_SESSION["notification_csrf"] =
        bin2hex(random_bytes(32));
}

$csrf = (string)$_SESSION["notification_csrf"];

/*
|--------------------------------------------------------------------------
| Mark All Read
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && ($_POST["action"] ?? "") === "mark_all_read"
) {
    if (
        empty($_POST["csrf_token"])
        || !hash_equals(
            $csrf,
            (string)$_POST["csrf_token"]
        )
    ) {
        $_SESSION["notification_flash"] = [
            "type" => "danger",
            "message" => "Security token expired. Please try again."
        ];
    } else {
        $stmt = $conn->prepare(
            "UPDATE notifications
             SET is_read = 1
             WHERE user_id = ?
               AND is_read = 0"
        );

        $stmt->bind_param(
            "i",
            $userId
        );

        $stmt->execute();

        $updated = $stmt->affected_rows;
        $stmt->close();

        $_SESSION["notification_flash"] = [
            "type" => "success",
            "message" =>
                $updated > 0
                    ? $updated . " notification(s) marked as read."
                    : "There were no unread notifications."
        ];
    }

    header(
        "Location: "
        . rtrim(BASE_URL, "/")
        . "/user/notifications.php"
    );
    exit;
}

/*
|--------------------------------------------------------------------------
| Open Notification
|--------------------------------------------------------------------------
| Marks the notification as read, then redirects according to the current
| user role, notification type and current request status.
|--------------------------------------------------------------------------
*/

$openNotificationId = filter_input(
    INPUT_GET,
    "open",
    FILTER_VALIDATE_INT
);

if ($openNotificationId) {
    $stmt = $conn->prepare(
        "SELECT
            n.id,
            n.request_id,
            n.title,
            n.message,
            n.notification_type,
            n.action_url,
            n.publication_kind,
            n.publication_id,
            n.publication_status,
            n.is_read,
            n.created_at,
            jr.approval_status,
            jr.job_no,
            jr.project_title
         FROM notifications n
         LEFT JOIN job_requests jr
            ON jr.id = n.request_id
         WHERE n.id = ?
           AND n.user_id = ?
         LIMIT 1"
    );

    $stmt->bind_param(
        "ii",
        $openNotificationId,
        $userId
    );

    $stmt->execute();

    $notification = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    if ($notification) {
        $updateStmt = $conn->prepare(
            "UPDATE notifications
             SET is_read = 1
             WHERE id = ?
               AND user_id = ?"
        );

        $updateStmt->bind_param(
            "ii",
            $openNotificationId,
            $userId
        );

        $updateStmt->execute();
        $updateStmt->close();

        $action = notificationAction(
            $notification,
            $role
        );

        if ($action["url"] !== "") {
            header(
                "Location: "
                . $action["url"]
            );
            exit;
        }
    }

    /*
     * No action is available. Return to the list but keep it marked read.
     */
    header(
        "Location: "
        . rtrim(BASE_URL, "/")
        . "/user/notifications.php"
    );
    exit;
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$view = trim(
    (string)($_GET["view"] ?? "all")
);

if (
    !in_array(
        $view,
        ["all", "unread", "read"],
        true
    )
) {
    $view = "all";
}

$typeFilter = trim(
    (string)($_GET["type"] ?? "")
);

$search = trim(
    (string)($_GET["q"] ?? "")
);

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$summaryStmt = $conn->prepare(
    "SELECT
        COUNT(*) AS total,
        SUM(is_read = 0) AS unread,
        SUM(is_read = 1) AS read_total
     FROM notifications
     WHERE user_id = ?"
);

$summaryStmt->bind_param(
    "i",
    $userId
);

$summaryStmt->execute();

$summary = $summaryStmt
    ->get_result()
    ->fetch_assoc() ?: [];

$summaryStmt->close();

$totalCount = (int)($summary["total"] ?? 0);
$unreadCount = (int)($summary["unread"] ?? 0);
$readCount = (int)($summary["read_total"] ?? 0);

/*
|--------------------------------------------------------------------------
| Available Types
|--------------------------------------------------------------------------
*/

$typeStmt = $conn->prepare(
    "SELECT DISTINCT notification_type
     FROM notifications
     WHERE user_id = ?
       AND notification_type IS NOT NULL
       AND notification_type <> ''
     ORDER BY notification_type"
);

$typeStmt->bind_param(
    "i",
    $userId
);

$typeStmt->execute();

$typeResult = $typeStmt->get_result();
$availableTypes = [];

while ($row = $typeResult->fetch_assoc()) {
    $availableTypes[] =
        (string)$row["notification_type"];
}

$typeStmt->close();

/*
|--------------------------------------------------------------------------
| Notification List
|--------------------------------------------------------------------------
*/

$where = [
    "n.user_id = ?"
];

$types = "i";
$params = [$userId];

if ($view === "unread") {
    $where[] = "n.is_read = 0";
} elseif ($view === "read") {
    $where[] = "n.is_read = 1";
}

if ($typeFilter !== "") {
    $where[] = "n.notification_type = ?";
    $types .= "s";
    $params[] = $typeFilter;
}

if ($search !== "") {
    $where[] = "(
        n.title LIKE ?
        OR n.message LIKE ?
        OR jr.job_no LIKE ?
        OR jr.project_title LIKE ?
        OR n.publication_status LIKE ?
        OR n.publication_kind LIKE ?
    )";

    $like = "%" . $search . "%";
    $types .= "ssssss";

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

$sql = "
    SELECT
        n.id,
        n.request_id,
        n.title,
        n.message,
        n.notification_type,
        n.action_url,
        n.publication_kind,
        n.publication_id,
        n.publication_status,
        n.is_read,
        n.created_at,

        jr.approval_status,
        jr.job_no,
        jr.project_title

    FROM notifications n

    LEFT JOIN job_requests jr
        ON jr.id = n.request_id

    WHERE " . implode(
        " AND ",
        $where
    ) . "

    ORDER BY
        n.is_read ASC,
        n.created_at DESC,
        n.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    $types,
    ...$params
);

$stmt->execute();

$notifications = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

$flash = $_SESSION["notification_flash"] ?? null;
unset($_SESSION["notification_flash"]);

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
    Notifications | HPU Workbench
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

<style>
body {
    background:#f4f7fb;
}

.content {
    padding:18px;
}

/* Header styles moved to assets/css/page_headers.css (.ph) */

.summary-grid {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:10px;
    margin-bottom:13px;
}

.summary-card {
    background:#fff;
    border:1px solid #dfe6ef;
    border-radius:14px;
    padding:13px 14px;
    box-shadow:0 8px 24px rgba(28,42,72,.04);
}

.summary-label {
    color:#667085;
    font-size:.64rem;
    font-weight:850;
    text-transform:uppercase;
    letter-spacing:.055em;
}

.summary-value {
    margin-top:3px;
    font-size:1.55rem;
    line-height:1;
    font-weight:900;
}

.filter-card {
    padding:11px;
    margin-bottom:13px;
    background:#fff;
    border:1px solid #dfe6ef;
    border-radius:14px;
    box-shadow:0 8px 24px rgba(28,42,72,.04);
}

.view-tabs {
    display:inline-flex;
    padding:3px;
    gap:3px;
    border-radius:9px;
    background:#f2f4f7;
}

.view-tab {
    padding:6px 9px;
    border-radius:7px;
    color:#667085;
    text-decoration:none;
    font-size:.69rem;
    font-weight:800;
}

.view-tab.active {
    background:#fff;
    color:#1769e0;
    box-shadow:0 1px 3px rgba(16,24,40,.10);
}

.notification-list {
    display:flex;
    flex-direction:column;
    gap:9px;
}

.notification-card {
    position:relative;
    display:grid;
    grid-template-columns:auto minmax(0,1fr) auto;
    gap:11px;
    align-items:start;
    padding:13px 14px;
    background:#fff;
    border:1px solid #dfe6ef;
    border-radius:14px;
    box-shadow:0 7px 20px rgba(28,42,72,.035);
}

.notification-card.unread {
    border-left:4px solid #1769e0;
    background:linear-gradient(
        90deg,
        rgba(23,105,224,.035),
        #fff 22%
    );
}

.notification-icon {
    width:38px;
    height:38px;
    display:grid;
    place-items:center;
    border-radius:11px;
    font-size:1rem;
}

.type-assignment {
    background:#eff8ff;
    color:#175cd3;
}

.type-publication {
    background:#f5f3ff;
    color:#6d28d9;
}

.type-evaluation,
.type-survey {
    background:#ecfdf3;
    color:#027a48;
}

.type-revision,
.type-warning {
    background:#fffaeb;
    color:#b54708;
}

.type-success {
    background:#ecfdf3;
    color:#027a48;
}

.type-info {
    background:#f2f4f7;
    color:#475467;
}

.notification-title-row {
    display:flex;
    align-items:center;
    gap:7px;
    flex-wrap:wrap;
}

.notification-title {
    font-size:.84rem;
    font-weight:850;
    color:#1d2939;
}

.new-badge {
    padding:3px 6px;
    border-radius:999px;
    background:#1769e0;
    color:#fff;
    font-size:.56rem;
    font-weight:850;
}

.type-label {
    padding:3px 6px;
    border-radius:999px;
    background:#f2f4f7;
    color:#475467;
    font-size:.57rem;
    font-weight:800;
}

.notification-message {
    margin-top:4px;
    color:#475467;
    font-size:.74rem;
    line-height:1.45;
}

.request-context {
    display:flex;
    gap:6px 10px;
    flex-wrap:wrap;
    margin-top:7px;
    color:#667085;
    font-size:.64rem;
}

.status-pill {
    padding:3px 6px;
    border-radius:999px;
    font-size:.57rem;
    font-weight:850;
}

.status-pending,
.status-review {
    background:#fffaeb;
    color:#b54708;
}

.status-approved {
    background:#ecfdf3;
    color:#027a48;
}

.status-revision {
    background:#fff4ed;
    color:#c4320a;
}

.status-disapproved {
    background:#fef3f2;
    color:#b42318;
}

.status-completed,
.status-evaluation,
.status-closed {
    background:#ecfdf3;
    color:#027a48;
}

.status-default {
    background:#f2f4f7;
    color:#475467;
}

.notification-time {
    margin-top:7px;
    color:#98a2b3;
    font-size:.61rem;
}

.notification-action {
    align-self:center;
}

.notification-action .btn {
    white-space:nowrap;
    font-size:.68rem;
    font-weight:800;
}

.no-action {
    align-self:center;
    color:#98a2b3;
    font-size:.63rem;
}

.empty-state {
    padding:65px 20px;
    text-align:center;
    background:#fff;
    border:1px dashed #cfd9e7;
    border-radius:14px;
    color:#667085;
}

.empty-icon {
    width:54px;
    height:54px;
    display:grid;
    place-items:center;
    margin:0 auto 10px;
    border-radius:16px;
    background:#f2f4f7;
    color:#667085;
    font-size:1.3rem;
}

@media(max-width:800px) {
    .content {
        padding:12px;
    }

    .summary-grid {
        grid-template-columns:repeat(3,1fr);
    }

    .notification-card {
        grid-template-columns:auto minmax(0,1fr);
    }

    .notification-action,
    .no-action {
        grid-column:1/-1;
        padding-left:49px;
        align-self:auto;
    }

    .notification-action .btn {
        width:100%;
    }
}

@media(max-width:520px) {
    .summary-grid {
        gap:6px;
    }

    .summary-card {
        padding:10px;
    }

    .summary-value {
        font-size:1.25rem;
    }
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
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>
</div>

<?php endif; ?>

<!-- ======================================================
     HEADER
======================================================= -->

<div class="ph">

    <div class="ph-copy">
        <div class="ph-icon" aria-hidden="true">
            <i class="bi bi-bell-fill"></i>
        </div>

        <div class="min-w-0">
            <p class="ph-eyebrow">HPU Workbench</p>
            <h1 class="ph-title">Notifications</h1>
            <p class="ph-subtitle">
                <?= $isHpuRole
                    ? "Assignments, request updates and other Workbench alerts for " . e($userName) . "."
                    : "Request updates, revision notices, evaluations and Workbench alerts."
                ?>
            </p>
        </div>
    </div>

    <div class="ph-actions">
        <?php if ($unreadCount > 0): ?>

        <form method="post">

            <input
                type="hidden"
                name="action"
                value="mark_all_read"
            >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrf) ?>"
            >

            <button
                class="btn btn-sm btn-outline-primary"
                type="submit"
            >
                <i class="bi bi-check2-all me-1"></i>
                Mark All Read
            </button>

        </form>

        <?php endif; ?>
    </div>

</div>

<!-- ======================================================
     COUNTS
======================================================= -->

<div class="summary-grid">

    <div class="summary-card">
        <div class="summary-label">
            All
        </div>

        <div class="summary-value">
            <?= $totalCount ?>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-label">
            Unread
        </div>

        <div class="summary-value text-primary">
            <?= $unreadCount ?>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-label">
            Read
        </div>

        <div class="summary-value text-success">
            <?= $readCount ?>
        </div>
    </div>

</div>

<!-- ======================================================
     FILTERS
======================================================= -->

<div class="filter-card">

<form method="get">

<div class="row g-2 align-items-center">

    <div class="col-lg-auto">

        <div class="view-tabs">

            <?php
            foreach (
                [
                    "all" => "All",
                    "unread" => "Unread",
                    "read" => "Read"
                ]
                as $key => $label
            ):
                $query = http_build_query(
                    array_filter(
                        [
                            "view" => $key,
                            "type" => $typeFilter,
                            "q" => $search
                        ],
                        fn($value) =>
                            $value !== ""
                    )
                );
            ?>

            <a
                class="view-tab <?= $view === $key ? "active" : "" ?>"
                href="?<?= e($query) ?>"
            >
                <?= e($label) ?>
            </a>

            <?php endforeach; ?>

        </div>

    </div>

    <div class="col-lg-3">

        <select
            class="form-select form-select-sm"
            name="type"
        >
            <option value="">
                All Notification Types
            </option>

            <?php foreach ($availableTypes as $availableType): ?>
                <?php
                $meta =
                    notificationTypeMeta(
                        $availableType
                    );
                ?>

                <option
                    value="<?= e($availableType) ?>"
                    <?= $typeFilter === $availableType ? "selected" : "" ?>
                >
                    <?= e($meta["label"]) ?>
                </option>
            <?php endforeach; ?>
        </select>

    </div>

    <div class="col-lg">

        <input
            class="form-control form-control-sm"
            type="search"
            name="q"
            value="<?= e($search) ?>"
            placeholder="Search notifications, job number or project title..."
        >

    </div>

    <input
        type="hidden"
        name="view"
        value="<?= e($view) ?>"
    >

    <div class="col-lg-auto">

        <button
            class="btn btn-sm btn-primary"
            type="submit"
        >
            <i class="bi bi-search me-1"></i>
            Filter
        </button>

    </div>

    <?php if (
        $search !== ""
        || $typeFilter !== ""
        || $view !== "all"
    ): ?>

    <div class="col-lg-auto">
        <a
            class="btn btn-sm btn-light"
            href="<?= e(BASE_URL) ?>/user/notifications.php"
        >
            Reset
        </a>
    </div>

    <?php endif; ?>

</div>

</form>

</div>

<!-- ======================================================
     LIST
======================================================= -->

<?php if (!$notifications): ?>

<div class="empty-state">

    <div class="empty-icon">
        <i class="bi bi-bell-slash"></i>
    </div>

    <h5 class="fw-bold text-dark mb-1">
        No notifications found
    </h5>

    <div>
        There are no notifications matching the current filters.
    </div>

</div>

<?php else: ?>

<div class="notification-list">

<?php foreach ($notifications as $notification): ?>

    <?php
    $typeMeta =
        notificationTypeMeta(
            (string)$notification["notification_type"]
        );

    $action =
        notificationAction(
            $notification,
            $role
        );

    $status =
        trim(
            (string)(
                $notification["approval_status"]
                ?: (
                    $notification["publication_status"]
                    ?? ""
                )
            )
        );

    $isUnread =
        (int)$notification["is_read"] === 0;

    $createdAt =
        new DateTime(
            $notification["created_at"]
        );
    ?>

    <article
        class="notification-card <?= $isUnread ? "unread" : "" ?>"
    >

        <div
            class="notification-icon <?= e($typeMeta["class"]) ?>"
        >
            <i
                class="bi <?= e($typeMeta["icon"]) ?>"
            ></i>
        </div>

        <div>

            <div class="notification-title-row">

                <div class="notification-title">
                    <?= e($notification["title"]) ?>
                </div>

                <?php if ($isUnread): ?>
                    <span class="new-badge">
                        NEW
                    </span>
                <?php endif; ?>

                <span class="type-label">
                    <?= e($typeMeta["label"]) ?>
                </span>

            </div>

            <div class="notification-message">
                <?= nl2br(
                    e($notification["message"])
                ) ?>
            </div>

            <?php if (
                !empty($notification["job_no"])
                || !empty($notification["project_title"])
                || !empty($notification["publication_kind"])
                || $status !== ""
            ): ?>

            <div class="request-context">

                <?php if (!empty($notification["job_no"])): ?>
                <span>
                    <i class="bi bi-hash"></i>
                    <?= e($notification["job_no"]) ?>
                </span>
                <?php endif; ?>

                <?php if (!empty($notification["project_title"])): ?>
                <span>
                    <i class="bi bi-briefcase"></i>
                    <?= e($notification["project_title"]) ?>
                </span>
                <?php endif; ?>

                <?php if (!empty($notification["publication_kind"])): ?>
                <span>
                    <i class="bi bi-newspaper"></i>
                    <?= e(
                        $notification["publication_kind"] === "recognition"
                            ? "Recognition / Milestone"
                            : "Event / Activity"
                    ) ?>
                </span>
                <?php endif; ?>

                <?php if ($status !== ""): ?>
                <span
                    class="status-pill <?= e(notificationStatusClass($status)) ?>"
                >
                    <?= e($status) ?>
                </span>
                <?php endif; ?>

            </div>

            <?php endif; ?>

            <div class="notification-time">
                <i class="bi bi-clock me-1"></i>
                <?= e(
                    $createdAt->format(
                        "M d, Y • g:i A"
                    )
                ) ?>
            </div>

        </div>

        <?php if ($action["url"] !== ""): ?>

        <div class="notification-action">

            <a
                class="btn btn-sm <?= e($action["class"]) ?>"
                href="?open=<?= (int)$notification["id"] ?>"
            >
                <i
                    class="bi <?= e($action["icon"]) ?> me-1"
                ></i>

                <?= e($action["label"]) ?>
            </a>

        </div>

        <?php else: ?>

        <div class="no-action">
            <i class="bi bi-check2 me-1"></i>
            No action required
        </div>

        <?php endif; ?>

    </article>

<?php endforeach; ?>

</div>

<?php endif; ?>

</div>

</main>

</div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
></script>

</body>
</html>
