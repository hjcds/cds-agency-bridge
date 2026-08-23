<?php
/**
 * Plugin Name: CDS Agency Bridge
 * Plugin URI:  https://clouddigital.solutions
 * Description: Authenticated REST endpoints for agency automation - SEO fields, CPT archive SEO, image alt text, AVIF diagnostics, Elementor content editing, maintenance. Updates itself from the agency manifest.
 * Version:     1.1.0
 * Author:      Cloud Digital Solutions
 * Author URI:  https://clouddigital.solutions
 * License:     GPL-2.0-or-later
 * Requires PHP: 7.4
 *
 * INSTALL: upload the zip via Plugins > Add New > Upload, or drop the
 * cds-agency-bridge folder in wp-content/plugins/ and activate.
 *
 * IMPORTANT: if an older copy exists in wp-content/mu-plugins/, DELETE IT
 * FIRST. Two copies loading at once causes a fatal error on redeclared
 * functions.
 *
 * AUTH: WordPress Application Passwords. Optional second factor in wp-config.php:
 *   define( 'CDS_BRIDGE_SECRET', 'long-random-string' );
 * ...then every request must also send header  X-CDS-Secret: long-random-string
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'CDS_BRIDGE_DISABLED' ) && CDS_BRIDGE_DISABLED ) {
	return;
}

define( 'CDS_BRIDGE_VERSION', '1.1.0' );
define( 'CDS_BRIDGE_NS', 'cds/v1' );

/* -------------------------------------------------------------------------
 * Auth helpers
 * ---------------------------------------------------------------------- */

/**
 * Capability + optional shared-secret gate.
 *
 * @param string $level read|write|admin
 * @return true|WP_Error
 */
function cds_bridge_guard( $level ) {
	if ( defined( 'CDS_BRIDGE_SECRET' ) && CDS_BRIDGE_SECRET ) {
		$sent = isset( $_SERVER['HTTP_X_CDS_SECRET'] ) ? (string) $_SERVER['HTTP_X_CDS_SECRET'] : '';
		if ( ! hash_equals( (string) CDS_BRIDGE_SECRET, $sent ) ) {
			return new WP_Error( 'cds_bridge_secret', 'Missing or invalid X-CDS-Secret header.', array( 'status' => 403 ) );
		}
	}

	$map = array(
		'read'  => 'edit_posts',
		'write' => 'edit_others_posts',
		'admin' => 'manage_options',
	);
	$cap = isset( $map[ $level ] ) ? $map[ $level ] : 'manage_options';

	if ( ! current_user_can( $cap ) ) {
		return new WP_Error( 'cds_bridge_forbidden', 'Authenticated user lacks the ' . $cap . ' capability.', array( 'status' => 403 ) );
	}

	return true;
}

/**
 * Route registration shorthand.
 */
function cds_bridge_route( $route, $methods, $callback, $level = 'read', $args = array() ) {
	register_rest_route(
		CDS_BRIDGE_NS,
		$route,
		array(
			'methods'             => $methods,
			'callback'            => $callback,
			'args'                => $args,
			'permission_callback' => function () use ( $level ) {
				return cds_bridge_guard( $level );
			},
		)
	);
}

add_action( 'rest_api_init', 'cds_bridge_register_routes' );

function cds_bridge_register_routes() {
	// Diagnostics / maintenance.
	cds_bridge_route( '/health', 'GET', 'cds_bridge_health', 'read' );
	cds_bridge_route( '/updates', 'GET', 'cds_bridge_updates', 'admin' );
	cds_bridge_route( '/cache/flush', 'POST', 'cds_bridge_cache_flush', 'admin' );

	// Content.
	cds_bridge_route( '/posts', 'GET', 'cds_bridge_list_posts', 'read' );
	cds_bridge_route( '/post/(?P<id>\d+)', 'GET', 'cds_bridge_get_post', 'read' );
	cds_bridge_route( '/post/(?P<id>\d+)', 'POST', 'cds_bridge_update_post', 'write' );

	// SEO.
	cds_bridge_route( '/seo/audit', 'GET', 'cds_bridge_seo_audit', 'read' );
	cds_bridge_route( '/seo/(?P<id>\d+)', 'GET', 'cds_bridge_get_seo', 'read' );
	cds_bridge_route( '/seo/(?P<id>\d+)', 'POST', 'cds_bridge_set_seo', 'write' );

	// Media / alt text.
	cds_bridge_route( '/media/audit', 'GET', 'cds_bridge_media_audit', 'read' );
	cds_bridge_route( '/media/avif-report', 'GET', 'cds_bridge_avif_report', 'read' );
	cds_bridge_route( '/media/(?P<id>\d+)/alt', 'POST', 'cds_bridge_set_alt', 'write' );
	cds_bridge_route( '/media/alt/bulk', 'POST', 'cds_bridge_set_alt_bulk', 'write' );

	// Images hardcoded in markup.
	cds_bridge_route( '/content/image-audit', 'GET', 'cds_bridge_content_image_audit', 'read' );
	cds_bridge_route( '/content/fix-alt', 'POST', 'cds_bridge_fix_hardcoded_alt', 'write' );

	// Elementor.
	cds_bridge_route( '/elementor/(?P<id>\d+)/text', 'GET', 'cds_bridge_elementor_map', 'read' );
	cds_bridge_route( '/elementor/(?P<id>\d+)/text', 'POST', 'cds_bridge_elementor_edit', 'write' );
	cds_bridge_route( '/elementor/(?P<id>\d+)/rollback', 'POST', 'cds_bridge_elementor_rollback', 'write' );
	cds_bridge_route( '/elementor/flush-css', 'POST', 'cds_bridge_elementor_flush', 'admin' );
}

/* -------------------------------------------------------------------------
 * Health
 * ---------------------------------------------------------------------- */

function cds_bridge_health() {
	global $wp_version, $wpdb;

	$theme = wp_get_theme();

	$missing_alt = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
		 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
		 AND ( m.meta_value IS NULL OR TRIM(m.meta_value) = '' )"
	);

	$by_mime = $wpdb->get_results(
		"SELECT post_mime_type AS mime, COUNT(*) AS total FROM {$wpdb->posts}
		 WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'
		 GROUP BY post_mime_type",
		ARRAY_A
	);

	$gd      = function_exists( 'gd_info' ) ? gd_info() : array();
	$imagick = class_exists( 'Imagick' ) ? @Imagick::queryFormats( 'AVIF' ) : array();

	return rest_ensure_response(
		array(
			'bridge_version' => CDS_BRIDGE_VERSION,
			'site'           => array(
				'name'        => get_bloginfo( 'name' ),
				'url'         => home_url(),
				'wp_version'  => $wp_version,
				'php_version' => PHP_VERSION,
				'multisite'   => is_multisite(),
				'theme'       => $theme ? $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) : null,
				'indexable'   => (bool) get_option( 'blog_public' ),
				'permalinks'  => get_option( 'permalink_structure' ),
			),
			'plugins'        => array(
				'elementor'     => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
				'elementor_pro' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
				'seo'           => cds_bridge_seo_plugin(),
				'aioseo'        => defined( 'AIOSEO_VERSION' ) ? AIOSEO_VERSION : null,
				'woocommerce'   => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			),
			'images'         => array(
				'editor_supports_avif' => wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ),
				'editor_supports_webp' => wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ),
				'avif_upload_allowed'  => in_array( 'image/avif', (array) get_allowed_mime_types(), true ),
				'gd_avif'              => ! empty( $gd['AVIF Support'] ),
				'gd_webp'              => ! empty( $gd['WebP Support'] ),
				'imagick_avif'         => ! empty( $imagick ),
				'by_mime'              => $by_mime,
				'missing_alt_total'    => $missing_alt,
			),
			'counts'         => array(
				'posts'       => (int) wp_count_posts( 'post' )->publish,
				'pages'       => (int) wp_count_posts( 'page' )->publish,
				'attachments' => (int) wp_count_posts( 'attachment' )->inherit,
			),
		)
	);
}

