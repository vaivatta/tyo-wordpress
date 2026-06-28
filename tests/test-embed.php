<?php
class Test_Embed extends WP_UnitTestCase {
	public function test_no_render_without_scope() {
		$e = new Vaivatta_Embed();
		$this->assertFalse( $e->should_render( array( 'scope' => '', 'show_on' => 'all' ) ) );
		$this->assertFalse( $e->should_render( array( 'scope' => 'ten_x', 'show_on' => 'none' ) ) );
		$this->assertTrue(  $e->should_render( array( 'scope' => 'ten_x', 'show_on' => 'all' ) ) );
	}

	public function test_iframe_src_builds_scope_and_lang() {
		$e   = new Vaivatta_Embed();
		$src = $e->iframe_src( array( 'scope' => 'ten_x', 'lang_mode' => 'fi' ), 'https://msg.vaivatta.fi' );
		$this->assertSame( 'https://msg.vaivatta.fi/t/ten_x?lang=fi', $src );
	}

	public function test_iframe_src_escapes_scope() {
		$e   = new Vaivatta_Embed();
		$src = $e->iframe_src( array( 'scope' => 'ten_a/b', 'lang_mode' => 'auto' ), 'https://msg.vaivatta.fi' );
		$this->assertStringNotContainsString( '/b?', $src );   // scope path-segment-encoded, no extra path
	}
}
