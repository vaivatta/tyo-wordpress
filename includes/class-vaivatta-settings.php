<?php
/**
 * Settings registration and sanitization for vaivatta.
 *
 * @package vaivatta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin options: registration, sanitization, and admin UI.
 */
class Vaivatta_Settings {
	/**
	 * WordPress option name.
	 *
	 * @var string
	 */
	const OPTION = 'vaivatta_options';

	/**
	 * Registers admin menu and settings hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
	}

	/**
	 * Returns current options merged with defaults.
	 *
	 * @return array
	 */
	public static function get(): array {
		$o = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $o ) ? $o : array(),
			array(
				'scope'     => '',
				'position'  => 'right',
				'lang_mode' => 'auto',
				'show_on'   => 'all',
			)
		);
	}

	/**
	 * Sanitizes plugin options before saving.
	 *
	 * @param mixed $input Raw input from the settings form.
	 * @return array Sanitized values.
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$scope    = isset( $input['scope'] ) ? trim( (string) $input['scope'] ) : '';
		$scope    = preg_match( '/^ten_[A-Za-z0-9_-]+$/', $scope ) ? $scope : '';
		$position = ( isset( $input['position'] ) && 'left' === $input['position'] ) ? 'left' : 'right';
		$lang     = in_array( $input['lang_mode'] ?? '', array( 'fi', 'en' ), true ) ? $input['lang_mode'] : 'auto';
		$show     = ( isset( $input['show_on'] ) && 'none' === $input['show_on'] ) ? 'none' : 'all';
		return array(
			'scope'     => $scope,
			'position'  => $position,
			'lang_mode' => $lang,
			'show_on'   => $show,
		);
	}

	/**
	 * Registers the setting with WordPress.
	 *
	 * @return void
	 */
	public function settings() {
		register_setting( 'vaivatta', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	/**
	 * Adds the options page to the Settings menu.
	 *
	 * @return void
	 */
	public function menu() {
		add_options_page( 'vaivatta', 'vaivatta', 'manage_options', 'vaivatta', array( $this, 'render' ) );
	}

	/**
	 * Renders the settings page HTML.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = self::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'vaivatta — AI Front Desk', 'vaivatta' ); ?></h1>
			<p><?php esc_html_e( 'AI drafts every reply; a person approves and sends it. Your data and AI processing stay in the EU.', 'vaivatta' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'vaivatta' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="vv-scope"><?php esc_html_e( 'Workspace ID', 'vaivatta' ); ?></label></th>
						<td>
							<input name="vaivatta_options[scope]" id="vv-scope" type="text" class="regular-text"
								value="<?php echo esc_attr( $o['scope'] ); ?>" placeholder="ten_…" />
							<p class="description"><?php esc_html_e( 'Paste your vaivatta workspace ID (from your dashboard). Find it in the customer chat link: …/t/THIS.', 'vaivatta' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Bubble position', 'vaivatta' ); ?></th>
						<td>
							<select name="vaivatta_options[position]">
								<option value="right" <?php selected( $o['position'], 'right' ); ?>><?php esc_html_e( 'Bottom right', 'vaivatta' ); ?></option>
								<option value="left" <?php selected( $o['position'], 'left' ); ?>><?php esc_html_e( 'Bottom left', 'vaivatta' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Language', 'vaivatta' ); ?></th>
						<td>
							<select name="vaivatta_options[lang_mode]">
								<option value="auto" <?php selected( $o['lang_mode'], 'auto' ); ?>><?php esc_html_e( 'Match site language', 'vaivatta' ); ?></option>
								<option value="fi" <?php selected( $o['lang_mode'], 'fi' ); ?>>Suomi</option>
								<option value="en" <?php selected( $o['lang_mode'], 'en' ); ?>>English</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Show widget', 'vaivatta' ); ?></th>
						<td>
							<select name="vaivatta_options[show_on]">
								<option value="all" <?php selected( $o['show_on'], 'all' ); ?>><?php esc_html_e( 'On all pages', 'vaivatta' ); ?></option>
								<option value="none" <?php selected( $o['show_on'], 'none' ); ?>><?php esc_html_e( 'Hidden', 'vaivatta' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<p class="description"><?php esc_html_e( 'Powered by vaivatta — relies on the external vaivatta service (EU-hosted). See our Terms and Privacy Policy at vaivatta.fi.', 'vaivatta' ); ?></p>
		</div>
		<?php
	}
}
