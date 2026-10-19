<?php
defined( 'ABSPATH' ) || exit;

final class CTP_Engine extends TRP_Machine_Translator {
    public function __construct( $settings ) {
        parent::__construct( $settings );
        // Gettext originals are English even on sites whose source content is another language.
        $this->machine_translation_codes['en_US'] = 'en';
    }
    public function get_api_key() { return CTP_Support::key(); }
    public function get_chunk_size() { return CTP_Client::MAX_STRINGS; }
    /** Reuse a successful provider result when TRP needs the same text in another dictionary row. */
    public function translate( $strings, $target_language_code, $source_language_code = null, $dictionary_context = 'regular' ) {
        if ( ! $this->is_available() || ! is_array( $strings ) ) { return array(); }
        $reset_epoch = CTP_Support::reset_epoch();
        $config = CTP_Support::config();
        $source_language_code = $source_language_code ?? $this->settings['default-language'];
        $scope = wp_json_encode( array( $source_language_code, $target_language_code, $dictionary_context, $config['model'], $config['context'], get_option( 'ctp_cache_epoch', '1' ) ) );
        if ( CTP_Support::$diag !== null ) { ++CTP_Support::$diag['engine_calls']; CTP_Support::$diag['requested'] += count( $strings ); }
        $keys = $missing = $out = array();
        foreach ( $strings as $index => $text ) {
            $keys[$text] = 'ctp_result_' . hash( 'sha256', $scope . '|' . $text );
            $cached = get_transient( $keys[$text] );
            if ( is_string( $cached ) && $cached !== '' ) { $out[$text] = $cached; }
            else { $missing[$index] = $text; }
        }
        $fresh = $missing ? parent::translate( $missing, $target_language_code, $source_language_code, $dictionary_context ) : array();
        if ( CTP_Support::reset_epoch() !== $reset_epoch ) {
            CTP_Support::fail( 'reset_during_request', 'Translations were reset while this request was running. Its results were discarded.' );
            return array();
        }
        foreach ( $fresh as $original => $translated ) {
            if ( isset( $keys[$original] ) && is_string( $translated ) && $translated !== '' ) {
                set_transient( $keys[$original], $translated, MONTH_IN_SECONDS );
            }
        }
        $result = $out + $fresh;
        if ( CTP_Support::$diag !== null ) { CTP_Support::$diag['returned'] += count( $result ); }
        CTP_Support::$write_language = $target_language_code;
        CTP_Support::$write_epoch = $reset_epoch;
        CTP_Support::$write_originals = array_keys( $result );
        return $result;
    }
    /** Strings TRP dropped because another request holds their concurrency lock. */
    public function lock_skipped_count(): int {
        return method_exists( $this, 'get_lock_skipped_strings' ) ? count( $this->get_lock_skipped_strings() ) : 0;
    }
    public function quota_state(): array {
        return array( 'used' => (int) $this->machine_translator_logger->get_todays_character_count(),
            'limit' => (int) ( $this->settings['trp_machine_translation_settings']['machine_translation_limit'] ?? 1000000 ) );
    }
    public function is_available( $languages = array() ) {
        if ( CTP_Support::$scan || ( ! CTP_Support::$bulk && ! CTP_Support::config()['visitor_translation'] ) ) { return false; }
        return (bool) $this->get_api_key() && parent::is_available( $languages );
    }
    public function translate_array( $new_strings, $target_language_code, $source_language_code = null ) {
        CTP_Support::$error = null;
        if ( ! $this->is_available() || ! $this->verify_request_parameters( $target_language_code, $source_language_code ?? $this->settings['default-language'] ) ) {
            CTP_Support::fail( 'unavailable', 'Claude engine is disabled, its quota is exhausted, or the language is unavailable.' );
            return array();
        }
        $translated = array();
        $batch = array(); $bytes = 0;
        foreach ( $new_strings as $key => $text ) {
            if ( CTP_Support::is_xml_declaration( $text ) ) { continue; }
            if ( strlen( $text ) > CTP_Client::MAX_BYTES ) {
                CTP_Support::fail( 'large_string', 'A string exceeds 12 KB. Split the translation block before retrying.' );
                break;
            }
            if ( $batch && ( count( $batch ) >= CTP_Client::MAX_STRINGS || $bytes + strlen( $text ) > CTP_Client::MAX_BYTES ) ) {
                $part = $this->send_batch( $batch, $target_language_code, $source_language_code );
                $translated += $part;
                if ( CTP_Support::$error ) { return $translated; }
                $batch = array(); $bytes = 0;
            }
            $batch[$key] = $text; $bytes += strlen( $text );
        }
        if ( $batch && ! CTP_Support::$error ) {
            $translated += $this->send_batch( $batch, $target_language_code, $source_language_code );
        }
        return $translated;
    }
    private function send_batch( array $batch, string $target, ?string $source ): array {
        $out = ( new CTP_Client() )->translate( $batch, $source ?? $this->settings['default-language'], $target );
        if ( $out ) { $this->machine_translator_logger->count_towards_quota( $batch ); }
        return $out;
    }
    // Settings checks must not issue billable API calls merely by opening wp-admin.
    public function check_api_key_validity() {
        return array( 'error' => ! (bool) $this->get_api_key(), 'message' => $this->get_api_key() ? '' : 'Configure the key under Tools → Claude Translate.' );
    }
}
