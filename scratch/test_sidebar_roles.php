<?php

// Test sidebar rendering across roles

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';

$roles = ['admin', 'staff', 'supervisor', 'user'];

foreach ($roles as $r) {
    $_SESSION['role'] = $r;
    $_SESSION['user_id'] = 1;
    $_SESSION['name'] = 'Ace Cabuga';

    ob_start();
    include __DIR__ . '/../includes/sidebar.php';
    $output = ob_get_clean();

    echo "=== Testing Role: {$r} ===\n";
    echo "Output Length: " . strlen($output) . " bytes\n";

    if (str_contains($output, 'Fatal error') || str_contains($output, 'Warning:') || str_contains($output, 'Notice:')) {
        echo "ERROR DETECTED in role {$r}!\n";
        echo substr($output, 0, 500);
        exit(1);
    }

    // Check key elements
    $checks = [
        'phuSidebar',
        'sidebarCollapseBtn',
        'sidebar-section-heading',
        'sidebar-user-card',
        'sidebarUserDropdown',
        'data-tooltip'
    ];

    foreach ($checks as $chk) {
        if (!str_contains($output, $chk)) {
            echo "MISSING ELEMENT '{$chk}' in role {$r}!\n";
            exit(1);
        }
    }

    echo "Role {$r} rendered successfully!\n\n";
}

echo "ALL ROLES TESTED SUCCESSFULLY WITH ZERO ERRORS!\n";
