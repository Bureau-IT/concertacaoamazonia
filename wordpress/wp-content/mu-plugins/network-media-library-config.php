<?php
/**
 * Network Media Library - Configuração
 *
 * Define o blog_id=1 (site principal) como biblioteca central de mídia,
 * compartilhando todas as mídias com os subsites da rede.
 *
 * @package ConcertacaoAmazonica
 * @author  Bureau IT
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || die();

add_filter( 'network-media-library/site_id', function() {
    return 1;
} );
