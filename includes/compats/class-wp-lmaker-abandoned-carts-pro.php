<?php
/**
 * Created by PhpStorm.
 * User: manu
 * Date: 04/02/2019
 * Time: 20:22
 */

/**
 * Class WP_LMaker_Abandoned_Carts_Pro
 */
class WP_LMaker_Abandoned_Carts_Pro extends WP_LMaker_Abstract_Addon {
	public function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'ac_abandoned_cart_history';
		$tables[] = 'ac_guest_abandoned_cart_history';
		return $tables;
	}
}

new WP_LMaker_Abandoned_Carts_Pro();
