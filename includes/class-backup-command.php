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
 *
 * ## EXAMPLES
 *
 *     # Create a new database.
 *     $ wp db create
 *     Success: Database created.
 *
 *     # Drop an existing database.
 *     $ wp db drop --yes
 *     Success: Database dropped.
 *
 *     # Reset the current database.
 *     $ wp db reset --yes
 *     Success: Database reset.
 *
 *     # Execute a SQL query stored in a file.
 *     $ wp db query < debug.sql
 *
 * @when after_wp_config_load
 */
class Backup_Command extends WP_CLI_Command {

	protected static $tables_info = null;

	protected static $deferred_table_dumps = array();

	protected static $doing_deferred = false;

	protected static $new_domain = false;

	protected static $old_domain = false;

	protected static $current_assoc_args = array();

	/**
	 * Exports the database to a file or to STDOUT.
	 *
	 * Runs `mysqldump` utility using `DB_HOST`, `DB_NAME`, `DB_USER` and
	 * `DB_PASSWORD` database credentials specified in wp-config.php.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : The name of the SQL file to export. If '-', then outputs to STDOUT. If
	 * omitted, it will be '{dbname}-{Y-m-d}-{random-hash}.sql'.
	 *
	 * [--dbuser=<value>]
	 * : Username to pass to mysqldump. Defaults to DB_USER.
	 *
	 * [--dbpass=<value>]
	 * : Password to pass to mysqldump. Defaults to DB_PASSWORD.
	 *
	 * [--<field>=<value>]
	 * : Extra arguments to pass to mysqldump.
	 *
	 * [--tables=<tables>]
	 * : The comma separated list of specific tables to export. Excluding this parameter will export all tables in the database.
	 *
	 * [--exclude_tables=<tables>]
	 * : The comma separated list of specific tables that should be skipped from exporting. Excluding this parameter will export all tables in the database.
	 *
	 * [--porcelain]
	 * : Output filename for the exported database.
	 *
	 * ## EXAMPLES
	 *
	 *     # Export database with drop query included
	 *     $ wp db export --add-drop-table
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Export certain tables
	 *     $ wp db export --tables=wp_options,wp_users
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Export all tables matching a wildcard
	 *     $ wp db export --tables=$(wp db tables 'wp_user*' --format=csv)
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Export all tables matching prefix
	 *     $ wp db export --tables=$(wp db tables --all-tables-with-prefix --format=csv)
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Export certain posts without create table statements
	 *     $ wp db export --no-create-info=true --tables=wp_posts --where="ID in (100,101,102)"
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Export relating meta for certain posts without create table statements
	 *     $ wp db export --no-create-info=true --tables=wp_postmeta --where="post_id in (100,101,102)"
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Skip certain tables from the exported database
	 *     $ wp db export --exclude_tables=wp_options,wp_users
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Skip all tables matching a wildcard from the exported database
	 *     $ wp db export --exclude_tables=$(wp db tables 'wp_user*' --format=csv)
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Skip all tables matching prefix from the exported database
	 *     $ wp db export --exclude_tables=$(wp db tables --all-tables-with-prefix --format=csv)
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Export database to STDOUT.
	 *     $ wp db export -
	 *     -- MySQL dump 10.13  Distrib 5.7.19, for osx10.12 (x86_64)
	 *     --
	 *     -- Host: localhost    Database: wpdev
	 *     -- ------------------------------------------------------
	 *     -- Server version    5.7.19
	 *     ...
	 *
	 * @alias dump
	 */
	public function export( $args, $assoc_args ) {

		global $wpdb;

		if ( ! empty( $args[0] ) ) {
			$result_file = $args[0];
		} else {
			$hash        = substr( md5( wp_rand( PHP_INT_MIN, PHP_INT_MAX ) ), 0, 7 );
			$result_file = sprintf( 'WPLM-%s-%s-%s.zip', DB_NAME, date( 'Y-m-d' ), $hash );
		}

		self::cleanup(); // early cleanup, to cleanup unfinished exports.

		self::$current_assoc_args = $assoc_args;

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

		$db_only = WP_CLI\Utils\get_flag_value( $assoc_args, 'db-only', false );
		if ( $db_only ) {
			self::maybe_zip_file( $db_file, $result_file );
		} else {
			self::maybe_zip_folder( ABSPATH, $result_file );
		}

		self::cleanup();

		WP_CLI::success( sprintf( "Exported to '%s'. Export size: %s", $result_file, size_format( filesize( $result_file ) ) ) );
	}

	public static function get_limit_for_tag( $tag, $default = null ) {
		$limit = WP_CLI\Utils\get_flag_value( self::$current_assoc_args, 'limit-' . $tag, false );
		if( false !== $limit ) {
			return intval( $limit );
		}
		$limit = apply_filters( 'wp_local_maker_limit_' . $tag , $default );
		return intval( $limit );
	}

	protected static function get_db_file_path() {
		$upload_dir = wp_get_upload_dir();
		$path       = untrailingslashit( $upload_dir['basedir'] );
		return $path . '/database.sql';
	}

	protected static function get_temp_filename( $filename = null ) {
		if ( $filename ) {
			return trailingslashit( get_temp_dir() ) . $filename;
		} else {
			return wp_tempnam( 'backup_export' );
		}
	}

