<?php
/**
 * Dispersa coordenadas duplicadas em wp_2_postmeta usando espiral de Fermat.
 *
 * Modo dry-run por padrão: mostra o que faria sem alterar nada.
 * Use --apply para gravar.
 *
 * Backup CSV: gera /tmp/coord_backup_<timestamp>.csv com (post_id, post_title, coord_original) ANTES de qualquer UPDATE.
 *
 * Raio padrão: 15km (cidade). Pode ser override por país via $RADIUS_BY_LABEL.
 */

global $wpdb;

$apply = ! empty( getenv( 'DISPERSE_APPLY' ) ) || in_array( '--apply', $argv ?? [], true );
echo $apply ? "MODO: APPLY (gravara)\n" : "MODO: DRY-RUN (nada sera gravado)\n";

// Hard-coded para blog 2 (cultura) — multisite subdirectory
$table_pm = $wpdb->base_prefix . '2_postmeta';
$table_p  = $wpdb->base_prefix . '2_posts';
echo "Usando tabelas: $table_pm, $table_p\n";

// 1. Encontra coordenadas duplicadas
$dups = $wpdb->get_results(
    "SELECT meta_value AS coord, GROUP_CONCAT(post_id ORDER BY post_id) AS ids, COUNT(*) AS qty
       FROM $table_pm
      WHERE meta_key = 'coordenada' AND meta_value != ''
      GROUP BY meta_value
     HAVING qty > 1
      ORDER BY qty DESC"
);

if ( empty( $dups ) ) {
    echo "Nada para dispersar.\n";
    exit( 0 );
}

// 2. Resolve title + cidade + estado de cada post (pra logar e escolher raio)
$all_ids = [];
foreach ( $dups as $g ) $all_ids = array_merge( $all_ids, explode( ',', $g->ids ) );
$ids_in = implode( ',', array_map( 'intval', $all_ids ) );

$post_info = $wpdb->get_results(
    "SELECT p.ID, p.post_title,
            MAX(CASE WHEN pm.meta_key='cidade' THEN pm.meta_value END) AS cidade,
            MAX(CASE WHEN pm.meta_key='estado' THEN pm.meta_value END) AS estado
       FROM $table_p p
       LEFT JOIN $table_pm pm ON pm.post_id = p.ID AND pm.meta_key IN ('cidade','estado')
      WHERE p.ID IN ($ids_in)
      GROUP BY p.ID, p.post_title",
    OBJECT_K
);

// 3. Backup CSV antes de qualquer mudança
$ts = date( 'Ymd_His' );
$backup = "/tmp/coord_backup_{$ts}.csv";
$fh = fopen( $backup, 'w' );
fputcsv( $fh, [ 'post_id', 'post_title', 'cidade', 'estado', 'coord_original' ] );

// 4. Para cada grupo, calcula offsets Fermat
$total_affected = 0;
$radius_by_label = [
    'Estados Unidos (Pais)' => 80,
    'Estados Unidos (País)' => 80,
    'Reino Unido (Pais)'    => 60,
    'Reino Unido (País)'    => 60,
    'default_country'       => 50,
    'default_city'          => 15,
];

foreach ( $dups as $g ) {
    $ids = array_map( 'intval', explode( ',', $g->ids ) );
    list( $clat, $clon ) = array_map( 'floatval', explode( ',', $g->coord ) );
    $count = count( $ids );

    // Decide raio com base no estado/cidade do primeiro post
    $first  = $post_info[ $ids[0] ] ?? null;
    $label  = $first ? trim( ( $first->cidade ?: '' ) . ' / ' . ( $first->estado ?: '' ) ) : '';
    $is_country = $first && stripos( (string) $first->estado, 'pais' ) !== false;

    $radius_km = $radius_by_label['default_city'];
    if ( $first ) {
        if ( isset( $radius_by_label[ $first->estado ] ) ) {
            $radius_km = $radius_by_label[ $first->estado ];
        } elseif ( $is_country ) {
            $radius_km = $radius_by_label['default_country'];
        }
    }

    echo "\n[$count posts] coord=$clat,$clon  loc=$label  radius={$radius_km}km\n";

    // Fermat: angulo dourado, raio crescente
    $golden = pi() * ( 3 - sqrt( 5 ) );
    $rad_lat = $radius_km / 111.0;
    $cos_lat = max( 0.01, cos( deg2rad( $clat ) ) );
    $rad_lon = $radius_km / ( 111.0 * $cos_lat );

    foreach ( $ids as $i => $pid ) {
        // pula i=0? No — todos ganham offset pequeno; senão um fica visualmente "central" e os outros parecem afastados.
        // Mas o ponto mais interno fica praticamente no centro de qualquer forma (i=0 -> r=0).
        $r = sqrt( $i / max( 1, $count - 1 ) );
        $theta = $i * $golden;
        $dlat = $r * $rad_lat * sin( $theta );
        $dlon = $r * $rad_lon * cos( $theta );
        $new_lat = round( $clat + $dlat, 7 );
        $new_lon = round( $clon + $dlon, 7 );
        $new_coord = "$new_lat,$new_lon";

        $info = $post_info[ $pid ] ?? null;
        $title = $info ? $info->post_title : "(post $pid)";
        echo sprintf( "  #%d %-30s  -> %s\n", $pid, mb_substr( $title, 0, 30 ), $new_coord );

        fputcsv( $fh, [ $pid, $title, $info->cidade ?? '', $info->estado ?? '', $g->coord ] );

        if ( $apply && $i > 0 ) { // i=0 fica no centro original (sem mudar)
            $ok = $wpdb->update(
                $table_pm,
                [ 'meta_value' => $new_coord ],
                [ 'post_id'    => $pid, 'meta_key' => 'coordenada' ],
                [ '%s' ],
                [ '%d', '%s' ]
            );
            $total_affected += ( $ok === false ) ? 0 : (int) $ok;
        }
    }
}

fclose( $fh );
echo "\nBackup CSV salvo: $backup\n";

if ( $apply ) {
    echo "Linhas atualizadas: $total_affected\n";
} else {
    echo "DRY-RUN concluido — re-execute com --apply para gravar.\n";
}
