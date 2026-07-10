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
	const DEFAULT_BASE = 'https://chat.vaivatta.fi';

	/**
	 * Registers the wp_footer action to inject the iframe and the enqueue hook for
	 * the launcher's CSS/JS (inline assets must ride the enqueue API, not raw tags).
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
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
		$url   = trailingslashit( $base ) . 't/' . $scope . '?lang=' . $this->lang( $o );

		$accent = $o['accent'] ?? '';
		if ( '' !== $accent ) {
			$url .= '&accent=' . rawurlencode( $accent );
		}

		$greeting = $o['greeting'] ?? '';
		if ( '' !== $greeting ) {
			$url .= '&greeting=' . rawurlencode( $greeting );
		}

		return $url;
	}

	/**
	 * Full widget iframe URL: iframe_src plus the launcher mode unless legacy always-open.
	 *
	 * @param array  $o    Plugin options.
	 * @param string $base Messenger base URL.
	 * @return string
	 */
	public function widget_src( array $o, string $base ): string {
		$src = $this->iframe_src( $o, $base );
		if ( 'open' !== ( $o['display_mode'] ?? 'minimized' ) ) {
			$src .= '&mode=launcher';
		}
		return $src;
	}

	/**
	 * The origin (scheme://host[:port]) of the messenger base — the ONLY origin whose
	 * postMessages the resize script accepts.
	 *
	 * @param string $base Messenger base URL.
	 * @return string
	 */
	public function base_origin( string $base ): string {
		$p = wp_parse_url( $base );
		return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
	}

	/**
	 * Enqueues the launcher's inline CSS/JS when the minimized widget will render.
	 *
	 * The style positions/sizes the bubble iframe and its open state; the script resizes
	 * the iframe when the messenger posts {type:"tyo:ui", state}. Messages are honored
	 * ONLY from the messenger origin AND this iframe's own contentWindow.
	 *
	 * @return void
	 */
	public function maybe_enqueue() {
		$o = Vaivatta_Settings::get();
		if ( ! $this->should_render( $o ) || 'open' === ( $o['display_mode'] ?? 'minimized' ) ) {
			return;
		}
		$base = apply_filters( 'vaivatta_messenger_base', self::DEFAULT_BASE );
		$side = 'left' === ( $o['position'] ?? 'right' ) ? 'left' : 'right';

		$css = '#vaivatta-widget{position:fixed;bottom:16px;' . $side . ':16px;border:0;z-index:2147483000;transition:width .2s ease,height .2s ease}#vaivatta-widget.vv-closed{width:76px;height:76px;border-radius:50%}#vaivatta-widget.vv-open{width:380px;max-width:92vw;height:600px;max-height:80vh;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.16)}';
		$js  = '(function(){var f=document.getElementById("vaivatta-widget");if(!f)return;var origin=' . wp_json_encode( $this->base_origin( $base ) ) . ';window.addEventListener("message",function(e){if(e.origin!==origin||!f.contentWindow||e.source!==f.contentWindow){return;}var d=e.data;if(!d||"tyo:ui"!==d.type){return;}f.className="open"===d.state?"vv-open":"vv-closed";});})();';

		wp_register_style( 'vaivatta-widget', false, array(), VAIVATTA_VERSION );
		wp_enqueue_style( 'vaivatta-widget' );
		wp_add_inline_style( 'vaivatta-widget', $css );

		wp_register_script( 'vaivatta-widget', false, array(), VAIVATTA_VERSION, true );
		wp_enqueue_script( 'vaivatta-widget' );
		wp_add_inline_script( 'vaivatta-widget', $js );
	}

	/**
	 * Outputs the messenger iframe if the widget should be shown.
	 *
	 * Display_mode 'open'      → the legacy always-open iframe, byte-identical to pre-0.2.0.
	 * display_mode 'minimized' → a bubble-sized iframe in mode=launcher; its CSS/JS ride
	 *                            the enqueue API (see maybe_enqueue()).
	 *
	 * @return void
	 */
	public function maybe_render() {
		$o = Vaivatta_Settings::get();
		if ( ! $this->should_render( $o ) ) {
			return;
		}
		$base = apply_filters( 'vaivatta_messenger_base', self::DEFAULT_BASE );
		$side = 'left' === ( $o['position'] ?? 'right' ) ? 'left' : 'right';

		if ( 'open' === ( $o['display_mode'] ?? 'minimized' ) ) {
			printf( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format string is a literal; all interpolated args are individually escaped (esc_attr__, esc_url, esc_attr).
				'<iframe title="%s" src="%s" style="position:fixed;bottom:16px;%s;width:380px;max-width:92vw;height:600px;max-height:80vh;border:0;z-index:2147483000;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.16)" loading="lazy"></iframe>',
				esc_attr__( 'Customer chat', 'vaivatta' ),
				esc_url( $this->iframe_src( $o, $base ) ),
				esc_attr( $side . ':16px' )
			);
			return;
		}

		printf( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format string is a literal; all interpolated args are individually escaped (esc_attr__, esc_url).
			'<iframe id="vaivatta-widget" class="vv-closed" title="%1$s" src="%2$s" style="background:transparent" loading="lazy" allowtransparency="true"></iframe>',
			esc_attr__( 'Customer chat', 'vaivatta' ),
			esc_url( $this->widget_src( $o, $base ) )
		);
	}
}
