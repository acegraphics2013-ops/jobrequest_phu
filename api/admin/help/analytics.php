<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU Workbench - Help Assistant Admin Analytics API Endpoint
|--------------------------------------------------------------------------
*/

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/auth.php';

checkLogin();
requireRole(['admin', 'supervisor']);

// Total Questions
$totalQueriesRes = $conn->query("SELECT COUNT(*) AS total FROM help_query_logs");
$totalQueries = (int)($totalQueriesRes ? $totalQueriesRes->fetch_assoc()['total'] : 0);

// Matched vs Unmatched
$matchedQueriesRes = $conn->query("SELECT COUNT(*) AS total FROM help_query_logs WHERE confidence >= 0.45");
$matchedQueries = (int)($matchedQueriesRes ? $matchedQueriesRes->fetch_assoc()['total'] : 0);
$matchRate = $totalQueries > 0 ? round(($matchedQueries / $totalQueries) * 100, 1) : 100.0;

// Feedback Helpful Rate
$feedbackRes = $conn->query("SELECT COUNT(*) AS total, SUM(helpful) AS helpful_count FROM help_feedback");
$feedbackData = $feedbackRes ? $feedbackRes->fetch_assoc() : ['total' => 0, 'helpful_count' => 0];
$totalFeedback = (int)($feedbackData['total'] ?? 0);
$helpfulCount = (int)($feedbackData['helpful_count'] ?? 0);
$helpfulRate = $totalFeedback > 0 ? round(($helpfulCount / $totalFeedback) * 100, 1) : 100.0;

// Most Asked FAQs
$popularFaqsRes = $conn->query("
    SELECT f.question_en, COUNT(l.id) AS ask_count
    FROM help_query_logs l
    JOIN help_faqs f ON l.matched_faq_id = f.id
    GROUP BY l.matched_faq_id
    ORDER BY ask_count DESC
    LIMIT 5
");
$popularFaqs = [];
if ($popularFaqsRes) {
    while ($row = $popularFaqsRes->fetch_assoc()) {
        $popularFaqs[] = $row;
    }
}

// Unmatched queries count
$unmatchedCountRes = $conn->query("SELECT COUNT(*) AS total FROM help_unmatched_queries WHERE status = 'pending'");
$unmatchedCount = (int)($unmatchedCountRes ? $unmatchedCountRes->fetch_assoc()['total'] : 0);

echo json_encode([
    'success' => true,
    'analytics' => [
        'total_questions' => $totalQueries,
        'matched_questions' => $matchedQueries,
        'match_rate' => $matchRate,
        'total_feedback' => $totalFeedback,
        'helpful_rate' => $helpfulRate,
        'unmatched_pending' => $unmatchedCount,
        'popular_faqs' => $popularFaqs
    ]
], JSON_UNESCAPED_UNICODE);
