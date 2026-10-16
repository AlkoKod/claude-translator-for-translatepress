=== Claude Translator for TranslatePress ===
Tags: translatepress, claude, translation, anthropic, multilingual
Requires at least: 6.5
Tested up to: 7.1.3
Requires PHP: 8.1
Stable tag: 0.2.0
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

= 0.2.0 =
Default to Sonnet 5.5. Replace forced tools with structured JSON outputs. Use Sonnet 5.5 between_tools thinking mode and low effort. Retain strict ID, placeholder and HTML validation; upgrade the old default model without deleting keys, settings or saved TRP translations.

= 0.1.0 =
Initial preview: native Claude provider, protected placeholders, validated structured JSON responses, TRP dictionary reuse, bulk/gettext/SEO finalization, usage cap, admin UI and WP-CLI.
