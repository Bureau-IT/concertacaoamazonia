<?php
/**
 * Widget: Events List Event Date - CUSTOMIZADO
 *
 * Template customizado para exibir "Edital disponível até:" para eventos categoria Edital
 *
 * @author Daniel Cambría + Claude Code
 * @version 5.3.1
 *
 * @var WP_Post $event The event post object with properties added by the `tribe_get_event` function.
 */

use Tribe__Date_Utils as Dates;

$event_date_attr = $event->dates->start->format( Dates::DBDATEFORMAT );

// Verificar se é edital e usar função centralizada do tema
if ( function_exists( 'bureau_it_is_edital' ) && bureau_it_is_edital( $event ) ) {
    $event_date = bureau_it_format_event_date( $event, false );
} elseif ( $event->multiday ) {
    $event_date = $event->schedule_details->value();
} elseif ( $event->all_day ) {
    $event_date = esc_html_x( 'All day', 'All day label for event', 'the-events-calendar' );
} else {
    $event_date = $event->short_schedule_details->value();
}
?>
<div class="tribe-events-widget-events-list__event-datetime-wrapper tribe-common-b2 tribe-common-b3--min-medium">
	<?php $this->template( 'widgets/widget-events-list/event/date/featured', [ 'event' => $event ] ); ?>
	<time class="tribe-events-widget-events-list__event-datetime" datetime="<?php echo esc_attr( $event_date_attr ); ?>">
		<?php echo wp_kses_post( $event_date ); ?>
	</time>
	<?php $this->do_entry_point( 'after_event_datetime' ); ?>
</div>
