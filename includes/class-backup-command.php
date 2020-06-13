<?php
/**
 * Created by PhpStorm.
 * User: manu
 * Date: 04/02/2019
 * Time: 18:36
 */
use WP_CLI\Formatter;
use WP_CLI\Utils;

WP_CLI::add_command( 'backup', 'Backup_Command' );

/**
 * Perform backups of the database with reduced data sets
 */
class Backup_Command extends WP_CLI_Command {

	protected static $tables_info = null;

	protected static $deferred_table_dumps = array();

	protected static $doing_deferred = false;

	protected static $new_domain = false;

	protected static $old_domain = false;

	protected static $current_assoc_args = array();

	protected static $exported_files = array();

	protected static $hash = '';

	protected static $mysqldump = '';

	public static $db_name = '';

	protected function init_deps() {
		require_once __DIR__ . '/class-wp-lmaker-dir-crawler.php';
		require_once __DIR__ . '/class-wp-lmaker-dir-filter.php';
		require_once __DIR__ . '/class-wp-lmaker-core.php';
		require_once __DIR__ . '/class-wp-lmaker-init-compats.php';
		require_once __DIR__ . '/class-wp-lmaker-mysqldump.php';
		self::$mysqldump = new WP_LMaker_MySQLDump();
	}

	/**
	 * Exports the database and/or filesystem to a file.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : The name of the SQL file to export. If '-', then outputs to STDOUT. If
	 * omitted, it will be '{dbname}-{Y-m-d}-{random-hash}.sql'.
	 *
	 * [--new-domain=<domain>]
	 * : Domain to replace on the backup. For easy of setup in a local environment.
	 *
	 * [--db-only]
	 * : Only export database dump.
	 *
	 * [--verbosity=<level>]
	 * : Verbosity level. Shorthands available via --v, --vv, --vvv, --vvvv, --vvvvv.
	 *
	 * [--<field>=<value>]
	 * : Generic parameter to avoid validation issues.
	 *
	 */
	public function export( $args, $assoc_args ) {

		$this->init_deps();

		self::$current_assoc_args = $assoc_args;

		self::$hash = wp_generate_password( 7, false );

		global $wpdb;
		self::$db_name = $wpdb->get_var( 'SELECT DATABASE()' );

		if ( ! empty( $args[0] ) ) {
			$result_file = $args[0];
		} else {
			$result_file = sprintf( 'WPLM-%s-%s-%s.zip', self::$db_name, date( 'Y-m-d-H-i-s' ), self::$hash );
		}

		self::cleanup(); // early cleanup, to cleanup unfinished exports.

		$db_only = WP_CLI\Utils\get_flag_value( $assoc_args, 'db-only', false );

		$target_folder   = untrailingslashit( ABSPATH );
		$target_url_base = untrailingslashit( site_url( '' ) );
		$method          = 'fs';

		$replace = WP_CLI\Utils\get_flag_value( $assoc_args, 'new-domain', false );
		if ( $replace ) {
			self::$new_domain = $replace;
			$old_domain       = network_site_url();
			$old_domain       = wp_parse_url( $old_domain );
			self::$old_domain = $old_domain['host'];
		}

		$files   = array();
		$files[] = self::dump_structure();

		$files = array_merge( $files, self::dump_data() );

		$db_file = self::join_files( $files );

		if ( self::verbosity_is( 2 ) ) {
			WP_CLI::line( sprintf( 'SQL dump is %s (uncompressed).', size_format( filesize( $db_file ) ) ) );
		}

		if ( self::verbosity_is( 3 ) ) {
			arsort( self::$exported_files, SORT_NUMERIC );
			$top = array_slice( self::$exported_files, 0, 20, true );
			WP_CLI::line( 'Largest tables exported' );
			foreach ( $top as $table => $size ) {
				WP_CLI::line( sprintf( '  %s export is %s.', $table, size_format( $size ) ) );
			}
		}

		$result_file_tmp = self::get_temp_filename( 'result-file' );
		if ( $db_only ) {
			self::maybe_zip_file( $db_file, $result_file_tmp, basename( self::get_db_file_path_target() ) );
		} else {
			self::maybe_zip_folder( ABSPATH, $result_file_tmp );
		}

		self::cleanup();
		self::$current_assoc_args = array();

		$size = size_format( filesize( $result_file_tmp ) );

		switch ( $method ) {
			case 'fs':
				$target_file = $target_folder . '/' . $result_file;
				wp_mkdir_p( dirname( $target_file ) );
				rename( $result_file_tmp, $target_file );
				$result_file_url = $target_url_base . '/' . $result_file;
				WP_CLI::line( sprintf( "Exported to '%s'. Export size: %s.", $result_file, $size ) );
				WP_CLI::line( sprintf( 'You can download here: %s', $result_file_url ) );
				break;
		}
	}

