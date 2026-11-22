<?php
/**
 * Support Septian — API: Email Templates CRUD & Live Preview Renderer
 * GET  /mail/support/api/templates.php?category=reply|compose|...&id=...
 * POST /mail/support/api/templates.php (action: create | update | delete | duplicate | set_default | preview)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/email-renderer.php';

$admin = require_admin(true);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        if (!empty($_GET['id'])) {
            $stmt = db()->prepare('SELECT * FROM email_templates WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => (int) $_GET['id']]);
            $tpl = $stmt->fetch();
            if (!$tpl) {
                json_response(['ok' => false, 'message' => 'Template tidak ditemukan.'], 404);
            }
            json_response(['ok' => true, 'template' => $tpl]);
        }

        $category = isset($_GET['category']) ? trim((string) $_GET['category']) : null;
        $list     = get_templates($category);
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
            $subject  = (string) ($data['subject'] ?? 'Contoh Subjek Support Septian');
            $vars     = [
                'nama_penerima'  => (string) ($data['nama_penerima'] ?? 'Budi Santoso'),
                'email_penerima' => (string) ($data['email_penerima'] ?? 'budi@contoh.id'),
                'subjek'         => $subject,
                'nama_pengirim'  => $admin['name'] ?: DEFAULT_OFFICIAL_NAME,
                'isi_pesan'      => (string) ($data['isi_pesan'] ?? 'Terima kasih atas pesan Anda. Tim kami telah memeriksa detail yang disampaikan dan siap membantu langkah selanjutnya.'),
            ];
            $renderedSubject = replace_template_variables($subject, $vars, false);
            $renderedHtml    = render_professional_email_html($bodyHtml, $renderedSubject, $vars, true);
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

            if ($name === '' || trim($bodyHtml) === '') {
                json_response(['ok' => false, 'message' => 'Nama template dan isi HTML wajib diisi.'], 422);
            }
            if (!in_array($category, $allowedCategories, true)) {
                $category = 'reply';
            }

            $slug = slugify($name) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            $safeBody = sanitize_outgoing_body_html($bodyHtml);

            if ($isDef === 1) {
                $reset = db()->prepare('UPDATE email_templates SET is_default = 0 WHERE category = :cat');
                $reset->execute([':cat' => $category]);
            }

            $stmt = db()->prepare(
                'INSERT INTO email_templates (slug, name, category, subject_template, body_html, is_default, is_active, description, created_by, updated_by)
                 VALUES (:slug, :name, :cat, :subj, :body, :def, 1, :desc, :uid1, :uid2)'
            );
            $stmt->execute([
                ':slug' => $slug,
                ':name' => $name,
                ':cat'  => $category,
                ':subj' => $subject,
                ':body' => $safeBody,
                ':def'  => $isDef,
                ':desc' => $desc,
                ':uid1' => (int) $admin['id'],
                ':uid2' => (int) $admin['id'],
            ]);
            $newId = (int) db()->lastInsertId();

            log_activity('template_created', 'Template email baru dibuat: ' . $name, 'template', (string) $newId);
            json_response(['ok' => true, 'message' => 'Template berhasil dibuat.', 'id' => $newId]);

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
            if (!in_array($category, $allowedCategories, true)) {
                $category = 'reply';
            }

            $safeBody = sanitize_outgoing_body_html($bodyHtml);
            if ($isDef === 1) {
                $reset = db()->prepare('UPDATE email_templates SET is_default = 0 WHERE category = :cat');
                $reset->execute([':cat' => $category]);
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
                ':uid'  => (int) $admin['id'],
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

            $newName = $orig['name'] . ' (Salinan)';
            $newSlug = slugify($orig['name']) . '-copy-' . substr(bin2hex(random_bytes(3)), 0, 6);
            $ins = db()->prepare(
                'INSERT INTO email_templates (slug, name, category, subject_template, body_html, is_default, is_active, description, created_by, updated_by)
                 VALUES (:slug, :name, :cat, :subj, :body, 0, 1, :desc, :u1, :u2)'
            );
            $ins->execute([
                ':slug' => $newSlug,
                ':name' => $newName,
                ':cat'  => $orig['category'],
                ':subj' => $orig['subject_template'],
                ':body' => $orig['body_html'],
                ':desc' => $orig['description'],
                ':u1'   => (int) $admin['id'],
                ':u2'   => (int) $admin['id'],
            ]);
            $newId = (int) db()->lastInsertId();
            log_activity('template_duplicated', 'Template digandakan: ' . $newName, 'template', (string) $newId);
            json_response(['ok' => true, 'message' => 'Template berhasil digandakan.', 'id' => $newId]);

        case 'set_default':
            $id = (int) ($data['id'] ?? 0);
            $stmt = db()->prepare('SELECT id, name, category FROM email_templates WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $tpl = $stmt->fetch();
            if (!$tpl) {
                json_response(['ok' => false, 'message' => 'Template tidak ditemukan.'], 404);
            }
            $reset = db()->prepare('UPDATE email_templates SET is_default = 0 WHERE category = :cat');
            $reset->execute([':cat' => $tpl['category']]);

            $set = db()->prepare('UPDATE email_templates SET is_default = 1, updated_by = :uid WHERE id = :id');
            $set->execute([':uid' => (int) $admin['id'], ':id' => $id]);

            log_activity('template_default', 'Template default diubah ke: ' . $tpl['name'], 'template', (string) $id);
            json_response(['ok' => true, 'message' => 'Template "' . $tpl['name'] . '" ditetapkan sebagai default.']);

        case 'delete':
            $id = (int) ($data['id'] ?? 0);
            $stmt = db()->prepare('SELECT id, name FROM email_templates WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $tpl = $stmt->fetch();
            if (!$tpl) {
                json_response(['ok' => false, 'message' => 'Template tidak ditemukan.'], 404);
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
