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

	private $config = array();

	public function __construct( $config = array() ) {
		parent::__construct();
		$this->config = (object) wp_parse_args( $config, array(
			'scr_r' => 'scr_relationships',
			'scr_rm' => 'scr_relationshipmeta',
		) );
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_scr' ), 45 );
		add_filter( 'wp_local_maker_global_tables', array( $this, 'register_global_tables' ), 45 );
	}

	public function enqueue_process_scr( $tables ) {
		global $wpdb;
		$tables[$this->config->scr_r]    = array( $this, 'process_scr_relationships' );
		$tables[$this->config->scr_rm] = array( $this, 'process_scr_relationshipmeta' );
		return $tables;
	}

	public function register_global_tables( $tables ) {
		$tables[] = $this->config->scr_r;
		$tables[] = $this->config->scr_rm;
		return $tables;
	}

	public function process_scr_relationships() {
		global $wpdb;
		$tables_info = Backup_Command::get_tables_names();
		$current     = $tables_info[$this->config->scr_r]['currname'];
		$temp        = $tables_info[$this->config->scr_r]['tempname'];

		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}" );

		$temp_posts = $tables_info['posts']['tempname'];
		$temp_users = $tables_info['users']['tempname'];

		// Export every matching relationship from a user standpoint
		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT * FROM {$current} scr 
			WHERE 
			scr.object1_type = 'user' AND scr.object1_site = 1 AND scr.object1_id IN ( SELECT ID FROM {$temp_users} )  
			AND
			scr.object2_type = 'post' AND scr.object2_site = " . get_current_blog_id() . " AND scr.object2_id IN ( SELECT ID FROM {$temp_posts} )"
		);

		// Export every matching relationship from a post standpoint
		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT * FROM {$current} scr 
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
		return Backup_Command::dependant_table_dump_single( $this->config->scr_rm, $this->config->scr_r, 'scr_relationship_id', 'rel_id' );
	}
}

new WP_LMaker_SCR();

