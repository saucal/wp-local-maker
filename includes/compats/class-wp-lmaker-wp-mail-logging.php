<?php
/**
 * WP Mail Logging Addon compatibility.
 *
 * Class WP_LMaker_WP_Mail_Logging
 */
class WP_LMaker_WP_Mail_Logging extends WP_LMaker_Abstract_Addon {

	public function excluded_tables( $tables ) {
		$tables[] = 'wpml_mails';
		return $tables;
	}
}

new WP_LMaker_WP_Mail_Logging();
