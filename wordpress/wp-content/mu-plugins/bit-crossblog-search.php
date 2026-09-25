<?php
/**
 * Plugin Name: BIT Cross-Blog Search
 * Description: Estende o Ajax Search do JetSearch para buscar nos dois blogs do
 *              multisite. Registra fontes adicionais com conteúdo do Atlas Cultural
 *              (blog 2: páginas, Linha das Artes, artistas), aponta a busca do
 *              /cultura/ para o endpoint do blog 1, renderiza no header do blog 2
 *              o painel de busca cujo template só existe no blog 1 e serve a
 *              página de resultados completa em /busca/ (e /en/busca/). O
 *              resultado de artista abre o popup dele no mapa do Atlas. Os
 *              resultados se dividem em abas por categoria (Tudo, Estudos,
 *              Notícias…), na página e no dropdown.
 * Version: 1.5.0
 * Author: Bureau de Tecnologia
 *
 * Por que fontes adicionais, e não a lista principal: o JetSearch descarta da
 * lista principal todo post cujo tipo não é registrado e "viewable" no blog que
 * atende o request (Jet_Search_Tools::filter_public_search_query_results), e gera
 * link/thumbnail no blog atual. As fontes (jet-search/sources/register) rendem o
 * próprio bloco e não passam por esse filtro.
 *
 * Contra updates do JetSearch: as fontes estendem uma classe interna dele. Antes
 * de declará-las a assinatura é conferida por Reflection
 * (bit_crossblog_search_jetsearch_incompat); se mudou, elas não são registradas
 * — a busca segue sem os blocos do Atlas, em vez de derrubar o site com fatal —
 * e o admin mostra o motivo. A página /busca/ não depende do JetSearch. Depois
 * de qualquer update: testes/tests/13-busca-crossblog.spec.js.
 */

if ( ! defined( 'ABSPATH' ) || ! is_multisite() ) {
	return;
}

const BIT_CROSSBLOG_SEARCH_VERSION    = '1.5.0'; // entra na chave dos transients
const BIT_CROSSBLOG_SEARCH_MAIN_BLOG  = 1;
const BIT_CROSSBLOG_SEARCH_ATLAS_BLOG = 2;
const BIT_CROSSBLOG_SEARCH_ATLAS_PAGE = 57548; // "Atlas Cultural das Amazônias" no blog 2 (PT)

// Versão do JetSearch contra a qual a integração foi validada. Outra versão
// não desliga nada (a checagem por Reflection é quem decide): só pede, no
// admin, que o spec de contrato seja rodado e este valor atualizado.
const BIT_CROSSBLOG_SEARCH_JETSEARCH_TESTED = '3.6.3.1';

// Página de resultados completa ("Ver mais resultados") no blog 1.
const BIT_CROSSBLOG_SEARCH_RESULTS_SLUG     = 'busca';
const BIT_CROSSBLOG_SEARCH_RESULTS_PER_PAGE = 10;
const BIT_CROSSBLOG_SEARCH_ATLAS_MAX        = 20;
// Numa aba de seção (?cat=noticias…) a lista é paginada em PHP sobre no máximo
// este tanto de itens: a deduplicação não combina com LIMIT/OFFSET no banco.
const BIT_CROSSBLOG_SEARCH_SECTION_MAX      = 100;
// Itens por categoria na aba "Tudo" da página.
const BIT_CROSSBLOG_SEARCH_ALL_PER_SECTION  = 3;

/**
 * Idioma do request de busca. O JetSearch manda `lang` fora de `data`, e as
 * fontes só recebem `data` — por isso ele é capturado no filtro de query-args,
 * que roda antes das fontes.
 */
function bit_crossblog_search_lang( ?string $set = null ): string {
	static $lang = '';

	if ( null !== $set ) {
		$lang = $set;
	}

	if ( '' !== $lang ) {
		return $lang;
	}

	$current = apply_filters( 'wpml_current_language', null );

	return is_string( $current ) ? $current : '';
}

add_filter( 'jet-search/ajax-search/query-args', function ( $args ) {
	if ( ! empty( $args['lang'] ) && is_string( $args['lang'] ) ) {
		bit_crossblog_search_lang( sanitize_key( $args['lang'] ) );
	}

	return $args;
} );

/**
 * Executa $callback no blog $blog_id, com o idioma WPML do request.
 * Tudo o que depende do blog (permalink, título) precisa ser montado dentro do
 * callback, antes do restore.
 */
