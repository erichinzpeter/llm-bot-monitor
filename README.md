# LLM Bot Monitor

WordPress plugin that tracks AI/LLM bot crawlers visiting your site. Install, activate, done — no configuration needed.

## Features

- **Automatic detection** of 50 AI bots: GPTBot, ClaudeBot, PerplexityBot, Google-Extended, Amazonbot, Bytespider, and more
- **Admin dashboard** at Tools > LLM Bot Monitor with:
  - Stats cards (all time, 7-day, 30-day, 90-day)
  - 30-day bar chart
  - Top bots leaderboard
  - Filterable, paginated log table
  - Bulk delete
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

OpenAI (GPTBot, ChatGPT-User, OAI-SearchBot), Anthropic (ClaudeBot, Claude-Web, Claude-SearchBot), Google (Google-Extended, GoogleOther, Gemini-Deep-Research, Google-CloudVertexBot), Perplexity (PerplexityBot), Meta (FacebookBot, Meta-ExternalAgent), Amazon (Amazonbot, NovaAct), ByteDance (Bytespider), Apple (Applebot-Extended), Common Crawl (CCBot), and 20+ more.

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
