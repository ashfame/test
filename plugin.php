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
	public static $required_wp_version = '4.7';
	// both PHP & MySQL versions are what WordPress itself recommends
	public static $required_php_version = '5.6';
	public static $required_mysql_version = '5.6'; // not sure how to use / check this one

	public static $subscribers_table_name;
	public static $subscriber_statuses;

	public function __construct() {
		global $wpdb;

		if ( !$this->is_compatible() ) {
			return;
		}

		self::$subscribers_table_name = $wpdb->prefix . 'grabconversions_list_data';
		self::$subscriber_statuses    = array( 0 => 'unconfirmed', 1 => 'confirmed', 9 => 'deleted' );

		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'init' ) );

		if ( is_admin() ) {
			require 'includes/subscribers_list.php';
		}
	}

	public static function getInstance() {
		if ( !self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	public function activate() {
		global $wpdb;

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$collate = '';

		if ( $wpdb->has_cap( 'collation' ) ) {
			if ( !empty( $wpdb->charset ) ) {
				$collate .= "DEFAULT CHARACTER SET $wpdb->charset";
			}
			if ( !empty( $wpdb->collate ) ) {
				$collate .= " COLLATE $wpdb->collate";
			}
		}

		$sql = "CREATE TABLE " . self::$subscribers_table_name . " (
		  id BIGINT NOT NULL AUTO_INCREMENT,
		  name varchar(200) NOT NULL,
		  email VARCHAR(100) NOT NULL,
		  status TINYINT NULL DEFAULT 0,
		  created_at TIMESTAMP NOT NULL,
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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		add_action( 'widgets_init', array( $this, 'widget_init' ), 10, 2 );
		add_filter( 'plugin_action_links', array( $this, 'plugin_action_links' ), 10, 2 );

		add_action( 'admin_menu', array( $this, 'add_gc_menu_page' ) );
		add_action( 'admin_head', array( $this, 'admin_header' ) );
		add_filter( 'set-screen-option', array( $this, 'gc_menu_page_set_screen_options' ), 10, 3 );

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

		return apply_filters( 'grabconversions_compatibility', version_compare( $wp_version, self::$required_wp_version, '>=' ) && version_compare( phpversion(), self::$required_php_version ) );
	}

	public function enqueue_scripts() {
		wp_register_script( 'grabconversions_widget_js', plugins_url( 'js/widget.js', __FILE__ ), array( 'jquery' ), self::$version, true );
		wp_localize_script( 'grabconversions_widget_js', 'grabconversions', array(
			'ajax_url' => admin_url( 'admin-ajax.php' )
		) );
	}

	public function enqueue_admin_scripts( $hook ) {
		if ( $hook == 'grab-conversions_page_grabconversions_settings' ) {
			wp_enqueue_script( 'grabconversions_admin_js', plugins_url( 'js/admin.js', __FILE__ ), array( 'jquery' ), self::$version, true );
		} else if ( $hook == 'grab-conversions_page_grabconversions_broadcast' ) {
			wp_enqueue_script( 'grabconversions_trix_js', plugins_url( 'js/trix/trix.js', __FILE__ ), array(), '0.10.0', true );
			wp_enqueue_style( 'grabconversions_trix_css', plugins_url( 'css/trix/trix.css', __FILE__ ), array(), '0.10.0' );
		}
	}

	public function admin_header() {
		$page = ( isset( $_GET[ 'page' ] ) ) ? esc_attr( $_GET[ 'page' ] ) : false;
		if ( 'grabconversions_subscribers_list' != $page )
			return;

		echo '<style type="text/css">';
		echo '.wp-list-table .column-name { width: 40%; }';
		echo '.wp-list-table .column-email { width: 40%; }';
		echo '.wp-list-table .column-status { width: 20%; text-align:center; }';
		echo '</style>';
	}

	public function widget_init() {
		require plugin_dir_path( __FILE__ ) . 'includes/widget.php';

		register_widget( 'GrabConversions_Core_Widget' );
	}

	public function plugin_action_links( $links, $file ) {
		// Also check using strpos because when plugin is actually a symlink inside plugins folder, its plugin_basename will be based off its actual path
		if ( $file == plugin_basename( __FILE__ ) || strpos( plugin_basename( __FILE__ ), $file ) !== false ) {
			$settings_link     = '<a href="' . admin_url( 'options-general.php?page=grabconversions' ) . '">Settings</a>';
			$support_link      = '<a href="https://grabconversions.com/free-to-pro-upgrade/">Pro Upgrade</a>';
			$report_issue_link = '<a href="mailto:support@grabconversions.com">Report Issue</a>';
			$links             = array_merge( array( $settings_link, $support_link, $report_issue_link ), $links );
		}

		return $links;
	}

	public function add_gc_menu_page() {
		$hook = add_menu_page( __( 'Grab Conversions', 'textdomain' ), 'Grab Conversions&nbsp;&nbsp;&nbsp;', 'manage_options', 'grabconversions_subscribers_list', array(
			$this,
			'gc_menu_page_render'
		), 'dashicons-email', 60 );
		add_submenu_page( 'grabconversions_subscribers_list', 'Subscribers', 'Subscribers', 'manage_options', 'grabconversions_subscribers_list', array( $this, 'gc_menu_page_render' ) );
		add_submenu_page( 'grabconversions_subscribers_list', 'Broadcast', 'Broadcast', 'manage_options', 'grabconversions_broadcast', array( $this, 'gc_menu_broadcast_page_render' ) );
		add_submenu_page( 'grabconversions_subscribers_list', 'Settings', 'Settings', 'manage_options', 'grabconversions_settings', array( $this, 'gc_menu_settings_page_render' ) );

		add_action( 'load-' . $hook, array( $this, 'gc_menu_page_screen_options' ) );
	}

	public function gc_menu_page_screen_options() {
		$option = 'per_page';
		$args   = array(
			'label'   => 'Subscribers',
			'default' => 100,
			'option'  => 'subscribers_per_page'
		);
		add_screen_option( $option, $args );
	}

	public function gc_menu_page_set_screen_options( $status, $option, $value ) {
		return $value;
	}

	public function get_subscribers_summary() {
		global $wpdb;

		$summary = array();

		foreach ( self::$subscriber_statuses as $key => $label ) {
			$summary[ 'count' ][ $key ] = $wpdb->get_var( "SELECT COUNT(*) FROM " . self::$subscribers_table_name . " WHERE status = $key;" );
		}

		update_option( 'gc_cache_subscribers_summary', $summary, true );

		return $summary;
	}

	public function gc_menu_page_render() {
		$GrabConversions_Subscribers_List_Table = new GrabConversions_Subscribers_List_Table();
		?>
        <div class="wrap">
            <div id="icon-users" class="icon32"></div>
            <h2>Grab Conversions</h2>
			<?php $GrabConversions_Subscribers_List_Table->prepare_items(); ?>
            <form method="GET">
                <input type="hidden" name="page" value="grabconversions_subscribers_list"/>
				<?php $GrabConversions_Subscribers_List_Table->search_box( 'search', 'search_id' ); ?>
            </form>
            <form method="POST">
				<?php
				$admin_url           = admin_url( 'admin.php?page=grabconversions_subscribers_list' );
				$subscribers_summary = $this->get_subscribers_summary();
				?>
                <ul class="subsubsub">
                    <li class="all">
                        <a href="<?php echo $admin_url; ?>"
                           class="<?php if ( strpos( 'http://' . $_SERVER[ 'HTTP_HOST' ] . $_SERVER[ 'REQUEST_URI' ], $admin_url ) === 0 && strpos( $_SERVER[ 'REQUEST_URI' ], '&status=' ) === false )
							   echo 'current'; ?>">
							<?php _e( 'All' ); ?>
                            <span class="count">(<?php echo array_sum( $subscribers_summary[ 'count' ] ); ?>)</span>
                        </a>
                    </li>
					<?php foreach ( self::$subscriber_statuses as $key => $label ) { ?>
						<?php if ( $key == 9 )
							continue; ?>
                        <li class="">|
                            <a href="<?php echo add_query_arg( 'status', $label, $admin_url ); ?>"
                               class="<?php if ( strpos( 'http://' . $_SERVER[ 'HTTP_HOST' ] . $_SERVER[ 'REQUEST_URI' ], add_query_arg( 'status', $label, $admin_url ) ) === 0 )
								   echo 'current'; ?>">
								<?php _e( ucfirst( $label ) ); ?>
                                <span class="count">(<?php echo $subscribers_summary[ 'count' ][ $key ]; ?>)</span>
                            </a>
                        </li>
					<?php } ?>
                </ul>
				<?php $GrabConversions_Subscribers_List_Table->display(); ?>
            </form>
        </div>
		<?php
	}

	public function gc_menu_broadcast_page_render() {
		$settings = get_option( 'grabconversions_settings', array() );
		echo '<pre>';
		print_r( $_POST );
		echo '</pre>';

		require plugin_dir_path( __FILE__ ) . 'includes/mailgun/Mailgun.php';
		//use Mailgun\Mailgun;

		$mg     = new \Mailgun\Mailgun( $settings[ 'mailgun' ][ 'apikey' ] );
		$domain = $settings[ 'mailgun' ][ 'domain' ];

		$mg->sendMessage( $domain, array(
			'from'    => $settings[ 'from_email_address' ],
			'to'      => 'ashishsainiashfame@gmail.com',
			'subject' => $_POST[ 'broadcast-subject' ],
			'text'    => $_POST[ 'broadcast-message' ]
		) );

		?>
        <div class="wrap">
            <h2>Broadcast</h2>
            <form action="" method="POST">
                <table class="form-table">
                    <tbody>
                    <tr>
                        <th scope="row">From</th>
                        <td>
                            <input type="text" class="regular-text" readonly="readonly" value="<?php echo $settings[ 'from_email_address' ]; ?>"/>
                            <p class="description">You can change this in settings</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">To</th>
                        <td>
                            <p>All Subscribers (Total of <?php echo $this->get_confirmed_subscribers_count() ?> subscribers)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Subject</th>
                        <td>
                            <input type="text" class="regular-text" name="broadcast-subject" placeholder="News that you have been waiting to hear"/>
                            <p class="description">You can change this in settings</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Message</th>
                        <td>
                            <trix-editor input="broadcast-message"></trix-editor>
                            <input id="broadcast-message" name="broadcast-message" type="hidden" value="Hi {NAME}, <br /><br />How is it going?"/>
                            <p class="description">Use {NAME} as variable, which will be replaced with actual name of the subscriber</p>
                        </td>
                    </tr>
                    </tbody>
                </table>
                <input type="submit" name="submit" id="submit" class="button button-primary" value="Send Broadcast"/>
                <input type="submit" name="submit" id="submit" class="button" value="Send as a test to yourself"/>
            </form>
        </div>
		<?php
	}

	public function gc_menu_settings_page_render() {
		// Settings API is deliberately not used here, since we want to inject JS show/hide + handle data upgrades as product matures
		$settings = get_option( 'grabconversions_settings', array() );

		if ( isset( $_REQUEST[ 'gc' ] ) ) {
			$settings                          = array();
			$settings[ 'email_engine' ]        = $_REQUEST[ 'gc' ][ 'email_engine' ] == 'whatever' ? 'whatever' : 'mailgun';
			$settings[ 'mailgun' ][ 'domain' ] = str_replace( array( 'http://', 'https://' ), '', $_REQUEST[ 'gc' ][ 'mailgun' ][ 'domain' ] );
			$settings[ 'mailgun' ][ 'domain' ] = explode( '/', $settings[ 'mailgun' ][ 'domain' ] );
			$settings[ 'mailgun' ][ 'domain' ] = str_replace( 'www.', '', $settings[ 'mailgun' ][ 'domain' ][ 0 ] );
			$settings[ 'mailgun' ][ 'apikey' ] = $_REQUEST[ 'gc' ][ 'mailgun' ][ 'apikey' ];
			$settings[ 'from_email_address' ]  = ( isset( $_REQUEST[ 'gc' ][ 'from_email_address' ] ) && is_email( $_REQUEST[ 'gc' ][ 'from_email_address' ] ) ) ? $_REQUEST[ 'gc' ][ 'from_email_address' ] : ( 'newsletter@' . $settings[ 'mailgun' ][ 'domain' ] );

			update_option( 'grabconversions_settings', $settings );
		}
		?>

        <div class="wrap">
            <h2>GrabConversions Settings</h2>
            <form method="POST">
                <input type="hidden" name="action" value="grabconversions_settings_submit"/>
                <table class="form-table">
                    <tbody>
                    <tr>
                        <th scope="row">Email Engine</th>
                        <td id="email-engine-setting">
                            <fieldset>
                                <legend class="screen-reader-text"><span>Email Engine</span></legend>
                                <p>
                                    <label>
                                        <input name="gc[email_engine]" type="radio" value="whatever" <?php checked( $settings[ 'email_engine' ], 'whatever', true ) ?>>
                                        Use what WordPress is using
                                    </label>
                                </p>
                                <p>
                                    <label>
                                        <input name="gc[email_engine]" type="radio" value="mailgun" <?php checked( $settings[ 'email_engine' ], 'mailgun', true ) ?>>
                                        Use <a target="_blank" href="http://www.mailgun.com/">Mailgun</a> API (recommended)
                                    </label>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                    <tr valign="top" class="mailgun">
                        <th scope="row">
                            Mailgun Domain Name
                        </th>
                        <td>
                            <legend class="screen-reader-text"><span>Mailgun Domain Name</span></legend>
                            <input type="text" class="regular-text" name="gc[mailgun][domain]" value="<?php echo $settings[ 'mailgun' ][ 'domain' ] ?>" placeholder="samples.mailgun.org"/>
                            <p class="description">Your Mailgun Domain Name</p>
                        </td>
                    </tr>
                    <tr valign="top" class="mailgun">
                        <th scope="row">
                            Mailgun API Key
                        </th>
                        <td>
                            <legend class="screen-reader-text"><span>Mailgun API Key</span></legend>
                            <input type="text" class="regular-text" name="gc[mailgun][apikey]" value="<?php echo $settings[ 'mailgun' ][ 'apikey' ] ?>" placeholder="key-3ax6xnjp29jd6fds4gc373sgvjxteol0"/>
                            <p class="description">Your Mailgun API key, that starts with and includes "key-"</p>
                        </td>
                    </tr>
                    <tr valign="top" class="">
                        <th scope="row">
                            Send Mail From
                        </th>
                        <td>
                            <legend class="screen-reader-text"><span>Send Mail From</span></legend>
                            <input type="text" class="regular-text" name="gc[from_email_address]" value="<?php echo $settings[ 'from_email_address' ] ?>"
                                   placeholder="newsletter@<?php echo $settings[ 'mailgun' ][ 'domain' ] ?>"/>
                            <p class="description">Emails will appear to come from this email address</p>
                        </td>
                    </tr>
                    </tbody>
                </table>
                <input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes"/>
            </form>
        </div>
		<?php
	}

	public function generate_confirmation_key( $email, $skip_db_write = false ) {
		global $wpdb;

		$confirmation_key = substr( md5( time() . rand() . $email ), 0, 16 );

		if ( !$skip_db_write ) {
			$result = $wpdb->update( self::$subscribers_table_name, array( 'confirmation_key' => $confirmation_key ), array( 'email' => $email ), array( '%s' ), array( '%s' ) );

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
		$result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::$subscribers_table_name . " WHERE email = '%s';", array( $data[ 'email' ] ) ), ARRAY_A );

		if ( is_null( $result ) ) {

			$now = new DateTime( 'now', new DateTimeZone( 'GMT' ) );
			$row = array(
				'name'             => $data[ 'name' ],
				'email'            => $data[ 'email' ],
				'status'           => $data[ 'doubleoptin' ] ? 0 : 1,
				'created_at'       => $now->format( 'Y-m-d h:i:s' ), // GMT
				'confirmation_key' => $data[ 'doubleoptin' ] ? $this->generate_confirmation_key( $data[ 'email' ], true ) : ''
			);

			$result = $wpdb->insert( $wpdb->prefix . 'grabconversions_list_data', $row, array(
				'%s',
				'%s',
				'%d',
				'%s'
			) );

			if ( $result ) {
				if ( $data[ 'doubleoptin' ] ) { // shouldn't double optin be the only choice, no single-optins allowed #mustThink
					$this->send_double_optin_confirmation_email( $row[ 'email' ], $row[ 'confirmation_key' ] );
				}
			} else {
				// something went wrong, how often does that happen?
			}
		} else {
			// user is already a subscriber
			if ( $result[ 'status' ] == 1 ) {
				// user is already confirmed, nothing to do here till we have tags/segments to worry about
			} else if ( $result[ 'status' ] == 9 ) {
				// user is deleted, bring it back to life
				$wpdb->update( self::$subscribers_table_name, array( 'status' => 0 ), array( 'id' => $result[ 'id' ] ), array( '%d' ), array( '%d' ) );

				// send double-optin email to the subscriber now
				$this->send_double_optin_confirmation_email( $data[ 'email' ] );
			} else {
				// send another double-optin email to the subscriber
				$this->send_double_optin_confirmation_email( $data[ 'email' ] );
			}
		}
	}

	public function send_double_optin_confirmation_email( $email, $confirmation_key = '' ) {
		if ( !$confirmation_key ) {
			$confirmation_key = $this->generate_confirmation_key( $email );
		}

		$email_subscription_link = admin_url( 'admin-ajax.php?action=grabconversions_confirm_email_subscription&who=' . md5( $email ) . '&key=' . $confirmation_key );
		$email_body              = '<p>Hi, Thanks for signing up! Click on this link to confirm your email subscription - <a href="' . $email_subscription_link . '">' . $email_subscription_link . '</a></p>';
		wp_mail( $email, 'Activate your email subscription', $email_body );
	}

	public function confirm_email_subscription() {
		global $wpdb;

		$email_hash       = $_GET[ 'who' ];
		$confirmation_key = $_GET[ 'key' ];

		// lets search for a subscriber who has that confirmation key
		$result = $wpdb->get_row( $wpdb->prepare( "SELECT id, email FROM " . self::$subscribers_table_name . " WHERE confirmation_key = '%s';", $confirmation_key ), ARRAY_A );

		if ( is_null( $result ) ) {
			die( 'Sorry! The confirmation key doesn\'t match with any of the subscriber.' );
		} else {
			if ( $email_hash == md5( $result[ 'email' ] ) ) {
				// verified, mark as confirmed
				$wpdb->update( self::$subscribers_table_name, array( 'status' => 1 ), array( 'id' => $result[ 'id' ] ), array( '%d' ), array( '%d' ) );
				die( 'Congrats! Your subscription is confirmed!' );
			} else {
				die( 'Sorry! The URL seems to be invalid.' );
			}
		}
	}

	public function confirm_email_unsubscription() {
		global $wpdb;

		$email_hash       = $_GET[ 'who' ];
		$confirmation_key = $_GET[ 'key' ];

		// lets search for a subscriber who has that confirmation key
		$result = $wpdb->get_row( $wpdb->prepare( "SELECT id, email FROM " . self::$subscribers_table_name . " WHERE confirmation_key = '%s';", $confirmation_key ), ARRAY_A );

		if ( is_null( $result ) ) {
			die( 'Invalid link! We don\'t have any records for your email.' );
		} else {
			if ( $email_hash == md5( $result[ 'email' ] ) ) {
				// verified, mark status as deleted/unsubscribed
				$wpdb->update( self::$subscribers_table_name, array( 'status' => 9 ), array( 'id' => $result[ 'id' ] ), array( '%d' ), array( '%d' ) );
				die( 'Sorry to see you go! You have been unsubscribed.' );
			} else {
				die( 'Sorry! The URL seems to be invalid.' );
			}
		}
	}

	public function get_confirmed_subscribers_count() {
		global $wpdb;

		return $wpdb->get_var( "SELECT COUNT(*) FROM " . self::$subscribers_table_name . " WHERE status = 1;" );
	}
}

GrabConversions_Core::getInstance();