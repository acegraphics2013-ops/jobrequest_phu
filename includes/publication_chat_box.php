<?php
if (!isset($pubKind, $pubId)) {
    return;
}
$pubKind = in_array($pubKind, ['event', 'recognition', 'contribution'], true) ? $pubKind : 'event';
$pubId = (int) $pubId;
if ($pubId <= 0) {
    return;
}
$chatRole = $_SESSION['role'] ?? 'user';
$canUseInternalNotes = in_array($chatRole, ['staff', 'supervisor', 'admin'], true);
if (empty($_SESSION['chat_csrf'])) {
    $_SESSION['chat_csrf'] = bin2hex(random_bytes(32));
}
$chatCsrf = (string) $_SESSION['chat_csrf'];
?>
<div class="request-chat-card" id="pubChatApp"
     data-kind="<?= htmlspecialchars($pubKind, ENT_QUOTES, 'UTF-8') ?>"
     data-publication-id="<?= $pubId ?>"
     data-fetch-url="<?= e(BASE_URL) ?>/ajax/pub_chat_fetch.php"
     data-send-url="<?= e(BASE_URL) ?>/ajax/pub_chat_send.php"
     data-read-url="<?= e(BASE_URL) ?>/ajax/pub_chat_mark_read.php"
     data-stream-url="<?= e(BASE_URL) ?>/ajax/pub_chat_stream.php"
     data-typing-url="<?= e(BASE_URL) ?>/ajax/pub_chat_typing.php">
    <div class="request-chat-header">
        <div>
            <div class="request-chat-eyebrow">Publication Conversation</div>
            <h4 class="request-chat-title"><i class="bi bi-chat-dots-fill"></i> Communication Center</h4>
            <div class="request-chat-presence d-none" id="pubChatPresence"></div>
        </div>
        <div class="request-chat-status"><span></span> <span id="pubChatLiveLabel">Active</span></div>
    </div>

    <div class="request-chat-notice">
        Messages here are connected to this <?= $pubKind === 'recognition' ? 'Recognition / Milestone' : ($pubKind === 'contribution' ? 'Content Contribution' : 'Event / Activity') ?> submission
        (<?= e(publicationDisplayCode($pubKind, (int) $pubId)) ?>)
        and visible in My Requests. Internal notes are HPU-only.
    </div>

    <div class="request-chat-messages" id="pubChatMessages" aria-live="polite">
        <div class="request-chat-loading"><span class="spinner-border spinner-border-sm"></span> Loading conversation…</div>
    </div>

    <div class="request-chat-typing d-none" id="pubChatTyping" aria-live="polite"></div>

    <form class="request-chat-form" id="pubChatForm" enctype="multipart/form-data">
        <input type="hidden" name="kind" value="<?= htmlspecialchars($pubKind, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="publication_id" value="<?= $pubId ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($chatCsrf, ENT_QUOTES, 'UTF-8') ?>">
        <div class="request-chat-replying d-none" id="pubChatReplying">
            <div><strong>Replying to</strong> <span id="pubChatReplyName"></span></div>
            <button type="button" id="pubChatCancelReply" aria-label="Cancel reply"><i class="bi bi-x-lg"></i></button>
        </div>
        <input type="hidden" name="reply_to" id="pubChatReplyTo" value="">

        <textarea name="message" id="pubChatMessage" rows="3" maxlength="3000"
                  placeholder="Write a message to the requestor or HPU staff…"></textarea>

        <div class="request-chat-form-bottom">
            <div class="request-chat-tools">
                <label class="request-chat-attach" title="Attach file">
                    <i class="bi bi-paperclip"></i>
                    <input type="file" name="attachment" id="pubChatAttachment"
                           accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.zip">
                </label>
                <span class="request-chat-file-name" id="pubChatFileName">No file selected</span>
            </div>
            <div class="request-chat-actions">
                <?php if ($canUseInternalNotes): ?>
                    <label class="request-chat-internal">
                        <input type="checkbox" name="is_internal" value="1">
                        <span><i class="bi bi-shield-lock"></i> Internal note</span>
                    </label>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary" id="pubChatSend">
                    <i class="bi bi-send-fill me-1"></i> Send
                </button>
            </div>
        </div>
        <div class="request-chat-feedback" id="pubChatFeedback"></div>
    </form>
</div>