function cds_bridge_updates() {
	require_once ABSPATH . 'wp-admin/includes/update.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';

	wp_update_plugins();
	wp_update_themes();

	$plugins = array();
	foreach ( (array) get_plugin_updates() as $file => $data ) {
		$plugins[] = array(
			'name'    => isset( $data->Name ) ? $data->Name : $file,
			'file'    => $file,
			'current' => isset( $data->Version ) ? $data->Version : null,
			'new'     => isset( $data->update->new_version ) ? $data->update->new_version : null,
		);
	}

	$themes = array();
	foreach ( (array) get_theme_updates() as $slug => $data ) {
		$themes[] = array(
			'name'    => $data->get( 'Name' ),
			'slug'    => $slug,
			'current' => $data->get( 'Version' ),
			'new'     => isset( $data->update['new_version'] ) ? $data->update['new_version'] : null,
		);
	}

	$core     = get_core_updates();
	$core_new = ( is_array( $core ) && ! empty( $core[0] ) && 'upgrade' === $core[0]->response ) ? $core[0]->current : null;

	return rest_ensure_response(
		array(
			'core_update_available' => $core_new,
			'plugins'               => $plugins,
			'themes'                => $themes,
			'php_version'           => PHP_VERSION,
		)
	);
}

function cds_bridge_cache_flush() {
	$done = array();

	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
		$done[] = 'wp-rocket';
	}
	if ( has_action( 'litespeed_purge_all' ) ) {
		do_action( 'litespeed_purge_all' );
		$done[] = 'litespeed';
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
		$done[] = 'w3-total-cache';
	}
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
		$done[] = 'wp-super-cache';
	}
	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
		$done[] = 'siteground';
	}
	if ( class_exists( 'autoptimizeCache' ) ) {
		autoptimizeCache::clearall();
		$done[] = 'autoptimize';
	}
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
		$done[] = 'elementor-css';
	}

	wp_cache_flush();
	$done[] = 'object-cache';

	return rest_ensure_response( array( 'flushed' => $done ) );
}

/* -------------------------------------------------------------------------
 * Posts
 * ---------------------------------------------------------------------- */

function cds_bridge_list_posts( WP_REST_Request $req ) {
	$q = new WP_Query(
		array(
			'post_type'        => $req->get_param( 'post_type' ) ? explode( ',', $req->get_param( 'post_type' ) ) : array( 'page', 'post' ),
			'post_status'      => $req->get_param( 'status' ) ? $req->get_param( 'status' ) : 'publish',
			's'                => $req->get_param( 'search' ),
			'posts_per_page'   => min( 200, max( 1, (int) ( $req->get_param( 'per_page' ) ?: 50 ) ) ),
			'paged'            => max( 1, (int) ( $req->get_param( 'page' ) ?: 1 ) ),
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'suppress_filters' => true,
		)
	);

	$items = array();
	foreach ( $q->posts as $p ) {
		$seo     = cds_bridge_seo_read( $p->ID );
		$items[] = array(
			'id'            => $p->ID,
			'type'          => $p->post_type,
			'title'         => get_the_title( $p ),
			'slug'          => $p->post_name,
			'link'          => get_permalink( $p ),
			'status'        => $p->post_status,
			'modified'      => $p->post_modified_gmt,
			'word_count'    => str_word_count( wp_strip_all_tags( $p->post_content ) ),
			'is_elementor'  => (bool) get_post_meta( $p->ID, '_elementor_edit_mode', true ),
			'seo_title'     => $seo['title'],
			'seo_desc'      => $seo['description'],
			'seo_noindex'   => $seo['noindex'],
		);
	}

	return rest_ensure_response(
		array(
			'total' => (int) $q->found_posts,
			'pages' => (int) $q->max_num_pages,
			'items' => $items,
		)
	);
}

function cds_bridge_get_post( WP_REST_Request $req ) {
	$id = (int) $req['id'];
	$p  = get_post( $id );
	if ( ! $p ) {
		return new WP_Error( 'cds_not_found', 'Post not found.', array( 'status' => 404 ) );
	}

	return rest_ensure_response(
		array(
			'id'           => $p->ID,
			'type'         => $p->post_type,
			'status'       => $p->post_status,
			'title'        => $p->post_title,
			'slug'         => $p->post_name,
			'link'         => get_permalink( $p ),
			'excerpt'      => $p->post_excerpt,
			'content'      => $p->post_content,
			'is_elementor' => (bool) get_post_meta( $p->ID, '_elementor_edit_mode', true ),
			'seo'          => cds_bridge_seo_read( $p->ID ),
			'images'       => cds_bridge_scan_img_tags( $p->post_content ),
		)
	);
}

function cds_bridge_update_post( WP_REST_Request $req ) {
	$id = (int) $req['id'];
	if ( ! get_post( $id ) ) {
		return new WP_Error( 'cds_not_found', 'Post not found.', array( 'status' => 404 ) );
	}

	$data = array( 'ID' => $id );
	foreach ( array( 'title', 'content', 'excerpt', 'status', 'slug' ) as $field ) {
		$val = $req->get_param( $field );
		if ( null !== $val ) {
			$key          = ( 'slug' === $field ) ? 'post_name' : ( ( 'status' === $field ) ? 'post_status' : 'post_' . $field );
			$data[ $key ] = $val;
		}
	}

	if ( count( $data ) === 1 ) {
		return new WP_Error( 'cds_no_fields', 'No updatable fields supplied.', array( 'status' => 400 ) );
	}

	$res = wp_update_post( $data, true );
	if ( is_wp_error( $res ) ) {
		return $res;
	}

	return rest_ensure_response( array( 'updated' => $id, 'link' => get_permalink( $id ) ) );
}

/* -------------------------------------------------------------------------
 * SEO adapter (AIOSEO primary, Yoast / Rank Math fallback)
 * ---------------------------------------------------------------------- */

function cds_bridge_seo_plugin() {
	if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
		return 'aioseo';
	}
	if ( defined( 'WPSEO_VERSION' ) ) {
		return 'yoast';
	}
	if ( class_exists( 'RankMath' ) ) {
		return 'rankmath';
	}
	return 'none';
}

function cds_bridge_seo_read( $post_id ) {
	global $wpdb;

	$out = array(
		'plugin'      => cds_bridge_seo_plugin(),
		'title'       => null,
		'description' => null,
		'canonical'   => null,
		'noindex'     => false,
		'og_title'    => null,
		'og_desc'     => null,
		'keyphrase'   => null,
	);

	switch ( $out['plugin'] ) {
		case 'aioseo':
			$table = $wpdb->prefix . 'aioseo_posts';
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A );
			if ( $row ) {
				$out['title']       = $row['title'] !== '' ? $row['title'] : null;
				$out['description'] = $row['description'] !== '' ? $row['description'] : null;
				$out['canonical']   = isset( $row['canonical_url'] ) && $row['canonical_url'] !== '' ? $row['canonical_url'] : null;
				$out['noindex']     = empty( $row['robots_default'] ) && ! empty( $row['robots_noindex'] );
				$out['og_title']    = isset( $row['og_title'] ) && $row['og_title'] !== '' ? $row['og_title'] : null;
				$out['og_desc']     = isset( $row['og_description'] ) && $row['og_description'] !== '' ? $row['og_description'] : null;
				if ( ! empty( $row['keyphrases'] ) ) {
					$kp = json_decode( $row['keyphrases'], true );
					if ( isset( $kp['focus']['keyphrase'] ) ) {
						$out['keyphrase'] = $kp['focus']['keyphrase'];
					}
				}
			}
			break;

		case 'yoast':
			$out['title']       = get_post_meta( $post_id, '_yoast_wpseo_title', true ) ?: null;
			$out['description'] = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) ?: null;
			$out['canonical']   = get_post_meta( $post_id, '_yoast_wpseo_canonical', true ) ?: null;
			$out['noindex']     = '1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
			$out['keyphrase']   = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ) ?: null;
			break;

		case 'rankmath':
			$out['title']       = get_post_meta( $post_id, 'rank_math_title', true ) ?: null;
			$out['description'] = get_post_meta( $post_id, 'rank_math_description', true ) ?: null;
			$out['canonical']   = get_post_meta( $post_id, 'rank_math_canonical_url', true ) ?: null;
			$robots             = get_post_meta( $post_id, 'rank_math_robots', true );
			$out['noindex']     = is_array( $robots ) && in_array( 'noindex', $robots, true );
			$out['keyphrase']   = get_post_meta( $post_id, 'rank_math_focus_keyword', true ) ?: null;
			break;
	}

	return $out;
}

