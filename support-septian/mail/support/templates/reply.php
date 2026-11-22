<?php
/**
 * Support Septian — Default Template: Balas Pesan (Reply)
 * Dipakai sebagai fallback otomatis apabila template DB belum dimuat atau dirender langsung.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/email-renderer.php';

function build_reply_template_html(array $vars = [], string $quotedHtml = ''): array
{
    $tpl = get_default_template_by_category('reply');
    $subjectTpl = $tpl['subject_template'] ?? 'Re: {subjek}';
    $bodyTpl    = $tpl['body_html'] ?? '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Terima kasih telah menghubungi <strong>{nama_perusahaan}</strong>.</p><p>{isi_pesan}</p><p>Apabila masih ada hal lain yang dapat kami bantu, silakan balas langsung email ini.</p>';

    $subject = replace_template_variables($subjectTpl, $vars, false);
    $html    = render_professional_email_html($bodyTpl, $subject, $vars, true, $quotedHtml);

    return [
        'template_id' => $tpl['id'] ?? null,
        'subject'     => $subject,
        'body_raw'    => $bodyTpl,
        'html'        => $html,
    ];
}
