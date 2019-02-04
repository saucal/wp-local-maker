<?php
/**
 * Created by PhpStorm.
 * User: manu
 * Date: 04/02/2019
 * Time: 18:58
 */



class WP_LMaker_WooCommerce_Subscriptions extends WP_LMaker_Addon {
	function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_ignore_straight_post_types', array( $this, 'ignore_straight_post_types' ) );
		add_action( 'wp_local_maker_orders_after_orders', array( $this, 'process_subscriptions' ) );
	}

	function process_subscriptions( $tables_info ) {
		global $wpdb;
		$current = $tables_info[ 'posts' ][ 'currname' ];
		$temp = $tables_info[ 'posts' ][ 'tempname' ];
		$curr_pm = $tables_info[ 'postmeta' ][ 'currname' ];

		// Handle subscriptions
		$wpdb->query("REPLACE INTO {$temp}
            SELECT * FROM {$current} p
            WHERE p.post_status NOT IN ('auto-draft', 'trash')
            AND p.post_type IN ( 'shop_subscription' )
            ORDER BY p.post_date DESC
            LIMIT 50");

		// Handle subscriptions related orders
		$wpdb->query("REPLACE INTO {$temp}
            SELECT p.* FROM {$current} p
            INNER JOIN {$curr_pm} pm ON p.ID = pm.post_id AND ( pm.meta_key = '_subscription_switch' OR pm.meta_key = '_subscription_renewal' OR pm.meta_key = 'subscription_resubscribe' )
            WHERE pm.meta_value IN ( SELECT ID FROM {$temp} p2 WHERE p2.post_type = 'shop_subscription' )");

		do_action( 'wp_local_maker_subscriptions_after_subscriptions', $tables_info );
	}

	function ignore_straight_post_types($types) {
		$types[] = 'shop_subscription';
		return $types;
	}
}
new WP_LMaker_WooCommerce_Subscriptions();

class WP_LMaker_WooCommerce_Memberships extends WP_LMaker_Addon {
	function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_ignore_straight_post_types', array( $this, 'ignore_straight_post_types' ) );
		add_action( 'wp_local_maker_orders_after_orders', array( $this, 'process_memberships' ) );
	}

	function process_memberships( $tables_info ) {
		global $wpdb;
		$current = $tables_info[ 'posts' ][ 'currname' ];
		$temp = $tables_info[ 'posts' ][ 'tempname' ];
		$curr_pm = $tables_info[ 'postmeta' ][ 'currname' ];

		// Handle memberships
		$wpdb->query("REPLACE INTO {$temp}
            SELECT * FROM {$current} p
            WHERE p.post_status NOT IN ('auto-draft', 'trash')
            AND p.post_type IN ( 'wc_user_membership' )
            ORDER BY p.post_date DESC
            LIMIT 50");

		// Handle subscriptions related memberships
		$wpdb->query("REPLACE INTO {$temp}
            SELECT p.* FROM {$current} p
            INNER JOIN {$curr_pm} pm ON p.ID = pm.post_id AND pm.meta_key = '_subscription_id'
            WHERE p.post_type IN ( 'wc_user_membership' ) AND pm.meta_value IN ( SELECT ID FROM {$temp} p2 WHERE p2.post_type = 'shop_subscription' )");

		do_action( 'wp_local_maker_memberships_after_memberships', $tables_info );
	}

	function ignore_straight_post_types($types) {
		$types[] = 'wc_user_membership';
		return $types;
	}
}
new WP_LMaker_WooCommerce_Memberships();

class WP_LMaker_Action_Scheduler extends WP_LMaker_Addon {
	function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_ignore_straight_post_types', array( $this, 'ignore_straight_post_types' ) );
		add_action( 'wp_local_maker_subscriptions_after_subscriptions', array( $this, 'process_subscriptions_actions' ) );
		add_action( 'wp_local_maker_memberships_after_memberships', array( $this, 'process_memberships_actions' ) );
	}

	function process_subscriptions_actions( $tables_info ) {
		global $wpdb;
		$current = $tables_info[ 'posts' ][ 'currname' ];
		$temp = $tables_info[ 'posts' ][ 'tempname' ];

		// Handle subscriptions related actions
		$wpdb->query("REPLACE INTO {$temp}
            SELECT * FROM {$current} 
            WHERE post_type = 'scheduled-action' AND post_content IN ( SELECT CONCAT('{\"subscription_id\":', ID, '}') FROM {$temp} p2 WHERE p2.post_type = 'shop_subscription' )");
	}

	function process_memberships_actions( $tables_info ) {
		global $wpdb;
		$current = $tables_info[ 'posts' ][ 'currname' ];
		$temp = $tables_info[ 'posts' ][ 'tempname' ];

		// Handle memberships related actions
		$wpdb->query("REPLACE INTO {$temp}
            SELECT * FROM {$current} 
            WHERE post_type = 'scheduled-action' AND post_content IN ( SELECT CONCAT('{\"user_membership_id\":', ID, '}') FROM {$temp} p2 WHERE p2.post_type = 'wc_user_membership' )");
	}

	function ignore_straight_post_types($types) {
		$types[] = 'scheduled-action';
		return $types;
	}
}
new WP_LMaker_Action_Scheduler();

class WP_LMaker_Gravity_Forms extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'gf_entry';
		$tables[] = 'gf_entry_meta';
		$tables[] = 'gf_entry_notes';
		$tables[] = 'gf_form_view';
		$tables[] = 'rg_lead';
		$tables[] = 'rg_lead_detail';
		$tables[] = 'rg_lead_detail_long';
		$tables[] = 'rg_lead_meta';
		$tables[] = 'rg_lead_notes';
		$tables[] = 'rg_form_view';
		$tables[] = 'rg_incomplete_submissions';
		return $tables;
	}
}
new WP_LMaker_Gravity_Forms();

class WP_LMaker_Redirection extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'redirection_logs';
		$tables[] = 'redirection_404';
		return $tables;
	}
}
new WP_LMaker_Redirection();

