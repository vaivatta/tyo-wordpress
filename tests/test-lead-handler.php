<?php
/**
 * Tests for Vaivatta_Lead_Handler.
 *
 * @package vaivatta
 */

/**
 * Captures redirects instead of exiting (same pattern as the Connect stub).
 */
class Vaivatta_Lead_Handler_Test_Stub extends Vaivatta_Lead_Handler {

	/**
	 * Last redirect URL.
	 *
	 * @var string|null
	 */
	public $redirect_url = null;

	/**
	 * Captures the redirect URL.
	 *
	 * @param string $url Target URL.
	 * @return void
	 */
	protected function do_redirect( string $url ): void {
		$this->redirect_url = $url;
	}
}

/**
 * Test_Lead_Handler class.
 */
class Test_Lead_Handler extends WP_UnitTestCase {

	/**
	 * Captured wp_remote_post request (url + parsed args), or null.
	 *
	 * @var array|null
	 */
	private $captured = null;

	/**
	 * Mocked HTTP status for the platform response.
	 *
	 * @var int
	 */
	private $mock_status = 201;

	public function set_up() {
		parent::set_up();
		update_option( Vaivatta_Settings::OPTION, array( 'scope' => 'acme' ) );
		$this->captured = null;
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		delete_option( Vaivatta_Settings::OPTION );
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Captures the outgoing request and returns a mocked platform response.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public function mock_http( $pre, $args, $url ) {
		$this->captured = array( 'url' => $url, 'args' => $args, 'body' => json_decode( $args['body'], true ) );
		return array(
			'headers'  => array(),
			'body'     => '{"ok":true}',
			'response' => array( 'code' => $this->mock_status ),
		);
	}

	/**
	 * Runs the handler with the given POST fields and returns the stub.
	 *
	 * @param array $post POST fields.
	 * @return Vaivatta_Lead_Handler_Test_Stub
	 */
	private function run_handler( array $post ): Vaivatta_Lead_Handler_Test_Stub {
		$_POST   = $post;
		$handler = new Vaivatta_Lead_Handler_Test_Stub();
		$handler->handle();
		return $handler;
	}

	public function test_happy_path_posts_lead_and_redirects_sent() {
		$h = $this->run_handler(
			array(
				'vaivatta_name'     => 'Matti Meikäläinen',
				'vaivatta_phone'    => '040 123 4567',
				'vaivatta_message'  => "Ääni kuuluu edestä.\nToinen rivi.",
				'vaivatta_extra'    => array( 'Rekisterinumero / Reg. number' => 'ABC-123' ),
				'vaivatta_lang'     => 'fi',
				'vaivatta_redirect' => home_url( '/?p=1#yhteystiedot' ),
			)
		);

		$this->assertNotNull( $this->captured );
		$this->assertStringEndsWith( '/leads', $this->captured['url'] );
		$this->assertSame( 'acme', $this->captured['args']['headers']['x-tyo-tenant'] );
		$this->assertSame( home_url(), $this->captured['args']['headers']['Origin'] );
		$this->assertSame( 'Matti Meikäläinen', $this->captured['body']['name'] );
		$this->assertSame( '040 123 4567', $this->captured['body']['phone'] );
		$this->assertSame(
			array( array( 'label' => 'Rekisterinumero / Reg. number', 'value' => 'ABC-123' ) ),
			$this->captured['body']['extras']
		);
		$this->assertSame( home_url(), $this->captured['body']['source'] );
		$this->assertStringContainsString( 'vaivatta_sent=1', $h->redirect_url );
		$this->assertStringContainsString( '#yhteystiedot', $h->redirect_url );
	}

	public function test_honeypot_drops_silently_but_redirects_as_success() {
		$h = $this->run_handler(
			array(
				'vaivatta_name'  => 'Bot',
				'vaivatta_phone' => '1',
				'vaivatta_hp'    => 'https://spam.example',
			)
		);
		$this->assertNull( $this->captured );
		$this->assertStringContainsString( 'vaivatta_sent=1', $h->redirect_url );
	}

	public function test_missing_required_fields_redirect_error_without_posting() {
		$h = $this->run_handler( array( 'vaivatta_name' => 'NoPhone' ) );
		$this->assertNull( $this->captured );
		$this->assertStringContainsString( 'vaivatta_sent=0', $h->redirect_url );
	}

	public function test_unconnected_scope_redirects_error() {
		update_option( Vaivatta_Settings::OPTION, array( 'scope' => '' ) );
		$h = $this->run_handler( array( 'vaivatta_name' => 'X', 'vaivatta_phone' => '1' ) );
		$this->assertNull( $this->captured );
		$this->assertStringContainsString( 'vaivatta_sent=0', $h->redirect_url );
	}

	public function test_platform_error_redirects_error() {
		$this->mock_status = 429;
		$h = $this->run_handler( array( 'vaivatta_name' => 'X', 'vaivatta_phone' => '1' ) );
		$this->assertStringContainsString( 'vaivatta_sent=0', $h->redirect_url );
	}

	public function test_external_redirect_is_rejected() {
		$h = $this->run_handler(
			array(
				'vaivatta_name'     => 'X',
				'vaivatta_phone'    => '1',
				'vaivatta_redirect' => 'https://evil.example/phish',
			)
		);
		$this->assertStringStartsWith( home_url(), $h->redirect_url );
	}

	public function test_extras_capped_at_ten() {
		$extras = array();
		for ( $i = 0; $i < 15; $i++ ) {
			$extras[ 'Label ' . $i ] = 'v' . $i;
		}
		$this->run_handler(
			array( 'vaivatta_name' => 'X', 'vaivatta_phone' => '1', 'vaivatta_extra' => $extras )
		);
		$this->assertCount( 10, $this->captured['body']['extras'] );
	}
}
