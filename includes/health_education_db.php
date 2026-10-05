<?php
declare(strict_types=1);

/**
 * Health Education Database Migration & Helpers
 * Safely ensures required columns and log tables exist without modifying/dropping existing data.
 */

if (!function_exists('ensureHealthEducationSchema')) {
    function ensureHealthEducationSchema(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        // 1. Ensure primary sessions table exists
        $conn->query("CREATE TABLE IF NOT EXISTS ward_health_education_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            submitted_by INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            session_date DATE NOT NULL,
            activity_time VARCHAR(120) NULL,
            ward_area VARCHAR(190) NOT NULL,
            lead_office VARCHAR(190) NOT NULL,
            health_topic VARCHAR(255) NOT NULL,
            facilitator VARCHAR(255) NOT NULL,
            target_participants VARCHAR(190) NULL,
            participant_count INT NOT NULL DEFAULT 0,
            teaching_method VARCHAR(190) NULL,
            objectives LONGTEXT NOT NULL,
            key_outputs LONGTEXT NULL,
            impact_significance LONGTEXT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'For Review',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_whe_date(session_date),
            INDEX idx_whe_status(status),
            INDEX idx_whe_ward(ward_area)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 2. Safely add missing columns to ward_health_education_sessions
        $columnsToEnsure = [
            'activity_type' => "VARCHAR(190) NULL AFTER title",
            'patients_count' => "INT NOT NULL DEFAULT 0 AFTER participant_count",
            'watchers_count' => "INT NOT NULL DEFAULT 0 AFTER patients_count",
            'employees_count' => "INT NOT NULL DEFAULT 0 AFTER watchers_count",
            'others_count' => "INT NOT NULL DEFAULT 0 AFTER employees_count",
            'partner_offices' => "VARCHAR(255) NULL AFTER lead_office",
            'iec_materials_used' => "VARCHAR(255) NULL AFTER teaching_method",
            'iec_quantity' => "INT NOT NULL DEFAULT 0 AFTER iec_materials_used",
            'remarks' => "TEXT NULL AFTER impact_significance",
            'documentation_percentage' => "INT NOT NULL DEFAULT 0 AFTER status",
            'cover_output_id' => "INT NULL AFTER documentation_percentage",
            'publication_id' => "INT NULL AFTER cover_output_id",
            'reviewed_by' => "INT NULL AFTER publication_id",
            'reviewed_at' => "DATETIME NULL AFTER reviewed_by",
            'approved_by' => "INT NULL AFTER reviewed_at",
            'approved_at' => "DATETIME NULL AFTER approved_by",
        ];

        foreach ($columnsToEnsure as $colName => $colDef) {
            $check = $conn->query("SHOW COLUMNS FROM ward_health_education_sessions LIKE '$colName'");
            if ($check && $check->num_rows === 0) {
                $conn->query("ALTER TABLE ward_health_education_sessions ADD COLUMN `$colName` $colDef");
            }
        }

        // 3. Ensure outputs table exists
        $conn->query("CREATE TABLE IF NOT EXISTS ward_health_education_outputs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            uploaded_by INT NOT NULL,
            output_type VARCHAR(120) NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            extension VARCHAR(20) NULL,
            mime_type VARCHAR(120) NULL,
            file_size INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_whe_output_session(session_id),
            CONSTRAINT fk_whe_output_session FOREIGN KEY(session_id)
                REFERENCES ward_health_education_sessions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 4. Ensure activity timeline logs table exists
        $conn->query("CREATE TABLE IF NOT EXISTS ward_health_education_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            user_id INT NOT NULL,
            action VARCHAR(80) NOT NULL,
            description TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_whel_session(session_id),
            INDEX idx_whel_user(user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $ensured = true;
    }
}

if (!function_exists('logHealthEducationAction')) {
    function logHealthEducationAction(mysqli $conn, int $sessionId, int $userId, string $action, string $description): void
    {
        ensureHealthEducationSchema($conn);
        $stmt = $conn->prepare("INSERT INTO ward_health_education_logs (session_id, user_id, action, description) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("iiss", $sessionId, $userId, $action, $description);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('calculateDocumentationCompleteness')) {
    function calculateDocumentationCompleteness(mysqli $conn, int $sessionId): int
    {
        ensureHealthEducationSchema($conn);
        $stmt = $conn->prepare("SELECT * FROM ward_health_education_sessions WHERE id = ? LIMIT 1");
        if (!$stmt) return 0;
        $stmt->bind_param("i", $sessionId);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$session) return 0;

        $score = 0;
        $total = 7;

        // 1. Activity Information
        if (!empty($session['title']) && !empty($session['session_date']) && !empty($session['ward_area']) && !empty($session['lead_office']) && !empty($session['health_topic']) && !empty($session['facilitator'])) {
            $score++;
        }
        // 2. Objectives
        if (!empty(trim((string)$session['objectives']))) {
            $score++;
        }
        // 3. Participants
        if ((int)($session['participant_count'] ?? 0) > 0) {
            $score++;
        }
        // 4. Key Outputs
        if (!empty(trim((string)$session['key_outputs']))) {
            $score++;
        }
        // 5. Impact / Significance
        if (!empty(trim((string)$session['impact_significance']))) {
            $score++;
        }

        // Output queries
        $oStmt = $conn->prepare("SELECT output_type FROM ward_health_education_outputs WHERE session_id = ?");
        $oStmt->bind_param("i", $sessionId);
        $oStmt->execute();
        $oRes = $oStmt->get_result();
        $types = [];
        while ($r = $oRes->fetch_assoc()) {
            $types[] = strtolower(trim((string)$r['output_type']));
        }
        $oStmt->close();

        // 6. Photo Documentation
        $photoTypes = ['before activity', 'during activity', 'after activity', 'group photo', 'photo documentation'];
        $hasPhoto = false;
        foreach ($types as $t) {
            if (in_array($t, $photoTypes, true)) {
                $hasPhoto = true;
                break;
            }
        }
        if ($hasPhoto) $score++;

        // 7. Attendance Sheet or IEC Material
        $hasDoc = false;
        foreach ($types as $t) {
            if (in_array($t, ['attendance sheet', 'iec material', 'presentation', 'other'], true)) {
                $hasDoc = true;
                break;
            }
        }
        if ($hasDoc || !empty($session['iec_materials_used'])) $score++;

        $pct = (int)round(($score / $total) * 100);

        // Update stored completeness percentage
        $up = $conn->prepare("UPDATE ward_health_education_sessions SET documentation_percentage = ? WHERE id = ?");
        if ($up) {
            $up->bind_param("ii", $pct, $sessionId);
            $up->execute();
            $up->close();
        }

        return $pct;
    }
}
