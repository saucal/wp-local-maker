<?php
/**
 * Created by PhpStorm.
 * User: manu
 * Date: 04/02/2019
 * Time: 20:06
 */

/**
 * Woocommerce Memberships Addon compatibility.
 *
 * Class WP_LMaker_WooCommerce_Memberships
 */
class WP_LMaker_WooCommerce_Memberships extends WP_LMaker_Abstract_Addon {

	public function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_ignore_straight_post_types', array( $this, 'ignore_straight_post_types' ) );
		add_action( 'wp_local_maker_orders_after_orders', array( $this, 'process_memberships' ), 12 );
	}

	public function process_memberships( $tables_info ) {
		global $wpdb;
		$current = $tables_info['posts']['currname'];
		$temp = $tables_info['posts']['tempname'];
		$curr_pm = $tables_info['postmeta']['currname'];

		// Handle memberships
		$wpdb->query(
			"REPLACE INTO {$temp}
            SELECT * FROM {$current} p
            WHERE p.post_status NOT IN ('auto-draft', 'trash')
            AND p.post_type IN ( 'wc_user_membership' )
            ORDER BY p.post_date DESC
            LIMIT 50"
		);

		// Handle subscriptions related memberships
		$wpdb->query(
			"REPLACE INTO {$temp}
            SELECT p.* FROM {$current} p
            INNER JOIN {$curr_pm} pm ON p.ID = pm.post_id AND pm.meta_key = '_subscription_id'
            WHERE p.post_type IN ( 'wc_user_membership' ) AND pm.meta_value IN ( SELECT ID FROM {$temp} p2 WHERE p2.post_type = 'shop_subscription' )"
		);

		do_action( 'wp_local_maker_memberships_after_memberships', $tables_info );
	}

	public function ignore_straight_post_types( $types ) {
		$types[] = 'wc_user_membership';
		return $types;
	}
}

new WP_LMaker_WooCommerce_Memberships();
