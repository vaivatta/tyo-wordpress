<?php
class Test_Settings extends WP_UnitTestCase {
	public function test_sanitize_keeps_valid_scope_and_rejects_garbage() {
		$s = new Vaivatta_Settings();
		$out = $s->sanitize( array(
			'scope'     => '  ten_AbC123  ',
			'position'  => 'left',
			'lang_mode' => 'fi',
			'show_on'   => 'all',
			'evil'      => '<script>',
		) );
		$this->assertSame( 'ten_AbC123', $out['scope'] );  // trimmed
		$this->assertSame( 'left', $out['position'] );
		$this->assertSame( 'fi', $out['lang_mode'] );
		$this->assertArrayNotHasKey( 'evil', $out );        // unknown keys dropped
	}

	public function test_sanitize_rejects_non_scope_string() {
		$s = new Vaivatta_Settings();
		$out = $s->sanitize( array( 'scope' => 'not a scope!' ) );
		$this->assertSame( '', $out['scope'] );             // only ten_<alnum> allowed
	}

	public function test_sanitize_rejects_invalid_hex_color() {
		$s   = new Vaivatta_Settings();
		$out = $s->sanitize( array( 'accent' => 'not-a-color' ) );
		$this->assertSame( '', $out['accent'], 'Invalid hex must sanitize to empty string.' );
	}

	public function test_sanitize_accepts_valid_hex_color() {
		$s   = new Vaivatta_Settings();
		$out = $s->sanitize( array( 'accent' => '#3b6ef8' ) );
		$this->assertSame( '#3b6ef8', $out['accent'], 'Valid 6-digit hex must be preserved.' );
	}

	public function test_sanitize_caps_greeting_at_120_chars() {
		$s        = new Vaivatta_Settings();
		$long     = str_repeat( 'a', 150 );
		$out      = $s->sanitize( array( 'greeting' => $long ) );
		$this->assertSame( 120, mb_strlen( $out['greeting'] ), 'Greeting must be capped at 120 characters.' );
	}

	public function test_sanitize_caps_reseller_code_at_60_chars() {
		$s    = new Vaivatta_Settings();
		$long = str_repeat( 'x', 80 );
		$out  = $s->sanitize( array( 'reseller_code' => $long ) );
		$this->assertSame( 60, mb_strlen( $out['reseller_code'] ), 'Reseller code must be capped at 60 characters.' );
	}

	public function test_get_includes_new_defaults() {
		delete_option( Vaivatta_Settings::OPTION );
		$o = Vaivatta_Settings::get();
		$this->assertArrayHasKey( 'accent', $o );
		$this->assertArrayHasKey( 'greeting', $o );
		$this->assertArrayHasKey( 'reseller_code', $o );
		$this->assertSame( '', $o['accent'] );
		$this->assertSame( '', $o['greeting'] );
		$this->assertSame( '', $o['reseller_code'] );
	}
}
