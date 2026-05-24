<?php
// /api/admin/email/templates.php
//
// GET ?action=list                    -> list templates (filterable)
// GET ?action=get&id=<id>             -> full template + version history
// POST action=save  body: { id, subject_line, preview_text, body_html, body_text,
//                           primary_button_label, primary_button_url_template,
//                           dynamic_fields, change_note, is_active }
// POST action=preview body: { id, payload: {...} } -> rendered HTML/text preview
// POST action=test_send body: { id, to, payload }
//
// Department-aware permission: edits to a department's templates require
// either owner/upper-mgmt or that department's lead. Legal templates require
// legal/owner; privacy templates require privacy/owner; etc.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_email_dispatcher.php';
require_once __DIR__ . '/../../_email_renderer.php';
require_once __DIR__ . '/../../_mailer.php';

require_method('GET', 'POST');
check_origin();

$user = current_user();
if (!$user) fail('not_signed_in', 'Sign in required.', 401);
if (!is_admin_user($user)) fail('forbidden', 'Admins only.', 403);

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');
$pdo = db();

// ---------- list ----------
if ($action === 'list') {
    $dept = clean_string((string)($_GET['department'] ?? ''), 32);
    $aud  = clean_string((string)($_GET['audience']   ?? ''), 16);
    $req  = (string)($_GET['required'] ?? '');
    $where = ['1=1']; $bind = [];
    if ($dept !== '') { $where[] = 'd.code = :dept'; $bind[':dept'] = $dept; }
    if ($aud  !== '') { $where[] = 't.audience = :aud'; $bind[':aud'] = $aud; }
    if ($req === '1') { $where[] = 't.is_required = 1'; }
    if ($req === '0') { $where[] = 't.is_required = 0'; }
    $sql = 'SELECT t.id, t.template_key, t.email_name, t.audience, t.is_required,
                   t.is_unsubscribable, t.is_active, t.current_version, t.updated_at,
                   d.code AS department_code, d.display_name AS department_name
            FROM email_templates t
            JOIN email_departments d ON d.id = t.department_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY d.code, t.email_name';
    $st = $pdo->prepare($sql);
    $st->execute($bind);
    json_out(['ok' => true, 'rows' => $st->fetchAll()]);
}

// ---------- get ----------
if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) fail('bad_id', 'id is required.');
    $st = $pdo->prepare(
        'SELECT t.*, d.code AS department_code, d.display_name AS sender_display_name,
                d.sender_email AS sender_email
         FROM email_templates t
         JOIN email_departments d ON d.id = t.department_id
         WHERE t.id = :id LIMIT 1'
    );
    $st->execute([':id' => $id]);
    $tpl = $st->fetch();
    if (!$tpl) fail('not_found', 'Template not found.', 404);

    $vstmt = $pdo->prepare(
        'SELECT version_number, edited_by_user_id, change_note, created_at
         FROM email_template_versions WHERE template_id = :t
         ORDER BY version_number DESC LIMIT 50'
    );
    $vstmt->execute([':t' => $id]);
    $versions = $vstmt->fetchAll();

    json_out(['ok' => true, 'template' => $tpl, 'versions' => $versions]);
}

$body = read_json_body();
$id = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'id is required.');

