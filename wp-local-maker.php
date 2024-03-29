<?php
/**
 * Plugin Name: WP Local Maker
 * Plugin URI: https://www.saucal.com
 * Description: WP CLI Exports with reduced datasets
 * Version: 1.0.1
 * Author: SAU/CAL
 * Author URI: https://www.saucal.com
 * GitHub Plugin URI: saucal/wp-local-maker
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}


require_once __DIR__ . '/includes/class-wp-lmaker-cli-command-base.php';
require_once __DIR__ . '/includes/class-backup-command.php';


