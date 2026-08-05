<?php
/**
 * Dynamic Tag "Eixos do Estudo" para o Elementor.
 *
 * Permite usar um widget nativo (Text Editor / Heading) com todas as abas de
 * Estilo do Elementor, em vez do widget Shortcode (que não tem Estilo).
 *
 * O conteúdo (eixos + links idênticos à espiral) vem de BIT_Estudo_Eixos;
 * a aparência é controlada inteiramente pelo widget Elementor que hospeda a tag.
 *
 * Parte do mu-plugin bit-estudo-eixos.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( class_exists( '\Elementor\Core\DynamicTags\Tag' ) && ! class_exists( 'BIT_Estudo_Eixos_Tag' ) ) :

class BIT_Estudo_Eixos_Tag extends \Elementor\Core\DynamicTags\Tag {

	public function get_name() {
		return 'bit-estudo-eixos';
	}

	public function get_title() {
		return __( 'Eixos do Estudo', 'bit' );
	}

	public function get_group() {
		return 'post';
	}

	public function get_categories() {
		// TEXT_CATEGORY: renderiza dentro de widgets de texto (Text Editor, Heading).
		return [ \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY ];
	}

	/**
	 * Controles editáveis na interface do Elementor (aba Conteúdo da tag).
	 */
	protected function register_controls() {
		$this->add_control( 'prefix', [
			'label'       => __( 'Texto antes', 'bit' ),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'default'     => '',
			'placeholder' => __( 'Aparece nos eixos: ', 'bit' ),
			'description' => __( 'Vazio = "Aparece nos eixos:" (PT) / "Appears in axes:" (EN).', 'bit' ),
		] );

		$this->add_control( 'sep', [
			'label'   => __( 'Separador', 'bit' ),
			'type'    => \Elementor\Controls_Manager::TEXT,
			'default' => ', ',
		] );

		$this->add_control( 'linked', [
			'label'        => __( 'Eixos clicáveis', 'bit' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => __( 'Sim', 'bit' ),
			'label_off'    => __( 'Não', 'bit' ),
			'return_value' => 'yes',
			'default'      => 'yes',
		] );
	}

	/**
	 * Saída da tag. Delega o render para BIT_Estudo_Eixos (fonte única).
	 */
	public function render() {
		if ( ! class_exists( 'BIT_Estudo_Eixos' ) ) {
			return;
		}
		$settings = $this->get_settings();
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}
		$atts = [
			'id'     => 0, // post atual do contexto Elementor
			'prefix' => isset( $settings['prefix'] ) ? (string) $settings['prefix'] : '',
			'sep'    => isset( $settings['sep'] ) && $settings['sep'] !== '' ? (string) $settings['sep'] : ', ',
			'linked' => ( isset( $settings['linked'] ) && $settings['linked'] === 'yes' ) ? 'yes' : 'no',
		];
		// BIT_Estudo_Eixos::render() é público e reusa a lógica do shortcode.
		echo BIT_Estudo_Eixos::instance()->render( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- saída já escapada internamente
	}
}

endif;
