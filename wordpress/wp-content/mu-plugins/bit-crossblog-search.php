<?php
/**
 * Plugin Name: BIT Cross-Blog Search
 * Description: Estende o Ajax Search do JetSearch para buscar nos dois blogs do
 *              multisite. Registra fontes adicionais com conteúdo do Atlas Cultural
 *              (blog 2: páginas, Linha das Artes, artistas), aponta a busca do
 *              /cultura/ para o endpoint do blog 1, renderiza no header do blog 2
 *              o painel de busca cujo template só existe no blog 1 e serve a
 *              página de resultados completa em /busca/ (e /en/busca/).
 * Version: 1.2.0
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
function bit_crossblog_search_posts( int $blog_id, array $post_types, string $search, int $limit, callable $build_item, ?int &$total = null ): array {
	// Total só quando o chamador pede (a página de resultados); o dropdown
	// dispensa o SQL_CALC_FOUND_ROWS.
	$count  = func_num_args() > 5;
	$search = trim( $search );

	if ( '' === $search || $limit < 1 ) {
		return [];
	}

	return bit_crossblog_search_in_blog( $blog_id, function ( string $lang ) use ( $post_types, $search, $limit, $build_item, $count, &$total ) {
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
 * URL do Atlas no idioma do request, com âncora na listagem de artistas.
 * Artistas não têm página própria (CPT não público): o resultado leva ao Atlas.
 */