function cds_bridge_get_seo( WP_REST_Request $req ) {
	$id = (int) $req['id'];
	if ( ! get_post( $id ) ) {
		return new WP_Error( 'cds_not_found', 'Post not found.', array( 'status' => 404 ) );
	}

	$seo = cds_bridge_seo_read( $id );

	return rest_ensure_response(
		array(
			'id'          => $id,
			'title'       => get_the_title( $id ),
			'link'        => get_permalink( $id ),
			'seo'         => $seo,
			'title_len'   => $seo['title'] ? mb_strlen( $seo['title'] ) : 0,
			'desc_len'    => $seo['description'] ? mb_strlen( $seo['description'] ) : 0,
		)
	);
}

function cds_bridge_set_seo( WP_REST_Request $req ) {
	global $wpdb;

	$id = (int) $req['id'];
	if ( ! get_post( $id ) ) {
		return new WP_Error( 'cds_not_found', 'Post not found.', array( 'status' => 404 ) );
	}

	$fields = array();
	foreach ( array( 'title', 'description', 'canonical', 'og_title', 'og_desc', 'keyphrase' ) as $f ) {
		$v = $req->get_param( $f );
		if ( null !== $v ) {
			$fields[ $f ] = is_string( $v ) ? wp_strip_all_tags( $v ) : $v;
		}
	}
	$noindex = $req->get_param( 'noindex' );

	$plugin = cds_bridge_seo_plugin();

	if ( 'aioseo' === $plugin ) {
		$model = null;
		if ( class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
			$model = \AIOSEO\Plugin\Common\Models\Post::getPost( $id );
		}

		if ( $model ) {
			$model->post_id = $id;
			if ( isset( $fields['title'] ) ) {
				$model->title = $fields['title'];
			}
			if ( isset( $fields['description'] ) ) {
				$model->description = $fields['description'];
			}
			if ( isset( $fields['canonical'] ) ) {
				$model->canonical_url = $fields['canonical'];
			}
			if ( isset( $fields['og_title'] ) ) {
				$model->og_title = $fields['og_title'];
			}
			if ( isset( $fields['og_desc'] ) ) {
				$model->og_description = $fields['og_desc'];
			}
			if ( isset( $fields['keyphrase'] ) ) {
				$model->keyphrases = wp_json_encode(
					array( 'focus' => array( 'keyphrase' => $fields['keyphrase'], 'score' => 0, 'analysis' => array() ) )
				);
			}
			if ( null !== $noindex ) {
				$model->robots_default = false;
				$model->robots_noindex = (bool) $noindex;
			}
			$model->save();

			if ( function_exists( 'aioseo' ) && isset( aioseo()->core->cache ) ) {
				aioseo()->core->cache->clear();
			}
		} else {
			// Direct table fallback.
			$table = $wpdb->prefix . 'aioseo_posts';
			$map   = array(
				'title'       => 'title',
				'description' => 'description',
				'canonical'   => 'canonical_url',
				'og_title'    => 'og_title',
				'og_desc'     => 'og_description',
			);
			$row = array();
			foreach ( $map as $in => $col ) {
				if ( isset( $fields[ $in ] ) ) {
					$row[ $col ] = $fields[ $in ];
				}
			}
			if ( null !== $noindex ) {
				$row['robots_default'] = 0;
				$row['robots_noindex'] = (int) (bool) $noindex;
			}
			if ( $row ) {
				$row['updated'] = current_time( 'mysql' );
				$exists         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d", $id ) );
				if ( $exists ) {
					$wpdb->update( $table, $row, array( 'post_id' => $id ) );
				} else {
					$row['post_id'] = $id;
					$row['created'] = current_time( 'mysql' );
					$wpdb->insert( $table, $row );
				}
			}
		}
	} elseif ( 'yoast' === $plugin ) {
		$map = array(
			'title'       => '_yoast_wpseo_title',
			'description' => '_yoast_wpseo_metadesc',
			'canonical'   => '_yoast_wpseo_canonical',
			'keyphrase'   => '_yoast_wpseo_focuskw',
		);
		foreach ( $map as $in => $key ) {
			if ( isset( $fields[ $in ] ) ) {
				update_post_meta( $id, $key, $fields[ $in ] );
			}
		}
		if ( null !== $noindex ) {
			update_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', $noindex ? '1' : '2' );
		}
	} elseif ( 'rankmath' === $plugin ) {
		$map = array(
			'title'       => 'rank_math_title',
			'description' => 'rank_math_description',
			'canonical'   => 'rank_math_canonical_url',
			'keyphrase'   => 'rank_math_focus_keyword',
		);
		foreach ( $map as $in => $key ) {
			if ( isset( $fields[ $in ] ) ) {
				update_post_meta( $id, $key, $fields[ $in ] );
			}
		}
		if ( null !== $noindex ) {
			update_post_meta( $id, 'rank_math_robots', $noindex ? array( 'noindex' ) : array( 'index' ) );
		}
	} else {
		return new WP_Error( 'cds_no_seo_plugin', 'No supported SEO plugin detected on this site.', array( 'status' => 400 ) );
	}

	return rest_ensure_response( array( 'id' => $id, 'plugin' => $plugin, 'seo' => cds_bridge_seo_read( $id ) ) );
}

/**
 * Portfolio-style audit: missing, too short/long, duplicated, noindexed.
 */
function cds_bridge_seo_audit( WP_REST_Request $req ) {
	$types = $req->get_param( 'post_type' ) ? explode( ',', $req->get_param( 'post_type' ) ) : array( 'page', 'post' );
	$limit = min( 500, max( 1, (int) ( $req->get_param( 'per_page' ) ?: 200 ) ) );

	$q = new WP_Query(
		array(
			'post_type'        => $types,
			'post_status'      => 'publish',
			'posts_per_page'   => $limit,
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'suppress_filters' => true,
		)
	);

	$rows   = array();
	$titles = array();
	$descs  = array();

	foreach ( $q->posts as $p ) {
		$seo    = cds_bridge_seo_read( $p->ID );
		$issues = array();

		$tlen = $seo['title'] ? mb_strlen( $seo['title'] ) : 0;
		$dlen = $seo['description'] ? mb_strlen( $seo['description'] ) : 0;

		if ( ! $seo['title'] ) {
			$issues[] = 'title_missing';
		} elseif ( $tlen > 60 ) {
			$issues[] = 'title_too_long';
		} elseif ( $tlen < 30 ) {
			$issues[] = 'title_too_short';
		}

		if ( ! $seo['description'] ) {
			$issues[] = 'description_missing';
		} elseif ( $dlen > 160 ) {
			$issues[] = 'description_too_long';
		} elseif ( $dlen < 70 ) {
			$issues[] = 'description_too_short';
		}

		if ( $seo['noindex'] ) {
			$issues[] = 'noindex';
		}

		if ( $seo['title'] ) {
			$k            = strtolower( trim( $seo['title'] ) );
			$titles[ $k ] = isset( $titles[ $k ] ) ? $titles[ $k ] + 1 : 1;
		}
		if ( $seo['description'] ) {
			$k           = strtolower( trim( $seo['description'] ) );
			$descs[ $k ] = isset( $descs[ $k ] ) ? $descs[ $k ] + 1 : 1;
		}

		$rows[] = array(
			'id'          => $p->ID,
			'type'        => $p->post_type,
			'title'       => get_the_title( $p ),
			'link'        => get_permalink( $p ),
			'word_count'  => str_word_count( wp_strip_all_tags( $p->post_content ) ),
			'seo_title'   => $seo['title'],
			'title_len'   => $tlen,
			'seo_desc'    => $seo['description'],
			'desc_len'    => $dlen,
			'noindex'     => $seo['noindex'],
			'issues'      => $issues,
		);
	}

	// Second pass for duplicates.
	foreach ( $rows as &$row ) {
		if ( $row['seo_title'] && $titles[ strtolower( trim( $row['seo_title'] ) ) ] > 1 ) {
			$row['issues'][] = 'title_duplicate';
		}
		if ( $row['seo_desc'] && $descs[ strtolower( trim( $row['seo_desc'] ) ) ] > 1 ) {
			$row['issues'][] = 'description_duplicate';
		}
	}
	unset( $row );

	$with_issues = array_values(
		array_filter(
			$rows,
			function ( $r ) {
				return ! empty( $r['issues'] );
			}
		)
	);

	return rest_ensure_response(
		array(
			'seo_plugin'  => cds_bridge_seo_plugin(),
			'scanned'     => count( $rows ),
			'with_issues' => count( $with_issues ),
			'items'       => $with_issues,
		)
	);
}

