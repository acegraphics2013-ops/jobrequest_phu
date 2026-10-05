<?php
function checkLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

function requireRole(array $roles): void
{
    checkLogin();

    $role = $_SESSION['role'] ?? '';

    if (!in_array($role, $roles, true)) {
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }
}

function checkRole(string $role): void
{
    // Backward-compat shim: legacy supervisor pages call checkRole("supervisor").
    // Allow admin to access supervisor areas to avoid lockout.
    if ($role === 'supervisor') {
        requireRole(['supervisor', 'admin']);
        return;
    }

    requireRole([$role]);
}
