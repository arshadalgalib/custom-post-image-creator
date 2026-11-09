<?php
/**
 * Date formatting helpers (Bengali numerals + month names).
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts timestamps into the formats the renderer expects. All conversions
 * are pure string operations so they behave identically on every host.
 */
final class Dates {

	/**
	 * Bengali digits.
	 */
	const DIGITS = array( '০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯' );

	/**
	 * Bengali month names (Gregorian order).
	 *
	 * @return array<int,string>
	 */
	public function months() {
		return array(
			1  => 'জানুয়ারি',
			2  => 'ফেব্রুয়ারি',
			3  => 'মার্চ',
			4  => 'এপ্রিল',
			5  => 'মে',
			6  => 'জুন',
			7  => 'জুলাই',
			8  => 'আগস্ট',
			9  => 'সেপ্টেম্বর',
			10 => 'অক্টোবর',
			11 => 'নভেম্বর',
			12 => 'ডিসেম্বর',
		);
	}

	/**
	 * Weekday names.
	 *
	 * @return array<int,string>
	 */
	public function weekdays() {
		return array(
			0 => 'রবিবার',
			1 => 'সোমবার',
			2 => 'মঙ্গলবার',
			3 => 'বুধবার',
			4 => 'বৃহস্পতিবার',
			5 => 'শুক্রবার',
			6 => 'শনিবার',
		);
	}

	/**
	 * Replace ASCII digits with Bengali digits.
	 *
	 * @param string $value Input.
	 * @return string
	 */
	public function to_bengali_digits( $value ) {
		return str_replace( array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ), self::DIGITS, (string) $value );
	}

	/**
	 * Replace Bengali digits with ASCII digits.
	 *
	 * @param string $value Input.
	 * @return string
	 */
	public function to_ascii_digits( $value ) {
		return str_replace( self::DIGITS, array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ), (string) $value );
	}

	/**
	 * Format a timestamp.
	 *
	 * Supported formats:
	 *  - bangla_long  : ১৪ আগস্ট ২০২৬
	 *  - bangla_short : ১৪ আগস্ট
	 *  - bangla_full  : শুক্রবার, ১৪ আগস্ট ২০২৬
	 *  - iso          : Y-m-d
	 *  - site         : WordPress date format
	 *  - custom       : a PHP date() pattern
	 *
	 * @param int|null $timestamp Unix timestamp (site time). Null = now.
	 * @param string   $format    Format key.
	 * @param string   $custom    Custom PHP date pattern.
	 * @return string
	 */
	public function format( $timestamp = null, $format = 'bangla_long', $custom = '' ) {
		$timestamp = null === $timestamp ? time() : (int) $timestamp;

		$year  = (int) gmdate( 'Y', $timestamp );
		$month = (int) gmdate( 'n', $timestamp );
		$day   = (int) gmdate( 'j', $timestamp );
		$dow   = (int) gmdate( 'w', $timestamp );

		$months = $this->months();

		switch ( $format ) {
			case 'bangla_short':
				return $this->to_bengali_digits( $day ) . ' ' . $months[ $month ];

			case 'bangla_full':
				$weekdays = $this->weekdays();
				return $weekdays[ $dow ] . ', ' . $this->to_bengali_digits( $day ) . ' ' . $months[ $month ] . ' ' . $this->to_bengali_digits( $year );

			case 'iso':
				return sprintf( '%04d-%02d-%02d', $year, $month, $day );

			case 'site':
				return (string) gmdate( (string) get_option( 'date_format', 'F j, Y' ), $timestamp );

			case 'custom':
				if ( '' === $custom ) {
					$custom = 'j F Y';
				}
				return $this->to_bengali_digits( (string) gmdate( $custom, $timestamp ) );

			case 'bangla_long':
			default:
				return $this->to_bengali_digits( $day ) . ' ' . $months[ $month ] . ' ' . $this->to_bengali_digits( $year );
		}
	}

	/**
	 * Format a WordPress post timestamp using the *site* timezone (not the
	 * browser timezone, which is what caused day-shift bugs in 6.x).
	 *
	 * @param int    $post_id Post id.
	 * @param string $format  Format key.
	 * @return string
	 */
	public function format_post_date( $post_id, $format = 'bangla_long' ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return $this->format( time(), $format );
		}

		$timestamp = function_exists( 'get_post_timestamp' )
			? (int) get_post_timestamp( $post )
			: strtotime( $post->post_date );

		if ( ! $timestamp ) {
			$timestamp = time();
		}

		return $this->format( $timestamp, $format );
	}

	/**
	 * Parse a MySQL datetime into a site-timezone timestamp.
	 *
	 * @param string $datetime MySQL datetime.
	 * @return int
	 */
	public function to_timestamp( $datetime ) {
		$datetime = (string) $datetime;
		if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return time();
		}

		$timestamp = strtotime( $datetime );

		return false === $timestamp ? time() : $timestamp;
	}

	/**
	 * Human relative time (admin UI).
	 *
	 * @param string $datetime MySQL datetime.
	 * @return string
	 */
	public function relative( $datetime ) {
		$timestamp = $this->to_timestamp( $datetime );
		$diff      = time() - $timestamp;

		if ( $diff < 60 ) {
			return __( 'just now', 'custom-post-image-creator' );
		}
		if ( $diff < HOUR_IN_SECONDS ) {
			/* translators: %d: minutes */
			return sprintf( __( '%d min ago', 'custom-post-image-creator' ), (int) floor( $diff / 60 ) );
		}
		if ( $diff < DAY_IN_SECONDS ) {
			/* translators: %d: hours */
			return sprintf( __( '%d hours ago', 'custom-post-image-creator' ), (int) floor( $diff / HOUR_IN_SECONDS ) );
		}

		/* translators: %d: days */
		return sprintf( __( '%d days ago', 'custom-post-image-creator' ), (int) floor( $diff / DAY_IN_SECONDS ) );
	}

	/**
	 * Validate a MySQL datetime string.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public function is_valid_datetime( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return false;
		}

		$dt = \DateTime::createFromFormat( 'Y-m-d H:i:s', $value );

		return $dt && $dt->format( 'Y-m-d H:i:s' ) === $value;
	}
}
