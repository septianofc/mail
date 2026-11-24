<?php
/**
 * Skyzz Mail — API: Ganti Akun Email Aktif (Mailbox Switcher)
 * POST /mail/support/api/switch-mailbox.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$user = require_admin(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

$data = !empty($_POST) ? $_POST : read_json_input();
require_csrf(true);

try {
    $mailboxId = (int) ($data['mailbox_id'] ?? 0);
    if ($mailboxId <= 0) {
        throw new RuntimeException('Silakan pilih akun email yang valid.');
    }
    $mb = set_user_selected_mailbox((int) $user['id'], $mailboxId, is_superadmin($user));
    json_response([
        'ok'      => true,
        'message' => 'Berhasil beralih ke akun email ' . $mb['email'] . '.',
        'mailbox' => [
            'id'           => (int) $mb['id'],
            'email'        => (string) $mb['email'],
            'label'        => (string) $mb['label'],
            'display_name' => (string) $mb['display_name'],
        ],
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
