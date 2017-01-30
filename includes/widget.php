<?php

// die if called directly
defined( 'ABSPATH' ) || die();

class GrabConversions_Core_Widget extends WP_Widget {

	/**
	 * Sets up the widgets name etc
	 */
	public function __construct() {
		parent::__construct( 'grabconversions_widget', __( 'GrabConversions Widget', 'grabconversions' ), array(
			'classname'   => 'grabconversions_widget',
			'description' => __( 'Collect emails to build your email list', 'grabconversions' ),
		) );

		add_action( 'wp_ajax_grabconversions_widget_submission', array( $this, 'catch_data' ) );
		add_action( 'wp_ajax_nopriv_grabconversions_widget_submission', array( $this, 'catch_data' ) );
	}

	/**
	 * Outputs the content of the widget
	 *
	 * @param array $args
	 * @param array $instance
	 */
	public function widget( $args, $instance ) {
		wp_enqueue_script( 'grabconversions_widget_js' );

		echo $args[ 'before_widget' ];
		if ( ! empty( $instance[ 'title' ] ) ) {
			echo $args[ 'before_title' ] . apply_filters( 'widget_title', $instance[ 'title' ] ) . $args[ 'after_title' ];
		}

		echo '<p>' . $instance[ 'content' ] . '</p>';
		?>

		<form class="grabconversions-widget">
			<input type="text" name="name" placeholder="First Name" />
			<br /><br />
			<input type="email" name="email" placeholder="Email Address" />
			<br /><br />
			<input type="hidden" name="action" value="grabconversions_widget_submission" />
			<input type="submit" />
		</form>

		<?php

		echo $args[ 'after_widget' ];
	}

	/**
	 * Outputs the options form on admin
	 *
	 * @param array $instance The widget options
	 */
	public function form( $instance ) {
		$title   = ! empty( $instance[ 'title' ] ) ? $instance[ 'title' ] : esc_html__( 'New title', 'text_domain' );
		$content = ! empty( $instance[ 'content' ] ) ? $instance[ 'content' ] : esc_html__( 'Enter some content to encourage visitors to sign up', 'text_domain' );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_attr_e( 'Title:', 'text_domain' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'content' ) ); ?>"><?php esc_attr_e( 'Content:', 'text_domain' ); ?></label>
			<textarea class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'content' ) ); ?>" rows="5" cols="20" name="<?php echo esc_attr( $this->get_field_name( 'content' ) ); ?>"><?php echo esc_attr( $content ); ?></textarea>
		</p>
		<?php
	}

	/**
	 * Processing widget options on save
	 *
	 * @param array $new_instance The new options
	 * @param array $old_instance The previous options
	 */
	public function update( $new_instance, $old_instance ) {
		$instance              = array();
		$instance[ 'title' ]   = ( ! empty( $new_instance[ 'title' ] ) ) ? strip_tags( $new_instance[ 'title' ] ) : '';
		$instance[ 'content' ] = ( ! empty( $new_instance[ 'content' ] ) ) ? $new_instance[ 'content' ] : '';

		return $instance;
	}

	public function catch_data() {
		if ( ! is_email( $_REQUEST[ 'email' ] ) ) {
			wp_send_json_error( array( 'reason' => 'Invalid email address' ) );
		}

		$name  = $_REQUEST[ 'name' ];
		$email = $_REQUEST[ 'email' ];

		do_action( 'grabconversions_announce_optin', array(
			'source'      => 'widget',
			'name'        => $name,
			'email'       => $email,
			'doubleoptin' => true
		) );

		wp_send_json_success();
	}
}

