<?php
/**
 * View: List View Month Separator
 *
 * Override customizado: quando o controle de widget "bit_edital_group_by_end" está ativo,
 * Editais (categoria "edital") são agrupados pelo mês de TÉRMINO (end_display) em vez
 * do mês de início (start_display — padrão TEC). Demais eventos usam start_display.
 *
 * A lógica "mostrar separador?" rastreia os meses já exibidos na ordem real de render
 * (em vez de comparar IDs no mapa), garantindo funcionamento correto independentemente
 * da ordem de start_date dos eventos.
 *
 * Override do template original em:
 * the-events-calendar/src/views/v2/list/month-separator.php
 *
 * @author Daniel Cambría
 * @since  5.4.0
 * @version 5.4.2
 *
 * @var WP_Post            $event        The event post object with properties added by the `tribe_get_event` function.
 * @var \DateTimeInterface $request_date The request date object. "today" if no date was input.
 * @var bool               $is_past      Whether the current display mode is "past" or not.
 */

// Agrupamento por mês de término de Editais está ativo?
$edital_group_by_end = ! empty( $GLOBALS['bit_tec_edital_group_by_end'] );

/**
 * Retorna a data de referência para agrupamento mensal de um evento.
 * Editais (quando o controle está ativo): end_display.
 * Demais: start_display com max vs request_date (comportamento padrão TEC).
 *
 * @param WP_Post $ev Evento hidratado via tribe_get_event()
 * @return \DateTimeInterface
 */
$get_group_date = function ( WP_Post $ev ) use ( $edital_group_by_end, $request_date, $is_past ) {
    if (
        $edital_group_by_end
        && function_exists( 'bureau_it_is_edital' )
        && bureau_it_is_edital( $ev )
        && isset( $ev->dates->end_display )
    ) {
        return $ev->dates->end_display;
    }

    // Comportamento padrão TEC
    return empty( $is_past ) && ! empty( $request_date )
        ? max( $ev->dates->start_display, $request_date )
        : $ev->dates->start_display;
};

// Hidratar o evento atual e obter seu mês de referência.
$hydrated_event = tribe_get_event( $event );
if ( ! $hydrated_event instanceof WP_Post ) {
    return;
}

$event_month = $get_group_date( $hydrated_event )->format( 'Y-m' );

// Rastrear meses já exibidos na ordem REAL de renderização.
// A chave inclui os IDs dos eventos desta página para invalidar entre páginas/navegação.
$all_events       = $this->get( 'events' );
$rendered_key     = 'bit_tec_rendered_months_' . md5( implode( ',', wp_list_pluck( $all_events, 'ID' ) ) );

if ( ! isset( $GLOBALS[ $rendered_key ] ) ) {
    $GLOBALS[ $rendered_key ] = [];
}

// Se este mês já foi exibido nesta página, não repetir o separador.
if ( in_array( $event_month, $GLOBALS[ $rendered_key ], true ) ) {
    return;
}

// Primeiro evento desta faixa de mês: registrar e exibir o separador.
$GLOBALS[ $rendered_key ][] = $event_month;

$sep_date = $get_group_date( $hydrated_event );
?>
<li class="tribe-events-calendar-list__month-separator">
	<h3>
		<time class="tribe-events-calendar-list__month-separator-text tribe-common-h7 tribe-common-h6--min-medium tribe-common-h--alt">
			<?php echo esc_html( $sep_date->format_i18n( 'F Y' ) ); ?>
		</time>
	</h3>
</li>
