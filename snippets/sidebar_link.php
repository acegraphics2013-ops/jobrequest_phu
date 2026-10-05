<?php if (
    in_array(
        $role,
        [
            "staff",
            "supervisor",
            "admin"
        ],
        true
    )
): ?>

    <a
        class="nav-link <?= navActive([
            "/staff/calendar.php",
            "/reports/evaluation_analytics.php",
            "/reports/print_evaluation_report.php"
        ]) ?>"
        href="<?= e(BASE_URL) ?>/staff/calendar.php"
    >
        <i class="bi bi-calendar3"></i>
        <span>Calendar View</span>
    </a>

<?php endif; ?>
