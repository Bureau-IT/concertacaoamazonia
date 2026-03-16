<?php
/**
 * The Events Calendar - Hooks customizados
 *
 * Este arquivo é carregado automaticamente pelo TEC quando existe no diretório tribe/ do tema.
 * Contém filtros e ações para customizar o comportamento do calendário.
 *
 * @package HelloElementorChild
 * @subpackage TEC
 * @author Daniel Cambría + Claude Code
 * @version 1.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registra o diretório de templates do tema para o TEC Views V2
 *
 * Isso garante que os templates customizados em tribe/events/v2/ sejam carregados
 * em todos os contextos (shortcodes, páginas de arquivo, widgets, etc.)
 */
add_filter('tribe_template_path_list', 'bureau_it_tec_register_template_path', 15, 2);
function bureau_it_tec_register_template_path($folders, $template) {
    $theme_folder = [
        'id'       => 'hello-elementor-child-tribe',
        'priority' => 5, // Prioridade alta para carregar antes do plugin
        'path'     => get_stylesheet_directory() . '/tribe',
    ];

    // Adicionar no início para ter prioridade
    array_unshift($folders, $theme_folder);

    return $folders;
}
