<?php
/**
 * Skyzz Mail — API: Custom Email Templates CRUD & Live Preview Renderer
 * GET  /mail/support/api/templates.php?category=reply|compose|...&id=...
 * POST /mail/support/api/templates.php (action: create | update | delete | duplicate | set_default | preview)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/email-renderer.php';

$user    = require_admin(true);
$isSuper = is_superadmin($user);
$uid     = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        if (!empty($_GET['id'])) {
            $stmt = db()->prepare('SELECT * FROM email_templates WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => (int) $_GET['id']]);
            $tpl = $stmt->fetch();
            if (!$tpl) {
                json_response(['ok' => false, 'message' => 'Template tidak ditemukan.'], 404);
            }
            if (!$isSuper && $tpl['owner_id'] !== null && (int) $tpl['owner_id'] !== $uid) {
                json_response(['ok' => false, 'message' => 'Akses ditolak.'], 403);
            }
            json_response(['ok' => true, 'template' => $tpl]);
        }

        $category = isset($_GET['category']) ? trim((string) $_GET['category']) : null;
        $list     = get_templates($category, $uid, $isSuper);
        json_response(['ok' => true, 'templates' => $list]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

$data = !empty($_POST) ? $_POST : read_json_input();
require_csrf(true);

$action = trim((string) ($data['action'] ?? ''));
$allowedCategories = ['reply', 'reply_all', 'compose', 'forward', 'draft'];

try {
    switch ($action) {
        case 'preview':
            $bodyHtml = (string) ($data['body_html'] ?? '');
            $subject  = (string) ($data['subject'] ?? 'Contoh Subjek Skyzz Mail');
            $plan     = get_user_effective_plan($user);
            $mb       = get_effective_mailbox($user);
            $senderNm = trim((string) ($user['custom_sender_name'] ?? '')) ?: (string) ($mb['display_name'] ?? $user['name']);
            $vars     = [
                'nama_penerima'   => (string) ($data['nama_penerima'] ?? 'Budi Santoso'),
                'email_penerima'  => (string) ($data['email_penerima'] ?? 'budi@contoh.id'),
                'subjek'          => $subject,
                'nama_pengirim'   => $senderNm,
                'nama_perusahaan' => $senderNm,
                'email_support'   => (string) ($mb['email'] ?? DEFAULT_OFFICIAL_GMAIL),
                'isi_pesan'       => (string) ($data['isi_pesan'] ?? 'Terima kasih atas pesan Anda. Ini adalah contoh pratinjau template bebas kustom dari Skyzz Mail.'),
            ];
            $opts = [
                'sender_name'    => $senderNm,
                'sender_email'   => (string) ($mb['email'] ?? DEFAULT_OFFICIAL_GMAIL),
                'watermark_text' => !empty($plan['has_watermark']) ? ((string) ($plan['watermark_text'] ?: FREE_WATERMARK_DEFAULT)) : '',
            ];
            if (!empty($user['custom_signature'])) {
                $opts['signature'] = (string) $user['custom_signature'];
            }
            if (isset($user['custom_footer_note']) && trim((string) $user['custom_footer_note']) !== '') {
                $opts['footer_note'] = (string) $user['custom_footer_note'];
            }
            $renderedSubject = replace_template_variables($subject, $vars, false);
            $renderedHtml    = render_professional_email_html($bodyHtml, $renderedSubject, $vars, true, '', $opts);
            json_response([
                'ok'      => true,
                'subject' => $renderedSubject,
                'html'    => $renderedHtml,
            ]);

        case 'create':
            $name     = trim((string) ($data['name'] ?? ''));
            $category = trim((string) ($data['category'] ?? 'reply'));
            $subject  = trim((string) ($data['subject_template'] ?? ''));
            $bodyHtml = (string) ($data['body_html'] ?? '');
            $desc     = trim((string) ($data['description'] ?? ''));
            $isDef    = !empty($data['is_default']) ? 1 : 0;
            $ownerId  = $isSuper && !empty($data['is_global']) ? null : ($isSuper ? null : $uid);

            if ($name === '' || trim($bodyHtml) === '') {
                json_response(['ok' => false, 'message' => 'Nama template dan isi HTML wajib diisi.'], 422);
            }
            if (!in_array($category, $allowedCategories, true)) {
                $category = 'reply';
            }

            $slug = slugify($name) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            $safeBody = sanitize_outgoing_body_html($bodyHtml);

            if ($isDef === 1) {
                if ($ownerId === null) {
                    $reset = db()->prepare('UPDATE email_templates SET is_default = 0 WHERE category = :cat AND owner_id IS NULL');
                    $reset->execute([':cat' => $category]);
                } else {
                    $reset = db()->prepare('UPDATE email_templates SET is_default = 0 WHERE category = :cat AND owner_id = :oid');
                    $reset->execute([':cat' => $category, ':oid' => $ownerId]);
                }
            }

            $stmt = db()->prepare(
                'INSERT INTO email_templates (owner_id, slug, name, category, subject_template, body_html, is_default, is_active, description, created_by, updated_by)
                 VALUES (:oid, :slug, :name, :cat, :subj, :body, :def, 1, :desc, :uid1, :uid2)'
            );
            $stmt->execute([
                ':oid'  => $ownerId,
                ':slug' => $slug,
                ':name' => $name,
                ':cat'  => $category,
                ':subj' => $subject,
                ':body' => $safeBody,
                ':def'  => $isDef,
                ':desc' => $desc,
                ':uid1' => $uid,
                ':uid2' => $uid,
            ]);
            $newId = (int) db()->lastInsertId();

            log_activity('template_created', 'Template email kustom dibuat: ' . $name, 'template', (string) $newId);
            json_response(['ok' => true, 'message' => 'Template kustom berhasil dibuat.', 'id' => $newId]);

        case 'update':
            $id       = (int) ($data['id'] ?? 0);
            $name     = trim((string) ($data['name'] ?? ''));
            $category = trim((string) ($data['category'] ?? 'reply'));
            $subject  = trim((string) ($data['subject_template'] ?? ''));
            $bodyHtml = (string) ($data['body_html'] ?? '');
            $desc     = trim((string) ($data['description'] ?? ''));
            $isDef    = !empty($data['is_default']) ? 1 : 0;

            if ($id <= 0 || $name === '' || trim($bodyHtml) === '') {
                json_response(['ok' => false, 'message' => 'Data template tidak lengkap.'], 422);
            }

            $chk = db()->prepare('SELECT id, owner_id FROM email_templates WHERE id = :id LIMIT 1');
            $chk->execute([':id' => $id]);
            $existing = $chk->fetch();
            if (!$existing) {
                json_response(['ok' => false, 'message' => 'Template tidak ditemukan.'], 404);
            }
            if (!$isSuper && (int) ($existing['owner_id'] ?? 0) !== $uid) {
                json_response(['ok' => false, 'message' => 'Template bawaan global tidak bisa diubah langsung. Silakan klik Gandakan untuk membuat versi kustom milik Anda.'], 403);
            }

            if (!in_array($category, $allowedCategories, true)) {
                $category = 'reply';
            }

            $safeBody = sanitize_outgoing_body_html($bodyHtml);
            if ($isDef === 1) {
                if ($existing['owner_id'] === null) {
                    $reset = db()->prepare('UPDATE email_templates SET is_default = 0 WHERE category = :cat AND owner_id IS NULL');
                    $reset->execute([':cat' => $category]);
                } else {
                    $reset = db()->prepare('UPDATE email_templates SET is_default = 0 WHERE category = :cat AND owner_id = :oid');
                    $reset->execute([':cat' => $category, ':oid' => (int) $existing['owner_id']]);
                }
            }

            $stmt = db()->prepare(
                'UPDATE email_templates
                 SET name = :name, category = :cat, subject_template = :subj, body_html = :body,
                     is_default = :def, description = :desc, updated_by = :uid
                 WHERE id = :id'
            );
            $stmt->execute([
                ':name' => $name,
                ':cat'  => $category,
                ':subj' => $subject,
                ':body' => $safeBody,
                ':def'  => $isDef,
                ':desc' => $desc,
                ':uid'  => $uid,
                ':id'   => $id,
            ]);

            log_activity('template_updated', 'Template email diperbarui: ' . $name, 'template', (string) $id);
            json_response(['ok' => true, 'message' => 'Perubahan template berhasil disimpan.']);

        case 'duplicate':
            $id = (int) ($data['id'] ?? 0);
            $stmt = db()->prepare('SELECT * FROM email_templates WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $orig = $stmt->fetch();
            if (!$orig) {
                json_response(['ok' => false, 'message' => 'Template asal tidak ditemukan.'], 404);
            }
            if (!$isSuper && $orig['owner_id'] !== null && (int) $orig['owner_id'] !== $uid) {
                json_response(['ok' => false, 'message' => 'Akses ditolak.'], 403);
            }

            $newOwner = $isSuper ? $orig['owner_id'] : $uid;
            $newName  = $orig['name'] . ' (Kustom)';
            $newSlug  = slugify($orig['name']) . '-copy-' . substr(bin2hex(random_bytes(3)), 0, 6);
            $ins = db()->prepare(
                'INSERT INTO email_templates (owner_id, slug, name, category, subject_template, body_html, is_default, is_active, description, created_by, updated_by)
                 VALUES (:oid, :slug, :name, :cat, :subj, :body, 0, 1, :desc, :u1, :u2)'
            );
            $ins->execute([
                ':oid'  => $newOwner,
                ':slug' => $newSlug,
                ':name' => $newName,
                ':cat'  => $orig['category'],
                ':subj' => $orig['subject_template'],
                ':body' => $orig['body_html'],
                ':desc' => $orig['description'],
                ':u1'   => $uid,
                ':u2'   => $uid,
            ]);
            $newId = (int) db()->lastInsertId();
            log_activity('template_duplicated', 'Template digandakan: ' . $newName, 'template', (string) $newId);
            json_response(['ok' => true, 'message' => 'Template berhasil digandakan menjadi template kustom Anda.', 'id' => $newId]);

        case 'set_default':
            $id = (int) ($data['id'] ?? 0);
            $stmt = db()->prepare('SELECT id, name, category, owner_id FROM email_templates WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $tpl = $stmt->fetch();
            if (!$tpl) {
                json_response(['ok' => false, 'message' => 'Template tidak ditemukan.'], 404);
            }
            if (!$isSuper && (int) ($tpl['owner_id'] ?? 0) !== $uid) {
                json_response(['ok' => false, 'message' => 'Hanya dapat menjadikan template kustom milik Anda sebagai default pribadi.'], 403);
            }

            if ($tpl['owner_id'] === null) {
                $reset = db()->prepare('UPDATE email_templates SET is_default = 0 WHERE category = :cat AND owner_id IS NULL');
                $reset->execute([':cat' => $tpl['category']]);
            } else {
                $reset = db()->prepare('UPDATE email_templates SET is_default = 0 WHERE category = :cat AND owner_id = :oid');
                $reset->execute([':cat' => $tpl['category'], ':oid' => (int) $tpl['owner_id']]);
            }

            $set = db()->prepare('UPDATE email_templates SET is_default = 1, updated_by = :uid WHERE id = :id');
            $set->execute([':uid' => $uid, ':id' => $id]);

            log_activity('template_default', 'Template default diubah ke: ' . $tpl['name'], 'template', (string) $id);
            json_response(['ok' => true, 'message' => 'Template "' . $tpl['name'] . '" ditetapkan sebagai default.']);

        case 'delete':
            $id = (int) ($data['id'] ?? 0);
            $stmt = db()->prepare('SELECT id, name, owner_id FROM email_templates WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $tpl = $stmt->fetch();
            if (!$tpl) {
                json_response(['ok' => false, 'message' => 'Template tidak ditemukan.'], 404);
            }
            if (!$isSuper && (int) ($tpl['owner_id'] ?? 0) !== $uid) {
                json_response(['ok' => false, 'message' => 'Anda hanya dapat menghapus template kustom milik Anda sendiri.'], 403);
            }

            $del = db()->prepare('DELETE FROM email_templates WHERE id = :id');
            $del->execute([':id' => $id]);

            log_activity('template_deleted', 'Template dihapus: ' . $tpl['name'], 'template', (string) $id);
            json_response(['ok' => true, 'message' => 'Template berhasil dihapus.']);

        default:
            json_response(['ok' => false, 'message' => 'Aksi template tidak valid.'], 400);
    }
} catch (Throwable $e) {
    app_log('error', 'API templates.php error: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
