<?php
/**
 * Skyzz Mail — API: Messages, Thread Detail & Mailbox Stats (dengan Cache & Force Refresh)
 * GET /mail/support/api/messages.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/gmail-service.php';

$user = require_admin(true);

try {
    $force = (string) ($_GET['force'] ?? '') === '1';

    if (!empty($_GET['stats'])) {
        $status = get_mail_connection_status();
        $quota  = get_user_quota_status($user);

        if (!$status['active_ready']) {
            json_response([
                'ok'         => false,
                'code'       => 'NOT_CONNECTED',
                'message'    => $status['notice'] ?: 'Akun email belum dikonfigurasi.',
                'connection' => $status,
                'quota'      => $quota,
            ], 200);
        }

        $stats = mail_get_dashboard_stats($force);
        json_response([
            'ok'         => true,
            'stats'      => $stats,
            'connection' => $status,
            'quota'      => $quota,
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

    $result = mail_list_messages($folder, $limit, $pageToken, $q, $force);

    json_response([
        'ok'              => true,
        'folder'          => $folder,
        'messages'        => $result['messages'],
        'next_page_token' => $result['next_page_token'],
        'result_estimate' => $result['result_estimate'],
        'transport'       => $result['transport'],
        'from_cache'      => !empty($result['from_cache']),
        'notice'          => $result['notice'] ?? null,
    ]);
} catch (Throwable $e) {
    app_log('error', 'API messages.php error: ' . $e->getMessage());
    json_response([
        'ok'      => false,
        'message' => $e->getMessage(),
    ], 400);
}
