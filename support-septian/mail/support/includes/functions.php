<?php
/**
 * Support Septian — Helper Functions, Sanitization, Attachment Validation & Safe Logging
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

/**
 * Escape string untuk output HTML yang aman dari XSS.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Redaksi token, secret, dan password agar tidak pernah bocor ke log.
 */
function redact_sensitive_text(string $text): string
{
    $patterns = [
        '/(ya29\.[A-Za-z0-9\-_]+)/i',
        '/(1\/\/[A-Za-z0-9\-_]+)/i',
        '/(GOCSPX-[A-Za-z0-9\-_]+)/i',
        '/(password|secret|access_token|refresh_token|authorization)\s*[:=]\s*[^\s,;]+/i',
    ];
    return (string) preg_replace($patterns, '[REDACTED]', $text);
}

/**
 * Tulis log aplikasi aman ke storage/logs/app.log.
 */
function app_log(string $level, string $message, array $context = []): void
{
    if (!is_dir(LOGS_PATH)) {
        @mkdir(LOGS_PATH, 0750, true);
    }

    $logFile = LOGS_PATH . '/app-' . date('Y-m-d') . '.log';
    $safeMsg = redact_sensitive_text($message);

    $safeContext = [];
    foreach ($context as $k => $v) {
        $kl = strtolower((string) $k);
        if (str_contains($kl, 'token') || str_contains($kl, 'secret') || str_contains($kl, 'password')) {
            $safeContext[$k] = '[REDACTED]';
        } else {
            $safeContext[$k] = is_scalar($v) ? redact_sensitive_text((string) $v) : '[COMPLEX]';
        }
    }

    $line = sprintf(
        "[%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $safeMsg,
        $safeContext ? json_encode($safeContext, JSON_UNESCAPED_SLASHES) : ''
    );
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Kirim respons JSON konsisten dari endpoint /api/*.
 */
function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Baca body JSON request dengan aman.
 */
function read_json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Set & ambil flash message sesi.
 */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash_messages'][] = [
        'type'    => $type,
        'message' => $message,
    ];
}

function pull_flashes(): array
{
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return is_array($messages) ? $messages : [];
}

/**
 * Redirect aman dalam aplikasi.
 */
function redirect_to(string $relativePath): never
{
    header('Location: ' . base_url($relativePath));
    exit;
}

/**
 * Validasi daftar alamat email (dipisahkan koma atau titik koma).
 * Mengembalikan array email valid.
 */
function parse_email_list(string $input): array
{
    $parts = preg_split('/[,;\n]+/', $input) ?: [];
    $valid = [];
    foreach ($parts as $part) {
        $trimmed = trim($part);
        if ($trimmed === '') {
            continue;
        }
        // Ekstrak email jika format "Nama <email@domain.com>"
        if (preg_match('/<([^>]+)>/', $trimmed, $m)) {
            $email = trim($m[1]);
        } else {
            $email = $trimmed;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $valid[] = strtolower($email);
        }
    }
    return array_values(array_unique($valid));
}

/**
 * Parse header "Nama <email@domain.com>" menjadi ['name' => ..., 'email' => ...].
 */
function parse_sender_header(string $headerValue): array
{
    $headerValue = trim($headerValue);
    if (preg_match('/^(.*?)<([^>]+)>$/', $headerValue, $matches)) {
        $name  = trim($matches[1], " \t\n\r\0\x0B\"'");
        $email = trim($matches[2]);
        return [
            'name'  => $name !== '' ? $name : $email,
            'email' => strtolower($email),
        ];
    }

    return [
        'name'  => $headerValue,
        'email' => strtolower($headerValue),
    ];
}

/**
 * Format tanggal bahasa Indonesia (WIB).
 */
function format_tanggal_id(?string $dateInput, bool $withTime = true): string
{
    if (!$dateInput) {
        return '-';
    }

    $timestamp = is_numeric($dateInput) ? (int) $dateInput : strtotime($dateInput);
    if ($timestamp === false) {
        return $dateInput;
    }

    $bulan = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    $day   = date('d', $timestamp);
    $month = $bulan[(int) date('n', $timestamp)] ?? date('M', $timestamp);
    $year  = date('Y', $timestamp);

    if ($withTime) {
        return sprintf('%s %s %s, %s WIB', $day, $month, $year, date('H:i', $timestamp));
    }
    return sprintf('%s %s %s', $day, $month, $year);
}

