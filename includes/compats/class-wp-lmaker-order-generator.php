<?php
/**
 * Created by PhpStorm.
 * User: manu
 * Date: 04/02/2019
 * Time: 20:23
 */


/**
 * Class WP_LMaker_Order_Generator
 */
class WP_LMaker_Order_Generator extends WP_LMaker_Abstract_Addon {
	public function excluded_tables( $tables ) {
		$tables[] = 'fakenames';
		return $tables;
	}
}

new WP_LMaker_Order_Generator();
