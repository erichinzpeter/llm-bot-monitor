<?php
/**
 * Plugin Name: LLM Bot Monitor
 * Plugin URI:  https://github.com/erichinzpeter/llm-bot-monitor
 * Description: Tracks AI/LLM bot crawlers visiting your site. GDPR-compliant — only bot traffic is logged, never human visitors.
 * Version:     1.0.0
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

define( 'LLM_BOT_MONITOR_VERSION', '1.0.0' );
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
		'GPTBot'                 => 'GPTBot',
		'ChatGPT-User'          => 'ChatGPT-User',
		'OAI-SearchBot'         => 'OAI-SearchBot',

		// Anthropic
		'ClaudeBot'             => 'ClaudeBot',
		'Claude-Web'            => 'Claude-Web',
		'Claude-SearchBot'      => 'Claude-SearchBot',
		'anthropic-ai'          => 'Anthropic AI',

		// Google AI
		'Google-Extended'       => 'Google-Extended',
		'GoogleOther'           => 'GoogleOther',
		'GoogleOther-Image'     => 'GoogleOther-Image',
		'GoogleOther-Video'     => 'GoogleOther-Video',
		'Google-CloudVertexBot' => 'Google-CloudVertexBot',
		'Gemini-Deep-Research'  => 'Gemini-Deep-Research',
		'Google-Safety'         => 'Google-Safety',
		'GoogleAgent-Mariner'   => 'GoogleAgent-Mariner',

		// Perplexity
		'PerplexityBot'         => 'PerplexityBot',
		'Perplexity-User'       => 'Perplexity-User',

		// Meta
		'FacebookBot'           => 'FacebookBot',
		'Meta-ExternalAgent'    => 'Meta-ExternalAgent',
		'Meta-ExternalFetcher'  => 'Meta-ExternalFetcher',

		// Amazon
		'Amazonbot'             => 'Amazonbot',
		'NovaAct'               => 'NovaAct',

		// ByteDance
		'Bytespider'            => 'Bytespider',

		// Apple (AI-specific extended crawler)
		'Applebot-Extended'     => 'Applebot-Extended',

		// Common Crawl / ML training
		'CCBot'                 => 'CCBot',
		'Diffbot'               => 'Diffbot',

		// SEO / Data
		'SemrushBot'            => 'SemrushBot',
		'AhrefsBot'             => 'AhrefsBot',
		'DotBot'                => 'DotBot',
		'MJ12bot'               => 'MJ12bot',
		'DataForSeoBot'         => 'DataForSeoBot',

		// AI Search / Assistants
		'YouBot'                => 'YouBot',
		'AI2Bot'                => 'AI2Bot',
		'Cohere-ai'             => 'Cohere AI',
		'cohere-training'       => 'Cohere Training',
		'MistralBot'            => 'MistralBot',
		'Timpibot'              => 'Timpibot',
		'PetalBot'              => 'PetalBot',
		'iaskspider'            => 'iAsk Spider',
		'Kangaroo Bot'          => 'Kangaroo Bot',
		'Velenpublicwebcrawler' => 'Velen Crawler',
		'Webzio-Extended'       => 'Webzio-Extended',
		'omgili'                => 'Omgili',
		'Nicecrawler'           => 'Nicecrawler',
		'FriendlyCrawler'       => 'FriendlyCrawler',
		'ImagesiftBot'          => 'ImagesiftBot',
		'img2dataset'           => 'img2dataset',

		// Devin / AI Agents
		'Devin'                 => 'Devin',
		'LinerBot'              => 'LinerBot',
		'QualifiedBot'          => 'QualifiedBot',
	);
}

function llm_bot_monitor_match_bot( string $ua ): string|false {
	static $pattern   = null;
	static $lower_map = null;

	if ( $pattern === null ) {
		$bots      = llm_bot_monitor_bot_list();
		$lower_map = array_change_key_case( $bots, CASE_LOWER );
		$escaped   = array_map( static fn( $sig ) => preg_quote( $sig, '/' ), array_keys( $bots ) );
		$pattern   = '/(' . implode( '|', $escaped ) . ')/i';
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
		(string) filemtime( $dir . 'assets/admin.css' )
	);

	wp_enqueue_script(
		'llm-bot-monitor-admin',
		$url . 'assets/admin.js',
		array(),
		(string) filemtime( $dir . 'assets/admin.js' ),
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
		"SELECT DATE(hit_at) AS day, COUNT(*) AS hits
		 FROM {$table}
		 WHERE hit_at >= %s
		 GROUP BY DATE(hit_at)
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
   7. DASHBOARD RENDER
   ========================================================================== */

function llm_bot_monitor_render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}

	llm_bot_monitor_handle_bulk_action();

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
	<div class="wrap">
		<h1>LLM Bot Monitor</h1>

		<!-- Stats Cards -->
		<div class="llm-stats-cards">
			<div class="llm-stat-card">
				<span class="llm-stat-label">ALL TIME</span>
				<strong><?php echo esc_html( number_format_i18n( $stats['all_time'] ) ); ?></strong>
				<span class="llm-stat-desc">total hits</span>
			</div>
			<div class="llm-stat-card">
				<span class="llm-stat-label">THIS WEEK</span>
				<strong><?php echo esc_html( number_format_i18n( $stats['this_week'] ) ); ?></strong>
				<span class="llm-stat-desc">last 7 days</span>
			</div>
			<div class="llm-stat-card">
				<span class="llm-stat-label">THIS MONTH</span>
				<strong><?php echo esc_html( number_format_i18n( $stats['this_month'] ) ); ?></strong>
				<span class="llm-stat-desc">last 30 days</span>
			</div>
			<div class="llm-stat-card">
				<span class="llm-stat-label">THIS QUARTER</span>
				<strong><?php echo esc_html( number_format_i18n( $stats['this_quarter'] ) ); ?></strong>
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
								<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $row->hit_at ) ) ); ?></td>
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
