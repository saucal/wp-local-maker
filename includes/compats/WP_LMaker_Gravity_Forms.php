<?php
/**
 * Created by PhpStorm.
 * User: manu
 * Date: 04/02/2019
 * Time: 20:18
 */

/**
 * Gravity forms compatibility.
 *
 * Class WP_LMaker_Gravity_Forms
 */
class WP_LMaker_Gravity_Forms extends WP_LMaker_Abstract_Addon {

	public function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'gf_entry';
		$tables[] = 'gf_entry_meta';
		$tables[] = 'gf_entry_notes';
		$tables[] = 'gf_form_view';
		$tables[] = 'rg_lead';
		$tables[] = 'rg_lead_detail';
		$tables[] = 'rg_lead_detail_long';
		$tables[] = 'rg_lead_meta';
		$tables[] = 'rg_lead_notes';
		$tables[] = 'rg_form_view';
		$tables[] = 'rg_incomplete_submissions';
		return $tables;
	}
}

new WP_LMaker_Gravity_Forms();