/* -------------------------------------------------------------------------
 * Media / alt text
 * ---------------------------------------------------------------------- */

/**
 * Build usable context so alt text can be written well, not generically.
 */
function cds_bridge_attachment_context( $att_id, $with_usage = false ) {
	global $wpdb;

	$att  = get_post( $att_id );
	$meta = wp_get_attachment_metadata( $att_id );
	$file = get_attached_file( $att_id );

	$ctx = array(
		'id'            => (int) $att_id,
		'filename'      => $att ? basename( get_post_meta( $att_id, '_wp_attached_file', true ) ) : null,
		'mime'          => $att ? $att->post_mime_type : null,
		'url'           => wp_get_attachment_url( $att_id ),
		'alt'           => get_post_meta( $att_id, '_wp_attachment_image_alt', true ),
		'media_title'   => $att ? $att->post_title : null,
		'caption'       => $att ? $att->post_excerpt : null,
		'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : null,
		'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : null,
		'sizes'         => isset( $meta['sizes'] ) ? count( $meta['sizes'] ) : 0,
		'has_metadata'  => ! empty( $meta['width'] ),
		'file_exists'   => $file ? file_exists( $file ) : false,
		'parent'        => $att && $att->post_parent ? array(
			'id'    => (int) $att->post_parent,
			'title' => get_the_title( $att->post_parent ),
			'link'  => get_permalink( $att->post_parent ),
		) : null,
	);

	if ( $with_usage ) {
		$like_id   = '%wp-image-' . (int) $att_id . '%';
		$like_file = '%' . $wpdb->esc_like( (string) $ctx['filename'] ) . '%';
		$like_el   = '%"id":' . (int) $att_id . ',%';

		$used = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID, p.post_title, p.post_type FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
				 WHERE p.post_status = 'publish' AND p.post_type NOT IN ('attachment','revision')
				 AND ( p.post_content LIKE %s OR p.post_content LIKE %s OR m.meta_value LIKE %s )
				 LIMIT 5",
				$like_id,
				$like_file,
				$like_el
			),
			ARRAY_A
		);

		$ctx['used_on'] = array_map(
			function ( $r ) {
				return array(
					'id'    => (int) $r['ID'],
					'title' => $r['post_title'],
					'type'  => $r['post_type'],
					'link'  => get_permalink( $r['ID'] ),
				);
			},
			(array) $used
		);
	}

	return $ctx;
}

function cds_bridge_media_audit( WP_REST_Request $req ) {
	global $wpdb;

	$mime        = $req->get_param( 'mime' );          // e.g. image/avif
	$missing_alt = null === $req->get_param( 'missing_alt' ) ? true : rest_sanitize_boolean( $req->get_param( 'missing_alt' ) );
	$usage       = rest_sanitize_boolean( $req->get_param( 'usage' ) );
	$per_page    = min( 200, max( 1, (int) ( $req->get_param( 'per_page' ) ?: 50 ) ) );
	$page        = max( 1, (int) ( $req->get_param( 'page' ) ?: 1 ) );
	$offset      = ( $page - 1 ) * $per_page;

	$where  = "p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'";
	$params = array();

	if ( $mime ) {
		$where   .= ' AND p.post_mime_type = %s';
		$params[] = $mime;
	}
	if ( $missing_alt ) {
		$where .= " AND ( m.meta_value IS NULL OR TRIM(m.meta_value) = '' )";
	}

	$sql_count = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
		WHERE {$where}";

	$sql_rows = "SELECT p.ID FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
		WHERE {$where} ORDER BY p.post_date DESC LIMIT %d OFFSET %d";

	$total = $params
		? (int) $wpdb->get_var( $wpdb->prepare( $sql_count, $params ) )
		: (int) $wpdb->get_var( $sql_count );

	$row_params = array_merge( $params, array( $per_page, $offset ) );
	$ids        = $wpdb->get_col( $wpdb->prepare( $sql_rows, $row_params ) );

	$items = array();
	foreach ( (array) $ids as $id ) {
		$items[] = cds_bridge_attachment_context( (int) $id, $usage );
	}

	return rest_ensure_response(
		array(
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'items'    => $items,
		)
	);
}

function cds_bridge_set_alt( WP_REST_Request $req ) {
	$id  = (int) $req['id'];
	$alt = $req->get_param( 'alt' );

	if ( 'attachment' !== get_post_type( $id ) ) {
		return new WP_Error( 'cds_not_attachment', 'Not an attachment.', array( 'status' => 404 ) );
	}
	if ( null === $alt ) {
		return new WP_Error( 'cds_no_alt', 'alt is required.', array( 'status' => 400 ) );
	}

	$overwrite = null === $req->get_param( 'overwrite' ) ? true : rest_sanitize_boolean( $req->get_param( 'overwrite' ) );
	$existing  = get_post_meta( $id, '_wp_attachment_image_alt', true );

	if ( ! $overwrite && '' !== trim( (string) $existing ) ) {
		return rest_ensure_response( array( 'id' => $id, 'skipped' => true, 'alt' => $existing ) );
	}

	update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );

	$patch = array( 'ID' => $id );
	if ( null !== $req->get_param( 'media_title' ) ) {
		$patch['post_title'] = sanitize_text_field( $req->get_param( 'media_title' ) );
	}
	if ( null !== $req->get_param( 'caption' ) ) {
		$patch['post_excerpt'] = sanitize_text_field( $req->get_param( 'caption' ) );
	}
	if ( count( $patch ) > 1 ) {
		wp_update_post( $patch );
	}

	return rest_ensure_response( array( 'id' => $id, 'skipped' => false, 'alt' => get_post_meta( $id, '_wp_attachment_image_alt', true ) ) );
}

function cds_bridge_set_alt_bulk( WP_REST_Request $req ) {
	$items     = $req->get_param( 'items' );
	$overwrite = null === $req->get_param( 'overwrite' ) ? false : rest_sanitize_boolean( $req->get_param( 'overwrite' ) );

	if ( ! is_array( $items ) || ! $items ) {
		return new WP_Error( 'cds_no_items', 'items must be an array of {id, alt}.', array( 'status' => 400 ) );
	}
	if ( count( $items ) > 200 ) {
		return new WP_Error( 'cds_too_many', 'Maximum 200 items per request.', array( 'status' => 400 ) );
	}

	$results = array();
	foreach ( $items as $item ) {
		$id  = isset( $item['id'] ) ? (int) $item['id'] : 0;
		$alt = isset( $item['alt'] ) ? (string) $item['alt'] : null;

		if ( ! $id || null === $alt || 'attachment' !== get_post_type( $id ) ) {
			$results[] = array( 'id' => $id, 'status' => 'error', 'message' => 'invalid id or alt' );
			continue;
		}

		$existing = get_post_meta( $id, '_wp_attachment_image_alt', true );
		if ( ! $overwrite && '' !== trim( (string) $existing ) ) {
			$results[] = array( 'id' => $id, 'status' => 'skipped', 'alt' => $existing );
			continue;
		}

		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		$results[] = array( 'id' => $id, 'status' => 'updated', 'alt' => sanitize_text_field( $alt ) );
	}

	return rest_ensure_response(
		array(
			'updated' => count( array_filter( $results, function ( $r ) { return 'updated' === $r['status']; } ) ),
			'skipped' => count( array_filter( $results, function ( $r ) { return 'skipped' === $r['status']; } ) ),
			'errors'  => count( array_filter( $results, function ( $r ) { return 'error' === $r['status']; } ) ),
			'results' => $results,
		)
	);
}

/**
 * Why AVIF images misbehave: server support, broken attachment metadata,
 * and converter-generated files that are not attachments at all.
 */
