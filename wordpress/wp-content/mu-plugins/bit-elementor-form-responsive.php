<?php
/**
 * Plugin Name: BIT Elementor Form Responsive
 * Plugin URI:  https://bureau-it.com
 * Description: Estende o widget Form do Elementor Pro tornando `form_name`,
 *              `placeholder` e `field_options_empty` device-aware (Desktop/Tablet/Mobile)
 *              via switcher nativo. Permite unificar 2 widgets de form com designs
 *              distintos em 1 widget. CSS pill/retângulo + JS placeholder por breakpoint.
 *              Spec: docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md
 * Version:     1.0.0
 * Author:      Daniel Cambría / Bureau de Tecnologia Ltda.
 * Network:     true
 */

namespace BIT\ElementorFormResponsive;

defined( 'ABSPATH' ) || exit;

const VERSION      = '1.0.0';
const WIDGET_CLASS = 'bit-form-responsive';

/**
 * Adia até plugins carregarem (mu-plugins rodam antes dos plugins normais).
 * Elementor Pro precisa estar disponível para registrar os hooks de controles.
 */
add_action( 'plugins_loaded', function () {
    // Guard: Elementor Pro precisa estar carregado
    if ( ! class_exists( '\ElementorPro\Plugin' ) ) {
        return;
    }

    _register_form_control_hooks();
}, 20 );

/**
 * Registra os hooks que tornam controles do Form responsivos.
 *
 * Estratégia para form_name (controle de nível de widget):
 *   Elementor no modo "duplication" (sem additional_custom_breakpoints) cria
 *   controles irmãos {id}_tablet e {id}_mobile. Usamos remove_control() +
 *   add_responsive_control() para criar os 3 corretamente, replicando o
 *   comportamento nativo de add_responsive_control.
 *
 * Estratégia para placeholder e field_options_empty (sub-controles do repeater):
 *   O repeater form_fields armazena seus fields como um array de schemas no
 *   controle 'form_fields'. Para tornar um field responsivo, precisamos:
 *   1. Atualizar o field base com responsive={max:desktop}, parent=null, inheritors=[..._tablet]
 *   2. Adicionar os fields irmãos {name}_tablet e {name}_mobile com a estrutura
 *      correta (responsive={max:tablet/mobile}, parent={...|..._tablet}).
 *   3. field_options_empty não existe nativamente — é adicionado como controle custom.
 *
 * Validado empiricamente: Elementor additional_custom_breakpoints=INACTIVE, duplication_mode=off.
 * Controles criados após hook disparam no editor com switcher Desktop/Tablet/Mobile.
 */
function _register_form_control_hooks() {
    // Prioridade 10 — roda após o registro nativo dos controles do Elementor Pro (que usa prioridade padrão=10, mas dispara antes pois registrado antes).
    add_action(
        'elementor/element/form/section_form_fields/before_section_end',
        __NAMESPACE__ . '\\_make_form_name_responsive',
        10,
        2
    );

    // Prioridade 11 — roda após _make_form_name_responsive; callbacks separados para manter cada um atômico e removível independentemente.
    add_action(
        'elementor/element/form/section_form_fields/before_section_end',
        __NAMESPACE__ . '\\_make_repeater_fields_responsive',
        11,
        2
    );
}

/**
 * Remove form_name (não-responsivo) e re-registra via add_responsive_control,
 * criando form_name (desktop), form_name_tablet e form_name_mobile.
 *
 * @param \Elementor\Widget_Base $element
 * @param array                  $args
 */
