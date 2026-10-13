<?php
/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - PER-USER MODULE ACCESS
|--------------------------------------------------------------------------
| Model: role defaults (module_registry.php) + per-user override rows.
|   - No row        => inherit role default (visible by role, backward compat)
|   - is_allowed=1  => grant (show even if role denies)
|   - is_allowed=0  => revoke (hide even if role allows)
|
| Editors:
|   - admin can edit anyone
|   - supervisor can edit user/staff only, cannot grant modules
|     they themselves cannot access, cannot touch admin-only modules
|     (users, departments, backup, upload_settings)
*/

require_once __DIR__ . '/module_registry.php';

if (!function_exists('moduleAccessEnsureTable')) {
    function moduleAccessEnsureTable(mysqli $conn): bool
    {
        try {
            $check = $conn->query("SHOW TABLES LIKE 'user_module_permissions'");
            if ($check && $check->num_rows > 0) {
                return true;
            }
        } catch (Throwable $e) {
            // fall through to create attempt
        }

        try {
            $sqlFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database'
                . DIRECTORY_SEPARATOR . 'user_module_permissions.sql';
            if (is_file($sqlFile)) {
                $sql = file_get_contents($sqlFile);
                if ($sql !== false && trim($sql) !== '') {
                    // Strip FK when users table engine differs; try full SQL first.
                    try {
                        $conn->query($sql);
                    } catch (Throwable $e2) {
                        $fallback = "CREATE TABLE IF NOT EXISTS user_module_permissions (
                            user_id INT NOT NULL,
                            module_key VARCHAR(60) NOT NULL,
                            is_allowed TINYINT(1) NOT NULL,
                            updated_by INT NULL,
                            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            PRIMARY KEY (user_id, module_key),
                            KEY idx_ump_user (user_id),
                            KEY idx_ump_module (module_key)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                        $conn->query($fallback);
                    }
                }
            } else {
                $conn->query(
                    "CREATE TABLE IF NOT EXISTS user_module_permissions (
                        user_id INT NOT NULL,
                        module_key VARCHAR(60) NOT NULL,
                        is_allowed TINYINT(1) NOT NULL,
                        updated_by INT NULL,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (user_id, module_key)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
            }
        } catch (Throwable $e) {
            return false;
        }

        try {
            $check = $conn->query("SHOW TABLES LIKE 'user_module_permissions'");
            return (bool) ($check && $check->num_rows > 0);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('getUserModuleOverrides')) {
    function getUserModuleOverrides(mysqli $conn, int $userId, bool $forceRefresh = false): array
    {
        if (!isset($GLOBALS['__moduleAccessOverrideCache']) || !is_array($GLOBALS['__moduleAccessOverrideCache'])) {
            $GLOBALS['__moduleAccessOverrideCache'] = [];
        }
        $cache = &$GLOBALS['__moduleAccessOverrideCache'];
        if (!$forceRefresh && isset($cache[$userId])) {
            return $cache[$userId];
        }

        $overrides = [];
        if ($userId <= 0 || !moduleAccessEnsureTable($conn)) {
            $cache[$userId] = $overrides;
            return $overrides;
        }

        try {
            $stmt = $conn->prepare(
                "SELECT module_key, is_allowed FROM user_module_permissions WHERE user_id = ?"
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $key = (string) ($row['module_key'] ?? '');
                if ($key !== '' && array_key_exists($key, moduleRegistry())) {
                    $overrides[$key] = ((int) $row['is_allowed']) === 1 ? 1 : 0;
                }
            }
            $stmt->close();
        } catch (Throwable $e) {
            $overrides = [];
        }

        $cache[$userId] = $overrides;
        return $overrides;
    }
}

if (!function_exists('moduleAccessClearCache')) {
    function moduleAccessClearCache(?int $userId = null): void
    {
        if (!isset($GLOBALS['__moduleAccessOverrideCache'])) {
            return;
        }
        if ($userId === null) {
            $GLOBALS['__moduleAccessOverrideCache'] = [];
        } else {
            unset($GLOBALS['__moduleAccessOverrideCache'][$userId]);
        }
    }
}

if (!function_exists('canAccessModule')) {
    function canAccessModule(mysqli $conn, int $userId, string $role, string $moduleKey): bool
    {
        $registry = moduleRegistry();
        if (!isset($registry[$moduleKey])) {
            return false;
        }
        if ($userId <= 0) {
            return false;
        }

        $overrides = getUserModuleOverrides($conn, $userId);
        if (array_key_exists($moduleKey, $overrides)) {
            return $overrides[$moduleKey] === 1;
        }

        return in_array($role, $registry[$moduleKey]['roles'], true);
    }
}

if (!function_exists('getEffectiveModules')) {
    function getEffectiveModules(mysqli $conn, int $userId, string $role): array
    {
        $effective = [];
        foreach (moduleRegistry() as $key => $meta) {
            $effective[$key] = canAccessModule($conn, $userId, $role, $key);
        }
        return $effective;
    }
}

if (!function_exists('requireAnyModule')) {
    function requireAnyModule(mysqli $conn, array $moduleKeys): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        }
        $userId = (int) $_SESSION['user_id'];
        $role = (string) ($_SESSION['role'] ?? '');
        foreach ($moduleKeys as $key) {
            if (canAccessModule($conn, $userId, $role, (string) $key)) {
                return;
            }
        }
        $_SESSION['module_denied'] = 'You do not have access to that module. Contact your administrator.';
        header('Location: ' . BASE_URL . '/dashboard.php?denied=1');
        exit;
    }
}

if (!function_exists('requireModule')) {
    function requireModule(mysqli $conn, string $moduleKey): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        }
        $userId = (int) $_SESSION['user_id'];
        $role = (string) ($_SESSION['role'] ?? '');
        if (!canAccessModule($conn, $userId, $role, $moduleKey)) {
            $_SESSION['module_denied'] = 'You do not have access to that module. Contact your administrator.';
            header('Location: ' . BASE_URL . '/dashboard.php?denied=1');
            exit;
        }
    }
}

