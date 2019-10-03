<?php

class WP_LMaker_Dir_Crawler {
	private static $total_size = 0;
	private static $count      = 0;

	public static function reset() {
		self::$total_size = self::$count = 0; // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments
	}

	public static function process( $options, $zip ) {
		$options   = wp_parse_args(
			$options,
			array(
				'path'          => null,
				'root_path'     => null,
				'ignored_paths' => array(),
			)
		);
		$path      = untrailingslashit( $options['path'] );
		$root_path = ! empty( $options['root_path'] ) ? untrailingslashit( $options['root_path'] ) : $path;

		if ( ! is_a( $zip, 'ZipArchive' ) ) {
			// Initialize archive object
			$zip_fn = $zip;
			$zip    = new ZipArchive();
			$zip->open( $zip_fn, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		}

		if ( ! file_exists( $path ) ) {
			return $zip;
		}
		// Create recursive directory iterator
		/** @var SplFileInfo[] $files */

		$dir_iterator          = new RecursiveDirectoryIterator( $path );
		$dir_iterator_filtered = new WP_LMaker_Dir_Filter( $dir_iterator, array( '..', '.git', '.DS_Store', 'WPLM-*.zip' ) );

		$files = new RecursiveIteratorIterator(
			$dir_iterator_filtered,
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		self::$total_size = 0;

		$warnings = array( 200, 500, 1000, 2000 );

		foreach ( $files as $name => $file ) {
			if ( 100 === self::$count ) {
				self::$count = 0;
				echo '.';
			}
			self::$count++;
			$file_path     = $file->getRealPath();
			$relative_path = substr( $file_path, strlen( $root_path ) + 1 );

			$paths_ignored = $options['ignored_paths'];
			foreach ( $paths_ignored as $this_ignored_path ) {
				if ( strpos( $file_path, $this_ignored_path ) !== false ) {
					continue 2;
				}
			}

			if ( ! $file->isDir() ) {
				$this_size   = $file->getSize();
				$total_size += $this_size;
				if ( $this_size > 2 * MB_IN_BYTES ) {
					echo esc_html( "\nWARNING: File too big. " . $file_path . ' ' . size_format( $file->getSize() ) . ".\n" );
				}
			}

			if ( ! empty( $warnings ) && $total_size > $warnings[0] * MB_IN_BYTES ) {
				echo esc_html( "\nWARNING: " . $warnings[0] . "MB in files to be compressed.\n" );
				array_shift( $warnings );
			}

			if ( ! $file->isDir() ) {
				$path = array(
					$relative_path,
					$file_path,
				);
			} else {
				$path = array(
					$relative_path,
				);
			}

			if ( count( $path ) == 2 ) {
				$zip->addFile( $path[1], $path[0] );
			} else {
				$zip->addEmptyDir( $path[0] );
			}
		}
		return $zip;
	}
}
