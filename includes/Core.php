<?php
/**
 * Settings, schema, migrations and lifecycle.
 *
 * @package NIBWOZ\BetterImg
 */

namespace NIBWOZ\BetterImg;

if (!defined('ABSPATH')) { exit; }

final class Core {
    public static function defaults(): array {
        return [
            'format'=>'webp','engine'=>'auto','quality_webp'=>85,'quality_avif'=>75,
            'quality_original'=>90,'max_width'=>2048,'max_height'=>0,'automatic'=>1,
            'thumbnails'=>1,'concurrency'=>1,'larger'=>'keep','delivery_enabled'=>0,'cdn_hosts'=>[],'uninstall_cleanup'=>0,
        ];
    }

    public static function settings(): array {
        $saved=(array)get_option('nwzbi_settings',[]);
        if (!array_key_exists('max_width',$saved) && isset($saved['max_edge'])) { $saved['max_width']=(int)$saved['max_edge']; }
        if (!array_key_exists('max_height',$saved) && isset($saved['max_edge'])) { $saved['max_height']=(int)$saved['max_edge']; }
        unset($saved['max_edge'],$saved['batch']);
        return array_replace(self::defaults(),$saved);
    }

    public static function sanitizeHosts($value): array {
        $items=is_array($value) ? $value : preg_split('/[\s,;]+/',(string)$value);
        $hosts=[];
        foreach ((array)$items as $item) {
            $item=strtolower(trim((string)$item));
            if ($item === '') { continue; }
            if (str_contains($item,'://')) { $item=(string)wp_parse_url($item,PHP_URL_HOST) . (wp_parse_url($item,PHP_URL_PORT) ? ':' . wp_parse_url($item,PHP_URL_PORT) : ''); }
            $item=rtrim($item,'/');
            if (preg_match('/^(?=.{1,253}(?::|$))(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}(?::[0-9]{1,5})?$/D',$item)) { $hosts[$item]=true; }
            if (count($hosts) >= 10) { break; }
        }
        return array_keys($hosts);
    }

    public static function lockPrefix(): string {
        global $wpdb;
        return 'nwzbi_' . substr(md5(DB_NAME . $wpdb->prefix),0,12);
    }

