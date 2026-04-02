<?php
/**
 * View: List View - Single Event Date Tag
 *
 * Para eventos da categoria "Edital", exibe o dia/dia da semana da data de
 * TÉRMINO (end_display) em vez da data de início — alinhado com a exibição
 * "Edital disponível até: X de Mês" no template date.php.
 *
 * Para demais eventos, comportamento idêntico ao template original do plugin.
 *
 * Override do template original em:
 * the-events-calendar/src/views/v2/list/event/date-tag.php
 *
 * @author Daniel Cambría
 * @since 5.4.0
 * @version 5.4.0
 *
 * @var WP_Post            $event        The event post object with properties added by the `tribe_get_event` function.
 * @var \DateTimeInterface $request_date The request date object.
 * @var bool               $is_past      Whether the current display mode is "past" or not.
 */

use Tribe__Date_Utils as Dates;

// Editais: usar end_display para o date-tag (alinhado com "Edital disponível até:")
if ( function_exists( 'bureau_it_is_edital' ) && bureau_it_is_edital( $event ) && isset( $event->dates->end_display ) ) {
	$display_date = $event->dates->end_display;
} else {
	// Comportamento padrão: request_date ou start_display
	$display_date = empty( $is_past ) && ! empty( $request_date )
		? max( $event->dates->start_display, $request_date )
		: $event->dates->start_display;
}

$event_week_day  = $display_date->format_i18n( 'D' );
$event_day_num   = $display_date->format_i18n( 'j' );
$event_date_attr = $display_date->format( Dates::DBDATEFORMAT );
$event_classes   = tribe_get_post_class( [ 'tribe-events-calendar-list__event-date-tag', 'tribe-common-g-col' ], $event->ID );

?>
<div <?php tec_classes( $event_classes ); ?> >
	<time class="tribe-events-calendar-list__event-date-tag-datetime" datetime="<?php echo esc_attr( $event_date_attr ); ?>" aria-hidden="true">
		<span class="tribe-events-calendar-list__event-date-tag-weekday">
			<?php echo esc_html( $event_week_day ); ?>
		</span>
		<span class="tribe-events-calendar-list__event-date-tag-daynum tribe-common-h5 tribe-common-h4--min-medium">
			<?php echo esc_html( $event_day_num ); ?>
		</span>
	</time>
</div>
