<?php
/**
 * Removal of optimized copies by registry, confined path and SHA-256.
 *
 * @package NIBWOZ\BetterImg
 */

namespace NIBWOZ\BetterImg;

if (!defined('ABSPATH')) { exit; }

final class Cleanup {
    public static function attachment(int $attachment): void {
        global $wpdb;
        $assets=Core::table('assets');
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,dest_rel,digest FROM %i WHERE attachment_id=%d',$assets,$attachment)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the plugin's own table while an attachment is deleted.
        foreach ((array)$rows as $row) {
            if (!Storage::exists((string)$row->dest_rel) || Storage::remove((string)$row->dest_rel,(string)$row->digest)) { $wpdb->delete($assets,['id'=>(int)$row->id]); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin's own table.
        }
        $wpdb->delete(Core::table('queue'),['attachment_id'=>$attachment]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin's own queue table.
    }

    private static function lock(string $key): bool {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key)) === 1; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL named lock that excludes running workers.
    }

    public static function all(): array {
        global $wpdb;
        $prefix=Core::lockPrefix(); $lifecycle=$prefix . '_lifecycle';
        if (!self::lock($lifecycle)) { return [0,1]; }
        $locks=[];
        try {
            for ($i=0;$i<4;$i++) {
                $key=$prefix . '_slot_' . $i;
                if (!self::lock($key)) { return [0,1]; }
                $locks[]=$key;
            }
            $queue=Core::table('queue');
            if ((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE state='processing'",$queue)) > 0 || $wpdb->last_error) { return [0,1]; } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live queue state; caching would be wrong.
            $removed=0; $unresolved=0; $seen=[];
            if (!self::purge(Core::table('assets'),$seen,$removed,$unresolved)) { return [0,1]; }
            $legacyFiles=Core::table('files');
            if (Core::table_exists($legacyFiles) && !self::purge($legacyFiles,$seen,$removed,$unresolved)) { ++$unresolved; }
            if (!$unresolved) {
                $wpdb->query($wpdb->prepare('DELETE FROM %i',$queue)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Empties the plugin's own queue table.
                foreach (['tasks','generations'] as $legacy) {
                    $table=Core::table($legacy);
                    if (Core::table_exists($table)) { $wpdb->query($wpdb->prepare('DELETE FROM %i',$table)); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Empties the plugin's own legacy table.
                }
                delete_option('nwzbi_active_generation'); delete_option('nwzbi_building_generation');
                self::prune(); Metrics::invalidate();
            }
            return [$removed,$unresolved];
        } finally {
            foreach ($locks as $key) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key)); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases a MySQL named lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lifecycle)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases a MySQL named lock.
        }
    }

    private static function purge(string $table,array &$seen,int &$removed,int &$unresolved): bool {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,dest_rel,digest FROM %i ORDER BY id DESC',$table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the plugin's own registry table.
        if ($wpdb->last_error) { return false; }
        foreach ((array)$rows as $row) {
            $relative=(string)$row->dest_rel;
            if (isset($seen[$relative])) { $wpdb->delete($table,['id'=>(int)$row->id]); continue; } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin's own table.
            $seen[$relative]=true;
            if (!Storage::validDerivativeRelative($relative) || !(string)$row->digest) { ++$unresolved; continue; }
            if (!Storage::exists($relative) || Storage::remove($relative,(string)$row->digest)) { $wpdb->delete($table,['id'=>(int)$row->id]); ++$removed; } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin's own table.
            else { ++$unresolved; }
        }
        return true;
    }

    public static function staleTemporaries(): int {
        global $wpdb;
        if (get_transient('nwzbi_temp_sweep')) { return 0; }
        set_transient('nwzbi_temp_sweep',1,DAY_IN_SECONDS);
        $base=Storage::base(); $root=$base ? realpath($base) : false;
        if (!$root) { return 0; }
        try { $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS)); }
        catch (\UnexpectedValueException) { return 0; }
        $cutoff=time()-DAY_IN_SECONDS; $candidates=[];
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink() || $item->getMTime() > $cutoff) { continue; }
            if (!preg_match('/^nwzbi-(?:tmp-|bak-|backup-)?[A-Za-z0-9]{4,12}(?:\.(?:jpe?g|png|webp|avif))?$/D',$item->getFilename())) { continue; }
            $candidates['m/' . str_replace(DIRECTORY_SEPARATOR,'/',substr($item->getPathname(),strlen($root)+1))]=$item->getPathname();
        }
        if (!$candidates) { return 0; }
        $registered=$wpdb->get_col($wpdb->prepare('SELECT dest_rel FROM %i WHERE dest_rel LIKE %s',Core::table('assets'),'%' . $wpdb->esc_like('nwzbi-') . '%')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One query for all candidates; registered copies named like temporaries are kept.
        if ($wpdb->last_error) { return 0; }
        $removed=0;
        foreach (array_diff_key($candidates,array_flip((array)$registered)) as $path) { if (Storage::delete($path)) { ++$removed; } }
        return $removed;
    }

    private static function prune(): void {
        $base=Storage::base(); $root=$base ? realpath($base) : false;
        if (!$root) { return; }
        $directories=[];
        try { $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST); }
        catch (\UnexpectedValueException) { return; }
        foreach ($iterator as $item) { if ($item->isDir() && !$item->isLink()) { $directories[]=$item->getPathname(); } }
        foreach ($directories as $directory) { Storage::fs()->rmdir($directory); }
    }
}