	public static function get_flag_value( $flag, $default = null ) {
		return WP_CLI\Utils\get_flag_value( self::$current_assoc_args, $flag, $default );
	}

	public static function verbosity_is( $level ) {
		return self::get_verbosity_level() >= $level;
	}

	public static function get_verbosity_level() {
		$long_version = self::get_flag_value( 'verbosity', false );
		if ( false !== $long_version ) {
			return (int) $long_version;
		}

		for ( $i = 1; $i <= 5; $i ++ ) {
			$flag      = str_repeat( 'v', $i );
			$candidate = self::get_flag_value( $flag, false );
			if ( false !== $candidate ) {
				return $i;
			}
		}

		return 0;
	}

	public static function get_limit_for_tag( $tag, $default = null ) {
		$limit = self::get_flag_value( 'limit-' . $tag, false );
		if ( false !== $limit ) {
			return intval( $limit );
		}
		$limit = apply_filters( 'wp_local_maker_limit_' . $tag, $default );
		return intval( $limit );
	}

	protected static function get_db_file_path() {
		return self::get_temp_filename( 'result-dump' );
	}

	protected static function get_uploads_folder_path( $desired_path ) {
		$upload_dir = wp_get_upload_dir();
		$basedir    = untrailingslashit( $upload_dir['basedir'] );
		return $basedir . '/' . $desired_path;
	}

	protected static function get_uploads_folder_url( $desired_path ) {
		$upload_dir = wp_get_upload_dir();
		$basedir    = untrailingslashit( $upload_dir['baseurl'] );
		return $basedir . '/' . $desired_path;
	}

	protected static function get_db_file_path_target() {
		return self::get_uploads_folder_path( 'database.sql' );
	}

	protected static function get_temp_filename( $filename = null ) {
		if ( $filename ) {
			return trailingslashit( get_temp_dir() ) . $filename . '-' . self::$hash . '.tmp';
		} else {
			return wp_tempnam( 'backup_export' );
		}
	}

	public static function dump_structure() {
		if ( self::verbosity_is( 2 ) ) {
			WP_CLI::line( 'Exporting database structure (all tables).' );
		}

		$first_pass = self::get_temp_filename();

		self::$mysqldump->run(
			self::$db_name,
			$first_pass,
			array(
				'no-data' => true,
			)
		);

		$first_pass = self::adjust_structure( $first_pass );

		if ( self::verbosity_is( 1 ) ) {
			WP_CLI::line( sprintf( 'Exported database structure (all tables). Export size: %s', size_format( filesize( $first_pass ) ) ) );
		}

		return $first_pass;
	}

	public static function adjust_structure( $file ) {
		$source      = fopen( $file, 'r' );
		$target_name = self::get_temp_filename();
		$target      = fopen( $target_name, 'w' );

		while ( ! feof( $source ) ) {
			$str = fgets( $source );

			// Clear definer for views
			$str = preg_replace( '/DEFINER=\`.*?\`@\`.*?\`/', '', $str );
			$str = preg_replace( '/SQL SECURITY DEFINER/', '', $str );
			$str = preg_replace( '/\/\*\![0-9]+\s*\*\/\n/', '', $str );
			fputs( $target, $str );
		}

		fclose( $source );
		@unlink( $file );
		fclose( $target );
		rename( $target_name, $file );
		return $file;
	}

	public static function dump_data_from_table( $table, $this_table_file = null ) {
		if ( is_null( $this_table_file ) ) {
			$this_table_file = self::get_temp_filename();
		}

		@unlink( $this_table_file );

		self::$mysqldump->run(
			self::$db_name,
			$this_table_file,
			array(
				'tables'          => array(
					$table,
				),
				'no-create-info'  => true,
				'complete-insert' => true,
			)
		);

		return $this_table_file;
	}

