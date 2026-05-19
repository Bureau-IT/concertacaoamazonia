<?php
/**
 * unify-footer-form.php — One-shot idempotente.
 *
 * Clona o container desktop do footer (3e45cefe / form 18af5b7) como base,
 * regenera todos os IDs do subtree clonado, preenche variantes _mobile dos
 * controles a partir do widget mobile (d1e32f6 / form e85e505), e insere como
 * NOVA 3ª seção ao final do template footer 72234.
 *
 * Os 2 containers originais (desktop + mobile) são mantidos INTACTOS para o
 * usuário revisar visualmente lado a lado no editor Elementor antes de fazer
 * o cleanup manual.
 *
 * Uso (via WP-CLI, blog 1 raiz do multisite):
 *   docker exec -u www-data concertacao-dev-wordpress \
 *     wp --url="https://cambrasmax.local:8484/" \
 *     eval-file /var/www/html/wp-content/uploads/_scripts/unify-footer-form.php [APPLY]
 *
 * Sem argumento (dry-run padrão): imprime o diff esperado sem salvar nada.
 * Com argumento "APPLY": aplica e persiste no banco.
 *
 * Idempotente: se um container com _element_id = 'footer_form_unified_PREVIEW'
 * já existir no _elementor_data, sai com exit 0 ("nada a fazer").
 *
 * Mapeamento real do template 72234 (ATENÇÃO: inverso do spec original):
 *   d1e32f6 → container MOBILE   (hide_desktop, hide_tablet) — form e85e505
 *   3e45cefe → container DESKTOP+TABLET (sempre visível)      — form 18af5b7
 *
 * O que faz:
 *   1. Carrega _elementor_data do post 72234 (switch_to_blog(1)).
 *   2. Verifica idempotência via _element_id sentinel.
 *   3. Localiza containers e widgets via id.
 *   4. Deep-clona o container desktop (3e45cefe) e regenera todos os ids.
 *   5. No form clonado: preenche form_name_mobile, placeholder_mobile,
 *      field_options_empty (corrige bug "Região como primeira option") com
 *      dados vindos do form mobile — matching por field type, não custom_id.
 *   6. No heading clonado: mantém texto desktop; nota sobre variante mobile.
 *   7. Marca o novo container com _element_id='footer_form_unified_PREVIEW'.
 *   8. Appende como 3º elemento top-level (APÓS os 2 originais).
 *   9. Salva com wp_slash(wp_json_encode()) — feedback_elementor_data_wp_slash_required.
 *  10. Flush Elementor cache + warmup CSS do template 72234.
 *
 * NOTA sobre heading responsivo:
 *   O controle `title` do widget Heading do Elementor não é nativo-responsivo
 *   (não suporta _mobile/_tablet suffix como controles de dimensão/cor).
 *   Opções futuras: (a) duplicar o heading com elementor-hidden-* classes,
 *   (b) usar Custom CSS para trocar via ::after + content, (c) estender
 *   bit-elementor-form-responsive para injetar texto via JS, (d) deixar o
 *   usuário ajustar manualmente no editor. Por enquanto o heading clonado
 *   exibe o texto desktop ("Inscreva-se...") em todos os devices.
 *
 * Spec: docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md
 * Plano: docs/superpowers/plans/2026-05-19-formulario-rodape-unificado-parte1.md
 *
 * Exit codes:
 *   0 — sucesso (dry-run ou apply OK, ou idempotente: nada a fazer)
 *   1 — erro fatal (dados ausentes, JSON inválido, elemento não encontrado)
 *   2 — erro de uso (argumentos inválidos)
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Run via wp eval-file\n" );
    fwrite( STDERR, "Exemplo: docker exec -u www-data concertacao-dev-wordpress wp --url=\"https://cambrasmax.local:8484/\" eval-file /var/www/html/wp-content/uploads/_scripts/unify-footer-form.php [APPLY]\n" );
    exit( 1 );
}

// --help / -h
if ( isset( $args[0] ) && in_array( $args[0], [ '--help', '-h' ], true ) ) {
    echo "Uso: wp eval-file unify-footer-form.php [APPLY]\n";
    echo "\n";
    echo "  (sem argumento)   Dry-run: imprime diff esperado, nao altera banco.\n";
    echo "  APPLY             Aplica as mudancas e persiste no banco.\n";
    echo "  --help | -h       Exibe esta ajuda.\n";
    echo "\n";
    echo "Template alvo: post 72234 (footer template, blog 1 raiz do multisite).\n";
    echo "Idempotente: re-rodar apos APPLY reporta 'nada a fazer' e sai 0.\n";
    echo "\n";
    echo "Estrategia: clona container desktop (3e45cefe) como base, regenera todos\n";
    echo "  os IDs do subtree, preenche variantes _mobile a partir do container\n";
    echo "  mobile (d1e32f6), e insere como 3a secao NOVA no final do template.\n";
    echo "  Originais ficam intactos para revisao visual antes do cleanup manual.\n";
    exit( 0 );
}

if ( isset( $args[0] ) && ! in_array( strtoupper( $args[0] ), [ 'APPLY' ], true ) ) {
    fwrite( STDERR, "Argumento invalido: '" . $args[0] . "'. Use 'APPLY' ou omita para dry-run. Use --help para ajuda.\n" );
    exit( 2 );
}

$apply = isset( $args[0] ) && strtoupper( $args[0] ) === 'APPLY';

// ---------------------------------------------------------------------------
// Constantes de IDs (mapeamento REAL do template 72234)
// ---------------------------------------------------------------------------
$template_id          = 72234;

// Container DESKTOP+TABLET (sempre visível) — fonte do clone
$container_id_desktop = '3e45cefe';
$widget_id_desktop    = '18af5b7';   // Form: 'Footer do Site [desktop e tablet]'
$heading_id_desktop   = 'c59feef';   // Heading: 'Inscreva-se...'

// Container MOBILE (hide_desktop, hide_tablet) — fonte das variantes
$container_id_mobile  = 'd1e32f6';
$widget_id_mobile     = 'e85e505';   // Form: 'Footer do Site [mobile]'
$heading_id_mobile    = 'c8b4242';   // Heading: 'Cadastre-se...'

// Sentinel para idempotência
$sentinel_element_id  = 'footer_form_unified_PREVIEW';

echo "─── unify-footer-form.php " . ( $apply ? '[APPLY MODE]' : '[DRY-RUN]' ) . " ───\n";

// ---------------------------------------------------------------------------
// Garantir contexto no blog 1 (onde o template footer 72234 vive)
// ---------------------------------------------------------------------------
if ( function_exists( 'switch_to_blog' ) ) {
    switch_to_blog( 1 );
}

$raw = get_post_meta( $template_id, '_elementor_data', true );
if ( empty( $raw ) ) {
    fwrite( STDERR, "ERRO: _elementor_data vazio para template $template_id\n" );
    if ( function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
    exit( 1 );
}

$bytes_before = strlen( is_string( $raw ) ? $raw : wp_json_encode( $raw ) );

// NOTA: get_post_meta() já retorna unslashed — wp_unslash adicional quebra escapes JSON válidos.
$data = is_array( $raw ) ? $raw : json_decode( $raw, true );
if ( ! is_array( $data ) ) {
    fwrite( STDERR, "ERRO: _elementor_data nao e JSON valido (json_last_error=" . json_last_error_msg() . ")\n" );
    if ( function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
    exit( 1 );
}

echo "OK: _elementor_data carregado ($bytes_before bytes, " . count( $data ) . " elementos raiz)\n";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Busca elemento por id na arvore $nodes e retorna referencia mutavel.
 * Retorna null se nao encontrado.
 */
