<?php
/**
 * Optional full cleanup, only when "Delete all data on uninstall" is on.
 *
 * @package NIBWOZ\BetterImg
 */

if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

require_once __DIR__ . '/includes/Core.php';
require_once __DIR__ . '/includes/Diagnostics.php';
require_once __DIR__ . '/includes/Storage.php';
require_once __DIR__ . '/includes/Processor.php';
require_once __DIR__ . '/includes/Metrics.php';
require_once __DIR__ . '/includes/Cleanup.php';

$nwzbi_uninstall_site=static function (): void {
    global $wpdb;
    foreach (['nwzbi_tick','nwzbi_continue','nwzbi_resume'] as $hook) { wp_clear_scheduled_hook($hook); }
    delete_transient('nwzbi_dispatch_lease');
    delete_transient('nwzbi_stop_requested');
    delete_transient('nwzbi_temp_sweep');
    $settings=get_option('nwzbi_settings',[]);
    if (!is_array($settings) || empty($settings['uninstall_cleanup'])) { return; }
    [, $unresolved]=\NIBWOZ\BetterImg\Cleanup::all();
    if ($unresolved) { return; }
    foreach (['assets','queue','workers','attempts','files','tasks','generations'] as $suffix) {
        $table=\NIBWOZ\BetterImg\Core::table($suffix);
        if (\NIBWOZ\BetterImg\Core::table_exists($table)) { $wpdb->query($wpdb->prepare('DROP TABLE %i',$table)); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removes the plugin's own tables on uninstall when the user enabled full cleanup.
    }
    foreach (['nwzbi_settings','nwzbi_version','nwzbi_schema','nwzbi_defaults_version','nwzbi_engine_capability_version','nwzbi_queue_policy_version','nwzbi_parallel_version','nwzbi_worker_route','nwzbi_last_dispatch','nwzbi_session','nwzbi_active_generation','nwzbi_building_generation'] as $option) { delete_option($option); }
    delete_transient('nwzbi_inventory_v3');
    $uploads=wp_get_upload_dir();
    if (empty($uploads['error']) && !empty($uploads['basedir'])) {
        $base=trailingslashit($uploads['basedir']) . 'nwz-better-img';
        if (is_dir($base) && !is_link($base)) {
            $index=$base . '/index.php';
            if (is_file($index) && !is_link($index) && file_get_contents($index) === '<?php // Silence is golden.') { wp_delete_file($index); }
            \NIBWOZ\BetterImg\Storage::fs()->rmdir($base);
        }
    }
};

if (is_multisite()) {
    foreach (get_sites(['fields'=>'ids','number'=>0]) as $nwzbi_site_id) {
        switch_to_blog((int)$nwzbi_site_id);
        $nwzbi_uninstall_site();
        restore_current_blog();
    }
} else { $nwzbi_uninstall_site(); }
