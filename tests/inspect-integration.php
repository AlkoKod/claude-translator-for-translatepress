<?php
/** wp eval-file tests/inspect-integration.php on an isolated test site only. */
if ( ! defined( 'CTP_TEST_SITE' ) || CTP_TEST_SITE !== true ) { throw new RuntimeException( 'Disposable test site required.' ); }
global $wpdb;
$checks = 0; $calls = 0;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$checks; };
$reject = static function () use ( $check ) {
    try { CTP_Bulk::inspect_blocked_source(); } catch ( RuntimeException $e ) { $check( true, 'Rejected invalid inspector state' ); return; }
    $check( false, 'Inspector should reject invalid state' );
};
add_filter( 'pre_http_request', static function () use ( &$calls ) { ++$calls; throw new RuntimeException( 'Inspector must not make HTTP calls.' ); } );
$old_job = get_option( 'ctp_job' ); $old_paused = get_option( 'ctp_paused' ); $usage = get_option( 'ctp_usage' );
$query = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
$table = $query->get_table_name( 'de_DE' );
$gettext = $query->get_gettext_table_name( 'de_DE' );
$originals = $query->get_table_name_for_gettext_original_strings();
$id = $gettext_id = $plural_id = 0;
try {
    $source = '<?php echo "must never execute"; ' . CTP_Support::key() . ' sk-ant-test-secret';
    $wpdb->insert( $table, array( 'original' => $source, 'status' => 0, 'block_type' => 0 ) ); $id = (int) $wpdb->insert_id;
    $check( $id > 0, 'Create isolated regular fixture' );
    $job = array( 'phase' => 'translate', 'languages' => array( 'de_DE' ), 'language_index' => 0, 'error' => 'Dictionary row ' . $id . ' contains code or an oversized block. Fix or exclude this source in TRP before resuming.' );
    update_option( 'ctp_job', $job, false ); update_option( 'ctp_paused', 1, false );
    $row = CTP_Bulk::inspect_blocked_source();
    $check( $row['row_id'] === $id && $row['kind'] === 'regular', 'Exact blocked row selected' );
    $check( $row['source_bytes'] === strlen( $source ) && str_contains( $row['reason'], 'opening marker' ), 'Source length and PHP/XML reason' );
    $check( ! str_contains( $row['source'], CTP_Support::key() ) && ! str_contains( $row['source'], 'sk-ant-test-secret' ), 'Credentials masked in preview' );
    $check( $wpdb->get_var( $wpdb->prepare( "SELECT original FROM `{$table}` WHERE id=%d", $id ) ) === $source, 'Inspector leaves original unchanged' );
    $large = str_repeat( 'ž', 6500 );
    $wpdb->update( $table, array( 'original' => $large ), array( 'id' => $id ) );
    $row = CTP_Bulk::inspect_blocked_source();
    $check( $row['source_bytes'] === 13000 && mb_strlen( $row['source'] ) === 6000, 'UTF-8 byte count and bounded character preview' );
    $check( str_contains( $row['reason'], '12,000-byte' ), 'Oversize reason distinguished from code' );
    update_option( 'ctp_paused', 0, false ); $reject(); update_option( 'ctp_paused', 1, false );
    $job['error'] = 'Anthropic returned HTTP 400'; update_option( 'ctp_job', $job, false ); $reject();
    $wpdb->insert( $originals, array( 'original' => 'One fixture', 'original_plural' => '<script>plural fixture</script>', 'domain' => 'ctp-test' ) ); $plural_id = (int) $wpdb->insert_id;
    $wpdb->insert( $gettext, array( 'original' => 'One fixture', 'original_id' => $plural_id, 'plural_form' => 1, 'status' => 0, 'domain' => 'ctp-test' ) ); $gettext_id = (int) $wpdb->insert_id;
    $check( $plural_id > 0 && $gettext_id > 0, 'Create isolated gettext plural fixtures' );
    $job['phase'] = 'gettext'; $job['error'] = 'Unsafe or oversized gettext source at row ' . $gettext_id . '. Correct it before resuming.'; update_option( 'ctp_job', $job, false );
    $row = CTP_Bulk::inspect_blocked_source();
    $check( $row['kind'] === 'gettext' && str_contains( $row['source'], 'plural fixture' ) && str_contains( $row['reason'], 'script or style' ), 'Gettext preview uses effective plural source' );
    $check( $calls === 0 && get_option( 'ctp_usage' ) === $usage, 'No network calls or usage reservation' );
} finally {
    if ( $id ) { $wpdb->delete( $table, array( 'id' => $id ) ); }
    if ( $gettext_id ) { $wpdb->delete( $gettext, array( 'id' => $gettext_id ) ); }
    if ( $plural_id ) { $wpdb->delete( $originals, array( 'id' => $plural_id ) ); }
    if ( $old_job === false ) { delete_option( 'ctp_job' ); } else { update_option( 'ctp_job', $old_job, false ); }
    if ( $old_paused === false ) { delete_option( 'ctp_paused' ); } else { update_option( 'ctp_paused', $old_paused, false ); }
}
WP_CLI::success( "{$checks} read-only source inspector assertions; no HTTP calls." );