function cds_bridge_avif_report( WP_REST_Request $req ) {
	global $wpdb;

	$limit = min( 300, max( 1, (int) ( $req->get_param( 'per_page' ) ?: 100 ) ) );

	$gd      = function_exists( 'gd_info' ) ? gd_info() : array();
	$imagick = class_exists( 'Imagick' ) ? @Imagick::queryFormats( 'AVIF' ) : array();

	$server = array(
		'editor_supports_avif' => wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ),
		'avif_upload_allowed'  => in_array( 'image/avif', (array) get_allowed_mime_types(), true ),
		'gd_avif'              => ! empty( $gd['AVIF Support'] ),
		'imagick_avif'         => ! empty( $imagick ),
		'wp_version_ok'        => version_compare( get_bloginfo( 'version' ), '6.5', '>=' ),
	);

	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type = 'image/avif'
			 ORDER BY post_date DESC LIMIT %d",
			$limit
		)
	);

	$attachments = array();
	$problems    = array( 'missing_alt' => 0, 'no_metadata' => 0, 'no_sizes' => 0, 'file_missing' => 0 );

	foreach ( (array) $ids as $id ) {
		$ctx = cds_bridge_attachment_context( (int) $id, false );

		$flags = array();
		if ( '' === trim( (string) $ctx['alt'] ) ) {
			$flags[] = 'missing_alt';
			$problems['missing_alt']++;
		}
		if ( ! $ctx['has_metadata'] ) {
			$flags[] = 'no_metadata';
			$problems['no_metadata']++;
		}
		if ( 0 === $ctx['sizes'] ) {
			$flags[] = 'no_sizes';
			$problems['no_sizes']++;
		}
		if ( ! $ctx['file_exists'] ) {
			$flags[] = 'file_missing';
			$problems['file_missing']++;
		}

		$ctx['flags']  = $flags;
		$attachments[] = $ctx;
	}

	// AVIF files referenced in markup that are NOT attachments (converter output).
	$rows = $wpdb->get_results(
		"SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts}
		 WHERE post_status = 'publish' AND post_type NOT IN ('attachment','revision')
		 AND post_content LIKE '%.avif%' LIMIT 200",
		ARRAY_A
	);

	$orphan_pages = array();
	foreach ( (array) $rows as $r ) {
		$imgs = array_values(
			array_filter(
				cds_bridge_scan_img_tags( $r['post_content'] ),
				function ( $i ) {
					return 'avif' === $i['ext'];
				}
			)
		);
		$bad = array_values(
			array_filter(
				$imgs,
				function ( $i ) {
					return ! $i['has_alt'];
				}
			)
		);
		if ( $bad ) {
			$orphan_pages[] = array(
				'id'     => (int) $r['ID'],
				'title'  => $r['post_title'],
				'type'   => $r['post_type'],
				'link'   => get_permalink( $r['ID'] ),
				'images' => $bad,
			);
		}
	}

	$notes = array();
	if ( ! $server['editor_supports_avif'] ) {
		$notes[] = 'Server image editor cannot process AVIF - WordPress will not generate sub-sizes, so responsive srcset and some theme output will be incomplete. Ask the host for GD with AVIF or ImageMagick 7 with libheif.';
	}
	if ( ! $server['avif_upload_allowed'] ) {
		$notes[] = 'image/avif is not in the allowed upload mime types on this site.';
	}
	if ( $problems['no_metadata'] ) {
		$notes[] = 'Some AVIF attachments have no dimensions stored. wp_get_attachment_image() can output a broken or alt-less tag for these. Regenerate metadata (wp media regenerate) after AVIF support is enabled.';
	}
	if ( $orphan_pages ) {
		$notes[] = 'AVIF images are referenced directly in markup without alt attributes and are not resolvable to an attachment - these are likely converter output (ShortPixel / Imagify / Converter for Media). Setting alt in the media library will not fix them; use /content/fix-alt.';
	}

	return rest_ensure_response(
		array(
			'server'              => $server,
			'avif_attachments'    => count( $attachments ),
			'problem_counts'      => $problems,
			'attachments'         => $attachments,
			'hardcoded_no_alt'    => $orphan_pages,
			'notes'               => $notes,
		)
	);
}

/* -------------------------------------------------------------------------
 * Images hardcoded in markup
 * ---------------------------------------------------------------------- */

function cds_bridge_scan_img_tags( $html ) {
	$out = array();
	if ( ! $html || false === strpos( $html, '<img' ) ) {
		return $out;
	}
	if ( ! preg_match_all( '/<img\b[^>]*>/i', $html, $m ) ) {
		return $out;
	}

	foreach ( $m[0] as $tag ) {
		$src = '';
		$alt = null;
		$aid = 0;

		if ( preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $tag, $s ) ) {
			$src = $s[1];
		}
		if ( preg_match( '/\balt=["\']([^"\']*)["\']/i', $tag, $a ) ) {
			$alt = $a[1];
		}
		if ( preg_match( '/wp-image-(\d+)/i', $tag, $i ) ) {
			$aid = (int) $i[1];
		}

		$path = $src ? (string) wp_parse_url( $src, PHP_URL_PATH ) : '';

		$out[] = array(
			'src'           => $src,
			'alt'           => $alt,
			'has_alt'       => ( null !== $alt && '' !== trim( $alt ) ),
			'attachment_id' => $aid,
			'ext'           => $path ? strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) : '',
		);
	}

	return $out;
}

function cds_bridge_content_image_audit( WP_REST_Request $req ) {
	$post_id = (int) $req->get_param( 'post_id' );
	$limit   = min( 300, max( 1, (int) ( $req->get_param( 'per_page' ) ?: 100 ) ) );

	if ( $post_id ) {
		$posts = array_filter( array( get_post( $post_id ) ) );
	} else {
		$q     = new WP_Query(
			array(
				'post_type'        => $req->get_param( 'post_type' ) ? explode( ',', $req->get_param( 'post_type' ) ) : array( 'page', 'post' ),
				'post_status'      => 'publish',
				'posts_per_page'   => $limit,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);
		$posts = $q->posts;
	}

	$items = array();
	foreach ( $posts as $p ) {
		$imgs = cds_bridge_scan_img_tags( $p->post_content );
		$bad  = array_values(
			array_filter(
				$imgs,
				function ( $i ) {
					return ! $i['has_alt'];
				}
			)
		);
		if ( $bad ) {
			$items[] = array(
				'id'     => $p->ID,
				'title'  => get_the_title( $p ),
				'link'   => get_permalink( $p ),
				'images' => $bad,
			);
		}
	}

	return rest_ensure_response( array( 'pages_with_issues' => count( $items ), 'items' => $items ) );
}

/**
 * Inject alt attributes into img tags that are hardcoded in post_content.
 * Body: { post_id: 12, fixes: [ { src: "...", alt: "..." } ] }
 */
function cds_bridge_fix_hardcoded_alt( WP_REST_Request $req ) {
	$post_id = (int) $req->get_param( 'post_id' );
	$fixes   = $req->get_param( 'fixes' );

	$p = get_post( $post_id );
	if ( ! $p ) {
		return new WP_Error( 'cds_not_found', 'Post not found.', array( 'status' => 404 ) );
	}
	if ( ! is_array( $fixes ) || ! $fixes ) {
		return new WP_Error( 'cds_no_fixes', 'fixes must be an array of {src, alt}.', array( 'status' => 400 ) );
	}

	$content = $p->post_content;
	$applied = array();

	foreach ( $fixes as $fix ) {
		$src = isset( $fix['src'] ) ? (string) $fix['src'] : '';
		$alt = isset( $fix['alt'] ) ? sanitize_text_field( (string) $fix['alt'] ) : '';
		if ( ! $src ) {
			continue;
		}

		$pattern = '/<img\b(?![^>]*\balt=)([^>]*\bsrc=["\']' . preg_quote( $src, '/' ) . '["\'][^>]*)>/i';
		$count   = 0;
		$content = preg_replace_callback(
			$pattern,
			function ( $m ) use ( $alt, &$count ) {
				$count++;
				return '<img alt="' . esc_attr( $alt ) . '"' . $m[1] . '>';
			},
			$content
		);

		// Also handle empty alt="" that should be populated.
		$pattern_empty = '/<img\b([^>]*\bsrc=["\']' . preg_quote( $src, '/' ) . '["\'][^>]*)\balt=["\']\s*["\']([^>]*)>/i';
		$content       = preg_replace_callback(
			$pattern_empty,
			function ( $m ) use ( $alt, &$count ) {
				$count++;
				return '<img' . $m[1] . 'alt="' . esc_attr( $alt ) . '"' . $m[2] . '>';
			},
			$content
		);

		$applied[] = array( 'src' => $src, 'alt' => $alt, 'replaced' => $count );
	}

	if ( $content !== $p->post_content ) {
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $content ) );
	}

	return rest_ensure_response( array( 'post_id' => $post_id, 'applied' => $applied ) );
}

