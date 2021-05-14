<?php
/**
 * Created by PhpStorm.
 * User: manu
 * Date: 04/02/2019
 * Time: 18:55
 */

/**
 * Class WP_LMaker_Core
 */
class WP_LMaker_Core {

	public function __construct() {
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_posts' ), 10 );
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_comments' ), 20 );
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_users' ), 30 );
		add_action( 'wp_local_maker_before_dump_usermeta', array( $this, 'before_dump_usermeta' ) );
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_terms' ), 40 );
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_core' ), 50 );
	}

	public function enqueue_process_posts( $tables ) {
		$tables['posts']    = array( $this, 'process_posts' );
		$tables['postmeta'] = array( $this, 'process_postmeta' );
		return $tables;
	}

	public function enqueue_process_comments( $tables ) {
		$tables['comments']    = array( $this, 'process_comments' );
		$tables['commentmeta'] = array( $this, 'process_commentmeta' );
		return $tables;
	}

	public function enqueue_process_users( $tables ) {
		$tables['users']    = array( $this, 'process_users' );
		$tables['usermeta'] = array( $this, 'process_usermeta' );
		return $tables;
	}

	public function enqueue_process_terms( $tables ) {
		$tables['term_relationships'] = array( $this, 'process_term_relationships' );
		$tables['term_taxonomy']      = array( $this, 'process_term_taxonomy' );
		$tables['terms']              = array( $this, 'process_terms' );
		$tables['termmeta']           = array( $this, 'process_termmeta' );
		return $tables;
	}

	public function enqueue_process_core( $tables ) {
		$tables['options'] = array( $this, 'process_options' );
		return $tables;
	}

	public function process_posts() {
		global $wpdb;
		$tables_info = Backup_Command::get_tables_names();
		$current     = $tables_info['posts']['currname'];
		$temp        = $tables_info['posts']['tempname'];

		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}" );

		$ignored_post_types = apply_filters( 'wp_local_maker_ignore_straight_post_types', array( 'post', 'attachment', 'revision' ) );
		foreach ( $ignored_post_types as $k => $v ) {
			$ignored_post_types[ $k ] = $wpdb->prepare( '%s', $v );
		}
		$ignored_post_types = implode( ',', $ignored_post_types );

		// Export everything but a few known post types
		$post_types = $wpdb->get_col(
			"SELECT p.post_type FROM {$current} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type NOT IN ( {$ignored_post_types} )
			GROUP BY p.post_type"
		);

		foreach ( $post_types as $post_type ) {
			$limit = Backup_Command::get_limit_for_tag( 'post-type-' . $post_type, PHP_INT_MAX );

			// Handle each unhandled post type separately, so that we can limit post type
			$wpdb->query(
				"REPLACE INTO {$temp}
				SELECT * FROM {$current} p
				WHERE p.post_status NOT IN ('auto-draft', 'trash')
				AND p.post_type IN ( '{$post_type}' )
				ORDER BY p.post_date DESC
				LIMIT {$limit}"
			);
		}

		$limit = Backup_Command::get_limit_for_tag( 'posts', 50 );

