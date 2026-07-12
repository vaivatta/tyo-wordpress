<?php
/**
 * [vaivatta_lead_form] — native HTML lead form (no iframe), posts to the
 * vaivatta_lead connector. The theme styles it via the vv-lead-* classes.
 *
 * @package vaivatta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a plain, theme-styleable lead form wired to Vaivatta_Lead_Handler.
 */
class Vaivatta_Lead_Form_Shortcode {

	/**
	 * Registers the shortcode.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'vaivatta_lead_form', array( $this, 'render' ) );
	}

	/**
	 * Bilingual default labels, keyed by resolved language.
	 *
	 * @param string $lang 'fi' or 'en'.
	 * @return array<string, string>
	 */
	private function labels( string $lang ): array {
		if ( 'fi' === $lang ) {
			return array(
				'name'    => __( 'Nimi', 'vaivatta' ),
				'phone'   => __( 'Puhelin', 'vaivatta' ),
				'email'   => __( 'Sähköposti', 'vaivatta' ),
				'message' => __( 'Viesti', 'vaivatta' ),
				'submit'  => __( 'Lähetä yhteydenottopyyntö', 'vaivatta' ),
				'success' => __( 'Kiitos! Viesti lähetetty — olemme yhteydessä pian.', 'vaivatta' ),
				'error'   => __( 'Lähetys epäonnistui. Yritä uudelleen tai soita meille.', 'vaivatta' ),
			);
		}
		return array(
			'name'    => __( 'Name', 'vaivatta' ),
			'phone'   => __( 'Phone', 'vaivatta' ),
			'email'   => __( 'Email', 'vaivatta' ),
			'message' => __( 'Message', 'vaivatta' ),
			'submit'  => __( 'Send request', 'vaivatta' ),
			'success' => __( 'Thanks! Message sent — we will be in touch soon.', 'vaivatta' ),
			'error'   => __( 'Sending failed. Please try again or give us a call.', 'vaivatta' ),
		);
	}

	/**
	 * Shortcode handler.
	 *
	 * @param mixed $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ): string {
		$o = Vaivatta_Settings::get();
		if ( empty( $o['scope'] ) ) {
			return '';
		}

		$a = shortcode_atts(
			array(
				'extra_label'  => '',
				'lang'         => '',
				'show_message' => '1',
				'redirect'     => '',
			),
			$atts,
			'vaivatta_lead_form'
		);

		$lang = in_array( $a['lang'], array( 'fi', 'en' ), true )
			? $a['lang']
			: ( new Vaivatta_Embed() )->lang( $o );
		$l    = $this->labels( $lang );

		$extra_label = mb_substr( sanitize_text_field( (string) $a['extra_label'] ), 0, 120 );
		$redirect    = '' !== $a['redirect']
			? esc_url_raw( (string) $a['redirect'] )
			: get_permalink() . '#vaivatta-form';

		$notice = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only banner keyed on the connector's redirect.
		if ( isset( $_GET['vaivatta_sent'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sent   = '1' === sanitize_text_field( wp_unslash( $_GET['vaivatta_sent'] ) );
			$notice = sprintf(
				'<p class="%s">%s</p>',
				$sent ? 'vv-lead-success' : 'vv-lead-error',
				esc_html( $sent ? $l['success'] : $l['error'] )
			);
		}

		$extra_field = '';
		if ( '' !== $extra_label ) {
			$extra_field = sprintf(
				'<label class="vv-lead-field"><span>%1$s</span><input type="text" name="vaivatta_extra[%2$s]"></label>',
				esc_html( $extra_label ),
				esc_attr( $extra_label )
			);
		}

		$message_field = '';
		if ( '0' !== $a['show_message'] ) {
			$message_field = sprintf(
				'<label class="vv-lead-field"><span>%s</span><textarea name="vaivatta_message" rows="4"></textarea></label>',
				esc_html( $l['message'] )
			);
		}

		return sprintf(
			'<form id="vaivatta-form" class="vv-lead-form" action="%1$s" method="post">%2$s' .
			'<input type="hidden" name="action" value="vaivatta_lead">' .
			'<input type="hidden" name="vaivatta_lang" value="%3$s">' .
			'<input type="hidden" name="vaivatta_redirect" value="%4$s">' .
			'<p class="vv-lead-hp" aria-hidden="true" style="position:absolute !important;left:-9999px;height:1px;width:1px;overflow:hidden"><label>Website<input type="text" name="vaivatta_hp" tabindex="-1" autocomplete="off"></label></p>' .
			'<label class="vv-lead-field"><span>%5$s</span><input type="text" name="vaivatta_name" required></label>' .
			'<label class="vv-lead-field"><span>%6$s</span><input type="tel" name="vaivatta_phone" required></label>' .
			'<label class="vv-lead-field"><span>%7$s</span><input type="email" name="vaivatta_email"></label>' .
			'%8$s%9$s' .
			'<button type="submit" class="vv-lead-submit">%10$s</button>' .
			'</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$notice, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
			esc_attr( $lang ),
			esc_attr( $redirect ),
			esc_html( $l['name'] ),
			esc_html( $l['phone'] ),
			esc_html( $l['email'] ),
			$extra_field, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
			$message_field, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
			esc_html( $l['submit'] )
		);
	}
}
