# Claude Translator for TranslatePress

A small, independent WordPress plugin that adds **Anthropic Claude (direct API)** to TranslatePress's native automatic translation providers, plus a resumable bulk translation runner.

**0.3.0 is a preview release.** The local integration suite passes against WordPress 7.1.3, TranslatePress 3.3.8 and the Developer 1.8.8 add-on, on PHP 8.4.26 and MariaDB 11.4.5. Anthropic responses are mocked in automated tests; a live-key smoke test and staging verification are required before production use. No premium TranslatePress code is included in this repository.

## Reset and rebuild dictionaries (0.3.0)

Under **Tools → Claude Translate → Reset saved translations**, select target languages, choose a mode and preview exact row counts. Type **RESET TRANSLATIONS** and click the delete button only after reviewing the preview and making a database backup. This removes manual and machine translations, including current SEO Pack slug translations for the selected languages; translated URLs may change. Close translation-editor tabs while resetting. Nothing is reset merely by installing this version.

- **Full refresh (default):** empties selected regular/gettext language dictionaries, including stale malformed source rows. Start a new bulk job to rebuild from currently rendered public pages. Fix template output first. Shared internal TRP source catalogs remain because other language tables may reference them; obsolete fragments in those catalogs do not repopulate the bulk queue unless encountered again on current pages.
- **Translations only:** clears translated values and resets translated statuses while retaining discovered originals.

The source language cannot be selected. Source pages, builder templates, language configuration, glossary/settings, API key and Claude context/model are retained. The reset cancels the old job, disables visitor-triggered Claude calls, invalidates provider result caches and prevents stale in-flight Claude responses from being saved. Usage counters remain unchanged. Clear affected translated-page caches explicitly, then start a new bulk job; no site-wide cache purge is triggered by the plugin.

Reset requires manage_options, the admin AJAX nonce, a per-user one-use preview token (10-minute expiry), exact typed confirmation and the worker lock. Tables and schemas are derived server-side; no browser-supplied table names are used. Only InnoDB tables are accepted, and dictionary/slug changes share a rollback-capable transaction. A failed reset leaves the worker paused and visitor translation off. Legacy SEO Pack metadata storage outside the current shared slug tables is outside this reset's support.

## XML metadata fix (0.2.6)

A dictionary row consisting entirely of a valid XML declaration is non-translatable metadata. Bulk regular/gettext processing advances past it without modifying the row or its translation status and reports the count separately. The native provider and direct client also omit pure declarations from API input. No source-byte reservation or API request is made for declarations alone. PHP, script/style markup, malformed declarations and XML followed by human text retain the existing protection; this release does not silently skip those blocks. Update and resume an existing job stopped on a pure XML declaration; no dictionary reset is needed.

## Blocked-source inspection (0.2.5)

When a job pauses because a dictionary row contains code or exceeds 12,000 bytes, click **Inspect blocked source (no API call)** below the job status. This reads only the exact row recorded in the paused job error, in its configured language and regular/gettext table. The first 6,000 characters, total source byte length and detected reason are shown as literal text. The source is never executed, deleted, excluded or sent to the translation API by inspection. Known API credentials are masked; review other content before sharing. The endpoint requires manage_options and the existing admin nonce. Historical bad rows can remain in TRP after their source template is fixed: inspect them before making any dictionary changes.

## Workspace selection (0.2.4)

