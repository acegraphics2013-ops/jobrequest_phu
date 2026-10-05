<?php
if (!function_exists('e')) {

    function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}


function postString(string $name): string
{
    return trim((string) ($_POST[$name] ?? ''));
}

function postInt(string $name): int
{
    return max(0, (int) ($_POST[$name] ?? 0));
}

function postBool(string $name): int
{
    return isset($_POST[$name]) ? 1 : 0;
}

function postArray(string $name, array $allowed = []): array
{
    $values = $_POST[$name] ?? [];

    if (!is_array($values)) {
        return [];
    }

    $clean = [];

    foreach ($values as $value) {
        $value = trim((string) $value);

        if ($value === '') {
            continue;
        }

        if ($allowed && !in_array($value, $allowed, true)) {
            continue;
        }

        $clean[] = $value;
    }

    return array_values(array_unique($clean));
}

function jsonArray(array $values): ?string
{
    if (!$values) {
        return null;
    }

    $json = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        throw new RuntimeException('Unable to encode form data.');
    }

    return $json;
}

function decodeArray($value): array
{
    if (!$value) {
        return [];
    }

    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : [];
}

function validDate(string $value): bool
{
    if ($value === '') {
        return false;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

function alertBack(string $message): never
{
    $json = json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    echo "<script>alert($json);history.back();</script>";
    exit;
}

function jobRequestTypeCode(string $requestType): string
{
    return match (trim($requestType)) {
        "Graphic Design" => "GD",
        "Video Production" => "VP",
        "Photo/Video Coverage" => "PVC",
        "IEC Materials" => "IEC",
        default => "GEN"
    };
}

function publicationKindCode(string $kind): string
{
    return match (strtolower(trim($kind))) {
        "event", "activity", "event/activity" => "EVT",
        "recognition", "milestone", "recognition/milestone" => "REC",
        "contribution", "content", "content contribution" => "CON",
        default => "GEN"
    };
}

function publicationDisplayCode(string $kind, int $id): string
{
    // Format: PHU-HPU-JRF-{KIND}-{NNNNN}, derived from the submission id.
    // e.g. PHU-HPU-JRF-EVT-00012. Display-only; no stored column.
    $kindCode = publicationKindCode($kind);

    return 'PHU-HPU-JRF-' . $kindCode . '-' . str_pad((string) max(0, $id), 5, '0', STR_PAD_LEFT);
}

function generateJobNumber(mysqli $conn, string $requestType = ""): string
{
    // Format: PHU-HPU-JRF-{KIND}-{NNNNN}
    // One independent 5-digit sequence per request type that never
    // resets, e.g. PHU-HPU-JRF-GD-00001 (first Graphic Design request).
    // Legacy HPU-{KIND}-{YYYYMMDD}-{NNNN} rows are left untouched;
    // only newly created requests use this series.
    $kindCode = jobRequestTypeCode($requestType);

    $prefix = 'PHU-HPU-JRF-' . $kindCode . '-';
    $pattern = $prefix . '%';

    $stmt = $conn->prepare(
        "SELECT job_no FROM job_requests
         WHERE job_no LIKE ?
         ORDER BY job_no DESC LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('s', $pattern);
    $stmt->execute();
    $result = $stmt->get_result();

    $sequence = 0;

    if ($row = $result->fetch_assoc()) {
        $sequence = (int) substr((string) $row['job_no'], strlen($prefix));
    }

    $stmt->close();

    do {
        $sequence++;
        $jobNo = $prefix . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);

        $check = $conn->prepare("SELECT 1 FROM job_requests WHERE job_no = ? LIMIT 1");
        $check->bind_param('s', $jobNo);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
    } while ($exists);

    return $jobNo;
}

function uploadAttachment(array $file, ?string &$savedPath = null): string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Attachment upload failed.');
    }

    if ((int) $file['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('Attachment must not exceed 10 MB.');
    }

    $allowed = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'ppt',
        'pptx',
        'zip'
    ];

    $original = basename((string) $file['name']);
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowed, true)) {
        throw new RuntimeException('This attachment type is not allowed.');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid uploaded file.');
    }

    $uploadDir = uploadsSubDir();

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to create upload directory.');
    }

    $baseName = pathinfo($original, PATHINFO_FILENAME);
    $baseName = trim((string) preg_replace('/[^A-Za-z0-9_-]/', '_', $baseName), '_');

    if ($baseName === '') {
        $baseName = 'attachment';
    }

    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '_' . $baseName . '.' . $extension;
    $path = $uploadDir . DIRECTORY_SEPARATOR . $stored;

    if (!move_uploaded_file($file['tmp_name'], $path)) {
        throw new RuntimeException('Unable to save attachment.');
    }

    $savedPath = $path;
    return $stored;
}

