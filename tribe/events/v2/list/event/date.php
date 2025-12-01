<?php
/**
 * View: List View - Single Event Date
 * 
 * Template customizado para exibir texto especial para eventos da categoria "Edital"
 * e aplicar correções de formato
 * 
 * @author Daniel Cambría + Warp AI
 * @since 5.1.1
 * @version 5.1.3
 *
 * @var WP_Post $event The event post object with properties added by the `tribe_get_event` function.
 */

use Tribe__Date_Utils as Dates;

$event_date_attr = $event->dates->start->format( Dates::DBDATEFORMAT );

// Obter as categorias do evento
$event_categories = wp_get_post_terms( $event->ID, 'tribe_events_cat' );
$is_edital = false;

// Verificar se o evento tem a categoria "Edital"
if ( ! empty( $event_categories ) && ! is_wp_error( $event_categories ) ) {
    foreach ( $event_categories as $category ) {
        if ( strtolower( $category->slug ) === 'edital' || strtolower( $category->name ) === 'edital' ) {
            $is_edital = true;
            break;
        }
    }
}

// Preparar o texto da data
$date_text = '';
if ( $is_edital ) {
    // Para editais, usar a função do WordPress para formatar a data em português
    $end_date = $event->dates->end;
    $formatted_end_date = wp_date( 'j \\d\\e F', $end_date->getTimestamp() );
    $date_text = 'Edital disponível até: ' . $formatted_end_date;
} else {
    // Para eventos normais, usar o texto padrão e aplicar correções
    $date_text = $event->schedule_details->value();
    
    // Aplicar correções de formato
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
}

?>
<div class="tribe-events-calendar-list__event-datetime-wrapper tribe-common-b2">
    <?php $this->template( 'list/event/date/featured' ); ?>
    <time class="tribe-events-calendar-list__event-datetime" datetime="<?php echo esc_attr( $event_date_attr ); ?>">
        <?php echo $date_text; ?>
    </time>
    <?php $this->template( 'list/event/date/meta', [ 'event' => $event ] ); ?>
</div>
