# Changelog — LLM Bot Monitor

Development changelog tracking code review findings and fixes.
Serves as a learning reference for future iterations.

## v2.3.0 — 2026-03-26

Crawler Logs UX: full URL display, CSV export, README credit.

### Changes

- **Full URL as clickable link** — Page column in the Crawler Logs table now shows the complete URL as a link opening in a new tab. Previously URLs were truncated at 300px with ellipsis.
- **CSV export** — New "Export CSV" button in the filter bar exports all matching log rows (up to 50,000) as a `.csv` file. Respects all active filters (bot, path, IP, date range). Nonce-protected GET request.
- **README credits** — Added Credits section attributing inspiration to LLM Bot Tracker by Hueston.

### Code Review Findings

- **Nonce before capability check** — Both `llm_bot_monitor_handle_csv_export()` and `llm_bot_monitor_handle_bulk_action()` now call `check_admin_referer()` before `current_user_can()`, matching WordPress security guidance (nonce check costs nothing and eliminates capability lookup on forged requests).
- **`array_filter` precision** — Replaced `array_filter($filters)` with `array_filter($filters, fn($v) => $v !== '')` to avoid accidentally dropping the string `"0"` from filter URL parameters.

**Lesson:** Nonce verification should always precede capability checks — it's cheaper, eliminates forged requests early, and is the pattern WordPress core uses throughout.

---

## v2.2.0 — 2026-03-25

Full English UI and Bot Overview redesign.

### Changes

- **Full English UI** — All German strings replaced across every tab: tab names (Bot Overview, AI Visibility, Configuration), intro texts, filter labels, table headers, stat card labels, empty states, and all config tab content including cache setup instructions
- **Bot Overview redesign** — Replaced 10 separate tables (each with its own column header row) with a single unified table. Provider names are now rendered as full-width separator rows (`tr.llm-provider-header`), eliminating the repetitive "Bot / Category / Hits / Last seen" header that appeared once per provider
- **Provider order updated** — `$provider_order` now matches the current 10 active providers; defunct entries (Amazon, Cohere, Diffbot, You.com, AI2, SEO/Data, Other) removed
- **CSS cleanup** — Removed unused `.llm-provider-group` and `.llm-bot-row` styles; added `tr.llm-provider-header` style

---

## v2.1.0 — 2026-03-25

Bot list focused and corrected. Reduced from 44 to 19 bots.

### Changes

