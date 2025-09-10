<?php
/*
Plugin Name: Scheduled Dev URL Reference Search
Description: Searches specific columns in certain tables for references to the dev URL and emails the admin with matching table names.
Version: 1.2
Author: Pavel Burminsky
*/

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

class Scheduled_Dev_URL_Search {
	private $search_patterns;
	private $row_limit_per_column;
	private $urls_per_row_limit;
	private $snippet_len;

	public function __construct() {
		$this->search_patterns      = ['%.wpengine.com%', '%.wpenginepowered.com%'];

		$this->row_limit_per_column = 30;   // max rows to include per table/column
		$this->urls_per_row_limit   = 5;    // max URLs to show per matching row
		$this->snippet_len          = 160;  // characters around the first match for context

		add_action( 'wp', [$this, 'schedule_daily_dev_url_search'] );
		add_action( 'daily_dev_url_search_event', [$this, 'search_dev_references_and_notify'] );
		register_deactivation_hook( __FILE__, [$this, 'unschedule_daily_dev_url_search'] );
	}

	public function schedule_daily_dev_url_search() {
		if ( $this->is_production() && ! wp_next_scheduled( 'daily_dev_url_search_event' ) ) {
			wp_schedule_event( time(), 'daily', 'daily_dev_url_search_event' );
		}
	}

	public function search_dev_references_and_notify() {
		if ( ! $this->is_production() ) {
			return;
		}

		global $wpdb;

		$prefix = $wpdb->prefix;
		$tables_and_columns = [
			"{$prefix}posts"            => ['post_excerpt', 'post_content', 'guid'],
			"{$prefix}postmeta"         => ['meta_value'],
			"{$prefix}redirection_items"=> ['action_data'],
		];

		$report_sections = [];
		$total_matches   = 0;

		foreach ( $tables_and_columns as $table => $columns ) {
			$tbl_exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
				$table
			) );
			if ( ! $tbl_exists ) {
				continue;
			}

