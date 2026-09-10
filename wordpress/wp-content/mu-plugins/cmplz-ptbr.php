<?php
/**
 * Plugin Name: Complianz pt_BR Labels
 * Description: Traduz labels do Complianz para pt_BR (só com locale pt_*)
 * Version: 1.1.0
 */
add_filter('gettext', function($translation, $text, $domain) {
    if ($domain !== 'complianz-gdpr') return $translation;
    // Só traduz quando o locale corrente é português. Sem esta guarda o
    // filtro rodava também em /cultura/en/ (WPML) e o link do banner saía
    // "Aviso de Privacidade" em inglês — Gate 20 do /smoke, 10/09/2026.
    if (strpos((string) determine_locale(), 'pt') !== 0) return $translation;
    static $map = array('Privacy Statement' => 'Aviso de Privacidade');
    return isset($map[$text]) ? $map[$text] : $translation;
}, 10, 3);
