<?php

/*
|--------------------------------------------------------------------------
| Current Page, Role and User
|--------------------------------------------------------------------------
*/
$currentScript = str_replace(
    "\\",
    "/",
    $_SERVER["SCRIPT_NAME"] ?? ""
);

$role = $_SESSION["role"] ?? "user";
$currentUserId = (int) ($_SESSION["user_id"] ?? 0);
$userName = (string) ($_SESSION["name"] ?? "User");

// Generate initials for avatar bubble (e.g. "Ace Cabuga" -> "AC")
$nameParts = array_values(array_filter(explode(" ", trim($userName))));
$userInitials = "U";
if (count($nameParts) >= 2) {
    $userInitials = strtoupper(substr($nameParts[0], 0, 1) . substr(end($nameParts), 0, 1));
} elseif (count($nameParts) === 1) {
    $userInitials = strtoupper(substr($nameParts[0], 0, 2));
}

/*
 |--------------------------------------------------------------------------
 | Session photo backfill (existing logins predate the photo feature)
 |--------------------------------------------------------------------------
 */
if (empty($_SESSION["photo_checked"]) && $currentUserId > 0 && isset($conn) && $conn instanceof mysqli) {
    try {
        $photoStmt = $conn->prepare("SELECT photo FROM users WHERE id = ? LIMIT 1");
        if ($photoStmt) {
            $photoStmt->bind_param("i", $currentUserId);
            $photoStmt->execute();
            $photoRow = $photoStmt->get_result()->fetch_assoc();
            $photoStmt->close();
            if (!empty($photoRow["photo"])) {
                $_SESSION["photo"] = (string) $photoRow["photo"];
            }
        }
    } catch (Throwable $e) {
        // Column may not exist yet on older installs — keep initials.
    }
    $_SESSION["photo_checked"] = true;
}

/*
|--------------------------------------------------------------------------
| Per-user module visibility (role defaults + overrides)
|--------------------------------------------------------------------------
*/
require_once __DIR__ . "/module_registry.php";
require_once __DIR__ . "/module_access.php";

$canModule = static function (string $key) use ($conn, $currentUserId, $role): bool {
    try {
        return canAccessModule($conn, $currentUserId, $role, $key);
    } catch (Throwable $e) {
        return true;
    }
};

/*
|--------------------------------------------------------------------------
| Active Navigation Helper
|--------------------------------------------------------------------------
*/
if (!function_exists("navActive")) {
    function navActive(array $paths): string
    {
        $currentScript = str_replace(
            "\\",
            "/",
            $_SERVER["SCRIPT_NAME"] ?? ""
        );

        foreach ($paths as $path) {
            if (str_ends_with($currentScript, $path)) {
                return "active";
            }
        }

        return "";
    }
}

if (!function_exists("navAriaCurrent")) {
    function navAriaCurrent(array $paths): string
    {
        $currentScript = str_replace(
            "\\",
            "/",
            $_SERVER["SCRIPT_NAME"] ?? ""
        );

        foreach ($paths as $path) {
            if (str_ends_with($currentScript, $path)) {
                return ' aria-current="page"';
            }
        }

        return "";
    }
}

/*
|--------------------------------------------------------------------------
| Settings section active state (profile / change password live outside
| the main module groups, inside the user dropdown).
|--------------------------------------------------------------------------
*/
$isProfilePage = str_ends_with($currentScript, "/settings/profile.php");
$isSecurityPage = str_ends_with($currentScript, "/settings/change_password.php");
$isSettingsPage = $isProfilePage || $isSecurityPage;

/*
|--------------------------------------------------------------------------
| Role Helpers
|--------------------------------------------------------------------------
*/
$isAdmin = $role === "admin";
$isStaff = $role === "staff";
$isSupervisor = $role === "supervisor";
$isUser = $role === "user";

/*
|--------------------------------------------------------------------------
| Unread Notification & Chat Count
|--------------------------------------------------------------------------
*/
$unreadNotificationCount = 0;
$unreadChatCount = 0;

if ($currentUserId > 0 && isset($conn) && $conn instanceof mysqli) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'notifications'");

    if ($tableCheck && $tableCheck->num_rows > 0) {
        $notificationCountStmt = $conn->prepare(
            "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0"
        );

        if ($notificationCountStmt) {
            $notificationCountStmt->bind_param("i", $currentUserId);
            $notificationCountStmt->execute();
            $notificationCountRow = $notificationCountStmt->get_result()->fetch_assoc();
            $unreadNotificationCount = (int) ($notificationCountRow["total"] ?? 0);
            $notificationCountStmt->close();
        }
    }

    try {
        $chatCountStmt = $conn->prepare(
            "SELECT COUNT(*) AS total FROM chat_messages WHERE receiver_id = ? AND is_read = 0"
        );
        if ($chatCountStmt) {
            $chatCountStmt->bind_param("i", $currentUserId);
            $chatCountStmt->execute();
            $chatCountRow = $chatCountStmt->get_result()->fetch_assoc();
            $unreadChatCount = (int) ($chatCountRow["total"] ?? 0);
            $chatCountStmt->close();
        }
    } catch (Throwable $e) {
    }
}

