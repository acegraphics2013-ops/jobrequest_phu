<?php
// HPU Workbench - Smart FAQ & Help Assistant Component
$hpuAuthUser = $_SESSION['name'] ?? null;
$hpuAuthRole = $_SESSION['role'] ?? 'guest';
$hpuIsLoggedIn = !empty($_SESSION['user_id']);
$hpuBaseUrl = htmlspecialchars(rtrim(BASE_URL, '/'), ENT_QUOTES, 'UTF-8');

// Determine active page module
$hpuScript = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$hpuPageModule = 'global';
if (str_ends_with($hpuScript, 'index.php') || str_ends_with($hpuScript, 'login.php')) {
    $hpuPageModule = 'login';
} elseif (str_ends_with($hpuScript, 'dashboard.php')) {
    $hpuPageModule = 'dashboard';
} elseif (str_ends_with($hpuScript, 'my_requests.php') || str_ends_with($hpuScript, 'requests.php')) {
    $hpuPageModule = 'my_requests';
} elseif (str_ends_with($hpuScript, 'new_request.php')) {
    $hpuPageModule = 'new_request';
} elseif (str_ends_with($hpuScript, 'kanban.php')) {
    $hpuPageModule = 'kanban';
} elseif (str_ends_with($hpuScript, 'my_calendar.php') || str_ends_with($hpuScript, 'calendar.php')) {
    $hpuPageModule = 'calendar';
} elseif (str_ends_with($hpuScript, 'review_request.php')) {
    $hpuPageModule = 'review';
}
?>
<!-- Help Assistant CSS -->
<link rel="stylesheet" href="<?= $hpuBaseUrl ?>/assets/css/faq-assistant.css?v=<?= time() ?>">

<!-- Floating Launcher Button (Bottom Right) -->
<div id="phu-faq-launcher" role="button" tabindex="0" aria-label="Open HPU Help Assistant"
    data-base-url="<?= $hpuBaseUrl ?>" data-module="<?= e($hpuPageModule) ?>" data-role="<?= e($hpuAuthRole) ?>"
    data-user="<?= e($hpuAuthUser) ?>">
    <span class="launcher-sparkle">✦</span>
    <span>Ask HPU</span>
    <span class="launcher-badge" title="Online Assistant Active"></span>
</div>

<!-- Chat Assistant Window -->
<div id="phu-faq-window" role="dialog" aria-label="HPU Help Assistant Window" data-module="<?= e($hpuPageModule) ?>">
    <!-- Header -->
    <div class="phu-faq-header">
        <div class="phu-faq-header-info">
            <div class="phu-faq-header-icon">✦</div>
            <div>
                <div class="phu-faq-header-title">HPU Help Assistant</div>
                <div class="phu-faq-header-subtitle">
                    <span class="status-dot"></span>
                    <span>Job Request Support</span>
                </div>
            </div>
        </div>
        <div class="phu-faq-header-actions">
            <!-- Language Selector -->
            <div class="hpu-lang-selector" title="Switch Language">
                <button type="button" class="lang-btn active" data-lang="en">EN</button>
                <button type="button" class="lang-btn" data-lang="bisaya">BIS</button>
                <button type="button" class="lang-btn" data-lang="tagalog">TL</button>
            </div>
            <button type="button" id="phu-faq-clear-btn" class="phu-faq-btn-icon" title="Clear Conversation"
                aria-label="Clear Conversation">
                <i class="bi bi-trash3"></i>
            </button>
            <button type="button" id="phu-faq-close-btn" class="phu-faq-btn-icon" title="Close Assistant"
                aria-label="Close Assistant">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </div>

    <!-- Quick Navigation Toolbar -->
    <div class="hpu-faq-toolbar">
        <button type="button" class="toolbar-btn" data-flow="status_guide">
            <i class="bi bi-diagram-3"></i> Status Guide
        </button>
        <button type="button" class="toolbar-btn" data-flow="submit_guide">
            <i class="bi bi-journal-plus"></i> How to Submit
        </button>
        <button type="button" class="toolbar-btn" data-action="all_faqs">
            <i class="bi bi-grid"></i> All Topics
        </button>
    </div>

    <!-- Messages Body Feed -->
    <div id="phu-faq-body" class="phu-faq-body">
        <!-- Rendered dynamically by faq-assistant.js -->
    </div>

    <!-- Composer Form -->
    <div class="phu-faq-footer">
        <form id="phu-faq-form" class="phu-faq-form" onsubmit="return false;">
            <input type="text" id="phu-faq-input" class="phu-faq-input"
                placeholder="Ask a question about job requests..." autocomplete="off">
            <button type="submit" id="phu-faq-send-btn" class="phu-faq-send-btn" title="Send Question"
                aria-label="Send Question">
                <i class="bi bi-send-fill"></i>
            </button>
        </form>
    </div>
</div>

<!-- FAQ Assistant Script -->
<script src="<?= $hpuBaseUrl ?>/assets/js/faq-assistant.js?v=<?= time() ?>"></script>