function bit_crossblog_search_atlas_url( string $lang ): string {
	$url = get_permalink( bit_crossblog_search_translation_id( BIT_CROSSBLOG_SEARCH_ATLAS_PAGE, $lang ) );

	return $url ? $url . '#artista-mapa' : '';
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
 * Thumbnail e resumo dos itens do dropdown. Os helpers do JetSearch aplicam os
 * settings do widget (tamanho, fonte e comprimento do resumo), e é por isso que
 * são a primeira escolha — mas são internos: se um update os remover ou mudar,
 * cai para o equivalente do core em vez de dar fatal.
 */
function bit_crossblog_search_item_thumbnail( array $args, WP_Post $post ): string {
	if ( is_callable( [ '\Jet_Search_Template_Functions', 'get_post_thumbnail' ] ) ) {
		return (string) \Jet_Search_Template_Functions::get_post_thumbnail( $args, $post );
	}

	$html = get_the_post_thumbnail( $post, 'thumbnail', [ 'class' => 'jet-ajax-search__item-thumbnail-img' ] );

	return $html ? '<div class="jet-ajax-search__item-thumbnail">' . $html . '</div>' : '';
}

function bit_crossblog_search_item_content( array $args, WP_Post $post ): string {
	if ( is_callable( [ '\Jet_Search_Template_Functions', 'get_post_content' ] ) ) {
		return (string) \Jet_Search_Template_Functions::get_post_content( $args, $post );
	}

	return esc_html( bit_crossblog_search_excerpt( $post, 25 ) );
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
		foreach ( [ 'blog_id', 'post_types', 'url_for', 'item_content', 'build_item', 'titles' ] as $name ) {
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

add_action( 'jet-search/sources/register', function ( $manager ) {
	if ( class_exists( 'BIT_Crossblog_Search_Source', false ) || ! is_object( $manager ) || ! method_exists( $manager, 'register_source' ) ) {
		return;
	}

	$incompat = bit_crossblog_search_jetsearch_incompat();

	if ( '' !== $incompat ) {
		error_log( '[bit-crossblog-search] fontes do Atlas desligadas — JetSearch ' . bit_crossblog_search_jetsearch_version() . ': ' . $incompat );

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
			return bit_crossblog_search_item_content( $this->args, $post );
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
				'thumbnail' => bit_crossblog_search_item_thumbnail( $this->args, $post ),
				'content'   => $this->item_content( $post ),
			];
		}

		public function get_query_result( $limit = null ) {
			return bit_crossblog_search_posts(
				$this->blog_id(),
				$this->post_types(),
				// set_search_string() do JetSearch aplica esc_sql(); o WP_Query
				// escapa de novo, e "d'água" viraria busca por "d\'água".
				stripslashes( (string) $this->search_string ),
				// O limite vem de data[search_source_<nome>_limit], do request
				// público (Base::set_args_limit). Sem teto, um GET anônimo pedia
				// 5000 e levava os 658 artistas (292 KB) — e /wp-json/ não é
				// cacheado no CloudFront. Revisão de 25/09/2026.
				min( max( (int) ( $limit ?? $this->limit ), 1 ), BIT_CROSSBLOG_SEARCH_ATLAS_MAX ),
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

	// Última rede: qualquer erro ao instanciar vira log, não fatal no init.
	try {
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
			$meta = bit_crossblog_search_artist_meta( $post );
			$bio  = parent::item_content( $post );

			if ( '' === $meta ) {
				return $bio;
			}

			return esc_html( $meta ) . ( '' !== $bio ? ' — ' . $bio : '' );
		}
	} );
	} catch ( \Throwable $e ) {
		error_log( '[bit-crossblog-search] fontes do Atlas não registradas: ' . $e->getMessage() );
	}
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
			var space = Math.floor( window.innerHeight - active.getBoundingClientRect().top - below - 12 );
			slides.forEach( function ( slide ) {
				setStyle( slide, 'maxHeight', space >= 160 && slide.scrollHeight > space ? space + 'px' : '' );
			} );
			if ( list.style.height ) {
				setStyle( list, 'height', active.offsetHeight + 'px' );
			}
			return;
		}
		var capped = false;
		if ( holder.querySelector( SEL ) ) {
			var room = Math.floor( window.innerHeight - holder.getBoundingClientRect().top - 16 );
			capped = room > 160 && holder.scrollHeight > room;
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
			'main'          => 'Estudos e páginas',
			'atlas_pages'   => 'Atlas Cultural: páginas',
			'atlas_artists' => 'Atlas Cultural: artistas',
			'showing'       => 'Mostrando %1$s de %2$s',
			'atlas_all'     => 'Explorar o Atlas Cultural completo',
			'prev'          => 'Anteriores',
			'next'          => 'Próximos',
			'page_of'       => 'Página %1$s de %2$s',
			'pagination'    => 'Paginação dos resultados',
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
			'main'          => 'Studies and pages',
			'atlas_pages'   => 'Cultural Atlas: pages',
			'atlas_artists' => 'Cultural Atlas: artists',
			'showing'       => 'Showing %1$s of %2$s',
			'atlas_all'     => 'Explore the full Cultural Atlas',
			'prev'          => 'Previous',
			'next'          => 'Next',
			'page_of'       => 'Page %1$s of %2$s',
			'pagination'    => 'Results pagination',
		],
	];

	$lang = apply_filters( 'wpml_current_language', null );
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
 * Termo e post types vindos da URL, saneados. Post types: só os da allowlist,
 * mesmo que a URL peça outros (a URL é pública).
 *
 * @return array{term: string, types: string[], page: int}
 */
function bit_crossblog_search_results_request(): array {
	$term = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$term = trim( mb_substr( $term, 0, 100 ) );

	$allowed   = (array) apply_filters( 'bit_crossblog_search/results_post_types', [ 'estudos', 'page' ] );
	$requested = [];

	if ( isset( $_GET['jet_ajax_search_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$settings  = json_decode( wp_unslash( (string) $_GET['jet_ajax_search_settings'] ), true ); // phpcs:ignore
		$requested = is_array( $settings ) && isset( $settings['search_source'] ) ? array_map( 'sanitize_key', (array) $settings['search_source'] ) : [];
	}

	$types = array_values( array_intersect( $requested, $allowed ) );

	return [
		'term'  => $term,
		'types' => $types ?: array_values( $allowed ),
		'page'  => min( 500, max( 1, isset( $_GET['pg'] ) ? absint( $_GET['pg'] ) : 1 ) ), // phpcs:ignore
	];
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
 * Monta os dados da página: blog 1 paginado, Atlas limitado.
 */
function bit_crossblog_search_results_data( array $request ): array {
	$data = [ 'main' => null, 'atlas_pages' => null, 'atlas_artists' => null, 'total' => 0 ];

	if ( '' === $request['term'] ) {
		return $data;
	}

	$query = new WP_Query( [
		's'                   => $request['term'],
		'post_type'           => $request['types'],
		'post_status'         => 'publish',
		'sentence'            => true,
		'orderby'             => 'relevance',
		'posts_per_page'      => BIT_CROSSBLOG_SEARCH_RESULTS_PER_PAGE,
		'paged'               => $request['page'],
		'ignore_sticky_posts' => true,
		'suppress_filters'    => false,
	] );

	$data['main'] = [
		'cards' => array_map( function ( $post ) {
			return bit_crossblog_search_card( $post, (string) get_permalink( $post ) );
		}, $query->posts ),
		'total' => (int) $query->found_posts,
		'pages' => (int) $query->max_num_pages,
	];

	$pages_total = 0;
	$pages       = bit_crossblog_search_posts( BIT_CROSSBLOG_SEARCH_ATLAS_BLOG, [ 'page', 'linha-das-artes' ], $request['term'], BIT_CROSSBLOG_SEARCH_ATLAS_MAX, function ( WP_Post $post, string $lang ) {
		return bit_crossblog_search_card( $post, bit_crossblog_search_permalink( $post, $lang ) );
	}, $pages_total );

	$artists_total = 0;
	$artists       = bit_crossblog_search_posts( BIT_CROSSBLOG_SEARCH_ATLAS_BLOG, [ 'artistas' ], $request['term'], BIT_CROSSBLOG_SEARCH_ATLAS_MAX, function ( WP_Post $post, string $lang ) {
		return bit_crossblog_search_card( $post, bit_crossblog_search_atlas_url( $lang ), bit_crossblog_search_artist_meta( $post ) );
	}, $artists_total );

	$data['atlas_pages']   = [ 'cards' => $pages, 'total' => $pages_total ];
	$data['atlas_artists'] = [ 'cards' => $artists, 'total' => $artists_total ];
	$data['total']         = $data['main']['total'] + $pages_total + $artists_total;

	return $data;
}

function bit_crossblog_search_results_html( array $request, array $data ): string {
	$self = bit_crossblog_search_results_url( (string) apply_filters( 'wpml_current_language', '' ) );
	$html = '<main id="content" class="site-main bit-busca"><div class="bit-busca__inner">';

	$html .= '<h1 class="bit-busca__title">' . esc_html( bit_crossblog_search_t( 'title' ) ) . '</h1>';
	$html .= '<form class="bit-busca__form" role="search" method="get" action="' . esc_url( $self ) . '">'
		. '<label class="bit-busca__label screen-reader-text" for="bit-busca-s">' . esc_html( bit_crossblog_search_t( 'label' ) ) . '</label>'
		. '<input class="bit-busca__input" id="bit-busca-s" type="search" name="s" value="' . esc_attr( $request['term'] ) . '" placeholder="' . esc_attr( bit_crossblog_search_t( 'placeholder' ) ) . '">'
		. '<button class="bit-busca__submit" type="submit">' . esc_html( bit_crossblog_search_t( 'submit' ) ) . '</button>'
		. '</form>';

	if ( '' === $request['term'] ) {
		return $html . '<p class="bit-busca__summary">' . esc_html( bit_crossblog_search_t( 'empty' ) ) . '</p></div></main>';
	}

	if ( 0 === $data['total'] ) {
		return $html . '<p class="bit-busca__summary">' . esc_html( sprintf( bit_crossblog_search_t( 'none' ), $request['term'] ) ) . '</p></div></main>';
	}

	$summary = 1 === $data['total'] ? 'summary_one' : 'summary_many';
	$html   .= '<p class="bit-busca__summary">' . esc_html( sprintf( bit_crossblog_search_t( $summary ), number_format_i18n( $data['total'] ), $request['term'] ) ) . '</p>';

	// Blog 1, paginado.
	if ( $data['main']['cards'] ) {
		$html .= '<section class="bit-busca__section bit-busca__section--main">'
			. '<h2 class="bit-busca__section-title">' . esc_html( bit_crossblog_search_t( 'main' ) ) . ' <span class="bit-busca__count">' . esc_html( number_format_i18n( $data['main']['total'] ) ) . '</span></h2>'
			. bit_crossblog_search_render_cards( $data['main']['cards'] );

		if ( $data['main']['pages'] > 1 ) {
			$link = function ( int $page ) use ( $self, $request ) {
				// add_query_arg() não codifica: "P&D #1" virava s=P.
				return add_query_arg( array_filter( [ 's' => rawurlencode( $request['term'] ), 'pg' => $page > 1 ? $page : null ] ), $self );
			};

			$html .= '<nav class="bit-busca__pagination" aria-label="' . esc_attr( bit_crossblog_search_t( 'pagination' ) ) . '">';
			$html .= $request['page'] > 1 ? '<a class="bit-busca__page-link" href="' . esc_url( $link( $request['page'] - 1 ) ) . '">' . esc_html( bit_crossblog_search_t( 'prev' ) ) . '</a>' : '<span></span>';
			$html .= '<span class="bit-busca__page-status">' . esc_html( sprintf( bit_crossblog_search_t( 'page_of' ), number_format_i18n( $request['page'] ), number_format_i18n( $data['main']['pages'] ) ) ) . '</span>';
			$html .= $request['page'] < $data['main']['pages'] ? '<a class="bit-busca__page-link" href="' . esc_url( $link( $request['page'] + 1 ) ) . '">' . esc_html( bit_crossblog_search_t( 'next' ) ) . '</a>' : '<span></span>';
			$html .= '</nav>';
		}

		$html .= '</section>';
	}

	// Atlas: só na primeira página, limitado a BIT_CROSSBLOG_SEARCH_ATLAS_MAX.
	if ( 1 === $request['page'] ) {
		foreach ( [ 'atlas_pages', 'atlas_artists' ] as $key ) {
			$section = $data[ $key ];

			if ( ! $section['cards'] ) {
				continue;
			}

			$html .= '<section class="bit-busca__section bit-busca__section--' . esc_attr( str_replace( '_', '-', $key ) ) . '">'
				. '<h2 class="bit-busca__section-title">' . esc_html( bit_crossblog_search_t( $key ) ) . ' <span class="bit-busca__count">' . esc_html( number_format_i18n( $section['total'] ) ) . '</span></h2>'
				. bit_crossblog_search_render_cards( $section['cards'] );

			if ( $section['total'] > count( $section['cards'] ) ) {
				$html .= '<p class="bit-busca__more">' . esc_html( sprintf( bit_crossblog_search_t( 'showing' ), number_format_i18n( count( $section['cards'] ) ), number_format_i18n( $section['total'] ) ) ) . '</p>';
			}

			if ( 'atlas_artists' === $key ) {
				$atlas = bit_crossblog_search_in_blog( BIT_CROSSBLOG_SEARCH_ATLAS_BLOG, function ( string $lang ) {
					return bit_crossblog_search_atlas_url( $lang );
				} );
				$html .= '<p class="bit-busca__more"><a href="' . esc_url( preg_replace( '/#.*$/', '', $atlas ) ) . '">' . esc_html( bit_crossblog_search_t( 'atlas_all' ) ) . '</a></p>';
			}

			$html .= '</section>';
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
	$data    = bit_crossblog_search_results_data( $request );

	// pg além da última página: o WP não preenche found_posts quando a página
	// vem vazia, e o total saía errado sem link de volta. Volta para a 1.
	if ( $request['page'] > 1 && $data['main'] && ! $data['main']['cards'] ) {
		nocache_headers();
		wp_safe_redirect( add_query_arg( [ 's' => rawurlencode( $request['term'] ) ], bit_crossblog_search_results_url( (string) apply_filters( 'wpml_current_language', '' ) ) ), 302, 'bit-crossblog-search' );
		exit;
	}

	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}

	status_header( 200 );
	nocache_headers();
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
	header( 'X-Robots-Tag: noindex, follow' );

	$title = bit_crossblog_search_t( 'title' ) . ( '' !== $request['term'] ? ': ' . $request['term'] : '' );

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
			$languages[ $code ]['url'] = add_query_arg( array_filter( [ 's' => rawurlencode( $request['term'] ) ] ), bit_crossblog_search_results_url( (string) $code ) );
		}

		return $languages;
	}, 99 );

	get_header();
	echo bit_crossblog_search_results_html( $request, $data ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado na montagem
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
