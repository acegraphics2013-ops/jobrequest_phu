/* ==========================================================================
   PHU WORKBENCH - GLOBAL FLOATING CHAT ADD-ON ENGINE
   COMPLETE STATE MACHINE, DRAG & SNAP, MESSENGER GROUPING, 2-MIN PRIVACY LOCK
   ========================================================================== */

(function () {
    'use strict';

    // ----------------------------------------------------------------------
    // 1. CONFIGURATION & CONSTANTS
    // ----------------------------------------------------------------------
    const CHAT_AUTO_LOCK_ENABLED = true;
    const CHAT_AUTO_LOCK_MS = 120000; // 2 minutes (120,000 ms)
    const CHAT_IDLE_WARNING_MS = 105000; // 1 min 45 sec (105,000 ms)
    const CHAT_POLL_INTERVAL_MS = 1500;   // 1.5 seconds live polling
    const DRAG_THRESHOLD_PX = 5;      // Drag vs click threshold

    // DOM Container & Data Attributes
    const container = document.getElementById('phu-floating-chat-container');
    if (!container) return;

    const currentUserId = parseInt(container.getAttribute('data-user-id') || '0', 10);
    const currentUserName = container.getAttribute('data-user-name') || 'User';
    const currentUserRole = container.getAttribute('data-user-role') || 'user';
    const baseUrl = (container.getAttribute('data-base-url') || '').replace(/\/+$/, '');

    // ----------------------------------------------------------------------
    // 2. STATE MACHINE ('CLOSED' | 'MINIMIZED' | 'OPEN' | 'LOCKED')
    // ----------------------------------------------------------------------
    let chatState = 'MINIMIZED';
    const savedLocked = localStorage.getItem('phu_chat_locked') === 'true';
    const savedMinimized = localStorage.getItem('phu_chat_minimized') !== 'false';

    if (savedLocked) {
        chatState = 'LOCKED';
    } else if (!savedMinimized) {
        chatState = 'OPEN';
    } else {
        chatState = 'MINIMIZED';
    }

    let activeConversation = loadSavedConversation();
    let unreadMap = {};
    let messagesCache = [];
    let messageIdSet = new Set();
    let prevTotalUnread = -1;
    let selectedFile = null;
    let isSending = false;

    // Timers
    let idleTimer = null;
    let warningTimer = null;
    let pollTimer = null;

    // Pointer Drag & Resize State
    let isDraggingHead = false;
    let pointerStartX = 0;
    let pointerStartY = 0;
    let headDragOffset = { x: 0, y: 0 };
    let wasHeadDragged = false;

    let isDraggingWindow = false;
    let windowDragOffset = { x: 0, y: 0 };

    let isResizingWindow = false;
    let resizeStart = { x: 0, y: 0, w: 0, h: 0 };

    // ----------------------------------------------------------------------
    // 3. DOM ELEMENTS
    // ----------------------------------------------------------------------
    const chatHead = document.getElementById('phu-chat-head');
    const headBadge = document.getElementById('phu-chat-head-badge');
    const headLockBadge = document.getElementById('phu-chat-head-lock');
    const userHeadsContainer = document.getElementById('phu-chat-user-heads');
    const chatWindow = document.getElementById('phu-chat-window');
    const chatHeader = document.getElementById('phu-chat-header');
    const btnLock = document.getElementById('phu-chat-btn-lock');
    const btnMinimize = document.getElementById('phu-chat-btn-minimize');
    const btnPopout = document.getElementById('phu-chat-btn-popout');
    const btnClose = document.getElementById('phu-chat-btn-close');
    const btnMobileBack = document.getElementById('phu-chat-mobile-back');
    const searchInput = document.getElementById('phu-chat-search-input');
    const convListEl = document.getElementById('phu-chat-conv-list');
    const activeNameEl = document.getElementById('phu-chat-active-name');
    const activeStatusEl = document.getElementById('phu-chat-active-status');
    const reconnectingBanner = document.getElementById('phu-chat-reconnecting');
    const idleWarningEl = document.getElementById('phu-chat-idle-warning');
    const messagesEl = document.getElementById('phu-chat-messages');
    const newMsgsBtn = document.getElementById('phu-chat-new-msgs-btn');
    const attachmentBar = document.getElementById('phu-chat-attachment-bar');
    const attachmentNameEl = document.getElementById('phu-chat-attachment-name');
    const attachmentCancel = document.getElementById('phu-chat-attachment-cancel');
    const quickRepliesEl = document.getElementById('phu-chat-quick-replies');
    const composerForm = document.getElementById('phu-chat-composer');
    const fileInput = document.getElementById('phu-chat-file-input');
    const btnAttach = document.getElementById('phu-chat-btn-attach');
    const textareaEl = document.getElementById('phu-chat-textarea');
    const btnSend = document.getElementById('phu-chat-btn-send');
    const lockScreen = document.getElementById('phu-chat-lock-screen');
    const lockScreenTitle = document.getElementById('phu-chat-lock-screen-title');
    const lockScreenMsg = document.getElementById('phu-chat-lock-screen-msg');
    const unlockForm = document.getElementById('phu-chat-unlock-form');
    const unlockPassword = document.getElementById('phu-chat-unlock-password');
    const lockError = document.getElementById('phu-chat-lock-error');
    const btnUnlock = document.getElementById('phu-chat-btn-unlock');
    const resizeHandle = document.getElementById('phu-chat-resize-handle');

    // ----------------------------------------------------------------------
    // 4. INITIALIZATION
    // ----------------------------------------------------------------------
    function init() {
        restorePosition();
        restoreWindowSize();
        setChatState(chatState);
        bindEvents();
        startPolling();
        resetChatIdleTimer();

        // Screen Resize Guard
        window.addEventListener('resize', debounce(() => {
            validatePositionBounds();
            validateWindowSizeBounds();
        }, 200));
    }

    function loadSavedConversation() {
        try {
            const raw = localStorage.getItem('phu_chat_last_conversation');
            if (raw) {
                const parsed = JSON.parse(raw);
                if (parsed && typeof parsed === 'object') return parsed;
            }
        } catch (e) { }
        return { channel: 'general', receiver_id: null, name: 'General Channel' };
    }

    function saveActiveConversation(conv) {
        activeConversation = conv;
        localStorage.setItem('phu_chat_last_conversation', JSON.stringify(conv));
    }

    // ----------------------------------------------------------------------
    // 5. STATE MACHINE CONTROLLER
    // ----------------------------------------------------------------------
    function setChatState(newState) {
        chatState = newState;

        switch (newState) {
            case 'OPEN':
                localStorage.setItem('phu_chat_minimized', 'false');
                localStorage.setItem('phu_chat_locked', 'false');
                chatHead.style.display = 'flex';
                headLockBadge.style.display = 'none';
                chatWindow.classList.remove('hidden');
                lockScreen.style.display = 'none';
                if (userHeadsContainer) userHeadsContainer.innerHTML = '';
                resetChatIdleTimer();
                break;

            case 'MINIMIZED':
                localStorage.setItem('phu_chat_minimized', 'true');
                localStorage.setItem('phu_chat_locked', 'false');
                chatHead.style.display = 'flex';
                headLockBadge.style.display = 'none';
                chatWindow.classList.add('hidden');
                lockScreen.style.display = 'none';
                if (idleWarningEl) idleWarningEl.style.display = 'none';
                break;

            case 'LOCKED':
                localStorage.setItem('phu_chat_minimized', 'true');
                localStorage.setItem('phu_chat_locked', 'true');
                chatHead.style.display = 'flex';
                headLockBadge.style.display = 'flex';
                chatWindow.classList.add('hidden');
                lockScreen.style.display = 'flex';
                lockScreenTitle.innerText = 'PHU Chat Locked';
                lockScreenMsg.innerText = 'Enter your account password to unlock PHU Chat.';
                if (btnUnlock) {
                    btnUnlock.innerText = 'Unlock Chat';
                    btnUnlock.onclick = null;
                }
                if (messagesEl) messagesEl.innerHTML = '';
                if (idleWarningEl) idleWarningEl.style.display = 'none';
                if (idleTimer) clearTimeout(idleTimer);
                if (warningTimer) clearTimeout(warningTimer);
                break;

            case 'CLOSED':
                localStorage.setItem('phu_chat_minimized', 'true');
                chatHead.style.display = 'flex';
                chatWindow.classList.add('hidden');
                if (userHeadsContainer) userHeadsContainer.innerHTML = '';
                break;
        }
    }

    // ----------------------------------------------------------------------
    // 6. INACTIVITY TIMER & AUTO-LOCK (2 MINUTES = 120,000 MS)
    // ----------------------------------------------------------------------
    function resetChatIdleTimer() {
        if (!CHAT_AUTO_LOCK_ENABLED || chatState === 'LOCKED') return;

        if (idleTimer) clearTimeout(idleTimer);
        if (warningTimer) clearTimeout(warningTimer);

        if (idleWarningEl) idleWarningEl.style.display = 'none';

        // 1 min 45 sec warning
        warningTimer = setTimeout(showIdleWarning, CHAT_IDLE_WARNING_MS);

        // 2 min auto-lock
        idleTimer = setTimeout(autoLockFloatingChat, CHAT_AUTO_LOCK_MS);
    }

    function showIdleWarning() {
        if (chatState !== 'OPEN') return;
        if (idleWarningEl) idleWarningEl.style.display = 'flex';
    }

    function autoLockFloatingChat() {
        setChatState('LOCKED');
    }

    function manualLock() {
        autoLockFloatingChat();
    }

    function manualMinimize() {
        setChatState('MINIMIZED');
    }

    // ----------------------------------------------------------------------
    // 7. SESSION STATUS VALIDATION & ACCOUNT PASSWORD UNLOCK
    // ----------------------------------------------------------------------
    function handleUnlockAttempt() {
        if (chatState !== 'LOCKED') {
            setChatState('OPEN');
            fetchChatData();
            return;
        }

        // Display expanded window with lock screen overlay & focus password input
        chatWindow.classList.remove('hidden');
        lockScreen.style.display = 'flex';

        if (lockError) lockError.style.display = 'none';
        if (unlockPassword) {
            unlockPassword.focus();
        }
    }

    function submitPasswordUnlock(e) {
        if (e) e.preventDefault();

        const pwd = unlockPassword ? unlockPassword.value : '';
        if (!pwd) {
            if (lockError) {
                lockError.innerText = 'Please enter your account password.';
                lockError.style.display = 'block';
            }
            return;
        }

        btnUnlock.disabled = true;
        btnUnlock.innerText = 'Verifying Password...';
        if (lockError) lockError.style.display = 'none';

        fetch(baseUrl + '/api/chat/session-status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ password: pwd })
        })
            .then(res => res.json())
            .then(data => {
                btnUnlock.disabled = false;
                btnUnlock.innerText = 'Unlock Chat';

                if (data && data.authenticated && data.success) {
                    if (unlockPassword) unlockPassword.value = '';
                    if (lockError) lockError.style.display = 'none';
                    setChatState('OPEN');
                    fetchChatData();
                } else {
                    if (lockError) {
                        lockError.innerText = data.message || 'Incorrect password. Please try again.';
                        lockError.style.display = 'block';
                    }
                    if (unlockPassword) {
                        unlockPassword.value = '';
                        unlockPassword.focus();
                    }
                }
            })
            .catch(err => {
                btnUnlock.disabled = false;
                btnUnlock.innerText = 'Unlock Chat';
                if (lockError) {
                    lockError.innerText = 'Verification error. Please try again.';
                    lockError.style.display = 'block';
                }
                console.error('Password unlock error:', err);
            });
    }

    // ----------------------------------------------------------------------
    // 8. POINTER DRAGGING & EDGE SNAPPING FOR CHAT HEAD (RULE 9 & 10 & 33)
    // ----------------------------------------------------------------------
    function restorePosition() {
        try {
            const x = localStorage.getItem('phu_chat_x');
            const y = localStorage.getItem('phu_chat_y');
            if (x !== null && y !== null) {
                const posX = parseFloat(x);
                const posY = parseFloat(y);
                if (!isNaN(posX) && !isNaN(posY)) {
                    setHeadPosition(posX, posY);
                    return;
                }
            }
        } catch (e) { }

        // Default bottom-right position
        chatHead.style.bottom = '24px';
        chatHead.style.right = '24px';
        chatHead.style.left = 'auto';
        chatHead.style.top = 'auto';
    }

    function setHeadPosition(x, y) {
        const maxX = window.innerWidth - 68;
        const maxY = window.innerHeight - 68;
        const safeX = Math.max(12, Math.min(x, maxX));
        const safeY = Math.max(12, Math.min(y, maxY));

        chatHead.style.left = safeX + 'px';
        chatHead.style.top = safeY + 'px';
        chatHead.style.bottom = 'auto';
        chatHead.style.right = 'auto';
    }

    function saveHeadPosition(x, y) {
        localStorage.setItem('phu_chat_x', x.toString());
        localStorage.setItem('phu_chat_y', y.toString());
    }

    function validatePositionBounds() {
        const rect = chatHead.getBoundingClientRect();
        if (rect.left > window.innerWidth - 40 || rect.top > window.innerHeight - 40) {
            setHeadPosition(window.innerWidth - 80, window.innerHeight - 80);
            saveHeadPosition(window.innerWidth - 80, window.innerHeight - 80);
        }
    }

    function initHeadPointerDrag(e) {
        if (e.button !== 0 && e.pointerType === 'mouse') return;

        isDraggingHead = true;
        wasHeadDragged = false;
        pointerStartX = e.clientX;
        pointerStartY = e.clientY;

        const rect = chatHead.getBoundingClientRect();
        headDragOffset.x = e.clientX - rect.left;
        headDragOffset.y = e.clientY - rect.top;

        chatHead.style.transition = 'none';
        chatHead.setPointerCapture(e.pointerId);

        chatHead.addEventListener('pointermove', onHeadPointerMove);
        chatHead.addEventListener('pointerup', onHeadPointerUp);
        chatHead.addEventListener('pointercancel', onHeadPointerUp);
    }

    function onHeadPointerMove(e) {
        if (!isDraggingHead) return;

        const dist = Math.hypot(e.clientX - pointerStartX, e.clientY - pointerStartY);
        if (dist > DRAG_THRESHOLD_PX) {
            wasHeadDragged = true;
        }

        let newX = e.clientX - headDragOffset.x;
        let newY = e.clientY - headDragOffset.y;

        newX = Math.max(8, Math.min(newX, window.innerWidth - 64));
        newY = Math.max(8, Math.min(newY, window.innerHeight - 64));

        chatHead.style.left = newX + 'px';
        chatHead.style.top = newY + 'px';
        chatHead.style.bottom = 'auto';
        chatHead.style.right = 'auto';

        resetChatIdleTimer();
    }

    function onHeadPointerUp(e) {
        if (!isDraggingHead) return;
        isDraggingHead = false;

        chatHead.releasePointerCapture(e.pointerId);
        chatHead.removeEventListener('pointermove', onHeadPointerMove);
        chatHead.removeEventListener('pointerup', onHeadPointerUp);
        chatHead.removeEventListener('pointercancel', onHeadPointerUp);

        if (wasHeadDragged) {
            const rect = chatHead.getBoundingClientRect();
            const currentX = rect.left;
            const currentY = rect.top;

            // Nearest horizontal edge snapping
            const distLeft = currentX;
            const distRight = window.innerWidth - (currentX + rect.width);
            let finalX = (distLeft < distRight) ? 16 : (window.innerWidth - rect.width - 16);
            let finalY = Math.max(12, Math.min(currentY, window.innerHeight - rect.height - 12));

            chatHead.style.transition = 'left 0.18s ease, top 0.18s ease';
            chatHead.style.left = finalX + 'px';
            chatHead.style.top = finalY + 'px';

            saveHeadPosition(finalX, finalY);
        }
    }

    // ----------------------------------------------------------------------
    // 9. WINDOW DRAGGING & RESIZING
    // ----------------------------------------------------------------------
    function restoreWindowSize() {
        try {
            const w = localStorage.getItem('phu_chat_width');
            const h = localStorage.getItem('phu_chat_height');
            if (w !== null && h !== null) {
                const width = parseFloat(w);
                const height = parseFloat(h);
                if (!isNaN(width) && !isNaN(height)) {
                    chatWindow.style.width = Math.max(380, Math.min(width, window.innerWidth * 0.85)) + 'px';
                    chatWindow.style.height = Math.max(420, Math.min(height, window.innerHeight * 0.85)) + 'px';
                    return;
                }
            }
        } catch (e) { }

        // Desktop default: 680px x 540px
        if (window.innerWidth > 640) {
            chatWindow.style.width = '680px';
            chatWindow.style.height = '540px';
            const left = Math.max(20, window.innerWidth - 720);
            const top = Math.max(20, window.innerHeight - 580);
            chatWindow.style.left = left + 'px';
            chatWindow.style.top = top + 'px';
        }
    }

    function validateWindowSizeBounds() {
        if (window.innerWidth <= 640) return;
        const currentW = chatWindow.offsetWidth;
        const currentH = chatWindow.offsetHeight;
        const safeW = Math.min(currentW, window.innerWidth * 0.85);
        const safeH = Math.min(currentH, window.innerHeight * 0.85);
        chatWindow.style.width = safeW + 'px';
        chatWindow.style.height = safeH + 'px';
    }

    function initWindowDrag(e) {
        if (e.target.closest('.phu-chat-header-actions')) return;
        if (window.innerWidth <= 640) return;

        isDraggingWindow = true;
        const rect = chatWindow.getBoundingClientRect();
        windowDragOffset.x = e.clientX - rect.left;
        windowDragOffset.y = e.clientY - rect.top;

        document.addEventListener('mousemove', onWindowDrag);
        document.addEventListener('mouseup', endWindowDrag);
    }

    function onWindowDrag(e) {
        if (!isDraggingWindow) return;

        let newX = e.clientX - windowDragOffset.x;
        let newY = e.clientY - windowDragOffset.y;

        newX = Math.max(0, Math.min(newX, window.innerWidth - chatWindow.offsetWidth));
        newY = Math.max(0, Math.min(newY, window.innerHeight - chatWindow.offsetHeight));

        chatWindow.style.left = newX + 'px';
        chatWindow.style.top = newY + 'px';
        resetChatIdleTimer();
    }

    function endWindowDrag() {
        isDraggingWindow = false;
        document.removeEventListener('mousemove', onWindowDrag);
        document.removeEventListener('mouseup', endWindowDrag);
    }

    function initResize(e) {
        e.preventDefault();
        isResizingWindow = true;
        resizeStart.x = e.clientX;
        resizeStart.y = e.clientY;
        resizeStart.w = chatWindow.offsetWidth;
        resizeStart.h = chatWindow.offsetHeight;

        document.addEventListener('mousemove', onResize);
        document.addEventListener('mouseup', endResize);
    }

    function onResize(e) {
        if (!isResizingWindow) return;

        const deltaX = e.clientX - resizeStart.x;
        const deltaY = e.clientY - resizeStart.y;

        const newW = Math.max(380, Math.min(resizeStart.w + deltaX, window.innerWidth * 0.85));
        const newH = Math.max(420, Math.min(resizeStart.h + deltaY, window.innerHeight * 0.85));

        chatWindow.style.width = newW + 'px';
        chatWindow.style.height = newH + 'px';

        localStorage.setItem('phu_chat_width', newW.toString());
        localStorage.setItem('phu_chat_height', newH.toString());
        resetChatIdleTimer();
    }

    function endResize() {
        isResizingWindow = false;
        document.removeEventListener('mousemove', onResize);
        document.removeEventListener('mouseup', endResize);
    }

    // ----------------------------------------------------------------------
    // 10. POLLING ENGINE & DUPLICATE PREVENTION (RULE 26, 34, 37)
    // ----------------------------------------------------------------------
    function startPolling() {
        fetchChatData();
        pollTimer = setInterval(fetchChatData, CHAT_POLL_INTERVAL_MS);
    }

    function fetchChatData() {
        let query = '?api=fetch';
        if (activeConversation.receiver_id) {
            query += '&receiver_id=' + activeConversation.receiver_id;
        } else if (activeConversation.channel) {
            query += '&channel=' + encodeURIComponent(activeConversation.channel);
        }

        fetch(baseUrl + '/chat_center.php' + query, {
            method: 'GET',
            headers: { 'Accept': 'application/json' }
        })
            .then(res => {
                if (res.status === 401) {
                    setChatState('LOCKED');
                    throw new Error('Unauthorized');
                }
                return res.json();
            })
            .then(data => {
                if (!data || !data.ok) return;

                if (reconnectingBanner) reconnectingBanner.style.display = 'none';

                unreadMap = data.unread_counts || {};
                updateUnreadBadges(data);

                renderUserChatHeads(data);
                renderConversationsList(data);

                if (chatState === 'OPEN') {
                    mergeAndRenderMessages(data.messages || []);
                }
            })
            .catch(err => {
                if (reconnectingBanner && chatState === 'OPEN') {
                    reconnectingBanner.style.display = 'block';
                }
            });
    }

    function updateUnreadBadges(data) {
        let totalUnread = 0;
        Object.values(unreadMap).forEach(cnt => {
            totalUnread += parseInt(cnt, 10) || 0;
        });

        if (totalUnread > 0) {
            headBadge.innerText = totalUnread > 99 ? '99+' : totalUnread;
            headBadge.style.display = 'flex';
        } else {
            headBadge.style.display = 'none';
        }

        if (prevTotalUnread >= 0 && totalUnread > prevTotalUnread) {
            playNotificationChime();
            showGenericBrowserNotification();

            // Find sender with unread messages outside currently open conversation
            if (data && data.team_members) {
                const unreadUser = data.team_members.find(u => {
                    const uid = parseInt(u.id, 10);
                    return unreadMap[uid] > 0 && activeConversation.receiver_id !== uid;
                });

                if (unreadUser && chatState === 'OPEN') {
                    showNewMessageToast(unreadUser);
                }
            }
        }
        prevTotalUnread = totalUnread;
    }

    function showNewMessageToast(user) {
        const toastEl = document.getElementById('phu-chat-new-msg-toast');
        const toastText = document.getElementById('phu-chat-toast-text');
        const toastBtn = document.getElementById('phu-chat-toast-btn');
        if (!toastEl || !toastText || !toastBtn) return;

        toastText.innerText = `💬 New message from ${user.name}`;
        toastEl.style.display = 'flex';

        toastBtn.onclick = function () {
            saveActiveConversation({ channel: null, receiver_id: parseInt(user.id, 10), name: user.name });
            if (activeNameEl) activeNameEl.innerText = user.name;
            toastEl.style.display = 'none';
            fetchChatData();
        };

        setTimeout(() => {
            if (toastEl) toastEl.style.display = 'none';
        }, 5000);
    }

    function playNotificationChime() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const ctx = new AudioCtx();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();

            osc.type = 'sine';
            osc.frequency.setValueAtTime(800, ctx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(1050, ctx.currentTime + 0.15);

            gain.gain.setValueAtTime(0.12, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.28);

            osc.connect(gain);
            gain.connect(ctx.destination);

            osc.start();
            osc.stop(ctx.currentTime + 0.28);
        } catch (e) { }
    }

    function showGenericBrowserNotification() {
        if (!('Notification' in window)) return;
        if (Notification.permission === 'granted') {
            try {
                new Notification('PHU Chat', {
                    body: 'New PHU Chat message',
                    icon: baseUrl + '/favicon.ico'
                });
            } catch (e) { }
        }
    }

    // ----------------------------------------------------------------------
    // 11. MESSENGER CONSECUTIVE GROUPING & AUTO SCROLL (RULE 17 & 18)
    // ----------------------------------------------------------------------
    function mergeAndRenderMessages(newMsgs) {
        if (!messagesEl) return;

        if (activeNameEl) activeNameEl.innerText = activeConversation.name || 'PHU Chat';

        if (!newMsgs || newMsgs.length === 0) {
            messagesCache = [];
            messageIdSet.clear();
            messagesEl.innerHTML = `
                <div class="phu-chat-empty-msg">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom:8px; opacity:0.6;">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                    <div>No messages in ${escapeHtml(activeConversation.name || 'this conversation')} yet.</div>
                    <div style="font-size:11px; margin-top:4px; opacity:0.75;">Send a message below to start chatting!</div>
                </div>
            `;
            return;
        }

        // Check scroll position before updating DOM
        const isNearBottom = (messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight) < 80;

        messagesCache = newMsgs;
        messageIdSet = new Set(newMsgs.map(m => parseInt(m.id, 10)));

        let html = '';
        let lastSenderId = null;

        newMsgs.forEach((m, idx) => {
            const senderId = parseInt(m.sender_id, 10);
            const isMe = (senderId === currentUserId);
            const timeStr = formatTime(m.created_at);
            const showSenderHeader = (!isMe && senderId !== lastSenderId);
            lastSenderId = senderId;

            html += `
                <div class="phu-chat-msg-group ${isMe ? 'me' : 'other'}">
                    ${showSenderHeader ? `<div class="phu-chat-msg-sender">${escapeHtml(m.sender_name)}</div>` : ''}
                    <div class="phu-chat-bubble">
                        ${m.reply_sender_name ? `
                            <div class="phu-chat-reply-quote">
                                <strong>${escapeHtml(m.reply_sender_name)}</strong>: ${escapeHtml(m.reply_message || m.reply_attachment_name || '')}
                            </div>
                        ` : ''}

                        <div>${escapeHtml(m.message)}</div>

                        ${m.attachment_url ? `
                            <div class="phu-chat-attachment">
                                ${m.attachment_is_image ? `
                                    <a href="${m.attachment_url}" target="_blank">
                                        <img src="${m.attachment_url}" class="phu-chat-attachment-img" alt="attachment">
                                    </a>
                                ` : `
                                    <a href="${m.attachment_url}" target="_blank" class="phu-chat-attachment-file">
                                        📄 ${escapeHtml(m.attachment_name || 'Download File')}
                                    </a>
                                `}
                            </div>
                        ` : ''}

                        <div class="phu-chat-msg-time">${timeStr}</div>
                    </div>
                </div>
            `;
        });

        messagesEl.innerHTML = html;

        // Auto-scroll logic
        if (isNearBottom) {
            messagesEl.scrollTop = messagesEl.scrollHeight;
            if (newMsgsBtn) newMsgsBtn.style.display = 'none';
        } else {
            if (newMsgsBtn) newMsgsBtn.style.display = 'flex';
        }
    }

    function renderUserChatHeads(data) {
        if (!userHeadsContainer) return;

        if (chatState !== 'MINIMIZED' && chatState !== 'LOCKED') {
            userHeadsContainer.innerHTML = '';
            return;
        }

        const teamMembers = data.team_members || window.__phu_team_members || [];
        const onlineUsers = new Set(data.online_user_ids || []);

        const activeUsers = teamMembers.filter(u => {
            const uid = parseInt(u.id, 10);
            return (unreadMap[uid] && unreadMap[uid] > 0) || (activeConversation.receiver_id === uid);
        }).slice(0, 3);

        if (activeUsers.length === 0) {
            userHeadsContainer.innerHTML = '';
            return;
        }

        const mainRect = chatHead.getBoundingClientRect();
        let html = '';

        activeUsers.forEach((u, index) => {
            const uid = parseInt(u.id, 10);
            const isOnline = onlineUsers.has(uid);
            const unreadCnt = unreadMap[uid] || 0;
            const initials = (u.name || 'U').split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();

            const headTop = Math.max(12, mainRect.top - ((index + 1) * 66));
            const headLeft = Math.max(12, Math.min(mainRect.left, window.innerWidth - 68));

            html += `
                <div class="phu-user-chat-head" 
                     data-user-id="${uid}" 
                     data-name="${escapeHtml(u.name)}" 
                     style="left: ${headLeft}px; top: ${headTop}px;" 
                     title="${escapeHtml(u.name)}">
                    
                    ${u.photo_url ? `<img src="${u.photo_url}" alt="${escapeHtml(u.name)}">` : initials}
                    <span class="phu-chat-status-dot ${isOnline ? 'online' : ''}"></span>
                    <span class="user-head-label">${escapeHtml(u.name)}</span>

                    ${unreadCnt > 0 ? `<span class="phu-chat-badge">${unreadCnt > 99 ? '99+' : unreadCnt}</span>` : ''}

                    ${chatState === 'LOCKED' ? `
                        <span class="phu-chat-lock-badge">
                            <svg viewBox="0 0 24 24">
                                <path d="M19 11H5c-1.1 0-2 .9-2 2v7c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2v-7c0-1.1-.9-2-2-2zm-7 6c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-7H8.9V8c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v3z"/>
                            </svg>
                        </span>
                    ` : ''}
                </div>
            `;
        });

        userHeadsContainer.innerHTML = html;

        userHeadsContainer.querySelectorAll('.phu-user-chat-head').forEach(head => {
            head.addEventListener('click', function () {
                const uid = parseInt(this.getAttribute('data-user-id'), 10);
                const name = this.getAttribute('data-name');

                saveActiveConversation({ channel: null, receiver_id: uid, name: name });
                activeNameEl.innerText = name;
                handleUnlockAttempt();
            });
        });
    }

    function renderConversationsList(data) {
        if (!convListEl) return;

        const onlineUsers = new Set(data.online_user_ids || []);
        const dmMap = data.dm_previews || {};
        const filterText = (searchInput ? searchInput.value : '').toLowerCase().trim();

        let html = '<div class="phu-chat-section-label">Team Channels</div>';
        const channels = [
            { key: 'general', name: 'General Channel', desc: 'Team-wide discussion' },
            { key: 'announcements', name: 'Announcements', desc: 'Broadcast updates' }
        ];

        channels.forEach(ch => {
            if (filterText && !ch.name.toLowerCase().includes(filterText)) return;
            const isActive = !activeConversation.receiver_id && activeConversation.channel === ch.key;

            html += `
                <div class="phu-chat-item ${isActive ? 'active' : ''}" data-type="channel" data-channel="${ch.key}" data-name="${ch.name}">
                    <div class="phu-chat-avatar" style="background:#dbeafe; color:#1e40af;">#</div>
                    <div class="phu-chat-item-info">
                        <div class="phu-chat-item-name">${ch.name}</div>
                        <div class="phu-chat-item-preview">${ch.desc}</div>
                    </div>
                </div>
            `;
        });

        html += '<div class="phu-chat-section-label" style="margin-top:8px;">Direct Messages</div>';

        const teamList = (data.team_members && Array.isArray(data.team_members) && data.team_members.length > 0)
            ? data.team_members
            : (window.__phu_team_members || []);

        teamList.forEach(u => {
            if (parseInt(u.id, 10) === currentUserId) return;
            if (filterText && !u.name.toLowerCase().includes(filterText) && !(u.department || '').toLowerCase().includes(filterText)) return;

            const isOnline = onlineUsers.has(parseInt(u.id, 10));
            const unreadCnt = unreadMap[u.id] || 0;
            const preview = dmMap[u.id] ? dmMap[u.id].text : (u.role || 'Staff');
            const isActive = activeConversation.receiver_id === parseInt(u.id, 10);
            const initials = (u.name || 'U').split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();

            html += `
                <div class="phu-chat-item ${isActive ? 'active' : ''}" data-type="dm" data-user-id="${u.id}" data-name="${escapeHtml(u.name)}">
                    <div class="phu-chat-avatar">
                        ${u.photo_url ? `<img src="${u.photo_url}" alt="">` : initials}
                        <span class="phu-chat-status-dot ${isOnline ? 'online' : ''}"></span>
                    </div>
                    <div class="phu-chat-item-info">
                        <div class="phu-chat-item-name">${escapeHtml(u.name)}</div>
                        <div class="phu-chat-item-preview">${escapeHtml(preview)}</div>
                    </div>
                    ${unreadCnt > 0 ? `<div class="phu-chat-item-unread">${unreadCnt}</div>` : ''}
                </div>
            `;
        });

        convListEl.innerHTML = html;

        convListEl.querySelectorAll('.phu-chat-item').forEach(item => {
            item.addEventListener('click', function () {
                const type = this.getAttribute('data-type');
                const name = this.getAttribute('data-name');

                if (type === 'channel') {
                    const channel = this.getAttribute('data-channel');
                    saveActiveConversation({ channel, receiver_id: null, name });
                } else if (type === 'dm') {
                    const receiver_id = parseInt(this.getAttribute('data-user-id'), 10);
                    saveActiveConversation({ channel: null, receiver_id, name });
                }

                activeNameEl.innerText = name;
                if (window.innerWidth <= 640) {
                    chatWindow.classList.add('mobile-chat-open');
                }

                fetchChatData();
                resetChatIdleTimer();
            });
        });
    }

    // ----------------------------------------------------------------------
    // 12. MESSAGE SENDING & ATTACHMENT UPLOAD
    // ----------------------------------------------------------------------
    function sendMessage() {
        if (isSending) return;
        const text = textareaEl.value.trim();
        if (!text && !selectedFile) return;

        isSending = true;
        btnSend.disabled = true;

        // Instant Optimistic Rendering
        const tempMsg = {
            id: 'temp_' + Date.now(),
            sender_id: currentUserId,
            sender_name: currentUserName,
            message: text,
            attachment_url: selectedFile ? URL.createObjectURL(selectedFile) : null,
            attachment_name: selectedFile ? selectedFile.name : null,
            attachment_is_image: selectedFile ? selectedFile.type.startsWith('image/') : false,
            created_at: new Date().toISOString()
        };

        const updatedMsgs = [...messagesCache, tempMsg];
        mergeAndRenderMessages(updatedMsgs);

        const formData = new FormData();
        formData.append('message', text);

        if (activeConversation.receiver_id) {
            formData.append('receiver_id', activeConversation.receiver_id);
        } else {
            formData.append('channel', activeConversation.channel || 'general');
        }

        if (selectedFile) {
            formData.append('attachment', selectedFile);
        }

        textareaEl.value = '';
        selectedFile = null;
        if (attachmentBar) attachmentBar.style.display = 'none';

        fetch(baseUrl + '/chat_center.php?api=send', {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                isSending = false;
                btnSend.disabled = false;

                if (data && data.ok) {
                    fetchChatData();
                    resetChatIdleTimer();
                } else if (data && data.error) {
                    alert(data.error);
                    fetchChatData();
                }
            })
            .catch(err => {
                isSending = false;
                btnSend.disabled = false;
                console.error('Send message error:', err);
                fetchChatData();
            });
    }

    // ----------------------------------------------------------------------
    // 13. EVENT BINDINGS
    // ----------------------------------------------------------------------
    function bindEvents() {
        // Chat Head Pointer Events (Drag + Click guard)
        chatHead.addEventListener('pointerdown', initHeadPointerDrag);
        chatHead.addEventListener('click', function (e) {
            if (wasHeadDragged) return; // Ignore click after dragging
            handleUnlockAttempt();
        });

        // Window Dragging & Resizing
        chatHeader.addEventListener('mousedown', initWindowDrag);
        if (resizeHandle) {
            resizeHandle.addEventListener('mousedown', initResize);
        }

        // Header Action Controls
        if (btnLock) btnLock.addEventListener('click', manualLock);
        if (btnMinimize) btnMinimize.addEventListener('click', manualMinimize);
        if (btnClose) btnClose.addEventListener('click', function () {
            setChatState('CLOSED');
        });

        if (btnMobileBack) {
            btnMobileBack.addEventListener('click', function () {
                chatWindow.classList.remove('mobile-chat-open');
            });
        }

        // Floating New Messages Scroll Button
        if (newMsgsBtn) {
            newMsgsBtn.addEventListener('click', function () {
                if (messagesEl) {
                    messagesEl.scrollTop = messagesEl.scrollHeight;
                    newMsgsBtn.style.display = 'none';
                }
            });
        }

        // Lock Screen Password Unlock Form
        if (unlockForm) {
            unlockForm.addEventListener('submit', submitPasswordUnlock);
        }

        // File Attachment
        if (btnAttach && fileInput) {
            btnAttach.addEventListener('click', () => fileInput.click());
            fileInput.addEventListener('change', function () {
                if (this.files && this.files[0]) {
                    selectedFile = this.files[0];
                    attachmentNameEl.innerText = selectedFile.name;
                    attachmentBar.style.display = 'flex';
                }
            });
        }

        if (attachmentCancel) {
            attachmentCancel.addEventListener('click', function () {
                selectedFile = null;
                fileInput.value = '';
                attachmentBar.style.display = 'none';
            });
        }

        // Quick Replies
        if (quickRepliesEl) {
            quickRepliesEl.querySelectorAll('.phu-chat-quick-chip').forEach(chip => {
                chip.addEventListener('click', function () {
                    const text = this.getAttribute('data-reply');
                    textareaEl.value = text;
                    sendMessage();
                });
            });
        }

        // Composer Submit
        if (composerForm) {
            composerForm.addEventListener('submit', function (e) {
                e.preventDefault();
                sendMessage();
            });
        }

        if (textareaEl) {
            textareaEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
        }

        // Search Input Filter
        if (searchInput) {
            searchInput.addEventListener('input', debounce(() => {
                fetchChatData();
            }, 150));
        }

        // Reset Inactivity Timer on User Interactions
        const resetEvents = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'];
        resetEvents.forEach(evt => {
            container.addEventListener(evt, resetChatIdleTimer, { passive: true });
        });

        // Page Visibility API Guard (Rule 39)
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                // Tab hidden: do not reset idle timer so it locks when 2 minutes elapse
            } else {
                // Tab active: if state is open, reset timer
                if (chatState === 'OPEN') resetChatIdleTimer();
            }
        });
    }

    // Helper functions
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatTime(timeStr) {
        if (!timeStr) return '';
        try {
            const date = new Date(timeStr.replace(/-/g, '/'));
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return timeStr;
        }
    }

    function debounce(func, wait) {
        let timeout;
        return function (...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    // Initialize when DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
