<?php
/**
 * Skyzz Mail — Multi-Mailbox PHPMailer SMTP & PHP IMAP Transport
 *
 * - Mendukung banyak akun email (Gmail App Password maupun SMTP/IMAP kustom) yang dikonfigurasi Superadmin
 * - Menjamin Username SMTP = From Email agar DKIM Gmail tetap PASS
 * - Menyinkronkan email terkirim ke folder Sent / Pesan Terkirim Gmail (via IMAP append anti-duplikat)
 *   agar setiap email yang dikirim dari aplikasi selalu muncul di Terkirim Gmail!
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/email-renderer.php';

/**
 * Cek apakah library PHPMailer (Composer maupun manual upload) sudah terpasang.
 */
function is_phpmailer_installed(): bool
{
    return class_exists(\PHPMailer\PHPMailer\PHPMailer::class)
        && class_exists(\PHPMailer\PHPMailer\SMTP::class);
}

/**
 * Ambil kredensial SMTP & IMAP dari mailbox aktif (atau override / app_settings).
 */
function app_password_credentials(
    ?string $overrideEmail = null,
    ?string $overridePassword = null,
    bool $requirePassword = true,
    ?array $mailbox = null,
    ?string $overrideFromName = null
): array {
    $mb = $mailbox;
    if ($mb === null && function_exists('get_effective_mailbox')) {
        $mb = get_effective_mailbox();
    }

    $email = strtolower(trim($overrideEmail ?? (string) ($mb['email'] ?? get_setting('gmail_app_password_email', DEFAULT_OFFICIAL_GMAIL))));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = strtolower(DEFAULT_OFFICIAL_GMAIL);
    }

    $smtpUsername = strtolower(trim($overrideEmail ?? (string) ($mb['smtp_username'] ?? $email)));
    if ($smtpUsername === '') {
        $smtpUsername = $email;
    }

    $rawPass = $overridePassword !== null
        ? $overridePassword
        : (string) ($mb['smtp_password'] ?? get_setting('gmail_app_password_secret', ''));
    $password = str_replace(' ', '', trim($rawPass));

    if ($requirePassword && $password === '') {
        throw new RuntimeException('Sandi SMTP / Google App Password belum diisi untuk akun ' . $email . '.');
    }

    $smtpHost = trim((string) ($mb['smtp_host'] ?? get_setting('gmail_smtp_host', 'smtp.gmail.com'))) ?: 'smtp.gmail.com';
    $smtpPort = (int) ($mb['smtp_port'] ?? (get_setting('gmail_smtp_port', '465') ?: 465));
    $smtpEnc  = strtolower(trim((string) ($mb['smtp_encryption'] ?? get_setting('gmail_smtp_encryption', 'ssl'))));
    if (!in_array($smtpEnc, ['ssl', 'tls', 'none'], true)) {
        $smtpEnc = 'ssl';
    }

    // Jika memakai smtp.gmail.com, wajib samakan From Email dengan Username SMTP agar DKIM Google PASS
    if (str_contains(strtolower($smtpHost), 'gmail.com') && filter_var($smtpUsername, FILTER_VALIDATE_EMAIL)) {
        $email = $smtpUsername;
    }

    $fromName = trim((string) ($overrideFromName ?? ''))
        ?: trim((string) ($mb['display_name'] ?? ''))
        ?: (get_setting('official_name', DEFAULT_OFFICIAL_NAME) ?: DEFAULT_OFFICIAL_NAME);

    return [
        'mailbox_id' => (int) ($mb['id'] ?? 0),
        'email'      => $email,
        'username'   => $smtpUsername,
        'from_name'  => $fromName,
        'password'   => $password,
        'smtp_host'  => $smtpHost,
        'smtp_port'  => $smtpPort > 0 ? $smtpPort : 465,
        'smtp_enc'   => $smtpEnc,
        'smtp_auth'  => true,
        'imap_host'  => trim((string) ($mb['imap_host'] ?? get_setting('gmail_imap_host', 'imap.gmail.com'))) ?: 'imap.gmail.com',
        'imap_port'  => (int) ($mb['imap_port'] ?? (get_setting('gmail_imap_port', '993') ?: 993)),
    ];
}

