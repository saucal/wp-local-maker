<?php
/**
 * Class WP_LMaker_Mailchimp
 */
class WP_LMaker_Mailchimp extends WP_LMaker_Abstract_Addon {

	public function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_mcdata' ), 45 );
		add_filter( 'wp_local_maker_global_tables', array( $this, 'register_global_tables' ), 45 );
	}

	public function enqueue_process_mcdata( $tables ) {
		global $wpdb;
		$tables['mcdata'] = array( $this, 'process_mcdata' );
		return $tables;
	}

	public function process_mcdata() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single( 'mcdata', 'users', 'EMAIL', 'user_email' );
	}

	public function register_global_tables( $tables ) {
		$tables[] = 'mcdata';
		return $tables;
	}
}

new WP_LMaker_Mailchimp();

