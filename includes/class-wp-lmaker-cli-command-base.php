<?php

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

// phpcs:disable Generic.Files.OneObjectStructurePerFile
if ( class_exists( 'WPCOM_VIP_CLI_Command' ) ) {
	class WP_LMaker_CLI_Command_Base extends WPCOM_VIP_CLI_Command {

	}
} else {
	// phpcs:ignore WordPressVIPMinimum.Classes.RestrictedExtendClasses.wp_cli, Generic.Classes.DuplicateClassName
	class WP_LMaker_CLI_Command_Base extends WP_CLI_Command {

	}
}
