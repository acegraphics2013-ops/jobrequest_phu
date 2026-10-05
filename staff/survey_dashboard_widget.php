<?php
/*
 * SURVEY DASHBOARD WIDGET
 *
 * Include this file inside your existing dashboard after config.php,
 * auth.php and helpers.php are already loaded.
 *
 * Example:
 * include __DIR__ . "/staff/survey_dashboard_widget.php";
 */

// Direct-access guard: this is an include fragment, not a standalone endpoint.
if (empty($_SESSION['user_id'] ?? null)) {
    http_response_code(403);
    exit('Access denied.');
}

$surveyDashboardPath =
    rtrim(BASE_URL, "/") .
    "/staff/survey_analytics.php";

$surveyWidgetTableResult = $conn->query(
    "SHOW TABLES LIKE 'job_request_client_experience'"
);

$surveyWidgetTableReady =
    $surveyWidgetTableResult
    && $surveyWidgetTableResult->num_rows > 0;

$surveyWidget = [
    "total" => 0,
    "average" => 0,
    "this_month" => 0,
    "positive" => 0
];

if ($surveyWidgetTableReady) {
    $surveyWidgetResult = $conn->query(
        "SELECT
            COUNT(*) AS total,
            COALESCE(
                AVG(overall_satisfaction),
                0
            ) AS average,
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

    if ($surveyWidgetResult) {
        $row =
            $surveyWidgetResult->fetch_assoc()
            ?: [];

        $total = (int) (
            $row["total"] ?? 0
        );

        $positiveCount = (int) (
            $row["positive_count"] ?? 0
        );

        $surveyWidget = [
            "total" => $total,

            "average" => round(
                (float) (
                    $row["average"] ?? 0
                ),
                2
            ),

            "this_month" => (int) (
                $row["this_month"] ?? 0
            ),

            "positive" =>
                $total > 0
                    ? round(
                        (
                            $positiveCount
                            / $total
                        ) * 100,
                        1
                    )
                    : 0
        ];
    }
}
?>

<style>
    .survey-widget-card {
        overflow: hidden;
        border: 1px solid #dbe3ef;
        border-radius: 18px;
        background: #ffffff;
        box-shadow: 0 10px 28px rgba(15, 23, 42, .05);
    }

    .survey-widget-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
        padding: 14px 20px;
        border-bottom: 1px solid #edf1f6;
    }

    .survey-widget-title {
        display: flex;
        align-items: center;
        gap: 11px;
    }

    .survey-widget-icon {
        display: grid;
        width: 40px;
        height: 40px;
        place-items: center;
        border: 1px solid #cfe0f8;
        border-radius: 10px;
        background: #eef4ff;
        color: #2563eb;
    }

    .survey-widget-title h3 {
        margin: 0;
        color: #0f172a;
        font-size: 1rem;
        font-weight: 800;
    }

    .survey-widget-title p {
        margin: 2px 0 0;
        color: #64748b;
        font-size: .78rem;
    }

    .survey-widget-grid {
        display: grid;
        grid-template-columns:
            repeat(4, minmax(0, 1fr));
        gap: 12px;
        padding: 18px 20px 20px;
    }

    .survey-widget-metric {
        padding: 14px;
        border: 1px solid #e2e8f0;
        border-radius: 13px;
        background: #f8fafc;
    }

    .survey-widget-label {
        color: #64748b;
        font-size: .68rem;
        font-weight: 800;
        letter-spacing: .04em;
        text-transform: uppercase;
    }

    .survey-widget-value {
        margin-top: 5px;
        color: #0f172a;
        font-size: 1.45rem;
        font-weight: 800;
    }

    @media (max-width: 850px) {
        .survey-widget-grid {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 480px) {
        .survey-widget-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<section class="survey-widget-card mb-4">

    <div class="survey-widget-header">

        <div class="survey-widget-title">

            <div class="survey-widget-icon">
                <i class="bi bi-clipboard-data"></i>
            </div>

            <div>

                <h3>
                    Client Experience Survey
                </h3>

                <p>
                    Consolidated PHU service feedback.
                </p>

            </div>

        </div>

        <?php if ($surveyWidgetTableReady): ?>

            <a
                class="btn btn-sm btn-outline-primary"
                href="<?= e($surveyDashboardPath) ?>"
            >
                <i class="bi bi-bar-chart me-1"></i>
                View Analytics
            </a>

        <?php endif; ?>

    </div>

    <?php if (!$surveyWidgetTableReady): ?>

        <div class="alert alert-warning m-3 mb-3">

            Import
            <code>
                job_request_client_experience.sql
            </code>
            to activate survey analytics.

        </div>

    <?php else: ?>

        <div class="survey-widget-grid">

            <div class="survey-widget-metric">

                <div class="survey-widget-label">
                    Total Responses
                </div>

                <div class="survey-widget-value">
                    <?= (int) $surveyWidget["total"] ?>
                </div>

            </div>

            <div class="survey-widget-metric">

                <div class="survey-widget-label">
                    Average Rating
                </div>

                <div class="survey-widget-value">
                    <?= number_format(
                        (float) $surveyWidget[
                            "average"
                        ],
                        2
                    ) ?>
                    <small class="fs-6">
                        / 5
                    </small>
                </div>

            </div>

            <div class="survey-widget-metric">

                <div class="survey-widget-label">
                    This Month
                </div>

                <div class="survey-widget-value">
                    <?= (int) $surveyWidget[
                        "this_month"
                    ] ?>
                </div>

            </div>

            <div class="survey-widget-metric">

                <div class="survey-widget-label">
                    Objective Achieved
                </div>

                <div class="survey-widget-value">
                    <?= number_format(
                        (float) $surveyWidget[
                            "positive"
                        ],
                        1
                    ) ?>%
                </div>

            </div>

        </div>

    <?php endif; ?>

</section>
