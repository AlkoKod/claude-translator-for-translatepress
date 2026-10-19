<?php
/** Run via wp eval-file only on a disposable CTP_TEST_SITE. */
if ( ! defined( 'CTP_TEST_SITE' ) || CTP_TEST_SITE !== true ) { throw new RuntimeException( 'Disposable test site required.' ); }
global $wpdb;
$checks = 0; $calls = 0; $ids = array();
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$checks; };
$query = TRP_Translate_Press::get_trp_instance()->get_component( 'query' ); $table = $query->get_table_name( 'de_DE' );
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$calls ) {
    if ( $url !== CTP_Client::ENDPOINT ) { return $pre; }
    ++$calls;
    $items = json_decode( json_decode( $args['body'], true )['messages'][0]['content'], true )['strings'];
    $rows = array_map( static fn( $s ) => array( 'id' => $s['id'], 'text' => 'Translated ' . $s['text'] ), $items );
    return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( array( 'translations' => $rows ) ) ) ) ) ) );
}, 10, 3 );
$insert = static function ( $original, $status, $translated = '' ) use ( $wpdb, $table, &$ids ) {
    $wpdb->insert( $table, array( 'original' => $original, 'translated' => $translated, 'status' => $status, 'block_type' => 0 ) );
    return $ids[] = (int) $wpdb->insert_id;
};
try {
    $token = strtoupper( substr( wp_generate_uuid4(), 0, 8 ) );
    $reviewed_id = $insert( 'Reviewed fixture ' . $token, $query->get_constant_human_reviewed(), 'Geprüft' );
    $variant = 'reviewed fixture ' . strtolower( $token );
    $variant_id = $insert( $variant, 0 );
    $plain = 'Plain fixture ' . wp_generate_uuid4();
    $plain_id = $insert( $plain, 0 );
    $job = array( 'phase' => 'translate', 'languages' => array( 'de_DE' ), 'language_index' => 0, 'cursor' => $variant_id - 1, 'translated' => 0, 'skipped' => 0 );
    ( new ReflectionMethod( CTP_Bulk::class, 'translate' ) )->invokeArgs( null, array( &$job ) );
    $check( (int) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$table}` WHERE id=%d", $variant_id ) ) === 0, 'Collation-equivalent variant of a reviewed row is left pending' );
    $check( $wpdb->get_var( $wpdb->prepare( "SELECT translated FROM `{$table}` WHERE id=%d", $reviewed_id ) ) === 'Geprüft', 'Reviewed translation is untouched' );
    $check( (int) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$table}` WHERE id=%d", $plain_id ) ) !== 0, 'Other strings in the same batch are still saved' );
    $check( $job['reviewed_equivalents'] === 1 && $job['skipped'] === 1 && $job['translated'] === 1 && $job['cursor'] === $plain_id, 'Skip is counted and the cursor advances' );

    $blocked = 'Blocked fixture ' . wp_generate_uuid4();
    $blocked_id = $insert( $blocked, 0 );
    $deny = static fn() => false;
    add_filter( 'trp_allow_string_saving', $deny, 99 );
    $job = array( 'phase' => 'translate', 'languages' => array( 'de_DE' ), 'language_index' => 0, 'cursor' => $blocked_id - 1, 'translated' => 0, 'skipped' => 0 );
    $message = '';
    try { ( new ReflectionMethod( CTP_Bulk::class, 'translate' ) )->invokeArgs( null, array( &$job ) ); }
    catch ( RuntimeException $e ) { $message = $e->getMessage(); }
    remove_filter( 'trp_allow_string_saving', $deny, 99 );
    $check( str_contains( $message, 'not persisted (dictionary rows ' . $blocked_id . ')' ), 'Persistence failure names the unsaved row IDs' );
    $check( str_contains( $message, 'trp_allow_string_saving filter rejected the save' ), 'Persistence failure names the observed cause' );
    $check( $calls === 2, 'One mocked request per batch; skipped variant is never sent' );

    // Simulate a WP-cron request where TRP's hooks did not run and only its placeholder engine exists.
    $trp = TRP_Translate_Press::get_trp_instance();
    $placeholder = new TRP_Machine_Translator( CTP_Support::trp_settings() );
    ( function () use ( $placeholder ) { $this->machine_translator = $placeholder; } )->call( $trp );
    ( function () use ( $placeholder ) { $this->machine_translator = $placeholder; } )->call( $trp->get_component( 'translation_render' ) );
    $cron = 'Cron fixture ' . wp_generate_uuid4();
    $cron_id = $insert( $cron, 0 );
    $job = array( 'phase' => 'translate', 'languages' => array( 'de_DE' ), 'language_index' => 0, 'cursor' => $cron_id - 1, 'translated' => 0, 'skipped' => 0 );
    ( new ReflectionMethod( CTP_Bulk::class, 'translate' ) )->invokeArgs( null, array( &$job ) );
    $check( $trp->get_component( 'machine_translator' ) instanceof CTP_Engine, 'Placeholder engine is replaced by the Claude engine' );
    $check( (int) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$table}` WHERE id=%d", $cron_id ) ) !== 0 && $calls === 3, 'Batch is translated and saved without TRP hooks' );
} finally {
    CTP_Support::$bulk = false;
    foreach ( $ids as $id ) { if ( $id ) { $wpdb->delete( $table, array( 'id' => $id ) ); } }
}
WP_CLI::success( "{$checks} real TRP reviewed-variant assertions; API mocked." );
