<?php
/**
 * Jetpack Addon compatibility.
 *
 * Class WP_LMaker_Jetpack
 */
class WP_LMaker_Jetpack extends WP_LMaker_Abstract_Addon {

	public function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_ignore_straight_post_types', array( $this, 'ignore_straight_post_types' ) );
	}

	public function ignore_straight_post_types( $types ) {
		$types[] = 'jp_sitemap_master';
		$types[] = 'jp_sitemap';
		$types[] = 'jp_sitemap_index';
		$types[] = 'jp_img_sitemap';
		$types[] = 'jp_img_sitemap_index';
		$types[] = 'jp_vid_sitemap';
		$types[] = 'jp_vid_sitemap_index';
		return $types;
	}
}

new WP_LMaker_Jetpack();

