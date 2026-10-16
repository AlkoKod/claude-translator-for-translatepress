<?php
defined( 'ABSPATH' ) || exit;

final class CTP_CLI {
    /** Show saved job progress. */
    public function status(): void { WP_CLI::line( wp_json_encode( CTP_Bulk::status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); }
    /** Start a job. ## OPTIONS
     * --languages=<locales>
     * : Comma-separated configured TRP target locales.
     * [--urls-file=<path>]
     * : Optional file with extra public same-origin URLs, one per line.
     */
    public function start( $args, $assoc ): void {
        try {
            $extra = '';
            if ( isset( $assoc['urls-file'] ) ) {
                $extra = file_get_contents( $assoc['urls-file'] );
                if ( $extra === false ) { throw new RuntimeException( 'Cannot read the URL file.' ); }
            }
            CTP_Bulk::create( explode( ',', $assoc['languages'] ?? '' ), $extra );
            WP_CLI::success( 'Job created. Run wp ctp run to process it without visitor traffic.' );
        } catch ( Throwable $e ) { WP_CLI::error( $e->getMessage() ); }
    }
    /** Run an existing job synchronously. ## OPTIONS
     * [--max-steps=<count>]
     * : Maximum batches in this invocation. Default 10000.
     */
    public function run( $args, $assoc ): void {
        $max = max( 1, min( 100000, (int) ( $assoc['max-steps'] ?? 10000 ) ) );
        for ( $i = 0; $i < $max; ++$i ) {
            // TRP's budget is request-wide; the CLI intentionally processes multiple worker steps.
            unset( $GLOBALS['trp_machine_translation_deadline'] );
            $status = CTP_Bulk::tick();
            WP_CLI::line( $status['phase'] . ': ' . ( $status['translated'] ?? 0 ) . ' strings processed' );
            if ( ! empty( $status['error'] ) ) { WP_CLI::error( $status['error'] ); }
            if ( $status['phase'] === 'done' ) { WP_CLI::success( 'Run finished.' ); return; }
            if ( $status['paused'] ) { WP_CLI::warning( 'Job is paused or no job exists.' ); return; }
        }
        WP_CLI::warning( 'Step limit reached. Progress is saved; run this command again.' );
    }
    public function pause(): void { CTP_Bulk::control( 'pause' ); WP_CLI::success( 'Paused.' ); }
    public function resume(): void { CTP_Bulk::control( 'resume' ); WP_CLI::success( 'Resumed.' ); }
    public function cancel(): void { CTP_Bulk::control( 'cancel' ); WP_CLI::success( 'Cancelled; translations retained.' ); }
}
