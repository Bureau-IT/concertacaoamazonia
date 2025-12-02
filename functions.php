<?php
/**
 * Theme functions and definitions
 *
 * @package HelloElementorChild
 * @author  Daniel Cambría + Warp
 * @version 1.4.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ============================================================================
 * THEME SETUP
 * ============================================================================
 */

/**
 * Enqueue parent and child theme styles
 */
add_action('wp_enqueue_scripts', 'hello_elementor_child_enqueue_scripts');
function hello_elementor_child_enqueue_scripts() {
    wp_enqueue_style('hello-elementor-parent', get_template_directory_uri() . '/style.css');
    wp_enqueue_style('hello-elementor-child', get_stylesheet_directory_uri() . '/style.css', array('hello-elementor-parent'));
}

/**
 * ============================================================================
 * ADMIN CUSTOMIZATIONS
 * ============================================================================
 */

/**
 * Add custom admin CSS
 */
add_action('admin_enqueue_scripts', 'bureau_it_admin_css');
function bureau_it_admin_css() {
    wp_enqueue_style('bureau-it-admin-css', get_stylesheet_directory_uri() . '/admin-style.css');
}

/**
 * ============================================================================
 * JETENGINE CUSTOMIZATIONS
 * ============================================================================
 */
add_filter('jet-engine/maps-listings/data-settings', function($settings) {
    $settings['clustererImg'] = get_stylesheet_directory_uri() . '/markerclusterer-img/m';
    return $settings;
});

/**
 * ============================================================================
 * EVENTS CALENDAR CUSTOMIZATIONS
 * ============================================================================
 */
add_action('tribe_events_single_event_before_the_content', 'bureau_it_custom_event_display');
add_action('tribe_events_list_widget_before_the_event_title', 'bureau_it_custom_event_display');

function bureau_it_custom_event_display($event = null) {
    $event = $event ?? tribe_get_event();
    if (!$event) return;

    $categories = wp_get_post_terms($event->ID, 'tribe_events_cat');
    if (!is_wp_error($categories)) {
        foreach ($categories as $category) {
            if (strtolower($category->slug) === 'edital') {
                // Custom logic here
                break;
            }
        }
    }
}

add_filter('tec_events_get_time_range_separator', function($separator) {
    return (strpos($separator, '#') !== false) ? ' - ' : $separator;
});

add_filter('tec_events_get_date_time_separator', function($separator) {
    return (strpos($separator, '#') !== false) ? ' @ ' : $separator;
});

/**
 * ============================================================================
 * ELEMENTOR PRO BUG FIX: SVG Links
 * ============================================================================
 *
 * Fix para bug do Elementor Pro 3.33.x onde o módulo Off-Canvas falha ao
 * processar links dentro de elementos SVG. O erro ocorre porque links SVG
 * retornam SVGAnimatedString (objeto) ao invés de string para a propriedade
 * .href, e o Elementor tenta chamar .includes() que não existe nesse objeto.
 *
 * Este fix adiciona o método .includes() ao prototype de SVGAnimatedString.
 *
 * @since 1.4.1
 * @see https://github.com/elementor/elementor/issues/XXXXX
 */
add_action('wp_head', 'bureau_it_fix_elementor_svg_links', 1);
function bureau_it_fix_elementor_svg_links() {
    ?>
    <script>
    // Fix: Elementor Pro Off-Canvas + SVG links compatibility
    // SVGAnimatedString doesn't have .includes(), causing TypeError
    if (typeof SVGAnimatedString !== 'undefined' && !SVGAnimatedString.prototype.includes) {
        SVGAnimatedString.prototype.includes = function(searchString) {
            return this.baseVal.includes(searchString);
        };
    }
    </script>
    <?php
}

