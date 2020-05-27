<?php
use WP_CLI\Formatter;
use WP_CLI\Utils;
class WP_LMaker_MySQLDump {
	public function __construct() {
		
	}

	public function run( $db, $target_file, $settings = array() ) {
		$this->run_cmd( $db, $target_file, $settings );
	}

	protected function run_cmd( $db, $target_file, $settings = array() ) {
		$command          = '/usr/bin/env mysqldump --no-defaults %s --single-transaction --quick';
		$command_esc_args = array( $db );

		$cmd = call_user_func_array( '\WP_CLI\Utils\esc_cmd', array_merge( array( $command ), $command_esc_args ) );

		$tables = false;
		if ( isset( $settings['tables'] ) ) {
			$tables = $settings['tables'];
			unset( $settings['tables'] );
			if ( is_string( $tables ) ) {
				$tables = array( $tables );
			}
		}

		$cmd .= Utils\assoc_args_to_str( $settings );

		$assoc_args = array(
			'result-file' => $target_file,
		);

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

		if ( is_array( $tables ) ) {
			$cmd .= ' --tables ' . implode( ' ', array_map( 'escapeshellarg', $tables ) );
		}

		$final_args = array_merge( $assoc_args, $required );
		Utils\run_mysql_command( $cmd, $final_args );
	}
}
