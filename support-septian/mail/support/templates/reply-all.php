<?php
/**
 * Support Septian — Default Template: Balas Semua (Reply All)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/email-renderer.php';

function build_reply_all_template_html(array $vars = [], string $quotedHtml = ''): array
{
    $tpl = get_default_template_by_category('reply_all');
    $subjectTpl = $tpl['subject_template'] ?? 'Re: {subjek}';
    $bodyTpl    = $tpl['body_html'] ?? '<p>Halo <strong>{nama_penerima}</strong> dan seluruh rekan terkait,</p><p>Menindaklanjuti percakapan mengenai <strong>&ldquo;{subjek}&rdquo;</strong>, berikut pembaruan resmi dari <strong>{nama_perusahaan}</strong>:</p><p>{isi_pesan}</p><p>Silakan balas email ini apabila masih diperlukan koordinasi lebih lanjut.</p>';

    $subject = replace_template_variables($subjectTpl, $vars, false);
    $html    = render_professional_email_html($bodyTpl, $subject, $vars, true, $quotedHtml);

    return [
        'template_id' => $tpl['id'] ?? null,
        'subject'     => $subject,
        'body_raw'    => $bodyTpl,
        'html'        => $html,
    ];
}
