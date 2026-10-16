<?php
/** wp eval-file tests/xml-integration.php on a disposable local site only. */
if ( ! defined( 'CTP_TEST_SITE' ) || CTP_TEST_SITE !== true ) { throw new RuntimeException( 'Disposable test site required.' ); }
global $wpdb;
$checks = $calls = 0;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$checks; };
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$calls, $check ) {
    if ( $url !== CTP_Client::ENDPOINT ) { return $pre; }
    ++$calls;
    $items = json_decode( json_decode( $args['body'], true )['messages'][0]['content'], true )['strings'];
    $check( count( $items ) === 1 && ! str_contains( $items[0]['text'], '<?xml' ), 'Only the human string reaches the API' );
    $rows = array_map( static fn( $s ) => array( 'id' => $s['id'], 'text' => 'Translated ' . $s['text'] ), $items );
    return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( array( 'translations' => $rows ) ) ) ) ) ) );
}, 10, 3 );
$trp = TRP_Translate_Press::get_trp_instance(); $query = $trp->get_component( 'query' );
$table = $query->get_table_name( 'de_DE' ); $gettext = $query->get_gettext_table_name( 'de_DE' );
$ids = array(); $gettext_id = 0;
$xml = '<?xml version="1.0" encoding="UTF-8"?>'; $text = 'XML regression ' . wp_generate_uuid4();
try {
    foreach ( array( $xml, $text ) as $source ) {
        $wpdb->insert( $table, array( 'original' => $source, 'status' => 0, 'block_type' => 0 ) ); $ids[] = (int) $wpdb->insert_id;
    }
    $check( min( $ids ) > 0, 'Create isolated regular fixtures' );
    $job = array( 'phase' => 'translate', 'languages' => array( 'de_DE' ), 'language_index' => 0, 'cursor' => $ids[0] - 1, 'translated' => 0, 'skipped' => 0 );
    $translate = new ReflectionMethod( CTP_Bulk::class, 'translate' );
    $translate->invokeArgs( null, array( &$job ) );
    $check( $job['cursor'] === $ids[1] && $job['translated'] === 1, 'Bulk passes XML row and translates next human row' );
    $check( $job['skipped'] === 1 && $job['xml_declarations'] === 1, 'Non-text metadata counted separately' );
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT original,translated,status FROM `{$table}` WHERE id=%d", $ids[0] ), ARRAY_A );
    $check( $row['original'] === $xml && empty( $row['translated'] ) && (int) $row['status'] === 0, 'XML source and pending dictionary status remain unchanged' );
    $saved = $query->get_existing_translations( array( $text ), 'de_DE' );
    $check( ! empty( $saved[$text]->translated ) && $calls === 1, 'Human translation saved through real TRP' );
    $engine = $trp->get_component( 'machine_translator' );
    $before = $calls; $usage = get_option( 'ctp_usage' );
    CTP_Support::$bulk = true;
    $check( $engine->translate_array( array( $xml ), 'de_DE', 'cs_CZ' ) === array() && CTP_Support::$error === null, 'Native engine accepts pure metadata without error' );
    $check( $calls === $before && get_option( 'ctp_usage' ) === $usage, 'Native metadata path makes no API call or reservation' );
    $wpdb->insert( $gettext, array( 'original' => $xml, 'plural_form' => 0, 'status' => 0, 'domain' => 'ctp-test' ) ); $gettext_id = (int) $wpdb->insert_id;
    $check( $gettext_id > 0, 'Create isolated gettext fixture' );
    $job = array( 'phase' => 'gettext', 'languages' => array( 'de_DE' ), 'language_index' => 0, 'cursor' => $gettext_id - 1, 'translated' => 0, 'skipped' => 0 );
    ( new ReflectionMethod( CTP_Bulk::class, 'gettext' ) )->invokeArgs( null, array( &$job ) );
    $check( $job['cursor'] === $gettext_id && $job['xml_declarations'] === 1 && $job['translated'] === 0, 'Gettext XML advances cursor without translation' );
    $check( $calls === $before && (int) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$gettext}` WHERE id=%d", $gettext_id ) ) === 0, 'Gettext metadata remains untouched without spending' );
    $wpdb->insert( $table, array( 'original' => '<?php echo "unsafe";?>', 'status' => 0, 'block_type' => 0 ) ); $unsafe_id = (int) $wpdb->insert_id; $ids[] = $unsafe_id;
    $job = array( 'phase' => 'translate', 'languages' => array( 'de_DE' ), 'language_index' => 0, 'cursor' => $unsafe_id - 1, 'translated' => 0, 'skipped' => 0 );
    $rejected = false;
    try { $translate->invokeArgs( null, array( &$job ) ); } catch ( RuntimeException $e ) { $rejected = str_contains( $e->getMessage(), 'contains code' ); }
    $check( $rejected && $calls === $before, 'Real PHP still blocks without API spending' );
} finally {
    CTP_Support::$bulk = false;
    foreach ( $ids as $id ) { if ( $id ) { $wpdb->delete( $table, array( 'id' => $id ) ); } }
    if ( $gettext_id ) { $wpdb->delete( $gettext, array( 'id' => $gettext_id ) ); }
}
WP_CLI::success( "{$checks} real TRP XML regression assertions; API mocked." );
