<?php
/**
 * Skyzz Mail — Unified Multi-Mailbox Email Service Layer
 *
 * - Mendukung banyak akun email (mailboxes) yang dipilih user
 * - Menegakkan kuota kirim harian per paket (Free 10, Lite 50, Pro 100, Business 200)
 * - GAGAL KIRIM TIDAK MENGURANGI KUOTA HARIAN!
 * - Terima pesan TANPA BATASAN untuk semua paket
 * - Watermark otomatis untuk paket Free ("Dikirim Menggunakan Aplikasi Skyzz Mail")
 * - Cache server-side untuk Inbox/Folder & Dashboard Stats (performa cepat)
 * - Fallback log lokal Terkirim jika php-imap tidak aktif di hosting
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

require_once __DIR__ . '/gmail-client.php';
require_once __DIR__ . '/smtp-imap-service.php';
require_once __DIR__ . '/email-renderer.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/saas.php';

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

function is_app_password_mode(?array $mailbox = null): bool
{
    $mb = $mailbox ?? get_effective_mailbox();
    return ($mb['provider_mode'] ?? get_setting('mail_connection_mode', 'app_password')) === 'app_password';
}

function get_gmail_api_service(): \Google\Service\Gmail
{
    $client = get_authenticated_google_client();
    return new \Google\Service\Gmail($client);
}

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
 */
