<?php
/**
 * Main plugin class.
 *
 * @package SafeSchema
 */

namespace SafeSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	const META_SCHEMA = '_safeschema_json_ld';
	const META_MODE   = '_safeschema_mode';
	const META_HASH   = '_safeschema_validation_hash';
	const NONCE       = 'safeschema_save_schema';

	/** @var Plugin|null */
	private static $instance = null;

	/** @var array<int,array> */
	private $validation_cache = array();

	/**
	 * Get singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Register hooks. */
	private function __construct() {
		add_action( 'init', array( $this, 'register_meta' ), 99 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SAFESCHEMA_FILE ), array( $this, 'plugin_action_links' ) );

		add_filter( 'rank_math/json_ld', array( $this, 'filter_rank_math_schema' ), 999, 2 );
		add_filter( 'wpseo_json_ld_output', array( $this, 'filter_yoast_schema' ), 999 );
		add_filter( 'aioseo_schema_disable', array( $this, 'filter_aioseo_schema' ), 999 );

		$this->register_seopress_filters();
		add_action( 'wp', array( $this, 'suppress_seopress_schema_actions' ), 999 );
		add_action( 'template_redirect', array( $this, 'suppress_seopress_schema_actions' ), 999 );
		add_action( 'wp_head', array( $this, 'output_schema' ), 99 );
	}

	/** Register private post meta. */
	public function register_meta() {
		foreach ( $this->get_supported_post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_SCHEMA,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => array( $this, 'sanitize_schema_meta' ),
					'auth_callback'     => function() {
						return current_user_can( 'edit_posts' );
					},
				)
			);

			register_post_meta(
				$post_type,
				self::META_MODE,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => array( $this, 'sanitize_mode' ),
					'auth_callback'     => function() {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/** Add the Gutenberg-compatible meta box. */
	public function add_meta_boxes() {
		foreach ( $this->get_supported_post_types() as $post_type ) {
			add_meta_box(
				'safeschema-meta-box',
				__( 'SafeSchema', 'safeschema' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'low',
				array( '__block_editor_compatible_meta_box' => true )
			);
		}
	}

	/**
	 * Render meta box.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public function render_meta_box( $post ) {
		$schema    = (string) get_post_meta( $post->ID, self::META_SCHEMA, true );
		$mode      = $this->sanitize_mode( get_post_meta( $post->ID, self::META_MODE, true ) );
		$detected  = $this->detect_seo_plugins();
		$permalink = get_permalink( $post );

		wp_nonce_field( self::NONCE, 'safeschema_nonce' );
		?>
		<div class="safeschema-wrap" data-safeschema>
			<p class="safeschema-intro">
				<?php esc_html_e( 'Paste raw JSON-LD or one complete JSON-LD script block. SafeSchema validates and normalizes it before saving.', 'safeschema' ); ?>
			</p>

			<div class="safeschema-detected">
				<strong><?php esc_html_e( 'SEO plugin detected:', 'safeschema' ); ?></strong>
				<span>
					<?php
					echo esc_html(
						empty( $detected )
							? __( 'None of the supported SEO plugins is active.', 'safeschema' )
							: implode( ', ', $detected )
					);
					?>
				</span>
			</div>

			<fieldset class="safeschema-modes">
				<legend><?php esc_html_e( 'Output mode', 'safeschema' ); ?></legend>
				<label>
					<input type="radio" name="safeschema_mode" value="disabled" <?php checked( $mode, 'disabled' ); ?>>
					<span><strong><?php esc_html_e( 'Disabled', 'safeschema' ); ?></strong> <?php esc_html_e( 'Do not output this custom schema.', 'safeschema' ); ?></span>
				</label>
				<label>
					<input type="radio" name="safeschema_mode" value="add" <?php checked( $mode, 'add' ); ?>>
					<span><strong><?php esc_html_e( 'Add', 'safeschema' ); ?></strong> <?php esc_html_e( 'Output custom schema alongside existing schema.', 'safeschema' ); ?></span>
				</label>
				<label>
					<input type="radio" name="safeschema_mode" value="replace" <?php checked( $mode, 'replace' ); ?>>
					<span><strong><?php esc_html_e( 'Replace supported SEO schema', 'safeschema' ); ?></strong> <?php esc_html_e( 'Disable schema from supported SEO plugins on this URL, then output the custom schema.', 'safeschema' ); ?></span>
				</label>
			</fieldset>

			<div class="safeschema-replace-warning" data-safeschema-replace-warning>
				<strong><?php esc_html_e( 'Replace mode warning:', 'safeschema' ); ?></strong>
				<?php esc_html_e( 'Your custom JSON-LD should include every entity you want to keep. Themes, blocks, WooCommerce, tag managers, or unrelated plugins may still output schema.', 'safeschema' ); ?>
				<?php if ( in_array( 'SEOPress', $detected, true ) ) : ?>
					<?php esc_html_e( 'SEOPress replacement is beta because its schema output is spread across several modules.', 'safeschema' ); ?>
				<?php endif; ?>
			</div>

			<label class="safeschema-label" for="safeschema_json_ld"><?php esc_html_e( 'Custom JSON-LD', 'safeschema' ); ?></label>
			<textarea id="safeschema_json_ld" name="safeschema_json_ld" rows="18" spellcheck="false" placeholder='{"@context":"https://schema.org","@type":"WebPage"}'><?php echo esc_textarea( $schema ); ?></textarea>

			<div class="safeschema-actions">
				<button type="button" class="button button-secondary" data-safeschema-validate><?php esc_html_e( 'Validate', 'safeschema' ); ?></button>
				<button type="button" class="button button-secondary" data-safeschema-format><?php esc_html_e( 'Format JSON', 'safeschema' ); ?></button>
				<span class="safeschema-status" data-safeschema-status aria-live="polite"></span>
			</div>

			<ul class="safeschema-checks">
				<li><?php esc_html_e( 'Syntax validation runs in the browser and again in PHP when the post is saved.', 'safeschema' ); ?></li>
				<li><?php esc_html_e( 'SafeSchema validates JSON structure, not Google rich result eligibility or factual accuracy.', 'safeschema' ); ?></li>
			</ul>

			<?php if ( $permalink && 'publish' === $post->post_status ) : ?>
				<p class="safeschema-test-links">
					<a class="button-link" href="<?php echo esc_url( $permalink ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open page', 'safeschema' ); ?></a>
					<span aria-hidden="true"> · </span>
					<a class="button-link" href="<?php echo esc_url( 'https://search.google.com/test/rich-results?url=' . rawurlencode( $permalink ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Rich Results Test', 'safeschema' ); ?></a>
					<span aria-hidden="true"> · </span>
					<a class="button-link" href="<?php echo esc_url( 'https://validator.schema.org/#url=' . rawurlencode( $permalink ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Schema.org Validator', 'safeschema' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save post meta only when input is valid.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 */
	public function save_post( $post_id, $post ) {
		if ( ! isset( $_POST['safeschema_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['safeschema_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post || ! in_array( $post->post_type, $this->get_supported_post_types(), true ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$mode  = isset( $_POST['safeschema_mode'] ) ? $this->sanitize_mode( wp_unslash( $_POST['safeschema_mode'] ) ) : 'disabled';
		$input = isset( $_POST['safeschema_json_ld'] ) ? (string) wp_unslash( $_POST['safeschema_json_ld'] ) : '';

		if ( 'disabled' === $mode && '' === trim( $input ) ) {
			update_post_meta( $post_id, self::META_MODE, 'disabled' );
			delete_post_meta( $post_id, self::META_SCHEMA );
			delete_post_meta( $post_id, self::META_HASH );
			$this->set_notice( $post_id, 'success', __( 'SafeSchema is disabled and its saved JSON-LD was removed.', 'safeschema' ) );
			return;
		}

		$validation = Validator::validate( $input );
		if ( ! $validation['valid'] ) {
			$message = implode( ' ', $validation['errors'] );
			if ( 'disabled' === $mode ) {
				update_post_meta( $post_id, self::META_MODE, 'disabled' );
				$this->set_notice( $post_id, 'warning', __( 'SafeSchema was disabled. The invalid editor value was not saved, and the previous valid JSON-LD was kept.', 'safeschema' ) . ' ' . $message );
				return;
			}
			$this->set_notice( $post_id, 'error', __( 'SafeSchema was not updated. The previously saved schema and mode were kept.', 'safeschema' ) . ' ' . $message );
			return;
		}

		update_post_meta( $post_id, self::META_SCHEMA, $validation['json'] );
		update_post_meta( $post_id, self::META_MODE, $mode );
		update_post_meta( $post_id, self::META_HASH, hash( 'sha256', $validation['json'] ) );

		$message = __( 'Custom schema validated and saved.', 'safeschema' );
		if ( ! empty( $validation['warnings'] ) ) {
			$message .= ' ' . implode( ' ', $validation['warnings'] );
		}
		$this->set_notice( $post_id, empty( $validation['warnings'] ) ? 'success' : 'warning', $message );
	}

	/** Load editor assets only on supported edit screens. */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $this->get_supported_post_types(), true ) ) {
			return;
		}

		wp_enqueue_style( 'safeschema-admin', SAFESCHEMA_URL . 'assets/admin.css', array(), SAFESCHEMA_VERSION );
		wp_enqueue_script( 'safeschema-admin', SAFESCHEMA_URL . 'assets/admin.js', array(), SAFESCHEMA_VERSION, true );
		wp_localize_script(
			'safeschema-admin',
			'SafeSchemaI18n',
			array(
				'valid'          => __( 'Valid JSON-LD syntax.', 'safeschema' ),
				'empty'          => __( 'Schema is empty.', 'safeschema' ),
				'invalid'        => __( 'Invalid JSON:', 'safeschema' ),
				'root'           => __( 'The JSON root must be an object or an array of objects.', 'safeschema' ),
				'formatted'      => __( 'JSON formatted.', 'safeschema' ),
				'wrapperRemoved' => __( 'The JSON-LD script wrapper was removed and the JSON was formatted.', 'safeschema' ),
				'badWrapper'     => __( 'Only one complete JSON-LD script wrapper is accepted.', 'safeschema' ),
				'missingContext' => __( 'Warning: no @context property was found.', 'safeschema' ),
				'missingType'    => __( 'Warning: no @type or @graph property was found.', 'safeschema' ),
			)
		);
	}

	/** Display a one-time save result notice. */
	public function admin_notice() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $post_id ) {
			return;
		}

		$key    = $this->notice_key( $post_id );
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );

		$type = in_array( $notice['type'], array( 'success', 'warning', 'error', 'info' ), true ) ? $notice['type'] : 'info';
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p><strong>SafeSchema:</strong> %2$s</p></div>',
			esc_attr( $type ),
			esc_html( $notice['message'] )
		);
	}

	/** Add an editor link from the Plugins screen. */
	public function plugin_action_links( $links ) {
		return array_merge(
			array( '<a href="' . esc_url( admin_url( 'edit.php' ) ) . '">' . esc_html__( 'Edit a post', 'safeschema' ) . '</a>' ),
			$links
		);
	}

	/** Disable Rank Math JSON-LD in Replace mode. */
	public function filter_rank_math_schema( $data, $json = null ) {
		unset( $json );
		return $this->should_replace_current_schema() ? array() : $data;
	}

	/** Disable Yoast JSON-LD in Replace mode. */
	public function filter_yoast_schema( $data ) {
		return $this->should_replace_current_schema() ? false : $data;
	}

	/** Disable AIOSEO schema in Replace mode. */
	public function filter_aioseo_schema( $disabled ) {
		return $this->should_replace_current_schema() ? true : (bool) $disabled;
	}

	/** Register known SEOPress schema HTML filters. */
	private function register_seopress_filters() {
		$filters = array(
			'seopress_schemas_website_html',
			'seopress_schemas_organization_html',
			'seopress_schemas_article_html',
			'seopress_schemas_breadcrumb_html',
			'seopress_schemas_breadcrumbs_html',
			'seopress_schemas_course_html',
			'seopress_schemas_custom_html',
			'seopress_schemas_event_html',
			'seopress_schemas_faq_html',
			'seopress_schemas_howto_html',
			'seopress_schemas_job_html',
			'seopress_schemas_localbusiness_html',
			'seopress_schemas_product_html',
			'seopress_schemas_recipe_html',
			'seopress_schemas_review_html',
			'seopress_schemas_service_html',
			'seopress_schemas_softwareapp_html',
			'seopress_schemas_softwareapplication_html',
			'seopress_schemas_video_html',
		);

		foreach ( $filters as $filter ) {
			add_filter( $filter, array( $this, 'filter_seopress_schema_html' ), 999 );
		}
	}

	/** Empty a known SEOPress schema filter in Replace mode. */
	public function filter_seopress_schema_html( $html ) {
		return $this->should_replace_current_schema() ? '' : $html;
	}

	/** Remove identifiable SEOPress schema callbacks only. */
	public function suppress_seopress_schema_actions() {
		if ( ! $this->should_replace_current_schema() || ! $this->is_seopress_active() ) {
			return;
		}
		$this->remove_matching_callbacks_from_hook( 'wp_head' );
		$this->remove_matching_callbacks_from_hook( 'wp_footer' );
	}

	/** Remove matching callbacks from one hook. */
	private function remove_matching_callbacks_from_hook( $hook_name ) {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook_name ] ) || ! $wp_filter[ $hook_name ] instanceof \WP_Hook ) {
			return;
		}

		foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( empty( $callback['function'] ) || ! $this->is_seopress_schema_callback( $callback['function'] ) ) {
					continue;
				}
				remove_filter( $hook_name, $callback['function'], $priority );
			}
		}
	}

	/** Determine whether a callback belongs to SEOPress schema rendering. */
	private function is_seopress_schema_callback( $callback ) {
		$descriptor = '';
		if ( is_string( $callback ) ) {
			$descriptor = $callback;
		} elseif ( is_array( $callback ) && 2 === count( $callback ) ) {
			$owner      = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			$descriptor = $owner . '::' . (string) $callback[1];
		} elseif ( $callback instanceof \Closure ) {
			return false;
		} elseif ( is_object( $callback ) ) {
			$descriptor = get_class( $callback );
		}

		$descriptor = strtolower( $descriptor );
		if ( false === strpos( $descriptor, 'seopress' ) ) {
			return false;
		}

		return false !== strpos( $descriptor, 'schema' )
			|| false !== strpos( $descriptor, 'json_ld' )
			|| false !== strpos( $descriptor, 'jsonld' )
			|| false !== strpos( $descriptor, 'seopress_social_website_option' );
	}

	/** Print the custom schema safely. */
	public function output_schema() {
		$post_id = $this->current_post_id();
		if ( ! $post_id || post_password_required( $post_id ) ) {
			return;
		}

		$mode = $this->sanitize_mode( get_post_meta( $post_id, self::META_MODE, true ) );
		if ( ! in_array( $mode, array( 'add', 'replace' ), true ) ) {
			return;
		}

		$validation = $this->get_validated_schema( $post_id );
		if ( ! $validation['valid'] ) {
			return;
		}

		$json = wp_json_encode(
			$validation['data'],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		if ( false === $json ) {
			return;
		}

		$open  = '<' . 'script id="safeschema-json-ld" type="application/ld+json">';
		$close = '<' . '/script>';
		echo "\n" . $open . $json . $close . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated data is encoded with JSON_HEX flags.
	}

	/** Check whether Replace mode is active for the current singular post. */
	private function should_replace_current_schema() {
		$post_id = $this->current_post_id();
		if ( ! $post_id ) {
			return false;
		}
		if ( 'replace' !== $this->sanitize_mode( get_post_meta( $post_id, self::META_MODE, true ) ) ) {
			return false;
		}
		return $this->get_validated_schema( $post_id )['valid'];
	}

	/** Get and cache validated schema for the request. */
	private function get_validated_schema( $post_id ) {
		$post_id = absint( $post_id );
		if ( isset( $this->validation_cache[ $post_id ] ) ) {
			return $this->validation_cache[ $post_id ];
		}
		$stored = (string) get_post_meta( $post_id, self::META_SCHEMA, true );
		$this->validation_cache[ $post_id ] = Validator::validate( $stored );
		return $this->validation_cache[ $post_id ];
	}

	/** Get current singular post ID. */
	private function current_post_id() {
		if ( is_admin() || ! is_singular() ) {
			return 0;
		}
		return absint( get_queried_object_id() );
	}

	/** Sanitize output mode. */
	public function sanitize_mode( $mode ) {
		$mode = is_string( $mode ) ? strtolower( $mode ) : '';
		return in_array( $mode, array( 'disabled', 'add', 'replace' ), true ) ? $mode : 'disabled';
	}

	/** Sanitize schema meta written by another API. */
	public function sanitize_schema_meta( $value ) {
		$validation = Validator::validate( is_string( $value ) ? $value : '' );
		return $validation['valid'] ? $validation['json'] : '';
	}

	/** Get supported public, editable post types. */
	private function get_supported_post_types() {
		$post_types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'names'
		);
		unset( $post_types['attachment'] );
		return array_values( $post_types );
	}

	/** Detect supported SEO plugins for the editor message. */
	private function detect_seo_plugins() {
		$plugins = array();
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false ) ) {
			$plugins[] = 'Rank Math';
		}
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options', false ) ) {
			$plugins[] = 'Yoast SEO';
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			$plugins[] = 'All in One SEO';
		}
		if ( $this->is_seopress_active() ) {
			$plugins[] = 'SEOPress';
		}
		return $plugins;
	}

	/** Check SEOPress state. */
	private function is_seopress_active() {
		return defined( 'SEOPRESS_VERSION' )
			|| function_exists( 'seopress_get_service' )
			|| class_exists( 'SEOPress\Core\Kernel', false );
	}

	/** Save a one-time admin notice. */
	private function set_notice( $post_id, $type, $message ) {
		set_transient(
			$this->notice_key( $post_id ),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/** Get notice key. */
	private function notice_key( $post_id ) {
		return 'safeschema_notice_' . get_current_user_id() . '_' . absint( $post_id );
	}
}
