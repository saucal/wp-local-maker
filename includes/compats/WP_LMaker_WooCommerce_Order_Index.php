<?php
/**
 * Created by PhpStorm.
 * User: manu
 * Date: 04/02/2019
 * Time: 20:27
 */

/**
 * Class WP_LMaker_WooCommerce_Order_Index
 */
class WP_LMaker_WooCommerce_Order_Index extends WP_LMaker_Addon {

	public function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_customer_order_index' ), 45 );
	}

	public function enqueue_process_customer_order_index( $tables ) {
		global $wpdb;
		$tables['woocommerce_customer_order_index'] = array( $this, 'process_customer_order_index' );
		return $tables;
	}

	public function process_customer_order_index() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single( 'woocommerce_customer_order_index', 'posts', 'order_id', 'ID' );
	}
}

new WP_LMaker_WooCommerce_Order_Index();