- **Bot list cleanup** — Removed SEO crawlers (Semrush, Ahrefs, DotBot, MJ12bot, DataForSeo), generic Google infrastructure bots (GoogleOther, GoogleOther-Image, GoogleOther-Video, Google-CloudVertexBot, Google-Safety), Facebook link-preview bot, Amazon general crawler, and 14 obscure/unverified "Other" bots
- **`Claude-Web` → `Claude-User`** — Updated UA token to match current Anthropic naming; old key was still valid for legacy logs but new requests use `Claude-User`
- **`GoogleAgent-Mariner` → `Google-Agent`** — Corrected to the actual UA token per Google docs; the old key never matched any real request
- **`Meta-ExternalFetcher` reclassified** — Changed category from `training` to `grounding` (it's used for real-time link fetching in Meta AI, not model training)
- **Added `Applebot`** — The base Applebot also feeds Apple Intelligence; previously only `Applebot-Extended` was tracked
- **Added `Bingbot`** — Powers Microsoft Copilot grounding in addition to traditional search indexing
- **Added `Google-Agent`** — New March 2026 bot for user-triggered agentic tasks (Project Mariner)

- **Log cleanup on upgrade** — `activate()` now deletes log rows whose `bot_name` is no longer in the active bot list, keeping historical data consistent with the current tracking scope. The known-name list is built dynamically from `llm_bot_monitor_bot_list()`.
- **File header versions** — `admin.js` and `admin.css` now carry the version in their top comment.

**Lesson:** SEO crawlers and general-purpose infrastructure bots belong in a different tool. An LLM monitor should track only bots from AI providers that are either training models or powering AI-generated answers.

---

## v2.0.0 — 2026-03-24

Major feature release: four-tab admin dashboard, bot categorization, AI visibility scoring, cache configuration guide, and design refresh.

### New Features

- **Tab navigation** — Admin page split into four tabs: Crawler Logs, Bot-Übersicht, AI-Sichtbarkeit, Konfiguration
- **Bot-Übersicht tab** — All 47 tracked bots grouped by provider (OpenAI, Anthropic, Google, …) with Grounding/Training category badges and hit stats per time period
- **AI-Sichtbarkeit tab** — All published pages scored by AI coverage (% of active bots that visited in the selected period), with summary cards and color-coded score badges
- **Konfiguration tab** — Cache exclusion instructions for WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, and Cloudflare; copyable bot patterns in plain, regex, and wildcard formats
- **Bot metadata** — `llm_bot_monitor_bot_list()` now returns `[name, provider, category]` per bot instead of a flat string
- **Text logo** — Header now shows "LLM Bot Monitor by Eric Hinzpeter" with link to eric-hinzpeter.de
- **Tab intro text** — Each tab has a one-sentence description explaining its purpose and, where relevant, how metrics are calculated

### Design

- Minimalist WordPress-native aesthetic — no dark top borders, more whitespace, subtle box shadows
- New CSS components: `.llm-category-badge`, `.llm-score-badge` (high/medium/low), `.llm-provider-group`, `.llm-copyable`, `.llm-tab-intro`

### Bug Fixes & Improvements

- **UTC datetime handling** — `strtotime()` on stored datetimes now appends `' UTC'` to prevent timezone misinterpretation on non-UTC servers
- **Chart query timezone** — `DATE(hit_at)` replaced with `DATE(CONVERT_TZ(hit_at, '+00:00', @@session.time_zone))` so bar chart day-bucketing is correct on non-UTC MySQL servers
- **`filemtime()` guard** — Asset versioning now checks `file_exists()` before calling `filemtime()` to prevent E_WARNING on missing files
- **Table name constant** — All query functions consistently use `LLM_BOT_MONITOR_TABLE` constant; hardcoded string in visibility query removed
- **`no_found_rows`** — Added to `get_posts()` call in visibility tab to suppress unnecessary COUNT query
- **`Claude-Web` reclassified** — Changed category from `training` to `grounding` (it's a retrieval/browsing bot)

### Code Review Findings (3 rounds)

- Unescaped `$row_class` variable in bot table `<tr>` — replaced with direct ternary inline
- `.llm-bot-inactive` CSS selector mismatch — corrected from `.llm-bot-row.llm-bot-inactive` to `tr.llm-bot-inactive`
- Stat card HTML/CSS mismatch — aligned `<h3>` / `.llm-stat-number` classes between PHP and CSS
- Orphan CSS rules removed (`.llm-config-section`, `.llm-period-filter`)

**Lesson:** When CSS is written before PHP, always cross-reference every class name. UTC datetimes need explicit hints at both the PHP (`strtotime` + `' UTC'`) and SQL (`CONVERT_TZ`) layer.

---

## v1.0.0 — 2026-03-23

Initial release after 4 rounds of code review.

### Code Review Round 1 — Security & Data Quality

**Critical fixes:**
- **Case-insensitive bot matching was broken.** Regex used `/i` flag but the label lookup map used original casing, so matches like `gptbot` (lowercase) failed to resolve. Fixed with `array_change_key_case($bots, CASE_LOWER)` + `strtolower($m[1])`.
- **No server-side cap on `per_page`.** A malicious request could set `per_page=999999` and dump the entire table. Fixed with `min(absint(...), 500)`.

**Important fixes:**
- Date filter inputs (`date_from`, `date_to`) were not validated before entering SQL queries. Added `preg_match('/^\d{4}-\d{2}-\d{2}$/')` guard.
- Removed general-purpose crawlers that are not AI/LLM-specific: Bingbot, BingPreview, Applebot (kept Applebot-Extended which is the AI-training variant).
- Uninstall handler was missing `wp_clear_scheduled_hook()` — cron event would survive plugin deletion.

**Lesson:** Always normalize case when combining case-insensitive regex with array lookups. Always cap user-controlled LIMIT values.

---

### Code Review Round 2 — Hook Timing & Edge Cases

**Important fixes:**
- **`status_code` unreliable on `send_headers`.** Switched tracking hook from `send_headers` to `template_redirect` where WordPress has already determined the response status.
- **Seekr bot too generic.** User-agent string "Seekr" could false-positive on legitimate traffic. Removed from bot list.
- **`request_url` could exceed `VARCHAR(2048)`.** Added `mb_substr(..., 0, 2048)` truncation before insert.
- **Uninstall `LIKE` query not using `$wpdb->prepare()`.** Fixed with `$wpdb->prepare()` + `$wpdb->esc_like()`.
- **Select-all checkbox was one-directional.** Unchecking individual rows did not uncheck the "select all" box. Added reverse listener in JS.

**Lesson:** Choose the right WordPress hook for the data you need. `send_headers` fires before the query is parsed; `template_redirect` fires after. Always truncate user-controlled strings to match DB column limits.

---

### Code Review Round 3 — Accessibility & Performance

**Fixes applied:**
- **README still listed removed bots** (Bingbot, Applebot). Updated tracked bots paragraph.
- **Cron scheduling ran on every `admin_init`.** `wp_next_scheduled()` was called on every admin page load. Moved scheduling into `register_activation_hook` callback; the existing `admin_init` DB version check calls `activate()` on upgrades, so cron re-registers automatically.
- **404 status not detected.** Even on `template_redirect`, `http_response_code()` can return 200 for WordPress 404s. Added `is_404() ? 404 : (http_response_code() ?: 200)`.
- **Bulk actions shown on empty table.** Select-all checkbox and bulk delete dropdown rendered even with zero rows. Wrapped in `if (!empty($log_data['rows']))`.
- **Pagination links lacked accessibility attributes.** Added `aria-label="Previous page"` and `aria-label="Next page"`.
- **Select-all checkbox lacked screen-reader label.** Added `<label class="screen-reader-text">Select all</label>` matching WP core's `WP_List_Table` pattern.

**Lesson:** WordPress's `http_response_code()` does not reflect WP's own 404 handling — always use `is_404()`. Move one-time setup (cron scheduling) into activation hooks, not recurring hooks like `admin_init`.

---

### Code Review Round 4 — Final Polish

**Fixes applied:**
- **`status_code` tracked but never displayed.** The column was in the DB schema and written on every request, but the dashboard log table had no "Status" column. Added it.
- **README said "50+" but exact count is 50.** Changed to "50 AI bots".

**Lesson:** If you capture data, display it. Otherwise remove the column to avoid confusion.
