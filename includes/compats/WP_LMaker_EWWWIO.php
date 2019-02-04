<?php
/**
 * Created by PhpStorm.
 * User: manu
 * Date: 04/02/2019
 * Time: 20:24
 */

/**
 * Class WP_LMaker_EWWWIO
 */
class WP_LMaker_EWWWIO extends WP_LMaker_Addon {

	public function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_ewwio' ), 45 );
	}

	public function enqueue_process_ewwio( $tables ) {
		global $wpdb;
		$tables['ewwwio_images'] = array( $this, 'process_ewwwio_images' );
		return $tables;
	}

	public function process_ewwwio_images() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single( 'ewwwio_images', 'posts', 'id', 'ID' );
	}
}

new WP_LMaker_EWWWIO();