function bit_crossblog_search_in_blog( int $blog_id, callable $callback ) {
	$switched = get_current_blog_id() !== $blog_id;
	$lang     = bit_crossblog_search_lang();
	$prev     = apply_filters( 'wpml_current_language', null );

	if ( $switched ) {
		switch_to_blog( $blog_id );
	}

	try {
		if ( '' !== $lang ) {
			do_action( 'wpml_switch_language', $lang );
		}

		return $callback( $lang );
	} finally {
		if ( '' !== $lang && is_string( $prev ) && $prev !== $lang ) {
			do_action( 'wpml_switch_language', $prev );
		}

		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Busca posts publicados de $post_types no blog $blog_id, no idioma do request.
 * Cada item é montado por $build_item( WP_Post, $lang ) ainda DENTRO do blog
 * alvo — permalink, thumbnail e resumo dependem dele. Item null é descartado.
 *
 * $opts:
 * - 'fallback'   (bool)  no idioma não-padrão, inclui também os originais sem
 *                        tradução nele — o "exibir como traduzido" do WPML.
 *                        Eventos e exposições quase não têm EN.
 * - 'query_args' (array) args extras do WP_Query (meta_query, orderby…).
 *
 * @return array<int, array>
 */
function bit_crossblog_search_posts( int $blog_id, array $post_types, string $search, int $limit, callable $build_item, ?int &$total = null, array $opts = [] ): array {
	// Total só quando o chamador pede (a página de resultados); o dropdown
	// dispensa o SQL_CALC_FOUND_ROWS.
	$count    = null !== $total;
	$fallback = ! empty( $opts['fallback'] );
	$extra    = isset( $opts['query_args'] ) && is_array( $opts['query_args'] ) ? $opts['query_args'] : [];
	$search = trim( $search );

	if ( '' === $search || $limit < 1 ) {
		return [];
	}

	return bit_crossblog_search_in_blog( $blog_id, function ( string $lang ) use ( $post_types, $search, $limit, $build_item, $count, &$total, $fallback, $extra ) {
		$query_args = [
			's'                   => $search,
			'post_type'           => $post_types,
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'no_found_rows'       => ! $count,
			'sentence'            => true,
			'ignore_sticky_posts' => true,
			'suppress_filters'    => false,
			'search_columns'      => [ 'post_title', 'post_excerpt', 'post_content' ],
		];

		// Depois do switch_to_blog o WPML segue com a configuração do blog de
		// origem e ignora `lang` (PT e EN vinham misturados). O filtro de idioma
		// é feito aqui, direto na icl_translations do blog alvo.
		$lang_clauses = null;

		if ( '' !== $lang ) {
			$settings = get_option( 'icl_sitepress_settings' );
			$default  = is_array( $settings ) ? (string) ( $settings['default_language'] ?? '' ) : '';

			$lang_clauses = function ( array $clauses, WP_Query $query ) use ( $lang, $fallback, $default ) {
				global $wpdb;

				if ( ! $query->get( 'bit_crossblog_search' ) ) {
					return $clauses;
				}

				$clauses['join'] .= " INNER JOIN {$wpdb->prefix}icl_translations bit_cbs_t"
					. " ON bit_cbs_t.element_id = {$wpdb->posts}.ID"
					. " AND bit_cbs_t.element_type = CONCAT('post_', {$wpdb->posts}.post_type)";

				if ( $fallback && '' !== $default && $lang !== $default ) {
					$clauses['where'] .= $wpdb->prepare(
						" AND ( bit_cbs_t.language_code = %s OR ( bit_cbs_t.language_code = %s AND NOT EXISTS ("
						. " SELECT 1 FROM {$wpdb->prefix}icl_translations bit_cbs_t2"
						. " WHERE bit_cbs_t2.trid = bit_cbs_t.trid AND bit_cbs_t2.language_code = %s ) ) )",
						$lang,
						$default,
						$lang
					);
				} else {
					$clauses['where'] .= $wpdb->prepare( ' AND bit_cbs_t.language_code = %s', $lang );
				}

				return $clauses;
			};

			add_filter( 'posts_clauses', $lang_clauses, 99, 2 );
		}

		try {
			$query = new WP_Query( apply_filters( 'bit_crossblog_search/query_args', array_merge( $query_args, $extra, [ 'bit_crossblog_search' => true ] ), $post_types ) );
		} finally {
			if ( $lang_clauses ) {
				remove_filter( 'posts_clauses', $lang_clauses, 99 );
			}
		}

		if ( $count ) {
			$total = (int) $query->found_posts;
		}

		$items = [];

		foreach ( $query->posts as $post ) {
			$item = $build_item( $post, $lang );

			if ( $item ) {
				$items[] = $item;
			}
		}

		return $items;
	} );
}

/**
 * ID da tradução de $post_id em $lang, lido da icl_translations do blog atual
 * (o wpml_object_id não é confiável depois de switch_to_blog).
 */
function bit_crossblog_search_translation_id( int $post_id, string $lang ): int {
	global $wpdb;

	if ( '' === $lang ) {
		return $post_id;
	}

	$table = $wpdb->prefix . 'icl_translations';
	$id    = $wpdb->get_var( $wpdb->prepare(
		"SELECT t2.element_id FROM {$table} t1
		 INNER JOIN {$table} t2 ON t2.trid = t1.trid AND t2.element_type = t1.element_type
		 WHERE t1.element_id = %d AND t1.element_type LIKE 'post\\_%%' AND t2.language_code = %s
		 LIMIT 1",
		$post_id,
		$lang
	) );

	return $id ? (int) $id : $post_id;
}

/**
 * Permalink de um post do blog atual. Tipo registrado: get_permalink(). Tipo
 * que só existe no outro blog (CPT JetEngine do blog 2 visto do blog 1): o WP
 * cai em ?p=, então a URL é montada pelo slug de rewrite — que para os CPTs
 * do Atlas é o próprio nome do tipo (filtro para exceções).
 */
function bit_crossblog_search_permalink( WP_Post $post, string $lang ): string {
	if ( post_type_exists( $post->post_type ) ) {
		return (string) get_permalink( $post );
	}

	$slugs = apply_filters( 'bit_crossblog_search/rewrite_slugs', [] );
	$slug  = $slugs[ $post->post_type ] ?? $post->post_type;
	// Opção crua, não home_url(): quando o request chega por /en/wp-json/ o
	// WPML já pôs o idioma no home_url() e o /en sairia duplicado.
	$base  = untrailingslashit( (string) get_option( 'home' ) );

	// Diretório de idioma depois do path do subsite (/cultura/en/...). O
	// wpml_permalink, com a config do blog de origem, põe antes (/en/cultura/) — 404.
	$settings = get_option( 'icl_sitepress_settings' );
	$default  = is_array( $settings ) ? ( $settings['default_language'] ?? '' ) : '';

	if ( '' !== $lang && '' !== $default && $lang !== $default ) {
		$base .= '/' . $lang;
	}

	return $base . '/' . user_trailingslashit( $slug . '/' . $post->post_name );
}

/**
 * URL do Atlas no idioma do request. Artistas não têm página própria (CPT não
 * público): com $artist_id o link leva ao Atlas com o popup do artista aberto
 * no mapa (#atlas-artista-<ID>, lido pelo script do Atlas, abaixo). O ID é o
 * do post no idioma do request — os markers do mapa EN são os posts EN.
 * Fragmento, e não query string: não chega ao servidor, então a página em
 * cache (WP Rocket, CloudFront) continua uma só.
 */
function bit_crossblog_search_atlas_url( string $lang, int $artist_id = 0 ): string {
	$url = get_permalink( bit_crossblog_search_translation_id( BIT_CROSSBLOG_SEARCH_ATLAS_PAGE, $lang ) );

	if ( ! $url ) {
		return '';
	}

	return $url . ( $artist_id > 0 ? '#atlas-artista-' . $artist_id : '' );
}


/**
 * Linha que situa o artista no Atlas: "Fotografia · Palmas, Tocantins".
 * Artista não tem imagem destacada (0 de 1.311), então é o que o identifica.
 */
function bit_crossblog_search_artist_meta( WP_Post $post ): string {
	$place = implode( ', ', array_filter( [ get_post_meta( $post->ID, 'cidade', true ), get_post_meta( $post->ID, 'estado', true ) ] ) );

	return implode( ' · ', array_filter( [ get_post_meta( $post->ID, 'tema', true ), $place ] ) );
}

/**
 * Resumo em texto puro: excerpt se houver, senão o começo do conteúdo.
 * Usado pela página /busca/ e como fallback dos itens do dropdown.
 */
function bit_crossblog_search_excerpt( WP_Post $post, int $words = 30 ): string {
	$text = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : strip_shortcodes( $post->post_content );

	return wp_trim_words( wp_strip_all_tags( $text ), $words, '…' );
}

/**
 * Thumbnail dos itens do dropdown. O helper do JetSearch aplica os settings do
 * widget (visibilidade e tamanho), e é por isso que é a primeira escolha — mas
 * é interno: se um update o remover ou mudar, cai para o equivalente do core
 * em vez de dar fatal.
 */
function bit_crossblog_search_item_thumbnail( array $args, WP_Post $post ): string {
	if ( is_callable( [ '\Jet_Search_Template_Functions', 'get_post_thumbnail' ] ) ) {
		return (string) \Jet_Search_Template_Functions::get_post_thumbnail( $args, $post );
	}

	$html = get_the_post_thumbnail( $post, 'thumbnail', [ 'class' => 'jet-ajax-search__item-thumbnail-img' ] );

	return $html ? '<div class="jet-ajax-search__item-thumbnail">' . $html . '</div>' : '';
}

/**
 * As fontes estendem \Jet_Search\Search_Sources\Base, classe interna do
 * JetSearch. Se um update acrescentar um método abstrato, mudar a assinatura de
 * um método que sobrescrevemos ou tipar uma propriedade que redeclaramos, a
 * declaração da classe é um fatal no `init` — site inteiro fora, nos dois blogs.
 * Aqui isso é conferido por Reflection ANTES de declarar.
 *
 * @return string '' se compatível; senão o motivo, para log e aviso no admin.
 */
function bit_crossblog_search_jetsearch_incompat( string $base = '\Jet_Search\Search_Sources\Base' ): string {
	static $cache = [];

	if ( isset( $cache[ $base ] ) ) {
		return $cache[ $base ];
	}

	$cache[ $base ] = bit_crossblog_search_check_base_class( $base );

	return $cache[ $base ];
}

/**
 * A checagem em si, sem cache — separada para poder ser exercitada contra
 * classes de teste que simulam um update (ver o commit que a introduziu).
 */
function bit_crossblog_search_check_base_class( string $base ): string {
	if ( ! class_exists( $base ) ) {
		return sprintf( 'a classe %s não existe mais', ltrim( $base, '\\' ) );
	}

	try {
		$class = new ReflectionClass( $base );

		// Abstratos: todos precisam estar entre os que implementamos.
		$implemented = [ 'get_label', 'get_priority', 'build_items_list', 'get_query_result' ];

		foreach ( $class->getMethods( ReflectionMethod::IS_ABSTRACT ) as $method ) {
			if ( ! in_array( $method->getName(), $implemented, true ) ) {
				return sprintf( 'método abstrato novo: %s()', $method->getName() );
			}
		}

		// Sobrescritos: nossa versão não declara tipo de retorno e aceita no
		// máximo N parâmetros. Se o pai passar a declarar retorno, exigir mais
		// parâmetros ou ficar final/static, a sobrescrita deixa de ser compatível.
		$overridden = [
			'get_label'        => 0,
			'get_priority'     => 0,
			'build_items_list' => 0,
			'get_query_result' => 1,
			'render'           => 0,
		];

		foreach ( $overridden as $name => $max_params ) {
			if ( ! $class->hasMethod( $name ) ) {
				return sprintf( 'o método %s() sumiu da classe base', $name );
			}

			$method = $class->getMethod( $name );

			if ( $method->hasReturnType() || $method->isFinal() || $method->isStatic() || $method->getNumberOfParameters() > $max_params ) {
				return sprintf( 'a assinatura de %s() mudou', $name );
			}
		}

		// Métodos nossos não podem colidir com métodos novos da base.
		foreach ( [ 'section' ] as $name ) {
			if ( $class->hasMethod( $name ) ) {
				return sprintf( 'a classe base passou a ter um método %s()', $name );
			}
		}

		// As fontes são instanciadas com `new class()` no init, nos dois blogs:
		// construtor com parâmetro obrigatório seria ArgumentCountError em
		// todo request. get_name() é chamado pelo manager, pelo render e aqui.
		$constructor = $class->getConstructor();

		if ( $constructor && $constructor->getNumberOfRequiredParameters() > 0 ) {
			return 'o construtor da classe base passou a exigir parâmetros';
		}

		if ( ! $class->hasMethod( 'get_name' ) ) {
			return 'o método get_name() sumiu da classe base';
		}

		// Propriedades que lemos; `source_name` é redeclarada sem tipo, o que
		// é fatal se o pai a tipar.
		foreach ( [ 'args', 'search_string', 'items_list', 'results_count', 'limit', 'source_name' ] as $name ) {
			if ( ! $class->hasProperty( $name ) ) {
				return sprintf( 'a propriedade $%s sumiu da classe base', $name );
			}
		}

		if ( $class->getProperty( 'source_name' )->hasType() ) {
			return 'a propriedade $source_name passou a ter tipo';
		}
	} catch ( ReflectionException $e ) {
		return 'Reflection falhou: ' . $e->getMessage();
	}

	return '';
}

/**
 * Versão do JetSearch em execução, ou '' se não der para saber.
 */
function bit_crossblog_search_jetsearch_version(): string {
	if ( function_exists( 'jet_search' ) && is_callable( [ jet_search(), 'get_version' ] ) ) {
		return (string) jet_search()->get_version();
	}

	return '';
}

/* ──────────────────────────────────────────────────────────────────────────
 * Registro das seções — fonte única do dropdown E da página /busca/
 *
 * Cada entrada vira uma fonte adicional do JetSearch (bloco com título no
 * dropdown) e uma seção da página de resultados. Chaves:
 *   label      rótulo no editor do Elementor
 *   titles     título do bloco/seção por idioma (os headers EN mostram o
 *              template PT 4360, então o título do widget sairia em PT)
 *   priority   ordem: maior aparece primeiro, logo depois da lista principal
 *   blog/types posts buscados (WP_Query em modo frase, no idioma do request)
 *   fallback   no EN, inclui originais PT sem tradução (eventos, exposições)
 *   query_args callable → args extras do WP_Query (ex.: só eventos futuros)
 *   url        callable( WP_Post, $lang ) → link; padrão: permalink
 *   prefix     callable( WP_Post ) → linha antes do resumo ("Fotografia · …")
 *   items      callable( $term, $limit, $context, &$total ) → cards prontos,
 *              para o que não é post (participantes, CCT do JetEngine)
 *   more       callable( $lang ) → link "ver todos" na página /busca/
 *   tab        slug da aba (?cat=) em que a seção entra; seções com o mesmo
 *              slug dividem a aba, na ordem de prioridade
 *   tab_labels rótulo da aba por idioma (vale o da primeira seção da aba)
 * ────────────────────────────────────────────────────────────────────────── */

const BIT_CROSSBLOG_SEARCH_PARTICIPANTS_PAGE = 26645; // "Participantes" no blog 1 (PT)

function bit_crossblog_search_sections(): array {
	static $sections = null;

	if ( null !== $sections ) {
		return $sections;
	}

	$sections = [
		'bit_noticias'      => [
			'label'    => 'Notícias',
			'titles'   => [ 'pt-br' => 'Notícias', 'en' => 'News' ],
			'priority' => 6,
			'tab'      => 'noticias',
			'tab_labels' => [ 'pt-br' => 'Notícias', 'en' => 'News' ],
			'blog'     => BIT_CROSSBLOG_SEARCH_MAIN_BLOG,
			'types'    => [ 'post' ],
			'prefix'   => 'bit_crossblog_search_news_meta',
		],
		'bit_eventos'       => [
			'label'      => 'Próximos eventos',
			'titles'     => [ 'pt-br' => 'Próximos eventos', 'en' => 'Upcoming events' ],
			'priority'   => 5,
			'tab'        => 'eventos',
			'tab_labels' => [ 'pt-br' => 'Eventos', 'en' => 'Events' ],
			'blog'       => BIT_CROSSBLOG_SEARCH_MAIN_BLOG,
			'types'      => [ 'tribe_events' ],
			'fallback'   => true, // 14 futuros em PT, 0 em EN
			'query_args' => 'bit_crossblog_search_events_args',
			'prefix'     => 'bit_crossblog_search_event_meta',
		],
		'bit_participantes' => [
			'label'    => 'Participantes',
			'titles'   => [ 'pt-br' => 'Participantes', 'en' => 'Participants' ],
			'priority' => 4,
			'tab'      => 'participantes',
			'tab_labels' => [ 'pt-br' => 'Participantes', 'en' => 'Participants' ],
			'items'    => 'bit_crossblog_search_participants',
			'more'     => 'bit_crossblog_search_participants_url',
		],
		// A chave diz "atlas" por herança (é o nome gravado nos templates 4360/5638),
		// mas o conteúdo são as páginas do site /cultura/ (Linha do Tempo, Linha
		// das Artes…): o Atlas em si é uma página só, e o que mora nele são os
		// artistas. As duas dividem a aba "Cultura".
		'bit_atlas_pages'   => [
			'label'    => 'Cultura (páginas do /cultura/)',
			'titles'   => [ 'pt-br' => 'Páginas da Cultura', 'en' => 'Culture pages' ],
			'priority' => 3,
			'tab'      => 'cultura',
			'tab_labels' => [ 'pt-br' => 'Cultura', 'en' => 'Culture' ],
			'blog'     => BIT_CROSSBLOG_SEARCH_ATLAS_BLOG,
			'types'    => [ 'page', 'linha-das-artes' ],
		],
		'bit_atlas_artists' => [
			'label'    => 'Atlas Cultural (artistas)',
			'titles'   => [ 'pt-br' => 'Atlas Cultural: artistas', 'en' => 'Cultural Atlas: artists' ],
			'priority' => 2,
			'tab'      => 'cultura',
			'blog'     => BIT_CROSSBLOG_SEARCH_ATLAS_BLOG,
			'types'    => [ 'artistas' ],
			'url'      => function ( WP_Post $post, string $lang ): string {
				return bit_crossblog_search_atlas_url( $lang, (int) $post->ID );
			},
			'prefix'   => 'bit_crossblog_search_artist_meta',
			'more'     => function ( string $lang ): string {
				return (string) bit_crossblog_search_in_blog( BIT_CROSSBLOG_SEARCH_ATLAS_BLOG, function ( string $l ) {
					return bit_crossblog_search_atlas_url( $l );
				} );
			},
		],
		'bit_exposicoes'    => [
			'label'    => 'Atlas Cultural (exposições)',
			'titles'   => [ 'pt-br' => 'Exposições', 'en' => 'Exhibitions' ],
			'priority' => 1,
			'tab'      => 'exposicoes',
			'tab_labels' => [ 'pt-br' => 'Exposições', 'en' => 'Exhibitions' ],
			'blog'     => BIT_CROSSBLOG_SEARCH_ATLAS_BLOG,
			'types'    => array_keys( bit_crossblog_search_exhibition_pages() ),
			'fallback' => true, // galeria-1 e expo-pdp só têm PT
			'url'      => 'bit_crossblog_search_exhibition_url',
			'prefix'   => 'bit_crossblog_search_exhibition_name',
		],
	];

	$sections = (array) apply_filters( 'bit_crossblog_search/sections', $sections );

	uasort( $sections, function ( $a, $b ) {
		return ( $b['priority'] ?? 0 ) <=> ( $a['priority'] ?? 0 );
	} );

	return $sections;
}

function bit_crossblog_search_section_title( string $key, string $fallback = '' ): string {
	$section = bit_crossblog_search_sections()[ $key ] ?? [];
	$lang    = bit_crossblog_search_lang();

	return (string) ( $section['titles'][ $lang ] ?? ( '' !== $fallback ? $fallback : ( $section['titles']['pt-br'] ?? $key ) ) );
}

/**
 * Abas de categoria, na ordem em que aparecem: a lista principal (estudos,
 * encontros e publicações — os post types do widget) e depois as seções do
 * registro, agrupadas pelo slug `tab`. Fonte nova no registro ganha aba
 * sozinha. "Tudo" não está aqui: é a ausência de categoria.
 *
 * @return array<string, array{labels: array, sections: string[], main: bool}>
 */
function bit_crossblog_search_tabs(): array {
	$tabs = [
		'estudos' => [ 'labels' => [ 'pt-br' => 'Estudos', 'en' => 'Studies' ], 'sections' => [], 'main' => true ],
	];

	foreach ( bit_crossblog_search_sections() as $key => $section ) {
		$slug = sanitize_key( (string) ( $section['tab'] ?? str_replace( '_', '-', preg_replace( '/^bit_/', '', $key ) ) ) );

		if ( ! isset( $tabs[ $slug ] ) ) {
			$tabs[ $slug ] = [ 'labels' => (array) ( $section['tab_labels'] ?? $section['titles'] ?? [] ), 'sections' => [], 'main' => false ];
		}

		$tabs[ $slug ]['sections'][] = $key;
	}

	return $tabs;
}

function bit_crossblog_search_tab_label( string $slug ): string {
	$tab  = bit_crossblog_search_tabs()[ $slug ] ?? null;
	$lang = bit_crossblog_search_lang();

	if ( ! $tab ) {
		return bit_crossblog_search_t( 'all' );
	}

	return (string) ( $tab['labels'][ $lang ] ?? $tab['labels']['pt-br'] ?? $slug );
}

/**
 * Abas com contagem, para a página e para o dropdown. $counts traz 'estudos'
 * (lista principal) e as chaves de seção que estão ligadas; aba sem nenhuma
 * seção ligada fica de fora. A primeira é "Tudo" (slug '').
 *
 * @return array<int, array{slug: string, label: string, count: int, sections: string[]}>
 */
function bit_crossblog_search_tab_list( array $counts ): array {
	$list = [];
	$all  = 0;

	foreach ( bit_crossblog_search_tabs() as $slug => $tab ) {
		$keys = $tab['main'] ? [ 'estudos' ] : $tab['sections'];
		$on   = array_values( array_intersect( $keys, array_keys( $counts ) ) );

		if ( ! $on ) {
			continue;
		}

		$count = 0;

		foreach ( $on as $key ) {
			$count += (int) $counts[ $key ];
		}

		$all   += $count;
		$list[] = [ 'slug' => $slug, 'label' => bit_crossblog_search_tab_label( $slug ), 'count' => $count, 'sections' => $tab['main'] ? [] : $on ];
	}

	array_unshift( $list, [ 'slug' => '', 'label' => bit_crossblog_search_t( 'all' ), 'count' => $all, 'sections' => [] ] );

	return $list;
}

/**
 * Itens de uma seção no formato comum: title, url, thumb (HTML), text (puro).
 * $context 'dropdown' usa o helper de thumbnail do JetSearch (tamanho do
 * widget); 'page' usa o thumbnail do core com a classe da página /busca/.
 *
 * @return array<int, array{title: string, url: string, thumb: string, text: string}>
 */
function bit_crossblog_search_section_items( string $key, string $term, int $limit, string $context, array $args = [], ?int &$total = null ): array {
	$section = bit_crossblog_search_sections()[ $key ] ?? null;
	$term    = trim( $term );

	if ( ! $section || '' === $term || $limit < 1 ) {
		return [];
	}

	if ( isset( $section['items'] ) && is_callable( $section['items'] ) ) {
		$fn  = $section['items'];
		$raw = (array) $fn( $term, $limit * 2, $context, $total );

		return bit_crossblog_search_dedupe_slice( $raw, $limit * 2, $limit, $total );
	}

	$build = function ( WP_Post $post, string $lang ) use ( $section, $context, $args ) {
		$url = isset( $section['url'] ) ? (string) call_user_func( $section['url'], $post, $lang ) : bit_crossblog_search_permalink( $post, $lang );

		if ( '' === $url ) {
			return null;
		}

		$prefix = isset( $section['prefix'] ) ? (string) call_user_func( $section['prefix'], $post ) : '';
		$text   = bit_crossblog_search_excerpt( $post, 'page' === $context ? 30 : 25 );

		return [
			'title' => get_the_title( $post ),
			'url'   => $url,
			'thumb' => 'page' === $context
				? (string) get_the_post_thumbnail( $post, 'thumbnail', [ 'class' => 'bit-busca__img', 'loading' => 'lazy', 'alt' => '' ] )
				: bit_crossblog_search_item_thumbnail( $args, $post ),
			'text'  => '' !== $prefix ? $prefix . ( '' !== $text ? ' — ' . $text : '' ) : $text,
		];
	};

	$opts = [
		'fallback'   => ! empty( $section['fallback'] ),
		'query_args' => isset( $section['query_args'] ) && is_callable( $section['query_args'] ) ? (array) call_user_func( $section['query_args'] ) : [],
	];

	// Busca o dobro e deduplica (ver bit_crossblog_search_dedupe). O total da
	// página continua o do banco, que conta as cópias.
	$items = bit_crossblog_search_posts( (int) $section['blog'], (array) $section['types'], $term, $limit * 2, $build, $total, $opts );

	return bit_crossblog_search_dedupe_slice( $items, $limit * 2, $limit, $total );
}

/**
 * Deduplica e corta em $limit. Quando a busca veio inteira (menos itens que o
 * pedido ao banco), o total passa a ser o que sobrou da deduplicação: sem isso
 * a aba dizia 23 e a lista mostrava 15 (as cópias da galeria-1). Com a busca
 * cortada o total segue o do banco, que conta as cópias.
 */
function bit_crossblog_search_dedupe_slice( array $raw, int $fetched, int $limit, ?int &$total ): array {
	$cards = bit_crossblog_search_dedupe( $raw );

	if ( null !== $total && count( $raw ) < $fetched ) {
		$total = count( $cards );
	}

	return array_slice( $cards, 0, $limit );
}

/**
 * Remove cards repetidos. Repetido = mesmo título E (mesmo destino OU mesmo
 * texto). O conteúdo tem cópias que o visitante veria em dobro (medido em
 * 25/09/2026):
 * - 15 obras de galeria-1 idênticas às de galeria-2, com o mesmo destino;
 * - notícias com o mesmo título e o mesmo texto em destinos diferentes (as
 *   de clipping de veículos distintos NÃO somem: o veículo entra no texto);
 * - 253 participantes com linha repetida, todos com o mesmo destino.
 * Título igual com texto E destino diferentes não é repetição: as três
 * "Rakel Caminha" da Linha das Artes são obras distintas.
 */
function bit_crossblog_search_dedupe( array $cards ): array {
	$norm = function ( string $text, int $length = 0 ): string {
		$text = mb_strtolower( trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) ) ) );

		return $length ? mb_substr( $text, 0, $length ) : $text;
	};

	$seen = [];
	$out  = [];

	foreach ( $cards as $card ) {
		$title = $norm( (string) $card['title'] );
		$keys  = [ $title . '|url|' . $card['url'] ];

		if ( '' !== trim( (string) $card['text'] ) ) {
			$keys[] = $title . '|txt|' . $norm( (string) $card['text'], 160 );
		}

		if ( array_intersect_key( $seen, array_flip( $keys ) ) ) {
			continue;
		}

		foreach ( $keys as $key ) {
			$seen[ $key ] = true;
		}

		$out[] = $card;
	}

	return $out;
}

