<?php
/**
 * Export UberMenu nav menus + segments + settings to a portable pack.
 *
 * @package FX_UberMenu_Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds an fx-ubermenu-pack JSON payload.
 */
class FX_UberMenu_Migrator_Exporter {

	/**
	 * Meta key for UberMenu item settings.
	 *
	 * @var string
	 */
	protected $settings_meta = '_ubermenu_settings';

	/**
	 * Meta key for UberMenu custom item type.
	 *
	 * @var string
	 */
	protected $type_meta = '_ubermenu_custom_item_type';

	/**
	 * Source home URL.
	 *
	 * @var string
	 */
	protected $home = '';

	/**
	 * URL resolver.
	 *
	 * @var FX_UberMenu_Migrator_URL_Resolver
	 */
	protected $resolver;

	/**
	 * Menus already queued, keyed by term_id.
	 *
	 * @var array
	 */
	protected $queued = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( defined( 'UBERMENU_MENU_ITEM_META_KEY' ) ) {
			$this->settings_meta = UBERMENU_MENU_ITEM_META_KEY;
		}
		$this->home     = untrailingslashit( home_url() );
		$this->resolver = new FX_UberMenu_Migrator_URL_Resolver( $this->home, $this->home );
	}

	/**
	 * Export one or more menus (auto-includes referenced segments).
	 *
	 * @param int|int[] $menu_ids Nav menu term ID(s).
	 * @return array Pack array ready for JSON encoding.
	 */
	public function export( $menu_ids ) {
		$menu_ids = array_map( 'intval', (array) $menu_ids );
		$menu_ids = array_filter( $menu_ids );

		foreach ( $menu_ids as $menu_id ) {
			$this->queue_menu_tree( $menu_id );
		}

		$menus = array();
		foreach ( $this->queued as $term_id => $menu ) {
			$menus[] = $this->serialize_menu( (int) $term_id );
		}

		// Segments first so importers can create dependencies before parents.
		usort(
			$menus,
			function ( $a, $b ) {
				if ( ! empty( $a['is_segment'] ) && empty( $b['is_segment'] ) ) {
					return -1;
				}
				if ( empty( $a['is_segment'] ) && ! empty( $b['is_segment'] ) ) {
					return 1;
				}
				return strcmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'format'           => FX_UBERMENU_MIGRATOR_FORMAT,
			'version'          => 1,
			'exported_at'      => gmdate( 'c' ),
			'source'           => array(
				'home'      => $this->home,
				'site_name' => get_bloginfo( 'name' ),
			),
			'ubermenu_options' => $this->export_ubermenu_options(),
			'theme_locations'  => $this->export_theme_locations(),
			'menus'            => $menus,
		);
	}

	/**
	 * Recursively queue a menu and any menus referenced as segments.
	 *
	 * @param int $menu_id Menu term ID.
	 * @return void
	 */
	protected function queue_menu_tree( $menu_id ) {
		$menu_id = (int) $menu_id;
		if ( $menu_id <= 0 || isset( $this->queued[ $menu_id ] ) ) {
			return;
		}

		$term = get_term( $menu_id, 'nav_menu' );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		$this->queued[ $menu_id ] = $term;

		$items = wp_get_nav_menu_items( $menu_id );
		if ( ! $items ) {
			return;
		}

		foreach ( $items as $item ) {
			$settings = get_post_meta( $item->ID, $this->settings_meta, true );
			if ( ! is_array( $settings ) ) {
				continue;
			}
			if ( empty( $settings['menu_segment'] ) || '_none' === $settings['menu_segment'] ) {
				continue;
			}
			$segment_id = (int) $settings['menu_segment'];
			if ( $segment_id > 0 ) {
				$this->queue_menu_tree( $segment_id );
			}
		}
	}

	/**
	 * Serialize one nav menu and its items.
	 *
	 * @param int $menu_id Menu term ID.
	 * @return array
	 */
	protected function serialize_menu( $menu_id ) {
		$term  = get_term( $menu_id, 'nav_menu' );
		$items = wp_get_nav_menu_items( $menu_id );
		$out   = array(
			'export_id'  => 'menu_' . $menu_id,
			'name'       => $term->name,
			'slug'       => $term->slug,
			'is_segment' => $this->is_segment_menu( $menu_id ),
			'items'      => array(),
		);

		if ( ! $items ) {
			return $out;
		}

		foreach ( $items as $item ) {
			$out['items'][] = $this->serialize_item( $item );
		}

		return $out;
	}

	/**
	 * True when this menu is only referenced as a segment (or named like a segment pack).
	 *
	 * @param int $menu_id Menu term ID.
	 * @return bool
	 */
	protected function is_segment_menu( $menu_id ) {
		$locations = get_nav_menu_locations();
		foreach ( $locations as $loc_menu_id ) {
			if ( (int) $loc_menu_id === (int) $menu_id ) {
				return false;
			}
		}

		// Referenced as a segment from another queued menu?
		foreach ( $this->queued as $other_id => $term ) {
			if ( (int) $other_id === (int) $menu_id ) {
				continue;
			}
			$items = wp_get_nav_menu_items( (int) $other_id );
			if ( ! $items ) {
				continue;
			}
			foreach ( $items as $item ) {
				$settings = get_post_meta( $item->ID, $this->settings_meta, true );
				if ( is_array( $settings ) && (int) ( $settings['menu_segment'] ?? 0 ) === (int) $menu_id ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Serialize a single menu item.
	 *
	 * @param object $item Menu item object from wp_get_nav_menu_items().
	 * @return array
	 */
	protected function serialize_item( $item ) {
		$settings = get_post_meta( $item->ID, $this->settings_meta, true );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// Remap numeric segment menu IDs to export_id strings.
		if ( ! empty( $settings['menu_segment'] ) && '_none' !== $settings['menu_segment'] ) {
			$seg = (int) $settings['menu_segment'];
			if ( $seg > 0 ) {
				$settings['menu_segment'] = 'menu_' . $seg;
			}
		}

		$url  = (string) $item->url;
		$path = $this->resolver->path_from_url( $url );

		// For object items (page/post), prefer permalink path.
		if ( in_array( $item->type, array( 'post_type', 'taxonomy' ), true ) ) {
			$permalink = '';
			if ( 'post_type' === $item->type ) {
				$permalink = get_permalink( (int) $item->object_id );
			} elseif ( 'taxonomy' === $item->type ) {
				$permalink = get_term_link( (int) $item->object_id, $item->object );
				if ( is_wp_error( $permalink ) ) {
					$permalink = '';
				}
			}
			if ( $permalink ) {
				$url  = $permalink;
				$path = $this->resolver->path_from_url( $permalink );
			}
		}

		$parent_export_id = null;
		if ( ! empty( $item->menu_item_parent ) ) {
			$parent_export_id = 'item_' . (int) $item->menu_item_parent;
		}

		return array(
			'export_id'                 => 'item_' . (int) $item->ID,
			'title'                     => $item->title,
			'type'                      => $item->type,
			'object'                    => $item->object,
			'object_slug'               => $this->object_slug_for_item( $item ),
			'url'                       => $url,
			'path'                      => $path,
			'parent_export_id'          => $parent_export_id,
			'position'                  => (int) $item->menu_order,
			'classes'                   => is_array( $item->classes ) ? array_values( array_filter( $item->classes ) ) : array(),
			'target'                    => $item->target,
			'xfn'                       => $item->xfn,
			'description'               => $item->description,
			'attr_title'                => $item->attr_title,
			'ubermenu_custom_item_type' => (string) get_post_meta( $item->ID, $this->type_meta, true ),
			'ubermenu_settings'         => $settings,
		);
	}

	/**
	 * Store a portable slug for page/post/term object items.
	 *
	 * @param object $item Menu item.
	 * @return string
	 */
	protected function object_slug_for_item( $item ) {
		if ( 'post_type' === $item->type ) {
			$post = get_post( (int) $item->object_id );
			return $post ? (string) $post->post_name : '';
		}
		if ( 'taxonomy' === $item->type ) {
			$term = get_term( (int) $item->object_id, $item->object );
			return ( $term && ! is_wp_error( $term ) ) ? (string) $term->slug : '';
		}
		return '';
	}

	/**
	 * Export UberMenu instance options (safe subset).
	 *
	 * @return array
	 */
	protected function export_ubermenu_options() {
		$keys = array( 'ubermenu_main', 'ubermenu_general' );
		$out  = array();
		foreach ( $keys as $key ) {
			$val = get_option( $key, null );
			if ( null !== $val ) {
				$out[ $key ] = $val;
			}
		}
		return $out;
	}

	/**
	 * Export theme location assignments for queued menus (by slug).
	 *
	 * @return array location => menu slug
	 */
	protected function export_theme_locations() {
		$locations = get_nav_menu_locations();
		$out       = array();
		foreach ( $locations as $location => $menu_id ) {
			$menu_id = (int) $menu_id;
			if ( $menu_id && isset( $this->queued[ $menu_id ] ) ) {
				$out[ $location ] = $this->queued[ $menu_id ]->slug;
			}
		}
		return $out;
	}
}