class WP_LMaker_SCH_Smart_Transients extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'sch_smart_transients';
		return $tables;
	}
}
new WP_LMaker_SCH_Smart_Transients();

class WP_LMaker_Affiliate_WP extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'affiliate_wp_visits';
		return $tables;
	}
}
new WP_LMaker_Affiliate_WP();

class WP_LMaker_Abandoned_Carts_Pro extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'ac_abandoned_cart_history';
		$tables[] = 'ac_guest_abandoned_cart_history';
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

class WP_LMaker_EWWWIO extends WP_LMaker_Addon {
	function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_ewwio' ), 45);
	}

	function enqueue_process_ewwio( $tables ) {
		global $wpdb;
		$tables['ewwwio_images'] = array( $this, 'process_ewwwio_images' );
		return $tables;
	}

	public function process_ewwwio_images() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('ewwwio_images', 'posts', 'id', 'ID');
	}
}
new WP_LMaker_EWWWIO();

class WP_LMaker_NGG extends WP_LMaker_Addon {
	function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_ngg' ), 45);
		add_filter( 'wp_local_maker_ignore_straight_post_types', array( $this, 'ignore_straight_post_types' ) );
	}

	function enqueue_process_ngg( $tables ) {
		global $wpdb;
		if( ! $this->is_plugin_active( 'nextgen-gallery/nggallery.php' ) ) {
			$tables['ngg_album'] = false;
			$tables['ngg_gallery'] = false;
			$tables['ngg_pictures'] = false;
		}
		return $tables;
	}

	function ignore_straight_post_types($types) {
		if( ! $this->is_plugin_active( 'nextgen-gallery/nggallery.php' ) ) {
			$types[] = 'ngg_pictures';
		}
		return $types;
	}
}
new WP_LMaker_NGG();

class WP_LMaker_SCR extends WP_LMaker_Addon {
	function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_scr' ), 45);
		add_filter( 'wp_local_maker_global_tables', array( $this, 'register_global_tables' ), 45);
	}

	function enqueue_process_scr( $tables ) {
		global $wpdb;
		$tables['scr_relationships'] = array( $this, 'process_scr_relationships' );
		$tables['scr_relationshipmeta'] = array( $this, 'process_scr_relationshipmeta' );
		return $tables;
	}

	function register_global_tables( $tables ) {
		$tables[] = 'scr_relationships';
		$tables[] = 'scr_relationshipmeta';
		return $tables;
	}

	function process_scr_relationships() {
		global $wpdb;
		$tables_info = Backup_Command::get_tables_names();
		$current = $tables_info[ 'scr_relationships' ][ 'currname' ];
		$temp = $tables_info[ 'scr_relationships' ][ 'tempname' ];

		$wpdb->query("CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}");

		$temp_posts = $tables_info[ 'posts' ][ 'tempname' ];
		$temp_users = $tables_info[ 'users' ][ 'tempname' ];

		// Export every matching relationship from a user standpoint
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM wp_scr_relationships scr 
			WHERE 
			scr.object1_type = 'user' AND scr.object1_site = 1 AND scr.object1_id IN ( SELECT ID FROM {$temp_users} )  
			AND
			scr.object2_type = 'post' AND scr.object2_site = " . get_current_blog_id() . " AND scr.object2_id IN ( SELECT ID FROM {$temp_posts} )");

		// Export every matching relationship from a post standpoint
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM wp_scr_relationships scr 
			WHERE 
			scr.object1_type = 'post' AND scr.object1_site = " . get_current_blog_id() . " AND scr.object1_id IN ( SELECT ID FROM {$temp_posts} )
			AND
			scr.object2_type = 'user' AND scr.object2_site = 1 AND scr.object2_id IN ( SELECT ID FROM {$temp_users} )");

		$file = Backup_Command::write_table_file( $temp, $current );

		return $file;
	}

	public function process_scr_relationshipmeta() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('scr_relationshipmeta', 'scr_relationships', 'scr_relationship_id', 'rel_id');
	}
}
new WP_LMaker_SCR();

class WP_LMaker_WooCommerce_Order_Index extends WP_LMaker_Addon {
	function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_customer_order_index' ), 45);
	}

	function enqueue_process_customer_order_index( $tables ) {
		global $wpdb;
		$tables['woocommerce_customer_order_index'] = array( $this, 'process_customer_order_index' );
		return $tables;
	}

	public function process_customer_order_index() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('woocommerce_customer_order_index', 'posts', 'order_id', 'ID');
	}
}
new WP_LMaker_WooCommerce_Order_Index();

class WP_LMaker_SAD extends WP_LMaker_Addon {
	function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_sad_users' ), 45);
	}

	function enqueue_process_sad_users( $tables ) {
		global $wpdb;
		if( ! $this->is_plugin_active( 'halfdata-optin-downloads/halfdata-optin-downloads.php' ) ) {
			$tables['sad_users'] = false;
		}
		return $tables;
	}
}
new WP_LMaker_SAD();
