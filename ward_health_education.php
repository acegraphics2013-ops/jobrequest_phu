<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/module_access.php';
checkLogin();
requireModule($conn, 'ward_health_education');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userRole = (string) ($_SESSION['role'] ?? 'user');
$isWheStaff = in_array($userRole, ['staff', 'supervisor', 'admin'], true);
$isWheManager = in_array($userRole, ['supervisor', 'admin'], true);

/* Per-view module gating (Module Permissions > Health Education section). */
$wheViewModules = [
    'overview' => 'whe_overview',
    'new' => 'whe_new',
    'sessions' => 'whe_sessions',
    'visuals' => 'whe_visuals',
    'reports' => 'whe_reports',
];
$wheAllowedViews = [];
foreach ($wheViewModules as $wheView => $wheModule) {
    if (canAccessModule($conn, $userId, $userRole, $wheModule)) {
        $wheAllowedViews[] = $wheView;
    }
}
if (!$wheAllowedViews) {
    $_SESSION['module_denied'] = 'You do not have access to Ward Health Education. Contact your administrator.';
    header('Location: ' . BASE_URL . '/dashboard.php?denied=1');
    exit;
}
$wheAfterSave = in_array('sessions', $wheAllowedViews, true)
    ? 'sessions'
    : (in_array('visuals', $wheAllowedViews, true) ? 'visuals' : $wheAllowedViews[0]);
$wheBackView = in_array('overview', $wheAllowedViews, true) ? 'overview' : $wheAfterSave;

function whe_date(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '')
        return null;
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}
function whe_clock_minutes($value): ?int
{
    $value = trim((string) $value);
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $m))
        return null;
    return ((int) $m[1]) * 60 + (int) $m[2];
}
function whe_clock_label(int $minutes): string
{
    return date('g:i A', mktime(intdiv($minutes, 60), $minutes % 60, 0, 1, 1, 2000));
}
function whe_flash(string $type, string $message): void
{
    $_SESSION['whe_flash'] = compact('type', 'message');
}
function whe_pull_flash(): ?array
{
    $f = $_SESSION['whe_flash'] ?? null;
    unset($_SESSION['whe_flash']);
    return is_array($f) ? $f : null;
}
function whe_view(string $view): string
{
    $allowed = ['overview', 'new', 'sessions', 'visuals', 'reports'];
    return in_array($view, $allowed, true) ? $view : 'overview';
}
function whe_status_class(string $status): string
{
    return match ($status) {
        'Approved' => 'approved',
        'Published' => 'published',
        'Archived' => 'archived',
        default => 'review'
    };
}
function whe_can_edit_session(array $session, int $userId, bool $isStaff, bool $isManager): bool
{
    /* Supervisors/admins may edit any activity; HPU staff may only edit their own submitted data. */
    if ($isManager) {
        return true;
    }
    if (!$isStaff || $userId <= 0) {
        return false;
    }
    return (int) ($session['submitted_by'] ?? 0) === $userId;
}

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

$storageDir = __DIR__ . '/storage/ward_health_education';
if (!is_dir($storageDir))
    @mkdir($storageDir, 0775, true);
if (!is_file($storageDir . '/.htaccess'))
    @file_put_contents($storageDir . '/.htaccess', "Options -Indexes\nRequire all denied\n");

if (empty($_SESSION['whe_csrf']))
    $_SESSION['whe_csrf'] = bin2hex(random_bytes(32));
