<?php
/**
 * Plugin Name: LLM Bot Monitor
 * Plugin URI:  https://github.com/erichinzpeter/llm-bot-monitor
 * Description: Tracks AI/LLM bot crawlers visiting your site. GDPR-compliant — only bot traffic is logged, never human visitors.
 * Version:     2.4.0
 * Author:      Eric Hinzpeter
 * Author URI:  https://eric-hinzpeter.de
 * License:     GPL-2.0-or-later
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Text Domain: llm-bot-monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LLM_BOT_MONITOR_VERSION', '2.4.0' );
define( 'LLM_BOT_MONITOR_TABLE', 'llm_bot_log' );

/* ==========================================================================
   1. ACTIVATION / DEACTIVATION / DB VERSION CHECK
   ========================================================================== */

function llm_bot_monitor_activate(): void {
	global $wpdb;
	$table   = $wpdb->prefix . LLM_BOT_MONITOR_TABLE;
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		hit_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		bot_name varchar(80) NOT NULL DEFAULT '',
		request_url varchar(2048) NOT NULL DEFAULT '',
		ip_address varchar(45) NOT NULL DEFAULT '',
		user_agent varchar(512) NOT NULL DEFAULT '',
		status_code smallint(5) unsigned NOT NULL DEFAULT 200,
		PRIMARY KEY  (id),
		KEY idx_hit_at (hit_at),
		KEY idx_bot_name (bot_name,hit_at),
		KEY idx_ip (ip_address)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	// Remove log entries for bots no longer in the active list.
	$known_names    = array_column( llm_bot_monitor_bot_list(), 'name' );
	$placeholders   = implode( ', ', array_fill( 0, count( $known_names ), '%s' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE bot_name NOT IN ({$placeholders})", $known_names ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Anonymize existing IP addresses (zero last 2 octets for IPv4).
	$wpdb->query( "UPDATE {$table} SET ip_address = CONCAT(SUBSTRING_INDEX(ip_address, '.', 2), '.0.0') WHERE ip_address LIKE '%.%.%.%' AND ip_address NOT LIKE '%.0.0'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	// Anonymize IPv6 addresses via PHP (low volume expected).
	$ipv6_rows = $wpdb->get_results( "SELECT id, ip_address FROM {$table} WHERE ip_address LIKE '%:%' AND ip_address != '0.0.0.0'" );
	foreach ( $ipv6_rows as $row ) {
		$anon = llm_bot_monitor_anonymize_ip( $row->ip_address );
		if ( $anon !== $row->ip_address ) {
			$wpdb->update( $table, array( 'ip_address' => $anon ), array( 'id' => $row->id ), array( '%s' ), array( '%d' ) );
		}
	}

	update_option( 'llm_bot_monitor_db_version', LLM_BOT_MONITOR_VERSION );

	if ( ! wp_next_scheduled( 'llm_bot_monitor_daily_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'llm_bot_monitor_daily_cleanup' );
	}
}
register_activation_hook( __FILE__, 'llm_bot_monitor_activate' );

function llm_bot_monitor_deactivate(): void {
	wp_clear_scheduled_hook( 'llm_bot_monitor_daily_cleanup' );
}
register_deactivation_hook( __FILE__, 'llm_bot_monitor_deactivate' );

// Runs on every admin page load; calls activate() when the stored DB version
// doesn't match the plugin version. Safe for updates: dbDelta() only adds
// missing columns/indexes and never drops or truncates data.
function llm_bot_monitor_check_db(): void {
	if ( get_option( 'llm_bot_monitor_db_version' ) !== LLM_BOT_MONITOR_VERSION ) {
		llm_bot_monitor_activate();
	}
}
add_action( 'admin_init', 'llm_bot_monitor_check_db' );

/* ==========================================================================
   2. BOT LIST & DETECTION
   ========================================================================== */

function llm_bot_monitor_bot_list(): array {
	return array(
		// OpenAI
		'GPTBot'               => array( 'name' => 'GPTBot',               'provider' => 'OpenAI',       'category' => 'training' ),
		'ChatGPT-User'         => array( 'name' => 'ChatGPT-User',         'provider' => 'OpenAI',       'category' => 'grounding' ),
		'OAI-SearchBot'        => array( 'name' => 'OAI-SearchBot',        'provider' => 'OpenAI',       'category' => 'grounding' ),

		// Anthropic
		'ClaudeBot'            => array( 'name' => 'ClaudeBot',            'provider' => 'Anthropic',    'category' => 'training' ),
		'Claude-User'          => array( 'name' => 'Claude-User',          'provider' => 'Anthropic',    'category' => 'grounding' ),
		'Claude-SearchBot'     => array( 'name' => 'Claude-SearchBot',     'provider' => 'Anthropic',    'category' => 'grounding' ),

		// Google
		'Google-Extended'      => array( 'name' => 'Google-Extended',      'provider' => 'Google',       'category' => 'training' ),
		'Gemini-Deep-Research' => array( 'name' => 'Gemini-Deep-Research', 'provider' => 'Google',       'category' => 'grounding' ),
		'Google-Agent'         => array( 'name' => 'Google-Agent',         'provider' => 'Google',       'category' => 'grounding' ),

		// Perplexity
		'PerplexityBot'        => array( 'name' => 'PerplexityBot',        'provider' => 'Perplexity',   'category' => 'grounding' ),
		'Perplexity-User'      => array( 'name' => 'Perplexity-User',      'provider' => 'Perplexity',   'category' => 'grounding' ),

		// Meta
		'Meta-ExternalAgent'   => array( 'name' => 'Meta-ExternalAgent',   'provider' => 'Meta',         'category' => 'training' ),
		'Meta-ExternalFetcher' => array( 'name' => 'Meta-ExternalFetcher', 'provider' => 'Meta',         'category' => 'grounding' ),

		// Apple
		'Applebot-Extended'    => array( 'name' => 'Applebot-Extended',    'provider' => 'Apple',        'category' => 'training' ),
		'Applebot'             => array( 'name' => 'Applebot',             'provider' => 'Apple',        'category' => 'grounding' ),

		// Microsoft
		'Bingbot'              => array( 'name' => 'Bingbot',              'provider' => 'Microsoft',    'category' => 'grounding' ),

		// ByteDance
		'Bytespider'           => array( 'name' => 'Bytespider',           'provider' => 'ByteDance',    'category' => 'training' ),

		// Common Crawl
		'CCBot'                => array( 'name' => 'CCBot',                'provider' => 'Common Crawl', 'category' => 'training' ),

		// Mistral
		'MistralBot'           => array( 'name' => 'MistralBot',           'provider' => 'Mistral',      'category' => 'training' ),
	);
}

function llm_bot_monitor_match_bot( string $ua ): string|false {
	static $pattern   = null;
	static $lower_map = null;

	if ( $pattern === null ) {
		$bots      = llm_bot_monitor_bot_list();
		$lower_map = array();
		foreach ( $bots as $key => $meta ) {
			$lower_map[ strtolower( $key ) ] = $meta['name'];
		}
		$escaped = array_map( static fn( $sig ) => preg_quote( $sig, '/' ), array_keys( $bots ) );
		$pattern = '/(' . implode( '|', $escaped ) . ')/i';
	}

	if ( preg_match( $pattern, $ua, $m ) ) {
		return $lower_map[ strtolower( $m[1] ) ] ?? $m[1];
	}

	return false;
}

function llm_bot_monitor_anonymize_ip( string $ip ): string {
	if ( str_contains( $ip, '.' ) && filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
		// IPv4: zero last 2 octets → 1.2.3.4 becomes 1.2.0.0
		$parts = explode( '.', $ip );
		$parts[2] = '0';
		$parts[3] = '0';
		return implode( '.', $parts );
	}

	if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
		// IPv6: zero last 80 bits → keep first 48 bits (3 groups)
		$expanded = inet_ntop( inet_pton( $ip ) );
		$groups   = explode( ':', $expanded );
		for ( $i = 3; $i < 8; $i++ ) {
			$groups[ $i ] = '0000';
		}
		return inet_ntop( inet_pton( implode( ':', $groups ) ) );
	}

	// Fallback: invalid IP or 0.0.0.0 — return as-is.
	return $ip;
}

function llm_bot_monitor_get_ip(): string {
	$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return '0.0.0.0';
	}
	return llm_bot_monitor_anonymize_ip( $ip );
}

function llm_bot_monitor_track_request(): void {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
	if ( $ua === '' ) {
		return;
	}

	$bot_name = llm_bot_monitor_match_bot( $ua );
	if ( $bot_name === false ) {
		return;
	}

	global $wpdb;

	$ip = llm_bot_monitor_get_ip();

	$recent = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM " . $wpdb->prefix . LLM_BOT_MONITOR_TABLE . " WHERE ip_address = %s AND bot_name = %s AND hit_at > %s",
		$ip,
		$bot_name,
		gmdate( 'Y-m-d H:i:s', time() - 3600 )
	) );

	if ( $recent >= 20 ) {
		return;
	}

	$wpdb->insert(
		$wpdb->prefix . LLM_BOT_MONITOR_TABLE,
		array(
			'hit_at'      => current_time( 'mysql', true ),
			'bot_name'    => $bot_name,
			'request_url' => mb_substr( esc_url_raw( home_url( $_SERVER['REQUEST_URI'] ?? '/' ) ), 0, 2048 ),
			'ip_address'  => $ip,
			'user_agent'  => mb_substr( $ua, 0, 512 ),
			'status_code' => is_404() ? 404 : ( http_response_code() ?: 200 ),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%d' )
	);
}
add_action( 'template_redirect', 'llm_bot_monitor_track_request' );

/* ==========================================================================
   3. CRON CLEANUP (90-day retention)
   ========================================================================== */

function llm_bot_monitor_run_cleanup(): void {
	global $wpdb;
	$table = $wpdb->prefix . LLM_BOT_MONITOR_TABLE;
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$table} WHERE hit_at < %s LIMIT 10000",
		gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) )
	) );
}
add_action( 'llm_bot_monitor_daily_cleanup', 'llm_bot_monitor_run_cleanup' );

