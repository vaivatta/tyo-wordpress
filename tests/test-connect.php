<?php
/**
 * Tests for Vaivatta_Connect.
 *
 * @package vaivatta
 */

/**
 * Testable subclass of Vaivatta_Connect that overrides do_redirect() to avoid
 * calling exit() during PHPUnit test runs, while keeping all other production
 * behavior intact.
 */
class Vaivatta_Connect_Test_Stub extends Vaivatta_Connect {

	/**
	 * The last URL passed to do_redirect(), or null if not called.
	 *
	 * @var string|null
	 */
	public $redirect_url = null;

	/**
	 * Captures the redirect URL instead of actually redirecting.
	 *
	 * @param string $url Target URL.
	 * @return void
	 */
	protected function do_redirect( string $url ): void {
		$this->redirect_url = $url;
	}
}

/**
 * Test_Connect class.
 */
class Test_Connect extends WP_UnitTestCase {

	/**
	 * Admin user ID created for each test.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Sets up an admin user for each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Restores state after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		parent::tear_down();
		delete_option( Vaivatta_Settings::OPTION );
		$_GET     = array();
		$_REQUEST = array();
		remove_all_filters( 'pre_http_request' );
	}

	// -------------------------------------------------------------------------
	// authorize_url() tests
	// -------------------------------------------------------------------------

	/**
	 * authorize_url() must embed the admin callback URL (URL-encoded) and a
	 * non-empty state nonce.
	 *
	 * @return void
	 */
	public function test_authorize_url_contains_encoded_callback_and_state() {
		$connect = new Vaivatta_Connect();
		$url     = $connect->authorize_url();

		// The redirect_uri parameter must contain the admin-post callback URL-encoded.
		$this->assertStringContainsString(
			rawurlencode( 'admin-post.php?action=vaivatta_connect' ),
			$url,
			'authorize_url() must include the URL-encoded admin-post callback.'
		);

		// Parse the outer query string to extract the state parameter.
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
		$this->assertNotEmpty( $params['state'], 'authorize_url() must include a non-empty state nonce.' );

		// The site_url parameter must be present for opt-out seeding.
		$this->assertStringContainsString( 'site_url=', $url, 'authorize_url() must include the site_url parameter.' );
	}

	// -------------------------------------------------------------------------
	// handle_callback() — nonce rejection
	// -------------------------------------------------------------------------

	/**
	 * handle_callback() with an invalid state must write NO option.
	 *
	 * @return void
	 */
	public function test_handle_callback_rejects_invalid_state_and_writes_no_option() {
		$connect           = new Vaivatta_Connect_Test_Stub();
		$_GET['state']     = 'not_a_valid_nonce';
		$_GET['code']      = 'some_code';

		$connect->handle_callback();

		$opts = Vaivatta_Settings::get();
		$this->assertSame( '', $opts['scope'], 'scope must remain empty when nonce is invalid.' );
	}

	/**
	 * handle_callback() with an invalid state must redirect with an error flag.
	 *
	 * @return void
	 */
	public function test_handle_callback_invalid_state_redirects_with_error() {
		$connect       = new Vaivatta_Connect_Test_Stub();
		$_GET['state'] = 'not_a_valid_nonce';
		$_GET['code']  = 'some_code';

		$connect->handle_callback();

		$this->assertStringContainsString(
			'vaivatta_error=invalid_state',
			(string) $connect->redirect_url,
			'Bad nonce must redirect with vaivatta_error=invalid_state.'
		);
	}

	// -------------------------------------------------------------------------
	// handle_callback() — valid exchange (mocked via pre_http_request filter)
	// -------------------------------------------------------------------------

