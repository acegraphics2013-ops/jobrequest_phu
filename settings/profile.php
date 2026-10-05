<?php
declare(strict_types=1);

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";

checkLogin();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

/* Tolerant column mapping (current schema uses name/email; older dumps used fullname/username). */
$displayName = (string) ($user['name'] ?? $user['fullname'] ?? $_SESSION['name'] ?? 'User');
$accountEmail = (string) ($user['email'] ?? $user['username'] ?? '');
$department = (string) ($user['department'] ?? '');
$photoFile = (string) ($user['photo'] ?? '');
$photoUrl = $photoFile !== ''
    ? BASE_URL . '/download.php?scope=avatars&file=' . rawurlencode(basename($photoFile))
    : '';
$role = (string) ($user['role'] ?? 'user');
$status = (string) ($user['status'] ?? 'Active');
$createdAt = (string) ($user['created_at'] ?? '');
$updatedAt = (string) ($user['updated_at'] ?? '');

$roleLabel = match ($role) {
    'admin' => 'Administrator',
    'staff' => 'HPU Staff',
    'supervisor' => 'Supervisor',
    default => 'Requestor',
};

$roleBadgeClass = match ($role) {
    'admin' => 'badge-admin',
    'staff' => 'badge-staff',
    'supervisor' => 'badge-supervisor',
    default => 'badge-user',
};

$statusClass = strcasecmp($status, 'Active') === 0 ? 'is-active' : 'is-inactive';

$initials = 'U';
$parts = array_values(array_filter(preg_split('/\s+/', trim($displayName)) ?: []));
if (count($parts) >= 2) {
    $initials = mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
} elseif (count($parts) === 1) {
    $initials = mb_strtoupper(mb_substr($parts[0], 0, 2));
}

function profileDate(string $value): string
{
    $value = trim($value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return '—';
    }
    try {
        return (new DateTime($value))->format('M d, Y • g:i A');
    } catch (Throwable $e) {
        return '—';
    }
}

/* Department suggestions (optional; never blocks the page). */
$departmentOptions = [];
try {
    $tbl = $conn->query("SHOW TABLES LIKE 'departments'");
    if ($tbl && $tbl->num_rows > 0) {
        $res = $conn->query("SELECT name FROM departments ORDER BY name ASC LIMIT 100");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $n = trim((string) ($row['name'] ?? ''));
                if ($n !== '') {
                    $departmentOptions[] = $n;
                }
            }
            $res->free();
        }
    }
} catch (Throwable $e) {
    $departmentOptions = [];
}

