<?php
// Fix Spinner Elementor Page Transition — usar Main color das Global Colors
//
// Atual:
//   settings_page_transitions_preloader_color = "#262834" (cinza escuro literal)
//   __globals__[settings_page_transitions_preloader_color] = "globals/colors?id=96a86ed" (Color Extra 1 #003A26)
//
// Esperado:
//   settings_page_transitions_preloader_color = "" (vazio — usa global)
//   __globals__[settings_page_transitions_preloader_color] = "globals/colors?id=primary" (Main color #005A42)

$kit_id = (int) get_option('elementor_active_kit');
echo "Active kit: $kit_id\n";

$settings = get_post_meta($kit_id, '_elementor_page_settings', true);
if (!is_array($settings)) {
    echo "ERROR: settings not array (type: " . gettype($settings) . ")\n";
    return;
}

// Inspect current
echo "BEFORE settings_page_transitions_preloader_color: " . var_export($settings['settings_page_transitions_preloader_color'] ?? null, true) . "\n";
$gl = $settings['__globals__'] ?? [];
echo "BEFORE __globals__[settings_page_transitions_preloader_color]: " . var_export($gl['settings_page_transitions_preloader_color'] ?? null, true) . "\n";

// Apply changes
$settings['settings_page_transitions_preloader_color'] = '';
if (!isset($settings['__globals__']) || !is_array($settings['__globals__'])) {
    $settings['__globals__'] = [];
}
$settings['__globals__']['settings_page_transitions_preloader_color'] = 'globals/colors?id=primary';

echo "AFTER settings_page_transitions_preloader_color: '" . $settings['settings_page_transitions_preloader_color'] . "'\n";
echo "AFTER __globals__[settings_page_transitions_preloader_color]: '" . $settings['__globals__']['settings_page_transitions_preloader_color'] . "'\n";

if (getenv('APPLY') !== '1') {
    echo "\nDRY-RUN. Set APPLY=1 to apply.\n";
    return;
}

$ok = update_post_meta($kit_id, '_elementor_page_settings', $settings);
echo $ok ? "OK: kit settings updated\n" : "WARN: update_post_meta returned false (mesmo valor?)\n";

// Regenerate kit CSS so the change reflects in front-end
if (class_exists('\Elementor\Plugin')) {
    \Elementor\Plugin::$instance->files_manager->clear_cache();
    // Regenerate global CSS file
    if (method_exists(\Elementor\Plugin::$instance->kits_manager, 'get_active_kit')) {
        $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
        if ($kit) {
            $kit_css = new \Elementor\Core\Files\CSS\Post($kit_id);
            $kit_css->update();
        }
    }
    echo "Elementor CSS cache cleared + kit CSS regenerated\n";
}

// Verify persistence
$check = get_post_meta($kit_id, '_elementor_page_settings', true);
echo "POST-WRITE settings_page_transitions_preloader_color: " . var_export($check['settings_page_transitions_preloader_color'] ?? null, true) . "\n";
echo "POST-WRITE __globals__[settings_page_transitions_preloader_color]: " . var_export($check['__globals__']['settings_page_transitions_preloader_color'] ?? null, true) . "\n";
