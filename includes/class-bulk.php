<?php
defined( 'ABSPATH' ) || exit;

final class CTP_Bulk {
    public static function register(): void {
        add_action( 'plugins_loaded', array( self::class, 'scan_request' ), -999 );
        add_action( 'ctp_tick', array( self::class, 'tick' ) );
    }

    /** Only a signed, short-lived loopback request may bypass cache and collect strings. */
    public static function scan_request(): void {
        // Even if a proxy strips authentication headers, discovery must never trigger visitor API billing.
        if ( isset( $_GET['ctp_scan'] ) ) {
            CTP_Support::$scan = true;
            add_filter( 'trp_machine_translator_is_available', '__return_false', PHP_INT_MAX );
            add_filter( 'trp_gettext_allow_machine_translation', '__return_false', PHP_INT_MAX );
        }
        $stamp = (int) ( $_SERVER['HTTP_X_CTP_TIME'] ?? 0 );
        $signature = (string) ( $_SERVER['HTTP_X_CTP_SIGNATURE'] ?? '' );
        $uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        if ( ! $stamp || abs( time() - $stamp ) > 120 || ! hash_equals( hash_hmac( 'sha256', $stamp . '|' . $uri, wp_salt( 'auth' ) ), $signature ) ) {
            return;
        }
        CTP_Support::$scan = true;
        if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
        add_filter( 'trp_machine_translator_is_available', '__return_false', PHP_INT_MAX );
        add_filter( 'trp_gettext_allow_machine_translation', '__return_false', PHP_INT_MAX );
        add_action( 'send_headers', static function () {
            nocache_headers();
            header( 'X-CTP-Scan: collected' );
            do_action( 'litespeed_control_set_nocache', 'Claude TranslatePress discovery' );
        } );
    }

    public static function schedule(): void {
        if ( ! get_option( 'ctp_paused', true ) && ! wp_next_scheduled( 'ctp_tick' ) ) {
            wp_schedule_single_event( time() + 5, 'ctp_tick' );
        }
    }

    public static function assert_ready(): void {
        if ( ! class_exists( 'TRP_Translate_Press' ) ) { throw new RuntimeException( 'Activate TranslatePress first.' ); }
        $settings = CTP_Support::trp_settings();
        $mt = $settings['trp_machine_translation_settings'] ?? array();
        if ( ( $mt['translation-engine'] ?? '' ) !== 'ctp_claude' || ( $mt['machine-translation'] ?? '' ) !== 'yes' ) {
            throw new RuntimeException( 'Enable Automatic Translation and select Anthropic Claude in TranslatePress settings first.' );
        }
        if ( ! CTP_Support::key() ) { throw new RuntimeException( 'Save your Anthropic API key first.' ); }
        if ( ! method_exists( TRP_Translate_Press::get_trp_instance()->get_component( 'translation_render' ), 'process_strings' ) ) {
            throw new RuntimeException( 'This TranslatePress version does not expose the required string processing API.' );
        }
    }

