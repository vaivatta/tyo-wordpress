<?php
class Test_Form_Shortcode extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		update_option( Vaivatta_Settings::OPTION, array( 'scope' => 'ten_x', 'lang_mode' => 'fi' ) );
	}

	public function tear_down(): void {
		delete_option( Vaivatta_Settings::OPTION );
		parent::tear_down();
	}

	public function test_form_src_builds_mode_form_with_extra() {
		$s   = new Vaivatta_Form_Shortcode();
		$o   = array( 'scope' => 'ten_x', 'accent' => '#112233' );
		$src = $s->form_src( $o, 'https://msg.vaivatta.fi', 'Rekisterinumero', 'fi' );
		$this->assertSame( 'https://msg.vaivatta.fi/t/ten_x?lang=fi&mode=form&accent=%23112233&extra=Rekisterinumero', $src );
	}

	public function test_shortcode_renders_iframe() {
		$html = do_shortcode( '[vaivatta_form extra_label="Rekisterinumero"]' );
		$this->assertStringContainsString( '<iframe', $html );
		$this->assertStringContainsString( 'mode=form', $html );
		$this->assertStringContainsString( 'extra=Rekisterinumero', $html );
		$this->assertStringContainsString( 'height:560px', $html );
	}

	public function test_shortcode_lang_override_and_height_clamp() {
		$html = do_shortcode( '[vaivatta_form lang="en" height="9999"]' );
		$this->assertStringContainsString( 'lang=en', $html );
		$this->assertStringContainsString( 'height:1200px', $html );
		$html = do_shortcode( '[vaivatta_form height="10"]' );
		$this->assertStringContainsString( 'height:320px', $html );
	}

	public function test_shortcode_empty_without_scope() {
		update_option( Vaivatta_Settings::OPTION, array( 'scope' => '' ) );
		$this->assertSame( '', do_shortcode( '[vaivatta_form]' ) );
	}
}
