<?php
/**
 * Class WP_LMaker_WooCommerce_Order_Index
 */
class WP_LMaker_OAuth_Server extends WP_LMaker_Abstract_Addon {

	public function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_custom_tables' ), 45 );
	}

	public function enqueue_custom_tables( $tables ) {
		$tables['oauth_access_tokens'] = array( $this, 'process_access_tokens' );
		$tables['oauth_authorization_codes'] = array( $this, 'process_authorization_codes' );
		$tables['oauth_refresh_tokens'] = array( $this, 'process_refresh_tokens' );
		return $tables;
	}

	public function process_access_tokens() {
		return Backup_Command::dependant_table_dump_single( 'oauth_access_tokens', 'users', 'user_id', 'ID' );
	}

	public function process_authorization_codes() {
		return Backup_Command::dependant_table_dump_single( 'oauth_authorization_codes', 'users', 'user_id', 'ID' );
	}

	public function process_refresh_tokens() {
		return Backup_Command::dependant_table_dump_single( 'oauth_refresh_tokens', 'users', 'user_id', 'ID' );
	}
}

new WP_LMaker_OAuth_Server();