/* ==========================================================================
   4. ADMIN MENU & ASSETS
   ========================================================================== */

function llm_bot_monitor_admin_menu(): void {
	add_management_page(
		'LLM Bot Monitor',
		'LLM Bot Monitor',
		'manage_options',
		'llm-bot-monitor',
		'llm_bot_monitor_render_page'
	);
}
add_action( 'admin_menu', 'llm_bot_monitor_admin_menu' );

function llm_bot_monitor_enqueue_assets( string $hook ): void {
	if ( $hook !== 'tools_page_llm-bot-monitor' ) {
		return;
	}

	$dir = plugin_dir_path( __FILE__ );
	$url = plugin_dir_url( __FILE__ );

	wp_enqueue_style(
		'llm-bot-monitor-admin',
		$url . 'assets/admin.css',
		array(),
		file_exists( $dir . 'assets/admin.css' ) ? (string) filemtime( $dir . 'assets/admin.css' ) : LLM_BOT_MONITOR_VERSION
	);

	wp_enqueue_script(
		'llm-bot-monitor-admin',
		$url . 'assets/admin.js',
		array(),
		file_exists( $dir . 'assets/admin.js' ) ? (string) filemtime( $dir . 'assets/admin.js' ) : LLM_BOT_MONITOR_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'llm_bot_monitor_enqueue_assets' );

/* ==========================================================================
   5. DASHBOARD QUERIES
   ========================================================================== */

function llm_bot_monitor_get_stats(): array {
	global $wpdb;
	$table = $wpdb->prefix . LLM_BOT_MONITOR_TABLE;
	$now   = current_time( 'mysql', true );

	return array(
		'all_time'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
		'this_week'    => (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE hit_at >= %s",
			gmdate( 'Y-m-d H:i:s', strtotime( '-7 days', strtotime( $now ) ) )
		) ),
		'this_month'   => (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE hit_at >= %s",
			gmdate( 'Y-m-d H:i:s', strtotime( '-30 days', strtotime( $now ) ) )
		) ),
		'this_quarter' => (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE hit_at >= %s",
			gmdate( 'Y-m-d H:i:s', strtotime( '-90 days', strtotime( $now ) ) )
		) ),
	);
}

