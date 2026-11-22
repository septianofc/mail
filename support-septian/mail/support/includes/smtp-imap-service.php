<?php
/**
 * Support Septian — Google App Password Mail Transport (PHPMailer SMTP SSL:465 + PHP IMAP)
 *
 * Seluruh pengiriman SMTP menggunakan PHPMailer terautentikasi ke smtp.gmail.com:465 (SSL),
 * bukan PHP mail(), sendmail, atau SMTP lokal hosting.
 * Penandatanganan DKIM untuk support.septianfr@gmail.com ditangani otomatis oleh server Google.
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Cek apakah library PHPMailer (via Composer) sudah terpasang.
 */
function is_phpmailer_installed(): bool
{
    return class_exists(\PHPMailer\PHPMailer\PHPMailer::class)
        && class_exists(\PHPMailer\PHPMailer\SMTP::class);
}

/**
 * Ambil kredensial App Password resmi.
 * Username SMTP & From Email dikunci ketat ke support.septianfr@gmail.com agar DKIM Gmail valid.
 */
function app_password_credentials(?string $overrideEmail = null, ?string $overridePassword = null, bool $requirePassword = true): array
{
    $officialEmail = strtolower(DEFAULT_OFFICIAL_GMAIL);
    $email         = strtolower(trim($overrideEmail ?? get_setting('gmail_app_password_email', $officialEmail)));
    $password      = str_replace(' ', '', trim($overridePassword ?? get_setting('gmail_app_password_secret', '')));

    if ($email === '') {
        $email = $officialEmail;
    }

    if ($email !== $officialEmail) {
        throw new RuntimeException(
            'Username SMTP dan From Email wajib sama persis dengan akun resmi ' . $officialEmail . '.'
        );
    }

    if ($requirePassword && $password === '') {
        throw new RuntimeException('Google App Password belum diisi pada halaman Pengaturan / Koneksi Gmail.');
    }

    return [
        'email'      => $officialEmail,
        'from_name'  => DEFAULT_OFFICIAL_NAME,
        'password'   => $password,
        'smtp_host'  => 'smtp.gmail.com',
        'smtp_port'  => 465,
        'smtp_enc'   => 'ssl',
        'smtp_auth'  => true,
        'imap_host'  => get_setting('gmail_imap_host', 'imap.gmail.com') ?: 'imap.gmail.com',
        'imap_port'  => (int) (get_setting('gmail_imap_port', '993') ?: 993),
    ];
}

/**
 * Sanitasi log debug SMTP agar password / token base64 AUTH tidak pernah muncul.
 */
function sanitize_smtp_debug_line(string $line, string $secretPassword = ''): string
{
    $clean = trim($line);
    if ($secretPassword !== '') {
        $clean = str_replace($secretPassword, '[REDACTED]', $clean);
        $clean = str_replace(base64_encode($secretPassword), '[REDACTED_B64]', $clean);
        $plainAuth = base64_encode("\0" . DEFAULT_OFFICIAL_GMAIL . "\0" . $secretPassword);
        $clean = str_replace($plainAuth, '[REDACTED_AUTH]', $clean);
    }
    return redact_sensitive_text($clean);
}

/**
 * Buat instance PHPMailer yang terkonfigurasi ketat untuk SMTP Gmail (SSL port 465, SMTPAuth=true).
 */
