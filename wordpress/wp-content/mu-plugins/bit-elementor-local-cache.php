<?php
/**
 * Plugin Name: BIT — Elementor Local Cache (bypass S3-Uploads)
 * Description: Mantem o cache CSS do Elementor no filesystem local, fora do S3.
 * Version: 2.1.2
 * Author: Daniel Cambria / Bureau de Tecnologia
 *
 * v2.1.2: micro-otimizacao de performance — cache estatico por request nas
 * funcoes `bit_elc_caller_is_elementor_files()` e `bit_elc_caller_is_elementor_css_render()`.
 * Listings JetEngine com 50-100 attachments invocavam `debug_backtrace(20|25)`
 * 100-200x por request (50-200ms TTFB extra). A chave de cache usa hash dos 8
 * frames topo do stack (~3-5x mais barato que profundidade 20-25, mas
 * suficiente para distinguir contextos: Control_Media::get_style_value vs
 * menu/header/etc terao stacks topo distintos nesses 8 frames). Loops dentro
 * do mesmo caller batem o cache nas chamadas subsequentes. Variaveis estaticas
 * resetam entre requests (FPM workers reciclam). Logica funcional preservada.
 *
 * Por que: o plugin S3-Uploads desvia /uploads/ para s3://. O cache CSS gerado pelo
 * Elementor (em /uploads/elementor/) iria pro S3 tambem. Como blue e green geram CSS
 * com IDs/slugs diferentes pos-import de DB, isso causaria sobrescrita cruzada.
 *
 * Solucao v2.0.0 (substitui v1.x — debug_backtrace nao funcionava):
 *
 *   1) PRE-WARM do static cache do Elementor com path LOCAL antes do S3-Uploads
 *      popular. Elementor 3.35.x tem `Base::$wp_uploads_dir[$blog_id]` que e
 *      memoizado por request (core/files/base.php:18,296-303). A primeira chamada
 *      a wp_upload_dir() popula esse cache e nenhuma chamada subsequente passa
 *      pelo filtro upload_dir. Por isso forcamos a primeira chamada com filtro
 *      local de prioridade 1 (antes do S3-Uploads em prioridade 10).
 *
 *   2) Filtro `s3_uploads_enabled` retorna false quando o caller atual e
 *      Elementor escrevendo CSS — proteção em camada para escritas que escapem
 *      o pre-warm (regeneracoes mid-request, hot reload, etc).
 *
 *   3) Arquivos servidos via path /wp-content/elementor-cache/{sites/N/} —
 *      esse path NAO bate com behaviors S3 do CloudFront, vai pro ALB normalmente.
 *
 * Cuidados:
 * - Multisite path-based: blog 1 vai pra /elementor-cache/, blog 2 pra /elementor-cache/sites/2/
 * - Pre-warm precisa rodar ANTES do S3-Uploads (`s3-uploads/s3-uploads.php`).
 *   Mu-plugins carregam antes de plugins, entao plugins_loaded priority 1 e
 *   suficiente. Se trocar para must-use plugin folder, garantir ordem alfabetica
 *   (b < s, ja garante).
 *
 * Validacao:
 *   wp eval 'echo \Elementor\Core\Files\Base::get_base_uploads_dir();'
 *   → deve retornar /var/www/.../wp-content/elementor-cache/[sites/N/]elementor/
 *
 *   ls /var/www/.../wp-content/elementor-cache/sites/2/elementor/css/
 *   → deve listar post-XX.css
 *
 *   curl -I /wp-content/elementor-cache/sites/2/elementor/css/post-72730.css
 *   → 200 OK servido pelo nginx (ALB origin)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BIT_ELEMENTOR_CACHE_DIR_BASE = WP_CONTENT_DIR . '/elementor-cache';
const BIT_ELEMENTOR_CACHE_URL_PATH = '/wp-content/elementor-cache';

/**
 * Resolve o basedir + baseurl locais para o blog corrente.
 *
 * @return array{basedir:string, baseurl:string}
 */
function bit_elc_get_local_paths() {
	$blog_id = get_current_blog_id();
	$suffix  = ( is_multisite() && $blog_id > 1 ) ? "/sites/{$blog_id}" : '';
	$base    = BIT_ELEMENTOR_CACHE_DIR_BASE . $suffix;

	// Pegar URL do blog SEM passar por home_url()/get_home_url() — evita
	// o filtro `home_url` que o WPML usa para injetar prefixo de idioma
	// (ex: 'cultura/en' quando navegando em EN). Lemos direto do option
	// 'home' do blog (raw) e removemos trailing slash.
	if ( is_multisite() ) {
		$blog_home = get_blog_option( $blog_id, 'home' );
	} else {
		$blog_home = get_option( 'home' );
	}
	$blog_home = is_string( $blog_home ) ? rtrim( $blog_home, '/' ) : '';
	$url       = $blog_home . BIT_ELEMENTOR_CACHE_URL_PATH . $suffix;

	if ( ! is_dir( $base ) ) {
		wp_mkdir_p( $base );
	}

	return [ 'basedir' => $base, 'baseurl' => $url ];
}

