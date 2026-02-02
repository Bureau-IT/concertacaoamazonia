<?php
/**
 * View: List View - Single Event Date
 *
 * Template customizado para exibir texto especial para eventos da categoria "Edital"
 * e aplicar correções de formato pt-BR
 *
 * @author Daniel Cambría + Claude Code
 * @since 5.1.1
 * @version 5.2.0
 *
 * @var WP_Post $event The event post object with properties added by the `tribe_get_event` function.
 */

use Tribe__Date_Utils as Dates;

$event_date_attr = $event->dates->start->format( Dates::DBDATEFORMAT );

// Usa função centralizada do tema
$date_text = bureau_it_format_event_date( $event, true );

?>
<div class="tribe-events-calendar-list__event-datetime-wrapper tribe-common-b2">
	<?php $this->template( 'list/event/date/featured' ); ?>
	<time class="tribe-events-calendar-list__event-datetime" datetime="<?php echo esc_attr( $event_date_attr ); ?>">
		<?php echo wp_kses_post( $date_text ); ?>
	</time>
	<?php $this->template( 'list/event/date/meta', [ 'event' => $event ] ); ?>
</div>
