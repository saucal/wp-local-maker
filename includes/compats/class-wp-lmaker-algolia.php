<?php
/**
 * Algolia Addon compatibility.
 *
 * Class WP_LMaker_Algolia
 */
class WP_LMaker_Algolia extends WP_LMaker_Abstract_Addon {

	public function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_ignore_straight_post_types', array( $this, 'ignore_straight_post_types' ) );
	}

	public function ignore_straight_post_types( $types ) {
		$types[] = 'algolia_log';
		return $types;
	}
}

new WP_LMaker_Algolia();