    public static function table(string $suffix): string {
        global $wpdb;
        return $wpdb->prefix . 'nwzbi_' . $suffix;
    }

    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate=$wpdb->get_charset_collate();
        dbDelta('CREATE TABLE ' . self::table('assets') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            source_rel varchar(600) NOT NULL,
            source_hash char(64) NOT NULL,
            dest_rel varchar(600) NOT NULL,
            format varchar(12) NOT NULL,
            source_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
            dest_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
            width int(10) unsigned NOT NULL DEFAULT 0,
            height int(10) unsigned NOT NULL DEFAULT 0,
            digest char(64) NOT NULL DEFAULT '',
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_hash (source_hash),
            KEY source (source_rel(190)),
            KEY attachment (attachment_id),
            KEY format (format)
        ) $collate;");
        dbDelta('CREATE TABLE ' . self::table('queue') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            state varchar(20) NOT NULL DEFAULT 'pending',
            attempts tinyint unsigned NOT NULL DEFAULT 0,
            lease_until datetime DEFAULT NULL,
            error text DEFAULT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY attachment (attachment_id),
            KEY state_lease (state,lease_until)
        ) $collate;");
        dbDelta('CREATE TABLE ' . self::table('workers') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(36) NOT NULL,
            worker_id tinyint unsigned NOT NULL,
            expected_workers tinyint unsigned NOT NULL DEFAULT 1,
            started_us bigint(20) unsigned NOT NULL DEFAULT 0,
            ended_us bigint(20) unsigned NOT NULL DEFAULT 0,
            tasks int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY run_worker (run_id,worker_id),
            KEY started_us (started_us)
        ) $collate;");
        dbDelta('CREATE TABLE ' . self::table('attempts') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(36) NOT NULL,
            worker_id tinyint unsigned NOT NULL,
            task_id bigint(20) unsigned NOT NULL,
            attachment_id bigint(20) unsigned NOT NULL,
            started_us bigint(20) unsigned NOT NULL DEFAULT 0,
            ended_us bigint(20) unsigned NOT NULL DEFAULT 0,
            result varchar(20) NOT NULL DEFAULT 'processing',
            PRIMARY KEY  (id),
            KEY run_id (run_id),
            KEY task_id (task_id),
            KEY started_us (started_us)
        ) $collate;");
        foreach (['assets','queue','workers','attempts'] as $required) { if (!self::table_exists(self::table($required))) { return; } }
        if (!self::table_has_column(self::table('assets'),'source_hash')) { return; }
        if (!get_option('nwzbi_settings')) { add_option('nwzbi_settings',self::defaults()); }
        self::migrate_active_library();
        update_option('nwzbi_schema',5,false);
    }

    /** Copies the active generation of the pre-1.0 storage model into the current registry, once. */
    private static function migrate_active_library(): void {
        global $wpdb;
        $assets=self::table('assets'); $oldFiles=self::table('files'); $oldGenerations=self::table('generations');
        if (!self::table_exists($oldFiles) || (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$assets)) > 0) { return; } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration that reads the plugin's own custom table.
        $active=(int)get_option('nwzbi_active_generation',0);
        if ($active < 1) { return; }
        $format='';
        if (self::table_exists($oldGenerations)) {
            $json=$wpdb->get_var($wpdb->prepare('SELECT config FROM %i WHERE id=%d',$oldGenerations,$active)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration that reads the plugin's own legacy table.
            $config=is_string($json) ? json_decode($json,true) : [];
            $format=is_array($config) ? sanitize_key((string)($config['format']??'')) : '';
        }
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE generation_id=%d ORDER BY id ASC',$oldFiles,$active),ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration that reads the plugin's own legacy table.
        if ($wpdb->last_error || !is_array($rows)) { return; }
        foreach ($rows as $row) {
            $rowFormat=$format ?: strtolower((string)pathinfo((string)$row['dest_rel'],PATHINFO_EXTENSION));
            if (in_array($rowFormat,['jpg','jpeg','png'],true)) { $rowFormat='original'; }
            if (!in_array($rowFormat,['webp','avif','original'],true)) { continue; }
            $wpdb->replace($assets,[ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin's own custom table.
                'attachment_id'=>(int)$row['attachment_id'],'source_rel'=>(string)$row['source_rel'],
                'source_hash'=>hash('sha256',(string)$row['source_rel']),
                'dest_rel'=>(string)$row['dest_rel'],'format'=>$rowFormat,
                'source_bytes'=>(int)$row['source_bytes'],'dest_bytes'=>(int)$row['dest_bytes'],
                'width'=>(int)($row['width']??0),'height'=>(int)($row['height']??0),
                'digest'=>(string)($row['digest']??''),'updated_at'=>current_time('mysql',true),
            ]);
        }
    }

    public static function table_exists(string $table): bool {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table))) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for the plugin's own tables; must not be cached.
    }

    private static function table_has_column(string $table,string $column): bool {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s',$table,$column)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for the plugin's own table; must not be cached.
    }

    public static function boot(): void {
        load_plugin_textdomain('nibwoz-better-img',false,dirname(plugin_basename(FILE)) . '/languages'); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Loads the pt_BR translation bundled in /languages, which language packs do not provide.
        if (get_option('nwzbi_version') !== VERSION && !self::upgrade()) {
            add_action('admin_notices',static function (): void {
                global $pagenow;
                $screen=$pagenow === 'plugins.php' || ($pagenow === 'tools.php' && isset($_GET['page']) && 'nwzbi' === $_GET['page']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of the current admin screen.
                if ($screen && current_user_can('manage_options')) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('NIBWOZ Better Img could not create or update its database tables. Check the database user permissions.','nibwoz-better-img') . '</p></div>';
                }
            });
            return;
        }
        Admin::boot(); Queue::boot(); Delivery::boot();
        add_action('add_attachment',static function ($id): void {
            if (Processor::eligibleType((int)$id)) { set_transient('nwzbi_new_upload_' . (int)$id,1,10*MINUTE_IN_SECONDS); }
        });
        add_filter('wp_generate_attachment_metadata',static function ($metadata,$id) {
            Metrics::invalidate();
            $uploadKey='nwzbi_new_upload_' . (int)$id;
            if (get_transient($uploadKey)) {
                delete_transient($uploadKey);
                if (self::settings()['automatic'] && Processor::eligible((int)$id) && Queue::enqueue((int)$id,true)) {
                    add_action('shutdown',static function (): void { Queue::dispatch(); },PHP_INT_MAX);
                }
            }
            return $metadata;
        },20,2);
        add_action('delete_attachment',static function ($id): void {
            Cleanup::attachment((int)$id);
            Metrics::invalidate();
        });
    }

    private static function upgrade(): bool {
        if ((int)get_option('nwzbi_schema',0) < 5) {
            self::activate();
            if ((int)get_option('nwzbi_schema',0) < 5) { return false; }
        }
        self::migrateSettingsDefaults();
        self::migrateSimultaneousImages();
        self::migrateEngineCompatibility();
        self::migrateQueuePolicy();
        wp_set_option_autoload_values(['nwzbi_settings'=>true,'nwzbi_schema'=>false,'nwzbi_defaults_version'=>false,'nwzbi_parallel_version'=>false,'nwzbi_engine_capability_version'=>false,'nwzbi_queue_policy_version'=>false]);
        update_option('nwzbi_version',VERSION,true);
        return true;
    }

    private static function migrateSettingsDefaults(): void {
        if ((int)get_option('nwzbi_defaults_version',0) >= 311) { return; }
        $saved=get_option('nwzbi_settings',[]);
        if (!is_array($saved)) { $saved=[]; }
        if (!array_key_exists('max_width',$saved) || (int)$saved['max_width']===0) { $saved['max_width']=2048; }
        if (!array_key_exists('max_height',$saved)) { $saved['max_height']=0; }
        if (!array_key_exists('quality_original',$saved) || (int)$saved['quality_original']===82) { $saved['quality_original']=90; }
        if (!array_key_exists('larger',$saved) || $saved['larger']==='skip') { $saved['larger']='keep'; }
        $saved['automatic']=1;
        $saved['uninstall_cleanup']=empty($saved['uninstall_cleanup']) ? 0 : 1;
        update_option('nwzbi_settings',array_replace(self::defaults(),$saved));
        update_option('nwzbi_defaults_version',311,false);
    }

    private static function migrateSimultaneousImages(): void {
        if ((int)get_option('nwzbi_parallel_version',0) >= 1113) { return; }
        $saved=get_option('nwzbi_settings',[]);
        if (is_array($saved)) {
            unset($saved['batch']);
            $saved['concurrency']=max(1,min(4,(int)($saved['concurrency']??1)));
            update_option('nwzbi_settings',array_replace(self::defaults(),$saved));
        }
        update_option('nwzbi_parallel_version',1113,false);
    }

    private static function migrateEngineCompatibility(): void {
        if ((int)get_option('nwzbi_engine_capability_version',0) >= 104) { return; }
        $settings=self::settings();
        $format=(string)$settings['format']; $engine=(string)$settings['engine'];
        if (Diagnostics::supports($format,$engine)) { update_option('nwzbi_engine_capability_version',104,false); return; }
        if (Diagnostics::supports($format,'auto')) { $settings['engine']='auto'; }
        elseif (Diagnostics::supports('webp','auto')) { $settings['format']='webp'; $settings['engine']='auto'; }
        else { update_option('nwzbi_engine_capability_version',104,false); return; }
        update_option('nwzbi_settings',$settings);
        update_option('nwzbi_engine_capability_version',104,false);
    }

    private static function migrateQueuePolicy(): void {
        if ((int)get_option('nwzbi_queue_policy_version',0) >= 111) { return; }
        self::haltQueue(__('Previous queue stopped during a security update.','nibwoz-better-img'));
        update_option('nwzbi_queue_policy_version',111,false);
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('nwzbi_tick');
        self::haltQueue(__('Processing stopped because the plugin was deactivated.','nibwoz-better-img'));
        delete_option('nwzbi_version');
    }

    private static function haltQueue(string $reason): void {
        global $wpdb;
        $queue=self::table('queue');
        if (!self::table_exists($queue)) { return; }
        Queue::halt();
        $wpdb->query($wpdb->prepare("UPDATE %i SET state='failed',lease_until=NULL,error=%s,updated_at=UTC_TIMESTAMP() WHERE state='processing'",$queue,$reason)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Updates the plugin's own queue table.
    }
}
