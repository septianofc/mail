<?php
/**
 * Support Septian — Unified Gmail Service Layer
 *
 * Mengelola seluruh operasi email sungguhan (Inbox, Sent, Drafts, Starred, Spam, Trash,
 * Thread Detail, Search, Send, Reply, Reply All, Forward, Save Draft, Actions, Attachments, Stats)
 * dengan Google Gmail API (OAuth 2.0) dan PHPMailer SMTP Gmail (App Password, SSL:465).
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

require_once __DIR__ . '/gmail-client.php';
require_once __DIR__ . '/smtp-imap-service.php';
require_once __DIR__ . '/email-renderer.php';

/**
 * Helper URL-safe Base64 decode & encode standar Gmail API.
 */
function gmail_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function gmail_base64url_decode(string $data): string
{
    $replaced = strtr($data, '-_', '+/');
    $pad = strlen($replaced) % 4;
    if ($pad > 0) {
        $replaced .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($replaced) ?: '';
}

/**
 * Cek apakah mode aktif adalah App Password (PHPMailer SMTP Gmail).
 */
function is_app_password_mode(): bool
{
    return get_setting('mail_connection_mode', 'oauth') === 'app_password';
}

/**
 * Buat instance Google\Service\Gmail terautentikasi.
 */
function get_gmail_api_service(): \Google\Service\Gmail
{
    $client = get_authenticated_google_client();
    return new \Google\Service\Gmail($client);
}

/**
 * Encode header UTF-8 (Subjek & Nama) sesuai RFC 2047.
 */
function mime_encode_header(string $value): string
{
    $clean = str_replace(["\r", "\n"], ' ', trim($value));
    if (preg_match('/[^\x20-\x7E]/', $clean)) {
        return '=?UTF-8?B?' . base64_encode($clean) . '?=';
    }
    return $clean;
}

/**
 * Bangun dokumen email RFC 2822 MIME lengkap untuk Gmail API / Drafts.
 * Memprioritaskan PHPMailer agar header From, Sender, Reply-To, Message-ID, MIME-Version, dan Content-Type konsisten tanpa duplikasi.
 */
function build_rfc2822_mime(array $params): string
{
    if (is_phpmailer_installed()) {
        $built = phpmailer_build_raw_mime($params);
        if ($built !== '') {
            return $built;
        }
    }

    $officialName  = DEFAULT_OFFICIAL_NAME;
    $supportEmail  = strtolower(DEFAULT_OFFICIAL_GMAIL);

    $toList        = $params['to'] ?? [];
    $ccList        = $params['cc'] ?? [];
    $bccList       = $params['bcc'] ?? [];
    $subject       = (string) ($params['subject'] ?? '(Tanpa Subjek)');
    $htmlContent   = (string) ($params['html'] ?? '');
    $plainContent  = (string) ($params['plain'] ?? html_to_plain_text($htmlContent));
    $inReplyTo     = trim((string) ($params['in_reply_to'] ?? ''));
    $references    = trim((string) ($params['references'] ?? ''));
    $attachments   = $params['attachments'] ?? [];

    $headers = [];
    $headers[] = 'From: ' . mime_encode_header($officialName) . ' <' . $supportEmail . '>';
    $headers[] = 'Reply-To: ' . mime_encode_header($officialName) . ' <' . $supportEmail . '>';
    if (!empty($toList)) {
        $headers[] = 'To: ' . implode(', ', $toList);
    }
    if (!empty($ccList)) {
        $headers[] = 'Cc: ' . implode(', ', $ccList);
    }
    if (!empty($bccList)) {
        $headers[] = 'Bcc: ' . implode(', ', $bccList);
    }
    $headers[] = 'Subject: ' . mime_encode_header($subject);
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'Message-ID: <ss-' . bin2hex(random_bytes(12)) . '@gmail.com>';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'X-Mailer: Support Septian Mailer';

    if ($inReplyTo !== '') {
        $headers[] = 'In-Reply-To: ' . str_replace(["\r", "\n"], '', $inReplyTo);
    }
    if ($references !== '') {
        $headers[] = 'References: ' . str_replace(["\r", "\n"], '', $references);
    }

    $altBoundary = 'ss_alt_' . bin2hex(random_bytes(10));

    $altPart  = "--{$altBoundary}\r\n";
    $altPart .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $altPart .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $altPart .= chunk_split(base64_encode($plainContent)) . "\r\n";

    $altPart .= "--{$altBoundary}\r\n";
    $altPart .= "Content-Type: text/html; charset=UTF-8\r\n";
    $altPart .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $altPart .= chunk_split(base64_encode($htmlContent)) . "\r\n";
    $altPart .= "--{$altBoundary}--\r\n";

    if (empty($attachments)) {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';
        return implode("\r\n", $headers) . "\r\n\r\n" . $altPart;
    }

    $mixBoundary = 'ss_mix_' . bin2hex(random_bytes(10));
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixBoundary . '"';

    $body  = "--{$mixBoundary}\r\n";
    $body .= 'Content-Type: multipart/alternative; boundary="' . $altBoundary . "\"\r\n\r\n";
    $body .= $altPart . "\r\n";

    foreach ($attachments as $att) {
        $safeFilename = str_replace(['"', "\r", "\n"], '_', (string) $att['name']);
        $mimeType     = (string) ($att['mime'] ?: 'application/octet-stream');
        $body .= "--{$mixBoundary}\r\n";
        $body .= "Content-Type: {$mimeType}; name=\"{$safeFilename}\"\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$safeFilename}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode((string) $att['content'])) . "\r\n";
    }

    $body .= "--{$mixBoundary}--\r\n";

    return implode("\r\n", $headers) . "\r\n\r\n" . $body;
}

/**
 * Ekstrak header spesifik dari objek Gmail MessagePart.
 */
function gmail_extract_headers(array $headersList): array
{
    $map = [];
    foreach ($headersList as $h) {
        $name = strtolower((string) $h->getName());
        $map[$name] = (string) $h->getValue();
    }
    return $map;
}

/**
 * Ekstrak isi HTML, Plain Text, dan daftar lampiran dari payload Gmail API secara rekursif.
 */
function gmail_extract_payload_parts(\Google\Service\Gmail\MessagePart $payload, string $messageId): array
{
    $html        = '';
    $plain       = '';
    $attachments = [];

    $walk = function (\Google\Service\Gmail\MessagePart $part) use (&$walk, &$html, &$plain, &$attachments, $messageId): void {
        $mime     = strtolower((string) $part->getMimeType());
        $filename = (string) $part->getFilename();
        $body     = $part->getBody();

        if ($filename !== '' && $body && $body->getAttachmentId()) {
            $attachments[] = [
                'attachment_id' => (string) $body->getAttachmentId(),
                'message_id'    => $messageId,
                'filename'      => $filename,
                'mime_type'     => $mime ?: 'application/octet-stream',
                'size'          => (int) $body->getSize(),
            ];
        } elseif ($body && $body->getData()) {
            $decoded = gmail_base64url_decode((string) $body->getData());
            if ($mime === 'text/html' && $html === '') {
                $html = $decoded;
            } elseif ($mime === 'text/plain' && $plain === '') {
                $plain = $decoded;
            }
        }

        $subParts = $part->getParts();
        if (is_array($subParts)) {
            foreach ($subParts as $sub) {
                $walk($sub);
            }
        }
    };

    $walk($payload);

    return [
        'html'        => $html,
        'plain'       => $plain,
        'attachments' => $attachments,
    ];
}

/**
 * Map folder aplikasi ke Gmail Label ID.
 */
function gmail_folder_label_id(string $folder): ?string
{
    return match (strtolower($folder)) {
        'inbox'   => 'INBOX',
        'sent'    => 'SENT',
        'drafts'  => 'DRAFT',
        'starred' => 'STARRED',
        'spam'    => 'SPAM',
        'trash'   => 'TRASH',
        default   => null,
    };
}

/**
 * Ambil statistik kotak surat sungguhan dari Gmail (Inbox, Unread, Sent, Drafts, Starred, Spam, Trash).
 */
function mail_get_dashboard_stats(): array
{
    if (is_app_password_mode()) {
        return imap_get_mailbox_stats();
    }

    $service = get_gmail_api_service();
    $labels  = [
        'INBOX'   => ['total' => 'inbox_total', 'unread' => 'inbox_unread'],
        'SENT'    => ['total' => 'sent_total'],
        'DRAFT'   => ['total' => 'drafts_total'],
        'STARRED' => ['total' => 'starred_total'],
        'SPAM'    => ['total' => 'spam_total'],
        'TRASH'   => ['total' => 'trash_total'],
    ];

    $stats = [
        'inbox_total'   => 0,
        'inbox_unread'  => 0,
        'sent_total'    => 0,
        'drafts_total'  => 0,
        'starred_total' => 0,
        'spam_total'    => 0,
        'trash_total'   => 0,
    ];

    foreach ($labels as $labelId => $keys) {
        try {
            $lbl = $service->users_labels->get('me', $labelId);
            $stats[$keys['total']] = (int) $lbl->getMessagesTotal();
            if (isset($keys['unread'])) {
                $stats[$keys['unread']] = (int) $lbl->getMessagesUnread();
            }
        } catch (Throwable $e) {
            app_log('warning', 'Gagal membaca label Gmail ' . $labelId . ': ' . $e->getMessage());
        }
    }

    return $stats;
}

/**
 * Ambil daftar email berdasarkan folder dan/atau kueri pencarian (mendukung pagination Gmail).
 */
function mail_list_messages(string $folder = 'inbox', int $maxResults = 20, ?string $pageToken = null, string $searchQuery = ''): array
{
    $maxResults = max(5, min(50, $maxResults));

    if (is_app_password_mode()) {
        return imap_list_messages($folder, $maxResults, $pageToken, $searchQuery);
    }

    $service = get_gmail_api_service();
    $params  = [
        'maxResults' => $maxResults,
    ];

    $labelId = gmail_folder_label_id($folder);
    if ($labelId !== null) {
        $params['labelIds'] = [$labelId];
        if (in_array($labelId, ['SPAM', 'TRASH'], true)) {
            $params['includeSpamTrash'] = true;
        }
    }
    if ($pageToken !== null && $pageToken !== '') {
        $params['pageToken'] = $pageToken;
    }
    if (trim($searchQuery) !== '') {
        $params['q'] = trim($searchQuery);
    }

    $draftMap = [];
    if (strtolower($folder) === 'drafts') {
        $draftList = $service->users_drafts->listUsersDrafts('me', array_filter([
            'maxResults' => $maxResults,
            'pageToken'  => $pageToken,
            'q'          => trim($searchQuery) !== '' ? trim($searchQuery) : null,
        ]));
        $items = [];
        foreach ((array) $draftList->getDrafts() as $dr) {
            $draftId = (string) $dr->getId();
            $msgRef  = $dr->getMessage();
            if ($msgRef && $msgRef->getId()) {
                $draftMap[(string) $msgRef->getId()] = $draftId;
                $items[] = $msgRef;
            }
        }
        $nextToken = $draftList->getNextPageToken();
        $estimate  = (int) $draftList->getResultSizeEstimate();
    } else {
        $listResp  = $service->users_messages->listUsersMessages('me', $params);
        $items     = (array) $listResp->getMessages();
        $nextToken = $listResp->getNextPageToken();
        $estimate  = (int) $listResp->getResultSizeEstimate();
    }

    $messages = [];
    foreach ($items as $ref) {
        $msgId = (string) $ref->getId();
        $msg = $service->users_messages->get('me', $msgId, [
            'format'          => 'metadata',
            'metadataHeaders' => ['From', 'To', 'Subject', 'Date'],
        ]);

        $payload = $msg->getPayload();
        $headers = $payload ? gmail_extract_headers((array) $payload->getHeaders()) : [];
        $from    = parse_sender_header($headers['from'] ?? '');
        $labels  = (array) $msg->getLabelIds();
        $dateRaw = $headers['date'] ?? '';

        $hasAttachments = false;
        if ($payload && is_array($payload->getParts())) {
            foreach ($payload->getParts() as $part) {
                if ((string) $part->getFilename() !== '') {
                    $hasAttachments = true;
                    break;
                }
            }
        }

        $messages[] = [
            'id'              => $msgId,
            'draft_id'        => $draftMap[$msgId] ?? null,
            'thread_id'       => (string) $msg->getThreadId(),
            'from_name'       => $from['name'] ?: '(Tanpa Nama)',
            'from_email'      => $from['email'],
            'to'              => $headers['to'] ?? '',
            'subject'         => ($headers['subject'] ?? '') !== '' ? $headers['subject'] : '(Tanpa Subjek)',
            'snippet'         => html_entity_decode((string) $msg->getSnippet(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'date_raw'        => $dateRaw,
            'date_short'      => format_short_date($dateRaw),
            'date_full'       => format_tanggal_id($dateRaw, true),
            'is_unread'       => in_array('UNREAD', $labels, true),
            'is_starred'      => in_array('STARRED', $labels, true),
            'has_attachments' => $hasAttachments,
            'labels'          => $labels,
        ];
    }

    return [
        'messages'        => $messages,
        'next_page_token' => $nextToken ?: null,
        'result_estimate' => $estimate,
        'transport'       => 'gmail_api_oauth',
    ];
}

/**
 * Baca seluruh percakapan (thread) berdasarkan Message ID atau Thread ID.
 */
function mail_get_thread_detail(string $messageId, bool $markAsRead = true): array
{
    if (is_app_password_mode()) {
        return imap_get_message_detail($messageId);
    }

    $service = get_gmail_api_service();
    $initialMsg = $service->users_messages->get('me', $messageId, ['format' => 'minimal']);
    $threadId   = (string) $initialMsg->getThreadId();

    $thread = $service->users_threads->get('me', $threadId, ['format' => 'full']);
    $rawMessages = (array) $thread->getMessages();

    $parsedMessages = [];
    $threadSubject  = '(Tanpa Subjek)';

    foreach ($rawMessages as $idx => $m) {
        $mid     = (string) $m->getId();
        $payload = $m->getPayload();
        $headers = $payload ? gmail_extract_headers((array) $payload->getHeaders()) : [];
        $parts   = $payload ? gmail_extract_payload_parts($payload, $mid) : ['html' => '', 'plain' => '', 'attachments' => []];
        $labels  = (array) $m->getLabelIds();

        $from    = parse_sender_header($headers['from'] ?? '');
        $subject = ($headers['subject'] ?? '') !== '' ? $headers['subject'] : '(Tanpa Subjek)';
        if ($idx === 0) {
            $threadSubject = $subject;
        }

        $safeHtml = $parts['html'] !== ''
            ? sanitize_email_html($parts['html'])
            : '<div style="white-space:pre-wrap;font-family:inherit;line-height:1.65;">' . e($parts['plain']) . '</div>';

        if ($markAsRead && in_array('UNREAD', $labels, true)) {
            try {
                $mod = new \Google\Service\Gmail\ModifyMessageRequest();
                $mod->setRemoveLabelIds(['UNREAD']);
                $service->users_messages->modify('me', $mid, $mod);
                $labels = array_values(array_diff($labels, ['UNREAD']));
            } catch (Throwable $e) {
            }
        }

        $parsedMessages[] = [
            'id'             => $mid,
            'thread_id'      => $threadId,
            'message_id_hdr' => $headers['message-id'] ?? '',
            'references_hdr' => $headers['references'] ?? '',
            'from_raw'       => $headers['from'] ?? '',
            'from_name'      => $from['name'] ?: '(Tanpa Nama)',
            'from_email'     => $from['email'],
            'to'             => $headers['to'] ?? '',
            'cc'             => $headers['cc'] ?? '',
            'reply_to'       => $headers['reply-to'] ?? ($headers['from'] ?? ''),
            'subject'        => $subject,
            'date_raw'       => $headers['date'] ?? '',
            'date_full'      => format_tanggal_id($headers['date'] ?? '', true),
            'body_html'      => $safeHtml,
            'body_plain'     => $parts['plain'] !== '' ? $parts['plain'] : html_to_plain_text($parts['html']),
            'attachments'    => $parts['attachments'],
            'is_unread'      => in_array('UNREAD', $labels, true),
            'is_starred'     => in_array('STARRED', $labels, true),
            'labels'         => $labels,
        ];
    }

    return [
        'thread_id' => $threadId,
        'subject'   => $threadSubject,
        'messages'  => $parsedMessages,
        'transport' => 'gmail_api_oauth',
    ];
}

/**
 * Kirim email sungguhan (Compose, Reply, Reply All, Forward) melalui:
 * - PHPMailer SMTP Gmail terautentikasi (smtp.gmail.com:465 SSL, App Password), ATAU
 * - Google Gmail API (OAuth 2.0)
 * Tidak pernah memakai PHP mail(), sendmail(), atau SMTP lokal hosting.
 */
function mail_send_email(array $payload, bool $forceSmtp = false): array
{
    $toList  = parse_email_list((string) ($payload['to'] ?? ''));
    $ccList  = parse_email_list((string) ($payload['cc'] ?? ''));
    $bccList = parse_email_list((string) ($payload['bcc'] ?? ''));

    if (empty($toList)) {
        throw new RuntimeException('Minimal satu alamat email penerima (Kepada) yang valid wajib diisi.');
    }

    $subject = trim((string) ($payload['subject'] ?? ''));
    if ($subject === '') {
        throw new RuntimeException('Subjek email tidak boleh kosong.');
    }

    $bodyHtml     = (string) ($payload['body_html'] ?? '');
    $quotedHtml   = (string) ($payload['quoted_html'] ?? '');
    $wrapTemplate = (bool) ($payload['wrap_template'] ?? true);
    $variables    = is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
    $attachments  = is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [];
    $threadId     = trim((string) ($payload['thread_id'] ?? ''));
    $inReplyTo    = trim((string) ($payload['in_reply_to'] ?? ''));
    $references   = trim((string) ($payload['references'] ?? ''));

    if (!isset($variables['email_penerima'])) {
        $variables['email_penerima'] = $toList[0];
    }
    if (!isset($variables['nama_penerima']) || trim((string) $variables['nama_penerima']) === '') {
        $variables['nama_penerima'] = explode('@', $toList[0])[0];
    }

    // Pertahankan seluruh template HTML resmi Support Septian (logo, footer, alamat, tanda tangan)
    $finalHtml = $wrapTemplate
        ? render_professional_email_html($bodyHtml, $subject, $variables, true, $quotedHtml)
        : sanitize_outgoing_body_html($bodyHtml);

    $plainBody = html_to_plain_text($finalHtml);

    // Jalur PHPMailer SMTP Gmail terautentikasi (wajib untuk mode App Password atau forceSmtp)
    if ($forceSmtp || is_app_password_mode()) {
        $res = phpmailer_send_email([
            'to'          => $toList,
            'cc'          => $ccList,
            'bcc'         => $bccList,
            'subject'     => $subject,
            'html'        => $finalHtml,
            'plain'       => $plainBody,
            'in_reply_to' => $inReplyTo,
            'references'  => $references,
            'attachments' => $attachments,
        ], !empty($payload['capture_debug']));

        log_activity(
            'email_sent',
            'Email terkirim via PHPMailer SMTP Gmail (smtp.gmail.com:465 SSL) ke ' . implode(', ', $toList) . ' — Subjek: ' . $subject,
            'email',
            $res['id']
        );
        return $res;
    }

    // Jalur Gmail API OAuth 2.0 (jika mode OAuth aktif dan terhubung)
    $rawMime = build_rfc2822_mime([
        'to'          => $toList,
        'cc'          => $ccList,
        'bcc'         => $bccList,
        'subject'     => $subject,
        'html'        => $finalHtml,
        'plain'       => $plainBody,
        'in_reply_to' => $inReplyTo,
        'references'  => $references,
        'attachments' => $attachments,
    ]);

    $service = get_gmail_api_service();
    $gmailMessage = new \Google\Service\Gmail\Message();
    $gmailMessage->setRaw(gmail_base64url_encode($rawMime));
    if ($threadId !== '') {
        $gmailMessage->setThreadId($threadId);
    }

    $sent = $service->users_messages->send('me', $gmailMessage);

    log_activity(
        'email_sent',
        'Email terkirim via Gmail API OAuth 2.0 ke ' . implode(', ', $toList) . ' — Subjek: ' . $subject,
        'email',
        (string) $sent->getId()
    );

    return [
        'id'                 => (string) $sent->getId(),
        'thread_id'          => (string) $sent->getThreadId(),
        'transport'          => 'gmail_api_oauth',
        'smtp_connected'     => true,
        'smtp_authenticated' => true,
        'account_email'      => DEFAULT_OFFICIAL_GMAIL,
        'from_email'         => DEFAULT_OFFICIAL_GMAIL,
        'from_name'          => DEFAULT_OFFICIAL_NAME,
    ];
}

/**
 * Kirim satu email percobaan diagnostik ke alamat email admin lain (tidak pernah mengulang otomatis).
 */
function send_diagnostic_test_email(string $recipientEmail, string $adminName = DEFAULT_OFFICIAL_NAME): array
{
    $recipientEmail = strtolower(trim($recipientEmail));
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Alamat email tujuan tes tidak valid.');
    }

    $appPassSet = trim(get_setting('gmail_app_password_secret', '')) !== '';
    $oauthToken = get_stored_oauth_token();
    $useSmtp    = is_app_password_mode() || $appPassSet || !$oauthToken || $oauthToken['status'] !== 'connected';

    $subject = 'Uji Pengiriman & Verifikasi DKIM Support Septian (' . date('d M Y H:i') . ' WIB)';
    $bodyHtml = '<p>Halo <strong>{nama_penerima}</strong>,</p>'
        . '<p>Ini adalah <strong>email uji pengiriman diagnostik</strong> dari aplikasi <strong>Support Septian F. R.</strong>.</p>'
        . '<p>Pengiriman ini dijalankan melalui koneksi terautentikasi untuk memverifikasi keaslian pengirim, header MIME, serta kelulusan tanda tangan DKIM Google (<code>DKIM=PASS</code>).</p>'
        . '<ul>'
        . '<li><strong>Akun Pengirim Resmi:</strong> ' . e(DEFAULT_OFFICIAL_GMAIL) . '</li>'
        . '<li><strong>Nama Pengirim:</strong> ' . e(DEFAULT_OFFICIAL_NAME) . '</li>'
        . '<li><strong>Metode Pengiriman:</strong> ' . ($useSmtp ? 'PHPMailer SMTP (smtp.gmail.com:465 SSL)' : 'Google Gmail API (OAuth 2.0)') . '</li>'
        . '<li><strong>Waktu Uji:</strong> ' . e(format_tanggal_id(date('Y-m-d H:i:s'), true)) . '</li>'
        . '</ul>'
        . '<p>Silakan buka menu <strong>Lainnya (&vellip;) &rarr; Tampilkan Asli (Show Original)</strong> pada email ini di Gmail untuk memastikan <code>DKIM: PASS</code>.</p>';

    $result = mail_send_email([
        'to'            => $recipientEmail,
        'subject'       => $subject,
        'body_html'     => $bodyHtml,
        'wrap_template' => true,
        'capture_debug' => true,
        'variables'     => [
            'nama_penerima'  => explode('@', $recipientEmail)[0],
            'email_penerima' => $recipientEmail,
            'nama_pengirim'  => $adminName ?: DEFAULT_OFFICIAL_NAME,
            'subjek'         => $subject,
        ],
    ], $useSmtp);

    log_activity(
        'diagnostic_test_send',
        'Satu email tes diagnostik dikirim ke ' . $recipientEmail . ' via ' . ($result['transport'] ?? 'smtp'),
        'diagnostics',
        $result['id'] ?? null
    );

    return $result;
}

/**
 * Simpan draft sungguhan ke Gmail Drafts.
 */
function mail_save_draft(array $payload): array
{
    $toList      = parse_email_list((string) ($payload['to'] ?? ''));
    $ccList      = parse_email_list((string) ($payload['cc'] ?? ''));
    $bccList     = parse_email_list((string) ($payload['bcc'] ?? ''));
    $subject     = trim((string) ($payload['subject'] ?? '(Tanpa Subjek)'));
    $bodyHtml    = (string) ($payload['body_html'] ?? '');
    $variables   = is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
    $attachments = is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [];
    $draftId     = trim((string) ($payload['draft_id'] ?? ''));
    $threadId    = trim((string) ($payload['thread_id'] ?? ''));

    $finalHtml = render_professional_email_html($bodyHtml, $subject, $variables, true);

    $rawMime = build_rfc2822_mime([
        'to'          => $toList,
        'cc'          => $ccList,
        'bcc'         => $bccList,
        'subject'     => $subject,
        'html'        => $finalHtml,
        'attachments' => $attachments,
    ]);

    if (is_app_password_mode()) {
        $res = imap_save_draft($rawMime);
        log_activity('draft_saved', 'Draft email disimpan via IMAP — Subjek: ' . $subject, 'draft', $res['draft_id']);
        return $res;
    }

    $service = get_gmail_api_service();
    $gmailMsg = new \Google\Service\Gmail\Message();
    $gmailMsg->setRaw(gmail_base64url_encode($rawMime));
    if ($threadId !== '') {
        $gmailMsg->setThreadId($threadId);
    }

    $draft = new \Google\Service\Gmail\Draft();
    $draft->setMessage($gmailMsg);

    if ($draftId !== '') {
        $saved = $service->users_drafts->update('me', $draftId, $draft);
    } else {
        $saved = $service->users_drafts->create('me', $draft);
    }

    log_activity('draft_saved', 'Draft email disimpan ke Gmail — Subjek: ' . $subject, 'draft', (string) $saved->getId());

    return [
        'draft_id'   => (string) $saved->getId(),
        'message_id' => $saved->getMessage() ? (string) $saved->getMessage()->getId() : '',
        'transport'  => 'gmail_api_oauth',
    ];
}

/**
 * Jalankan aksi pada pesan Gmail: mark_read, mark_unread, star, unstar, archive, trash, untrash, spam, not_spam, delete.
 */
function mail_perform_message_action(string $messageId, string $action): bool
{
    if (is_app_password_mode()) {
        $ok = imap_perform_action($messageId, $action);
        log_activity('email_action', "Aksi '{$action}' dijalankan pada pesan {$messageId} (IMAP).", 'email', $messageId);
        return $ok;
    }

    $service = get_gmail_api_service();

    switch ($action) {
        case 'mark_read':
            $req = new \Google\Service\Gmail\ModifyMessageRequest();
            $req->setRemoveLabelIds(['UNREAD']);
            $service->users_messages->modify('me', $messageId, $req);
            break;

        case 'mark_unread':
            $req = new \Google\Service\Gmail\ModifyMessageRequest();
            $req->setAddLabelIds(['UNREAD']);
            $service->users_messages->modify('me', $messageId, $req);
            break;

        case 'star':
            $req = new \Google\Service\Gmail\ModifyMessageRequest();
            $req->setAddLabelIds(['STARRED']);
            $service->users_messages->modify('me', $messageId, $req);
            break;

        case 'unstar':
            $req = new \Google\Service\Gmail\ModifyMessageRequest();
            $req->setRemoveLabelIds(['STARRED']);
            $service->users_messages->modify('me', $messageId, $req);
            break;

        case 'archive':
            $req = new \Google\Service\Gmail\ModifyMessageRequest();
            $req->setRemoveLabelIds(['INBOX']);
            $service->users_messages->modify('me', $messageId, $req);
            break;

        case 'spam':
            $req = new \Google\Service\Gmail\ModifyMessageRequest();
            $req->setAddLabelIds(['SPAM']);
            $req->setRemoveLabelIds(['INBOX']);
            $service->users_messages->modify('me', $messageId, $req);
            break;

        case 'not_spam':
            $req = new \Google\Service\Gmail\ModifyMessageRequest();
            $req->setRemoveLabelIds(['SPAM']);
            $req->setAddLabelIds(['INBOX']);
            $service->users_messages->modify('me', $messageId, $req);
            break;

        case 'trash':
            $service->users_messages->trash('me', $messageId);
            break;

        case 'untrash':
            $service->users_messages->untrash('me', $messageId);
            break;

        case 'delete':
            $service->users_messages->delete('me', $messageId);
            break;

        default:
            throw new RuntimeException('Aksi email tidak dikenal: ' . $action);
    }

    log_activity('email_action', "Aksi '{$action}' berhasil pada pesan {$messageId}.", 'email', $messageId);
    return true;
}

/**
 * Unduh file lampiran dari Gmail API atau IMAP.
 */
function mail_download_attachment(string $messageId, string $attachmentId, string $fallbackFilename = 'lampiran'): array
{
    if (is_app_password_mode()) {
        return imap_download_attachment($messageId, $attachmentId);
    }

    $service = get_gmail_api_service();
    $att     = $service->users_messages_attachments->get('me', $messageId, $attachmentId);
    $binary  = gmail_base64url_decode((string) $att->getData());

    $safeName = preg_replace('/[^A-Za-z0-9._\- ]/', '_', basename($fallbackFilename)) ?: 'lampiran';

    return [
        'filename'  => $safeName,
        'mime_type' => 'application/octet-stream',
        'data'      => $binary,
    ];
}
