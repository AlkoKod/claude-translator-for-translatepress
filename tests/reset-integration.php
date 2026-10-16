<?php
/** Destructive tests: wp eval-file tests/reset-integration.php on a disposable CTP_TEST_SITE only. */
if ( ! defined( 'CTP_TEST_SITE' ) || CTP_TEST_SITE !== true ) { throw new RuntimeException( 'Disposable test site required.' ); }
global $wpdb;
$checks = 0;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$checks; };
$reject = static function ( $call ) use ( $check ) { try { $call(); } catch ( RuntimeException $e ) { $check( true, 'Invalid reset rejected' ); return; } $check( false, 'Expected reset rejection' ); };
$query = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
$table = $query->get_table_name( 'de_DE' ); $gettext = $query->get_gettext_table_name( 'de_DE' );
$other = $query->get_table_name( 'en_GB' ); $slugs = $wpdb->prefix . 'trp_slug_translations';
$backups = array();
foreach ( array( $table, $gettext, $other, $slugs ) as $name ) { $backups[$name] = $wpdb->get_results( "SELECT * FROM `{$name}`", ARRAY_A ); }
$options = array();
foreach ( array( 'ctp_job', 'ctp_paused', 'ctp_settings', 'ctp_reset_epoch', 'ctp_cache_epoch', 'ctp_usage' ) as $name ) { $options[$name] = get_option( $name ); }
$original_user = get_current_user_id();
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
$http_calls = 0;
$http_guard = static function ( $pre, $args, $url ) use ( &$http_calls ) { ++$http_calls; throw new RuntimeException( 'Reset must not call any API.' ); };
add_filter( 'pre_http_request', $http_guard, 10, 3 );
try {
    wp_set_current_user( 0 ); $reject( static fn() => CTP_Reset::preview( array( 'de_DE' ), 'dictionary' ) );
    wp_set_current_user( $admin );
    $reject( static fn() => CTP_Reset::preview( array( 'cs_CZ' ), 'dictionary' ) );
    $reject( static fn() => CTP_Reset::preview( array( 'de_DE' ), 'unknown' ) );
    $id = wp_generate_uuid4(); $de_ids = array();
    foreach ( array( array( 'Manual ' . $id, 'Reviewed translation', 2 ), array( 'Machine ' . $id, 'Machine translation', 1 ), array( '<? //silence</div><footer>Old footer ' . $id, '', 0 ) ) as [ $original, $translated, $status ] ) {
        $wpdb->insert( $table, array( 'original' => $original, 'translated' => $translated, 'status' => $status, 'block_type' => 0 ) ); $de_ids[] = (int) $wpdb->insert_id;
    }
    $wpdb->insert( $gettext, array( 'original' => 'Gettext ' . $id, 'translated' => 'Gettext translation', 'status' => 2, 'domain' => 'ctp-test' ) );
    $wpdb->insert( $other, array( 'original' => 'Other ' . $id, 'translated' => 'Keep British English', 'status' => 2, 'block_type' => 0 ) ); $other_id = (int) $wpdb->insert_id;
    // Reference an existing original slug so this suite never modifies the shared original catalog.
    $slug_original = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}trp_slug_originals LIMIT 1" );
    $check( $slug_original > 0, 'SEO fixture has a shared original' );
    $check( $wpdb->replace( $slugs, array( 'original_id' => $slug_original, 'translated' => 'reset-fixture-' . $id, 'language' => 'de_DE', 'status' => 2 ) ) !== false, 'Create selected-language SEO fixture' );
    $other_slugs = $wpdb->get_results( "SELECT * FROM `{$slugs}` WHERE language<>'de_DE'", ARRAY_A );
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
    $preview = CTP_Reset::preview( array( 'de_DE' ), 'translations' );
    $check( (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) === $count, 'Preview does not remove dictionary rows' );
    $reject( static fn() => CTP_Reset::execute( $preview['token'], 'wrong phrase' ) );
    $check( (int) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$table}` WHERE id=%d", $de_ids[0] ) ) === 2, 'Wrong confirmation leaves human translation untouched' );
    $epoch = get_option( 'ctp_cache_epoch', '1' );
    CTP_Reset::execute( $preview['token'], 'RESET TRANSLATIONS' );
    $check( (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) === $count, 'Translations-only reset preserves source rows' );
    $check( (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE COALESCE(translated,'')<>'' OR status IN(1,2)" ) === 0, 'Manual and machine regular translations cleared' );
    $check( (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$gettext}` WHERE COALESCE(translated,'')<>'' OR status IN(1,2)" ) === 0, 'Gettext translations cleared' );
    $check( (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$slugs}` WHERE language='de_DE'" ) === 0 && $wpdb->get_results( "SELECT * FROM `{$slugs}` WHERE language<>'de_DE'", ARRAY_A ) === $other_slugs, 'Only selected SEO slug translations removed' );
    $check( $wpdb->get_var( $wpdb->prepare( "SELECT translated FROM `{$other}` WHERE id=%d", $other_id ) ) === 'Keep British English', 'Unselected language remains translated' );
    $check( get_option( 'ctp_cache_epoch' ) !== $epoch && ! CTP_Support::config()['visitor_translation'] && get_option( 'ctp_paused' ), 'Result cache invalidated; visitors disabled and worker paused' );
    $reject( static fn() => CTP_Reset::execute( $preview['token'], 'RESET TRANSLATIONS' ) );
    // Force failure on the second table and verify rollback of the first table's update.
    $wpdb->update( $table, array( 'translated' => 'Rollback sentinel', 'status' => 2 ), array( 'id' => $de_ids[0] ) );
    $preview = CTP_Reset::preview( array( 'de_DE' ), 'translations' );
    $inject_failure = static function ( $sql ) use ( $gettext ) { return str_starts_with( $sql, "UPDATE `{$gettext}` SET translated=" ) ? 'UPDATE ctp_nonexistent_reset_test_table SET impossible=1' : $sql; };
    $suppress = $wpdb->suppress_errors( true ); add_filter( 'query', $inject_failure );
    try { $reject( static fn() => CTP_Reset::execute( $preview['token'], 'RESET TRANSLATIONS' ) ); }
    finally { remove_filter( 'query', $inject_failure ); $wpdb->suppress_errors( $suppress ); }
    $check( $wpdb->get_var( $wpdb->prepare( "SELECT translated FROM `{$table}` WHERE id=%d", $de_ids[0] ) ) === 'Rollback sentinel', 'Failure on a later table rolls back earlier translation changes' );
    $preview = CTP_Reset::preview( array( 'de_DE' ), 'dictionary' );
    CTP_Reset::execute( $preview['token'], 'RESET TRANSLATIONS' );
    $check( (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) === 0 && (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$gettext}`" ) === 0, 'Full refresh removes regular/gettext source rows, including malformed historical footer' );
    $check( CTP_Support::key() === $options['ctp_settings']['api_key'] && CTP_Support::config()['model'] === $options['ctp_settings']['model'], 'API key and model retained' );
    $check( get_option( 'ctp_usage' ) === $options['ctp_usage'] && $http_calls === 0, 'Reset makes no network requests and does not reset spending limits' );
    remove_filter( 'pre_http_request', $http_guard, 10 );
    $race_original = 'Reset race ' . wp_generate_uuid4();
    $epoch_race = static function ( $pre, $args, $url ) {
        if ( $url !== CTP_Client::ENDPOINT ) { return $pre; }
        update_option( 'ctp_reset_epoch', wp_generate_uuid4(), false );
        $items = json_decode( json_decode( $args['body'], true )['messages'][0]['content'], true )['strings'];
        $rows = array_map( static fn( $s ) => array( 'id' => $s['id'], 'text' => 'Stale response' ), $items );
        return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( array( 'translations' => $rows ) ) ) ) ) ) );
    };
    add_filter( 'pre_http_request', $epoch_race, 10, 3 ); CTP_Support::$bulk = true;
    $trp = TRP_Translate_Press::get_trp_instance(); $trp->get_component( 'translation_render' )->process_strings( array( $race_original ), 'de_DE' );
    $check( CTP_Support::$error && CTP_Support::$error->get_error_code() === 'reset_during_request', 'In-flight Claude response detects a concurrent reset' );
    $check( empty( $query->get_existing_translations( array( $race_original ), 'de_DE' ) ), 'Stale in-flight response is not saved after reset' );
    remove_filter( 'pre_http_request', $epoch_race, 10 );
} finally {
    CTP_Support::$bulk = false; CTP_Support::$write_epoch = null; CTP_Support::$write_originals = array();
    foreach ( $backups as $name => $rows ) { $wpdb->query( "DELETE FROM `{$name}`" ); foreach ( $rows as $row ) { $wpdb->insert( $name, $row ); } }
    foreach ( $options as $name => $value ) { if ( $value === false ) { delete_option( $name ); } else { update_option( $name, $value, false ); } }
    wp_set_current_user( $original_user );
}
WP_CLI::success( "{$checks} real WordPress/TRP reset assertions; no live API spend." );
