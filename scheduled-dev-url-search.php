<?php
/*
Plugin Name: Scheduled Dev URL Reference Search
Description: Searches specific columns in certain tables for references to the dev URL and emails the admin with matching table names.
Version: 1.1
Author: Pavel Burminsky
*/

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

class Scheduled_Dev_URL_Search {
	private $search_patterns;

	public function __construct() {
		$this->search_patterns = ['%.wpengine.com%', '%.wpenginepowered.com%'];

		add_action( 'wp', [$this, 'schedule_daily_dev_url_search'] );
		add_action( 'daily_dev_url_search_event', [$this, 'search_dev_references_and_notify'] );
		register_deactivation_hook( __FILE__, [$this, 'unschedule_daily_dev_url_search'] );
	}

	public function schedule_daily_dev_url_search() {
		if ( $this->is_production() && !wp_next_scheduled( 'daily_dev_url_search_event' ) ) {
			wp_schedule_event( time(), 'daily', 'daily_dev_url_search_event' );
		}
	}

	public function search_dev_references_and_notify() {
		if ( !$this->is_production() ) {
			return;
		}

		global $wpdb;
		$matching_tables = [];

		$tables_and_columns = [
			'wp_posts' => ['post_excerpt', 'post_content', 'guid'],
			'wp_postmeta' => ['meta_value'],
			'wp_options' => ['option_value'],
			'redirection_items' => ['action_data'],
		];

		foreach ( $tables_and_columns as $table => $columns ) {
			foreach ( $columns as $column ) {
				foreach ( $this->search_patterns as $pattern ) {
					$result = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $column LIKE %s", $pattern ) );

					if ( $result > 0 ) {
						$matching_tables[] = "$table ($column)";
						break 2;
					}
				}
			}
		}

		if ( !empty( $matching_tables ) ) {
			$this->send_notification( $matching_tables );
		}
	}

	private function send_notification( $matching_tables ) {
		$subject = 'Daily Dev URL Search Results';
		$body = "The following tables contain references to the development URL patterns:\n\n" . implode( "\n", array_unique( $matching_tables ) );
		$admin_email = get_option( 'admin_email' );

		wp_mail( $admin_email, $subject, $body );
	}

	public function unschedule_daily_dev_url_search() {
		$timestamp = wp_next_scheduled( 'daily_dev_url_search_event' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'daily_dev_url_search_event' );
		}
	}

	private function is_production() {
		return strpos( home_url(), 'wpengine.com' ) === false && strpos( home_url(), 'wpenginepowered.com' ) === false;
	}
}

new Scheduled_Dev_URL_Search();