function llm_bot_monitor_get_chart_data(): array {
	global $wpdb;
	$table = $wpdb->prefix . LLM_BOT_MONITOR_TABLE;

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(CONVERT_TZ(hit_at, '+00:00', @@session.time_zone)) AS day, COUNT(*) AS hits
		 FROM {$table}
		 WHERE hit_at >= %s
		 GROUP BY DATE(CONVERT_TZ(hit_at, '+00:00', @@session.time_zone))
		 ORDER BY day ASC",
		gmdate( 'Y-m-d', strtotime( '-30 days' ) )
	) );

	$map = array();
	foreach ( $rows as $row ) {
		$map[ $row->day ] = (int) $row->hits;
	}

	$data = array();
	$date = new DateTime( '-30 days', new DateTimeZone( 'UTC' ) );
	$end  = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
	while ( $date <= $end ) {
		$key    = $date->format( 'Y-m-d' );
		$data[] = array( 'day' => $key, 'hits' => $map[ $key ] ?? 0 );
		$date->modify( '+1 day' );
	}

	return $data;
}

function llm_bot_monitor_get_top_bots( int $limit = 10 ): array {
	global $wpdb;
	$table = $wpdb->prefix . LLM_BOT_MONITOR_TABLE;

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT bot_name, COUNT(*) AS hits
		 FROM {$table}
		 WHERE hit_at >= %s
		 GROUP BY bot_name
		 ORDER BY hits DESC
		 LIMIT %d",
		gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) ),
		$limit
	) );
}

function llm_bot_monitor_get_bot_overview( int $days = 30 ): array {
	global $wpdb;
	$table = $wpdb->prefix . LLM_BOT_MONITOR_TABLE;

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT bot_name, COUNT(*) AS hits, MAX(hit_at) AS last_seen
		 FROM {$table}
		 WHERE hit_at >= %s
		 GROUP BY bot_name
		 ORDER BY hits DESC",
		gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) )
	) );

	$result = array();
	foreach ( $rows as $row ) {
		$result[ $row->bot_name ] = array(
			'hits'      => (int) $row->hits,
			'last_seen' => $row->last_seen,
		);
	}

	return $result;
}

function llm_bot_monitor_get_distinct_bots(): array {
	global $wpdb;
	$table = $wpdb->prefix . LLM_BOT_MONITOR_TABLE;
	return $wpdb->get_col( "SELECT DISTINCT bot_name FROM {$table} ORDER BY bot_name ASC" );
}

function llm_bot_monitor_get_logs( array $filters, int $page, int $per_page ): array {
	global $wpdb;
	$table  = $wpdb->prefix . LLM_BOT_MONITOR_TABLE;
	$where  = array();
	$values = array();

	if ( ! empty( $filters['bot_name'] ) ) {
		$where[]  = 'bot_name = %s';
		$values[] = $filters['bot_name'];
	}
	if ( ! empty( $filters['path_contains'] ) ) {
		$where[]  = 'request_url LIKE %s';
		$values[] = '%' . $wpdb->esc_like( $filters['path_contains'] ) . '%';
	}
	if ( ! empty( $filters['ip_contains'] ) ) {
		$where[]  = 'ip_address LIKE %s';
		$values[] = '%' . $wpdb->esc_like( $filters['ip_contains'] ) . '%';
	}
	if ( ! empty( $filters['date_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'] ) ) {
		$where[]  = 'hit_at >= %s';
		$values[] = $filters['date_from'] . ' 00:00:00';
	}
	if ( ! empty( $filters['date_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'] ) ) {
		$where[]  = 'hit_at <= %s';
		$values[] = $filters['date_to'] . ' 23:59:59';
	}

	$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
	$offset    = ( $page - 1 ) * $per_page;

	if ( ! empty( $values ) ) {
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} {$where_sql}",
			$values
		) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} {$where_sql} ORDER BY hit_at DESC LIMIT %d OFFSET %d",
			array_merge( $values, array( $per_page, $offset ) )
		) );
	} else {
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY hit_at DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		) );
	}

	return array( 'rows' => $rows, 'total' => $total );
}

/* ---------- Visibility data ------------------------------------------- */

