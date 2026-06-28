<?php
/**
 * Plugin Name:       vaivatta — AI Front Desk
 * Plugin URI:        https://vaivatta.fi
 * Description:       Add an AI front desk to your site: every visitor message is drafted by AI and approved by a person before it's sent. EU-hosted. English &amp; Finnish.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            vaivatta
 * Author URI:        https://vaivatta.fi
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vaivatta
 * Domain Path:       /languages
 *
 * @package vaivatta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VAIVATTA_VERSION', '0.1.0' );
define( 'VAIVATTA_SLUG', 'vaivatta' );
define( 'VAIVATTA_PATH', plugin_dir_path( __FILE__ ) );
define( 'VAIVATTA_URL', plugin_dir_url( __FILE__ ) );

require_once VAIVATTA_PATH . 'includes/class-vaivatta-settings.php';
require_once VAIVATTA_PATH . 'includes/class-vaivatta-embed.php';
require_once VAIVATTA_PATH . 'includes/class-vaivatta-connect.php';

add_action(
	'init',
	function () {
		( new Vaivatta_Settings() )->register();
		( new Vaivatta_Embed() )->register();
		( new Vaivatta_Connect() )->register();
	}
);
