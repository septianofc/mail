<?php
/**
 * Support Septian — Default Template: Draft Email
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/email-renderer.php';

function build_draft_template_html(array $vars = []): array
{
    $tpl = get_default_template_by_category('draft');
    $subjectTpl = $tpl['subject_template'] ?? '{subjek}';
    $bodyTpl    = $tpl['body_html'] ?? '<p>Halo <strong>{nama_penerima}</strong>,</p><p>{isi_pesan}</p><p>Silakan lengkapi draft ini sebelum dikirimkan melalui akun resmi {email_support}.</p>';

    $subject = replace_template_variables($subjectTpl, $vars, false);
    $html    = render_professional_email_html($bodyTpl, $subject, $vars, true);

    return [
        'template_id' => $tpl['id'] ?? null,
        'subject'     => $subject,
        'body_raw'    => $bodyTpl,
        'html'        => $html,
    ];
}