function llm_bot_monitor_get_visibility_data( int $days = 30 ): array {
	global $wpdb;
	$table = $wpdb->prefix . LLM_BOT_MONITOR_TABLE;
	$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

	// 1. Get all published pages and posts
	$posts = get_posts( [
		'post_type'      => [ 'post', 'page' ],
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'no_found_rows'  => true,
		'orderby'        => 'date',
		'order'          => 'DESC',
	] );

	// 2. Get bot visits aggregated by URL path
	$bot_visits = $wpdb->get_results( $wpdb->prepare(
		"SELECT request_url, COUNT(*) AS total_hits, COUNT(DISTINCT bot_name) AS unique_bots
		 FROM {$table}
		 WHERE hit_at >= %s
		 GROUP BY request_url",
		$since
	) );

	// 3. Get total distinct bots active in this period
	$total_active_bots = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT bot_name) FROM {$table} WHERE hit_at >= %s",
		$since
	) );
	if ( $total_active_bots < 1 ) {
		$total_active_bots = 1; // avoid division by zero
	}

	// 4. Build URL path lookup from bot visits
	$path_hits = [];
	foreach ( $bot_visits as $row ) {
		$path = wp_parse_url( $row->request_url, PHP_URL_PATH );
		if ( $path ) {
			$path = untrailingslashit( $path );
			if ( ! isset( $path_hits[ $path ] ) ) {
				$path_hits[ $path ] = [ 'total_hits' => 0, 'unique_bots' => 0 ];
			}
			$path_hits[ $path ]['total_hits'] += (int) $row->total_hits;
			$path_hits[ $path ]['unique_bots'] = max( $path_hits[ $path ]['unique_bots'], (int) $row->unique_bots );
		}
	}

	// 5. Match posts to bot visit data
	$results = [];
	foreach ( $posts as $post ) {
		$permalink = get_permalink( $post->ID );
		$path      = untrailingslashit( wp_make_link_relative( $permalink ) );
		$hits_data = $path_hits[ $path ] ?? [ 'total_hits' => 0, 'unique_bots' => 0 ];
		$ai_score  = round( $hits_data['unique_bots'] / $total_active_bots * 100 );

		$results[] = [
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'type'        => $post->post_type,
			'permalink'   => $permalink,
			'published'   => $post->post_date,
			'total_hits'  => $hits_data['total_hits'],
			'unique_bots' => $hits_data['unique_bots'],
			'ai_score'    => $ai_score,
		];
	}

	// Sort by AI score descending
	usort( $results, fn( $a, $b ) => $b['ai_score'] <=> $a['ai_score'] );

	return [
		'pages'             => $results,
		'total_active_bots' => $total_active_bots,
	];
}

/* ==========================================================================
   6. CSV EXPORT
   ========================================================================== */

function llm_bot_monitor_handle_csv_export(): void {
	if ( ( $_GET['action'] ?? '' ) !== 'export_csv' ) {
		return;
	}
	check_admin_referer( 'llm_csv_export' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}

	global $wpdb;
	$table  = $wpdb->prefix . LLM_BOT_MONITOR_TABLE;
	$where  = array();
	$values = array();

	$filters = array(
		'bot_name'      => sanitize_text_field( $_GET['bot_name'] ?? '' ),
		'path_contains' => sanitize_text_field( $_GET['path_contains'] ?? '' ),
		'ip_contains'   => sanitize_text_field( $_GET['ip_contains'] ?? '' ),
		'date_from'     => sanitize_text_field( $_GET['date_from'] ?? '' ),
		'date_to'       => sanitize_text_field( $_GET['date_to'] ?? '' ),
	);

	if ( ! empty( $filters['bot_name'] ) ) {
		$where[]  = 'bot_name = %s';
		$values[] = $filters['bot_name'];
	}
	if ( ! empty( $filters['path_contains'] ) ) {
		$where[]  = 'request_url LIKE %s';
		$values[] = '%' . $wpdb->esc_like( $filters['path_contains'] ) . '%';
	}
	if ( ! empty( $filters['ip_contains'] ) ) {
		$where[]  = 'ip_address LIKE %s';
		$values[] = '%' . $wpdb->esc_like( $filters['ip_contains'] ) . '%';
	}
	if ( ! empty( $filters['date_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'] ) ) {
		$where[]  = 'hit_at >= %s';
		$values[] = $filters['date_from'] . ' 00:00:00';
	}
	if ( ! empty( $filters['date_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'] ) ) {
		$where[]  = 'hit_at <= %s';
		$values[] = $filters['date_to'] . ' 23:59:59';
	}

	$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

	if ( ! empty( $values ) ) {
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, hit_at, bot_name, request_url, ip_address, user_agent, status_code FROM {$table} {$where_sql} ORDER BY hit_at DESC LIMIT 50000",
			$values
		), ARRAY_A );
	} else {
		$rows = $wpdb->get_results(
			"SELECT id, hit_at, bot_name, request_url, ip_address, user_agent, status_code FROM {$table} ORDER BY hit_at DESC LIMIT 50000",
			ARRAY_A
		);
	}

	$filename = 'llm-bot-log-' . gmdate( 'Y-m-d' ) . '.csv';
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Pragma: no-cache' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'id', 'hit_at', 'bot_name', 'request_url', 'ip_address', 'user_agent', 'status_code' ) );
	foreach ( $rows as $row ) {
		$row = array_map( static function ( $v ) {
			return preg_match( '/^[=+\-@]/', (string) $v ) ? "'" . $v : $v;
		}, $row );
		fputcsv( $out, $row );
	}
	fclose( $out );
	exit;
}

/* ==========================================================================
   6b. BULK DELETE HANDLER
   ========================================================================== */

function llm_bot_monitor_handle_bulk_action(): void {
	if ( ! isset( $_POST['llm_bulk_action'] ) || $_POST['llm_bulk_action'] !== 'delete' ) {
		return;
	}
	check_admin_referer( 'llm_bot_monitor_bulk', '_llm_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}

	$ids = array_map( 'absint', (array) ( $_POST['log_ids'] ?? array() ) );
	$ids = array_filter( $ids );
	if ( empty( $ids ) ) {
		return;
	}

	global $wpdb;
	$table        = $wpdb->prefix . LLM_BOT_MONITOR_TABLE;
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$table} WHERE id IN ({$placeholders})",
		$ids
	) );

	wp_safe_redirect( add_query_arg(
		array( 'page' => 'llm-bot-monitor', 'deleted' => count( $ids ) ),
		admin_url( 'tools.php' )
	) );
	exit;
}

