<?php
defined( 'ABSPATH' ) || exit;

final class CTP_Admin {
    public static function register(): void {
        add_action( 'admin_menu', static function () {
            add_management_page( 'Claude Translate', 'Claude Translate', 'manage_options', 'ctp-claude', array( self::class, 'page' ) );
        } );
        add_action( 'admin_enqueue_scripts', static function ( $hook ) {
            if ( $hook !== 'tools_page_ctp-claude' ) { return; }
            wp_enqueue_style( 'ctp-admin', plugins_url( 'assets/admin.css', CTP_FILE ), array(), CTP_VERSION );
            wp_enqueue_script( 'ctp-admin', plugins_url( 'assets/admin.js', CTP_FILE ), array(), CTP_VERSION, true );
            wp_localize_script( 'ctp-admin', 'ctpAdmin', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'ctp_admin' ) ) );
        } );
        add_action( 'wp_ajax_ctp_admin', array( self::class, 'ajax' ) );
        // Native TRP 3.3.8 debug output masks Authorization but not x-api-key. Handle our test safely.
        add_action( 'wp_ajax_test_api_key', array( self::class, 'test_native' ), 0 );
        add_action( 'admin_post_ctp_save', array( self::class, 'save' ) );
        add_action( 'trp_machine_translation_extra_settings_middle', static function () {
            echo '<div class="trp-engine" id="ctp_claude"><p>Direct Anthropic API. Configure your key, model and bulk jobs under <a href="' . esc_url( admin_url( 'tools.php?page=ctp-claude' ) ) . '">Tools → Claude Translate</a>. Existing translations stay in TranslatePress.</p></div>';
        } );
    }

    public static function test_native(): void {
        if ( ( CTP_Support::trp_settings()['trp_machine_translation_settings']['translation-engine'] ?? '' ) !== 'ctp_claude' ) { return; }
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Forbidden' ), 403 ); }
        check_ajax_referer( 'trp_test_api_nonce', 'security' );
        $translations = ( new CTP_Client() )->translate( array( 'About us' ), 'en_US', 'de_DE' );
        $data = array(
            'message' => $translations ? 'Claude test succeeded. This small test uses API credits.' : ( CTP_Support::$error ? CTP_Support::$error->get_error_message() : 'Claude test failed.' ),
            'referrer' => home_url( '/' ), 'request' => array( 'url' => CTP_Client::ENDPOINT, 'headers' => '[redacted]' ),
            'response' => array( 'response' => array( 'code' => $translations ? 200 : 'test_failed' ), 'body' => $translations ? wp_json_encode( $translations ) : '' ),
            'raw_response' => '[Credentials and upstream diagnostics intentionally omitted.]',
        );
        if ( $translations ) { wp_send_json_success( $data ); }
        wp_send_json_error( $data );
    }

    public static function save(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'ctp_save' );
        $old = CTP_Support::config();
        $model = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );
        if ( ! preg_match( '/^claude-[a-zA-Z0-9._-]{1,100}$/D', $model ) ) { wp_die( 'Enter a valid Claude model ID.' ); }
        $key = trim( (string) wp_unslash( $_POST['api_key'] ?? '' ) );
        if ( $key !== '' && ! preg_match( '/^[a-zA-Z0-9_-]{20,250}$/D', $key ) ) { wp_die( 'The API key has an invalid format.' ); }
        $workspace = trim( (string) wp_unslash( $_POST['workspace_id'] ?? $old['workspace_id'] ) );
        if ( ! CTP_Support::valid_workspace_id( $workspace ) ) { wp_die( 'Enter a valid Workspace ID starting with wrkspc_ (not the workspace name).' ); }
        $config = array(
            'api_key' => isset( $_POST['clear_key'] ) ? '' : ( $key !== '' ? $key : $old['api_key'] ),
            'model' => $model, 'context' => substr( sanitize_textarea_field( wp_unslash( $_POST['context'] ?? '' ) ), 0, 6000 ),
            'daily_chars' => max( 1000, min( 100000000, absint( $_POST['daily_chars'] ?? 100000 ) ) ),
            'visitor_translation' => ! empty( $_POST['visitor_translation'] ),
            'workspace_id' => $workspace,
        );
        update_option( 'ctp_settings', $config, false );
        if ( ! empty( $_POST['clear_provider_cache'] ) ) { update_option( 'ctp_cache_epoch', wp_generate_uuid4(), false ); }
        wp_safe_redirect( admin_url( 'tools.php?page=ctp-claude&saved=1' ) );
        exit;
    }

    public static function ajax(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Forbidden' ), 403 ); }
        check_ajax_referer( 'ctp_admin', 'nonce' );
        $command = sanitize_key( $_POST['command'] ?? '' );
        try {
            if ( $command === 'start' ) {
                $languages = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['languages'] ?? array() ) );
                $result = CTP_Bulk::create( $languages, (string) wp_unslash( $_POST['urls'] ?? '' ) );
            } elseif ( in_array( $command, array( 'pause', 'resume', 'cancel' ), true ) ) {
                $result = CTP_Bulk::control( $command );
            } elseif ( $command === 'tick' ) {
                $result = CTP_Bulk::tick();
            } elseif ( $command === 'inspect_source' ) {
                $result = CTP_Bulk::inspect_blocked_source();
            } elseif ( $command === 'status' ) {
                $result = CTP_Bulk::status();
            } else { throw new RuntimeException( 'Unknown command.' ); }
            wp_send_json_success( $result );
        } catch ( Throwable $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
        }
    }

    public static function page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $config = CTP_Support::config();
        $settings = class_exists( 'TRP_Translate_Press' ) ? CTP_Support::trp_settings() : array();
        ?>
        <div class="wrap ctp-wrap">
            <h1>Claude Translate <span class="ctp-version"><?php echo esc_html( CTP_VERSION ); ?> · preview</span></h1>
            <p>Direct Anthropic API. Translations are stored and edited in TranslatePress. No separate translation service.</p>
            <?php if ( ! empty( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p>Settings saved.</p></div><?php endif; ?>
            <section class="ctp-card">
                <h2>1. Configure Claude</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="ctp_save">
                    <?php wp_nonce_field( 'ctp_save' ); ?>
                    <table class="form-table" role="presentation"><tbody>
                        <tr><th><label for="ctp-key">Anthropic API key</label></th><td>
                            <input id="ctp-key" type="password" name="api_key" value="" autocomplete="new-password" class="regular-text" placeholder="<?php echo CTP_Support::key() ? 'Key configured — leave blank to keep' : 'sk-ant-…'; ?>" <?php disabled( defined( 'CTP_ANTHROPIC_API_KEY' ) ); ?>>
                            <p class="description">Never displayed again. Alternatively define CTP_ANTHROPIC_API_KEY in wp-config.php. API usage is billed by Anthropic.</p>
                            <?php if ( ! defined( 'CTP_ANTHROPIC_API_KEY' ) ) : ?><label><input type="checkbox" name="clear_key" value="1"> Remove stored key</label><?php endif; ?>
                        </td></tr>
                        <tr><th><label for="ctp-workspace">Workspace ID (optional)</label></th><td>
                            <input id="ctp-workspace" name="workspace_id" value="<?php echo esc_attr( CTP_Support::workspace_id() ); ?>" class="regular-text" placeholder="wrkspc_…" maxlength="107" <?php disabled( defined( 'CTP_ANTHROPIC_WORKSPACE_ID' ) ); ?>>
                            <p class="description">Required for keys that are not scoped to one workspace. Copy the ID from Claude Console → Settings → Workspaces (ID column). Leave blank for a workspace-scoped key. Alternatively define CTP_ANTHROPIC_WORKSPACE_ID in wp-config.php.</p>
                        </td></tr>
                        <tr><th><label for="ctp-model">Model ID</label></th><td><input id="ctp-model" name="model" value="<?php echo esc_attr( $config['model'] ); ?>" class="regular-text" required><p class="description">Default: claude-sonnet-5-5. Use a model available to your Anthropic account that supports structured JSON output.</p></td></tr>
                        <tr><th><label for="ctp-context">Site context / terminology</label></th><td><textarea id="ctp-context" name="context" rows="4" class="large-text" maxlength="6000"><?php echo esc_textarea( $config['context'] ); ?></textarea><p class="description">For example: hospitality website, formal German Sie, preserve the brand name.</p></td></tr>
                        <tr><th><label for="ctp-limit">Daily source limit</label></th><td><input type="number" id="ctp-limit" name="daily_chars" value="<?php echo (int) $config['daily_chars']; ?>" min="1000" max="100000000"><p class="description">UTF-8 source bytes, resetting at midnight UTC. Conservative usage cap, not a currency budget. Failed API attempts count too.</p></td></tr>
                        <tr><th>Visitor-triggered translation</th><td><label><input type="checkbox" name="visitor_translation" value="1" <?php checked( $config['visitor_translation'] ); ?>> Translate new strings when visitors open uncached pages</label><p class="description">Off by default. Saved translations are always served by TRP without calling Claude. Use bulk mode to prepare translations first.</p></td></tr>
                        <tr><th>Provider result cache</th><td><label><input type="checkbox" name="clear_provider_cache" value="1"> Forget cached Claude results when saving settings</label><p class="description">A 30-day result cache prevents duplicate billing across TRP rows and persistence retries. This does not delete saved TRP translations.</p></td></tr>
                    </tbody></table>
                    <?php submit_button( 'Save Claude settings' ); ?>
                </form>
            </section>
            <section class="ctp-card">
                <h2>2. Select the engine in TranslatePress</h2>
                <p>Open <a href="<?php echo esc_url( admin_url( 'admin.php?page=trp_machine_translation' ) ); ?>">TranslatePress → Automatic Translation</a>, enable automatic translation and select <strong>Anthropic Claude (direct API)</strong>.</p>
                <p>Keep your existing TRP languages and SEO Pack settings. This plugin does not add languages or replace TranslatePress licensing.</p>
            </section>
            <section class="ctp-card">
                <h2>3. Translate the site now</h2>
                <p>Scans published public posts and pages, including their rendered headers, footers and builder content. Then translates pending TRP dictionary strings and re-renders pages to apply SEO Pack slug saves. Existing translations are preserved.</p>
                <form id="ctp-job-form">
                    <fieldset><legend class="screen-reader-text">Target languages</legend>
                    <?php foreach ( array_diff( $settings['translation-languages'] ?? array(), array( $settings['default-language'] ?? '' ) ) as $language ) : ?>
                        <label class="ctp-language"><input type="checkbox" name="languages[]" value="<?php echo esc_attr( $language ); ?>" checked> <?php echo esc_html( $language ); ?></label>
                    <?php endforeach; ?>
                    </fieldset>
                    <p><label for="ctp-urls">Extra public URLs, one per line (archives, pagination, special landing pages)</label></p>
                    <textarea id="ctp-urls" name="urls" rows="3" class="large-text" placeholder="https://example.com/category/news/"></textarea>
                    <p class="description">Includes all pending regular strings already in TRP, even from older scans. AJAX-only content needs to be discovered separately in TRP. Only same-origin language URLs are supported.</p>
                    <p><button class="button button-primary" type="submit">Start bulk translation</button> <button class="button" type="button" data-ctp="pause">Pause</button> <button class="button" type="button" data-ctp="resume">Resume</button> <button class="button" type="button" data-ctp="cancel">Cancel job</button></p>
                </form>
                <div id="ctp-status" role="status" aria-live="polite">Loading job status…</div>
                <p><button class="button" type="button" id="ctp-inspect-source">Inspect blocked source (no API call)</button></p>
                <div id="ctp-source-panel" hidden>
                    <p id="ctp-source-info" role="status"></p>
                    <textarea id="ctp-source-preview" class="large-text code" rows="8" readonly aria-label="Blocked source preview"></textarea>
                    <p class="description">Read-only preview of the row blocking this paused job, limited to the first 6,000 characters. Nothing is executed, translated, deleted or excluded. Review for personal data or credentials before copying this preview to support.</p>
                </div>
                <p class="description">Keep this page open to run without visitor traffic. For unattended jobs, configure real server cron to run WordPress scheduled events. A running batch finishes before a pause takes effect.</p>
            </section>
            <section class="ctp-card">
                <h2>Cache & coverage</h2>
                <p>Normal page caching remains active. Only signed discovery requests use no-cache headers. This plugin never purges the entire site cache. After a completed run, clear the affected translated-page caches using your cache plugin, then verify the result.</p>
                <p>Preview release: use a staging copy first. Builder compatibility follows TRP's rendered-output extraction; dynamically opened states, emails and logged-in pages are outside the bulk public-page scan.</p>
            </section>
        </div>
        <?php
    }
}
