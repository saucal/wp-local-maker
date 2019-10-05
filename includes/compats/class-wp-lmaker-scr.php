<?php
/**
 * Created by PhpStorm.
 * User: manu
 * Date: 04/02/2019
 * Time: 20:26
 */

/**
 * Class WP_LMaker_SCR
 */
class WP_LMaker_SCR extends WP_LMaker_Abstract_Addon {

	public function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_scr' ), 45 );
		add_filter( 'wp_local_maker_global_tables', array( $this, 'register_global_tables' ), 45 );
	}

	public function enqueue_process_scr( $tables ) {
		global $wpdb;
		$tables['scr_relationships']    = array( $this, 'process_scr_relationships' );
		$tables['scr_relationshipmeta'] = array( $this, 'process_scr_relationshipmeta' );
		return $tables;
	}

	public function register_global_tables( $tables ) {
		$tables[] = 'scr_relationships';
		$tables[] = 'scr_relationshipmeta';
		return $tables;
	}

	public function process_scr_relationships() {
		global $wpdb;
		$tables_info = Backup_Command::get_tables_names();
		$current     = $tables_info['scr_relationships']['currname'];
		$temp        = $tables_info['scr_relationships']['tempname'];

		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}" );

		$temp_posts = $tables_info['posts']['tempname'];
		$temp_users = $tables_info['users']['tempname'];

		// Export every matching relationship from a user standpoint
		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT * FROM wp_scr_relationships scr 
			WHERE 
			scr.object1_type = 'user' AND scr.object1_site = 1 AND scr.object1_id IN ( SELECT ID FROM {$temp_users} )  
			AND
			scr.object2_type = 'post' AND scr.object2_site = " . get_current_blog_id() . " AND scr.object2_id IN ( SELECT ID FROM {$temp_posts} )"
		);

		// Export every matching relationship from a post standpoint
		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT * FROM wp_scr_relationships scr 
			WHERE 
			scr.object1_type = 'post' AND scr.object1_site = " . get_current_blog_id() . " AND scr.object1_id IN ( SELECT ID FROM {$temp_posts} )
			AND
			scr.object2_type = 'user' AND scr.object2_site = 1 AND scr.object2_id IN ( SELECT ID FROM {$temp_users} )"
		);

		$file = Backup_Command::write_table_file( $temp, $current );

		return $file;
	}

	public function process_scr_relationshipmeta() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single( 'scr_relationshipmeta', 'scr_relationships', 'scr_relationship_id', 'rel_id' );
	}
}

new WP_LMaker_SCR();

