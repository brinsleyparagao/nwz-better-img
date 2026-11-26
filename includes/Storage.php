<?php
/**
 * Confined paths, collision-safe names and URLs of the optimized copies.
 *
 * @package NIBWOZ\BetterImg
 */

namespace NIBWOZ\BetterImg;

if (!defined('ABSPATH')) { exit; }

final class Storage {
    public static function uploads(): array { return wp_get_upload_dir(); }

    /** Direct filesystem access for files inside uploads/nwz-better-img, also usable in background workers without credentials. */
    public static function fs(): \WP_Filesystem_Direct {
        static $fs=null;
        if ($fs === null) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            $fs=new \WP_Filesystem_Direct(null);
        }
        return $fs;
    }

    /** Deletes a file and reports whether it is gone (wp_delete_file() returns nothing before WordPress 6.6). */
    public static function delete(string $path): bool {
        wp_delete_file($path);
        return !file_exists($path);
    }

    public static function base(): string {
        $uploads=self::uploads();
        if (!empty($uploads['error']) || empty($uploads['basedir']) || !is_dir($uploads['basedir'])) { return ''; }
        $base=trailingslashit($uploads['basedir']) . 'nwz-better-img';
        if ((file_exists($base) && (is_link($base) || !is_dir($base))) || (!file_exists($base) && !wp_mkdir_p($base))) { return ''; }
        if (!wp_is_writable($base)) { return ''; }
        if (!file_exists($base . '/index.php')) { file_put_contents($base . '/index.php','<?php // Silence is golden.'); }
        $legacy="Options -Indexes\n<FilesMatch \"^(?!.*\\.(?:jpg|jpeg|png|webp|avif)$).*$\">\nRequire all denied\n</FilesMatch>\n";
        $htaccess=$base . '/.htaccess';
        if (is_file($htaccess) && !is_link($htaccess) && file_get_contents($htaccess) === $legacy) { self::delete($htaccess); }
        return $base;
    }

    private static function root(): string {
        static $root=null;
        if ($root === null) { $uploads=self::uploads(); $root=!empty($uploads['basedir']) ? (string)realpath($uploads['basedir']) : ''; }
        return $root;
    }

    public static function relative(string $absolute): string {
        $root=self::root();
        $path=realpath($absolute);
        if (!$root || !$path || !str_starts_with($path,$root . DIRECTORY_SEPARATOR) || is_link($absolute)) { return ''; }
        $relative=str_replace(DIRECTORY_SEPARATOR,'/',substr($path,strlen($root)+1));
        return str_starts_with($relative,'nwz-better-img/') ? '' : $relative;
    }

    public static function destination(int $attachment,string $source,string $format): array {
        global $wpdb;
        $sourceRel=self::relative($source);
        $base=self::base();
        if ($attachment < 1 || !$sourceRel || !$base || !preg_match('/\.(jpe?g|png|webp|avif)$/i',$sourceRel)) { return []; }
        $directory=dirname($sourceRel) === '.' ? '' : dirname($sourceRel) . '/';
        $sourceExtension=strtolower(pathinfo($sourceRel,PATHINFO_EXTENSION));
        $extension=$format === 'original' ? $sourceExtension : $format;
        $stem=pathinfo($sourceRel,PATHINFO_FILENAME);
        $targetDir=trailingslashit($base) . rtrim($directory,'/');
        if (!is_dir($targetDir) && !wp_mkdir_p($targetDir)) { return []; }
        $realBase=realpath($base); $realDir=realpath($targetDir);
        if (!$realBase || !$realDir || ($realDir !== $realBase && !str_starts_with($realDir,$realBase . DIRECTORY_SEPARATOR)) || is_link($targetDir) || !wp_is_writable($targetDir)) { return []; }
        $assets=Core::table('assets');
        foreach ([$stem,$stem . '-' . $sourceExtension,$stem . '-' . substr(hash('sha256',$sourceRel),0,12)] as $candidate) {
            $name=$candidate . '.' . $extension; $destRel='m/' . $directory . $name; $path=$targetDir . '/' . $name;
            $conflict=$wpdb->get_var($wpdb->prepare('SELECT source_rel FROM %i WHERE dest_rel=%s AND source_rel<>%s LIMIT 1',$assets,$destRel,$sourceRel)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Collision check in the plugin's own table; must read the current state.
            if ($wpdb->last_error) { return []; }
            if ($conflict) { continue; }
            if (file_exists($path)) {
                $owned=$wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE dest_rel=%s AND source_rel=%s',$assets,$destRel,$sourceRel)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Ownership check in the plugin's own table; must read the current state.
                if ($wpdb->last_error) { return []; }
                if (!$owned) { continue; }
            }
            return is_link($path) ? [] : [$path,$destRel,$sourceRel];
        }
        return [];
    }

    public static function supported(string $format): bool {
        return Diagnostics::supports($format,(string)Core::settings()['engine']);
    }

    private static function tail(string $destRel): string {
        if (str_starts_with($destRel,'m/')) { return 'nwz-better-img/' . substr($destRel,2); }
        return str_starts_with($destRel,'u/') ? substr($destRel,2) : 'nwz-better-img/' . $destRel;
    }

    public static function url(string $destRel): string {
        return trailingslashit(self::uploads()['baseurl']) . str_replace('%2F','/',rawurlencode(self::tail($destRel)));
    }

    public static function absolute(string $destRel): string {
        if (!self::validDerivativeRelative($destRel)) { return ''; }
        $uploads=self::uploads();
        if (!empty($uploads['error']) || empty($uploads['basedir']) || !is_dir($uploads['basedir'])) { return ''; }
        return trailingslashit($uploads['basedir']) . self::tail($destRel);
    }

    public static function validDerivativeRelative(string $relative): bool {
        if (preg_match('~^m/(.+)$~Ds',$relative,$match)) { $tail=$match[1]; }
        elseif (preg_match('~^u/((?:.+/)?nwzbi-[1-9][0-9]*-[^/]+--v[1-9][0-9]*\.(?:jpe?g|png|webp|avif))$~Di',$relative,$match)) { $tail=$match[1]; }
        elseif (preg_match('~^g[1-9][0-9]*/a[1-9][0-9]*/(.+)$~Ds',$relative,$match)) { $tail=$match[1]; }
        else { return false; }
        if (!preg_match('/\.(?:jpe?g|png|webp|avif)$/iD',$tail)) { return false; }
        foreach (explode('/',$tail) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_contains($part,'\\') || preg_match('/[\x00-\x1F]/',$part)) { return false; }
        }
        return !str_starts_with($tail,'nwz-better-img/');
    }

    public static function exists(string $destRel): bool {
        $path=self::absolute($destRel);
        if (!$path) { return false; }
        $root=self::root(); $actual=realpath($path);
        return $root && $actual && str_starts_with($actual,$root . DIRECTORY_SEPARATOR) && is_file($path) && !is_link($path);
    }

    public static function remove(string $destRel,string $digest=''): bool {
        $path=self::absolute($destRel);
        if (!$path || is_link($path)) { return false; }
        if (!file_exists($path)) { return true; }
        $root=self::root(); $actual=realpath($path);
        if (!$root || !$actual || !str_starts_with($actual,$root . DIRECTORY_SEPARATOR) || !is_file($path)) { return false; }
        if ($digest !== '') { $actualDigest=hash_file('sha256',$path); if (!$actualDigest || !hash_equals($digest,$actualDigest)) { return false; } }
        return self::delete($path);
    }
}
