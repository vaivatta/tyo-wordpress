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

	public function test_iframe_src_appends_accent_and_greeting_when_set() {
		$e   = new Vaivatta_Embed();
		$src = $e->iframe_src(
			array(
				'scope'    => 'ten_x',
				'lang_mode' => 'fi',
				'accent'   => '#3b6ef8',
				'greeting' => 'Hello there!',
			),
			'https://msg.vaivatta.fi'
		);
		$this->assertStringContainsString( 'accent=' . rawurlencode( '#3b6ef8' ), $src );
		$this->assertStringContainsString( 'greeting=' . rawurlencode( 'Hello there!' ), $src );
	}

	public function test_iframe_src_omits_accent_and_greeting_when_empty() {
		$e   = new Vaivatta_Embed();
		$src = $e->iframe_src(
			array(
				'scope'    => 'ten_x',
				'lang_mode' => 'fi',
				'accent'   => '',
				'greeting' => '',
			),
			'https://msg.vaivatta.fi'
		);
		$this->assertStringNotContainsString( 'accent=', $src );
		$this->assertStringNotContainsString( 'greeting=', $src );
	}

	public function test_iframe_src_omits_accent_and_greeting_when_keys_absent() {
		$e   = new Vaivatta_Embed();
		$src = $e->iframe_src(
			array( 'scope' => 'ten_x', 'lang_mode' => 'en' ),
			'https://msg.vaivatta.fi'
		);
		$this->assertStringNotContainsString( 'accent=', $src );
		$this->assertStringNotContainsString( 'greeting=', $src );
	}

	public function test_widget_src_appends_launcher_mode_by_default() {
		$e = new Vaivatta_Embed();
		$o = array( 'scope' => 'ten_x', 'lang_mode' => 'fi', 'display_mode' => 'minimized' );
		$this->assertSame( 'https://msg.vaivatta.fi/t/ten_x?lang=fi&mode=launcher', $e->widget_src( $o, 'https://msg.vaivatta.fi' ) );
	}

	public function test_widget_src_open_mode_is_legacy_url() {
		$e = new Vaivatta_Embed();
		$o = array( 'scope' => 'ten_x', 'lang_mode' => 'fi', 'display_mode' => 'open' );
		$this->assertSame( 'https://msg.vaivatta.fi/t/ten_x?lang=fi', $e->widget_src( $o, 'https://msg.vaivatta.fi' ) );
	}

	public function test_base_origin() {
		$e = new Vaivatta_Embed();
		$this->assertSame( 'https://msg.vaivatta.fi', $e->base_origin( 'https://msg.vaivatta.fi/' ) );
		$this->assertSame( 'http://localhost:5173', $e->base_origin( 'http://localhost:5173/x' ) );
	}

	public function tear_down(): void {
		delete_option( Vaivatta_Settings::OPTION );
		wp_dequeue_style( 'vaivatta-widget' );
		wp_deregister_style( 'vaivatta-widget' );
		wp_dequeue_script( 'vaivatta-widget' );
		wp_deregister_script( 'vaivatta-widget' );
		parent::tear_down();
	}

	public function test_maybe_render_minimized_outputs_iframe_only() {
		update_option( Vaivatta_Settings::OPTION, array( 'scope' => 'ten_x' ) );
		$e = new Vaivatta_Embed();
		ob_start();
		$e->maybe_render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'mode=launcher', $html );
		$this->assertStringContainsString( 'id="vaivatta-widget"', $html );
		$this->assertStringContainsString( 'class="vv-closed"', $html );
		// CSS/JS go through the enqueue API (WP.org review), never raw tags in the footer.
		$this->assertStringNotContainsString( '<style', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_enqueue_minimized_adds_inline_style_and_script() {
		update_option( Vaivatta_Settings::OPTION, array( 'scope' => 'ten_x' ) );
		$e = new Vaivatta_Embed();
		$e->maybe_enqueue();

		$this->assertTrue( wp_style_is( 'vaivatta-widget', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'vaivatta-widget', 'enqueued' ) );

		$css = implode( "\n", (array) wp_styles()->get_data( 'vaivatta-widget', 'after' ) );
		$this->assertStringContainsString( '#vaivatta-widget.vv-closed', $css );
		$this->assertStringContainsString( '#vaivatta-widget.vv-open', $css );
		$this->assertStringContainsString( 'right:16px', $css );

		$js = implode( "\n", (array) wp_scripts()->get_data( 'vaivatta-widget', 'after' ) );
		$this->assertStringContainsString( '"https:\/\/chat.vaivatta.fi"', $js ); // json-encoded origin
		$this->assertStringContainsString( 'e.origin!==origin', $js );
		$this->assertStringContainsString( 'e.source!==f.contentWindow', $js );
		$this->assertStringContainsString( '"tyo:ui"!==d.type', $js );
	}

	public function test_enqueue_respects_position_option() {
		update_option( Vaivatta_Settings::OPTION, array( 'scope' => 'ten_x', 'position' => 'left' ) );
		( new Vaivatta_Embed() )->maybe_enqueue();
		$css = implode( "\n", (array) wp_styles()->get_data( 'vaivatta-widget', 'after' ) );
		$this->assertStringContainsString( 'left:16px', $css );
	}

	public function test_enqueue_skips_open_mode_and_missing_scope() {
		update_option( Vaivatta_Settings::OPTION, array( 'scope' => 'ten_x', 'display_mode' => 'open' ) );
		( new Vaivatta_Embed() )->maybe_enqueue();
		$this->assertFalse( wp_style_is( 'vaivatta-widget', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'vaivatta-widget', 'enqueued' ) );

		update_option( Vaivatta_Settings::OPTION, array( 'scope' => '' ) );
		( new Vaivatta_Embed() )->maybe_enqueue();
		$this->assertFalse( wp_style_is( 'vaivatta-widget', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'vaivatta-widget', 'enqueued' ) );
	}

	public function test_maybe_render_open_mode_matches_legacy_markup() {
		update_option( Vaivatta_Settings::OPTION, array( 'scope' => 'ten_x', 'display_mode' => 'open', 'lang_mode' => 'fi' ) );
		$e = new Vaivatta_Embed();
		ob_start();
		$e->maybe_render();
		$html = ob_get_clean();
		$expected = '<iframe title="Customer chat" src="https://chat.vaivatta.fi/t/ten_x?lang=fi" style="position:fixed;bottom:16px;right:16px;width:380px;max-width:92vw;height:600px;max-height:80vh;border:0;z-index:2147483000;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.16)" loading="lazy"></iframe>';
		$this->assertSame( $expected, $html );
		delete_option( Vaivatta_Settings::OPTION );
	}
}
