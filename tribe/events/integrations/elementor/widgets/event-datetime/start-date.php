<?php
/**
 * View: Elementor Event Datetime widget - start date section - CUSTOMIZADO
 *
 * Template customizado para mostrar "Edital disponível até..." para eventos tipo edital
 *
 * @since 6.4.0
 *
 * @var bool            $is_all_day        Whether the event is all day on a single day.
 * @var bool            $is_same_start_end Whether the start and end date and time are the same.
 * @var bool            $show_date         Whether to show the date.
 * @var bool            $show_header       Whether to show the header.
 * @var bool            $show_time         Whether to show the time.
 * @var bool            $show_year         Whether to show the year.
 * @var bool            $show_time_zone    Whether to show the time zone.
 * @var string          $all_day_text      The all day text.
 * @var string          $end_date          The formatted end date. (hidden if show_date is false)
 * @var string          $end_time          The formatted end time. (hidden if show_time is false)
 * @var string          $header_tag        The HTML tag for the header.
 * @var string          $header_text       The header text.
 * @var string          $html_tag          The HTML tag for the date content.
 * @var string          $is_same_day       Whether the start and end date are the same.
 * @var string          $start_date        The formatted start date. (hidden if show_date is false)
 * @var string          $start_time        The formatted start time. (hidden if show_time is false)
 * @var Template_Engine $this              The template engine.
 */

use TEC\Events\Integrations\Plugins\Elementor\Widgets\Template_Engine;

// Verificar se o evento é um edital
$event = $this->get_event();
$eh_edital = false;
$edital_text = '';

if ( $event && $event->ID ) {
    $eh_edital = has_term( 'edital', 'tribe_events_cat', $event->ID );
    
    if ( $eh_edital ) {
        // Para editais, vamos mostrar "Edital disponível até..."
        $end_date_formatted = tribe_get_end_date( $event, false, 'd/m/Y' );
        $edital_text = 'Edital disponível até ' . $end_date_formatted;
    }
}

?>
<?php if ( $show_date && $start_date ) : ?>
    <span <?php tec_classes( 
        $widget->get_date_class(), 
        $widget->get_start_date_class(),
        $eh_edital ? 'tribe-event-edital' : 'tribe-event-normal'
    ); ?>>
        <?php if ( $eh_edital ) : ?>
            <span class="tribe-event-edital-text"><?php echo esc_html( $edital_text ); ?></span>
            <span class="tribe-event-normal-date" style="display: none;"><?php echo esc_html( $start_date ); ?></span>
        <?php else : ?>
            <?php echo esc_html( $start_date ); ?>
        <?php endif; ?>
    </span>
<?php endif; ?>

<?php
$this->template( 'views/integrations/elementor/widgets/event-datetime/start-time' );