	public static function get_tables_info() {
		if ( isset( self::$tables_info ) ) {
			return self::$tables_info;
		}

		$tables_to_custom_process = apply_filters( 'wp_local_maker_custom_process_tables', array() );

		$key = -1;
		foreach ( $tables_to_custom_process as $table => $cb ) {
			$key++;
			$tables_to_custom_process[ $table ] = array(
				'prio'     => $key,
				'callback' => $cb,
			);
		}

		$excluded_tables = apply_filters( 'wp_local_maker_excluded_tables', array() );

		foreach ( $excluded_tables as $table ) {
			$key++;
			$tables_to_custom_process[ $table ] = array(
				'prio'     => $key,
				'callback' => false,
			);
		}

		self::$tables_info = $tables_to_custom_process;
		return self::$tables_info;
	}

	public static function get_table_internal_info( $table ) {
		global $wpdb;
		$re = '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '(?:([0-9]*)_)?/m';
		preg_match( $re, $table, $matches );

		$internal_key = $table;
		$blog_id      = 1;
		$prefixed     = true;
		if ( ! empty( $matches ) ) {
			// Print the entire match result
			if ( ! isset( $matches[1] ) ) {
				$blog_id = 1;
			} else {
				$blog_id = (int) $matches[1];
			}
			$internal_key = substr( $internal_key, strlen( $matches[0] ) );
		} else {
			$prefixed = false;
		}

		return array( $internal_key, $blog_id, $prefixed );
	}

	public static function get_table_temp_name( $table ) {
		return '_WPLM_' . wp_hash( $table, 'nonce' );
	}

	public static function get_table_name( $table, $key = 'curr' ) {
		global $wpdb;

		$dbname          = self::$db_name;
		$sql             = "SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE' AND `TABLES_IN_{$dbname}` REGEXP '^({$wpdb->base_prefix}(?:([0-9]*)_)?)?{$table}$'";
		$table_name_full = $wpdb->get_var( $sql );

		if ( is_null( $table_name_full ) ) {
			$table_name_full = $table;
		}

		list($internal_key, , $prefixed) = self::get_table_internal_info( $table_name_full );
		$table                           = $internal_key;

		if ( $prefixed ) {
			if ( in_array( $table, self::global_tables(), true ) ) {
				$table = $wpdb->base_prefix . $table;
			} else {
				$table = $wpdb->prefix . $table;
			}
		}

		if ( 'temp' === $key ) {
			return self::get_table_temp_name( $table );
		} else {
			return $table;
		}
	}

	public static function get_tables_names() {
		$tables_info = self::get_tables_info();
		foreach ( array_keys( $tables_info ) as $table ) {
			$new_info              = array(
				'currname' => self::get_table_name( $table, 'curr' ),
				'tempname' => self::get_table_name( $table, 'temp' ),
			);
			$tables_info[ $table ] = $new_info;
		}
		return $tables_info;
	}

	public static function write_table_file( $table, $replace_name = '' ) {
		global $wpdb;
		$table_final_name = $table;
		if ( $replace_name ) {
			$table_final_name = $replace_name;
		}

		$clean_table_name = $table_final_name;
		$clean_table_name = str_replace( $wpdb->prefix, '', $clean_table_name );
		$clean_table_name = str_replace( $wpdb->base_prefix, '', $clean_table_name );

		$tables_info = self::get_tables_info();
		$is_custom   = isset( $tables_info[ $clean_table_name ] );

		$table_file = self::get_temp_filename( $table_final_name );

		if ( ! self::$doing_deferred && in_array( $clean_table_name, self::global_tables(), true ) && $is_custom ) {
			$prio                                = $tables_info[ $clean_table_name ]['prio'];
			self::$deferred_table_dumps[ $prio ] = array( $table, $replace_name );
			return $table_file;
		}

		do_action( 'wp_local_maker_before_dump_' . $clean_table_name, $table );
		do_action( 'wp_local_maker_before_dump', $table, $clean_table_name );

		if ( self::$new_domain ) {
			if ( in_array( $clean_table_name, array( 'blogs', 'site' ), true ) ) {
				$search_command = 'search-replace ' . self::$old_domain . ' ' . self::$new_domain . ' ' . $table . ' --all-tables --precise --report=0';
			} else {
				$search_command = 'search-replace //' . self::$old_domain . ' //' . self::$new_domain . ' ' . $table . ' --all-tables --precise --report=0';
			}
			$options = array(
				'return'     => 'all',   // Return 'STDOUT'; use 'all' for full object.
				'launch'     => false,   // Reuse the current process.
				'exit_error' => false,   // Halt script execution on error.
			);

			$ret = WP_CLI::runcommand( $search_command, $options );

			if ( $ret->stderr ) {
				echo esc_html( 'ERROR:' . $ret->stderr . "\n" );
			}
			if ( $ret->stdout ) {
				echo esc_html( $ret->stdout . "\n" );
			}
		}

		if ( self::verbosity_is( 4 ) ) {
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
			WP_CLI::line( sprintf( 'Exporting %d rows from %s.', $count, $replace_name ) );
		}

		$file = self::dump_data_from_table( $table, $table_file );

		if ( $replace_name ) {
			$file = self::adjust_file( $file, "`{$table}`", "`{$replace_name}`" );
		}

		$original_table_name = $replace_name ? $replace_name : $table;
		$export_size         = filesize( $file );
		if ( self::verbosity_is( 1 ) ) {
			WP_CLI::line( sprintf( 'Exported %d rows from %s. Export size: %s', $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), $original_table_name, size_format( $export_size ) ) );
		}

