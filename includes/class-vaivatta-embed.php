<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Vaivatta_Embed {
	// One messenger deployment serves every tenant; the base is filterable for staging.
	const DEFAULT_BASE = 'https://messenger.vaivatta.fi';

	public function register() {
		add_action( 'wp_footer', array( $this, 'maybe_render' ) );
	}

	public function should_render( array $o ) : bool {
		return ! empty( $o['scope'] ) && 'none' !== ( $o['show_on'] ?? 'all' );
	}

	public function lang( array $o ) : string {
		$mode = $o['lang_mode'] ?? 'auto';
		if ( 'fi' === $mode || 'en' === $mode ) { return $mode; }
		return ( 0 === strpos( get_locale(), 'fi' ) ) ? 'fi' : 'en';   // map site locale
	}

	public function iframe_src( array $o, string $base ) : string {
		$scope = rawurlencode( $o['scope'] );
		return trailingslashit( $base ) . 't/' . $scope . '?lang=' . $this->lang( $o );
	}

	public function maybe_render() {
		$o = Vaivatta_Settings::get();
		if ( ! $this->should_render( $o ) ) { return; }
		$base = apply_filters( 'vaivatta_messenger_base', self::DEFAULT_BASE );
		$src  = $this->iframe_src( $o, $base );
		$side = 'left' === ( $o['position'] ?? 'right' ) ? 'left:16px' : 'right:16px';
		printf(
			'<iframe title="%s" src="%s" style="position:fixed;bottom:16px;%s;width:380px;max-width:92vw;height:600px;max-height:80vh;border:0;z-index:2147483000;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.16)" loading="lazy"></iframe>',
			esc_attr__( 'Customer chat', 'vaivatta' ),
			esc_url( $src ),
			esc_attr( $side )
		);
	}
}