/* -------------------------------------------------------------------------
 * Elementor
 * ---------------------------------------------------------------------- */

function cds_bridge_elementor_read( $post_id ) {
	$raw = get_post_meta( $post_id, '_elementor_data', true );
	if ( empty( $raw ) ) {
		return null;
	}
	$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
	return is_array( $data ) ? $data : null;
}

function cds_bridge_elementor_text_keys() {
	return array(
		'title', 'editor', 'text', 'heading_text', 'description_text', 'title_text', 'sub_title',
		'caption', 'button_text', 'before_text', 'highlighted_text', 'after_text', 'rotating_text',
		'alert_title', 'alert_description', 'tab_title', 'tab_content', 'testimonial_content',
		'testimonial_name', 'testimonial_job', 'item_description', 'price', 'period', 'ribbon_title',
		'inner_text', 'label', 'placeholder', 'html', 'form_name', 'heading', 'sub_heading',
	);
}

/**
 * Walk the Elementor tree collecting editable text and image references.
 * Paths are literal array index paths so edits can be applied precisely.
 */
function cds_bridge_elementor_walk( $node, $path, &$text, &$images, $context ) {
	if ( ! is_array( $node ) ) {
		return;
	}

	$el_id   = isset( $node['id'] ) ? $node['id'] : ( isset( $context['id'] ) ? $context['id'] : null );
	$el_type = isset( $node['widgetType'] ) ? $node['widgetType'] : ( isset( $node['elType'] ) ? $node['elType'] : ( isset( $context['type'] ) ? $context['type'] : null ) );
	$ctx     = array( 'id' => $el_id, 'type' => $el_type );

	if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
		cds_bridge_elementor_settings( $node['settings'], array_merge( $path, array( 'settings' ) ), $text, $images, $ctx );
	}

	if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
		foreach ( $node['elements'] as $i => $child ) {
			cds_bridge_elementor_walk( $child, array_merge( $path, array( 'elements', $i ) ), $text, $images, $ctx );
		}
	}
}

function cds_bridge_elementor_settings( $settings, $path, &$text, &$images, $ctx ) {
	$keys = cds_bridge_elementor_text_keys();

	foreach ( $settings as $key => $value ) {
		$this_path = array_merge( $path, array( $key ) );

		// Image control: array with url + id.
		if ( is_array( $value ) && isset( $value['url'] ) && array_key_exists( 'id', $value ) ) {
			$att_id = (int) $value['id'];
			$images[] = array(
				'element_id'    => $ctx['id'],
				'widget'        => $ctx['type'],
				'setting'       => $key,
				'attachment_id' => $att_id ?: null,
				'url'           => $value['url'],
				'alt'           => $att_id ? get_post_meta( $att_id, '_wp_attachment_image_alt', true ) : null,
				'fixable_via'   => $att_id ? 'media_library' : 'external_or_unlinked',
			);
			continue;
		}

		if ( is_string( $value ) && in_array( $key, $keys, true ) && '' !== trim( wp_strip_all_tags( $value ) ) ) {
			$text[] = array(
				'path'       => implode( '.', $this_path ),
				'element_id' => $ctx['id'],
				'widget'     => $ctx['type'],
				'setting'    => $key,
				'value'      => $value,
			);
			continue;
		}

		// Repeaters and nested control groups.
		if ( is_array( $value ) ) {
			foreach ( $value as $i => $sub ) {
				if ( is_array( $sub ) ) {
					cds_bridge_elementor_settings( $sub, array_merge( $this_path, array( $i ) ), $text, $images, $ctx );
				}
			}
		}
	}
}

function cds_bridge_elementor_map( WP_REST_Request $req ) {
	$id   = (int) $req['id'];
	$data = cds_bridge_elementor_read( $id );

	if ( null === $data ) {
		return new WP_Error( 'cds_not_elementor', 'No Elementor data on this post.', array( 'status' => 404 ) );
	}

	$text   = array();
	$images = array();
	foreach ( $data as $i => $node ) {
		cds_bridge_elementor_walk( $node, array( $i ), $text, $images, array() );
	}

	$missing_alt = array_values(
		array_filter(
			$images,
			function ( $img ) {
				return '' === trim( (string) $img['alt'] );
			}
		)
	);

	return rest_ensure_response(
		array(
			'post_id'           => $id,
			'title'             => get_the_title( $id ),
			'link'              => get_permalink( $id ),
			'text_nodes'        => $text,
			'images'            => $images,
			'images_missing_alt' => $missing_alt,
		)
	);
}

function cds_bridge_array_set( &$arr, $path_parts, $value ) {
	$ref = &$arr;
	foreach ( $path_parts as $part ) {
		$key = is_numeric( $part ) ? (int) $part : $part;
		if ( ! is_array( $ref ) || ! array_key_exists( $key, $ref ) ) {
			return false;
		}
		$ref = &$ref[ $key ];
	}
	$ref = $value;
	return true;
}

/**
 * Body options:
 *   { ops: [ { path: "0.elements.1.settings.title", value: "New heading" } ] }
 *   { find: "old text", replace: "new text" }
 */
function cds_bridge_elementor_edit( WP_REST_Request $req ) {
	$id   = (int) $req['id'];
	$data = cds_bridge_elementor_read( $id );

	if ( null === $data ) {
		return new WP_Error( 'cds_not_elementor', 'No Elementor data on this post.', array( 'status' => 404 ) );
	}

	// Backup before touching anything.
	update_post_meta( $id, '_cds_elementor_backup', get_post_meta( $id, '_elementor_data', true ) );
	update_post_meta( $id, '_cds_elementor_backup_time', current_time( 'mysql' ) );

	$changes = array();
	$ops     = $req->get_param( 'ops' );

	if ( is_array( $ops ) ) {
		foreach ( $ops as $op ) {
			$path  = isset( $op['path'] ) ? (string) $op['path'] : '';
			$value = isset( $op['value'] ) ? $op['value'] : null;
			if ( ! $path || null === $value ) {
				continue;
			}
			$ok        = cds_bridge_array_set( $data, explode( '.', $path ), $value );
			$changes[] = array( 'path' => $path, 'applied' => $ok, 'value' => $value );
		}
	}

	$find    = $req->get_param( 'find' );
	$replace = $req->get_param( 'replace' );

	if ( null !== $find && null !== $replace && '' !== $find ) {
		$text   = array();
		$images = array();
		foreach ( $data as $i => $node ) {
			cds_bridge_elementor_walk( $node, array( $i ), $text, $images, array() );
		}
		foreach ( $text as $node ) {
			if ( false !== strpos( $node['value'], $find ) ) {
				$new = str_replace( $find, $replace, $node['value'] );
				$ok  = cds_bridge_array_set( $data, explode( '.', $node['path'] ), $new );
				$changes[] = array( 'path' => $node['path'], 'applied' => $ok, 'value' => $new );
			}
		}
	}

	if ( ! $changes ) {
		return new WP_Error( 'cds_no_changes', 'No ops applied - supply ops[] or find/replace.', array( 'status' => 400 ) );
	}

	update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	cds_bridge_elementor_regenerate( $id );

	return rest_ensure_response(
		array(
			'post_id'  => $id,
			'changes'  => $changes,
			'rollback' => 'POST ' . rest_url( CDS_BRIDGE_NS . '/elementor/' . $id . '/rollback' ),
		)
	);
}

function cds_bridge_elementor_rollback( WP_REST_Request $req ) {
	$id     = (int) $req['id'];
	$backup = get_post_meta( $id, '_cds_elementor_backup', true );

	if ( empty( $backup ) ) {
		return new WP_Error( 'cds_no_backup', 'No bridge backup stored for this post.', array( 'status' => 404 ) );
	}

	update_post_meta( $id, '_elementor_data', wp_slash( $backup ) );
	cds_bridge_elementor_regenerate( $id );

	return rest_ensure_response(
		array(
			'post_id'     => $id,
			'restored'    => true,
			'backup_time' => get_post_meta( $id, '_cds_elementor_backup_time', true ),
		)
	);
}

