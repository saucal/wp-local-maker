<?php
/**
 * Class WP_LMaker_Stream
 */
class WP_LMaker_Stream extends WP_LMaker_Abstract_Addon {
	public function excluded_tables( $tables ) {
		$tables[] = 'stream';
		$tables[] = 'stream_meta';
		return $tables;
	}
}

new WP_LMaker_Stream();
