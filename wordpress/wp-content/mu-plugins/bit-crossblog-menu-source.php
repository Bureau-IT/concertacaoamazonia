<?php
/**
 * Plugin Name: BIT Cross-Blog Menu Source
 * Description: Injeta páginas e CPTs de outros blogs do multisite como fonte disponível
 *              no editor de menus (Aparência > Menus) do Blog 1. Os itens são inseridos
 *              como links customizados com URL absoluta do blog de origem.
 * Version: 1.0.0
 * Author: Bureau de Tecnologia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Só executa no admin, no editor de menus, e apenas no Blog 1
add_action( 'load-nav-menus.php', function () {
	if ( get_current_blog_id() !== 1 ) {
		return;
	}

	/**
	 * Configuração dos blogs remotos a expor no editor de menus do Blog 1.
	 * Cada entrada: blog_id => array de post_types a listar.
	 *
	 * @filter bit_crossblog_menu_sources
	 */
	$sources = apply_filters( 'bit_crossblog_menu_sources', [
		2 => [ 'page', 'linha-das-artes' ],
	] );

	foreach ( $sources as $blog_id => $post_types ) {
		switch_to_blog( $blog_id );
		$blog_name = get_bloginfo( 'name' );
		restore_current_blog();

		foreach ( $post_types as $post_type ) {
			switch_to_blog( $blog_id );
			$pto = get_post_type_object( $post_type );
			restore_current_blog();

			if ( ! $pto ) {
				continue;
			}

			$label      = sprintf(
				/* translators: 1: post type label, 2: blog name */
				__( '%1$s (Blog: %2$s)', 'bit' ),
				$pto->labels->name,
				$blog_name
			);
			$meta_box_id = "bit-crossblog-{$blog_id}-{$post_type}";

			add_meta_box(
				$meta_box_id,
				$label,
				'bit_crossblog_menu_source_meta_box',
				'nav-menus',
				'side',
				'default',
				[
					'blog_id'   => $blog_id,
					'post_type' => $post_type,
				]
			);
		}
	}
} );

/**
 * Renderiza a meta box com o checklist de posts do blog remoto.
 * Imita o comportamento de wp_nav_menu_item_post_type_meta_box().
 *
 * @param mixed $object Não utilizado.
 * @param array $meta_box Dados da meta box, incluindo 'args' com blog_id e post_type.
 */
function bit_crossblog_menu_source_meta_box( $object, $meta_box ) {
	$blog_id   = (int) $meta_box['args']['blog_id'];
	$post_type = $meta_box['args']['post_type'];

	switch_to_blog( $blog_id );

	$posts = get_posts( [
		'post_type'      => $post_type,
		'post_status'    => 'publish',
		'posts_per_page' => 100,
		'orderby'        => 'title',
		'order'          => 'ASC',
	] );

	if ( empty( $posts ) ) {
		restore_current_blog();
		echo '<p>' . esc_html__( 'Nenhum item publicado encontrado.', 'bit' ) . '</p>';
		return;
	}

	// Tabs: Mais Recentes | Exibir Tudo (padrão WP)
	$tab_id = "bit-crossblog-{$blog_id}-{$post_type}";
	?>
	<div id="<?php echo esc_attr( $tab_id ); ?>" class="posttypediv">
		<div id="tabs-panel-<?php echo esc_attr( $tab_id ); ?>-all" class="tabs-panel tabs-panel-active">
			<ul id="<?php echo esc_attr( $tab_id ); ?>checklist" class="categorychecklist form-no-clear">
				<?php
				foreach ( $posts as $post ) {
					$url   = get_permalink( $post->ID );
					$title = $post->post_title ?: __( '(sem título)', 'bit' );

					// Usar ID negativo para evitar colisão com IDs do blog 1.
					// Formato: -{blog_id}{post_id} como identificador único no form.
					$form_id = - absint( "{$blog_id}0{$post->ID}" );
					?>
					<li>
						<label class="menu-item-title">
							<input type="checkbox"
								class="menu-item-checkbox"
								name="menu-item[<?php echo esc_attr( $form_id ); ?>][menu-item-object-id]"
								value="<?php echo esc_attr( $form_id ); ?>" />
							<?php echo esc_html( $title ); ?>
						</label>
						<input type="hidden"
							name="menu-item[<?php echo esc_attr( $form_id ); ?>][menu-item-db-id]"
							value="0" />
						<input type="hidden"
							name="menu-item[<?php echo esc_attr( $form_id ); ?>][menu-item-object]"
							value="custom" />
						<input type="hidden"
							name="menu-item[<?php echo esc_attr( $form_id ); ?>][menu-item-parent-id]"
							value="0" />
						<input type="hidden"
							name="menu-item[<?php echo esc_attr( $form_id ); ?>][menu-item-type]"
							value="custom" />
						<input type="hidden"
							name="menu-item[<?php echo esc_attr( $form_id ); ?>][menu-item-title]"
							value="<?php echo esc_attr( $title ); ?>" />
						<input type="hidden"
							name="menu-item[<?php echo esc_attr( $form_id ); ?>][menu-item-url]"
							value="<?php echo esc_attr( $url ); ?>" />
						<input type="hidden"
							name="menu-item[<?php echo esc_attr( $form_id ); ?>][menu-item-description]"
							value="" />
						<input type="hidden"
							name="menu-item[<?php echo esc_attr( $form_id ); ?>][menu-item-attr-title]"
							value="" />
						<input type="hidden"
							name="menu-item[<?php echo esc_attr( $form_id ); ?>][menu-item-target]"
							value="" />
						<input type="hidden"
							name="menu-item[<?php echo esc_attr( $form_id ); ?>][menu-item-classes]"
							value="" />
						<input type="hidden"
							name="menu-item[<?php echo esc_attr( $form_id ); ?>][menu-item-xfn]"
							value="" />
					</li>
					<?php
				}
				?>
			</ul>
		</div>
		<p class="button-controls wp-clearfix" data-items-type="<?php echo esc_attr( $tab_id ); ?>">
			<span class="list-controls">
				<a href="<?php echo esc_url( admin_url( 'nav-menus.php?page-tab=all&selectall=1#' . $tab_id ) ); ?>"
					class="select-all aria-button-if-js">
					<?php esc_html_e( 'Selecionar tudo', 'bit' ); ?>
				</a>
			</span>
			<span class="add-to-menu">
				<input type="submit"
					class="button submit-add-to-menu right"
					value="<?php esc_attr_e( 'Adicionar ao menu', 'bit' ); ?>"
					name="add-post-type-menu-item"
					id="submit-<?php echo esc_attr( $tab_id ); ?>" />
				<span class="spinner"></span>
			</span>
		</p>
	</div>
	<?php

	restore_current_blog();
}
