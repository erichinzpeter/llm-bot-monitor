# Changelog — LLM Bot Monitor

Development changelog tracking code review findings and fixes.
Serves as a learning reference for future iterations.

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