$csrf = (string) $_SESSION['whe_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = (string) ($_POST['csrf_token'] ?? '');
    if ($posted === '' || !hash_equals($csrf, $posted)) {
        whe_flash('danger', 'Security token expired. Please try again.');
        header('Location: ' . BASE_URL . '/ward_health_education.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_session') {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === 'Others') {
            $title = trim((string) ($_POST['title_other'] ?? ''));
        }
        $titleFree = trim((string) ($_POST['title_free'] ?? ''));
        if ($titleFree !== '') {
            $title = $titleFree;
        }
        $date = whe_date($_POST['session_date'] ?? null);
        $startRaw = trim((string) ($_POST['activity_time_start'] ?? ''));
        $endRaw = trim((string) ($_POST['activity_time_end'] ?? ''));
        $startMin = $startRaw === '' ? null : whe_clock_minutes($startRaw);
        $endMin = $endRaw === '' ? null : whe_clock_minutes($endRaw);
        if (($startRaw !== '' && $startMin === null) || ($endRaw !== '' && $endMin === null)) {
            whe_flash('danger', 'Activity time is invalid. Use the Start and End time pickers.');
            header('Location: ' . BASE_URL . '/ward_health_education.php?view=new');
            exit;
        }
        if ($startMin !== null && $endMin !== null && $endMin <= $startMin) {
            whe_flash('danger', 'Activity End Time must be after the Start Time.');
            header('Location: ' . BASE_URL . '/ward_health_education.php?view=new');
            exit;
        }
        if ($startMin !== null && $endMin !== null) {
            $time = whe_clock_label($startMin) . ' to ' . whe_clock_label($endMin);
        } elseif ($startMin !== null) {
            $time = whe_clock_label($startMin);
        } elseif ($endMin !== null) {
            $time = whe_clock_label($endMin);
        } else {
            $time = '';
        }
        $ward = trim((string) ($_POST['ward_area'] ?? ''));
        $office = trim((string) ($_POST['lead_office'] ?? ''));
        $topic = trim((string) ($_POST['health_topic'] ?? ''));
        $facilitator = trim((string) ($_POST['facilitator'] ?? ''));
        $target = trim((string) ($_POST['target_participants'] ?? ''));
        $count = max(0, (int) ($_POST['participant_count'] ?? 0));
        $method = trim((string) ($_POST['teaching_method'] ?? ''));
        $objectives = trim((string) ($_POST['objectives'] ?? ''));
        $outputs = trim((string) ($_POST['key_outputs'] ?? ''));
        $impact = trim((string) ($_POST['impact_significance'] ?? ''));

        /* Ward / Area accepts a Departments suggestion or a typed custom value. */
        if ($title === '' || !$date || $ward === '' || $office === '' || $topic === '' || $facilitator === '' || $objectives === '') {
            whe_flash('danger', 'Please complete all required Health Education Activity fields.');
        } else {
            $stmt = $conn->prepare("INSERT INTO ward_health_education_sessions
                (submitted_by,title,session_date,activity_time,ward_area,lead_office,health_topic,facilitator,target_participants,participant_count,teaching_method,objectives,key_outputs,impact_significance)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('issssssssissss', $userId, $title, $date, $time, $ward, $office, $topic, $facilitator, $target, $count, $method, $objectives, $outputs, $impact);
            $stmt->execute();
            $stmt->close();
            whe_flash('success', 'Health Education Activity saved successfully.');
        }
        header('Location: ' . BASE_URL . '/ward_health_education.php?view=' . $wheAfterSave);
        exit;
    }

    if ($action === 'update_session') {
        if (!$isWheStaff) {
            whe_flash('danger', 'Only supervisors and HPU staff are allowed to edit Health Education Activity sessions.');
            header('Location: ' . BASE_URL . '/ward_health_education.php?view=' . $wheAfterSave);
            exit;
        }
        $id = (int) ($_POST['session_id'] ?? 0);
        if ($id > 0 && !$isWheManager) {
            $ownStmt = $conn->prepare('SELECT submitted_by FROM ward_health_education_sessions WHERE id = ? LIMIT 1');
            $ownStmt->bind_param('i', $id);
            $ownStmt->execute();
            $ownRow = $ownStmt->get_result()->fetch_assoc() ?: null;
            $ownStmt->close();
            if (!$ownRow || (int) ($ownRow['submitted_by'] ?? 0) !== $userId) {
                whe_flash('danger', 'You can only edit activities you submitted. Supervisors may edit any activity.');
                header('Location: ' . BASE_URL . '/ward_health_education.php?view=' . $wheAfterSave);
                exit;
            }
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === 'Others') {
            $title = trim((string) ($_POST['title_other'] ?? ''));
        }
        $titleFree = trim((string) ($_POST['title_free'] ?? ''));
        if ($titleFree !== '') {
            $title = $titleFree;
        }
        $date = whe_date($_POST['session_date'] ?? null);
        $startRaw = trim((string) ($_POST['activity_time_start'] ?? ''));
        $endRaw = trim((string) ($_POST['activity_time_end'] ?? ''));
        $startMin = $startRaw === '' ? null : whe_clock_minutes($startRaw);
        $endMin = $endRaw === '' ? null : whe_clock_minutes($endRaw);
        if (($startRaw !== '' && $startMin === null) || ($endRaw !== '' && $endMin === null)) {
            whe_flash('danger', 'Activity time is invalid. Use the Start and End time pickers.');
            header('Location: ' . BASE_URL . '/ward_health_education.php?view=edit&id=' . $id);
            exit;
        }
        if ($startMin !== null && $endMin !== null && $endMin <= $startMin) {
            whe_flash('danger', 'Activity End Time must be after the Start Time.');
            header('Location: ' . BASE_URL . '/ward_health_education.php?view=edit&id=' . $id);
            exit;
        }
        if ($startMin !== null && $endMin !== null) {
            $time = whe_clock_label($startMin) . ' to ' . whe_clock_label($endMin);
        } elseif ($startMin !== null) {
            $time = whe_clock_label($startMin);
        } elseif ($endMin !== null) {
            $time = whe_clock_label($endMin);
        } else {
            $time = '';
        }
        $ward = trim((string) ($_POST['ward_area'] ?? ''));
        $office = trim((string) ($_POST['lead_office'] ?? ''));
        $topic = trim((string) ($_POST['health_topic'] ?? ''));
        $facilitator = trim((string) ($_POST['facilitator'] ?? ''));
        $target = trim((string) ($_POST['target_participants'] ?? ''));
        $count = max(0, (int) ($_POST['participant_count'] ?? 0));
        $method = trim((string) ($_POST['teaching_method'] ?? ''));
        $objectives = trim((string) ($_POST['objectives'] ?? ''));
        $outputs = trim((string) ($_POST['key_outputs'] ?? ''));
        $impact = trim((string) ($_POST['impact_significance'] ?? ''));

        if ($id <= 0) {
            whe_flash('danger', 'Session not found.');
        } elseif ($title === '' || !$date || $ward === '' || $office === '' || $topic === '' || $facilitator === '' || $objectives === '') {
            whe_flash('danger', 'Please complete all required Health Education Activity fields.');
            header('Location: ' . BASE_URL . '/ward_health_education.php?view=edit&id=' . $id);
            exit;
        } else {
            $stmt = $conn->prepare("UPDATE ward_health_education_sessions
                SET title=?,session_date=?,activity_time=?,ward_area=?,lead_office=?,health_topic=?,facilitator=?,target_participants=?,participant_count=?,teaching_method=?,objectives=?,key_outputs=?,impact_significance=?
                WHERE id=?");
            $stmt->bind_param('ssssssssissssi', $title, $date, $time, $ward, $office, $topic, $facilitator, $target, $count, $method, $objectives, $outputs, $impact, $id);
            $stmt->execute();
            $stmt->close();
            whe_flash('success', 'Health Education Activity session updated successfully.');
        }
        header('Location: ' . BASE_URL . '/ward_health_education.php?view=' . $wheBackView);
        exit;
    }

    if ($action === 'delete_session') {
        if (!$isWheManager) {
            whe_flash('danger', 'Only supervisors are allowed to delete Health Education Activity sessions.');
            header('Location: ' . BASE_URL . '/ward_health_education.php?view=' . $wheAfterSave);
            exit;
        }
        $id = (int) ($_POST['session_id'] ?? 0);
        if ($id > 0) {
            try {
                $fStmt = $conn->prepare('SELECT stored_name FROM ward_health_education_outputs WHERE session_id = ?');
                $fStmt->bind_param('i', $id);
                $fStmt->execute();
                $fRes = $fStmt->get_result();
                $dlRoot = realpath($storageDir);
                while ($fr = $fRes->fetch_assoc()) {
                    $fp = $storageDir . '/' . basename((string) $fr['stored_name']);
                    $real = realpath($fp);
                    if ($real !== false && $dlRoot !== false && strpos($real, $dlRoot) === 0 && is_file($real)) {
                        @unlink($real);
                    }
                }
                $fStmt->close();
            } catch (Throwable $e) {
            }
            $dStmt = $conn->prepare('DELETE FROM ward_health_education_sessions WHERE id = ?');
            $dStmt->bind_param('i', $id);
            $dStmt->execute();
            $dStmt->close();
            whe_flash('success', 'Health Education Activity session deleted.');
        } else {
            whe_flash('danger', 'Session not found.');
        }
        header('Location: ' . BASE_URL . '/ward_health_education.php?view=' . $wheBackView);
        exit;
    }

    if ($action === 'update_status') {
        $id = (int) ($_POST['session_id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? ''));
        $allowed = ['For Review', 'Approved', 'Published', 'Archived'];
        if ($id > 0 && in_array($status, $allowed, true)) {
            // Role gate: staff+ may move to Approved/Published;
            // only supervisor/admin may Archive. Anyone may revert to For Review.
            $permitted = $status === 'For Review'
                || (in_array($status, ['Approved', 'Published'], true) && $isWheStaff)
                || ($status === 'Archived' && $isWheManager);
            if (!$permitted) {
                whe_flash('danger', 'You are not allowed to set that status.');
            } else {
                $stmt = $conn->prepare('UPDATE ward_health_education_sessions SET status=? WHERE id=?');
                $stmt->bind_param('si', $status, $id);
                $stmt->execute();
                $stmt->close();
                whe_flash('success', 'Session status updated.');
            }
        }
        header('Location: ' . BASE_URL . '/ward_health_education.php?view=' . $wheAfterSave);
        exit;
    }

    if ($action === 'upload_output') {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $type = trim((string) ($_POST['output_type'] ?? ''));
        $title = trim((string) ($_POST['output_title'] ?? ''));
        $desc = trim((string) ($_POST['description'] ?? ''));

        if ($sessionId <= 0 || $type === '' || $title === '' || empty($_FILES['supporting_files']['name'])) {
            whe_flash('danger', 'Select a session, output type, title, and file.');
        } else {
            $names = $_FILES['supporting_files']['name'];
            $tmps = $_FILES['supporting_files']['tmp_name'];
            $errors = $_FILES['supporting_files']['error'];
            $sizes = $_FILES['supporting_files']['size'];
            if (!is_array($names))
                $names = [$names];
            if (!is_array($tmps))
                $tmps = [$tmps];
            if (!is_array($errors))
                $errors = [$errors];
            if (!is_array($sizes))
                $sizes = [$sizes];
            $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'ppt', 'pptx', 'mp4', 'mov'];
            $saved = 0;
            $stmt = $conn->prepare("INSERT INTO ward_health_education_outputs
                (session_id,uploaded_by,output_type,title,description,original_name,stored_name,extension,mime_type,file_size)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
            foreach ($names as $i => $name) {
                if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
                    continue;
                $name = basename((string) $name);
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true))
                    continue;
                $size = (int) ($sizes[$i] ?? 0);
                $limit = in_array($ext, ['mp4', 'mov'], true) ? 50 * 1024 * 1024 : 12 * 1024 * 1024;
                if ($size <= 0 || $size > $limit)
                    continue;
                $tmp = (string) ($tmps[$i] ?? '');
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
                if (stripos($mime, 'php') !== false || stripos($mime, 'javascript') !== false || stripos($mime, 'text/html') !== false)
                    continue;
                $stored = bin2hex(random_bytes(18)) . '.' . $ext;
                if (!move_uploaded_file($tmp, $storageDir . '/' . $stored))
                    continue;
                $stmt->bind_param('iisssssssi', $sessionId, $userId, $type, $title, $desc, $name, $stored, $ext, $mime, $size);
                $stmt->execute();
                $saved++;
            }
            $stmt->close();
            whe_flash($saved ? 'success' : 'danger', $saved ? "$saved file(s) uploaded successfully." : 'No valid files were uploaded.');
        }
        header('Location: ' . BASE_URL . '/ward_health_education.php?view=visuals');
        exit;
    }
}

/* Secure file viewer: streams stored outputs inline (storage dir is web-denied). */
if (isset($_GET['download'])) {
    $dlId = (int) $_GET['download'];
    $dlFile = null;
    if ($dlId > 0) {
        $dlStmt = $conn->prepare('SELECT original_name, stored_name, mime_type FROM ward_health_education_outputs WHERE id = ? LIMIT 1');
        $dlStmt->bind_param('i', $dlId);
        $dlStmt->execute();
        $dlFile = $dlStmt->get_result()->fetch_assoc() ?: null;
        $dlStmt->close();
    }
    $dlReal = false;
    if ($dlFile) {
        $dlPath = $storageDir . '/' . basename((string) $dlFile['stored_name']);
        $dlReal = realpath($dlPath);
        $dlRoot = realpath($storageDir);
        if ($dlReal === false || $dlRoot === false || strpos($dlReal, $dlRoot) !== 0 || !is_file($dlReal)) {
            $dlReal = false;
        }
    }
    if ($dlFile && $dlReal !== false) {
        $dlMime = trim((string) ($dlFile['mime_type'] ?? ''));
        if ($dlMime === '') {
            $dlMime = (new finfo(FILEINFO_MIME_TYPE))->file($dlReal) ?: 'application/octet-stream';
        }
        header('Content-Type: ' . $dlMime);
        header('Content-Length: ' . filesize($dlReal));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', basename((string) $dlFile['original_name'])) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($dlReal);
        exit;
    }
    whe_flash('danger', 'File not found.');
    header('Location: ' . BASE_URL . '/ward_health_education.php?view=visuals');
    exit;
}

$rawView = (string) ($_GET['view'] ?? 'overview');
/* Edit form (staff+ only): reuses the New form prefilled; not a sidebar tab. */
$wheEditing = null;
if ($rawView === 'edit') {
    if (!$isWheStaff) {
        $_SESSION['module_denied'] = 'You do not have access to that module. Contact your administrator.';
        header('Location: ' . BASE_URL . '/dashboard.php?denied=1');
        exit;
    }
    $editId = (int) ($_GET['id'] ?? 0);
    if ($editId > 0) {
        $eStmt = $conn->prepare('SELECT * FROM ward_health_education_sessions WHERE id = ? LIMIT 1');
        $eStmt->bind_param('i', $editId);
        $eStmt->execute();
        $wheEditing = $eStmt->get_result()->fetch_assoc() ?: null;
        $eStmt->close();
    }
    if (!$wheEditing) {
        whe_flash('danger', 'Session not found.');
        header('Location: ' . BASE_URL . '/ward_health_education.php?view=' . $wheBackView);
        exit;
    }
    if (!whe_can_edit_session($wheEditing, $userId, $isWheStaff, $isWheManager)) {
        whe_flash('danger', 'You can only edit activities you submitted. Supervisors may edit any activity.');
        header('Location: ' . BASE_URL . '/ward_health_education.php?view=' . $wheBackView);
        exit;
    }
    $view = 'edit';
} else {
    $view = whe_view($rawView);
}
/* Views are governed by Module Permissions; fall back to the first allowed view. */
if (!in_array($view, $wheAllowedViews, true) && $view !== 'edit') {
    header('Location: ' . BASE_URL . '/ward_health_education.php?view=' . $wheAllowedViews[0]);
    exit;
}
$flash = whe_pull_flash();

/* Prefill helpers for the shared New/Edit form. */
$wheEditTitle = $wheEditing ? (string) ($wheEditing['title'] ?? '') : '';
$wheTitlePresets = [
    'Health Education Sessions and Lectures (Patient and Family Education)',
    'Health Promotion Campaigns and Advocacy (Symposium)',
    'Staff Health Education and Wellness Activities',
    'Screening and Early Detection Activities',
    'Community-Based Health Promotion',
];
$wheTitleIsOther = $wheEditing !== null && $wheEditTitle !== '' && !in_array($wheEditTitle, $wheTitlePresets, true);
$wheStartVal = '';
$wheEndVal = '';
if ($wheEditing && trim((string) ($wheEditing['activity_time'] ?? '')) !== '') {
    $wheTimeParts = explode(' to ', (string) $wheEditing['activity_time']);
    $wheT1 = strtotime(trim($wheTimeParts[0] ?? ''));
    if ($wheT1 !== false) {
        $wheStartVal = date('H:i', $wheT1);
    }
    if (isset($wheTimeParts[1])) {
        $wheT2 = strtotime(trim($wheTimeParts[1]));
        if ($wheT2 !== false) {
            $wheEndVal = date('H:i', $wheT2);
        }
    }
}

$statsRow = $conn->query("SELECT COUNT(*) sessions,COALESCE(SUM(participant_count),0) participants,
    SUM(CASE WHEN YEAR(session_date)=YEAR(CURDATE()) AND MONTH(session_date)=MONTH(CURDATE()) THEN 1 ELSE 0 END) this_month,
    SUM(CASE WHEN status='For Review' THEN 1 ELSE 0 END) pending,
    SUM(CASE WHEN status='Published' THEN 1 ELSE 0 END) published
    FROM ward_health_education_sessions")->fetch_assoc();
$stats = [
    'sessions' => (int) ($statsRow['sessions'] ?? 0),
    'participants' => (int) ($statsRow['participants'] ?? 0),
    'this_month' => (int) ($statsRow['this_month'] ?? 0),
    'pending' => (int) ($statsRow['pending'] ?? 0),
    'published' => (int) ($statsRow['published'] ?? 0),
    'outputs' => (int) (($conn->query('SELECT COUNT(*) total FROM ward_health_education_outputs')->fetch_assoc())['total'] ?? 0)
];

$recent = $conn->query('SELECT * FROM ward_health_education_sessions ORDER BY session_date DESC,id DESC LIMIT 8')->fetch_all(MYSQLI_ASSOC);
$all = $conn->query('SELECT * FROM ward_health_education_sessions ORDER BY session_date DESC,id DESC')->fetch_all(MYSQLI_ASSOC);
$outputs = $conn->query("SELECT o.*,s.title session_title,s.session_date,s.ward_area,s.health_topic,s.lead_office
    FROM ward_health_education_outputs o JOIN ward_health_education_sessions s ON s.id=o.session_id
    ORDER BY o.created_at DESC,o.id DESC")->fetch_all(MYSQLI_ASSOC);
$topics = $conn->query('SELECT health_topic,COUNT(*) total FROM ward_health_education_sessions GROUP BY health_topic ORDER BY total DESC LIMIT 6')->fetch_all(MYSQLI_ASSOC);
$wards = $conn->query('SELECT ward_area,COUNT(*) sessions,COALESCE(SUM(participant_count),0) participants FROM ward_health_education_sessions GROUP BY ward_area ORDER BY sessions DESC')->fetch_all(MYSQLI_ASSOC);
$targetSummary = $conn->query("SELECT COALESCE(NULLIF(TRIM(target_participants),''),'Not Specified') grp,COUNT(*) sessions,COALESCE(SUM(participant_count),0) participants FROM ward_health_education_sessions GROUP BY grp ORDER BY sessions DESC")->fetch_all(MYSQLI_ASSOC);
$monthly = array_fill(1, 12, 0);
$res = $conn->query('SELECT MONTH(session_date)m,COUNT(*) total FROM ward_health_education_sessions WHERE YEAR(session_date)=YEAR(CURDATE()) GROUP BY MONTH(session_date)');
while ($r = $res->fetch_assoc())
    $monthly[(int) $r['m']] = (int) $r['total'];

/* Ward / Area options come from the Departments registry (admin/departments.php). */
$wheDepartments = [];
try {
    $wheDeptCols = $conn->query("SHOW COLUMNS FROM departments LIKE 'status'");
    $wheDeptHasStatus = $wheDeptCols && $wheDeptCols->num_rows > 0;
    $wheDeptSql = "SELECT department_name"
        . ($wheDeptHasStatus ? ", status" : ", 'Active' AS status")
        . " FROM departments ORDER BY department_name ASC";
    $wheDeptRes = $conn->query($wheDeptSql);
    if ($wheDeptRes) {
        while ($wheDeptRow = $wheDeptRes->fetch_assoc()) {
            if (($wheDeptRow['status'] ?? 'Active') === 'Active') {
                $wheDepartments[] = (string) $wheDeptRow['department_name'];
            }
        }
    }
} catch (Throwable $e) {
    $wheDepartments = [];
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Health Education and Promotion Activities</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background: #f4f7fb
        }

        .whe-page {
            max-width: 1400px;
            margin: auto
        }

        .whe-head,
        .whe-card,
        .metric {
            background: #fff;
            border: 1px solid #dfe6ef;
            border-radius: 15px;
            box-shadow: 0 6px 20px rgba(15, 23, 42, .04)
        }

        .whe-head {
            padding: 18px 20px;
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: flex-start;
            margin-bottom: 14px
        }

        .whe-title {
            display: flex;
            gap: 12px;
            align-items: flex-start
        }

        .whe-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #2563eb, #16a36f);
            color: #fff;
            font-size: 21px
        }

        .whe-title h1 {
            font-size: 1.4rem;
            margin: 0 0 4px;
            font-weight: 850
        }

        .whe-title p {
            margin: 0;
            color: #64748b;
            font-size: .76rem
        }

        .tabs {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
            margin-bottom: 14px
        }

        .tab {
            padding: 9px 12px;
            border: 1px solid #dfe6ef;
            border-radius: 10px;
            background: #fff;
            color: #475569;
            text-decoration: none;
            font-size: .7rem;
            font-weight: 750
        }

        .tab.active {
            background: #eff6ff;
            border-color: #60a5fa;
            color: #1d4ed8
        }

        .metrics {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 10px;
            margin-bottom: 14px
        }

        .metric {
            padding: 14px
        }

        .metric i {
            font-size: 18px;
            color: #2563eb
        }

        .metric small {
            display: block;
            color: #64748b;
            font-size: .59rem;
            font-weight: 700;
            margin-top: 6px
        }

        .metric strong {
            font-size: 1.45rem;
            display: block
        }

        .grid2 {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 14px
        }

        .whe-card {
            overflow: hidden;
            margin-bottom: 14px
        }

        .card-h {
            padding: 13px 15px;
            border-bottom: 1px solid #edf1f5;
            background: #fbfcfe;
            display: flex;
            justify-content: space-between;
            align-items: center
        }

        .card-h h2 {
            font-size: .78rem;
            margin: 0;
            font-weight: 850
        }

        .card-b {
            padding: 15px
        }

        .chart {
            height: 270px
        }

        .form-label {
            font-size: .68rem;
            font-weight: 800;
            color: #334155
        }

        .form-control,
        .form-select {
            min-height: 42px;
            border-radius: 9px;
            border-color: #dbe3ef;
            font-size: .78rem
        }

        .status {
            padding: 4px 8px;
            border-radius: 999px;
            font-size: .59rem;
            font-weight: 800
        }

        .review {
            background: #fff7db;
            color: #a16207
        }

        .approved {
            background: #e8fbf2;
            color: #087a52
        }

        .published {
            background: #eaf3ff;
            color: #1d5fa7
        }

        .archived {
            background: #eef2f7;
            color: #475569
        }

        .output-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px
        }

        .output {
            border: 1px solid #dfe6ef;
            border-radius: 12px;
            overflow: hidden;
            background: #fff
        }

        .output-preview {
            height: 110px;
            background: linear-gradient(135deg, #eff6ff, #f8fafc);
            display: grid;
            place-items: center;
            font-size: 30px;
            color: #5b88bb
        }

        .output-copy {
            padding: 10px
        }

        .output-copy strong,
        .output-copy small {
            display: block
        }

        .output-copy strong {
            font-size: .68rem
        }

        .output-copy small {
            font-size: .57rem;
            color: #64748b
        }

        .empty {
            text-align: center;
            padding: 28px;
            color: #94a3b8;
            font-size: .72rem
        }

        .table {
            font-size: .7rem
        }

        .table th {
            font-size: .59rem;
            text-transform: uppercase;
            color: #64748b;
            background: #f8fafc;
            white-space: nowrap
        }

        @media(max-width:1100px) {
            .metrics {
                grid-template-columns: repeat(3, 1fr)
            }

            .output-grid {
                grid-template-columns: repeat(3, 1fr)
            }
        }

        @media(max-width:800px) {
            .grid2 {
                grid-template-columns: 1fr
            }

            .output-grid {
                grid-template-columns: repeat(2, 1fr)
            }
        }

        @media(max-width:600px) {
            .content {
                padding: 12px
            }

            .whe-head {
                flex-direction: column
            }

            .metrics {
                grid-template-columns: repeat(2, 1fr)
            }

            .output-grid {
                grid-template-columns: 1fr
            }
        }
    </style>
    <style>
        /* ============================================================
   Ward Health Education — Workbench standard page system
   Mirrors page_headers.css (.ph) + vault panels (kpi/panel/table)
   ============================================================ */
        .whe-page {
            max-width: 1500px;
            margin: 0 auto
        }

        /* ---- Page header (same as .ph) ---- */
        .whe-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            padding: 16px 20px;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            box-shadow: 0 4px 20px rgba(15, 23, 42, .035);
            position: relative;
            overflow: hidden
        }

        .whe-head::after {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 3px;
            background: linear-gradient(90deg, #2563eb, #7c3aed, #0ea5e9);
            opacity: .9
        }

        .whe-title {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0
        }

        .whe-icon {
            display: grid;
            width: 46px;
            height: 46px;
            flex: 0 0 46px;
            place-items: center;
            border: 1px solid #cfe0f8;
            border-radius: 12px;
            background: #eef4ff;
            color: #2563eb;
            font-size: 21px
        }

        .whe-eyebrow {
            margin: 0 0 2px 0;
            color: #2563eb;
            font-size: .68rem;
            font-weight: 850;
            letter-spacing: .07em;
            text-transform: uppercase
        }

        .whe-title h1 {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 850;
            letter-spacing: -.02em;
            line-height: 1.15;
            color: #0f172a
        }

        .whe-title p {
            margin: 4px 0 0;
            color: #64748b;
            font-size: .81rem;
            font-weight: 500;
            line-height: 1.45
        }

        .whe-head .btn {
            border-radius: 8px;
            font-size: .8rem;
            font-weight: 700;
            padding: 6px 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px
        }

        /* ---- View tabs (segmented bar) ---- */
        .tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 4px 20px rgba(15, 23, 42, .035)
        }

        .tab {
            padding: 7px 14px;
            border: 1px solid #dfe6ef;
            border-radius: 8px;
            background: #fff;
            color: #475569;
            text-decoration: none;
            font-size: .78rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background .15s ease, border-color .15s ease, color .15s ease
        }

        .tab:hover {
            border-color: #2563eb;
            color: #2563eb;
            background: #f8fbff
        }

        .tab.active {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
            box-shadow: 0 4px 14px rgba(37, 99, 235, .25)
        }

        /* ---- Stat cards (same as kpi-grid) ---- */
        .metrics {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 12px
        }

        .metric {
            display: grid;
            grid-template-columns: 42px minmax(0, 1fr);
            gap: 4px 12px;
            align-items: center;
            padding: 13px 14px;
            border: 1px solid #dfe6ef;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 8px 24px rgba(28, 42, 72, .06)
        }

        .metric i {
            grid-row: span 2;
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: #eff8ff;
            color: #175cd3;
            font-size: 18px
        }

        .metric:nth-child(2) i {
            background: #ecfdf3;
            color: #027a48
        }

        .metric:nth-child(3) i {
            background: #f5f3ff;
            color: #6d28d9
        }

        .metric:nth-child(4) i {
            background: #fef9e7;
            color: #92400e
        }

        .metric:nth-child(5) i {
            background: #ecfdf3;
            color: #027a48
        }

        .metric:nth-child(6) i {
            background: #eef4ff;
            color: #3538cd
        }

        .metric small {
            display: block;
            color: #667085;
            font-size: .58rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin: 0
        }

        .metric strong {
            font-size: 1.4rem;
            font-weight: 900;
            line-height: 1.1;
            color: #172033
        }

        /* ---- Panels (same as .panel) ---- */
        .grid2 {
            display: grid;
            grid-template-columns: 1.5fr minmax(0, 1fr);
            gap: 14px
        }

        .whe-card {
            border: 1px solid #dfe6ef;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 8px 24px rgba(28, 42, 72, .06);
            overflow: hidden;
            margin-bottom: 14px
        }

        .card-h {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 13px 16px;
            border-bottom: 1px solid #edf1f6;
            background: #fff;
            flex-wrap: wrap
        }

        .card-h h2 {
            margin: 0;
            font-size: .92rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #0f172a
        }

        .card-b {
            padding: 15px
        }

        .chart {
            height: 270px
        }

        /* ---- Tables inside panels (same as vault tables) ---- */
        .whe-card .table {
            margin-bottom: 0;
            font-size: .68rem
        }

        .whe-card .table thead th {
            padding: 9px 12px;
            background: #f8fafc;
            border-bottom: 1px solid #eaecf0;
            color: #667085;
            font-size: .56rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .05em;
            white-space: nowrap
        }

        .whe-card .table tbody td {
            padding: 11px 12px;
            border-bottom: 1px solid #f0f2f5;
            vertical-align: middle
        }

        .whe-card .table tbody tr:hover {
            background: #f8fbff
        }

        /* ---- Status pills (same weight/shape as vault) ---- */
        .status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 9px;
            border-radius: 999px;
            font-size: .6rem;
            font-weight: 850;
            white-space: nowrap
        }

        /* ---- Empty states (same as vault) ---- */
        .empty {
            padding: 52px 16px;
            text-align: center;
            color: #667085;
            font-size: .68rem
        }

        /* ---- Responsive (same breakpoints as vault) ---- */
        @media(max-width:1100px) {
            .metrics {
                grid-template-columns: repeat(3, minmax(0, 1fr))
            }

            .grid2 {
                grid-template-columns: minmax(0, 1fr)
            }
        }

        @media(max-width:700px) {
            .whe-head {
                padding: 14px 16px;
                align-items: flex-start
            }

            .whe-title {
                align-items: flex-start
            }

            .whe-icon {
                width: 40px;
                height: 40px;
                flex-basis: 40px;
                font-size: 18px
            }

            .whe-title h1 {
                font-size: 1.15rem
            }
        }

        @media(max-width:640px) {
            .metrics {
                grid-template-columns: repeat(2, minmax(0, 1fr))
            }

            .tabs {
                padding: 8px
            }

            .tab {
                flex: 1 1 auto;
                justify-content: center
            }
        }

        /* ---- Visual output cards: file action ---- */
        .output-copy .btn {
            margin-top: 8px;
            align-self: flex-start
        }
    </style>
</head>

<body class="app-shell">
    <div class="d-flex min-vh-100">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <div class="main flex-grow-1">
            <main class="content">
                <div class="container-fluid">
                    <div class="whe-page">

                        <div class="whe-head">
                            <div class="whe-title">
                                <div class="whe-icon"><i class="bi bi-mortarboard-fill"></i></div>
                                <div>
                                    <p class="whe-eyebrow">Health Education and Promotion</p>
                                    <h1>Health Education and Promotion Activities</h1>
                                    <p>Monitoring, documentation, visual outputs, and reporting of health education activities</p>
                                </div>
                            </div>
                            <?php if ($view !== 'new'): ?><a class="btn btn-primary"
                                    href="<?= e(BASE_URL) ?>/ward_health_education.php?view=new"><i
                                        class="bi bi-plus-lg"></i>New Activity</a><?php endif; ?>
                        </div>

                        <nav class="tabs">
                            <?php
                            $wheTabs = [
                                'overview' => ['bi-grid-1x2-fill', 'Overview'],
                                'new' => ['bi-plus-square-fill', 'New Health Education and Promotion Activity'],
                                'sessions' => ['bi-list-check', 'Health Education and Promotion Sessions'],
                                'visuals' => ['bi-images', 'Visual Outputs / Documentation'],
                                'reports' => ['bi-bar-chart-fill', 'Reports']
                            ];
                            $wheTabs = array_filter(
                                $wheTabs,
                                static fn($k) => in_array($k, $wheAllowedViews, true),
                                ARRAY_FILTER_USE_KEY
                            );
                            ?>
                            <?php foreach ($wheTabs as $k => $v): ?>
                                <a class="tab <?= $view === $k ? 'active' : '' ?>"
                                    href="<?= e(BASE_URL) ?>/ward_health_education.php?view=<?= e($k) ?>"><i
                                        class="bi <?= e($v[0]) ?>"></i> <?= e($v[1]) ?></a>
                            <?php endforeach; ?>
                        </nav>

                        <?php if ($flash): ?>
                            <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
                                <?= e($flash['message']) ?><button class="btn-close" type="button"
                                    data-bs-dismiss="alert"></button>
                            </div><?php endif; ?>

                        <?php if ($view === 'overview'): ?>
                            <div class="metrics">
                                <?php foreach ([
                                    ['bi-mortarboard-fill', 'Total Activities', $stats['sessions']],
                                    ['bi-people-fill', 'Participants Reached', $stats['participants']],
                                    ['bi-calendar2-week-fill', 'Sessions This Month', $stats['this_month']],
                                    ['bi-hourglass-split', 'Pending Review', $stats['pending']],
                                    ['bi-patch-check-fill', 'Published Sessions', $stats['published']],
                                    ['bi-images', 'Visual Outputs', $stats['outputs']]
                                ] as $m): ?>
                                    <div class="metric"><i
                                            class="bi <?= e($m[0]) ?>"></i><small><?= e($m[1]) ?></small><strong><?= number_format((int) $m[2]) ?></strong>
                                    </div><?php endforeach; ?>
                            </div>
                            <div class="grid2">
                                <section class="whe-card">
                                    <div class="card-h">
                                        <h2>Sessions per Month</h2>
                                    </div>
                                    <div class="card-b">
                                        <div class="chart"><canvas id="monthlyChart"></canvas></div>
                                    </div>
                                </section>
                                <section class="whe-card">
                                    <div class="card-h">
                                        <h2>Top Health Topics</h2>
                                    </div>
                                    <div class="card-b">
                                        <div class="chart"><canvas id="topicChart"></canvas></div>
                                    </div>
                                </section>
                            </div>
                            <section class="whe-card">
                                <div class="card-h">
                                    <h2>Consolidated Data by Target Participants</h2>
                                    <span class="text-muted small">Based on Target Participants input</span>
                                </div>
                                <?php if (!$targetSummary): ?>
                                    <div class="empty">No consolidated data available yet.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Target Participants</th>
                                                    <th>Sessions</th>
                                                    <th>Participants Reached</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($targetSummary as $t): ?>
                                                    <tr>
                                                        <td><strong><?= e($t['grp']) ?></strong></td>
                                                        <td><?= number_format((int) $t['sessions']) ?></td>
                                                        <td><?= number_format((int) $t['participants']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </section>
                            <section class="whe-card">
                                <div class="card-h">
                                    <h2>Recent Activities</h2>
                                    <div class="d-flex gap-2">
                                        <?php if ($isWheStaff && in_array('new', $wheAllowedViews, true)): ?>
                                            <a class="btn btn-sm btn-primary"
                                                href="?view=new"><i class="bi bi-plus-lg me-1"></i>Add Activity</a>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-outline-primary"
                                            href="?view=sessions">View All Sessions</a>
                                    </div>
                                </div>
                                <?php if (!$recent): ?>
                                    <div class="empty">No activities have been recorded yet.</div><?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Health Topic</th>
                                                    <th>Ward / Area</th>
                                                    <th>Participants</th>
                                                    <th>Status</th>
                                                    <?php if ($isWheStaff): ?>
                                                        <th class="text-end">Actions</th>
                                                    <?php endif; ?>
                                                </tr>
                                            </thead>
                                            <tbody><?php foreach ($recent as $s): ?>
                                                    <tr>
                                                        <td><?= e(date('M d, Y', strtotime($s['session_date']))) ?></td>
                                                        <td><strong><?= e($s['health_topic']) ?></strong></td>
                                                        <td><?= e($s['ward_area']) ?></td>
                                                        <td><?= number_format((int) $s['participant_count']) ?></td>
                                                        <td><span
                                                                class="status <?= e(whe_status_class($s['status'])) ?>"><?= e($s['status']) ?></span>
                                                        </td>
                                                        <?php if ($isWheStaff): ?>
                                                            <td>
                                                                <div class="d-flex gap-1 justify-content-end">
                                                                    <?php if (whe_can_edit_session($s, $userId, $isWheStaff, $isWheManager)): ?>
                                                                    <a class="btn btn-sm btn-outline-primary"
                                                                        href="?view=edit&id=<?= (int) $s['id'] ?>"
                                                                        title="Edit session"><i class="bi bi-pencil"></i></a>
                                                                    <?php endif; ?>
                                                                    <?php if ($isWheManager): ?>
                                                                    <form method="post"
                                                                        action="<?= e(BASE_URL) ?>/ward_health_education.php"
                                                                        class="d-inline"
                                                                        onsubmit="return confirm('Delete this Health Education and Promotion session and its uploaded files? This cannot be undone.');">
                                                                        <input type="hidden" name="csrf_token"
                                                                            value="<?= e($csrf) ?>">
                                                                        <input type="hidden" name="action"
                                                                            value="delete_session">
                                                                        <input type="hidden" name="session_id"
                                                                            value="<?= (int) $s['id'] ?>">
                                                                        <button class="btn btn-sm btn-outline-danger"
                                                                            type="submit" title="Delete session"><i
                                                                                class="bi bi-trash"></i></button>
                                                                    </form>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </td>
                                                        <?php endif; ?>
                                                    </tr><?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div><?php endif; ?>
                            </section>

                        <?php elseif ($view === 'new' || $view === 'edit'): ?>
                            <form method="post" action="<?= e(BASE_URL) ?>/ward_health_education.php">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden"
                                    name="action"
                                    value="<?= $view === 'edit' ? 'update_session' : 'create_session' ?>">
                                <?php if ($view === 'edit' && $wheEditing): ?>
                                    <input type="hidden" name="session_id"
                                        value="<?= (int) $wheEditing['id'] ?>">
                                <?php endif; ?>
                                <section class="whe-card">
                                    <div class="card-h">
                                        <h2><?= $view === 'edit' ? 'Edit Activity' : 'New Activity' ?></h2>
                                    </div>
                                    <div class="card-b">
                                        <div class="row g-3">
                                            <div class="col-12<?= $wheTitleIsOther ? '' : ' d-none' ?>" id="wheTitleOtherWrap"><label
                                                    class="form-label">Title of Activity / Specify Other Title *</label><input
                                                    class="form-control" name="title_other" id="wheTitleOther"
                                                    value="<?= e($wheTitleIsOther ? $wheEditTitle : '') ?>"
                                                    placeholder="Type the specific title of activity"></div>
                                            <div class="col-12"><label
                                                    class="form-label">Type of Health Education and Promotion Activity *</label><input
                                                    class="form-control" name="title_free" id="wheTitleFree"
                                                    value="<?= e($wheTitleIsOther ? $wheEditTitle : '') ?>"
                                                    placeholder="Type freely here — if filled, this will be saved as the activity type"
                                                    autocomplete="off">
                                            </div>

                                            <div class="col-12"><label class="form-label">Type of Activity *</label><select
                                                    class="form-select" name="title" id="wheTitleSelect"
                                                    data-current="<?= e($wheEditing ? ($wheTitleIsOther ? 'Others' : $wheEditTitle) : '') ?>"
                                                    required>
                                                    <option value="">Select activity title</option>
                                                    <option value="Health Education Sessions and Lectures (Patient and Family Education)">Health Education Sessions and Lectures (Patient and Family Education)</option>
                                                    <option value="Health Promotion Campaigns and Advocacy (Symposium)">Health Promotion Campaigns and Advocacy (Symposium)</option>
                                                    <option value="Staff Health Education and Wellness Activities">Staff Health Education and Wellness Activities</option>
                                                    <option value="Screening and Early Detection Activities">Screening and Early Detection Activities</option>
                                                    <option value="Community-Based Health Promotion">Community-Based Health Promotion</option>
                                                    <option value="Others">Others</option>
                                                </select>
                                                <div class="form-text">Select the title that best describes this activity.</div>
                                            </div>
                                            <div class="col-md-4"><label class="form-label">Session Date *</label><input
                                                    type="date" class="form-control" name="session_date"
                                                    value="<?= e($wheEditing ? (string) ($wheEditing['session_date'] ?? '') : '') ?>"
                                                    required></div>
                                            <div class="col-md-2"><label class="form-label">Start Time</label><input
                                                    type="time" class="form-control" name="activity_time_start"
                                                    value="<?= e($wheStartVal) ?>"></div>
                                            <div class="col-md-2"><label class="form-label">End Time</label><input
                                                    type="time" class="form-control" name="activity_time_end"
                                                    value="<?= e($wheEndVal) ?>"></div>
                                            <div class="col-md-4"><label class="form-label">Ward / Area *</label>
                                                <input class="form-control" name="ward_area"
                                                    list="wheWardList" placeholder="Select or type ward / area"
                                                    value="<?= e($wheEditing ? (string) ($wheEditing['ward_area'] ?? '') : '') ?>"
                                                    autocomplete="off" required>
                                                <datalist id="wheWardList">
                                                    <?php foreach ($wheDepartments as $wheDept): ?>
                                                        <option value="<?= e($wheDept) ?>"></option>
                                                    <?php endforeach; ?>
                                                </datalist>
                                                <div class="form-text">Pick from Departments or type a custom ward / area.</div>
                                            </div>
                                            <div class="col-md-6"><label class="form-label">Lead Office / Unit
                                                    *</label><input class="form-control" name="lead_office"
                                                    list="wheOfficeList" placeholder="Select or type lead office / unit"
                                                    value="<?= e($wheEditing ? (string) ($wheEditing['lead_office'] ?? '') : '') ?>"
                                                    autocomplete="off" required>
                                                <datalist id="wheOfficeList">
                                                    <?php foreach ($wheDepartments as $wheDept): ?>
                                                        <option value="<?= e($wheDept) ?>"></option>
                                                    <?php endforeach; ?>
                                                </datalist>
                                            </div>
                                            <div class="col-md-6"><label class="form-label">Health Topic *</label><input
                                                    class="form-control" name="health_topic"
                                                    value="<?= e($wheEditing ? (string) ($wheEditing['health_topic'] ?? '') : '') ?>"
                                                    placeholder="e.g. Hand Hygiene and Infection Prevention" required></div>
                                            <div class="col-md-6"><label class="form-label">Facilitator / Health Educator
                                                    *</label><input class="form-control" name="facilitator"
                                                    value="<?= e($wheEditing ? (string) ($wheEditing['facilitator'] ?? '') : '') ?>"
                                                    placeholder="Name / designation" required></div>
                                            <div class="col-md-3"><label class="form-label">Target
                                                    Participants</label><select class="form-select"
                                                    name="target_participants" id="wheTargetSelect"
                                                    data-current="<?= e($wheEditing ? (string) ($wheEditing['target_participants'] ?? '') : '') ?>">
                                                    <option>Patients and Watchers</option>
                                                    <option>Patients Only</option>
                                                    <option>Watchers / Family Members</option>
                                                    <option>Employees</option>
                                                    <option>Community Members / General Public</option>
                                                    <option>Barangay Health Workers</option>
                                                    <option>Students and Teachers</option>
                                                    <option>Senior Citizens and PWDs</option>
                                                    <option>External Partners (LGU / NGO / Stakeholders)</option>
                                                    <option>Healthcare Professionals (External)</option>
                                                    <option>Others</option>
                                                </select>
                                                <div class="form-text">For outside-hospital symposiums, choose Community, Barangay, School, or External Partner groups.</div>
                                            </div>
                                            <div class="col-md-3"><label class="form-label">Number of
                                                    Participants</label><input type="number" min="0" class="form-control"
                                                    name="participant_count"
                                                    value="<?= e($wheEditing ? (string) ((int) ($wheEditing['participant_count'] ?? 0)) : '0') ?>"></div>
                                            <div class="col-md-6"><label class="form-label">Teaching Method</label><select
                                                    class="form-select" name="teaching_method" id="wheMethodSelect"
                                                    data-current="<?= e($wheEditing ? (string) ($wheEditing['teaching_method'] ?? '') : '') ?>">
                                                    <option>Interactive Discussion</option>
                                                    <option>Lecture</option>
                                                    <option>Demonstration</option>
                                                    <option>Video Presentation</option>
                                                    <option>IEC Distribution</option>
                                                    <option>Mixed Method</option>
                                                </select></div>
                                            <div class="col-12"><label class="form-label">Objectives *</label><textarea
                                                    class="form-control" rows="5" name="objectives"
                                                    required><?= e($wheEditing ? (string) ($wheEditing['objectives'] ?? '') : '') ?></textarea>
                                            </div>
                                            <div class="col-12"><label class="form-label">Key Outputs /
                                                    Agreements</label><textarea class="form-control" rows="5"
                                                    name="key_outputs"
                                                    placeholder="Example: Conducted 1 Ward Health Education Class with 25 patients/watchers reached; distributed IEC materials and documented the activity."><?= e($wheEditing ? (string) ($wheEditing['key_outputs'] ?? '') : '') ?></textarea>
                                            </div>
                                            <div class="col-12"><label class="form-label">Impact /
                                                    Significance</label><textarea class="form-control" rows="5"
                                                    name="impact_significance"><?= e($wheEditing ? (string) ($wheEditing['impact_significance'] ?? '') : '') ?></textarea></div>
                                        </div>
                                        <div class="d-flex justify-content-end gap-2 mt-3"><a
                                                class="btn btn-outline-secondary" href="?view=<?= e($wheBackView) ?>">Cancel</a><button
                                                class="btn btn-primary"><i class="bi bi-save"></i> <?= $view === 'edit' ? 'Update Health Education and Promotion Activity' : 'Save Health Education and Promotion Activity' ?></button></div>
                                    </div>
                                </section>
                            </form>
                            <script>
                                (function () {
                                    function initSelect(id) {
                                        var el = document.getElementById(id);
                                        if (!el) return;
                                        var cur = el.getAttribute('data-current') || '';
                                        if (cur === '') return;
                                        for (var i = 0; i < el.options.length; i++) {
                                            if (el.options[i].value === cur) {
                                                el.selectedIndex = i;
                                                break;
                                            }
                                        }
                                    }
                                    initSelect('wheTitleSelect');
                                    initSelect('wheTargetSelect');
                                    initSelect('wheMethodSelect');
                                    var sel = document.getElementById('wheTitleSelect');
                                    var wrap = document.getElementById('wheTitleOtherWrap');
                                    var other = document.getElementById('wheTitleOther');
                                    if (!sel || !wrap || !other) return;
                                    if (sel.value === 'Others') {
                                        wrap.classList.remove('d-none');
                                        other.required = true;
                                    }
                                    sel.addEventListener('change', function () {
                                        var isOther = sel.value === 'Others';
                                        wrap.classList.toggle('d-none', !isOther);
                                        other.required = isOther;
                                        if (!isOther) other.value = '';
                                    });
                                })();
                            </script>

                        <?php elseif ($view === 'sessions'): ?>
                            <section class="whe-card">
                                <div class="card-h">
                                    <h2>Health Education and Promotion Sessions</h2><input id="search" class="form-control form-control-sm"
                                        style="max-width:260px" placeholder="Search sessions...">
                                </div>
                                <?php if (!$all): ?>
                                    <div class="empty">No Health Education and Promotion sessions recorded yet.</div><?php else: ?>
                                    <div class="table-responsive">
                                        <table id="sessionTable" class="table table-hover mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Time</th>
                                                    <th>Title / Topic</th>
                                                    <th>Ward</th>
                                                    <th>Facilitator</th>
                                                    <th>Participants</th>
                                                    <th>Status</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($all as $s): ?>
                                                    <tr>
                                                        <td><?= e(date('M d, Y', strtotime($s['session_date']))) ?></td>
                                                        <td style="white-space:nowrap">
                                                            <?= e(trim((string) ($s['activity_time'] ?? '')) !== '' ? (string) $s['activity_time'] : '—') ?>
                                                        </td>
                                                        <td><strong><?= e($s['health_topic']) ?></strong>
                                                            <div class="text-muted small"><?= e($s['title']) ?></div>
                                                        </td>
                                                        <td><?= e($s['ward_area']) ?></td>
                                                        <td><?= e($s['facilitator']) ?></td>
                                                        <td><?= number_format((int) $s['participant_count']) ?></td>
                                                        <td><span
                                                                class="status <?= e(whe_status_class($s['status'])) ?>"><?= e($s['status']) ?></span>
                                                        </td>
                                                        <td>
                                                            <form method="post"
                                                                action="<?= e(BASE_URL) ?>/ward_health_education.php"><input
                                                                    type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input
                                                                    type="hidden" name="action" value="update_status"><input
                                                                    type="hidden" name="session_id"
                                                                    value="<?= (int) $s['id'] ?>"><select
                                                                    class="form-select form-select-sm" name="status"
                                                                    onchange="this.form.submit()"><?php foreach (['For Review', 'Approved', 'Published', 'Archived'] as $st): ?><?php $stDisabled = ($st !== 'For Review' && in_array($st, ['Approved', 'Published'], true) && !$isWheStaff) || ($st === 'Archived' && !$isWheManager); ?>
                                                                        <option value="<?= e($st) ?>" <?= $s['status'] === $st ? 'selected' : '' ?>                 <?= $stDisabled ? 'disabled' : '' ?>><?= e($st) ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select></form>
                                                        </td>
                                                    </tr><?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div><?php endif; ?>
                            </section>

                        <?php elseif ($view === 'visuals'): ?>
                            <section class="whe-card">
                                <div class="card-h">
                                    <h2>Upload Visual Output / Documentation</h2>
                                </div>
                                <div class="card-b">
                                    <?php if (!$all): ?>
                                        <div class="alert alert-info mb-0">Create a Health Education and Promotion session first.</div>
                                    <?php else: ?>
                                        <form method="post" enctype="multipart/form-data"
                                            action="<?= e(BASE_URL) ?>/ward_health_education.php"><input type="hidden"
                                                name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action"
                                                value="upload_output">
                                            <div class="row g-3">
                                                <div class="col-md-6"><label class="form-label">Health Education and Promotion Session
                                                        *</label><select class="form-select" name="session_id" required>
                                                        <option value="">Select session</option><?php foreach ($all as $s): ?>
                                                            <option value="<?= (int) $s['id'] ?>">
                                                                <?= e(date('M d, Y', strtotime($s['session_date'])) . ' — ' . $s['health_topic'] . ' — ' . $s['ward_area']) ?>
                                                            </option><?php endforeach; ?>
                                                    </select></div>
                                                <div class="col-md-3"><label class="form-label">Output Type *</label><select
                                                        class="form-select" name="output_type" required>
                                                        <option value="">Select type</option>
                                                        <option>Photo Documentation</option>
                                                        <option>IEC Material</option>
                                                        <option>Presentation</option>
                                                        <option>Attendance Sheet</option>
                                                        <option>Video Documentation</option>
                                                        <option>Other</option>
                                                    </select></div>
                                                <div class="col-md-3"><label class="form-label">Output Title *</label><input
                                                        class="form-control" name="output_title" required></div>
                                                <div class="col-12"><label class="form-label">Description</label><textarea
                                                        class="form-control" rows="3" name="description"></textarea></div>
                                                <div class="col-12"><label class="form-label">Files *</label><input type="file"
                                                        class="form-control" name="supporting_files[]" multiple required
                                                        accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.ppt,.pptx,.mp4,.mov">
                                                    <div class="text-muted small mt-1">Images/documents: max 12 MB each. Videos:
                                                        max 50 MB each.</div>
                                                </div>
                                            </div>
                                            <div class="d-flex justify-content-end mt-3"><button class="btn btn-primary"><i
                                                        class="bi bi-cloud-arrow-up"></i> Upload Documentation</button></div>
                                        </form><?php endif; ?>
                                </div>
                            </section>
                            <section class="whe-card">
                                <div class="card-h">
                                    <h2>Visual Outputs / Documentation</h2>
                                </div>
                                <div class="card-b"><?php if (!$outputs): ?>
                                        <div class="empty">No visual outputs uploaded yet.</div><?php else: ?>
                                        <div class="output-grid">
                                            <?php foreach ($outputs as $o):
                                                $ext = strtolower($o['extension']);
                                                $icon = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? 'bi-image' : (in_array($ext, ['mp4', 'mov'], true) ? 'bi-camera-video-fill' : 'bi-file-earmark-text-fill'); ?>
                                                <article class="output">
                                                    <div class="output-preview"><i class="bi <?= e($icon) ?>"></i></div>
                                                    <div class="output-copy">
                                                        <strong><?= e($o['title']) ?></strong><small><?= e($o['output_type']) ?></small><small><?= e($o['health_topic']) ?>
                                                            • <?= e($o['ward_area']) ?></small><small>Lead Office:
                                                            <?= e(trim((string) ($o['lead_office'] ?? '')) !== '' ? (string) $o['lead_office'] : '—') ?></small><small><?= e($o['original_name']) ?></small><a
                                                            class="btn btn-sm btn-outline-primary mt-2 align-self-start"
                                                            href="<?= e(BASE_URL) ?>/ward_health_education.php?download=<?= (int) $o['id'] ?>"
                                                            target="_blank" rel="noopener"><i class="bi bi-eye"></i> View File</a>
                                                    </div>
                                                </article><?php endforeach; ?>
                                        </div><?php endif; ?>
                                </div>
                            </section>

                        <?php elseif ($view === 'reports'): ?>
                            <section class="whe-card">
                                <div class="card-h">
                                    <h2>Reports</h2><button class="btn btn-sm btn-outline-primary"
                                        onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
                                </div>
                                <div class="card-b">
                                    <div class="metrics" style="grid-template-columns:repeat(3,1fr);margin-bottom:14px">
                                        <div class="metric"><i class="bi bi-mortarboard-fill"></i><small>Total
                                                Sessions</small><strong><?= number_format($stats['sessions']) ?></strong>
                                        </div>
                                        <div class="metric"><i class="bi bi-people-fill"></i><small>Total
                                                Participants</small><strong><?= number_format($stats['participants']) ?></strong>
                                        </div>
                                        <div class="metric"><i class="bi bi-images"></i><small>Visual
                                                Outputs</small><strong><?= number_format($stats['outputs']) ?></strong>
                                        </div>
                                    </div>
                                    <?php if (!empty($targetSummary)): ?>
                                        <h6 class="fw-bold mb-2">Consolidated Participants by Target Group</h6>
                                        <div class="table-responsive mb-3">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Target Participants</th>
                                                        <th>Sessions</th>
                                                        <th>Participants Reached</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($targetSummary as $t): ?>
                                                        <tr>
                                                            <td><strong><?= e($t['grp']) ?></strong></td>
                                                            <td><?= number_format((int) $t['sessions']) ?></td>
                                                            <td><?= number_format((int) $t['participants']) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!$all): ?>
                                        <div class="empty">No report records available.</div><?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Date</th>
                                                        <th>Ward / Area</th>
                                                        <th>Health Topic</th>
                                                        <th>Target Participants</th>
                                                        <th>Facilitator</th>
                                                        <th>Participants</th>
                                                        <th>Method</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody><?php foreach ($all as $s): ?>
                                                        <tr>
                                                            <td><?= e(date('M d, Y', strtotime($s['session_date']))) ?></td>
                                                            <td><?= e($s['ward_area']) ?></td>
                                                            <td><?= e($s['health_topic']) ?></td>
                                                            <td><?= e(trim((string) ($s['target_participants'] ?? '')) !== '' ? (string) $s['target_participants'] : '—') ?></td>
                                                            <td><?= e($s['facilitator']) ?></td>
                                                            <td><?= number_format((int) $s['participant_count']) ?></td>
                                                            <td><?= e($s['teaching_method']) ?></td>
                                                            <td><span
                                                                    class="status <?= e(whe_status_class($s['status'])) ?>"><?= e($s['status']) ?></span>
                                                            </td>
                                                        </tr><?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div><?php endif; ?>
                                </div>
                            </section>
                        <?php endif; ?>

                    </div>
                </div>
            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($view === 'overview'): ?>
        <script>
            const monthlyCtx = document.getElementById('monthlyChart'); if (monthlyCtx) new Chart(monthlyCtx, { type: 'bar', data: { labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], datasets: [{ data: <?= json_encode(array_values($monthly)) ?>, label: 'Sessions', borderRadius: 6 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
            const topicCtx = document.getElementById('topicChart'); if (topicCtx) new Chart(topicCtx, { type: 'doughnut', data: { labels: <?= json_encode(array_column($topics, 'health_topic'), JSON_UNESCAPED_UNICODE) ?>, datasets: [{ data: <?= json_encode(array_map('intval', array_column($topics, 'total'))) ?> }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'right', labels: { boxWidth: 10, font: { size: 10 } } } } } });
        </script><?php endif; ?>
    <?php if ($view === 'sessions'): ?>
        <script>const q = document.getElementById('search'), t = document.getElementById('sessionTable'); if (q && t) q.addEventListener('input', () => { const v = q.value.toLowerCase(); t.querySelectorAll('tbody tr').forEach(r => r.style.display = r.textContent.toLowerCase().includes(v) ? '' : 'none') });</script>
    <?php endif; ?>
    <?php if ($view === 'new'): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const titleSelect = document.getElementById('wheTitleSelect');
                const titleOtherWrap = document.getElementById('wheTitleOtherWrap');
                const titleOtherInput = document.getElementById('wheTitleOther');

                function syncTitleOther() {
                    if (!titleSelect || !titleOtherWrap) return;
                    const isOthers = titleSelect.value === 'Others';
                    titleOtherWrap.classList.toggle('d-none', !isOthers);
                    if (titleOtherInput) {
                        titleOtherInput.required = isOthers;
                        if (!isOthers) titleOtherInput.value = '';
                    }
                }

                if (titleSelect) {
                    titleSelect.addEventListener('change', syncTitleOther);
                    syncTitleOther();
                }
            });
        </script>
    <?php endif; ?>
</body>

</html>