function phpmailer_create_configured(array $creds, bool $captureDebug = false, array &$debugLog = []): \PHPMailer\PHPMailer\PHPMailer
{
    if (!is_phpmailer_installed()) {
        throw new RuntimeException(
            'Library PHPMailer belum terpasang di vendor/. Jalankan "composer install --no-dev --optimize-autoloader" lalu unggah folder mail/support/vendor/ ke hosting.'
        );
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    // Pastikan menggunakan SMTP Gmail terautentikasi — bukan mail(), sendmail, atau qmail
    $mail->isSMTP();
    $mail->Host        = 'smtp.gmail.com';
    $mail->Port        = 465;
    $mail->SMTPSecure  = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS; // SSL
    $mail->SMTPAutoTLS = false;
    $mail->SMTPAuth    = true;
    $mail->Username    = DEFAULT_OFFICIAL_GMAIL;
    $mail->Password    = $creds['password'];
    $mail->Timeout     = 25;
    $mail->Hostname    = 'gmail.com';
    $mail->Helo        = 'septianfr.web.id';
    $mail->XMailer     = 'Support Septian Mailer';

    // Pastikan tidak ada DKIM manual yang tidak valid (DKIM ditangani server Google)
    $mail->DKIM_domain         = '';
    $mail->DKIM_private        = '';
    $mail->DKIM_private_string = '';
    $mail->DKIM_selector       = '';
    $mail->DKIM_passphrase     = '';

    // Bersihkan header/penerima agar tidak pernah terjadi duplikasi header From
    $mail->clearAllRecipients();
    $mail->clearReplyTos();
    $mail->clearAttachments();
    $mail->clearCustomHeaders();

    // Set From, Sender (Return-Path), dan Reply-To identik ke akun resmi
    $mail->CharSet  = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
    $mail->Encoding = \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;
    $mail->setFrom(DEFAULT_OFFICIAL_GMAIL, DEFAULT_OFFICIAL_NAME, true);
    $mail->Sender = DEFAULT_OFFICIAL_GMAIL;
    $mail->addReplyTo(DEFAULT_OFFICIAL_GMAIL, DEFAULT_OFFICIAL_NAME);

    if ($captureDebug) {
        $secret = $creds['password'];
        $mail->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
        $mail->Debugoutput = static function (string $str, int $level) use (&$debugLog, $secret): void {
            $safe = sanitize_smtp_debug_line($str, $secret);
            if ($safe !== '') {
                $debugLog[] = $safe;
            }
        };
    } else {
        $mail->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_OFF;
    }

    return $mail;
}

/**
 * Kirim email melalui PHPMailer SMTP Gmail terautentikasi (smtp.gmail.com:465 SSL).
 */
function phpmailer_send_email(array $params, bool $captureDebug = false): array
{
    $creds    = app_password_credentials();
    $debugLog = [];
    $mail     = phpmailer_create_configured($creds, $captureDebug, $debugLog);

    $toList      = $params['to'] ?? [];
    $ccList      = $params['cc'] ?? [];
    $bccList     = $params['bcc'] ?? [];
    $subject     = (string) ($params['subject'] ?? '(Tanpa Subjek)');
    $htmlContent = (string) ($params['html'] ?? '');
    $plainText   = (string) ($params['plain'] ?? html_to_plain_text($htmlContent));
    $inReplyTo   = trim((string) ($params['in_reply_to'] ?? ''));
    $references  = trim((string) ($params['references'] ?? ''));
    $attachments = $params['attachments'] ?? [];

    foreach ($toList as $rcpt) {
        $mail->addAddress($rcpt);
    }
    foreach ($ccList as $cc) {
        $mail->addCC($cc);
    }
    foreach ($bccList as $bcc) {
        $mail->addBCC($bcc);
    }

    if ($inReplyTo !== '') {
        $mail->addCustomHeader('In-Reply-To', str_replace(["\r", "\n"], '', $inReplyTo));
    }
    if ($references !== '') {
        $mail->addCustomHeader('References', str_replace(["\r", "\n"], '', $references));
    }

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $htmlContent;
    $mail->AltBody = $plainText;

    foreach ($attachments as $att) {
        $safeFilename = str_replace(['"', "\r", "\n"], '_', (string) $att['name']);
        $mimeType     = (string) ($att['mime'] ?: 'application/octet-stream');
        $mail->addStringAttachment(
            (string) $att['content'],
            $safeFilename,
            \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64,
            $mimeType
        );
    }

    try {
        $mail->send();
        $sentMessageId = $mail->getLastMessageID();

        return [
            'id'                 => $sentMessageId !== '' ? $sentMessageId : 'smtp_' . bin2hex(random_bytes(8)),
            'thread_id'          => '',
            'transport'          => 'phpmailer_gmail_smtp',
            'smtp_connected'     => true,
            'smtp_authenticated' => true,
            'smtp_host'          => 'smtp.gmail.com',
            'smtp_port'          => 465,
            'smtp_encryption'    => 'SSL (SMTPS)',
            'smtp_auth'          => true,
            'account_email'      => DEFAULT_OFFICIAL_GMAIL,
            'from_email'         => DEFAULT_OFFICIAL_GMAIL,
            'from_name'          => DEFAULT_OFFICIAL_NAME,
            'sender'             => DEFAULT_OFFICIAL_GMAIL,
            'reply_to'           => DEFAULT_OFFICIAL_GMAIL,
            'message_id'         => $sentMessageId,
            'headers_verified'   => [
                'From'         => DEFAULT_OFFICIAL_NAME . ' <' . DEFAULT_OFFICIAL_GMAIL . '>',
                'Sender'       => DEFAULT_OFFICIAL_GMAIL,
                'Reply-To'     => DEFAULT_OFFICIAL_NAME . ' <' . DEFAULT_OFFICIAL_GMAIL . '>',
                'Return-Path'  => DEFAULT_OFFICIAL_GMAIL,
                'Message-ID'   => $sentMessageId,
                'MIME-Version' => '1.0',
                'Content-Type' => !empty($attachments) ? 'multipart/mixed' : 'multipart/alternative',
            ],
            'smtp_transcript'    => $captureDebug ? implode("\n", array_slice($debugLog, -24)) : '',
        ];
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        $safeError = sanitize_smtp_debug_line($mail->ErrorInfo ?: $e->getMessage(), $creds['password']);
        app_log('error', 'PHPMailer SMTP send gagal: ' . $safeError);
        throw new RuntimeException('Pengiriman via SMTP Gmail gagal: ' . $safeError);
    }
}

/**
 * Bangun dokumen email RFC 2822 menggunakan PHPMailer (untuk Gmail API OAuth & Drafts)
 * sehingga seluruh header From, Sender, Reply-To, Message-ID, MIME-Version, dan Content-Type konsisten.
 */
function phpmailer_build_raw_mime(array $params): string
{
    if (!is_phpmailer_installed()) {
        return '';
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Hostname = 'gmail.com';
    $mail->XMailer  = 'Support Septian Mailer';
    $mail->CharSet  = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
    $mail->Encoding = \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;
    $mail->DKIM_domain = '';
    $mail->DKIM_private = '';
    $mail->DKIM_selector = '';

    $mail->setFrom(DEFAULT_OFFICIAL_GMAIL, DEFAULT_OFFICIAL_NAME, true);
    $mail->Sender = DEFAULT_OFFICIAL_GMAIL;
    $mail->addReplyTo(DEFAULT_OFFICIAL_GMAIL, DEFAULT_OFFICIAL_NAME);

    foreach (($params['to'] ?? []) as $rcpt) {
        $mail->addAddress($rcpt);
    }
    foreach (($params['cc'] ?? []) as $cc) {
        $mail->addCC($cc);
    }
    foreach (($params['bcc'] ?? []) as $bcc) {
        $mail->addBCC($bcc);
    }

    $inReplyTo  = trim((string) ($params['in_reply_to'] ?? ''));
    $references = trim((string) ($params['references'] ?? ''));
    if ($inReplyTo !== '') {
        $mail->addCustomHeader('In-Reply-To', str_replace(["\r", "\n"], '', $inReplyTo));
    }
    if ($references !== '') {
        $mail->addCustomHeader('References', str_replace(["\r", "\n"], '', $references));
    }

    $htmlContent = (string) ($params['html'] ?? '');
    $plainText   = (string) ($params['plain'] ?? html_to_plain_text($htmlContent));

    $mail->isHTML(true);
    $mail->Subject = (string) ($params['subject'] ?? '(Tanpa Subjek)');
    $mail->Body    = $htmlContent;
    $mail->AltBody = $plainText;

    foreach (($params['attachments'] ?? []) as $att) {
        $safeFilename = str_replace(['"', "\r", "\n"], '_', (string) $att['name']);
        $mimeType     = (string) ($att['mime'] ?: 'application/octet-stream');
        $mail->addStringAttachment(
            (string) $att['content'],
            $safeFilename,
            \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64,
            $mimeType
        );
    }

    $mail->preSend();
    return $mail->getSentMIMEMessage();
}

/**
 * Jalankan diagnostik lengkap koneksi & autentikasi SMTP Gmail (PHPMailer) + IMAP.
 * Tidak pernah mengembalikan atau mencatat password.
 */
function run_smtp_diagnostics(?string $overrideEmail = null, ?string $overridePassword = null): array
{
    $mode             = get_setting('mail_connection_mode', 'oauth');
    $phpmailerReady   = is_phpmailer_installed();
    $officialEmail    = strtolower(DEFAULT_OFFICIAL_GMAIL);
    $emailInput       = strtolower(trim($overrideEmail ?? get_setting('gmail_app_password_email', $officialEmail)));
    $password         = str_replace(' ', '', trim($overridePassword ?? get_setting('gmail_app_password_secret', '')));

    $diag = [
        'ok'                 => false,
        'smtp'               => false,
        'imap'               => false,
        'smtp_connected'     => false,
        'smtp_authenticated' => false,
        'phpmailer_installed'=> $phpmailerReady,
        'account_email'      => $officialEmail,
        'from_email'         => $officialEmail,
        'from_name'          => DEFAULT_OFFICIAL_NAME,
        'transport_method'   => $mode === 'app_password'
            ? 'PHPMailer SMTP (smtp.gmail.com:465 · SSL · SMTPAuth=true)'
            : 'Google Gmail API (OAuth 2.0) — Fallback Diagnostik SMTP Tersedia',
        'smtp_host'          => 'smtp.gmail.com',
        'smtp_port'          => 465,
        'smtp_encryption'    => 'SSL (SMTPS)',
        'smtp_auth'          => true,
        'smtp_error'         => '',
        'smtp_transcript'    => '',
        'message'            => '',
    ];

    if ($emailInput !== '' && $emailInput !== $officialEmail) {
        $diag['smtp_error'] = 'Email akun tidak sesuai. Wajib menggunakan ' . $officialEmail . '.';
        $diag['message']    = $diag['smtp_error'];
        return $diag;
    }

    if (!$phpmailerReady) {
        $diag['smtp_error'] = 'PHPMailer belum terpasang di folder vendor/. Jalankan composer install --no-dev --optimize-autoloader.';
        $diag['message']    = $diag['smtp_error'];
        return $diag;
    }

    if ($password === '') {
        $diag['smtp_error'] = 'Google App Password belum diisi.';
        $diag['message']    = $diag['smtp_error'];
        return $diag;
    }

    $debugLines = [];
    $smtp = new \PHPMailer\PHPMailer\SMTP();
    $smtp->do_debug = \PHPMailer\PHPMailer\SMTP::DEBUG_CONNECTION;
    $smtp->Debugoutput = static function (string $str, int $level) use (&$debugLines, $password): void {
        $safe = sanitize_smtp_debug_line($str, $password);
        if ($safe !== '') {
            $debugLines[] = $safe;
        }
    };

    try {
        if (!$smtp->connect('ssl://smtp.gmail.com', 465, 20)) {
            $lastErr = $smtp->getError();
            throw new RuntimeException('Gagal terhubung ke ssl://smtp.gmail.com:465 — ' . ($lastErr['error'] ?? 'Koneksi ditolak'));
        }
        $diag['smtp_connected'] = true;

        if (!$smtp->hello('septianfr.web.id')) {
            $lastErr = $smtp->getError();
            throw new RuntimeException('EHLO ke smtp.gmail.com gagal: ' . ($lastErr['smtp_msg'] ?? $lastErr['error'] ?? 'Unknown'));
        }

        if (!$smtp->authenticate($officialEmail, $password)) {
            $lastErr = $smtp->getError();
            throw new RuntimeException(
                'Autentikasi SMTP ditolak oleh Gmail (' . ($lastErr['smtp_code'] ?? '535') . '): ' .
                ($lastErr['smtp_msg'] ?? $lastErr['error'] ?? 'Periksa 2-Step Verification & 16 digit Google App Password.')
            );
        }

        $diag['smtp_authenticated'] = true;
        $diag['smtp']               = true;
        $diag['ok']                 = true;
        $smtp->quit();
    } catch (Throwable $e) {
        $diag['smtp_error'] = sanitize_smtp_debug_line($e->getMessage(), $password);
    } finally {
        if ($smtp->connected()) {
            $smtp->close();
        }
    }

    $diag['smtp_transcript'] = implode("\n", array_slice($debugLines, -20));

    $details = [];
    if ($diag['smtp_authenticated']) {
        $details[] = 'PHPMailer terhubung dan terautentikasi ke smtp.gmail.com:465 (SSL) sebagai ' . $officialEmail . '.';
    } else {
        $details[] = $diag['smtp_error'] ?: 'Koneksi SMTP Gmail gagal.';
    }

    // Cek IMAP opsional
    if (function_exists('imap_open')) {
        $imapHost = get_setting('gmail_imap_host', 'imap.gmail.com') ?: 'imap.gmail.com';
        $imapPort = (int) (get_setting('gmail_imap_port', '993') ?: 993);
        $mailbox  = sprintf('{%s:%d/imap/ssl}INBOX', $imapHost, $imapPort);
        $imap     = @imap_open($mailbox, $officialEmail, $password, OP_READONLY, 1);
        if ($imap) {
            $diag['imap'] = true;
            imap_close($imap);
            $details[] = 'IMAP Gmail terhubung.';
        } else {
            $details[] = 'IMAP belum terhubung (pastikan IMAP aktif di Gmail).';
        }
    } else {
        $details[] = 'Ekstensi php-imap tidak aktif di server (pengiriman PHPMailer SMTP tetap berfungsi penuh).';
    }

    $diag['message'] = implode(' ', $details);
    return $diag;
}

/**
 * Wrapper kompatibilitas untuk pengujian koneksi App Password.
 */
function test_google_app_password_connection(?string $overrideEmail = null, ?string $overridePassword = null): array
{
    return run_smtp_diagnostics($overrideEmail, $overridePassword);
}

/**
 * Pemetaan nama folder aplikasi ke nama mailbox IMAP Gmail.
 */
function imap_folder_name(string $folder): string
{
    return match (strtolower($folder)) {
        'sent'    => '[Gmail]/Sent Mail',
        'drafts'  => '[Gmail]/Drafts',
        'starred' => '[Gmail]/Starred',
        'spam'    => '[Gmail]/Spam',
        'trash'   => '[Gmail]/Trash',
        'all'     => '[Gmail]/All Mail',
        default   => 'INBOX',
    };
}

/**
 * Buka koneksi IMAP ke folder Gmail tertentu.
 */
function imap_connect_folder(string $folder = 'inbox')
{
    if (!function_exists('imap_open')) {
        throw new RuntimeException(
            'Ekstensi PHP IMAP belum aktif di server hosting. Aktifkan ekstensi php-imap di cPanel atau beralih ke mode Google Gmail API (OAuth 2.0).'
        );
    }

    $creds   = app_password_credentials();
    $mailbox = sprintf('{%s:%d/imap/ssl}%s', $creds['imap_host'], $creds['imap_port'], imap_folder_name($folder));
    $stream  = @imap_open($mailbox, $creds['email'], $creds['password'], 0, 1);
    if (!$stream) {
        $err = imap_last_error() ?: 'Autentikasi IMAP gagal.';
        throw new RuntimeException('Gagal membuka folder Gmail via IMAP: ' . redact_sensitive_text($err));
    }
    return $stream;
}

/**
 * Dekode header MIME IMAP menjadi UTF-8 bersih.
 */
function imap_decode_header_str(string $value): string
{
    $decoded = imap_mime_header_decode($value);
    $result = '';
    foreach ($decoded as $part) {
        $charset = strtoupper((string) ($part->charset ?? 'UTF-8'));
        $text    = (string) ($part->text ?? '');
        if ($charset !== 'DEFAULT' && $charset !== 'UTF-8' && function_exists('mb_convert_encoding')) {
            $text = @mb_convert_encoding($text, 'UTF-8', $charset) ?: $text;
        }
        $result .= $text;
    }
    return trim($result);
}

/**
 * Daftar pesan melalui IMAP (ketika mode Google App Password dipilih).
 */
function imap_list_messages(string $folder, int $maxResults = 20, ?string $pageToken = null, string $searchQuery = ''): array
{
    $stream = imap_connect_folder($folder);
    try {
        $criteria = 'ALL';
        if (trim($searchQuery) !== '') {
            $cleanQ = str_replace('"', '', trim($searchQuery));
            $criteria = 'TEXT "' . $cleanQ . '"';
        }

        $uids = imap_search($stream, $criteria, SE_UID) ?: [];
        rsort($uids);

        $total  = count($uids);
        $offset = max(0, (int) ($pageToken ?: 0));
        $slice  = array_slice($uids, $offset, $maxResults);
        $nextPageToken = ($offset + $maxResults < $total) ? (string) ($offset + $maxResults) : null;

        $messages = [];
        foreach ($slice as $uid) {
            $overviewList = imap_fetch_overview($stream, (string) $uid, FT_UID);
            $ov = $overviewList[0] ?? null;
            if (!$ov) {
                continue;
            }
            $fromRaw = imap_decode_header_str((string) ($ov->from ?? ''));
            $from    = parse_sender_header($fromRaw);
            $subject = imap_decode_header_str((string) ($ov->subject ?? '(Tanpa Subjek)'));
            $dateStr = (string) ($ov->date ?? '');

            $messages[] = [
                'id'              => $folder . ':' . $uid,
                'thread_id'       => $folder . ':' . $uid,
                'from_name'       => $from['name'],
                'from_email'      => $from['email'],
                'to'              => imap_decode_header_str((string) ($ov->to ?? '')),
                'subject'         => $subject !== '' ? $subject : '(Tanpa Subjek)',
                'snippet'         => '',
                'date_raw'        => $dateStr,
                'date_short'      => format_short_date($dateStr),
                'date_full'       => format_tanggal_id($dateStr, true),
                'is_unread'       => empty($ov->seen),
                'is_starred'      => !empty($ov->flagged),
                'has_attachments' => false,
                'labels'          => [strtoupper($folder)],
            ];
        }

        return [
            'messages'        => $messages,
            'next_page_token' => $nextPageToken,
            'result_estimate' => $total,
            'transport'       => 'app_password_imap',
        ];
    } finally {
        imap_close($stream);
    }
}

/**
 * Baca detail pesan tunggal via IMAP.
 */
function imap_get_message_detail(string $compositeId): array
{
    $folder = 'inbox';
    $uid    = $compositeId;
    if (str_contains($compositeId, ':')) {
        [$folder, $uid] = explode(':', $compositeId, 2);
    }

    $stream = imap_connect_folder($folder);
    try {
        $msgNo = imap_msgno($stream, (int) $uid);
        if ($msgNo <= 0) {
            throw new RuntimeException('Email tidak ditemukan di folder IMAP.');
        }

        $header    = imap_headerinfo($stream, $msgNo);
        $structure = imap_fetchstructure($stream, (int) $uid, FT_UID);
        $fromRaw   = imap_decode_header_str((string) ($header->fromaddress ?? ''));
        $from      = parse_sender_header($fromRaw);
        $toRaw     = imap_decode_header_str((string) ($header->toaddress ?? ''));
        $ccRaw     = imap_decode_header_str((string) ($header->ccaddress ?? ''));
        $subject   = imap_decode_header_str((string) ($header->subject ?? '(Tanpa Subjek)'));
        $dateRaw   = (string) ($header->date ?? '');
        $msgIdHdr  = (string) ($header->message_id ?? '');

        $htmlBody    = '';
        $plainBody   = '';
        $attachments = [];

        $decodePart = function (string $data, int $encoding): string {
            return match ($encoding) {
                3 => base64_decode($data) ?: '',
                4 => quoted_printable_decode($data),
                default => $data,
            };
        };

        $extractParts = function ($struct, string $partNum = '') use ($stream, $uid, $folder, &$htmlBody, &$plainBody, &$attachments, $decodePart, &$extractParts): void {
            if (!empty($struct->parts)) {
                foreach ($struct->parts as $idx => $sub) {
                    $num = $partNum === '' ? (string) ($idx + 1) : ($partNum . '.' . ($idx + 1));
                    $extractParts($sub, $num);
                }
                return;
            }

            $section = $partNum === '' ? '1' : $partNum;
            $rawData = imap_fetchbody($stream, (int) $uid, $section, FT_UID | FT_PEEK);
            $content = $decodePart((string) $rawData, (int) ($struct->encoding ?? 0));

            $filename = '';
            foreach (['dparameters', 'parameters'] as $paramField) {
                if (!empty($struct->$paramField)) {
                    foreach ($struct->$paramField as $p) {
                        if (in_array(strtolower((string) $p->attribute), ['filename', 'name'], true)) {
                            $filename = imap_decode_header_str((string) $p->value);
                        }
                    }
                }
            }

            if ($filename !== '') {
                $attachments[] = [
                    'attachment_id' => $section,
                    'message_id'    => $folder . ':' . $uid,
                    'filename'      => $filename,
                    'mime_type'     => strtolower((string) ($struct->subtype ?? 'octet-stream')),
                    'size'          => (int) ($struct->bytes ?? strlen($content)),
                ];
                return;
            }

            $subtype = strtoupper((string) ($struct->subtype ?? 'PLAIN'));
            if ($subtype === 'HTML' && $htmlBody === '') {
                $htmlBody = $content;
            } elseif ($subtype === 'PLAIN' && $plainBody === '') {
                $plainBody = $content;
            }
        };

        $extractParts($structure);

        // Tandai sudah dibaca
        @imap_setflag_full($stream, (string) $uid, '\\Seen', ST_UID);

        $safeHtml = $htmlBody !== ''
            ? sanitize_email_html($htmlBody)
            : '<div style="white-space:pre-wrap;font-family:inherit;">' . e($plainBody) . '</div>';

        $msg = [
            'id'              => $folder . ':' . $uid,
            'thread_id'       => $folder . ':' . $uid,
            'message_id_hdr'  => $msgIdHdr,
            'references_hdr'  => (string) ($header->references ?? ''),
            'from_raw'        => $fromRaw,
            'from_name'       => $from['name'],
            'from_email'      => $from['email'],
            'to'              => $toRaw,
            'cc'              => $ccRaw,
            'reply_to'        => imap_decode_header_str((string) ($header->reply_toaddress ?? $fromRaw)),
            'subject'         => $subject !== '' ? $subject : '(Tanpa Subjek)',
            'date_raw'        => $dateRaw,
            'date_full'       => format_tanggal_id($dateRaw, true),
            'body_html'       => $safeHtml,
            'body_plain'      => $plainBody !== '' ? $plainBody : html_to_plain_text($htmlBody),
            'attachments'     => $attachments,
            'is_unread'       => false,
            'is_starred'      => trim((string) ($header->Flagged ?? '')) === 'F',
            'labels'          => [strtoupper($folder)],
        ];

        return [
            'thread_id' => $msg['thread_id'],
            'subject'   => $msg['subject'],
            'messages'  => [$msg],
            'transport' => 'app_password_imap',
        ];
    } finally {
        imap_close($stream);
    }
}

/**
 * Statistik folder Gmail melalui IMAP.
 */
function imap_get_mailbox_stats(): array
{
    $stats = [
        'inbox_total'   => 0,
        'inbox_unread'  => 0,
        'sent_total'    => 0,
        'drafts_total'  => 0,
        'starred_total' => 0,
        'spam_total'    => 0,
        'trash_total'   => 0,
    ];

    if (!function_exists('imap_open')) {
        return $stats;
    }

    $creds = app_password_credentials();
    $map = [
        'inbox'   => 'INBOX',
        'sent'    => '[Gmail]/Sent Mail',
        'drafts'  => '[Gmail]/Drafts',
        'starred' => '[Gmail]/Starred',
        'spam'    => '[Gmail]/Spam',
        'trash'   => '[Gmail]/Trash',
    ];

    $baseStream = @imap_open(sprintf('{%s:%d/imap/ssl}INBOX', $creds['imap_host'], $creds['imap_port']), $creds['email'], $creds['password'], OP_HALFOPEN, 1);
    if (!$baseStream) {
        return $stats;
    }

    try {
        foreach ($map as $key => $folder) {
            $mbx = sprintf('{%s:%d/imap/ssl}%s', $creds['imap_host'], $creds['imap_port'], $folder);
            $st = @imap_status($baseStream, $mbx, SA_MESSAGES | SA_UNSEEN);
            if ($st) {
                if ($key === 'inbox') {
                    $stats['inbox_total']  = (int) ($st->messages ?? 0);
                    $stats['inbox_unread'] = (int) ($st->unseen ?? 0);
                } else {
                    $stats[$key . '_total'] = (int) ($st->messages ?? 0);
                }
            }
        }
    } finally {
        imap_close($baseStream);
    }

    return $stats;
}

/**
 * Aksi IMAP: mark_read, mark_unread, star, unstar, trash, archive, delete.
 */
function imap_perform_action(string $compositeId, string $action): bool
{
    $folder = 'inbox';
    $uid    = $compositeId;
    if (str_contains($compositeId, ':')) {
        [$folder, $uid] = explode(':', $compositeId, 2);
    }

    $stream = imap_connect_folder($folder);
    try {
        switch ($action) {
            case 'mark_read':
                return (bool) imap_setflag_full($stream, $uid, '\\Seen', ST_UID);
            case 'mark_unread':
                return (bool) imap_clearflag_full($stream, $uid, '\\Seen', ST_UID);
            case 'star':
                return (bool) imap_setflag_full($stream, $uid, '\\Flagged', ST_UID);
            case 'unstar':
                return (bool) imap_clearflag_full($stream, $uid, '\\Flagged', ST_UID);
            case 'trash':
                $ok = imap_mail_move($stream, $uid, '[Gmail]/Trash', CP_UID);
                imap_expunge($stream);
                return (bool) $ok;
            case 'archive':
                $ok = imap_mail_move($stream, $uid, '[Gmail]/All Mail', CP_UID);
                imap_expunge($stream);
                return (bool) $ok;
            case 'delete':
                $ok = imap_delete($stream, $uid, FT_UID);
                imap_expunge($stream);
                return (bool) $ok;
            default:
                return false;
        }
    } finally {
        imap_close($stream);
    }
}

/**
 * Simpan draft melalui IMAP ke [Gmail]/Drafts.
 */
function imap_save_draft(string $rawMime): array
{
    if (!function_exists('imap_open')) {
        throw new RuntimeException('Ekstensi php-imap diperlukan untuk menyimpan draft dalam mode App Password.');
    }
    $creds   = app_password_credentials();
    $mailbox = sprintf('{%s:%d/imap/ssl}[Gmail]/Drafts', $creds['imap_host'], $creds['imap_port']);
    $stream  = @imap_open($mailbox, $creds['email'], $creds['password'], 0, 1);
    if (!$stream) {
        throw new RuntimeException('Gagal membuka folder Drafts Gmail via IMAP.');
    }
    try {
        $ok = imap_append($stream, $mailbox, $rawMime, '\\Draft');
        if (!$ok) {
            throw new RuntimeException('Gagal menyimpan draft ke Gmail.');
        }
        return ['draft_id' => 'imap_draft_' . time(), 'transport' => 'app_password_imap'];
    } finally {
        imap_close($stream);
    }
}

/**
 * Unduh lampiran via IMAP.
 */
function imap_download_attachment(string $compositeId, string $section): array
{
    $folder = 'inbox';
    $uid    = $compositeId;
    if (str_contains($compositeId, ':')) {
        [$folder, $uid] = explode(':', $compositeId, 2);
    }

    $stream = imap_connect_folder($folder);
    try {
        $structure = imap_fetchstructure($stream, (int) $uid, FT_UID);
        $rawData   = imap_fetchbody($stream, (int) $uid, $section, FT_UID | FT_PEEK);

        $partsIdx = explode('.', $section);
        $node = $structure;
        foreach ($partsIdx as $p) {
            $idx = max(0, (int) $p - 1);
            if (!empty($node->parts[$idx])) {
                $node = $node->parts[$idx];
            }
        }
        $encoding = (int) ($node->encoding ?? 0);
        $binary = match ($encoding) {
            3 => base64_decode((string) $rawData) ?: '',
            4 => quoted_printable_decode((string) $rawData),
            default => (string) $rawData,
        };

        $filename = 'lampiran';
        foreach (['dparameters', 'parameters'] as $paramField) {
            if (!empty($node->$paramField)) {
                foreach ($node->$paramField as $p) {
                    if (in_array(strtolower((string) $p->attribute), ['filename', 'name'], true)) {
                        $filename = imap_decode_header_str((string) $p->value);
                    }
                }
            }
        }

        return [
            'filename'  => $filename,
            'mime_type' => 'application/octet-stream',
            'data'      => $binary,
        ];
    } finally {
        imap_close($stream);
    }
}