/* ── Notícias: o veículo antes do resumo ──
 * Boa parte das notícias é clipping: a mesma matéria republicada por vários
 * veículos, cada uma com o seu registro ("Em vez de chantagem…" tem 11, da
 * Folha, Yahoo, MSN…). São registros legítimos, não cópias; sem o veículo o
 * visitante via oito títulos iguais sem saber a diferença. */

function bit_crossblog_search_news_meta( WP_Post $post ): string {
	$names = wp_get_post_terms( $post->ID, 'veiculo', [ 'fields' => 'names' ] );

	return is_array( $names ) ? implode( ', ', $names ) : '';
}

/* ── Eventos (The Events Calendar, blog 1): só os que ainda não terminaram ── */

function bit_crossblog_search_events_args(): array {
	return [
		'meta_query' => [
			[
				'key'     => '_EventEndDate',
				'value'   => current_time( 'mysql' ),
				'compare' => '>=',
				'type'    => 'DATETIME',
			],
		],
		'meta_key'   => '_EventStartDate', // phpcs:ignore WordPress.DB.SlowDBQuery
		'orderby'    => 'meta_value',
		'order'      => 'ASC',
	];
}

/**
 * "12 out 2026 · Local" — data de início e local, antes do resumo.
 */
function bit_crossblog_search_event_meta( WP_Post $post ): string {
	$start = (string) get_post_meta( $post->ID, '_EventStartDate', true );
	$date  = '' !== $start ? date_i18n( get_option( 'date_format' ), strtotime( $start ) ) : '';
	$venue = (int) get_post_meta( $post->ID, '_EventVenueID', true );

	return implode( ' · ', array_filter( [ $date, $venue ? get_the_title( $venue ) : '' ] ) );
}

/* ── Participantes (CCT do JetEngine, blog 1) ──
 * A fonte nativa do JetEngine para CCT (cct_participantes_cct) busca em todos
 * os campos e rende só uma lista de links, sem resumo nem imagem; esta segue
 * o formato dos outros itens: nome e organização. Não há página por
 * participante: o link leva à página de participantes no idioma do request.
 * (O filtro de busca daquela página não filtra, em dev e em prod — medido em
 * 25/09/2026 —, por isso o link não tenta pré-filtrar.) */

function bit_crossblog_search_participants_url( string $lang ): string {
	return (string) bit_crossblog_search_in_blog( BIT_CROSSBLOG_SEARCH_MAIN_BLOG, function ( string $l ) use ( $lang ) {
		$url = get_permalink( bit_crossblog_search_translation_id( BIT_CROSSBLOG_SEARCH_PARTICIPANTS_PAGE, '' !== $lang ? $lang : $l ) );

		return $url ? $url : '';
	} );
}

function bit_crossblog_search_participants( string $term, int $limit, string $context, ?int &$total = null ): array {
	return (array) bit_crossblog_search_in_blog( BIT_CROSSBLOG_SEARCH_MAIN_BLOG, function ( string $lang ) use ( $term, $limit, $context, &$total ) {
		global $wpdb;

		static $exists = null;
		$table = $wpdb->prefix . 'jet_cct_participantes_cct';

		if ( null === $exists ) {
			$exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		}

		if ( ! $exists ) {
			return [];
		}

		$like  = '%' . $wpdb->esc_like( $term ) . '%';
		$where = $wpdb->prepare( "cct_status = 'publish' AND ( item_title LIKE %s OR organizacao LIKE %s )", $like, $like );

		if ( null !== $total ) {
			// Por nome distinto: 253 nomes têm linha repetida no CCT.
			$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT LOWER(TRIM(item_title))) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		// Quem começa com o termo primeiro, depois ordem alfabética; entre linhas
		// repetidas do mesmo nome, a que tem organização vem antes — é a que
		// sobra na deduplicação.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT item_title, organizacao, item_thumbnail FROM {$table} WHERE {$where} ORDER BY ( item_title LIKE %s ) DESC, item_title ASC, ( TRIM( IFNULL( organizacao, '' ) ) = '' ) ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->esc_like( $term ) . '%',
			$limit
		) );

		$url   = bit_crossblog_search_participants_url( $lang );
		$cards = [];

		foreach ( (array) $rows as $row ) {
			$thumb = '';

			if ( is_numeric( $row->item_thumbnail ) && (int) $row->item_thumbnail > 0 ) {
				$img   = wp_get_attachment_image( (int) $row->item_thumbnail, 'thumbnail', false, [ 'class' => 'page' === $context ? 'bit-busca__img' : 'jet-ajax-search__item-thumbnail-img', 'alt' => '' ] );
				$thumb = $img ? ( 'page' === $context ? $img : '<div class="jet-ajax-search__item-thumbnail">' . $img . '</div>' ) : '';
			}

			$cards[] = [
				'title' => (string) $row->item_title,
				'url'   => $url,
				'thumb' => $thumb,
				'text'  => (string) $row->organizacao,
			];
		}

		return $cards;
	} );
}

/* ── Exposições do Atlas (blog 2): cada tipo mora numa página de exposição ── */

function bit_crossblog_search_exhibition_pages(): array {
	return [
		'artistas-infantis' => [ 70848, 'Exposição Cores do Futuro', 'Colors of the Future Exhibition' ],
		'galeria-1'         => [ 26767, 'Galeria', 'Gallery' ],
		'galeria-2'         => [ 26767, 'Galeria', 'Gallery' ],
		'expo-pdp'          => [ 80405, 'Exposição Poéticas do Possível', 'Poetics of the Possible Exhibition' ],
	];
}