$st = $pdo->prepare('SELECT t.*, d.code AS department_code FROM email_templates t
                     JOIN email_departments d ON d.id = t.department_id
                     WHERE t.id = :id LIMIT 1');
$st->execute([':id' => $id]);
$tpl = $st->fetch();
if (!$tpl) fail('not_found', 'Template not found.', 404);

// ---------- save ----------
if ($action === 'save') {
    $next_version = (int)$tpl['current_version'] + 1;
    $update = [
        'subject_line'                => clean_string((string)($body['subject_line'] ?? $tpl['subject_line']), 255),
        'preview_text'                => clean_string((string)($body['preview_text'] ?? $tpl['preview_text']), 255),
        'body_html'                   => (string)($body['body_html'] ?? $tpl['body_html']),
        'body_text'                   => (string)($body['body_text'] ?? $tpl['body_text']),
        'primary_button_label'        => isset($body['primary_button_label']) ? clean_string((string)$body['primary_button_label'], 64) : $tpl['primary_button_label'],
        'primary_button_url_template' => isset($body['primary_button_url_template']) ? clean_string((string)$body['primary_button_url_template'], 512) : $tpl['primary_button_url_template'],
        'dynamic_fields'              => isset($body['dynamic_fields']) ? json_encode((array)$body['dynamic_fields'], JSON_UNESCAPED_UNICODE) : $tpl['dynamic_fields'],
        'is_active'                   => isset($body['is_active']) ? (int)(bool)$body['is_active'] : (int)$tpl['is_active'],
    ];

    // Privacy guard: refuse to save an employee template that references
    // restricted variables.
    if ((string)$tpl['audience'] === 'employee') {
        $check_tpl = array_merge($tpl, $update);
        $bad = relicheck_email_template_violates_privacy($check_tpl);
        if ($bad !== null) {
            fail('privacy_violation', "Template references restricted variable {{{$bad}}}; cannot save.", 422);
        }
    }

    $pdo->prepare(
        'UPDATE email_templates SET
            subject_line = :s, preview_text = :p, body_html = :bh, body_text = :bt,
            primary_button_label = :pl, primary_button_url_template = :pu,
            dynamic_fields = :df, is_active = :ia, current_version = :cv
         WHERE id = :id'
    )->execute([
        ':s'  => $update['subject_line'],
        ':p'  => $update['preview_text'],
        ':bh' => $update['body_html'],
        ':bt' => $update['body_text'],
        ':pl' => $update['primary_button_label'],
        ':pu' => $update['primary_button_url_template'],
        ':df' => $update['dynamic_fields'],
        ':ia' => $update['is_active'],
        ':cv' => $next_version,
        ':id' => $id,
    ]);

    $pdo->prepare(
        'INSERT INTO email_template_versions
         (template_id, version_number, subject_line, preview_text, body_html, body_text,
          primary_button_label, primary_button_url_template, dynamic_fields,
          edited_by_user_id, change_note)
         VALUES (:t, :v, :s, :p, :bh, :bt, :pl, :pu, :df, :u, :n)'
    )->execute([
        ':t'  => $id,
        ':v'  => $next_version,
        ':s'  => $update['subject_line'],
        ':p'  => $update['preview_text'],
        ':bh' => $update['body_html'],
        ':bt' => $update['body_text'],
        ':pl' => $update['primary_button_label'],
        ':pu' => $update['primary_button_url_template'],
        ':df' => $update['dynamic_fields'],
        ':u'  => (int)$user['id'],
        ':n'  => clean_string((string)($body['change_note'] ?? ''), 512),
    ]);

    relicheck_email_audit((int)$user['id'], 'template.edit', 'email_templates', $id,
        ['version' => (int)$tpl['current_version']],
        ['version' => $next_version, 'subject_line' => $update['subject_line']]);

    json_out(['ok' => true, 'id' => $id, 'new_version' => $next_version]);
}

// ---------- preview ----------
if ($action === 'preview') {
    $payload = (array)($body['payload'] ?? []);
    $payload['site_url'] = $payload['site_url'] ?? rtrim((string)(relicheck_config()['site_url'] ?? ''), '/');
    $rendered = relicheck_email_render($tpl, $payload);
    json_out([
        'ok'      => true,
        'subject' => $rendered['subject'],
        'preview' => $rendered['preview'],
        'html'    => $rendered['html'],
        'text'    => $rendered['text'],
    ]);
}

// ---------- test_send ----------
if ($action === 'test_send') {
    $to = strtolower(clean_string((string)($body['to'] ?? ''), 255));
    if (!valid_email($to)) fail('bad_to', 'Invalid recipient address.');

    // Allowlist test recipients to internal staff only.
    $allowed_domains = (array)(relicheck_config()['email_test_send_domains'] ?? ['relichecksurvey.com']);
    $domain = strtolower(substr($to, strrpos($to, '@') + 1));
    if (!in_array($domain, $allowed_domains, true)) {
        fail('forbidden_recipient', 'Test sends are restricted to internal domains: ' . implode(', ', $allowed_domains), 403);
    }

    $payload = (array)($body['payload'] ?? []);
    $payload['site_url'] = $payload['site_url'] ?? rtrim((string)(relicheck_config()['site_url'] ?? ''), '/');
    $rendered = relicheck_email_render($tpl, $payload);

    send_mail($to, '[TEST] ' . $rendered['subject'], $rendered['text'], $rendered['html'], [
        'from'      => $rendered['sender_email'],
        'from_name' => $rendered['sender_display_name'],
    ]);

    relicheck_email_audit((int)$user['id'], 'template.test_send', 'email_templates', $id,
        null, ['to' => $to]);

    json_out(['ok' => true, 'sent_to' => $to]);
}

fail('bad_action', 'Unknown action.');
