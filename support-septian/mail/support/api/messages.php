<?php
/**
 * Support Septian — API: Messages, Thread Detail & Mailbox Stats
 * GET /mail/support/api/messages.php
 *   ?folder=inbox|sent|drafts|starred|spam|trash
 *   &pageToken=...
 *   &q=...
 *   &limit=20
 *   &id=... (ambil detail percakapan/thread)
 *   &stats=1 (ambil statistik kotak surat sungguhan)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/gmail-service.php';

require_admin(true);

try {
    if (!empty($_GET['stats'])) {
        $status = get_mail_connection_status();
        if (!$status['active_ready']) {
            json_response([
                'ok'         => false,
                'code'       => 'NOT_CONNECTED',
                'message'    => $status['notice'] ?: 'Gmail belum terhubung.',
                'connection' => $status,
            ], 200);
        }

        $stats = mail_get_dashboard_stats();
        json_response([
            'ok'         => true,
            'stats'      => $stats,
            'connection' => $status,
        ]);
    }

    if (!empty($_GET['id'])) {
        $messageId = trim((string) $_GET['id']);
        $thread = mail_get_thread_detail($messageId, true);
        json_response([
            'ok'     => true,
            'thread' => $thread,
        ]);
    }

    $allowedFolders = ['inbox', 'sent', 'drafts', 'starred', 'spam', 'trash', 'all'];
    $folder = strtolower(trim((string) ($_GET['folder'] ?? 'inbox')));
    if (!in_array($folder, $allowedFolders, true)) {
        $folder = 'inbox';
    }

    $limit     = (int) ($_GET['limit'] ?? get_setting('items_per_page', '20'));
    $pageToken = isset($_GET['pageToken']) && $_GET['pageToken'] !== '' ? trim((string) $_GET['pageToken']) : null;
    $q         = trim((string) ($_GET['q'] ?? ''));

    $result = mail_list_messages($folder, $limit, $pageToken, $q);

    json_response([
        'ok'              => true,
        'folder'          => $folder,
        'messages'        => $result['messages'],
        'next_page_token' => $result['next_page_token'],
        'result_estimate' => $result['result_estimate'],
        'transport'       => $result['transport'],
    ]);
} catch (Throwable $e) {
    app_log('error', 'API messages.php error: ' . $e->getMessage());
    json_response([
        'ok'      => false,
        'message' => $e->getMessage(),
    ], 400);
}
