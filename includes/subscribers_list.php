<?php

if ( !class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class GrabConversions_Subscribers_List_Table extends WP_List_Table {

	private static $subscribers_table_name;

	public function __construct() {
		global $wpdb;

		self::$subscribers_table_name = $wpdb->prefix . 'grabconversions_list_data';

		parent::__construct( array(
			'singular' => __( 'subscriber', 'whatsapp' ),
			'plural'   => __( 'subscribers', 'whatsapp' )
		) );
	}

	public function no_items() {
		if ( isset( $_POST[ 'page' ] ) && isset( $_POST[ 's' ] ) && $_POST[ 'page' ] == 'grabconversions_subscriber_search' ) {
			_e( 'No subscribers found!' );
		} else {
			_e( 'No subscribers found! Why not add yourself as the first subscriber as a test? You will also get to see how your subscribers see the emails in their inbox, when you send a broadcast.' );
		}
	}

	public function get_columns() {
		$columns = array(
			'cb'     => '<input type="checkbox" />',
			'name'   => __( 'Name' ),
			'email'  => __( 'Email address' ),
			'status' => __( 'Status' )
		);

		return $columns;
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'name':
			case 'email':
				return $item[ $column_name ];
			case 'status':
				switch ( $item[ $column_name ] ) {
					case 1:
						return '✔';
						break;
					case 0:
						return ''; //'Resend verification email';
						break;
					case 9:
						return 'x';
						break;
					default:
						return print_r( $item, true );
				}
			default:
				return print_r( $item, true ); //Show the whole array for troubleshooting purposes
		}
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="subscribers[]" value="%s" />', $item[ 'id' ] );
	}

	public function prepare_items() {
		global $wpdb, $current_user;

		$columns               = $this->get_columns();
		$hidden                = array();
		$sortable              = array();
		$this->_column_headers = array( $columns, $hidden, $sortable );

		if ( isset( $_POST[ 'page' ] ) && isset( $_POST[ 's' ] ) && $_POST[ 'page' ] == 'grabconversions_subscriber_search' ) {
			$search_string = $_POST[ 's' ];

			if ( is_email( $search_string ) ) {
				$query = "SELECT id, name, email, status FROM " . self::$subscribers_table_name . " WHERE email = '$search_string' AND status <> 9 ORDER BY id DESC;";
			} else {
				echo $query = "SELECT id, name, email, status FROM " . self::$subscribers_table_name . " WHERE name like '%$search_string%' or email like '%$search_string%' AND status <> 9 ORDER BY id DESC;";
			}

			$this->items = $wpdb->get_results( $query, ARRAY_A );

		} else {
			$query = "SELECT id, name, email, status FROM " . self::$subscribers_table_name . " WHERE status <> 9 ORDER BY id DESC;";

			$data = $wpdb->get_results( $query, ARRAY_A );

			$per_page = absint( get_user_meta( $current_user->ID, 'subscribers_per_page', true ) );
			$per_page = $per_page ? $per_page : 100;
			$current_page = $this->get_pagenum();
			$total_items  = count( $data );

			$this->set_pagination_args( array(
				'total_items' => $total_items,
				'per_page'    => $per_page
			) );

			$this->items = array_slice( $data, ( ( $current_page - 1 ) * $per_page ), $per_page );
		}
	}
}