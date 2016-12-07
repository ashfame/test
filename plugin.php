<?php

/**
 * Plugin Name: GrabConversions
 * Plugin URI: http://wordpress.org/plugins/grabconversions/
 * Description: Email marketing inside WordPress
 * Author: Ashfame
 * Author URI: http://ashfame.com/
 * Version: 0.1-alpha
 */

// die if called directly
defined( 'ABSPATH' ) || die();

class GrabConversions_Core {

	private static $instance;

	public static $version = '0.1-alpha';
	public static $required_wp_version = '4.6';
	// both PHP & MySQL versions are what WordPress itself recommends
	public static $required_php_version = '5.6';
	public static $required_mysql_version = '5.6'; // not sure how to use / check this one

	private static $subscribers_table_name;

	public function __construct() {
		global $wpdb;

		if ( ! $this->is_compatible() ) {
			return;
		}

		self::$subscribers_table_name = $wpdb->prefix . 'grabconversions_list_data';

		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'init' ) );

		if ( is_admin() ) {
		    require 'includes/subscribers_list.php';
        }
	}

	public static function getInstance() {
		if ( ! self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	public function activate() {
		global $wpdb;

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$collate = '';

		if ( $wpdb->has_cap( 'collation' ) ) {
			if ( ! empty( $wpdb->charset ) ) {
				$collate .= "DEFAULT CHARACTER SET $wpdb->charset";
			}
			if ( ! empty( $wpdb->collate ) ) {
				$collate .= " COLLATE $wpdb->collate";
			}
		}

		// @TODO have to add a created_date
		$sql = "CREATE TABLE " . self::$subscribers_table_name . " (
		  id BIGINT NOT NULL AUTO_INCREMENT,
		  name varchar(200) NOT NULL,
		  email VARCHAR(100) NOT NULL,
		  status TINYINT NULL DEFAULT 0,
		  confirmation_key VARCHAR(255) NULL,
		  PRIMARY KEY (id)
		  KEY email (email)
		) $collate";
		dbDelta( $sql );
	}

	public function deactivate() {
		// do nothing for now
	}

	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		add_action( 'widgets_init', array( $this, 'widget_init' ), 10, 2 );
		add_filter( 'plugin_action_links', array( $this, 'plugin_action_links' ), 10, 2 );

		add_action( 'admin_menu', array( $this, 'add_gc_menu_page' ) );

		// widget + add-ons announce after collecting optin data for processing
		add_action( 'grabconversions_announce_optin', array( $this, 'collect_optin_data' ) );

		// handle confirmation of email subscriptions
		add_action( 'wp_ajax_grabconversions_confirm_email_subscription', array( $this, 'confirm_email_subscription' ) );
		add_action( 'wp_ajax_nopriv_grabconversions_confirm_email_subscription', array( $this, 'confirm_email_subscription' ) );

		// handle unsubscribes
		add_action( 'wp_ajax_grabconversions_confirm_email_unsubscription', array( $this, 'confirm_email_unsubscription' ) );
		add_action( 'wp_ajax_nopriv_grabconversions_confirm_email_unsubscription', array( $this, 'confirm_email_unsubscription' ) );
	}

	/**
	 * Function to ensure that plugin is only run on a WordPress v4.6+ install
	 *
	 * Filter is available to change the output of this function if somebody wants to run on WordPress versions prior to 4.6
	 * @return boolean
	 */
	public function is_compatible() {
		global $wp_version;
		return apply_filters(
			'grabconversions_compatibility',
			version_compare( $wp_version, self::$required_wp_version, '>=' ) && version_compare( phpversion(), self::$required_php_version )
		);
	}

	public function enqueue_scripts() {
		wp_register_script( 'grabconversions_widget_js', plugins_url( 'js/widget.js', __FILE__ ), array( 'jquery' ), self::$version, true );
		wp_localize_script( 'grabconversions_widget_js', 'grabconversions', array(
			'ajax_url' => admin_url( 'admin-ajax.php' )
		) );
	}

	public function widget_init() {
		require plugin_dir_path( __FILE__ ) . 'includes/widget.php';

		register_widget( 'GrabConversions_Core_Widget' );
	}

	public function plugin_action_links( $links, $file ) {
		// Also check using strpos because when plugin is actually a symlink inside plugins folder, its plugin_basename will be based off its actual path
		if ( $file == plugin_basename( __FILE__ ) || strpos( plugin_basename( __FILE__ ), $file ) !== false ) {
			$settings_link = '<a href="' . admin_url( 'options-general.php?page=grabconversions' ) . '">Settings</a>';
			$support_link = '<a href="https://grabconversions.com/free-to-pro-upgrade/">Pro Upgrade</a>';
			$report_issue_link = '<a href="mailto:support@grabconversions.com">Report Issue</a>';
			$links = array_merge( array( $settings_link, $support_link, $report_issue_link ), $links );
		}
		return $links;
	}

	public function add_gc_menu_page() {
		add_menu_page(
			__( 'Grab Conversions', 'textdomain' ),
			'Grab Conversions&nbsp;&nbsp;&nbsp;',
			'manage_options',
			'grabconversions.php',
			array( $this, 'gc_menu_page_render' ),
			'dashicons-email',
			60
		);
	}

	public function gc_menu_page_render() {
		$GrabConversions_Subscribers_List_Table = new GrabConversions_Subscribers_List_Table();
		?>
		<div class="wrap">
			<div id="icon-users" class="icon32"></div>
			<h2>Grab Conversions</h2>
            <?php $GrabConversions_Subscribers_List_Table->prepare_items(); ?>
            <form method="post">
                <input type="hidden" name="page" value="grabconversions_subscriber_search" />
			    <?php $GrabConversions_Subscribers_List_Table->search_box( 'search', 'search_id' ); ?>
            </form>
            <?php $GrabConversions_Subscribers_List_Table->display(); ?>
		</div>
		<?php
	}

	public function generate_confirmation_key( $email, $skip_db_write = false ) {
		global $wpdb;

		$confirmation_key = substr( md5( time() . rand() . $email ), 0, 16 );

		if ( ! $skip_db_write ) {
			$result = $wpdb->update(
				self::$subscribers_table_name,
				array( 'confirmation_key' => $confirmation_key ),
				array( 'email' => $email ),
				array( '%s' ),
				array( '%s' )
			);

			if ( $result ) {
				return $confirmation_key;
			} else {
				return null;
			}
		}

		return $confirmation_key;
	}

	public function collect_optin_data( $data ) {
		global $wpdb;

		// lets check if we already have this email as a subscriber (unconfirmed/confirmed)
		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::$subscribers_table_name . " WHERE email = '%s';",
				array( $data['email'] )
			),
			ARRAY_A
		);

		if ( is_null( $result ) ) {
			$row = array(
				'name' => $data['name'],
				'email' => $data['email'],
				'status' => $data['doubleoptin'] ? 0 : 1,
				'confirmation_key' => $data['doubleoptin'] ? $this->generate_confirmation_key( $data['email'], true ) : ''
			);

			$result = $wpdb->insert(
				$wpdb->prefix . 'grabconversions_list_data',
				$row,
				array(
					'%s', '%s', '%d', '%s'
				)
			);

			if ( $result ) {
				if ( $data['doubleoptin'] ) { // shouldn't double optin be the only choice, no single-optins allowed #mustThink
					$this->send_double_optin_confirmation_email( $row['email'], $row['confirmation_key'] );
				}
			} else {
				// something went wrong, how often does that happen?
			}
		} else {
			// user is already a subscriber
			if ( $result['status'] == 1 ) {
				// user is already confirmed, nothing to do here till we have tags/segments to worry about
			} else if ( $result['status'] == 9 ) {
                // user is deleted, bring it back to life
				$wpdb->update(
					self::$subscribers_table_name,
					array( 'status' => 0 ),
					array( 'id' => $result['id'] ),
					array( '%d' ),
					array( '%d' )
				);

				// send double-optin email to the subscriber now
				$this->send_double_optin_confirmation_email( $data['email'] );
			} else {
				// send another double-optin email to the subscriber
				$this->send_double_optin_confirmation_email( $data['email'] );
			}
		}
	}

	public function send_double_optin_confirmation_email( $email, $confirmation_key = '' ) {
		if ( ! $confirmation_key ) {
			$confirmation_key = $this->generate_confirmation_key( $email );
		}

		$email_subscription_link = admin_url( 'admin-ajax.php?action=grabconversions_confirm_email_subscription&who=' . md5( $email ) . '&key=' . $confirmation_key );
		$email_body = '<p>Hi, Thanks for signing up! Click on this link to confirm your email subscription - <a href="' . $email_subscription_link . '">' . $email_subscription_link . '</a></p>';
		wp_mail( $email, 'Activate your email subscription', $email_body );
	}

	public function confirm_email_subscription() {
		global $wpdb;

		$email_hash = $_GET['who'];
		$confirmation_key = $_GET['key'];

		// lets search for a subscriber who has that confirmation key
		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, email FROM " . self::$subscribers_table_name . " WHERE confirmation_key = '%s';",
				$confirmation_key
			),
			ARRAY_A
		);

		if ( is_null( $result ) ) {
			die( 'Sorry! The confirmation key doesn\'t match with any of the subscriber.' );
		} else {
			if ( $email_hash == md5( $result['email'] ) ) {
				// verified, mark as confirmed
				$wpdb->update(
					self::$subscribers_table_name,
					array( 'status' => 1 ),
					array( 'id' => $result['id'] ),
					array( '%d' ),
					array( '%d' )
				);
				die( 'Congrats! Your subscription is confirmed!' );
			} else {
				die( 'Sorry! The URL seems to be invalid.' );
			}
		}
	}

	public function confirm_email_unsubscription() {
		global $wpdb;

		$email_hash = $_GET['who'];
		$confirmation_key = $_GET['key'];

		// lets search for a subscriber who has that confirmation key
		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, email FROM " . self::$subscribers_table_name . " WHERE confirmation_key = '%s';",
				$confirmation_key
			),
			ARRAY_A
		);

		if ( is_null( $result ) ) {
			die( 'Invalid link! We don\'t have any records for your email.' );
		} else {
			if ( $email_hash == md5( $result['email'] ) ) {
				// verified, mark status as deleted/unsubscribed
				$wpdb->update(
					self::$subscribers_table_name,
                    array( 'status' => 9 ),
					array( 'id' => $result['id'] ),
					array( '%d' ),
					array( '%d' )
				);
				die( 'Sorry to see you go! You have been unsubscribed.' );
			} else {
				die( 'Sorry! The URL seems to be invalid.' );
			}
		}
	}
}

GrabConversions_Core::getInstance();