/* ==========================================================================
   7. DASHBOARD RENDER — ROUTER & HEADER
   ========================================================================== */

function llm_bot_monitor_render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}

	// Handle actions before any output (needs headers).
	llm_bot_monitor_handle_csv_export();
	llm_bot_monitor_handle_bulk_action();

	$tab  = sanitize_key( $_GET['tab'] ?? 'logs' );
	$tabs = array(
		'logs'       => 'Crawler Logs',
		'bots'       => 'Bot Overview',
		'visibility' => 'AI Visibility',
		'config'     => 'Configuration',
	);

	echo '<div class="wrap">';

	llm_bot_monitor_render_header( $tab, $tabs );

	switch ( $tab ) {
		case 'bots':
			llm_bot_monitor_render_tab_bots();
			break;
		case 'visibility':
			llm_bot_monitor_render_tab_visibility();
			break;
		case 'config':
			llm_bot_monitor_render_tab_config();
			break;
		default:
			llm_bot_monitor_render_tab_logs();
			break;
	}

	echo '</div>';
}

function llm_bot_monitor_render_header( string $active_tab, array $tabs ): void {
	?>
	<div class="llm-header">
		<h1>LLM Bot Monitor</h1>
		<p class="llm-header-byline">by <a href="https://eric-hinzpeter.de" target="_blank" rel="noopener">Eric Hinzpeter</a></p>
	</div>
	<?php

	echo '<nav class="nav-tab-wrapper">';
	$base_url = admin_url( 'tools.php?page=llm-bot-monitor' );
	foreach ( $tabs as $slug => $label ) {
		$url   = $slug === 'logs' ? $base_url : add_query_arg( 'tab', $slug, $base_url );
		$class = $slug === $active_tab ? 'nav-tab nav-tab-active' : 'nav-tab';
		printf( '<a href="%s" class="%s">%s</a>', esc_url( $url ), esc_attr( $class ), esc_html( $label ) );
	}
	echo '</nav>';
}

/* ==========================================================================
   7a. TAB: CRAWLER LOGS
   ========================================================================== */