/*
|--------------------------------------------------------------------------
| Pending Account Request Count (approvers only)
|--------------------------------------------------------------------------
*/
$pendingAccountCount = 0;

if (
    in_array($role, ["admin", "supervisor", "staff"], true)
    && $currentUserId > 0
    && isset($conn) && $conn instanceof mysqli
    && $canModule("account_requests")
) {
    $arTableCheck = $conn->query("SHOW TABLES LIKE 'account_requests'");

    if ($arTableCheck && $arTableCheck->num_rows > 0) {
        $arCountStmt = $conn->prepare("SELECT COUNT(*) AS total FROM account_requests WHERE status = 'Pending'");

        if ($arCountStmt) {
            $arCountStmt->execute();
            $arCountRow = $arCountStmt->get_result()->fetch_assoc();
            $pendingAccountCount = (int) ($arCountRow["total"] ?? 0);
            $arCountStmt->close();
        }

        $helpTableCheck = $conn->query("SHOW TABLES LIKE 'account_help_requests'");

        if ($helpTableCheck && $helpTableCheck->num_rows > 0) {
            $helpCountStmt = $conn->prepare("SELECT COUNT(*) AS total FROM account_help_requests WHERE status = 'Pending'");

            if ($helpCountStmt) {
                $helpCountStmt->execute();
                $helpCountRow = $helpCountStmt->get_result()->fetch_assoc();
                $pendingAccountCount += (int) ($helpCountRow["total"] ?? 0);
                $helpCountStmt->close();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Portal & Role Labels
|--------------------------------------------------------------------------
*/
$portalLabel = match ($role) {
    "admin" => "Administrator Portal",
    "staff" => "Staff Portal",
    "supervisor" => "Supervisor Portal",
    default => "User Portal"
};

$roleLabel = match ($role) {
    "admin" => "Administrator",
    "staff" => "HPU Staff",
    "supervisor" => "Supervisor",
    default => "Requestor"
};
?>

<!-- Mobile Sticky Top Bar -->
<div class="mobile-top-nav">
    <div class="d-flex align-items-center gap-2">
        <button type="button" id="mobileNavToggle" class="mobile-nav-toggle" aria-label="Open Menu"
            aria-expanded="false" aria-controls="phuSidebar">
            <i class="bi bi-list"></i>
        </button>
        <span class="fw-bold fs-6 text-white">HPU Workbench</span>
    </div>
    <span class="badge bg-primary text-uppercase font-monospace"
        style="font-size: 9px;"><?= e(strtoupper($role)) ?></span>
</div>
<div class="sidebar-mobile-backdrop" id="sidebarMobileBackdrop"></div>

<!-- Inline script to prevent layout jump on load -->
<script>
    (function () {
        try {
            var stored = localStorage.getItem('phu_sidebar_collapsed');
            // First visit on tablet/desktop-narrow: start collapsed for content room.
            var isNarrowDesktop = window.innerWidth <= 991 && window.innerWidth >= 768;
            if (stored === 'true' || (stored === null && isNarrowDesktop)) {
                document.documentElement.classList.add('sidebar-is-collapsed');
            }
        } catch (e) { }
    })();
</script>

<aside id="phuSidebar" class="sidebar" aria-label="Primary navigation">

    <!-- ==================================================
         BRAND HEADER
    =================================================== -->
    <div class="sidebar-brand">
        <a href="<?= e(BASE_URL) ?>/dashboard.php" class="brand-content">
            <div class="brand-icon-wrap">
                <i class="bi bi-hospital"></i>
            </div>
            <div class="brand-text">
                <span class="brand-title">HPU Workbench</span>
                <span class="brand-subtitle">Job Request System</span>
            </div>
        </a>

        <button type="button" id="sidebarCollapseBtn" class="sidebar-toggle-btn" title="Collapse sidebar"
            aria-label="Collapse sidebar" aria-expanded="true" aria-controls="phuSidebar">
            <i class="bi bi-chevron-left toggle-icon" aria-hidden="true"></i>
        </button>
    </div>

    <!-- ==================================================
         SIDEBAR MENU ITEMS
    =================================================== -->
    <div class="sidebar-menu">

        <!-- MAIN -->
        <div class="sidebar-section-heading">MAIN</div>

        <?php if ($canModule("dashboard")): ?>
            <a class="nav-link <?= navActive(["/dashboard.php"]) ?>" href="<?= e(BASE_URL) ?>/dashboard.php"
                data-tooltip="Dashboard">
                <i class="bi bi-grid-1x2"></i>
                <span>Dashboard</span>
            </a>
        <?php endif; ?>

        <!-- REQUESTS -->
        <?php if (
            $canModule("job_requests_admin") ||
            $canModule("review_requests") ||
            $canModule("new_request") ||
            $canModule("publication_submission") ||
            $canModule("my_requests")
        ): ?>

            <div class="sidebar-section-heading">REQUESTS</div>

            <?php if ($canModule("job_requests_admin")): ?>
                <a class="nav-link <?= navActive(["/admin/requests.php", "/admin/view.php"]) ?>"
                    href="<?= e(BASE_URL) ?>/admin/requests.php" data-tooltip="Job Requests">
                    <i class="bi bi-briefcase"></i>
                    <span>Job Requests</span>
                </a>
            <?php endif; ?>

            <?php if ($canModule("review_requests")): ?>
                <a class="nav-link <?= navActive(["/staff/requests.php", "/staff/review_request.php", "/staff/evaluation.php"]) ?>"
                    href="<?= e(BASE_URL) ?>/staff/requests.php"
                    data-tooltip="<?= $isSupervisor ? 'Approval Workflow' : 'Request Review' ?>">
                    <i class="<?= $isSupervisor ? "bi bi-patch-check" : "bi bi-clipboard-check" ?>"></i>
                    <span><?= $isSupervisor ? "Approval Workflow" : "Request Review" ?></span>
                </a>
            <?php endif; ?>

            <?php if ($canModule("new_request")): ?>
                <a class="nav-link <?= navActive(["/user/new_request.php"]) ?>" href="<?= e(BASE_URL) ?>/user/new_request.php"
                    data-tooltip="New Job Request">
                    <i class="bi bi-file-earmark-plus"></i>
                    <span>New Job Request</span>
                </a>
            <?php endif; ?>

            <?php if ($canModule("publication_submission")): ?>
                <?php
                $contentNavActive = navActive([
                    "/content_submission/event_activity.php",
                    "/content_submission/recognition_milestone.php",
                    "/content_submission/content_contribution.php"
                ]);
                ?>
                <div class="nav-group<?= $contentNavActive ? " open" : "" ?>">
                    <button type="button" class="nav-group-toggle<?= $contentNavActive ? " active" : "" ?>"
                        aria-expanded="<?= $contentNavActive ? "true" : "false" ?>" aria-controls="navGroupPublication"
                        data-tooltip="Publication Submission">
                        <i class="bi bi-journal-text" aria-hidden="true"></i>
                        <span>Publication Submission</span>
                        <i class="nav-chevron bi bi-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="nav-group-menu" id="navGroupPublication">
                        <a class="nav-link nav-group-item <?= navActive(["/content_submission/event_activity.php"]) ?>"
                            href="<?= e(BASE_URL) ?>/content_submission/event_activity.php" data-tooltip="Event / Activity">
                            <i class="bi bi-calendar2-event"></i>
                            <span>Event / Activity</span>
                        </a>
                        <a class="nav-link nav-group-item <?= navActive(["/content_submission/recognition_milestone.php"]) ?>"
                            href="<?= e(BASE_URL) ?>/content_submission/recognition_milestone.php"
                            data-tooltip="Recognition / Milestone">
                            <i class="bi bi-award"></i>
                            <span>Recognition / Milestone</span>
                        </a>
                        <a class="nav-link nav-group-item <?= navActive(["/content_submission/content_contribution.php"]) ?>"
                            href="<?= e(BASE_URL) ?>/content_submission/content_contribution.php"
                            data-tooltip="Content Contribution">
                            <i class="bi bi-journal-plus"></i>
                            <span>Content Contribution</span>
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($canModule("my_requests")): ?>
                <a class="nav-link <?= navActive(["/user/my_requests.php", "/user/evaluation.php", "/user/print_request.php", "/user/qr.php"]) ?>"
                    href="<?= e(BASE_URL) ?>/user/my_requests.php" data-tooltip="My Requests">
                    <i class="bi bi-card-checklist"></i>
                    <span>My Requests</span>
                </a>
            <?php endif; ?>

        <?php endif; ?>

        <!-- WORKFLOW -->
        <?php if (
            $canModule("publication_requests") ||
            $canModule("kanban") ||
            $canModule("assign_jobs") ||
            $canModule("calendar") ||
            $canModule("blocked_dates") ||
            $canModule("account_requests") ||
            $canModule("ward_health_education") ||
            $canModule("my_assigned_jobs") ||
            $canModule("iec_materials")
        ): ?>

            <div class="sidebar-section-heading">WORKFLOW</div>

            <?php if ($canModule("publication_requests")): ?>
                <a class="nav-link <?= navActive(["/staff/publication_requests.php"]) ?>"
                    href="<?= e(BASE_URL) ?>/staff/publication_requests.php" data-tooltip="Publication Requests">
                    <i class="bi bi-newspaper"></i>
                    <span>Publication Requests</span>
                </a>
            <?php endif; ?>

            <?php if ($canModule("kanban")): ?>
                <a class="nav-link <?= navActive(["/staff/kanban.php"]) ?>" href="<?= e(BASE_URL) ?>/staff/kanban.php"
                    data-tooltip="Kanban Board">
                    <i class="bi bi-kanban"></i>
                    <span>Kanban Board</span>
                </a>
            <?php endif; ?>

            <?php if ($canModule("assign_jobs")): ?>
                <a class="nav-link <?= navActive(["/assign_jobs.php"]) ?>" href="<?= e(BASE_URL) ?>/assign_jobs.php"
                    data-tooltip="Project Monitoring">
                    <i class="bi bi-person-check"></i>
                    <span>Project Monitoring</span>
                </a>
            <?php endif; ?>

            <?php if ($canModule("calendar")): ?>
                <a class="nav-link <?= navActive(["/my_calendar.php"]) ?>" href="<?= e(BASE_URL) ?>/my_calendar.php"
                    data-tooltip="Calendar of Activities">
                    <i class="bi bi-calendar3-week"></i>
                    <span>Calendar of Activities</span>
                </a>
            <?php endif; ?>

            <?php if ($canModule("blocked_dates")): ?>
                <a class="nav-link <?= navActive(["/staff/blocked_dates.php"]) ?>"
                    href="<?= e(BASE_URL) ?>/staff/blocked_dates.php" data-tooltip="Blocked Dates">
                    <i class="bi bi-calendar-x"></i>
                    <span>Blocked Dates</span>
                </a>
            <?php endif; ?>

            <?php if ($canModule("account_requests")): ?>
                <a class="nav-link <?= navActive(["/staff/account_requests.php"]) ?>"
                    href="<?= e(BASE_URL) ?>/staff/account_requests.php" data-tooltip="Account Requests">
                    <i class="bi bi-person-plus"></i>
                    <span>Account Requests</span>
                    <?php if ($pendingAccountCount > 0): ?>
                        <span class="sidebar-badge bg-danger">
                            <?= $pendingAccountCount > 99 ? "99+" : $pendingAccountCount ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>

            <?php if ($canModule("ward_health_education")): ?>
                <a class="nav-link <?= navActive(["/ward_health_education.php"]) ?>"
                    href="<?= e(BASE_URL) ?>/ward_health_education.php" data-tooltip="Ward Health Education">
                    <i class="bi bi-mortarboard-fill"></i>
                    <span>Ward Health Education</span>
                </a>
            <?php endif; ?>

            <?php if ($canModule("my_assigned_jobs")): ?>
                <a class="nav-link <?= navActive(["/my_assigned_jobs.php"]) ?>" href="<?= e(BASE_URL) ?>/my_assigned_jobs.php"
                    data-tooltip="My Assigned Jobs">
                    <i class="bi bi-person-workspace"></i>
                    <span>My Assigned Jobs</span>
                </a>
            <?php endif; ?>

            <?php if ($canModule("iec_materials")): ?>
                <a class="nav-link <?= navActive(["/iec_materials.php"]) ?>" href="<?= e(BASE_URL) ?>/iec_materials.php"
                    data-tooltip="IEC Materials">
                    <i class="bi bi-folder2-open"></i>
                    <span>IEC Materials</span>
                </a>
            <?php endif; ?>

        <?php endif; ?>

        <!-- COMMUNICATION -->
        <?php if ($canModule("notifications") || $canModule("chat_center")): ?>
            <div class="sidebar-section-heading">COMMUNICATION</div>

            <?php if ($canModule("chat_center")): ?>
                <a class="nav-link <?= navActive(["/chat_center.php"]) ?>" href="<?= e(BASE_URL) ?>/chat_center.php"
                    data-tooltip="Chat Center">
                    <i class="bi bi-chat-dots"></i>
                    <span>Chat Center</span>
                    <?php if ($unreadChatCount > 0): ?>
                        <span class="sidebar-badge bg-primary">
                            <?= $unreadChatCount > 99 ? "99+" : $unreadChatCount ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
            <?php if ($canModule("notifications")): ?>
                <a class="nav-link <?= navActive(["/user/notifications.php"]) ?>"
                    href="<?= e(BASE_URL) ?>/user/notifications.php" data-tooltip="Notifications">
                    <i class="bi bi-bell"></i>
                    <span>Notifications</span>
                    <?php if ($unreadNotificationCount > 0): ?>
                        <span class="sidebar-badge bg-danger">
                            <?= $unreadNotificationCount > 99 ? "99+" : $unreadNotificationCount ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        <?php endif; ?>

        <!-- MANAGEMENT -->
        <?php if (
            $canModule("users") ||
            $canModule("departments") ||
            $canModule("module_permissions") ||
            $canModule("backup") ||
            $canModule("upload_settings")
        ): ?>
            <div class="sidebar-section-heading">MANAGEMENT</div>

            <?php if ($canModule("users")): ?>
                <a class="nav-link <?= navActive(["/admin/users.php"]) ?>" href="<?= e(BASE_URL) ?>/admin/users.php"
                    data-tooltip="User Management">
                    <i class="bi bi-people"></i>
                    <span>User Management</span>
                </a>
            <?php endif; ?>

            <?php if ($canModule("departments")): ?>
                <a class="nav-link <?= navActive(["/admin/departments.php"]) ?>" href="<?= e(BASE_URL) ?>/admin/departments.php"
                    data-tooltip="Department Management">
                    <i class="bi bi-building"></i>
                    <span>Department Management</span>
                </a>
            <?php endif; ?>

            <?php if ($canModule("module_permissions")): ?>
                <?php
                $permPath = $isAdmin
                    ? "/admin/module_permissions.php"
                    : "/staff/module_permissions.php";
                ?>
                <a class="nav-link <?= navActive(["/admin/module_permissions.php", "/staff/module_permissions.php"]) ?>"
                    href="<?= e(BASE_URL) ?><?= e($permPath) ?>" data-tooltip="Module Access">
                    <i class="bi bi-sliders"></i>
                    <span>Module Access</span>
                </a>
            <?php endif; ?>

            <?php if ($canModule("backup")): ?>
                <a class="nav-link <?= navActive(["/admin/backup.php"]) ?>" href="<?= e(BASE_URL) ?>/admin/backup.php"
                    data-tooltip="Database Backup">
                    <i class="bi bi-database-down"></i>
                    <span>Database Backup</span>
                </a>
            <?php endif; ?>

            <?php if ($canModule("upload_settings")): ?>
                <a class="nav-link <?= navActive(["/admin/upload_settings.php"]) ?>"
                    href="<?= e(BASE_URL) ?>/admin/upload_settings.php" data-tooltip="Upload Location">
                    <i class="bi bi-folder-symlink"></i>
                    <span>Upload Location</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>

        <!-- REPORTS -->
        <?php if (
            $canModule("evaluation_analytics") ||
            $canModule("archived_requests")
        ): ?>
            <div class="sidebar-section-heading">REPORTS</div>

            <?php if ($canModule("evaluation_analytics")): ?>
                <a class="nav-link <?= navActive(["/reports/evaluation_analytics.php", "/reports/export_evaluations.php"]) ?>"
                    href="<?= e(BASE_URL) ?>/reports/evaluation_analytics.php" data-tooltip="Reports & Analytics">
                    <i class="bi bi-bar-chart"></i>
                    <span>Reports</span>
                </a>
                <a class="nav-link <?= navActive(["/reports/monthly_report.php"]) ?>"
                    href="<?= e(BASE_URL) ?>/reports/monthly_report.php" data-tooltip="Monthly Report">
                    <i class="bi bi-calendar-month"></i>
                    <span>Monthly Report</span>
                </a>
            <?php endif; ?>

            <?php if ($canModule("archived_requests")): ?>
                <?php
                $archivePath = $isAdmin
                    ? "/admin/archived_requests.php"
                    : "/staff/archived_requests.php";
                ?>
                <a class="nav-link <?= navActive(["/staff/archived_requests.php", "/admin/archived_requests.php"]) ?>"
                    href="<?= e(BASE_URL) ?><?= e($archivePath) ?>" data-tooltip="Archived Requests">
                    <i class="bi bi-archive"></i>
                    <span>Archived Requests</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>

    </div>

    <!-- ==================================================
         BOTTOM USER SECTION
    =================================================== -->
    <div class="sidebar-user-section">

        <div class="sidebar-user-card<?= $isSettingsPage ? " has-active" : "" ?>" id="sidebarUserCard" tabindex="0"
            role="button" aria-haspopup="menu" aria-expanded="false" aria-controls="sidebarUserDropdown"
            data-tooltip="<?= e($userName) ?> (<?= e($roleLabel) ?>)"
            title="<?= e($userName) ?> — <?= e($roleLabel) ?>">
            <div class="sidebar-avatar" aria-hidden="true">
                <?php $sessionPhoto = basename((string) ($_SESSION["photo"] ?? "")); ?>
                <?php if ($sessionPhoto !== ""): ?>
                    <img src="<?= e(BASE_URL) ?>/download.php?scope=avatars&amp;file=<?= rawurlencode($sessionPhoto) ?>" alt=""
                        style="width:32px;height:32px;max-width:32px;max-height:32px;object-fit:cover;border-radius:50%;display:block;"
                        onerror="this.replaceWith(document.createTextNode('<?= e($userInitials) ?>'))">
                <?php else: ?>
                    <?= e($userInitials) ?>
                <?php endif; ?>
                <span class="status-dot online" title="Online"></span>
            </div>
            <div class="sidebar-user-info">
                <span class="user-name"><?= e($userName) ?></span>
                <span class="user-role"><?= e($roleLabel) ?><?= $isSettingsPage ? " • Settings" : "" ?></span>
            </div>
            <i class="bi bi-three-dots-vertical user-menu-dots" aria-hidden="true"></i>
        </div>

        <div class="sidebar-user-dropdown" id="sidebarUserDropdown" role="menu" aria-label="Account menu">
            <p class="user-dropdown-heading">Account</p>
            <a href="<?= e(BASE_URL) ?>/settings/profile.php" role="menuitem"
                class="user-dropdown-item<?= $isProfilePage ? " active" : "" ?>" <?= $isProfilePage ? ' aria-current="page"' : "" ?>>
                <i class="bi bi-person" aria-hidden="true"></i>
                <span>My Profile</span>
                <?php if ($isProfilePage): ?><i class="bi bi-check-lg ms-auto" aria-hidden="true"></i><?php endif; ?>
            </a>
            <a href="<?= e(BASE_URL) ?>/settings/change_password.php" role="menuitem"
                class="user-dropdown-item<?= $isSecurityPage ? " active" : "" ?>" <?= $isSecurityPage ? ' aria-current="page"' : "" ?>>
                <i class="bi bi-shield-lock" aria-hidden="true"></i>
                <span>Change Password</span>
                <?php if ($isSecurityPage): ?><i class="bi bi-check-lg ms-auto" aria-hidden="true"></i><?php endif; ?>
            </a>
            <div class="user-dropdown-divider" aria-hidden="true"></div>
            <a href="<?= e(BASE_URL) ?>/logout.php" role="menuitem" class="user-dropdown-item text-danger"
                data-logout-link>
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                <span>Logout</span>
            </a>
        </div>

    </div>

</aside>

<!-- Sidebar Logic Script -->
<script>
    document.addEventListener("DOMContentLoaded", function () {
        var STORAGE_KEY = "phu_sidebar_collapsed";
        var MOBILE_BREAKPOINT = 768;
        var sidebar = document.getElementById("phuSidebar");
        var collapseBtn = document.getElementById("sidebarCollapseBtn");
        var userCard = document.getElementById("sidebarUserCard");
        var userDropdown = document.getElementById("sidebarUserDropdown");
        var backdrop = document.getElementById("sidebarMobileBackdrop");
        var mobileToggleBtn = document.getElementById("mobileNavToggle");
        var menu = sidebar ? sidebar.querySelector(".sidebar-menu") : null;

        if (!sidebar) return;

        function isMobileView() {
            return window.innerWidth < MOBILE_BREAKPOINT;
        }

        function isCollapsed() {
            return sidebar.classList.contains("is-collapsed");
        }

        function syncCollapseAria() {
            if (collapseBtn) {
                var expanded = !isCollapsed();
                collapseBtn.setAttribute("aria-expanded", expanded ? "true" : "false");
                collapseBtn.setAttribute("aria-label", expanded ? "Collapse sidebar" : "Expand sidebar");
                collapseBtn.setAttribute("title", expanded ? "Collapse sidebar" : "Expand sidebar");
            }
        }

        function setCollapsed(nextCollapsed, persist) {
            sidebar.classList.toggle("is-collapsed", nextCollapsed);
            sidebar.classList.remove("hover-expanded");
            document.documentElement.classList.toggle("sidebar-is-collapsed", nextCollapsed);
            if (persist !== false) {
                try {
                    localStorage.setItem(STORAGE_KEY, nextCollapsed ? "true" : "false");
                } catch (err) { }
            }
            closeUserDropdown();
            syncCollapseAria();
        }

        // Apply saved state to sidebar element
        if (document.documentElement.classList.contains("sidebar-is-collapsed")) {
            sidebar.classList.add("is-collapsed");
        } else {
            sidebar.classList.remove("is-collapsed");
        }
        syncCollapseAria();

        // Mark active links for assistive tech + keep them visible.
        sidebar.querySelectorAll(".nav-link.active").forEach(function (link) {
            if (!link.hasAttribute("aria-current")) {
                link.setAttribute("aria-current", "page");
            }
        });
        var firstActive = sidebar.querySelector(".nav-link.active, .nav-group-toggle.active");
        if (firstActive && firstActive.scrollIntoView) {
            try {
                firstActive.scrollIntoView({ block: "nearest" });
            } catch (err) { }
        }

        // Toggle Collapse / Expand (desktop rail)
        if (collapseBtn) {
            collapseBtn.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (isMobileView()) {
                    closeMobileDrawer();
                    return;
                }
                setCollapsed(!isCollapsed());
            });
        }

        // Hover-peek: temporarily expand the collapsed rail on desktop so
        // labels are readable and the menu stays scrollable. Purely visual;
        // persisted collapsed state is untouched until the toggle is used.
        var hoverTimer = null;
        function cancelHoverExpand() {
            if (hoverTimer) {
                clearTimeout(hoverTimer);
                hoverTimer = null;
            }
            sidebar.classList.remove("hover-expanded");
        }
        if (window.matchMedia) {
            sidebar.addEventListener("mouseenter", function () {
                if (!isCollapsed() || isMobileView()) return;
                if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
                    sidebar.classList.add("hover-expanded");
                    return;
                }
                hoverTimer = setTimeout(function () {
                    sidebar.classList.add("hover-expanded");
                }, 120);
            });
            sidebar.addEventListener("mouseleave", function () {
                cancelHoverExpand();
                closeUserDropdown();
            });
            sidebar.addEventListener("focusin", function () {
                if (isCollapsed() && !isMobileView()) sidebar.classList.add("hover-expanded");
            });
            sidebar.addEventListener("focusout", function () {
                if (isCollapsed() && !sidebar.contains(document.activeElement)) {
                    cancelHoverExpand();
                }
            });
        }

        // Accordion Submenu Toggles (single-open accordion)
        function closeOtherGroups(currentGroup) {
            document.querySelectorAll(".nav-group.open").forEach(function (otherGroup) {
                if (otherGroup !== currentGroup) {
                    otherGroup.classList.remove("open");
                    var btn = otherGroup.querySelector(".nav-group-toggle");
                    if (btn) btn.setAttribute("aria-expanded", "false");
                }
            });
        }

        document.querySelectorAll(".nav-group-toggle").forEach(function (toggle) {
            toggle.addEventListener("click", function (e) {
                e.preventDefault();

                // On the collapsed desktop rail, expand first so the
                // submenu opens inline with room instead of a flyout.
                if (isCollapsed() && !isMobileView()) {
                    setCollapsed(false);
                }

                var group = toggle.closest(".nav-group");
                if (!group) return;

                var isCurrentlyOpen = group.classList.contains("open");
                closeOtherGroups(group);
                group.classList.toggle("open", !isCurrentlyOpen);
                toggle.setAttribute("aria-expanded", !isCurrentlyOpen ? "true" : "false");
            });
            toggle.addEventListener("keydown", function (e) {
                if (e.key === "ArrowRight" || e.key === "ArrowDown") {
                    var group = toggle.closest(".nav-group");
                    if (group && !group.classList.contains("open")) {
                        e.preventDefault();
                        toggle.click();
                    }
                } else if (e.key === "ArrowLeft" || e.key === "ArrowUp") {
                    var openGroup = toggle.closest(".nav-group.open");
                    if (openGroup) {
                        e.preventDefault();
                        toggle.click();
                    }
                }
            });
        });

        // User Profile Dropdown Toggle
        function openUserDropdown(focusFirst) {
            if (!userDropdown || !userCard) return;
            userDropdown.classList.add("show");
            userCard.setAttribute("aria-expanded", "true");
            if (focusFirst) {
                var first = userDropdown.querySelector(".user-dropdown-item");
                if (first) first.focus();
            }
        }

        function closeUserDropdown(returnFocus) {
            if (!userDropdown || !userCard) return;
            userDropdown.classList.remove("show");
            userCard.setAttribute("aria-expanded", "false");
            if (returnFocus && userCard.focus) {
                try { userCard.focus(); } catch (err) { }
            }
        }

        function toggleUserDropdown() {
            if (!userDropdown) return;
            if (userDropdown.classList.contains("show")) {
                closeUserDropdown();
            } else {
                openUserDropdown();
            }
        }

        function dropdownItems() {
            if (!userDropdown) return [];
            return Array.prototype.slice.call(userDropdown.querySelectorAll(".user-dropdown-item"));
        }

        if (userCard && userDropdown) {
            userCard.addEventListener("click", function (e) {
                e.stopPropagation();
                toggleUserDropdown();
            });

            // Keyboard operability for the div-based button.
            userCard.addEventListener("keydown", function (e) {
                if (e.key === "Enter" || e.key === " ") {
                    e.preventDefault();
                    toggleUserDropdown();
                    if (userDropdown.classList.contains("show")) openUserDropdown(true);
                } else if (e.key === "Escape") {
                    closeUserDropdown();
                } else if ((e.key === "ArrowDown" || e.key === "ArrowUp") && !userDropdown.classList.contains("show")) {
                    e.preventDefault();
                    openUserDropdown(true);
                }
            });

            document.addEventListener("click", function (e) {
                if (!userCard.contains(e.target) && !userDropdown.contains(e.target)) {
                    closeUserDropdown();
                }
            });

            document.addEventListener("keydown", function (e) {
                if (e.key === "Escape") {
                    closeUserDropdown();
                    if (isMobileView()) closeMobileDrawer();
                }
            });

            userDropdown.addEventListener("keydown", function (e) {
                var items = dropdownItems();
                var idx = items.indexOf(document.activeElement);
                if (e.key === "Escape") {
                    e.preventDefault();
                    closeUserDropdown(true);
                } else if (e.key === "ArrowDown") {
                    e.preventDefault();
                    (items[idx + 1] || items[0]).focus();
                } else if (e.key === "ArrowUp") {
                    e.preventDefault();
                    (items[idx - 1] || items[items.length - 1]).focus();
                } else if (e.key === "Tab" && !e.shiftKey && idx === items.length - 1) {
                    closeUserDropdown();
                }
            });
        }

        // Mobile Drawer Toggles
        function openMobileDrawer() {
            sidebar.classList.add("mobile-show");
            if (backdrop) backdrop.classList.add("show");
            document.body.classList.add("sidebar-drawer-open");
            if (mobileToggleBtn) mobileToggleBtn.setAttribute("aria-expanded", "true");
        }

        function closeMobileDrawer() {
            sidebar.classList.remove("mobile-show");
            if (backdrop) backdrop.classList.remove("show");
            document.body.classList.remove("sidebar-drawer-open");
            if (mobileToggleBtn) mobileToggleBtn.setAttribute("aria-expanded", "false");
        }

        if (mobileToggleBtn) {
            mobileToggleBtn.addEventListener("click", function (e) {
                e.preventDefault();
                if (sidebar.classList.contains("mobile-show")) {
                    closeMobileDrawer();
                } else {
                    openMobileDrawer();
                }
            });
        }

        if (backdrop) {
            backdrop.addEventListener("click", function () {
                closeMobileDrawer();
            });
        }

        // Close the drawer after navigating (mobile only).
        sidebar.querySelectorAll("a.nav-link, .user-dropdown-item").forEach(function (link) {
            link.addEventListener("click", function () {
                if (isMobileView()) closeMobileDrawer();
            });
        });

        // Reset drawer state when resizing up to desktop.
        var resizeTimer = null;
        window.addEventListener("resize", function () {
            if (resizeTimer) clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                if (!isMobileView()) closeMobileDrawer();
                cancelHoverExpand();
            }, 150);
        });
    });
</script>

<?php
/*
|--------------------------------------------------------------------------
| PHU Floating Chat Add-on Mount
|--------------------------------------------------------------------------
| Render Messenger-style floating chat for authenticated users with
| chat_center module access on internal pages.
|--------------------------------------------------------------------------
*/
$fcScriptPath = str_replace("\\", "/", $_SERVER["SCRIPT_NAME"] ?? "");
$fcIsPrintOrExport = (str_contains($fcScriptPath, "/pdf/") || str_contains($fcScriptPath, "/export") || str_contains($fcScriptPath, "print"));
$fcIsChatCenterPage = str_ends_with($fcScriptPath, "chat_center.php");

if (!empty($currentUserId) && !empty($canModule) && $canModule("chat_center") && !$fcIsPrintOrExport && !$fcIsChatCenterPage) {
    include __DIR__ . "/floating_chat.php";
}

/*
|--------------------------------------------------------------------------
| HPU Help Assistant Mount (Authenticated Pages)
|--------------------------------------------------------------------------
*/
if (!empty($currentUserId) && !$fcIsPrintOrExport) {
    include __DIR__ . "/faq_assistant.php";
}
?>