function bit_crossblog_search_exhibition_url( WP_Post $post, string $lang ): string {
	$page = bit_crossblog_search_exhibition_pages()[ $post->post_type ][0] ?? 0;
	$url  = $page ? get_permalink( bit_crossblog_search_translation_id( $page, $lang ) ) : '';

	return $url ? $url : '';
}

function bit_crossblog_search_exhibition_name( WP_Post $post ): string {
	$data = bit_crossblog_search_exhibition_pages()[ $post->post_type ] ?? null;

	if ( ! $data ) {
		return '';
	}

	return 'en' === bit_crossblog_search_lang() ? $data[2] : $data[1];
}

/* ── Fontes do dropdown: uma classe genérica, uma instância por seção ── */

add_action( 'jet-search/sources/register', function ( $manager ) {
	if ( class_exists( 'BIT_Crossblog_Search_Source', false ) || ! is_object( $manager ) || ! method_exists( $manager, 'register_source' ) ) {
		return;
	}

	$incompat = bit_crossblog_search_jetsearch_incompat();

	if ( '' !== $incompat ) {
		error_log( '[bit-crossblog-search] fontes adicionais desligadas — JetSearch ' . bit_crossblog_search_jetsearch_version() . ': ' . $incompat );

		return;
	}

	/**
	 * Fonte adicional do JetSearch montada a partir de uma entrada do registro.
	 */
	class BIT_Crossblog_Search_Source extends \Jet_Search\Search_Sources\Base {

		protected $source_name = '';

		public function __construct( string $key ) {
			$this->source_name = $key;
			parent::__construct();
		}

		protected function section(): array {
			return bit_crossblog_search_sections()[ $this->source_name ] ?? [];
		}

		public function get_label() {
			return (string) ( $this->section()['label'] ?? $this->source_name );
		}

		// O JS do JetSearch insere cada fonte "depois dos posts" com .after()
		// colado na lista: a de maior prioridade é inserida por último e fica
		// em cima — a ordem visível é a da prioridade, decrescente.
		public function get_priority() {
			return (int) ( $this->section()['priority'] ?? 1 );
		}

		public function get_query_result( $limit = null ) {
			// Com o total: é a contagem da aba no dropdown.
			$total = 0;
			$items = bit_crossblog_search_section_items(
				$this->source_name,
				// set_search_string() do JetSearch aplica esc_sql(); o WP_Query
				// escapa de novo, e "d'água" viraria busca por "d\'água".
				stripslashes( (string) $this->search_string ),
				// O limite vem de data[search_source_<nome>_limit], do request
				// público (Base::set_args_limit). Sem teto, um GET anônimo pedia
				// 5000 e levava os 658 artistas (292 KB) — e /wp-json/ não é
				// cacheado no CloudFront. Revisão de 25/09/2026.
				min( max( (int) ( $limit ?? $this->limit ), 1 ), BIT_CROSSBLOG_SEARCH_ATLAS_MAX ),
				'dropdown',
				$this->args,
				$total
			);

			bit_crossblog_search_dropdown_counts( $this->source_name, $total );

			return $items;
		}

		public function build_items_list() {
			$items = apply_filters( 'bit_crossblog_search/' . $this->source_name . '/items', $this->get_query_result() );

			$this->items_list    = $items;
			$this->results_count = count( $items );
		}

		/**
		 * Mesmo markup de templates/jet-ajax-search/global/results-item.php,
		 * para herdar o estilo do widget (tamanho da thumb, fonte, divisórias).
		 * O render() da classe base só produz uma lista de links.
		 */
		public function render() {
			if ( empty( $this->items_list ) ) {
				return '';
			}

			$name   = $this->source_name;
			$title  = bit_crossblog_search_section_title( $name, (string) ( $this->args[ 'search_source_' . $name . '_title' ] ?? '' ) );
			$target = ! empty( $this->args['show_result_new_tab'] ) && filter_var( $this->args['show_result_new_tab'], FILTER_VALIDATE_BOOLEAN ) ? ' target="_blank"' : '';
			$html   = '';

			foreach ( $this->items_list as $item ) {
				$content = '' !== $item['text'] ? '<div class="jet-ajax-search__item-content">' . esc_html( $item['text'] ) . '</div>' : '';

				$html .= '<div class="jet-ajax-search__results-item">'
					. '<a class="jet-ajax-search__item-link" href="' . esc_url( $item['url'] ) . '"' . $target . '>'
					. wp_kses_post( $item['thumb'] )
					. '<div class="jet-ajax-search__item-content-wrapper">'
					. '<div class="jet-ajax-search__item-title">' . esc_html( $item['title'] ) . '</div>'
					. $content
					. '</div></a></div>';
			}

			return '<div class="jet-ajax-search__source-results-holder jet-ajax-search__source-results-holder_' . esc_attr( $name ) . '">'
				. '<div class="jet-ajax-search__source-results-holder-title">' . esc_html( $title ) . '</div>'
				. $html
				. '</div>';
		}
	}

	// Última rede: qualquer erro ao instanciar vira log, não fatal no init.
	try {
		foreach ( array_keys( bit_crossblog_search_sections() ) as $key ) {
			$manager->register_source( new BIT_Crossblog_Search_Source( $key ) );
		}
	} catch ( \Throwable $e ) {
		error_log( '[bit-crossblog-search] fontes adicionais não registradas: ' . $e->getMessage() );
	}
} );

/* ── Abas do dropdown: contagens por categoria na resposta do REST ──
 * O JetSearch devolve o total "N resultados" como a soma do que cada bloco
 * MOSTRA (5 por fonte, 10 posts), não do que existe. As abas precisam do total
 * real de cada categoria, então ele é medido aqui e vai na resposta em
 * `bit_tabs`, que o script do dropdown lê. Sem o campo (hook removido num
 * update), o dropdown só não ganha abas. */

/**
 * Totais do request corrente: seção => total, e 'estudos' => total da lista
 * principal. Setter e getter no mesmo static.
 */
function bit_crossblog_search_dropdown_counts( ?string $key = null, int $total = 0 ): array {
	static $counts = [];

	if ( null !== $key ) {
		$counts[ $key ] = $total;
	}

	return $counts;
}

// A query principal do JetSearch é marcada no filtro de args e o total vem do
// found_posts dela — antes do filter_public_search_query_results, que o
// reescreve para o número de posts da página (no máximo 10). Lido em
// posts_results, e não no filtro found_posts: com o cache de queries do core
// (WP 6.1+, Redis) o resultado repetido vem do cache sem passar pelo filtro —
// a aba dizia 34 na primeira busca e 10 nas seguintes.
add_filter( 'jet-search/ajax-search/query-args', function ( $args ) {
	$args['bit_crossblog_search_main'] = true;

	return $args;
}, 20 );

add_filter( 'posts_results', function ( $posts, $query ) {
	if ( $query instanceof WP_Query && $query->get( 'bit_crossblog_search_main' ) ) {
		bit_crossblog_search_dropdown_counts( 'estudos', (int) $query->found_posts );
	}

	return $posts;
}, 10, 2 );

add_action( 'jet-search/ajax-search/search-results', function ( &$response, $posts = null, $data = [] ) {
	if ( ! is_array( $response ) || empty( $response['sources'] ) ) {
		return;
	}

	$sections = bit_crossblog_search_sections();
	$enabled  = [];

	foreach ( (array) $response['sources'] as $source ) {
		if ( isset( $source['type'], $sections[ $source['type'] ] ) ) {
			$enabled[] = $source['type'];
		}
	}

	if ( ! $enabled ) {
		return;
	}

	$measured = bit_crossblog_search_dropdown_counts();
	$counts   = [ 'estudos' => (int) ( $measured['estudos'] ?? ( $response['post_count'] ?? 0 ) ) ];

	foreach ( $enabled as $key ) {
		$counts[ $key ] = (int) ( $measured[ $key ] ?? 0 );
	}

	$term = is_array( $data ) && isset( $data['value'] ) ? sanitize_text_field( (string) $data['value'] ) : '';
	$base = bit_crossblog_search_results_url( bit_crossblog_search_lang() );
	$tabs = bit_crossblog_search_tab_list( $counts );

	foreach ( $tabs as $i => $tab ) {
		$tabs[ $i ]['url'] = bit_crossblog_search_results_link( $base, $term, $tab['slug'] );
	}

	$response['bit_tabs'] = [
		'term'    => $term,
		'tabs'    => $tabs,
		'label'   => bit_crossblog_search_t( 'tabs' ),
		'see_all' => bit_crossblog_search_t( 'see_all' ),
		'see_one' => bit_crossblog_search_t( 'see_one' ),
	];
}, 10, 3 );

/**
 * Ajustes de front-end no dropdown, porque o JetSearch não prevê fontes longas:
 *
 * 1. Ele põe as fontes "depois dos posts" dentro de
 *    .jet-ajax-search__results-list, fora do slide de posts — que tem rolagem
 *    própria (max-height do widget) — e fixa a altura da lista na do slide com
 *    overflow:hidden. Os blocos ficavam cortados, ou com uma segunda rolagem.
 *    Aqui eles entram no fim do último slide: uma rolagem só cobre posts e
 *    Atlas, e a altura da lista é ressincronizada com o slide.
 * 2. Busca sem nenhum post não tem slide: os blocos ficam na lista, de altura
 *    automática. Como o painel do header é position:fixed, a altura fica
 *    limitada à janela, com rolagem interna.
 *
 * Observa o DOM em vez do evento `jet-ajax-search/show-results`, que só
 * dispara quando há posts.
 */
