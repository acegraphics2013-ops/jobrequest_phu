<?php
function hpu_e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function hpu_s(string $k): string { return trim((string)($_POST[$k] ?? '')); }
function hpu_i(string $k): int { return max(0, (int)($_POST[$k] ?? 0)); }
function hpu_b(string $k): int { return isset($_POST[$k]) ? 1 : 0; }
function hpu_a(string $k, array $allowed = []): array {
    $v = $_POST[$k] ?? [];
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $x) {
        $x = trim((string)$x);
        if ($x === '') continue;
        if ($allowed && !in_array($x, $allowed, true)) continue;
        $out[] = $x;
    }
    return array_values(array_unique($out));
}
function hpu_json(array $v): ?string {
    return $v ? json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
}
function hpu_decode($v): array {
    $d = json_decode((string)$v, true);
    return is_array($d) ? $d : [];
}
function hpu_alert(string $m): never {
    $j = json_encode($m, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
    echo "<script>alert($j);history.back();</script>";
    exit;
}
function hpu_upload(array $f, ?string &$saved = null): string {
    if (!isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) return '';
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Attachment upload failed.');
    if ((int)$f['size'] > 10*1024*1024) throw new RuntimeException('Attachment exceeds 10 MB.');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','ppt','pptx','zip'];
    if (!in_array($ext, $allowed, true)) throw new RuntimeException('File type is not allowed.');
    $dir = uploadsSubDir();
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $base = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo($f['name'], PATHINFO_FILENAME));
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '_' . $base . '.' . $ext;
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($f['tmp_name'], $path)) throw new RuntimeException('Could not save attachment.');
    $saved = $path;
    return $name;
}
