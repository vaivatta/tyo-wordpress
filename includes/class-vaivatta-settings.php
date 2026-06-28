<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Vaivatta_Settings {
	const OPTION = 'vaivatta_options';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
	}

	public static function get() : array {
		$o = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $o ) ? $o : array(), array(
			'scope' => '', 'position' => 'right', 'lang_mode' => 'auto', 'show_on' => 'all',
		) );
	}

	public function sanitize( $input ) : array {
		$input    = is_array( $input ) ? $input : array();
		$scope    = isset( $input['scope'] ) ? trim( (string) $input['scope'] ) : '';
		$scope    = preg_match( '/^ten_[A-Za-z0-9_-]+$/', $scope ) ? $scope : '';
		$position = ( isset( $input['position'] ) && 'left' === $input['position'] ) ? 'left' : 'right';
		$lang     = in_array( $input['lang_mode'] ?? '', array( 'fi', 'en' ), true ) ? $input['lang_mode'] : 'auto';
		$show     = ( isset( $input['show_on'] ) && 'none' === $input['show_on'] ) ? 'none' : 'all';
		return array(
			'scope'     => $scope,
			'position'  => $position,
			'lang_mode' => $lang,
			'show_on'   => $show,
		);
	}

	public function settings() {
		register_setting( 'vaivatta', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function menu() {
		add_options_page( 'vaivatta', 'vaivatta', 'manage_options', 'vaivatta', array( $this, 'render' ) );
	}

	public function render() { /* Task 1.3 */ }
}