add_action( 'wp_enqueue_scripts', function () {
	$js = <<<'JS'
( function () {
	var SEL = '[class*="jet-ajax-search__source-results-holder_bit_"]';

	// Slide visível: o JetSearch pagina movendo a lista interna com
	// translateX(-N*100%). É ele que a lista mede em syncResultsListHeight.
	function activeSlide( list, slides ) {
		var inner = list.querySelector( '.jet-ajax-search__results-list-inner' );
		var index = 0;
		if ( inner && window.DOMMatrixReadOnly && list.clientWidth ) {
			var tx = new DOMMatrixReadOnly( getComputedStyle( inner ).transform ).m41;
			index = Math.round( Math.abs( tx ) / list.clientWidth );
		}
		return slides[ index ] || slides[ 0 ];
	}

	// Escreve só quando muda: o observer também vigia `style`, e uma escrita
	// que não altera nada não pode gerar outra mutação (laço infinito).
	function setStyle( el, prop, value ) {
		if ( el.style[ prop ] !== value ) {
			el.style[ prop ] = value;
		}
	}

	// Limite de baixo visível: a janela, ou o ancestral que corta o conteúdo
	// (overflow != visible) — o painel do header é fixed com inset de 4rem e
	// overflow:hidden, e medir só pela janela deixava o rodapé "Ver mais
	// resultados" na faixa cortada (reportado em 25/09/2026).
	function visibleBottom( area ) {
		var bottom = window.innerHeight;
		for ( var el = area.parentElement; el && el !== document.body; el = el.parentElement ) {
			if ( getComputedStyle( el ).overflowY !== 'visible' ) {
				bottom = Math.min( bottom, el.getBoundingClientRect().bottom );
			}
		}
		return bottom;
	}

	function adjust( area ) {
		var list = area.querySelector( '.jet-ajax-search__results-list' );
		var holder = area.querySelector( '.jet-ajax-search__results-holder' );
		if ( ! list || ! holder ) {
			return;
		}
		var slides = list.querySelectorAll( '.jet-ajax-search__results-slide' );
		// Blocos no fim do PRIMEIRO slide: com o widget paginado (10 por
		// slide), no último eles só apareceriam depois de o visitante clicar
		// até a última bolinha.
		var first = slides.length ? slides[ 0 ] : null;
		var loose = list.querySelectorAll( ':scope > ' + SEL + ', :scope > .jet-ajax-search__results-list-inner > ' + SEL );
		if ( first && loose.length ) {
			loose.forEach( function ( block ) {
				first.appendChild( block );
			} );
		}
		if ( first ) {
			setStyle( holder, 'maxHeight', '' );
			setStyle( holder, 'overflowY', '' );
			// O max-height do slide (widget) ignora a janela: no mobile o
			// dropdown passava do rodapé da tela e o fim da lista não era
			// alcançável. Teto em todos os slides, medido no visível.
			var active = activeSlide( list, slides );
			var footer = area.querySelector( '.jet-ajax-search__results-footer' );
			var below = footer ? footer.offsetHeight : 0;
			var space = Math.floor( visibleBottom( area ) - active.getBoundingClientRect().top - below - 12 );
			// Piso de ~1 item: em tela baixa (1280x720) o espaço sob o campo
			// fica abaixo de 160px, e sem teto o rodapé saía cortado.
			space = Math.max( space, 96 );
			slides.forEach( function ( slide ) {
				setStyle( slide, 'maxHeight', slide.scrollHeight > space ? space + 'px' : '' );
			} );
			if ( list.style.height ) {
				setStyle( list, 'height', active.offsetHeight + 'px' );
			}
			return;
		}
		var capped = false;
		if ( holder.querySelector( SEL ) ) {
			var room = Math.floor( visibleBottom( area ) - holder.getBoundingClientRect().top - 16 );
			room = Math.max( room, 96 );
			capped = holder.scrollHeight > room;
		}
		setStyle( holder, 'maxHeight', capped ? room + 'px' : '' );
		setStyle( holder, 'overflowY', capped ? 'auto' : '' );
	}

	function watch() {
		document.querySelectorAll( '.jet-ajax-search__results-area' ).forEach( function ( area ) {
			var queued = false;
			new MutationObserver( function () {
				if ( queued ) {
					return;
				}
				queued = true;
				requestAnimationFrame( function () {
					queued = false;
					adjust( area );
				} );
			} ).observe( area, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'style' ] } );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', watch );
	} else {
		watch();
	}

	/* Abas por categoria. Os dados vêm no `bit_tabs` da resposta do REST
	   (rótulos no idioma, totais reais, URL da aba em /busca/). O filtro é só
	   no cliente: todos os blocos já vieram na mesma resposta. A resposta é
	   lida pelo ajaxSuccess global do jQuery, que dispara depois do callback
	   do JetSearch — a lista já está no DOM. */
	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function fmt( tpl, count, label ) {
		return tpl.replace( '%1$s', String( count ) ).replace( '%2$s', label );
	}

	function applyTab( area, slug ) {
		var data = area.bitTabs;
		if ( ! data ) {
			return;
		}
		var tab = data.tabs.filter( function ( t ) { return t.slug === slug; } )[ 0 ] || data.tabs[ 0 ];
		area.setAttribute( 'data-bit-tab', tab.slug );

		area.querySelectorAll( '.bit-tabs__tab' ).forEach( function ( b ) {
			b.setAttribute( 'aria-selected', b.getAttribute( 'data-cat' ) === tab.slug ? 'true' : 'false' );
		} );

		// Blocos adicionais: visíveis em Tudo e na aba a que pertencem.
		area.querySelectorAll( SEL ).forEach( function ( block ) {
			var m = /jet-ajax-search__source-results-holder_(bit_[a-z_]+)/.exec( block.className );
			var show = tab.slug === '' || ( m && tab.sections.indexOf( m[ 1 ] ) >= 0 );
			block.classList.toggle( 'bit-tab-hidden', ! show );
		} );

		var count = area.querySelector( '.jet-ajax-search__results-count span' );
		if ( count ) {
			count.textContent = String( tab.count );
		}

		var btn = area.querySelector( '.jet-ajax-search__full-results' );
		if ( btn ) {
			if ( ! btn.hasAttribute( 'data-bit-text' ) ) {
				btn.setAttribute( 'data-bit-text', btn.textContent );
			}
			btn.textContent = tab.slug === ''
				? btn.getAttribute( 'data-bit-text' )
				: fmt( tab.count === 1 ? data.see_one : data.see_all, tab.count, tab.label );
		}

		adjust( area );
	}

	function renderTabs( area, data ) {
		var old = area.querySelector( '.bit-tabs' );
		if ( old ) {
			old.remove();
		}
		area.bitTabs = data;
		area.setAttribute( 'data-bit-tab', '' );

		var bar = el( 'div', 'bit-tabs bit-tabs--compact' );
		var track = el( 'div', 'bit-tabs__track' );
		track.setAttribute( 'role', 'tablist' );
		track.setAttribute( 'aria-label', data.label );

		data.tabs.forEach( function ( tab ) {
			var b = el( 'button', 'bit-tabs__tab' );
			b.type = 'button';
			b.setAttribute( 'role', 'tab' );
			b.setAttribute( 'data-cat', tab.slug );
			b.setAttribute( 'aria-selected', tab.slug === '' ? 'true' : 'false' );
			b.appendChild( el( 'span', 'bit-tabs__label', tab.label ) );
			b.appendChild( el( 'span', 'bit-tabs__count', String( tab.count ) ) );
			if ( tab.count === 0 ) {
				b.disabled = true;
				b.setAttribute( 'aria-disabled', 'true' );
			}
			track.appendChild( b );
		} );
		bar.appendChild( track );

		var header = area.querySelector( '.jet-ajax-search__results-header' );
		var holder = area.querySelector( '.jet-ajax-search__results-holder' );
		if ( header ) {
			header.after( bar );
		} else if ( holder ) {
			holder.prepend( bar );
		}
		watchOverflow( bar );

		if ( ! area.bitTabsBound ) {
			area.bitTabsBound = true;
			area.addEventListener( 'click', function ( e ) {
				var b = e.target.closest( '.bit-tabs__tab' );
				if ( b && area.contains( b ) ) {
					e.preventDefault();
					if ( ! b.disabled ) {
						applyTab( area, b.getAttribute( 'data-cat' ) );
					}
					return;
				}
				// Numa aba, o rodapé leva à mesma aba em /busca/. Captura, para
				// chegar antes do handler do JetSearch no botão.
				var full = e.target.closest( '.jet-ajax-search__full-results' );
				var slug = area.getAttribute( 'data-bit-tab' );
				if ( full && slug && area.bitTabs ) {
					var tab = area.bitTabs.tabs.filter( function ( t ) { return t.slug === slug; } )[ 0 ];
					if ( tab && tab.url ) {
						e.preventDefault();
						e.stopImmediatePropagation();
						window.location.href = tab.url;
					}
				}
			}, true );
		}

		applyTab( area, '' );
	}

	// Esmaecimento nas bordas só quando há mais abas para aquele lado.
	function watchOverflow( bar ) {
		var track = bar.querySelector( '.bit-tabs__track' );
		function update() {
			var max = track.scrollWidth - track.clientWidth;
			bar.classList.toggle( 'has-more-right', max > 1 && track.scrollLeft < max - 1 );
			bar.classList.toggle( 'has-more-left', track.scrollLeft > 1 );
		}
		track.addEventListener( 'scroll', update, { passive: true } );
		window.addEventListener( 'resize', update );
		requestAnimationFrame( update );
	}

	if ( window.jQuery ) {
		window.jQuery( document ).on( 'ajaxSuccess', function ( e, xhr, opts, resp ) {
			if ( ! opts || ! opts.url || opts.url.indexOf( '/jet-search/v1/search-posts' ) < 0 ) {
				return;
			}
			var data = resp && ( resp.bit_tabs ? resp : resp.data );
			data = data && data.bit_tabs;
			if ( ! data || ! data.tabs || ! data.tabs.length ) {
				return;
			}
			requestAnimationFrame( function () {
				var term = String( data.term || '' ).trim().toLowerCase();
				document.querySelectorAll( '.jet-ajax-search' ).forEach( function ( widget ) {
					var field = widget.querySelector( '.jet-ajax-search__field' );
					var area = widget.querySelector( '.jet-ajax-search__results-area' );
					if ( area && field && field.value.trim().toLowerCase() === term ) {
						renderTabs( area, data );
					}
				} );
			} );
		} );
	}
} )();
JS;
	wp_add_inline_script( 'jet-search', $js );
}, 20 );

/**
 * No blog 2 a busca roda no endpoint do blog 1: estudos e páginas do blog 1 só
 * existem lá, e as fontes do Atlas trazem o blog 2. Mesma origem, sem CORS.
 */
add_filter( 'jet-ajax-search/assets/localize-data', function ( $data ) {
	if ( get_current_blog_id() === BIT_CROSSBLOG_SEARCH_MAIN_BLOG || empty( $data['rest_api_url'] ) ) {
		return $data;
	}

	if ( false !== strpos( (string) $data['rest_api_url'], '/jet-search/v1/search-posts' ) ) {
		$data['rest_api_url'] = get_rest_url( BIT_CROSSBLOG_SEARCH_MAIN_BLOG ) . 'jet-search/v1/search-posts';
	}

	return $data;
} );

/**
 * Painel do header (jet-hamburger-panel) cujo template não existe no blog atual:
 * renderiza o template do blog 1. É o caso do painel de busca (4360) nos
 * headers do /cultura/, que saía vazio.
 */
add_filter( 'elementor/widget/render_content', function ( $content, $widget ) {
	if ( get_current_blog_id() === BIT_CROSSBLOG_SEARCH_MAIN_BLOG
		|| 'jet-hamburger-panel' !== $widget->get_name()
		|| ! class_exists( '\Elementor\Plugin' ) ) {
		return $content;
	}

	$template_id = (int) $widget->get_settings( 'panel_template_id' );

	if ( ! $template_id || get_post( $template_id ) ) {
		return $content;
	}

	$empty = '<div class="jet-hamburger-panel__content" data-template-id="' . $template_id . '"></div>';

	if ( false === strpos( $content, $empty ) ) {
		return $content;
	}

	// O ID é usado como está, sem traduzir: é o que o blog 1 faz — o header EN
	// dele também aponta para 4360 e mostra o painel PT. A tradução 5638 ficou
	// para trás no visual (sem o box) e não é exibida em lugar nenhum.
	$html = bit_crossblog_search_in_blog( BIT_CROSSBLOG_SEARCH_MAIN_BLOG, function () use ( $template_id ) {
		if ( 'elementor_library' !== get_post_type( $template_id ) ) {
			return '';
		}

		return \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $template_id );
	} );

	if ( '' === $html ) {
		return $content;
	}

	return str_replace(
		$empty,
		'<div class="jet-hamburger-panel__content" data-template-id="' . $template_id . '">' . $html . '</div>',
		$content
	);
}, 10, 2 );

/**
 * Aviso no admin quando a integração com o JetSearch está desligada (Reflection
 * recusou a classe base) ou quando o JetSearch em execução não é a versão
 * validada. Só para quem pode agir (manage_options).
 */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'jet_search' ) ) {
		return;
	}

	$incompat = bit_crossblog_search_jetsearch_incompat();
	$version  = bit_crossblog_search_jetsearch_version();

	if ( '' !== $incompat ) {
		printf(
			'<div class="notice notice-error"><p><strong>Busca cross-blog:</strong> os blocos do Atlas Cultural foram desligados no dropdown da busca porque o JetSearch %1$s mudou uma interface da qual eles dependem (%2$s). O site segue no ar e a página /busca/ continua funcionando. Ajuste o mu-plugin bit-crossblog-search.php e rode testes/tests/13-busca-crossblog.spec.js.</p></div>',
			esc_html( $version ),
			esc_html( $incompat )
		);

		return;
	}

	if ( '' !== $version && version_compare( $version, BIT_CROSSBLOG_SEARCH_JETSEARCH_TESTED, '!=' ) ) {
		printf(
			'<div class="notice notice-warning"><p><strong>Busca cross-blog:</strong> o JetSearch está na %1$s e a integração foi validada na %2$s. A checagem automática não achou incompatibilidade, mas rode testes/tests/13-busca-crossblog.spec.js e, se passar, atualize BIT_CROSSBLOG_SEARCH_JETSEARCH_TESTED no mu-plugin.</p></div>',
			esc_html( $version ),
			esc_html( BIT_CROSSBLOG_SEARCH_JETSEARCH_TESTED )
		);
	}
} );

/* ──────────────────────────────────────────────────────────────────────────
 * Página de resultados completa — "Ver mais resultados"
 *
 * O botão e o Enter do widget levam a <search_results_url>?s=…&jet_ajax_search_settings=….
 * Sem URL configurada o destino era /?s=…, e isso não tem conserto na raiz:
 * o bit-disable-unused-archives redireciona toda busca nativa para a home
 * (armadilha de crawler), e em prod a cache policy padrão do CloudFront não
 * tem `s` na chave — /?s=x e / são a mesma entrada, então viria a home do
 * cache sem nem chegar ao PHP.
 *
 * Daí uma rota própria, /busca/ (e /en/busca/), que responde sempre no-store:
 * o CloudFront nunca guarda nada nessa chave, cada request chega à origem com
 * a query completa (a origin request policy repassa todas), e o WP Rocket não
 * cacheia. Nenhuma mudança de infra.
 *
 * A consulta reproduz a do dropdown — WP search com sentence=true nos mesmos
 * post types do widget — e acrescenta as seções do Atlas (blog 2).
 * ────────────────────────────────────────────────────────────────────────── */

