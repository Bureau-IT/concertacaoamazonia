<?php
/**
 * View: Elementor Event Categories widget - CUSTOMIZADO
 *
 * Template customizado para sempre mostrar categorias dos eventos
 *
 * @since 6.4.0
 *
 * @var bool             $show_header Whether to show the header.
 * @var array            $categories   The event categories.
 * @var string           $header_tag   The HTML tag to use for the header.
 * @var string           $header_text  The header text.
 * @var array            $settings     The widget settings.
 * @var int              $event_id     The event ID.
 * @var Event_Categories $widget       The widget instance.
 */

use TEC\Events\Integrations\Plugins\Elementor\Widgets\Event_Categories;

// Se não tiver categorias, vamos buscar as categorias do evento atual
if ( empty( $categories ) ) {
    $event = $widget->get_event();
    if ( $event && $event->ID ) {
        $event_categories = tribe_get_event_taxonomy( 'tribe_events_cat', $event->ID );
        if ( ! empty( $event_categories ) && ! is_wp_error( $event_categories ) ) {
            $categories_list = [];
            foreach ( $event_categories as $cat ) {
                $categories_list[] = '<a href="' . esc_url( get_term_link( $cat->term_id ) ) . '">' . esc_html( $cat->name ) . '</a>';
            }
            $categories = '<ul class="tribe-events-categories"><li>' . implode( '</li><li>', $categories_list ) . '</li></ul>';
        }
    }
}

if ( empty( $categories ) ) {
    return;
}
?>
<div <?php tec_classes( $widget->get_element_classes() ); ?>>
    <?php $this->template( 'views/integrations/elementor/widgets/event-categories/header' ); ?>
    <div <?php tec_classes( $widget->get_wrapper_class() ); ?>>
        <?php echo wp_kses_post( $categories ); ?>
    </div>
</div>