/**
 * Format tanggal ringkas untuk daftar inbox.
 */
function format_short_date(?string $dateInput): string
{
    if (!$dateInput) {
        return '';
    }
    $ts = is_numeric($dateInput) ? (int) $dateInput : strtotime($dateInput);
    if ($ts === false) {
        return $dateInput;
    }

    if (date('Y-m-d', $ts) === date('Y-m-d')) {
        return date('H:i', $ts);
    }
    if (date('Y', $ts) === date('Y')) {
        return format_tanggal_id((string) $ts, false);
    }
    return date('d/m/Y', $ts);
}

/**
 * Format ukuran byte menjadi teks yang mudah dibaca.
 */
function format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 2) . ' MB';
}

/**
 * Validasi upload lampiran email (ekstensi, MIME finfo, batas ukuran).
 * Mengembalikan array lampiran siap kirim: [['name', 'mime', 'content', 'size'], ...]
 */
function validate_uploaded_attachments(array $filesInput): array
{
    $allowedExtensions = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'rar', '7z',
    ];

    $allowedMimePrefixes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument',
        'application/vnd.ms-excel',
        'application/vnd.ms-powerpoint',
        'application/zip',
        'application/x-zip-compressed',
        'application/x-rar',
        'application/vnd.rar',
        'application/x-7z-compressed',
        'application/octet-stream',
        'text/plain',
        'text/csv',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    $attachments = [];
    if (empty($filesInput) || empty($filesInput['name'])) {
        return $attachments;
    }

    $names    = is_array($filesInput['name']) ? $filesInput['name'] : [$filesInput['name']];
    $tmpNames = is_array($filesInput['tmp_name']) ? $filesInput['tmp_name'] : [$filesInput['tmp_name']];
    $errors   = is_array($filesInput['error']) ? $filesInput['error'] : [$filesInput['error']];
    $sizes    = is_array($filesInput['size']) ? $filesInput['size'] : [$filesInput['size']];

    $totalSize = 0;
    $finfo     = new finfo(FILEINFO_MIME_TYPE);

    foreach ($names as $i => $rawName) {
        $err = (int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Gagal mengunggah lampiran: ' . basename((string) $rawName));
        }

        $tmp  = (string) ($tmpNames[$i] ?? '');
        $size = (int) ($sizes[$i] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('File unggahan tidak valid.');
        }

        if ($size <= 0 || $size > MAX_ATTACHMENT_SIZE) {
            throw new RuntimeException('Ukuran lampiran melebihi batas 15 MB: ' . basename((string) $rawName));
        }

        $totalSize += $size;
        if ($totalSize > MAX_TOTAL_ATTACHMENTS_SIZE) {
            throw new RuntimeException('Total ukuran seluruh lampiran melebihi batas 25 MB.');
        }

        $safeName = preg_replace('/[^A-Za-z0-9._\- ]/', '_', basename((string) $rawName)) ?: 'lampiran.bin';
        $ext      = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) {
            throw new RuntimeException('Ekstensi file tidak diizinkan demi keamanan: .' . $ext);
        }

        $detectedMime = (string) $finfo->file($tmp);
        $mimeAllowed  = false;
        foreach ($allowedMimePrefixes as $prefix) {
            if (str_starts_with($detectedMime, $prefix)) {
                $mimeAllowed = true;
                break;
            }
        }
        if (!$mimeAllowed) {
            throw new RuntimeException('Tipe MIME file tidak diizinkan: ' . $detectedMime);
        }

        $content = file_get_contents($tmp);
        if ($content === false) {
            throw new RuntimeException('Gagal membaca konten file lampiran.');
        }

        $attachments[] = [
            'name'    => $safeName,
            'mime'    => $detectedMime,
            'size'    => $size,
            'content' => $content,
        ];
    }

    return $attachments;
}

/**
 * Validasi URL agar hanya menerima http:// atau https:// (mencegah javascript:).
 */
function safe_http_url(string $url, string $fallback = DEFAULT_WEBSITE_URL): string
{
    $url = trim($url);
    if ($url === '') {
        return $fallback;
    }
    $parsed = parse_url($url);
    $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return $fallback;
    }
    return $url;
}

/**
 * Buat slug URL-friendly dari nama template.
 */
function slugify(string $text): string
{
    $slug = strtolower(trim($text));
    $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'template-' . time();
}