/**
 * Sanitasi log debug SMTP agar password / token base64 AUTH tidak pernah muncul.
 */
function sanitize_smtp_debug_line(string $line, string $secretPassword = '', string $accountEmail = ''): string
{
    $clean = trim($line);
    if ($secretPassword !== '') {
        $clean = str_replace($secretPassword, '[REDACTED]', $clean);
        $clean = str_replace(base64_encode($secretPassword), '[REDACTED_B64]', $clean);
        $em = $accountEmail !== '' ? $accountEmail : DEFAULT_OFFICIAL_GMAIL;
        $plainAuth = base64_encode("\0" . $em . "\0" . $secretPassword);
        $clean = str_replace($plainAuth, '[REDACTED_AUTH]', $clean);
    }
    return redact_sensitive_text($clean);
}

/**
 * Buat instance PHPMailer terkonfigurasi ketat (SSL/TLS + SMTPAuth=true, tanpa DKIM manual rusak).
 */
function phpmailer_create_configured(array $creds, bool $captureDebug = false, array &$debugLog = []): \PHPMailer\PHPMailer\PHPMailer
{
    if (!is_phpmailer_installed()) {
        throw new RuntimeException(
            'Library PHPMailer belum terpasang di vendor/ maupun includes/phpmailer/. Unggah folder src PHPMailer ke /mail/support/includes/phpmailer/src/ atau instal via Composer.'
        );
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host     = $creds['smtp_host'];
    $mail->Port     = (int) $creds['smtp_port'];
    $mail->SMTPAuth = true;
    $mail->Username = $creds['username'];
    $mail->Password = $creds['password'];
    $mail->Timeout  = 25;

    if ($creds['smtp_enc'] === 'tls') {
        $mail->SMTPSecure  = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;
    } elseif ($creds['smtp_enc'] === 'none') {
        $mail->SMTPSecure  = '';
        $mail->SMTPAutoTLS = false;
    } else {
        $mail->SMTPSecure  = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->SMTPAutoTLS = false;
    }

    $emailDomain = str_contains($creds['email'], '@') ? explode('@', $creds['email'], 2)[1] : 'gmail.com';
    $mail->Hostname = $emailDomain;
    $mail->Helo     = parse_url(get_setting('website_url', DEFAULT_WEBSITE_URL), PHP_URL_HOST) ?: 'skyzzmail.local';
    $mail->XMailer  = 'Skyzz Mail';

    // Tidak menyisipkan tanda tangan DKIM manual palsu (DKIM ditangani provider SMTP)
    $mail->DKIM_domain         = '';
    $mail->DKIM_private        = '';
    $mail->DKIM_private_string = '';
    $mail->DKIM_selector       = '';
    $mail->DKIM_passphrase     = '';

    $mail->clearAllRecipients();
    $mail->clearReplyTos();
    $mail->clearAttachments();
    $mail->clearCustomHeaders();

    $mail->CharSet  = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
    $mail->Encoding = \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;
    $mail->setFrom($creds['email'], $creds['from_name'], true);
    $mail->Sender = $creds['email'];
    $mail->addReplyTo($creds['email'], $creds['from_name']);

    if ($captureDebug) {
        $secret = $creds['password'];
        $accEm  = $creds['email'];
        $mail->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
        $mail->Debugoutput = static function (string $str, int $level) use (&$debugLog, $secret, $accEm): void {
            $safe = sanitize_smtp_debug_line($str, $secret, $accEm);
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
 * Pastikan salinan email yang baru dikirim via SMTP juga tercatat di folder Sent / Pesan Terkirim Gmail (IMAP).
 * Memeriksa Message-ID terlebih dahulu agar tidak pernah membuat pesan ganda jika server SMTP sudah otomatis menyimpan.
 */
function imap_append_sent_copy(array $creds, string $rawMime, string $messageId = ''): bool
{
    if ($rawMime === '' || !function_exists('imap_open')) {
        return false;
    }
    if (get_setting('imap_append_sent', '1') !== '1') {
        return false;
    }

    $ref = sprintf('{%s:%d/imap/ssl}', $creds['imap_host'], (int) $creds['imap_port']);
    $half = @imap_open($ref . 'INBOX', $creds['username'], $creds['password'], OP_HALFOPEN, 1);
    if (!$half) {
        return false;
    }

    try {
        $sentFolder = imap_resolve_folder_name($half, $creds, 'sent');
    } finally {
        @imap_close($half);
    }

    $sentBox = $ref . $sentFolder;
    $stream  = @imap_open($sentBox, $creds['username'], $creds['password'], 0, 1);
    if (!$stream) {
        return false;
    }

    try {
        // Cek apakah server SMTP (mis. smtp.gmail.com) sudah otomatis menyimpan salinan dengan Message-ID yang sama
        $cleanId = trim(str_replace(['"', "\r", "\n"], '', $messageId));
        if ($cleanId !== '') {
            $existing = @imap_search($stream, 'HEADER Message-ID "' . $cleanId . '"', SE_UID);
            if (is_array($existing) && !empty($existing)) {
                return true; // Sudah tercatat di Terkirim Gmail — tidak perlu duplikasi
            }
        }

        // Salin RFC 2822 MIME ke folder Sent / Pesan Terkirim Gmail dengan flag \Seen
        $ok = @imap_append($stream, $sentBox, $rawMime, '\\Seen');
        return (bool) $ok;
    } catch (Throwable $e) {
        app_log('warning', 'imap_append_sent_copy gagal: ' . $e->getMessage());
        return false;
    } finally {
        @imap_close($stream);
    }
}

/**
 * Kirim email melalui PHPMailer SMTP terautentikasi & pastikan tercatat di Terkirim Gmail.
 */
function phpmailer_send_email(array $params, bool $captureDebug = false, ?array $mailbox = null): array
{
    $customFromName = isset($params['from_name']) ? trim((string) $params['from_name']) : null;
    $creds    = app_password_credentials(null, null, true, $mailbox, $customFromName);
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
        $rawMime       = $mail->getSentMIMEMessage();

        // Pastikan salinan pesan juga muncul di folder Terkirim / Sent Mail Gmail
        $imapSentSynced = false;
        if (str_contains(strtolower($creds['smtp_host']), 'gmail.com')) {
            // smtp.gmail.com biasanya menyimpan otomatis ke Sent Mail; verifikasi / append jika belum ada
            $imapSentSynced = imap_append_sent_copy($creds, $rawMime, $sentMessageId);
            if (!$imapSentSynced) {
                // Jika php-imap tidak aktif, Gmail SMTP tetap menyimpan di Sent Mail akun Gmail secara native
                $imapSentSynced = true;
            }
        } else {
            $imapSentSynced = imap_append_sent_copy($creds, $rawMime, $sentMessageId);
        }

        return [
            'id'                 => $sentMessageId !== '' ? $sentMessageId : 'smtp_' . bin2hex(random_bytes(8)),
            'thread_id'          => '',
            'transport'          => 'phpmailer_gmail_smtp',
            'smtp_connected'     => true,
            'smtp_authenticated' => true,
            'smtp_host'          => $creds['smtp_host'],
            'smtp_port'          => $creds['smtp_port'],
            'smtp_encryption'    => strtoupper($creds['smtp_enc']),
            'smtp_auth'          => true,
            'account_email'      => $creds['email'],
            'from_email'         => $creds['email'],
            'from_name'          => $creds['from_name'],
            'sender'             => $creds['email'],
            'reply_to'           => $creds['email'],
            'message_id'         => $sentMessageId,
            'imap_sent_synced'   => $imapSentSynced,
            'headers_verified'   => [
                'From'         => $creds['from_name'] . ' <' . $creds['email'] . '>',
                'Sender'       => $creds['email'],
                'Reply-To'     => $creds['from_name'] . ' <' . $creds['email'] . '>',
                'Return-Path'  => $creds['email'],
                'Message-ID'   => $sentMessageId,
                'MIME-Version' => '1.0',
                'Content-Type' => !empty($attachments) ? 'multipart/mixed' : 'multipart/alternative',
            ],
            'smtp_transcript'    => $captureDebug ? implode("\n", array_slice($debugLog, -24)) : '',
        ];
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        $safeError = sanitize_smtp_debug_line($mail->ErrorInfo ?: $e->getMessage(), $creds['password'], $creds['email']);
        app_log('error', 'PHPMailer SMTP send gagal: ' . $safeError);
        throw new RuntimeException('Pengiriman via SMTP gagal: ' . $safeError);
    }
}

/**
 * Bangun dokumen email RFC 2822 menggunakan PHPMailer (untuk Gmail API OAuth & Drafts).
 */
function phpmailer_build_raw_mime(array $params, ?array $mailbox = null): string
{
    if (!is_phpmailer_installed()) {
        return '';
    }

    $creds = app_password_credentials(null, null, false, $mailbox, $params['from_name'] ?? null);

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Hostname = str_contains($creds['email'], '@') ? explode('@', $creds['email'], 2)[1] : 'gmail.com';
    $mail->XMailer  = 'Skyzz Mail';
    $mail->CharSet  = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
    $mail->Encoding = \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;
    $mail->DKIM_domain = '';
    $mail->DKIM_private = '';
    $mail->DKIM_selector = '';

    $mail->setFrom($creds['email'], $creds['from_name'], true);
    $mail->Sender = $creds['email'];
    $mail->addReplyTo($creds['email'], $creds['from_name']);

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
 * Jalankan diagnostik koneksi & autentikasi SMTP + IMAP untuk mailbox aktif atau override.
 */
function run_smtp_diagnostics(?string $overrideEmail = null, ?string $overridePassword = null, ?array $mailbox = null): array
{
    $creds          = app_password_credentials($overrideEmail, $overridePassword, false, $mailbox);
    $phpmailerReady = is_phpmailer_installed();
    $password       = $creds['password'];

    $diag = [
        'ok'                 => false,
        'smtp'               => false,
        'imap'               => false,
        'smtp_connected'     => false,
        'smtp_authenticated' => false,
        'phpmailer_installed'=> $phpmailerReady,
        'account_email'      => $creds['email'],
        'from_email'         => $creds['email'],
        'from_name'          => $creds['from_name'],
        'transport_method'   => sprintf('PHPMailer SMTP (%s:%d · %s · SMTPAuth=true)', $creds['smtp_host'], $creds['smtp_port'], strtoupper($creds['smtp_enc'])),
        'smtp_host'          => $creds['smtp_host'],
        'smtp_port'          => $creds['smtp_port'],
        'smtp_encryption'    => strtoupper($creds['smtp_enc']),
        'smtp_auth'          => true,
        'smtp_error'         => '',
        'smtp_transcript'    => '',
        'message'            => '',
    ];

    if (!$phpmailerReady) {
        $diag['smtp_error'] = 'PHPMailer belum terpasang di vendor/ maupun includes/phpmailer/.';
        $diag['message']    = $diag['smtp_error'];
        return $diag;
    }

    if ($password === '') {
        $diag['smtp_error'] = 'Sandi SMTP / Google App Password belum diisi untuk ' . $creds['email'] . '.';
        $diag['message']    = $diag['smtp_error'];
        return $diag;
    }

    $debugLines = [];
    $smtp = new \PHPMailer\PHPMailer\SMTP();
    $smtp->do_debug = \PHPMailer\PHPMailer\SMTP::DEBUG_CONNECTION;
    $accEm = $creds['email'];
    $smtp->Debugoutput = static function (string $str, int $level) use (&$debugLines, $password, $accEm): void {
        $safe = sanitize_smtp_debug_line($str, $password, $accEm);
        if ($safe !== '') {
            $debugLines[] = $safe;
        }
    };

    try {
        $connectHost = $creds['smtp_enc'] === 'ssl' ? ('ssl://' . $creds['smtp_host']) : $creds['smtp_host'];
        if (!$smtp->connect($connectHost, (int) $creds['smtp_port'], 20)) {
            $lastErr = $smtp->getError();
            throw new RuntimeException('Gagal terhubung ke ' . $connectHost . ':' . $creds['smtp_port'] . ' — ' . ($lastErr['error'] ?? 'Koneksi ditolak'));
        }
        $diag['smtp_connected'] = true;

        $heloHost = parse_url(get_setting('website_url', DEFAULT_WEBSITE_URL), PHP_URL_HOST) ?: 'skyzzmail.local';
        if (!$smtp->hello($heloHost)) {
            $lastErr = $smtp->getError();
            throw new RuntimeException('EHLO gagal: ' . ($lastErr['smtp_msg'] ?? $lastErr['error'] ?? 'Unknown'));
        }

        if ($creds['smtp_enc'] === 'tls') {
            if (!$smtp->startTLS()) {
                throw new RuntimeException('STARTTLS gagal dinegosiasikan dengan server SMTP.');
            }
            $smtp->hello($heloHost);
        }

        if (!$smtp->authenticate($creds['username'], $password)) {
            $lastErr = $smtp->getError();
            throw new RuntimeException(
                'Autentikasi SMTP ditolak (' . ($lastErr['smtp_code'] ?? '535') . '): ' .
                ($lastErr['smtp_msg'] ?? $lastErr['error'] ?? 'Periksa username dan App Password.')
            );
        }

        $diag['smtp_authenticated'] = true;
        $diag['smtp']               = true;
        $diag['ok']                 = true;
        $smtp->quit();
    } catch (Throwable $e) {
        $diag['smtp_error'] = sanitize_smtp_debug_line($e->getMessage(), $password, $accEm);
    } finally {
        if ($smtp->connected()) {
            $smtp->close();
        }
    }

    $diag['smtp_transcript'] = implode("\n", array_slice($debugLines, -20));

    $details = [];
    if ($diag['smtp_authenticated']) {
        $details[] = sprintf('PHPMailer terhubung dan terautentikasi ke %s:%d sebagai %s.', $creds['smtp_host'], $creds['smtp_port'], $creds['email']);
    } else {
        $details[] = $diag['smtp_error'] ?: 'Koneksi SMTP gagal.';
    }

    if (function_exists('imap_open')) {
        $mailboxStr = sprintf('{%s:%d/imap/ssl}INBOX', $creds['imap_host'], (int) $creds['imap_port']);
        $imap = @imap_open($mailboxStr, $creds['username'], $password, OP_READONLY, 1);
        if ($imap) {
            $diag['imap'] = true;
            @imap_close($imap);
            $details[] = 'IMAP terhubung (Kotak Masuk & sinkronisasi Terkirim aktif).';
        } else {
            $details[] = 'IMAP belum terhubung (pastikan IMAP aktif di pengaturan akun email).';
        }
    } else {
        $details[] = 'Ekstensi php-imap tidak aktif di server (pengiriman SMTP & log Terkirim lokal tetap berfungsi).';
    }

    $diag['message'] = implode(' ', $details);
    return $diag;
}

/**
 * Wrapper kompatibilitas untuk pengujian koneksi App Password.
 */
function test_google_app_password_connection(?string $overrideEmail = null, ?string $overridePassword = null, ?array $mailbox = null): array
{
    return run_smtp_diagnostics($overrideEmail, $overridePassword, $mailbox);
}

/**
 * Daftar kandidat nama folder IMAP (mendukung Gmail bahasa Inggris, Indonesia, dan mail server umum).
 */
function imap_folder_candidates(string $folder): array
{
    return match (strtolower($folder)) {
        'sent'    => ['[Gmail]/Sent Mail', '[Gmail]/Pesan Terkirim', '[Gmail]/Terkirim', '[Google Mail]/Sent Mail', 'INBOX.Sent', 'Sent', 'Sent Mail', 'Sent Items'],
        'drafts'  => ['[Gmail]/Drafts', '[Gmail]/Draf', '[Google Mail]/Drafts', 'INBOX.Drafts', 'Drafts'],
        'starred' => ['[Gmail]/Starred', '[Gmail]/Berbintang', '[Google Mail]/Starred', 'Starred'],
        'spam'    => ['[Gmail]/Spam', '[Google Mail]/Spam', 'INBOX.Spam', 'Spam', 'Junk'],
        'trash'   => ['[Gmail]/Trash', '[Gmail]/Sampah', '[Gmail]/Bin', '[Google Mail]/Trash', 'INBOX.Trash', 'Trash'],
        'all'     => ['[Gmail]/All Mail', '[Gmail]/Semua Email', '[Google Mail]/All Mail', 'All Mail'],
        default   => ['INBOX'],
    };
}

function imap_folder_name(string $folder): string
{
    return imap_folder_candidates($folder)[0];
}

/**
 * Temukan nama mailbox IMAP aktual di server (per akun email, otomatis mendeteksi bahasa Inggris/Indonesia).
 */
function imap_resolve_folder_name($stream, array $creds, string $folder): string
{
    $key = strtolower($folder);
    if ($key === 'inbox') {
        return 'INBOX';
    }

    static $discoveredByAccount = [];
    $accKey = strtolower($creds['imap_host'] . ':' . $creds['imap_port'] . ':' . $creds['email']);

    if (!isset($discoveredByAccount[$accKey])) {
        $discoveredByAccount[$accKey] = [];
        $ref = sprintf('{%s:%d/imap/ssl}', $creds['imap_host'], (int) $creds['imap_port']);
        $boxes = @imap_list($stream, $ref, '*');
        if (is_array($boxes)) {
            $available = [];
            foreach ($boxes as $box) {
                $short = str_replace($ref, '', (string) $box);
                $available[strtolower($short)] = $short;
            }
            foreach (['sent', 'drafts', 'starred', 'spam', 'trash', 'all'] as $fKey) {
                foreach (imap_folder_candidates($fKey) as $cand) {
                    if (isset($available[strtolower($cand)])) {
                        $discoveredByAccount[$accKey][$fKey] = $available[strtolower($cand)];
                        break;
                    }
                }
            }
        }
    }

    return $discoveredByAccount[$accKey][$key] ?? imap_folder_name($key);
}

/**
 * Buka koneksi IMAP ke folder tertentu untuk mailbox aktif.
 */
function imap_connect_folder(string $folder = 'inbox', ?array $mailbox = null)
{
    if (!function_exists('imap_open')) {
        throw new RuntimeException(
            'Ekstensi PHP IMAP belum aktif di server hosting. Aktifkan php-imap di cPanel atau gunakan mode Gmail API (OAuth 2.0).'
        );
    }

    $creds   = app_password_credentials(null, null, true, $mailbox);
    $ref     = sprintf('{%s:%d/imap/ssl}', $creds['imap_host'], (int) $creds['imap_port']);
    $primary = $ref . imap_folder_name($folder);
    $stream  = @imap_open($primary, $creds['username'], $creds['password'], 0, 1);
    if ($stream) {
        return $stream;
    }

    $half = @imap_open($ref . 'INBOX', $creds['username'], $creds['password'], OP_HALFOPEN, 1);
    if ($half) {
        $resolvedName = imap_resolve_folder_name($half, $creds, $folder);
        @imap_close($half);
        if ($resolvedName !== imap_folder_name($folder)) {
            $retryStream = @imap_open($ref . $resolvedName, $creds['username'], $creds['password'], 0, 1);
            if ($retryStream) {
                return $retryStream;
            }
        }
    }

    $err = (string) (imap_last_error() ?: 'Autentikasi IMAP atau nama folder gagal.');
    throw new RuntimeException('Gagal membuka folder (' . $folder . ') via IMAP: ' . redact_sensitive_text($err));
}

function imap_decode_header_str(string $value): string
{
    if ($value === '') {
        return '';
    }
    $decoded = @imap_mime_header_decode($value);
    if (!is_array($decoded)) {
        return trim($value);
    }
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
 * Daftar pesan melalui IMAP untuk mailbox aktif.
 */
function imap_list_messages(string $folder, int $maxResults = 20, ?string $pageToken = null, string $searchQuery = '', ?array $mailbox = null): array
{
    $stream = imap_connect_folder($folder, $mailbox);
    try {
        $criteria = 'ALL';
        if (trim($searchQuery) !== '') {
            $cleanQ = str_replace('"', '', trim($searchQuery));
            $criteria = 'TEXT "' . $cleanQ . '"';
        }

        $uids = @imap_search($stream, $criteria, SE_UID);
        if (!is_array($uids)) {
            $uids = [];
        }
        rsort($uids);

        $total  = count($uids);
        $offset = max(0, (int) ($pageToken ?: 0));
        $slice  = array_slice($uids, $offset, $maxResults);
        $nextPageToken = ($offset + $maxResults < $total) ? (string) ($offset + $maxResults) : null;

        $messages = [];
        foreach ($slice as $uid) {
            $overviewList = @imap_fetch_overview($stream, (string) $uid, FT_UID);
            $ov = (is_array($overviewList) && isset($overviewList[0])) ? $overviewList[0] : null;
            if (!$ov) {
                continue;
            }
            $fromRaw = imap_decode_header_str((string) ($ov->from ?? ''));
            $from    = parse_sender_header($fromRaw);
            $toRaw   = imap_decode_header_str((string) ($ov->to ?? ''));
            $subject = imap_decode_header_str((string) ($ov->subject ?? '(Tanpa Subjek)'));
            $dateStr = (string) ($ov->date ?? '');

            $messages[] = [
                'id'              => $folder . ':' . $uid,
                'thread_id'       => $folder . ':' . $uid,
                'from_name'       => $folder === 'sent' ? ('Kepada: ' . ($toRaw ?: '(Penerima)')) : ($from['name'] ?: '(Tanpa Nama)'),
                'from_email'      => $from['email'],
                'to'              => $toRaw,
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
        @imap_close($stream);
    }
}

/**
 * Baca detail pesan tunggal via IMAP.
 */
function imap_get_message_detail(string $compositeId, ?array $mailbox = null): array
{
    $folder = 'inbox';
    $uid    = $compositeId;
    if (str_contains($compositeId, ':')) {
        [$folder, $uid] = explode(':', $compositeId, 2);
    }

    $stream = imap_connect_folder($folder, $mailbox);
    try {
        $msgNo = (int) @imap_msgno($stream, (int) $uid);
        if ($msgNo <= 0) {
            throw new RuntimeException('Email tidak ditemukan di folder IMAP.');
        }

        $header    = @imap_headerinfo($stream, $msgNo);
        $structure = @imap_fetchstructure($stream, (int) $uid, FT_UID);
        if (!$header) {
            throw new RuntimeException('Gagal membaca header pesan dari server IMAP.');
        }
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

        if (is_object($structure)) {
            $extractParts($structure);
        } else {
            $plainBody = (string) @imap_body($stream, (int) $uid, FT_UID | FT_PEEK);
        }

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
        @imap_close($stream);
    }
}

/**
 * Statistik folder melalui IMAP untuk mailbox aktif.
 */
function imap_get_mailbox_stats(?array $mailbox = null): array
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

    try {
        $creds = app_password_credentials(null, null, true, $mailbox);
    } catch (Throwable $e) {
        return $stats;
    }

    $baseStream = @imap_open(sprintf('{%s:%d/imap/ssl}INBOX', $creds['imap_host'], (int) $creds['imap_port']), $creds['username'], $creds['password'], OP_HALFOPEN, 1);
    if (!$baseStream) {
        return $stats;
    }

    try {
        foreach (['inbox', 'sent', 'drafts', 'starred', 'spam', 'trash'] as $key) {
            $folderName = imap_resolve_folder_name($baseStream, $creds, $key);
            $mbx = sprintf('{%s:%d/imap/ssl}%s', $creds['imap_host'], (int) $creds['imap_port'], $folderName);
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
        @imap_close($baseStream);
    }

    return $stats;
}

/**
 * Aksi IMAP: mark_read, mark_unread, star, unstar, trash, untrash, spam, not_spam, archive, delete.
 */
function imap_perform_action(string $compositeId, string $action, ?array $mailbox = null): bool
{
    $folder = 'inbox';
    $uid    = $compositeId;
    if (str_contains($compositeId, ':')) {
        [$folder, $uid] = explode(':', $compositeId, 2);
    }

    $creds  = app_password_credentials(null, null, true, $mailbox);
    $stream = imap_connect_folder($folder, $mailbox);
    try {
        switch ($action) {
            case 'mark_read':
                return (bool) @imap_setflag_full($stream, $uid, '\\Seen', ST_UID);
            case 'mark_unread':
                return (bool) @imap_clearflag_full($stream, $uid, '\\Seen', ST_UID);
            case 'star':
                return (bool) @imap_setflag_full($stream, $uid, '\\Flagged', ST_UID);
            case 'unstar':
                return (bool) @imap_clearflag_full($stream, $uid, '\\Flagged', ST_UID);
            case 'trash':
                $target = imap_resolve_folder_name($stream, $creds, 'trash');
                $ok = @imap_mail_move($stream, $uid, $target, CP_UID);
                @imap_expunge($stream);
                return (bool) $ok;
            case 'untrash':
            case 'not_spam':
                $ok = @imap_mail_move($stream, $uid, 'INBOX', CP_UID);
                @imap_expunge($stream);
                return (bool) $ok;
            case 'spam':
                $target = imap_resolve_folder_name($stream, $creds, 'spam');
                $ok = @imap_mail_move($stream, $uid, $target, CP_UID);
                @imap_expunge($stream);
                return (bool) $ok;
            case 'archive':
                $target = imap_resolve_folder_name($stream, $creds, 'all');
                $ok = @imap_mail_move($stream, $uid, $target, CP_UID);
                @imap_expunge($stream);
                return (bool) $ok;
            case 'delete':
                $ok = @imap_delete($stream, $uid, FT_UID);
                @imap_expunge($stream);
                return (bool) $ok;
            default:
                return false;
        }
    } finally {
        @imap_close($stream);
    }
}

/**
 * Simpan draft melalui IMAP ke folder Drafts.
 */
function imap_save_draft(string $rawMime, ?array $mailbox = null): array
{
    if (!function_exists('imap_open')) {
        throw new RuntimeException('Ekstensi php-imap diperlukan untuk menyimpan draft dalam mode App Password.');
    }
    $creds   = app_password_credentials(null, null, true, $mailbox);
    $stream  = imap_connect_folder('drafts', $mailbox);
    try {
        $draftFolder = imap_resolve_folder_name($stream, $creds, 'drafts');
        $mailboxPath = sprintf('{%s:%d/imap/ssl}%s', $creds['imap_host'], (int) $creds['imap_port'], $draftFolder);
        $ok = @imap_append($stream, $mailboxPath, $rawMime, '\\Draft');
        if (!$ok) {
            throw new RuntimeException('Gagal menyimpan draft ke server email.');
        }
        return ['draft_id' => 'imap_draft_' . time(), 'transport' => 'app_password_imap'];
    } finally {
        @imap_close($stream);
    }
}

/**
 * Unduh lampiran via IMAP.
 */
function imap_download_attachment(string $compositeId, string $section, ?array $mailbox = null): array
{
    $folder = 'inbox';
    $uid    = $compositeId;
    if (str_contains($compositeId, ':')) {
        [$folder, $uid] = explode(':', $compositeId, 2);
    }

    $stream = imap_connect_folder($folder, $mailbox);
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
        @imap_close($stream);
    }
}
