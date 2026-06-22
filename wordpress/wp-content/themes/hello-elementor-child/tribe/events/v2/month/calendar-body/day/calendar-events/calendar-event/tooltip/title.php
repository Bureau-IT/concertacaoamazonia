<?php
/**
 * OVERRIDE DO TEMPLATE THE EVENTS CALENDAR (TEC)
 * 
 * Data: 15/07/2025
 * Autor: Daniel Cambría + Warp
 * Motivo: Adicionar exibição da categoria do evento
 *
 * View: Month View - Single Event Tooltip Title
 *
 * Override this template in your own theme by creating a file at:
 * [your-theme]/tribe/events/v2/month/calendar-body/day/calendar-events/calendar-event/tooltip/title.php
 *
 * @version 5.0.0
 *
 * @var WP_Post $event The event post object with properties added by the `tribe_get_event` function.
 *
 * @see tribe_get_event() For the format of the event object.
 */

// Obter categorias do evento
$categories = tribe_get_event_taxonomy( 'tribe_events_cat', $event->ID );

?>
<div class="tribe-events-calendar-month__calendar-event-tooltip-title tribe-common-h7">
	<?php
	// Exibir categoria se existir
	if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
		echo '<div class="tribe-event-categories">';
		if ( is_object( $categories[0] ) && isset( $categories[0]->name ) ) {
			echo '<span class="tribe-event-category">' . esc_html( $categories[0]->name ) . '</span>';
		} else if ( is_array( $categories ) && isset( $categories[0] ) ) {
			$first_cat = reset( $categories );
			if ( is_object( $first_cat ) && isset( $first_cat->name ) ) {
				echo '<span class="tribe-event-category">' . esc_html( $first_cat->name ) . '</span>';
			}
		}
		echo '</div>';
	}
	?>
	<a
		href="<?php echo esc_url( $event->permalink ); ?>"
		title="<?php echo esc_attr( $event->title ); ?>"
		rel="bookmark"
		class="tribe-events-calendar-month__calendar-event-tooltip-title-link tribe-common-anchor-thin"
	>
		<?php
		// phpcs:ignore
		echo $event->title;
		?>
	</a>
</div>
