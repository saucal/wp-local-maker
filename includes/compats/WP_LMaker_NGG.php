<?php
/**
 * Created by PhpStorm.
 * User: manu
 * Date: 04/02/2019
 * Time: 20:25
 */

/**
 * Class WP_LMaker_NGG
 */
class WP_LMaker_NGG extends WP_LMaker_Abstract_Addon {

	public function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_ngg' ), 45 );
		add_filter( 'wp_local_maker_ignore_straight_post_types', array( $this, 'ignore_straight_post_types' ) );
	}

	public function enqueue_process_ngg( $tables ) {
		global $wpdb;
		if ( ! $this->is_plugin_active( 'nextgen-gallery/nggallery.php' ) ) {
			$tables['ngg_album'] = false;
			$tables['ngg_gallery'] = false;
			$tables['ngg_pictures'] = false;
		}
		return $tables;
	}

	public function ignore_straight_post_types( $types ) {
		if ( ! $this->is_plugin_active( 'nextgen-gallery/nggallery.php' ) ) {
			$types[] = 'ngg_pictures';
		}
		return $types;
	}
}

new WP_LMaker_NGG();

