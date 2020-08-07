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
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_as_tables' ), 15 );
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

	public function enqueue_process_as_tables( $tables ) {
		$tables['actionscheduler_actions'] = array( $this, 'process_actions' );
		$tables['actionscheduler_claims']  = array( $this, 'process_claims' );
		$tables['actionscheduler_groups']  = array( $this, 'process_groups' );
		$tables['actionscheduler_logs']    = array( $this, 'process_logs' );
		
		return $tables;
	}

	public function process_actions( $current, $temp ) {
		global $wpdb;
		$tables_info = Backup_Command::get_tables_names();
		$temp_post   = $tables_info['posts']['tempname'];

		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}" );

		// Handle subscriptions related actions
		$wpdb->query(
			"REPLACE INTO {$temp}
            SELECT * FROM {$current} 
            WHERE args IN ( SELECT CONCAT('{\"subscription_id\":', ID, '}') FROM {$temp_post} p2 WHERE p2.post_type = 'shop_subscription' )"
		);

		// Handle memberships related actions
		$wpdb->query(
			"REPLACE INTO {$temp}
            SELECT * FROM {$current} 
            WHERE args IN ( SELECT CONCAT('{\"user_membership_id\":', ID, '}') FROM {$temp_post} p2 WHERE p2.post_type = 'wc_user_membership' )"
		);

		$file = Backup_Command::write_table_file( $temp, $current );

		return $file;
	}

	public function process_claims() {
		return Backup_Command::dependant_table_dump_single( 'actionscheduler_claims', 'actionscheduler_actions', 'claim_id', 'claim_id' );
	}

	public function process_groups() {
		return Backup_Command::dependant_table_dump_single( 'actionscheduler_groups', 'actionscheduler_actions', 'group_id', 'group_id' );
	}

	public function process_logs() {
		return Backup_Command::dependant_table_dump_single( 'actionscheduler_logs', 'actionscheduler_actions', 'action_id', 'action_id' );
	}
}

new WP_LMaker_Action_Scheduler();

