<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU Workbench - Help Assistant Query Engine API Endpoint
|--------------------------------------------------------------------------
*/

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/faq_engine.php';

$rawInput = file_get_contents('php://input');
$input = json_decode((string)$rawInput, true);
if (!is_array($input)) {
    $input = $_POST;
}

$query = trim((string)($input['query'] ?? ''));
$lang = strtolower(trim((string)($input['language'] ?? 'en')));
$module = trim((string)($input['page_context'] ?? 'global'));
$flowKey = trim((string)($input['flow_key'] ?? ''));

$engine = new HpuHelpEngine($conn);

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'guest');
$visibility = ($userId > 0) ? 'authenticated' : 'public';

// If requested a guided workflow (e.g., status guide or submit guide)
if ($flowKey !== '') {
    $guided = $engine->getGuidedWorkflow($flowKey, $lang);
    if (!empty($guided)) {
        echo json_encode([
            'success' => true,
            'is_guided' => true,
            'guided_data' => $guided
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($query === '') {
    echo json_encode([
        'success' => true,
        'matched' => false,
        'answer' => ($lang === 'bisaya') ? "Palihug pangutana bahin sa pag-submit, pag-track, o pag-manage sa HPU Job Requests." : (($lang === 'tagalog') ? "Mangyaring magtanong tungkol sa pag-submit, pag-track, o pag-manage ng HPU Job Requests." : "Please ask a question about submitting, tracking, or managing HPU Job Requests!"),
        'suggested' => $engine->getSuggestedChips($lang, $visibility, $role, $module)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = $engine->matchQuery($query, $lang, $visibility, $role, $module, $userId > 0 ? $userId : null);
$result['success'] = true;

echo json_encode($result, JSON_UNESCAPED_UNICODE);
