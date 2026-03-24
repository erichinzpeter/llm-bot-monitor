# LLM Bot Monitor — Gotchas & Architecture Notes

## Tricky Areas (where bugs tend to appear)

### 1. UTC Datetimes — two different contexts

`hit_at` is stored in UTC via `current_time('mysql', true)`.

- **Display** (bot table, logs): append `' UTC'` before `strtotime()` so PHP parses it as UTC regardless of server timezone:
  ```php
  wp_date( get_option('date_format'), strtotime( $row->last_seen . ' UTC' ) )
  ```
- **Chart query**: group by day using `CONVERT_TZ` so MySQL buckets hits in the server's local timezone, not UTC:
  ```sql
  DATE(CONVERT_TZ(hit_at, '+00:00', @@session.time_zone))
  ```
  Without this, on non-UTC MySQL servers bar chart bars are off by one day.

- **`$post->post_date`** (visibility tab) is WordPress local time — do NOT append `' UTC'` here.

### 2. Bot list structure — not a flat array

`llm_bot_monitor_bot_list()` returns `'UA_Pattern' => ['name', 'provider', 'category']`. The key is the raw UA string used for regex matching. The `name` field is the human-readable label.

`llm_bot_monitor_match_bot()` returns `string|false` — always the `name` value, never the key. If you need to group by provider/category, call `bot_list()` directly and join on `name`.

### 3. `unique_bots` in visibility data is a lower bound

`get_visibility_data()` groups bot visits by full `request_url` before collapsing to URL path. When the same page is hit under multiple URLs (UTM params, query strings), `unique_bots` uses `MAX()` across the merged rows — not a true distinct count. AI Score is therefore a conservative underestimate. Do not try to fix this with a single SQL query; it requires either a sub-select or accepting the approximation.

### 4. `get_posts()` in visibility tab loads all published posts

`'posts_per_page' => -1` + `'no_found_rows' => true`. The `no_found_rows` flag suppresses the `SQL_CALC_FOUND_ROWS` count query. Without it, WP runs two queries on every visibility tab load. If this ever needs pagination, the whole function needs a rethink.

### 5. Tab router location — bulk delete must fire before HTML output

The bulk delete handler (`llm_bot_monitor_handle_bulk_action()`) is called at the top of `render_page()` before any `echo`. It calls `wp_safe_redirect()` + `exit`. If you move it below the first `echo '<div class="wrap">'`, the redirect silently fails on hosts without output buffering.

### 6. CSS class naming — `<tr>` vs row wrapper

Bot table inactive rows use `tr.llm-bot-inactive` (CSS selector with element qualifier). The class is set directly on `<tr>`, not on a wrapping div. If row markup ever changes to use a div grid, the CSS selector must be updated.

### 7. `filemtime()` asset versioning — guard with `file_exists()`

```php
$version = file_exists( $path ) ? (string) filemtime( $path ) : LLM_BOT_MONITOR_VERSION;
```
Without the guard, a missing asset file throws an `E_WARNING` and enqueues version `''`.

### 8. Table name constant — always use `LLM_BOT_MONITOR_TABLE`

Never hardcode `'llm_bot_log'`. Always: `$wpdb->prefix . LLM_BOT_MONITOR_TABLE`. All query functions follow this — if you add a new query, follow the same pattern.

## Known Accepted Trade-offs

- `unique_bots` undercounting (see #3) — acceptable for a personal site dashboard
- No pagination on visibility tab — acceptable until site exceeds ~500 published posts
- Bulk delete success notice is replayable via URL bookmark — low risk, WP convention
