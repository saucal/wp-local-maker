<?php

/**
 * Woocommerce Addon compatibility.
 *
 * Class WP_LMaker_WooCommerce
 */
class WP_LMaker_WooCommerce extends WP_LMaker_Abstract_Addon {

	public function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_order_items' ), 25 );
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_download_permissions' ), 27 );
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_payment_tokens' ), 35 );
		add_action( 'wp_local_maker_users_after_authors', array( $this, 'process_customers' ) );
		add_filter( 'wp_local_maker_ignore_straight_post_types', array( $this, 'ignore_straight_post_types' ) );
		add_action( 'wp_local_maker_posts_after_posts', array( $this, 'process_orders' ) );
		add_action( 'wp_local_maker_posts_after_posts', array( $this, 'process_coupons' ) );
		add_action( 'wp_local_maker_posts_after_posts', array( $this, 'process_products' ) );
	}

	public function ignore_straight_post_types( $types ) {
		$types[] = 'shop_order';
		$types[] = 'shop_order_refund';
		$types[] = 'shop_coupon';
		$types[] = 'product';
		return $types;
	}

	public function enqueue_process_order_items( $tables ) {
		$tables['woocommerce_order_items']    = array( $this, 'process_woocommerce_order_items' );
		$tables['woocommerce_order_itemmeta'] = array( $this, 'process_woocommerce_order_itemmeta' );
		return $tables;
	}

	public function enqueue_process_download_permissions( $tables ) {
		$tables['woocommerce_downloadable_product_permissions'] = array( $this, 'process_woocommerce_downloadable_product_permissions' );
		return $tables;
	}

	public function enqueue_process_payment_tokens( $tables ) {
		$tables['woocommerce_payment_tokens']    = array( $this, 'process_woocommerce_payment_tokens' );
		$tables['woocommerce_payment_tokenmeta'] = array( $this, 'process_woocommerce_payment_tokenmeta' );
		return $tables;
	}

	public function process_woocommerce_order_items() {
		return Backup_Command::dependant_table_dump_single( 'woocommerce_order_items', 'posts', 'order_id', 'ID' );
	}

	public function process_woocommerce_order_itemmeta() {
		return Backup_Command::dependant_table_dump_single( 'woocommerce_order_itemmeta', 'woocommerce_order_items', 'order_item_id', 'order_item_id' );
	}

	public function process_woocommerce_downloadable_product_permissions() {
		return Backup_Command::dependant_table_dump_single( 'woocommerce_downloadable_product_permissions', 'posts', 'order_id', 'ID' );
	}

	public function process_woocommerce_payment_tokens() {
		return Backup_Command::dependant_table_dump_single( 'woocommerce_payment_tokens', 'users', 'user_id', 'ID' );
	}

	public function process_woocommerce_payment_tokenmeta() {
		return Backup_Command::dependant_table_dump_single( 'woocommerce_payment_tokenmeta', 'woocommerce_payment_tokens', 'payment_token_id', 'token_id' );
	}

	public function process_orders( $tables_info ) {
		global $wpdb;
		$current = $tables_info['posts']['currname'];
		$temp    = $tables_info['posts']['tempname'];

		$limit = Backup_Command::get_limit_for_tag( 'orders', 50 );

		// Handle orders
		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT * FROM {$current} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'shop_order' )
			ORDER BY p.post_date DESC
			LIMIT {$limit}"
		);

		do_action( 'wp_local_maker_orders_after_orders', $tables_info );

		// Handle refunds
		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT * FROM {$current} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'shop_order_refund' ) AND p.post_parent IN ( SELECT ID FROM {$temp} p2 )"
		);
	}

	public function process_coupons( $tables_info ) {
		global $wpdb;
		$current = $tables_info['posts']['currname'];
		$temp    = $tables_info['posts']['tempname'];
		$curr_oi = $tables_info['woocommerce_order_items']['currname'];

		$table_exists = $wpdb->get_col( "SHOW TABLES LIKE '{$curr_oi}'" );

		if ( empty( $table_exists ) ) {
			return;
		}

		// Handle coupons (only copy used)
		$wpdb->query(
			"CREATE TEMPORARY TABLE wp_list_temp 
			SELECT oi.order_item_name FROM {$curr_oi} oi
			WHERE oi.order_id IN ( SELECT ID FROM {$temp} ) AND oi.order_item_type = 'coupon'
			GROUP BY oi.order_item_name"
		);

		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT * FROM {$current} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'shop_coupon' ) AND post_title IN ( SELECT * FROM wp_list_temp )"
		);

		$wpdb->query( 'DROP TABLE wp_list_temp' );
	}

	public function process_products( $tables_info ) {
		global $wpdb;
		$current = $tables_info['posts']['currname'];
		$temp    = $tables_info['posts']['tempname'];

		$curr_oi  = $tables_info['woocommerce_order_items']['currname'];
		$curr_oim = $tables_info['woocommerce_order_itemmeta']['currname'];

		$table_exists = $wpdb->get_col( "SHOW TABLES LIKE '{$curr_oi}'" );
		if ( count( $table_exists ) ) {
			// Handle products related to copied orders
			$wpdb->query(
				"CREATE TEMPORARY TABLE wp_related_products_temp 
				SELECT oim.meta_value as product_id FROM {$curr_oim} oim
				INNER JOIN {$curr_oi} oi ON oi.order_item_id = oim.order_item_id
				WHERE oi.order_id IN ( SELECT ID FROM {$temp} WHERE post_type = 'shop_order' ) 
				AND oim.meta_key = '_product_id'
				GROUP BY product_id"
			);

			$wpdb->query(
				"REPLACE INTO {$temp}
				SELECT * FROM {$current} p
				WHERE p.post_status NOT IN ('auto-draft', 'trash')
				AND p.post_type IN ( 'product' ) AND p.ID IN ( SELECT * FROM wp_related_products_temp )"
			);

			$wpdb->query( 'DROP TABLE wp_related_products_temp ' );
		}

		$limit = Backup_Command::get_limit_for_tag( 'products', 9999999999 );

		// Handle products
		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT * FROM {$current} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'product' )
			LIMIT {$limit}"
		);
	}

	public function process_customers( $tables_info ) {
		global $wpdb;
		$current       = $tables_info['users']['currname'];
		$temp          = $tables_info['users']['tempname'];
		$temp_posts    = $tables_info['posts']['tempname'];
		$temp_postmeta = $tables_info['postmeta']['tempname'];
		$user_keys     = Backup_Command::get_table_keys_group( $current, 'u' );

		// Export customers
		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT u.* FROM {$temp_posts} p 
			INNER JOIN {$temp_postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_customer_user'
			INNER JOIN {$current} u ON u.ID = pm.meta_value
			GROUP BY {$user_keys}"
		);
	}

	public function excluded_tables( $tables ) {
		$tables[] = 'wc_download_log';
		$tables[] = 'woocommerce_sessions';
		$tables[] = 'woocommerce_log';
		return $tables;
	}
}
new WP_LMaker_WooCommerce();
