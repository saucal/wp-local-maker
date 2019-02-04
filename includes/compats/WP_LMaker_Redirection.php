<?php
/**
 * Created by PhpStorm.
 * User: manu
 * Date: 04/02/2019
 * Time: 20:19
 */


/**
 * Redirections compatibility.
 *
 * Class WP_LMaker_Redirection
 */
class WP_LMaker_Redirection extends WP_LMaker_Addon {

	public function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'redirection_logs';
		$tables[] = 'redirection_404';
		return $tables;
	}
}

new WP_LMaker_Redirection();