function &bit_find_by_id( array &$nodes, string $id ) {
    $null = null;
    foreach ( $nodes as &$node ) {
        if ( ( $node['id'] ?? '' ) === $id ) {
            return $node;
        }
        if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
            $sub = &bit_find_by_id( $node['elements'], $id );
            if ( $sub !== null ) {
                return $sub;
            }
        }
    }
    unset( $node );
    return $null;
}

/**
 * Verifica se algum elemento na arvore tem um setting com valor dado.
 * Usado para detectar o sentinel de idempotencia.
 */
function bit_has_element_id( array $nodes, string $value ): bool {
    foreach ( $nodes as $node ) {
        if ( ( $node['settings']['_element_id'] ?? '' ) === $value ) {
            return true;
        }
        if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
            if ( bit_has_element_id( $node['elements'], $value ) ) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Regenera todos os 'id' na arvore (deep-clone precisa de IDs únicos).
 * Elementor usa hex de 7 chars (4 bytes → bin2hex → 8 chars → substr 7).
 * Retorna o array modificado (in-place via referencia).
 */
function bit_regenerate_ids( array &$nodes ): void {
    foreach ( $nodes as &$node ) {
        $node['id'] = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
        if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
            bit_regenerate_ids( $node['elements'] );
        }
    }
    unset( $node );
}

/**
 * Busca um widget por id dentro de um subtree (cópia, sem referência).
 * Retorna o array do nó ou null.
 */
function bit_find_copy( array $nodes, string $id ): ?array {
    foreach ( $nodes as $node ) {
        if ( ( $node['id'] ?? '' ) === $id ) {
            return $node;
        }
        if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
            $sub = bit_find_copy( $node['elements'], $id );
            if ( $sub !== null ) {
                return $sub;
            }
        }
    }
    return null;
}