			foreach ( $columns as $column ) {
				$likes = implode( ' OR ', array_fill( 0, count( $this->search_patterns ), "$column LIKE %s" ) );

				$count_sql = "SELECT COUNT(*) FROM $table WHERE ($likes)";
				$count_vals = $this->search_patterns;
				$count      = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_vals ) );

				if ( $count <= 0 ) {
					continue;
				}

				$total_matches += $count;

				$select_fields = $this->select_fields_for_table( $table, $column );
				$detail_sql    = "SELECT $select_fields FROM $table WHERE ($likes) LIMIT %d";
				$detail_vals   = array_merge( $this->search_patterns, [ $this->row_limit_per_column ] );
				$rows          = $wpdb->get_results( $wpdb->prepare( $detail_sql, $detail_vals ), ARRAY_A );

				$section_lines = [];
				$section_lines[] = "Table: $table | Column: $column";
				$section_lines[] = "Total matches: $count (showing up to {$this->row_limit_per_column})";

				foreach ( $rows as $row ) {
					$raw = isset( $row[$column] ) ? (string) $row[$column] : '';

					$urls = $this->extract_dev_urls( $raw );
					if ( empty( $urls ) ) {
						$snippet = $this->make_snippet_from_patterns( $raw, $this->search_patterns, $this->snippet_len );
					} else {
						$urls = array_slice( array_values( array_unique( $urls ) ), 0, $this->urls_per_row_limit );
						$snippet = $this->make_snippet_from_first_url( $raw, $urls[0], $this->snippet_len );
					}

					$identifier = $this->format_row_identifier( $table, $row );
					$urls_line  = !empty($urls) ? "URLs: " . implode( ', ', $urls ) : "URLs: (not parsed; see snippet)";
					$section_lines[] = "- {$identifier}\n  {$urls_line}\n  Snippet: {$snippet}";
				}

				$report_sections[] = implode( "\n", $section_lines );
			}
		}

		if ( ! empty( $report_sections ) ) {
			$site = home_url();
			$subject = 'Daily Dev URL Search Results';
			$header  = "Site: $site\nDate: " . gmdate( 'Y-m-d' ) . "\nTotal matches (all tables): $total_matches\n";
			$body    = $header . "\n" . implode( "\n\n-----------------------------\n\n", $report_sections );

			$this->send_notification( $subject, $body );
		}
	}

	private function send_notification( $subject, $body ) {
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
		$home = home_url();
		return ( strpos( $home, 'wpengine.com' ) === false && strpos( $home, 'wpenginepowered.com' ) === false );
	}

	private function select_fields_for_table( $table, $column ) {
		if ( preg_match( '/_posts$/', $table ) ) {
			return "ID, post_title, post_type, post_status, $column";
		}
		if ( preg_match( '/_postmeta$/', $table ) ) {
			return "meta_id, post_id, meta_key, $column";
		}
		if ( preg_match( '/_redirection_items$/', $table ) ) {
			return "id, action_type, action_code, action_data, $column";
		}

		return "$column";
	}

	private function format_row_identifier( $table, $row ) {
		if ( preg_match( '/_posts$/', $table ) ) {
			$title = isset( $row['post_title'] ) ? $row['post_title'] : '';
			$title = $title !== '' ? $this->trim_text( $title, 80 ) : '(no title)';
			$type  = isset( $row['post_type'] ) ? $row['post_type'] : 'post';
			$stat  = isset( $row['post_status'] ) ? $row['post_status'] : '';
			return "Post ID {$row['ID']} [type={$type}, status={$stat}, title=\"{$title}\"]";
		}
		if ( preg_match( '/_postmeta$/', $table ) ) {
			$key = isset( $row['meta_key'] ) ? $row['meta_key'] : '';
			return "Meta ID {$row['meta_id']} [post_id={$row['post_id']}, meta_key=\"{$key}\"]";
		}
		if ( preg_match( '/_redirection_items$/', $table ) ) {
			$atype = isset( $row['action_type'] ) ? $row['action_type'] : '';
			$acode = isset( $row['action_code'] ) ? $row['action_code'] : '';
			return "Redirection ID {$row['id']} [action_type={$atype}, action_code={$acode}]";
		}
		return 'Match';
	}

	private function extract_dev_urls( $text ) {
		$urls = [];
		if ( ! is_string( $text ) || $text === '' ) {
			return $urls;
		}
		$pattern = '~https?://[^\s"\'<>]+~i';
		if ( preg_match_all( $pattern, $text, $m ) ) {
			foreach ( $m[0] as $u ) {
				if ( stripos( $u, '.wpengine.com' ) !== false || stripos( $u, '.wpenginepowered.com' ) !== false ) {
					$urls[] = rtrim( $u, ".,);]" );
				}
			}
		}
		return $urls;
	}

	private function make_snippet_from_first_url( $text, $url, $len ) {
		$pos = stripos( $text, $url );
		if ( $pos === false ) {
			return $this->trim_text( $text, $len );
		}
		return $this->context_snippet( $text, $pos, strlen( $url ), $len );
	}

	private function make_snippet_from_patterns( $text, $patterns, $len ) {
		$lowest = false;
		foreach ( $patterns as $p ) {
			$needle = str_ireplace('%', '', $p);
			$pos = stripos( $text, $needle );
			if ( $pos !== false && ($lowest === false || $pos < $lowest) ) {
				$lowest = $pos;
			}
		}
		if ( $lowest === false ) {
			return $this->trim_text( $text, $len );
		}
		return $this->context_snippet( $text, $lowest, 0, $len );
	}

	private function context_snippet( $text, $start, $match_len, $len ) {
		$half = max( 20, (int) floor( $len / 2 ) );
		$from = max( 0, $start - $half );
		$to   = min( strlen( $text ), $start + $match_len + $half );
		$snippet = substr( $text, $from, $to - $from );
		$snippet = $this->collapse_ws( $snippet );
		$prefix  = $from > 0 ? '…' : '';
		$suffix  = $to < strlen( $text ) ? '…' : '';
		return $prefix . $snippet . $suffix;
	}

	private function trim_text( $text, $len ) {
		$text = $this->collapse_ws( $text );
		if ( strlen( $text ) <= $len ) {
			return $text;
		}
		return substr( $text, 0, $len - 1 ) . '…';
	}

	private function collapse_ws( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}
}

new Scheduled_Dev_URL_Search();
