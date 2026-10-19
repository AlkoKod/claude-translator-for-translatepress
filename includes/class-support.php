<?php
defined( 'ABSPATH' ) || exit;

final class CTP_Support {
    public static bool $bulk = false;
    public static bool $scan = false;
    public static ?WP_Error $error = null;
    public static ?string $failed_original = null;
    public static ?string $write_language = null;
    public static array $write_originals = array();
    public static ?string $write_epoch = null;
    /** Per-batch evidence for explaining why TRP did not persist a bulk batch; null outside a bulk batch. */
    public static ?array $diag = null;

    /** Bypass the per-request option cache so in-flight responses observe a concurrent reset. */
    public static function reset_epoch(): string {
        global $wpdb;
        $epoch = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", 'ctp_reset_epoch' ) );
        return is_string( $epoch ) ? $epoch : '0';
    }

    public static function config(): array {
        return wp_parse_args( get_option( 'ctp_settings', array() ), array(
            'model' => 'claude-sonnet-5-5', 'context' => '', 'daily_chars' => 100000,
            'visitor_translation' => false, 'api_key' => '', 'workspace_id' => '',
        ) );
    }

    public static function key(): string {
        return defined( 'CTP_ANTHROPIC_API_KEY' ) ? (string) CTP_ANTHROPIC_API_KEY : (string) self::config()['api_key'];
    }

    public static function workspace_id(): string {
        return trim( defined( 'CTP_ANTHROPIC_WORKSPACE_ID' ) ? (string) CTP_ANTHROPIC_WORKSPACE_ID : (string) self::config()['workspace_id'] );
    }

    public static function valid_workspace_id( string $id ): bool {
        return $id === '' || (bool) preg_match( '/^wrkspc_[A-Za-z0-9]{1,100}$/D', $id );
    }

    /** Recognize only an entire XML declaration, never markup, PHP or human text around it. */
    public static function is_xml_declaration( string $text ): bool {
        $pattern = <<<'REGEX'
~\A[\x20\t\r\n]*<\?xml[\x20\t\r\n]+version[\x20\t\r\n]*=[\x20\t\r\n]*(?:"1\.[01]"|'1\.[01]')(?:[\x20\t\r\n]+encoding[\x20\t\r\n]*=[\x20\t\r\n]*(?:"[A-Za-z][A-Za-z0-9._-]*"|'[A-Za-z][A-Za-z0-9._-]*'))?(?:[\x20\t\r\n]+standalone[\x20\t\r\n]*=[\x20\t\r\n]*(?:"(?:yes|no)"|'(?:yes|no)'))?[\x20\t\r\n]*\?>[\x20\t\r\n]*\z~D
REGEX;
        return preg_match( $pattern, $text ) === 1;
    }

    public static function trp_settings(): array {
        return TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
    }

    public static function fail( string $code, string $message ): WP_Error {
        self::$error = new WP_Error( $code, $message );
        return self::$error;
    }

    public static function usage(): array {
        $day = gmdate( 'Y-m-d' );
        $usage = get_option( 'ctp_usage', array() );
        return array( 'day' => $day, 'characters' => is_array( $usage ) && ( $usage['day'] ?? '' ) === $day ? max( 0, (int) ( $usage['characters'] ?? 0 ) ) : 0 );
    }

    /** Atomic option lock with compare-and-delete recovery; never deletes another owner's lock. */
    public static function lock( string $name, int $ttl = 180 ): ?string {
        global $wpdb;
        $key = 'ctp_lock_' . $name;
        $old = get_option( $key );
        if ( is_string( $old ) && (int) explode( ':', $old )[0] < time() ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $old ) );
            wp_cache_delete( $key, 'options' );
        }
        $token = ( time() + $ttl ) . ':' . wp_generate_uuid4();
        return add_option( $key, $token, '', false ) ? $token : null;
    }

    public static function unlock( string $name, string $token ): void {
        global $wpdb;
        $key = 'ctp_lock_' . $name;
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $token ) );
        wp_cache_delete( $key, 'options' );
    }

    /** Reserve before sending. Failed/uncertain calls remain charged to this local safety limit. */
    public static function reserve( int $characters ): bool {
        $lock = self::lock( 'budget', 10 );
        if ( ! $lock ) {
            self::fail( 'budget_busy', 'Usage counter is busy. Retry this batch.' );
            return false;
        }
        try {
            $usage = self::usage();
            if ( $usage['characters'] + $characters > (int) self::config()['daily_chars'] ) {
                self::fail( 'daily_limit', 'Daily character limit reached. Raise the limit or resume after midnight UTC.' );
                return false;
            }
            $usage['characters'] += $characters;
            update_option( 'ctp_usage', $usage, false );
            return true;
        } finally {
            self::unlock( 'budget', $lock );
        }
    }

    public static function valid_url( string $url ): bool {
        $home = wp_parse_url( home_url( '/' ) );
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ||
            strtolower( $parts['host'] ?? '' ) !== strtolower( $home['host'] ?? '' ) ||
            ( $parts['port'] ?? null ) !== ( $home['port'] ?? null ) ||
            ( $parts['scheme'] ?? '' ) !== ( $home['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return false;
        }
        $path = rawurldecode( $parts['path'] ?? '/' );
        $home_path = trailingslashit( $home['path'] ?? '/' );
        if ( ! str_starts_with( trailingslashit( $path ), $home_path ) ||
            preg_match( '~(?:^|/)(?:wp-admin|wp-json|wp-login\.php|wp-cron\.php|xmlrpc\.php|\.\.?)(?:/|$)~i', $path ) ) {
            return false;
        }
        parse_str( $parts['query'] ?? '', $query );
        return ! array_intersect( array_keys( $query ), array( 'rest_route', 'preview', 'action', 'add-to-cart', 'wc-ajax', 'ctp_scan' ) );
    }
}
