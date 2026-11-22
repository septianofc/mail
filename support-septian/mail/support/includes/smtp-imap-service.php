<?php
/**
 * Support Septian — Google App Password Mail Transport (Pure PHP SMTP + PHP IMAP)
 *
 * Memungkinkan Support Septian terhubung ke Gmail menggunakan Google App Password 16 digit:
 * - Pengiriman email via SMTP TLS/SSL langsung ke smtp.gmail.com (tanpa library tambahan!)
 * - Pembacaan folder Gmail (Inbox, Sent, Drafts, Starred, Spam, Trash) via PHP IMAP (imap.gmail.com:993)
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Ambil kredensial App Password (hanya di sisi server, tidak pernah dikirim ke browser).
 */
function app_password_credentials(): array
{
    $officialEmail = strtolower(trim(get_setting('support_email', DEFAULT_OFFICIAL_GMAIL)));
    $email         = strtolower(trim(get_setting('gmail_app_password_email', $officialEmail)));
    $password      = str_replace(' ', '', trim(get_setting('gmail_app_password_secret', '')));

    if ($email !== $officialEmail) {
        throw new RuntimeException('Email Google App Password harus menggunakan akun resmi: ' . $officialEmail);
    }
    if ($password === '') {
        throw new RuntimeException('Google App Password belum diisi pada halaman Pengaturan / Koneksi Gmail.');
    }

    return [
        'email'      => $email,
        'password'   => $password,
        'smtp_host'  => get_setting('gmail_smtp_host', 'smtp.gmail.com') ?: 'smtp.gmail.com',
        'smtp_port'  => (int) (get_setting('gmail_smtp_port', '465') ?: 465),
        'smtp_enc'   => strtolower(get_setting('gmail_smtp_encryption', 'ssl') ?: 'ssl'),
        'imap_host'  => get_setting('gmail_imap_host', 'imap.gmail.com') ?: 'imap.gmail.com',
        'imap_port'  => (int) (get_setting('gmail_imap_port', '993') ?: 993),
    ];
}

/**
 * Baca respons multi-baris dari socket SMTP.
 */
function smtp_read_response($socket): string
{
    $response = '';
    while (($line = fgets($socket, 1024)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

/**
 * Kirim perintah SMTP dan verifikasi kode status yang diharapkan.
 */
function smtp_command($socket, string $command, array $expectedCodes): string
{
    if ($command !== '') {
        fwrite($socket, $command . "\r\n");
    }
    $response = smtp_read_response($socket);
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Kesalahan SMTP (' . $code . '): ' . trim(redact_sensitive_text($response)));
    }
    return $response;
}

/**
 * Uji autentikasi SMTP (dan IMAP jika ekstensi tersedia) menggunakan Google App Password.
 */
function test_google_app_password_connection(?string $overrideEmail = null, ?string $overridePassword = null): array
{
    $officialEmail = strtolower(trim(get_setting('support_email', DEFAULT_OFFICIAL_GMAIL)));
    $email    = strtolower(trim($overrideEmail ?? get_setting('gmail_app_password_email', $officialEmail)));
    $password = str_replace(' ', '', trim($overridePassword ?? get_setting('gmail_app_password_secret', '')));

    if ($email !== $officialEmail) {
        return ['ok' => false, 'smtp' => false, 'imap' => false, 'message' => 'Email harus menggunakan akun resmi ' . $officialEmail];
    }
    if ($password === '') {
        return ['ok' => false, 'smtp' => false, 'imap' => false, 'message' => 'Google App Password masih kosong.'];
    }

    $smtpHost = get_setting('gmail_smtp_host', 'smtp.gmail.com') ?: 'smtp.gmail.com';
    $smtpPort = (int) (get_setting('gmail_smtp_port', '465') ?: 465);
    $smtpEnc  = strtolower(get_setting('gmail_smtp_encryption', 'ssl') ?: 'ssl');
    $imapHost = get_setting('gmail_imap_host', 'imap.gmail.com') ?: 'imap.gmail.com';
    $imapPort = (int) (get_setting('gmail_imap_port', '993') ?: 993);

    $smtpOk = false;
    $imapOk = false;
    $details = [];

    // 1. Tes koneksi SMTP
    $target = ($smtpEnc === 'ssl' ? 'ssl://' : 'tcp://') . $smtpHost . ':' . $smtpPort;
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    $socket = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$socket) {
        return ['ok' => false, 'smtp' => false, 'imap' => false, 'message' => 'Gagal terhubung ke server SMTP Gmail: ' . $errstr];
    }

    try {
        stream_set_timeout($socket, 15);
        smtp_command($socket, '', [220]);
        smtp_command($socket, 'EHLO septianfr.web.id', [250]);
        if ($smtpEnc === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Gagal mengaktifkan enkripsi STARTTLS.');
            }
            smtp_command($socket, 'EHLO septianfr.web.id', [250]);
        }
        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($email), [334]);
        smtp_command($socket, base64_encode($password), [235]);
        smtp_command($socket, 'QUIT', [221, 250]);
        $smtpOk = true;
        $details[] = 'SMTP Gmail terautentikasi.';
    } catch (Throwable $e) {
        fclose($socket);
        return ['ok' => false, 'smtp' => false, 'imap' => false, 'message' => 'Autentikasi SMTP ditolak. Pastikan 2-Step Verification aktif dan 16 karakter App Password benar.'];
    }
    fclose($socket);

    // 2. Tes koneksi IMAP jika ekstensi php-imap tersedia
    if (function_exists('imap_open')) {
        $mailbox = sprintf('{%s:%d/imap/ssl}INBOX', $imapHost, $imapPort);
        $imap = @imap_open($mailbox, $email, $password, OP_READONLY, 1);
        if ($imap) {
            $imapOk = true;
            imap_close($imap);
            $details[] = 'IMAP Gmail terhubung (Kotak Masuk siap dibaca).';
        } else {
            $details[] = 'IMAP gagal terhubung (pastikan IMAP aktif di pengaturan Gmail).';
        }
    } else {
        $details[] = 'Ekstensi php-imap belum aktif di server hosting (SMTP tetap berfungsi penuh untuk mengirim email).';
    }

    return [
        'ok'      => $smtpOk,
        'smtp'    => $smtpOk,
        'imap'    => $imapOk,
        'message' => implode(' ', $details),
    ];
}

