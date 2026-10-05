<?php

include_once __DIR__ . "/activity_log.php";

if (!isset($requestId)) {
    throw new RuntimeException(
        "Activity timeline requires the request ID."
    );
}

$activityTimelineItems =
    getRequestActivities(
        $conn,
        (int) $requestId
    );
?>
<?php

if (!isset($requestId)) {
    throw new RuntimeException(
        "Activity timeline requires the $requestId variable."
    );
}

include_once __DIR__ . "/activity_log.php";

$activityTimelineItems = getRequestActivities(
    $conn,
    (int) $requestId
);
?>

<style>
.activity-timeline{position:relative;margin:0;padding:0;list-style:none}
.activity-timeline:before{position:absolute;top:15px;bottom:15px;left:23px;width:2px;background:#dbe3ef;content:""}
.activity-item{position:relative;display:grid;grid-template-columns:48px 1fr;gap:14px;padding-bottom:22px}
.activity-marker{position:relative;z-index:2;display:grid;width:46px;height:46px;place-items:center;border:5px solid #fff;border-radius:50%;box-shadow:0 0 0 1px #dbe3ef;font-size:17px}
.activity-content{padding:14px 16px;border:1px solid #e2e8f0;border-radius:12px;background:#fff}
.activity-title{margin-bottom:3px;color:#0f172a;font-weight:800}
.activity-meta{margin-bottom:8px;color:#64748b;font-size:12px}
.activity-description{margin:0;color:#334155;white-space:pre-line}
.activity-status-change{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px}
.activity-status-pill{padding:4px 8px;border-radius:999px;background:#f1f5f9;color:#334155;font-size:11px;font-weight:700}
.activity-blue{background:#dbeafe;color:#1d4ed8}.activity-cyan{background:#cffafe;color:#155e75}
.activity-green{background:#dcfce7;color:#166534}.activity-yellow{background:#fef3c7;color:#92400e}
.activity-red{background:#fee2e2;color:#991b1b}.activity-indigo{background:#e0e7ff;color:#3730a3}
.activity-purple{background:#f3e8ff;color:#7e22ce}.activity-orange{background:#ffedd5;color:#9a3412}
.activity-slate{background:#f1f5f9;color:#475569}.activity-dark{background:#e2e8f0;color:#0f172a}
.activity-empty{padding:36px 20px;border:1px dashed #cbd5e1;border-radius:12px;color:#64748b;text-align:center}
</style>

<section class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-4">
            <div>
                <h4 class="mb-1">
                    <i class="bi bi-clock-history me-2"></i>
                    Activity Log Timeline
                </h4>
                <p class="text-muted mb-0">
                    Complete history of this job request.
                </p>
            </div>

            <span class="badge bg-light text-dark border">
                <?= count($activityTimelineItems) ?> event(s)
            </span>
        </div>

        <?php if (!$activityTimelineItems): ?>
            <div class="activity-empty">
                <i class="bi bi-clock-history fs-1 d-block mb-2"></i>
                No activity has been recorded yet.
            </div>
        <?php else: ?>
            <ol class="activity-timeline">
                <?php foreach ($activityTimelineItems as $activity): ?>
                    <li class="activity-item">
                        <div class="activity-marker <?= activityColorClass($activity["action_type"]) ?>">
                            <i class="bi <?= activityIcon($activity["action_type"]) ?>"></i>
                        </div>

                        <div class="activity-content">
                            <div class="activity-title">
                                <?= e($activity["action_title"]) ?>
                            </div>

                            <div class="activity-meta">
                                <?= e(date(
                                    "F d, Y • g:i A",
                                    strtotime($activity["created_at"])
                                )) ?>
                                ·
                                <?= e($activity["actor_name"]) ?>
                                (<?= e($activity["actor_role"]) ?>)
                            </div>

                            <?php if (!empty($activity["description"])): ?>
                                <p class="activity-description">
                                    <?= e($activity["description"]) ?>
                                </p>
                            <?php endif; ?>

                            <?php if (
                                !empty($activity["old_status"]) ||
                                !empty($activity["new_status"])
                            ): ?>
                                <div class="activity-status-change">
                                    <?php if (!empty($activity["old_status"])): ?>
                                        <span class="activity-status-pill">
                                            <?= e($activity["old_status"]) ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if (
                                        !empty($activity["old_status"]) &&
                                        !empty($activity["new_status"])
                                    ): ?>
                                        <i class="bi bi-arrow-right"></i>
                                    <?php endif; ?>

                                    <?php if (!empty($activity["new_status"])): ?>
                                        <span class="activity-status-pill bg-primary-subtle text-primary-emphasis">
                                            <?= e($activity["new_status"]) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>
</section>
