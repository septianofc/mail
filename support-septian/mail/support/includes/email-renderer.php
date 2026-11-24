<?php
/**
 * Support Septian — Email Template Engine & HTML Sanitizer
 *
 * - Menghasilkan email HTML profesional bergaya Google (lebar 600px, background putih, inline CSS)
 * - Mendukung seluruh variabel dinamis Support Septian F. R.
 * - Mensanitasi HTML email masuk sebelum ditampilkan di browser (anti-XSS)
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Daftar variabel dinamis yang didukung pada seluruh template.
 */
function get_template_variables_catalog(): array
{
    return [
        '{nama_penerima}'   => 'Nama penerima email',
        '{email_penerima}'  => 'Alamat email penerima',
        '{subjek}'          => 'Subjek email / percakapan',
        '{tanggal}'         => 'Tanggal pengiriman (format Indonesia)',
        '{nama_pengirim}'   => 'Nama admin pengirim',
        '{isi_pesan}'       => 'Isi utama pesan dari editor',
        '{nama_perusahaan}' => 'Nama identitas resmi (Support Septian F. R.)',
        '{website}'         => 'Alamat situs resmi (https://septianfr.web.id)',
        '{email_support}'   => 'Email resmi (support.septianfr@gmail.com)',
    ];
}

/**
 * Mengganti placeholder variabel dinamis pada teks/HTML template.
 */
function replace_template_variables(string $content, array $vars, bool $escapeValues = true): string
{
    $settings = get_all_settings();

    $defaults = [
        'nama_penerima'   => 'Pelanggan',
        'email_penerima'  => '',
        'subjek'          => '',
        'tanggal'         => format_tanggal_id(date('Y-m-d H:i:s'), true),
        'nama_pengirim'   => $settings['official_name'] ?: DEFAULT_OFFICIAL_NAME,
        'isi_pesan'       => '',
        'nama_perusahaan' => $settings['official_name'] ?: DEFAULT_OFFICIAL_NAME,
        'website'         => $settings['website_url'] ?: DEFAULT_WEBSITE_URL,
        'email_support'   => $settings['support_email'] ?: DEFAULT_OFFICIAL_GMAIL,
    ];

    $merged = array_merge($defaults, $vars);
    $replacements = [];
    foreach ($merged as $key => $value) {
        $placeholder = '{' . $key . '}';
        if ($key === 'isi_pesan') {
            $replacements[$placeholder] = sanitize_outgoing_body_html((string) $value);
        } else {
            $replacements[$placeholder] = $escapeValues ? e((string) $value) : (string) $value;
        }
    }

    return strtr($content, $replacements);
}

/**
 * Bungkus konten email dalam tata letak HTML profesional bergaya Google (inline CSS, 600px, responsif).
 */
