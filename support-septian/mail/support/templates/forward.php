<?php
/**
 * Support Septian — Default Template: Teruskan Pesan (Forward)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/email-renderer.php';

function build_forward_template_html(array $vars = [], string $quotedHtml = ''): array
{
    $tpl = get_default_template_by_category('forward');
    $subjectTpl = $tpl['subject_template'] ?? 'Fwd: {subjek}';
    $bodyTpl    = $tpl['body_html'] ?? '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Berikut kami teruskan pesan terkait <strong>&ldquo;{subjek}&rdquo;</strong> dari layanan <strong>{nama_perusahaan}</strong> untuk dapat ditindaklanjuti.</p><p>{isi_pesan}</p><p>Silakan hubungi kami kembali melalui {email_support} apabila diperlukan detail tambahan.</p>';

    $subject = replace_template_variables($subjectTpl, $vars, false);
    $html    = render_professional_email_html($bodyTpl, $subject, $vars, true, $quotedHtml);

    return [
        'template_id' => $tpl['id'] ?? null,
        'subject'     => $subject,
        'body_raw'    => $bodyTpl,
        'html'        => $html,
    ];
}