/**
 * URL da página de resultados no idioma dado, sempre no blog 1.
 */
function bit_crossblog_search_results_url( string $lang = '' ): string {
	$base     = untrailingslashit( (string) get_blog_option( BIT_CROSSBLOG_SEARCH_MAIN_BLOG, 'home' ) );
	$settings = get_blog_option( BIT_CROSSBLOG_SEARCH_MAIN_BLOG, 'icl_sitepress_settings' );
	$default  = is_array( $settings ) ? ( $settings['default_language'] ?? '' ) : '';

	if ( '' !== $lang && '' !== $default && $lang !== $default ) {
		$base .= '/' . $lang;
	}

	return $base . '/' . BIT_CROSSBLOG_SEARCH_RESULTS_SLUG . '/';
}

/**
 * Textos da página por idioma. Poucos e fixos: não justificam String
 * Translation, e o idioma já vem da URL.
 */
function bit_crossblog_search_t( string $key ): string {
	static $strings = [
		'pt-br' => [
			'title'         => 'Resultados da busca',
			'label'         => 'Buscar no site',
			'placeholder'   => 'O que você procura?',
			'submit'        => 'Buscar',
			'empty'         => 'Digite um termo para buscar em estudos, páginas e no Atlas Cultural.',
			'none'          => 'Nenhum resultado para “%s”.',
			'summary_one'   => '1 resultado para “%2$s”',
			'summary_many'  => '%1$s resultados para “%2$s”',
			'main'          => 'Estudos, encontros e publicações',
			'showing'       => 'Mostrando %1$s de %2$s',
			'more_bit_atlas_artists' => 'Explorar o Atlas Cultural completo',
			'more_bit_participantes' => 'Ver todos os participantes',
			'prev'          => 'Anteriores',
			'next'          => 'Próximos',
			'page_of'       => 'Página %1$s de %2$s',
			'pagination'    => 'Paginação dos resultados',
			'all'           => 'Tudo',
			'tabs'          => 'Categorias de resultado',
			'see_all'       => 'Ver todos os %1$s em %2$s',
			'see_one'       => 'Ver o resultado em %2$s',
			'cat_summary_one'  => '1 resultado em %3$s para “%2$s”',
			'cat_summary_many' => '%1$s resultados em %3$s para “%2$s”',
			'capped'        => 'Mostrando os %1$s primeiros de %2$s. Refine a busca para ver os outros.',
		],
		'en'    => [
			'title'         => 'Search results',
			'label'         => 'Search the site',
			'placeholder'   => 'What are you looking for?',
			'submit'        => 'Search',
			'empty'         => 'Type a term to search studies, pages and the Cultural Atlas.',
			'none'          => 'No results for “%s”.',
			'summary_one'   => '1 result for “%2$s”',
			'summary_many'  => '%1$s results for “%2$s”',
			'main'          => 'Studies, meetings and publications',
			'showing'       => 'Showing %1$s of %2$s',
			'more_bit_atlas_artists' => 'Explore the full Cultural Atlas',
			'more_bit_participantes' => 'See all participants',
			'prev'          => 'Previous',
			'next'          => 'Next',
			'page_of'       => 'Page %1$s of %2$s',
			'pagination'    => 'Results pagination',
			'all'           => 'All',
			'tabs'          => 'Result categories',
			'see_all'       => 'See all %1$s in %2$s',
			'see_one'       => 'See the result in %2$s',
			'cat_summary_one'  => '1 result in %3$s for “%2$s”',
			'cat_summary_many' => '%1$s results in %3$s for “%2$s”',
			'capped'        => 'Showing the first %1$s of %2$s. Refine the search to see the rest.',
		],
	];

	// Idioma do request: no REST do dropdown vem do `lang` do JetSearch; na
	// página, do WPML.
	$lang = bit_crossblog_search_lang();
	$set  = $strings[ is_string( $lang ) && isset( $strings[ $lang ] ) ? $lang : 'pt-br' ];

	return $set[ $key ] ?? $key;
}

/**
 * Flag da rota /busca/. Não é query var registrada: se fosse pública,
 * /qualquer-pagina/?bit_busca=1 viraria a página de resultados.
 */
function bit_crossblog_search_is_results_page( ?bool $set = null ): bool {
	static $is = false;

	if ( null !== $set ) {
		$is = $set;
	}

	return $is;
}

/**
 * Prefixo de idioma aceito na rota: só idiomas ativos do WPML que não são o
 * padrão (hoje, "en"). Qualquer outro prefixo (/xx/busca/) não é a rota.
 */
function bit_crossblog_search_lang_prefix_pattern(): string {
	$languages = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
	$default   = (string) apply_filters( 'wpml_default_language', '' );
	$codes     = [];

	foreach ( is_array( $languages ) ? array_keys( $languages ) : [] as $code ) {
		if ( $code !== $default ) {
			$codes[] = preg_quote( (string) $code, '#' );
		}
	}

	return $codes ? '(?:(?:' . implode( '|', $codes ) . ')/)?' : '';
}

/**
 * A rota é reconhecida pelo path, depois de o WP montar as query vars — e não
 * por rewrite rule. Uma regra nova só vale depois de um flush, e o flush
 * preguiçoso roda DURANTE o primeiro request: esse request saía como home, o
 * WP Rocket cacheava a home em /busca/#s=<termo> e o nginx passava a servir o
 * arquivo errado (medido em dev, 25/09/2026). Aqui vale desde o primeiro hit.
 *
 * As query vars são trocadas inteiras por bit_busca: com `s` o WP marcaria
 * is_search() e o bit-disable-unused-archives redirecionaria para a home. O
 * termo é lido direto de $_GET na renderização. $wp->request já vem sem o
 * diretório de idioma quando o WPML o remove; o prefixo opcional cobre o caso
 * em que não remove.
 */
