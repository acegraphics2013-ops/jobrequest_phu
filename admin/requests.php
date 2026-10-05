<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

requireRole(["admin"]);
requireModule($conn, "job_requests_admin");

$requests = $conn->query(
    "SELECT
        id,
        job_no,
        requestor_name,
        department,
        project_title,
        date_request,
        reviewed_by,
        reviewed_date,
        approval_status
     FROM job_requests
     ORDER BY id DESC"
)->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   UI HELPERS — mirror the staff registry pages so every
   page shares one visual language.
========================================================= */
function requestStatusClass(string $status): string
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

function requestStatusIcon(string $status): string
{
    return match ($status) {
        "Pending Review" => "bi-hourglass-split",
        "Under Review" => "bi-search",
        "Approved" => "bi-check-circle-fill",
        "In Development" => "bi-hammer",
        "For Revision" => "bi-arrow-counterclockwise",
        "Disapproved" => "bi-x-circle-fill",
        "Completed" => "bi-patch-check-fill",
        "For Evaluation" => "bi-clipboard-data",
        "Closed" => "bi-lock-fill",
        default => "bi-circle"
    };
}

function initials(string $name): string
{
    $parts = preg_split("/\s+/", trim($name));

    $out = "";

    foreach (array_slice($parts, 0, 2) as $part) {
        $out .= strtoupper(substr($part, 0, 1));
    }

    return $out !== "" ? $out : "?";
}

/* Derived counts from the current result set — no extra queries. */
$stats = [];

foreach ($requests as $row) {
    $status = $row["approval_status"];
    $stats[$status] = ($stats[$status] ?? 0) + 1;
}

$metrics = [
    ["status" => "",            "label" => "All Requests",    "icon" => "bi-files",                  "cls" => "metric-all"],
    ["status" => "Pending Review", "label" => "Pending",      "icon" => "bi-hourglass-split",        "cls" => "metric-pending"],
    ["status" => "Under Review",   "label" => "Under Review", "icon" => "bi-search",                 "cls" => "metric-review"],
    ["status" => "Approved",       "label" => "Approved",     "icon" => "bi-check-circle-fill",      "cls" => "metric-approved"],
    ["status" => "For Revision",   "label" => "For Revision", "icon" => "bi-arrow-counterclockwise", "cls" => "metric-development"],
    ["status" => "Disapproved",    "label" => "Disapproved",  "icon" => "bi-x-circle-fill",          "cls" => "metric-disapproved"]
];

