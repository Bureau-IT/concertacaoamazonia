<?php
// Same fix as fix-preloader-main-color.php but for blog 2 (Cultura)

switch_to_blog(2);

$kit_id = (int) get_option('elementor_active_kit');
echo "Blog 2 active kit: $kit_id\n";

$settings = get_post_meta($kit_id, '_elementor_page_settings', true);
if (!is_array($settings)) {
    echo "ERROR: settings not array (type: " . gettype($settings) . ")\n";
    return;
}

echo "BEFORE settings_page_transitions_preloader_color: " . var_export($settings['settings_page_transitions_preloader_color'] ?? null, true) . "\n";
$gl = $settings['__globals__'] ?? [];
echo "BEFORE __globals__[settings_page_transitions_preloader_color]: " . var_export($gl['settings_page_transitions_preloader_color'] ?? null, true) . "\n";

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
echo $ok ? "OK: kit settings updated\n" : "WARN: update_post_meta returned false\n";

if (class_exists('\Elementor\Plugin')) {
    \Elementor\Plugin::$instance->files_manager->clear_cache();
    $kit_css = new \Elementor\Core\Files\CSS\Post($kit_id);
    $kit_css->update();
    echo "Elementor CSS cache cleared + kit CSS regenerated\n";
}

$check = get_post_meta($kit_id, '_elementor_page_settings', true);
echo "POST-WRITE settings_page_transitions_preloader_color: " . var_export($check['settings_page_transitions_preloader_color'] ?? null, true) . "\n";
echo "POST-WRITE __globals__[settings_page_transitions_preloader_color]: " . var_export($check['__globals__']['settings_page_transitions_preloader_color'] ?? null, true) . "\n";
