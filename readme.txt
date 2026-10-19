=== Claude Translator for TranslatePress ===
Tags: translatepress, claude, translation, anthropic, multilingual
Requires at least: 6.5
Tested up to: 7.1.3
Requires PHP: 8.1
Stable tag: 0.3.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Direct Anthropic Claude provider for TranslatePress, with resumable bulk translation and native TRP storage.

== Description ==

Adds Anthropic Claude (direct API) to TranslatePress Automatic Translation.
Translations remain in TranslatePress and can be edited in its normal editor.
Tools > Claude Translate provides key/model settings, spending controls and bulk translation.

Preview release. Tested with TranslatePress 3.3.8 and Developer 1.8.8 on PHP 8.4.26.
Use staging and a live-key smoke test before production. Full builder/cache-stack compatibility has not been certified.

== Installation ==

1. Install and activate TranslatePress.
2. Upload and activate this plugin.
3. Configure Tools > Claude Translate.
4. Enable automatic translation and select Anthropic Claude in TranslatePress.
5. Start a bulk job. Keep its page open or run wp ctp run for unattended processing.

== External services ==

This plugin sends selected website text, source/target locale codes and your configured site instructions to Anthropic's Messages API at https://api.anthropic.com/v1/messages when a translation or API test is explicitly triggered, or when optional visitor translation is enabled and TRP encounters new content. Requires your own API key and separately billed API usage.

Anthropic terms: https://www.anthropic.com/legal/commercial-terms
Anthropic privacy: https://www.anthropic.com/legal/privacy

Bulk discovery also requests public pages on the same WordPress site. There is no telemetry or other third-party translation gateway.

== Frequently Asked Questions ==

= Does every page visit call Claude? =
No. Saved translations are served by TranslatePress. Visitor-triggered translation is off by default and can be enabled for new strings. A provider-result cache also avoids duplicate requests across repeated dictionary rows.

= Is Claude API access free? =
No. This plugin is free software; Anthropic API usage is billed separately.

= Does this replace TranslatePress Pro? =
No. Extra languages, SEO Pack and other premium features still require your normal TranslatePress entitlement.

= Can it work with page builders? =
It uses TRP's rendered HTML extraction. Gutenberg rendering and representative Divi/Oxygen/Bricks HTML fixtures are tested. Full builder plugin certification is not claimed.

== Changelog ==

= 0.3.6 =
A text Claude will not translate no longer stops the bulk job. Items missing from a response are now sent again one at a time, so one problem text cannot block the rest of the batch. A text that still gets no translation when sent alone stays untranslated, and its row ID appears in the job status ("Left untranslated") so it can be translated manually in TranslatePress. Replaces the 0.3.5 behaviour, which rejected the whole batch after one retry.

= 0.3.5 =
A bulk job no longer stops with "Response has missing or extra translations" when Claude leaves an item out of an otherwise valid response. The returned items are validated and kept, and only the missing ones are requested again, once. If they are missing again, the batch is rejected and the error names the dictionary row. Extra, duplicate or unknown items are still rejected.

= 0.3.4 =
Fix bulk batches run by WP-cron being left unsaved with "TranslatePress did not call the engine". When TranslatePress skips its hooks for /wp-cron.php (for example because of "Do not translate certain paths"), only its placeholder engine is loaded and it never reports itself available. Bulk mode now loads the Claude engine itself, the same way TranslatePress handles WooCommerce cron emails.

= 0.3.3 =
When TranslatePress does not save a bulk batch, the error now states the cause this request observed: engine not called, strings held by another request's translation lock, no engine result, a trp_allow_string_saving veto, a dictionary database error, or originals missing from the original-strings table.

= 0.3.2 =
A bulk job no longer stalls with "Some translations were not persisted" when a human-reviewed string differs from a pending one only in case, accents or trailing spaces. Such rows are left for review and counted separately; the rest of the batch is saved. Persistence errors now list the unsaved dictionary row IDs.

= 0.3.1 =
Protect literal angle brackets and entities. Keep non-linguistic strings unchanged without API calls. Distinguish empty output from introduced HTML and identify exact failed regular/gettext rows for inspection. Preserve validation and existing translations; no automatic paid retries.

= 0.3.0 =
Add explicit selected-language translation reset and full dictionary refresh with preview counts, typed confirmation, per-user single-use tokens, InnoDB rollback, worker exclusion and in-flight Claude response invalidation. Include current SEO Pack slugs. Preserve site content/settings and keep visitor translation off after reset. Full refresh rebuilds from current pages and removes stale malformed target dictionary rows.

= 0.2.6 =
Recognize pure XML declarations as non-translatable metadata. Continue regular/gettext bulk jobs without changing those rows or spending API credits. Preserve protection for PHP, malformed markup and mixed text; show XML metadata count separately.

= 0.2.5 =
Add read-only inspection of the exact dictionary row blocking a paused bulk job. Show bounded literal source preview, byte count and blocking reason with credential masking. No API calls, automatic skipping or dictionary modifications.

= 0.2.4 =
Support multi-workspace API keys with optional validated Workspace ID and anthropic-workspace-id request header. Add actionable guidance for workspace selection errors. Preserve existing jobs and translations.

= 0.2.3 =
Add restricted, bounded API error details with key/input redaction, request ID, error type and non-JSON response detection. No automatic retries.

= 0.2.2 =
Add safe API failure classifications, including HTTP 400 for insufficient API credits, without exposing upstream response text or automatically retrying.

= 0.2.1 =
Fix fresh-install jobs pausing immediately and undefined usage counters. Fail closed for unauthenticated discovery requests. Handle English-source gettext for en_GB and other English targets.

= 0.2.0 =
Default to Sonnet 5.5. Replace forced tools with structured JSON outputs. Use Sonnet 5.5 between_tools thinking mode and low effort. Retain strict ID, placeholder and HTML validation; upgrade the old default model without deleting keys, settings or saved TRP translations.

= 0.1.0 =
Initial preview: native Claude provider, protected placeholders, validated structured JSON responses, TRP dictionary reuse, bulk/gettext/SEO finalization, usage cap, admin UI and WP-CLI.