		self::$exported_files[ $original_table_name ] = $export_size;

		return $file;
	}

	public static function dependant_table_dump( $current_index, $after = '' ) {
		global $wpdb;
		$tables_info = self::get_tables_names();
		$current     = $tables_info[ $current_index ]['currname'];
		$temp        = $tables_info[ $current_index ]['tempname'];

		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}" );

		$query = "REPLACE INTO {$temp} SELECT * FROM {$current} p";
		if ( $after ) {
			$query .= ' ' . $after;
		}

		$wpdb->query( $query );

		$file = self::write_table_file( $temp, $current );

		return $file;
	}

	public static function dependant_table_dump_single( $current, $dependant, $current_key, $dependant_key ) {
		$tables_info = self::get_tables_names();
		$temp_posts  = $tables_info[ $dependant ]['tempname'];
		return self::dependant_table_dump( $current, "WHERE p.{$current_key} IN ( SELECT {$dependant_key} FROM {$temp_posts} p2 GROUP BY {$dependant_key} )" );
	}

	public static function get_table_keys_group( $table, $prefix = '' ) {
		$keys = self::get_columns( $table )[0];
		if ( $prefix ) {
			foreach ( $keys as $i => $key ) {
				$keys[ $i ] = $prefix . '.' . $key;
			}
		}

		return implode( ', ', $keys );
	}

	protected static function global_tables() {
		global $wpdb;
		return apply_filters( 'wp_local_maker_global_tables', $wpdb->tables( 'global', false ) );
	}

	protected static function array_unique_last( $arr ) {
		$res = array();
		for ( $i = count( $arr ) - 1; $i >= 0; --$i ) {
			$item = $arr[ $i ];

			if ( ! isset( $res[ $item ] ) ) {
				$res = array( $item => $item ) + $res; // unshift
			}
		}

		return array_values( $res );
	}

	protected static function dump_data() {
		global $wpdb;

		$files  = array();
		$tables = $wpdb->get_col( "SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE'" );

		$tables_info   = self::get_tables_info();
		$global_tables = self::global_tables();

		$process_queue = array();

		$global_queue = array();

		foreach ( $tables as $table ) {

			list($internal_key, $blog_id) = self::get_table_internal_info( $table );

			if ( ! isset( $tables_info[ $internal_key ] ) ) {
				$current = $table;
				$temp    = self::get_table_temp_name( $current );

				$wpdb->query( "CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}" );
				$query = "REPLACE INTO {$temp} SELECT * FROM {$current}";
				$wpdb->query( $query );

				$files[] = self::write_table_file( $temp, $current );
				continue;
			}

			$tbl_info = $tables_info[ $internal_key ];
			if ( ! is_callable( $tbl_info['callback'] ) ) {
				continue;
			}

			$object_to_append = array(
				'currname' => $table,
				'tempname' => self::get_table_name( $internal_key, 'temp' ),
				'callback' => $tbl_info['callback'],
			);

			if ( in_array( $internal_key, $global_tables, true ) ) {
				$global_queue[ $tbl_info['prio'] ] = $object_to_append;
			} else {
				if ( ! isset( $process_queue[ $blog_id ] ) ) {
					$process_queue[ $blog_id ] = array();
				}

				$process_queue[ $blog_id ][ $tbl_info['prio'] ] = $object_to_append;
			}
		}

		krsort( $process_queue, SORT_NUMERIC );

		if ( ! empty( $global_queue ) ) {
			foreach ( array_keys( $process_queue ) as $blog_id ) {
				foreach ( $global_queue as $prio => $tbl_info ) {
					$process_queue[ $blog_id ][ $prio ] = $tbl_info;
				}
			}
		}

		foreach ( $process_queue as $blog_id => $blog_queue ) {
			ksort( $blog_queue, SORT_NUMERIC );
			$switched = false;
			if ( is_multisite() && get_current_blog_id() !== $blog_id ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
			$process_queue[ $blog_id ] = $blog_queue;
			foreach ( $blog_queue as $i => $tbl_info ) {
				$callback = $tbl_info['callback'];
				if ( is_callable( $callback ) ) {
					$files[] = call_user_func( $callback, $tbl_info['currname'], $tbl_info['tempname'] );
					unset( $process_queue[ $blog_id ][ $i ] );
				}
			}
			if ( $switched ) {
				restore_current_blog();
			}

			if ( empty( $process_queue[ $blog_id ] ) ) {
				unset( $process_queue[ $blog_id ] );
			}
		}

		if ( ! empty( self::$deferred_table_dumps ) ) {
			ksort( self::$deferred_table_dumps, SORT_NUMERIC );
			while ( ! empty( self::$deferred_table_dumps ) ) {
				self::$doing_deferred = true;

				$data = array_shift( self::$deferred_table_dumps );

				$files[] = call_user_func_array( array( __CLASS__, 'write_table_file' ), $data );
			}
		}

		$files = self::array_unique_last( $files );

		if ( ! empty( $process_queue ) ) {
			WP_CLI::warning( sprintf( 'Unfinished tables %s.', implode( ', ', $process_queue ) ) );
		}

		return $files;
	}

	public static function adjust_file( $file, $find, $replace ) {
		$source      = fopen( $file, 'r' );
		$target_name = self::get_temp_filename();
		$target      = fopen( $target_name, 'w' );

		while ( ! feof( $source ) ) {
			$str = str_replace( $find, $replace, fgets( $source ) );
			fputs( $target, $str );
		}

		fclose( $source );
		@unlink( $file );
		fclose( $target );
		rename( $target_name, $file );
		return $file;
	}

	protected static function join_files( $files ) {
		$result_file = self::get_db_file_path();
		@unlink( $result_file );
		$target = fopen( $result_file, 'w' );

		foreach ( $files as $file ) {
			if ( ! file_exists( $file ) ) {
				continue; // maybe it was already included
			}

			$source = fopen( $file, 'r' );
			stream_copy_to_stream( $source, $target );
			fclose( $source );
			@unlink( $file );
		}

		fclose( $target );
		return $result_file;
	}

	protected static function cleanup() {
		global $wpdb;
		$tables = $wpdb->get_col( "SHOW TABLES LIKE '_WPLM%'" );
		foreach ( $tables as $table ) {
			if ( self::verbosity_is( 5 ) ) {
				WP_CLI::line( "Removing temporary table {$table}." );
			}
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
			if ( self::verbosity_is( 4 ) ) {
				WP_CLI::line( "Removed temporary table {$table}." );
			}
		}
		self::$new_domain     = self::$old_domain = false; // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments
		self::$exported_files = array();
	}

	protected static function maybe_zip_file( $file, $zip_fn, $filename = '' ) {
		// Initialize archive object
		$zip = new ZipArchive();
		if ( empty( $filename ) ) {
			$filename = basename( $file );
		}
		$zip->open( $zip_fn, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFile( $file, $filename );
		$zip->close();
		return $zip_fn;
	}

	protected static function maybe_zip_folder( $root_path, $zip_fn ) {
		echo 'Compressing directory';

		$root_path = untrailingslashit( $root_path );

		$zip = WP_LMaker_Dir_Crawler::process(
			array(
				'path'          => $root_path,
				'ignored_paths' => apply_filters( 'wp_local_maker_zip_ignored_paths', array( 'wp-content/uploads' ) ),
			),
			$zip_fn
		);

		$zip->addEmptyDir( 'wp-content/uploads' );

		foreach ( apply_filters( 'wp_local_maker_extra_compressed_paths', array() ) as $relative_path_to_compress ) {
			WP_LMaker_Dir_Crawler::process(
				array(
					'path'      => $root_path . '/' . $relative_path_to_compress,
					'root_path' => $root_path,
				),
				$zip
			);
		}

		$source_wp_conf = ABSPATH . 'wp-config.php';
		$target_wp_conf = self::get_temp_filename( 'wp-config-wplm-temp' );
		$copied         = @copy( $source_wp_conf, $target_wp_conf );
		if ( $copied ) {
			$config_transformer = new WPConfigTransformer( $target_wp_conf );
			if ( self::$new_domain ) {
				$config_transformer->update(
					'constant',
					'DOMAIN_CURRENT_SITE',
					self::$new_domain,
					array(
						'add'       => false,
						'normalize' => true,
					)
				);
			}
			$config_transformer->remove( 'constant', 'WP_SITEURL' );
			$config_transformer->remove( 'constant', 'WP_HOME' );
			$home_url = is_multisite() ? network_home_url() : home_url();
			$home_url = wp_parse_url( $home_url );
			$home_url = $home_url['host'] . ( isset( $home_url['port'] ) ? $home_url['port'] : '' );

			$anchors = array( "/* That's all, stop editing!", "# That's It. Pencils down" );
			foreach ( $anchors as $anchor ) {
				try {
					$config_transformer->update( 'constant', 'WPLM_OLD_HOME', $home_url, array( 'anchor' => $anchor ) );
					break;
				} catch ( Exception $e ) {
					unset( $e );
				}
			}
			$zip->addFile( $target_wp_conf, 'wp-config.php' );
		}

		do_action( 'wp_local_maker_before_closing_zip', $zip );

		// Zip archive will be created only after closing object
		$zip->close();

		echo "\n";

		WP_LMaker_Dir_Crawler::reset();

		@unlink( $target_wp_conf );

		return $zip_fn;
	}

	/**
	 * Gets the column names of a db table differentiated into key columns and text columns and all columns.
	 *
	 * @param string $table The table name.
	 * @return array A 3 element array consisting of an array of primary key column names, an array of text column names, and an array containing all column names.
	 */
	private static function get_columns( $table ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName
		global $wpdb;

		$table_sql       = self::esc_sql_ident( $table );
		$primary_keys    = $text_columns = $all_columns = array(); // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments
		$suppress_errors = $wpdb->suppress_errors();
		$results         = $wpdb->get_results( "DESCRIBE $table_sql" );
		if ( $results ) {
			foreach ( $results as $col ) {
				if ( 'PRI' === $col->Key ) {
					$primary_keys[] = $col->Field;
				}
				if ( self::is_text_col( $col->Type ) ) {
					$text_columns[] = $col->Field;
				}
				$all_columns[] = $col->Field;
			}
		}
		$wpdb->suppress_errors( $suppress_errors );
		return array( $primary_keys, $text_columns, $all_columns );
		// phpcs:enable
	}

	/**
	 * Determines whether a column is considered text or not.
	 *
	 * @param string Column type.
	 * @bool True if text column, false otherwise.
	 */
	private static function is_text_col( $type ) {
		foreach ( array( 'text', 'varchar' ) as $token ) {
			if ( false !== strpos( $type, $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Escapes (backticks) MySQL identifiers (aka schema object names) - i.e. column names, table names, and database/index/alias/view etc names.
	 * See https://dev.mysql.com/doc/refman/5.5/en/identifiers.html
	 *
	 * @param string|array $idents A single identifier or an array of identifiers.
	 * @return string|array An escaped string if given a string, or an array of escaped strings if given an array of strings.
	 */
	private static function esc_sql_ident( $idents ) {
		$backtick = function ( $v ) {
			// Escape any backticks in the identifier by doubling.
			return '`' . str_replace( '`', '``', $v ) . '`';
		};
		if ( is_string( $idents ) ) {
			return $backtick( $idents );
		}
		return array_map( $backtick, $idents );
	}
}
