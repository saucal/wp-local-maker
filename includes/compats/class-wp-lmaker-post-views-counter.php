<?php
/**
 * Class WP_LMaker_Post_Views_Counter
 */
class WP_LMaker_Post_Views_Counter extends WP_LMaker_Abstract_Addon {
	public function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'post_views';
		return $tables;
	}
}

new WP_LMaker_Post_Views_Counter();