function cds_bridge_elementor_regenerate( $post_id ) {
	if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
		try {
			$css = \Elementor\Core\Files\CSS\Post::create( $post_id );
			$css->update();
		} catch ( \Exception $e ) {
			// Non-fatal.
		}
	}
	if ( function_exists( 'rocket_clean_post' ) ) {
		rocket_clean_post( $post_id );
	}
	clean_post_cache( $post_id );
}

function cds_bridge_elementor_flush() {
	if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->files_manager ) ) {
		return new WP_Error( 'cds_no_elementor', 'Elementor not active on this site.', array( 'status' => 400 ) );
	}
	\Elementor\Plugin::$instance->files_manager->clear_cache();
	return rest_ensure_response( array( 'flushed' => true ) );
}


/* =========================================================================
 * CPT archive SEO (folded in from the archives add-on)
 * ====================================================================== */

define( 'CDS_BRIDGE_ARCHIVES_OPTION', 'aioseo_options_dynamic' );
define( 'CDS_BRIDGE_ARCHIVES_BACKUP', 'cds_bridge_aioseo_dynamic_backup' );

add_action( 'rest_api_init', function () {
	if ( ! function_exists( 'cds_bridge_guard' ) ) {
		return; // Main bridge not installed.
	}

	register_rest_route(
		'cds/v1',
		'/seo/archive/(?P<post_type>[a-zA-Z0-9_-]+)',
		array(
			'methods'             => 'GET',
			'callback'            => 'cds_bridge_archive_get',
			'permission_callback' => function () { return cds_bridge_guard( 'read' ); },
		)
	);

	register_rest_route(
		'cds/v1',
		'/seo/archive/(?P<post_type>[a-zA-Z0-9_-]+)',
		array(
			'methods'             => 'POST',
			'callback'            => 'cds_bridge_archive_set',
			'permission_callback' => function () { return cds_bridge_guard( 'admin' ); },
		)
	);

	register_rest_route(
		'cds/v1',
		'/seo/archive-rollback',
		array(
			'methods'             => 'POST',
			'callback'            => 'cds_bridge_archive_rollback',
			'permission_callback' => function () { return cds_bridge_guard( 'admin' ); },
		)
	);
} );

/**
 * Decode the dynamic option. Returns array|WP_Error.
 */
function cds_bridge_archive_options() {
	$raw = get_option( CDS_BRIDGE_ARCHIVES_OPTION );

	if ( empty( $raw ) ) {
		return new WP_Error( 'cds_no_option', 'aioseo_options_dynamic does not exist - is AIOSEO active?', array( 'status' => 500 ) );
	}

	$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

	if ( ! is_array( $decoded ) ) {
		return new WP_Error( 'cds_bad_option', 'Could not decode aioseo_options_dynamic.', array( 'status' => 500 ) );
	}

	return $decoded;
}

/**
 * The shape AIOSEO uses for a CPT archive entry, used when creating one.
 */
function cds_bridge_archive_defaults() {
	return array(
		'show'            => true,
		'advanced'        => array(
			'robotsMeta'                => array(
				'default'         => true,
				'noindex'         => false,
				'nofollow'        => false,
				'noarchive'       => false,
				'noimageindex'    => false,
				'notranslate'     => false,
				'nosnippet'       => false,
				'noodp'           => false,
				'maxSnippet'      => -1,
				'maxVideoPreview' => -1,
				'maxImagePreview' => 'large',
			),
			'showDateInGooglePreview'   => true,
			'showPostThumbnailInSearch' => true,
			'showMetaBox'               => true,
			'keywords'                  => '',
		),
		'title'           => '#archive_title #separator_sa #site_title',
		'metaDescription' => '',
		'customFields'    => '',
	);
}

function cds_bridge_archive_get( WP_REST_Request $req ) {
	$post_type = $req['post_type'];
	$options   = cds_bridge_archive_options();

	if ( is_wp_error( $options ) ) {
		return $options;
	}

	$archives = isset( $options['searchAppearance']['archives'] ) ? $options['searchAppearance']['archives'] : array();
	$entry    = isset( $archives[ $post_type ] ) ? $archives[ $post_type ] : null;

	return rest_ensure_response(
		array(
			'post_type'        => $post_type,
			'archive_url'      => get_post_type_archive_link( $post_type ),
			'configured'       => null !== $entry,
			'title'            => $entry && isset( $entry['title'] ) ? $entry['title'] : null,
			'meta_description' => $entry && isset( $entry['metaDescription'] ) ? $entry['metaDescription'] : null,
			'show'             => $entry && isset( $entry['show'] ) ? (bool) $entry['show'] : null,
			'noindex'          => $entry && isset( $entry['advanced']['robotsMeta']['noindex'] ) ? (bool) $entry['advanced']['robotsMeta']['noindex'] : null,
			'known_post_types' => array_keys( (array) $archives ),
			'raw'              => $entry,
		)
	);
}

function cds_bridge_archive_set( WP_REST_Request $req ) {
	$post_type = $req['post_type'];
	$raw       = get_option( CDS_BRIDGE_ARCHIVES_OPTION );
	$options   = cds_bridge_archive_options();

	if ( is_wp_error( $options ) ) {
		return $options;
	}

	$title       = $req->get_param( 'title' );
	$description = $req->get_param( 'description' );
	$noindex     = $req->get_param( 'noindex' );

	if ( null === $title && null === $description && null === $noindex ) {
		return new WP_Error( 'cds_no_fields', 'Supply at least one of: title, description, noindex.', array( 'status' => 400 ) );
	}

	// Back up the whole option before touching it.
	update_option(
		CDS_BRIDGE_ARCHIVES_BACKUP,
		array( 'time' => current_time( 'mysql' ), 'value' => $raw ),
		false
	);

	if ( ! isset( $options['searchAppearance'] ) || ! is_array( $options['searchAppearance'] ) ) {
		$options['searchAppearance'] = array();
	}
	if ( ! isset( $options['searchAppearance']['archives'] ) || ! is_array( $options['searchAppearance']['archives'] ) ) {
		$options['searchAppearance']['archives'] = array();
	}
	if ( ! isset( $options['searchAppearance']['archives'][ $post_type ] ) || ! is_array( $options['searchAppearance']['archives'][ $post_type ] ) ) {
		$options['searchAppearance']['archives'][ $post_type ] = cds_bridge_archive_defaults();
	}

	$entry = &$options['searchAppearance']['archives'][ $post_type ];

	if ( null !== $title ) {
		$entry['title'] = wp_strip_all_tags( (string) $title );
	}
	if ( null !== $description ) {
		$entry['metaDescription'] = wp_strip_all_tags( (string) $description );
	}
	if ( null !== $noindex ) {
		$flag = rest_sanitize_boolean( $noindex );
		// AIOSEO: show=false hides the archive from search; robotsMeta carries the tag.
		$entry['show'] = ! $flag;
		if ( ! isset( $entry['advanced']['robotsMeta'] ) || ! is_array( $entry['advanced']['robotsMeta'] ) ) {
			$defaults                    = cds_bridge_archive_defaults();
			$entry['advanced']['robotsMeta'] = $defaults['advanced']['robotsMeta'];
		}
		$entry['advanced']['robotsMeta']['default'] = ! $flag;
		$entry['advanced']['robotsMeta']['noindex'] = $flag;
	}

	unset( $entry );

	$encoded = wp_json_encode( $options );

	if ( false === $encoded || ! is_string( $encoded ) || '' === $encoded ) {
		return new WP_Error( 'cds_encode_failed', 'Refusing to write - could not re-encode the option.', array( 'status' => 500 ) );
	}

	// Sanity check: never shrink the blob dramatically, that would mean data loss.
	if ( is_string( $raw ) && strlen( $encoded ) < ( strlen( $raw ) * 0.5 ) ) {
		return new WP_Error( 'cds_size_guard', 'Refusing to write - encoded option is less than half the original size.', array( 'status' => 500 ) );
	}

	update_option( CDS_BRIDGE_ARCHIVES_OPTION, $encoded );

	if ( function_exists( 'aioseo' ) && isset( aioseo()->core->cache ) ) {
		aioseo()->core->cache->clear();
	}

	return rest_ensure_response(
		array(
			'post_type'   => $post_type,
			'archive_url' => get_post_type_archive_link( $post_type ),
			'saved'       => $options['searchAppearance']['archives'][ $post_type ],
			'rollback'    => 'POST ' . rest_url( 'cds/v1/seo/archive-rollback' ),
		)
	);
}

