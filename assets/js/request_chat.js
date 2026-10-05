(() => {
  const app = document.getElementById('requestChatApp');
  if (!app) return;

  const bc = new BroadcastChannel('hpu_chat_read');

  const messagesEl = document.getElementById('requestChatMessages');
  const form = document.getElementById('requestChatForm');
  const messageInput = document.getElementById('requestChatMessage');
  const attachmentInput = document.getElementById('requestChatAttachment');
  const fileNameEl = document.getElementById('requestChatFileName');
  const feedback = document.getElementById('requestChatFeedback');
  const sendButton = document.getElementById('requestChatSend');
  const replyInput = document.getElementById('requestChatReplyTo');
  const replying = document.getElementById('requestChatReplying');
  const replyName = document.getElementById('requestChatReplyName');
  let latestSignature = '';
  let firstLoad = true;

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
  const formatText = (value) => escapeHtml(value).replace(/\n/g, '<br>');

  async function readJsonResponse(response) {
    const raw = await response.text();
    try {
      return JSON.parse(raw);
    } catch (error) {
      const clean = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
      throw new Error(clean || `Server returned an invalid response (HTTP ${response.status}).`);
    }
  }

  const iconForFile = (name) => /\.pdf$/i.test(name) ? 'bi-file-earmark-pdf' : /\.(jpg|jpeg|png|gif)$/i.test(name) ? 'bi-file-earmark-image' : 'bi-file-earmark-text';

  function render(messages) {
    const signature = messages.map(m => m.id).join(',');
    if (signature === latestSignature && !firstLoad) return;
    latestSignature = signature;
    if (!messages.length) {
      messagesEl.innerHTML = '<div class="request-chat-empty"><i class="bi bi-chat-square-heart"></i><strong>No messages yet</strong><span>Start the conversation about this request.</span></div>';
      return;
    }
    messagesEl.innerHTML = messages.map(m => {
      const attachment = m.attachment_url ? `<a class="request-chat-file" href="${escapeHtml(m.attachment_url)}" target="_blank" rel="noopener"><i class="bi ${iconForFile(m.attachment_original_name || m.attachment)}"></i><span>${escapeHtml(m.attachment_original_name || 'Attachment')}</span><i class="bi bi-box-arrow-up-right"></i></a>` : '';
      const quoted = m.reply_to ? `<div class="request-chat-quote"><strong>${escapeHtml(m.reply_sender_name || 'Message')}</strong><span>${formatText((m.reply_message || '').slice(0,120))}</span></div>` : '';
      const internal = Number(m.is_internal) === 1 ? '<span class="request-chat-private"><i class="bi bi-shield-lock"></i> Internal note</span>' : '';
      return `<article class="request-chat-message ${m.mine ? 'mine' : ''} ${Number(m.is_internal) === 1 ? 'internal' : ''}">
        <div class="request-chat-avatar">${escapeHtml((m.sender_name || '?').charAt(0).toUpperCase())}</div>
        <div class="request-chat-bubble">
          <div class="request-chat-meta"><strong>${escapeHtml(m.sender_name)}</strong><span>${escapeHtml(m.sender_role)}</span>${internal}<time>${escapeHtml(m.created_label)}</time></div>
          ${quoted}${m.message ? `<div class="request-chat-text">${formatText(m.message)}</div>` : ''}${attachment}
          <button class="request-chat-reply-btn" type="button" data-id="${m.id}" data-name="${escapeHtml(m.sender_name)}"><i class="bi bi-reply"></i> Reply</button>
        </div>
      </article>`;
    }).join('');
    if (firstLoad || messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 220) messagesEl.scrollTop = messagesEl.scrollHeight;
    firstLoad = false;
  }

  async function loadMessages() {
    try {
      const url = new URL(app.dataset.fetchUrl, window.location.origin);
      url.searchParams.set('request_id', app.dataset.requestId);
      const response = await fetch(url, {credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
      const data = await readJsonResponse(response);
      if (!data.ok) throw new Error(data.message || 'Could not load messages.');
      render(data.messages || []);
      if (data.marked_read && data.last_read_id) {
        bc.postMessage({type: 'marked_read', request_id: app.dataset.requestId, last_read_id: data.last_read_id});
      }
    } catch (error) {
      if (firstLoad) messagesEl.innerHTML = `<div class="request-chat-error">${escapeHtml(error.message)}</div>`;
    }
  }

  messagesEl.addEventListener('click', e => {
    const button = e.target.closest('.request-chat-reply-btn');
    if (!button) return;
    replyInput.value = button.dataset.id;
    replyName.textContent = button.dataset.name;
    replying.classList.remove('d-none');
    messageInput.focus();
  });
  document.getElementById('requestChatCancelReply')?.addEventListener('click', () => {
    replyInput.value = ''; replying.classList.add('d-none');
  });
  attachmentInput?.addEventListener('change', () => fileNameEl.textContent = attachmentInput.files?.[0]?.name || 'No file selected');

  form.addEventListener('submit', async e => {
    e.preventDefault();
    feedback.textContent = '';
    sendButton.disabled = true;
    sendButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending';
    try {
      const response = await fetch(app.dataset.sendUrl, {method:'POST', body:new FormData(form), credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
      const data = await readJsonResponse(response);
      if (!data.ok) throw new Error(data.message || 'Message could not be sent.');
      messageInput.value = '';
      attachmentInput.value = '';
      fileNameEl.textContent = 'No file selected';
      replyInput.value = '';
      replying.classList.add('d-none');
      markTyping(false);
      feedback.className = 'request-chat-feedback success';
      feedback.textContent = 'Message sent.';
      await loadMessages();
      setTimeout(() => { feedback.textContent = ''; }, 1800);
    } catch (error) {
      feedback.className = 'request-chat-feedback error';
      feedback.textContent = error.message;
    } finally {
      sendButton.disabled = false;
      sendButton.innerHTML = '<i class="bi bi-send-fill me-1"></i> Send';
    }
  });

  const typingEl = document.getElementById('requestChatTyping');
  const presenceEl = document.getElementById('requestChatPresence');
  const liveLabel = document.getElementById('requestChatLiveLabel');
  let liveConnected = false;

  function renderLive(live) {
    const typers = (live && live.typers) || [];
    const online = (live && live.online) || [];

    if (typingEl) {
      if (typers.length) {
        const names = typers.slice(0, 2).map(t => t.name).join(', ');
        const extra = typers.length > 2 ? ` +${typers.length - 2} more` : '';
        typingEl.innerHTML = `<span class="typing-dots"><span></span><span></span><span></span></span><span>${escapeHtml(names)}${escapeHtml(extra)} ${typers.length > 1 ? 'are' : 'is'} typing…</span>`;
        typingEl.classList.remove('d-none');
      } else {
        typingEl.classList.add('d-none');
        typingEl.innerHTML = '';
      }
    }

    if (presenceEl) {
      if (online.length) {
        presenceEl.innerHTML = online.slice(0, 4).map(o =>
          `<span class="presence-chip">${escapeHtml((o.name || '?').charAt(0).toUpperCase())} ${escapeHtml(o.name.split(' ')[0])}</span>`
        ).join('') + (online.length > 4 ? `<span class="presence-chip">+${online.length - 4}</span>` : '') + '<span>online</span>';
        presenceEl.classList.remove('d-none');
      } else {
        presenceEl.classList.add('d-none');
        presenceEl.innerHTML = '';
      }
    }
  }

  function setLiveLabel() {
    if (liveLabel) liveLabel.textContent = liveConnected ? 'Live' : 'Active';
  }

  // Typing + presence heartbeat (also doubles as keep-alive).
  let lastTypingSent = 0;
  let typingState = false;

  async function sendHeartbeat(isTyping) {
    try {
      const body = new FormData();
      body.append('request_id', app.dataset.requestId);
      body.append('csrf_token', (form.querySelector('[name="csrf_token"]') || {}).value || '');
      body.append('is_typing', isTyping ? '1' : '');
      await fetch(app.dataset.typingUrl, {method: 'POST', body, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}});
    } catch (error) { /* heartbeat is best-effort */ }
  }

  function markTyping(state) {
    typingState = state;
    const now = Date.now();
    if (state && now - lastTypingSent < 2000) return;
    lastTypingSent = now;
    sendHeartbeat(state);
  }

  messageInput?.addEventListener('input', () => markTyping((messageInput.value || '').trim() !== ''));
  messageInput?.addEventListener('blur', () => markTyping(false));

  // Live stream (SSE) with polling fallback already below.
  function connectStream() {
    if (!app.dataset.streamUrl || typeof EventSource === 'undefined') return;
    let url;
    try {
      url = new URL(app.dataset.streamUrl, window.location.origin);
    } catch (error) { return; }
    url.searchParams.set('request_id', app.dataset.requestId);
    let source;
    try {
      source = new EventSource(url.toString());
    } catch (error) { return; }

    source.addEventListener('messages', () => {
      loadMessages(); // fetch path also marks the thread read
    });
    source.addEventListener('live', (e) => {
      try { renderLive(JSON.parse(e.data)); } catch (error) {}
    });
    source.onopen = () => { liveConnected = true; setLiveLabel(); };
    source.onerror = () => { liveConnected = false; setLiveLabel(); };
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) { try { source.close(); } catch (error) {} connectStream(); }
    }, { once: true });
  }

  loadMessages();
  setInterval(loadMessages, 5000);
  setInterval(() => sendHeartbeat(typingState), 15000);
  sendHeartbeat(false);
  connectStream();
  document.addEventListener('visibilitychange', () => { if (!document.hidden) loadMessages(); });
})();
