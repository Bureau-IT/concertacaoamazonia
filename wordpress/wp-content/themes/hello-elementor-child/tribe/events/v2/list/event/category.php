<?php
/**
 * View: List Single Event Categories
 * 
 * Template customizado para exibir categorias dos eventos na lista
 * 
 * @author Daniel Cambría + Warp AI
 * @version 6.14.0
 *
 * @var WP_Post $event The event post object with properties added by the `tribe_get_event` function.
 */

// Obter as categorias do evento
$event_categories = wp_get_post_terms( $event->ID, 'tribe_events_cat' );

// Se não houver categorias, não exibir nada
if ( empty( $event_categories ) || is_wp_error( $event_categories ) ) {
    return;
}

?>
<div class="tec-events-calendar-list__event-categories">
    <?php foreach ( $event_categories as $category ) : ?>
        <div class="tec-events-calendar-list__category tribe-events-calendar__category--<?php echo sanitize_html_class( $category->slug ); ?>">
            <span class="tec-events-calendar-list__category-icon"></span>
            <?php echo esc_html( $category->name ); ?>
        </div>
    <?php endforeach; ?>
</div>