function cds_bridge_archive_rollback() {
	$backup = get_option( CDS_BRIDGE_ARCHIVES_BACKUP );

	if ( empty( $backup['value'] ) ) {
		return new WP_Error( 'cds_no_backup', 'No backup stored.', array( 'status' => 404 ) );
	}

	update_option( CDS_BRIDGE_ARCHIVES_OPTION, $backup['value'] );

	if ( function_exists( 'aioseo' ) && isset( aioseo()->core->cache ) ) {
		aioseo()->core->cache->clear();
	}

	return rest_ensure_response( array( 'restored' => true, 'backup_time' => $backup['time'] ) );
}

/* -------------------------------------------------------------------------
 * Updates from GitHub Releases
 *
 * Set your repo in wp-config.php (or leave the default below):
 *   define( 'CDS_BRIDGE_GITHUB_REPO', 'your-org/cds-agency-bridge' );
 *
 * Publishing a release with a tag like v1.2.0 and an attached
 * cds-agency-bridge-1.2.0.zip is all that is needed - every site picks it
 * up within six hours and installs it unattended.
 *
 * Disable unattended updates on a site with:
 *   define( 'CDS_BRIDGE_AUTOUPDATE', false );
 * ---------------------------------------------------------------------- */

if ( ! defined( 'CDS_BRIDGE_DEFAULT_REPO' ) ) {
	define( 'CDS_BRIDGE_DEFAULT_REPO', 'hjcds/cds-agency-bridge' );
}

function cds_bridge_repo() {
	return defined( 'CDS_BRIDGE_GITHUB_REPO' ) ? CDS_BRIDGE_GITHUB_REPO : CDS_BRIDGE_DEFAULT_REPO;
}

/**
 * Latest published release, normalised. Returns array|null.
 */
function cds_bridge_manifest( $force = false ) {
	$repo = cds_bridge_repo();
	if ( empty( $repo ) ) {
		return null;
	}

	if ( ! $force ) {
		$cached = get_site_transient( 'cds_bridge_manifest' );
		if ( is_array( $cached ) ) {
			return isset( $cached['error'] ) ? null : $cached;
		}
	}

	$res = wp_remote_get(
		'https://api.github.com/repos/' . $repo . '/releases/latest',
		array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'cds-agency-bridge/' . CDS_BRIDGE_VERSION,
			),
		)
	);

	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		// Back off so a rate limit or outage does not slow every admin page.
		set_site_transient( 'cds_bridge_manifest', array( 'error' => true ), HOUR_IN_SECONDS );
		return null;
	}

	$body = json_decode( wp_remote_retrieve_body( $res ), true );

	if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
		set_site_transient( 'cds_bridge_manifest', array( 'error' => true ), HOUR_IN_SECONDS );
		return null;
	}

	// Prefer an attached .zip asset - it has the correct folder name inside.
	$package = '';
	if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
		foreach ( $body['assets'] as $asset ) {
			if ( ! empty( $asset['browser_download_url'] ) && '.zip' === strtolower( substr( $asset['browser_download_url'], -4 ) ) ) {
				$package = $asset['browser_download_url'];
				break;
			}
		}
	}
	if ( ! $package && ! empty( $body['zipball_url'] ) ) {
		$package = $body['zipball_url']; // Folder name fixed by cds_bridge_fix_folder().
	}

	if ( ! $package ) {
		set_site_transient( 'cds_bridge_manifest', array( 'error' => true ), HOUR_IN_SECONDS );
		return null;
	}

	$data = array(
		'version'      => ltrim( (string) $body['tag_name'], 'vV' ),
		'download_url' => $package,
		'homepage'     => isset( $body['html_url'] ) ? $body['html_url'] : '',
		'changelog'    => isset( $body['body'] ) ? wp_kses_post( nl2br( $body['body'] ) ) : '',
		'published'    => isset( $body['published_at'] ) ? $body['published_at'] : '',
	);

	set_site_transient( 'cds_bridge_manifest', $data, 6 * HOUR_IN_SECONDS );

	return $data;
}

/**
 * GitHub zipballs extract to owner-repo-sha/. Rename to the plugin folder so
 * WordPress updates in place instead of creating a duplicate plugin.
 */
add_filter( 'upgrader_source_selection', 'cds_bridge_fix_folder', 10, 4 );

function cds_bridge_fix_folder( $source, $remote_source, $upgrader, $args = array() ) {
	if ( empty( $args['plugin'] ) || plugin_basename( __FILE__ ) !== $args['plugin'] ) {
		return $source;
	}

	$desired = trailingslashit( $remote_source ) . dirname( plugin_basename( __FILE__ ) );

	if ( untrailingslashit( $source ) === $desired ) {
		return $source;
	}

	global $wp_filesystem;
	if ( ! $wp_filesystem || ! $wp_filesystem->move( $source, $desired, true ) ) {
		return $source;
	}

	return trailingslashit( $desired );
}

add_filter( 'site_transient_update_plugins', 'cds_bridge_inject_update' );

function cds_bridge_inject_update( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}

	$manifest = cds_bridge_manifest();
	if ( ! $manifest ) {
		return $transient;
	}

	$basename = plugin_basename( __FILE__ );

	if ( version_compare( $manifest['version'], CDS_BRIDGE_VERSION, '<=' ) ) {
		if ( isset( $transient->response[ $basename ] ) ) {
			unset( $transient->response[ $basename ] );
		}
		return $transient;
	}

	$update = (object) array(
		'id'          => $basename,
		'slug'        => dirname( $basename ),
		'plugin'      => $basename,
		'new_version' => $manifest['version'],
		'url'         => $manifest['homepage'],
		'package'     => $manifest['download_url'],
		'icons'       => array(),
		'banners'     => array(),
	);

	if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
		$transient->response = array();
	}

	$transient->response[ $basename ] = $update;

	return $transient;
}

add_filter( 'plugins_api', 'cds_bridge_plugin_info', 20, 3 );

function cds_bridge_plugin_info( $result, $action, $args ) {
	if ( 'plugin_information' !== $action ) {
		return $result;
	}
	if ( empty( $args->slug ) || dirname( plugin_basename( __FILE__ ) ) !== $args->slug ) {
		return $result;
	}

	$manifest = cds_bridge_manifest();
	if ( ! $manifest ) {
		return $result;
	}

	return (object) array(
		'name'          => 'CDS Agency Bridge',
		'slug'          => $args->slug,
		'version'       => $manifest['version'],
		'author'        => 'Cloud Digital Solutions',
		'homepage'      => $manifest['homepage'],
		'download_link' => $manifest['download_url'],
		'sections'      => array(
			'description' => 'Authenticated REST endpoints for agency automation across client sites.',
			'changelog'   => $manifest['changelog'],
		),
	);
}

/**
 * Unattended updates on by default - the point of central deployment.
 */
add_filter(
	'auto_update_plugin',
	function ( $update, $item ) {
		if ( isset( $item->plugin ) && plugin_basename( __FILE__ ) === $item->plugin ) {
			return defined( 'CDS_BRIDGE_AUTOUPDATE' ) ? (bool) CDS_BRIDGE_AUTOUPDATE : true;
		}
		return $update;
	},
	10,
	2
);

add_action(
	'upgrader_process_complete',
	function () {
		delete_site_transient( 'cds_bridge_manifest' );
	},
	10,
	0
);

/**
 * GET /cds/v1/update-status - audit which sites are on which version.
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			CDS_BRIDGE_NS,
			'/update-status',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return cds_bridge_guard( 'read' );
				},
				'callback'            => function () {
					$manifest = cds_bridge_manifest( true );
					return rest_ensure_response(
						array(
							'installed_version' => CDS_BRIDGE_VERSION,
							'repo'              => cds_bridge_repo(),
							'source_reachable'  => (bool) $manifest,
							'available_version' => $manifest ? $manifest['version'] : null,
							'update_available'  => $manifest ? version_compare( $manifest['version'], CDS_BRIDGE_VERSION, '>' ) : null,
							'auto_update'       => defined( 'CDS_BRIDGE_AUTOUPDATE' ) ? (bool) CDS_BRIDGE_AUTOUPDATE : true,
						)
					);
				},
			)
		);
	}
);
