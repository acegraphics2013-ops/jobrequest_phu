<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU Workbench - Admin Help FAQs Management API Endpoint
|--------------------------------------------------------------------------
*/

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/auth.php';

checkLogin();
requireRole(['admin', 'supervisor']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rawInput = file_get_contents('php://input');
$input = json_decode((string)$rawInput, true) ?: $_POST;

if ($method === 'GET') {
    $res = $conn->query("
        SELECT f.*, c.title_en as category_title
        FROM help_faqs f
        LEFT JOIN help_categories c ON f.category_key = c.key_name
        ORDER BY f.sort_order ASC, f.id DESC
    ");
    $faqs = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $faqs[] = $row;
        }
    }
    echo json_encode(['success' => true, 'faqs' => $faqs], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $action = trim((string)($input['action'] ?? 'save'));

    if ($action === 'toggle') {
        $id = (int)($input['id'] ?? 0);
        $active = (int)($input['is_active'] ?? 0);
        $stmt = $conn->prepare("UPDATE help_faqs SET is_active = ? WHERE id = ?");
        $stmt->bind_param('ii', $active, $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Status updated.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM help_faqs WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'FAQ deleted.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Save (Create or Update)
    $id = (int)($input['id'] ?? 0);
    $categoryKey = trim((string)($input['category_key'] ?? 'getting_started'));
    $questionEn = trim((string)($input['question_en'] ?? ''));
    $questionBisaya = trim((string)($input['question_bisaya'] ?? ''));
    $questionTagalog = trim((string)($input['question_tagalog'] ?? ''));
    $answerEn = trim((string)($input['answer_en'] ?? ''));
    $answerBisaya = trim((string)($input['answer_bisaya'] ?? ''));
    $answerTagalog = trim((string)($input['answer_tagalog'] ?? ''));
    $keywords = is_array($input['keywords'] ?? null) ? json_encode($input['keywords']) : trim((string)($input['keywords'] ?? ''));
    $aliases = is_array($input['aliases'] ?? null) ? json_encode($input['aliases']) : trim((string)($input['aliases'] ?? ''));
    $visibility = in_array($input['visibility'] ?? '', ['public', 'authenticated', 'admin_only'], true) ? $input['visibility'] : 'public';
    $moduleKey = trim((string)($input['module_key'] ?? 'global'));
    $roleScope = trim((string)($input['role_scope'] ?? 'all'));
    $sortOrder = (int)($input['sort_order'] ?? 0);
    $isActive = (int)($input['is_active'] ?? 1);
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($questionEn === '' || $answerEn === '') {
        echo json_encode(['success' => false, 'message' => 'English question and answer are required.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($id > 0) {
        $stmt = $conn->prepare("
            UPDATE help_faqs SET
                category_key = ?, question_en = ?, question_bisaya = ?, question_tagalog = ?,
                answer_en = ?, answer_bisaya = ?, answer_tagalog = ?, keywords = ?, aliases = ?,
                visibility = ?, module_key = ?, role_scope = ?, sort_order = ?, is_active = ?, updated_by = ?
            WHERE id = ?
        ");
        $stmt->bind_param('ssssssssssssiiii',
            $categoryKey, $questionEn, $questionBisaya, $questionTagalog,
            $answerEn, $answerBisaya, $answerTagalog, $keywords, $aliases,
            $visibility, $moduleKey, $roleScope, $sortOrder, $isActive, $userId, $id
        );
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'FAQ updated successfully.'], JSON_UNESCAPED_UNICODE);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO help_faqs
            (category_key, question_en, question_bisaya, question_tagalog, answer_en, answer_bisaya, answer_tagalog, keywords, aliases, visibility, module_key, role_scope, sort_order, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('ssssssssssssiii',
            $categoryKey, $questionEn, $questionBisaya, $questionTagalog,
            $answerEn, $answerBisaya, $answerTagalog, $keywords, $aliases,
            $visibility, $moduleKey, $roleScope, $sortOrder, $isActive, $userId
        );
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'FAQ created successfully.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
