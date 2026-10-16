<?php
/** Run via wp eval-file only on a disposable CTP_TEST_SITE. */
if ( ! defined( 'CTP_TEST_SITE' ) || CTP_TEST_SITE !== true ) { throw new RuntimeException( 'Disposable test site required.' ); }
global $wpdb;
$checks = 0; $mode = 'empty'; $calls = 0; $ids = array();
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$checks; };
$query = TRP_Translate_Press::get_trp_instance()->get_component( 'query' ); $table = $query->get_table_name( 'de_DE' );
$old_job = get_option( 'ctp_job' ); $old_paused = get_option( 'ctp_paused' );
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$calls, &$mode ) {
    if ( $url !== CTP_Client::ENDPOINT ) { return $pre; }
    ++$calls;
    $items = json_decode( json_decode( $args['body'], true )['messages'][0]['content'], true )['strings'];
    $rows = array_map( static fn( $s ) => array( 'id' => $s['id'], 'text' => $mode === 'empty' ? '' : ( $mode === 'html' ? '<img src=x onerror=alert(1)>' : 'Translated ' . $s['text'] ) ), $items );
    return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( array( 'translations' => $rows ) ) ) ) ) ) );
}, 10, 3 );
try {
    foreach ( array( 'empty', 'html' ) as $mode ) {
        $source = 'Validation fixture ' . wp_generate_uuid4();
        $wpdb->insert( $table, array( 'original' => $source, 'translated' => '', 'status' => 0, 'block_type' => 0 ) ); $id = (int) $wpdb->insert_id; $ids[] = $id;
        $job = array( 'phase' => 'translate', 'languages' => array( 'de_DE' ), 'language_index' => 0, 'cursor' => $id - 1, 'translated' => 0, 'skipped' => 0 );
        $message = '';
        try { ( new ReflectionMethod( CTP_Bulk::class, 'translate' ) )->invokeArgs( null, array( &$job ) ); }
        catch ( RuntimeException $e ) { $message = $e->getMessage(); }
        $check( str_starts_with( $message, 'Translation validation failed at dictionary row ' . $id . ':' ), 'Validation failure identifies exact real TRP row' );
        $check( str_contains( $message, $mode === 'empty' ? 'empty translation' : 'unprotected angle bracket' ), 'Empty and unsafe output have distinct diagnostics' );
        $check( empty( $query->get_existing_translations( array( $source ), 'de_DE' ) ), 'Rejected response is not persisted' );
        $job['error'] = $message; update_option( 'ctp_job', $job, false ); update_option( 'ctp_paused', 1, false );
        $preview = CTP_Bulk::inspect_blocked_source();
        $check( $preview['row_id'] === $id && $preview['source'] === $source && str_contains( $preview['reason'], 'Claude output failed validation' ), 'Read-only inspector supports failed model output' );
    }
    $mode = 'ok'; $source = 'Operator fixture ' . wp_generate_uuid4() . ' > 5 &lt; 10';
    $wpdb->insert( $table, array( 'original' => $source, 'translated' => '', 'status' => 0, 'block_type' => 0 ) ); $id = (int) $wpdb->insert_id; $ids[] = $id;
    $job = array( 'phase' => 'translate', 'languages' => array( 'de_DE' ), 'language_index' => 0, 'cursor' => $id - 1, 'translated' => 0, 'skipped' => 0 );
    ( new ReflectionMethod( CTP_Bulk::class, 'translate' ) )->invokeArgs( null, array( &$job ) );
    $saved = $query->get_existing_translations( array( $source ), 'de_DE' );
    $check( ! empty( $saved[$source]->translated ) && str_contains( html_entity_decode( $saved[$source]->translated, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), '> 5 < 10' ), 'Protected operators/entities persist with equivalent rendered text through real TRP' );
    $check( $calls === 3, 'Exactly one mocked request per case; no automatic retry' );
} finally {
    CTP_Support::$bulk = false;
    foreach ( $ids as $id ) { if ( $id ) { $wpdb->delete( $table, array( 'id' => $id ) ); } }
    if ( $old_job === false ) { delete_option( 'ctp_job' ); } else { update_option( 'ctp_job', $old_job, false ); }
    if ( $old_paused === false ) { delete_option( 'ctp_paused' ); } else { update_option( 'ctp_paused', $old_paused, false ); }
}
WP_CLI::success( "{$checks} real TRP output-validation assertions; API mocked." );