/**
 * Filtro upload_dir que devolve o path local do mu-plugin.
 * Usado APENAS durante o pre-warm — registrado e desregistrado em sequencia
 * para que o cache static do Elementor seja populado com path local na
 * primeira leitura.
 */
function bit_elc_force_local( $dirs ) {
	$paths   = bit_elc_get_local_paths();
	$subdir  = isset( $dirs['subdir'] ) ? $dirs['subdir'] : '';

	$dirs['basedir'] = $paths['basedir'];
	$dirs['baseurl'] = $paths['baseurl'];
	$dirs['path']    = $paths['basedir'] . $subdir;
	$dirs['url']     = $paths['baseurl'] . $subdir;
	$dirs['error']   = false;

	return $dirs;
}

/**
 * Detecta se o stack atual e Elementor ESCREVENDO CSS.
 *
 * v2.0.5: defesa em duas camadas:
 *
 *   (a) BLACKLIST de funcoes WP de leitura de attachment.
 *       Se backtrace contem wp_get_attachment_url, _image_src, _metadata, etc,
 *       e leitura — abortar (return false) mesmo que classes Elementor estejam
 *       no stack. Isso resolve o bug v2.0.4: durante render de CSS background-image,
 *       Control_Media::get_style_value chama wp_get_attachment_image_url, que dispara
 *       upload_dir filter com Elementor\Core\Files\CSS\Post no stack — antes da
 *       blacklist, o whitelist por classe casava e a URL da imagem virava
 *       /elementor-cache/2026/04/X.jpg.
 *
 *   (b) WHITELIST por classe + metodo. So reescreve se:
 *         - classe Elementor\Core\Files\CSS\* ou Manager ESTA no stack, E
 *         - metodo de ESCRITA (set_path, update_file, delete, etc) ESTA no stack.
 *       Isso reproduz o comportamento defensivo da v2.0.2 que funcionou em prod
 *       desde o deploy inicial.
 *
 * @return bool
 */
function bit_elc_caller_is_elementor_files() {
	// v2.1.2: cache estatico por request — evita debug_backtrace(20) repetido
	// em loops de attachments. Key = hash dos 8 frames topo (~5x mais barato
	// que profundidade 20, mas suficiente para distinguir contextos:
	// wp_get_attachment_url chamado de menu/header vs Post::parse_content vs
	// Control_Media::get_style_value tem stacks topo distintos nesses 8 frames).
	static $cache = [];
	$top = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 8 );
	$cache_key = '';
	foreach ( $top as $tf ) {
		$cache_key .= ( $tf['class'] ?? '' ) . '::' . ( $tf['function'] ?? '' ) . '|';
	}
	if ( isset( $cache[ $cache_key ] ) ) {
		return $cache[ $cache_key ];
	}

	$bt = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 );

	// (a) Blacklist: funcoes WP de leitura de attachment.
	// Sintoma direto do bug: stack com Control_Media + wp_get_attachment_*.
	static $attachment_read_fns = [
		'wp_get_attachment_url'        => true,
		'wp_get_attachment_image_url'  => true,
		'wp_get_attachment_image_src'  => true,
		'wp_get_attachment_image'      => true,
		'wp_get_attachment_metadata'   => true,
		'wp_get_attachment_thumb_url'  => true,
		'image_downsize'               => true,
	];

	// (b) Whitelist de metodos de escrita/init de cache.
	// Reproduz a abordagem $write_methods da v2.0.2 ampliada.
	// Cobre todo o ciclo de vida de Elementor\Core\Files\CSS\Base e Manager:
	//   - __construct -> set_path (computa path local)
	//   - update -> write (escreve arquivo)
	//   - update_file -> get_content -> parse_content (re-renderiza)
	//   - delete (limpa)
	//   - enqueue -> print_css (servir via wp_enqueue_style)
	static $write_methods = [
		'__construct'        => true,
		'set_path'           => true,
		'set_files_dir'      => true,
		'get_path'           => true,
		'get_url'            => true,
		'update'             => true,
		'update_file'        => true,
		'write'              => true,
		'delete'             => true,
		'clear_cache'        => true,
		'regenerate_cache'   => true,
		'parse_content'      => true,
		'get_content'        => true,
		'enqueue'            => true,
		'print_css'          => true,
		'render_css'         => true,
		'get_meta'           => true,
		'update_meta'        => true,
		'load_meta'          => true,
		'init_stylesheet'    => true,
	];

	$matched_class  = false;
	$matched_method = false;

	foreach ( $bt as $frame ) {
		$fn = $frame['function'] ?? '';

		// Blacklist tem prioridade absoluta — leitura de attachment, abortar.
		if ( isset( $attachment_read_fns[ $fn ] ) ) {
			$cache[ $cache_key ] = false;
			return false;
		}

		// Whitelist 1: classe Elementor\Core\Files\CSS\* ou Manager
		if ( ! $matched_class && isset( $frame['class'] ) ) {
			$cls = $frame['class'];
			if ( strpos( $cls, 'Elementor\\Core\\Files\\CSS\\' ) === 0
				|| $cls === 'Elementor\\Core\\Files\\Manager' ) {
				$matched_class = true;
			}
		}

		// Whitelist 2: metodo de escrita
		if ( ! $matched_method && isset( $write_methods[ $fn ] ) ) {
			$matched_method = true;
		}
	}

	// AND logico: reescreve apenas se classe E metodo casarem.
	$result = $matched_class && $matched_method;
	$cache[ $cache_key ] = $result;
	return $result;
}

