<?php
/**
 * Support Septian — API: Message Actions (Read, Unread, Star, Archive, Spam, Trash, Delete)
 * POST /mail/support/api/actions.php
 * Body JSON: { "action": "mark_read|mark_unread|star|unstar|archive|trash|untrash|spam|not_spam|delete", "ids": ["..."] }
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/gmail-service.php';

require_admin(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

$data = !empty($_POST) ? $_POST : read_json_input();
require_csrf(true);

$action = trim((string) ($data['action'] ?? ''));
$ids    = $data['ids'] ?? [];
if (is_string($ids)) {
    $ids = [$ids];
}

$allowedActions = ['mark_read', 'mark_unread', 'star', 'unstar', 'archive', 'trash', 'untrash', 'spam', 'not_spam', 'delete'];
if (!in_array($action, $allowedActions, true) || !is_array($ids) || empty($ids)) {
    json_response(['ok' => false, 'message' => 'Aksi atau daftar pesan tidak valid.'], 422);
}

try {
    $processed = 0;
    foreach ($ids as $msgId) {
        $cleanId = trim((string) $msgId);
        if ($cleanId === '') {
            continue;
        }
        mail_perform_message_action($cleanId, $action);
        $processed++;
    }

    $labels = [
        'mark_read'   => 'Ditandai sudah dibaca.',
        'mark_unread' => 'Ditandai belum dibaca.',
        'star'        => 'Ditambahkan tanda bintang.',
        'unstar'      => 'Tanda bintang dihapus.',
        'archive'     => 'Email dipindahkan ke arsip.',
        'trash'       => 'Email dipindahkan ke sampah.',
        'untrash'     => 'Email dipulihkan dari sampah.',
        'spam'        => 'Email dilaporkan sebagai spam.',
        'not_spam'    => 'Email dipindahkan kembali ke kotak masuk.',
        'delete'      => 'Email dihapus permanen.',
    ];

    json_response([
        'ok'        => true,
        'processed' => $processed,
        'message'   => $labels[$action] ?? 'Aksi berhasil dijalankan.',
    ]);
} catch (Throwable $e) {
    app_log('error', 'API actions.php error: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
