<?php
/**
 * Import an fx-ubermenu-pack onto the current site.
 *
 * @package FX_UberMenu_Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates menus/items from an export pack with URL remapping.
 */
class FX_UberMenu_Migrator_Importer {

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
	 * URL resolver.
	 *
	 * @var FX_UberMenu_Migrator_URL_Resolver
	 */
	protected $resolver;

	/**
	 * menu export_id => new term_id
	 *
	 * @var array
	 */
	protected $menu_map = array();

	/**
	 * item export_id => new item_id
	 *
	 * @var array
	 */
	protected $item_map = array();

	/**
	 * Import report rows.
	 *
	 * @var array
	 */
	protected $report = array();

	/**
	 * Whether this is a dry run.
	 *
	 * @var bool
	 */
	protected $dry_run = false;

	/**
	 * Whether to replace existing menus with the same name.
	 *
	 * @var bool
	 */
	protected $replace_existing = true;

	/**
	 * Whether to import UberMenu option panels.
	 *
	 * @var bool
	 */
	protected $import_options = true;

	/**
	 * Whether to assign theme locations.
	 *
	 * @var bool
	 */
	protected $import_locations = true;

	/**
	 * Optional progress callback.
	 *
	 * @var callable|null
	 */
	protected $progress_callback = null;

	/**
	 * Items processed so far.
	 *
	 * @var int
	 */
	protected $progress_done = 0;

	/**
	 * Total items to process.
	 *
	 * @var int
	 */
	protected $progress_total = 0;

	/**
	 * Constructor.
	 *
	 * @param array $args Importer args.
	 */
	public function __construct( array $args = array() ) {
		if ( defined( 'UBERMENU_MENU_ITEM_META_KEY' ) ) {
			$this->settings_meta = UBERMENU_MENU_ITEM_META_KEY;
		}

		$this->dry_run          = ! empty( $args['dry_run'] );
		$this->replace_existing = ! isset( $args['replace_existing'] ) || ! empty( $args['replace_existing'] );
		$this->import_options   = ! isset( $args['import_options'] ) || ! empty( $args['import_options'] );
		$this->import_locations = ! isset( $args['import_locations'] ) || ! empty( $args['import_locations'] );

		if ( isset( $args['progress_callback'] ) && is_callable( $args['progress_callback'] ) ) {
			$this->progress_callback = $args['progress_callback'];
		}
	}

	/**
	 * Count one processed item and report progress.
	 *
	 * @param string $phase Phase key.
	 * @param string $label Item label.
	 * @return void
	 */
	protected function tick( $phase, $label ) {
		$this->progress_done++;
		$this->emit_progress( $phase, $label );
	}

	/**
	 * Hand the current progress to the callback, when one was supplied.
	 *
	 * @param string $phase Phase key.
	 * @param string $label Item label.
	 * @return void
	 */
	protected function emit_progress( $phase, $label ) {
		if ( ! is_callable( $this->progress_callback ) ) {
			return;
		}

		$total = max( 1, (int) $this->progress_total );
		$done  = max( 0, min( (int) $this->progress_done, $total ) );

		call_user_func(
			$this->progress_callback,
			array(
				'phase'   => (string) $phase,
				'label'   => (string) $label,
				'done'    => $done,
				'total'   => $total,
				'percent' => (int) floor( ( $done / $total ) * 100 ),
			)
		);
	}