add_action( 'parse_request', function ( $wp ) {
	if ( get_current_blog_id() !== BIT_CROSSBLOG_SEARCH_MAIN_BLOG ) {
		return;
	}

	$prefix = bit_crossblog_search_lang_prefix_pattern();

	if ( preg_match( '#^' . $prefix . preg_quote( BIT_CROSSBLOG_SEARCH_RESULTS_SLUG, '#' ) . '/?$#', (string) $wp->request ) ) {
		bit_crossblog_search_is_results_page( true );
		$wp->query_vars   = [ 'bit_busca' => '1' ];
		$wp->matched_rule = '';

		return;
	}

	// Legado: o "Ver mais resultados" de uma página cujo HTML ficou em cache
	// antes desta versão (sem o script que aponta o widget para /busca/) ainda
	// manda para /?s=…&jet_ajax_search_settings=…. Se esse request chegar ao
	// PHP, vai para /busca/ em vez de cair no 301 para a home. Não cobre o hit
	// do CloudFront — lá /?s= tem a chave de / —, por isso o deploy limpa o
	// cache de HTML. Busca nativa pura (/?s= sem o parâmetro do JetSearch)
	// continua no 301 do bit-disable-unused-archives.
	if ( ( '' === (string) $wp->request || ( '' !== $prefix && preg_match( '#^' . $prefix . '$#', (string) $wp->request . '/' ) ) )
		&& isset( $_GET['s'], $_GET['jet_ajax_search_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$lang = (string) apply_filters( 'wpml_current_language', '' );
		$url  = add_query_arg( [ 's' => rawurlencode( sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) ) ], bit_crossblog_search_results_url( $lang ) ); // phpcs:ignore

		nocache_headers();
		wp_safe_redirect( $url, 302, 'bit-crossblog-search' );
		exit;
	}
}, 0 );

/**
 * A query principal da rota resolve como home (os 10 posts mais recentes) e
 * ninguém a usa: a página monta as próprias consultas. Curto-circuito.
 */
add_filter( 'posts_pre_query', function ( $posts, $query ) {
	if ( bit_crossblog_search_is_results_page() && $query->is_main_query() ) {
		$query->found_posts   = 0;
		$query->max_num_pages = 0;

		return [];
	}

	return $posts;
}, 10, 2 );

/**
 * Termo, post types, categoria e página vindos da URL, saneados. Post types:
 * só os da allowlist, mesmo que a URL peça outros (a URL é pública).
 * Categoria desconhecida vale "Tudo".
 *
 * @return array{term: string, types: string[], page: int, cat: string}
 */
function bit_crossblog_search_results_request(): array {
	$term = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$term = trim( mb_substr( $term, 0, 100 ) );

	// Os mesmos post types da lista principal do widget (search_source dos
	// templates 4360/5638). Notícias e eventos têm seção própria.
	$allowed   = (array) apply_filters( 'bit_crossblog_search/results_post_types', [ 'estudos', 'plenarias', 'releases', 'webinarios', 'page' ] );
	$requested = [];

	if ( isset( $_GET['jet_ajax_search_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$settings  = json_decode( wp_unslash( (string) $_GET['jet_ajax_search_settings'] ), true ); // phpcs:ignore
		$requested = is_array( $settings ) && isset( $settings['search_source'] ) ? array_map( 'sanitize_key', (array) $settings['search_source'] ) : [];
	}

	$types = array_values( array_intersect( $requested, $allowed ) );
	$cat   = isset( $_GET['cat'] ) ? sanitize_key( wp_unslash( (string) $_GET['cat'] ) ) : ''; // phpcs:ignore

	return [
		'term'  => $term,
		'types' => $types ?: array_values( $allowed ),
		'page'  => min( 500, max( 1, isset( $_GET['pg'] ) ? absint( $_GET['pg'] ) : 1 ) ), // phpcs:ignore
		'cat'   => isset( bit_crossblog_search_tabs()[ $cat ] ) ? $cat : '',
	];
}

/**
 * Link da página de resultados com termo, aba e página. add_query_arg() não
 * codifica: "P&D #1" virava s=P.
 */
function bit_crossblog_search_results_link( string $base, string $term, string $cat = '', int $page = 1 ): string {
	return add_query_arg( array_filter( [
		's'   => '' !== $term ? rawurlencode( $term ) : null,
		'cat' => '' !== $cat ? $cat : null,
		'pg'  => $page > 1 ? $page : null,
	] ), $base );
}

/**
 * Item da página no formato comum às três seções.
 *
 * @return array{title: string, url: string, thumb: string, text: string}
 */
function bit_crossblog_search_card( WP_Post $post, string $url, string $prefix = '' ): array {
	$text = bit_crossblog_search_excerpt( $post );

	return [
		'title' => get_the_title( $post ),
		'url'   => $url,
		'thumb' => (string) get_the_post_thumbnail( $post, 'thumbnail', [ 'class' => 'bit-busca__img', 'loading' => 'lazy', 'alt' => '' ] ),
		'text'  => '' !== $prefix ? $prefix . ( '' !== $text ? ' — ' . $text : '' ) : $text,
	];
}

function bit_crossblog_search_render_cards( array $cards ): string {
	$html = '<ul class="bit-busca__list">';

	foreach ( $cards as $card ) {
		$html .= '<li class="bit-busca__item"><a class="bit-busca__link" href="' . esc_url( $card['url'] ) . '">'
			. ( '' !== $card['thumb'] ? '<span class="bit-busca__thumb">' . $card['thumb'] . '</span>' : '' )
			. '<span class="bit-busca__body">'
			. '<span class="bit-busca__item-title">' . esc_html( $card['title'] ) . '</span>'
			. ( '' !== $card['text'] ? '<span class="bit-busca__text">' . esc_html( $card['text'] ) . '</span>' : '' )
			. '</span></a></li>';
	}

	return $html . '</ul>';
}

/**
 * Cache de 10 min (Redis em prod). Um termo amplo ("Amazônia") levava 3–4,5 s
 * em dev: sete consultas LIKE, cada uma contando o total. A página é no-store
 * no CloudFront, então sem isto cada repetição pagava o custo inteiro.
 * Conteúdo novo aparece em até 10 min.
 */
function bit_crossblog_search_cached( string $prefix, array $parts, callable $build ): array {
	$key    = 'bit_busca_' . $prefix . '_' . md5( wp_json_encode( array_merge( [
		(string) apply_filters( 'wpml_current_language', '' ),
		array_keys( bit_crossblog_search_sections() ),
		BIT_CROSSBLOG_SEARCH_VERSION,
	], $parts ) ) );
	$cached = get_transient( $key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$data = $build();
	set_transient( $key, $data, 10 * MINUTE_IN_SECONDS );

	return $data;
}

/**
 * Lista principal (blog 1): WP search em modo frase nos mesmos post types do
 * widget, paginada.
 */
function bit_crossblog_search_main_query( array $request, int $per_page, int $page ): array {
	$query = new WP_Query( [
		's'                   => $request['term'],
		'post_type'           => $request['types'],
		'post_status'         => 'publish',
		'sentence'            => true,
		'orderby'             => 'relevance',
		'posts_per_page'      => $per_page,
		'paged'               => $page,
		'ignore_sticky_posts' => true,
		'suppress_filters'    => false,
	] );

	return [
		'cards' => array_map( function ( $post ) {
			return bit_crossblog_search_card( $post, (string) get_permalink( $post ) );
		}, $query->posts ),
		'total' => (int) $query->found_posts,
		'pages' => (int) $query->max_num_pages,
	];
}

/**
 * Resumo de todas as categorias: é a aba "Tudo" e a fonte das contagens das
 * abas. A lista principal traz só os primeiros; as seções trazem até
 * BIT_CROSSBLOG_SEARCH_ATLAS_MAX, o que torna o total exato (sem as cópias)
 * sempre que a seção cabe nisso.
 *
 * @return array{main: array, sections: array, counts: array, total: int}
 */
function bit_crossblog_search_results_summary( array $request ): array {
	if ( '' === $request['term'] ) {
		return [ 'main' => null, 'sections' => [], 'counts' => [], 'total' => 0 ];
	}

	return bit_crossblog_search_cached( 'all', [ mb_strtolower( $request['term'] ), $request['types'] ], function () use ( $request ) {
		$data = [
			'main'     => bit_crossblog_search_main_query( $request, BIT_CROSSBLOG_SEARCH_ALL_PER_SECTION, 1 ),
			'sections' => [],
			'counts'   => [],
			'total'    => 0,
		];

		$data['counts']['estudos'] = $data['main']['total'];

		foreach ( array_keys( bit_crossblog_search_sections() ) as $key ) {
			$total = 0;
			$cards = bit_crossblog_search_section_items( $key, $request['term'], BIT_CROSSBLOG_SEARCH_ATLAS_MAX, 'page', [], $total );

			$data['sections'][ $key ] = [ 'cards' => $cards, 'total' => $total ];
			$data['counts'][ $key ]   = $total;
		}

		$data['total'] = array_sum( $data['counts'] );

		return $data;
	} );
}

/**
 * Uma aba: a lista completa daquela categoria, paginada. A lista principal
 * pagina no banco; as seções buscam até BIT_CROSSBLOG_SEARCH_SECTION_MAX,
 * deduplicam e paginam aqui (o cache é por aba, não por página).
 *
 * @return array{sections: array, total: int, pages: int, capped: bool}
 */
function bit_crossblog_search_results_tab( array $request ): array {
	$tab = bit_crossblog_search_tabs()[ $request['cat'] ];

	if ( $tab['main'] ) {
		$main = bit_crossblog_search_cached( 'main', [ mb_strtolower( $request['term'] ), $request['types'], $request['page'] ], function () use ( $request ) {
			return bit_crossblog_search_main_query( $request, BIT_CROSSBLOG_SEARCH_RESULTS_PER_PAGE, $request['page'] );
		} );

		return [ 'sections' => [ 'main' => $main ], 'total' => $main['total'], 'pages' => $main['pages'], 'capped' => false ];
	}

	$full = bit_crossblog_search_cached( 'cat', [ mb_strtolower( $request['term'] ), $request['cat'] ], function () use ( $request, $tab ) {
		$out = [];

		foreach ( $tab['sections'] as $key ) {
			$total       = 0;
			$cards       = bit_crossblog_search_section_items( $key, $request['term'], BIT_CROSSBLOG_SEARCH_SECTION_MAX, 'page', [], $total );
			$out[ $key ] = [ 'cards' => $cards, 'total' => $total ];
		}

		return $out;
	} );

	// Uma lista só, na ordem das seções, fatiada na página pedida; cada fatia
	// volta para a sua seção (os títulos continuam separando páginas e artistas).
	$flat = [];

	foreach ( $full as $key => $section ) {
		foreach ( $section['cards'] as $card ) {
			$flat[] = [ $key, $card ];
		}
	}

	$per      = BIT_CROSSBLOG_SEARCH_RESULTS_PER_PAGE;
	$slice    = array_slice( $flat, ( $request['page'] - 1 ) * $per, $per );
	$sections = [];

	foreach ( $slice as [ $key, $card ] ) {
		$sections[ $key ]['cards'][] = $card;
		$sections[ $key ]['total']   = $full[ $key ]['total'];
	}

	return [
		'sections' => $sections,
		'total'    => array_sum( array_column( $full, 'total' ) ),
		'pages'    => (int) ceil( count( $flat ) / $per ),
		'capped'   => array_sum( array_column( $full, 'total' ) ) > count( $flat ),
		'shown'    => count( $flat ),
	];
}

/**
 * Linha de abas da página. Links de verdade (?cat=), funcionam sem JS; aba
 * sem resultado fica esmaecida e não é link.
 */
function bit_crossblog_search_tabs_html( array $tabs, string $active, string $base, string $term ): string {
	$html = '<nav class="bit-tabs bit-busca__tabs" aria-label="' . esc_attr( bit_crossblog_search_t( 'tabs' ) ) . '"><div class="bit-tabs__track">';

	foreach ( $tabs as $tab ) {
		$inner = '<span class="bit-tabs__label">' . esc_html( $tab['label'] ) . '</span>'
			. '<span class="bit-tabs__count">' . esc_html( number_format_i18n( $tab['count'] ) ) . '</span>';

		if ( $tab['slug'] === $active ) {
			$html .= '<a class="bit-tabs__tab" aria-current="page" href="' . esc_url( bit_crossblog_search_results_link( $base, $term, $tab['slug'] ) ) . '">' . $inner . '</a>';
		} elseif ( 0 === $tab['count'] ) {
			$html .= '<span class="bit-tabs__tab" aria-disabled="true">' . $inner . '</span>';
		} else {
			$html .= '<a class="bit-tabs__tab" href="' . esc_url( bit_crossblog_search_results_link( $base, $term, $tab['slug'] ) ) . '">' . $inner . '</a>';
		}
	}

	return $html . '</div></nav>';
}

/**
 * Uma seção da página: título com total, cards e, opcionalmente, rodapé.
 */
function bit_crossblog_search_section_html( string $key, array $section, string $after = '' ): string {
	$title = 'main' === $key ? bit_crossblog_search_t( 'main' ) : bit_crossblog_search_section_title( $key );
	$slug  = 'main' === $key ? 'main' : str_replace( '_', '-', preg_replace( '/^bit_/', '', $key ) );

	return '<section class="bit-busca__section bit-busca__section--' . esc_attr( $slug ) . '">'
		. '<h2 class="bit-busca__section-title">' . esc_html( $title ) . ' <span class="bit-busca__count">' . esc_html( number_format_i18n( $section['total'] ) ) . '</span></h2>'
		. bit_crossblog_search_render_cards( $section['cards'] )
		. $after
		. '</section>';
}

function bit_crossblog_search_results_html( array $request, array $summary, ?array $tab ): string {
	$lang = (string) apply_filters( 'wpml_current_language', '' );
	$self = bit_crossblog_search_results_url( $lang );
	$html = '<main id="content" class="site-main bit-busca"><div class="bit-busca__inner">';

	$html .= '<h1 class="bit-busca__title">' . esc_html( bit_crossblog_search_t( 'title' ) ) . '</h1>';
	$html .= '<form class="bit-busca__form" role="search" method="get" action="' . esc_url( $self ) . '">'
		. '<label class="bit-busca__label screen-reader-text" for="bit-busca-s">' . esc_html( bit_crossblog_search_t( 'label' ) ) . '</label>'
		. '<input class="bit-busca__input" id="bit-busca-s" type="search" name="s" value="' . esc_attr( $request['term'] ) . '" placeholder="' . esc_attr( bit_crossblog_search_t( 'placeholder' ) ) . '">'
		// Nova busca continua na aba aberta, como no Google.
		. ( '' !== $request['cat'] ? '<input type="hidden" name="cat" value="' . esc_attr( $request['cat'] ) . '">' : '' )
		. '<button class="bit-busca__submit" type="submit">' . esc_html( bit_crossblog_search_t( 'submit' ) ) . '</button>'
		. '</form>';

	if ( '' === $request['term'] ) {
		return $html . '<p class="bit-busca__summary">' . esc_html( bit_crossblog_search_t( 'empty' ) ) . '</p></div></main>';
	}

	if ( 0 === $summary['total'] ) {
		return $html . '<p class="bit-busca__summary">' . esc_html( sprintf( bit_crossblog_search_t( 'none' ), $request['term'] ) ) . '</p></div></main>';
	}

	// Contagens: as do resumo; a da aba aberta, a da própria aba (mede mais
	// itens, então o total dela é o mais exato).
	$counts = $summary['counts'];

	if ( $tab ) {
		foreach ( bit_crossblog_search_tabs()[ $request['cat'] ]['sections'] ?: [ 'estudos' ] as $key ) {
			$counts[ $key ] = 'estudos' === $key ? $tab['total'] : (int) ( $tab['sections'][ $key ]['total'] ?? $counts[ $key ] ?? 0 );
		}
	}

	$tabs  = bit_crossblog_search_tab_list( $counts );
	$html .= bit_crossblog_search_tabs_html( $tabs, $request['cat'], $self, $request['term'] );

	if ( ! $tab ) {
		$html .= '<p class="bit-busca__summary">' . esc_html( sprintf( bit_crossblog_search_t( 1 === $summary['total'] ? 'summary_one' : 'summary_many' ), number_format_i18n( $summary['total'] ), $request['term'] ) ) . '</p>';

		// Tudo: os primeiros de cada categoria, e o caminho para a aba dela.
		foreach ( $tabs as $t ) {
			if ( '' === $t['slug'] || 0 === $t['count'] ) {
				continue;
			}

			$keys   = $t['sections'] ?: [ 'main' ];
			$last   = end( $keys );
			$shown  = 0;
			$blocks = '';

			foreach ( $keys as $key ) {
				$section = 'main' === $key ? $summary['main'] : $summary['sections'][ $key ];
				$cards   = array_slice( $section['cards'], 0, BIT_CROSSBLOG_SEARCH_ALL_PER_SECTION );

				if ( ! $cards ) {
					continue;
				}

				$shown  += count( $cards );
				$more    = '';

				if ( $key === $last && $t['count'] > $shown ) {
					$more = '<a class="bit-busca__see-all" href="' . esc_url( bit_crossblog_search_results_link( $self, $request['term'], $t['slug'] ) ) . '">'
						. esc_html( sprintf( bit_crossblog_search_t( 'see_all' ), number_format_i18n( $t['count'] ), $t['label'] ) )
						. ' <span aria-hidden="true">→</span></a>';
				}

				$blocks .= bit_crossblog_search_section_html( $key, [ 'cards' => $cards, 'total' => $section['total'] ], $more );
			}

			// Link para a aba mesmo quando a última seção do grupo veio vazia.
			if ( $t['count'] > $shown && false === strpos( $blocks, 'bit-busca__see-all' ) ) {
				$blocks .= '<p><a class="bit-busca__see-all" href="' . esc_url( bit_crossblog_search_results_link( $self, $request['term'], $t['slug'] ) ) . '">'
					. esc_html( sprintf( bit_crossblog_search_t( 'see_all' ), number_format_i18n( $t['count'] ), $t['label'] ) ) . ' <span aria-hidden="true">→</span></a></p>';
			}

			$html .= '<div class="bit-busca__group bit-busca__group--' . esc_attr( $t['slug'] ) . '">' . $blocks . '</div>';
		}

		return $html . '</div></main>';
	}

	// Uma aba: lista completa, paginada.
	$label  = bit_crossblog_search_tab_label( $request['cat'] );
	$html  .= '<p class="bit-busca__summary">' . esc_html( sprintf( bit_crossblog_search_t( 1 === $tab['total'] ? 'cat_summary_one' : 'cat_summary_many' ), number_format_i18n( $tab['total'] ), $request['term'], $label ) ) . '</p>';
	$config = bit_crossblog_search_sections();

	foreach ( $tab['sections'] as $key => $section ) {
		$html .= bit_crossblog_search_section_html( $key, $section );
	}

	if ( $tab['pages'] > 1 ) {
		$link  = function ( int $page ) use ( $self, $request ) {
			return bit_crossblog_search_results_link( $self, $request['term'], $request['cat'], $page );
		};
		$html .= '<nav class="bit-busca__pagination" aria-label="' . esc_attr( bit_crossblog_search_t( 'pagination' ) ) . '">';
		$html .= $request['page'] > 1 ? '<a class="bit-busca__page-link" href="' . esc_url( $link( $request['page'] - 1 ) ) . '">' . esc_html( bit_crossblog_search_t( 'prev' ) ) . '</a>' : '<span></span>';
		$html .= '<span class="bit-busca__page-status">' . esc_html( sprintf( bit_crossblog_search_t( 'page_of' ), number_format_i18n( $request['page'] ), number_format_i18n( $tab['pages'] ) ) ) . '</span>';
		$html .= $request['page'] < $tab['pages'] ? '<a class="bit-busca__page-link" href="' . esc_url( $link( $request['page'] + 1 ) ) . '">' . esc_html( bit_crossblog_search_t( 'next' ) ) . '</a>' : '<span></span>';
		$html .= '</nav>';
	}

	if ( ! empty( $tab['capped'] ) && $request['page'] >= $tab['pages'] ) {
		$html .= '<p class="bit-busca__more">' . esc_html( sprintf( bit_crossblog_search_t( 'capped' ), number_format_i18n( $tab['shown'] ), number_format_i18n( $tab['total'] ) ) ) . '</p>';
	}

	// Links para fora (Atlas completo, página de participantes), no fim da aba.
	foreach ( bit_crossblog_search_tabs()[ $request['cat'] ]['sections'] as $key ) {
		if ( isset( $config[ $key ]['more'] ) && is_callable( $config[ $key ]['more'] ) && ! empty( $tab['sections'] ) ) {
			$more = (string) call_user_func( $config[ $key ]['more'], $lang );

			if ( '' !== $more ) {
				$html .= '<p class="bit-busca__more"><a href="' . esc_url( $more ) . '">' . esc_html( bit_crossblog_search_t( 'more_' . $key ) ) . '</a></p>';
			}
		}
	}

	return $html . '</div></main>';
}

/**
 * Em duas etapas no template_redirect:
 *
 * - prioridade 0: prepara a query antes de todo mundo. Sem as flags de home o
 *   tema e o Elementor tratariam a página como front page, e o
 *   redirect_canonical (prioridade 10) mandaria /busca/ para a home.
 * - prioridade 999: renderiza e encerra. Tem de ser DEPOIS do Frontend::init()
 *   do Elementor (template_redirect, 10): saindo antes, o header/footer do
 *   Elementor Pro sai sem o elementorFrontendConfig e o JS do front quebra.
 *
 * O bit-disable-unused-archives (prioridade 1) não interfere: com as query
 * vars trocadas não há is_search() nem outro arquivo que ele redirecione.
 */
add_action( 'template_redirect', function () {
	if ( ! bit_crossblog_search_is_results_page() ) {
		return;
	}

	remove_action( 'template_redirect', 'redirect_canonical' );

	global $wp_query;

	// A query principal (só bit_busca) resolve como home; sem isto o tema e
	// o Elementor tratariam a página como front page (CSS da home, condições).
	$wp_query->is_home    = false;
	$wp_query->is_archive = false;
	$wp_query->is_404     = false;
}, 0 );

add_action( 'template_redirect', function () {
	if ( ! bit_crossblog_search_is_results_page() ) {
		return;
	}

	$request = bit_crossblog_search_results_request();

	// "Tudo" não pagina: ?pg= sem aba é resto de link antigo (a lista principal
	// paginava em "Tudo" até a 1.4) — vai para a aba Estudos, que é o que ele
	// paginava.
	if ( '' === $request['cat'] && $request['page'] > 1 && '' !== $request['term'] ) {
		$request['cat'] = 'estudos';
	}

	$summary = bit_crossblog_search_results_summary( $request );
	$tab     = '' !== $request['cat'] && '' !== $request['term'] ? bit_crossblog_search_results_tab( $request ) : null;

	// pg além da última página da aba: volta para a primeira dela.
	if ( $tab && $request['page'] > 1 && $request['page'] > $tab['pages'] ) {
		nocache_headers();
		wp_safe_redirect( bit_crossblog_search_results_link( bit_crossblog_search_results_url( (string) apply_filters( 'wpml_current_language', '' ) ), $request['term'], $request['cat'] ), 302, 'bit-crossblog-search' );
		exit;
	}

	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}

	status_header( 200 );
	nocache_headers();
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
	header( 'X-Robots-Tag: noindex, follow' );

	$title = bit_crossblog_search_t( 'title' ) . ( '' !== $request['term'] ? ': ' . $request['term'] : '' ) . ( $tab ? ' · ' . bit_crossblog_search_tab_label( $request['cat'] ) : '' );

	add_filter( 'pre_get_document_title', function () use ( $title ) {
		return $title . ' – ' . get_bloginfo( 'name' );
	}, 99 );
	add_filter( 'wpseo_title', function () use ( $title ) {
		return $title . ' – ' . get_bloginfo( 'name' );
	}, 99 );
	add_filter( 'wpseo_robots', function () {
		return 'noindex, follow';
	}, 99 );
	add_filter( 'wpseo_canonical', '__return_false', 99 );
	add_filter( 'wp_robots', function ( $robots ) {
		return [ 'noindex' => true, 'follow' => true ] + $robots;
	}, 99 );
	add_filter( 'body_class', function ( $classes ) {
		return array_merge( array_diff( $classes, [ 'home', 'blog' ] ), [ 'bit-busca-page' ] );
	} );

	// Seletor de idioma: a mesma busca no outro idioma, não a home dele.
	add_filter( 'icl_ls_languages', function ( $languages ) use ( $request ) {
		foreach ( $languages as $code => $language ) {
			$languages[ $code ]['url'] = bit_crossblog_search_results_link( bit_crossblog_search_results_url( (string) $code ), $request['term'], $request['cat'] );
		}

		return $languages;
	}, 99 );

	get_header();
	echo bit_crossblog_search_results_html( $request, $summary, $tab ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado na montagem
	// Esmaecimento nas bordas da linha de abas (só do lado em que há mais) e
	// a aba ativa à vista no celular. Próprio, e não o do dropdown: o script
	// do JetSearch pode vir atrasado pelo "delay JS" do WP Rocket.
	echo '<script>(function(){var n=document.querySelector(".bit-busca__tabs");if(!n)return;var t=n.querySelector(".bit-tabs__track"),a=n.querySelector("[aria-current]");function u(){var m=t.scrollWidth-t.clientWidth;n.classList.toggle("has-more-right",m>1&&t.scrollLeft<m-1);n.classList.toggle("has-more-left",t.scrollLeft>1);}if(a&&t.scrollWidth>t.clientWidth)t.scrollLeft=a.offsetLeft-16;t.addEventListener("scroll",u,{passive:true});window.addEventListener("resize",u);u();})();</script>';
	get_footer();
	exit;
}, 999 );

/**
 * O schema do Yoast anuncia uma SearchAction em /?s={search_term_string} —
 * que em prod devolve a home do cache do CloudFront. Aponta para /busca/.
 */
add_filter( 'wpseo_json_ld_search_url', function ( $url ) {
	if ( get_current_blog_id() !== BIT_CROSSBLOG_SEARCH_MAIN_BLOG ) {
		return $url;
	}

	return bit_crossblog_search_results_url( (string) apply_filters( 'wpml_current_language', '' ) ) . '?s={search_term_string}';
} );

/**
 * /busca/ nunca é cacheada e cada hit roda três buscas LIKE: é a mesma classe
 * de armadilha de crawler que motivou o bit-disable-unused-archives. O
 * noindex impede indexar, não impede rastrear; o Disallow impede.
 */
add_filter( 'robots_txt', function ( $output, $public ) {
	if ( ! $public || get_current_blog_id() !== BIT_CROSSBLOG_SEARCH_MAIN_BLOG ) {
		return $output;
	}

	$rules = "Disallow: /" . BIT_CROSSBLOG_SEARCH_RESULTS_SLUG . "/\nDisallow: /*/" . BIT_CROSSBLOG_SEARCH_RESULTS_SLUG . "/\n";

	// Dentro do bloco "User-agent: *" que já existe (o do Yoast), senão no fim.
	if ( preg_match( '/^User-agent: \*\s*$/mi', $output ) ) {
		return preg_replace( '/^(User-agent: \*\s*\n)/mi', '$1' . $rules, $output, 1 );
	}

	return rtrim( $output ) . "\nUser-agent: *\n" . $rules;
}, 99, 2 );

/**
 * Aponta o "Ver mais resultados" (e o Enter) do widget para /busca/ no idioma
 * da página. Feito no navegador, antes de o JetSearch ler o data-settings: o
 * HTML do widget pode vir do cache de elemento do Elementor, que é por post e
 * não por idioma — uma URL gravada no servidor serviria /busca/ nas páginas EN.
 * Widget com URL própria configurada no Elementor é respeitado.
 */
add_action( 'wp_enqueue_scripts', function () {
	$url = bit_crossblog_search_results_url( (string) apply_filters( 'wpml_current_language', '' ) );
	$js  = 'document.querySelectorAll(".jet-ajax-search[data-settings]").forEach(function(el){try{var s=JSON.parse(el.getAttribute("data-settings"));if(!s.search_results_url){s.search_results_url=' . wp_json_encode( $url ) . ';el.setAttribute("data-settings",JSON.stringify(s));}}catch(e){}});';

	wp_add_inline_script( 'jet-search', $js );
}, 21 );

/**
 * No Atlas (blog 2), abre o popup do artista pedido em #atlas-artista-<ID>.
 *
 * Usa a API pública do JetEngine Maps, window.JetEngineMaps.openMapListingPopup
 * — a mesma do "abrir popup do mapa" das listagens —, que já trata marker
 * dentro de cluster (abre o cluster e centraliza). SEM scroll_to_map: com
 * marker em cluster o JetEngine 3.8.15 chama getContainer(undefined) e quebra
 * (medido em 25/09/2026); a rolagem até o mapa é feita aqui.
 *
 * Os markers entram de forma assíncrona depois do load. Espera até 20s.
 * Artista sem coordenada não tem marker (655 de 660 têm): cai na listagem
 * (#artista-mapa). Também reage a hashchange — busca feita no próprio Atlas.
 * O script só age quando o fragmento casa; nas outras páginas é inerte.
 */
add_action( 'wp_footer', function () {
	if ( get_current_blog_id() !== BIT_CROSSBLOG_SEARCH_ATLAS_BLOG ) {
		return;
	}
	?>
<script id="bit-atlas-artista-popup">
( function () {
	function openFromHash() {
		var match = /^#atlas-artista-(\d+)$/.exec( window.location.hash );
		if ( ! match ) {
			return;
		}
		var id = parseInt( match[ 1 ], 10 );
		var started = Date.now();

		// Não fecha popup aberto: o botão de fechar do Leaflet é um
		// <a href="#close"> sem preventDefault, e clicá-lo trocaria o
		// fragmento da URL. Popup de outro artista aberto só acontece trocando
		// o fragmento na mesma página — e o widget abre resultados em nova aba.
		function fallback() {
			var list = document.getElementById( 'artista-mapa' );
			if ( list ) {
				list.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		}

		( function wait() {
			var maps = window.JetEngineMaps;
			var ready = maps && maps.markersData && Object.keys( maps.markersData ).length > 0;
			if ( ! ready ) {
				if ( Date.now() - started < 20000 ) {
					return setTimeout( wait, 250 );
				}
				return fallback();
			}
			if ( ! maps.markersData[ id ] || typeof maps.openMapListingPopup !== 'function' ) {
				return fallback();
			}
			var map = document.querySelector( '.elementor-widget-jet-engine-maps-listing' );
			if ( map ) {
				map.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
			try {
				maps.openMapListingPopup( { id: id, zoom: 12 } );
			} catch ( e ) {
				fallback();
			}
		} )();
	}

	if ( document.readyState === 'complete' ) {
		openFromHash();
	} else {
		window.addEventListener( 'load', openFromHash );
	}
	window.addEventListener( 'hashchange', openFromHash );
} )();
</script>
	<?php
}, 99 );