	/**
	 * handle_callback() with a valid nonce and a mocked successful exchange
	 * response must save scope, workspace_name, and plan into vaivatta_options.
	 *
	 * @return void
	 */
	public function test_handle_callback_saves_scope_on_valid_exchange() {
		// Mock the HTTP exchange call.
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'tenantId'      => 'ten_abc',
							'workspaceName' => 'Demo',
							'plan'          => 'free',
						)
					),
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$connect       = new Vaivatta_Connect_Test_Stub();
		$_GET['state'] = wp_create_nonce( 'vaivatta_connect' );
		$_GET['code']  = 'valid_code';

		$connect->handle_callback();

		$opts = Vaivatta_Settings::get();
		$this->assertSame( 'ten_abc', $opts['scope'], 'scope must be saved from the exchange tenantId.' );
		$this->assertSame( 'Demo', $opts['workspace_name'], 'workspace_name must be saved from the exchange.' );
		$this->assertSame( 'free', $opts['plan'], 'plan must be saved from the exchange.' );
	}

	/**
	 * handle_callback() with a valid nonce and a successful exchange must
	 * redirect to the settings page with vaivatta_connected=1.
	 *
	 * @return void
	 */
	public function test_handle_callback_success_redirects_with_connected_flag() {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) {
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => wp_json_encode(
						array(
							'tenantId'      => 'ten_abc',
							'workspaceName' => 'Demo',
							'plan'          => 'free',
						)
					),
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$connect       = new Vaivatta_Connect_Test_Stub();
		$_GET['state'] = wp_create_nonce( 'vaivatta_connect' );
		$_GET['code']  = 'valid_code';

		$connect->handle_callback();

		$this->assertStringContainsString(
			'vaivatta_connected=1',
			(string) $connect->redirect_url,
			'Successful exchange must redirect with vaivatta_connected=1.'
		);
	}

	/**
	 * handle_callback() where the exchange returns a non-200 status must
	 * write no scope and redirect with an error flag.
	 *
	 * @return void
	 */
	public function test_handle_callback_failed_exchange_writes_no_scope() {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) {
				return array(
					'response' => array( 'code' => 404, 'message' => 'Not Found' ),
					'body'     => wp_json_encode( array( 'error' => 'invalid_or_used_code' ) ),
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$connect       = new Vaivatta_Connect_Test_Stub();
		$_GET['state'] = wp_create_nonce( 'vaivatta_connect' );
		$_GET['code']  = 'expired_code';

		$connect->handle_callback();

		$opts = Vaivatta_Settings::get();
		$this->assertSame( '', $opts['scope'], 'Failed exchange must not write scope.' );
		$this->assertStringContainsString(
			'vaivatta_error=exchange_failed',
			(string) $connect->redirect_url,
			'Failed exchange must redirect with vaivatta_error=exchange_failed.'
		);
	}

	// -------------------------------------------------------------------------
	// handle_callback() — capability gate
	// -------------------------------------------------------------------------

	/**
	 * handle_callback() must call wp_die() for a non-admin user and must NOT
	 * write the scope option.
	 *
	 * In the WP test suite wp_die() throws WPDieException, so we catch it here
	 * and assert both that the exception was raised and that no option was saved.
	 *
	 * @return void
	 */
	public function test_handle_callback_denies_non_admin() {
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $uid );

		$connect       = new Vaivatta_Connect_Test_Stub();
		$_GET['state'] = 'any_value';
		$_GET['code']  = 'any_code';

		$caught = false;
		try {
			$connect->handle_callback();
		} catch ( WPDieException $e ) {
			$caught = true;
		}

		$this->assertTrue( $caught, 'handle_callback() must call wp_die() when user lacks manage_options.' );

		$opts = Vaivatta_Settings::get();
		$this->assertSame( '', $opts['scope'], 'scope must not be written when capability check fails.' );
	}

	// -------------------------------------------------------------------------
	// handle_disconnect() — clears the connection
	// -------------------------------------------------------------------------

	/**
	 * handle_disconnect() as an admin with a valid nonce must clear scope,
	 * workspace_name, and plan from vaivatta_options.
	 *
	 * @return void
	 */
	public function test_handle_disconnect_clears_connection() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		update_option(
			Vaivatta_Settings::OPTION,
			array(
				'scope'          => 'ten_abc',
				'workspace_name' => 'Demo',
				'plan'           => 'free',
			)
		);

		// check_admin_referer() reads _wpnonce from $_REQUEST.
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'vaivatta_disconnect' );

		$connect = new Vaivatta_Connect_Test_Stub();
		$connect->handle_disconnect();

		$opts = Vaivatta_Settings::get();
		$this->assertSame( '', $opts['scope'], 'handle_disconnect() must clear scope.' );
		$this->assertSame( '', $opts['workspace_name'], 'handle_disconnect() must clear workspace_name.' );
		$this->assertSame( '', $opts['plan'], 'handle_disconnect() must clear plan.' );
	}
}
