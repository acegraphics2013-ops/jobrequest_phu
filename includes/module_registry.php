<?php
/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - MODULE REGISTRY (single source of truth)
|--------------------------------------------------------------------------
| Each module key maps to label, section, and default roles.
| Role defaults preserve current behavior (visible by role).
| Per-user overrides in `user_module_permissions` grant/revoke
| on top of these defaults.
*/

if (!function_exists('moduleRegistry')) {
    function moduleRegistry(): array
    {
        return [
            'dashboard' => [
                'label' => 'Dashboard',
                'section' => 'General',
                'roles' => ['user', 'staff', 'supervisor', 'admin'],
            ],
            'job_requests_admin' => [
                'label' => 'Job Requests (Admin)',
                'section' => 'Requests',
                'roles' => ['admin'],
            ],
            'review_requests' => [
                'label' => 'Review Job Requests / Approvals',
                'section' => 'Requests',
                'roles' => ['staff', 'supervisor', 'admin'],
            ],
            'new_request' => [
                'label' => 'New Job Request',
                'section' => 'Requests',
                'roles' => ['user', 'staff', 'supervisor'],
            ],
            'my_requests' => [
                'label' => 'My Requests',
                'section' => 'Requests',
                'roles' => ['user', 'staff', 'supervisor'],
            ],
            'publication_submission' => [
                'label' => 'Publication Submission',
                'section' => 'Requests',
                'roles' => ['user', 'staff', 'supervisor', 'admin'],
            ],
            'publication_requests' => [
                'label' => 'Publication Requests',
                'section' => 'Project Monitoring',
                'roles' => ['staff', 'supervisor', 'admin'],
            ],
            'kanban' => [
                'label' => 'Kanban Board',
                'section' => 'Project Monitoring',
                'roles' => ['staff', 'supervisor', 'admin'],
            ],
            'assign_jobs' => [
                'label' => 'Assign Jobs',
                'section' => 'Project Monitoring',
                'roles' => ['supervisor', 'admin'],
            ],
            'blocked_dates' => [
                'label' => 'Blocked Dates',
                'section' => 'Project Monitoring',
                'roles' => ['staff', 'supervisor', 'admin'],
            ],
            'account_requests' => [
                'label' => 'Account Requests',
                'section' => 'Project Monitoring',
                'roles' => ['staff', 'supervisor', 'admin'],
            ],
            'my_assigned_jobs' => [
                'label' => 'My Assigned Jobs',
                'section' => 'My Workspace',
                'roles' => ['staff', 'supervisor', 'admin'],
            ],
            'calendar' => [
                'label' => 'Calendar of Activities',
                'section' => 'My Workspace',
                'roles' => ['user', 'staff', 'supervisor', 'admin'],
            ],
            'notifications' => [
                'label' => 'Notifications',
                'section' => 'Communication',
                'roles' => ['user', 'staff', 'supervisor', 'admin'],
            ],
            'chat_center' => [
                'label' => 'Chat Center',
                'section' => 'Communication',
                'roles' => ['staff', 'supervisor', 'admin'],
            ],
            'peer_chat' => [
                'label' => 'Peer Chat (User to User)',
                'section' => 'Communication',
                'roles' => [],
            ],
            'evaluation_analytics' => [
                'label' => 'Evaluation Analytics',
                'section' => 'Reports',
                'roles' => ['staff', 'supervisor', 'admin'],
            ],
            'archived_requests' => [
                'label' => 'Archived Requests',
                'section' => 'Reports',
                'roles' => ['staff', 'supervisor', 'admin'],
            ],
            'users' => [
                'label' => 'Users',
                'section' => 'Administration',
                'roles' => ['admin'],
            ],
            'departments' => [
                'label' => 'Departments',
                'section' => 'Administration',
                'roles' => ['admin'],
            ],
            'backup' => [
                'label' => 'Database Backup',
                'section' => 'Administration',
                'roles' => ['admin'],
            ],
            'upload_settings' => [
                'label' => 'Upload Location',
                'section' => 'Administration',
                'roles' => ['admin'],
            ],
            'iec_materials' => [
                'label' => 'IEC Materials',
                'section' => 'Project Monitoring',
                'roles' => ['user', 'staff', 'supervisor', 'admin'],
            ],
            'ward_health_education' => [
                'label' => 'Ward Health Education',
                'section' => 'Health Education',
                'roles' => ['user', 'staff', 'supervisor', 'admin'],
            ],
            'whe_overview' => [
                'label' => 'WHE Overview',
                'section' => 'Health Education',
                'roles' => ['staff', 'supervisor', 'admin'],
            ],
            'whe_new' => [
                'label' => 'WHE New Activity',
                'section' => 'Health Education',
                'roles' => ['user', 'staff', 'supervisor', 'admin'],
            ],
            'whe_sessions' => [
                'label' => 'WHE Sessions',
                'section' => 'Health Education',
                'roles' => ['staff', 'supervisor', 'admin'],
            ],
            'whe_visuals' => [
                'label' => 'WHE Visual Outputs',
                'section' => 'Health Education',
                'roles' => ['user', 'staff', 'supervisor', 'admin'],
            ],
            'whe_reports' => [
                'label' => 'WHE Reports',
                'section' => 'Health Education',
                'roles' => ['staff', 'supervisor', 'admin'],
            ],
            'module_permissions' => [
                'label' => 'Module Permissions',
                'section' => 'Administration',
                'roles' => ['admin', 'supervisor'],
            ],
        ];
    }
}

if (!function_exists('moduleRegistryKeys')) {
    function moduleRegistryKeys(): array
    {
        return array_keys(moduleRegistry());
    }
}

if (!function_exists('moduleDefaultRoles')) {
    function moduleDefaultRoles(string $moduleKey): array
    {
        $registry = moduleRegistry();
        return $registry[$moduleKey]['roles'] ?? [];
    }
}
