<?php

/**
 * Class to init all compatible plugins.
 *
 * Class Init_Compats
 */
class Init_Compats {

	/**
	 * Init_Compats constructor.
	 */
	public function __construct() {

		// Addon Base Class
		require_once 'WP_LMaker_Addon.php';

		$compats = glob( __DIR__ . '/WP_LMaker*.php' );
		foreach ( $compats as $compat ) {
			require_once $compat;
		}
	}

}

new Init_Compats();