	/**
	 * Import a decoded pack array.
	 *
	 * @param array $pack Export pack.
	 * @return array|\WP_Error Report on success.
	 */
	public function import( array $pack ) {
		$validated = $this->validate_pack( $pack );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$source_home = untrailingslashit( $pack['source']['home'] );
		$dest_home   = untrailingslashit( home_url() );
		$this->resolver = new FX_UberMenu_Migrator_URL_Resolver( $source_home, $dest_home );

		$this->report[] = array(
			'type'    => 'info',
			'message' => sprintf(
				'Source: %s -> Destination: %s%s',
				$source_home,
				$dest_home,
				$this->dry_run ? ' (dry run)' : ''
			),
		);

		$menus = $pack['menus'];
		usort(
			$menus,
			function ( $a, $b ) {
				if ( ! empty( $a['is_segment'] ) && empty( $b['is_segment'] ) ) {
					return -1;
				}
				if ( empty( $a['is_segment'] ) && ! empty( $b['is_segment'] ) ) {
					return 1;
				}
				return 0;
			}
		);

		$item_total = 0;
		foreach ( $menus as $menu ) {
			$item_total += ( isset( $menu['items'] ) && is_array( $menu['items'] ) ) ? count( $menu['items'] ) : 0;
		}
		$this->progress_total = max(
			1,
			count( $menus ) + $item_total + ( $this->import_options ? 1 : 0 ) + ( $this->import_locations ? 1 : 0 )
		);
		$this->emit_progress( 'start', 'Reading the pack' );

		foreach ( $menus as $menu ) {
			$this->tick( 'menu', isset( $menu['name'] ) ? $menu['name'] : 'menu' );

			$result = $this->import_menu( $menu );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Second pass: rewrite menu_segment settings now that all menus exist.
		foreach ( $menus as $menu ) {
			$this->apply_item_settings( $menu );
		}

		if ( $this->import_options ) {
			$this->tick( 'option', 'UberMenu settings' );

			if ( ! empty( $pack['ubermenu_options'] ) && is_array( $pack['ubermenu_options'] ) ) {
				$this->import_ubermenu_options( $pack['ubermenu_options'] );
			}
		}

		if ( $this->import_locations ) {
			$this->tick( 'location', 'Theme locations' );

			if ( ! empty( $pack['theme_locations'] ) && is_array( $pack['theme_locations'] ) ) {
				$this->import_theme_locations( $pack['theme_locations'] );
			}
		}

		$this->report[] = array(
			'type'    => 'success',
			'message' => $this->dry_run
				? 'Dry run complete. No changes were written.'
				: 'Import complete.',
		);

		$this->progress_done = $this->progress_total;
		$this->emit_progress( 'finish', 'Finishing up' );

		return array(
			'dry_run'  => $this->dry_run,
			'menu_map' => $this->menu_map,
			'item_map' => $this->item_map,
			'report'   => $this->report,
		);
	}

	/**
	 * Validate pack structure.
	 *
	 * @param array $pack Pack.
	 * @return true|\WP_Error
	 */
	protected function validate_pack( array $pack ) {
		if ( empty( $pack['format'] ) || FX_UBERMENU_MIGRATOR_FORMAT !== $pack['format'] ) {
			return new WP_Error( 'invalid_format', 'This file is not a valid FX UberMenu pack.' );
		}
		if ( empty( $pack['source']['home'] ) ) {
			return new WP_Error( 'missing_source', 'Export pack is missing source home URL.' );
		}
		if ( empty( $pack['menus'] ) || ! is_array( $pack['menus'] ) ) {
			return new WP_Error( 'missing_menus', 'Export pack has no menus.' );
		}
		return true;
	}

	/**
	 * Import one menu and its items (settings applied in second pass).
	 *
	 * @param array $menu Menu payload.
	 * @return true|\WP_Error
	 */
	protected function import_menu( array $menu ) {
		$name      = isset( $menu['name'] ) ? sanitize_text_field( $menu['name'] ) : '';
		$export_id = isset( $menu['export_id'] ) ? $menu['export_id'] : '';
		if ( '' === $name || '' === $export_id ) {
			return new WP_Error( 'invalid_menu', 'A menu in the pack is missing name or export_id.' );
		}

		$menu_id = 0;
		$existing = get_term_by( 'name', $name, 'nav_menu' );

		if ( $existing && $this->replace_existing ) {
			$menu_id = (int) $existing->term_id;
			$this->report[] = array(
				'type'    => 'info',
				'message' => sprintf( 'Replacing existing menu "%s" (ID %d).', $name, $menu_id ),
			);
			if ( ! $this->dry_run ) {
				$this->clear_menu_items( $menu_id );
			}
		} elseif ( $existing && ! $this->replace_existing ) {
			$name    = $name . ' (imported ' . gmdate( 'Y-m-d H:i' ) . ')';
			$menu_id = 0;
		}

		if ( ! $menu_id ) {
			if ( $this->dry_run ) {
				$menu_id = -1 * ( count( $this->menu_map ) + 1 );
				$this->report[] = array(
					'type'    => 'info',
					'message' => sprintf( 'Would create menu "%s".', $name ),
				);
			} else {
				$created = wp_create_nav_menu( $name );
				if ( is_wp_error( $created ) ) {
					return $created;
				}
				$menu_id = (int) $created;
				$this->report[] = array(
					'type'    => 'info',
					'message' => sprintf( 'Created menu "%s" (ID %d).', $name, $menu_id ),
				);
			}
		}

		$this->menu_map[ $export_id ] = $menu_id;

		$items = isset( $menu['items'] ) && is_array( $menu['items'] ) ? $menu['items'] : array();
		$items = $this->sort_items_parents_first( $items );

		foreach ( $items as $item ) {
			$this->tick( 'item', isset( $item['title'] ) && '' !== $item['title'] ? $item['title'] : 'menu item' );

			$result = $this->import_item( $menu_id, $item );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Ensure parents are created before children.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	protected function sort_items_parents_first( array $items ) {
		$by_id = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['export_id'] ) ) {
				$by_id[ $item['export_id'] ] = $item;
			}
		}

		$sorted   = array();
		$visited  = array();
		$visiting = array();

		$visit = function ( $export_id ) use ( &$visit, &$by_id, &$sorted, &$visited, &$visiting ) {
			if ( isset( $visited[ $export_id ] ) || ! isset( $by_id[ $export_id ] ) ) {
				return;
			}
			if ( isset( $visiting[ $export_id ] ) ) {
				return;
			}
			$visiting[ $export_id ] = true;
			$parent = $by_id[ $export_id ]['parent_export_id'] ?? null;
			if ( $parent ) {
				$visit( $parent );
			}
			$sorted[]              = $by_id[ $export_id ];
			$visited[ $export_id ] = true;
			unset( $visiting[ $export_id ] );
		};

		foreach ( array_keys( $by_id ) as $export_id ) {
			$visit( $export_id );
		}

		return $sorted;
	}

