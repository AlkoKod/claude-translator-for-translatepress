<?php
defined( 'ABSPATH' ) || exit;

/** Compatibility adapter for the queue API shipped in TranslatePress 3.3.8. */
final class CTP_Gettext_Runner extends TRP_Gettext_Manager {
    public function run_queue( array $queue, string $language ): void {
        if ( ! method_exists( $this, 'machine_translate_gettext_queue' ) ) {
            throw new RuntimeException( 'This TRP release does not expose the gettext queue API required by bulk mode.' );
        }
        $this->machine_translate_gettext_queue( $queue, $language );
    }
}
