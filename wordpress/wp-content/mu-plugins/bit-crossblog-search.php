<?php
/**
 * Plugin Name: BIT Cross-Blog Search
 * Description: Estende o Ajax Search do JetSearch para buscar nos dois blogs do
 *              multisite. Registra fontes adicionais com conteúdo do Atlas Cultural
 *              (blog 2: páginas, Linha das Artes, artistas), aponta a busca do
 *              /cultura/ para o endpoint do blog 1 e renderiza no header do blog 2
 *              o painel de busca cujo template só existe no blog 1.
 * Version: 1.1.0
 * Author: Bureau de Tecnologia
 *
 * Por que fontes adicionais, e não a lista principal: o JetSearch descarta da
 * lista principal todo post cujo tipo não é registrado e "viewable" no blog que
 * atende o request (Jet_Search_Tools::filter_public_search_query_results), e gera
 * link/thumbnail no blog atual. As fontes (jet-search/sources/register) rendem o
 * próprio bloco e não passam por esse filtro.
 */

if ( ! defined( 'ABSPATH' ) || ! is_multisite() ) {
	return;
}

const BIT_CROSSBLOG_SEARCH_MAIN_BLOG  = 1;
const BIT_CROSSBLOG_SEARCH_ATLAS_BLOG = 2;
const BIT_CROSSBLOG_SEARCH_ATLAS_PAGE = 57548; // "Atlas Cultural das Amazônias" no blog 2 (PT)

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
 * @return array<int, array>
 */
