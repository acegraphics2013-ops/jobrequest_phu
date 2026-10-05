<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU Workbench - Public FAQ Knowledge Base API Endpoint
|--------------------------------------------------------------------------
*/
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: public, max-age=300");
header("X-Content-Type-Options: nosniff");

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/faq_engine.php';

$engine = new HpuHelpEngine($conn);

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'guest');
$visibility = ($userId > 0) ? 'authenticated' : 'public';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode((string)$rawInput, true) ?: $_POST;

    $userQuery = trim((string)($input['query'] ?? ''));
    $lang = strtolower(trim((string)($input['language'] ?? 'en')));

    if ($userQuery === '') {
        echo json_encode([
            'success' => true,
            'match' => false,
            'answer' => "Please ask a question about submitting, tracking, or managing HPU Job Requests!",
            'suggested' => $engine->getSuggestedChips($lang, $visibility, $role, 'global')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $res = $engine->matchQuery($userQuery, $lang, $visibility, $role, 'global', $userId > 0 ? $userId : null);
    $res['success'] = true;
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

// GET Request: Return List of FAQs & Suggested Chips
$faqs = $engine->getFaqs($visibility, $role, 'global');
$suggested = $engine->getSuggestedChips('en', $visibility, $role, 'global');

echo json_encode([
    'success' => true,
    'faqs' => array_map(function($f) {
        return [
            'id' => (int)$f['id'],
            'category' => $f['category_key'],
            'question' => $f['question_en'],
            'answer' => $f['answer_en']
        ];
    }, $faqs),
    'suggested' => $suggested
], JSON_UNESCAPED_UNICODE);
