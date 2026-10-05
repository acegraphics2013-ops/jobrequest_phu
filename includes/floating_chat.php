<?php
/*
|--------------------------------------------------------------------------
| PHU WORKBENCH - MESSENGER-STYLE FLOATING CHAT ADD-ON WITH 2-MIN AUTO-LOCK
|--------------------------------------------------------------------------
| Global widget mounted on authenticated internal pages.
| Reuses the existing Chat Center PHP/MySQL backend APIs.
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['user_id'])) {
    return;
}

$fcUserId   = (int) ($_SESSION['user_id'] ?? 0);
$fcUserName = (string) ($_SESSION['name'] ?? 'User');
$fcUserRole = (string) ($_SESSION['role'] ?? 'user');
$fcBaseUrl  = defined('BASE_URL') ? BASE_URL : '';

// Query Team Members for Direct Messaging (reusing Chat Center logic)
$fcTeamMembers = [];
if (isset($conn) && $conn instanceof mysqli) {
    try {
        $photoSelect = chatHasPhotoColumn($conn) ? "u.photo," : "NULL AS photo,";
        $visibleHpuRoles = ($fcUserRole === "user") ? "('staff')" : "('staff', 'supervisor', 'admin')";
        $grantBranch = ($fcUserRole === "user")
            ? "OR (ump_peer.is_allowed = 1 AND u.role NOT IN ('supervisor', 'admin'))"
            : "OR (ump.is_allowed = 1) OR (ump_peer.is_allowed = 1)";

        $stmtFc = $conn->prepare("
            SELECT DISTINCT u.id, u.name, u.email, u.role, u.department, u.status, {$photoSelect}
                   (u.last_seen IS NOT NULL AND u.last_seen >= NOW() - INTERVAL 3 MINUTE) AS is_online
            FROM users u
            LEFT JOIN user_module_permissions ump ON (ump.user_id = u.id AND ump.module_key = 'chat_center')
            LEFT JOIN user_module_permissions ump_peer ON (ump_peer.user_id = u.id AND ump_peer.module_key = 'peer_chat')
            WHERE u.id != ?
              AND u.status = 'Active'
              AND (
                  (u.role IN {$visibleHpuRoles} AND (ump.is_allowed IS NULL OR ump.is_allowed = 1))
                  {$grantBranch}
              )
            ORDER BY is_online DESC, FIELD(u.role, 'admin', 'supervisor', 'staff', 'user'), u.name ASC
        ");
        $stmtFc->bind_param("i", $fcUserId);
        $stmtFc->execute();
        $resFc = $stmtFc->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtFc->close();

        foreach ($resFc as $tm) {
            $sp = basename((string) ($tm["photo"] ?? ""));
            $photoUrl = $sp !== ""
                ? rtrim($fcBaseUrl, "/") . "/download.php?scope=avatars&file=" . rawurlencode($sp)
                : null;
            $fcTeamMembers[] = [
                'id' => (int)$tm['id'],
                'name' => (string)$tm['name'],
                'role' => (string)$tm['role'],
                'department' => (string)$tm['department'],
                'is_online' => (bool)$tm['is_online'],
                'photo_url' => $photoUrl
            ];
        }
    } catch (Throwable $e) {
        $fcTeamMembers = [];
    }
}
?>

<!-- Export Team Members for Floating Chat Sidebar -->
<script>
    window.__phu_team_members = <?= json_encode($fcTeamMembers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<!-- PHU Floating Chat Add-on Stylesheet -->
<link rel="stylesheet" href="<?= e($fcBaseUrl) ?>/assets/css/floating-chat.css?v=<?= time() ?>">

<div id="phu-floating-chat-container" 
     data-user-id="<?= $fcUserId ?>" 
     data-user-name="<?= e($fcUserName) ?>" 
     data-user-role="<?= e($fcUserRole) ?>"
     data-base-url="<?= e($fcBaseUrl) ?>">

    <!-- ====================================================================
         1. FLOATING CHAT HEAD (MINIMIZED LAUNCHER BUBBLE)
         ==================================================================== -->
    <div id="phu-chat-head" class="phu-chat-head" title="PHU Chat" role="button" tabindex="0" aria-label="Open PHU Chat">
        <svg class="phu-chat-head-icon" viewBox="0 0 24 24">
            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
        </svg>
        <!-- Unread Message Count Badge (Top Right) -->
        <span id="phu-chat-head-badge" class="phu-chat-badge" style="display: none;">0</span>
        <!-- Privacy Lock Badge Indicator (Bottom Right) -->
        <span id="phu-chat-head-lock" class="phu-chat-lock-badge" style="display: none;" title="Chat is Privacy Locked">
            <svg viewBox="0 0 24 24">
                <path d="M19 11H5c-1.1 0-2 .9-2 2v7c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2v-7c0-1.1-.9-2-2-2zm-7 6c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-7H8.9V8c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v3z"/>
            </svg>
        </span>
    </div>

    <!-- Additional User Floating Chat Heads Stack -->
    <div id="phu-chat-user-heads" class="phu-chat-user-heads"></div>


    <!-- ====================================================================
         2. EXPANDED CHAT WINDOW
         ==================================================================== -->
    <div id="phu-chat-window" class="phu-chat-window hidden" aria-live="polite">
        
        <!-- Window Title Header Bar (Drag Handle) -->
        <div id="phu-chat-header" class="phu-chat-header">
            <div class="phu-chat-header-title">
                <svg class="brand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                </svg>
                <span>PHU Chat</span>
            </div>

            <!-- Action Controls -->
            <div class="phu-chat-header-actions">
                <!-- Manual Lock Button -->
                <button type="button" id="phu-chat-btn-lock" class="phu-chat-action-btn btn-lock" title="Lock Chat (Privacy Protection)" aria-label="Lock Chat">
                    <svg viewBox="0 0 24 24">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </button>
                <!-- Minimize Button -->
                <button type="button" id="phu-chat-btn-minimize" class="phu-chat-action-btn" title="Minimize Chat" aria-label="Minimize Chat">
                    <svg viewBox="0 0 24 24">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                </button>
                <!-- Pop-out Standalone Browser Window Button -->
                <button type="button" id="phu-chat-btn-popout" class="phu-chat-action-btn" title="Pop-out Chat to Standalone Window" aria-label="Pop-out Window">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                        <polyline points="15 3 21 3 21 9"></polyline>
                        <line x1="10" y1="14" x2="21" y2="3"></line>
                    </svg>
                </button>
                <!-- Open Full Chat Center Link -->
                <a href="<?= e($fcBaseUrl) ?>/chat_center.php" id="phu-chat-btn-full" class="phu-chat-action-btn" title="Open Full Chat Center" aria-label="Full Chat Center">
                    <svg viewBox="0 0 24 24">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="9" y1="3" x2="9" y2="21"></line>
                    </svg>
                </a>
                <!-- Close Button -->
                <button type="button" id="phu-chat-btn-close" class="phu-chat-action-btn btn-close" title="Close Window" aria-label="Close Window">
                    <svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Window Inner Grid Body -->
        <div class="phu-chat-body">
            
            <!-- Left Sidebar (Conversations List) -->
            <div id="phu-chat-sidebar" class="phu-chat-sidebar">
                <div class="phu-chat-search-wrap">
                    <div class="phu-chat-search-box">
                        <svg class="phu-chat-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <input type="text" id="phu-chat-search-input" class="phu-chat-search-input" placeholder="Search team & messages...">
                    </div>
                </div>

                <div id="phu-chat-conv-list" class="phu-chat-conv-list">
                    <!-- Dynamic Conversations Rendered by JS -->
                </div>
            </div>

            <!-- Right Main Panel (Active Conversation) -->
            <div id="phu-chat-main" class="phu-chat-main">
                
                <!-- Active Conversation Header Bar -->
                <div class="phu-chat-topbar">
                    <button type="button" id="phu-chat-mobile-back" class="phu-chat-mobile-back" title="Back to conversations">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="15 18 9 12 15 6"></polyline>
                        </svg>
                        <span>Chats</span>
                    </button>
                    <div class="phu-chat-topbar-info">
                        <div id="phu-chat-active-name" class="phu-chat-topbar-name">General Channel</div>
                        <div id="phu-chat-active-status" class="phu-chat-topbar-status">
                            <span class="dot online"></span>
                            <span class="status-text">Public Channel</span>
                        </div>
                    </div>
                </div>

                <!-- Incoming Message Toast Notification -->
                <div id="phu-chat-new-msg-toast" class="phu-chat-new-msg-toast" style="display: none;">
                    <span id="phu-chat-toast-text">New message received</span>
                    <button type="button" id="phu-chat-toast-btn" class="phu-chat-new-msg-toast-btn">Open</button>
                </div>

                <!-- Reconnecting Network Alert -->
                <div id="phu-chat-reconnecting" class="phu-chat-reconnecting-banner" style="display: none;">
                    Reconnecting to server...
                </div>

                <!-- Idle 15-Second Warning Banner (Auto-Lock Alert) -->
                <div id="phu-chat-idle-warning" class="phu-chat-idle-warning" style="display: none;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <span>Chat will lock in 15 seconds due to inactivity.</span>
                </div>

                <!-- Messages Container -->
                <div id="phu-chat-messages" class="phu-chat-messages">
                    <!-- Dynamic Messages Rendered by JS -->
                </div>

                <!-- New Messages Floating Scroll Button -->
                <div id="phu-chat-new-msgs-btn" class="phu-chat-new-msgs-btn" style="display: none;">
                    <span>New messages ↓</span>
                </div>

                <!-- Attachment Preview Bar (When file selected) -->
                <div id="phu-chat-attachment-bar" class="phu-chat-attachment-bar" style="display: none;">
                    <span id="phu-chat-attachment-name">attachment.pdf</span>
                    <span id="phu-chat-attachment-cancel" class="phu-chat-attachment-cancel" title="Remove attachment">✕</span>
                </div>

                <!-- Quick Replies Chips -->
                <div id="phu-chat-quick-replies" class="phu-chat-quick-replies">
                    <button type="button" class="phu-chat-quick-chip" data-reply="Got it">Got it</button>
                    <button type="button" class="phu-chat-quick-chip" data-reply="On it">On it</button>
                    <button type="button" class="phu-chat-quick-chip" data-reply="Request Update">Request Update</button>
                    <button type="button" class="phu-chat-quick-chip" data-reply="Discuss further">Discuss further</button>
                </div>

                <!-- Composer Form -->
                <form id="phu-chat-composer" class="phu-chat-composer" onsubmit="return false;">
                    <input type="file" id="phu-chat-file-input" style="display: none;">
                    <button type="button" id="phu-chat-btn-attach" class="phu-chat-attach-btn" title="Attach file">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>
                        </svg>
                    </button>
                    <textarea id="phu-chat-textarea" class="phu-chat-textarea" placeholder="Type message..." rows="1"></textarea>
                    <button type="submit" id="phu-chat-btn-send" class="phu-chat-send-btn" title="Send message">
                        <svg viewBox="0 0 24 24">
                            <line x1="22" y1="2" x2="11" y2="13"></line>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                        </svg>
                    </button>
                </form>

                <!-- Privacy Lock / Session Expired Screen Overlay -->
                <div id="phu-chat-lock-screen" class="phu-chat-lock-screen" style="display: none;">
                    <svg class="phu-chat-lock-icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                    <div id="phu-chat-lock-screen-title" class="phu-chat-lock-title">PHU Chat Privacy Locked</div>
                    <div id="phu-chat-lock-screen-msg" class="phu-chat-lock-subtitle">
                        Enter password for <strong><?= e($fcUserName) ?></strong> to unlock PHU Chat.
                    </div>
                    <form id="phu-chat-unlock-form" style="display: flex; flex-direction: column; align-items: center; width: 100%;" onsubmit="return false;">
                        <input type="password" id="phu-chat-unlock-password" class="phu-chat-lock-input" placeholder="Account password..." autocomplete="current-password" required>
                        <div id="phu-chat-lock-error" class="phu-chat-lock-error" style="display: none;"></div>
                        <button type="submit" id="phu-chat-btn-unlock" class="phu-chat-unlock-btn">
                            Unlock Chat
                        </button>
                    </form>
                </div>

            </div>

        </div>

        <!-- Window Desktop Bottom-Right Resize Handle -->
        <div id="phu-chat-resize-handle" class="phu-chat-resize-handle" title="Resize window"></div>

    </div>

</div>

<!-- PHU Floating Chat Add-on Script -->
<script src="<?= e($fcBaseUrl) ?>/assets/js/floating-chat.js?v=<?= time() ?>"></script>
