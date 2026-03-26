<?php
/**
 * View helpers for the Pricing Rules admin page.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static utility methods for building schedule badge HTML and the full rules view array
 * consumed by the Pricing Rules list template.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_Pricing_Rules_View_Helper {

	/**
	 * Fetch the name of a customer group by its ID.
	 *
	 * @since  1.0.0
	 * @param  int $group_id Customer group ID.
	 * @return string Group name, or 'Unknown Group' if the ID does not exist.
	 */
	public static function get_group_name_by_id( $group_id ) {
		global $wpdb;

		$name = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT group_name FROM {$wpdb->prefix}customer_groups WHERE group_id = %d",
				$group_id
			)
		);

		return $name ? $name : __( 'Unknown Group', 'alynt-customer-groups' );
	}

	/**
	 * Build schedule status and display HTML for a single pricing rule.
	 *
	 * @since  1.1.0
	 * @param  object $rule stdClass with start_date, end_date, and is_active properties.
	 * @return array {
	 *     @type string $status       One of 'active', 'scheduled', or 'expired'.
	 *     @type string $badge_html   Rendered HTML for the status badge span.
	 *     @type string $display_html Rendered HTML showing start/end dates in local time.
	 *     @type bool   $has_schedule Whether the rule has at least one date constraint.
	 * }
	 */
	public static function build_schedule_data( $rule ) {
		$status       = 'active';
		$badge_html   = '';
		$display_html = '';
		$now          = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$has_schedule = ! empty( $rule->start_date ) || ! empty( $rule->end_date );

		if ( $has_schedule ) {
			$start_dt = ! empty( $rule->start_date ) ? new DateTime( $rule->start_date, new DateTimeZone( 'UTC' ) ) : null;
			$end_dt   = ! empty( $rule->end_date ) ? new DateTime( $rule->end_date, new DateTimeZone( 'UTC' ) ) : null;

			if ( $start_dt && $start_dt > $now ) {
				$status     = 'scheduled';
				$badge_html = '<span class="wccg-status-badge wccg-status-scheduled">' . esc_html__( 'Scheduled', 'alynt-customer-groups' ) . '</span>';
			} elseif ( $end_dt && $end_dt < $now ) {
				$status     = 'expired';
				$badge_html = '<span class="wccg-status-badge wccg-status-expired">' . esc_html__( 'Expired', 'alynt-customer-groups' ) . '</span>';
			} else {
				$badge_html = '<span class="wccg-status-badge wccg-status-active">' . esc_html__( 'Active', 'alynt-customer-groups' ) . '</span>';
			}

			$date_format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			$schedule_parts = array();
			if ( $start_dt ) {
				$schedule_parts[] = '<strong>' . esc_html__( 'Start:', 'alynt-customer-groups' ) . '</strong> ' . esc_html( get_date_from_gmt( $rule->start_date, $date_format ) );
			}
			if ( $end_dt ) {
				$schedule_parts[] = '<strong>' . esc_html__( 'End:', 'alynt-customer-groups' ) . '</strong> ' . esc_html( get_date_from_gmt( $rule->end_date, $date_format ) );
			}
			if ( ! empty( $schedule_parts ) ) {
				$display_html = '<div class="wccg-schedule-dates">' . implode( '<br>', $schedule_parts ) . '</div>';
			}
		} else {
			$badge_html = '<span class="wccg-status-badge wccg-status-active">' . esc_html__( 'Always Active', 'alynt-customer-groups' ) . '</span>';
		}

		return array(
			'status'       => $status,
			'badge_html'   => $badge_html,
			'display_html' => $display_html,
			'has_schedule' => $has_schedule,
		);
	}

	/**
	 * Transform raw pricing rule database rows into a structured view array for the list template.
	 *
	 * @since  1.0.0
	 * @param  object[] $pricing_rules Keyed array of stdClass rule rows from WCCG_Admin_Pricing_Rules_Page.
	 * @return array[] Array of associative arrays, each containing display-ready rule data.
	 */
	public static function build_pricing_rules_view( $pricing_rules ) {
		$rules_view = array();

		foreach ( $pricing_rules as $rule ) {
			$product_ids    = ! empty( $rule->product_ids ) ? array_filter( array_map( 'intval', explode( ',', $rule->product_ids ) ) ) : array();
			$category_ids   = ! empty( $rule->category_ids ) ? array_filter( array_map( 'intval', explode( ',', $rule->category_ids ) ) ) : array();
			$product_names  = array();
			$category_names = array();

			foreach ( $product_ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$product_names[] = $product->get_name();
				}
			}

			foreach ( $category_ids as $category_id ) {
				$category = get_term( $category_id, 'product_cat' );
				if ( $category && ! is_wp_error( $category ) ) {
					$category_names[] = $category->name;
				}
			}

			$schedule_data = self::build_schedule_data( $rule );
			$is_active     = isset( $rule->is_active ) ? (int) $rule->is_active : 1;

			$rules_view[] = array(
				'rule_id'                => (int) $rule->rule_id,
				'group_name'             => self::get_group_name_by_id( $rule->group_id ),
				'discount_type'          => $rule->discount_type,
				'discount_value_display' => $rule->discount_type === 'percentage' ? $rule->discount_value . '%' : get_woocommerce_currency_symbol() . $rule->discount_value,
				'product_names'          => $product_names,
				'category_names'         => $category_names,
				'is_active'              => $is_active,
				'created_at'             => $rule->created_at,
				'start_date'             => $rule->start_date,
				'end_date'               => $rule->end_date,
				'schedule'               => $schedule_data,
				'start_local'            => ! empty( $rule->start_date ) ? get_date_from_gmt( $rule->start_date, 'Y-m-d\TH:i' ) : '',
				'end_local'              => ! empty( $rule->end_date ) ? get_date_from_gmt( $rule->end_date, 'Y-m-d\TH:i' ) : '',
			);
		}

		return $rules_view;
	}
}
