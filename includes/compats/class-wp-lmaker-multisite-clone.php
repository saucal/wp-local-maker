<?php
/**
 * WP_LMaker_Multisite_Clone
 */
class WP_LMaker_Multisite_Clone extends WP_LMaker_Abstract_Addon {

	/**
	 * Original Blog ID
	 *
	 * @var int Original Blog ID
	 */
	private $cloned_blog_id;

	/**
	 * Original blog DB tables prefix
	 *
	 * @var string Original blog DB tables prefix
	 */
	private $cloned_blog_table_perfix;

	/**
	 * Destination blog ID
	 *
	 * @var int Destination blog ID
	 */
	private $new_blog_id;

	/**
	 * Destination blog DB tables prefix
	 *
	 * @var string Destination blog DB tables prefix
	 */
	private $new_blog_table_perfix;

	/**
	 * DB tables to clone.
	 *
	 * @var array DB tables to clone.
	 */
	private $blog_tables;

	/**
	 * Main constructir
	 *
	 * @param array $config Config.
	 */
	public function __construct( $config = array() ) {
		add_filter( 'wp_local_maker_dump_settings', array( $this, 'set_export_tables' ), 10 );
		add_filter( 'wp_local_maker_dump_tables', array( $this, 'get_blog_tables' ), 10 );
		add_filter( 'wp_local_maker_dump_string', array( $this, 'replace_table_prefix' ), 10 );
		add_action( 'wp_local_maker_adjust_dump_data_file', array( $this, 'replace_table_prefix_in_file' ), 10, 1 );
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'clean_tables_callbacks' ), PHP_INT_MAX );
	}

	/**
	 * Export all data.
	 *
	 * @param array $tables Tables & callbacks.
	 *
	 * @return array
	 */
	public function clean_tables_callbacks( $tables ) {
		if ( 1 === Backup_Command::get_flag_value( 'full-db', 0 ) ) {
			return array();
		}

		return $tables;
	}

	/**
	 * Replace table name in file.
	 *
	 * @param string $file File name.
	 *
	 * @return void
	 */
	public function replace_table_prefix_in_file( $file ) {
		if ( ! $this->is_source_blog_id_isset() || ! $this->is_new_blog_id_isset() ) {
			return;
		}

		$source      = fopen( $file, 'r' );// phpcs:ignore
		$target_name = wp_tempnam( 'backup_export' );
		$target      = fopen( $target_name, 'w' );// phpcs:ignore

		while ( ! feof( $source ) ) {
			$str = fgets( $source );
			$str = $this->replace_table_prefix( $str );
			fputs( $target, $str ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions
		}

		fclose( $source );// phpcs:ignore
		if ( file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore
		}
		fclose( $target );
		rename( $target_name, $file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions
	}

	/**
	 * Replace table name from source blog to new blog.
	 *
	 * @param string $str Dump string.
	 *
	 * @return array|string|string[]
	 */
	public function replace_table_prefix( $str ) {
		if ( ! $this->is_source_blog_id_isset() || ! $this->is_new_blog_id_isset() ) {
			return $str;
		}

		if ( $this->is_source_blog_id_valid() ) {
			return $str;
		}

		return str_replace( '`' . $this->cloned_blog_table_perfix, '`' . $this->new_blog_table_perfix, $str );
	}

	/**
	 * Is source blog ID is set.
	 *
	 * @return bool
	 */
	public function is_source_blog_id_isset() {
		if ( is_null( $this->cloned_blog_id ) ) {
			$this->is_source_blog_id_valid();
			$this->cloned_blog_id = Backup_Command::get_flag_value( 'multisite-blog-clone-from', false );
		}

		return (bool) $this->cloned_blog_id;
	}

	/**
	 * Is source blog ID is valid.
	 *
	 * @return bool
	 */
	public function is_source_blog_id_valid() {
		if ( is_null( $this->cloned_blog_table_perfix ) ) {
			global $wpdb;
			$this->cloned_blog_table_perfix = $wpdb->get_blog_prefix( $this->cloned_blog_id );
		}

		return empty( $this->cloned_blog_table_perfix );
	}

	/**
	 * Is new blog ID is set.
	 *
	 * @return bool
	 */
	public function is_new_blog_id_isset() {
		if ( is_null( $this->new_blog_id ) ) {
			$this->new_blog_id           = Backup_Command::get_flag_value( 'multisite-blog-clone-to', false );
			$this->new_blog_table_perfix = $this->cloned_blog_table_perfix . $this->new_blog_id . '_';
		}

		return (bool) $this->new_blog_id;
	}


	/**
	 * Update export settings
	 *
	 * @param array $settings Dump settings.
	 *
	 * @return mixed
	 */
	public function set_export_tables( $settings ) {
		if ( ! $this->is_source_blog_id_isset() ) {
			return $settings;
		}

		$settings['tables'] = $this->generate_blog_tables();

		return $settings;
	}

	/**
	 * Return tables array to dump data.
	 *
	 * @param array $tables DB tables array.
	 *
	 * @return array|mixed
	 */
	public function get_blog_tables( $tables ) {
		if ( ! $this->is_source_blog_id_isset() ) {
			return $tables;
		}
		$tables = $this->generate_blog_tables( $tables );
		if ( Backup_Command::get_flag_value( 'multisite-skip-tables-data', false ) ) {

			$skip_tables = explode( ',', Backup_Command::get_flag_value( 'multisite-skip-tables-data', false ) );
			foreach ( $skip_tables as $table ) {
				$table = $this->cloned_blog_table_perfix . trim( $table );
				if ( ( $key = array_search( $table, $tables, true ) ) !== false ) {
					unset( $tables[ $key ] );
				}
			}
		}

		return $tables;
	}

	/**
	 * Export only tables for a specific blog ID.
	 *
	 * @param array $tables Blog tables.
	 *
	 * @return array|mixed
	 */
	private function generate_blog_tables( $tables = array() ) {
		if ( ! $this->is_source_blog_id_isset() ) {
			return $tables;
		}

		if ( ! empty( $this->blog_tables ) ) {
			return $this->blog_tables;
		}
		$blog_tables = array();

		global $wpdb;

		if ( $this->is_source_blog_id_valid() ) {
			return $tables;
		}
		$prefix = $this->cloned_blog_table_perfix;

		$tables          = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore
		$excluded_tables = array( 'wp_blog_versions', 'wp_blogs', 'wp_blogmeta', 'wp_site', 'wp_sitemeta' );

		foreach ( $tables as $table ) {
			if ( in_array( $table, $excluded_tables, true ) || substr( $table, 0, strlen( $prefix ) ) !== $prefix ) {
				continue;
			}

			if ( 1 === $this->cloned_blog_id && ! preg_match( '/' . $prefix . '[0-9]+_/', $table ) ) {
				$blog_tables[] = $table;
			} elseif ( 1 !== $this->cloned_blog_id && substr( $table, 0, strlen( $prefix ) ) === $prefix ) {
				$blog_tables[] = $table;
			}
		}

		$this->blog_tables = $blog_tables;

		return $blog_tables;
	}
}

new WP_LMaker_Multisite_Clone();
