<?php
/**
 * Support Septian — Default Template: Kirim Pesan Baru (Compose)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/email-renderer.php';

function build_compose_template_html(array $vars = []): array
{
    $tpl = get_default_template_by_category('compose');
    $subjectTpl = $tpl['subject_template'] ?? '{subjek}';
    $bodyTpl    = $tpl['body_html'] ?? '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Kami dari tim resmi <strong>{nama_perusahaan}</strong> menghubungi Anda terkait hal berikut:</p><p>{isi_pesan}</p><p>Apabila ada hal yang ingin dikonfirmasi, silakan balas langsung email ini atau kunjungi {website}.</p>';

    $subject = replace_template_variables($subjectTpl, $vars, false);
    $html    = render_professional_email_html($bodyTpl, $subject, $vars, true);

    return [
        'template_id' => $tpl['id'] ?? null,
        'subject'     => $subject,
        'body_raw'    => $bodyTpl,
        'html'        => $html,
    ];
}