function build_rfc2822_mime(array $params, ?array $mailbox = null): string
{
    if (is_phpmailer_installed()) {
        $built = phpmailer_build_raw_mime($params, $mailbox);
        if ($built !== '') {
            return $built;
        }
    }

    $mb           = $mailbox ?? get_effective_mailbox();
    $officialName = trim((string) ($params['from_name'] ?? '')) ?: (string) ($mb['display_name'] ?? DEFAULT_OFFICIAL_NAME);
    $supportEmail = strtolower(trim((string) ($mb['email'] ?? DEFAULT_OFFICIAL_GMAIL)));

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
    $headers[] = 'Message-ID: <skz-' . bin2hex(random_bytes(12)) . '@gmail.com>';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'X-Mailer: Skyzz Mail';

    if ($inReplyTo !== '') {
        $headers[] = 'In-Reply-To: ' . str_replace(["\r", "\n"], '', $inReplyTo);
    }
    if ($references !== '') {
        $headers[] = 'References: ' . str_replace(["\r", "\n"], '', $references);
    }

    $altBoundary = 'skz_alt_' . bin2hex(random_bytes(10));

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

    $mixBoundary = 'skz_mix_' . bin2hex(random_bytes(10));
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

function gmail_extract_headers(array $headersList): array
{
    $map = [];
    foreach ($headersList as $h) {
        $name = strtolower((string) $h->getName());
        $map[$name] = (string) $h->getValue();
    }
    return $map;
}

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
 * Ambil statistik kotak surat dengan server-side cache (60 detik).
 */
function mail_get_dashboard_stats(bool $forceRefresh = false, ?array $mailbox = null): array
{
    $mb    = $mailbox ?? get_effective_mailbox();
    $mbId  = (int) ($mb['id'] ?? 0);
    $cacheKey = 'mb_' . $mbId . '_stats';

    if (!$forceRefresh) {
        $cached = cache_get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
    }

    if (is_app_password_mode($mb)) {
        $stats = imap_get_mailbox_stats($mb);
    } else {
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
    }

    // Jika sent_total dari IMAP/Gmail 0 (mis. php-imap tidak aktif), lengkapi dari log lokal
    if ((int) ($stats['sent_total'] ?? 0) === 0) {
        try {
            $localCnt = (int) db()->query("SELECT COUNT(*) FROM sent_email_logs WHERE status = 'sent'")->fetchColumn();
            $stats['sent_total'] = $localCnt;
        } catch (Throwable $e) {
        }
    }

    cache_set($cacheKey, $stats, 60);
    return $stats;
}

/**
 * Ambil daftar email berdasarkan folder dan/atau pencarian (dengan cache 45 detik & fallback log Terkirim).
 * Terima pesan (Inbox) berlaku TANPA BATASAN untuk semua paket.
 */
function mail_list_messages(
    string $folder = 'inbox',
    int $maxResults = 20,
    ?string $pageToken = null,
    string $searchQuery = '',
    bool $forceRefresh = false,
    ?array $mailbox = null
): array {
    $maxResults = max(5, min(50, $maxResults));
    $mb         = $mailbox ?? get_effective_mailbox();
    $mbId       = (int) ($mb['id'] ?? 0);
    $actor      = function_exists('current_admin') ? current_admin() : null;
    $isSuper    = is_superadmin($actor);
    $folderLow  = strtolower($folder);

    $cacheKey = sprintf(
        'mb_%d_list_%s_%d_%s_%s',
        $mbId,
        $folderLow,
        $maxResults,
        (string) ($pageToken ?? '0'),
        md5(strtolower(trim($searchQuery)) . ':' . ($isSuper ? 's' : (string) ($actor['id'] ?? '0')))
    );

    if (!$forceRefresh) {
        $cached = cache_get($cacheKey);
        if (is_array($cached)) {
            $cached['from_cache'] = true;
            return $cached;
        }
    }

    if (is_app_password_mode($mb)) {
        try {
            $res = imap_list_messages($folderLow, $maxResults, $pageToken, $searchQuery, $mb);
            // Untuk folder 'sent', jika IMAP kosong tapi ada log terkirim lokal, tampilkan log lokal
            if ($folderLow === 'sent' && empty($res['messages'])) {
                $localRes = get_local_sent_messages(
                    $actor ? (int) $actor['id'] : null,
                    $mbId,
                    $isSuper,
                    $maxResults,
                    max(0, (int) ($pageToken ?: 0)),
                    $searchQuery
                );
                if (!empty($localRes['messages'])) {
                    $res = $localRes;
                }
            }
            cache_set($cacheKey, $res, 45);
            return $res;
        } catch (Throwable $e) {
            if ($folderLow === 'sent') {
                // Fallback mulus ke riwayat Terkirim lokal agar Terkirim tidak pernah error 500
                $localRes = get_local_sent_messages(
                    $actor ? (int) $actor['id'] : null,
                    $mbId,
                    $isSuper,
                    $maxResults,
                    max(0, (int) ($pageToken ?: 0)),
                    $searchQuery
                );
                $localRes['notice'] = 'Menampilkan riwayat Terkirim aplikasi (IMAP sedang tidak tersedia: ' . $e->getMessage() . ').';
                return $localRes;
            }
            throw $e;
        }
    }

    // Mode Gmail API (OAuth 2.0)
    $service = get_gmail_api_service();
    $params  = [
        'maxResults' => $maxResults,
    ];

    $labelId = gmail_folder_label_id($folderLow);
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
    if ($folderLow === 'drafts') {
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
        if ($msgId === '') {
            continue;
        }
        try {
            $msg = $service->users_messages->get('me', $msgId, [
                'format'          => 'metadata',
                'metadataHeaders' => ['From', 'To', 'Subject', 'Date'],
            ]);

            $payload = $msg->getPayload();
            $headers = $payload ? gmail_extract_headers((array) $payload->getHeaders()) : [];
            $from    = parse_sender_header((string) ($headers['from'] ?? ''));
            $labels  = (array) $msg->getLabelIds();
            $dateRaw = (string) ($headers['date'] ?? '');

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
                'from_name'       => $folderLow === 'sent' ? ('Kepada: ' . ((string) ($headers['to'] ?? '(Penerima)'))) : ($from['name'] ?: '(Tanpa Nama)'),
                'from_email'      => $from['email'],
                'to'              => (string) ($headers['to'] ?? ''),
                'subject'         => ($headers['subject'] ?? '') !== '' ? (string) $headers['subject'] : '(Tanpa Subjek)',
                'snippet'         => html_entity_decode((string) $msg->getSnippet(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'date_raw'        => $dateRaw,
                'date_short'      => format_short_date($dateRaw),
                'date_full'       => format_tanggal_id($dateRaw, true),
                'is_unread'       => in_array('UNREAD', $labels, true),
                'is_starred'      => in_array('STARRED', $labels, true),
                'has_attachments' => $hasAttachments,
                'labels'          => $labels,
            ];
        } catch (Throwable $e) {
            app_log('warning', 'Lewati pesan Gmail ' . $msgId . ' di folder ' . $folderLow . ': ' . $e->getMessage());
        }
    }

    $result = [
        'messages'        => $messages,
        'next_page_token' => $nextToken ?: null,
        'result_estimate' => $estimate,
        'transport'       => 'gmail_api_oauth',
    ];
    cache_set($cacheKey, $result, 45);
    return $result;
}

/**
 * Baca seluruh percakapan (thread) berdasarkan Message ID (termasuk local:<id> dari sent_email_logs).
 */
function mail_get_thread_detail(string $messageId, bool $markAsRead = true, ?array $mailbox = null): array
{
    if (str_starts_with($messageId, 'local:')) {
        $localId = (int) substr($messageId, 6);
        $viewer  = function_exists('current_admin') ? current_admin() : null;
        return get_local_sent_message_detail($localId, $viewer);
    }

    $mb = $mailbox ?? get_effective_mailbox();
    if (is_app_password_mode($mb)) {
        return imap_get_message_detail($messageId, $mb);
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
 * Kirim email sungguhan (Web Compose/Reply/Forward maupun REST API).
 * - Mengecek kuota harian pengguna sebelum kirim
 * - Jika pengiriman GAGAL, kuota harian TIDAK berkurang (hanya status='sent' yang dihitung)
 * - Paket Free otomatis menambahkan watermark: "Dikirim Menggunakan Aplikasi Skyzz Mail"
 * - Otomatis menyinkronkan salinan ke folder Terkirim Gmail & riwayat lokal sent_email_logs
 */
function mail_send_email(array $payload, bool $forceSmtp = false): array
{
    $actor   = is_array($payload['actor'] ?? null) ? $payload['actor'] : (function_exists('current_admin') ? current_admin() : null);
    $mailbox = is_array($payload['mailbox'] ?? null) ? $payload['mailbox'] : get_effective_mailbox($actor);
    $sentVia = ($payload['sent_via'] ?? 'web') === 'api' ? 'api' : 'web';

    // 1. Cek kuota kirim harian (Superadmin tidak dibatasi)
    $plan = null;
    if ($actor) {
        $quota = check_user_send_quota($actor);
        $plan  = $quota['plan'];
    }

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

    // Nama pengirim kustom (dari parameter, profil user, atau mailbox)
    $senderName = trim((string) ($payload['from_name'] ?? ''))
        ?: trim((string) ($actor['custom_sender_name'] ?? ''))
        ?: trim((string) ($mailbox['display_name'] ?? ''))
        ?: get_setting('official_name', DEFAULT_OFFICIAL_NAME);
    $senderEmail = strtolower(trim((string) ($mailbox['email'] ?? DEFAULT_OFFICIAL_GMAIL)));

    // Watermark wajib untuk paket Free
    $watermarkText = '';
    if ($plan && !empty($plan['has_watermark'])) {
        $watermarkText = trim((string) ($plan['watermark_text'] ?? '')) ?: FREE_WATERMARK_DEFAULT;
    }

    if (!isset($variables['email_penerima'])) {
        $variables['email_penerima'] = $toList[0];
    }
    if (!isset($variables['nama_penerima']) || trim((string) $variables['nama_penerima']) === '') {
        $variables['nama_penerima'] = explode('@', $toList[0])[0];
    }
    if (!isset($variables['nama_pengirim']) || trim((string) $variables['nama_pengirim']) === '') {
        $variables['nama_pengirim'] = $senderName;
    }

    $renderOpts = [
        'sender_name'    => $senderName,
        'sender_email'   => $senderEmail,
        'watermark_text' => $watermarkText,
    ];
    if ($actor && !empty($actor['custom_signature'])) {
        $renderOpts['signature'] = (string) $actor['custom_signature'];
    }
    if ($actor && isset($actor['custom_footer_note']) && trim((string) $actor['custom_footer_note']) !== '') {
        $renderOpts['footer_note'] = (string) $actor['custom_footer_note'];
    }

    if ($wrapTemplate) {
        $finalHtml = render_professional_email_html($bodyHtml, $subject, $variables, true, $quotedHtml, $renderOpts);
    } else {
        $resolved  = replace_template_variables($bodyHtml, array_merge($variables, ['subjek' => $subject]));
        $finalHtml = sanitize_outgoing_body_html($resolved);
        if ($watermarkText !== '') {
            $finalHtml = append_plain_watermark_html($finalHtml, $watermarkText);
        }
    }

    $plainBody = html_to_plain_text($finalHtml);
    $preview   = safe_substr(preg_replace('/\s+/', ' ', $plainBody) ?? $plainBody, 0, 260);
    $mbId      = (int) ($mailbox['id'] ?? 0);

    try {
        if ($forceSmtp || is_app_password_mode($mailbox)) {
            $res = phpmailer_send_email([
                'to'          => $toList,
                'cc'          => $ccList,
                'bcc'         => $bccList,
                'subject'     => $subject,
                'html'        => $finalHtml,
                'plain'       => $plainBody,
                'from_name'   => $senderName,
                'in_reply_to' => $inReplyTo,
                'references'  => $references,
                'attachments' => $attachments,
            ], !empty($payload['capture_debug']), $mailbox);
        } else {
            $rawMime = build_rfc2822_mime([
                'to'          => $toList,
                'cc'          => $ccList,
                'bcc'         => $bccList,
                'subject'     => $subject,
                'html'        => $finalHtml,
                'plain'       => $plainBody,
                'from_name'   => $senderName,
                'in_reply_to' => $inReplyTo,
                'references'  => $references,
                'attachments' => $attachments,
            ], $mailbox);

            $service = get_gmail_api_service();
            $gmailMessage = new \Google\Service\Gmail\Message();
            $gmailMessage->setRaw(gmail_base64url_encode($rawMime));
            if ($threadId !== '') {
                $gmailMessage->setThreadId($threadId);
            }

            $sent = $service->users_messages->send('me', $gmailMessage);
            $res = [
                'id'                 => (string) $sent->getId(),
                'thread_id'          => (string) $sent->getThreadId(),
                'transport'          => 'gmail_api_oauth',
                'smtp_connected'     => true,
                'smtp_authenticated' => true,
                'account_email'      => $senderEmail,
                'from_email'         => $senderEmail,
                'from_name'          => $senderName,
                'message_id'         => (string) $sent->getId(),
                'imap_sent_synced'   => true, // Gmail API selalu mencatat di Sent Gmail
            ];
        }

        // Pengiriman SUKSES -> catat status='sent' (baru di sinilah kuota harian terpakai 1)
        record_sent_email_log([
            'user_id'          => $actor ? (int) $actor['id'] : null,
            'mailbox_id'       => $mbId,
            'from_email'       => $res['from_email'] ?? $senderEmail,
            'to_emails'        => implode(', ', $toList),
            'cc_emails'        => implode(', ', $ccList),
            'subject'          => $subject,
            'body_preview'     => $preview,
            'body_html'        => $finalHtml,
            'transport'        => $res['transport'] ?? 'phpmailer_gmail_smtp',
            'message_id'       => $res['message_id'] ?? $res['id'] ?? '',
            'imap_sent_synced' => !empty($res['imap_sent_synced']),
            'sent_via'         => $sentVia,
            'status'           => 'sent',
        ]);

        // Bersihkan cache daftar email & statistik agar folder Terkirim langsung terbaru
        cache_forget_prefix('mb_' . $mbId . '_');

        log_activity(
            'email_sent',
            'Email terkirim (' . strtoupper($sentVia) . ') dari ' . $senderEmail . ' ke ' . implode(', ', $toList) . ' — Subjek: ' . $subject,
            'email',
            (string) ($res['id'] ?? ''),
            'success',
            $actor ? (int) $actor['id'] : null
        );

        if ($actor) {
            $afterQuota = get_user_quota_status($actor);
            $res['quota'] = [
                'plan'        => $afterQuota['plan']['name'],
                'used_today'  => $afterQuota['used_today'],
                'daily_limit' => $afterQuota['daily_limit'],
                'remaining'   => $afterQuota['remaining'],
                'unlimited'   => $afterQuota['is_unlimited'],
            ];
        }

        return $res;
    } catch (Throwable $e) {
        // Pengiriman GAGAL -> catat status='failed' sehingga KUOTA HARIAN TIDAK BERKURANG!
        record_sent_email_log([
            'user_id'          => $actor ? (int) $actor['id'] : null,
            'mailbox_id'       => $mbId,
            'from_email'       => $senderEmail,
            'to_emails'        => implode(', ', $toList),
            'cc_emails'        => implode(', ', $ccList),
            'subject'          => $subject,
            'body_preview'     => $preview,
            'transport'        => $forceSmtp || is_app_password_mode($mailbox) ? 'phpmailer_gmail_smtp' : 'gmail_api_oauth',
            'imap_sent_synced' => false,
            'sent_via'         => $sentVia,
            'status'           => 'failed',
            'error_message'    => $e->getMessage(),
        ]);

        throw $e;
    }
}

/**
 * Kirim satu email percobaan diagnostik ke alamat email admin lain (tidak mengulang otomatis).
 */
function send_diagnostic_test_email(string $recipientEmail, string $adminName = DEFAULT_OFFICIAL_NAME, ?array $mailbox = null): array
{
    $recipientEmail = strtolower(trim($recipientEmail));
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Alamat email tujuan tes tidak valid.');
    }

    $mb         = $mailbox ?? get_effective_mailbox();
    $appPassSet = trim((string) ($mb['smtp_password'] ?? get_setting('gmail_app_password_secret', ''))) !== '';
    $oauthToken = get_stored_oauth_token((string) ($mb['email'] ?? ''));
    $useSmtp    = is_app_password_mode($mb) || $appPassSet || !$oauthToken || $oauthToken['status'] !== 'connected';

    $appName = get_setting('app_name', DEFAULT_APP_NAME);
    $subject = 'Uji Pengiriman & Verifikasi DKIM ' . $appName . ' (' . date('d M Y H:i') . ' WIB)';
    $bodyHtml = '<p>Halo <strong>{nama_penerima}</strong>,</p>'
        . '<p>Ini adalah <strong>email uji pengiriman diagnostik</strong> dari aplikasi <strong>' . e($appName) . '</strong>.</p>'
        . '<ul>'
        . '<li><strong>Akun Pengirim:</strong> ' . e((string) ($mb['email'] ?? DEFAULT_OFFICIAL_GMAIL)) . '</li>'
        . '<li><strong>Nama Pengirim:</strong> ' . e((string) ($mb['display_name'] ?? $appName)) . '</li>'
        . '<li><strong>Metode:</strong> ' . ($useSmtp ? 'PHPMailer SMTP (' . e((string) ($mb['smtp_host'] ?? 'smtp.gmail.com')) . ':' . (int) ($mb['smtp_port'] ?? 465) . ')' : 'Google Gmail API (OAuth 2.0)') . '</li>'
        . '<li><strong>Waktu Uji:</strong> ' . e(format_tanggal_id(date('Y-m-d H:i:s'), true)) . '</li>'
        . '</ul>'
        . '<p>Silakan buka menu <strong>Lainnya (&vellip;) &rarr; Tampilkan Asli (Show Original)</strong> di Gmail untuk memastikan <code>DKIM: PASS</code> dan cek folder <strong>Terkirim / Sent</strong> akun pengirim.</p>';

    $result = mail_send_email([
        'to'            => $recipientEmail,
        'subject'       => $subject,
        'body_html'     => $bodyHtml,
        'wrap_template' => true,
        'capture_debug' => true,
        'mailbox'       => $mb,
        'variables'     => [
            'nama_penerima'  => explode('@', $recipientEmail)[0],
            'email_penerima' => $recipientEmail,
            'nama_pengirim'  => $adminName ?: $appName,
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
 * Simpan draft ke Gmail / IMAP Drafts.
 */
function mail_save_draft(array $payload, ?array $mailbox = null): array
{
    $mb          = $mailbox ?? get_effective_mailbox();
    $toList      = parse_email_list((string) ($payload['to'] ?? ''));
    $ccList      = parse_email_list((string) ($payload['cc'] ?? ''));
    $bccList     = parse_email_list((string) ($payload['bcc'] ?? ''));
    $subject     = trim((string) ($payload['subject'] ?? '(Tanpa Subjek)'));
    $bodyHtml    = (string) ($payload['body_html'] ?? '');
    $variables   = is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
    $attachments = is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [];
    $draftId     = trim((string) ($payload['draft_id'] ?? ''));
    $threadId    = trim((string) ($payload['thread_id'] ?? ''));

    $finalHtml = render_professional_email_html($bodyHtml, $subject, $variables, true, '', [
        'sender_name'  => (string) ($mb['display_name'] ?? DEFAULT_OFFICIAL_NAME),
        'sender_email' => (string) ($mb['email'] ?? DEFAULT_OFFICIAL_GMAIL),
    ]);

    $rawMime = build_rfc2822_mime([
        'to'          => $toList,
        'cc'          => $ccList,
        'bcc'         => $bccList,
        'subject'     => $subject,
        'html'        => $finalHtml,
        'attachments' => $attachments,
    ], $mb);

    $mbId = (int) ($mb['id'] ?? 0);

    if (is_app_password_mode($mb)) {
        $res = imap_save_draft($rawMime, $mb);
        cache_forget_prefix('mb_' . $mbId . '_');
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

    cache_forget_prefix('mb_' . $mbId . '_');
    log_activity('draft_saved', 'Draft email disimpan ke Gmail — Subjek: ' . $subject, 'draft', (string) $saved->getId());

    return [
        'draft_id'   => (string) $saved->getId(),
        'message_id' => $saved->getMessage() ? (string) $saved->getMessage()->getId() : '',
        'transport'  => 'gmail_api_oauth',
    ];
}

/**
 * Jalankan aksi pada pesan (termasuk hapus riwayat lokal local:<id>).
 */
function mail_perform_message_action(string $messageId, string $action, ?array $mailbox = null): bool
{
    $mb   = $mailbox ?? get_effective_mailbox();
    $mbId = (int) ($mb['id'] ?? 0);

    if (str_starts_with($messageId, 'local:')) {
        $localId = (int) substr($messageId, 6);
        if (in_array($action, ['trash', 'delete'], true)) {
            $actor = function_exists('current_admin') ? current_admin() : null;
            if (is_superadmin($actor)) {
                $stmt = db()->prepare('DELETE FROM sent_email_logs WHERE id = :id');
                $stmt->execute([':id' => $localId]);
            } elseif ($actor) {
                $stmt = db()->prepare('DELETE FROM sent_email_logs WHERE id = :id AND user_id = :uid');
                $stmt->execute([':id' => $localId, ':uid' => (int) $actor['id']]);
            }
            cache_forget_prefix('mb_' . $mbId . '_');
            return true;
        }
        return true;
    }

    if (is_app_password_mode($mb)) {
        $ok = imap_perform_action($messageId, $action, $mb);
        cache_forget_prefix('mb_' . $mbId . '_');
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

    cache_forget_prefix('mb_' . $mbId . '_');
    log_activity('email_action', "Aksi '{$action}' berhasil pada pesan {$messageId}.", 'email', $messageId);
    return true;
}

/**
 * Unduh file lampiran dari Gmail API atau IMAP.
 */
function mail_download_attachment(string $messageId, string $attachmentId, string $fallbackFilename = 'lampiran', ?array $mailbox = null): array
{
    $mb = $mailbox ?? get_effective_mailbox();
    if (is_app_password_mode($mb)) {
        return imap_download_attachment($messageId, $attachmentId, $mb);
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
