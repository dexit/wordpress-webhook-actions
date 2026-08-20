=== Webhook Actions - build automations and integrations with AI help ===
Contributors: mateuszflowsystems
Tags: webhooks, automation, zapier, n8n, ai
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Describe an integration in chat and the AI builds it — outgoing webhooks from any WordPress or WooCommerce action, queued, retried and logged.

== Description ==

**Describe the integration you want. The AI builds it.** Webhook Actions ships with **Build with AI** — an in-admin agent that turns a plain-language request like *"When a Contact Form 7 form is submitted, send it as JSON to my n8n webhook"* into a working, tested automation. The agent proposes a plan you can review and edit, then creates the webhook, captures a real example payload from your site, maps the fields, sets dispatch conditions, probes your endpoint, and sends a test delivery. Nothing goes live without your confirmation — new webhooks are always created disabled, and you can undo the last change with one click.

📖 [Full documentation at wpwebhooks.org/docs/](https://wpwebhooks.org/docs/)

= What you can connect =

**Sources — anything in WordPress that fires an action.** Webhook Actions turns any `do_action` into a trigger, so form submissions, WooCommerce orders, user registrations, post publishes and your own custom plugin events can all start an automation. That covers Elementor Forms, WPForms, Forminator, Fluent Forms, Gravity Forms, WooCommerce and your own code — there is no per-plugin add-on to hunt down. Contact Form 7 and IvyForms go one step further with built-in support: their submissions are normalized into clean JSON payloads automatically.

**Destinations — any HTTP endpoint.** The plugin sends outgoing webhooks to anything that accepts a request: an n8n, Make, Zapier or Pabbly webhook node, a Slack or Discord incoming-webhook URL for order and form notifications, Airtable, Google Sheets, Mailchimp, HubSpot, Salesforce, Notion, your CRM, an internal microservice, or an AI agent API. There are no bundled per-service connectors and none are needed — point a webhook at a URL, map the fields, and send. Every delivery is queued, retried on failure and logged with full request and response history.

That makes it a no-code way to sync WordPress data outward: describe what you want in chat and let the AI build it, or wire it up by hand in the admin UI. No PHP required either way.

= Bring your own AI — free options included =

- **WordPress 7.0 AI Client** — if your site already has an AI provider connected (Settings → Connectors), the builder uses it directly; the plugin stores no keys
- **Your own API key** — connect Anthropic, OpenAI, or Google in the builder; keys are encrypted in the Credentials Vault and never returned over the API
- **Free to run** — a free Google AI Studio key gives you Gemini at no cost: [step-by-step tutorial](https://wpwebhooks.org/docs/get-google-ai-studio-api-key/)
- **Automatic fallback** — if a provider is rate-limited mid-build, the agent switches to another connected provider and keeps going

= What the AI works from =

The agent doesn't guess — it works from your site's real data. It maps fields against actually captured payloads, edits existing webhooks by name or id instead of duplicating them, validates endpoints with a guarded probe (SSRF-protected, secrets always redacted), and verifies the result with a real test delivery. Every operation is also published as a WordPress Ability, so external AI tools (Claude Code, Cursor) can drive the same toolset over MCP with scoped API tokens.

= The engine underneath (free) =

- Turn any WordPress do_action into a first-class automation trigger your CRMs, n8n flows, AI agents, and internal services can consume — every dispatch is an outgoing webhook you fully control
- Persistent delivery queue with smart retry and exponential backoff — powered by WP-Cron, auto-upgrades to Action Scheduler or System Cron when available, **(Pro)** External Cron for guaranteed reliability
- Per-event UUID and ISO 8601 timestamp — enable downstream deduplication
- Delivery logs with full attempt history, request/response inspection, replay, and bulk retry
- Synchronous execution mode — fire inline without queue delay
- Payload mapping — rename, restructure, exclude, and type-cast fields with dot-notation paths
- Conditional dispatch — filter events by payload field values before dispatch, so a Slack notification or a CRM sync only fires when it should
- HTTP method, custom headers, and URL query parameters per webhook
- Dynamic endpoint URLs — `{{ field.path }}` placeholders resolved at dispatch time (free via `fswa_webhook_url` filter)
- Webhook Chains — wire 2xx completions to downstream webhooks with full observability
- Import & Export — move webhooks and chains between sites as portable JSON (triggers, field mapping, and conditions included; Code Glue with Pro), with strict validation and a per-item result summary on import
- Markdown descriptions — document what each webhook and chain does inline, with a Write/Preview toggle while editing
- Credentials Vault — store reusable auth secrets (Bearer, Basic, API key, custom) encrypted at rest; reference them from webhooks instead of pasting raw Authorization headers. Secrets are write-only over the API — never returned, only a masked hint
- Activity History — persistent audit log of every admin and API-token action
- Built-in CF7 and IvyForms integrations — structured payloads, no extra plugins
- Action Scheduler auto-detection — more reliable delivery on high-traffic sites
- Fully translatable — the entire admin interface and all server-side strings are internationalized; ships with Polish, Simplified Chinese, and Dutch, and is compatible with WPML and Polylang String Translation
- Full REST API with scoped API token authentication (`read` / `operational` / `full` / `agent`) — the `agent` scope grants full write access for AI assistants while never exposing stored secrets
- Developer extensibility — 16 filters and 7 action hooks ([reference](https://wpwebhooks.org/docs/))

= Pro features =

- AI credits included — Build with AI runs through the hosted WP Webhooks AI service on every Pro plan: no API keys to create, no provider accounts, a monthly credit allowance that renews automatically, and a live credits counter in the builder. Your own keys and WordPress connectors stay available any time
- AI writes Code Glue for you — the agent drafts PHP snippets, test-runs them against your real captured payloads, and assigns them to webhooks (with your confirmation) for pre-dispatch payload enrichment or post-dispatch side effects
- AI sets advanced conditions — with Pro the agent can propose multi-rule AND/OR condition groups instead of a single rule
- Code Glue — attach PHP snippets to any webhook+trigger (pre-dispatch payload enrichment, post-dispatch side effects)
- External Cron — replace unreliable visitor-triggered WP-Cron with a managed external pinger, provisioned automatically on license activation. Two modes: plugin queue endpoint (down to 20 s interval, configurable batch size) or WP-Cron endpoint (60 s, covers all WordPress background work). No server crontab or external dashboard — controlled entirely from wp-admin, with a live heartbeat chart and inline error alerts
- Unlimited conditions per trigger with AND/OR groups
- Per-webhook retry limit and backoff strategy overrides
- Dynamic URL templates — `{{ }}` syntax with no custom PHP required

[See pricing and upgrade →](https://wpwebhooks.org/pricing/)

= Examples =

- [Send Contact Form 7 submissions to a webhook (n8n demo)](https://wpwebhooks.org/examples/cf7-to-webhook/)
- [Send Gravity Forms Submissions to n8n](https://wpwebhooks.org/examples/gravity-forms-webhooks/)
- [Send IvyForms submissions to a webhook (n8n demo)](https://wpwebhooks.org/examples/ivyforms-to-webhook/)
- [WooCommerce orders to n8n on completion — wired up with a Claude Code agent](https://wpwebhooks.org/examples/woocommerce-order-webhook-claude-code/)
- [WooCommerce to HubSpot integration — sync orders, contacts, and deals with no custom code](https://wpwebhooks.org/examples/hubspot-woocommerce-integration/)

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/flowsystems-webhook-actions` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to Webhook Actions in the admin menu — it opens on **Build with AI**.
4. Connect an AI provider (or use your WordPress 7.0 AI connectors) and describe the integration you want — or skip the AI and configure webhooks manually under the Webhooks tab.

== Frequently Asked Questions ==

= Does the AI Builder need an API key? Is it free to use? =

The AI Builder needs a model to talk to, and you have free options. If your WordPress 7.0 site already has an AI provider connected (Settings → Connectors), the builder uses it with no extra setup. Otherwise connect your own Anthropic, OpenAI, or Google key — a free Google AI Studio key gives you Gemini at no cost. [Here's how to get one in two minutes →](https://wpwebhooks.org/docs/get-google-ai-studio-api-key/)

= Is my data safe with the AI Builder? =

Yes. Your provider API keys are encrypted in the Credentials Vault and never returned over the API. Stored webhook credentials are never sent to the AI model, and captured payload values whose field names look sensitive (passwords, tokens, keys) are redacted before any prompt is built. The agent's changes run locally in the plugin — the model only proposes the plan.

= Is this plugin free? =

Yes. The core plugin is completely free and licensed under GPL. Webhook Actions Pro is an optional paid upgrade that adds included AI credits for Build with AI (hosted, no API keys needed), unlimited conditions, per-webhook retry and backoff settings, Code Glue snippets, External Cron (activated automatically on license activation), and more. [Learn more →](https://wpwebhooks.org/pricing/)

= Does it work with WooCommerce, n8n, Make, Zapier, and AI agents? =

Yes. Any WordPress or WooCommerce action can be a trigger. The plugin delivers to any HTTP endpoint — n8n, Make, Zapier webhook nodes, internal services, or AI agent APIs. Scoped API tokens let Claude Code, Cursor, or any automation tool read logs, retry deliveries, and toggle webhooks without WordPress credentials.

= Does it work with Elementor Forms, WPForms, Forminator, Fluent Forms or Gravity Forms? =

Yes. These plugins fire their own WordPress actions when a form is submitted, and Webhook Actions can use any of them as a trigger — so you can send an Elementor Forms or WPForms submission straight to a webhook without a dedicated add-on. Pick the plugin's submit action in the trigger list, capture a real submission to see the payload, then map the fields you want. Contact Form 7 and IvyForms additionally ship with built-in normalization; the others use the generic trigger path.

= Can I send WordPress data to Slack, Google Sheets, Airtable or a CRM? =

Yes — any destination that accepts an HTTP request works. For Slack or Discord, paste the incoming-webhook URL they give you and map the payload into the message field. For Google Sheets, Airtable, Mailchimp, HubSpot or Salesforce, either call their API directly or point the webhook at an n8n, Make or Zapier node and let it do the last hop. Nothing here needs a per-service connector, because the plugin speaks plain HTTP.

= Do I need extra plugins for Contact Form 7 or IvyForms? =

No. Both integrations are built in. When CF7 or IvyForms is active, submissions are automatically normalized into clean JSON payloads — no additional plugins or custom code required.

= How does retry work? =

5xx and 429 responses retry automatically with exponential backoff (delays of ~30s, 60s, 120s, 240s, 480s, capped at 1 hour). 4xx and 3xx responses are marked `permanently_failed` immediately — bad payloads are not worth retrying. Default maximum is 5 attempts; override with the `fswa_max_attempts` filter or **(Pro)** per-webhook settings.

= Can I access the REST API without a WordPress login? =

Yes. Create a token from the API Tokens screen and pass it as `X-FSWA-Token: <token>` (or `Authorization: Bearer`). Four scopes available — `read`, `operational`, `full`, and `agent` (full write access for AI assistants that never exposes stored secrets) — so you can grant exactly the access each integration needs. Full API reference at [wpwebhooks.org/webhook-wordpress-plugin-api/](https://wpwebhooks.org/webhook-wordpress-plugin-api/)

== Screenshots ==

1. Build with AI — describe the integration in chat; the agent plans, builds, and tests it step by step, with progress in the sidebar and one-click enable when the build completes
2. Webhooks list view
3. Webhook configuration screen
4. Selecting WordPress action triggers
5. Payload mapping configuration
6. Webhook delivery logs with replay and retry controls
7. Queue status overview
8. Settings configuration screen
9. REST API Tokens configuration screen
10. Conditional webhook dispatch — conditions editor
11. Test webhook drawer — send a test delivery and inspect request details inline
12. Webhook Chains — pick an existing chain or create a new one, then select which upstream webhooks should fire this one on their 2xx response
13. Credentials Vault — store reusable authentication secrets (Bearer, Basic, API key, custom) encrypted at rest and reference them from webhooks instead of pasting raw Authorization headers

== Changelog ==

For the full release history see [wpwebhooks.org/changelog/](https://wpwebhooks.org/changelog/)

= 2.7.4 — 2026-08-20 =
- Changed: when you have builds but no AI provider connected, Build with AI now opens on your builds with a single "Connect" bar above them, instead of a full-height setup card that pushed everything below the fold. The same provider settings are one click away inside that bar. A site with no builds yet still sees the full setup card.
