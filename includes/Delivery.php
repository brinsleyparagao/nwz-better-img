<?php
/**
 * Optional public delivery: swaps image URLs for validated optimized copies.
 *
 * @package NIBWOZ\BetterImg
 */

namespace NIBWOZ\BetterImg;

if (!defined('ABSPATH')) { exit; }

final class Delivery {
    private static array $originalFor=[];

    public static function boot(): void {
        if (empty(Core::settings()['delivery_enabled'])) { return; }
        add_filter('wp_get_attachment_image_src',[self::class,'imageSrc'],20,4);
        add_filter('wp_calculate_image_srcset',[self::class,'srcset'],20,5);
        add_filter('wp_get_attachment_image_attributes',[self::class,'attributes'],20,3);
    }

    private static function bypass(): bool {
        return is_admin() || (defined('REST_REQUEST') && REST_REQUEST);
    }

    private static function lookupData(int $attachment,string $relative): array {
        static $rows=[],$cache=[];
        global $wpdb;
        $key=$attachment . ':' . $relative;
        if (array_key_exists($key,$cache)) { return $cache[$key]; }
        if (!isset($rows[$attachment])) {
            $rows[$attachment]=[];
            $found=$wpdb->get_results($wpdb->prepare('SELECT source_rel,dest_rel,digest,updated_at FROM %i WHERE attachment_id=%d',Core::table('assets'),$attachment)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One query per displayed attachment, memoized for the request.
            foreach ((array)$found as $row) { $rows[$attachment][(string)$row->source_rel]=$row; }
        }
        $row=$rows[$attachment][$relative]??null; $cache[$key]=[];
        if ($row && Storage::exists((string)$row->dest_rel)) {
            $url=Storage::url((string)$row->dest_rel);
            if (preg_match('/^[a-f0-9]{64}$/D',(string)$row->digest)) { $url=add_query_arg('ver',substr(hash('sha256',$row->digest . '|' . (string)$row->updated_at),0,10),$url); }
            $cache[$key]=['url'=>$url];
        }
        return $cache[$key];
    }

    private static function relatives(int $attachment): array {
        static $cache=[];
        if (isset($cache[$attachment])) { return $cache[$attachment]; }
        $main=get_attached_file($attachment); $relatives=[];
        if (is_string($main) && $main !== '' && Storage::relative($main) !== '') {
            $paths=[$main]; $metadata=wp_get_attachment_metadata($attachment);
            foreach (($metadata['sizes']??[]) as $image) {
                if (is_array($image) && !empty($image['file']) && basename($image['file']) === $image['file']) { $paths[]=dirname($main) . '/' . $image['file']; }
            }
            foreach (array_unique($paths) as $path) { $relative=Storage::relative($path); if ($relative !== '') { $relatives[$relative]=true; } }
        }
        return $cache[$attachment]=$relatives;
    }

    private static function cdnHost(array $parts): string {
        static $hosts=null;
        $hosts??=array_flip((array)Core::settings()['cdn_hosts']);
        $host=strtolower((string)($parts['host']??'')) . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
        return isset($hosts[$host]) ? $host : '';
    }

    private static function relativeForUrl(int $attachment,string $url): string {
        if (!$url || str_contains($url,'\\') || preg_match('~%(?:2f|5c|00)~i',$url)) { return ''; }
        $parts=wp_parse_url($url); $base=wp_parse_url(Storage::uploads()['baseurl']);
        if (!is_array($parts) || !is_array($base) || empty($parts['host']) || !empty($parts['query']) || !empty($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) { return ''; }
        if (!in_array(strtolower($parts['scheme']??''),['http','https'],true)) { return ''; }
        $sameHost=strcasecmp($parts['host'],$base['host']??'') === 0 && ($parts['port']??null) === ($base['port']??null);
        if (!$sameHost && self::cdnHost($parts) === '') { return ''; }
        $path=rawurldecode($parts['path']??''); $prefix=rtrim(rawurldecode($base['path']??''),'/') . '/';
        if (!str_starts_with($path,$prefix) || str_contains($path,'\\') || str_contains($path,"\0")) { return ''; }
        $relative=substr($path,strlen($prefix));
        foreach (explode('/',$relative) as $part) { if ($part === '' || $part === '.' || $part === '..') { return ''; } }
        return isset(self::relatives($attachment)[$relative]) ? $relative : '';
    }

    private static function mappedData(int $attachment,string $url): array {
        $relative=self::relativeForUrl($attachment,$url);
        $data=$relative !== '' ? self::lookupData($attachment,$relative) : [];
        if (!$data) { return []; }
        $parts=wp_parse_url($url);
        if (is_array($parts) && self::cdnHost($parts) !== '') {
            $derivative=wp_parse_url($data['url']);
            $data['url']=strtolower((string)$parts['scheme']) . '://' . self::cdnHost($parts) . ($derivative['path']??'') . (isset($derivative['query']) ? '?' . $derivative['query'] : '');
        }
        return $data;
    }

    private static function reportFailure(\Throwable $error): void {
        static $reported=false;
        if ($reported) { return; }
        $reported=true;
        wp_trigger_error(__METHOD__,esc_html('Optimized delivery skipped safely: ' . substr($error->getMessage(),0,500)),E_USER_WARNING);
    }

    public static function imageSrc($image,$attachment,$size,$icon) {
        if (self::bypass() || !empty($icon) || !is_array($image) || empty($image[0]) || !is_string($image[0]) || !is_numeric($attachment)) { return $image; }
        try {
            $data=self::mappedData(absint($attachment),$image[0]);
            if ($data) { self::$originalFor[$data['url']]=$image[0]; $image[0]=$data['url']; }
        } catch (\Throwable $error) {
            self::reportFailure($error);
        }
        return $image;
    }

    public static function attributes($attr,$attachment,$size) {
        if (self::bypass() || !is_array($attr) || !empty($attr['srcset']) || empty($attr['src']) || !is_string($attr['src']) || !($attachment instanceof \WP_Post)) { return $attr; }
        $original=self::$originalFor[$attr['src']]??'';
        if ($original === '') { return $attr; }
        try {
            $meta=wp_get_attachment_metadata($attachment->ID);
            $image=wp_get_attachment_image_src($attachment->ID,$size);
            if (!is_array($meta) || !is_array($image) || empty($image[1]) || empty($image[2])) { return $attr; }
            $sizeArray=[absint($image[1]),absint($image[2])];
            $srcset=wp_calculate_image_srcset($sizeArray,$original,$meta,$attachment->ID);
            if (!$srcset) { return $attr; }
            $attr['srcset']=$srcset;
            if (empty($attr['sizes'])) {
                $sizes=wp_calculate_image_sizes($sizeArray,$original,$meta,$attachment->ID);
                if ($sizes) {
                    if (($attr['loading']??'') === 'lazy' && function_exists('wp_sizes_attribute_includes_valid_auto') && !wp_sizes_attribute_includes_valid_auto($sizes)) { $sizes='auto, ' . $sizes; }
                    $attr['sizes']=$sizes;
                }
            }
        } catch (\Throwable $error) {
            self::reportFailure($error);
        }
        return $attr;
    }

    public static function srcset($sources,$sizeArray,$imageSrc,$imageMeta,$attachment) {
        if (self::bypass() || !is_array($sources) || !is_numeric($attachment)) { return $sources; }
        $original=$sources;
        try {
            foreach ($sources as &$item) {
                if (!is_array($item) || empty($item['url']) || !is_string($item['url'])) { continue; }
                $data=self::mappedData(absint($attachment),$item['url']);
                if ($data) { $item['url']=$data['url']; }
            }
            unset($item);
        } catch (\Throwable $error) {
            unset($item);
            self::reportFailure($error);
            return $original;
        }
        return $sources;
    }
}
