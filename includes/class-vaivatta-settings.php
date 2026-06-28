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
				'scope'          => '',
				'position'       => 'right',
				'lang_mode'      => 'auto',
				'show_on'        => 'all',
				'workspace_name' => '',
				'plan'           => '',
			)
		);
	}

	/**
	 * Sanitizes plugin options before saving via the settings form.
	 *
	 * Workspace_name and plan are set by the Connect flow (not the form),
	 * so they are preserved from the existing stored option. They are cleared
	 * when the user pastes a different scope manually.
	 *
	 * @param mixed $input Raw input from the settings form.
	 * @return array Sanitized values.
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();

		$scope    = isset( $input['scope'] ) ? trim( (string) $input['scope'] ) : '';
		$scope    = preg_match( '/^ten_[A-Za-z0-9_-]+$/', $scope ) ? $scope : '';
		$position = ( isset( $input['position'] ) && 'left' === $input['position'] ) ? 'left' : 'right';
		$lang     = in_array( isset( $input['lang_mode'] ) ? $input['lang_mode'] : '', array( 'fi', 'en' ), true ) ? $input['lang_mode'] : 'auto';
		$show     = ( isset( $input['show_on'] ) && 'none' === $input['show_on'] ) ? 'none' : 'all';

		// Preserve Connect-flow metadata when the scope is unchanged.
		$existing       = get_option( self::OPTION, array() );
		$existing       = is_array( $existing ) ? $existing : array();
		$existing_scope = isset( $existing['scope'] ) ? $existing['scope'] : '';

		if ( $scope === $existing_scope ) {
			$workspace_name = isset( $existing['workspace_name'] ) ? $existing['workspace_name'] : '';
			$plan           = isset( $existing['plan'] ) ? $existing['plan'] : '';
		} else {
			// Scope changed manually — clear Connect metadata.
			$workspace_name = '';
			$plan           = '';
		}

		return array(
			'scope'          => $scope,
			'position'       => $position,
			'lang_mode'      => $lang,
			'show_on'        => $show,
			'workspace_name' => $workspace_name,
			'plan'           => $plan,
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
		add_options_page( 'työ. by vaivatta.', 'työ', 'manage_options', 'vaivatta', array( $this, 'render' ) );
	}

	/**
	 * Renders the settings page HTML.
	 *
	 * When not connected: shows a prominent "Connect with vaivatta" button.
	 * When connected:     shows workspace info, Disconnect, and Reconnect links.
	 * Always:             the manual Workspace-ID form is available under "Advanced".
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$o         = self::get();
		$connected = ! empty( $o['scope'] );
		$connect   = new Vaivatta_Connect();

		// Read redirect-flag query params safely (read-only; no state change here).
		$raw_connected    = filter_input( INPUT_GET, 'vaivatta_connected' );
		$raw_disconnected = filter_input( INPUT_GET, 'vaivatta_disconnected' );
		$raw_error        = filter_input( INPUT_GET, 'vaivatta_error' );

		$notice_connected    = ( null !== $raw_connected );
		$notice_disconnected = ( null !== $raw_disconnected );
		$notice_error        = $raw_error ? sanitize_key( (string) $raw_error ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'työ. by vaivatta.', 'vaivatta' ); ?></h1>
			<p><?php esc_html_e( 'AI drafts every reply; a person approves and sends it. Your data and AI processing stay in the EU.', 'vaivatta' ); ?></p>

			<?php if ( $notice_connected ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Successfully connected to vaivatta!', 'vaivatta' ); ?></p>
				</div>
			<?php elseif ( $notice_disconnected ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Disconnected from vaivatta.', 'vaivatta' ); ?></p>
				</div>
			<?php elseif ( $notice_error ) : ?>
				<div class="notice notice-error is-dismissible">
					<?php if ( 'invalid_state' === $notice_error ) : ?>
						<p><?php esc_html_e( 'Connection failed: invalid or expired request. Please try again.', 'vaivatta' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'Connection failed. Please try again or contact support.', 'vaivatta' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $connected ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: workspace name or scope id */
								__( 'Connected to workspace: %s', 'vaivatta' ),
								'<strong>' . esc_html( $o['workspace_name'] ? $o['workspace_name'] : $o['scope'] ) . '</strong>'
							),
							array( 'strong' => array() )
						);
						?>
						<?php if ( ! empty( $o['plan'] ) ) : ?>
							&mdash; <span><?php echo esc_html( $o['plan'] ); ?></span>
						<?php endif; ?>
					</p>
				</div>
				<p>
					<form method="post" action="admin-post.php" style="display:inline">
						<input type="hidden" name="action" value="vaivatta_disconnect" />
						<?php wp_nonce_field( 'vaivatta_disconnect' ); ?>
						<button type="submit" class="button">
							<?php esc_html_e( 'Disconnect', 'vaivatta' ); ?>
						</button>
					</form>
					&nbsp;
					<a href="<?php echo esc_url( $connect->authorize_url() ); ?>" class="button">
						<?php esc_html_e( 'Reconnect', 'vaivatta' ); ?>
					</a>
				</p>
			<?php else : ?>
				<p>
					<a href="<?php echo esc_url( $connect->authorize_url() ); ?>" class="button button-primary button-large">
						<?php esc_html_e( 'Connect with työ', 'vaivatta' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<details style="margin-top:1.5em">
				<summary style="cursor:pointer;font-weight:600">
					<?php esc_html_e( 'Advanced: manual workspace ID', 'vaivatta' ); ?>
				</summary>
				<form method="post" action="options.php" style="margin-top:1em">
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
					<?php submit_button( __( 'Save settings', 'vaivatta' ) ); ?>
				</form>
			</details>

			<p class="description" style="margin-top:1.5em"><?php esc_html_e( 'Powered by työ (by vaivatta) — relies on the external vaivatta service (EU-hosted). See our Terms and Privacy Policy at vaivatta.fi.', 'vaivatta' ); ?></p>
		</div>
		<?php
	}
}
