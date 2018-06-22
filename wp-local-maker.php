<?php
 /**
  * Plugin Name: WP Local Maker
  * Plugin URI: https://www.saucal.com
  * Description: WP CLI Exports with reduced datasets
  * Version: 1.0.0
  * Author: SAU/CAL
  * Author URI: https://www.saucal.com
  */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

use WP_CLI\Formatter;
use \WP_CLI\Utils;

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
			$hash = substr( md5( mt_rand() ), 0, 7 );
			$result_file = sprintf( '%s-%s-%s.sql', DB_NAME, date( 'Y-m-d' ), $hash );;
		}
		$stdout = ( '-' === $result_file );

		/*$tables_to_treat_separately = array(
			$wpdb->posts, 
			$wpdb->postmeta, 
			$wpdb->comments, 
			$wpdb->commentmeta, 
			'fakenames'
		);

		foreach ( $tables_to_treat_separately as $table ) {
			$command .= ' --ignore-table';
			$command .= ' %s';
			$command_esc_args[] = trim( DB_NAME . '.' . $table );
		}*/

		$files = array();
		$files[] = self::dump_structure();

		$files = array_merge( $files, self::dump_data() );

		self::join_files( $files, $result_file );

		self::cleanup();

		if ( ! $stdout ) {
			WP_CLI::success( sprintf( "Exported to '%s'.", $result_file ) );
		}
	}

	protected static function get_temp_filename() {
		return tempnam(sys_get_temp_dir(), 'backup_export');
	}

	protected static function dump_structure() {
		$command = '/usr/bin/env mysqldump --no-defaults %s --single-transaction --quick';
		$command_esc_args = array( DB_NAME );

		$command .= ' --no-data';

		$escaped_command = call_user_func_array( '\WP_CLI\Utils\esc_cmd', array_merge( array( $command ), $command_esc_args ) );

		$first_pass = self::get_temp_filename();

		self::run( $escaped_command, array(
			'result-file' => $first_pass,
		) );

		return $first_pass;
	}

	protected static function dump_data_from_table($table) {
		$command = '/usr/bin/env mysqldump --no-defaults %s --single-transaction --quick';
		$command_esc_args = array( DB_NAME );

		$command .= ' --no-create-info';

		$command .= ' --tables';
		$command .= ' %s';
		$command_esc_args[] = $table;

		$escaped_command = call_user_func_array( '\WP_CLI\Utils\esc_cmd', array_merge( array( $command ), $command_esc_args ) );
		
		$this_table_file = self::get_temp_filename();

		global $wpdb;

		self::run( $escaped_command, array(
			'result-file' => $this_table_file,
		) );

		WP_CLI::line( sprintf( 'Exported %d rows from %s. Export size: %s', $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), $table, size_format( filesize( $this_table_file ) ) ) );

		return $this_table_file;
	}

	protected static function get_tables_info() {
		global $wpdb;
		if(isset(self::$tables_info)) {
			return self::$tables_info;
		}

		$tables_to_custom_process = array(
			// Posts related
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->comments,
			$wpdb->commentmeta,
			// Order related
			$wpdb->prefix . 'woocommerce_order_items',
			$wpdb->prefix . 'woocommerce_order_itemmeta',
			$wpdb->prefix . 'woocommerce_downloadable_product_permissions',
			// User related
			$wpdb->users,
			$wpdb->usermeta,
			$wpdb->prefix . 'woocommerce_payment_tokens',
			$wpdb->prefix . 'woocommerce_payment_tokenmeta',
			// Term related
			$wpdb->term_relationships,
			$wpdb->term_taxonomy,
			$wpdb->terms,
			$wpdb->termmeta,
			// EWWIO
			$wpdb->prefix . 'ewwwio_images',
			// Core related
			$wpdb->options,
		);

		$new = array();
		foreach( $tables_to_custom_process as $key => $table ) {
			$new[$table] = array(
				'prio' => $key,
				'tempname' => $table . '_' . wp_generate_password( 6, false ),
				'ignore' => false,
			);
		}

		$excluded_tables = apply_filters( 'wp_local_maker_excluded_tables', array() );

		foreach( $excluded_tables as $table ) {
			$key++;
			$new[$table] = array(
				'prio' => $key,
				'tempname' => $table . '_' . wp_generate_password( 6, false ),
				'ignore' => true,
			);
		}

		self::$tables_info = $new;
		return self::$tables_info;
	}

	protected static function dump_data() {
		global $wpdb;
		$files = array();
		$tables = $wpdb->get_col("SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE'");

		$tables_info = self::get_tables_info();

		$process_queue = array();

		foreach($tables as $table) {
			if( isset( $tables_info[ $table ] ) ) {
				$tbl_info = $tables_info[ $table ];
				if( $tbl_info['ignore'] ) {
					continue;
				}

				$process_queue[ $tables_info[ $table ][ 'prio' ] ] = $table;
			} else {
				$files[] = self::dump_data_from_table( $table );
			}
		}

		ksort( $process_queue, SORT_NUMERIC );

		foreach( $process_queue as $i => $table ) {
			$real_name = substr($table, strlen( $wpdb->prefix ) );
			if( is_callable( array( __CLASS__, 'process_' . $real_name ) ) ) {
				$files[] = call_user_func( array( __CLASS__, 'process_' . $real_name ) );
				unset($process_queue[$i]);
			}
		} 

		if( ! empty( $process_queue ) ) {
			WP_CLI::warning( sprintf( "Unfinished tables %s.", implode( ', ', $process_queue ) ) );
		}

		return $files;
	}

	protected static function process_posts() {
		global $wpdb;
		$tables_info = self::get_tables_info();
		$temp = $tables_info[ $wpdb->posts ][ 'tempname' ];

		$wpdb->query("CREATE TABLE {$temp} LIKE {$wpdb->posts}");

		// Export everything but a few known post types
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$wpdb->posts} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type NOT IN ( 'post', 'attachment', 'shop_order', 'shop_order_refund', 'product', 'revision', 'shop_subscription', 'scheduled-action', 'wc_user_membership', 'shop_coupon' )");

		// Handle posts
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$wpdb->posts} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'post' )
			ORDER BY p.post_date DESC
			LIMIT 50");

		// Handle orders
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$wpdb->posts} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'shop_order' )
			ORDER BY p.post_date DESC
			LIMIT 50");

        // Handle subscriptions
        $wpdb->query("REPLACE INTO {$temp}
            SELECT * FROM {$wpdb->posts} p
            WHERE p.post_status NOT IN ('auto-draft', 'trash')
            AND p.post_type IN ( 'shop_subscription' )
            ORDER BY p.post_date DESC
            LIMIT 50");

        // Handle subscriptions related orders
        $wpdb->query("REPLACE INTO {$temp}
            SELECT p.* FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND ( pm.meta_key = '_subscription_switch' OR pm.meta_key = '_subscription_renewal' OR pm.meta_key = 'subscription_resubscribe' )
            WHERE pm.meta_value IN ( SELECT ID FROM {$temp} p2 WHERE p2.post_type = 'shop_subscription' )");

        // Handle subscriptions related actions
        $wpdb->query("REPLACE INTO {$temp}
            SELECT * FROM {$wpdb->posts} 
            WHERE post_type = 'scheduled-action' AND post_content IN ( SELECT CONCAT('{\"subscription_id\":', ID, '}') FROM {$temp} p2 WHERE p2.post_type = 'shop_subscription' )");

        // Handle memberships
        $wpdb->query("REPLACE INTO {$temp}
            SELECT * FROM {$wpdb->posts} p
            WHERE p.post_status NOT IN ('auto-draft', 'trash')
            AND p.post_type IN ( 'wc_user_membership' )
            ORDER BY p.post_date DESC
            LIMIT 50");

        // Handle subscriptions related memberships
        $wpdb->query("REPLACE INTO {$temp}
            SELECT p.* FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_subscription_id'
            WHERE p.post_type IN ( 'wc_user_membership' ) AND pm.meta_value IN ( SELECT ID FROM {$temp} p2 WHERE p2.post_type = 'shop_subscription' )");

        // Handle memberships related actions
        $wpdb->query("REPLACE INTO {$temp}
            SELECT * FROM {$wpdb->posts} 
            WHERE post_type = 'scheduled-action' AND post_content IN ( SELECT CONCAT('{\"user_membership_id\":', ID, '}') FROM {$temp} p2 WHERE p2.post_type = 'wc_user_membership' )");

		// Handle refunds
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$wpdb->posts} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'shop_order_refund' ) AND p.post_parent IN ( SELECT ID FROM {$temp} p2 )");

		// Handle coupons (only copy used)
		$wpdb->query("CREATE TEMPORARY TABLE wp_list_temp 
			SELECT oi.order_item_name FROM {$wpdb->prefix}woocommerce_order_items oi
			WHERE oi.order_id IN ( SELECT ID FROM {$temp} ) AND oi.order_item_type = 'coupon'
			GROUP BY oi.order_item_name");

		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$wpdb->posts} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'shop_coupon' ) AND post_title IN ( SELECT * FROM wp_list_temp )");

		$wpdb->query("DROP TABLE wp_list_temp");

		// Handle products
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$wpdb->posts} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'product' )");

		// Handle attachments
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$wpdb->posts} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'attachment' ) AND p.post_parent IN ( SELECT ID FROM {$temp} p2 )");

		// Handle unrelated attachments
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$wpdb->posts} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'attachment' ) AND p.post_parent = 0
            ORDER BY p.post_date DESC
			LIMIT 500");

        // Loop until there's no missing parents
        do {
            $affected = $wpdb->query("REPLACE INTO {$temp}
                SELECT p3.* FROM {$temp} p
                LEFT JOIN {$temp} p2 ON p2.ID = p.post_parent
                LEFT JOIN {$wpdb->posts} p3 ON p3.ID = p.post_parent
                WHERE p.post_parent != 0 AND p2.ID IS NULL AND p3.ID IS NOT NULL");
        } while( $affected > 0 );

		$file = self::dump_data_from_table( $temp );

		$file = self::adjust_file( $file, "`{$temp}`", "`{$wpdb->posts}`" );

		return $file;
	}

	protected static function dependant_table_dump( $current, $after = '' ) {
		global $wpdb;
		$tables_info = self::get_tables_info();
		$temp = $tables_info[ $current ][ 'tempname' ];

		$wpdb->query("CREATE TABLE {$temp} LIKE {$current}");

		$query = "REPLACE INTO {$temp} SELECT * FROM {$current} p";
		if( $after ) {
			$query .= " " . $after;
		}

		$wpdb->query($query);

		$file = self::dump_data_from_table( $temp );

		$file = self::adjust_file( $file, "`{$temp}`", "`{$current}`" );

		return $file;
	}

	protected static function get_table_keys_group( $table, $prefix = '' ) {
		$keys = self::get_columns( $table )[0];
		if( $prefix ) {
			foreach($keys as $i => $key) {
				$keys[$i] = $prefix.'.'.$key;
			}
		}

		return implode(', ', $keys);
	}

	protected static function dependant_table_dump_single( $current, $dependant, $current_key, $dependant_key ) {
		global $wpdb;
		$tables_info = self::get_tables_info();
		$temp_posts = $tables_info[ $dependant ][ 'tempname' ];
		return self::dependant_table_dump($current, "WHERE p.{$current_key} IN ( SELECT {$dependant_key} FROM {$temp_posts} p2 GROUP BY {$dependant_key} )");
	}

	protected static function process_postmeta() {
		global $wpdb;
		return self::dependant_table_dump_single($wpdb->postmeta, $wpdb->posts, 'post_id', 'ID');
	}

	protected static function process_comments() {
		global $wpdb;
		return self::dependant_table_dump_single($wpdb->comments, $wpdb->posts, 'comment_post_ID', 'ID');
	}

	protected static function process_commentmeta() {
		global $wpdb;
		return self::dependant_table_dump_single($wpdb->commentmeta, $wpdb->comments, 'comment_id', 'comment_ID');
	}

	protected static function process_woocommerce_order_items() {
		global $wpdb;
		return self::dependant_table_dump_single($wpdb->prefix . 'woocommerce_order_items', $wpdb->posts, 'order_id', 'ID');
	}

	protected static function process_woocommerce_order_itemmeta() {
		global $wpdb;
		return self::dependant_table_dump_single($wpdb->prefix . 'woocommerce_order_itemmeta', $wpdb->prefix . 'woocommerce_order_items', 'order_item_id', 'order_item_id');
	}

	protected static function process_woocommerce_downloadable_product_permissions() {
		global $wpdb;
		return self::dependant_table_dump_single($wpdb->prefix . 'woocommerce_downloadable_product_permissions', $wpdb->posts, 'order_id', 'ID');
	}

	protected static function process_users() {
		global $wpdb;
		$tables_info = self::get_tables_info();
		$current = $wpdb->users;
		$temp = $tables_info[ $current ][ 'tempname' ];

		$wpdb->query("CREATE TABLE {$temp} LIKE {$current}");

		// Export administrators
		$wpdb->query("REPLACE INTO {$temp}
			SELECT u.* FROM {$current} u 
			INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = 'wp_capabilities'
			WHERE um.meta_value LIKE '%\"administrator\"%'");

		$temp_posts = $tables_info[ $wpdb->posts ][ 'tempname' ];

		$user_keys = self::get_table_keys_group( $current, 'u' );

		// Export authors
		$wpdb->query("REPLACE INTO {$temp}
			SELECT u.* FROM {$current} u 
			INNER JOIN {$temp_posts} p ON p.post_author = u.ID
			GROUP BY {$user_keys}");


		$temp_postmeta = $tables_info[ $wpdb->postmeta ][ 'tempname' ];

		// Export customers
		$wpdb->query("REPLACE INTO {$temp}
			SELECT u.* FROM {$temp_posts} p 
			INNER JOIN {$temp_postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_customer_user'
			INNER JOIN {$current} u ON u.ID = pm.meta_value
			GROUP BY {$user_keys}");        

		$file = self::dump_data_from_table( $temp );

		$file = self::adjust_file( $file, "`{$temp}`", "`{$current}`" );

		return $file;
	}

	protected static function process_usermeta() {
		global $wpdb;
		return self::dependant_table_dump_single($wpdb->usermeta, $wpdb->users, 'user_id', 'ID');
	}

	protected static function process_woocommerce_payment_tokens() {
		global $wpdb;
		return self::dependant_table_dump_single($wpdb->prefix . 'woocommerce_payment_tokens', $wpdb->users, 'user_id', 'ID');
	}

	protected static function process_woocommerce_payment_tokenmeta() {
		global $wpdb;
		return self::dependant_table_dump_single($wpdb->prefix . 'woocommerce_payment_tokenmeta', $wpdb->prefix . 'woocommerce_payment_tokens', 'payment_token_id', 'token_id');
	}

	protected static function process_term_relationships() {
		global $wpdb;
		$tables_info = self::get_tables_info();
		$current = $wpdb->term_relationships;
		$temp = $tables_info[ $current ][ 'tempname' ];

		$wpdb->query("CREATE TABLE {$temp} LIKE {$current}");

		$temp_posts = $tables_info[ $wpdb->posts ][ 'tempname' ];

		$tr_keys = self::get_table_keys_group( $current, 'tr' );

		// Export post terms
		$wpdb->query("REPLACE INTO {$temp}
			SELECT tr.* FROM {$current} tr
			INNER JOIN {$temp_posts} p ON tr.object_id = p.ID
			GROUP BY {$tr_keys}");

		$temp_users = $tables_info[ $wpdb->users ][ 'tempname' ];

		// Export potential author terms
		$wpdb->query("REPLACE INTO {$temp}
			SELECT tr.* FROM {$current} tr
			INNER JOIN {$temp_users} u ON tr.object_id = u.ID
			GROUP BY {$tr_keys}");

		$file = self::dump_data_from_table( $temp );

		$file = self::adjust_file( $file, "`{$temp}`", "`{$current}`" );

		return $file;
	}

	protected static function process_term_taxonomy() {
		global $wpdb;
		return self::dependant_table_dump_single($wpdb->term_taxonomy, $wpdb->term_relationships, 'term_taxonomy_id', 'term_taxonomy_id');
	}

	protected static function process_terms() {
		global $wpdb;
		return self::dependant_table_dump_single($wpdb->terms, $wpdb->term_taxonomy, 'term_id', 'term_id');
	}

	protected static function process_termmeta() {
		global $wpdb;
		return self::dependant_table_dump_single($wpdb->termmeta, $wpdb->terms, 'term_id', 'term_id');
	}

	protected static function process_ewwwio_images() {
		global $wpdb;
		return self::dependant_table_dump_single($wpdb->prefix . 'ewwwio_images', $wpdb->posts, 'id', 'ID');
	}

	protected static function process_options() {
		global $wpdb;
		$tables_info = self::get_tables_info();
		$current = $wpdb->options;
		$temp = $tables_info[ $current ][ 'tempname' ];

		$wpdb->query("CREATE TABLE {$temp} LIKE {$current}");

		// Exclude transients
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$current}
			WHERE option_name NOT LIKE '\_transient%' && option_name NOT LIKE '\_site\_transient%'");

		$file = self::dump_data_from_table( $temp );

		$file = self::adjust_file( $file, "`{$temp}`", "`{$current}`" );

		return $file;
	}

	protected static function adjust_file( $file, $find, $replace ) {
		$lines = [];
		$source = fopen($file, "r");
		$target_name = self::get_temp_filename();
		$target = fopen($target_name, 'w');

		while(!feof($source)) {
			$str = str_replace($find, $replace, fgets($source));
			fputs($target, $str);
		}

		fclose($source);
		@unlink($file);
		fclose($target);
		return $target_name;
	}

	protected static function join_files($files, $result_file) {
		@unlink($result_file);
		$target = fopen($result_file, "w");

		foreach($files as $file) {
			$source = fopen($file, "r");
			stream_copy_to_stream($source, $target);
			fclose($source);
			@unlink($file);
		}

		fclose($target);
	}

	protected static function cleanup() {
		global $wpdb;
		$tables_info = self::get_tables_info();
		foreach($tables_info as $table => $info) {
			$temp = $info['tempname'];
			$wpdb->query("DROP TABLE IF EXISTS {$temp}");
		}
	}

	/**
	 * Imports a database from a file or from STDIN.
	 *
	 * Runs SQL queries using `DB_HOST`, `DB_NAME`, `DB_USER` and
	 * `DB_PASSWORD` database credentials specified in wp-config.php. This
	 * does not create database by itself and only performs whatever tasks are
	 * defined in the SQL.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : The name of the SQL file to import. If '-', then reads from STDIN. If omitted, it will look for '{dbname}.sql'.
	 *
	 * [--dbuser=<value>]
	 * : Username to pass to mysql. Defaults to DB_USER.
	 *
	 * [--dbpass=<value>]
	 * : Password to pass to mysql. Defaults to DB_PASSWORD.
	 *
	 * [--skip-optimization]
	 * : When using an SQL file, do not include speed optimization such as disabling auto-commit and key checks.
	 *
	 * ## EXAMPLES
	 *
	 *     # Import MySQL from a file.
	 *     $ wp db import wordpress_dbase.sql
	 *     Success: Imported from 'wordpress_dbase.sql'.
	 */
	public function import( $args, $assoc_args ) {
		if ( ! empty( $args[0] ) ) {
			$result_file = $args[0];
		} else {
			$result_file = sprintf( '%s.sql', DB_NAME );
		}

		$mysql_args = array(
			'database' => DB_NAME,
		);
		$mysql_args = array_merge( self::get_dbuser_dbpass_args( $assoc_args ), $mysql_args );

		if ( '-' !== $result_file ) {
			if ( ! is_readable( $result_file ) ) {
				WP_CLI::error( sprintf( 'Import file missing or not readable: %s', $result_file ) );
			}

			$query = \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-optimization' )
				? 'SOURCE %s;'
				: 'SET autocommit = 0; SET unique_checks = 0; SET foreign_key_checks = 0; SOURCE %s; COMMIT;';

			$mysql_args['execute'] = sprintf( $query, $result_file );
		}

		self::run( '/usr/bin/env mysql --no-defaults --no-auto-rehash', $mysql_args );

		WP_CLI::success( sprintf( "Imported from '%s'.", $result_file ) );
	}

	private static function run( $cmd, $assoc_args = array(), $descriptors = null ) {
		$required = array(
			'host' => DB_HOST,
			'user' => DB_USER,
			'pass' => DB_PASSWORD,
		);

		if ( ! isset( $assoc_args['default-character-set'] )
			&& defined( 'DB_CHARSET' ) && constant( 'DB_CHARSET' ) ) {
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
	 * Helper to pluck 'dbuser' and 'dbpass' from associative args array.
	 *
	 * @param array $assoc_args Associative args array.
	 * @return array Array with `dbuser' and 'dbpass' set if in passed-in associative args array.
	 */
	private static function get_dbuser_dbpass_args( $assoc_args ) {
		$mysql_args = array();
		if ( null !== ( $dbuser = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dbuser' ) ) ) {
			$mysql_args['dbuser'] = $dbuser;
		}
		if ( null !== ( $dbpass = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dbpass' ) ) ) {
			$mysql_args['dbpass'] = $dbpass;
		}
		return $mysql_args;
	}

	/**
	 * Gets the column names of a db table differentiated into key columns and text columns and all columns.
	 *
	 * @param string $table The table name.
	 * @return array A 3 element array consisting of an array of primary key column names, an array of text column names, and an array containing all column names.
	 */
	private static function get_columns( $table ) {
		global $wpdb;

		$table_sql = self::esc_sql_ident( $table );
		$primary_keys = $text_columns = $all_columns = array();
		$suppress_errors = $wpdb->suppress_errors();
		if ( ( $results = $wpdb->get_results( "DESCRIBE $table_sql" ) ) ) {
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
	}

	/**
	 * Determines whether a column is considered text or not.
	 *
	 * @param string Column type.
	 * @bool True if text column, false otherwise.
	 */
	private static function is_text_col( $type ) {
		foreach ( array( 'text', 'varchar' ) as $token ) {
			if ( false !== strpos( $type, $token ) )
				return true;
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

WP_CLI::add_command( 'backup', 'Backup_Command' );

class WP_LMaker_Addon {
	function __construct() {
		add_filter( 'wp_local_maker_excluded_tables', array( $this, 'excluded_tables' ) );
	}
	function excluded_tables( $tables ) {
		return $tables;
	}
}

class WP_LMaker_Gravity_Forms extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = $wpdb->prefix . 'gf_entry';
		$tables[] = $wpdb->prefix . 'gf_entry_meta';
		$tables[] = $wpdb->prefix . 'gf_entry_notes';
		$tables[] = $wpdb->prefix . 'gf_form_view';
		$tables[] = $wpdb->prefix . 'rg_lead';
		$tables[] = $wpdb->prefix . 'rg_lead_detail';
		$tables[] = $wpdb->prefix . 'rg_lead_detail_long';
		$tables[] = $wpdb->prefix . 'rg_lead_meta';
		$tables[] = $wpdb->prefix . 'rg_lead_notes';
		$tables[] = $wpdb->prefix . 'rg_form_view';
		$tables[] = $wpdb->prefix . 'rg_incomplete_submissions';
		return $tables;
	}
}
new WP_LMaker_Gravity_Forms();

class WP_LMaker_WooCommerce extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = $wpdb->prefix . 'wc_download_log';
		$tables[] = $wpdb->prefix . 'woocommerce_sessions';
		$tables[] = $wpdb->prefix . 'woocommerce_log';
		return $tables;
	}
}
new WP_LMaker_WooCommerce();

class WP_LMaker_Redirection extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = $wpdb->prefix . 'redirection_logs';
		$tables[] = $wpdb->prefix . 'redirection_404';
		return $tables;
	}
}
new WP_LMaker_Redirection();

class WP_LMaker_SCH_Smart_Transients extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = $wpdb->prefix . 'sch_smart_transients';
		return $tables;
	}
}
new WP_LMaker_SCH_Smart_Transients();

class WP_LMaker_Affiliate_WP extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = $wpdb->prefix . 'affiliate_wp_visits';
		return $tables;
	}
}
new WP_LMaker_Affiliate_WP();

class WP_LMaker_Abandoned_Carts_Pro extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = $wpdb->prefix . 'ac_abandoned_cart_history';
		$tables[] = $wpdb->prefix . 'ac_guest_abandoned_cart_history';
		return $tables;
	}
}
new WP_LMaker_Abandoned_Carts_Pro();

class WP_LMaker_Order_Generator extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'fakenames';
		return $tables;
	}
}
new WP_LMaker_Order_Generator();