	public static function dump_structure() {
		$command          = '/usr/bin/env mysqldump --no-defaults %s --single-transaction --quick';
		$command_esc_args = array( DB_NAME );

		$command .= ' --no-data';

		$escaped_command = call_user_func_array( '\WP_CLI\Utils\esc_cmd', array_merge( array( $command ), $command_esc_args ) );

		$first_pass = self::get_temp_filename();

		self::run(
			$escaped_command,
			array(
				'result-file' => $first_pass,
			)
		);

		$first_pass = self::adjust_structure( $first_pass );

		return $first_pass;
	}

	public static function adjust_structure( $file ) {
		$lines       = [];
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
		$command          = '/usr/bin/env mysqldump --no-defaults %s --single-transaction --quick';
		$command_esc_args = array( DB_NAME );

		$command .= ' --no-create-info';

		$command           .= ' --tables';
		$command           .= ' %s';
		$command_esc_args[] = $table;

		$escaped_command = call_user_func_array( '\WP_CLI\Utils\esc_cmd', array_merge( array( $command ), $command_esc_args ) );

		if ( is_null( $this_table_file ) ) {
			$this_table_file = self::get_temp_filename();
		}

		@unlink( $this_table_file );

		global $wpdb;

		self::run(
			$escaped_command,
			array(
				'result-file' => $this_table_file,
			)
		);

		return $this_table_file;
	}

	public static function get_tables_info() {
		global $wpdb;
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

	public static function get_table_name( $table, $key = 'curr' ) {
		global $wpdb;

		$dbname          = DB_NAME;
		$sql             = "SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE' AND `TABLES_IN_{$dbname}` REGEXP '^({$wpdb->base_prefix}(?:([0-9]*)_)?)?{$table}$'";
		$table_name_full = $wpdb->get_var( $sql );

		if ( is_null( $table_name_full ) ) {
			$table_name_full = $table;
		}

		list($internal_key, $blog_id, $prefixed) = self::get_table_internal_info( $table_name_full );
		$table                                   = $internal_key;

		if ( $prefixed ) {
			if ( in_array( $table, self::global_tables(), true ) ) {
				$table = $wpdb->base_prefix . $table;
			} else {
				$table = $wpdb->prefix . $table;
			}
		}

		if ( 'temp' === $key ) {
			return '_WPLM_' . wp_hash( $table, 'nonce' );
		} else {
			return $table;
		}
	}

	public static function get_tables_names() {
		$tables_info = self::get_tables_info();
		foreach ( $tables_info as $table => $info ) {
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

		$file = self::dump_data_from_table( $table, $table_file );

		if ( $replace_name ) {
			$file = self::adjust_file( $file, "`{$table}`", "`{$replace_name}`" );
		}

		WP_CLI::line( sprintf( 'Exported %d rows from %s. Export size: %s', $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), $replace_name ? $replace_name : $table, size_format( filesize( $file ) ) ) );

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
		global $wpdb;
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

			list($internal_key, $blog_id, $prefixed) = self::get_table_internal_info( $table );

			if ( ! isset( $tables_info[ $internal_key ] ) ) {
				$current = $table;
				$temp    = self::get_table_name( $current, 'temp' );

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
			foreach ( $process_queue as $blog_id => $queue ) {
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
		$lines       = [];
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
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
		self::$new_domain = self::$old_domain = false; // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments
		self::$current_assoc_args = array();
		@unlink( self::get_db_file_path() );
	}

	protected static function maybe_zip_file( $file, $zip_fn ) {
		// Initialize archive object
		$zip = new ZipArchive();
		$zip->open( $zip_fn, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFile( $file, basename( $file ) );
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

		/* if( $total_size > 50 * MB_IN_BYTES ) {
			return new WP_Error("too_large", "Folder is too large to compress");
		}*/

		// $object_data = $this->parse_addon_data( $object['info'] );
		// $zip->setArchiveComment( $object_data );

		// Zip archive will be created only after closing object
		$zip->close();

		echo "\n";

		WP_LMaker_Dir_Crawler::reset();

		@unlink( $target_wp_conf );

		return $zip_fn;
	}

	private static function run( $cmd, $assoc_args = array(), $descriptors = null ) {
		$required = array(
			'host' => DB_HOST,
			'user' => DB_USER,
			'pass' => DB_PASSWORD,
		);

		if ( ! isset( $assoc_args['default-character-set'] ) && defined( 'DB_CHARSET' ) && constant( 'DB_CHARSET' ) ) {
			$required['default-character-set'] = constant( 'DB_CHARSET' );
		}

		// Using 'dbuser' as option name to workaround clash with WP-CLI's global WP 'user' parameter, with 'dbpass' also available for tidyness.
		if ( isset( $assoc_args['dbuser'] ) ) {
			$required['user'] = $assoc_args['dbuser'];
			unset( $assoc_args['dbuser'] );
		}
		if ( isset( $assoc_args['dbpass'] ) ) {
			$required['pass'] = $assoc_args['dbpass'];
			unset( $assoc_args['dbpass'], $assoc_args['password'] );
		}

		$final_args = array_merge( $assoc_args, $required );
		Utils\run_mysql_command( $cmd, $final_args, $descriptors );
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
