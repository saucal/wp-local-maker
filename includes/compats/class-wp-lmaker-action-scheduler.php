<?php
/**
 * Created by PhpStorm.
 * User: manu
 * Date: 04/02/2019
 * Time: 20:09
 */

/**
 * Action_Scheduler Addon compatibility.
 *
 * Class WP_LMaker_Action_Scheduler
 */
class WP_LMaker_Action_Scheduler extends WP_LMaker_Abstract_Addon {

	public function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_ignore_straight_post_types', array( $this, 'ignore_straight_post_types' ) );
		add_action( 'wp_local_maker_subscriptions_after_subscriptions', array( $this, 'process_subscriptions_actions' ) );
		add_action( 'wp_local_maker_memberships_after_memberships', array( $this, 'process_memberships_actions' ) );
	}

	public function process_subscriptions_actions( $tables_info ) {
		global $wpdb;
		$current = $tables_info['posts']['currname'];
		$temp    = $tables_info['posts']['tempname'];

		// Handle subscriptions related actions
		$wpdb->query(
			"REPLACE INTO {$temp}
            SELECT * FROM {$current} 
            WHERE post_type = 'scheduled-action' AND post_content IN ( SELECT CONCAT('{\"subscription_id\":', ID, '}') FROM {$temp} p2 WHERE p2.post_type = 'shop_subscription' )"
		);
	}

	public function process_memberships_actions( $tables_info ) {
		global $wpdb;
		$current = $tables_info['posts']['currname'];
		$temp    = $tables_info['posts']['tempname'];

		// Handle memberships related actions
		$wpdb->query(
			"REPLACE INTO {$temp}
            SELECT * FROM {$current} 
            WHERE post_type = 'scheduled-action' AND post_content IN ( SELECT CONCAT('{\"user_membership_id\":', ID, '}') FROM {$temp} p2 WHERE p2.post_type = 'wc_user_membership' )"
		);
	}

	public function ignore_straight_post_types( $types ) {
		$types[] = 'scheduled-action';
		return $types;
	}
}

new WP_LMaker_Action_Scheduler();

