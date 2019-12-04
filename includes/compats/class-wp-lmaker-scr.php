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

	private $temp_prefix = '_WPLM_temp_scr_list_';

	public function __construct( $config = array() ) {
		parent::__construct();
		$this->config = (object) wp_parse_args(
			$config,
			array(
				'scr_r'        => 'scr_relationships',
				'scr_rm'       => 'scr_relationshipmeta',
				'scr_rm_r_key' => 'scr_relationship_id',
			) 
		);
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_scr' ), 45 );
		add_filter( 'wp_local_maker_global_tables', array( $this, 'register_global_tables' ), 45 );
		add_filter( 'wp_local_maker_before_dump_' . $this->config->scr_r, array( $this, 'cleanup' ) );
	
	}

	public function enqueue_process_scr( $tables ) {
		global $wpdb;
		$tables[ $this->config->scr_r ]  = array( $this, 'process_scr_relationships' );
		$tables[ $this->config->scr_rm ] = array( $this, 'process_scr_relationshipmeta' );
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
		$current     = $tables_info[ $this->config->scr_r ]['currname'];
		$temp        = $tables_info[ $this->config->scr_r ]['tempname'];

		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}" );

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$this->temp_prefix} (
				object_type varchar(30) COLLATE utf8mb4_unicode_520_ci NOT NULL,
				object_site bigint(20) NOT NULL,
				object_id varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL,
				PRIMARY KEY (object_type,object_site,object_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;"
		);

		$temp_posts         = $tables_info['posts']['tempname'];
		$temp_users         = $tables_info['users']['tempname'];
		$temp_terms         = $tables_info['terms']['tempname'];
		$temp_term_taxonomy = $tables_info['term_taxonomy']['tempname'];

		$data_types = array(
			'post'     => "SELECT ID FROM {$temp_posts}",
			'user'     => "SELECT ID FROM {$temp_users}",
			'tax_term' => "SELECT CAST( CONCAT( taxonomy, '|||', slug ) AS CHAR CHARACTER SET utf8) as ID FROM {$temp_term_taxonomy} tt
			INNER JOIN {$temp_terms} t ON t.term_id = tt.term_id",
		);

		$blog_id = get_current_blog_id();

		foreach ( $data_types as $type => $sql_where ) {
			if ( 'user' === $type && 1 !== $blog_id ) {
				continue;
			}

			$wpdb->query(
				"REPLACE INTO {$this->temp_prefix} 
				SELECT '{$type}', {$blog_id}, ID FROM ( {$sql_where} ) d"
			);
		}

		$file = Backup_Command::write_table_file( $temp, $current );

		return $file;
	}

	public function cleanup() {
		global $wpdb;
		$tables_info = Backup_Command::get_tables_names();
		$current     = $tables_info[ $this->config->scr_r ]['currname'];
		$temp        = $tables_info[ $this->config->scr_r ]['tempname'];

		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT r.* FROM {$current} r
			INNER JOIN {$this->temp_prefix} l1 ON r.object1_type = l1.object_type AND r.object1_site = l1.object_site AND r.object1_id = l1.object_id
			INNER JOIN {$this->temp_prefix} l2 ON r.object2_type = l2.object_type AND r.object2_site = l2.object_site AND r.object2_id = l2.object_id"
		);

		$wpdb->query( "DROP TABLE {$this->temp_prefix}" );
	}

	public function process_scr_relationshipmeta() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single( $this->config->scr_rm, $this->config->scr_r, $this->config->scr_rm_r_key, 'rel_id' );
	}
}

new WP_LMaker_SCR();

