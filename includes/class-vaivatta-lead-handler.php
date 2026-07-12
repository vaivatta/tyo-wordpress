<?php
/**
 * Lead connector — forwards a native form post to the työ platform as a lead.
 *
 * @package vaivatta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin-post `vaivatta_lead`: any site form (custom-themed or the
 * [vaivatta_lead_form] shortcode) posts here; the fields are sanitized and
 * forwarded server-side to POST {api_base}/leads with the connected scope.
 *
 * Deliberately NO nonce: the form is anonymous and lives on cacheable pages
 * (a cached nonce fails for every visitor after its lifetime). Abuse controls
 * are the vaivatta_hp honeypot here + rate limits on the platform.
 */
class Vaivatta_Lead_Handler {

	/**
	 * Registers the admin-post hooks (logged-in + visitors).
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_vaivatta_lead', array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_vaivatta_lead', array( $this, 'handle' ) );
	}

	/**
	 * Handles the form post: sanitize → forward → redirect back.
	 *
	 * @return void
	 */
	public function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- anonymous, cache-safe form; see class docblock.
		$back = $this->return_url();

		// Honeypot: pretend success, never forward.
		if ( ! empty( $_POST['vaivatta_hp'] ) ) {
			$this->do_redirect( add_query_arg( 'vaivatta_sent', '1', $back ) );
			return;
		}

		$opts  = Vaivatta_Settings::get();
		$scope = isset( $opts['scope'] ) ? (string) $opts['scope'] : '';
		$name  = $this->text_field( 'vaivatta_name', 200 );
		$phone = $this->text_field( 'vaivatta_phone', 60 );

		if ( '' === $scope || '' === $name || '' === $phone ) {
			$this->do_redirect( add_query_arg( 'vaivatta_sent', '0', $back ) );
			return;
		}

		$payload = array(
			'name'   => $name,
			'phone'  => $phone,
			'lang'   => $this->lang(),
			'source' => home_url(),
		);

		$email = $this->text_field( 'vaivatta_email', 254 );
		if ( '' !== $email ) {
			$payload['email'] = $email;
		}

		$message = isset( $_POST['vaivatta_message'] )
			? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['vaivatta_message'] ) ), 0, 4000 )
			: '';
		if ( '' !== $message ) {
			$payload['message'] = $message;
		}

		$extras = $this->extras();
		if ( array() !== $extras ) {
			$payload['extras'] = $extras;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$ok = $this->post_lead( $scope, $payload );
		$this->do_redirect( add_query_arg( 'vaivatta_sent', $ok ? '1' : '0', $back ) );
	}

	/**
	 * Reads, unslashes, sanitizes and truncates a single text field.
	 *
	 * @param string $key POST key.
	 * @param int    $max Max length.
	 * @return string
	 */
	private function text_field( string $key, int $max ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see class docblock.
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return mb_substr( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ), 0, $max );
	}

	/**
	 * Collects vaivatta_extra[Label]=value pairs (≤10, label ≤120, value ≤500).
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	private function extras(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see class docblock.
		$raw = isset( $_POST['vaivatta_extra'] ) && is_array( $_POST['vaivatta_extra'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each pair is sanitized in the loop below.
			? wp_unslash( $_POST['vaivatta_extra'] )
			: array();

		$out = array();
		foreach ( $raw as $label => $value ) {
			if ( count( $out ) >= 10 ) {
				break;
			}
			$label = mb_substr( sanitize_text_field( (string) $label ), 0, 120 );
			$value = mb_substr( sanitize_text_field( (string) $value ), 0, 500 );
			if ( '' !== $label && '' !== $value ) {
				$out[] = array(
					'label' => $label,
					'value' => $value,
				);
			}
		}
		return $out;
	}

	/**
	 * Resolves the lead language: posted vaivatta_lang, else the site locale.
	 *
	 * @return string 'fi' or 'en'.
	 */
	private function lang(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see class docblock.
		$posted = isset( $_POST['vaivatta_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['vaivatta_lang'] ) ) : '';
		if ( in_array( $posted, array( 'fi', 'en' ), true ) ) {
			return $posted;
		}
		return ( 0 === strpos( get_locale(), 'fi' ) ) ? 'fi' : 'en';
	}

	/**
	 * The same-site URL to send the visitor back to.
	 *
	 * @return string
	 */
	private function return_url(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see class docblock.
		$posted    = isset( $_POST['vaivatta_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['vaivatta_redirect'] ) ) : '';
		$validated = '' !== $posted ? wp_validate_redirect( $posted, '' ) : '';
		if ( '' !== $validated ) {
			return $validated;
		}
		$referer = wp_get_referer();
		return $referer ? $referer : home_url( '/' );
	}

	/**
	 * Forwards the lead to the platform. Extracted for test observation via
	 * the pre_http_request filter (same mechanism as Vaivatta_Connect tests).
	 *
	 * @param string $scope   Connected tenant scope.
	 * @param array  $payload Lead body.
	 * @return bool True on a 2xx platform response.
	 */
	protected function post_lead( string $scope, array $payload ): bool {
		$api_base = apply_filters( 'vaivatta_api_base', 'https://tyo.vaivatta.fi/api/v1' );

		$response = wp_remote_post(
			$api_base . '/leads',
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'x-tyo-tenant' => $scope,
					'Origin'       => home_url(),
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		return $status >= 200 && $status < 300;
	}

	/**
	 * Performs a safe redirect and exits. Overridden in tests.
	 *
	 * @param string $url Target URL.
	 * @return void
	 */
	protected function do_redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