function attachmentList(string $value): array
{
    if ($value === "") {
        return [];
    }

    $names = array_filter(
        array_map("trim", explode(",", $value)),
        static fn(string $name): bool => $name !== ""
    );

    return array_values($names);
}

function eventRangeSeparator(): string
{
    return " to ";
}

/*
| Split a submitted Event Date & Time value into [start, end].
| Single dates return ["<value>", ""]. Accepts the flatpickr range
| format "Y-m-d\TH:i to Y-m-d\TH:i" (times optional on either side).
*/
function splitEventRange(string $value): array
{
    $value = trim($value);

    if ($value === "") {
        return ["", ""];
    }

    $sep = eventRangeSeparator();
    $pos = strpos($value, $sep);

    if ($pos === false) {
        return [$value, ""];
    }

    return [
        trim(substr($value, 0, $pos)),
        trim(substr($value, $pos + strlen($sep))),
    ];
}

/* Normalize one side of an event range to "Y-m-d H:i" (or "" if invalid). */
function normalizeEventPoint(string $value): string
{
    $value = trim(str_replace("T", " ", $value));

    if ($value === "") {
        return "";
    }

    // Date only: treat as midnight start of that day.
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $value .= " 00:00";
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value)) {
        return "";
    }

    $ts = strtotime($value);

    if ($ts === false) {
        return "";
    }

    // Guard against overflow dates like 2026-02-30.
    if (date("Y-m-d H:i", $ts) !== substr($value, 0, 16)) {
        return "";
    }

    return date("Y-m-d H:i", $ts);
}

/*
| Display an event start/end pair, e.g. "September 7, 2026 8:00 AM"
| or "September 7, 2026 8:00 AM → September 8, 2026 5:00 PM".
| Falls back to "Not specified" when start is empty/invalid.
*/
function formatEventRange($start, $end = null): string
{
    $startText = trim(str_replace("T", " ", (string) ($start ?? "")));
    $endText = trim(str_replace("T", " ", (string) ($end ?? "")));

    $startTs = $startText !== "" ? strtotime($startText) : false;

    if ($startTs === false) {
        return "Not specified";
    }

    $label = date("F j, Y g:i A", $startTs);
    $endTs = $endText !== "" ? strtotime($endText) : false;

    if ($endTs !== false && $endTs > $startTs) {
        $label .= " → " . date("F j, Y g:i A", $endTs);
    }

    return $label;
}

function dueDateLabel(?string $date): array
{
    if (!$date) {
        return [
            "text" => "No deadline",
            "class" => "text-muted"
        ];
    }

    $today = new DateTime("today");
    $due = new DateTime($date);
    $days = (int) $today->diff($due)->format("%r%a");

    if ($days < 0) {
        return [
            "text" => "Overdue by " . abs($days) . " day(s)",
            "class" => "text-danger"
        ];
    }

    if ($days === 0) {
        return [
            "text" => "Due today",
            "class" => "text-danger"
        ];
    }

    if ($days === 1) {
        return [
            "text" => "Due tomorrow",
            "class" => "text-warning"
        ];
    }

    return [
        "text" => "Due in {$days} days",
        "class" => "text-success"
    ];
}

if (!function_exists('updateUserActivity')) {
    function updateUserActivity(mysqli $conn, int $userId): void {
        if ($userId <= 0) return;
        $lastUpdate = $_SESSION['last_activity_update'] ?? 0;
        $now = time();
        if ($now - $lastUpdate > 15) {
            $_SESSION['last_activity_update'] = $now;
            try {
                // Ensure last_seen column exists
                static $columnChecked = false;
                if (!$columnChecked) {
                    $columnChecked = true;
                    try {
                        $conn->query("ALTER TABLE users ADD COLUMN last_seen TIMESTAMP NULL DEFAULT NULL");
                    } catch (Throwable $e) {}
                }
                $stmt = $conn->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                    $stmt->close();
                }
            } catch (Throwable $e) {}
        }
    }
}