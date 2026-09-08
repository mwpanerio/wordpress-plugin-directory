<?php
/**
 * Admin UI for FX UberMenu Migrator.
 *
 * @package FX_UberMenu_Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Appearance > UberMenu Migrator screen.
 */
class FX_UberMenu_Migrator_Admin {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Last import report for display.
	 *
	 * @var array|null
	 */
	protected $last_result = null;

	/**
	 * Hook suffix of the admin screen.
	 *
	 * @var string
	 */
	protected $hook_suffix = '';

	/**
	 * Instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_fx_ubermenu_import_stream', array( $this, 'ajax_import_stream' ) );
	}

	/**
	 * Screen slug.
	 *
	 * @var string
	 */
	const SLUG = 'fx-ubermenu-migrator';

	/**
	 * Register submenu under Appearance.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = add_theme_page(
			'UberMenu Migrator',
			'UberMenu Migrator',
			'edit_theme_options',
			'fx-ubermenu-migrator',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Load screen assets on the migrator page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'fx-ubermenu-migrator-admin',
			FX_UBERMENU_MIGRATOR_URL . 'assets/admin.css',
			array(),
			FX_UBERMENU_MIGRATOR_VERSION
		);

		wp_enqueue_script(
			'fx-ubermenu-migrator-admin',
			FX_UBERMENU_MIGRATOR_URL . 'assets/admin.js',
			array(),
			FX_UBERMENU_MIGRATOR_VERSION,
			true
		);

		wp_localize_script(
			'fx-ubermenu-migrator-admin',
			'fxUberMenuData',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'action'         => 'fx_ubermenu_import_stream',
				'nonce'          => wp_create_nonce( 'fx_ubermenu_stream' ),
				'maxUpload'      => (int) wp_max_upload_size(),
				'maxUploadLabel' => size_format( wp_max_upload_size() ),
			)
		);
	}

	/**
	 * Handle export download / import upload.
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! is_admin() || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		if ( empty( $_POST['fx_ubermenu_migrator_action'] ) ) {
			$this->maybe_report_discarded_post();
			return;
		}

		check_admin_referer( 'fx_ubermenu_migrator' );

		$action = sanitize_key( wp_unslash( $_POST['fx_ubermenu_migrator_action'] ) );

		if ( 'export' === $action ) {
			$this->handle_export();
		} elseif ( 'import' === $action ) {
			$this->handle_import();
		}
	}

	/**
	 * Explain a POST that PHP threw away for exceeding post_max_size.
	 *
	 * Without this the screen simply reloads with no notice and no report, because
	 * an oversized body leaves both $_POST and $_FILES empty.
	 *
	 * @return void
	 */
	protected function maybe_report_discarded_post() {
		if ( empty( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return;
		}
		if ( ! isset( $_GET['page'] ) || self::SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		if ( ! empty( $_POST ) || ! empty( $_FILES ) ) {
			return;
		}

		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		if ( $length <= 0 ) {
			return;
		}

		add_settings_error(
			'fx_ubermenu_migrator',
			'post_too_large',
			sprintf(
				'That upload was %1$s, which is larger than this server allows (post_max_size is %2$s and the effective upload limit is %3$s), so PHP discarded it before WordPress saw it. Raise those PHP limits, or import large packs with the WP-CLI command shown at the bottom of this page.',
				size_format( $length ),
				esc_html( (string) ini_get( 'post_max_size' ) ),
				size_format( wp_max_upload_size() )
			),
			'error'
		);
	}

	/**
	 * Friendly message for a PHP upload error code.
	 *
	 * @param int $error PHP UPLOAD_ERR_* code.
	 * @return string
	 */
	protected function upload_error_message( $error ) {
		switch ( (int) $error ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return sprintf(
					'That pack is larger than this server accepts (upload_max_filesize is %1$s, effective limit %2$s). Raise the PHP limits, or import it with the WP-CLI command shown at the bottom of this page.',
					esc_html( (string) ini_get( 'upload_max_filesize' ) ),
					size_format( wp_max_upload_size() )
				);
			case UPLOAD_ERR_PARTIAL:
				return 'The upload was cut off before it finished. Please try again.';
			case UPLOAD_ERR_NO_FILE:
				return 'Please choose a JSON pack file first.';
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return 'The server could not write the uploaded file to disk. Check the PHP temp directory.';
			case UPLOAD_ERR_EXTENSION:
				return 'A PHP extension blocked this upload.';
		}

		return 'The upload failed. Please try again.';
	}

	/**
	 * Read the uploaded pack, or return an error.
	 *
	 * @return array|WP_Error
	 */
	protected function read_uploaded_pack() {
		if ( ! isset( $_FILES['import_file'] ) ) {
			return new WP_Error( 'no_file', 'Please choose a JSON pack file first.' );
		}

		$upload = $_FILES['import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- handled below.
		$error  = isset( $upload['error'] ) ? (int) $upload['error'] : UPLOAD_ERR_OK;

		if ( UPLOAD_ERR_OK !== $error ) {
			return new WP_Error( 'upload_error', $this->upload_error_message( $error ) );
		}
		if ( empty( $upload['tmp_name'] ) || ! is_uploaded_file( $upload['tmp_name'] ) ) {
			return new WP_Error( 'no_file', 'Please choose a JSON pack file first.' );
		}

		$raw = file_get_contents( $upload['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw || '' === $raw ) {
			return new WP_Error( 'empty_file', 'The uploaded file was empty.' );
		}

		$pack = json_decode( $raw, true );
		if ( ! is_array( $pack ) ) {
			return new WP_Error( 'bad_json', 'Could not parse JSON. Check the export file.' );
		}

		return $pack;
	}

	/**
	 * Importer options from the submitted form.
	 *
	 * @return array
	 */
	protected function import_args_from_post() {
		return array(
			'dry_run'          => ! empty( $_POST['dry_run'] ),
			'replace_existing' => ! empty( $_POST['replace_existing'] ),
			'import_options'   => ! empty( $_POST['import_options'] ),
			'import_locations' => ! empty( $_POST['import_locations'] ),
		);
	}

	/**
	 * Stream import progress as newline-delimited JSON.
	 *
	 * @return void
	 */
	public function ajax_import_stream() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to import menus.' ), 403 );
		}
		check_ajax_referer( 'fx_ubermenu_stream', 'nonce' );

		nocache_headers();
		header( 'Content-Type: application/x-ndjson; charset=utf-8' );
		// Keep proxies and PHP from holding the stream in a buffer.
		header( 'X-Accel-Buffering: no' );
		@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		while ( ob_get_level() > 0 ) {
			@ob_end_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$pack = $this->read_uploaded_pack();
		if ( is_wp_error( $pack ) ) {
			$this->emit( 'error', array( 'message' => $pack->get_error_message() ) );
			exit;
		}

		$args                      = $this->import_args_from_post();
		$args['progress_callback'] = array( $this, 'emit_progress' );

		$importer = new FX_UberMenu_Migrator_Importer( $args );
		$result   = $importer->import( $pack );

		if ( is_wp_error( $result ) ) {
			$this->emit( 'error', array( 'message' => $result->get_error_message() ) );
			exit;
		}

		$this->emit(
			'done',
			array(
				'dry_run' => ! empty( $result['dry_run'] ),
				'report'  => $this->normalize_report( (array) $result['report'] ),
			)
		);
		exit;
	}

	/**
	 * Progress callback for the streaming importer.
	 *
	 * @param array $progress Progress payload.
	 * @return void
	 */
	public function emit_progress( array $progress ) {
		$this->emit( 'progress', $progress );
	}

	/**
	 * Write one NDJSON event and push it to the browser.
	 *
	 * @param string $type    Event type.
	 * @param array  $payload Event payload.
	 * @return void
	 */
	protected function emit( $type, array $payload ) {
		$payload['t'] = (string) $type;

		echo wp_json_encode( $payload ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON stream.
		flush();
	}

	/**
	 * Flatten report rows for the browser, adding tone and status label.
	 *
	 * @param array $report Report rows.
	 * @return array
	 */
	protected function normalize_report( array $report ) {
		$rows = array();

		foreach ( $report as $row ) {
			$row    = (array) $row;
			$rows[] = array(
				'type'    => isset( $row['type'] ) ? (string) $row['type'] : '',
				'status'  => $this->report_label( $row ),
				'message' => isset( $row['message'] ) ? (string) $row['message'] : '',
				'tone'    => $this->report_tone( $row ),
			);
		}

		return $rows;
	}

	/**
	 * Stream JSON download.
	 *
	 * @return void
	 */
	protected function handle_export() {
		$menu_id = isset( $_POST['menu_id'] ) ? absint( $_POST['menu_id'] ) : 0;
		if ( ! $menu_id ) {
			add_settings_error( 'fx_ubermenu_migrator', 'no_menu', 'Please select a menu to export.', 'error' );
			return;
		}

		$exporter = new FX_UberMenu_Migrator_Exporter();
		$pack     = $exporter->export( $menu_id );

		$slug = 'ubermenu-export';
		$term = get_term( $menu_id, 'nav_menu' );
		if ( $term && ! is_wp_error( $term ) ) {
			$slug = sanitize_title( $term->slug );
		}

		$filename = $slug . '-' . gmdate( 'Ymd-His' ) . '.json';
		$json     = wp_json_encode( $pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw JSON download
		exit;
	}

	/**
	 * Process uploaded pack.
	 *
	 * @return void
	 */
	protected function handle_import() {
		$pack = $this->read_uploaded_pack();
		if ( is_wp_error( $pack ) ) {
			add_settings_error( 'fx_ubermenu_migrator', $pack->get_error_code(), $pack->get_error_message(), 'error' );
			return;
		}

		$args    = $this->import_args_from_post();
		$dry_run = ! empty( $args['dry_run'] );

		$importer = new FX_UberMenu_Migrator_Importer( $args );

		$result = $importer->import( $pack );
		if ( is_wp_error( $result ) ) {
			add_settings_error( 'fx_ubermenu_migrator', $result->get_error_code(), $result->get_error_message(), 'error' );
			return;
		}

		$this->last_result = $result;
		add_settings_error(
			'fx_ubermenu_migrator',
			'import_ok',
			$dry_run ? 'Dry run finished. Review the report below.' : 'Import finished. Review the report below.',
			'success'
		);
	}

	/**
	 * Build picker rows for every nav menu.
	 *
	 * @return array
	 */
	protected function get_menu_rows() {
		$menus     = wp_get_nav_menus();
		$locations = get_nav_menu_locations();
		$loc_names = get_registered_nav_menus();
		$by_menu   = array();

		foreach ( $locations as $location => $menu_id ) {
			$menu_id = (int) $menu_id;
			if ( $menu_id <= 0 ) {
				continue;
			}
			$label = isset( $loc_names[ $location ] ) ? $loc_names[ $location ] : $location;
			$by_menu[ $menu_id ][] = $label;
		}

		$rows = array();
		foreach ( (array) $menus as $menu ) {
			$id        = (int) $menu->term_id;
			$name      = '' !== trim( (string) $menu->name ) ? $menu->name : '(no name)';
			$assigned  = ! empty( $by_menu[ $id ] );
			$kind      = $assigned ? 'assigned' : 'segment';
			$kind_label = $assigned ? 'Theme location' : 'Likely segment';

			$rows[] = array(
				'id'         => $id,
				'name'       => $name,
				'slug'       => (string) $menu->slug,
				'items'      => (int) $menu->count,
				'assigned'   => $assigned ? 1 : 0,
				'kind'       => $kind,
				'kind_label' => $kind_label,
				'locations'  => $assigned ? $by_menu[ $id ] : array(),
				'edit_url'   => admin_url( 'nav-menus.php?action=edit&menu=' . $id ),
				'search'     => strtolower( $name . ' ' . $menu->slug . ' ' . $id . ' ' . ( $assigned ? implode( ' ', $by_menu[ $id ] ) : 'segment' ) ),
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				if ( $a['assigned'] !== $b['assigned'] ) {
					return $b['assigned'] - $a['assigned'];
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $rows;
	}

	/**
	 * Map a report row to a colour tone.
	 *
	 * UberMenu reports mix type-only rows (info/success/warning) with item rows that have a status.
	 *
	 * @param array $row Report row.
	 * @return string One of good, info, warn, bad.
	 */
	protected function report_tone( array $row ) {
		$type   = strtolower( trim( isset( $row['type'] ) ? (string) $row['type'] : '' ) );
		$status = strtolower( trim( isset( $row['status'] ) ? (string) $row['status'] : '' ) );

		if ( 'error' === $type || 'error' === $status ) {
			return 'bad';
		}
		if ( 'warning' === $type || in_array( $status, array( 'custom', 'missing', 'unresolved', 'fallback' ), true ) ) {
			return 'warn';
		}
		if ( 'success' === $type || 'matched' === $status ) {
			return 'good';
		}

		return 'info';
	}

	/**
	 * Label shown in the Status column for one report row.
	 *
	 * @param array $row Report row.
	 * @return string
	 */
	protected function report_label( array $row ) {
		if ( ! empty( $row['status'] ) ) {
			return (string) $row['status'];
		}
		return isset( $row['type'] ) ? (string) $row['type'] : 'info';
	}

	/**
	 * Count report rows for summary chips.
	 *
	 * @param array $report Report rows.
	 * @return array
	 */
	protected function report_summary( array $report ) {
		$summary = array();

		foreach ( $report as $row ) {
			$label = $this->report_label( $row );
			if ( ! isset( $summary[ $label ] ) ) {
				$summary[ $label ] = array(
					'count' => 0,
					'tone'  => $this->report_tone( $row ),
				);
			}
			$summary[ $label ]['count']++;
		}

		return $summary;
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$rows         = $this->get_menu_rows();
		$kind_counts  = array(
			'assigned' => 0,
			'segment'  => 0,
		);
		foreach ( $rows as $row ) {
			if ( isset( $kind_counts[ $row['kind'] ] ) ) {
				$kind_counts[ $row['kind'] ]++;
			}
		}
		?>
		<div class="wrap">
			<h1>UberMenu Migrator</h1>
			<p class="fx-um-intro">Copy an UberMenu from one site to another. Pick the menu here, download a single JSON file, then upload that file on the other site. Linked menu segments travel with it, and URLs are rewritten to the destination site automatically.</p>

			<?php settings_errors( 'fx_ubermenu_migrator' ); ?>

			<div class="fx-um-grid">
				<div class="fx-um-card">
					<div class="fx-um-card__head">
						<span class="fx-um-step">Step 1 - on the source site</span>
						<h2>Choose a menu to export</h2>
						<p class="fx-um-card__sub">Click a row to select it. Referenced UberMenu segments are included automatically.</p>
					</div>
					<div class="fx-um-card__body">
						<form method="post">
							<?php wp_nonce_field( 'fx_ubermenu_migrator' ); ?>
							<input type="hidden" name="fx_ubermenu_migrator_action" value="export" />

							<div class="fx-um-picker" data-fx-um="picker">
								<div class="fx-um-toolbar">
									<div class="fx-um-field fx-um-field--grow">
										<label for="fx-um-search">Search</label>
										<input type="search" id="fx-um-search" data-fx-um="search" placeholder="Menu name, slug, or ID" autocomplete="off" />
									</div>
									<div class="fx-um-field fx-um-field--fixed">
										<label for="fx-um-kind">Show</label>
										<select id="fx-um-kind" data-fx-um="kind">
											<option value="">All menus (<?php echo esc_html( (string) count( $rows ) ); ?>)</option>
											<?php if ( $kind_counts['assigned'] ) : ?>
												<option value="assigned">Assigned to a location (<?php echo esc_html( (string) $kind_counts['assigned'] ); ?>)</option>
											<?php endif; ?>
											<?php if ( $kind_counts['segment'] ) : ?>
												<option value="segment">Likely segments (<?php echo esc_html( (string) $kind_counts['segment'] ); ?>)</option>
											<?php endif; ?>
										</select>
									</div>
									<div class="fx-um-field fx-um-field--fixed">
										<label for="fx-um-sort">Sort by</label>
										<select id="fx-um-sort" data-fx-um="sort">
											<option value="assigned-first">Assigned first</option>
											<option value="name-asc">Name A-Z</option>
											<option value="name-desc">Name Z-A</option>
											<option value="items-desc">Most items</option>
											<option value="items-asc">Fewest items</option>
										</select>
									</div>
								</div>

								<div class="fx-um-bulk">
									<span class="fx-um-bulk__count" data-fx-um="count" aria-live="polite">No menu selected</span>
									<span class="fx-um-bulk__spacer"></span>
									<span class="fx-um-actions__hint" data-fx-um="shown"></span>
								</div>

								<?php if ( $rows ) : ?>
									<ul class="fx-um-list" data-fx-um="list">
										<?php foreach ( $rows as $row ) : ?>
											<li class="fx-um-row"
												data-fx-um="row"
												data-id="<?php echo esc_attr( (string) $row['id'] ); ?>"
												data-name="<?php echo esc_attr( $row['name'] ); ?>"
												data-items="<?php echo esc_attr( (string) $row['items'] ); ?>"
												data-assigned="<?php echo esc_attr( (string) $row['assigned'] ); ?>"
												data-kind="<?php echo esc_attr( $row['kind'] ); ?>"
												data-search="<?php echo esc_attr( $row['search'] ); ?>">
												<label class="fx-um-row__hit">
													<input type="radio" name="menu_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" required />
													<span class="fx-um-row__body">
														<span class="fx-um-row__title">
															<?php echo esc_html( $row['name'] ); ?>
															<span class="fx-um-badge fx-um-badge--<?php echo esc_attr( 'assigned' === $row['kind'] ? 'location' : 'segment' ); ?>">
																<?php echo esc_html( $row['kind_label'] ); ?>
															</span>
														</span>
														<span class="fx-um-row__meta">
															<code><?php echo esc_html( $row['slug'] ); ?></code>
															- ID <?php echo esc_html( (string) $row['id'] ); ?>
															- <?php echo esc_html( 1 === $row['items'] ? '1 item' : $row['items'] . ' items' ); ?>
															<?php if ( $row['locations'] ) : ?>
																- <?php echo esc_html( implode( ', ', $row['locations'] ) ); ?>
															<?php else : ?>
																- not assigned to a theme location
															<?php endif; ?>
														</span>
													</span>
												</label>
												<span class="fx-um-row__actions">
													<a href="<?php echo esc_url( $row['edit_url'] ); ?>" target="_blank" rel="noopener">Edit</a>
												</span>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php else : ?>
									<p class="fx-um-empty">No menus found on this site yet. Create one under Appearance &gt; Menus, then come back here.</p>
								<?php endif; ?>

								<p class="fx-um-empty" data-fx-um="empty" hidden>
									No menus match this search.
									<button type="button" class="button-link" data-fx-um-action="reset-filters">Reset filters</button>
								</p>

								<p class="description">Click anywhere on a row to select that menu. Exporting the main menu also packs any UberMenu segments it uses.</p>
							</div>

							<p class="fx-um-inline-error" data-fx-um="error" hidden>Select a menu before downloading an export.</p>

							<div class="fx-um-actions">
								<?php
								submit_button(
									'Download JSON export',
									'primary',
									'submit',
									false,
									array(
										'id'         => 'fx-um-export-submit',
										'data-fx-um' => 'submit',
									)
								);
								?>
							</div>
						</form>
					</div>
				</div>

				<div class="fx-um-card" data-fx-um="import">
					<div class="fx-um-card__head">
						<span class="fx-um-step">Step 2 - on the destination site</span>
						<h2>Import a menu pack</h2>
						<p class="fx-um-card__sub">Menus will be created on <code><?php echo esc_html( home_url() ); ?></code></p>
					</div>
					<div class="fx-um-card__body">
						<form method="post" enctype="multipart/form-data">
							<?php wp_nonce_field( 'fx_ubermenu_migrator' ); ?>
							<input type="hidden" name="fx_ubermenu_migrator_action" value="import" />

							<div class="fx-um-drop" data-fx-um="drop">
								<input type="file" name="import_file" id="fx-um-import-file" class="fx-um-drop__input" accept="application/json,.json" required data-fx-um="file" />
								<span class="fx-um-drop__label">
									<span class="fx-um-drop__title">Choose a pack file</span>
									<span class="fx-um-drop__hint">or drag the .json file you downloaded onto this box</span>
									<span class="fx-um-drop__hint">This server accepts uploads up to <?php echo esc_html( size_format( wp_max_upload_size() ) ); ?>. Larger packs have to go through WP-CLI.</span>
								</span>
								<p class="fx-um-drop__file" data-fx-um="file-info" hidden></p>
							</div>

							<ul class="fx-um-options">
								<li>
									<label class="fx-um-option">
										<input type="checkbox" name="replace_existing" value="1" checked />
										<span>
											<strong>Replace menus that already exist</strong>
											<span class="fx-um-option__hint">Matched by menu name. Turn this off to create a dated copy instead of overwriting.</span>
										</span>
									</label>
								</li>
								<li>
									<label class="fx-um-option">
										<input type="checkbox" name="import_options" value="1" checked />
										<span>
											<strong>Import UberMenu settings</strong>
											<span class="fx-um-option__hint">Merges instance settings from the pack. License keys already on this site are kept.</span>
										</span>
									</label>
								</li>
								<li>
									<label class="fx-um-option">
										<input type="checkbox" name="import_locations" value="1" checked />
										<span>
											<strong>Assign theme locations</strong>
											<span class="fx-um-option__hint">Hooks the imported menus into the same theme locations they used on the source site.</span>
										</span>
									</label>
								</li>
								<li>
									<label class="fx-um-option fx-um-option--danger">
										<input type="checkbox" name="dry_run" value="1" checked data-fx-um="dry-run" />
										<span>
											<strong>Dry run (recommended first)</strong>
											<span class="fx-um-option__hint">Shows exactly what would happen without changing anything on this site.</span>
										</span>
									</label>
								</li>
							</ul>

							<p class="fx-um-mode" data-fx-um="mode">Safe preview: nothing will be written.</p>

							<div class="fx-um-progress" data-fx-um="progress" hidden>
								<div class="fx-um-progress__head">
									<span class="fx-um-progress__phase" data-fx-um="progress-phase">Starting</span>
									<span class="fx-um-progress__pct" data-fx-um="progress-pct" aria-live="polite">0%</span>
								</div>
								<div class="fx-um-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-fx-um="progress-track">
									<span class="fx-um-progress__bar" data-fx-um="progress-bar"></span>
								</div>
								<p class="fx-um-progress__label" data-fx-um="progress-label"></p>
							</div>

							<p class="fx-um-inline-error" data-fx-um="import-error" hidden></p>

							<div class="fx-um-actions">
								<?php
								submit_button(
									'Preview import (dry run)',
									'primary',
									'submit',
									false,
									array(
										'id'         => 'fx-um-import-submit',
										'data-fx-um' => 'submit',
									)
								);
								?>
							</div>

							<p class="description">Pages and posts are matched by URL path when possible. If nothing matches, a custom link is created instead.</p>
						</form>
					</div>
				</div>
			</div>

			<div data-fx-um="report-target"></div>

			<?php if ( $this->last_result && ! empty( $this->last_result['report'] ) ) : ?>
				<?php
				$report  = $this->last_result['report'];
				$dry_run = ! empty( $this->last_result['dry_run'] );
				$summary = $this->report_summary( $report );
				?>
				<div class="fx-um-report" data-fx-um="report">
					<h2><?php echo $dry_run ? 'Dry run report' : 'Import report'; ?></h2>

					<ul class="fx-um-report__summary">
						<li class="fx-um-chip"><b><?php echo esc_html( (string) count( $report ) ); ?></b> total</li>
						<?php foreach ( $summary as $label => $info ) : ?>
							<li class="fx-um-chip fx-um-chip--<?php echo esc_attr( $info['tone'] ); ?>">
								<b><?php echo esc_html( (string) $info['count'] ); ?></b> <?php echo esc_html( $label ); ?>
							</li>
						<?php endforeach; ?>
					</ul>

					<div class="fx-um-report__tools">
						<input type="search" id="fx-um-report-search" data-fx-um="report-search" placeholder="Search the report" autocomplete="off" />
						<label class="fx-um-toggle">
							<input type="checkbox" data-fx-um="report-problems" /> Show only warnings and errors
						</label>
						<span class="fx-um-actions__hint" data-fx-um="report-count" aria-live="polite"></span>
					</div>

					<table class="widefat striped">
						<thead>
							<tr>
								<th style="width:110px;">Type</th>
								<th style="width:140px;">Status</th>
								<th>Details</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $report as $row ) : ?>
								<?php
								$tone  = $this->report_tone( $row );
								$label = $this->report_label( $row );
								?>
								<tr data-tone="<?php echo esc_attr( $tone ); ?>">
									<td><?php echo esc_html( isset( $row['type'] ) ? $row['type'] : '' ); ?></td>
									<td><span class="fx-um-pill fx-um-pill--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $label ); ?></span></td>
									<td><?php echo esc_html( isset( $row['message'] ) ? $row['message'] : '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<details class="fx-um-advanced">
				<summary>Advanced: run this from the command line (WP-CLI)</summary>
				<pre>wp fx-ubermenu export --menu="Main Menu" --file=main-menu.json
wp fx-ubermenu import --file=main-menu.json --dry-run
wp fx-ubermenu import --file=main-menu.json</pre>
			</details>
		</div>
		<?php
	}
}