function render_professional_email_html(
    string $bodyHtml,
    string $subject = '',
    array $variables = [],
    bool $includeSignature = true,
    string $quotedHtml = ''
): string {
    $settings = get_all_settings();

    $appName         = e($settings['app_name'] ?: DEFAULT_APP_NAME);
    $officialName    = e($settings['official_name'] ?: DEFAULT_OFFICIAL_NAME);
    $supportEmail    = e($settings['support_email'] ?: DEFAULT_OFFICIAL_GMAIL);
    $websiteUrl      = e(safe_http_url($settings['website_url'] ?: DEFAULT_WEBSITE_URL, DEFAULT_WEBSITE_URL));
    $logoUrl         = e(safe_http_url($settings['logo_url'] ?: DEFAULT_LOGO_URL, DEFAULT_LOGO_URL));
    $officialAddress = e($settings['official_address'] ?: DEFAULT_ADDRESS);
    $footerNote      = e($settings['footer_note'] ?: 'Email ini dikirim melalui sistem layanan resmi Support Septian F. R.');

    $resolvedBody = replace_template_variables($bodyHtml, array_merge($variables, ['subjek' => $subject]));
    $resolvedBody = sanitize_outgoing_body_html($resolvedBody);

    $signatureBlock = '';
    if ($includeSignature) {
        $rawSig = trim((string) ($settings['email_signature'] ?? ''));
        if ($rawSig !== '') {
            $signatureBlock = '<div style="margin-top:24px;padding-top:16px;border-top:1px solid #e8eaed;font-size:14px;line-height:1.6;color:#3c4043;">'
                . sanitize_outgoing_body_html($rawSig)
                . '</div>';
        }
    }

    $quotedBlock = '';
    if (trim($quotedHtml) !== '') {
        $quotedBlock = '<div style="margin-top:24px;padding:16px 18px;background-color:#f8f9fa;border:1px solid #e8eaed;border-radius:8px;font-size:13px;line-height:1.6;color:#5f6368;">'
            . sanitize_email_html($quotedHtml)
            . '</div>';
    }

    $safeSubjectTitle = e($subject !== '' ? $subject : $appName);

    return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>{$safeSubjectTitle}</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f7fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#202124;-webkit-font-smoothing:antialiased;">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f5f7fa;margin:0;padding:24px 12px;">
    <tr>
      <td align="center" style="padding:0;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;background-color:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
          <!-- Header -->
          <tr>
            <td style="padding:28px 32px 20px 32px;border-bottom:1px solid #eceff1;background-color:#ffffff;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td valign="middle" style="padding:0;">
                    <a href="{$websiteUrl}" target="_blank" style="text-decoration:none;display:inline-block;">
                      <img src="{$logoUrl}" alt="{$appName}" width="40" height="40" style="display:inline-block;vertical-align:middle;border:0;border-radius:8px;max-width:40px;height:auto;margin-right:12px;">
                      <span style="display:inline-block;vertical-align:middle;font-size:19px;font-weight:700;color:#111827;letter-spacing:-0.01em;">{$appName}</span>
                    </a>
                  </td>
                  <td align="right" valign="middle" style="padding:0;font-size:12px;color:#5f6368;">
                    Layanan Resmi
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Main Content -->
          <tr>
            <td style="padding:28px 32px 28px 32px;background-color:#ffffff;font-size:15px;line-height:1.7;color:#202124;">
              <div style="font-size:15px;line-height:1.7;color:#202124;">
                {$resolvedBody}
              </div>
              {$signatureBlock}
              {$quotedBlock}
            </td>
          </tr>

          <!-- Divider -->
          <tr>
            <td style="padding:0 32px;">
              <hr style="border:0;border-top:1px solid #e8eaed;margin:0;">
            </td>
          </tr>

          <!-- Official Footer -->
          <tr>
            <td style="padding:24px 32px 28px 32px;background-color:#fafbfc;font-size:12.5px;line-height:1.65;color:#5f6368;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td style="padding-bottom:12px;">
                    <img src="{$logoUrl}" alt="{$officialName}" width="28" height="28" style="display:inline-block;vertical-align:middle;border:0;border-radius:6px;margin-right:8px;">
                    <strong style="vertical-align:middle;font-size:14px;color:#111827;">{$officialName}</strong>
                  </td>
                </tr>
                <tr>
                  <td style="padding-bottom:8px;color:#3c4043;">
                    Website: <a href="{$websiteUrl}" target="_blank" style="color:#1a73e8;text-decoration:none;">{$websiteUrl}</a><br>
                    Email: <a href="mailto:{$supportEmail}" style="color:#1a73e8;text-decoration:none;">{$supportEmail}</a>
                  </td>
                </tr>
                <tr>
                  <td style="padding-bottom:12px;color:#5f6368;">
                    <strong style="color:#3c4043;">Alamat:</strong><br>
                    {$officialAddress}
                  </td>
                </tr>
                <tr>
                  <td style="padding-top:10px;border-top:1px solid #e8eaed;font-size:11.5px;color:#80868b;">
                    {$footerNote}
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

/**
 * Ambil template default untuk kategori tertentu (compose, reply, reply_all, forward, draft).
 */
function get_default_template_by_category(string $category): ?array
{
    $allowed = ['reply', 'reply_all', 'compose', 'forward', 'draft'];
    if (!in_array($category, $allowed, true)) {
        $category = 'reply';
    }

    try {
        $stmt = db()->prepare(
            'SELECT * FROM email_templates
             WHERE category = :cat AND is_active = 1
             ORDER BY is_default DESC, id ASC
             LIMIT 1'
        );
        $stmt->execute([':cat' => $category]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        app_log('error', 'get_default_template_by_category error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Ambil semua template aktif (opsional filter kategori).
 */
function get_templates(?string $category = null): array
{
    try {
        if ($category !== null && $category !== '' && $category !== 'all') {
            $stmt = db()->prepare(
                'SELECT * FROM email_templates WHERE category = :cat ORDER BY is_default DESC, name ASC'
            );
            $stmt->execute([':cat' => $category]);
            return $stmt->fetchAll();
        }

        return db()->query('SELECT * FROM email_templates ORDER BY category ASC, is_default DESC, name ASC')->fetchAll();
    } catch (Throwable $e) {
        app_log('error', 'get_templates error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Sanitasi HTML email keluar (dari editor admin) — hanya izinkan tag pemformatan aman.
 */
function sanitize_outgoing_body_html(string $html): string
{
    return sanitize_email_html($html);
}

/**
 * Sanitasi HTML email masuk / keluar menggunakan DOMDocument allowlist.
 * Menghapus script, iframe, event handler on*, dan URL javascript:/vbscript:/data:text/html.
 */
function sanitize_email_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $allowedTags = [
        'a', 'b', 'blockquote', 'br', 'caption', 'code', 'div', 'em',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'li',
        'ol', 'p', 'pre', 'small', 'span', 'strong', 'sub', 'sup',
        'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul',
    ];

    $allowedAttrs = [
        'href', 'src', 'alt', 'title', 'style', 'width', 'height',
        'align', 'valign', 'cellpadding', 'cellspacing', 'border', 'colspan', 'rowspan', 'target', 'rel',
    ];

    // Fallback aman apabila ekstensi php-xml / DOMDocument tidak aktif di server hosting
    if (!class_exists('DOMDocument')) {
        $clean = (string) preg_replace('#<(script|style|iframe|object|embed|form|svg|math)[^>]*>.*?</\1>#is', '', $html);
        $allowStr = '<' . implode('><', $allowedTags) . '>';
        $clean = strip_tags($clean, $allowStr);
        $clean = (string) preg_replace('/\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
        $clean = (string) preg_replace('/(href|src)\s*=\s*(["\'])\s*(?:javascript|vbscript|data:text\/html):[^"\']*\2/i', '$1="#"', $clean);
        return $clean;
    }

    $prevErrors = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="__ss_root__">' . $html . '</div></body></html>';
    $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($prevErrors);

    // Hapus tag berbahaya beserta isinya
    foreach (['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'meta', 'link', 'base', 'svg', 'math'] as $dangerTag) {
        $nodes = $dom->getElementsByTagName($dangerTag);
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $node = $nodes->item($i);
            if ($node && $node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    $root = $dom->getElementById('__ss_root__');
    if (!$root) {
        return nl2br(e(strip_tags($html)));
    }

    $walk = function (DOMNode $node) use (&$walk, $allowedTags, $allowedAttrs): void {
        if ($node->hasChildNodes()) {
            $children = [];
            foreach ($node->childNodes as $child) {
                $children[] = $child;
            }
            foreach ($children as $child) {
                if ($child instanceof DOMElement) {
                    $tagName = strtolower($child->tagName);
                    if (!in_array($tagName, $allowedTags, true)) {
                        // Pindahkan anak teks/elemen keluar lalu hapus tag tak diizinkan
                        while ($child->firstChild) {
                            $node->insertBefore($child->firstChild, $child);
                        }
                        $node->removeChild($child);
                        continue;
                    }

                    // Bersihkan atribut
                    $attrsToRemove = [];
                    foreach ($child->attributes as $attr) {
                        $attrName  = strtolower($attr->nodeName);
                        $attrValue = trim($attr->nodeValue);

                        if (str_starts_with($attrName, 'on') || !in_array($attrName, $allowedAttrs, true)) {
                            $attrsToRemove[] = $attr->nodeName;
                            continue;
                        }

                        if ($attrName === 'href' || $attrName === 'src') {
                            $lower = strtolower(preg_replace('/\s+/', '', $attrValue) ?? '');
                            if (
                                str_starts_with($lower, 'javascript:') ||
                                str_starts_with($lower, 'vbscript:') ||
                                str_starts_with($lower, 'data:text/html')
                            ) {
                                $attrsToRemove[] = $attr->nodeName;
                            }
                        }

                        if ($attrName === 'style') {
                            if (preg_match('/(expression\s*\(|javascript:|behavior\s*:|-moz-binding|url\s*\(\s*["\']?\s*javascript)/i', $attrValue)) {
                                $attrsToRemove[] = $attr->nodeName;
                            }
                        }
                    }

                    foreach ($attrsToRemove as $rm) {
                        $child->removeAttribute($rm);
                    }

                    if ($tagName === 'a' && $child->hasAttribute('href')) {
                        $child->setAttribute('target', '_blank');
                        $child->setAttribute('rel', 'noopener noreferrer');
                    }
                }
                $walk($child);
            }
        }
    };

    $walk($root);

    $output = '';
    foreach ($root->childNodes as $child) {
        $output .= $dom->saveHTML($child);
    }

    return $output;
}

/**
 * Konversi HTML ke plain text sebagai fallback multipart/alternative.
 */
function html_to_plain_text(string $html): string
{
    $text = preg_replace('/<(br|\/p|\/div|\/tr|\/li|\/h[1-6])[^>]*>/i', "\n", $html) ?? $html;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    return trim($text);
}
