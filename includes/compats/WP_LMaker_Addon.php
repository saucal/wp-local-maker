<?php

/**
 * Class WP_LMaker_Addon
 */
class WP_LMaker_Addon {

	public function __construct() {
		add_filter( 'wp_local_maker_excluded_tables', array( $this, 'excluded_tables' ) );
	}

	public function excluded_tables( $tables ) {
		return $tables;
	}

	protected function is_plugin_active( $plugin ) {
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		return is_plugin_active( $plugin );
	}
}