function _make_form_name_responsive( $element, $args ) {
    $ctrl = $element->get_controls( 'form_name' );

    // Guard idempotente: já é responsivo
    if ( ! $ctrl || ! empty( $ctrl['responsive'] ) ) {
        return;
    }

    $element->remove_control( 'form_name' );

    $element->add_responsive_control(
        'form_name',
        [
            'label'   => esc_html__( 'Form Name', 'elementor-pro' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => esc_html__( 'New Form', 'elementor-pro' ),
            'dynamic' => [
                'active' => true,
            ],
        ]
    );
}

/**
 * Torna `placeholder` responsivo e adiciona `field_options_empty` (responsivo)
 * no schema de fields do repeater form_fields.
 *
 * O padrão que o Elementor usa para repeater responsive fields (vide 'width'):
 *   - Campo base:   responsive={max:desktop}, parent=null, inheritors=[{name}_tablet]
 *   - Campo tablet: responsive={max:tablet},  parent={name}, inheritors=[{name}_mobile]
 *   - Campo mobile: responsive={max:mobile},  parent={name}_tablet
 *
 * @param \Elementor\Widget_Base $element
 * @param array                  $args
 */
function _make_repeater_fields_responsive( $element, $args ) {
    $form_fields_ctrl = $element->get_controls( 'form_fields' );
    if ( ! $form_fields_ctrl ) {
        return;
    }

    $fields = $form_fields_ctrl['fields'] ?? [];

    // Guard idempotente: placeholder já tem siblings tablet/mobile e field_options_empty já existe
    if ( isset( $fields['placeholder_tablet'] ) && isset( $fields['field_options_empty'] ) ) {
        return;
    }

    $changed = false;

    // --- placeholder ---
    if ( ! isset( $fields['placeholder_tablet'] ) && isset( $fields['placeholder'] ) ) {
        $ph_base = $fields['placeholder'];

        // Atualizar o campo base com estrutura responsive completa
        $fields['placeholder'] = array_merge( $ph_base, [
            'responsive' => [ 'max' => 'desktop' ],
            'parent'     => null,
            'inheritors' => [ 'placeholder_tablet' ],
        ] );

        $ph_conditions  = $ph_base['conditions'] ?? [];
        $ph_tab_wrapper = $ph_base['tabs_wrapper'] ?? '';
        $ph_inner_tab   = $ph_base['inner_tab'] ?? '';

        $fields['placeholder_tablet'] = [
            'type'         => \Elementor\Controls_Manager::TEXT,
            'tab'          => 'content',
            'label'        => esc_html__( 'Placeholder', 'elementor-pro' ),
            'default'      => '',
            'dynamic'      => [ 'active' => true ],
            'conditions'   => $ph_conditions,
            'tabs_wrapper' => $ph_tab_wrapper,
            'inner_tab'    => $ph_inner_tab,
            'name'         => 'placeholder_tablet',
            'responsive'   => [ 'max' => 'tablet' ],
            'parent'       => 'placeholder',
            'inheritors'   => [ 'placeholder_mobile' ],
        ];

        $fields['placeholder_mobile'] = [
            'type'         => \Elementor\Controls_Manager::TEXT,
            'tab'          => 'content',
            'label'        => esc_html__( 'Placeholder', 'elementor-pro' ),
            'default'      => '',
            'dynamic'      => [ 'active' => true ],
            'conditions'   => $ph_conditions,
            'tabs_wrapper' => $ph_tab_wrapper,
            'inner_tab'    => $ph_inner_tab,
            'name'         => 'placeholder_mobile',
            'responsive'   => [ 'max' => 'mobile' ],
            'parent'       => 'placeholder_tablet',
        ];

        $changed = true;
    }

    // --- field_options_empty (controle custom — não existe nativamente no Elementor Pro) ---
    if ( ! isset( $fields['field_options_empty'] ) ) {
        $select_conditions = [
            'terms' => [
                [
                    'name'     => 'field_type',
                    'operator' => '===',
                    'value'    => 'select',
                ],
            ],
        ];

        $foe_tab_wrapper = $fields['field_options']['tabs_wrapper'] ?? '';
        $foe_inner_tab   = $fields['field_options']['inner_tab'] ?? '';

        $new_field_options_empty = [
            'type'         => \Elementor\Controls_Manager::TEXT,
            'tab'          => 'content',
            'label'        => esc_html__( 'Empty Option', 'elementor-pro' ),
            'default'      => '',
            'dynamic'      => [ 'active' => true ],
            'conditions'   => $select_conditions,
            'tabs_wrapper' => $foe_tab_wrapper,
            'inner_tab'    => $foe_inner_tab,
            'name'         => 'field_options_empty',
            'responsive'   => [ 'max' => 'desktop' ],
            'parent'       => null,
            'inheritors'   => [ 'field_options_empty_tablet' ],
        ];

        $new_field_options_empty_tablet = [
            'type'         => \Elementor\Controls_Manager::TEXT,
            'tab'          => 'content',
            'label'        => esc_html__( 'Empty Option', 'elementor-pro' ),
            'default'      => '',
            'dynamic'      => [ 'active' => true ],
            'conditions'   => $select_conditions,
            'tabs_wrapper' => $foe_tab_wrapper,
            'inner_tab'    => $foe_inner_tab,
            'name'         => 'field_options_empty_tablet',
            'responsive'   => [ 'max' => 'tablet' ],
            'parent'       => 'field_options_empty',
            'inheritors'   => [ 'field_options_empty_mobile' ],
        ];

        $new_field_options_empty_mobile = [
            'type'         => \Elementor\Controls_Manager::TEXT,
            'tab'          => 'content',
            'label'        => esc_html__( 'Empty Option', 'elementor-pro' ),
            'default'      => '',
            'dynamic'      => [ 'active' => true ],
            'conditions'   => $select_conditions,
            'tabs_wrapper' => $foe_tab_wrapper,
            'inner_tab'    => $foe_inner_tab,
            'name'         => 'field_options_empty_mobile',
            'responsive'   => [ 'max' => 'mobile' ],
            'parent'       => 'field_options_empty_tablet',
        ];

        // Inserir field_options_empty imediatamente após field_options no panel (ordem de renderização)
        $rebuilt = [];
        foreach ( $fields as $key => $value ) {
            $rebuilt[ $key ] = $value;
            if ( $key === 'field_options' ) {
                $rebuilt['field_options_empty']        = $new_field_options_empty;
                $rebuilt['field_options_empty_tablet'] = $new_field_options_empty_tablet;
                $rebuilt['field_options_empty_mobile'] = $new_field_options_empty_mobile;
            }
        }
        // Fallback: field_options ausente no schema — anexa ao final para não perder o controle
        if ( ! isset( $rebuilt['field_options_empty'] ) ) {
            $rebuilt['field_options_empty']        = $new_field_options_empty;
            $rebuilt['field_options_empty_tablet'] = $new_field_options_empty_tablet;
            $rebuilt['field_options_empty_mobile'] = $new_field_options_empty_mobile;
        }
        $fields = $rebuilt;

        $changed = true;
    }

    // Salva os fields apenas se houve mutação real (evita write desnecessário)
    if ( ! $changed ) {
        return;
    }

    $element->update_control(
        'form_fields',
        [ 'fields' => $fields ],
        [ 'recursive' => false ]
    );
}
