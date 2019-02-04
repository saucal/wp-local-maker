<?php
/**
 * Created by PhpStorm.
 * User: manu
 * Date: 04/02/2019
 * Time: 20:05
 */

/**
 * Woocommerce Subscriptions Addon compatibility.
 *
 * Class WP_LMaker_WooCommerce_Subscriptions
 */
class WP_LMaker_WooCommerce_Subscriptions extends WP_LMaker_Addon {

	function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_ignore_straight_post_types', array( $this, 'ignore_straight_post_types' ) );
		add_action( 'wp_local_maker_orders_after_orders', array( $this, 'process_subscriptions' ) );
	}

	function process_subscriptions( $tables_info ) {
		global $wpdb;
		$current = $tables_info['posts']['currname'];
		$temp = $tables_info['posts']['tempname'];
		$curr_pm = $tables_info['postmeta']['currname'];

		// Handle subscriptions
		$wpdb->query(
			"REPLACE INTO {$temp}
            SELECT * FROM {$current} p
            WHERE p.post_status NOT IN ('auto-draft', 'trash')
            AND p.post_type IN ( 'shop_subscription' )
            ORDER BY p.post_date DESC
            LIMIT 50"
		);

		// Handle subscriptions related orders
		$wpdb->query(
			"REPLACE INTO {$temp}
            SELECT p.* FROM {$current} p
            INNER JOIN {$curr_pm} pm ON p.ID = pm.post_id AND ( pm.meta_key = '_subscription_switch' OR pm.meta_key = '_subscription_renewal' OR pm.meta_key = 'subscription_resubscribe' )
            WHERE pm.meta_value IN ( SELECT ID FROM {$temp} p2 WHERE p2.post_type = 'shop_subscription' )"
		);

		do_action( 'wp_local_maker_subscriptions_after_subscriptions', $tables_info );
	}

	function ignore_straight_post_types( $types ) {
		$types[] = 'shop_subscription';
		return $types;
	}
}

new WP_LMaker_WooCommerce_Subscriptions();

