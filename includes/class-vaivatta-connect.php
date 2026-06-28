<?php
/**
 * Connect with vaivatta — OAuth-style handshake handler.
 *
 * @package vaivatta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the "Connect with vaivatta" OAuth-style flow.
 *
 * The flow:
 *  1. authorize_url()     → redirect owner to vaivatta to authenticate + pick workspace.
 *  2. handle_callback()   → vaivatta redirects back with ?code=&state=; verify nonce,
 *                           exchange code for tenantId, store in vaivatta_options.
 *  3. handle_disconnect() → clears scope/workspace_name/plan from the option.
 */
class Vaivatta_Connect {

	/**
	 * Registers the admin_post hooks for connect/disconnect callbacks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_vaivatta_connect', array( $this, 'handle_callback' ) );
		add_action( 'admin_post_vaivatta_disconnect', array( $this, 'handle_disconnect' ) );
	}

	/**
	 * Returns the URL that starts the connect flow on the vaivatta platform.
	 *
	 * Includes a redirect_uri pointing back to this plugin's admin_post handler
	 * and a state nonce to guard the callback.
	 *
	 * @return string Full authorize URL.
	 */
	public function authorize_url(): string {
		$base     = apply_filters( 'vaivatta_app_base', 'https://app.vaivatta.fi' );
		$redirect = admin_url( 'admin-post.php?action=vaivatta_connect' );
		$state    = wp_create_nonce( 'vaivatta_connect' );
		return $base
			. '/connect/wordpress?redirect_uri='
			. rawurlencode( $redirect )
			. '&state='
			. rawurlencode( $state );
	}

	/**
	 * Handles the OAuth callback from vaivatta (?code=&state=).
	 *
	 * Verifies the state nonce, exchanges the code server-side, and saves
	 * scope / workspace_name / plan into vaivatta_options.
	 *
	 * @return void
	 */
	public function handle_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'vaivatta' ) );
		}

		$state         = sanitize_text_field( wp_unslash( isset( $_GET['state'] ) ? $_GET['state'] : '' ) );
		$redirect_base = admin_url( 'options-general.php?page=vaivatta' );

		if ( ! wp_verify_nonce( $state, 'vaivatta_connect' ) ) {
			$this->do_redirect( add_query_arg( 'vaivatta_error', 'invalid_state', $redirect_base ) );
			return;
		}

		$code = sanitize_text_field( wp_unslash( isset( $_GET['code'] ) ? $_GET['code'] : '' ) );
		$data = $this->exchange( $code );

		if ( $data && ! empty( $data['tenantId'] ) ) {
			$opts = get_option( Vaivatta_Settings::OPTION, array() );
			$opts = is_array( $opts ) ? $opts : array();

			$opts['scope']          = sanitize_text_field( $data['tenantId'] );
			$opts['workspace_name'] = sanitize_text_field( isset( $data['workspaceName'] ) ? $data['workspaceName'] : '' );
			$opts['plan']           = sanitize_text_field( isset( $data['plan'] ) ? $data['plan'] : '' );

			update_option( Vaivatta_Settings::OPTION, $opts );
			$this->do_redirect( add_query_arg( 'vaivatta_connected', '1', $redirect_base ) );
			return;
		}

		$this->do_redirect( add_query_arg( 'vaivatta_error', 'exchange_failed', $redirect_base ) );
	}

	/**
	 * Handles the disconnect action (clears the workspace connection).
	 *
	 * @return void
	 */
	public function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'vaivatta' ) );
		}

		check_admin_referer( 'vaivatta_disconnect' );

		$opts = get_option( Vaivatta_Settings::OPTION, array() );
		$opts = is_array( $opts ) ? $opts : array();

		$opts['scope']          = '';
		$opts['workspace_name'] = '';
		$opts['plan']           = '';

		update_option( Vaivatta_Settings::OPTION, $opts );

		$this->do_redirect(
			add_query_arg( 'vaivatta_disconnected', '1', admin_url( 'options-general.php?page=vaivatta' ) )
		);
	}

	/**
	 * Calls the vaivatta exchange endpoint and returns the decoded response data.
	 *
	 * Extracted as a protected method so tests can observe calls via the
	 * pre_http_request filter (WP's built-in HTTP mock mechanism).
	 *
	 * @param string $code The single-use connect code received in the callback.
	 * @return array|null Decoded associative array on HTTP 200 with tenantId, null otherwise.
	 */
	protected function exchange( string $code ): ?array {
		$api_base = apply_filters( 'vaivatta_api_base', 'https://app.vaivatta.fi/api/v1' );

		$response = wp_remote_post(
			$api_base . '/connect/wordpress/exchange',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'code' => $code ) ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tenantId'] ) ) {
			return null;
		}

		return $body;
	}

	/**
	 * Performs a safe redirect and exits.
	 *
	 * Extracted as a protected method so tests can subclass and override it
	 * to avoid calling exit() during test runs.
	 *
	 * @param string $url Target URL (passed through wp_safe_redirect).
	 * @return void
	 */
	protected function do_redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
