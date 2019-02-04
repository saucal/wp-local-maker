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


require_once __DIR__ . '/includes/Backup_Command.php';
require_once __DIR__ . '/includes/WP_LMaker_Core.php';



