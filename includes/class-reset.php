<?php
defined( 'ABSPATH' ) || exit;

final class CTP_Reset {
    private static function authorize(): void {
        if ( ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Administrator access required.' ); }
    }

    /** Build an exact allowlisted plan; never accept a table name from the browser. */
    private static function plan( array $languages ): array {
        global $wpdb;
        $settings = CTP_Support::trp_settings();
        $allowed = array_values( array_diff( $settings['translation-languages'], array( $settings['default-language'] ) ) );
        $languages = array_values( array_unique( $languages ) );
        if ( ! $languages || array_diff( $languages, $allowed ) ) { throw new RuntimeException( 'Select only configured target languages. The source language cannot be reset.' ); }
        $query = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
        $plan = array();
        foreach ( $languages as $language ) {
            foreach ( array( 'regular' => $query->get_table_name( $language ), 'gettext' => $query->get_gettext_table_name( $language ) ) as $kind => $table ) {
                self::check_table( $table, array( 'translated', 'status' ) );
                $plan[] = array( 'table' => $table, 'language' => $language, 'kind' => $kind, 'where' => "(COALESCE(translated,'')<>'' OR status IN (1,2))" );
            }
        }
        $slugs = $wpdb->prefix . 'trp_slug_translations';
        if ( $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $slugs ) ) ) {
            self::check_table( $slugs, array( 'language', 'translated', 'status' ) );
            foreach ( $languages as $language ) {
                $plan[] = array( 'table' => $slugs, 'language' => $language, 'kind' => 'SEO slugs', 'where' => $wpdb->prepare( 'language=%s', $language ) );
            }
        }
        return array( 'source' => $settings['default-language'], 'languages' => $languages, 'tables' => $plan );
    }

    private static function check_table( string $table, array $columns ): void {
        global $wpdb;
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) ) { throw new RuntimeException( 'Invalid TRP table name.' ); }
        $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
        if ( strtoupper( (string) $engine ) !== 'INNODB' ) { throw new RuntimeException( 'Reset requires existing InnoDB translation tables for transactional rollback. No translations were changed.' ); }
        $actual = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );
        if ( $wpdb->last_error || array_diff( $columns, $actual ) ) { throw new RuntimeException( 'Unsupported TRP table schema. No translations were changed.' ); }
    }

    public static function preview( array $languages, string $mode ): array {
        global $wpdb;
        self::authorize();
        if ( ! in_array( $mode, array( 'translations', 'dictionary' ), true ) ) { throw new RuntimeException( 'Choose a valid reset mode.' ); }
        $plan = self::plan( $languages ); $rows = array();
        foreach ( $plan['tables'] as $item ) {
            $where = $mode === 'dictionary' && $item['kind'] !== 'SEO slugs' ? '1=1' : $item['where'];
            $count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$item['table']}` WHERE {$where}" );
            if ( $wpdb->last_error ) { throw new RuntimeException( 'Cannot count saved translations.' ); }
            $rows[] = array( 'language' => $item['language'], 'kind' => $item['kind'], 'count' => (int) $count );
        }
        $token = wp_generate_uuid4();
        set_transient( 'ctp_reset_' . get_current_user_id() . '_' . $token, array( 'source' => $plan['source'], 'languages' => $plan['languages'], 'mode' => $mode ), 600 );
        return array( 'token' => $token, 'languages' => $plan['languages'], 'mode' => $mode, 'counts' => $rows );
    }

    public static function execute( string $token, string $confirmation ): array {
        global $wpdb;
        self::authorize();
        if ( $confirmation !== 'RESET TRANSLATIONS' || ! preg_match( '/^[a-f0-9-]{36}$/D', $token ) ) { throw new RuntimeException( 'Preview first, then type RESET TRANSLATIONS exactly.' ); }
        $transient = 'ctp_reset_' . get_current_user_id() . '_' . $token;
        $preview = get_transient( $transient );
        if ( ! is_array( $preview ) ) { throw new RuntimeException( 'Reset preview expired or was already used. Preview again.' ); }
        $lock = CTP_Support::lock( 'worker', 300 );
        if ( ! $lock ) { throw new RuntimeException( 'A translation batch is finishing. Pause it and retry when it finishes.' ); }
        try {
            $plan = self::plan( $preview['languages'] );
            if ( $plan['source'] !== $preview['source'] ) { throw new RuntimeException( 'Source language changed since preview. Preview again.' ); }
            delete_transient( $transient );
            update_option( 'ctp_paused', 1, false ); wp_clear_scheduled_hook( 'ctp_tick' );
            $config = CTP_Support::config(); $config['visitor_translation'] = false;
            update_option( 'ctp_settings', $config, false );
            // Invalidate any Claude request that started before reset, even if it completes later.
            update_option( 'ctp_reset_epoch', wp_generate_uuid4(), false );
            $affected = 0;
            if ( $wpdb->query( 'START TRANSACTION' ) === false ) { throw new RuntimeException( 'Cannot start reset transaction.' ); }
            try {
                foreach ( $plan['tables'] as $item ) {
                    $sql = $item['kind'] === 'SEO slugs' ? "DELETE FROM `{$item['table']}` WHERE {$item['where']}" : ( $preview['mode'] === 'dictionary' ? "DELETE FROM `{$item['table']}`" : "UPDATE `{$item['table']}` SET translated='',status=0 WHERE {$item['where']}" );
                    $changed = $wpdb->query( $sql );
                    if ( $changed === false ) { throw new RuntimeException( 'Reset failed; translation changes were rolled back. The job remains paused.' ); }
                    $affected += $changed;
                }
                if ( $wpdb->query( 'COMMIT' ) === false ) { throw new RuntimeException( 'Reset commit could not be confirmed. Check database state before retrying.' ); }
            } catch ( Throwable $e ) { $wpdb->query( 'ROLLBACK' ); throw $e; }
            update_option( 'ctp_cache_epoch', wp_generate_uuid4(), false );
            $job = get_option( 'ctp_job', array() );
            if ( $job ) { $job['phase'] = 'cancelled'; $job['error'] = ''; unset( $job['footer_repair'] ); update_option( 'ctp_job', $job, false ); }
            foreach ( $plan['languages'] as $language ) { wp_cache_delete( 'get_all_translation_blocks_' . md5( $language ), 'trp' ); }
            wp_cache_delete( 'cur_page_url_untranslated_slugs', 'trp' );
            if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) { wp_cache_flush_group( 'trp' ); }
            return array( 'message' => 'Reset completed for ' . implode( ', ', $plan['languages'] ) . '. Mode: ' . $preview['mode'] . '. Changed rows: ' . $affected . '. Manual and machine translations were cleared, including current SEO Pack slugs.' . ( $preview['mode'] === 'dictionary' ? ' Target-language dictionary rows were removed; new discovery will rebuild them from current pages.' : ' Existing source dictionary rows were retained.' ) . ' Visitor translation is now off and the old job is cancelled. Clear affected page caches and start a new bulk job. Source-language content, shared TRP source catalogs, API key and Claude settings are retained.' );
        } finally { CTP_Support::unlock( 'worker', $lock ); }
    }
}
