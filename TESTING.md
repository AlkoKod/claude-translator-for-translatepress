# Verification record — 0.2.1 preview

Date: 2026-10-16.

## Local environment

- Windows, PHP 8.4.26 (official Windows NTS build, SHA-256 verified).
- WordPress 7.1.3, MariaDB 11.4.5.
- TranslatePress 3.3.8 and Developer 1.8.8, including extra languages and the current SEO Pack.
- No production site changes. No license/API credentials bundled. Paid add-on code remains outside the repository.

## Passed

- PHP lint for all runtime and test PHP files; `node --check assets/admin.js`.
- `php tests/unit.php`: **116 assertions**. HTML/attributes/shortcode/printf/TRP placeholder round-trips; missing/duplicated/reordered placeholders; injected HTML; malformed/truncated/duplicate-ID responses; HTTP 429/transport errors; upstream diagnostic redaction; exact same-origin crawl restrictions; daily cap before HTTP; lock ownership and exclusion. Sonnet 5.5 checks verify structured JSON, no forced tools, between_tools thinking, low effort, refusal handling, unexpected response blocks/fields, malformed JSON and reasoning-block separation.
- `wp eval-file tests/integration.php`: **21 assertions**, running against real WordPress/TRP tables and real local HTTP page rendering with only the Anthropic response mocked. Checks upgraded Sonnet 5.5 model, provider registry, default visitor-call suppression, native dictionary persistence, repeated-render reuse, failed-response non-persistence, human edit during an API call, fresh-install start without pre-existing pause/usage options, pause/resume, full bulk lifecycle with en_GB, unsigned scan fail-closed behavior, rendered Divi/Oxygen/Bricks-shaped fixture text, SEO slug persistence for both DE/EN, and **zero further API calls on a second complete bulk run**.
- Admin progress tests: **12 assertions** for missing, empty and zero-valued usage counters.
- Unauthenticated `ctp_admin` job-control request returns HTTP 400 with WordPress `0`; there is no public AJAX handler.
- Browser: local WordPress login, plugin settings page, native TRP Automatic Translation page, **Anthropic Claude (direct API)** selected in the provider dropdown.

## Not claimed

- A live billable Anthropic call has not been made. Configure a real key and use the native Test API credentials button on staging.
- No complete Divi, Oxygen Legacy or Bricks runtime was installed. Compatibility is exercised at the rendered-HTML/TRP extraction boundary; Gutenberg is rendered by WordPress.
- No real LiteSpeed web server, WP Rocket or WP Fastest Cache stack was installed. Cache coexistence is based on narrow signed-request bypass and no global mutations, not a certified end-to-end matrix.
- PHP 8.1–8.5 CI is configured but has not run on GitHub until the repository is published. PHP 8.4.26 is the runtime actually exercised locally.
- The test site's premium add-on has no production license key. No license-validation logic was disabled or modified. The user's production license stays on their site.
