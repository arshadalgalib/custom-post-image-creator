<?php
/**
 * Bangla date conversion helper.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Bangla_Date {

    /**
     * English to Bangla digit map.
     *
     * @var array
     */
    private static $digits = array(
        '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪',
        '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯',
    );

    /**
     * English month names to Bangla.
     *
     * @var array
     */
    private static $months = array(
        'January'   => 'জানুয়ারি',
        'February'  => 'ফেব্রুয়ারি',
        'March'     => 'মার্চ',
        'April'     => 'এপ্রিল',
        'May'       => 'মে',
        'June'      => 'জুন',
        'July'      => 'জুলাই',
        'August'    => 'আগস্ট',
        'September' => 'সেপ্টেম্বর',
        'October'   => 'অক্টোবর',
        'November'  => 'নভেম্বর',
        'December'  => 'ডিসেম্বর',
    );

    /**
     * Convert English digits to Bangla.
     *
     * @param string $str Input containing English digits.
     * @return string
     */
    public static function to_bangla_digits( $str ) {
        return strtr( (string) $str, self::$digits );
    }

    /**
     * Convert a date string or timestamp to Bangla formatted date.
     *
     * Output example: ১২ মার্চ ২০২৬
     *
     * The timestamp must be a real (UTC based) Unix timestamp, because
     * wp_date() applies the site timezone offset itself. Never pass the
     * result of get_the_date( 'U' ) / get_post_time( 'U' ) / current_time(
     * 'timestamp' ) here: those already add the offset, so the date would be
     * shifted twice and can land on the wrong calendar day.
     *
     * Date strings without an explicit timezone are treated as site local
     * time, the same way WordPress core's mysql2date() does.
     *
     * @param string|int|\DateTimeInterface $date Date string or Unix timestamp.
     * @return string
     */
    public static function format( $date = '' ) {
        if ( $date instanceof \DateTimeInterface ) {
            $timestamp = $date->getTimestamp();
        } elseif ( is_int( $date ) || ( is_string( $date ) && is_numeric( $date ) ) ) {
            $timestamp = (int) $date;
        } elseif ( $date ) {
            // Parse in the site timezone so "2026-03-12 19:00:00" is read as
            // local wall time instead of UTC (PHP defaults to UTC in WP).
            $datetime = date_create( $date, wp_timezone() );
            $timestamp = ( false === $datetime ) ? time() : $datetime->getTimestamp();
        } else {
            $timestamp = time();
        }

        $month_en = wp_date( 'F', $timestamp );
        $bn_day   = self::to_bangla_digits( wp_date( 'j', $timestamp ) );
        $bn_month = isset( self::$months[ $month_en ] ) ? self::$months[ $month_en ] : $month_en;
        $bn_year  = self::to_bangla_digits( wp_date( 'Y', $timestamp ) );

        return $bn_day . ' ' . $bn_month . ' ' . $bn_year;
    }
}
