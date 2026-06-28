<?php
/**
 * PHPUnit bootstrap for vaivatta plugin tests.
 *
 * Note: this file intentionally omits the `if ( ! defined( 'ABSPATH' ) ) exit;`
 * guard because it is a *test-only* bootstrap that runs *before* WordPress (and
 * therefore ABSPATH) is loaded. It is excluded from the distribution ZIP via
 * .distignore and will never be shipped to end-users.
 *
 * @package vaivatta
 */

$vaivatta_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
require $vaivatta_tests_dir . '/includes/functions.php';
tests_add_filter( 'muplugins_loaded', function () { require dirname( __DIR__ ) . '/vaivatta.php'; } );
require $vaivatta_tests_dir . '/includes/bootstrap.php';