		// Handle posts
		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT * FROM {$current} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'post' )
			ORDER BY p.post_date DESC
			LIMIT {$limit}"
		);

		do_action( 'wp_local_maker_posts_after_posts', $tables_info );

		// Handle attachments
		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT * FROM {$current} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'attachment' ) AND p.post_parent IN ( SELECT ID FROM {$temp} p2 )"
		);

		$limit = Backup_Command::get_limit_for_tag( 'attachments', 500 );

		// Handle unrelated attachments
		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT * FROM {$current} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'attachment' ) AND p.post_parent = 0
            ORDER BY p.post_date DESC
			LIMIT {$limit}"
		);

		// Loop until there's no missing parents
		do {
			$affected = $wpdb->query(
				"REPLACE INTO {$temp}
                SELECT p3.* FROM {$temp} p
                LEFT JOIN {$temp} p2 ON p2.ID = p.post_parent
                LEFT JOIN {$current} p3 ON p3.ID = p.post_parent
                WHERE p.post_parent != 0 AND p2.ID IS NULL AND p3.ID IS NOT NULL"
			);
		} while ( $affected > 0 );

		$file = Backup_Command::write_table_file( $temp, $current );

		if ( Backup_Command::verbosity_is( 2 ) ) {
			$columns = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT column_name
					FROM information_schema.columns 
					WHERE table_schema=%s AND table_name=%s',
					Backup_Command::$db_name,
					$temp
				)
			);

			$size_col = "SUM( LENGTH( CONCAT_WS('\", \"', " . implode( ', ', $columns ) . ') ) )';
			$results  = $wpdb->get_results(
				"SELECT post_type, COUNT(*) as num, {$size_col} as size
				FROM {$temp} 
				GROUP BY post_type
				ORDER BY size DESC"
			);
			foreach ( $results as $row ) {
				WP_CLI::line( sprintf( '  Including %s %s posts, which is %s of data', $row->num, $row->post_type, size_format( $row->size ) ) );
			}
		}

		return $file;
	}

	public function process_postmeta() {
		global $wpdb;
		$result = Backup_Command::dependant_table_dump_single( 'postmeta', 'posts', 'post_id', 'ID' );
		if ( Backup_Command::verbosity_is( 2 ) ) {
			$tables_info = Backup_Command::get_tables_names();
			$temp_pm     = $tables_info['postmeta']['tempname'];
			$temp_p      = $tables_info['posts']['tempname'];
			$results     = $wpdb->get_results(
				"SELECT 
				p.post_type, 
				COUNT(*) as num, 
				SUM( LENGTH( meta_value ) ) as size 
				FROM {$temp_pm} pm 
				LEFT JOIN {$temp_p} p ON p.ID = pm.post_id
				GROUP BY p.post_type 
				ORDER BY size DESC"
			);
			foreach ( $results as $row ) {
				WP_CLI::line( sprintf( '  Including %s meta rows related to %s posts, which is %s of data', $row->num, $row->post_type, size_format( $row->size ) ) );
			}
		}

		return $result;
	}

	public function process_comments() {
		return Backup_Command::dependant_table_dump_single( 'comments', 'posts', 'comment_post_ID', 'ID' );
	}

	public function process_commentmeta() {
		return Backup_Command::dependant_table_dump_single( 'commentmeta', 'comments', 'comment_id', 'comment_ID' );
	}

	public function process_users() {
		global $wpdb;
		$tables_info   = Backup_Command::get_tables_names();
		$current       = $tables_info['users']['currname'];
		$temp          = $tables_info['users']['tempname'];
		$curr_usermeta = $tables_info['usermeta']['currname'];

		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}" );

		// Export administrators
		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT u.* FROM {$current} u 
			INNER JOIN {$curr_usermeta} um ON um.user_id = u.ID AND um.meta_key = '{$wpdb->prefix}capabilities'
			WHERE um.meta_value LIKE '%\"administrator\"%'"
		);

		do_action( 'wp_local_maker_users_after_admins', $tables_info, $current, $temp );

		$temp_posts = $tables_info['posts']['tempname'];

		$user_keys = Backup_Command::get_table_keys_group( $current, 'u' );

		// Export authors
		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT u.* FROM {$current} u 
			INNER JOIN {$temp_posts} p ON p.post_author = u.ID
			GROUP BY {$user_keys}"
		);

		do_action( 'wp_local_maker_users_after_authors', $tables_info, $current, $temp );

		$file = Backup_Command::write_table_file( $temp, $current );

		return $file;
	}

	public function process_usermeta() {
		return Backup_Command::dependant_table_dump_single( 'usermeta', 'users', 'user_id', 'ID' );
	}

	public function before_dump_usermeta( $table_name ) {
		global $wpdb;

		$wpdb->query(
			"DELETE FROM {$table_name}
    		WHERE meta_key = 'session_tokens'"
		);
	}

	public function process_term_relationships() {
		global $wpdb;
		$tables_info = Backup_Command::get_tables_names();
		$current     = $tables_info['term_relationships']['currname'];
		$temp        = $tables_info['term_relationships']['tempname'];

		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}" );

		$temp_posts = $tables_info['posts']['tempname'];

		$tr_keys = Backup_Command::get_table_keys_group( $current, 'tr' );

		// Export post terms
		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT tr.* FROM {$current} tr
			INNER JOIN {$temp_posts} p ON tr.object_id = p.ID
			GROUP BY {$tr_keys}"
		);

		$temp_users = $tables_info['users']['tempname'];

		// Export potential author terms
		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT tr.* FROM {$current} tr
			INNER JOIN {$temp_users} u ON tr.object_id = u.ID
			GROUP BY {$tr_keys}"
		);

		$file = Backup_Command::write_table_file( $temp, $current );

		return $file;
	}

	public function process_term_taxonomy() {
		return Backup_Command::dependant_table_dump_single( 'term_taxonomy', 'term_relationships', 'term_taxonomy_id', 'term_taxonomy_id' );
	}

	public function process_terms() {
		return Backup_Command::dependant_table_dump_single( 'terms', 'term_taxonomy', 'term_id', 'term_id' );
	}

	public function process_termmeta() {
		return Backup_Command::dependant_table_dump_single( 'termmeta', 'terms', 'term_id', 'term_id' );
	}

	public function process_options() {
		global $wpdb;
		$tables_info = Backup_Command::get_tables_names();
		$current     = $tables_info['options']['currname'];
		$temp        = $tables_info['options']['tempname'];

		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}" );

		// Exclude transients
		$wpdb->query(
			"REPLACE INTO {$temp}
			SELECT * FROM {$current}
			WHERE option_name NOT LIKE '\_transient%' AND option_name NOT LIKE '\_site\_transient%'"
		);

		$file = Backup_Command::write_table_file( $temp, $current );

		if ( Backup_Command::verbosity_is( 2 ) ) {
			$results = $wpdb->get_results(
				"SELECT 
				SUBSTRING( option_name, 1, 10 ) as option_prefix, 
				COUNT(*) as num, 
				SUM( LENGTH( option_value ) ) as size
				FROM {$temp} 
				GROUP BY option_prefix 
				ORDER BY size DESC
				LIMIT 10"
			);
			foreach ( $results as $row ) {
				WP_CLI::line( sprintf( '  Including %s options prefixed %s, which is %s of data', $row->num, "'{$row->option_prefix}%'", size_format( $row->size ) ) );
			}
		}

		return $file;
	}
}

new WP_LMaker_Core();
