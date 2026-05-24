<?php
// ReliCheck email renderer.
//
// Loads a template by template_key, substitutes {{variables}}, scrubs any
// variable that is on the restricted list (so private survey data never
// leaks into employee emails or stored sanitized snapshots), and wraps the
// inner content in the standard ReliCheck shell.
//
// All mutations stay in this file so the caller (dispatcher) does not need
// to know template internals.

declare(strict_types=1);

require_once __DIR__ . '/_db.php';

// ---------------------------------------------------------------------------
// Variable names that must NEVER appear in any rendered email body or
// stored snapshot. Restricted variables are referenced only by the
// elevated-permission UI inside the admin panel, never by mail.
// ---------------------------------------------------------------------------
function relicheck_email_restricted_fields(): array
{
    return [
        'response_text',
        'response_body',
        'respondent_id',
        'respondent_email',
        'respondent_name',
        'respondent_ip',
        'survey_responses',
        'survey_results_private',
        'uploaded_file_contents',
        'uploaded_file_path',
        'ai_analysis_text',
        'ai_insights_text',
        'private_report_body',
    ];
}

// ---------------------------------------------------------------------------
// Load a template row by key, including the resolved sender address derived
// from email_departments.
// ---------------------------------------------------------------------------
function relicheck_email_load_template(string $template_key): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT t.*, d.code AS department_code, d.display_name AS sender_display_name,
                d.sender_email AS sender_email
         FROM email_templates t
         JOIN email_departments d ON d.id = t.department_id
         WHERE t.template_key = :k AND t.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([':k' => $template_key]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ---------------------------------------------------------------------------
// Strip any restricted keys from the payload BEFORE substitution. The same
// scrubbed payload is what gets stored on email_logs.dynamic_payload.
// ---------------------------------------------------------------------------
function relicheck_email_scrub_payload(array $payload): array
{
    $blocked = relicheck_email_restricted_fields();
    foreach ($blocked as $k) {
        if (array_key_exists($k, $payload)) {
            unset($payload[$k]);
        }
    }
    return $payload;
}

// ---------------------------------------------------------------------------
// Replace {{var}} occurrences with values from $payload. Missing variables
// render as empty string (logged at WARN by the dispatcher).
// ---------------------------------------------------------------------------
function relicheck_email_substitute(string $template, array $payload): string
{
    return preg_replace_callback(
        '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
        static function ($m) use ($payload) {
            $k = $m[1];
            if (!array_key_exists($k, $payload)) return '';
            return (string)$payload[$k];
        },
        $template
    );
}

// ---------------------------------------------------------------------------
// HTML-escape every payload value before substitution into HTML bodies.
// Plain-text bodies use raw values.
// ---------------------------------------------------------------------------
function relicheck_email_escape_payload_for_html(array $payload): array
{
    $out = [];
    foreach ($payload as $k => $v) {
        $out[$k] = htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Wrap inner HTML body in the ReliCheck shell. The renderer assembles the
// primary button from the template's button label + url template.
// ---------------------------------------------------------------------------
function relicheck_email_wrap_html(array $tpl, string $inner_html, string $button_url, array $extras = []): string
{
    $brand_color = '#3d57f5';
    $text_color  = '#1a1d2e';
    $muted_color = '#5a607a';
    $bg_card     = '#ffffff';
    $bg_outer    = '#f4f5fb';

    $sender_label = htmlspecialchars((string)$tpl['sender_display_name'], ENT_QUOTES);
    $sender_email = htmlspecialchars((string)$tpl['sender_email'], ENT_QUOTES);
    $preview      = htmlspecialchars((string)$tpl['preview_text'], ENT_QUOTES);

    $button_html = '';
    if (!empty($tpl['primary_button_label']) && $button_url !== '') {
        $label = htmlspecialchars((string)$tpl['primary_button_label'], ENT_QUOTES);
        $href  = htmlspecialchars($button_url, ENT_QUOTES);
        $button_html =
            '<p style="text-align:center;margin:28px 0;">' .
              '<a href="' . $href . '" style="display:inline-block;padding:12px 22px;' .
              'background:' . $brand_color . ';color:#ffffff;border-radius:6px;' .
              'text-decoration:none;font-weight:600;font-size:14px;">' . $label . '</a>' .
            '</p>';
    }

    $unsubscribe_html = '';
    if (!empty($extras['unsubscribe_url'])) {
        $u = htmlspecialchars((string)$extras['unsubscribe_url'], ENT_QUOTES);
        $unsubscribe_html =
            '<p style="color:' . $muted_color . ';font-size:11px;margin-top:24px;">' .
              'You are receiving this because you opted in. ' .
              '<a href="' . $u . '" style="color:' . $muted_color . ';">Unsubscribe</a>.' .
            '</p>';
    }

    $address_html = '';
    if (!empty($extras['business_address'])) {
        $a = htmlspecialchars((string)$extras['business_address'], ENT_QUOTES);
        $address_html =
            '<p style="color:' . $muted_color . ';font-size:11px;margin-top:8px;">' . $a . '</p>';
    }

    return
      '<!DOCTYPE html><html><head><meta charset="UTF-8">' .
      '<meta name="viewport" content="width=device-width,initial-scale=1">' .
      '<title>' . htmlspecialchars((string)$tpl['subject_line'], ENT_QUOTES) . '</title></head>' .
      '<body style="margin:0;padding:0;background:' . $bg_outer . ';font-family:-apple-system,BlinkMacSystemFont,Inter,Arial,sans-serif;color:' . $text_color . ';">' .
        '<span style="display:none!important;visibility:hidden;mso-hide:all;font-size:1px;color:' . $bg_outer . ';line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;">' . $preview . '</span>' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $bg_outer . ';">' .
          '<tr><td align="center" style="padding:32px 16px;">' .
            '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:' . $bg_card . ';border-radius:10px;padding:32px;">' .
              '<tr><td>' .
                '<p style="margin:0 0 24px 0;font-size:20px;font-weight:700;color:' . $brand_color . ';">ReliCheck</p>' .
                '<div style="font-size:15px;line-height:1.55;">' . $inner_html . '</div>' .
                $button_html .
                '<p style="color:' . $muted_color . ';font-size:12px;margin-top:32px;">From ' . $sender_label . ' (' . $sender_email . ')</p>' .
                $unsubscribe_html .
                $address_html .
              '</td></tr>' .
            '</table>' .
          '</td></tr>' .
        '</table>' .
      '</body></html>';
}

// ---------------------------------------------------------------------------
// Render a template to ready-to-send {subject, html, text, preview, ...}.
// $payload values must already be merged with system defaults (site_url, etc).
// Sender details come from the template's department.
// ---------------------------------------------------------------------------
function relicheck_email_render(array $tpl, array $payload, array $extras = []): array
{
    // Restricted fields out, every time.
    $payload = relicheck_email_scrub_payload($payload);

    // Compose the button URL from the template's URL template.
    $button_url = '';
    if (!empty($tpl['primary_button_url_template'])) {
        $button_url = relicheck_email_substitute(
            (string)$tpl['primary_button_url_template'],
            $payload
        );
        $payload['button_url']   = $button_url;
        $payload['button_label'] = (string)($tpl['primary_button_label'] ?? '');
    }

    // Subject + preview substitute against raw payload (no HTML).
    $subject = relicheck_email_substitute((string)$tpl['subject_line'], $payload);
    $preview = relicheck_email_substitute((string)$tpl['preview_text'], $payload);

    // HTML body uses HTML-escaped payload (except button_url, which is in href).
    $html_payload = relicheck_email_escape_payload_for_html($payload);
    $html_payload['button_url'] = $button_url; // href is escaped at wrap time
    $inner_html  = relicheck_email_substitute((string)$tpl['body_html'], $html_payload);
    $full_html   = relicheck_email_wrap_html(array_merge($tpl, ['preview_text' => $preview]), $inner_html, $button_url, $extras);

    // Text body uses raw payload.
    $text = relicheck_email_substitute((string)$tpl['body_text'], $payload);

    return [
        'subject'              => $subject,
        'preview'              => $preview,
        'html'                 => $full_html,
        'text'                 => $text,
        'sender_email'         => (string)$tpl['sender_email'],
        'sender_display_name'  => (string)$tpl['sender_display_name'],
        'sanitized_payload'    => $payload,
        'button_url'           => $button_url,
    ];
}

// ---------------------------------------------------------------------------
// Defensive guard: reject any employee template that references a restricted
// variable in body or subject. Called by the dispatcher BEFORE rendering.
// ---------------------------------------------------------------------------
function relicheck_email_template_violates_privacy(array $tpl): ?string
{
    if (($tpl['audience'] ?? '') !== 'employee') return null;
    $blocked = relicheck_email_restricted_fields();
    $haystack = strtolower(
        (string)$tpl['subject_line'] . ' ' .
        (string)$tpl['preview_text'] . ' ' .
        (string)$tpl['body_html']    . ' ' .
        (string)$tpl['body_text']
    );
    foreach ($blocked as $b) {
        if (strpos($haystack, '{{' . $b . '}}') !== false ||
            strpos($haystack, '{{ ' . $b . ' }}') !== false) {
            return $b;
        }
    }
    return null;
}