$totalCount = count($requests);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Job Requests</title>

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
        :root {
            --req-primary: #2563eb;
            --req-border: #e2e8f0;
            --req-muted: #64748b;
            --req-text: #182135;
        }

        .admin-requests-page {
            max-width: 1500px;
            margin: 0 auto;
        }

        /* Metrics strip  (matches staff registry pages) */
        .metrics-strip {
            display: grid;
            grid-template-columns: repeat(6, minmax(128px, 1fr));
            gap: 9px;
            margin-bottom: 14px;
            overflow-x: auto;
            padding-bottom: 2px;
        }

        .metric-card {
            --metric-color: #375a7f;
            --metric-bg: #edf2f7;
            display: grid;
            min-width: 128px;
            grid-template-columns: 32px minmax(0, 1fr);
            align-items: center;
            gap: 9px;
            padding: 10px 12px;
            border: 1px solid var(--req-border);
            border-radius: 12px;
            background: #fff;
            color: inherit;
            text-align: left;
            cursor: pointer;
            transition: .16s ease;
        }

        .metric-card:hover,
        .metric-card.active {
            border-color: color-mix(in srgb, var(--metric-color) 36%, white);
            background: color-mix(in srgb, var(--metric-bg) 70%, white);
            transform: translateY(-1px);
        }

        .metric-card.active {
            box-shadow: inset 0 -2px 0 var(--metric-color);
        }

        .metric-icon {
            display: grid;
            width: 32px;
            height: 32px;
            place-items: center;
            border-radius: 9px;
            background: var(--metric-bg);
            color: var(--metric-color);
            font-size: .95rem;
        }

        .metric-label {
            display: block;
            overflow: hidden;
            color: var(--req-muted);
            font-size: .6rem;
            font-weight: 850;
            letter-spacing: .03em;
            text-overflow: ellipsis;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .metric-value {
            display: block;
            margin-top: 1px;
            color: var(--req-text);
            font-size: 1.05rem;
            font-weight: 850;
            line-height: 1.1;
        }

        .metric-all { --metric-color: #375a7f; --metric-bg: #edf2f7; }
        .metric-pending { --metric-color: #68758b; --metric-bg: #f1f4f8; }
        .metric-review { --metric-color: #087c91; --metric-bg: #e4f8fb; }
        .metric-approved { --metric-color: #168653; --metric-bg: #e7f8ef; }
        .metric-development { --metric-color: #a05c08; --metric-bg: #fff3df; }
        .metric-disapproved { --metric-color: #b4373f; --metric-bg: #fdeaea; }

        /* Shell */
        .req-shell {
            overflow: hidden;
            border: 1px solid var(--req-border);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 6px 22px rgba(15, 23, 42, .05);
        }

        /* Toolbar */
        .list-toolbar {
            display: flex;
            min-height: 46px;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 9px 14px;
            border-bottom: 1px solid var(--req-border);
            background: #fafbfd;
        }

        .list-toolbar-title {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--req-text);
            font-size: .78rem;
            font-weight: 850;
        }

        .list-toolbar-title i {
            color: var(--req-primary);
        }

        .result-pill {
            display: inline-flex;
            min-height: 26px;
            align-items: center;
            gap: 5px;
            padding: 4px 9px;
            border-radius: 999px;
            background: #eaf8f1;
            color: #116c45;
            font-size: .68rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .toolbar-search {
            position: relative;
            min-width: 240px;
            flex: 1 1 240px;
            max-width: 340px;
        }

        .toolbar-search > i {
            position: absolute;
            top: 50%;
            left: 11px;
            transform: translateY(-50%);
            color: #9aa6b8;
            font-size: .85rem;
            pointer-events: none;
        }

        .search-input {
            width: 100%;
            min-height: 36px;
            padding: 7px 12px 7px 32px;
            border: 1px solid #d9e1ec;
            border-radius: 9px;
            background: #fff;
            color: #1f2a3c;
            font-size: .8rem;
        }

        .search-input::placeholder {
            color: #9aa6b8;
        }

        .search-input:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 .18rem rgba(37, 99, 235, .1);
            outline: none;
        }

        /* Table */
        .req-table {
            margin: 0;
            min-width: 980px;
        }

        .req-table > :not(caption) > * > * {
            padding: 11px 14px;
        }

        .req-table thead th {
            background: #f5f7fa;
            border-bottom: 1px solid #e6ebf2;
            color: #65748c;
            font-size: .61rem;
            font-weight: 850;
            letter-spacing: .045em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .req-table tbody tr:last-child td {
            border-bottom: 0;
        }

        /* Cells */
        .req-cell {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .av {
            display: inline-flex;
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            font-size: .66rem;
            font-weight: 850;
            letter-spacing: .02em;
        }

        .av-1 { background: #e4edff; color: #1e54ba; }
        .av-2 { background: #e1f7ea; color: #126b43; }
        .av-3 { background: #f1ebff; color: #5d36ad; }
        .av-4 { background: #fff3df; color: #8a4d04; }
        .av-5 { background: #ffe9f1; color: #a1125a; }

        .cell-value {
            overflow: hidden;
            color: #263248;
            font-size: .76rem;
            font-weight: 750;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .cell-note {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 2px;
            color: var(--req-muted);
            font-size: .66rem;
        }

        .cell-note i {
            font-size: .66rem;
        }

        .job-chip {
            display: inline-flex;
            padding: 3px 8px;
            border: 1px solid #dee6f0;
            border-radius: 7px;
            background: #f7f9fc;
            color: #344054;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .68rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .dept-cell {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #5a6a80;
            font-size: .72rem;
            font-weight: 650;
        }

        .dept-cell i {
            color: #8ba0bd;
            font-size: .8rem;
        }

        .project-title {
            overflow: hidden;
            margin: 0;
            color: var(--req-text);
            font-size: .84rem;
            font-weight: 850;
            line-height: 1.32;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .request-subline {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 3px;
            color: var(--req-muted);
            font-size: .66rem;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: .62rem;
            font-weight: 850;
            white-space: nowrap;
        }

        .status-pending.status-badge { background: #eef1f5; color: #566174; }
        .status-review.status-badge { background: #dff7fa; color: #08697a; }
        .status-approved.status-badge { background: #e1f7ea; color: #126b43; }
        .status-development.status-badge { background: #fff0d9; color: #8a4d04; }
        .status-revision.status-badge { background: #fff1d7; color: #8a4d04; }
        .status-disapproved.status-badge { background: #ffe6e8; color: #a61e2c; }
        .status-completed.status-badge { background: #e4edff; color: #1e54ba; }
        .status-evaluation.status-badge { background: #eee8ff; color: #5d36ad; }
        .status-closed.status-badge { background: #e8ecf1; color: #263244; }
        .status-default.status-badge { background: #eef1f5; color: #566174; }

        .req-table .btn {
            border-radius: 9px;
            font-size: .72rem;
            font-weight: 750;
            padding: 5px 12px;
        }

        @media (max-width: 700px) {
            .list-toolbar {
                padding: 10px 12px;
            }

            .toolbar-search {
                max-width: none;
            }

            .metrics-strip {
                grid-template-columns: repeat(6, minmax(112px, 1fr));
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

                <div class="admin-requests-page">

                    <div class="ph">
                        <div class="ph-copy">
                            <div class="ph-icon" aria-hidden="true">
                                <i class="bi bi-card-checklist"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="ph-eyebrow">HPU Workbench</p>
                                <h1 class="ph-title">Job Requests</h1>
                                <p class="ph-subtitle">Review and monitor submitted job requests across departments.</p>
                            </div>
                        </div>
                        <div class="ph-actions">
                            <span class="btn btn-sm btn-outline-primary disabled text-dark" style="opacity:1; background:#ffffff;">
                                <i class="bi bi-collection me-1 text-primary"></i>
                                <?= $totalCount ?> total
                            </span>
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/dashboard.php">
                                <i class="bi bi-speedometer2 me-1"></i> Dashboard
                            </a>
                        </div>
                    </div>

                    <div class="metrics-strip">

                        <?php foreach ($metrics as $metric): ?>

                            <button
                                type="button"
                                class="metric-card <?= e($metric["cls"]) ?>"
                                data-status="<?= e($metric["status"]) ?>"
                                title="Filter by <?= e($metric["label"]) ?>"
                            >
                                <span class="metric-icon"><i class="bi <?= e($metric["icon"]) ?>"></i></span>
                                <span>
                                    <span class="metric-label"><?= e($metric["label"]) ?></span>
                                    <span class="metric-value">
                                        <?= $metric["status"] === ""
                                            ? $totalCount
                                            : (int) ($stats[$metric["status"]] ?? 0) ?>
                                    </span>
                                </span>
                            </button>

                        <?php endforeach; ?>

                    </div>

                    <div class="req-shell">

                        <div class="list-toolbar">
                            <div class="list-toolbar-title">
                                <i class="bi bi-list-check"></i>
                                Request Registry
                                <span class="result-pill" id="resultPill">
                                    <i class="bi bi-funnel"></i>
                                    <?= $totalCount ?> records
                                </span>
                            </div>

                            <div class="toolbar-search">
                                <i class="bi bi-search"></i>
                                <input
                                    id="searchBox"
                                    class="search-input"
                                    type="search"
                                    placeholder="Search requestor, project, job no."
                                    autocomplete="off"
                                >
                            </div>
                        </div>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle req-table" id="requestsTable">

                                <thead>
                                    <tr>
                                        <th>Requestor</th>
                                        <th>Job No.</th>
                                        <th>Department</th>
                                        <th>Project</th>
                                        <th>Reviewed By</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php foreach ($requests as $row):

                                        $avClass = "av-" . ((strlen($row["requestor_name"]) % 5) + 1);

                                    ?>

                                        <tr data-status="<?= e($row["approval_status"]) ?>">

                                            <td>
                                                <div class="req-cell">
                                                    <span class="av <?= $avClass ?>">
                                                        <?= e(initials($row["requestor_name"])) ?>
                                                    </span>
                                                    <span class="cell-value">
                                                        <?= e($row["requestor_name"]) ?>
                                                    </span>
                                                </div>
                                            </td>

                                            <td>
                                                <span class="job-chip"><?= e($row["job_no"]) ?></span>
                                            </td>

                                            <td>
                                                <span class="dept-cell">
                                                    <i class="bi bi-buildings"></i>
                                                    <?= e($row["department"]) ?>
                                                </span>
                                            </td>

                                            <td>
                                                <p class="project-title"><?= e($row["project_title"]) ?></p>
                                                <div class="request-subline">
                                                    <i class="bi bi-calendar3"></i>
                                                    <?= e($row["date_request"]) ?>
                                                </div>
                                            </td>

                                            <td>
                                                <?php if (!empty($row["reviewed_by"])): ?>

                                                    <div class="cell-value">
                                                        <?= e($row["reviewed_by"]) ?>
                                                    </div>

                                                    <div class="cell-note">
                                                        <i class="bi bi-clock"></i>
                                                        <?= e($row["reviewed_date"]) ?>
                                                    </div>

                                                <?php else: ?>

                                                    <span class="cell-note">
                                                        <i class="bi bi-dash-circle"></i>
                                                        Not reviewed
                                                    </span>

                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <span
                                                    class="status-badge <?= e(requestStatusClass(
                                                        $row["approval_status"]
                                                    )) ?>"
                                                >
                                                    <i class="bi <?= e(requestStatusIcon(
                                                        $row["approval_status"]
                                                    )) ?>"></i>
                                                    <?= e($row["approval_status"]) ?>
                                                </span>
                                            </td>

                                            <td class="text-end">
                                                <div class="d-flex justify-content-end gap-2 flex-wrap">

                                                    <a
                                                        class="btn btn-sm btn-outline-primary"
                                                        href="<?= e(BASE_URL) ?>/admin/view.php?id=<?= (int) $row["id"] ?>"
                                                    >
                                                        <i class="bi bi-eye"></i>
                                                        View
                                                    </a>

                                                    <a
                                                        class="btn btn-sm btn-primary"
                                                        href="<?= e(BASE_URL) ?>/staff/review_request.php?id=<?= (int) $row["id"] ?>"
                                                    >
                                                        <i class="bi bi-clipboard-check"></i>
                                                        <?= in_array(
                                                            $row["approval_status"],
                                                            ["Completed", "Closed"],
                                                            true
                                                        )
                                                            ? "Development"
                                                            : "Review"
                                                        ?>
                                                    </a>

                                                </div>
                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                    <tr id="emptyRow" style="display:none;">
                                        <td
                                            colspan="7"
                                            class="text-center text-muted py-5"
                                        >
                                            No job requests found.
                                        </td>
                                    </tr>

                                </tbody>

                            </table>

                        </div>

                    </div>

                </div>

            </div>

        </main>

    </div>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    const table = document.getElementById("requestsTable");
    const rows = Array.prototype.slice.call(
        table.querySelectorAll("tbody tr[data-status]")
    );
    const emptyRow = document.getElementById("emptyRow");
    const resultPill = document.getElementById("resultPill");
    const searchBox = document.getElementById("searchBox");
    const metricCards = Array.prototype.slice.call(
        document.querySelectorAll(".metric-card")
    );

    let activeStatus = "";

    function applyFilters() {
        const query = searchBox.value.trim().toLowerCase();
        let visible = 0;

        rows.forEach(function (tr) {
            const matchesStatus =
                activeStatus === "" ||
                tr.dataset.status === activeStatus;
            const matchesText =
                query === "" ||
                tr.textContent.toLowerCase().indexOf(query) !== -1;
            const show = matchesStatus && matchesText;

            tr.style.display = show ? "" : "none";

            if (show) {
                visible = visible + 1;
            }
        });

        if (visible === 0) {
            emptyRow.style.display = "";
            emptyRow.querySelector("td").textContent =
                rows.length === 0
                    ? "No job requests found."
                    : "No requests match your filters.";
        } else {
            emptyRow.style.display = "none";
        }

        resultPill.innerHTML =
            '<i class="bi bi-funnel"></i> ' +
            visible + " of " + rows.length + " records";
    }

    metricCards.forEach(function (card) {
        card.addEventListener("click", function () {
            const target =
                card.dataset.status === activeStatus
                    ? ""
                    : card.dataset.status;

            activeStatus = target;

            metricCards.forEach(function (c) {
                c.classList.toggle(
                    "active",
                    c.dataset.status === activeStatus
                );
            });

            applyFilters();
        });
    });

    searchBox.addEventListener("input", applyFilters);

    applyFilters();
});
</script>

</body>
</html>