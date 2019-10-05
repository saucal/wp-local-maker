<?php
/**
 * Plugin Name: WP Local Maker
 * Plugin URI: https://www.saucal.com
 * Description: WP CLI Exports with reduced datasets
 * Version: 1.0.0
 * Author: SAU/CAL
 * Author URI: https://www.saucal.com
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}


require_once __DIR__ . '/includes/class-backup-command.php';
require_once __DIR__ . '/includes/class-wp-lmaker-dir-crawler.php';
require_once __DIR__ . '/includes/class-wp-lmaker-dir-filter.php';
require_once __DIR__ . '/includes/class-wp-lmaker-core.php';
require_once __DIR__ . '/includes/class-wp-lmaker-init-compats.php';


