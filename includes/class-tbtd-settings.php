<?php
/**
 * Settings page: API key, model, the two pages, role grants, AI limits.
 */

defined( 'ABSPATH' ) || exit;

class TBTD_Settings {

	const GROUP = 'tbtd_settings';
	const SLUG  = 'translation-drill-settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_menu() {
		$parent = defined( 'TBT_HUB_SLUG' ) ? TBT_HUB_SLUG : TBTD_Admin::SLUG;
		$label  = defined( 'TBT_HUB_SLUG' )
			? __( 'Translation Drill Settings', 'translation-drill' )
			: __( 'Settings', 'translation-drill' );

		add_submenu_page(
			$parent,
			__( 'Translation Drill Settings', 'translation-drill' ),
			$label,
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function register_settings() {
		register_setting( self::GROUP, 'tbtd_api_key', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_api_key' ),
			'default'           => '',
		) );
		register_setting( self::GROUP, 'tbtd_model', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );
		register_setting( self::GROUP, 'tbtd_player_page_id', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		) );
		register_setting( self::GROUP, 'tbtd_builder_page_id', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		) );
		register_setting( self::GROUP, 'tbtd_manager_roles', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_manager_roles' ),
			'default'           => array( 'administrator' ),
		) );
		register_setting( self::GROUP, 'tbtd_max_sentences', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_max_sentences' ),
			'default'           => TBTD_Translator::DEFAULT_MAX_SENTENCES,
		) );
		register_setting( self::GROUP, 'tbtd_max_translations_per_day', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => TBTD_Translator::DEFAULT_MAX_REQUESTS,
		) );
	}

	/**
	 * Keep the stored key when the field is left blank, so submitting the
	 * masked form never wipes it. A single dash clears it on purpose, which
	 * is how a site switches back to Swipe's key.
	 */
	public function sanitize_api_key( $value ) {
		$value = trim( (string) $value );
		if ( '-' === $value ) {
			return '';
		}
		if ( '' === $value ) {
			return get_option( 'tbtd_api_key', '' );
		}
		return sanitize_text_field( $value );
	}

	/**
	 * Normalise the role selection and push it onto the roles themselves.
	 * Administrators are always included.
	 *
	 * @param mixed $value Submitted role slugs.
	 * @return string[]
	 */
	public function sanitize_manager_roles( $value ) {
		$selected = array_values( array_unique( array_map( 'sanitize_key', (array) $value ) ) );
		$selected = array_values( array_filter( $selected, function ( $slug ) {
			return (bool) get_role( $slug );
		} ) );
		if ( ! in_array( 'administrator', $selected, true ) ) {
			$selected[] = 'administrator';
		}

		TBTD_Capabilities::sync_role_caps( $selected );

		return $selected;
	}

	public function sanitize_max_sentences( $value ) {
		$value = absint( $value );
		if ( $value < 1 ) {
			$value = TBTD_Translator::DEFAULT_MAX_SENTENCES;
		}
		return min( $value, TBTD_Translator::HARD_MAX_SENTENCES );
	}

	/**
	 * A page dropdown with its help line.
	 */
	private function page_row( $option, $label, $help ) {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php
				wp_dropdown_pages( array(
					'name'              => $option,
					'id'                => $option,
					'selected'          => (int) get_option( $option, 0 ),
					'show_option_none'  => __( '— Select a page —', 'translation-drill' ),
					'option_none_value' => 0,
				) );
				?>
				<p class="description"><?php echo esc_html( $help ); ?></p>
			</td>
		</tr>
		<?php
	}

	public function render() {
		$source        = TBTD_API::key_source();
		$has_own_key   = 'own' === $source;
		$model         = (string) get_option( 'tbtd_model', '' );
		$manager_roles = TBTD_Capabilities::managing_roles();
		$all_roles     = wp_roles()->roles;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Translation Drill Settings', 'translation-drill' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2 class="title"><?php esc_html_e( 'AI', 'translation-drill' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tbtd_api_key"><?php esc_html_e( 'OpenAI API key', 'translation-drill' ); ?></label></th>
						<td>
							<input type="password" name="tbtd_api_key" id="tbtd_api_key" class="regular-text" autocomplete="off" value=""
								placeholder="<?php echo $has_own_key ? esc_attr__( 'Saved — leave blank to keep', 'translation-drill' ) : 'sk-…'; ?>">
							<p class="description">
								<?php
								if ( 'swipe' === $source ) {
									esc_html_e( 'Currently using the key saved in TBT Swipe. Enter a key here only if this plugin should use a different one.', 'translation-drill' );
								} elseif ( $has_own_key ) {
									esc_html_e( 'Stored server-side and never sent to the browser. Leave blank to keep it; enter a single dash (-) to remove it and fall back to TBT Swipe\'s key.', 'translation-drill' );
								} else {
									esc_html_e( 'Stored server-side and never sent to the browser. If TBT Swipe has a key, it is used automatically while this is empty.', 'translation-drill' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tbtd_model"><?php esc_html_e( 'Model', 'translation-drill' ); ?></label></th>
						<td>
							<input type="text" name="tbtd_model" id="tbtd_model" class="regular-text" value="<?php echo esc_attr( $model ); ?>"
								placeholder="<?php echo esc_attr( TBTD_API::model() ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: 1: model in use */
									esc_html__( 'OpenAI model identifier. In use now: %1$s. Leave blank to use TBT Swipe\'s model, or the default if Swipe has none.', 'translation-drill' ),
									'<code>' . esc_html( TBTD_API::model() ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Pages', 'translation-drill' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->page_row(
						'tbtd_player_page_id',
						__( 'Student page', 'translation-drill' ),
						__( 'The published page with the [tbt_drill] shortcode. Every drill link and QR code points here.', 'translation-drill' )
					);
					$this->page_row(
						'tbtd_builder_page_id',
						__( 'Teacher page', 'translation-drill' ),
						__( 'The published page with the [tbt_drill_builder] shortcode. Used for Edit links from wp-admin. Optional: the page links to itself without it.', 'translation-drill' )
					);
					?>
				</table>

				<h2 class="title"><?php esc_html_e( 'Who can build drills', 'translation-drill' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Everyone in a ticked role can create, translate and delete their own drills on the teacher page. Avoid ticking a role that students also use. Each teacher only sees their own drills.', 'translation-drill' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Roles', 'translation-drill' ); ?></th>
						<td>
							<?php foreach ( $all_roles as $slug => $role ) : ?>
								<?php
								$is_admin_role = ( 'administrator' === $slug );
								$checked       = $is_admin_role || in_array( $slug, $manager_roles, true );
								?>
								<label style="display:block;margin-bottom:6px;">
									<input type="checkbox" name="tbtd_manager_roles[]" value="<?php echo esc_attr( $slug ); ?>"
										<?php checked( $checked ); ?> <?php disabled( $is_admin_role ); ?>>
									<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
									<?php if ( $is_admin_role ) : ?>
										<em>(<?php esc_html_e( 'always', 'translation-drill' ); ?>)</em>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'AI usage limits', 'translation-drill' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tbtd_max_sentences"><?php esc_html_e( 'Sentences per translation', 'translation-drill' ); ?></label></th>
						<td>
							<input type="number" min="1" max="<?php echo esc_attr( TBTD_Translator::HARD_MAX_SENTENCES ); ?>" step="1"
								name="tbtd_max_sentences" id="tbtd_max_sentences" class="small-text"
								value="<?php echo esc_attr( TBTD_Translator::max_sentences() ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: 1: default, 2: hard maximum */
									esc_html__( 'Most sentences sent to the AI in one go. Default %1$d, hard maximum %2$d.', 'translation-drill' ),
									(int) TBTD_Translator::DEFAULT_MAX_SENTENCES,
									(int) TBTD_Translator::HARD_MAX_SENTENCES
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tbtd_max_translations_per_day"><?php esc_html_e( 'Translations per teacher per day', 'translation-drill' ); ?></label></th>
						<td>
							<input type="number" min="0" step="1" name="tbtd_max_translations_per_day" id="tbtd_max_translations_per_day"
								class="small-text" value="<?php echo esc_attr( TBTD_Translator::default_max_requests() ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: 1: default, 2: user meta key */
									esc_html__( 'Default %1$d. 0 means no limit. Translating one sentence again counts as one. Failed calls cost nothing. A single teacher can be given a different limit with the user meta key %2$s.', 'translation-drill' ),
									(int) TBTD_Translator::DEFAULT_MAX_REQUESTS,
									'<code>' . esc_html( TBTD_Translator::LIMIT_META ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