/**
 * (1) PRE-WARM do static cache do Elementor.
 *
 * Roda em `plugins_loaded` priority 1 — depois dos mu-plugins carregarem mas
 * antes do S3-Uploads se inscrever no `init` do WP. Forca o filtro local com
 * prioridade 1 (antes do S3-Uploads em 10), chama `wp_upload_dir()` (que
 * preenche o cache static do Elementor via primeira chamada na sessao), e
 * desregistra o filtro para nao afetar uploads reais.
 */
add_action( 'plugins_loaded', function () {
	add_filter( 'upload_dir', 'bit_elc_force_local', 1 );

	// Pre-warm para TODOS os blogs do multisite — Base::$wp_uploads_dir e
	// keyed por blog_id (Elementor base.php:297-299 usa `global $blog_id`),
	// entao se houver switch_to_blog mid-request precisamos ter cada blog
	// pre-warmado, senao a chamada subsequente passa pelo S3-Uploads.
	if ( is_multisite() && function_exists( 'get_sites' ) ) {
		$sites = get_sites( [ 'number' => 0, 'fields' => 'ids' ] );
		foreach ( (array) $sites as $sid ) {
			switch_to_blog( (int) $sid );
			wp_upload_dir( null, false );
			if ( class_exists( '\\Elementor\\Core\\Files\\Base' ) ) {
				\Elementor\Core\Files\Base::get_base_uploads_dir();
			}
			restore_current_blog();
		}
	} else {
		wp_upload_dir( null, false );
		if ( class_exists( '\\Elementor\\Core\\Files\\Base' ) ) {
			\Elementor\Core\Files\Base::get_base_uploads_dir();
		}
	}

	remove_filter( 'upload_dir', 'bit_elc_force_local', 1 );
}, 1 );

/**
 * Re-pre-warm em switch_to_blog dinamico — se algum codigo der switch
 * para um blog que nao foi pre-warmado (cenario raro: novo blog criado
 * mid-request), garantimos que o cache static do Elementor seja preenchido
 * com path local.
 */
add_action( 'switch_blog', function ( $new_blog_id, $prev_blog_id ) {
	if ( ! class_exists( '\\Elementor\\Core\\Files\\Base' ) ) {
		return;
	}
	try {
		$ref  = new ReflectionClass( '\\Elementor\\Core\\Files\\Base' );
		$prop = $ref->getProperty( 'wp_uploads_dir' );
		$prop->setAccessible( true );
		$cache = $prop->getValue();
		if ( isset( $cache[ (int) $new_blog_id ] ) ) {
			return; // ja pre-warmado
		}
	} catch ( \Throwable $e ) {
		return;
	}
	add_filter( 'upload_dir', 'bit_elc_force_local', 1 );
	wp_upload_dir( null, false );
	\Elementor\Core\Files\Base::get_base_uploads_dir();
	remove_filter( 'upload_dir', 'bit_elc_force_local', 1 );
}, 1, 2 );

