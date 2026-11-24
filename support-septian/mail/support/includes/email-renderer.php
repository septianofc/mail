<?php
/**
 * Skyzz Mail — Customizable Email Template Engine, Watermark & HTML Sanitizer
 *
 * - Template bebas kustom untuk setiap pengguna & superadmin (tidak dikunci ke satu merek)
 * - Paket Free otomatis menyertakan watermark: "Dikirim Menggunakan Aplikasi Skyzz Mail"
 * - Paket Berbayar (Lite, Pro, Business) & Superadmin bebas watermark
 * - Sanitasi HTML anti-XSS untuk email masuk & keluar
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
        '{nama_pengirim}'   => 'Nama pengirim kustom Anda',
        '{isi_pesan}'       => 'Isi utama pesan dari editor',
        '{nama_perusahaan}' => 'Nama bisnis / identitas pengirim Anda',
        '{website}'         => 'Alamat situs web',
        '{email_support}'   => 'Alamat email pengirim aktif',
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
 * Bungkus konten email dalam tata letak HTML profesional (inline CSS, 600px, responsif).
 * Mendukung kustomisasi pengirim, tanda tangan, catatan footer, serta watermark otomatis untuk paket Free.
 */
function render_professional_email_html(
    string $bodyHtml,
    string $subject = '',
    array $variables = [],
    bool $includeSignature = true,
    string $quotedHtml = '',
    array $options = []
): string {
    $settings = get_all_settings();

    $brandTitle      = trim((string) ($options['sender_name'] ?? '')) ?: ($settings['official_name'] ?: DEFAULT_OFFICIAL_NAME);
    $supportEmailRaw = trim((string) ($options['sender_email'] ?? '')) ?: ($settings['support_email'] ?: DEFAULT_OFFICIAL_GMAIL);
    $websiteUrlRaw   = trim((string) ($options['website_url'] ?? '')) ?: ($settings['website_url'] ?: DEFAULT_WEBSITE_URL);
    $logoUrlRaw      = trim((string) ($options['logo_url'] ?? '')) ?: ($settings['logo_url'] ?: DEFAULT_LOGO_URL);
    $addressRaw      = trim((string) ($options['address'] ?? ($settings['official_address'] ?? '')));
    $footerNoteRaw   = array_key_exists('footer_note', $options)
        ? (string) $options['footer_note']
        : (string) ($settings['footer_note'] ?? '');
    $watermarkText   = trim((string) ($options['watermark_text'] ?? ''));

    $varsMerged = array_merge([
        'subjek'          => $subject,
        'nama_pengirim'   => $brandTitle,
        'nama_perusahaan' => $brandTitle,
        'email_support'   => $supportEmailRaw,
        'website'         => $websiteUrlRaw,
    ], $variables);

    $resolvedBody = replace_template_variables($bodyHtml, $varsMerged);
    $resolvedBody = sanitize_outgoing_body_html($resolvedBody);

    $signatureBlock = '';
    if ($includeSignature) {
        $rawSig = array_key_exists('signature', $options)
            ? trim((string) $options['signature'])
            : trim((string) ($settings['email_signature'] ?? ''));
        if ($rawSig !== '') {
            $sigResolved = replace_template_variables($rawSig, $varsMerged);
            $signatureBlock = '<div style="margin-top:24px;padding-top:16px;border-top:1px solid #e8eaed;font-size:14px;line-height:1.6;color:#3c4043;">'
                . sanitize_outgoing_body_html($sigResolved)
                . '</div>';
        }
    }

    $quotedBlock = '';
    if (trim($quotedHtml) !== '') {
        $quotedBlock = '<div style="margin-top:24px;padding:16px 18px;background-color:#f8f9fa;border:1px solid #e8eaed;border-radius:8px;font-size:13px;line-height:1.6;color:#5f6368;">'
            . sanitize_email_html($quotedHtml)
            . '</div>';
    }

    $watermarkBlock = '';
    if ($watermarkText !== '') {
        $safeWm = e($watermarkText);
        $watermarkBlock = '<tr><td style="padding:12px 32px;background-color:#f1f5f9;border-top:1px solid #e2e8f0;text-align:center;font-size:11.5px;color:#64748b;">'
            . $safeWm
            . '</td></tr>';
    }

    $eBrandTitle   = e($brandTitle);
    $eSupportEmail = e($supportEmailRaw);
    $eWebsiteUrl   = e(safe_http_url($websiteUrlRaw, DEFAULT_WEBSITE_URL));
    $eLogoUrl      = e(safe_http_url($logoUrlRaw, DEFAULT_LOGO_URL));
    $eAddress      = e($addressRaw);
    $eFooterNote   = e($footerNoteRaw);
    $safeSubjectTitle = e($subject !== '' ? $subject : $brandTitle);

    $addressRow = $eAddress !== ''
        ? '<tr><td style="padding-bottom:10px;color:#5f6368;"><strong style="color:#3c4043;">Alamat:</strong><br>' . $eAddress . '</td></tr>'
        : '';
    $footerNoteRow = $eFooterNote !== ''
        ? '<tr><td style="padding-top:10px;border-top:1px solid #e8eaed;font-size:11.5px;color:#80868b;">' . $eFooterNote . '</td></tr>'
        : '';

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
            <td style="padding:24px 32px 18px 32px;border-bottom:1px solid #eceff1;background-color:#ffffff;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td valign="middle" style="padding:0;">
                    <img src="{$eLogoUrl}" alt="{$eBrandTitle}" width="36" height="36" style="display:inline-block;vertical-align:middle;border:0;border-radius:8px;max-width:36px;height:auto;margin-right:10px;">
                    <span style="display:inline-block;vertical-align:middle;font-size:18px;font-weight:700;color:#111827;letter-spacing:-0.01em;">{$eBrandTitle}</span>
                  </td>
                  <td align="right" valign="middle" style="padding:0;font-size:12px;color:#5f6368;">
                    {$eSupportEmail}
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

          <!-- Footer -->
          <tr>
            <td style="padding:20px 32px 22px 32px;background-color:#fafbfc;border-top:1px solid #e8eaed;font-size:12.5px;line-height:1.65;color:#5f6368;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td style="padding-bottom:8px;color:#3c4043;">
                    <strong style="font-size:13.5px;color:#111827;">{$eBrandTitle}</strong><br>
                    Email: <a href="mailto:{$eSupportEmail}" style="color:#1a73e8;text-decoration:none;">{$eSupportEmail}</a>
                  </td>
                </tr>
                {$addressRow}
                {$footerNoteRow}
              </table>
            </td>
          </tr>
          {$watermarkBlock}
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

/**
 * Sisipkan watermark wajib paket Free ketika user memilih mode kirim HTML bebas (tanpa wrapper).
 */
function append_plain_watermark_html(string $html, string $watermarkText): string
{
    $wm = trim($watermarkText);
    if ($wm === '') {
        return $html;
    }
    return $html . '<div style="margin-top:24px;padding-top:12px;border-top:1px solid #e5e7eb;font-size:11.5px;color:#64748b;text-align:center;">'
        . e($wm)
        . '</div>';
}

/**
 * Ambil template default untuk kategori tertentu (memprioritaskan template milik user jika ada).
 */
function get_default_template_by_category(string $category, ?int $ownerId = null): ?array
{
    $allowed = ['reply', 'reply_all', 'compose', 'forward', 'draft'];
    if (!in_array($category, $allowed, true)) {
        $category = 'reply';
    }

    try {
        if ($ownerId !== null && $ownerId > 0) {
            $stmt = db()->prepare(
                'SELECT * FROM email_templates
                 WHERE category = :cat AND is_active = 1 AND (owner_id = :oid OR owner_id IS NULL)
                 ORDER BY (owner_id = :oid2) DESC, is_default DESC, id ASC
                 LIMIT 1'
            );
            $stmt->execute([':cat' => $category, ':oid' => $ownerId, ':oid2' => $ownerId]);
        } else {
            $stmt = db()->prepare(
                'SELECT * FROM email_templates
                 WHERE category = :cat AND is_active = 1 AND owner_id IS NULL
                 ORDER BY is_default DESC, id ASC
                 LIMIT 1'
            );
            $stmt->execute([':cat' => $category]);
        }
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        app_log('error', 'get_default_template_by_category error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Ambil semua template yang dapat diakses pengguna (template global + template pribadi miliknya).
 * Superadmin melihat semua template global & pribadinya.
 */
function get_templates(?string $category = null, ?int $ownerId = null, bool $isSuper = false): array
{
    try {
        $where  = [];
        $params = [];

        if ($category !== null && $category !== '' && $category !== 'all') {
            $where[] = 'category = :cat';
            $params[':cat'] = $category;
        }

        if (!$isSuper) {
            if ($ownerId !== null && $ownerId > 0) {
                $where[] = '(owner_id IS NULL OR owner_id = :oid)';
                $params[':oid'] = $ownerId;
            } else {
                $where[] = 'owner_id IS NULL';
            }
        }

        $sql = 'SELECT * FROM email_templates';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY (owner_id IS NOT NULL) DESC, category ASC, is_default DESC, name ASC';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        app_log('error', 'get_templates error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Sanitasi HTML email keluar (dari editor) — hanya izinkan tag pemformatan aman.
 */
function sanitize_outgoing_body_html(string $html): string
{
    return sanitize_email_html($html);
}

/**
 * Sanitasi HTML email masuk / keluar menggunakan DOMDocument allowlist (anti-XSS).
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
                        while ($child->firstChild) {
                            $node->insertBefore($child->firstChild, $child);
                        }
                        $node->removeChild($child);
                        continue;
                    }

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
