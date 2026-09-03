=== Webhook Actions - build automations and integrations with AI help ===
Contributors: mateuszflowsystems
Tags: webhooks, automation, zapier, n8n, ai
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Describe an integration and the AI builds it — no API key needed. Outgoing webhooks from any WordPress or WooCommerce action, queued and retried.

== Description ==

**Describe the integration you want. The AI builds it.** Webhook Actions ships with **Build with AI** — an in-admin agent that turns a plain-language request like *"When a Contact Form 7 form is submitted, send it as JSON to my n8n webhook"* into a working, tested automation. The agent proposes a plan you can review and edit, then creates the webhook, captures a real example payload from your site, maps the fields, sets dispatch conditions, probes your endpoint, and sends a test delivery. Nothing goes live without your confirmation — new webhooks are always created disabled, and you can undo the last change with one click.

📖 [Full documentation at wpwebhooks.org/docs/](https://wpwebhooks.org/docs/)

= What you can connect =

**Sources — anything in WordPress that fires an action.** Webhook Actions turns any `do_action` into a trigger, so form submissions, WooCommerce orders, user registrations, post publishes and your own custom plugin events can all start an automation. That covers Elementor Forms, WPForms, Forminator, Fluent Forms, Gravity Forms, WooCommerce and your own code — there is no per-plugin add-on to hunt down. Contact Form 7 and IvyForms go one step further with built-in support: their submissions are normalized into clean JSON payloads automatically.

**Destinations — any HTTP endpoint.** The plugin sends outgoing webhooks to anything that accepts a request: an n8n, Make (formerly Integromat), Zapier or Pabbly webhook node, a Slack or Discord incoming-webhook URL for order and form notifications, Airtable, Google Sheets, Mailchimp, HubSpot, Salesforce, Notion, your CRM, an internal microservice, or an AI agent API. There are no bundled per-service connectors and none are needed — point a webhook at a URL, map the fields, and send. Every delivery is queued, retried on failure and logged with full request and response history.

That makes it a no-code way to sync WordPress data outward: describe what you want in chat and let the AI build it, or wire it up by hand in the admin UI. No PHP required either way.

= No API key needed to start =

- **55 free credits, no key and no signup** — claimed automatically on your first prompt. There is no button to press, no account to create and no card; the plugin sends nothing but your site address. That is about five agent turns, or roughly two complete automations built end to end
- **Try it without installing anything** — the **Live Preview** button on this page boots a throwaway WordPress in your browser and runs the real agent on those credits
- **WordPress connectors** — if your site already has an AI provider connected (Settings → Connectors), the builder uses it directly and the plugin stores no keys
- **My own keys** — connect Anthropic, OpenAI or Google in the builder; keys are encrypted in the Credentials Vault and never returned over the API. A free Google AI Studio key gives you Gemini at no cost: [step-by-step tutorial](https://wpwebhooks.org/docs/get-google-ai-studio-api-key/)
- **Your own key always wins** — once a provider of yours is connected, the free credits are never spent
- **Automatic fallback** — if a provider is rate-limited mid-build, the agent switches to another connected provider and keeps going

= What the AI works from =

The agent doesn't guess — it works from your site's real data. It maps fields against actually captured payloads, edits existing webhooks by name or id instead of duplicating them, validates endpoints with a guarded probe (SSRF-protected, secrets always redacted), and verifies the result with a real test delivery. Every operation is also published as a WordPress Ability, so external AI tools (Claude Code, Cursor) can drive the same toolset over the Model Context Protocol (MCP) with scoped API tokens.

= The engine underneath (free) =

- Turn any WordPress do_action into a first-class automation trigger your CRMs, n8n flows, AI agents, and internal services can consume — every dispatch is an outgoing webhook you fully control
- Persistent delivery queue with smart retry and exponential backoff — powered by WP-Cron, auto-upgrades to Action Scheduler or System Cron when available, **(Pro)** External Cron for guaranteed reliability
- Per-event UUID and ISO 8601 timestamp — enable downstream deduplication
- Delivery logs with full attempt history, request/response inspection, replay, and bulk retry
- Code Glue — attach PHP snippets to any webhook+trigger to reshape the payload before dispatch or run side effects after the response, with a preview that runs your code against a real captured payload first. Build with AI can draft, test-run and assign them for you
- Per-webhook retry limit and backoff strategy (exponential, linear or fixed), each falling back to a site-wide default
- Synchronous execution mode — fire inline without queue delay
- Payload mapping — rename, restructure, exclude, and type-cast fields with dot-notation paths
- Conditional dispatch — filter events by payload field values before dispatch, so a Slack notification or a CRM sync only fires when it should. Build as many rules as you need and group them with AND/OR matching, by hand or by describing them to Build with AI
- HTTP method, custom headers, and URL query parameters per webhook
- Dynamic endpoint URLs — `{{ field.path }}` placeholders resolved against the payload at dispatch time, or the `fswa_webhook_url` filter if you would rather do it in PHP
- Webhook Chains — wire 2xx completions to downstream webhooks with full observability
- Import & Export — move webhooks and chains between sites as portable JSON (triggers, field mapping, conditions and Code Glue snippets included), with strict validation and a per-item result summary on import
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
- External Cron — replace unreliable visitor-triggered WP-Cron with a managed external pinger, provisioned automatically on license activation. Two modes: plugin queue endpoint (down to 20 s interval, configurable batch size) or WP-Cron endpoint (60 s, covers all WordPress background work). No server crontab or external dashboard — controlled entirely from wp-admin, with a live heartbeat chart and inline error alerts

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

No key, and nothing to sign up for. Your first prompt automatically claims **55 free credits** — no account, no card, and the only thing the plugin sends is your site address. That is about five agent turns, or roughly two complete automations built end to end, which is enough to find out whether this works for you before committing anything.

When the credits run out you have three ways to carry on, and two of them are free. If your site has an AI provider connected in WordPress (Settings → Connectors), the builder uses it with no extra setup. Otherwise bring your own Anthropic, OpenAI or Google key — a free Google AI Studio key gives you Gemini at no cost. [Here's how to get one in two minutes →](https://wpwebhooks.org/docs/get-google-ai-studio-api-key/) Or move to Pro, which includes a hosted credit pool and no keys at all. Whichever you pick, a site with its own key never spends the free credits.

= Is my data safe with the AI Builder? =

Yes. Your provider API keys are encrypted in the Credentials Vault and never returned over the API. Stored webhook credentials are never sent to the AI model, and captured payload values whose field names look sensitive (passwords, tokens, keys) are redacted before any prompt is built. The agent's changes run locally in the plugin — the model only proposes the plan.

= Is this plugin free? =

Yes. The core plugin is completely free and licensed under GPL. Webhook Actions Pro is an optional paid upgrade that adds two things, both of which run on our infrastructure rather than yours: included AI credits for Build with AI (hosted, no API keys needed) and External Cron (activated automatically on license activation). Everything the plugin does — Code Glue, unlimited AND/OR conditions, per-webhook retry and backoff, dynamic URL templates, and the AI agent that can build all of them for you — is free as of 3.0.0. [Learn more →](https://wpwebhooks.org/pricing/)

= Does it work with WooCommerce, n8n, Make, Zapier, and AI agents? =

Yes. Any WordPress or WooCommerce action can be a trigger. The plugin delivers to any HTTP endpoint — n8n, Make, Zapier webhook nodes, internal services, or AI agent APIs. Scoped API tokens let Claude Code, Cursor, or any automation tool read logs, retry deliveries, and toggle webhooks without WordPress credentials.

= Is this a Zapier or Make alternative? =

For the WordPress half of the job, yes — and it is worth being precise about which half. Zapier, Make and Pabbly are multi-app orchestrators with large connector catalogues and a visual canvas. If your workflow joins several SaaS products that have nothing to do with WordPress, that is what they are for, and this plugin is not an alternative to it.

What it does replace is the metered hop out of WordPress. Rather than spending a task every time an order or a form entry leaves your site, your own server sends it — queued, retried with exponential backoff, and logged with full request and response history. Webhook Chains let one delivery trigger the next on a 2xx response, so a multi-step flow can stay on your own infrastructure. There is no connector catalogue here: you point a webhook at a URL and map the fields.

Plenty of sites run both — this plugin as the unmetered, self-hosted exit from WordPress, with a Zapier or Make node on the far end when a workflow genuinely needs their catalogue.

= Does it work with Elementor Forms, WPForms, Forminator, Fluent Forms or Gravity Forms? =

Yes. These plugins fire their own WordPress actions when a form is submitted, and Webhook Actions can use any of them as a trigger — so you can send an Elementor Forms or WPForms submission straight to a webhook without a dedicated add-on. Pick the plugin's submit action in the trigger list, capture a real submission to see the payload, then map the fields you want. Contact Form 7 and IvyForms additionally ship with built-in normalization; the others use the generic trigger path.

= Can I send WordPress data to Slack, Google Sheets, Airtable or a CRM? =

Yes — any destination that accepts an HTTP request works. For Slack or Discord, paste the incoming-webhook URL they give you and map the payload into the message field. For Google Sheets, Airtable, Mailchimp, HubSpot or Salesforce, either call their API directly or point the webhook at an n8n, Make or Zapier node and let it do the last hop. Nothing here needs a per-service connector, because the plugin speaks plain HTTP.

= Do I need extra plugins for Contact Form 7 or IvyForms? =

No. Both integrations are built in. When CF7 or IvyForms is active, submissions are automatically normalized into clean JSON payloads — no additional plugins or custom code required.

= How does retry work? =

The dispatcher retries 5xx and 429 responses automatically with exponential backoff (delays of ~30s, 60s, 120s, 240s, 480s, capped at 1 hour). 4xx and 3xx responses are marked `permanently_failed` immediately — bad payloads are not worth retrying. Default maximum is 5 attempts; override it per webhook in the UI, or globally with the `fswa_max_attempts` filter. The backoff strategy (exponential, linear or fixed) is a per-webhook setting too, falling back to a site-wide default.

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

= 3.0.0 — 2026-09-02 =
- Changed: Code Glue, dynamic URL templates, per-webhook retry limits and backoff strategies, unlimited AND/OR conditions and publishing a build are now part of the free plugin. They used to require Webhook Actions Pro. Nothing is lost on upgrade — existing snippets, assignments and retry settings keep working exactly as they were.
- Added: Code Glue — PHP snippets that reshape the payload before a delivery or run side effects after the response — with a preview that runs your code against a real captured payload before you assign it.
- Added: dynamic `{{ field.path }}` templates in the endpoint URL, resolved against the payload at dispatch time.
- Added: per-webhook retry limit and backoff strategy (exponential, linear or fixed), each falling back to a site-wide default you can set in Settings.
- Added: conditions are no longer limited to a single rule — build as many as you need, group them, and match on ANY or ALL.
- Added: publish a build to wpwebhooks.org and earn AI credits, from any site, including one running only the free AI trial. Publishing is not offered from a WordPress Playground demo or from an address the internet cannot reach, and the library keeps one page per recipe — if a build like yours is already there, you are pointed at it rather than adding a near-identical second page.
- Security: writing a Code Glue snippet now requires the same capability WordPress uses for editing plugin code (`edit_plugins`), and is refused entirely on sites that set DISALLOW_FILE_EDIT — that is a wp-config choice and nothing in the plugin can override it. Sites that only set DISALLOW_FILE_MODS (common on managed hosts, where it means "do not install plugins from the dashboard") are unaffected. API tokens — including connected AI tools over MCP — cannot write snippets unless you explicitly allow it in Settings. Reading snippets is unchanged, and snippets already assigned keep running in every case.
- Note: Webhook Actions Pro 1.9.0 or later is required alongside this release. An older Pro keeps running its own copies of the moved features until you update it, so nothing breaks in the meantime.

= 2.9.0 — 2026-08-30 =
- Added: connect an external AI tool to your site over MCP. Everything Build with AI can do — reading your triggers, mapping fields, creating and testing webhooks — is now reachable from Claude Code, Cursor and Claude on the web, driving the same toolset against your real configuration. See the setup guides at https://wpwebhooks.org/docs/
- Fixed: the abilities registered since 2.0.0 never actually reached the WordPress Abilities API, so nothing could discover them. Three separate causes, all silent: ability names used underscores, which core rejects; the category was registered on the wrong hook and was dropped along with everything assigned to it; and the metadata that makes an ability visible over REST and MCP was missing. All 26 are now discoverable and executable.
- Fixed: abilities that take no arguments — listing your webhooks, triggers or snippets — failed over MCP with a generic error, while abilities taking a parameter worked. Their input schema declared no default, so an empty argument set never validated.
- Fixed: the AI-facing webhook read now masks a manually entered authorization header, matching what the plugin's own REST endpoints have always done. This only applied to webhooks using the manual header field rather than the Credentials Vault — vault secrets are stored encrypted and have always come back as names and masked hints. Deliveries are unaffected and still send the real header.
- Added: destructive abilities now require explicit confirmation when called from outside the plugin. Deleting a webhook, taking one live, firing a test delivery or provisioning an application password are refused unless the call confirms the intent, so a connected AI cannot perform them on its own initiative.
- Added: API tokens now work against the Abilities REST route, honouring their scope exactly as the plugin's own endpoints do — a read token cannot reach a write ability, and the agent token can build without ever revealing a stored secret.
- Added: a setting to hold connected AI tools to read-only. Building is on by default; switching it off leaves reads working and does not affect Build with AI.
- Changed: listing webhooks now returns a short snippet of each description rather than the whole thing, so a site with long documented builds no longer sends thousands of words of context on every AI read. Fetching a single webhook still returns the full description.
