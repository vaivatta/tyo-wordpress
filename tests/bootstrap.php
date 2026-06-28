<?php
$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
require $_tests_dir . '/includes/functions.php';
tests_add_filter( 'muplugins_loaded', function () { require dirname( __DIR__ ) . '/vaivatta.php'; } );
require $_tests_dir . '/includes/bootstrap.php';
