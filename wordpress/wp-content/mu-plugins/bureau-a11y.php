<?php
/**
 * Plugin Name: Bureau A11y
 * Description: Acessibilidade: alto contraste, leitura de texto (ResponsiveVoice), VLibras (Libras)
 * Version: 1.0.0
 * Author: Bureau de Tecnologia Ltda.
 *
 * @package BureauA11y
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BUREAU_A11Y_VERSION', '1.0.0' );
define( 'BUREAU_A11Y_RV_KEY', 'rS4GfS4a' );
define( 'BUREAU_A11Y_DIR', __DIR__ . '/bureau-a11y/' );
define( 'BUREAU_A11Y_URL', plugin_dir_url( __FILE__ ) . 'bureau-a11y/' );

/**
 * Enqueue CSS and JS assets
 */
add_action( 'wp_enqueue_scripts', 'bureau_a11y_enqueue_assets' );
function bureau_a11y_enqueue_assets() {
    if ( is_admin() ) {
        return;
    }

    wp_enqueue_style(
        'bureau-a11y',
        BUREAU_A11Y_URL . 'bureau-a11y.css',
        [],
        BUREAU_A11Y_VERSION
    );

    wp_enqueue_script(
        'bureau-a11y',
        BUREAU_A11Y_URL . 'bureau-a11y.js',
        [],
        BUREAU_A11Y_VERSION,
        true
    );

    wp_localize_script( 'bureau-a11y', 'bureauA11y', [
        'rvKey' => BUREAU_A11Y_RV_KEY,
    ] );

    wp_enqueue_script(
        'vlibras',
        'https://vlibras.gov.br/app/vlibras-plugin.js',
        [],
        null,
        true
    );
}

/**
 * Render the a11y buttons HTML in the footer
 */
add_action( 'wp_footer', 'bureau_a11y_render_buttons', 50 );
function bureau_a11y_render_buttons() {
    if ( is_admin() ) {
        return;
    }
    ?>
    <aside data-aside="a11yButtons" id="a11yButtons">
        <section data-aside-section="Play / Pause Button">
            <button data-id="a11yPlayButton" class="a11y-button a11y-group a11y-button--play" id="a11y-Play-Stop-Button" tabindex="0" aria-label="Tocar texto selecionado">
                <svg xmlns="http://www.w3.org/2000/svg" xml:space="preserve" viewBox="0 0 414 412">
                    <path d="M204.11 0C91.388 0 0 91.388 0 204.111c0 112.725 91.388 204.11 204.11 204.11 112.729 0 204.11-91.385 204.11-204.11C408.221 91.388 316.839 0 204.11 0zm82.437 229.971-126.368 72.471c-17.003 9.75-30.781 1.763-30.781-17.834V140.012c0-19.602 13.777-27.575 30.781-17.827l126.368 72.466c17.004 9.752 17.004 25.566 0 35.32z" />
                    <rect x="99" y="100" width="220" height="220" rx="30"></rect>
                </svg>
            </button>
        </section>
        <section data-aside-section="Main button: toggles a11y ON/OFF">
            <button id="ucpa-a11y-newbutton" class="a11y-button a11y-button--main" tabindex="0" aria-label="Toggle Accessibility Mode">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                    <path d="M256,112a56,56,0,1,1,56-56A56.06,56.06,0,0,1,256,112Z"></path>
                    <path d="M432,112.8l-.45.12h0l-.42.13c-1,.28-2,.58-3,.89-18.61,5.46-108.93,30.92-172.56,30.92-59.13,0-141.28-22-167.56-29.47a73.79,73.79,0,0,0-8-2.58c-19-5-32,14.3-32,31.94,0,17.47,15.7,25.79,31.55,31.76v.28l95.22,29.74c9.73,3.73,12.33,7.54,13.6,10.84,4.13,10.59.83,31.56-.34,38.88l-5.8,45L150.05,477.44q-.15.72-.27,1.47l-.23,1.27h0c-2.32,16.15,9.54,31.82,32,31.82,19.6,0,28.25-13.53,32-31.94h0s28-157.57,42-157.57,42.84,157.57,42.84,157.57h0c3.75,18.41,12.4,31.94,32,31.94,22.52,0,34.38-15.74,32-31.94-.21-1.38-.46-2.74-.76-4.06L329,301.27l-5.79-45c-4.19-26.21-.82-34.87.32-36.9a1.09,1.09,0,0,0,.08-.15c1.08-2,6-6.48,17.48-10.79l89.28-31.21a16.9,16.9,0,0,0,1.62-.52c16-6,32-14.3,32-31.93S451,107.81,432,112.8Z"></path>
                </svg>
            </button>
        </section>
        <section data-aside-section="VLIBRAS">
            <div vw class="enabled">
                <div vw-access-button class="active"></div>
                <div vw-plugin-wrapper>
                    <div class="vw-plugin-top-wrapper"></div>
                </div>
            </div>
        </section>
        <section data-aside-section="a11yBackToTopButton">
            <button data-id="a11yBackToTopButton" class="a11y-button a11y-button--top" id="a11y-Back-To-Top-Button" tabindex="0" aria-label="Scroll To Top">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 115.4 122.88">
                    <path d="M24.94 67.88A14.66 14.66 0 0 1 4.38 47L47.83 4.21a14.66 14.66 0 0 1 20.56 0L111 46.15a14.66 14.66 0 0 1-20.54 20.91l-18-17.69-.29 59.17c-.1 19.28-29.42 19-29.33-.25l.3-58.29-18.2 17.88Z" />
                </svg>
            </button>
        </section>
    </aside>
    <?php
}
