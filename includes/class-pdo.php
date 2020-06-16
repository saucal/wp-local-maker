<?php
require_once __DIR__ . '/class-pdostatement.php';
class PDO {
	const ATTR_PERSISTENT               = 12;
	const ATTR_ERRMODE                  = 3;
	const ERRMODE_EXCEPTION             = 2;
	const MYSQL_ATTR_USE_BUFFERED_QUERY = 1000; // phpcs:ignore
	const ATTR_SERVER_VERSION           = 4;
	const ATTR_ORACLE_NULLS             = 11;
	const NULL_NATURAL                  = 0;
	const FETCH_ASSOC                   = 2;
	const FETCH_NUM                     = 3; // TODO: Not checked
	const FETCH_OBJ                     = 4; // TODO: Not checked

	public function __construct() {
		// TODO: Parse connection string if needed
	}

	public function query( $sql ) {
		return new PDOStatement( $sql ); // phpcs:ignore
	}

	public function exec( $query ) {
		global $wpdb;
		return (int) $wpdb->query( $query );
	}

	public function getAttribute( $attr ) { // phpcs:ignore
		global $wpdb;
		switch ( $attr ) {
			case self::ATTR_SERVER_VERSION:
				return $wpdb->get_var( 'SELECT VERSION()' );
			default:
				return self::handle_unimplemented( __METHOD__ ); // TODO: Implement
		}
	}

	public function setAttribute( $attr, $value ) { // phpcs:ignore
		switch ( $attr ) {
			case self::ATTR_ORACLE_NULLS:
				return true; // TODO: Check
			default:
				return self::handle_unimplemented( __METHOD__ ); // TODO: Implement
		}
	}

	public function quote( $string ) {
		global $wpdb;
		return $wpdb->prepare( '%s', $string );
	}

	public function __call( $name, $arguments ) {
		return self::handle_unimplemented( $name );
	}

	public static function __callStatic( $name, $arguments ) {
		return self::handle_unimplemented( $name );
	}

	public static function handle_unimplemented( $method ) {
		// phpcs:ignore
		trigger_error( sprintf( 'PDO WP Polyfill is not fully implemented yet. Method *%s* not available.', esc_html( $method ) ), E_USER_ERROR );
		return false;
	}
}
