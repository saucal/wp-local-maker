<?php
/**
 * Class WP_LMaker_Yoast_WordPress_SEO
 */
class WP_LMaker_Yoast_WordPress_SEO extends WP_LMaker_Abstract_Addon {

	public function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_custom_tables' ), 75 );
	}

	public function enqueue_custom_tables( $tables ) {
		$tables['yoast_seo_meta'] = array( $this, 'process_seo_meta' );
		$tables['yoast_seo_links'] = array( $this, 'process_seo_links' );
		$tables['yoast_indexable'] = array( $this, 'process_indexable' );
		$tables['yoast_indexable_hierarchy'] = array( $this, 'process_indexable_hierarchy' );
		$tables['yoast_primary_term'] = array( $this, 'process_primary_term' );
		return $tables;
	}

	public function process_seo_meta() {
		return Backup_Command::dependant_table_dump_single( 'yoast_seo_meta', 'posts', 'object_id', 'ID' );
	}

	public function process_seo_links() {
		return Backup_Command::dependant_table_dump_single( 'yoast_seo_links', 'posts', 'post_id', 'ID' );
	}

	public function process_indexable( $current, $temp ) {
		global $wpdb;
		$tables_info = Backup_Command::get_tables_names();
		$temp_post   = $tables_info['posts']['tempname'];
		$temp_users  = $tables_info['users']['tempname'];
		$temp_terms  = $tables_info['terms']['tempname'];

		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}" );

		// Handle anything not handled specifically related actions
		$wpdb->query(
			"REPLACE INTO {$temp}
            SELECT * FROM {$current} 
            WHERE object_type NOT IN ( 'user', 'post', 'term' )"
		);

		// Handle users
		$wpdb->query(
			"REPLACE INTO {$temp}
            SELECT * FROM {$current} 
            WHERE object_type = 'user' AND object_id IN ( SELECT ID FROM {$temp_users} )"
		);

		// Handle posts
		$wpdb->query(
			"REPLACE INTO {$temp}
            SELECT * FROM {$current} 
            WHERE object_type = 'post' AND object_id IN ( SELECT ID FROM {$temp_post} )"
		);

		// Handle terms
		$wpdb->query(
			"REPLACE INTO {$temp}
            SELECT * FROM {$current} 
            WHERE object_type = 'term' AND object_id IN ( SELECT ID FROM {$temp_terms} )"
		);

		$file = Backup_Command::write_table_file( $temp, $current );

		return $file;
	}

	public function process_indexable_hierarchy() {
		return Backup_Command::dependant_table_dump_single( 'yoast_indexable_hierarchy', 'yoast_indexable', 'indexable_id', 'id' );
	}

	public function process_primary_term() {
		return Backup_Command::dependant_table_dump_single( 'yoast_primary_term', 'posts', 'post_id', 'ID' );
	}
}

new WP_LMaker_Yoast_WordPress_SEO();

