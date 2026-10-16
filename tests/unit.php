<?php
/** Standalone protocol/security tests. No network, WordPress install or API key needed. */
define( 'ABSPATH', __DIR__ . '/' );
final class WP_Error {
    public function __construct( public string $code, public string $message ) {}
    public function get_error_message(): string { return $this->message; }
}
$GLOBALS['ctp_options'] = array();
function get_option( $key, $default = false ) { return $GLOBALS['ctp_options'][$key] ?? $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['ctp_options'][$key] = $value; return true; }
function add_option( $key, $value, $deprecated = '', $autoload = false ) { if ( isset( $GLOBALS['ctp_options'][$key] ) ) { return false; } $GLOBALS['ctp_options'][$key] = $value; return true; }
function wp_parse_args( $value, $defaults ) { return array_merge( $defaults, $value ); }
function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
function wp_cache_delete( $key, $group ) {}
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( $value ) { return $value['response']['code']; }
function wp_remote_retrieve_body( $value ) { return $value['body']; }
function wp_remote_post( $url, $args ) { return ( $GLOBALS['http_mock'] )( $url, $args ); }
$GLOBALS['wpdb'] = new class {
    public string $options = 'wp_options';
    public function prepare( $sql, ...$args ) { return $args; }
    public function query( $args ) { if ( ( $GLOBALS['ctp_options'][$args[0]] ?? null ) === $args[1] ) { unset( $GLOBALS['ctp_options'][$args[0]] ); } }
};
require __DIR__ . '/../includes/class-support.php';
require __DIR__ . '/../includes/class-codec.php';
require __DIR__ . '/../includes/class-client.php';
$checks = 0;
function check( bool $ok, string $label ): void {
    global $checks;
    if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); }
    ++$checks;
}
function rejects( callable $callback, string $label ): void {
    try { $callback(); } catch ( RuntimeException $e ) { check( true, $label ); return; }
    check( false, $label );
}
$codec = new CTP_Codec();
$original = '<a href="https://example.test/?x=1" class="brxe-link">Vítejte %1$s {{name}} 1TP1T</a> [gallery ids="1"]';
$encoded = $codec->encode( $original );
check( ! str_contains( $encoded, '<a' ) && ! str_contains( $encoded, 'https://' ), 'attributes and HTML never sent as editable text' );
check( $codec->decode( str_replace( 'Vítejte', 'Willkommen', $encoded ) ) === str_replace( 'Vítejte', 'Willkommen', $original ), 'HTML, shortcode and placeholders preserved' );
rejects( fn() => $codec->decode( $encoded . '<script>alert(1)</script>' ), 'new HTML rejected' );
preg_match_all( '/CTPX\w+?X/', $encoded, $tokens );
rejects( fn() => $codec->decode( $encoded . $tokens[0][0] ), 'duplicate token rejected' );
rejects( fn() => $codec->decode( str_replace( $tokens[0][0], '', $encoded ) ), 'missing token rejected' );
$tag_codec = new CTP_Codec(); $tags = $tag_codec->encode( '<strong>A</strong><em>B</em>' );
preg_match_all( '/CTPX\w+?X/', $tags, $tag_tokens );
rejects( fn() => $tag_codec->decode( strtr( $tags, array( $tag_tokens[0][0] => $tag_tokens[0][2], $tag_tokens[0][2] => $tag_tokens[0][0] ) ) ), 'HTML token order enforced' );
foreach ( array( 'https://evil.test/', 'http://example.test/', 'https://example.test/wp-admin/', 'https://example.test/%77p-admin/', 'https://example.test/?add-to-cart=1', 'https://example.test/?rest_route=/wp/v2/users', 'https://name:pass@example.test/' ) as $url ) {
    check( ! CTP_Support::valid_url( $url ), 'unsafe crawl URL rejected' );
}
check( CTP_Support::valid_url( 'https://example.test/de/?p=42' ), 'same-site translated permalink accepted' );
check( CTP_Support::usage() === array( 'day' => gmdate( 'Y-m-d' ), 'characters' => 0 ), 'missing usage option is normalized to zero' );
update_option( 'ctp_usage', array( 'day' => '2000-01-01', 'characters' => 999 ) );
check( CTP_Support::usage()['characters'] === 0, 'yesterday usage is zero today' );
update_option( 'ctp_usage', false );
check( CTP_Support::usage()['characters'] === 0, 'malformed usage option is handled' );
update_option( 'ctp_settings', array( 'api_key' => 'TEST_NOT_A_REAL_KEY', 'daily_chars' => 10000 ) );
$calls = 0; $mode = 'ok';
$GLOBALS['http_mock'] = function ( $url, $args ) use ( &$calls, &$mode ) {
    ++$calls;
    check( $url === CTP_Client::ENDPOINT && $args['redirection'] === 0 && $args['sslverify'], 'fixed endpoint and TLS; no credential redirects' );
    $body = json_decode( $args['body'], true );
    check( $body['model'] === 'claude-sonnet-5-5', 'Sonnet 5.5 is the default model' );
    check( ! isset( $body['tools'] ) && ! isset( $body['tool_choice'] ), 'no forced tool choice for Sonnet 5.5' );
    check( ( $body['output_config']['format']['type'] ?? '' ) === 'json_schema', 'structured JSON response schema sent' );
    check( $body['thinking'] === array( 'type' => 'between_tools' ) && $body['output_config']['effort'] === 'low', 'documented Sonnet 5.5 thinking mode and low effort' );
    $strings = json_decode( $body['messages'][0]['content'], true )['strings'];
    check( ! str_contains( $body['messages'][0]['content'], 'TEST_NOT_A_REAL_KEY' ), 'API key excluded from prompt' );
    if ( $mode === '429' ) { return array( 'response' => array( 'code' => 429 ), 'body' => 'SECRET_UPSTREAM_TEXT' ); }
    if ( $mode === 'network' ) { return new WP_Error( 'transport', 'SECRET_TRANSPORT_TEXT' ); }
    $translations = array_map( static fn( $s ) => array( 'id' => $s['id'], 'text' => str_replace( 'Vítejte', 'Willkommen', $s['text'] ) ), $strings );
    if ( $mode === 'duplicate' ) { $translations[1]['id'] = $translations[0]['id']; }
    if ( $mode === 'html' ) { $translations[0]['text'] .= '<img src=x onerror=alert(1)>'; }
    if ( $mode === 'extra_field' ) { $translations[0]['unexpected'] = 'bad'; }
    $text = $mode === 'bad_json' ? '{invalid' : json_encode( array( 'translations' => array_reverse( $translations ) ) );
    $content = array( array( 'type' => 'text', 'text' => $text ) );
    if ( $mode === 'two_texts' ) { $content[] = $content[0]; }
    if ( $mode === 'tool_response' ) { $content[] = array( 'type' => 'tool_use', 'name' => 'unexpected' ); }
    if ( $mode === 'thinking' ) { array_unshift( $content, array( 'type' => 'thinking', 'thinking' => 'ignored private reasoning' ) ); }
    return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'content' => $content, 'stop_reason' => $mode === 'truncated' ? 'max_tokens' : ( $mode === 'refusal' ? 'refusal' : 'end_turn' ) ) ) );
};
$client = new CTP_Client();
$out = $client->translate( array( 8 => 'Vítejte', 19 => '<b>Vítejte</b>' ), 'cs_CZ', 'de_DE' );
check( $out[8] === 'Willkommen' && $out[19] === '<b>Willkommen</b>', 'IDs preserve non-sequential TRP keys despite reversed output' );
foreach ( array( 'duplicate', 'html', 'truncated', '429', 'network', 'bad_json', 'extra_field', 'two_texts', 'tool_response', 'refusal' ) as $mode ) {
    check( $client->translate( array( 'Vítejte', 'Vítejte znovu' ), 'cs_CZ', 'de_DE' ) === array(), 'bad response rejected atomically: ' . $mode );
    check( ! str_contains( CTP_Support::$error->get_error_message(), 'SECRET' ), 'upstream body never leaked' );
}
$mode = 'thinking';
check( $client->translate( array( 'Vítejte' ), 'cs_CZ', 'de_DE' ) === array( 'Willkommen' ), 'JSON text parsed independently of thinking blocks' );
$before = $calls;
check( $client->translate( array( '<? //silence' ), 'cs_CZ', 'de_DE' ) === array() && $calls === $before, 'broken PHP source rejected before billing' );
update_option( 'ctp_settings', array( 'api_key' => 'TEST_NOT_A_REAL_KEY', 'daily_chars' => 1 ) );
check( $client->translate( array( 'Vítejte' ), 'cs_CZ', 'de_DE' ) === array() && $calls === $before, 'daily limit blocks before HTTP call' );
$lock = CTP_Support::lock( 'test' );
check( $lock && CTP_Support::lock( 'test' ) === null, 'concurrent worker excluded' );
CTP_Support::unlock( 'test', 'wrong-owner' );
check( CTP_Support::lock( 'test' ) === null, 'wrong owner cannot unlock' );
CTP_Support::unlock( 'test', $lock );
check( (bool) CTP_Support::lock( 'test' ), 'owner can release lock' );
echo "PASS: {$checks} protocol/security assertions\n";
