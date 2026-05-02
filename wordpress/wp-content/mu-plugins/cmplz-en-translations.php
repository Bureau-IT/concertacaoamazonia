<?php
/**
 * Plugin Name: Complianz EN Translations
 * Description: Traduções EN do banner Complianz aplicadas direto no filtro wpml_translate_single_string. WPML String Translation usa MO files que não foram gerados para o domain "complianz" — este filtro intercepta antes da resolução MO/DB e retorna a tradução EN quando ICL_LANGUAGE_CODE=en.
 * Version: 1.0.0
 */

add_filter('wpml_translate_single_string', function ($translated, $domain, $name, $lang_code = null) {
    if ($domain !== 'complianz') {
        return $translated;
    }

    $lang = $lang_code ?: (defined('ICL_LANGUAGE_CODE') ? ICL_LANGUAGE_CODE : null);
    if ($lang !== 'en') {
        return $translated;
    }

    static $en = [
        'header'                    => 'Manage consent',
        'revoke'                    => 'Manage consent',
        'dismiss'                   => 'Decline',
        'accept'                    => 'Accept',
        'accept_informational'      => 'Accept',
        'save_preferences'          => 'Save preferences',
        'view_preferences'          => 'View preferences',
        'category_functional'       => 'Functional',
        'category_all'              => 'Marketing',
        'category_stats'            => 'Statistics',
        'category_prefs'            => 'Preferences',
        'message_optin'             => 'To provide the best experiences, we use technologies like cookies to store and/or access device information. Consenting to these technologies will allow us to process data such as browsing behavior or unique IDs on this site. Not consenting or withdrawing consent may adversely affect certain features and functions.',
        'message_optout'            => 'To provide the best experiences, we use technologies like cookies to store and/or access device information. Consenting to these technologies will allow us to process data such as browsing behavior or unique IDs on this site. Not consenting or withdrawing consent may adversely affect certain features and functions.',
        'functional_text'           => 'The technical storage or access is strictly necessary for the legitimate purpose of enabling the use of a specific service explicitly requested by the subscriber or user, or for the sole purpose of carrying out the transmission of a communication over an electronic communications network.',
        'statistics_text'           => 'The technical storage or access that is used exclusively for statistical purposes.',
        'statistics_text_anonymous' => 'The technical storage or access that is used exclusively for anonymous statistical purposes. Without a subpoena, voluntary compliance on the part of your Internet Service Provider, or additional records from a third party, information stored or retrieved for this purpose alone cannot usually be used to identify you.',
        'preferences_text'          => 'The technical storage or access is necessary for the legitimate purpose of storing preferences that are not requested by the subscriber or user.',
        'marketing_text'            => 'The technical storage or access is required to create user profiles to send advertising, or to track the user on a website or across several websites for similar marketing purposes.',
    ];

    return isset($en[$name]) ? $en[$name] : $translated;
}, 5, 4);
