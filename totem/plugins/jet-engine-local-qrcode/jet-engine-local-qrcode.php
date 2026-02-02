<?php
/**
 * Plugin Name: JetEngine Local QR Code
 * Description: Substitui a geração de QR code do JetEngine por método local, sem depender de API externa.
 * Version: 3.0.0
 * Author: Daniel Cambría + Warp
 */

// Se este arquivo for chamado diretamente, abortar.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Inclui a biblioteca QR Code
require_once __DIR__ . '/vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class JetEngine_Local_QRCode_Proxy {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Intercepta a inicialização do módulo QR Code
		add_action( 'init', array( $this, 'replace_qr_module' ), 9999 );
	}

	public function replace_qr_module() {
		
		// Obtém o módulo QR Code do JetEngine
		$qr_module = jet_engine()->modules->get_module( 'qr-code' );
		
		if ( ! $qr_module ) {
			error_log('[JetEngine Local QR Code] Módulo QR Code não encontrado');
			return;
		}

		// Usa closure binding para substituir o método
		$new_method = function( $value = null, $size = 150 ) {
			return JetEngine_Local_QRCode_Proxy::generate_local_qr_code( $value, $size );
		};

		// Cria uma nova classe que estende a original
		$reflection = new ReflectionClass( $qr_module );
		
		// Substitui a propriedade qr_code_api para forçar uso local
		$property = $reflection->getProperty( 'qr_code_api' );
		$property->setAccessible( true );
		$property->setValue( $qr_module, 'LOCAL' );

		// Adiciona um filtro que intercepta a resposta do wp_remote_get
		add_filter( 'pre_http_request', array( $this, 'intercept_qr_api_call' ), 10, 3 );

		error_log('[JetEngine Local QR Code] Módulo interceptado com sucesso');
	}

	/**
	 * Intercepta chamadas HTTP para a API de QR Code
	 */
	public function intercept_qr_api_call( $preempt, $parsed_args, $url ) {
		
		// Log todas as chamadas HTTP para debug
		if ( defined('WP_DEBUG') && WP_DEBUG ) {
			error_log('[JetEngine Local QR Code] HTTP request detectado: ' . $url);
		}
		
		// Verifica se é uma chamada para a API de QR Code
		if ( strpos( $url, 'qrserver.com' ) !== false || strpos( $url, 'LOCAL' ) !== false ) {
			
			// Extrai os parâmetros da URL
			$parsed_url = parse_url( $url );
			parse_str( $parsed_url['query'] ?? '', $params );
			
			$size = 150;
			$data = '';
			
			if ( isset( $params['size'] ) ) {
				$dimensions = explode( 'x', $params['size'] );
				$size = intval( $dimensions[0] );
			}
			
			if ( isset( $params['data'] ) ) {
				$data = urldecode( $params['data'] );
			}

			if ( defined('WP_DEBUG') && WP_DEBUG ) {
				error_log('[JetEngine Local QR Code] Interceptando chamada API. Tamanho: ' . $size . ', Dados: ' . substr($data, 0, 50));
			}

			// Gera o QR code localmente
			$svg = self::generate_local_qr_code( $data, $size );

			// Retorna resposta simulada como se fosse do wp_remote_get
			return array(
				'headers'  => array( 'content-type' => 'image/svg+xml' ),
				'body'     => $svg,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		return $preempt;
	}

	/**
	 * Gera QR code localmente usando chillerlan/php-qrcode
	 */
	public static function generate_local_qr_code( $value = null, $size = 150 ) {

		$hash   = 'local_qr_' . md5( $size . $value );
		$cached = get_transient( $hash );

		if ( $cached ) {
			return $cached;
		}

		// Fix encoding issue
		$value = str_replace( '&amp;', '&', $value );

		try {
			$options = new QROptions([
				'version'           => 5,
				'outputType'        => QRCode::OUTPUT_MARKUP_SVG,
				'outputBase64'      => false,
				'eccLevel'          => QRCode::ECC_L,
				'svgViewBoxSize'    => $size,
				'addQuietzone'      => true,
				'moduleValues'      => [
					// Define valores para os módulos (1 = escuro, 0 = claro)
					1536 => '#000',  // Escuro
					6    => '#000',  // Escuro
				],
				'drawLightModules'  => false,  // Não desenha módulos claros
			]);
			
			$qrcode = new QRCode($options);
			$svg = $qrcode->render($value);
			
			// Remove classes CSS e adiciona estilos inline forçados
			$svg = preg_replace('/class="[^"]*"/', '', $svg);
			$svg = str_replace('fill="#000"', 'fill="#000" style="fill:#000 !important"', $svg);
			
			// Adiciona retângulo branco de fundo e estilos
			$svg = str_replace(
				'<svg',
				'<svg style="background:#fff !important; display:block !important; width:100% !important; height:auto !important"',
				$svg
			);
			
			// Adiciona retângulo branco como primeiro elemento
			$svg = str_replace(
				'preserveAspectRatio="xMidYMid">',
				'preserveAspectRatio="xMidYMid"><rect width="100%" height="100%" fill="#fff" style="fill:#fff !important"/>',
				$svg
			);

			if ( defined('WP_DEBUG') && WP_DEBUG ) {
				error_log('[JetEngine Local QR Code] QR code gerado localmente. Tamanho: ' . $size);
			}

			set_transient( $hash, $svg, DAY_IN_SECONDS );

			return $svg;

		} catch ( Exception $e ) {
			error_log( 'JetEngine Local QRCode: Erro - ' . $e->getMessage() );
			
			// Fallback simples
			return sprintf(
				'<svg width="%1$d" height="%1$d" xmlns="http://www.w3.org/2000/svg">
					<rect width="100%%" height="100%%" fill="#f0f0f0"/>
					<text x="50%%" y="50%%" text-anchor="middle" font-size="14" fill="#333">
						Erro ao gerar QR Code
					</text>
				</svg>',
				$size
			);
		}
	}
}

// Inicializa o plugin após o WordPress carregar
add_action( 'plugins_loaded', function() {
	if ( class_exists( 'Jet_Engine' ) ) {
		JetEngine_Local_QRCode_Proxy::get_instance();
	}
}, 999 );