if (!function_exists('moduleSupervisorManagedModules')) {
    // Modules a supervisor is allowed to toggle for user/staff targets.
    function moduleSupervisorManagedModules(): array
    {
        return [
            'dashboard',
            'review_requests',
            'new_request',
            'my_requests',
            'publication_submission',
            'publication_requests',
            'kanban',
            'assign_jobs',
            'blocked_dates',
            'account_requests',
            'my_assigned_jobs',
            'calendar',
            'iec_materials',
            'evaluation_analytics',
            'monthly_report',
            'archived_requests',
            'ward_health_education',
            'whe_overview',
            'whe_new',
            'whe_sessions',
            'whe_visuals',
            'whe_reports',
            'notifications',
            'chat_center',
        ];
    }
}

if (!function_exists('canEditUserModules')) {
    // Whether $editorRole/$editorId may save overrides for $target.
    function canEditUserModules(
        mysqli $conn,
        string $editorRole,
        int $editorId,
        string $targetRole,
        int $targetId,
        ?string $moduleKey = null
    ): bool {
        if ($editorRole === 'admin') {
            // Admin cannot lock themselves out of Users / Module Permissions / Dashboard.
            if ($moduleKey !== null && $targetId === $editorId
                && in_array($moduleKey, ['users', 'module_permissions', 'dashboard'], true)) {
                return false;
            }
            return true;
        }

        if ($editorRole === 'supervisor') {
            // Supervisors manage user/staff only.
            if (!in_array($targetRole, ['user', 'staff'], true)) {
                return false;
            }
            if ($targetId === $editorId) {
                return false;
            }
            if ($moduleKey !== null
                && !in_array($moduleKey, moduleSupervisorManagedModules(), true)) {
                return false;
            }
            // Supervisor cannot grant a module they themselves lack.
            if ($moduleKey !== null) {
                $editorUserId = $editorId;
                $editorCan = canAccessModule($conn, $editorUserId, $editorRole, $moduleKey);
                if (!$editorCan) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }
}

if (!function_exists('saveUserModuleOverrides')) {
    /**
     * @param array<string,string> $choices module_key => 'inherit'|'allow'|'deny'
     * @return array{ok:bool,message:string}
     */
    function saveUserModuleOverrides(
        mysqli $conn,
        string $editorRole,
        int $editorId,
        int $targetId,
        string $targetRole,
        array $choices
    ): array {
        if (!moduleAccessEnsureTable($conn)) {
            return ['ok' => false, 'message' => 'Module permissions table is unavailable.'];
        }

        if (!in_array($editorRole, ['admin', 'supervisor'], true)) {
            return ['ok' => false, 'message' => 'You are not allowed to manage module access.'];
        }

        if (!canEditUserModules($conn, $editorRole, $editorId, $targetRole, $targetId)) {
            return ['ok' => false, 'message' => 'You are not allowed to edit this user.'];
        }

        $registry = moduleRegistry();
        $valid = ['inherit', 'allow', 'deny'];

        $conn->begin_transaction();
        try {
            foreach ($choices as $moduleKey => $mode) {
                $moduleKey = trim((string) $moduleKey);
                $mode = trim((string) $mode);
                if (!isset($registry[$moduleKey]) || !in_array($mode, $valid, true)) {
                    continue;
                }
                if (!canEditUserModules($conn, $editorRole, $editorId, $targetRole, $targetId, $moduleKey)) {
                    continue;
                }
                if ($mode === 'inherit') {
                    $del = $conn->prepare(
                        "DELETE FROM user_module_permissions WHERE user_id = ? AND module_key = ?"
                    );
                    $del->bind_param('is', $targetId, $moduleKey);
                    $del->execute();
                    $del->close();
                } else {
                    $allowed = $mode === 'allow' ? 1 : 0;
                    $up = $conn->prepare(
                        "INSERT INTO user_module_permissions (user_id, module_key, is_allowed, updated_by)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed), updated_by = VALUES(updated_by)"
                    );
                    $up->bind_param('isii', $targetId, $moduleKey, $allowed, $editorId);
                    $up->execute();
                    $up->close();
                }
            }
            $conn->commit();
            moduleAccessClearCache($targetId);
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $e2) {
            }
            return ['ok' => false, 'message' => 'Unable to save module permissions.'];
        }

        return ['ok' => true, 'message' => 'Module access updated successfully.'];
    }
}
