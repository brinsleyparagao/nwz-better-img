<?php
/**
 * Cached library inventory and dashboard totals.
 *
 * @package NIBWOZ\BetterImg
 */

namespace NIBWOZ\BetterImg;

if (!defined('ABSPATH')) { exit; }

final class Metrics {
    private const TRANSIENT='nwzbi_inventory_v3';

    public static function invalidate(): void { delete_transient(self::TRANSIENT); }

    public static function inventory(bool $refresh=false): array {
        $settings=Core::settings();
        $key=(int)$settings['thumbnails'];
        $cached=$refresh ? false : get_transient(self::TRANSIENT);
        if (is_array($cached) && ($cached['thumbnails']??null) === $key) { return $cached; }
        $attachments=0; $files=0; $bytes=0; $page=1;
        do {
            $ids=get_posts(['post_type'=>'attachment','post_mime_type'=>'image','post_status'=>'inherit','fields'=>'ids','posts_per_page'=>200,'paged'=>$page,'orderby'=>'ID','order'=>'ASC','no_found_rows'=>true]);
            if (!is_array($ids)) { break; }
            $attachments+=count($ids);
            update_postmeta_cache($ids);
            foreach ($ids as $id) {
                foreach (Processor::sources((int)$id,(bool)$settings['thumbnails']) as $path) {
                    ++$files; $size=filesize($path); if ($size !== false) { $bytes+=(int)$size; }
                }
            }
            ++$page;
        } while (count($ids) === 200);
        $result=['attachments'=>$attachments,'files'=>$files,'bytes'=>$bytes,'thumbnails'=>$key,'measured_at'=>time()];
        set_transient(self::TRANSIENT,$result,15*MINUTE_IN_SECONDS);
        return $result;
    }

    public static function snapshot(bool $refresh=false): array {
        global $wpdb;
        $inventory=self::inventory($refresh);
        $assets=Core::table('assets'); $format=(string)Core::settings()['format'];
        $optimized=$wpdb->get_row($wpdb->prepare('SELECT COUNT(*) files,COALESCE(SUM(source_bytes),0) source_bytes,COALESCE(SUM(dest_bytes),0) dest_bytes FROM %i',$assets),ARRAY_A) ?: []; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only totals of the plugin's own table.
        $current=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE format=%s',$assets,$format)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only totals of the plugin's own table.
        $sourceBytes=(int)($optimized['source_bytes']??0); $destBytes=(int)($optimized['dest_bytes']??0);
        $saved=$sourceBytes-$destBytes;
        return array_merge($inventory,[
            'optimized_files'=>(int)($optimized['files']??0),
            'optimized_source_bytes'=>$sourceBytes,'optimized_bytes'=>$destBytes,'current_format_files'=>$current,
            'saved_bytes'=>$saved,'saved_percent'=>$sourceBytes>0 ? round(($saved/$sourceBytes)*100,1) : 0,
            'coverage_percent'=>$inventory['files']>0 ? min(100,round(((int)($optimized['files']??0)/$inventory['files'])*100,1)) : 0,
        ]);
    }
}
