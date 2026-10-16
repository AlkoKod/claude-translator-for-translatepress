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
function wp_remote_retrieve_header( $value, $name ) { return $value['headers'][$name] ?? ''; }
function current_user_can( $capability ) { return false; }
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
foreach ( array( 'Vítejte > 5, < 10', 'Vítejte &lt; 5 &gt; 1 &amp; &#160; &#xA0;', '<span>Vítejte</span><!-- unchanged -->' ) as $special ) {
    $special_codec = new CTP_Codec(); $special_encoded = $special_codec->encode( $special );
    check( ! str_contains( $special_encoded, '<' ) && ! str_contains( $special_encoded, '>' ) && ! str_contains( $special_encoded, '&lt;' ), 'literal angle brackets, entities and markup protected before API' );
    check( $special_codec->decode( str_replace( 'Vítejte', 'Willkommen', $special_encoded ) ) === str_replace( 'Vítejte', 'Willkommen', $special ), 'special characters and entities round-trip unchanged' );
    check( $special_codec->has_translatable_text( $special_encoded ), 'human text around markup remains translatable' );
}
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
    if ( $mode === 'empty' ) { $translations[0]['text'] = ''; }
    if ( $mode === 'angle' ) { $translations[0]['text'] .= ' >'; }
    if ( $mode === 'extra_field' ) { $translations[0]['unexpected'] = 'bad'; }
    $text = $mode === 'bad_json' ? '{invalid' : json_encode( array( 'translations' => array_reverse( $translations ) ) );
    $content = array( array( 'type' => 'text', 'text' => $text ) );
    if ( $mode === 'two_texts' ) { $content[] = $content[0]; }
    if ( $mode === 'tool_response' ) { $content[] = array( 'type' => 'tool_use', 'name' => 'unexpected' ); }
    if ( $mode === 'thinking' ) { array_unshift( $content, array( 'type' => 'thinking', 'thinking' => 'ignored private reasoning' ) ); }
    return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'content' => $content, 'stop_reason' => $mode === 'truncated' ? 'max_tokens' : ( $mode === 'refusal' ? 'refusal' : 'end_turn' ) ) ) );
};
$client = new CTP_Client();
foreach ( array(
    array( 400, 'Your credit balance is too low SECRET_UPSTREAM_TEXT', 'API credit balance is too low' ),
    array( 400, 'Please purchase credits SECRET_UPSTREAM_TEXT', 'API credit balance is too low' ),
    array( 404, 'model does not exist SECRET_UPSTREAM_TEXT', 'Selected model is unavailable' ),
    array( 400, 'thinking: unsupported value SECRET_UPSTREAM_TEXT', 'thinking or effort configuration' ),
    array( 400, 'output_config.format invalid schema SECRET_UPSTREAM_TEXT', 'structured output configuration' ),
    array( 400, 'max_tokens is invalid SECRET_UPSTREAM_TEXT', 'token budget or input length' ),
    array( 401, 'SECRET_UPSTREAM_TEXT', 'authentication failed' ),
    array( 403, 'SECRET_UPSTREAM_TEXT', 'permission denied' ),
    array( 429, 'SECRET_UPSTREAM_TEXT', 'rate or spending limit' ),
    array( 529, 'SECRET_UPSTREAM_TEXT', 'temporarily unavailable' ),
    array( 400, 'SECRET_UPSTREAM_TEXT TEST_NOT_A_REAL_KEY', 'does not match a recognized safe diagnostic' ),
) as [ $status, $upstream, $expected ] ) {
    $diagnostic = CTP_Client::http_error( $status, json_encode( array( 'error' => array( 'message' => $upstream ) ) ) );
    check( str_contains( $diagnostic, $expected ), 'safe HTTP diagnostic classification: ' . $expected );
    check( ! str_contains( $diagnostic, 'SECRET' ) && ! str_contains( $diagnostic, 'TEST_NOT_A_REAL_KEY' ), 'upstream text and credentials stay private' );
}
check( str_contains( CTP_Client::http_error( 400, '<html>SECRET_PROXY_BODY</html>' ), 'does not match a recognized safe diagnostic' ), 'non-JSON upstream body handled safely' );
check( str_contains( CTP_Client::http_error( 400, '{"error":{"message":[]}}' ), 'does not match a recognized safe diagnostic' ), 'malformed upstream error handled safely' );
$diagnostic_response = array( 'response' => array( 'code' => 400 ), 'headers' => array( 'request-id' => 'req_0123456789abcdef' ), 'body' => json_encode( array( 'error' => array( 'type' => 'invalid_request_error', 'message' => 'Unrecognized parameter: example_setting. TEST_NOT_A_REAL_KEY sk-ant-secret123 Bearer abc123 Private hotel input <script>bad</script>' ) ) ) );
$diagnostic = CTP_Client::response_diagnostic( $diagnostic_response, array( 'TEST_NOT_A_REAL_KEY', 'Private hotel input' ) );
check( str_contains( $diagnostic, 'example_setting' ), 'unclassified API rejection retains actionable parameter detail' );
check( ! str_contains( $diagnostic, 'TEST_NOT_A_REAL_KEY' ) && ! str_contains( $diagnostic, 'sk-ant-secret123' ) && ! str_contains( $diagnostic, 'abc123' ), 'diagnostics redact current and recognizable credentials' );
check( ! str_contains( $diagnostic, 'Private hotel input' ) && ! str_contains( $diagnostic, '<script>' ), 'diagnostics redact complete known source and remove markup' );
check( str_contains( $diagnostic, 'request-id=req_0123456789abcdef' ), 'Anthropic request identifier retained' );
check( str_contains( $diagnostic, 'type=invalid_request_error' ), 'API error type retained' );
$diagnostic_response['body'] = '<html>SECRET_PROXY_PAGE</html>';
$diagnostic_response['headers'] = array();
$diagnostic = CTP_Client::response_diagnostic( $diagnostic_response, array() );
check( str_contains( $diagnostic, 'HTML/XML response' ) && str_contains( $diagnostic, 'request-id=absent' ) && ! str_contains( $diagnostic, 'SECRET_PROXY_PAGE' ), 'proxy response distinguished without exposing body' );
$diagnostic_response['body'] = json_encode( array( 'request_id' => 'req_abcdef1234567890', 'error' => array( 'message' => str_repeat( 'a', 5000 ) ) ) );
$diagnostic = CTP_Client::response_diagnostic( $diagnostic_response, array() );
check( strlen( $diagnostic ) < 1800, 'diagnostic detail length bounded' );
check( str_contains( $diagnostic, 'req_abcdef1234567890' ), 'body request ID fallback supported' );
$out = $client->translate( array( 8 => 'Vítejte', 19 => '<b>Vítejte</b>' ), 'cs_CZ', 'de_DE' );
check( $out[8] === 'Willkommen' && $out[19] === '<b>Willkommen</b>', 'IDs preserve non-sequential TRP keys despite reversed output' );
foreach ( array( 'duplicate', 'html', 'empty', 'angle', 'truncated', '429', 'network', 'bad_json', 'extra_field', 'two_texts', 'tool_response', 'refusal' ) as $mode ) {
    check( $client->translate( array( 'Vítejte', 'Vítejte znovu' ), 'cs_CZ', 'de_DE' ) === array(), 'bad response rejected atomically: ' . $mode );
    check( ! str_contains( CTP_Support::$error->get_error_message(), 'SECRET' ), 'upstream body never leaked' );
    if ( in_array( $mode, array( 'html', 'empty', 'angle' ), true ) ) { check( CTP_Support::$failed_original === 'Vítejte', 'invalid model output identifies exact original without exposing output' ); }
}
$mode = 'thinking';
check( $client->translate( array( 'Vítejte' ), 'cs_CZ', 'de_DE' ) === array( 'Willkommen' ), 'JSON text parsed independently of thinking blocks' );
check( CTP_Support::workspace_id() === '', 'existing settings default to no workspace header' );
foreach ( array( "wrkspc_abc\r\nx-api-key: injected", 'Default', 'wrkspc_abc/def', 'wrkspc_', str_repeat( 'a', 120 ) ) as $invalid_workspace ) {
    check( ! CTP_Support::valid_workspace_id( $invalid_workspace ), 'invalid workspace ID or header injection rejected' );
}
check( CTP_Support::valid_workspace_id( 'wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ' ) && CTP_Support::valid_workspace_id( '' ), 'documented workspace ID and optional blank accepted' );
$original_mock = $GLOBALS['http_mock'];
$GLOBALS['http_mock'] = function ( $url, $args ) use ( $original_mock ) {
    $workspace = CTP_Support::workspace_id();
    check( $workspace === '' ? ! isset( $args['headers']['anthropic-workspace-id'] ) : ( $args['headers']['anthropic-workspace-id'] ?? '' ) === $workspace, 'workspace header is sent only when selected' );
    check( ! str_contains( $args['body'], 'wrkspc_' ), 'workspace ID stays outside translation prompt' );
    return $original_mock( $url, $args );
};
update_option( 'ctp_settings', array_merge( CTP_Support::config(), array( 'workspace_id' => 'wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ' ) ) );
check( $client->translate( array( 'Vítejte' ), 'cs_CZ', 'de_DE' ) === array( 'Willkommen' ), 'translation succeeds with selected workspace' );
update_option( 'ctp_settings', array_merge( CTP_Support::config(), array( 'workspace_id' => '' ) ) );
check( $client->translate( array( 'Vítejte' ), 'cs_CZ', 'de_DE' ) === array( 'Willkommen' ), 'workspace-scoped keys retain omitted-header behavior' );
$before_workspace = $calls;
update_option( 'ctp_settings', array_merge( CTP_Support::config(), array( 'workspace_id' => "wrkspc_bad\r\ninjected" ) ) );
check( $client->translate( array( 'Vítejte' ), 'cs_CZ', 'de_DE' ) === array() && $calls === $before_workspace, 'invalid stored workspace rejected before HTTP or reservation' );
update_option( 'ctp_settings', array_merge( CTP_Support::config(), array( 'workspace_id' => '' ) ) );
check( str_contains( CTP_Client::http_error( 400, '{"error":{"message":"This API key is not scoped to a workspace; include anthropic-workspace-id"}}' ), 'Set Workspace ID' ), 'reported production error now has actionable workspace guidance' );
$GLOBALS['http_mock'] = $original_mock;
foreach ( array( '<?xml version="1.0" encoding="UTF-8"?>', "<?xml version='1.0'?>", " \n<?xml version = '1.1' encoding='UTF-16' standalone='yes' ?>\r\n", '<?xml version="1.0" standalone="no"?>' ) as $xml ) {
    check( CTP_Support::is_xml_declaration( $xml ), 'complete XML declaration recognized' );
}
foreach ( array( '<?php // silence ?>', '<? //silence', '<?xml-stylesheet href="file.css"?>', '<?xml version="1.0"?><p>Vítejte</p>', 'Vítejte <?xml version="1.0"?>', '<?xml version="1.0"?><?php echo 1;?>', '<?xml version="1.0" encoding="UTF-8"', '<?xml version="1.0" extra="bad"?>', '<?xml version="1.0\'?>', '<?xml version="1.2"?>', '<script>bad</script>', 'Vítejte' ) as $not_xml ) {
    check( ! CTP_Support::is_xml_declaration( $not_xml ), 'code, malformed declarations and surrounding human text never classified as metadata' );
}
$before_xml = $calls; $usage_before_xml = get_option( 'ctp_usage' );
check( $client->translate( array( '<?xml version="1.0" encoding="UTF-8"?>' ), 'cs_CZ', 'de_DE' ) === array() && CTP_Support::$error === null, 'pure XML alone needs no translation and causes no error' );
check( $calls === $before_xml && get_option( 'ctp_usage' ) === $usage_before_xml, 'XML declaration does not call API or reserve bytes' );
$xml_output = $client->translate( array( 4 => '<?xml version="1.0" encoding="UTF-8"?>', 9 => 'Vítejte' ), 'cs_CZ', 'de_DE' );
check( $xml_output === array( 9 => 'Willkommen' ), 'mixed batch translates human text and preserves input key without XML result' );
$before_technical = $calls; $technical_usage = get_option( 'ctp_usage' );
$technical = array( '12345', ' > ', '<span></span>', '&lt;&gt;&nbsp;', 'https://example.test/', '%1$s', '[gallery ids="1"]' );
check( $client->translate( $technical, 'cs_CZ', 'de_DE' ) === $technical, 'non-linguistic strings are returned unchanged locally' );
check( $calls === $before_technical && get_option( 'ctp_usage' ) === $technical_usage, 'non-linguistic strings use no API calls or source reservation' );
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