If your API key is not scoped to one workspace, set the optional **Workspace ID** under Tools → Claude Translate. Copy the wrkspc_ ID from Claude Console → Settings → Workspaces, then save and resume the existing job. The ID is sent only in the anthropic-workspace-id header and is validated before any HTTP request. Keys already scoped to one workspace can leave this blank. Server configuration can instead define CTP_ANTHROPIC_WORKSPACE_ID in wp-config.php. Changing workspace selection does not erase saved TRP translations or provider results. See [Anthropic authentication](https://platform.claude.com/docs/en/manage-claude/authentication#select-a-workspace).

## Restricted response diagnostics (0.2.3)

Admin tests and bulk job errors now include the API error type, request ID and a bounded error message. Known complete input strings, the configured key and recognizable credentials are redacted. Error messages can still contain partial input excerpts: review them before sharing. The sanitized diagnostic is retained with the private job status, never sent to a public page or written to a separate log. HTML/non-JSON error bodies are never displayed; their format and byte length help identify a proxy rejection. No paid retry or model fallback runs automatically.

## API diagnostics (0.2.2)

HTTP errors now distinguish insufficient API credits, model access, authentication, rate limits and rejected request parameters using fixed messages. Raw API error text is never exposed. Unknown errors remain unclassified; no automatic fallback or retry is added.

## First-run fix (0.2.1)

Fixes jobs pausing immediately on a fresh install and undefined daily usage counters. After updating, resume the existing job. Missing scan authentication fails closed without visitor API spending. British English (en_GB) bulk discovery and persistence are covered by the local integration suite; English-source gettext is retained for English target locales.

## What it does

### Sonnet 5.5 support (0.2.0)

The default model is `claude-sonnet-5-5`. Responses use `output_config.format` with a JSON schema; no tools or forced tool choices are sent. For Sonnet 5.5, `thinking.type=between_tools` and low effort avoid up-front reasoning on translation requests. See [Anthropic's Sonnet 5.5 migration notes](https://platform.claude.com/docs/en/models/sonnet-5-5/whats-new-sonnet-5-5) and [structured outputs](https://platform.claude.com/docs/en/build-with-claude/structured-outputs).

On upgrade, the former default `claude-sonnet-4-6` is migrated to `claude-sonnet-5-5`; other custom model IDs, the key, context and existing TRP translations are retained. Model/instruction changes select a fresh provider-cache scope but do not retranslate existing TRP dictionary entries.

- Connects directly to `https://api.anthropic.com/v1/messages`, using your Anthropic API key. No OpenRouter or intermediary account.
- Registers Claude in **TranslatePress → Automatic Translation**.
- Uses TranslatePress's own renderer, dictionary, sanitization, machine-translation statuses, exclusion filters and save paths. Translations remain editable in the normal TRP editor.
- Scans published, public, password-free posts/pages and public custom post types before translating, without waiting for visitors. Add archive/pagination URLs manually.
- Processes regular text and discovered gettext strings in small batches, then renders the scanned pages again so the active SEO Pack can persist translated slugs.
- Preserves existing translations during normal translation. An explicit admin reset can clear selected-language translations or rebuild their dictionaries.
- Has pause/resume/cancel, progress reporting, WP-CLI support and a conservative daily source-byte cap.
- Masks markup/attributes, shortcodes, URLs, email addresses, printf and TRP placeholders before sending text for translation, then verifies their integrity before restoring them.
- Rejects incomplete responses, unknown/duplicate IDs and new HTML. Failed batches do not become source-as-translation placeholders.

## Requirements

- WordPress 6.5+, PHP 8.1+ (tested locally on PHP 8.4).
- TranslatePress **3.3.8** is the tested integration version. Retest when upgrading: the bulk runner uses TRP's internal renderer and a narrow adapter to its protected gettext queue.
- Anthropic API access and billing, with a structured-output-capable Claude model available to your account. Model ID is configurable.
- HTTPS outbound access to Anthropic; working same-origin HTTP(S) loopback access for bulk discovery.
- Your normal TranslatePress license/add-ons for extra languages and SEO features. This plugin does not unlock paid TRP features or replace their license.

## Install & configure

1. Install the release ZIP using **Plugins → Add Plugin → Upload Plugin**, then activate it.
2. Open **Tools → Claude Translate**. Set your API key, model ID, site terminology and daily source limit.
3. Under **TranslatePress → Automatic Translation**, enable automatic translation, select **Anthropic Claude (direct API)** and save.
4. Keep **Visitor-triggered translation** off to translate only through explicit bulk jobs. Enable it if new public strings should translate during uncached visits.
5. Configure languages in TRP. If using SEO Pack, enable its automatic slug translation before starting the job.
6. Start a bulk job in **Tools → Claude Translate**. Keep that page open, or run WP-CLI/server cron as described below.
7. After completion, clear the affected translated-page caches through your existing cache plugin and review the translated pages in TRP.

The native **Test API credentials** button is supported. It makes a small billable translation request and returns redacted diagnostics. Merely viewing settings does not call the API.

For configuration outside the database, put this in `wp-config.php` using your real secret; do not commit it:

```php
define( 'CTP_ANTHROPIC_API_KEY', getenv( 'ANTHROPIC_API_KEY' ) );
```

The constant takes precedence over the stored key. Without it, the key is stored as a non-autoloaded WordPress option, protected by your database access controls; it is not encrypted at rest by this plugin. The admin input never renders the stored value.

## Avoiding repeat API charges

There are two persistence layers:

1. **TRP dictionary:** saved translations are served by TRP. They do not call Claude on each visit, including uncached visits.
2. **Provider-result cache:** successful results are cached for 30 days, scoped to original string, source/target language, dictionary context, model and site instructions. This reuses results across repeated TRP rows and retries after a failed save. Changing the model/instructions selects a new cache scope; **Forget cached Claude results** invalidates it explicitly.

The provider cache does not remove or overwrite TRP translations. It also cannot guarantee exactly-once billing if the process dies after Anthropic accepts a request but before the response is cached. Network timeouts are uncertain outcomes: resuming may incur another charge. The daily cap reserves UTF-8 source bytes before every real API attempt, including failed attempts; it resets at midnight UTC. It is not a precise token or currency budget. Use Anthropic account spending limits too.

## Cache behavior

Ordinary page requests receive no cache-control changes from this plugin. It does not replace a cache drop-in, modify `.htaccess`, change cache-plugin settings, force cache variation or purge the entire cache.

Only short-lived HMAC-signed discovery/finalization requests carry a unique `ctp_scan` query argument and receive `DONOTCACHEPAGE`, WordPress no-cache headers and a LiteSpeed no-cache action. The worker requires a response marker from a real PHP render. It pauses if the cache/CDN serves a cached response instead of silently claiming success. Redirects are limited and revalidated against the site's exact origin.

Designed to coexist with LiteSpeed Cache, WP Rocket and WP Fastest Cache using this narrow bypass. **Those commercial/server cache stacks have not been exercised end-to-end in this test environment.** If your CDN caches arbitrary query strings or strips custom headers, bypass caching for `ctp_scan` requests and forward `X-CTP-Time` / `X-CTP-Signature` on those requests. Never exempt all visitors or turn off the whole cache to run a translation job.

Existing cached translated pages may show old text until their normal expiration or a targeted purge. Bulk completion deliberately does not make a site-wide cache purge.

## Builders & coverage

The plugin supplies an engine to TRP rather than rewriting Divi/Oxygen/Bricks/Gutenberg documents. Text TRP extracts from their rendered HTML follows the same translation and persistence path.

| Content | Coverage |
|---|---|
| Gutenberg content and public HTML | Tested through real TRP rendering |
| Divi, Oxygen Legacy, Bricks | Representative rendered-HTML fixtures pass; full builder plugins were not installed/tested |
| Shared headers and footers | Collected when rendered on scanned public pages |
| SEO metadata and URL slugs | Follows active TRP SEO Pack; slug persistence tested with Developer 1.8.8 |
| Gettext strings | Discovered strings are processed through TRP's gettext queue; English source strings targeting English locales are left in their original English |
| AJAX-only content / unopened popup states | Not executed by the server-side scan; discover it in TRP first or provide a public URL rendering it |
| Emails, private/logged-in pages | Outside bulk discovery scope |
| Archives and pagination | Supply additional public URLs manually |
| Different-domain-per-language setups | Not supported by this preview's bulk scanner (same origin only) |

Bulk translation also processes pending active regular/gettext dictionary rows already present from earlier TRP activity; it is not limited to text found in the current page scan. Historical rows can increase the workload. Correct malformed source code (such as an unfinished PHP tag) before translating.

## Running without visitor traffic

The open admin screen pumps one step at a time. No external visitor is needed. WordPress cron is a fallback and needs either WordPress traffic or a real scheduler.

For a fully unattended run, use WP-CLI:

```sh
wp ctp start --languages=de_DE,en_US
wp ctp run
wp ctp status
wp ctp pause
wp ctp resume
wp ctp run --max-steps=1000
```

Optional extra URLs: `wp ctp start --languages=de_DE --urls-file=/path/to/public-urls.txt`.

For scheduled background steps, configure your server scheduler to run `wp cron event run ctp_tick --due-now` periodically. Only one worker holds the job lock. A pause lets the current in-flight request finish; cancelling retains saved translations. Maximum discovery list: 10,000 URLs per run.

## Security & privacy

- Admin controls require `manage_options` and WordPress nonces. There are no unauthenticated job-control routes.
- Fixed Anthropic HTTPS endpoint, verified TLS and no credential-bearing redirects. HTTP bodies/upstream errors/API keys are not logged by this plugin.
- Native TRP API-test handling is intercepted for this engine so the `x-api-key` header cannot appear in TRP 3.3.8's raw debug output.
- Crawl targets must be on the exact configured site origin, within the installation path, without admin/login/REST/action/cart URLs or embedded credentials. No cookies/authentication are forwarded.
- Signed scan requests only discover content and suppress machine translation. They do not authorize API spending.
- Owned option locks and budget reservations prevent duplicate workers and concurrent limit overspend. Results are validated before returning to TRP.
- Regular-string saves check for human-reviewed edits made while the API request was running. Existing translations are never intentionally replaced. Avoid concurrent manual editing during bulk runs; TRP owns final persistence and its own concurrency semantics.
- Translation sends the selected text, locales and your site instructions to Anthropic. No telemetry, advertising or intermediary translation service is added.

Deactivate to stop scheduled work. Deactivation does not erase TRP translations, plugin settings or API key. Remove the saved key explicitly if you no longer need it.

## Development & tests

```sh
php tests/unit.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/admin.js
node tests/admin.test.cjs
```

`tests/integration.php` runs via `wp eval-file` only when a disposable test site's `wp-config.php` defines `CTP_TEST_SITE=true`. It expects Czech as source, German and English configured with the extra-languages add-on, the Claude engine enabled, working TRP tables and a fixture page containing `Klidný pobyt`, `Pokoje s výhledem`, `Rezervujte nyní`, with slug `vitejte-v-hotelu`. API calls are intercepted in that CLI process. It exercises the actual TRP dictionary, bulk HTTP scan, gettext and SEO Pack persistence, repeated-run reuse and a human edit during an API call. Never run it on production.

CI runs syntax and standalone security/protocol tests on PHP 8.1–8.5. The premium integration suite is run locally; no paid plugin archive or license belongs in this repository. See [TESTING.md](TESTING.md) for the release's verification record.

## License

GPL-2.0-or-later. Independent community plugin; not affiliated with Anthropic or the TranslatePress authors. Anthropic and TranslatePress names belong to their respective owners.
