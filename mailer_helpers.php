<?php
/**************************************************
 * LRO mail helpers: fetch settings, render tokens,
 * and send a simple HTML email (with CC routing).
 **************************************************/
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

/* Load PS if not already loaded (safe on repeat) */
if (!class_exists('Configuration')) {
    $dir = __DIR__;
    for ($i=0; $i<8; $i++) {
        if (file_exists($dir.'/config/config.inc.php') && file_exists($dir.'/init.php')) {
            require_once $dir.'/config/config.inc.php';
            require_once $dir.'/init.php';
            break;
        }
        $dir = dirname($dir);
    }
}

function lro_email_settings(): array {
    $get = fn($k, $d='') => (string)Configuration::get($k) ?: $d;
    return [
        // templates
        'approve_subject' => $get('LRO_APPROVE_SUBJECT', 'Your documents are approved'),
        'approve_body'    => $get('LRO_APPROVE_BODY',    "Hello {firstname},\nYour documents were approved."),
        'reject_subject'  => $get('LRO_REJECT_SUBJECT',  'Issue with your documents'),
        'reject_body'     => $get('LRO_REJECT_BODY',     "Hello {firstname},\nWe could not accept your document: {rejection_reason}"),
        // routing
        'from_name'       => $get('LRO_MAIL_FROM_NAME',  Configuration::get('PS_SHOP_NAME') ?: 'Support'),
        'from_email'      => $get('LRO_MAIL_FROM_EMAIL', Configuration::get('PS_SHOP_EMAIL') ?: 'no-reply@'.parse_url(Tools::getShopDomainSsl(true), PHP_URL_HOST)),
        'notify_approve'  => $get('LRO_NOTIFY_APPROVAL_TO', ''),
        'notify_reject'   => $get('LRO_NOTIFY_REJECT_TO',  ''),
    ];
}

/* Replace {tokens} in text. */
function lro_render_template(string $tpl, array $vars): string {
    $repl = [];
    foreach ($vars as $k => $v) $repl['{'.strtolower($k).'}'] = (string)$v;
    return strtr($tpl, $repl);
}

/* Build a default token map for convenience. */
function lro_default_tokens(array $extra = []): array {
    $baseUrl = rtrim(Tools::getShopDomainSsl(true), '/') . rtrim(__PS_BASE_URI__ ?? '/', '/');
    $tokens = [
        'site_name'       => Configuration::get('PS_SHOP_NAME') ?: 'Our Store',
        'dashboard_url'   => $baseUrl.'/index.php?controller=history',
        // common person/group fields; callers can override
        'firstname'       => '',
        'lastname'        => '',
        'group_name'      => '',
        'requirement_name'=> '',
        'rejection_reason'=> '',
    ];
    return $extra + $tokens;
}

/* Send a simple HTML email (fallback using PHP mail). */
function lro_send_mail_simple(string $toEmail, string $toName, string $subject, string $html, ?string $fromEmail=null, ?string $fromName=null): bool {
    $fromEmail = $fromEmail ?: (Configuration::get('PS_SHOP_EMAIL') ?: 'no-reply@localhost');
    $fromName  = $fromName  ?: (Configuration::get('PS_SHOP_NAME')  ?: 'Support');
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'From: '.sprintf('"%s" <%s>', mb_encode_mimeheader($fromName, 'UTF-8'), $fromEmail);
    $headers[] = 'Reply-To: '.$fromEmail;
    $headers[] = 'X-Mailer: PHP/'.phpversion();
    return @mail($toEmail, $subject, $html, implode("\r\n", $headers));
}

/* Compose + optionally CC route list */
function lro_compose_and_send(string $kind, array $tokens, string $toEmail, string $toName=''): bool {
    $cfg = lro_email_settings();
    $isApprove = ($kind === 'approve');

    $subjectTpl = $isApprove ? $cfg['approve_subject'] : ($cfg['reject_subject'] ?: 'Notification');
    $bodyTpl    = $isApprove ? $cfg['approve_body']    : ($cfg['reject_body']    ?: '');
    $subject    = lro_render_template($subjectTpl, $tokens);
    $bodyHtml   = nl2br(lro_render_template($bodyTpl, $tokens), false);

    // main recipient
    $ok = lro_send_mail_simple($toEmail, $toName, $subject, $bodyHtml, $cfg['from_email'], $cfg['from_name']);

    // optional notify CCs
    $list = $isApprove ? $cfg['notify_approve'] : $cfg['notify_reject'];
    foreach (array_filter(array_map('trim', explode(',', $list))) as $cc) {
        if (filter_var($cc, FILTER_VALIDATE_EMAIL)) {
            lro_send_mail_simple($cc, '', '[Copy] '.$subject, $bodyHtml, $cfg['from_email'], $cfg['from_name']);
        }
    }
    return $ok;
}