/**
 * Kirim pesan RFC 2822 mentah melalui SMTP Gmail menggunakan Google App Password.
 */
function smtp_send_raw_message(string $rawMime, array $recipients): array
{
    $creds  = app_password_credentials();
    $target = ($creds['smtp_enc'] === 'ssl' ? 'ssl://' : 'tcp://') . $creds['smtp_host'] . ':' . $creds['smtp_port'];

    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $socket = @stream_socket_client($target, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$socket) {
        throw new RuntimeException('Tidak dapat terhubung ke SMTP Gmail: ' . $errstr);
    }

    try {
        stream_set_timeout($socket, 25);
        smtp_command($socket, '', [220]);
        smtp_command($socket, 'EHLO septianfr.web.id', [250]);

        if ($creds['smtp_enc'] === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Gagal mengaktifkan STARTTLS ke SMTP Gmail.');
            }
            smtp_command($socket, 'EHLO septianfr.web.id', [250]);
        }

        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($creds['email']), [334]);
        smtp_command($socket, base64_encode($creds['password']), [235]);
        smtp_command($socket, 'MAIL FROM:<' . $creds['email'] . '>', [250]);

        foreach ($recipients as $rcpt) {
            smtp_command($socket, 'RCPT TO:<' . $rcpt . '>', [250, 251]);
        }

        smtp_command($socket, 'DATA', [354]);
        // Dot-stuffing RFC 5321
        $stuffed = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $rawMime));
        $stuffed = str_replace("\n", "\r\n", (string) $stuffed);
        fwrite($socket, $stuffed . "\r\n.\r\n");
        $dataResp = smtp_read_response($socket);
        if ((int) substr($dataResp, 0, 3) !== 250) {
            throw new RuntimeException('Server SMTP menolak pengiriman pesan.');
        }

        smtp_command($socket, 'QUIT', [221, 250]);
        fclose($socket);

        return [
            'id'        => 'smtp_' . bin2hex(random_bytes(8)),
            'thread_id' => '',
            'transport' => 'app_password_smtp',
        ];
    } catch (Throwable $e) {
        @fclose($socket);
        throw $e;
    }
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
            'messages'       => $messages,
            'next_page_token'=> $nextPageToken,
            'result_estimate'=> $total,
            'transport'      => 'app_password_imap',
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

        $extractParts = function ($struct, string $partNum = '') use ($stream, $uid, &$htmlBody, &$plainBody, &$attachments, $decodePart, &$extractParts): void {
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

        // Cari encoding & nama file pada bagian terkait
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
