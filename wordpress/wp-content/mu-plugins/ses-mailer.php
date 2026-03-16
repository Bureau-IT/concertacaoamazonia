<?php
/**
 * Plugin Name: SES Mailer
 * Description: Configura envio de emails via AWS SES SMTP.
 * Version: 1.0.0
 */

add_action('phpmailer_init', function ($phpmailer) {
    $host = defined('SMTP_HOST') ? SMTP_HOST : '';
    if (empty($host)) {
        return;
    }

    $phpmailer->isSMTP();
    $phpmailer->Host       = $host;
    $phpmailer->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Username   = defined('SMTP_USERNAME') ? SMTP_USERNAME : '';
    $phpmailer->Password   = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '';
    $phpmailer->SMTPSecure = 'tls';

    // Força remetente verificado no SES, sobrescrevendo qualquer From definido por plugins (ex: Gravity Forms)
    $from_email = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'noreply@concertacaoamazonia.com.br';
    $from_name  = defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME  : 'Concertação pela Amazônia';
    $phpmailer->setFrom($from_email, $from_name, false);
}, 99);

add_filter('wp_mail_from', function () {
    return defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'noreply@concertacaoamazonia.com.br';
});

add_filter('wp_mail_from_name', function () {
    return defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Concertação pela Amazônia';
});
