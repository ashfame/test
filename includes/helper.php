<?php

// die if called directly
defined( 'ABSPATH' ) || die();

class GrabConversions_Helper {

	/**
	 * Taken from Woocommerce since WordPress doesn't always have timezone_string saved in options table
	 *
	 * @return false|mixed|string|void
	 */
	public static function get_wp_timezone_string() {

		// if site timezone string exists, return it
		if ( $timezone = get_option( 'timezone_string' ) ) {
			return $timezone;
		}
		// get UTC offset, if it isn't set then return UTC
		if ( 0 === ( $utc_offset = get_option( 'gmt_offset', 0 ) ) ) {
			return 'UTC';
		}
		// adjust UTC offset from hours to seconds
		$utc_offset *= 3600;
		// attempt to guess the timezone string from the UTC offset
		$timezone = timezone_name_from_abbr( '', $utc_offset, 0 );
		// last try, guess timezone string manually
		if ( false === $timezone ) {
			$is_dst = date( 'I' );
			foreach ( timezone_abbreviations_list() as $abbr ) {
				foreach ( $abbr as $city ) {
					if ( $city[ 'dst' ] == $is_dst && $city[ 'offset' ] == $utc_offset ) {
						return $city[ 'timezone_id' ];
					}
				}
			}

			return 'UTC'; // fallback to UTC
		}

		return $timezone;
	}
}