	/**
	 * Delete all items in a menu.
	 *
	 * @param int $menu_id Menu term ID.
	 * @return void
	 */
	protected function clear_menu_items( $menu_id ) {
		$existing = wp_get_nav_menu_items( $menu_id );
		if ( ! $existing ) {
			return;
		}
		foreach ( $existing as $ei ) {
			wp_delete_post( $ei->ID, true );
		}
	}

	/**
	 * Create one menu item (Uber settings applied later).
	 *
	 * @param int   $menu_id Destination menu term ID.
	 * @param array $item    Exported item.
	 * @return true|\WP_Error
	 */
	protected function import_item( $menu_id, array $item ) {
		$export_id = isset( $item['export_id'] ) ? $item['export_id'] : '';
		if ( '' === $export_id ) {
			return new WP_Error( 'invalid_item', 'A menu item is missing export_id.' );
		}

		$parent_id = 0;
		if ( ! empty( $item['parent_export_id'] ) && isset( $this->item_map[ $item['parent_export_id'] ] ) ) {
			$parent_id = (int) $this->item_map[ $item['parent_export_id'] ];
		}

		$uber_type = isset( $item['ubermenu_custom_item_type'] ) ? (string) $item['ubermenu_custom_item_type'] : '';
		$resolved  = $this->resolver->resolve_item_link( $item );

		// UberMenu custom item types must stay custom links with their special URLs.
		if ( $uber_type ) {
			$resolved = array(
				'status'    => 'anchor',
				'type'      => 'custom',
				'object'    => ! empty( $item['object'] ) ? $item['object'] : 'custom',
				'object_id' => 0,
				'url'       => ! empty( $item['url'] ) ? $item['url'] : '#',
				'post'      => null,
			);
		}

		$title = isset( $item['title'] ) ? $item['title'] : '';
		$args  = array(
			'menu-item-title'     => $title,
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => max( 0, $parent_id ),
			'menu-item-position'  => isset( $item['position'] ) ? (int) $item['position'] : 0,
			'menu-item-classes'   => isset( $item['classes'] ) && is_array( $item['classes'] ) ? implode( ' ', $item['classes'] ) : '',
			'menu-item-target'    => isset( $item['target'] ) ? $item['target'] : '',
			'menu-item-xfn'       => isset( $item['xfn'] ) ? $item['xfn'] : '',
			'menu-item-description' => isset( $item['description'] ) ? $item['description'] : '',
			'menu-item-attr-title'  => isset( $item['attr_title'] ) ? $item['attr_title'] : '',
		);

		if ( 'matched' === $resolved['status'] && ! $uber_type ) {
			$args['menu-item-type']      = 'post_type';
			$args['menu-item-object']    = $resolved['object'];
			$args['menu-item-object-id'] = (int) $resolved['object_id'];
			$args['menu-item-url']       = $resolved['url'];
		} else {
			$args['menu-item-type']   = 'custom';
			$args['menu-item-object'] = 'custom';
			$args['menu-item-url']    = $resolved['url'];
		}

		$path_label = isset( $item['path'] ) && $item['path'] ? $item['path'] : $resolved['url'];
		$this->report[] = array(
			'type'    => 'item',
			'status'  => $resolved['status'],
			'message' => sprintf(
				'%s "%s" (%s) -> %s',
				$uber_type ? '[' . $uber_type . ']' : 'Item',
				$title,
				$path_label,
				$resolved['status']
			),
		);

		if ( $this->dry_run ) {
			$item_id = -1 * ( count( $this->item_map ) + 1 );
			$this->item_map[ $export_id ] = $item_id;
			return true;
		}

		$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );
		if ( is_wp_error( $item_id ) ) {
			return $item_id;
		}

		$this->item_map[ $export_id ] = (int) $item_id;

		if ( $uber_type ) {
			update_post_meta( $item_id, $this->type_meta, sanitize_key( $uber_type ) );
		}

