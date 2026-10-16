<?php
/**
 * Plugin Name: Claude Translator for TranslatePress
 * Description: Direct Anthropic Claude translation engine and resumable whole-site translation for TranslatePress.
 * Version: 0.3.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: translatepress-multilingual
 * License: GPL-2.0-or-later
 * Text Domain: claude-translator-for-translatepress
 */
defined( 'ABSPATH' ) || exit;
define( 'CTP_VERSION', '0.3.0' );
define( 'CTP_FILE', __FILE__ );
define( 'CTP_DIR', __DIR__ . '/' );
require_once CTP_DIR . 'includes/class-support.php';
require_once CTP_DIR . 'includes/class-codec.php';
require_once CTP_DIR . 'includes/class-client.php';
require_once CTP_DIR . 'includes/class-bulk.php';
require_once CTP_DIR . 'includes/class-reset.php';
require_once CTP_DIR . 'includes/class-admin.php';

// Register before TranslatePress initializes its selected engine on plugins_loaded.
add_filter( 'trp_machine_translation_engines', static function ( $engines ) {
    $engines[] = array( 'value' => 'ctp_claude', 'label' => 'Anthropic Claude (direct API)' );
    return $engines;
} );
add_filter( 'trp_automatic_translation_engines_classes', static function ( $classes ) {
    if ( class_exists( 'TRP_Machine_Translator' ) ) {
        require_once CTP_DIR . 'includes/class-engine.php';
        $classes['ctp_claude'] = 'CTP_Engine';
    }
    return $classes;
} );
CTP_Bulk::register();
CTP_Admin::register();
add_action( 'plugins_loaded', static function () {
    if ( get_option( 'ctp_version' ) === CTP_VERSION ) { return; }
    $config = get_option( 'ctp_settings', array() );
    // Upgrade the old default; retain any other explicitly configured model and all secrets/settings.
    if ( ( $config['model'] ?? '' ) === 'claude-sonnet-4-6' ) {
        $config['model'] = 'claude-sonnet-5-5';
        update_option( 'ctp_settings', $config, false );
    }
    update_option( 'ctp_version', CTP_VERSION, false );
}, -1001 );
// If an editor reviewed a string while Claude was working, leave that native TRP save alone.
add_filter( 'trp_allow_string_saving', static function ( $allow, $new, $updates ) {
    if ( ! $allow || ! CTP_Support::$write_language || ! $updates ) { return $allow; }
    if ( CTP_Support::$write_epoch !== null && CTP_Support::$write_epoch !== CTP_Support::reset_epoch() ) { return false; }
    $originals = array_intersect( array_column( $updates, 'original' ), CTP_Support::$write_originals );
    if ( ! $originals ) { return $allow; }
    $query = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
    $current = $query->get_existing_translations( $originals, CTP_Support::$write_language );
    if ( $current === false ) { return false; }
    foreach ( $current as $row ) {
        if ( (int) $row->status === $query->get_constant_human_reviewed() ) { return false; }
    }
    return $allow;
}, 20, 3 );
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once CTP_DIR . 'includes/class-cli.php';
    WP_CLI::add_command( 'ctp', 'CTP_CLI' );
}
register_deactivation_hook( __FILE__, static function () {
    wp_clear_scheduled_hook( 'ctp_tick' );
    update_option( 'ctp_paused', 1, false );
} );