function llm_bot_monitor_render_tab_logs(): void {
	$stats      = llm_bot_monitor_get_stats();
	$chart_data = llm_bot_monitor_get_chart_data();
	$top_bots   = llm_bot_monitor_get_top_bots();
	$bot_names  = llm_bot_monitor_get_distinct_bots();

	$filters = array(
		'bot_name'      => sanitize_text_field( $_GET['bot_name'] ?? '' ),
		'path_contains' => sanitize_text_field( $_GET['path_contains'] ?? '' ),
		'ip_contains'   => sanitize_text_field( $_GET['ip_contains'] ?? '' ),
		'date_from'     => sanitize_text_field( $_GET['date_from'] ?? '' ),
		'date_to'       => sanitize_text_field( $_GET['date_to'] ?? '' ),
	);

	$per_page = min( absint( $_GET['per_page'] ?? 50 ), 500 );
	if ( $per_page < 1 ) {
		$per_page = 50;
	}
	$current_page = max( 1, absint( $_GET['paged'] ?? 1 ) );
	$log_data     = llm_bot_monitor_get_logs( $filters, $current_page, $per_page );
	$total_pages  = (int) ceil( $log_data['total'] / $per_page );

	if ( ! empty( $_GET['deleted'] ) ) {
		$count = absint( $_GET['deleted'] );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( '%d log entries deleted.', $count ) ) . '</p></div>';
	}
	?>
	<div class="llm-tab-content">

		<p class="llm-tab-intro">All AI and LLM bot visits to your site in real time. Shows which crawlers are active, which pages they visit, and when they were last seen.</p>

		<!-- Stats Cards -->
		<div class="llm-stats-cards">
			<div class="llm-stat-card">
				<h3>ALL TIME</h3>
				<span class="llm-stat-number"><?php echo esc_html( number_format_i18n( $stats['all_time'] ) ); ?></span>
				<span class="llm-stat-desc">total hits</span>
			</div>
			<div class="llm-stat-card">
				<h3>THIS WEEK</h3>
				<span class="llm-stat-number"><?php echo esc_html( number_format_i18n( $stats['this_week'] ) ); ?></span>
				<span class="llm-stat-desc">last 7 days</span>
			</div>
			<div class="llm-stat-card">
				<h3>THIS MONTH</h3>
				<span class="llm-stat-number"><?php echo esc_html( number_format_i18n( $stats['this_month'] ) ); ?></span>
				<span class="llm-stat-desc">last 30 days</span>
			</div>
			<div class="llm-stat-card">
				<h3>THIS QUARTER</h3>
				<span class="llm-stat-number"><?php echo esc_html( number_format_i18n( $stats['this_quarter'] ) ); ?></span>
				<span class="llm-stat-desc">last 90 days</span>
			</div>
		</div>

		<!-- Chart + Top Bots -->
		<div class="llm-dashboard-row">
			<div class="llm-panel llm-chart-panel">
				<h2>Last 30 days (total hits per day)</h2>
				<?php if ( array_sum( array_column( $chart_data, 'hits' ) ) > 0 ) : ?>
					<canvas id="llm-chart" width="800" height="300"
						data-chart="<?php echo esc_attr( wp_json_encode( $chart_data ) ); ?>">
					</canvas>
				<?php else : ?>
					<p class="llm-no-data">No data.</p>
				<?php endif; ?>
			</div>
			<div class="llm-panel llm-top-bots-panel">
				<h2>Top bots &mdash; last 7 days</h2>
				<?php if ( ! empty( $top_bots ) ) : ?>
					<ol class="llm-top-bots-list">
						<?php foreach ( $top_bots as $bot ) : ?>
							<li>
								<span class="llm-bot-name"><?php echo esc_html( $bot->bot_name ); ?></span>
								<span class="llm-bot-count"><?php echo esc_html( number_format_i18n( (int) $bot->hits ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php else : ?>
					<p class="llm-no-data">No data.</p>
				<?php endif; ?>
			</div>
		</div>

		<!-- Filter Bar -->
		<form method="get" class="llm-filter-bar">
			<input type="hidden" name="page" value="llm-bot-monitor">
			<label>
				Bot
				<select name="bot_name">
					<option value="">All bots</option>
					<?php foreach ( $bot_names as $name ) : ?>
						<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $filters['bot_name'], $name ); ?>>
							<?php echo esc_html( $name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				Path contains
				<input type="text" name="path_contains" value="<?php echo esc_attr( $filters['path_contains'] ); ?>" placeholder="/blog/">
			</label>
			<label>
				IP contains
				<input type="text" name="ip_contains" value="<?php echo esc_attr( $filters['ip_contains'] ); ?>" placeholder="192.168">
			</label>
			<label>
				From
				<input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
			</label>
			<label>
				To
				<input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
			</label>
			<label>
				Per page
				<input type="number" name="per_page" value="<?php echo esc_attr( $per_page ); ?>" min="1" max="500" style="width: 70px;">
			</label>
			<button type="submit" class="button button-primary">Filter</button>
			<a href="<?php echo esc_url( admin_url( 'tools.php?page=llm-bot-monitor' ) ); ?>" class="button">Reset</a>
			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'page' => 'llm-bot-monitor', 'action' => 'export_csv' ), array_filter( $filters, fn( $v ) => $v !== '' ) ), admin_url( 'tools.php' ) ), 'llm_csv_export' ) ); ?>" class="button">Export CSV</a>
		</form>

		<!-- Log Table -->
		<form method="post">
			<?php wp_nonce_field( 'llm_bot_monitor_bulk', '_llm_nonce' ); ?>
			<?php if ( ! empty( $log_data['rows'] ) ) : ?>
				<div class="tablenav top">
					<div class="alignleft actions">
						<select name="llm_bulk_action">
							<option value="">Bulk Actions</option>
							<option value="delete">Delete Selected</option>
						</select>
						<button type="submit" class="button">Apply</button>
					</div>
					<?php if ( $total_pages > 1 ) : ?>
						<div class="tablenav-pages">
							<span class="displaying-num"><?php echo esc_html( number_format_i18n( $log_data['total'] ) ); ?> items</span>
							<?php
							$base_url = admin_url( 'tools.php' );
							$args     = array_merge( array( 'page' => 'llm-bot-monitor' ), array_filter( $filters, fn( $v ) => $v !== '' ), array( 'per_page' => $per_page ) );
							?>
							<?php if ( $current_page > 1 ) : ?>
								<a class="prev-page button" aria-label="Previous page" href="<?php echo esc_url( add_query_arg( array_merge( $args, array( 'paged' => $current_page - 1 ) ), $base_url ) ); ?>">&lsaquo;</a>
							<?php endif; ?>
							<span class="paging-input">
								<?php echo esc_html( $current_page ); ?> of <?php echo esc_html( $total_pages ); ?>
							</span>
							<?php if ( $current_page < $total_pages ) : ?>
								<a class="next-page button" aria-label="Next page" href="<?php echo esc_url( add_query_arg( array_merge( $args, array( 'paged' => $current_page + 1 ) ), $base_url ) ); ?>">&rsaquo;</a>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<?php if ( ! empty( $log_data['rows'] ) ) : ?>
							<td class="manage-column column-cb check-column">
								<label for="llm-select-all" class="screen-reader-text">Select all</label>
								<input type="checkbox" id="llm-select-all">
							</td>
						<?php else : ?>
							<td class="manage-column column-cb check-column"></td>
						<?php endif; ?>
						<th class="manage-column">When</th>
						<th class="manage-column">Bot</th>
						<th class="manage-column column-page">Page</th>
						<th class="manage-column">IP</th>
						<th class="manage-column">Status</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $log_data['rows'] ) ) : ?>
						<tr>
							<td colspan="6">No results.</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $log_data['rows'] as $row ) : ?>
							<tr>
								<th class="check-column">
									<input type="checkbox" name="log_ids[]" value="<?php echo absint( $row->id ); ?>">
								</th>
								<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $row->hit_at . ' UTC' ) ) ); ?></td>
								<td><?php echo esc_html( $row->bot_name ); ?></td>
								<td class="column-page"><a href="<?php echo esc_url( $row->request_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row->request_url ); ?></a></td>
								<td><?php echo esc_html( $row->ip_address ); ?></td>
								<td><?php echo absint( $row->status_code ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</form>
	</div>
	<?php
}

/* ==========================================================================
   7b. TAB: BOT-ÜBERSICHT
   ========================================================================== */