    public static function create( array $languages, string $extra_urls ): array {
        self::assert_ready();
        $lock = CTP_Support::lock( 'worker' );
        if ( ! $lock ) { throw new RuntimeException( 'The previous worker is still finishing. Try again shortly.' ); }
        try {
            $old = get_option( 'ctp_job', array() );
            if ( $old && ! in_array( $old['phase'], array( 'done', 'cancelled' ), true ) ) {
                throw new RuntimeException( 'Resume or cancel the existing job before starting another.' );
            }
            $settings = CTP_Support::trp_settings();
            $allowed = array_diff( $settings['translation-languages'], array( $settings['default-language'] ) );
            $languages = array_values( array_intersect( array_unique( $languages ), $allowed ) );
            if ( ! $languages ) { throw new RuntimeException( 'Select at least one configured target language.' ); }
            $urls = array( home_url( '/' ) );
            $types = get_post_types( array( 'public' => true ) );
            unset( $types['attachment'] );
            $offset = 0;
            do {
                $ids = get_posts( array( 'post_type' => array_values( $types ), 'post_status' => 'publish', 'has_password' => false,
                    'numberposts' => 200, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'suppress_filters' => true ) );
                foreach ( $ids as $id ) {
                    if ( is_post_publicly_viewable( $id ) ) { $urls[] = get_permalink( $id ); }
                }
                $offset += 200;
                if ( count( $urls ) > 10000 ) { throw new RuntimeException( 'This release supports up to 10,000 URLs per run.' ); }
            } while ( count( $ids ) === 200 );
            foreach ( preg_split( '/\R/', $extra_urls ) as $url ) {
                if ( trim( $url ) !== '' ) {
                    $url = esc_url_raw( trim( $url ) );
                    if ( ! CTP_Support::valid_url( $url ) ) { throw new RuntimeException( 'Extra URLs must be public pages on this site, with no administrative or action parameters.' ); }
                    $urls[] = $url;
                }
            }
            $urls = array_values( array_unique( array_filter( $urls, array( CTP_Support::class, 'valid_url' ) ) ) );
            if ( count( $urls ) > 10000 ) { throw new RuntimeException( 'Too many URLs. Limit this run to 10,000.' ); }
            $job = array( 'id' => wp_generate_uuid4(), 'phase' => 'discover', 'source' => $settings['default-language'],
                'languages' => $languages, 'urls' => $urls, 'url_index' => 0, 'language_index' => 0,
                'cursor' => 0, 'translated' => 0, 'skipped' => 0, 'error' => '', 'created' => time(), 'updated' => time() );
            update_option( 'ctp_job', $job, false );
            // WordPress skips update_option(false) on a missing option. Explicit 0 persists on first install.
            update_option( 'ctp_paused', 0, false );
            self::schedule();
            return self::status();
        } finally { CTP_Support::unlock( 'worker', $lock ); }
    }

    public static function status(): array {
        $job = get_option( 'ctp_job', array() );
        if ( ! $job ) { return array( 'phase' => 'idle', 'paused' => true ); }
        // Avoid sending all URLs or any source strings to the admin browser.
        $out = $job;
        unset( $out['urls'] );
        $out['url_count'] = count( $job['urls'] );
        $out['paused'] = (bool) get_option( 'ctp_paused', true );
        $out['usage'] = CTP_Support::usage();
        return $out;
    }

    public static function control( string $command ): array {
        if ( $command === 'pause' || $command === 'cancel' ) {
            update_option( 'ctp_paused', 1, false );
            wp_clear_scheduled_hook( 'ctp_tick' );
        }
        $lock = CTP_Support::lock( 'worker' );
        if ( ! $lock ) {
            if ( $command === 'pause' ) { return self::status(); }
            throw new RuntimeException( 'A batch is finishing. Pause is applied; retry this command in a moment.' );
        }
        try {
            $job = get_option( 'ctp_job', array() );
            if ( ! $job ) { throw new RuntimeException( 'No job exists.' ); }
            if ( $command === 'cancel' ) { $job['phase'] = 'cancelled'; }
            if ( $command === 'resume' ) {
                self::assert_ready();
                if ( in_array( $job['phase'], array( 'done', 'cancelled' ), true ) ) { throw new RuntimeException( 'Start a new job instead.' ); }
                $job['error'] = '';
                update_option( 'ctp_paused', 0, false );
            }
            update_option( 'ctp_job', $job, false );
            self::schedule();
            return self::status();
        } finally { CTP_Support::unlock( 'worker', $lock ); }
    }