/**
 * (2) Defesa em profundidade: desligar S3-Uploads quando Elementor Files
 * estiver escrevendo. Usa o filtro proprio do plugin S3-Uploads.
 *
 * Cobre casos onde o pre-warm acima nao foi suficiente (ex: cache static
 * resetado por outro plugin, switch_to_blog em multisite, regeneracao
 * sob demanda mid-request).
 */
add_filter( 's3_uploads_enabled', function ( $enabled ) {
	if ( ! $enabled ) {
		return $enabled;
	}
	if ( bit_elc_caller_is_elementor_files() ) {
		return false;
	}
	return $enabled;
}, 10 );

/**
 * (3) Defesa em profundidade: filtro upload_dir tradicional (priority 20)
 * mantido como rede de seguranca caso (1) e (2) falhem em algum cenario.
 *
 * v2.0.3: alargado para cobrir QUALQUER chamada cujo backtrace contenha
 * Elementor\Core\Files\* — tanto escritas quanto leituras de URL. Isso
 * fecha o gap em que o static cache de um blog nao-pre-warmado (novo blog,
 * race condition) seria preenchido com path do S3-Uploads na PRIMEIRA
 * leitura, contaminando todas as URLs subsequentes daquele blog na request.
 */
add_filter( 'upload_dir', function ( $dirs ) {
	static $in_call = false;
	if ( $in_call ) {
		return $dirs;
	}

	$in_call = true;
	try {
		if ( ! bit_elc_caller_is_elementor_files() ) {
			return $dirs;
		}
		return bit_elc_force_local( $dirs );
	} finally {
		$in_call = false;
	}
}, 20 );

/**
 * (4) v2.1.1: Normaliza URLs absolutas para PATH-RELATIVE quando o Elementor
 * esta gerando CSS de background-image (Control_Media::get_style_value).
 *
 * Por que: o CSS gerado e estatico no filesystem. Se a URL embutida for
 * absoluta (https://cambrasmax.local:8484/uploads/X.jpg), ela vaza para qualquer
 * host que sirva esse CSS — local, tunnel cloudflare, prod. Ao reescrever para
 * `/wp-content/uploads/X.jpg` (path-relative), o browser resolve com o host
 * atual da request automaticamente. CSS fica portavel entre dev/tunnel/prod.
 *
 * Hooks:
 *   - wp_get_attachment_url        (single attachment URL — usado por Control_Media)
 *   - wp_get_attachment_image_url  (variante por size — chamada via _src)
 *   - wp_get_attachment_image_src  (filtra o array completo)
 *
 * So dispara quando:
 *   - URL aponta para asset interno do WP (path contem `/wp-content/uploads/`).
 *     v2.1.1 troca host-match por path-heuristic — host-match falhava no cenario
 *     tunnel onde home_url() retorna `cambrasmax.local` mas tunnel-url-rewrite.php
 *     ja reescreveu a URL para `concertacao.bureau-it.com`, fazendo o filtro
 *     bypassar e a URL com host do tunnel vazar pro CSS estatico.
 *   - Elementor esta renderizando CSS (caller_is_elementor_files retornaria true
 *     SEM a blacklist — usamos detect "raw" sem blacklist)
 */

/**
 * Detecta caller Elementor SEM blacklist de attachment reads.
 * Usado pelos filtros de URL-relative (que precisam casar mesmo durante
 * wp_get_attachment_url, exatamente o cenario que a blacklist principal
 * exclui).
 */
function bit_elc_caller_is_elementor_css_render() {
	// v2.1.2: cache estatico por request — evita debug_backtrace(25) repetido
	// em loops de wp_get_attachment_url. Key = hash dos 8 frames topo
	// (~3x mais barato que 25, mas suficiente para distinguir wp_get_attachment_url
	// chamado de Control_Media::get_style_value vs menu/header/etc).
	static $cache = [];
	$top = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 8 );
	$cache_key = '';
	foreach ( $top as $tf ) {
		$cache_key .= ( $tf['class'] ?? '' ) . '::' . ( $tf['function'] ?? '' ) . '|';
	}
	if ( isset( $cache[ $cache_key ] ) ) {
		return $cache[ $cache_key ];
	}

	$bt = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 25 );
	foreach ( $bt as $frame ) {
		if ( ! isset( $frame['class'] ) ) {
			continue;
		}
		$cls = $frame['class'];
		if ( strpos( $cls, 'Elementor\\Core\\Files\\CSS\\' ) === 0 ) {
			$cache[ $cache_key ] = true;
			return true;
		}
	}
	$cache[ $cache_key ] = false;
	return false;
}

/**
 * Converte URL absoluta para path-relative quando seguro.
 * Mantem URLs externas (CDN, S3) e URLs ja relativas inalteradas.
 */