function llm_bot_monitor_render_tab_bots(): void {
	$allowed_periods = array( 7, 30, 90 );
	$period          = absint( $_GET['period'] ?? 30 );
	if ( ! in_array( $period, $allowed_periods, true ) ) {
		$period = 30;
	}

	$bot_list = llm_bot_monitor_bot_list();
	$overview = llm_bot_monitor_get_bot_overview( $period );

	// Group bots by provider.
	$providers = array();
	foreach ( $bot_list as $key => $meta ) {
		$providers[ $meta['provider'] ][ $key ] = $meta;
	}

	// Desired provider display order.
	$provider_order = array(
		'OpenAI', 'Anthropic', 'Google', 'Perplexity', 'Meta',
		'Apple', 'Microsoft', 'ByteDance', 'Common Crawl', 'Mistral',
	);

	?>
	<div class="llm-tab-content">

		<p class="llm-tab-intro">All 19 tracked bots grouped by provider. Grounding bots search in real time (e.g. ChatGPT-User); Training bots collect data for future models (e.g. GPTBot).</p>

		<!-- Period filter -->
		<form method="get" class="llm-filter-bar">
			<input type="hidden" name="page" value="llm-bot-monitor">
			<input type="hidden" name="tab" value="bots">
			<label>
				Period
				<select name="period">
					<?php foreach ( $allowed_periods as $p ) : ?>
						<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $period, $p ); ?>>
							<?php echo esc_html( $p . ' days' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="button button-primary">Show</button>
		</form>

		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th>Bot</th>
					<th>Category</th>
					<th>Hits</th>
					<th>Last seen</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $provider_order as $provider_name ) : ?>
					<?php if ( empty( $providers[ $provider_name ] ) ) continue; ?>
					<tr class="llm-provider-header">
						<td colspan="4"><?php echo esc_html( $provider_name ); ?></td>
					</tr>
					<?php foreach ( $providers[ $provider_name ] as $key => $meta ) :
						$bot_display = $meta['name'];
						$stats       = $overview[ $bot_display ] ?? null;
						$has_hits    = $stats !== null && $stats['hits'] > 0;
						$badge_class = $meta['category'] === 'grounding' ? 'llm-badge-grounding' : 'llm-badge-training';
						$badge_label = $meta['category'] === 'grounding' ? 'Grounding' : 'Training';
					?>
					<tr<?php echo $has_hits ? '' : ' class="llm-bot-inactive"'; ?>>
						<td><?php echo esc_html( $bot_display ); ?></td>
						<td><span class="llm-category-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></span></td>
						<td><?php echo $has_hits ? esc_html( number_format_i18n( $stats['hits'] ) ) : '&mdash;'; ?></td>
						<td><?php echo $has_hits ? esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $stats['last_seen'] . ' UTC' ) ) ) : '&mdash;'; ?></td>
					</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</tbody>
		</table>

	</div>
	<?php
}

/* ==========================================================================
   7c. TAB: AI-SICHTBARKEIT
   ========================================================================== */

function llm_bot_monitor_render_tab_visibility(): void {
	$allowed_periods = array( 7, 30, 90 );
	$period          = absint( $_GET['period'] ?? 30 );
	if ( ! in_array( $period, $allowed_periods, true ) ) {
		$period = 30;
	}

	$data        = llm_bot_monitor_get_visibility_data( $period );
	$pages       = $data['pages'];
	$total_pages = count( $pages );
	$invisible   = count( array_filter( $pages, fn( $p ) => $p['ai_score'] === 0 ) );
	$visible     = $total_pages - $invisible;
	$coverage    = $total_pages > 0 ? round( $visible / $total_pages * 100 ) : 0;
	?>
	<div class="llm-tab-content">

	<p class="llm-tab-intro">Which of your pages have been visited by AI crawlers? The AI Score shows how many active bots visited a page in the selected period — 100% means all active bots were there.</p>

	<!-- Period Filter -->
	<form method="get" class="llm-filter-bar">
		<input type="hidden" name="page" value="llm-bot-monitor">
		<input type="hidden" name="tab" value="visibility">
		<label>
			Period
			<select name="period">
				<option value="7" <?php selected( $period, 7 ); ?>>7 days</option>
				<option value="30" <?php selected( $period, 30 ); ?>>30 days</option>
				<option value="90" <?php selected( $period, 90 ); ?>>90 days</option>
			</select>
		</label>
		<button type="submit" class="button button-primary">Filter</button>
	</form>

	<!-- Summary Cards -->
	<div class="llm-visibility-summary">
		<div class="llm-stat-card">
			<h3>INVISIBLE TO AI</h3>
			<span class="llm-stat-number"><?php echo esc_html( number_format_i18n( $invisible ) ); ?></span>
			<span class="llm-stat-desc">pages with no bot visits</span>
		</div>
		<div class="llm-stat-card">
			<h3>AI COVERAGE</h3>
			<span class="llm-stat-number"><?php echo esc_html( number_format_i18n( $coverage ) ); ?>%</span>
			<span class="llm-stat-desc"><?php echo esc_html( number_format_i18n( $visible ) ); ?> of <?php echo esc_html( number_format_i18n( $total_pages ) ); ?> pages</span>
		</div>
		<div class="llm-stat-card">
			<h3>PUBLISHED PAGES</h3>
			<span class="llm-stat-number"><?php echo esc_html( number_format_i18n( $total_pages ) ); ?></span>
			<span class="llm-stat-desc">Pages &amp; Posts</span>
		</div>
	</div>

	<!-- Visibility Table -->
	<table class="wp-list-table widefat striped">
		<thead>
			<tr>
				<th class="manage-column">Title</th>
				<th class="manage-column">Type</th>
				<th class="manage-column">AI Score</th>
				<th class="manage-column">Published</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $pages ) ) : ?>
				<tr><td colspan="4">No published content found.</td></tr>
			<?php else : ?>
				<?php foreach ( $pages as $row ) : ?>
					<?php
					if ( $row['ai_score'] > 60 ) {
						$score_class = 'llm-score-high';
					} elseif ( $row['ai_score'] >= 30 ) {
						$score_class = 'llm-score-medium';
					} else {
						$score_class = 'llm-score-low';
					}
					$type_label = $row['type'] === 'page' ? 'Page' : 'Post';
					?>
					<tr>
						<td><a href="<?php echo esc_url( $row['permalink'] ); ?>" target="_blank"><?php echo esc_html( $row['title'] ); ?></a></td>
						<td><?php echo esc_html( $type_label ); ?></td>
						<td><span class="llm-score-badge <?php echo esc_attr( $score_class ); ?>"><?php echo esc_html( number_format_i18n( $row['ai_score'] ) ); ?>%</span></td>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $row['published'] ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	</div>
	<?php
}

