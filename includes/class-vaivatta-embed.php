<?php
/**
 * Front-end messenger iframe embed for vaivatta.
 *
 * @package vaivatta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects the vaivatta messenger iframe into the site footer.
 */
class Vaivatta_Embed {
	/**
	 * One messenger deployment serves every tenant; the base is filterable for staging.
	 *
	 * @var string
	 */
	const DEFAULT_BASE = 'https://messenger.vaivatta.fi';

	/**
	 * Registers the wp_footer action to inject the iframe.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_footer', array( $this, 'maybe_render' ) );
	}

	/**
	 * Returns true when the widget should be rendered on the current page.
	 *
	 * @param array $o Plugin options.
	 * @return bool
	 */
	public function should_render( array $o ): bool {
		return ! empty( $o['scope'] ) && 'none' !== ( $o['show_on'] ?? 'all' );
	}

	/**
	 * Resolves the two-letter language code to use for the embed.
	 *
	 * @param array $o Plugin options.
	 * @return string
	 */
	public function lang( array $o ): string {
		$mode = $o['lang_mode'] ?? 'auto';
		if ( 'fi' === $mode || 'en' === $mode ) {
			return $mode;
		}
		// Map site locale to fi or en.
		return ( 0 === strpos( get_locale(), 'fi' ) ) ? 'fi' : 'en';
	}

	/**
	 * Builds the full iframe src URL for the given options and base URL.
	 *
	 * @param array  $o    Plugin options.
	 * @param string $base Messenger base URL.
	 * @return string
	 */
	public function iframe_src( array $o, string $base ): string {
		$scope = rawurlencode( $o['scope'] );
		return trailingslashit( $base ) . 't/' . $scope . '?lang=' . $this->lang( $o );
	}

	/**
	 * Outputs the messenger iframe if the widget should be shown.
	 *
	 * @return void
	 */
	public function maybe_render() {
		$o = Vaivatta_Settings::get();
		if ( ! $this->should_render( $o ) ) {
			return;
		}
		$base = apply_filters( 'vaivatta_messenger_base', self::DEFAULT_BASE );
		$src  = $this->iframe_src( $o, $base );
		$side = 'left' === ( $o['position'] ?? 'right' ) ? 'left:16px' : 'right:16px';
		printf( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format string is a literal; all interpolated args are individually escaped (esc_attr__, esc_url, esc_attr).
			'<iframe title="%s" src="%s" style="position:fixed;bottom:16px;%s;width:380px;max-width:92vw;height:600px;max-height:80vh;border:0;z-index:2147483000;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.16)" loading="lazy"></iframe>',
			esc_attr__( 'Customer chat', 'vaivatta' ),
			esc_url( $src ),
			esc_attr( $side )
		);
	}
}