function bit_elc_make_relative( $url ) {
	if ( ! is_string( $url ) || $url === '' ) {
		return $url;
	}
	// Ja eh relativa — retornar como esta
	if ( strpos( $url, '/' ) === 0 && strpos( $url, '//' ) !== 0 ) {
		return $url;
	}
	$path = parse_url( $url, PHP_URL_PATH );
	if ( ! is_string( $path ) || $path === '' ) {
		return $url;
	}
	// v2.1.1: heuristica por path em vez de host. Path /wp-content/uploads/...
	// sempre indica asset interno do WordPress — seguro cortar host independente
	// de qual host (local, tunnel, prod) esteja embutido.
	// Hosts externos genuinos (S3, CDNs) nao terao /wp-content/uploads/ no path.
	if ( strpos( $path, '/wp-content/uploads/' ) === false ) {
		return $url;
	}
	$qs = parse_url( $url, PHP_URL_QUERY );
	return $path . ( $qs ? '?' . $qs : '' );
}

add_filter( 'wp_get_attachment_url', function ( $url ) {
	if ( ! bit_elc_caller_is_elementor_css_render() ) {
		return $url;
	}
	return bit_elc_make_relative( $url );
}, 99 );

add_filter( 'wp_get_attachment_image_src', function ( $image ) {
	if ( ! is_array( $image ) || empty( $image[0] ) ) {
		return $image;
	}
	if ( ! bit_elc_caller_is_elementor_css_render() ) {
		return $image;
	}
	$image[0] = bit_elc_make_relative( $image[0] );
	return $image;
}, 99 );

/**
 * Helper CLI: wp bit-elementor-cache flush|status|info|reset-static
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'bit-elementor-cache', function ( $args ) {
		$action = isset( $args[0] ) ? $args[0] : 'status';
		$base   = BIT_ELEMENTOR_CACHE_DIR_BASE;

		switch ( $action ) {
			case 'status':
			case 'info':
				if ( ! is_dir( $base ) ) {
					\WP_CLI::log( "Cache dir nao existe ainda: {$base}" );
					return;
				}
				$files = 0;
				$bytes = 0;
				$rii   = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS )
				);
				foreach ( $rii as $f ) {
					if ( $f->isFile() ) {
						$files++;
						$bytes += $f->getSize();
					}
				}
				\WP_CLI::log( "Cache dir: {$base}" );
				\WP_CLI::log( "Arquivos: {$files}" );
				\WP_CLI::log( "Tamanho: " . round( $bytes / 1024, 2 ) . " KB" );

				// Mostrar tambem o que o Elementor enxerga
				if ( class_exists( '\\Elementor\\Core\\Files\\Base' ) ) {
					\WP_CLI::log( "\nElementor::Base::get_base_uploads_dir(): "
						. \Elementor\Core\Files\Base::get_base_uploads_dir() );
					\WP_CLI::log( "Elementor::Base::get_base_uploads_url(): "
						. \Elementor\Core\Files\Base::get_base_uploads_url() );
				}
				break;

			case 'flush':
				if ( is_dir( $base ) ) {
					$rii = new RecursiveIteratorIterator(
						new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS ),
						RecursiveIteratorIterator::CHILD_FIRST
					);
					foreach ( $rii as $f ) {
						if ( $f->isDir() ) {
							rmdir( $f->getPathname() );
						} else {
							unlink( $f->getPathname() );
						}
					}
				}
				if ( class_exists( '\\Elementor\\Plugin' ) ) {
					\Elementor\Plugin::$instance->files_manager->clear_cache();
				}
				\WP_CLI::success( 'Cache local flushado e Elementor sinalizado para regenerar' );
				break;

			case 'reset-static':
				// Reset do cache estatico via reflection — ultimo recurso
				if ( ! class_exists( '\\Elementor\\Core\\Files\\Base' ) ) {
					\WP_CLI::error( 'Elementor nao carregado' );
				}
				try {
					$ref  = new ReflectionClass( '\\Elementor\\Core\\Files\\Base' );
					$prop = $ref->getProperty( 'wp_uploads_dir' );
					$prop->setAccessible( true );
					$prop->setValue( null, [] );
					\WP_CLI::success( 'Static cache do Elementor resetado. Proxima chamada ira passar pelo filtro upload_dir.' );
				} catch ( \Exception $e ) {
					\WP_CLI::error( 'Falha: ' . $e->getMessage() );
				}
				break;

			default:
				\WP_CLI::error( "Acao invalida: {$action}. Use: flush | status | info | reset-static" );
		}
	} );
}