function bit_crossblog_search_posts( int $blog_id, array $post_types, string $search, int $limit, callable $build_item ): array {
	$search = trim( $search );

	if ( '' === $search || $limit < 1 ) {
		return [];
	}

	return bit_crossblog_search_in_blog( $blog_id, function ( string $lang ) use ( $post_types, $search, $limit, $build_item ) {
		$query_args = [
			's'                   => $search,
			'post_type'           => $post_types,
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'suppress_filters'    => false,
			'search_columns'      => [ 'post_title', 'post_excerpt', 'post_content' ],
		];

		// Depois do switch_to_blog o WPML segue com a configuração do blog de
		// origem e ignora `lang` (PT e EN vinham misturados). O filtro de idioma
		// é feito aqui, direto na icl_translations do blog alvo.
		$lang_clauses = null;

		if ( '' !== $lang ) {
			$lang_clauses = function ( array $clauses, WP_Query $query ) use ( $lang ) {
				global $wpdb;

				if ( ! $query->get( 'bit_crossblog_search' ) ) {
					return $clauses;
				}

				$clauses['join']  .= " INNER JOIN {$wpdb->prefix}icl_translations bit_cbs_t"
					. " ON bit_cbs_t.element_id = {$wpdb->posts}.ID"
					. " AND bit_cbs_t.element_type = CONCAT('post_', {$wpdb->posts}.post_type)";
				$clauses['where'] .= $wpdb->prepare( ' AND bit_cbs_t.language_code = %s', $lang );

				return $clauses;
			};

			add_filter( 'posts_clauses', $lang_clauses, 99, 2 );
		}

		try {
			$query = new WP_Query( apply_filters( 'bit_crossblog_search/query_args', $query_args + [ 'bit_crossblog_search' => true ], $post_types ) );
		} finally {
			if ( $lang_clauses ) {
				remove_filter( 'posts_clauses', $lang_clauses, 99 );
			}
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
 * URL do Atlas no idioma do request, com âncora na listagem de artistas.
 * Artistas não têm página própria (CPT não público): o resultado leva ao Atlas.
 */
function bit_crossblog_search_atlas_url( string $lang ): string {
	$url = get_permalink( bit_crossblog_search_translation_id( BIT_CROSSBLOG_SEARCH_ATLAS_PAGE, $lang ) );

	return $url ? $url . '#artista-mapa' : '';
}

add_action( 'jet-search/sources/register', function ( $manager ) {
	if ( ! class_exists( '\Jet_Search\Search_Sources\Base' ) || class_exists( 'BIT_Crossblog_Search_Source', false ) ) {
		return;
	}

	/**
	 * Fonte base: posts de outro blog. As filhas definem blog, tipos e URL.
	 */
	abstract class BIT_Crossblog_Search_Source extends \Jet_Search\Search_Sources\Base {

		abstract protected function blog_id(): int;

		abstract protected function post_types(): array;

		public function url_for( WP_Post $post, string $lang ): string {
			return bit_crossblog_search_permalink( $post, $lang );
		}

		/**
		 * Texto do item. Padrão: o mesmo resumo dos itens de post, com a fonte
		 * e o tamanho configurados no widget (post_content_source/length).
		 */
		protected function item_content( WP_Post $post ): string {
			return (string) \Jet_Search_Template_Functions::get_post_content( $this->args, $post );
		}

		/**
		 * Monta o item no blog de origem: título, link, thumbnail e resumo.
		 * Thumbnail e resumo usam os helpers do próprio JetSearch com os
		 * settings do widget, para o item sair igual aos de post. A imagem
		 * de um post do blog 2 vive no blog 1 (Network Media Library); quem
		 * resolve é o bit-crossblog-attachment-fix, que age com o blog 2 ativo.
		 */
		public function build_item( WP_Post $post, string $lang ): ?array {
			$url = $this->url_for( $post, $lang );

			if ( '' === $url ) {
				return null;
			}

			return [
				'name'      => esc_html( get_the_title( $post ) ),
				'url'       => esc_url( $url ),
				'thumbnail' => (string) \Jet_Search_Template_Functions::get_post_thumbnail( $this->args, $post ),
				'content'   => $this->item_content( $post ),
			];
		}

		public function get_query_result( $limit = null ) {
			return bit_crossblog_search_posts(
				$this->blog_id(),
				$this->post_types(),
				(string) $this->search_string,
				(int) ( $limit ?? $this->limit ),
				[ $this, 'build_item' ]
			);
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

			$name   = $this->get_name();
			$title  = $this->args[ 'search_source_' . $name . '_title' ] ?? '';
			$target = ! empty( $this->args['show_result_new_tab'] ) && filter_var( $this->args['show_result_new_tab'], FILTER_VALIDATE_BOOLEAN ) ? ' target="_blank"' : '';
			$html   = '';

			foreach ( $this->items_list as $item ) {
				$content = '' !== $item['content'] ? '<div class="jet-ajax-search__item-content">' . wp_kses_post( $item['content'] ) . '</div>' : '';

				$html .= '<div class="jet-ajax-search__results-item">'
					. '<a class="jet-ajax-search__item-link" href="' . $item['url'] . '"' . $target . '>'
					. wp_kses_post( $item['thumbnail'] )
					. '<div class="jet-ajax-search__item-content-wrapper">'
					. '<div class="jet-ajax-search__item-title">' . $item['name'] . '</div>'
					. $content
					. '</div></a></div>';
			}

			return '<div class="jet-ajax-search__source-results-holder jet-ajax-search__source-results-holder_' . esc_attr( $name ) . '">'
				. '<div class="jet-ajax-search__source-results-holder-title">' . wp_kses_post( $title ) . '</div>'
				. $html
				. '</div>';
		}

		/**
		 * Título do bloco por idioma. Os headers EN dos dois blogs mostram o
		 * template PT (4360), então o título do widget sairia em português.
		 * Idioma sem entrada aqui usa o título configurado no widget.
		 *
		 * @return array<string, string>
		 */
		protected function titles(): array {
			return [];
		}

		public function build_items_list() {
			$items = apply_filters( 'bit_crossblog_search/' . $this->get_name() . '/items', $this->get_query_result() );

			$this->items_list    = $items;
			$this->results_count = count( $items );

			$titles = apply_filters( 'bit_crossblog_search/' . $this->get_name() . '/titles', $this->titles() );
			$lang   = bit_crossblog_search_lang();

			if ( isset( $titles[ $lang ] ) ) {
				$this->args[ 'search_source_' . $this->get_name() . '_title' ] = $titles[ $lang ];
			}
		}
	}

	$manager->register_source( new class() extends BIT_Crossblog_Search_Source {
		protected $source_name = 'bit_atlas_pages';

		public function get_label() {
			return 'Atlas Cultural (páginas)';
		}

		// O JS insere cada fonte "depois dos posts" com .after() colado na
		// lista: a de maior prioridade é inserida por último e fica em cima.
		// Páginas = 2 para aparecer antes dos artistas.
		public function get_priority() {
			return 2;
		}

		protected function blog_id(): int {
			return BIT_CROSSBLOG_SEARCH_ATLAS_BLOG;
		}

		protected function post_types(): array {
			return [ 'page', 'linha-das-artes' ];
		}

		protected function titles(): array {
			return [ 'en' => 'Cultural Atlas: pages' ];
		}
	} );

	$manager->register_source( new class() extends BIT_Crossblog_Search_Source {
		protected $source_name = 'bit_atlas_artists';

		public function get_label() {
			return 'Atlas Cultural (artistas)';
		}

		public function get_priority() {
			return 1;
		}

		protected function blog_id(): int {
			return BIT_CROSSBLOG_SEARCH_ATLAS_BLOG;
		}

		protected function post_types(): array {
			return [ 'artistas' ];
		}

		protected function titles(): array {
			return [ 'en' => 'Cultural Atlas: artists' ];
		}

		public function url_for( WP_Post $post, string $lang ): string {
			return bit_crossblog_search_atlas_url( $lang );
		}

		// "Fotografia · Palmas, Tocantins — <bio>": o que situa o artista no
		// Atlas vem antes da bio. Artista não tem imagem destacada (0 de 1.311).
		protected function item_content( WP_Post $post ): string {
			$place = implode( ', ', array_filter( [ get_post_meta( $post->ID, 'cidade', true ), get_post_meta( $post->ID, 'estado', true ) ] ) );
			$meta  = implode( ' · ', array_filter( [ get_post_meta( $post->ID, 'tema', true ), $place ] ) );
			$bio   = parent::item_content( $post );

			if ( '' === $meta ) {
				return $bio;
			}

			return esc_html( $meta ) . ( '' !== $bio ? ' — ' . $bio : '' );
		}
	} );
} );

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
	var SEL = '[class*="jet-ajax-search__source-results-holder_bit_atlas_"]';

	function adjust( area ) {
		var list = area.querySelector( '.jet-ajax-search__results-list' );
		var holder = area.querySelector( '.jet-ajax-search__results-holder' );
		if ( ! list || ! holder ) {
			return;
		}
		var slides = list.querySelectorAll( '.jet-ajax-search__results-slide' );
		var slide = slides.length ? slides[ slides.length - 1 ] : null;
		var loose = list.querySelectorAll( ':scope > ' + SEL + ', :scope > .jet-ajax-search__results-list-inner > ' + SEL );
		if ( slide && loose.length ) {
			loose.forEach( function ( block ) {
				slide.appendChild( block );
			} );
		}
		holder.style.maxHeight = '';
		holder.style.overflowY = '';
		if ( slide ) {
			// O max-height do slide (widget) ignora a janela: no mobile o
			// dropdown passava do rodapé da tela e o fim da lista não era
			// alcançável. Limita à altura que sobra abaixo do slide.
			slide.style.maxHeight = '';
			var footer = area.querySelector( '.jet-ajax-search__results-footer' );
			var below = footer ? footer.offsetHeight : 0;
			var space = window.innerHeight - slide.getBoundingClientRect().top - below - 12;
			if ( space >= 160 && slide.offsetHeight > space ) {
				slide.style.maxHeight = space + 'px';
			}
			if ( list.style.height ) {
				list.style.height = slide.offsetHeight + 'px';
			}
			return;
		}
		if ( ! holder.querySelector( SEL ) ) {
			return;
		}
		var room = window.innerHeight - holder.getBoundingClientRect().top - 16;
		if ( room > 160 && holder.scrollHeight > room ) {
			holder.style.maxHeight = room + 'px';
			holder.style.overflowY = 'auto';
		}
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
			} ).observe( area, { childList: true, subtree: true } );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', watch );
	} else {
		watch();
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
