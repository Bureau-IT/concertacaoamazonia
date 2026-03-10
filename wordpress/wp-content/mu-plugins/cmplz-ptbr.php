<?php
/**
 * Plugin Name: Complianz pt_BR Labels
 * Description: Traduz labels do Complianz para pt_BR (sem WPML/Polylang)
 */
add_filter('gettext', function($translation, $text, $domain) {
    if ($domain !== 'complianz-gdpr') return $translation;
    static $map = array('Privacy Statement' => 'Aviso de Privacidade');
    return isset($map[$text]) ? $map[$text] : $translation;
}, 10, 3);
