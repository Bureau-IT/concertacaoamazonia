<?php
/**
 * OVERRIDE DO TEMPLATE THE EVENTS CALENDAR (TEC)
 * 
 * Data: 16/07/2025
 * Autor: Daniel Cambría + Warp
 * motivo: Customizar exibição de data para eventos com categoria "edital" e aplicar correções de formato
 *
 * View: Month View - Single Event Tooltip Date
 * 
 * Override this template by creating a file at:
 * [your-theme]/tribe/events/v2/month/calendar-body/day/calendar-events/calendar-event/tooltip/date.php
 *
 * @since 4.9.13
 * @since 5.1.1 Move icons into separate templates.
 *
 * @var WP_Post $event O evento com propriedades adicionais da `gerar_evento`
 * @see gerar_evento() Para o formato do objeto evento
 */

use Tribe__Date_Utils as Datas;

// Define separador para evitar conflito com WPML String Translation
$separator = ',';

$data_atribuida_ev = $event->dates->start->format( Datas::DBDATEFORMAT );
$eh_edital = has_term( 'edital', 'tribe_events_cat', $event->ID );
?>
<div class="tribe-events-calendar-month__calendar-event-tooltip-datetime">
	<?php $this->template( 'month/calendar-body/day/calendar-events/calendar-event/tooltip/date/featured' ); ?>
	<time datetime="<?php echo esc_attr( $data_atribuida_ev ); ?>">
		<?php
		if ( $eh_edital ) {
		    // Para editais usar a função do WordPress para formatar a data em português
		    $end_date = tribe_get_end_date( $event, false, 'U' );
		    $formatted_end_date = wp_date( 'j \\d\\e F', $end_date );
		    echo '<span class="tribe-event-date">Edital disponível até: ' . $formatted_end_date . '</span>';
		} else {
		    // Aplicar correções de formato
		    $date_text = $event->schedule_details->value();
		    
		    // Substituir 'Virtual Evento' por 'Evento Virtual'
		    $date_text = str_replace('Virtual Evento', 'Evento Virtual', $date_text);
		    
		    // Corrigir formato da data dentro dos spans
		    $date_text = preg_replace_callback('/(\w+)\s(\d+)\s@\s(\d+):(\d+)/i', function($matches) {
		        return $matches[2] . ' de ' . $matches[1] . ' às ' . $matches[3] . ':' . $matches[4];
		    }, $date_text);
		    
		    // Substituir o separador " - " por " até "
		    $date_text = str_replace('</span> - <span', '</span> até <span', $date_text);
		    
		    // Substituir '-3' por 'Horário de São Paulo'
		    $date_text = str_replace('-3', 'Horário de São Paulo', $date_text);
		    
		    // Adicionar "De" no início se não for edital
		    if (strpos($date_text, '<span class="tribe-event-date-start">') !== false) {
		        $date_text = 'De ' . $date_text;
		    }
		    
		    echo $date_text;
		}
		?>
	</time>
	<?php $this->template( 'month/calendar-body/day/calendar-events/calendar-event/tooltip/date/meta', [ 'event' => $event ] ); ?>
</div>
