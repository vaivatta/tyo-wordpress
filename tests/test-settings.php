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
}
