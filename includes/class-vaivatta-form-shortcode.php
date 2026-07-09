<?php
/**
 * [vaivatta_form] — inline lead/quote form embed (messenger mode=form).
 *
 * @package vaivatta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the messenger's inline lead form as a full-width iframe. The embedding page
 * supplies the heading around it; a submission creates a normal työ conversation.
 */
class Vaivatta_Form_Shortcode {

	/**
	 * Registers the shortcode.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'vaivatta_form', array( $this, 'render' ) );
	}

	/**
	 * Builds the mode=form iframe URL.
	 *
	 * @param array  $o           Plugin options.
	 * @param string $base        Messenger base URL.
	 * @param string $extra_label Optional extra-field label ('' = none).
	 * @param string $lang        Two-letter language.
	 * @return string
	 */
	public function form_src( array $o, string $base, string $extra_label, string $lang ): string {
		$url = trailingslashit( $base ) . 't/' . rawurlencode( $o['scope'] ) . '?lang=' . $lang . '&mode=form';

		$accent = $o['accent'] ?? '';
		if ( '' !== $accent ) {
			$url .= '&accent=' . rawurlencode( $accent );
		}
		if ( '' !== $extra_label ) {
			$url .= '&extra=' . rawurlencode( $extra_label );
		}
		return $url;
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
				'extra_label' => '',
				'lang'        => '',
				'height'      => 560,
			),
			$atts,
			'vaivatta_form'
		);

		$extra  = mb_substr( sanitize_text_field( (string) $a['extra_label'] ), 0, 120 );
		$lang   = in_array( $a['lang'], array( 'fi', 'en' ), true ) ? $a['lang'] : ( new Vaivatta_Embed() )->lang( $o );
		$height = max( 320, min( 1200, (int) $a['height'] ) );
		$base   = apply_filters( 'vaivatta_messenger_base', Vaivatta_Embed::DEFAULT_BASE );

		return sprintf(
			'<iframe title="%s" src="%s" style="width:100%%;height:%dpx;border:0;border-radius:16px" loading="lazy"></iframe>',
			esc_attr__( 'Contact form', 'vaivatta' ),
			esc_url( $this->form_src( $o, $base, $extra, $lang ) ),
			$height
		);
	}
}
