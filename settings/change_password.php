<?php
declare(strict_types=1);

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";

checkLogin();

$userName = (string) ($_SESSION['name'] ?? 'User');
$flashError = trim((string) ($_GET['error'] ?? ''));
$flashSuccess = isset($_GET['success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password — PHU Workbench</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">
    <style>
        body { background: #f4f7fb; }
        .content { padding: 20px; }
        .container-fluid { max-width: 860px; margin: 0 auto; }
        .security-card { background: #fff; border: 1px solid #dfe6ef; border-radius: 16px; box-shadow: 0 10px 30px -5px rgba(15,23,42,.05); overflow: hidden; }
        .security-head { padding: 22px 24px 0; }
        .security-head h2 { margin: 0; font-size: 1.05rem; font-weight: 850; color: #0f172a; display: flex; align-items: center; gap: 10px; }
        .security-head h2 i { width: 34px; height: 34px; display: grid; place-items: center; border-radius: 10px; background: #f5f3ff; color: #6d28d9; }
        .security-head p { margin: 8px 0 0 44px; color: #64748b; font-size: .83rem; }
        .security-body { padding: 18px 24px 24px; }
        .field-label { font-size: .78rem; font-weight: 800; color: #334155; margin-bottom: 6px; display: block; }
        .tips { margin: 16px 0 0; padding: 14px 16px; border-radius: 12px; background: #f8fafc; border: 1px solid #e8eef5; color: #475569; font-size: .8rem; }
        .tips ul { margin: 8px 0 0; padding-left: 18px; }
        .form-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
        .input-group .btn { border-color: #e2e8f0 !important; }
        @media (max-width: 576px) { .content { padding: 12px; } .security-head p { margin-left: 0; } .form-actions .btn { flex: 1 1 auto; justify-content: center; } }
    </style>
</head>
<body class="app-shell">
<div class="d-flex min-vh-100">
    <?php include "../includes/sidebar.php"; ?>
    <div class="main flex-grow-1">
        <main class="content">
            <div class="container-fluid">

                <div class="ph">
                    <div class="ph-copy">
                        <div class="ph-icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></div>
                        <div class="min-w-0">
                            <p class="ph-eyebrow">Account • <?= e($userName) ?></p>
                            <h1 class="ph-title">Change Password</h1>
                            <p class="ph-subtitle">Keep your account secure with a strong, unique password.</p>
                        </div>
                    </div>
                    <div class="ph-actions">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/settings/profile.php"><i class="bi bi-person me-1"></i>My Profile</a>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/dashboard.php"><i class="bi bi-arrow-left me-1"></i>Dashboard</a>
                    </div>
                </div>

                <?php if ($flashError !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i><?= e($flashError) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <?php if ($flashSuccess): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-1"></i>Password changed successfully.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <section class="security-card" aria-label="Change password form">
                    <div class="security-head">
                        <h2><i class="bi bi-key"></i>Update your password</h2>
                        <p>You will stay signed in on this device after changing your password.</p>
                    </div>
                    <div class="security-body">
                        <form action="update_password.php" method="POST" autocomplete="off">
                            <div class="mb-3">
                                <label class="field-label" for="currentPassword">Current Password</label>
                                <div class="input-group">
                                    <input type="password" id="currentPassword" name="current_password" class="form-control" required autocomplete="current-password" placeholder="Enter current password">
                                    <button class="btn btn-outline-secondary" type="button" data-toggle-password="currentPassword" aria-label="Show password"><i class="bi bi-eye"></i></button>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="field-label" for="newPassword">New Password</label>
                                    <div class="input-group">
                                        <input type="password" id="newPassword" name="new_password" class="form-control" required minlength="6" autocomplete="new-password" placeholder="Min. 6 characters">
                                        <button class="btn btn-outline-secondary" type="button" data-toggle-password="newPassword" aria-label="Show password"><i class="bi bi-eye"></i></button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="field-label" for="confirmPassword">Confirm New Password</label>
                                    <div class="input-group">
                                        <input type="password" id="confirmPassword" name="confirm_password" class="form-control" required minlength="6" autocomplete="new-password" placeholder="Repeat new password">
                                        <button class="btn btn-outline-secondary" type="button" data-toggle-password="confirmPassword" aria-label="Show password"><i class="bi bi-eye"></i></button>
                                    </div>
                                </div>
                            </div>
                            <div class="tips">
                                <strong><i class="bi bi-info-circle me-1"></i>Tips for a strong password</strong>
                                <ul>
                                    <li>At least 6 characters (longer is better).</li>
                                    <li>Mix letters, numbers and symbols.</li>
                                    <li>Don't reuse passwords from other sites.</li>
                                </ul>
                            </div>
                            <div class="form-actions">
                                <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>Update Password</button>
                                <a href="<?= e(BASE_URL) ?>/settings/profile.php" class="btn btn-outline-primary"><i class="bi bi-x-lg me-1"></i>Cancel</a>
                            </div>
                        </form>
                    </div>
                </section>

            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll("[data-toggle-password]").forEach(function (btn) {
    btn.addEventListener("click", function () {
        var input = document.getElementById(btn.getAttribute("data-toggle-password"));
        if (!input) return;
        var show = input.type === "password";
        input.type = show ? "text" : "password";
        btn.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
        btn.setAttribute("aria-label", show ? "Hide password" : "Show password");
    });
});
</script>
</body>
</html>