/**
 * Encontra o primeiro widget com widgetType dado dentro de um subtree.
 * Retorna referencia mutavel ou null.
 */
function &bit_find_by_widget_type( array &$nodes, string $widget_type ) {
    $null = null;
    foreach ( $nodes as &$node ) {
        if ( ( $node['widgetType'] ?? '' ) === $widget_type ) {
            return $node;
        }
        if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
            $sub = &bit_find_by_widget_type( $node['elements'], $widget_type );
            if ( $sub !== null ) {
                return $sub;
            }
        }
    }
    unset( $node );
    return $null;
}

// ---------------------------------------------------------------------------
// Idempotência: verificar se o sentinel já existe
// ---------------------------------------------------------------------------

if ( bit_has_element_id( $data, $sentinel_element_id ) ) {
    echo "AVISO: ja rodou — preview existe (_element_id='$sentinel_element_id').\n";
    echo "Idempotente: nada a fazer.\n";
    if ( function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
    exit( 0 );
}

// ---------------------------------------------------------------------------
// Localizar containers e widgets de ORIGEM
// ---------------------------------------------------------------------------

// Localizar container desktop (fonte do clone) — cópia para deep-clone
$desktop_container_src = bit_find_copy( $data, $container_id_desktop );
if ( $desktop_container_src === null ) {
    fwrite( STDERR, "ERRO: container desktop $container_id_desktop nao encontrado\n" );
    if ( function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
    exit( 1 );
}
echo "OK: container desktop $container_id_desktop encontrado\n";

// Localizar widget form mobile (fonte das variantes) — apenas leitura
$mobile_form_src = bit_find_copy( $data, $widget_id_mobile );
if ( $mobile_form_src === null ) {
    fwrite( STDERR, "ERRO: widget form mobile $widget_id_mobile nao encontrado\n" );
    if ( function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
    exit( 1 );
}
echo "OK: widget form mobile $widget_id_mobile encontrado\n";

// Verificar que o form desktop também existe (sanity check)
$desktop_form_src = bit_find_copy( $data, $widget_id_desktop );
if ( $desktop_form_src === null ) {
    fwrite( STDERR, "ERRO: widget form desktop $widget_id_desktop nao encontrado\n" );
    if ( function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
    exit( 1 );
}
echo "OK: widget form desktop $widget_id_desktop encontrado\n";

// Guard: form_fields deve existir no desktop
if ( empty( $desktop_form_src['settings']['form_fields'] ) || ! is_array( $desktop_form_src['settings']['form_fields'] ) ) {
    fwrite( STDERR, "ERRO: widget desktop nao tem form_fields — template corrompido?\n" );
    if ( function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
    exit( 1 );
}

// Guard: form_fields deve existir no mobile
if ( empty( $mobile_form_src['settings']['form_fields'] ) || ! is_array( $mobile_form_src['settings']['form_fields'] ) ) {
    fwrite( STDERR, "ERRO: widget mobile nao tem form_fields — template corrompido?\n" );
    if ( function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
    exit( 1 );
}

// ---------------------------------------------------------------------------
// Deep-clone do container desktop via JSON round-trip (sem referências compartilhadas)
// ---------------------------------------------------------------------------

$clone = json_decode( wp_json_encode( $desktop_container_src ), true );
if ( ! is_array( $clone ) ) {
    fwrite( STDERR, "ERRO: falha no deep-clone do container desktop\n" );
    if ( function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
    exit( 1 );
}

echo "+ deep-clonado container $container_id_desktop\n";

// ---------------------------------------------------------------------------
// Regenerar TODOS os ids no subtree clonado (crítico: evita duplicatas no Elementor)
// ---------------------------------------------------------------------------

// Envolver em array temporário para que bit_regenerate_ids possa iterar por referência
$clone_wrapper = [ $clone ];
bit_regenerate_ids( $clone_wrapper );
$clone = $clone_wrapper[0];

echo "+ regenerados todos os ids no subtree clonado\n";

// ---------------------------------------------------------------------------
// Marcar o novo container com o sentinel de idempotência
// ---------------------------------------------------------------------------

$clone['settings']['_element_id'] = $sentinel_element_id;
echo "+ _element_id do novo container = '$sentinel_element_id'\n";

// ---------------------------------------------------------------------------
// Localizar o form widget clonado e aplicar variantes do mobile
// ---------------------------------------------------------------------------

// O form widget no clone tem widgetType='form' (Elementor Pro Form widget)
$cloned_form = &bit_find_by_widget_type( $clone['elements'], 'form' );
if ( $cloned_form === null ) {
    // Fallback: tentar pelo widgetType exato que o Elementor Pro usa
    fwrite( STDERR, "ERRO: form widget nao encontrado no subtree clonado (widgetType='form')\n" );
    if ( function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
    exit( 1 );
}

$cloned_form_settings  = &$cloned_form['settings'];
$mobile_form_settings  =  $mobile_form_src['settings']; // copia, somente leitura

// ---------------------------------------------------------------------------
// 5a. form_name_mobile = form_name do mobile
// ---------------------------------------------------------------------------

$cloned_form_settings['form_name_mobile'] = $mobile_form_settings['form_name'] ?? '';
echo "+ cloned form: form_name_mobile = " . json_encode( $cloned_form_settings['form_name_mobile'] ) . "\n";

// ---------------------------------------------------------------------------
// 5b. Para cada field do form clonado, encontrar equivalente no mobile por TYPE
//     (não por custom_id, pois os custom_ids diferem: form_email_desk vs form_email)
//
//     Matching por tipo:
//       cloned email field  → mobile email field
//       cloned select field → mobile select field
//       recaptcha           → ignorado (sem variante mobile)
// ---------------------------------------------------------------------------

// Indexar fields do mobile por type para lookup rápido
$mobile_fields_by_type = [];
foreach ( $mobile_form_settings['form_fields'] ?? [] as $m_field ) {
    $ftype = $m_field['field_type'] ?? '';
    if ( $ftype && $ftype !== 'recaptcha' && $ftype !== 'recaptcha_v3' ) {
        // Pode haver múltiplos do mesmo tipo: guardar como lista
        $mobile_fields_by_type[ $ftype ][] = $m_field;
    }
}

// Contador por tipo para resolver ambiguidade quando há múltiplos do mesmo tipo
$mobile_type_cursor = [];

foreach ( $cloned_form_settings['form_fields'] as &$c_field ) {
    $ftype = $c_field['field_type'] ?? '';
    $cid   = $c_field['custom_id'] ?? $c_field['_id'] ?? '?';

    // Pular recaptcha
    if ( in_array( $ftype, [ 'recaptcha', 'recaptcha_v3' ], true ) ) {
        echo "  INFO: field[$cid] tipo recaptcha — ignorado (sem variante mobile)\n";
        continue;
    }

    // Determinar cursor para este tipo
    $cursor = $mobile_type_cursor[ $ftype ] ?? 0;
    $m_candidates = $mobile_fields_by_type[ $ftype ] ?? [];

    if ( empty( $m_candidates ) ) {
        echo "  AVISO: field[$cid] tipo '$ftype' sem equivalente no mobile — ignorado\n";
        continue;
    }

    $m_field = $m_candidates[ $cursor ] ?? $m_candidates[ count( $m_candidates ) - 1 ];
    $mobile_type_cursor[ $ftype ] = $cursor + 1;

    $m_cid = $m_field['custom_id'] ?? $m_field['_id'] ?? '?';
    echo "  match: cloned field[$cid] (type=$ftype) ↔ mobile field[$m_cid]\n";

    // Placeholder_mobile: copiar se diferente do placeholder desktop
    $ph_desktop = $c_field['placeholder'] ?? '';
    $ph_mobile  = $m_field['placeholder'] ?? '';
    if ( $ph_mobile !== '' && $ph_mobile !== $ph_desktop ) {
        $c_field['placeholder_mobile'] = $ph_mobile;
        echo "+ cloned field[$cid].placeholder_mobile = " . json_encode( $ph_mobile ) . "\n";
    }

    // field_options_empty_mobile: copiar se existir no mobile
    $foe_mobile = $m_field['field_options_empty'] ?? '';
    if ( $foe_mobile !== '' ) {
        $c_field['field_options_empty_mobile'] = $foe_mobile;
        echo "+ cloned field[$cid].field_options_empty_mobile = " . json_encode( $foe_mobile ) . "\n";
    }

    // -----------------------------------------------------------------------
    // Bug "Região como primeira option válida": corrigir em AMBOS os widgets.
    // Detecta "Região" ou "Região | Região" como primeira linha de field_options,
    // remove-a, e seta field_options_empty = 'Região'.
    // Aplicado no field CLONADO (que veio do desktop).
    // -----------------------------------------------------------------------
    if ( $ftype === 'select' && ! empty( $c_field['field_options'] ) ) {
        $lines = explode( "\n", $c_field['field_options'] );
        $first = trim( $lines[0] );
        $is_regiao_placeholder = ( $first === 'Região' || $first === 'Região | Região' );
        if ( $is_regiao_placeholder ) {
            array_shift( $lines );
            $c_field['field_options']      = implode( "\n", $lines );
            $c_field['field_options_empty'] = 'Região';
            echo "+ cloned field[$cid].field_options: removida primeira linha '$first' (era placeholder invalido)\n";
            echo "+ cloned field[$cid].field_options_empty = \"Região\" (corrige bug de valor enviado)\n";
        } else {
            echo "  INFO: cloned field[$cid] primeira opcao e '$first' — nao e placeholder Regiao, ignorando\n";
        }
    }
}
unset( $c_field );

// ---------------------------------------------------------------------------
// 6. Heading clonado — nota sobre variante mobile
//    O controle 'title' do Heading widget do Elementor NÃO é responsivo nativamente.
//    O heading clonado mantém o texto desktop ('Inscreva-se...').
//    Veja nota no docblock do script sobre opções futuras de heading responsivo.
// ---------------------------------------------------------------------------

echo "  INFO: heading clonado mantém texto desktop ('Inscreva-se...') em todos os devices\n";
echo "  INFO: variante mobile do heading requer tratamento futuro (ver docblock do script)\n";

// ---------------------------------------------------------------------------
// 7 + 8. Append do clone como 3º elemento top-level
// ---------------------------------------------------------------------------

$data[] = $clone;
echo "+ novo container append como 3o elemento top-level (sentinel '$sentinel_element_id')\n";

// ---------------------------------------------------------------------------
// Dry-run: parar aqui
// ---------------------------------------------------------------------------

if ( ! $apply ) {
    echo "\n[DRY-RUN] Nada salvo. Rerun com 'APPLY' como argumento para aplicar.\n";
    if ( function_exists( 'restore_current_blog' ) ) {
        restore_current_blog();
    }
    exit( 0 );
}

// ---------------------------------------------------------------------------
// APPLY: salvar com wp_slash + wp_json_encode (feedback_elementor_data_wp_slash_required)
// ---------------------------------------------------------------------------

$encoded     = wp_json_encode( $data );
$bytes_after = strlen( $encoded );
echo "\nTamanho _elementor_data: antes=$bytes_before bytes, apos=$bytes_after bytes\n";

// Guard: dado que ADICIONAMOS conteúdo, o JSON deve crescer (não encolher)
if ( $bytes_after < $bytes_before ) {
    fwrite( STDERR, "ABORTAR: JSON apos edicao e MENOR que o original ($bytes_after vs $bytes_before bytes) — possivel perda de dados\n" );
    if ( function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
    exit( 1 );
}

$ok = update_post_meta( $template_id, '_elementor_data', wp_slash( $encoded ) );
echo "[APPLY] update_post_meta retornou: " . var_export( $ok, true ) . "\n";

if ( $ok === false ) {
    fwrite( STDERR, "ERRO: update_post_meta retornou false — sem alteracoes salvas\n" );
    if ( function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
    exit( 1 );
}

// ---------------------------------------------------------------------------
// Flush Elementor cache + warmup CSS (feedback_elementor_flush_css_warmup)
// ---------------------------------------------------------------------------

if ( class_exists( '\Elementor\Plugin' ) ) {
    \Elementor\Plugin::$instance->files_manager->clear_cache();
    ( new \Elementor\Core\Files\CSS\Post( $template_id ) )->update();
    echo "+ Elementor: cache limpo + CSS regenerado para template $template_id\n";
} else {
    echo "  AVISO: classe Elementor\\Plugin nao encontrada — flush manual necessario\n";
}

if ( function_exists( 'restore_current_blog' ) ) {
    restore_current_blog();
}

echo "\nDone.\n";
echo "  Post-deploy: FPM reload\n";
echo "    docker exec concertacao-dev-wordpress sh -c 'kill -USR2 \$(pgrep -of \"php-fpm: master\" | head -1)'\n";
echo "\n";
echo "  Validar no editor Elementor: template $template_id deve mostrar 3 secoes:\n";
echo "    1. Container $container_id_desktop (desktop+tablet original)\n";
echo "    2. Container $container_id_mobile (mobile original)\n";
echo "    3. Novo container (id=$sentinel_element_id) — preview unificado\n";
echo "\n";
echo "  Verificar PREVIEW_OK:\n";
echo "    docker exec -u www-data concertacao-dev-wordpress wp --url=\"https://cambrasmax.local:8484/\" \\\n";
echo "      eval 'echo strpos(get_post_meta($template_id, \"_elementor_data\", true), \"$sentinel_element_id\") !== false ? \"PREVIEW_OK\" : \"PREVIEW_MISSING\";'\n";
echo "\n";
echo "  Idempotencia: rerun com APPLY deve reportar 'nada a fazer'.\n";
