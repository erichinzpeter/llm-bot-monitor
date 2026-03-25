<?php
/**
 * Plugin Name: LLM Bot Monitor
 * Plugin URI:  https://github.com/erichinzpeter/llm-bot-monitor
 * Description: Tracks AI/LLM bot crawlers visiting your site. GDPR-compliant — only bot traffic is logged, never human visitors.
 * Version:     2.1.0
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

define( 'LLM_BOT_MONITOR_VERSION', '2.1.0' );
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

function llm_bot_monitor_get_ip(): string {
	$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
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
	$wpdb->insert(
		$wpdb->prefix . LLM_BOT_MONITOR_TABLE,
		array(
			'hit_at'      => current_time( 'mysql', true ),
			'bot_name'    => $bot_name,
			'request_url' => mb_substr( esc_url_raw( home_url( $_SERVER['REQUEST_URI'] ?? '/' ) ), 0, 2048 ),
			'ip_address'  => llm_bot_monitor_get_ip(),
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
   6. BULK DELETE HANDLER
   ========================================================================== */

function llm_bot_monitor_handle_bulk_action(): void {
	if ( ! isset( $_POST['llm_bulk_action'] ) || $_POST['llm_bulk_action'] !== 'delete' ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}
	check_admin_referer( 'llm_bot_monitor_bulk', '_llm_nonce' );

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

	// Handle bulk delete action before any output (needs headers).
	llm_bot_monitor_handle_bulk_action();

	$tab  = sanitize_key( $_GET['tab'] ?? 'logs' );
	$tabs = array(
		'logs'       => 'Crawler Logs',
		'bots'       => 'Bot-Übersicht',
		'visibility' => 'AI-Sichtbarkeit',
		'config'     => 'Konfiguration',
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

		<p class="llm-tab-intro">Alle AI- und LLM-Bot-Besuche deiner Website in Echtzeit. Zeigt welche Crawler aktiv sind, welche Seiten sie besuchen und wann sie zuletzt da waren.</p>

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
							$args     = array_merge( array( 'page' => 'llm-bot-monitor' ), array_filter( $filters ), array( 'per_page' => $per_page ) );
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
						<th class="manage-column">Page</th>
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
								<td title="<?php echo esc_attr( $row->request_url ); ?>">
									<?php
									$path = wp_parse_url( $row->request_url, PHP_URL_PATH ) ?: '/';
									echo esc_html( $path );
									?>
								</td>
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
		'OpenAI', 'Anthropic', 'Google', 'Perplexity', 'Meta', 'Amazon',
		'ByteDance', 'Apple', 'Cohere', 'Mistral', 'Common Crawl', 'Diffbot',
		'You.com', 'AI2', 'SEO / Data', 'Other',
	);

	?>
	<div class="llm-tab-content">

		<p class="llm-tab-intro">Alle 47 getrackten Bots gruppiert nach Anbieter. Grounding-Bots suchen in Echtzeit (z.&nbsp;B. ChatGPT-User), Training-Bots sammeln Daten für zukünftige Modelle (z.&nbsp;B. GPTBot).</p>

		<!-- Period filter -->
		<form method="get" class="llm-filter-bar">
			<input type="hidden" name="page" value="llm-bot-monitor">
			<input type="hidden" name="tab" value="bots">
			<label>
				Zeitraum
				<select name="period">
					<?php foreach ( $allowed_periods as $p ) : ?>
						<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $period, $p ); ?>>
							<?php echo esc_html( $p . ' Tage' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="button button-primary">Anzeigen</button>
		</form>

		<?php foreach ( $provider_order as $provider_name ) : ?>
			<?php if ( empty( $providers[ $provider_name ] ) ) continue; ?>
			<div class="llm-provider-group">
				<h3><?php echo esc_html( $provider_name ); ?></h3>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th>Bot</th>
							<th>Kategorie</th>
							<th>Hits</th>
							<th>Zuletzt gesehen</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $providers[ $provider_name ] as $key => $meta ) :
							$bot_display = $meta['name'];
							$stats       = $overview[ $bot_display ] ?? null;
							$has_hits    = $stats !== null && $stats['hits'] > 0;							$badge_class = $meta['category'] === 'grounding' ? 'llm-badge-grounding' : 'llm-badge-training';
							$badge_label = $meta['category'] === 'grounding' ? 'Grounding' : 'Training';
						?>
						<tr<?php echo $has_hits ? '' : ' class="llm-bot-inactive"'; ?>>
							<td><?php echo esc_html( $bot_display ); ?></td>
							<td><span class="llm-category-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></span></td>
							<td><?php echo $has_hits ? esc_html( number_format_i18n( $stats['hits'] ) ) : '&mdash;'; ?></td>
							<td><?php echo $has_hits ? esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $stats['last_seen'] . ' UTC' ) ) ) : '&mdash;'; ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endforeach; ?>

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

	<p class="llm-tab-intro">Welche deiner Seiten wurden von AI-Crawlern besucht? Der AI-Score zeigt, wie viele der aktiven Bots im gewählten Zeitraum eine Seite gefunden haben&nbsp;– 100&nbsp;% bedeutet, alle aktiven Bots waren dort.</p>

	<!-- Period Filter -->
	<form method="get" class="llm-filter-bar">
		<input type="hidden" name="page" value="llm-bot-monitor">
		<input type="hidden" name="tab" value="visibility">
		<label>
			Zeitraum
			<select name="period">
				<option value="7" <?php selected( $period, 7 ); ?>>7 Tage</option>
				<option value="30" <?php selected( $period, 30 ); ?>>30 Tage</option>
				<option value="90" <?php selected( $period, 90 ); ?>>90 Tage</option>
			</select>
		</label>
		<button type="submit" class="button button-primary">Filter</button>
	</form>

	<!-- Summary Cards -->
	<div class="llm-visibility-summary">
		<div class="llm-stat-card">
			<h3>UNSICHTBAR FÜR AI</h3>
			<span class="llm-stat-number"><?php echo esc_html( number_format_i18n( $invisible ) ); ?></span>
			<span class="llm-stat-desc">Seiten ohne Bot-Besuch</span>
		</div>
		<div class="llm-stat-card">
			<h3>AI-ABDECKUNG</h3>
			<span class="llm-stat-number"><?php echo esc_html( number_format_i18n( $coverage ) ); ?>%</span>
			<span class="llm-stat-desc"><?php echo esc_html( number_format_i18n( $visible ) ); ?> von <?php echo esc_html( number_format_i18n( $total_pages ) ); ?> Seiten</span>
		</div>
		<div class="llm-stat-card">
			<h3>VERÖFFENTLICHTE SEITEN</h3>
			<span class="llm-stat-number"><?php echo esc_html( number_format_i18n( $total_pages ) ); ?></span>
			<span class="llm-stat-desc">Pages &amp; Posts</span>
		</div>
	</div>

	<!-- Visibility Table -->
	<table class="wp-list-table widefat striped">
		<thead>
			<tr>
				<th class="manage-column">Titel</th>
				<th class="manage-column">Typ</th>
				<th class="manage-column">AI Score</th>
				<th class="manage-column">Veröffentlicht</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $pages ) ) : ?>
				<tr><td colspan="4">Keine veröffentlichten Inhalte gefunden.</td></tr>
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
					$type_label = $row['type'] === 'page' ? 'Seite' : 'Beitrag';
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

	echo '<p class="llm-tab-intro">' . esc_html( 'Damit Caching-Plugins die Bot-Erkennung nicht blockieren, müssen AI-Bots vom Cache ausgeschlossen werden. Hier findest du fertige Konfigurationsanleitungen und kopierfertige Bot-Patterns für die gängigsten Plugins.' ) . '</p>';

	// Section 1: Info box
	echo '<div class="llm-info-box">';
	echo '<h3>' . esc_html( 'Warum Bots vom Cache ausschließen?' ) . '</h3>';
	echo '<ul class="llm-why-list">';
	echo '<li><strong>' . esc_html( 'Tracking-Genauigkeit' ) . '</strong> — ' . esc_html( 'Gecachte Seiten umgehen die Bot-Erkennung und verfälschen deine Statistiken' ) . '</li>';
	echo '<li><strong>' . esc_html( 'Ressourcen sparen' ) . '</strong> — ' . esc_html( 'AI-Bots brauchen keine gecachten Inhalte, du sparst Speicher und Rechenleistung' ) . '</li>';
	echo '<li><strong>' . esc_html( 'Echtzeit-Daten' ) . '</strong> — ' . esc_html( 'Bot-Aktivität wird sofort erfasst, nicht erst nach Cache-Ablauf' ) . '</li>';
	echo '</ul>';
	echo '</div>';

	// Section 2: Cache-Konfiguration
	echo '<h2>' . esc_html( 'Cache-Konfiguration' ) . '</h2>';
	echo '<p>' . esc_html( 'Anleitung für die gängigsten Caching-Plugins:' ) . '</p>';
	echo '<table class="llm-config-table wp-list-table widefat striped">';
	echo '<thead><tr><th>' . esc_html( 'Caching Plugin' ) . '</th><th>' . esc_html( 'Konfigurationsschritte' ) . '</th></tr></thead>';
	echo '<tbody>';

	$configs = array(
		'WP Rocket'       => '1. Gehe zu Einstellungen → WP Rocket → Erweiterte Regeln. 2. Finde "Cache nicht anlegen für User Agents". 3. Füge die Bot-Patterns von unten ein (alle auf einmal). 4. Speichern und Cache leeren.',
		'LiteSpeed Cache'  => '1. Navigiere zu LiteSpeed Cache → Cache → Ausschlüsse. 2. Finde "User Agents nicht cachen". 3. Füge jeden Bot-Pattern in eine eigene Zeile ein. 4. Klicke "Änderungen speichern".',
		'W3 Total Cache'   => '1. Gehe zu Performance → Page Cache. 2. Scrolle zum Abschnitt "Erweitert". 3. Finde "Abgelehnte User Agents". 4. Füge die Patterns ein (eine pro Zeile). 5. Alle Einstellungen speichern und Cache leeren.',
		'WP Super Cache'   => '1. Gehe zu Einstellungen → WP Super Cache → Erweitert. 2. Finde "Abgelehnte User Agents". 3. Füge die Bot-Patterns ein. 4. Klicke "Status aktualisieren".',
		'Cloudflare'       => '1. Gehe zu Caching → Konfiguration. 2. Erstelle eine Cache-Regel. 3. Bedingung: User Agent enthält eines der Patterns. 4. Aktion: Cache umgehen.',
	);

	foreach ( $configs as $plugin => $steps ) {
		echo '<tr>';
		echo '<td><strong>' . esc_html( $plugin ) . '</strong></td>';
		echo '<td>' . esc_html( $steps ) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';

	// Section 3: Bot-Patterns zum Kopieren
	echo '<h2>' . esc_html( 'Bot-Patterns zum Kopieren' ) . '</h2>';
	echo '<p>' . esc_html( 'Kopiere diese Patterns in die Einstellungen deines Caching-Plugins:' ) . '</p>';
	echo '<div class="llm-copyable-wrapper">';
	echo '<textarea class="llm-copyable" id="llm-bot-patterns" readonly>' . esc_textarea( $plain ) . '</textarea>';
	echo '<button type="button" class="button llm-copy-btn" data-target="llm-bot-patterns">' . esc_html( 'Alle kopieren' ) . '</button>';
	echo '</div>';

	// Section 4: Alternative Formate
	echo '<h2>' . esc_html( 'Alternative Formate' ) . '</h2>';
	echo '<p>' . esc_html( 'Für Regex-basierte Systeme:' ) . '</p>';
	echo '<div class="llm-copyable-wrapper">';
	echo '<textarea class="llm-copyable" id="llm-bot-regex" readonly>' . esc_textarea( $regex ) . '</textarea>';
	echo '<button type="button" class="button llm-copy-btn" data-target="llm-bot-regex">' . esc_html( 'Regex kopieren' ) . '</button>';
	echo '</div>';

	echo '<div class="llm-copyable-wrapper">';
	echo '<textarea class="llm-copyable" id="llm-bot-wildcards" readonly>' . esc_textarea( $wilds ) . '</textarea>';
	echo '<button type="button" class="button llm-copy-btn" data-target="llm-bot-wildcards">' . esc_html( 'Wildcards kopieren' ) . '</button>';
	echo '</div>';

	echo '</div>';
}
