<?php
/**
 * Support Septian — API: Search Emails Across Gmail
 * GET /mail/support/api/search.php?q=kata_kunci&folder=all&pageToken=...
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/gmail-service.php';

require_admin(true);

try {
    $q         = trim((string) ($_GET['q'] ?? ''));
    $folder    = strtolower(trim((string) ($_GET['folder'] ?? 'all')));
    $pageToken = isset($_GET['pageToken']) && $_GET['pageToken'] !== '' ? trim((string) $_GET['pageToken']) : null;
    $limit     = (int) ($_GET['limit'] ?? 20);

    if ($q === '') {
        json_response(['ok' => true, 'messages' => [], 'next_page_token' => null, 'result_estimate' => 0]);
    }

    $result = mail_list_messages($folder, $limit, $pageToken, $q);

    json_response([
        'ok'              => true,
        'query'           => $q,
        'messages'        => $result['messages'],
        'next_page_token' => $result['next_page_token'],
        'result_estimate' => $result['result_estimate'],
    ]);
} catch (Throwable $e) {
    app_log('error', 'API search.php error: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
