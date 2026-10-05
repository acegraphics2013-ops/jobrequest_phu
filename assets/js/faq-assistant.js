/**
 * HPU Workbench - AI FAQ & Help Assistant JS Engine
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'hpu_faq_chat_history';
    const LANG_KEY = 'hpu_faq_lang';

    let isWindowOpen = false;
    let isTyping = false;
    let currentLang = localStorage.getItem(LANG_KEY) || 'en';
    let baseUrl = '';
    let pageModule = 'global';
    let userRole = 'guest';
    let userName = null;
    let cachedFaqs = [];
    let suggestedChips = [];

    // DOM Elements
    let launcherEl, windowEl, bodyEl, formEl, inputEl, sendBtnEl, closeBtnEl, clearBtnEl, langBtns, toolbarEl;

    document.addEventListener('DOMContentLoaded', initFaqAssistant);

    function initFaqAssistant() {
        launcherEl = document.getElementById('phu-faq-launcher');
        windowEl = document.getElementById('phu-faq-window');
        bodyEl = document.getElementById('phu-faq-body');
        formEl = document.getElementById('phu-faq-form');
        inputEl = document.getElementById('phu-faq-input');
        sendBtnEl = document.getElementById('phu-faq-send-btn');
        closeBtnEl = document.getElementById('phu-faq-close-btn');
        clearBtnEl = document.getElementById('phu-faq-clear-btn');
        langBtns = document.querySelectorAll('.lang-btn');
        toolbarEl = document.querySelector('.hpu-faq-toolbar');

        if (!launcherEl || !windowEl) return;

        // Context Data
        baseUrl = (launcherEl.getAttribute('data-base-url') || '').replace(/\/+$/, '');
        pageModule = launcherEl.getAttribute('data-module') || 'global';
        userRole = launcherEl.getAttribute('data-role') || 'guest';
        userName = launcherEl.getAttribute('data-user') || null;

        // Sync Language UI
        syncLanguageUI();

        // Fetch Bootstrap Knowledge Base
        fetchFaqKnowledgeBase();

        // Event Listeners
        launcherEl.addEventListener('click', toggleFaqWindow);
        if (closeBtnEl) closeBtnEl.addEventListener('click', closeFaqWindow);
        if (clearBtnEl) clearBtnEl.addEventListener('click', clearConversationHistory);

        // Language Buttons
        langBtns.forEach(btn => {
            btn.addEventListener('click', function () {
                currentLang = this.getAttribute('data-lang') || 'en';
                localStorage.setItem(LANG_KEY, currentLang);
                syncLanguageUI();
                fetchFaqKnowledgeBase();
            });
        });

        // Event Delegation for Toolbar Buttons
        if (toolbarEl) {
            toolbarEl.addEventListener('click', function (e) {
                const btn = e.target.closest('.toolbar-btn');
                if (!btn) return;

                const flowKey = btn.getAttribute('data-flow');
                const action = btn.getAttribute('data-action');

                if (flowKey) {
                    fetchGuidedWorkflow(flowKey);
                } else if (action === 'all_faqs') {
                    renderAllTopics();
                }
            });
        }

        if (formEl) {
            formEl.addEventListener('submit', function (e) {
                e.preventDefault();
                handleUserSubmit();
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isWindowOpen) {
                closeFaqWindow();
            }
        });
    }

    function syncLanguageUI() {
        langBtns.forEach(btn => {
            if (btn.getAttribute('data-lang') === currentLang) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
    }

    function toggleFaqWindow() {
        if (isWindowOpen) {
            closeFaqWindow();
        } else {
            openFaqWindow();
        }
    }

    function openFaqWindow() {
        isWindowOpen = true;
        windowEl.classList.add('active');
        if (inputEl) inputEl.focus();

        const cached = getStoredHistory();
        if (cached.length === 0) {
            renderWelcomeMessage();
        } else {
            renderHistory(cached);
        }
    }

    function closeFaqWindow() {
        isWindowOpen = false;
        windowEl.classList.remove('active');
    }

    function fetchFaqKnowledgeBase() {
        const endpoint = `${baseUrl}/api/help/faqs.php?lang=${encodeURIComponent(currentLang)}&module=${encodeURIComponent(pageModule)}`;
        fetch(endpoint, {
            method: 'GET',
            headers: { 'Accept': 'application/json' }
        })
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                suggestedChips = data.suggested || [];
                cachedFaqs = data.faqs || [];
                if (data.user && data.user.name) {
                    userName = data.user.name;
                }
            }
        })
        .catch(err => {
            console.error('Failed to bootstrap FAQ system:', err);
        });
    }

    function renderWelcomeMessage() {
        bodyEl.innerHTML = '';

        let greetingText = "";
        if (userName) {
            const firstName = userName.split(' ')[0];
            if (currentLang === 'bisaya') {
                greetingText = `Maayong adlaw, ${escapeHtml(firstName)}! 👋 Unsaon nako pagtabang nimo sa HPU Job Request System karon?`;
            } else if (currentLang === 'tagalog') {
                greetingText = `Magandang araw, ${escapeHtml(firstName)}! 👋 Paano kita matutulungan sa HPU Job Request System ngayon?`;
            } else {
                greetingText = `Hi, ${escapeHtml(firstName)}! 👋 How can I help you with the HPU Job Request System today?`;
            }
        } else {
            if (currentLang === 'bisaya') {
                greetingText = `Kumusta! Ako ang HPU Help Assistant. 👋 Makatubag ko sa imong mga pangutana bahin sa pag-submit, pagsubay, ug pag-manage sa Job Requests.`;
            } else if (currentLang === 'tagalog') {
                greetingText = `Kumusta! Ako ang HPU Help Assistant. 👋 Makatutulong ako sa iyong mga tanong tungkol sa pag-submit, pag-track, at pag-manage ng Job Requests.`;
            } else {
                greetingText = `Hi! I'm the HPU Help Assistant. 👋 I can help answer common questions about submitting, tracking, reviewing, and managing Job Requests.`;
            }
        }

        const welcomeHtml = `
            <div class="phu-faq-msg bot">
                <div class="phu-faq-bubble">
                    ${greetingText}
                </div>
                <div class="phu-faq-time">${formatTime()}</div>
            </div>
        `;

        bodyEl.insertAdjacentHTML('beforeend', welcomeHtml);
        renderSuggestedChips(suggestedChips);
        saveHistory();
    }

    function renderAllTopics() {
        if (!cachedFaqs || cachedFaqs.length === 0) {
            fetchFaqKnowledgeBase();
            setTimeout(() => {
                if (cachedFaqs && cachedFaqs.length > 0) renderAllTopics();
            }, 300);
            return;
        }

        let allHtml = `
            <div class="phu-faq-msg bot">
                <div class="phu-faq-bubble">
                    <strong>${currentLang === 'bisaya' ? 'Tanan nga Pangutana' : (currentLang === 'tagalog' ? 'Lahat ng Paksa' : 'All Available FAQ Topics')}:</strong><br>
                    Select any question below to view details:
                </div>
                <div class="phu-faq-time">${formatTime()}</div>
            </div>
        `;

        bodyEl.insertAdjacentHTML('beforeend', allHtml);
        renderSuggestedChips(cachedFaqs);
        saveHistory();
        scrollToBottom();
    }

    function renderSuggestedChips(chips) {
        if (!chips || chips.length === 0) return;

        let chipsHtml = '<div class="phu-faq-suggestions">';
        chips.forEach(chip => {
            chipsHtml += `
                <button type="button" class="phu-faq-chip" data-question="${escapeHtml(chip.question)}">
                    ${escapeHtml(chip.question)}
                </button>
            `;
        });
        chipsHtml += '</div>';

        bodyEl.insertAdjacentHTML('beforeend', chipsHtml);

        const createdChips = bodyEl.querySelectorAll('.phu-faq-chip');
        createdChips.forEach(chip => {
            chip.addEventListener('click', function () {
                const questionText = this.getAttribute('data-question');
                if (questionText) {
                    processUserQuestion(questionText);
                }
            });
        });

        scrollToBottom();
    }

    function handleUserSubmit() {
        if (!inputEl) return;
        const text = inputEl.value.trim();
        if (!text || isTyping) return;

        inputEl.value = '';
        processUserQuestion(text);
    }

    function processUserQuestion(questionText) {
        const userMsgHtml = `
            <div class="phu-faq-msg user">
                <div class="phu-faq-bubble">${escapeHtml(questionText)}</div>
                <div class="phu-faq-time">${formatTime()}</div>
            </div>
        `;
        bodyEl.insertAdjacentHTML('beforeend', userMsgHtml);
        scrollToBottom();

        showTypingIndicator();

        const endpoint = `${baseUrl}/api/help/ask.php`;
        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                query: questionText,
                language: currentLang,
                page_context: pageModule
            })
        })
        .then(res => res.json())
        .then(data => {
            setTimeout(() => {
                removeTypingIndicator();

                if (data && data.success) {
                    if (data.is_did_you_mean) {
                        renderBotAnswer(data.answer, data.suggested, null, false);
                    } else if (data.matched) {
                        renderBotAnswer(data.answer, data.related, data.action_link, true, data.faq_id);
                    } else {
                        renderBotAnswer(data.answer, data.suggested || suggestedChips, null, false);
                    }
                } else {
                    renderBotAnswer("Connection issue. Please try again.", suggestedChips, null, false);
                }
            }, 350);
        })
        .catch(err => {
            setTimeout(() => {
                removeTypingIndicator();
                renderBotAnswer("Unable to reach HPU Help Engine. Please try again.", suggestedChips, null, false);
            }, 300);
        });
    }

    function fetchGuidedWorkflow(flowKey) {
        showTypingIndicator();

        const endpoint = `${baseUrl}/api/help/ask.php`;
        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                flow_key: flowKey,
                language: currentLang,
                page_context: pageModule
            })
        })
        .then(res => res.json())
        .then(data => {
            setTimeout(() => {
                removeTypingIndicator();
                if (data && data.success && data.guided_data) {
                    renderGuidedWorkflow(data.guided_data);
                }
            }, 300);
        })
        .catch(() => removeTypingIndicator());
    }

    function renderGuidedWorkflow(guided) {
        let html = `<div class="phu-faq-msg bot"><div class="phu-faq-bubble"><strong>${escapeHtml(guided.title)}</strong><br><br>`;

        if (guided.steps && guided.steps.length > 0) {
            html += `<div class="status-timeline">`;
            guided.steps.forEach(st => {
                if (st.status) {
                    html += `
                        <div class="status-item">
                            <span class="status-badge ${st.badge_class || 'bg-primary text-white'}">${escapeHtml(st.status)}</span><br>
                            ${escapeHtml(st['desc_' + currentLang] || st.desc_en)}
                        </div>
                    `;
                } else if (st.step) {
                    html += `
                        <div class="status-item">
                            <strong>Step ${st.step}:</strong> ${escapeHtml(st['text_' + currentLang] || st.text_en)}
                        </div>
                    `;
                }
            });
            html += `</div>`;
        }

        html += `</div><div class="phu-faq-time">${formatTime()}</div></div>`;

        bodyEl.insertAdjacentHTML('beforeend', html);
        saveHistory();
        scrollToBottom();
    }

    function renderBotAnswer(answerText, suggestions, actionLink, showFeedback = true, faqId = null) {
        let actionLinkHtml = '';
        if (actionLink && actionLink.url) {
            const absoluteUrl = actionLink.url.startsWith('http') ? actionLink.url : `${baseUrl}/${actionLink.url.replace(/^\/+/, '')}`;
            actionLinkHtml = `<a href="${escapeHtml(absoluteUrl)}" class="faq-action-link"><i class="bi bi-box-arrow-up-right"></i> ${escapeHtml(actionLink.label)}</a>`;
        }

        let feedbackHtml = '';
        if (showFeedback) {
            feedbackHtml = `
                <div class="phu-faq-feedback" data-faq-id="${faqId || ''}">
                    <span>Was this helpful?</span>
                    <button type="button" class="phu-faq-feedback-btn btn-yes">Yes 👍</button>
                    <button type="button" class="phu-faq-feedback-btn btn-no">No 👎</button>
                </div>
            `;
        }

        const botMsgHtml = `
            <div class="phu-faq-msg bot">
                <div class="phu-faq-bubble">
                    ${formatAnswerText(answerText)}
                    ${actionLinkHtml}
                </div>
                ${feedbackHtml}
                <div class="phu-faq-time">${formatTime()}</div>
            </div>
        `;

        bodyEl.insertAdjacentHTML('beforeend', botMsgHtml);

        const lastMsg = bodyEl.querySelector('.phu-faq-msg.bot:last-child');
        if (lastMsg) {
            const btnYes = lastMsg.querySelector('.btn-yes');
            const btnNo = lastMsg.querySelector('.btn-no');
            const feedbackContainer = lastMsg.querySelector('.phu-faq-feedback');

            if (btnYes) {
                btnYes.addEventListener('click', function () {
                    sendFeedback(faqId, 1);
                    feedbackContainer.innerHTML = '<span style="color:#16a34a; font-weight:600;">Thank you for your feedback! 😊</span>';
                    saveHistory();
                });
            }

            if (btnNo) {
                btnNo.addEventListener('click', function () {
                    sendFeedback(faqId, 0);
                    feedbackContainer.innerHTML = '<span style="color:#64748b;">Here are related topics:</span>';
                    renderSuggestedChips(suggestions || suggestedChips);
                    saveHistory();
                });
            }
        }

        if (suggestions && suggestions.length > 0) {
            renderSuggestedChips(suggestions);
        }

        saveHistory();
        scrollToBottom();
    }

    function sendFeedback(faqId, helpful) {
        const endpoint = `${baseUrl}/api/help/feedback.php`;
        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ faq_id: faqId, helpful: helpful })
        }).catch(() => {});
    }

    function showTypingIndicator() {
        if (isTyping) return;
        isTyping = true;

        const typingHtml = `
            <div id="phu-faq-typing-indicator" class="phu-faq-msg bot">
                <div class="phu-faq-typing">
                    <span></span><span></span><span></span>
                </div>
            </div>
        `;
        bodyEl.insertAdjacentHTML('beforeend', typingHtml);
        scrollToBottom();
    }

    function removeTypingIndicator() {
        isTyping = false;
        const el = document.getElementById('phu-faq-typing-indicator');
        if (el) el.remove();
    }

    function clearConversationHistory() {
        try {
            sessionStorage.removeItem(STORAGE_KEY);
        } catch (e) {}
        renderWelcomeMessage();
    }

    function saveHistory() {
        try {
            sessionStorage.setItem(STORAGE_KEY, bodyEl.innerHTML);
        } catch (e) {}
    }

    function getStoredHistory() {
        try {
            return sessionStorage.getItem(STORAGE_KEY) || '';
        } catch (e) {
            return '';
        }
    }

    function renderHistory(html) {
        bodyEl.innerHTML = html;

        bodyEl.querySelectorAll('.phu-faq-chip').forEach(chip => {
            chip.addEventListener('click', function () {
                const questionText = this.getAttribute('data-question');
                if (questionText) {
                    processUserQuestion(questionText);
                }
            });
        });

        scrollToBottom();
    }

    function scrollToBottom() {
        if (bodyEl) {
            bodyEl.scrollTop = bodyEl.scrollHeight;
        }
    }

    function formatTime() {
        return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function formatAnswerText(text) {
        return escapeHtml(text).replace(/\n/g, '<br>');
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
})();
