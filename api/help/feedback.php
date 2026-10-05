<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU Workbench - Help Assistant Feedback API Endpoint
|--------------------------------------------------------------------------
*/

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/faq_engine.php';

$rawInput = file_get_contents('php://input');
$input = json_decode((string)$rawInput, true);
if (!is_array($input)) {
    $input = $_POST;
}

$faqId = isset($input['faq_id']) ? (int)$input['faq_id'] : null;
$helpful = (int)($input['helpful'] ?? 1);
$queryText = trim((string)($input['query_text'] ?? ''));
$sessionId = session_id() ?: null;
$userId = (int)($_SESSION['user_id'] ?? 0);

$engine = new HpuHelpEngine($conn);
$saved = $engine->saveFeedback($faqId, $helpful, $queryText, $userId > 0 ? $userId : null, $sessionId);

echo json_encode([
    'success' => $saved,
    'message' => $saved ? 'Feedback recorded.' : 'Unable to record feedback.'
], JSON_UNESCAPED_UNICODE);
