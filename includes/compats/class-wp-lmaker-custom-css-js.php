<?php
class WP_LMaker_Custom_CSS_JS extends WP_LMaker_Abstract_Addon {
	public function __construct() {
		parent::__construct();
		add_action( 'wp_local_maker_extra_compressed_paths', array( $this, 'custom_paths' ) );
	}

	public function custom_paths( $paths ) {
		$paths[] = Backup_Command::get_content_folder_path( 'uploads/custom-css-js' );
		return $paths;
	}
}
new WP_LMaker_Custom_CSS_JS();
