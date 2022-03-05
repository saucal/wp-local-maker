<?php
class PDOStatement_WP implements Iterator {
	private $query          = '';
	private $query_clean    = '';
	private $page_size      = 1000;
	private $max_rows       = PHP_INT_MAX;
	private $initial_offset = 0;
	private $current_result = null;
	private $current_key    = 0;
	private $current_page   = 0;
	private $prev_keys      = 0;
	private $prev_offset    = 0;
	private $fetch_mode     = PDO_WP::FETCH_ASSOC; // phpcs:ignore
	private $prev_page_size = 0;

	public function __construct( $sql ) {
		$this->query       = trim( $sql, " \t\n\r\0\x0B;" );
		$this->is_select   = preg_match( '/^SELECT/i', $this->query ) === 1;
		$re                = '/\s*limit\s*([0-9]+)(?:\s*,\s*([0-9]+))?$/i';
		$this->query_clean = preg_replace_callback( $re, array( $this, 'clean_query' ), $this->query, 1 );
		
		$this->is_data_dump_select = $this->is_select && false === strpos( $this->query_clean, 'TABLE_NAME AS tbl_name' );
	}

	public function clean_query( $matches ) {
		if ( ! empty( $matches ) ) {
			if ( count( $matches ) == 3 ) {
				// we have "LIMIT offset, row_count" formatted limit
				$this->initial_offset = intval( $matches[1] );
				$this->max_rows       = intval( $matches[2] );
			} else {
				// we have "LIMIT row_count" formatted limit
				$this->max_rows = intval( $matches[1] );
			}
		}
		return '';
	}

	public function setFetchMode( $mode ) {
		$this->fetch_mode = $mode;
	}

	public function current() {
		return $this->current_result[ $this->current_key ];
		//return the current value
	}

	public function next() {
		$this->current_key++;
		if ( $this->valid() ) {
			return;
		}
		if ( ! $this->is_select ) {
			return;
		}
		if ( count( $this->current_result ) < $this->prev_page_size ) {
			return; // we're out
		}
		$this->prev_offset += ( $this->prev_page_size );
		$this->prev_keys  += $this->current_key;
		$this->current_key = 0;
		$this->get_next_page();
		// increment the counter
	}

	public function rewind() {
		$this->current_key    = 0;
		$this->current_page   = 0;
		$this->current_result = null;
		// reset the counter to 0
	}

	public function key() {
		return $this->prev_keys + $this->current_key;
		//return the key value of the current value
	}

	public function valid() {
		if ( is_null( $this->current_result ) ) {
			$this->get_next_page();
		}
		return isset( $this->current_result[ $this->current_key ] );
		//return the Boolean value to indicate if the value exists
	}

	public function get_next_page() {
		global $wpdb;

		if ( ! is_null( $this->current_result ) ) {
			$this->current_page++;
		}

		$query = $this->query_clean;
		if ( $this->is_select ) {
			$offset    = $this->initial_offset + $this->prev_offset;
			$row_limit = min( $this->max_rows, $this->page_size ); // TODO needs improving
			$limit     = ' LIMIT ' . $offset . ',' . $row_limit;
			$query    .= $limit;

			$this->prev_page_size = $row_limit;
		}
		$mode = null;
		switch ( $this->fetch_mode ) {
			case PDO_WP::FETCH_ASSOC: // phpcs:ignore
				$mode = ARRAY_A;
				break;
			case PDO_WP::FETCH_NUM: // phpcs:ignore
				$mode = ARRAY_N;
				break;
			case PDO_WP::FETCH_OBJ: // phpcs:ignore
				$mode = OBJECT;
				break;
			default:
				throw new Exception( 'Invalid fetch mode' );
		}
		if ( $this->is_data_dump_select ) {
			$memory_usage = memory_get_usage();
		}
		$results = $wpdb->get_results( $query, $mode );
		if ( $this->is_data_dump_select ) {
			$memory_usage = memory_get_usage() - $memory_usage;
			if ( $memory_usage < 25 * MB_IN_BYTES ) {
				$this->page_size = $this->page_size * 2;
			}
		}

		// stop the insanity
		$wpdb->queries = array();

		$this->current_result = $results;
		// exit;
	}

	public function closeCursor() {
		$this->rewind();
		return true;
	}

	// some more functions here
}
