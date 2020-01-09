# WP Local Maker

Ever got into a situation where downloading a full database backup of a site is just too hard on your localhost? Loading a 1+ GB sql dump into mysql is killing it's resources?

Enters **WP Local Maker**, the tool that will create a copy of the database with a reduced amount of certain data types. Eg: Do you have a large WooCommerce + WooCommerce Subscriptions site? Do you really need all 300k+ orders on your local to develop the new theme redesign?

## How this works

The way the plugin works, is that it goes through every table on the database, and copies the data entirely by default. If any module of the plugin setup a special handling of a certain table, that module will determine what data to copy.

The modular structure of the plugin allows other plugins to extend it's functionality. This way, some modules are bundled by default, and some are ok to keep as external plugins (eg: some specific custom table handling for a specific site).

Each module is intended as an organizational element for specific plugins. Eg: there's a module for WooCommerce, another for WooCommerce Subscriptions, another for Gravity Forms, etc.

All of the processing is done through as-optimized-as-possible queries to MySQL to move data from the original tables, into structural mirrors that will contain the data to be. 

## Let's try it

All of the functionality of the plugin is made available through WP CLI, and the plugin itself does nothing in any other context.

The command to run is:

```sh
wp backup export
```

Use the help option to get further details

```sh
wp backup export --help
```

# Support Level

**Active:** SAU/CAL is actively working on this, and we expect to continue work for the foreseeable future including keeping tested up to the most recent version of WordPress and PHP. Bug reports, feature requests, questions, and pull requests are welcome.