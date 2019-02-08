<?php
/**
 * Created by PhpStorm.
 * User: manu
 * Date: 04/02/2019
 * Time: 20:21
 */

/**
 * SCH Smart Transients Addon Compatibility.
 *
 * Class WP_LMaker_SCH_Smart_Transients
 */
class WP_LMaker_SCH_Smart_Transients extends WP_LMaker_Abstract_Addon {

	public function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'sch_smart_transients';
		return $tables;
	}
}

new WP_LMaker_SCH_Smart_Transients();
