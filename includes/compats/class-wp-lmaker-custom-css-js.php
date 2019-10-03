<?php
class WP_LMaker_Custom_CSS_JS extends WP_LMaker_Abstract_Addon {
	function __construct() {
		parent::__construct();
		add_action( 'wp_local_maker_extra_compressed_paths', array( $this, 'custom_paths' ) );
	}

	function custom_paths( $paths ) {
		$paths[] = 'wp-content/uploads/custom-css-js';
		return $paths;
	}
}
new WP_LMaker_Custom_CSS_JS();