    /** Read only the row that blocked the current paused job. Caller must check admin capability and nonce. */
    public static function inspect_blocked_source(): array {
        global $wpdb;
        $job = get_option( 'ctp_job', array() );
        if ( ! $job || ! get_option( 'ctp_paused', true ) || ! in_array( $job['phase'], array( 'translate', 'gettext' ), true ) ||
            ! preg_match( '/^(?:Dictionary row (\d+) contains code or an oversized block\.|Unsafe or oversized gettext source at row (\d+)\.|Translation validation failed at (?:dictionary|gettext) row (\d+):)/', $job['error'] ?? '', $matches ) ) {
            throw new RuntimeException( 'No blocked dictionary source is recorded. Keep the existing job paused after its source error.' );
        }
        $id = (int) ( ( $matches[1] ?? '' ) ?: ( ( $matches[2] ?? '' ) ?: ( $matches[3] ?? 0 ) ) );
        $language = $job['languages'][$job['language_index']] ?? '';
        $settings = CTP_Support::trp_settings();
        if ( ! in_array( $language, $settings['translation-languages'], true ) || $language === $settings['default-language'] ) {
            throw new RuntimeException( 'The job language is no longer configured in TranslatePress.' );
        }
        $query = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
        $gettext = $job['phase'] === 'gettext';
        $table = $gettext ? $query->get_gettext_table_name( $language ) : $query->get_table_name( $language );
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) ) { throw new RuntimeException( 'Invalid dictionary table name.' ); }
        $sql = "SELECT LENGTH(t.original) AS source_bytes, LEFT(t.original, 6000) AS source FROM `{$table}` t WHERE t.id=%d";
        if ( $gettext ) {
            $originals = $query->get_table_name_for_gettext_original_strings();
            if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $originals ) ) { throw new RuntimeException( 'Invalid gettext originals table name.' ); }
            $expression = 'CASE WHEN t.plural_form>0 AND o.original_plural IS NOT NULL AND o.original_plural<>\'\' THEN o.original_plural ELSE t.original END';
            $sql = "SELECT LENGTH({$expression}) AS source_bytes, LEFT({$expression}, 6000) AS source FROM `{$table}` t LEFT JOIN `{$originals}` o ON o.id=t.original_id WHERE t.id=%d";
        }
        $row = $wpdb->get_row( $wpdb->prepare( $sql, $id ), ARRAY_A );
        if ( $wpdb->last_error || ! $row ) { throw new RuntimeException( 'The blocked dictionary row could not be read. Nothing was changed.' ); }
        $source = (string) $row['source'];
        $reasons = array();
        if ( str_starts_with( $job['error'], 'Translation validation failed at ' ) ) { $reasons[] = 'Claude output failed validation; see the job error for the exact reason. This does not by itself indicate a problem in the original source.'; }
        if ( (int) $row['source_bytes'] > CTP_Client::MAX_BYTES ) { $reasons[] = 'Source exceeds the 12,000-byte request limit.'; }
        if ( CTP_Support::is_xml_declaration( $source ) && (int) $row['source_bytes'] <= strlen( $source ) ) {
            $reasons[] = 'Pure XML declaration: non-translatable metadata. Resume to leave this row unchanged and continue.';
        } elseif ( str_contains( $source, '<?' ) ) { $reasons[] = 'Contains a PHP/XML opening marker (<?).'; }
        if ( preg_match( '~<(?:script|style)\b~i', $source ) ) { $reasons[] = 'Contains a script or style tag.'; }
        if ( ! $reasons ) { $reasons[] = 'No blocker in the preview. The source may have changed, or a marker may occur after the first 6,000 characters.'; }
        $key = CTP_Support::key();
        if ( $key !== '' ) { $source = str_replace( $key, '[API KEY REDACTED]', $source ); }
        $source = preg_replace( '/(?:sk-ant-|Bearer\s+)[A-Za-z0-9_.\-]+/i', '[CREDENTIAL REDACTED]', $source );
        return array( 'row_id' => $id, 'language' => $language, 'kind' => $gettext ? 'gettext' : 'regular',
            'source_bytes' => (int) $row['source_bytes'], 'preview_limit' => 6000, 'reason' => implode( ' ', $reasons ), 'source' => $source );
    }

    public static function tick(): array {
        $lock = CTP_Support::lock( 'worker' );
        if ( ! $lock ) { return self::status(); }
        try {
            $job = get_option( 'ctp_job', array() );
            if ( ! $job || get_option( 'ctp_paused', true ) || in_array( $job['phase'], array( 'done', 'cancelled' ), true ) ) { return self::status(); }
            try {
                self::assert_ready();
                self::engine();
                $settings = CTP_Support::trp_settings();
                if ( $job['source'] !== $settings['default-language'] || array_diff( $job['languages'], $settings['translation-languages'] ) ) {
                    throw new RuntimeException( 'TranslatePress languages changed. Cancel this job and start a new one.' );
                }
                if ( in_array( $job['phase'], array( 'discover', 'finalize' ), true ) ) { self::discover( $job ); }
                elseif ( $job['phase'] === 'gettext' ) { self::gettext( $job ); }
                else { self::translate( $job ); }
            } catch ( Throwable $e ) {
                $job['error'] = $e->getMessage();
                update_option( 'ctp_paused', 1, false );
            }
            $job['updated'] = time();
            update_option( 'ctp_job', $job, false );
        } finally {
            CTP_Support::$bulk = false;
            CTP_Support::unlock( 'worker', $lock );
        }
        self::schedule();
        return self::status();
    }

    private static function discover( array &$job ): void {
        $language = $job['languages'][$job['language_index']];
        $converter = TRP_Translate_Press::get_trp_instance()->get_component( 'url_converter' );
        $url = html_entity_decode( $converter->get_url_for_language( $language, $job['urls'][$job['url_index']], '' ), ENT_QUOTES );
        self::fetch( $url );
        ++$job['url_index'];
        if ( $job['url_index'] >= count( $job['urls'] ) ) { $job['url_index'] = 0; ++$job['language_index']; }
        if ( $job['language_index'] >= count( $job['languages'] ) ) {
            if ( $job['phase'] === 'finalize' ) {
                $job['phase'] = 'done'; update_option( 'ctp_paused', 1, false );
            } else {
                $job['phase'] = 'translate'; $job['language_index'] = 0;
            }
        }
    }

    private static function fetch( string $url ): void {
        for ( $redirects = 0; $redirects < 4; ++$redirects ) {
            if ( ! CTP_Support::valid_url( $url ) ) { throw new RuntimeException( 'Discovery URL is outside this site. Different-domain language setups are not supported by this release.' ); }
            $url = add_query_arg( 'ctp_scan', wp_generate_uuid4(), $url );
            $parts = wp_parse_url( $url );
            $uri = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
            $stamp = time();
            $response = wp_remote_get( $url, array( 'timeout' => 45, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 5242880,
                'user-agent' => 'ClaudeTranslatePress/0.1 discovery',
                'headers' => array( 'Cache-Control' => 'no-cache', 'X-CTP-Time' => $stamp,
                    'X-CTP-Signature' => hash_hmac( 'sha256', $stamp . '|' . $uri, wp_salt( 'auth' ) ) ) ) );
            if ( is_wp_error( $response ) ) { throw new RuntimeException( 'Discovery loopback failed. Check TLS, firewall and server loopback access.' ); }
            $code = wp_remote_retrieve_response_code( $response );
            if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
                $location = wp_remote_retrieve_header( $response, 'location' );
                $url = WP_Http::make_absolute_url( $location, remove_query_arg( 'ctp_scan', $url ) );
                $url = remove_query_arg( 'ctp_scan', $url );
                continue;
            }
            if ( $code !== 200 || wp_remote_retrieve_header( $response, 'x-ctp-scan' ) !== 'collected' ||
                ! str_contains( strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) ), 'text/html' ) ) {
                throw new RuntimeException( 'Discovery did not reach an uncached HTML page (HTTP ' . $code . '). Check cache query-string bypass and custom-header forwarding.' );
            }
            return;
        }
        throw new RuntimeException( 'Discovery exceeded its redirect limit.' );
    }

    /** Read pending originals; TRP owns all dictionary writes, sanitization and status values. */
    private static function translate( array &$job ): void {
        global $wpdb;
        $trp = TRP_Translate_Press::get_trp_instance();
        $query = $trp->get_component( 'query' );
        $language = $job['languages'][$job['language_index']];
        $table = $query->get_table_name( $language );
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) ) { throw new RuntimeException( 'Invalid TRP dictionary table name.' ); }
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, original FROM `{$table}` WHERE id > %d AND status = 0 AND block_type != 2 ORDER BY id LIMIT 16", $job['cursor'] ), ARRAY_A );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'Cannot read the TRP dictionary. Save TranslatePress language settings to create its tables.' ); }
        if ( ! $rows ) {
            ++$job['language_index']; $job['cursor'] = 0;
            if ( $job['language_index'] >= count( $job['languages'] ) ) {
                $job['phase'] = 'gettext'; $job['language_index'] = 0;
            }
            return;
        }
        $strings = $processed_ids = array(); $bytes = 0; $cursor = $job['cursor']; $skipped = $xml_skipped = $reviewed_skipped = 0;
        $reviewed = $query->get_constant_human_reviewed();
        foreach ( $rows as $row ) {
            $text = $row['original'];
            if ( CTP_Support::is_xml_declaration( $text ) ) { $cursor = (int) $row['id']; ++$skipped; ++$xml_skipped; continue; }
            if ( $strings && $bytes + strlen( $text ) > CTP_Client::MAX_BYTES ) { break; }
            if ( strlen( $text ) > CTP_Client::MAX_BYTES || preg_match( '~<\?|<(?:script|style)\b~i', $text ) ) {
                throw new RuntimeException( 'Dictionary row ' . (int) $row['id'] . ' contains code or an oversized block. Fix or exclude this source in TRP before resuming.' );
            }
            $cursor = (int) $row['id'];
            if ( trim( $text ) === '' || filter_var( $text, FILTER_VALIDATE_URL ) || preg_match( '~^(?:mailto:|tel:|#)~i', $text ) ) { ++$skipped; continue; }
            // The save guard matches originals by table collation; one reviewed case/accent/space variant would veto the whole batch.
            if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE original = %s AND status = %d AND id <> %d LIMIT 1", $text, $reviewed, $cursor ) ) ) {
                ++$skipped; ++$reviewed_skipped; continue;
            }
            $strings[] = $text; $processed_ids[] = $cursor; $bytes += strlen( $text );
        }
        if ( $strings ) {
            CTP_Support::$bulk = true; CTP_Support::$error = null; CTP_Support::$failed_original = null; CTP_Support::$omitted = array();
            $engine = self::engine();
            $locked_before = $engine->lock_skipped_count();
            CTP_Support::$diag = array( 'table' => $table, 'engine_calls' => 0, 'requested' => 0, 'returned' => 0, 'save_calls' => array(), 'pending_write' => false, 'db_error' => '' );
            try {
                // TRP's normal renderer consults its dictionary before calling our engine and saves results itself.
                $trp->get_component( 'translation_render' )->process_strings( $strings, $language );
                $wpdb->query( 'SELECT 1' ); // Flushes a trailing dictionary-write error into the diagnostics.
                $diag = CTP_Support::$diag;
            } finally { CTP_Support::$diag = null; }
            if ( CTP_Support::$error ) {
                foreach ( $rows as $row ) {
                    if ( $row['original'] === CTP_Support::$failed_original ) { throw new RuntimeException( 'Translation validation failed at dictionary row ' . (int) $row['id'] . ': ' . CTP_Support::$error->get_error_message() ); }
                }
                throw new RuntimeException( CTP_Support::$error->get_error_message() );
            }
            // Verify the exact pending row IDs; a collation-equivalent title is not proof that a slug row was saved.
            $pending = $wpdb->get_col( "SELECT id FROM `{$table}` WHERE id IN (" . implode( ',', $processed_ids ) . ') AND status=0 ORDER BY id' );
            if ( $wpdb->last_error ) { throw new RuntimeException( 'Cannot verify saved translations in the TRP dictionary.' ); }
            $pending = self::leave_omitted( $job, $pending );
            if ( $pending ) {
                throw new RuntimeException( 'Some translations were not persisted (dictionary rows ' . implode( ', ', array_map( 'intval', $pending ) ) . '). ' . self::persist_reason( $diag, $engine, $locked_before, count( $strings ) ) . ' Saved strings will be reused.' );
            }
            $job['translated'] += max( 0, count( array_unique( $strings ) ) - count( CTP_Support::$omitted ) );
        }
        $job['skipped'] += $skipped;
        $job['xml_declarations'] = ( $job['xml_declarations'] ?? 0 ) + $xml_skipped;
        $job['reviewed_equivalents'] = ( $job['reviewed_equivalents'] ?? 0 ) + $reviewed_skipped;
        $job['cursor'] = $cursor;
    }

    /**
     * WP-cron requests can skip TRP's hook loader (e.g. "Do not translate certain paths" does not match /wp-cron.php),
     * leaving its placeholder engine, which is never available. TRP repairs WooCommerce cron emails the same way.
     */
    private static function engine(): CTP_Engine {
        $trp = TRP_Translate_Press::get_trp_instance();
        if ( ! $trp->get_component( 'machine_translator' ) instanceof CTP_Engine && method_exists( $trp, 'init_machine_translation' ) ) {
            $trp->init_machine_translation();
        }
        $engine = $trp->get_component( 'machine_translator' );
        if ( ! $engine instanceof CTP_Engine ) {
            throw new RuntimeException( 'TranslatePress did not load the Claude engine in this request. Save the TranslatePress Automatic Translation settings, then resume.' );
        }
        // The renderer caches the engine lazily; replace a placeholder cached before initialization.
        ( function () use ( $engine ) { if ( $this->machine_translator && ! $this->machine_translator instanceof CTP_Engine ) { $this->machine_translator = $engine; } } )
            ->call( $trp->get_component( 'translation_render' ) );
        return $engine;
    }

    /**
     * Rows Claude returned nothing for, even alone, stay untranslated and are listed instead of pausing the job.
     * TRP rewrites text before the engine sees it, so match by count: only when every unsaved row is accounted for.
     */
    private static function leave_omitted( array &$job, array $pending ): array {
        if ( ! $pending || ! CTP_Support::$omitted || count( $pending ) > count( CTP_Support::$omitted ) ) { return $pending; }
        $job['omitted'] = ( $job['omitted'] ?? 0 ) + count( $pending );
        $job['omitted_rows'] = array_slice( array_merge( $job['omitted_rows'] ?? array(), array_map( 'intval', $pending ) ), 0, 100 );
        return array();
    }

    /** Explain an unsaved batch from what this request observed; never guesses beyond that evidence. */
    private static function persist_reason( array $diag, $engine, int $locked_before, int $sent ): string {
        global $trp_machine_translation_deadline;
        $locked = $engine instanceof CTP_Engine ? $engine->lock_skipped_count() - $locked_before : 0;
        if ( ! $diag['engine_calls'] ) {
            if ( isset( $trp_machine_translation_deadline ) && microtime( true ) > $trp_machine_translation_deadline ) {
                return 'Cause: TranslatePress did not call the engine because its per-request time budget (trp_machine_translation_time_budget) had expired.';
            }
            return 'Cause: TranslatePress did not call the engine for these strings; it treated them as already present in its dictionary or not machine-translatable.';
        }
        if ( $locked > 0 ) {
            return 'Cause: TranslatePress skipped ' . $locked . ' of ' . $sent . ' strings because another request holds their translation lock (in progress or translated within the last ~50 s). Wait a minute, then resume.';
        }
        if ( ! $diag['returned'] ) {
            return 'Cause: the engine returned no translations.' . ( $engine instanceof CTP_Engine ? ' TRP characters today: ' . implode( ' of ', $engine->quota_state() ) . '.' : '' );
        }
        if ( array_filter( $diag['save_calls'], static fn( $call ) => ! $call['allowed'] ) ) {
            return 'Cause: a trp_allow_string_saving filter rejected the save (a human-reviewed equivalent, a translation reset, or another plugin).';
        }
        if ( $diag['db_error'] !== '' ) {
            return 'Cause: the TRP dictionary write failed with a database error: ' . mb_substr( preg_replace( '/[\x00-\x1f\x7f]/', ' ', $diag['db_error'] ), 0, 300 ) . '.';
        }
        if ( ! $diag['save_calls'] ) {
            return 'Cause: TranslatePress received ' . $diag['returned'] . ' translations but did not attempt to save them; their originals did not match its original-strings table.';
        }
        return 'Cause: TranslatePress saved ' . array_sum( array_column( $diag['save_calls'], 'rows' ) ) . ' rows, but not these dictionary rows. Check for duplicate originals that differ only in case, accents or spacing.';
    }

    private static function gettext( array &$job ): void {
        global $wpdb;
        $trp = TRP_Translate_Press::get_trp_instance();
        $query = $trp->get_component( 'query' );
        $language = $job['languages'][$job['language_index']];
        $table = $query->get_gettext_table_name( $language );
        $originals = $query->get_table_name_for_gettext_original_strings();
        foreach ( array( $table, $originals ) as $name ) {
            if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $name ) ) { throw new RuntimeException( 'Invalid gettext table name.' ); }
        }
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.id, t.original, t.domain, t.plural_form, o.original_plural, o.context FROM `{$table}` t LEFT JOIN `{$originals}` o ON o.id=t.original_id WHERE t.id > %d AND t.status=0 ORDER BY t.id LIMIT 16", $job['cursor'] ), ARRAY_A );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'Cannot read TRP gettext tables. Check TranslatePress database migrations.' ); }
        if ( ! $rows ) {
            ++$job['language_index']; $job['cursor'] = 0;
            if ( $job['language_index'] >= count( $job['languages'] ) ) {
                $job['phase'] = 'finalize'; $job['language_index'] = 0; $job['url_index'] = 0;
            }
            return;
        }
        $queue = array(); $bytes = 0; $cursor = $job['cursor']; $xml_skipped = 0;
        foreach ( $rows as $row ) {
            $text = $row['original_plural'] && (int) $row['plural_form'] > 0 ? $row['original_plural'] : $row['original'];
            if ( CTP_Support::is_xml_declaration( $text ) ) { $cursor = (int) $row['id']; ++$xml_skipped; continue; }
            if ( $queue && $bytes + strlen( $text ) > CTP_Client::MAX_BYTES ) { break; }
            if ( strlen( $text ) > CTP_Client::MAX_BYTES || preg_match( '~<\?|<(?:script|style)\b~i', $text ) ) {
                throw new RuntimeException( 'Unsafe or oversized gettext source at row ' . (int) $row['id'] . '. Correct it before resuming.' );
            }
            $cursor = (int) $row['id'];
            if ( trim( $text ) === '' || preg_match( '/^en(?:_|$)/i', $language ) ) { ++$job['skipped']; continue; }
            $row['status'] = $query->get_constant_machine_translated();
            $row['translated'] = '';
            $queue[] = $row; $bytes += strlen( $text );
        }
        if ( $queue ) {
            // Expose TRP's protected queue runner without copying its persistence implementation.
            require_once CTP_DIR . 'includes/class-gettext-runner.php';
            CTP_Support::$bulk = true; CTP_Support::$error = null; CTP_Support::$failed_original = null; CTP_Support::$omitted = array();
            self::engine();
            ( new CTP_Gettext_Runner( CTP_Support::trp_settings() ) )->run_queue( $queue, $language );
            if ( CTP_Support::$error ) {
                foreach ( $rows as $row ) {
                    $effective = $row['original_plural'] && (int) $row['plural_form'] > 0 ? $row['original_plural'] : $row['original'];
                    if ( $effective === CTP_Support::$failed_original ) { throw new RuntimeException( 'Translation validation failed at gettext row ' . (int) $row['id'] . ': ' . CTP_Support::$error->get_error_message() ); }
                }
                throw new RuntimeException( CTP_Support::$error->get_error_message() );
            }
            $ids = array_map( 'intval', array_column( $queue, 'id' ) );
            $pending = $wpdb->get_col( "SELECT id FROM `{$table}` WHERE id IN (" . implode( ',', $ids ) . ') AND status=0 ORDER BY id' );
            if ( $wpdb->last_error ) { throw new RuntimeException( 'Cannot verify saved translations in the TRP gettext table.' ); }
            $pending = self::leave_omitted( $job, $pending );
            if ( $pending ) { throw new RuntimeException( 'Gettext translations were not saved (gettext rows ' . implode( ', ', array_map( 'intval', $pending ) ) . '). Check TRP exclusions and quota, then resume.' ); }
            $job['translated'] += max( 0, count( $queue ) - count( CTP_Support::$omitted ) );
        }
        $job['skipped'] += $xml_skipped;
        $job['xml_declarations'] = ( $job['xml_declarations'] ?? 0 ) + $xml_skipped;
        $job['cursor'] = $cursor;
    }
}
