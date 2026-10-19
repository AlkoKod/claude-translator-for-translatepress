# Verification record — 0.3.6 preview

Date: 2026-10-16.

## Local environment

- Windows, PHP 8.4.26 (official Windows NTS build, SHA-256 verified).
- WordPress 7.1.3, MariaDB 11.4.5.
- TranslatePress 3.3.8 and Developer 1.8.8, including extra languages and the current SEO Pack.
- No production site changes. No license/API credentials bundled. Paid add-on code remains outside the repository.

## Passed

- PHP lint for all runtime and test PHP files; `node --check assets/admin.js`.
- `php tests/unit.php`: **230 assertions**. HTML/attributes/shortcode/printf/TRP placeholder round-trips; missing/duplicated/reordered placeholders; injected HTML; malformed/truncated/duplicate-ID responses; HTTP 429/transport errors; upstream diagnostic redaction; exact same-origin crawl restrictions; daily cap before HTTP; lock ownership and exclusion. Sonnet 5.5 checks verify structured JSON, no forced tools, between_tools thinking, low effort, refusal handling, unexpected response blocks/fields, malformed JSON and reasoning-block separation.
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

Version 0.2.2 adds 24 standalone assertions for safe HTTP diagnostics. The 21-assertion WordPress/TRP integration run was performed on 0.2.1; the successful-response and persistence paths are unchanged in 0.2.2. No live API error response has been inspected or live-key request made.

Version 0.2.3 adds 8 assertions covering bounded error detail, credential/source redaction, markup removal, request ID handling and HTML proxy response detection. Live error cause remains unverified; install this build and resume once to capture the restricted diagnostic. The successful API response/persistence path is unchanged.

Version 0.2.4 adds workspace selection and 27 additional protocol assertions, including actual mocked HTTP header inspection with and without a workspace, invalid stored IDs blocked before HTTP, CRLF injection rejection, no workspace ID in prompts and actionable handling of the production-reported API error. All PHP files linted on PHP 8.4.26 and 12 admin-progress assertions passed. No live paid API call or fresh full integration run performed for this version.

Version 0.2.5: 175 standalone protocol assertions, 21 admin UI assertions and 12 real WordPress/TRP inspector assertions pass. Inspector tests verify exact row selection, UTF-8 length and bounded preview, regular/gettext plural handling, credential masking, rejection of running/unrelated-error jobs, unchanged source/usage and zero network requests. UI tests verify literal textarea display (including closing-textarea/script input), nonce submission, no resume/translate requests and error recovery. The original full bulk suite was last run on 0.2.1; bulk translation behavior is unchanged by this release.

Production footer diagnosis (read-only public HTML, 2026-10-16): current Czech and German output contains an unterminated PHP opening marker immediately before the footer in Oxygen code_block-242-4754. Running the actual TRP 3.3.8 HTML parser locally on the fetched Czech HTML finds zero footer/newsletter nodes; removing only the stray marker yields one footer and one newsletter node. No live template or cache settings changed. German HTTP response reports x-litespeed-cache: hit.

Version 0.2.6: 200 standalone protocol/security assertions, 21 admin UI assertions, 12 real TRP XML regression assertions and 12 inspector assertions passed. XML tests cover exact declarations, malformed/PHP/mixed inputs, API omission, no byte reservation, advancing the real regular/gettext cursors, native engine behavior, unchanged XML original/status and normal text persistence. Paid API responses remain mocked. PHP lint passes on PHP 8.4.26.
Full 21-assertion WordPress/TRP bulk integration suite also rerun successfully on 0.2.6, including en_GB, SEO persistence, pause/resume and zero additional API calls on the second full run.

Version 0.3.0: 200 protocol/security assertions, 35 admin UI assertions, 22 real WordPress/TRP reset assertions and the full 21-assertion bulk suite passed. Reset tests cover admin access, source-language rejection, preview without deletion, exact typed confirmation, one-use tokens, clearing manual/machine/gettext/current SEO translations, preserving unselected languages, full dictionary removal of malformed historical rows, unchanged API/model/usage, no network calls, forced second-table SQL failure with rollback and stale in-flight response rejection. UI tests cover explicit preview/confirmation flow and selection invalidation. All tests ran only on the disposable local site; no production reset or paid API call was performed.

Version 0.3.1: 230 protocol/security assertions, 35 admin UI assertions, 10 real TRP output-validation assertions, 22 reset assertions, 12 XML assertions and 12 inspector assertions passed. Tests verify protected operators/entities, non-linguistic bypass without network/usage, continued rejection of injected HTML/empty output, exact failed-row diagnostics and inspector support. Real TRP may canonicalize entity spelling; persistence tests compare rendered text. No live model response from the user's failure has been inspected, so its original cause remains unconfirmed. All API responses in tests are mocked.
The full 21-assertion bulk integration suite also passed sequentially on 0.3.1, including persistence reuse on a second complete run.

Version 0.3.2: PHP lint, 230 standalone protocol/security assertions and 35 admin UI assertions pass on PHP 8.4 and in GitHub CI on PHP 8.1–8.5. The new `tests/reviewed-integration.php` (reviewed collation-equivalent skip, partial-batch save, cursor advance, unsaved row IDs in the error) has not yet been run against real WordPress/TRP.

Version 0.3.3: PHP lint, 230 standalone protocol/security assertions and 35 admin UI assertions pass on PHP 8.4. Cause diagnostics were written against the TranslatePress 3.3.8 process_strings/lock code. Only the save-veto cause has an integration assertion (`tests/reviewed-integration.php`), and that test has not yet been run against real WordPress/TRP.

Version 0.3.4: PHP lint, 230 standalone and 35 admin UI assertions pass on PHP 8.4. The cron/placeholder-engine cause was identified from the production diagnostic and the TranslatePress 3.3.8 source. `tests/reviewed-integration.php` adds a placeholder-engine assertion, but that test has not yet been run against real WordPress/TRP.

Version 0.3.5: PHP lint, 265 standalone protocol/security assertions (new: single re-request of omitted items merged by TRP key, rejection after a repeated omission with the original identified, extra rows rejected) and 35 admin UI assertions pass on PHP 8.4. Not run against real WordPress/TRP or a live API.

Version 0.3.6: PHP lint, 284 standalone protocol/security assertions (new: an empty response retries each item alone, an item omitted even alone is recorded without failing, translated items are kept) and 35 admin UI assertions pass on PHP 8.4. The bulk-level skip of omitted rows has not been run against real WordPress/TRP or the live API.
