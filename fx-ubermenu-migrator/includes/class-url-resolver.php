<?php
/**
 * URL / path resolution helpers for UberMenu migrator.
 *
 * @package FX_UberMenu_Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve paths and rewrite URLs between sites.
 */
class FX_UberMenu_Migrator_URL_Resolver {

	/**
	 * Source site home URL (no trailing slash).
	 *
	 * @var string
	 */
	protected $source_home = '';

	/**
	 * Destination site home URL (no trailing slash).
	 *
	 * @var string
	 */
	protected $dest_home = '';

	/**
	 * Constructor.
	 *
	 * @param string $source_home Source home URL.
	 * @param string $dest_home   Destination home URL.
	 */
	public function __construct( $source_home = '', $dest_home = '' ) {
		$this->source_home = untrailingslashit( (string) $source_home );
		$this->dest_home   = untrailingslashit( (string) $dest_home );
	}

	/**
	 * Extract a site-relative path from a full URL when it matches source home.
	 *
	 * @param string $url Absolute or relative URL.
	 * @return string|null Path starting with /, or null if not remappable.
	 */
	public function path_from_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url || '#' === substr( $url, 0, 1 ) ) {
			return null;
		}

		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			$path = wp_parse_url( 'http://placeholder.local' . $url, PHP_URL_PATH );
			return $this->normalize_path( $path ? $path : $url );
		}

		if ( $this->source_home && 0 === stripos( $url, $this->source_home ) ) {
			$path = substr( $url, strlen( $this->source_home ) );
			$path = wp_parse_url( 'http://placeholder.local' . $path, PHP_URL_PATH );
			return $this->normalize_path( $path ? $path : '/' );
		}

		$parts = wp_parse_url( $url );
		if ( ! empty( $parts['path'] ) && empty( $parts['host'] ) ) {
			return $this->normalize_path( $parts['path'] );
		}

		return null;
	}

	/**
	 * Normalize a path: leading slash, trailing slash for non-file paths.
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	public function normalize_path( $path ) {
		$path = '/' . ltrim( (string) $path, '/' );

		if ( '/' === $path ) {
			return $path;
		}

		$basename = basename( $path );
		if ( false !== strpos( $basename, '.' ) ) {
			return $path;
		}

		return trailingslashit( $path );
	}

	/**
	 * Find a published page/post by path on the current (destination) site.
	 *
	 * @param string $path Path like /new-equipment/.
	 * @return WP_Post|null
	 */
	public function find_content_by_path( $path ) {
		$path = $this->normalize_path( $path );
		if ( '/' === $path || '' === $path ) {
			$front_id = (int) get_option( 'page_on_front' );
			if ( $front_id ) {
				$post = get_post( $front_id );
				if ( $post && 'publish' === $post->post_status ) {
					return $post;
				}
			}
			return null;
		}

		$full = $this->dest_home . $path;
		$post_id = url_to_postid( $full );
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post && 'publish' === $post->post_status ) {
				return $post;
			}
		}

		$trimmed = trim( $path, '/' );
		if ( '' === $trimmed ) {
			return null;
		}

		$page = get_page_by_path( $trimmed, OBJECT, array( 'page', 'post' ) );
		if ( $page && 'publish' === $page->post_status ) {
			return $page;
		}

		// Try last path segment as slug across public post types.
		$slug       = basename( untrailingslashit( $path ) );
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$query      = new WP_Query(
			array(
				'name'           => $slug,
				'post_type'      => array_values( $post_types ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);

		if ( $query->have_posts() ) {
			return $query->posts[0];
		}

		return null;
	}

	/**
	 * Build destination URL from a path.
	 *
	 * @param string $path Site-relative path.
	 * @return string
	 */
	public function dest_url_from_path( $path ) {
		$path = $this->normalize_path( $path );
		return $this->dest_home . ( '/' === $path ? '/' : $path );
	}

	/**
	 * Replace source home with destination home inside an HTML/string blob.
	 *
	 * @param string $content Content that may contain absolute URLs.
	 * @return string
	 */
	public function rewrite_content_urls( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		if ( $this->source_home && $this->dest_home && $this->source_home !== $this->dest_home ) {
			$content = str_ireplace( $this->source_home, $this->dest_home, $content );
		}

		return $content;
	}

	/**
	 * Resolve an exported item URL into create args + status for the importer.
	 *
	 * @param array $item Exported item array.
	 * @return array {
	 *   @type string      $status  matched|custom_fallback|unchanged|anchor
	 *   @type string      $type    Menu item type.
	 *   @type string      $object  Menu item object.
	 *   @type int|string  $object_id Object ID when matched.
	 *   @type string      $url     Final URL.
	 *   @type WP_Post|null $post   Matched post if any.
	 * }
	 */
	public function resolve_item_link( array $item ) {
		$url  = isset( $item['url'] ) ? (string) $item['url'] : '';
		$path = isset( $item['path'] ) ? $item['path'] : null;

		if ( '' === $url || '#' === substr( $url, 0, 1 ) ) {
			return array(
				'status'    => 'anchor',
				'type'      => 'custom',
				'object'    => ! empty( $item['object'] ) ? $item['object'] : 'custom',
				'object_id' => 0,
				'url'       => $url ? $url : '#',
				'post'      => null,
			);
		}

		if ( null === $path || '' === $path ) {
			$path = $this->path_from_url( $url );
		}

		if ( null === $path || '' === $path ) {
			return array(
				'status'    => 'unchanged',
				'type'      => 'custom',
				'object'    => 'custom',
				'object_id' => 0,
				'url'       => $this->rewrite_content_urls( $url ),
				'post'      => null,
			);
		}

		$post = $this->find_content_by_path( $path );
		if ( $post ) {
			return array(
				'status'    => 'matched',
				'type'      => 'post_type',
				'object'    => $post->post_type,
				'object_id' => (int) $post->ID,
				'url'       => get_permalink( $post ),
				'post'      => $post,
			);
		}

		return array(
			'status'    => 'custom_fallback',
			'type'      => 'custom',
			'object'    => 'custom',
			'object_id' => 0,
			'url'       => $this->dest_url_from_path( $path ),
			'post'      => null,
		);
	}
}
