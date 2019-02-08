<?php

/**
 * Class to init all compatible plugins.
 *
 * Class WP_LMaker_Init_Compats
 */
class WP_LMaker_Init_Compats {

	/**
	 * Init_Compats constructor.
	 */
	public function __construct() {

		// Addon Abstract Class
		require_once 'WP_LMaker_Abstract_Addon.php';

		$compats = glob( __DIR__ . '/compats/WP_LMaker*.php' );
		foreach ( $compats as $compat ) {
			require_once $compat;
		}
	}

}

new WP_LMaker_Init_Compats();