/* ==========================================================================
   7d. TAB: KONFIGURATION
   ========================================================================== */

function llm_bot_monitor_render_tab_config(): void {
	$bots    = llm_bot_monitor_bot_list();
	$keys    = array_map( 'strtolower', array_keys( $bots ) );
	$plain   = implode( "\n", $keys );
	$regex   = '(' . implode( '|', $keys ) . ')';
	$wilds   = implode( "\n", array_map( function ( $k ) { return '.*' . $k . '.*'; }, $keys ) );

	echo '<div class="llm-tab-content">';

	echo '<p class="llm-tab-intro">' . esc_html( 'To prevent caching plugins from blocking bot detection, AI bots must be excluded from cache. Below you\'ll find configuration guides and copy-ready bot patterns for the most common plugins.' ) . '</p>';

	// Section 1: Info box
	echo '<div class="llm-info-box">';
	echo '<h3>' . esc_html( 'Why exclude bots from cache?' ) . '</h3>';
	echo '<ul class="llm-why-list">';
	echo '<li><strong>' . esc_html( 'Tracking accuracy' ) . '</strong> — ' . esc_html( 'Cached pages bypass bot detection and skew your statistics' ) . '</li>';
	echo '<li><strong>' . esc_html( 'Save resources' ) . '</strong> — ' . esc_html( 'AI bots don\'t need cached content, so you save storage and processing power' ) . '</li>';
	echo '<li><strong>' . esc_html( 'Real-time data' ) . '</strong> — ' . esc_html( 'Bot activity is captured immediately, not after the cache expires' ) . '</li>';
	echo '</ul>';
	echo '</div>';

	// Section 2: Cache Configuration
	echo '<h2>' . esc_html( 'Cache Configuration' ) . '</h2>';
	echo '<p>' . esc_html( 'Setup guide for the most common caching plugins:' ) . '</p>';
	echo '<table class="llm-config-table wp-list-table widefat striped">';
	echo '<thead><tr><th>' . esc_html( 'Caching Plugin' ) . '</th><th>' . esc_html( 'Configuration Steps' ) . '</th></tr></thead>';
	echo '<tbody>';

	$configs = array(
		'WP Rocket'       => '1. Go to Settings → WP Rocket → Advanced Rules. 2. Find "Never Cache User Agent(s)". 3. Paste the bot patterns from below (all at once). 4. Save and clear cache.',
		'LiteSpeed Cache' => '1. Navigate to LiteSpeed Cache → Cache → Excludes. 2. Find "Do Not Cache User Agents". 3. Add each bot pattern on its own line. 4. Click "Save Changes".',
		'W3 Total Cache'  => '1. Go to Performance → Page Cache. 2. Scroll to the "Advanced" section. 3. Find "Rejected User Agents". 4. Paste the patterns (one per line). 5. Save all settings and clear cache.',
		'WP Super Cache'  => '1. Go to Settings → WP Super Cache → Advanced. 2. Find "Rejected User Agents". 3. Paste the bot patterns. 4. Click "Update Status".',
		'Cloudflare'      => '1. Go to Caching → Configuration. 2. Create a Cache Rule. 3. Condition: User Agent contains one of the patterns. 4. Action: Bypass cache.',
	);

	foreach ( $configs as $plugin => $steps ) {
		echo '<tr>';
		echo '<td><strong>' . esc_html( $plugin ) . '</strong></td>';
		echo '<td>' . esc_html( $steps ) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';

	// Section 3: Bot Patterns
	echo '<h2>' . esc_html( 'Bot Patterns' ) . '</h2>';
	echo '<p>' . esc_html( 'Copy these patterns into your caching plugin settings:' ) . '</p>';
	echo '<div class="llm-copyable-wrapper">';
	echo '<textarea class="llm-copyable" id="llm-bot-patterns" readonly>' . esc_textarea( $plain ) . '</textarea>';
	echo '<button type="button" class="button llm-copy-btn" data-target="llm-bot-patterns">' . esc_html( 'Copy all' ) . '</button>';
	echo '</div>';

	// Section 4: Alternative Formats
	echo '<h2>' . esc_html( 'Alternative Formats' ) . '</h2>';
	echo '<p>' . esc_html( 'For regex-based systems:' ) . '</p>';
	echo '<div class="llm-copyable-wrapper">';
	echo '<textarea class="llm-copyable" id="llm-bot-regex" readonly>' . esc_textarea( $regex ) . '</textarea>';
	echo '<button type="button" class="button llm-copy-btn" data-target="llm-bot-regex">' . esc_html( 'Copy regex' ) . '</button>';
	echo '</div>';

	echo '<div class="llm-copyable-wrapper">';
	echo '<textarea class="llm-copyable" id="llm-bot-wildcards" readonly>' . esc_textarea( $wilds ) . '</textarea>';
	echo '<button type="button" class="button llm-copy-btn" data-target="llm-bot-wildcards">' . esc_html( 'Copy wildcards' ) . '</button>';
	echo '</div>';

	echo '</div>';
}
