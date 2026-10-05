<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - HELP ASSISTANT ADMIN MANAGEMENT & ANALYTICS
|--------------------------------------------------------------------------
*/

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

requireRole(["admin", "supervisor"]);

$pageTitle = "Help Assistant Management & Analytics";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - HPU Workbench</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(rtrim(BASE_URL, '/'), ENT_QUOTES, 'UTF-8') ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #0f172a; }
        .main-content { margin-left: 260px; padding: 2rem; transition: all 0.3s ease; }
        .card { border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .kpi-card { padding: 1.25rem; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; }
        .kpi-value { font-size: 1.75rem; font-weight: 700; color: #0f172a; margin-top: 0.25rem; }
        .kpi-label { font-size: 0.85rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
        .table > :not(caption) > * > * { padding: 0.75rem 1rem; vertical-align: middle; }
        .badge-public { background-color: #dcfce7; color: #166534; font-weight: 600; }
        .badge-authenticated { background-color: #dbeafe; color: #1e40af; font-weight: 600; }
        .badge-admin { background-color: #f3e8ff; color: #6b21a8; font-weight: 600; }
        @media (max-width: 991.98px) { .main-content { margin-left: 0; padding: 1rem; } }
    </style>
</head>
<body>

<?php include "../includes/sidebar.php"; ?>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1"><i class="bi bi-headset text-primary me-2"></i>Help Assistant Management</h3>
            <p class="text-muted mb-0">Manage knowledge base FAQs, monitor analytics, and convert unanswered questions.</p>
        </div>
        <div>
            <button type="button" class="btn btn-primary rounded-pill px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#faqModal" onclick="openCreateFaqModal()">
                <i class="bi bi-plus-lg me-1"></i> Add New FAQ
            </button>
        </div>
    </div>

    <!-- Analytics KPI Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-label">Total Questions Asked</div>
                <div class="kpi-value" id="kpiTotalQueries">0</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-label">AI Match Rate</div>
                <div class="kpi-value text-success" id="kpiMatchRate">0%</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-label">User Helpful Rate</div>
                <div class="kpi-value text-primary" id="kpiHelpfulRate">0%</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-label">Unmatched Queries</div>
                <div class="kpi-value text-warning" id="kpiUnmatched">0</div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- FAQs Management Table -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0">Knowledge Base FAQs</h6>
                    <div class="d-flex gap-2">
                        <input type="text" id="faqSearchInput" class="form-control form-control-sm" placeholder="Search FAQs..." onkeyup="filterFaqsTable()">
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="faqsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Category</th>
                                <th>Question (English)</th>
                                <th>Visibility</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="faqsTableBody">
                            <tr><td colspan="5" class="text-center py-4 text-muted">Loading FAQs...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Unmatched Search Terms Review -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header bg-white py-3">
                    <h6 class="fw-bold mb-0"><i class="bi bi-question-diamond text-warning me-2"></i>Unanswered Search Terms</h6>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush" id="unmatchedList">
                        <li class="list-group-item text-center py-4 text-muted">Loading unmatched queries...</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit FAQ Modal -->
<div class="modal fade" id="faqModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="faqModalTitle">Add New FAQ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="faqForm" onsubmit="saveFaq(event)">
                <div class="modal-body">
                    <input type="hidden" id="faqId" value="0">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Category</label>
                            <select id="faqCategoryKey" class="form-select" required>
                                <option value="getting_started">Getting Started</option>
                                <option value="submitting_requests">Submitting Requests</option>
                                <option value="tracking">Tracking & Status</option>
                                <option value="status_workflow">Status Workflow</option>
                                <option value="revisions_attachments">Revisions & Attachments</option>
                                <option value="kanban_board">Kanban & Calendar</option>
                                <option value="account_access">Account & Login</option>
                                <option value="technical_support">Technical Support</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Visibility</label>
                            <select id="faqVisibility" class="form-select">
                                <option value="public">Public (Login Page & Auth)</option>
                                <option value="authenticated">Authenticated Only</option>
                                <option value="admin_only">Admin / Supervisor Only</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Question (English) *</label>
                            <input type="text" id="faqQuestionEn" class="form-control" required placeholder="e.g. How do I submit a job request?">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Question (Bisaya)</label>
                            <input type="text" id="faqQuestionBisaya" class="form-control" placeholder="e.g. Unsaon pagpasa og job request?">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Question (Tagalog)</label>
                            <input type="text" id="faqQuestionTagalog" class="form-control" placeholder="e.g. Paano mag-submit ng job request?">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Answer (English) *</label>
                            <textarea id="faqAnswerEn" class="form-control" rows="3" required placeholder="Detailed answer..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Answer (Bisaya)</label>
                            <textarea id="faqAnswerBisaya" class="form-control" rows="3" placeholder="Bisaya answer..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Answer (Tagalog)</label>
                            <textarea id="faqAnswerTagalog" class="form-control" rows="3" placeholder="Tagalog answer..."></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Keywords (comma separated)</label>
                            <input type="text" id="faqKeywords" class="form-control" placeholder="submit, create, request, form">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Aliases (comma separated)</label>
                            <input type="text" id="faqAliases" class="form-control" placeholder="magpasa, unsaon pag file">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Module Scope</label>
                            <input type="text" id="faqModuleKey" class="form-control" value="global" placeholder="global, my_requests, kanban">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Role Scope</label>
                            <select id="faqRoleScope" class="form-select">
                                <option value="all">All Roles</option>
                                <option value="user">User Only</option>
                                <option value="staff">Staff Only</option>
                                <option value="supervisor">Supervisor Only</option>
                                <option value="admin">Admin Only</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Sort Order</label>
                            <input type="number" id="faqSortOrder" class="form-control" value="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Save FAQ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars(rtrim(BASE_URL, '/'), ENT_QUOTES, 'UTF-8') ?>/assets/js/bootstrap.bundle.min.css"></script>
<script>
    let faqsCache = [];

    document.addEventListener('DOMContentLoaded', () => {
        loadAnalytics();
        loadFaqs();
        loadUnmatched();
    });

    function loadAnalytics() {
        fetch('../api/admin/help/analytics.php')
            .then(res => res.json())
            .then(data => {
                if (data && data.success && data.analytics) {
                    const a = data.analytics;
                    document.getElementById('kpiTotalQueries').textContent = a.total_questions || 0;
                    document.getElementById('kpiMatchRate').textContent = (a.match_rate || 100) + '%';
                    document.getElementById('kpiHelpfulRate').textContent = (a.helpful_rate || 100) + '%';
                    document.getElementById('kpiUnmatched').textContent = a.unmatched_pending || 0;
                }
            });
    }

    function loadFaqs() {
        fetch('../api/admin/help/faqs.php')
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    faqsCache = data.faqs || [];
                    renderFaqsTable(faqsCache);
                }
            });
    }

    function renderFaqsTable(faqs) {
        const tbody = document.getElementById('faqsTableBody');
        if (!faqs || faqs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No FAQs found.</td></tr>';
            return;
        }

        let html = '';
        faqs.forEach(f => {
            const badgeClass = f.visibility === 'public' ? 'badge-public' : (f.visibility === 'authenticated' ? 'badge-authenticated' : 'badge-admin');
            html += `
                <tr>
                    <td><span class="badge bg-light text-dark border">${escapeHtml(f.category_title || f.category_key)}</span></td>
                    <td><strong>${escapeHtml(f.question_en)}</strong></td>
                    <td><span class="badge ${badgeClass}">${escapeHtml(f.visibility)}</span></td>
                    <td>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" ${f.is_active == 1 ? 'checked' : ''} onchange="toggleFaqStatus(${f.id}, this.checked)">
                        </div>
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="openEditFaqModal(${f.id})"><i class="bi bi-pencil"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteFaq(${f.id})"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    }

    function filterFaqsTable() {
        const term = document.getElementById('faqSearchInput').value.toLowerCase().trim();
        const filtered = faqsCache.filter(f =>
            (f.question_en && f.question_en.toLowerCase().includes(term)) ||
            (f.answer_en && f.answer_en.toLowerCase().includes(term)) ||
            (f.category_key && f.category_key.toLowerCase().includes(term))
        );
        renderFaqsTable(filtered);
    }

    function loadUnmatched() {
        fetch('../api/admin/help/unmatched.php')
            .then(res => res.json())
            .then(data => {
                const ul = document.getElementById('unmatchedList');
                if (data && data.success && data.unmatched && data.unmatched.length > 0) {
                    let html = '';
                    data.unmatched.forEach(u => {
                        html += `
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold text-dark">${escapeHtml(u.query_text)}</div>
                                    <small class="text-muted">Asked ${u.count} time(s)</small>
                                </div>
                                <div>
                                    <button class="btn btn-xs btn-sm btn-outline-success me-1" title="Convert to FAQ" onclick="convertUnmatchedToFaq('${escapeHtml(u.query_text)}')"><i class="bi bi-plus"></i></button>
                                    <button class="btn btn-xs btn-sm btn-outline-secondary" title="Ignore" onclick="updateUnmatchedStatus(${u.id}, 'ignored')"><i class="bi bi-x"></i></button>
                                </div>
                            </li>
                        `;
                    });
                    ul.innerHTML = html;
                } else {
                    ul.innerHTML = '<li class="list-group-item text-center py-4 text-muted">No unmatched queries to review! 🎉</li>';
                }
            });
    }

    function convertUnmatchedToFaq(queryText) {
        openCreateFaqModal();
        document.getElementById('faqQuestionEn').value = queryText;
        const modal = new bootstrap.Modal(document.getElementById('faqModal'));
        modal.show();
    }

    function updateUnmatchedStatus(id, status) {
        fetch('../api/admin/help/unmatched.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, status: status })
        }).then(() => loadUnmatched());
    }

    function openCreateFaqModal() {
        document.getElementById('faqForm').reset();
        document.getElementById('faqId').value = '0';
        document.getElementById('faqModalTitle').textContent = 'Add New FAQ';
    }

    function openEditFaqModal(id) {
        const f = faqsCache.find(item => item.id == id);
        if (!f) return;

        document.getElementById('faqId').value = f.id;
        document.getElementById('faqCategoryKey').value = f.category_key || 'getting_started';
        document.getElementById('faqVisibility').value = f.visibility || 'public';
        document.getElementById('faqQuestionEn').value = f.question_en || '';
        document.getElementById('faqQuestionBisaya').value = f.question_bisaya || '';
        document.getElementById('faqQuestionTagalog').value = f.question_tagalog || '';
        document.getElementById('faqAnswerEn').value = f.answer_en || '';
        document.getElementById('faqAnswerBisaya').value = f.answer_bisaya || '';
        document.getElementById('faqAnswerTagalog').value = f.answer_tagalog || '';
        document.getElementById('faqKeywords').value = typeof f.keywords === 'string' ? f.keywords : JSON.stringify(f.keywords);
        document.getElementById('faqAliases').value = typeof f.aliases === 'string' ? f.aliases : JSON.stringify(f.aliases);
        document.getElementById('faqModuleKey').value = f.module_key || 'global';
        document.getElementById('faqRoleScope').value = f.role_scope || 'all';
        document.getElementById('faqSortOrder').value = f.sort_order || 0;
        document.getElementById('faqModalTitle').textContent = 'Edit FAQ #' + f.id;

        const modal = new bootstrap.Modal(document.getElementById('faqModal'));
        modal.show();
    }

    function saveFaq(e) {
        e.preventDefault();
        const payload = {
            action: 'save',
            id: parseInt(document.getElementById('faqId').value) || 0,
            category_key: document.getElementById('faqCategoryKey').value,
            visibility: document.getElementById('faqVisibility').value,
            question_en: document.getElementById('faqQuestionEn').value,
            question_bisaya: document.getElementById('faqQuestionBisaya').value,
            question_tagalog: document.getElementById('faqQuestionTagalog').value,
            answer_en: document.getElementById('faqAnswerEn').value,
            answer_bisaya: document.getElementById('faqAnswerBisaya').value,
            answer_tagalog: document.getElementById('faqAnswerTagalog').value,
            keywords: document.getElementById('faqKeywords').value,
            aliases: document.getElementById('faqAliases').value,
            module_key: document.getElementById('faqModuleKey').value,
            role_scope: document.getElementById('faqRoleScope').value,
            sort_order: parseInt(document.getElementById('faqSortOrder').value) || 0,
            is_active: 1
        };

        fetch('../api/admin/help/faqs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                bootstrap.Modal.getInstance(document.getElementById('faqModal')).hide();
                loadFaqs();
                loadAnalytics();
            } else {
                alert(data.message || 'Error saving FAQ.');
            }
        });
    }

    function toggleFaqStatus(id, isActive) {
        fetch('../api/admin/help/faqs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'toggle', id: id, is_active: isActive ? 1 : 0 })
        }).then(() => loadFaqs());
    }

    function deleteFaq(id) {
        if (!confirm('Are you sure you want to delete this FAQ?')) return;
        fetch('../api/admin/help/faqs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: id })
        }).then(() => loadFaqs());
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
</script>
</body>
</html>
