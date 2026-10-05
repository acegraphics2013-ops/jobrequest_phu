<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU Workbench - Help Assistant Bootstrap API Endpoint
|--------------------------------------------------------------------------
*/

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: public, max-age=60");

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/faq_engine.php';

$engine = new HpuHelpEngine($conn);

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'guest');
$visibility = ($userId > 0) ? 'authenticated' : 'public';
$lang = strtolower(trim((string)($_GET['lang'] ?? 'en')));
$module = trim((string)($_GET['module'] ?? 'global'));

$categories = $engine->getCategories($lang);
$suggested = $engine->getSuggestedChips($lang, $visibility, $role, $module);
$faqs = $engine->getFaqs($visibility, $role, $module);

echo json_encode([
    'success' => true,
    'user' => [
        'is_logged_in' => ($userId > 0),
        'name' => $_SESSION['name'] ?? null,
        'role' => $role
    ],
    'categories' => $categories,
    'suggested' => $suggested,
    'faqs' => array_map(function($f) use ($lang) {
        $qKey = 'question_' . (in_array($lang, ['en', 'bisaya', 'tagalog'], true) ? $lang : 'en');
        $aKey = 'answer_' . (in_array($lang, ['en', 'bisaya', 'tagalog'], true) ? $lang : 'en');
        return [
            'id' => (int)$f['id'],
            'category' => $f['category_key'],
            'question' => !empty($f[$qKey]) ? $f[$qKey] : $f['question_en'],
            'answer' => !empty($f[$aKey]) ? $f[$aKey] : $f['answer_en']
        ];
    }, $faqs)
], JSON_UNESCAPED_UNICODE);