/* CSRF token for the edit form. */
if (empty($_SESSION['csrf_profile'])) {
    $_SESSION['csrf_profile'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['csrf_profile'];

$flashSuccess = isset($_GET['success']);
$flashError = trim((string) ($_GET['error'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile — PHU Workbench</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">
    <style>
        body { background: #f4f7fb; }
        .content { padding: 20px; }
        .container-fluid { max-width: 1200px; margin: 0 auto; }
        .profile-grid { display: grid; grid-template-columns: 340px minmax(0, 1fr); gap: 16px; align-items: start; }
        .profile-card, .form-card, .security-card {
            background: #fff; border: 1px solid #dfe6ef; border-radius: 16px;
            box-shadow: 0 10px 30px -5px rgba(15,23,42,.05);
            overflow: hidden;
        }
        .profile-hero { padding: 24px 20px 18px; text-align: center; background: linear-gradient(180deg, #eff6ff 0%, #fff 70%); border-bottom: 1px solid #e8eef5; }
        .profile-avatar {
            width: 84px; height: 84px; margin: 0 auto 12px; display: grid; place-items: center;
            border-radius: 50%; background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff;
            font-size: 1.6rem; font-weight: 800; letter-spacing: .02em;
            box-shadow: 0 10px 24px rgba(37,99,235,.35); border: 4px solid #fff;
        }
        .profile-name { margin: 0; font-size: 1.2rem; font-weight: 850; letter-spacing: -.02em; color: #0f172a; overflow-wrap: anywhere; }
        .profile-email { margin: 4px 0 0; color: #64748b; font-size: .82rem; overflow-wrap: anywhere; }
        .profile-badges { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; margin-top: 12px; }
        .pill { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px; font-size: .7rem; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; border: 1px solid transparent; }
        .badge-admin { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
        .badge-staff { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
        .badge-supervisor { background: #f5f3ff; color: #6d28d9; border-color: #ddd6fe; }
        .badge-user { background: #f8fafc; color: #475569; border-color: #e2e8f0; }
        .pill.is-active { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
        .pill.is-inactive { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
        .pill .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
        .profile-meta { padding: 6px 0; }
        .meta-row { display: flex; gap: 12px; align-items: flex-start; padding: 12px 20px; border-bottom: 1px solid #f1f5f9; font-size: .83rem; }
        .meta-row:last-child { border-bottom: none; }
        .meta-row i { width: 32px; height: 32px; flex: 0 0 32px; display: grid; place-items: center; border-radius: 10px; background: #f8fafc; color: #2563eb; border: 1px solid #e8eef5; }
        .meta-label { display: block; color: #64748b; font-size: .68rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; }
        .meta-value { display: block; color: #0f172a; font-weight: 700; margin-top: 2px; overflow-wrap: anywhere; }
        .meta-hint { display: block; color: #94a3b8; font-weight: 500; font-size: .75rem; margin-top: 2px; }
        .card-head { padding: 18px 22px 0; }
        .card-head h2 { margin: 0; font-size: 1.05rem; font-weight: 850; letter-spacing: -.01em; color: #0f172a; display: flex; align-items: center; gap: 10px; }
        .card-head h2 i { width: 34px; height: 34px; display: grid; place-items: center; border-radius: 10px; background: #eff6ff; color: #2563eb; font-size: 1rem; }
        .card-head p { margin: 8px 0 0 44px; color: #64748b; font-size: .82rem; }
        .card-body-padded { padding: 18px 22px 22px; }
        .field-label { font-size: .78rem; font-weight: 800; color: #334155; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
        .field-label .req { color: #dc2626; }
        .readonly-note { font-size: .75rem; color: #94a3b8; margin-top: 6px; display: flex; gap: 6px; align-items: flex-start; }
        .form-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
        .security-card { margin-top: 16px; }
        .photo-card { margin-bottom: 16px; }
        .photo-inner { display: flex; gap: 16px; align-items: center; padding: 18px 22px; flex-wrap: wrap; }
        .photo-preview {
            width: 76px; height: 76px; flex: 0 0 76px; display: grid; place-items: center;
            border-radius: 50%; background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff;
            font-size: 1.4rem; font-weight: 800; overflow: hidden; border: 3px solid #eff6ff;
        }
        .photo-preview img { width: 100%; height: 100%; object-fit: cover; }
        .photo-btns { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
        .profile-avatar { overflow: hidden; }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .security-inner { display: flex; gap: 14px; align-items: center; padding: 18px 22px; flex-wrap: wrap; }
        .security-icon { width: 44px; height: 44px; flex: 0 0 44px; display: grid; place-items: center; border-radius: 12px; background: #f5f3ff; color: #6d28d9; font-size: 1.2rem; }
        @media (max-width: 992px) { .profile-grid { grid-template-columns: 1fr; } }
        @media (max-width: 576px) {
            .content { padding: 12px; }
            .card-head p { margin-left: 0; }
            .form-actions .btn { flex: 1 1 auto; justify-content: center; }
        }
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
                        <div class="ph-icon" aria-hidden="true"><i class="bi bi-person-circle"></i></div>
                        <div class="min-w-0">
                            <p class="ph-eyebrow">Account</p>
                            <h1 class="ph-title">My Profile</h1>
                            <p class="ph-subtitle">View your account details and keep your name and department up to date.</p>
                        </div>
                    </div>
                    <div class="ph-actions">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/dashboard.php"><i class="bi bi-arrow-left me-1"></i>Dashboard</a>
                        <a class="btn btn-sm btn-primary" href="<?= e(BASE_URL) ?>/settings/change_password.php"><i class="bi bi-shield-lock me-1"></i>Change Password</a>
                    </div>
                </div>

                <?php if ($flashSuccess): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-1"></i>Profile updated successfully.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <?php if ($flashError !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i><?= e($flashError) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="profile-grid">
                    <!-- Identity summary -->
                    <section class="profile-card" aria-label="Account summary">
                        <div class="profile-hero">
                            <div class="profile-avatar" aria-hidden="true"><?php if ($photoUrl !== ''): ?><img src="<?= e($photoUrl) ?>" alt=""><?php else: ?><?= e($initials) ?><?php endif; ?></div>
                            <h2 class="profile-name"><?= e($displayName) ?></h2>
                            <p class="profile-email"><i class="bi bi-envelope me-1"></i><?= e($accountEmail !== '' ? $accountEmail : 'No email on record') ?></p>
                            <div class="profile-badges">
                                <span class="pill <?= e($roleBadgeClass) ?>"><i class="bi bi-person-badge"></i><?= e($roleLabel) ?></span>
                                <span class="pill <?= e($statusClass) ?>"><span class="dot"></span><?= e($status) ?></span>
                            </div>
                        </div>
                        <div class="profile-meta">
                            <div class="meta-row">
                                <i class="bi bi-hash"></i>
                                <div><span class="meta-label">User ID</span><span class="meta-value">#<?= (int) $user['id'] ?></span></div>
                            </div>
                            <div class="meta-row">
                                <i class="bi bi-building"></i>
                                <div><span class="meta-label">Department</span><span class="meta-value"><?= e($department !== '' ? $department : '—') ?></span></div>
                            </div>
                            <div class="meta-row">
                                <i class="bi bi-calendar-check"></i>
                                <div><span class="meta-label">Member since</span><span class="meta-value"><?= e(profileDate($createdAt)) ?></span>
                                <?php if ($updatedAt !== '' && $updatedAt !== $createdAt): ?><span class="meta-hint">Last updated <?= e(profileDate($updatedAt)) ?></span><?php endif; ?></div>
                            </div>
                        </div>
                    </section>

                    <!-- Editable details + security -->
                    <div class="min-w-0">
                        <section class="form-card photo-card" aria-label="Profile photo">
                            <div class="photo-inner">
                                <div class="photo-preview" id="photoPreview"><?php if ($photoUrl !== ''): ?><img src="<?= e($photoUrl) ?>" alt=""><?php else: ?><?= e($initials) ?><?php endif; ?></div>
                                <div class="flex-grow-1 min-w-0" style="min-width:220px">
                                    <div class="fw-bold">Profile photo</div>
                                    <div class="text-muted small">JPG, PNG, GIF or WEBP, up to 5 MB. Shown in the sidebar and your profile.</div>
                                    <form action="upload_photo.php" method="POST" enctype="multipart/form-data" id="photoForm">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="photo_action" id="photoAction" value="upload">
                                        <input type="file" id="photoInput" name="photo" accept=".jpg,.jpeg,.png,.gif,.webp" class="d-none">
                                        <div class="photo-btns">
                                            <button type="button" id="photoChoose" class="btn btn-sm btn-outline-primary"><i class="bi bi-image me-1"></i>Choose photo</button>
                                            <button type="submit" id="photoSave" class="btn btn-sm btn-primary" disabled><i class="bi bi-check-lg me-1"></i>Save photo</button>
                                            <?php if ($photoFile !== ''): ?>
                                            <button type="submit" id="photoRemove" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Remove</button>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </section>
                        <section class="form-card" aria-label="Edit profile">
                            <div class="card-head">
                                <h2><i class="bi bi-pencil-square"></i>Profile details</h2>
                                <p>Your name and department are editable. Email, role and status are managed by an administrator.</p>
                            </div>
                            <div class="card-body-padded">
                                <form action="update_profile.php" method="POST" autocomplete="off" novalidate>
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="field-label" for="profileName">Full Name <span class="req">*</span></label>
                                            <input type="text" id="profileName" name="fullname" class="form-control" value="<?= e($displayName) ?>" required maxlength="150" placeholder="e.g. Juan D. Cruz">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="field-label" for="profileDept">Department / Unit</label>
                                            <input type="text" id="profileDept" name="department" class="form-control" value="<?= e($department) ?>" maxlength="150" list="deptList" placeholder="e.g. Nursing Service Division">
                                            <?php if ($departmentOptions): ?>
                                            <datalist id="deptList">
                                                <?php foreach ($departmentOptions as $opt): ?><option value="<?= e($opt) ?>"></option><?php endforeach; ?>
                                            </datalist>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="field-label" for="profileEmail">Email</label>
                                            <input type="email" id="profileEmail" class="form-control" value="<?= e($accountEmail) ?>" readonly disabled>
                                            <div class="readonly-note"><i class="bi bi-lock"></i><span>Contact your administrator to change your login email.</span></div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="field-label" for="profileRole">Role</label>
                                            <input type="text" id="profileRole" class="form-control" value="<?= e($roleLabel) ?>" readonly disabled>
                                            <div class="readonly-note"><i class="bi bi-shield-check"></i><span>Roles are assigned by an administrator.</span></div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="field-label" for="profileStatus">Status</label>
                                            <input type="text" id="profileStatus" class="form-control" value="<?= e($status) ?>" readonly disabled>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="field-label" for="profileSince">Member Since</label>
                                            <input type="text" id="profileSince" class="form-control" value="<?= e(profileDate($createdAt)) ?>" readonly disabled>
                                        </div>
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                                        <a href="<?= e(BASE_URL) ?>/dashboard.php" class="btn btn-outline-primary"><i class="bi bi-x-lg me-1"></i>Discard</a>
                                    </div>
                                </form>
                            </div>
                        </section>

                        <section class="security-card" aria-label="Password and security">
                            <div class="security-inner">
                                <div class="security-icon" aria-hidden="true"><i class="bi bi-shield-lock-fill"></i></div>
                                <div class="flex-grow-1 min-w-0" style="min-width:220px">
                                    <div class="fw-bold">Password &amp; security</div>
                                    <div class="text-muted small">Use a strong, unique password. Update it regularly to keep your account safe.</div>
                                </div>
                                <a href="<?= e(BASE_URL) ?>/settings/change_password.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-key me-1"></i>Change Password</a>
                            </div>
                        </section>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    var input = document.getElementById("photoInput");
    var choose = document.getElementById("photoChoose");
    var save = document.getElementById("photoSave");
    var remove = document.getElementById("photoRemove");
    var action = document.getElementById("photoAction");
    var preview = document.getElementById("photoPreview");
    if (!input || !choose) return;
    choose.addEventListener("click", function () { input.click(); });
    input.addEventListener("change", function () {
        var f = input.files && input.files[0];
        if (!f) return;
        var ok = ["image/jpeg", "image/png", "image/gif", "image/webp"].indexOf(f.type) !== -1;
        if (!ok) { alert("Use JPG, PNG, GIF or WEBP."); input.value = ""; return; }
        if (f.size > 5 * 1024 * 1024) { alert("Photo must not exceed 5 MB."); input.value = ""; return; }
        try {
            var url = URL.createObjectURL(f);
            preview.innerHTML = "";
            var img = document.createElement("img");
            img.src = url;
            img.alt = "";
            preview.appendChild(img);
        } catch (err) {}
        if (action) action.value = "upload";
        if (save) save.disabled = false;
    });
    if (remove) {
        remove.addEventListener("click", function () {
            if (action) action.value = "remove";
        });
    }
});
</script>
</body>
</html>