		return true;
	}

	/**
	 * Second pass: write UberMenu settings with remapped segment IDs and rewritten URLs.
	 *
	 * @param array $menu Menu payload.
	 * @return void
	 */
	protected function apply_item_settings( array $menu ) {
		$items = isset( $menu['items'] ) && is_array( $menu['items'] ) ? $menu['items'] : array();
		$defaults = array();
		if ( function_exists( 'ubermenu_menu_item_setting_defaults' ) ) {
			$defaults = ubermenu_menu_item_setting_defaults();
			if ( ! is_array( $defaults ) ) {
				$defaults = array();
			}
		}

		foreach ( $items as $item ) {
			$export_id = $item['export_id'] ?? '';
			if ( ! $export_id || ! isset( $this->item_map[ $export_id ] ) ) {
				continue;
			}

			$item_id  = (int) $this->item_map[ $export_id ];
			$settings = isset( $item['ubermenu_settings'] ) && is_array( $item['ubermenu_settings'] )
				? $item['ubermenu_settings']
				: array();

			if ( ! empty( $settings['menu_segment'] ) && '_none' !== $settings['menu_segment'] ) {
				$seg_export = $settings['menu_segment'];
				if ( isset( $this->menu_map[ $seg_export ] ) ) {
					$settings['menu_segment'] = (string) $this->menu_map[ $seg_export ];
					$this->report[] = array(
						'type'    => 'segment',
						'message' => sprintf(
							'Remapped segment %s -> menu ID %s for item "%s".',
							$seg_export,
							$settings['menu_segment'],
							$item['title'] ?? $export_id
						),
					);
				}
			}

			if ( ! empty( $settings['custom_content'] ) && is_string( $settings['custom_content'] ) ) {
				$settings['custom_content'] = $this->resolver->rewrite_content_urls( $settings['custom_content'] );
			}

			// Merge over defaults when available so UberMenu has a complete settings array.
			if ( $defaults ) {
				$settings = array_merge( $defaults, $settings );
			}

			if ( $this->dry_run || $item_id <= 0 ) {
				continue;
			}

			update_post_meta( $item_id, $this->settings_meta, $settings );
		}
	}

	/**
	 * Merge UberMenu options without wiping license-related keys.
	 *
	 * @param array $options Options from pack.
	 * @return void
	 */
	protected function import_ubermenu_options( array $options ) {
		$preserve_keys = array( 'envato_api_key', 'purchase_code', 'license_code', 'automatic_updates' );

		foreach ( $options as $option_name => $incoming ) {
			if ( ! is_string( $option_name ) || 0 !== strpos( $option_name, 'ubermenu_' ) ) {
				continue;
			}
			if ( ! is_array( $incoming ) ) {
				continue;
			}

			$existing = get_option( $option_name, array() );
			if ( ! is_array( $existing ) ) {
				$existing = array();
			}

			$merged = array_merge( $existing, $incoming );
			foreach ( $preserve_keys as $pkey ) {
				if ( isset( $existing[ $pkey ] ) ) {
					$merged[ $pkey ] = $existing[ $pkey ];
				}
			}

			$this->report[] = array(
				'type'    => 'option',
				'message' => sprintf( 'Would merge option %s.', $option_name ),
			);

			if ( ! $this->dry_run ) {
				update_option( $option_name, $merged );
				$this->report[ count( $this->report ) - 1 ]['message'] = sprintf( 'Merged option %s.', $option_name );
			}
		}
	}

	/**
	 * Assign theme locations by menu slug / export name mapping.
	 *
	 * @param array $locations location => menu slug.
	 * @return void
	 */
	protected function import_theme_locations( array $locations ) {
		$current = get_theme_mod( 'nav_menu_locations', array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		foreach ( $locations as $location => $menu_slug ) {
			$menu_id = 0;
			foreach ( $this->menu_map as $export_id => $new_id ) {
				// Match by finding the term for this new ID and comparing slug.
				if ( $new_id > 0 ) {
					$term = get_term( $new_id, 'nav_menu' );
					if ( $term && ! is_wp_error( $term ) && $term->slug === $menu_slug ) {
						$menu_id = $new_id;
						break;
					}
				}
			}

			if ( ! $menu_id ) {
				$term = get_term_by( 'slug', $menu_slug, 'nav_menu' );
				if ( $term ) {
					$menu_id = (int) $term->term_id;
				}
			}

			if ( ! $menu_id ) {
				$this->report[] = array(
					'type'    => 'warning',
					'message' => sprintf( 'Could not assign location "%s" (menu slug "%s" not found).', $location, $menu_slug ),
				);
				continue;
			}

			$current[ $location ] = $menu_id;
			$this->report[] = array(
				'type'    => 'location',
				'message' => sprintf(
					'%s theme location "%s" -> menu ID %d.',
					$this->dry_run ? 'Would assign' : 'Assigned',
					$location,
					$menu_id
				),
			);
		}

		if ( ! $this->dry_run ) {
			set_theme_mod( 'nav_menu_locations', $current );
		}
	}
}
