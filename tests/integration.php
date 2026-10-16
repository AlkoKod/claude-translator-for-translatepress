<?php
/** Run only on an isolated WordPress test install: wp eval-file tests/integration.php */
if ( ! defined( 'CTP_TEST_SITE' ) || CTP_TEST_SITE !== true ) {
    throw new RuntimeException( 'Set CTP_TEST_SITE=true ONLY in a disposable local WordPress test installation.' );
}
$checks = 0; $calls = 0; $mode = 'ok'; $race_original = null;
$check = static function ( bool $ok, string $message ) use ( &$checks ) {
    if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $message ); }
    ++$checks;
};
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$calls, &$mode, &$race_original ) {
    if ( $url !== CTP_Client::ENDPOINT ) { return $pre; }
    ++$calls;
    if ( $mode === 'fail' ) { return array( 'response' => array( 'code' => 429 ), 'body' => '{}', 'headers' => array() ); }
    $input = json_decode( $args['body'], true );
    $strings = json_decode( $input['messages'][0]['content'], true )['strings'];
    if ( $race_original ) {
        $query = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
        $ids = $query->get_string_ids( array( $race_original ), 'de_DE' );
        $query->update_strings( array( array( 'id' => $ids[$race_original]->id, 'original' => $race_original, 'translated' => 'Human reviewed during API call', 'status' => 2 ) ), 'de_DE', array( 'id', 'original', 'translated', 'status' ) );
        $race_original = null;
    }
    $rows = array_map( static fn( $s ) => array( 'id' => $s['id'], 'text' => 'Translated ' . $s['text'] ), $strings );
    return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( array( 'translations' => $rows ) ) ) ) ) ), 'headers' => array() );
}, 10, 3 );
$trp = TRP_Translate_Press::get_trp_instance();
$query = $trp->get_component( 'query' );
$renderer = $trp->get_component( 'translation_render' );
$engine = $trp->get_component( 'machine_translator' );
$check( CTP_Support::config()['model'] === 'claude-sonnet-5-5', 'new default or upgraded saved model is Sonnet 5.5' );
$check( $engine instanceof CTP_Engine, 'TRP instantiates Claude from its native provider registry' );
$check( ! $engine->is_available(), 'visitor-triggered API calls are disabled by default' );
foreach ( array( 'de_DE', 'en_US' ) as $language ) { $query->check_table( 'cs_CZ', $language ); }
$id = wp_generate_uuid4();
$strings = array( 'CTP ' . $id . ' Vítejte <b>v hotelu</b>', 'CTP ' . $id . ' Cena %s za pokoj' );
CTP_Support::$bulk = true;
$renderer->process_strings( $strings, 'de_DE' );
$check( $calls === 1, 'first native translation is one API call; calls=' . $calls . ', engine_error=' . ( CTP_Support::$error ? CTP_Support::$error->get_error_message() : 'none' ) );
$saved = $query->get_existing_translations( $strings, 'de_DE' );
$check( count( $saved ) === 2 && str_contains( $saved[$strings[0]]->translated, '<b>v hotelu</b>' ), 'TRP persists translated strings and restores HTML' );
$renderer->process_strings( $strings, 'de_DE' );
$check( $calls === 1, 'repeat rendering reuses dictionary; zero additional API calls' );
$new = 'CTP failure ' . wp_generate_uuid4();
$mode = 'fail';
$renderer->process_strings( array( $new ), 'de_DE' );
$check( empty( $query->get_existing_translations( array( $new ), 'de_DE' ) ), 'failed API response is not saved as a translation' );
$check( CTP_Support::$error instanceof WP_Error, 'failure is surfaced to worker' );
$mode = 'ok';
$race = 'CTP concurrent edit ' . wp_generate_uuid4();
$query->insert_strings( array( $race ), 'de_DE' ); $race_original = $race;
$renderer->process_strings( array( $race ), 'de_DE' );
$race_saved = $query->get_existing_translations( array( $race ), 'de_DE' );
$check( $race_saved[$race]->translated === 'Human reviewed during API call', 'human edit made during API request is preserved' );
CTP_Support::$bulk = false;
// Full discovery -> regular -> gettext -> finalization against a real local HTTP server and MySQL.
delete_option( 'ctp_job' ); update_option( 'ctp_paused', true );
CTP_Bulk::create( array( 'de_DE', 'en_US' ), '' );
for ( $i = 0; $i < 300; ++$i ) {
    unset( $GLOBALS['trp_machine_translation_deadline'] );
    $state = CTP_Bulk::tick();
    if ( $state['error'] ) { throw new RuntimeException( $state['error'] ); }
    if ( $state['phase'] === 'done' ) { break; }
}
$check( $state['phase'] === 'done', 'full bulk job finishes without visitor traffic' );
$check( $state['translated'] > 0, 'bulk job translates discovered content' );
foreach ( array( 'Klidný pobyt', 'Pokoje s výhledem', 'Rezervujte nyní' ) as $fixture ) {
    $saved = $query->get_existing_translations( array( $fixture ), 'de_DE' );
    $check( ! empty( $saved[$fixture]->translated ), 'rendered builder fixture translated: ' . $fixture );
}
if ( function_exists( 'trp_in_sp_create_db_tables' ) ) {
    global $wpdb;
    $slug_languages = $wpdb->get_col( $wpdb->prepare( "SELECT t.language FROM {$wpdb->prefix}trp_slug_originals o JOIN {$wpdb->prefix}trp_slug_translations t ON t.original_id=o.id WHERE o.original=%s AND t.translated<>''", 'vitejte-v-hotelu' ) );
    $check( in_array( 'de_DE', $slug_languages, true ) && in_array( 'en_US', $slug_languages, true ), 'SEO Pack persists slugs for both target languages' );
}
$before = $calls;
delete_option( 'ctp_job' ); update_option( 'ctp_paused', true );
CTP_Bulk::create( array( 'de_DE', 'en_US' ), '' );
for ( $i = 0; $i < 300; ++$i ) {
    unset( $GLOBALS['trp_machine_translation_deadline'] );
    $state = CTP_Bulk::tick();
    if ( $state['error'] ) { throw new RuntimeException( $state['error'] ); }
    if ( $state['phase'] === 'done' ) { break; }
}
$check( $state['phase'] === 'done' && $calls === $before, 'second full run uses persisted translations without API calls' );
WP_CLI::success( "{$checks} real TRP/WordPress integration assertions; API mocked, no API spend." );
