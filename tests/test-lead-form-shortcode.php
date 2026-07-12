<?php
/**
 * Tests for Vaivatta_Lead_Form_Shortcode.
 *
 * @package vaivatta
 */

/**
 * Test_Lead_Form_Shortcode class.
 */
class Test_Lead_Form_Shortcode extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		update_option( Vaivatta_Settings::OPTION, array( 'scope' => 'acme' ) );
		( new Vaivatta_Lead_Form_Shortcode() )->register();
	}

	public function tear_down() {
		delete_option( Vaivatta_Settings::OPTION );
		remove_shortcode( 'vaivatta_lead_form' );
		unset( $_GET['vaivatta_sent'] );
		parent::tear_down();
	}

	public function test_renders_native_form_with_required_fields() {
		$html = do_shortcode( '[vaivatta_lead_form lang="en"]' );
		$this->assertStringContainsString( 'class="vv-lead-form"', $html );
		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html );
		$this->assertStringContainsString( 'name="action" value="vaivatta_lead"', $html );
		$this->assertStringContainsString( 'name="vaivatta_name"', $html );
		$this->assertStringContainsString( 'name="vaivatta_phone"', $html );
		$this->assertStringContainsString( 'name="vaivatta_message"', $html );
		$this->assertStringContainsString( 'name="vaivatta_hp"', $html );
		$this->assertStringContainsString( 'name="vaivatta_lang" value="en"', $html );
		// No iframe — this is the native form surface.
		$this->assertStringNotContainsString( '<iframe', $html );
	}

	public function test_extra_label_adds_extra_field() {
		$html = do_shortcode( '[vaivatta_lead_form extra_label="Rekisterinumero"]' );
		$this->assertStringContainsString( 'name="vaivatta_extra[Rekisterinumero]"', $html );
	}

	public function test_show_message_0_omits_textarea() {
		$html = do_shortcode( '[vaivatta_lead_form show_message="0"]' );
		$this->assertStringNotContainsString( '<textarea', $html );
	}

	public function test_success_notice_when_sent() {
		$_GET['vaivatta_sent'] = '1';
		$html                  = do_shortcode( '[vaivatta_lead_form lang="fi"]' );
		$this->assertStringContainsString( 'vv-lead-success', $html );
	}

	public function test_empty_without_scope() {
		update_option( Vaivatta_Settings::OPTION, array( 'scope' => '' ) );
		$this->assertSame( '', do_shortcode( '[vaivatta_lead_form]' ) );
	}
}
