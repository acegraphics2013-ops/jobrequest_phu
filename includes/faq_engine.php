<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU Workbench - Help Assistant Engine
|--------------------------------------------------------------------------
| Handles natural language query matching, multilingual translations,
| context awareness (module & role), confidence scoring, prompt injection
| protection, and feedback analytics.
|--------------------------------------------------------------------------
*/

if (!function_exists('hpuHelpEngine')) {

    class HpuHelpEngine {
        private mysqli $conn;

        // Synonym Groups for EN, BIS, TL
        private array $synonyms = [
            'submit' => [
                'submit', 'create', 'add', 'send', 'make', 'file', 'new', 'post', 'request',
                'pasa', 'magpasa', 'buhat', 'magbuhat', 'unsaon pag-file', 'pagpasa', 'sumite', 'magsusumite'
            ],
            'track' => [
                'track', 'monitor', 'check', 'status', 'where', 'progress', 'view', 'find', 'see', 'follow',
                'asa', 'saan', 'tan-aw', 'tanawon', 'makita', 'subay', 'pagsubay', 'nasaan'
            ],
            'files' => [
                'attach', 'files', 'upload', 'photo', 'document', 'logo', 'reference', 'attachment', 'image', 'picture', 'pdf',
                'hulagway', 'larawan', 'litrato', 'dokumento', 'size', 'limit'
            ],
            'revision' => [
                'revision', 'revise', 'correction', 'change', 'edit', 'update', 'modify', 'redo', 'resubmit',
                'usab', 'bag-o', 'pagbag-o', 'baguhin', 'i-update'
            ],
            'approval' => [
                'approval', 'approve', 'approved', 'review', 'supervisor', 'admin', 'who', 'sign',
                'kinsa', 'sino', 'mag-approve', 'mag-aapruba'
            ],
            'account' => [
                'account', 'login', 'signin', 'password', 'forgot', 'reset', 'access', 'register', 'signup', 'user',
                'nakalimtan', 'nakalimutan', 'pagsulod', 'maka-login'
            ],
            'pending' => [
                'pending', 'waiting', 'evaluating', 'review', 'hulat', 'naghulat', 'naghihintay'
            ],
            'development' => [
                'development', 'progress', 'working', 'doing', 'in progress', 'assigned', 'gihimo', 'ginagawa'
            ],
            'kanban' => [
                'kanban', 'board', 'cards', 'drag', 'drop', 'kolum', 'column'
            ]
        ];

        public function __construct(mysqli $conn) {
            $this->conn = $conn;
        }

        /**
         * Fetch all active FAQs accessible to the given role and visibility mode.
         */
        public function getFaqs(string $visibility = 'public', string $role = 'guest', string $module = 'global'): array {
            $query = "SELECT * FROM help_faqs WHERE is_active = 1";
            $params = [];
            $types = "";

            if ($visibility === 'public') {
                $query .= " AND visibility = 'public'";
            } else {
                if ($role === 'admin') {
                    $query .= " AND visibility IN ('public', 'authenticated', 'admin_only')";
                } else {
                    $query .= " AND visibility IN ('public', 'authenticated')";
                }
            }

            if ($role !== 'guest' && $role !== 'admin') {
                $query .= " AND (role_scope = 'all' OR role_scope = ?)";
                $params[] = $role;
                $types .= "s";
            }

            $query .= " ORDER BY sort_order ASC, id ASC";

            if (empty($params)) {
                $res = $this->conn->query($query);
            } else {
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
            }

            $faqs = [];
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $faqs[] = $row;
                }
            }
            return $faqs;
        }

        /**
         * Fetch Categories with localized titles
         */
        public function getCategories(string $lang = 'en'): array {
            $res = $this->conn->query("SELECT * FROM help_categories WHERE is_active = 1 ORDER BY sort_order ASC");
            $categories = [];
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $titleKey = 'title_' . (in_array($lang, ['en', 'bisaya', 'tagalog'], true) ? $lang : 'en');
                    $categories[] = [
                        'key' => $row['key_name'],
                        'title' => $row[$titleKey] ?? $row['title_en'],
                        'icon' => $row['icon']
                    ];
                }
            }
            return $categories;
        }

        /**
         * Smart Match algorithm with prompt injection check & confidence scoring
         */
        public function matchQuery(string $userQuery, string $lang = 'en', string $visibility = 'public', string $role = 'guest', string $module = 'global', ?int $userId = null): array {
            // Security: Prompt Injection Guard
            if ($this->detectPromptInjection($userQuery)) {
                return [
                    'matched' => false,
                    'confidence' => 0.0,
                    'answer' => $this->getLocalizedText('security_warning', $lang),
                    'suggested' => $this->getSuggestedChips($lang, $visibility, $role, $module)
                ];
            }

            $faqs = $this->getFaqs($visibility, $role, $module);
            $cleanQuery = $this->normalizeText($userQuery);
            $queryTokens = array_filter(explode(' ', $cleanQuery), fn($t) => strlen($t) > 1);
            $expandedTokens = $this->expandSynonyms($queryTokens);

            $bestMatch = null;
            $bestScore = 0.0;
            $scoredFaqs = [];

            foreach ($faqs as $faq) {
                $score = 0.0;
                $qEn = $this->normalizeText($faq['question_en'] ?? '');
                $qBis = $this->normalizeText($faq['question_bisaya'] ?? '');
                $qTag = $this->normalizeText($faq['question_tagalog'] ?? '');
                $keywords = json_decode($faq['keywords'] ?? '[]', true) ?: [];
                $aliases = json_decode($faq['aliases'] ?? '[]', true) ?: [];

                // 1. Exact or Substring Question Match (Score: 100)
                if (
                    str_contains($qEn, $cleanQuery) || str_contains($cleanQuery, $qEn) ||
                    ($qBis !== '' && (str_contains($qBis, $cleanQuery) || str_contains($cleanQuery, $qBis))) ||
                    ($qTag !== '' && (str_contains($qTag, $cleanQuery) || str_contains($cleanQuery, $qTag)))
                ) {
                    $score += 100.0;
                }

                // 2. Keyword & Alias Matches (Score: 35 per match)
                foreach ($keywords as $kw) {
                    $normKw = $this->normalizeText($kw);
                    if (str_contains($cleanQuery, $normKw)) {
                        $score += 35.0;
                    }
                }

                foreach ($aliases as $alias) {
                    $normAlias = $this->normalizeText($alias);
                    if (str_contains($cleanQuery, $normAlias)) {
                        $score += 40.0;
                    }
                }

                // 3. Token Overlap Score (Score: 10 per matching token)
                $combinedQuestion = $qEn . " " . $qBis . " " . $qTag;
                foreach ($expandedTokens as $token) {
                    if (str_contains($combinedQuestion, $token)) {
                        $score += 12.0;
                    }
                }

                // Module Context Boost (Score: 20 if matched current page)
                if ($faq['module_key'] === $module && $module !== 'global') {
                    $score += 20.0;
                }

                if ($score > 0) {
                    $scoredFaqs[] = ['faq' => $faq, 'score' => $score];
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $faq;
                }
            }

            // Convert score to confidence (0.0 to 1.0)
            $confidence = min(1.0, round($bestScore / 100.0, 2));

            // Log query for analytics
            $matchedFaqId = isset($bestMatch['id']) ? (int)$bestMatch['id'] : null;
            $this->logQuery($userQuery, $matchedFaqId, $confidence, $lang, $module, $userId);

            if ($confidence >= 0.70 && $bestMatch !== null) {
                // High Confidence Direct Match
                return [
                    'matched' => true,
                    'confidence' => $confidence,
                    'faq_id' => (int)$bestMatch['id'],
                    'category' => $bestMatch['category_key'],
                    'question' => $this->getLocalizedField($bestMatch, 'question', $lang),
                    'answer' => $this->getLocalizedField($bestMatch, 'answer', $lang),
                    'related' => $this->getRelatedQuestions($faqs, (int)$bestMatch['id'], $lang),
                    'action_link' => $this->getActionLinkForFaq($bestMatch)
                ];
            } elseif ($confidence >= 0.45 && count($scoredFaqs) > 0) {
                // Medium Confidence Match - "Did you mean?"
                usort($scoredFaqs, fn($a, $b) => $b['score'] <=> $a['score']);
                $suggestions = array_slice(array_map(fn($item) => [
                    'id' => (int)$item['faq']['id'],
                    'question' => $this->getLocalizedField($item['faq'], 'question', $lang)
                ], $scoredFaqs), 0, 3);

                return [
                    'matched' => false,
                    'confidence' => $confidence,
                    'is_did_you_mean' => true,
                    'answer' => $this->getLocalizedText('did_you_mean', $lang),
                    'suggested' => $suggestions
                ];
            } else {
                // Low Match - Log to Unmatched Queries
                $this->logUnmatchedQuery($userQuery, $lang, $module, $userId);

                return [
                    'matched' => false,
                    'confidence' => $confidence,
                    'answer' => $this->getLocalizedText('no_match_fallback', $lang),
                    'suggested' => $this->getSuggestedChips($lang, $visibility, $role, $module)
                ];
            }
        }

        /**
         * Suggested Quick Action Chips dynamically tailored by role and module
         */
        public function getSuggestedChips(string $lang = 'en', string $visibility = 'public', string $role = 'guest', string $module = 'global'): array {
            $faqs = $this->getFaqs($visibility, $role, $module);
            $chips = [];

            // Context-driven priorities
            if ($module === 'kanban') {
                $chips[] = ['question' => $this->getLocalizedText('chip_kanban', $lang)];
            } elseif ($module === 'my_requests') {
                $chips[] = ['question' => $this->getLocalizedText('chip_track', $lang)];
                $chips[] = ['question' => $this->getLocalizedText('chip_revision', $lang)];
            }

            foreach ($faqs as $f) {
                $chips[] = [
                    'id' => (int)$f['id'],
                    'question' => $this->getLocalizedField($f, 'question', $lang)
                ];
                if (count($chips) >= 6) break;
            }

            return array_slice($chips, 0, 6);
        }

        /**
         * Guided Help Workflows & Visual Status Guide definition
         */
        public function getGuidedWorkflow(string $flowKey, string $lang = 'en'): array {
            if ($flowKey === 'status_guide') {
                return [
                    'title' => $this->getLocalizedText('status_guide_title', $lang),
                    'steps' => [
                        [
                            'status' => 'Pending Review',
                            'badge_class' => 'bg-warning text-dark',
                            'desc_en' => 'Your request was received and is awaiting evaluation by HPU Supervisor/Admin.',
                            'desc_bisaya' => 'Nadawat na ang imong request ug naghulat sa pagsusi sa Supervisor.',
                            'desc_tagalog' => 'Natanggap na ang request at naghihintay ng pagsusuri ng Supervisor.'
                        ],
                        [
                            'status' => 'Under Review',
                            'badge_class' => 'bg-info text-dark',
                            'desc_en' => 'Supervisor is checking project feasibility, manpower, and schedule availability.',
                            'desc_bisaya' => 'Gitan-aw karon sa Supervisor ang schedule ug manpower.',
                            'desc_tagalog' => 'Sinusuri ng Supervisor ang iskedyul at manpower.'
                        ],
                        [
                            'status' => 'Approved',
                            'badge_class' => 'bg-primary text-white',
                            'desc_en' => 'Request approved! Assigned to layout artist / videographer.',
                            'desc_bisaya' => 'Naka-approve na! Gi-assign na sa layout artist o videographer.',
                            'desc_tagalog' => 'Approved na! Naka-assign na sa staff.'
                        ],
                        [
                            'status' => 'In Development',
                            'badge_class' => 'bg-secondary text-white',
                            'desc_en' => 'Graphics, video editing, or coverage is actively in production.',
                            'desc_bisaya' => 'Gihimo ug gidesinyohan na ang imong project.',
                            'desc_tagalog' => 'Kasalukuyan nang ginagawa ang project.'
                        ],
                        [
                            'status' => 'Completed',
                            'badge_class' => 'bg-success text-white',
                            'desc_en' => 'Project finished! Deliverables ready for download / pickup.',
                            'desc_bisaya' => 'Humana ang project! Pwede na ma-download o makuha.',
                            'desc_tagalog' => 'Tapos na ang project! Maaari nang i-download.'
                        ]
                    ]
                ];
            }

            if ($flowKey === 'submit_guide') {
                return [
                    'title' => $this->getLocalizedText('submit_guide_title', $lang),
                    'steps' => [
                        ['step' => 1, 'text_en' => 'Sign in to your HPU Job Request account.', 'text_bisaya' => 'Mag-login sa imong account.', 'text_tagalog' => 'Mag-login sa iyong account.'],
                        ['step' => 2, 'text_en' => 'Click "Job Requests" or "My Requests" then select "+ New Request".', 'text_bisaya' => 'I-click ang "+ New Request".', 'text_tagalog' => 'I-click ang "+ New Request".'],
                        ['step' => 3, 'text_en' => 'Select request type (Graphic Design, Video, Coverage, IEC).', 'text_bisaya' => 'Pilia ang klase sa request.', 'text_tagalog' => 'Piliin ang uri ng request.'],
                        ['step' => 4, 'text_en' => 'Fill in Project Title, Objective, Event Dates, and Venue.', 'text_bisaya' => 'Sulati ang titulo, petsa, ug venue.', 'text_tagalog' => 'Punan ang title, petsa, at lugar.'],
                        ['step' => 5, 'text_en' => 'Attach logos or reference files (up to 10 MB per file).', 'text_bisaya' => 'Mag-attach og reference pictures o files.', 'text_tagalog' => 'Mag-attach ng mga reference file.'],
                        ['step' => 6, 'text_en' => 'Click "Submit Job Request" and track status under "My Requests".', 'text_bisaya' => 'I-submit ug subaya ang status.', 'text_tagalog' => 'I-submit at subaybayan ang status.']
                    ]
                ];
            }

            return [];
        }

        /**
         * Save Feedback (Helpful Yes/No)
         */
        public function saveFeedback(?int $faqId, int $helpful, ?string $queryText, ?int $userId, ?string $sessionId): bool {
            $stmt = $this->conn->prepare("INSERT INTO help_feedback (faq_id, user_id, session_id, helpful, query_text) VALUES (?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('iisis', $faqId, $userId, $sessionId, $helpful, $queryText);
                $res = $stmt->execute();
                $stmt->close();
                return $res;
            }
            return false;
        }

        // --- Helper & Utility Methods ---

        private function normalizeText(string $text): string {
            $text = mb_strtolower($text);
            $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
            return trim(preg_replace('/\s+/', ' ', $text));
        }

        private function expandSynonyms(array $tokens): array {
            $expanded = $tokens;
            foreach ($tokens as $token) {
                foreach ($this->synonyms as $group => $words) {
                    if (in_array($token, $words, true)) {
                        $expanded[] = $group;
                        $expanded = array_merge($expanded, $words);
                    }
                }
            }
            return array_unique($expanded);
        }

        private function detectPromptInjection(string $input): bool {
            $maliciousPatterns = [
                '/ignore (all )?previous instructions/i',
                '/show (all )?users/i',
                '/select \* from/i',
                '/drop table/i',
                '/give me (the )?password/i',
                '/bypass (security|auth)/i',
                '/reveal (database|credentials)/i'
            ];
            foreach ($maliciousPatterns as $pattern) {
                if (preg_match($pattern, $input)) {
                    return true;
                }
            }
            return false;
        }

        private function getLocalizedField(array $faq, string $fieldPrefix, string $lang): string {
            $langKey = $fieldPrefix . '_' . (in_array($lang, ['en', 'bisaya', 'tagalog'], true) ? $lang : 'en');
            $val = trim((string)($faq[$langKey] ?? ''));
            if ($val !== '') return $val;
            return (string)($faq[$fieldPrefix . '_en'] ?? '');
        }

        private function getLocalizedText(string $key, string $lang): string {
            $translations = [
                'security_warning' => [
                    'en' => "Security Notice: I can only answer authorized questions regarding HPU Job Requests.",
                    'bisaya' => "Security Notice: Makatubag ra ko sa mga pangutana mahitungod sa HPU Job Requests.",
                    'tagalog' => "Security Notice: Makasasagot lamang ako sa mga katanungan tungkol sa HPU Job Requests."
                ],
                'did_you_mean' => [
                    'en' => "Did you mean one of these topics?",
                    'bisaya' => "Kini ba nga mga topiko ang imong gipasabot?",
                    'tagalog' => "Ito ba ang iyong tinutukoy?"
                ],
                'no_match_fallback' => [
                    'en' => "I'm not sure about that specific question yet. Please try selecting one of the suggested topics below or contact HPU Support.",
                    'bisaya' => "Wala pa ko makasiguro ana nga pangutana. Palihug pagpili sa mga girekomendar nga topiko sa ubos.",
                    'tagalog' => "Hindi pa ako sigurado sa katanungang iyan. Mangyaring pumili sa mga iminumungkahing paksa sa ibaba."
                ],
                'chip_kanban' => [
                    'en' => 'What is the Kanban Board?',
                    'bisaya' => 'Unsa ang Kanban Board?',
                    'tagalog' => 'Ano ang Kanban Board?'
                ],
                'chip_track' => [
                    'en' => 'How can I track my request status?',
                    'bisaya' => 'Asa nako makita akong request status?',
                    'tagalog' => 'Saan makikita ang request status?'
                ],
                'chip_revision' => [
                    'en' => 'What does For Revision mean?',
                    'bisaya' => 'Unsa ang For Revision?',
                    'tagalog' => 'Ano ang For Revision?'
                ],
                'status_guide_title' => [
                    'en' => 'HPU Job Request Status Guide',
                    'bisaya' => 'Giyahan sa HPU Request Status',
                    'tagalog' => 'Gabay sa HPU Request Status'
                ],
                'submit_guide_title' => [
                    'en' => 'Step-by-Step Request Guide',
                    'bisaya' => 'Lakang sa Pag-submit og Request',
                    'tagalog' => 'Hakbang sa Pag-submit ng Request'
                ]
            ];

            return $translations[$key][$lang] ?? ($translations[$key]['en'] ?? '');
        }

        private function getRelatedQuestions(array $faqs, int $currentId, string $lang): array {
            $related = [];
            foreach ($faqs as $f) {
                if ((int)$f['id'] !== $currentId) {
                    $related[] = [
                        'id' => (int)$f['id'],
                        'question' => $this->getLocalizedField($f, 'question', $lang)
                    ];
                }
                if (count($related) >= 3) break;
            }
            return $related;
        }

        private function getActionLinkForFaq(array $faq): ?array {
            $module = $faq['module_key'] ?? 'global';
            if ($module === 'my_requests') {
                return ['label' => 'Go to My Requests', 'url' => 'user/my_requests.php'];
            } elseif ($module === 'new_request') {
                return ['label' => 'Submit New Request', 'url' => 'user/new_request.php'];
            } elseif ($module === 'kanban') {
                return ['label' => 'Open Kanban Board', 'url' => 'staff/kanban.php'];
            }
            return null;
        }

        private function logQuery(string $query, ?int $matchedFaqId, float $confidence, string $lang, string $module, ?int $userId): void {
            try {
                $stmt = $this->conn->prepare("INSERT INTO help_query_logs (user_id, query_text, matched_faq_id, confidence, language, page_context) VALUES (?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param('isidss', $userId, $query, $matchedFaqId, $confidence, $lang, $module);
                    $stmt->execute();
                    $stmt->close();
                }
            } catch (Throwable $e) {}
        }

        private function logUnmatchedQuery(string $query, string $lang, string $module, ?int $userId): void {
            try {
                $clean = $this->normalizeText($query);
                if (strlen($clean) < 3) return;

                $stmt = $this->conn->prepare("INSERT INTO help_unmatched_queries (query_text, normalized_query, language, page_context, user_id, count) VALUES (?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE count = count + 1, last_asked_at = NOW()");
                if ($stmt) {
                    $stmt->bind_param('ssssi', $query, $clean, $lang, $module, $userId);
                    $stmt->execute();
                    $stmt->close();
                }
            } catch (Throwable $e) {}
        }
    }
}
