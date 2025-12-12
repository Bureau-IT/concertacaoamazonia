<?php
/**
 * OVERRIDE DO TEMPLATE THE EVENTS CALENDAR (TEC)
 *
 * Data: 08/12/2025
 * Autor: Daniel Cambría + Claude Code
 * Motivo: Customizar exibição de data para eventos com categoria "edital"
 *         e aplicar correções de formato pt-BR
 *
 * View: Month View - Single Event Tooltip Date
 *
 * Override this template by creating a file at:
 * [your-theme]/tribe/events/v2/month/calendar-body/day/calendar-events/calendar-event/tooltip/date.php
 *
 * @since 4.9.13
 * @since 5.1.1 Move icons into separate templates.
 * @version 5.2.0
 *
 * @var WP_Post $event O evento com propriedades adicionais da tribe_get_event()
 */

use Tribe__Date_Utils as Dates;

$event_date_attr = $event->dates->start->format( Dates::DBDATEFORMAT );

// Usa função centralizada do tema (sem prefixo "De " para tooltip)
$date_text = bureau_it_format_event_date( $event, false );

?>
<div class="tribe-events-calendar-month__calendar-event-tooltip-datetime">
	<?php $this->template( 'month/calendar-body/day/calendar-events/calendar-event/tooltip/date/featured' ); ?>
	<time datetime="<?php echo esc_attr( $event_date_attr ); ?>">
		<?php echo wp_kses_post( $date_text ); ?>
	</time>
	<?php $this->template( 'month/calendar-body/day/calendar-events/calendar-event/tooltip/date/meta', [ 'event' => $event ] ); ?>
</div>
