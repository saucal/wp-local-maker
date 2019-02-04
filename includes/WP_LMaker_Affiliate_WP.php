<?php
/**
 * Created by PhpStorm.
 * User: manu
 * Date: 04/02/2019
 * Time: 20:22
 */

/**
 * Class WP_LMaker_Affiliate_WP
 */
class WP_LMaker_Affiliate_WP extends WP_LMaker_Addon {

	/**
	 * @param $tables
	 *
	 * @return array|mixed
	 */
	public function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'affiliate_wp_visits';
		return $tables;
	}
}

new WP_LMaker_Affiliate_WP();
