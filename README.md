# LLM Bot Monitor

WordPress plugin that tracks AI/LLM bot crawlers visiting your site. Install, activate, done — no configuration needed.

## Features

- **Automatic detection** of 19 AI bots categorized as Grounding (real-time search) or Training (data collection)
- **Four-tab admin dashboard** at Tools > LLM Bot Monitor:
  - **Crawler Logs** — stats cards, 30-day bar chart, top bots leaderboard, filterable paginated log table, bulk delete
  - **Bot-Übersicht** — all tracked bots grouped by provider with Grounding/Training badges and hit stats
  - **AI-Sichtbarkeit** — all published pages scored by AI coverage (% of active bots that visited)
  - **Konfiguration** — cache exclusion instructions for 5 caching plugins, copyable bot patterns
- **Lightweight** — adds <0.01s overhead per request
- **90-day automatic log rotation** via WP-Cron
- **Zero dependencies** — no external libraries, no build step

## Requirements

- WordPress 6.5+
- PHP 8.0+
- MySQL 5.6+

## Installation

### From GitHub

1. Download the [latest release](https://github.com/erichinzpeter/llm-bot-monitor/releases)
2. Upload the `llm-bot-monitor` folder to `wp-content/plugins/`
3. Activate in WordPress admin under Plugins

### For development

```bash
git clone https://github.com/erichinzpeter/llm-bot-monitor.git ~/Projects/llm-bot-monitor
ln -s ~/Projects/llm-bot-monitor /path/to/wordpress/wp-content/plugins/llm-bot-monitor
```

Activate the plugin in WordPress admin.

## Usage

After activation, the plugin immediately starts tracking AI bot visits in the background. View your data at **Tools > LLM Bot Monitor**.

### Tracked Bots

19 bots across two categories:

**Grounding** (real-time search/retrieval): ChatGPT-User, OAI-SearchBot, Claude-User, Claude-SearchBot, PerplexityBot, Perplexity-User, Gemini-Deep-Research, Google-Agent, Meta-ExternalFetcher, Applebot, Bingbot

**Training** (data collection for future models): GPTBot, ClaudeBot, Google-Extended, Meta-ExternalAgent, Applebot-Extended, Bytespider, CCBot, MistralBot

Providers: OpenAI, Anthropic, Google, Perplexity, Meta, Apple, Microsoft, ByteDance, Mistral, Common Crawl.

## Privacy & GDPR

This plugin is **GDPR-compliant by design**:

- **Only bot traffic is logged** — human visitors are never tracked
- **No cookies** set or read
- **No external API calls** — all data stays in your WordPress database
- **No fingerprinting** of any kind
- **IP addresses stored are bot IPs only** — datacenter IPs belonging to AI companies, not personal data
- **Automatic data minimization** — logs older than 90 days are automatically deleted
- **No consent mechanism needed** — no personal data of human users is collected

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) for details.

## Author

[Eric Hinzpeter](https://eric-hinzpeter.de)
