<?php
/**
 * Plugin Name:  BIT Estudo — Eixos da Espiral
 * Description:  Shortcode [estudo_eixos] que lista os eixos ("Espiral: X")
 *               associados a um estudo (CPT estudos, taxonomia "eixos"), no
 *               formato "Aparece nos eixos: PIQCTs, Segurança, ...". Cada eixo
 *               vira um link para a busca da Espiral de Conhecimento, gerando
 *               URLs IDÊNTICAS às que o widget da espiral renderiza — para não
 *               fragmentar relatórios do Google Analytics.
 *
 *               A taxonomia "eixos" é public=false (não aparece no seletor do
 *               JetEngine Dynamic Terms), por isso este shortcode é necessário.
 *
 *               O mapa term_id → (posição eixo{N} + _label) é lido em runtime
 *               do widget da espiral (axes_repeater), com fallback nos defaults
 *               canônicos. Cacheado em transient (12h) para performance.
 * Version:      1.0.0
 * Author:       Bureau IT
 * Network:      true
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'BIT_Estudo_Eixos' ) ) :

final class BIT_Estudo_Eixos {

	const VERSION         = '1.1.0';
	const TAXONOMY        = 'eixos';
	const PARENT_TERM_ID  = 1148;   // termo pai "Espiral" (PT). Eixos válidos são filhos dele.
	const SPIRAL_PAGE_ID  = 26826;  // página "Espiral de Conhecimento" (PT) que contém a busca #estudos.
	const SPIRAL_FALLBACK = '/conhecimento/espiral-de-conhecimento/'; // fallback se a página sumir.
	const JSF_PROVIDER    = 'jet-engine:estudos';
	const ANCHOR          = '#estudos';
	const TRANSIENT_KEY    = 'bit_estudo_eixos_map_v1';
	const TRANSIENT_TTL    = 12 * HOUR_IN_SECONDS;

	/** @var BIT_Estudo_Eixos|null Instância singleton (reusada pela Dynamic Tag). */
	private static $instance = null;

	public static function instance(): BIT_Estudo_Eixos {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		if ( self::$instance === null ) {
			self::$instance = $this;
		}
		add_shortcode( 'estudo_eixos', [ $this, 'render_shortcode' ] );
		// Invalida o cache do mapa quando a página da espiral é salva.
		add_action( 'save_post', [ $this, 'maybe_flush_map_cache' ], 20, 2 );
		// Dynamic Tag do Elementor — permite usar widget Text/Heading nativo
		// (com abas de Estilo completas) em vez do widget Shortcode.
		add_action( 'elementor/dynamic_tags/register', [ $this, 'register_dynamic_tag' ] );
	}

	/**
	 * Registra a Dynamic Tag "Eixos do Estudo" na API nativa do Elementor.
	 * Compatível com a assinatura nova (Dynamic_Tags_Manager) e legada.
	 *
	 * @param mixed $dynamic_tags Manager do Elementor.
	 */
	public function register_dynamic_tag( $dynamic_tags ): void {
		// A base só existe quando o Elementor já carregou o módulo de dynamic
		// tags — por isso incluímos o arquivo da tag AQUI (no hook), não no
		// boot. O arquivo fica em subdiretório (bit-estudo-eixos/tag.php): o WP
		// auto-carrega só os .php na RAIZ de mu-plugins; um arquivo solto na
		// raiz seria incluído cedo demais (antes da base Elementor existir),
		// queimando o guard class_exists e nunca definindo a classe.
		if ( ! class_exists( '\Elementor\Core\DynamicTags\Tag' ) ) {
			return;
		}
		if ( ! class_exists( 'BIT_Estudo_Eixos_Tag' ) ) {
			require_once __DIR__ . '/bit-estudo-eixos/tag.php';
		}
		if ( ! class_exists( 'BIT_Estudo_Eixos_Tag' ) ) {
			return; // segurança: arquivo não definiu a classe
		}
		// register() é o método atual; register_tag() é o legado.
		if ( method_exists( $dynamic_tags, 'register' ) ) {
			$dynamic_tags->register( new \BIT_Estudo_Eixos_Tag() );
		} elseif ( method_exists( $dynamic_tags, 'register_tag' ) ) {
			$dynamic_tags->register_tag( new \BIT_Estudo_Eixos_Tag() );
		}
	}

	/**
	 * Render reutilizável pela Dynamic Tag e pelo shortcode.
	 * Aceita o mesmo conjunto de atributos do shortcode.
	 *
	 * @param array $atts
	 * @return string HTML do bloco (ou vazio).
	 */
	public function render( array $atts ): string {
		return $this->render_shortcode( $atts );
	}

	/**
	 * Defaults canônicos (term_id PT → label PT / label EN), na ordem das
	 * posições eixo1..eixo21. Fallback quando o widget não puder ser lido.
	 * Espelha get_default_axes_repeater() do bit-elementor-espiral-widget.php.
	 *
	 * @return array<int, array{term_id:int, label_pt:string, label_en:string}>
	 */
	private function default_axes(): array {
		return [
			[ 'term_id' => 172,  'label_pt' => 'Governança',                                           'label_en' => 'Governance' ],
			[ 'term_id' => 174,  'label_pt' => 'Instrumentos de financiamento',                        'label_en' => 'Financing instruments' ],
			[ 'term_id' => 175,  'label_pt' => 'Planos e políticas públicas',                          'label_en' => 'Plans and public policies' ],
			[ 'term_id' => 176,  'label_pt' => 'Negócios',                                             'label_en' => 'Business' ],
			[ 'term_id' => 177,  'label_pt' => 'Sociedade civil',                                      'label_en' => 'Civil society' ],
			[ 'term_id' => 187,  'label_pt' => 'Ciência, Tecnologia e Inovação',                       'label_en' => 'Science, technology and innovation' ],
			[ 'term_id' => 178,  'label_pt' => 'Cultura',                                              'label_en' => 'Culture' ],
			[ 'term_id' => 180,  'label_pt' => 'Mudança do Uso do Solo',                               'label_en' => 'Land use change' ],
			[ 'term_id' => 2013, 'label_pt' => 'Ordenamento Territorial e Regularização Fundiária',    'label_en' => 'Territorial planning and land tenure regularization' ],
			[ 'term_id' => 182,  'label_pt' => 'Infraestrutura',                                       'label_en' => 'Infrastructure' ],
			[ 'term_id' => 183,  'label_pt' => 'Comunicação e mídia',                                  'label_en' => 'Communication and media' ],
			[ 'term_id' => 184,  'label_pt' => 'Mudanças Climáticas',                                  'label_en' => 'Climate change' ],
			[ 'term_id' => 185,  'label_pt' => 'Agenda Internacional',                                 'label_en' => 'International agenda' ],
			[ 'term_id' => 1819, 'label_pt' => 'Educação',                                             'label_en' => 'Education' ],
			[ 'term_id' => 604,  'label_pt' => 'Bioeconomia',                                          'label_en' => 'Bioeconomy' ],
			[ 'term_id' => 598,  'label_pt' => 'Segurança',                                            'label_en' => 'Security' ],
			[ 'term_id' => 2479, 'label_pt' => 'Saúde',                                                'label_en' => 'Health' ],
			[ 'term_id' => 2360, 'label_pt' => 'Cidades',                                              'label_en' => 'Cities' ],
			[ 'term_id' => 2463, 'label_pt' => 'Biodiversidade',                                       'label_en' => 'Biodiversity' ],
			[ 'term_id' => 2401, 'label_pt' => 'Povos indígenas, quilombolas e comunidades tradicionais', 'label_en' => 'Indigenous, quilombola and traditional communities' ],
			[ 'term_id' => 2464, 'label_pt' => 'Direitos humanos',                                     'label_en' => 'Human rights' ],
		];
	}

	/**
	 * Lê o axes_repeater do widget da espiral em runtime e o funde com os
	 * defaults canônicos (defaults preenchem segment_label_en, que o repeater
	 * salvo no banco não tem). Retorna um mapa posicional 1..21.
	 *
	 * @return array<int, array{term_id:int, label_pt:string, label_en:string}>
	 */
	private function build_axes_map(): array {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$defaults = $this->default_axes();
		$repeater = $this->read_widget_repeater();

		$map = [];
		if ( $repeater ) {
			$i = 0;
			foreach ( $repeater as $row ) {
				$n = $i + 1;
				if ( $n > 21 ) break;
				$term_id = isset( $row['segment_term_id'] ) ? (int) $row['segment_term_id'] : 0;
				if ( $term_id <= 0 ) { $i++; continue; }

				$label_pt = isset( $row['segment_label'] ) ? trim( (string) $row['segment_label'] ) : '';
				$label_en = isset( $row['segment_label_en'] ) ? trim( (string) $row['segment_label_en'] ) : '';
				// Fallback EN pela posição (repeater salvo não tem label_en).
				if ( $label_en === '' && isset( $defaults[ $i ] ) ) {
					$label_en = $defaults[ $i ]['label_en'];
				}
				$map[ $n ] = [
					'term_id'  => $term_id,
					'label_pt' => $label_pt !== '' ? $label_pt : ( $defaults[ $i ]['label_pt'] ?? '' ),
					'label_en' => $label_en,
				];
				$i++;
			}
		}

		// Fallback completo se nada veio do widget.
		if ( empty( $map ) ) {
			foreach ( $defaults as $i => $d ) {
				$map[ $i + 1 ] = $d;
			}
		}

		set_transient( self::TRANSIENT_KEY, $map, self::TRANSIENT_TTL );
		return $map;
	}

	/**
	 * Localiza a primeira instância do widget da espiral (axes_repeater) entre
	 * os posts conhecidos e retorna o repeater. Preferência: template
	 * elementor_library "espiral"; fallback para qualquer post publicado.
	 *
	 * @return array|null
	 */
	private function read_widget_repeater(): ?array {
		global $wpdb;
		// Ordena por post_type para priorizar elementor_library (template reutilizável).
		$post_ids = $wpdb->get_col(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_elementor_data'
			   AND pm.meta_value LIKE '%segment_term_id%'
			   AND p.post_status = 'publish'
			   AND p.post_type != 'revision'
			 ORDER BY ( p.post_type = 'elementor_library' ) DESC, p.ID ASC
			 LIMIT 10"
		);
		foreach ( $post_ids as $pid ) {
			$data = get_post_meta( (int) $pid, '_elementor_data', true );
			if ( ! $data ) continue;
			$elements = json_decode( $data, true );
			if ( ! is_array( $elements ) ) continue;
			$rep = $this->find_repeater_recursive( $elements );
			if ( $rep ) return $rep;
		}
		return null;
	}

	private function find_repeater_recursive( array $elements ): ?array {
		foreach ( $elements as $el ) {
			if ( ! empty( $el['settings']['axes_repeater'] ) && is_array( $el['settings']['axes_repeater'] ) ) {
				return $el['settings']['axes_repeater'];
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$r = $this->find_repeater_recursive( $el['elements'] );
				if ( $r ) return $r;
			}
		}
		return null;
	}

	/**
	 * Idioma corrente normalizado: 'en' para inglês, senão o código WPML/locale.
	 */
	private function current_lang(): string {
		$lang = apply_filters( 'wpml_current_language', null );
		if ( ! is_string( $lang ) || $lang === '' ) {
			$lang = substr( (string) get_locale(), 0, 2 );
		}
		return (string) $lang;
	}

	/**
	 * Base da URL da espiral no idioma corrente. A espiral renderiza links
	 * apontando para a PÁGINA traduzida (PT: /conhecimento/espiral-de-conhecimento/,
	 * EN: /en/knowledge/spiral-of-knowledge/) — portanto usamos o permalink da
	 * página da espiral no idioma atual, não um path hardcoded.
	 *
	 * @param string $lang Idioma corrente.
	 * @return string URL absoluta com barra final.
	 */
	private function spiral_base_url( string $lang ): string {
		$page_id = self::SPIRAL_PAGE_ID;
		// Traduz o ID da página para o idioma corrente via WPML.
		$translated = apply_filters( 'wpml_object_id', $page_id, 'page', true, $lang );
		if ( $translated ) {
			$page_id = (int) $translated;
		}
		$permalink = get_permalink( $page_id );
		if ( ! $permalink ) {
			$permalink = home_url( self::SPIRAL_FALLBACK );
		}
		return trailingslashit( $permalink );
	}

	/**
	 * Monta a URL de um eixo, idêntica à gerada pelo widget da espiral.
	 *
	 * @param int    $position       Posição eixo{N} (1..21).
	 * @param int    $term_id_pt     term_id PT do eixo (chave do mapa).
	 * @param string $label_for_slug Label (PT ou EN) usado no _label.
	 * @param string $lang           Idioma corrente.
	 */
	private function build_axis_url( int $position, int $term_id_pt, string $label_for_slug, string $lang ): string {
		$term_id = $term_id_pt;
		// EN: traduz o term_id via WPML — mesma lógica do widget.
		if ( $lang && stripos( $lang, 'pt' ) !== 0 ) {
			$translated = apply_filters( 'wpml_object_id', $term_id_pt, self::TAXONOMY, true, $lang );
			if ( $translated ) {
				$term_id = (int) $translated;
			}
		}
		$label_slug = sanitize_title( $label_for_slug );
		return sprintf(
			'%s?eixo=eixo%d%s&jsf=%s&tax=eixos:%d%s',
			$this->spiral_base_url( $lang ),
			$position,
			$label_slug !== '' ? '&_label=' . $label_slug : '',
			self::JSF_PROVIDER,
			$term_id,
			self::ANCHOR
		);
	}

	/**
	 * Shortcode [estudo_eixos].
	 *
	 * Atributos:
	 *   id        — post_id do estudo (default: post atual no loop)
	 *   prefix    — texto antes da lista (default: "Aparece nos eixos: ")
	 *   sep       — separador entre eixos (default: ", ")
	 *   linked    — "yes" (default) gera links; "no" só texto
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts( [
			'id'     => 0,
			'prefix' => '',
			'sep'    => ', ',
			'linked' => 'yes',
		], $atts, 'estudo_eixos' );

		$post_id = (int) $atts['id'];
		if ( $post_id <= 0 ) {
			$post_id = get_the_ID();
		}
		if ( ! $post_id ) return '';

		// Termos do estudo na taxonomia eixos (funciona mesmo com public=false).
		$terms = get_the_terms( $post_id, self::TAXONOMY );
		if ( is_wp_error( $terms ) || empty( $terms ) ) return '';

		$lang   = $this->current_lang();
		$is_en  = ( stripos( $lang, 'en' ) === 0 );

		// O mapa é indexado por posição, com term_id PT canônico. Os termos do
		// estudo podem vir com term_id PT (post só em PT) OU term_id traduzido
		// (post em EN). Indexamos o mapa por AMBAS as chaves (PT e a tradução
		// no idioma corrente), apontando para a mesma entrada — assim o
		// casamento funciona nos dois cenários.
		$axes_map  = $this->build_axes_map();
		$by_term   = [];   // term_id (qualquer idioma) → ['position','term_id_pt','label']
		foreach ( $axes_map as $position => $entry ) {
			$tid_pt = (int) $entry['term_id'];
			$info   = [
				'position'   => $position,
				'term_id_pt' => $tid_pt,
				'label'      => $is_en ? $entry['label_en'] : $entry['label_pt'],
			];
			// Chave pelo term_id PT (cobre posts só em PT vistos em qualquer idioma).
			$by_term[ $tid_pt ] = $info;
			// Em EN, também indexa pela tradução (cobre posts traduzidos).
			if ( $is_en ) {
				$translated = apply_filters( 'wpml_object_id', $tid_pt, self::TAXONOMY, true, $lang );
				if ( $translated ) {
					$by_term[ (int) $translated ] = $info;
				}
			}
		}

		// Filtra os termos do estudo: só os que estão no mapa de eixos da espiral
		// (isso já exclui o pai "Espiral" puro e os "OLD ..."). Preserva a ordem
		// das posições da espiral para consistência visual.
		$items = [];
		foreach ( $terms as $t ) {
			$tid = (int) $t->term_id;
			if ( ! isset( $by_term[ $tid ] ) ) continue;
			$info  = $by_term[ $tid ];
			// Label exibido: nome do termo sem o prefixo "Espiral: " (PT) ou "Spiral: " (EN).
			$display = preg_replace( '/^(Espiral|Spiral):\s*/u', '', $t->name );
			$display = trim( (string) $display );
			if ( $display === '' ) $display = $info['label'];

			$items[ $info['position'] ] = [
				'display'    => $display,
				'position'   => $info['position'],
				'term_id_pt' => $info['term_id_pt'],
				'label_slug' => $info['label'], // label da espiral (PT/EN) para o _label
			];
		}
		if ( empty( $items ) ) return '';

		ksort( $items ); // ordena pela posição na espiral

		$linked = ( strtolower( (string) $atts['linked'] ) !== 'no' );
		$sep    = (string) $atts['sep'];

		$rendered = [];
		foreach ( $items as $item ) {
			$label_html = esc_html( $item['display'] );
			if ( $linked ) {
				$url = $this->build_axis_url(
					$item['position'],
					$item['term_id_pt'],
					$item['label_slug'],
					$lang
				);
				$rendered[] = sprintf(
					'<a class="bit-estudo-eixo" href="%s">%s</a>',
					esc_url( $url ),
					$label_html
				);
			} else {
				$rendered[] = '<span class="bit-estudo-eixo">' . $label_html . '</span>';
			}
		}

		// Prefixo: default por idioma se não informado.
		$prefix = (string) $atts['prefix'];
		if ( $prefix === '' ) {
			$prefix = $is_en ? 'Appears in axes: ' : 'Aparece nos eixos: ';
		}

		return sprintf(
			'<div class="bit-estudo-eixos"><span class="bit-estudo-eixos__prefix">%s</span>%s</div>',
			esc_html( $prefix ),
			implode( esc_html( $sep ), $rendered )
		);
	}

	/**
	 * Invalida o cache do mapa quando um post que contém o widget da espiral
	 * é salvo (heurística: _elementor_data com segment_term_id).
	 */
	public function maybe_flush_map_cache( $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
		$data = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_string( $data ) && strpos( $data, 'segment_term_id' ) !== false ) {
			delete_transient( self::TRANSIENT_KEY );
		}
	}
}

BIT_Estudo_Eixos::instance();

endif;
