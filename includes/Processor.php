<?php
/**
 * Converts the files of one attachment and publishes each copy atomically.
 *
 * @package NIBWOZ\BetterImg
 */

namespace NIBWOZ\BetterImg;

if (!defined('ABSPATH')) { exit; }

final class Processor {
    public const SOURCE_MIMES=['image/jpeg','image/png','image/webp','image/avif'];

    public static function sourceMime(string $path): string {
        if (!is_file($path) || is_link($path)) { return ''; }
        $mime=wp_get_image_mime($path);
        if (is_string($mime) && in_array($mime,self::SOURCE_MIMES,true)) { return $mime; }
        $dimensions=@getimagesize($path);
        $mime=is_array($dimensions) && isset($dimensions['mime']) ? (string)$dimensions['mime'] : '';
        return in_array($mime,self::SOURCE_MIMES,true) ? $mime : '';
    }

    public static function eligible(int $attachment): bool {
        if ($attachment < 1 || get_post_type($attachment) !== 'attachment') { return false; }
        $path=get_attached_file($attachment);
        return is_string($path) && Storage::relative($path) !== '' && self::sourceMime($path) !== '';
    }

    public static function eligibleType(int $attachment): bool {
        return $attachment > 0 && get_post_type($attachment) === 'attachment'
            && in_array((string)get_post_mime_type($attachment),self::SOURCE_MIMES,true);
    }

    public static function sources(int $attachment,bool $thumbnails): array {
        $main=get_attached_file($attachment);
        if (!is_string($main) || !Storage::relative($main)) { return []; }
        $paths=[$main];
        if ($thumbnails) {
            $metadata=wp_get_attachment_metadata($attachment);
            foreach (($metadata['sizes']??[]) as $size) {
                if (!is_array($size) || empty($size['file']) || basename($size['file']) !== $size['file']) { continue; }
                $paths[]=dirname($main) . '/' . $size['file'];
            }
            if (!empty($metadata['original_image']) && basename($metadata['original_image']) === $metadata['original_image']) {
                $paths[]=dirname($main) . '/' . $metadata['original_image'];
            }
        }
        return array_values(array_unique(array_filter($paths,static function ($path) {
            if (!is_string($path) || !is_file($path) || !Storage::relative($path)) { return false; }
            return self::sourceMime($path) !== '';
        })));
    }

    public static function run(int $attachment): void {
        $settings=Core::settings();
        if (!Storage::supported((string)$settings['format'])) { throw new \RuntimeException(esc_html__('The selected format or engine is not available on this server.','nibwoz-better-img')); }
        $sources=self::sources($attachment,(bool)$settings['thumbnails']);
        if (!$sources) { throw new \RuntimeException(esc_html__('The attachment has no eligible local files.','nibwoz-better-img')); }
        foreach ($sources as $source) { self::convert($attachment,$source,$settings); }
        Metrics::invalidate();
    }

    private static function editor(string $source,string $sourceMime,string $targetMime,array $settings) {
        $engine=(string)$settings['engine'];
        $classes=['gd'=>'WP_Image_Editor_GD','imagick'=>'WP_Image_Editor_Imagick'];
        if ($engine === 'auto') {
            $indexedPng=$sourceMime === 'image/png' && $targetMime === 'image/webp' && self::isIndexedPng($source);
            $order=$indexedPng || $targetMime === 'image/avif' ? ['gd','imagick'] : ['imagick','gd'];
            $engines=Diagnostics::engines();
            $targetLabel=match ($targetMime) {
                'image/jpeg'=>'JPEG','image/png'=>'PNG','image/webp'=>'WebP','image/avif'=>'AVIF',default=>'',
            };
            $engine='';
            foreach ($order as $candidate) {
                $class=$classes[$candidate];
                if (!empty($engines[$candidate]['available']) && in_array($targetLabel,(array)$engines[$candidate]['formats'],true)
                    && $class::supports_mime_type($sourceMime) && $class::supports_mime_type($targetMime)) {
                    $engine=$candidate;
                    break;
                }
            }
        }
        $class=$classes[$engine]??'';
        if ($class === '' || !class_exists($class) || !$class::test() || !$class::supports_mime_type($sourceMime) || !$class::supports_mime_type($targetMime)) {
            return new \WP_Error('nwzbi_engine',__('The selected engine cannot fully read the source format and write the output format.','nibwoz-better-img'));
        }
        $useGd=$engine === 'gd';
        if ($useGd) {
            $editor=new class($source) extends \WP_Image_Editor_GD {
                public function normalizePalette(): bool {
                    if (!is_gd_image($this->image)) { return false; }
                    $normalized=imageistruecolor($this->image) || (function_exists('imagepalettetotruecolor') && imagepalettetotruecolor($this->image));
                    if ($normalized) { imagealphablending($this->image,false); imagesavealpha($this->image,true); }
                    return $normalized;
                }
            };
        } else { $editor=new \WP_Image_Editor_Imagick($source); }
        $loaded=$editor->load();
        if (is_wp_error($loaded)) { return $loaded; }
        if ($useGd && $sourceMime === 'image/png' && in_array($targetMime,['image/webp','image/avif'],true) && !$editor->normalizePalette()) {
            return new \WP_Error('nwzbi_palette',__('The indexed PNG could not be prepared for conversion.','nibwoz-better-img'));
        }
        return $editor;
    }

    private static function isIndexedPng(string $source): bool {
        $header=@file_get_contents($source,false,null,0,26);
        return is_string($header) && strlen($header)===26 && substr($header,1,3)==='PNG' && ord($header[25])===3;
    }

    private static function saveWithQuality($editor,string $target,string $targetMime,int $quality) {
        $quality=max(1,min(100,$quality));
        $qualityFilter=static function ($default,string $mime) use ($targetMime,$quality) {
            return $mime === $targetMime ? $quality : $default;
        };
        add_filter('wp_editor_set_quality',$qualityFilter,PHP_INT_MAX,2);
        try {
            $qualityResult=$editor->set_quality($quality);
            if (is_wp_error($qualityResult)) { return $qualityResult; }
            return $editor->save($target,$targetMime);
        } finally {
            remove_filter('wp_editor_set_quality',$qualityFilter,PHP_INT_MAX);
        }
    }

    private static function diskSpaceError(string $directory,int $required): string {
        $free=function_exists('disk_free_space') ? @disk_free_space($directory) : false;
        if ($free === false || $free >= $required) { return ''; }
        /* translators: 1: free disk space, 2: required disk space. */
        return sprintf(__('Not enough disk space in uploads/nwz-better-img: %1$s free, at least %2$s needed. Free up space and use "Retry failures".','nibwoz-better-img'),size_format((int)$free),size_format($required));
    }

    private static function convert(int $attachment,string $source,array $settings): void {
        global $wpdb;
        $sourceMime=self::sourceMime($source);
        if ($sourceMime === '') { throw new \RuntimeException(esc_html__('The source format is not recognized or not supported.','nibwoz-better-img')); }
        $targetMime=$settings['format'] === 'original' ? $sourceMime : 'image/' . $settings['format'];
        $sourceRel=Storage::relative($source);
        $targetExtension=$settings['format'] === 'original' ? strtolower(pathinfo($sourceRel,PATHINFO_EXTENSION)) : (string)$settings['format'];
        $family=(dirname($sourceRel) === '.' ? '' : dirname($sourceRel) . '/') . pathinfo($sourceRel,PATHINFO_FILENAME) . '.' . $targetExtension;
        $destinationLock='nwzbi_dest_' . substr(hash('sha256',DB_NAME . '|' . $wpdb->prefix . '|' . $family),0,40);
        if (!$sourceRel || (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)',$destinationLock)) !== 1) { throw new \RuntimeException(esc_html__('The image destination could not be reserved.','nibwoz-better-img')); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL named lock that serializes writes to one destination.
        try {
        $editor=self::editor($source,$sourceMime,$targetMime,$settings);
        if (is_wp_error($editor)) { throw new \RuntimeException(esc_html($editor->get_error_message())); }
        $sourceSize=$editor->get_size();
        if (empty($sourceSize['width']) || empty($sourceSize['height'])) { throw new \RuntimeException(esc_html__('The source dimensions are invalid.','nibwoz-better-img')); }
        $ratios=[1.0];
        if ((int)$settings['max_width'] > 0) { $ratios[]=(int)$settings['max_width']/(int)$sourceSize['width']; }
        if ((int)$settings['max_height'] > 0) { $ratios[]=(int)$settings['max_height']/(int)$sourceSize['height']; }
        $ratio=min($ratios);
        if ($ratio < 1) {
            $width=max(1,(int)round((int)$sourceSize['width']*$ratio));
            $height=max(1,(int)round((int)$sourceSize['height']*$ratio));
            $resized=$editor->resize($width,$height,false);
            if (is_wp_error($resized)) { throw new \RuntimeException(esc_html($resized->get_error_message())); }
        }
        $outputSize=$editor->get_size();
        if (empty($outputSize['width']) || empty($outputSize['height'])) { throw new \RuntimeException(esc_html__('The optimized copy dimensions are invalid.','nibwoz-better-img')); }
        $destination=Storage::destination($attachment,$source,(string)$settings['format']);
        if (!$destination) { throw new \RuntimeException(esc_html__('The destination is invalid or not writable.','nibwoz-better-img')); }
        [$path,$destRel,$sourceRel]=$destination;
        $assets=Core::table('assets');
        $previous=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE source_rel=%s',$assets,$sourceRel)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the current registry row of the plugin's own table.
        if ($wpdb->last_error) { throw new \RuntimeException(esc_html__('The previous record could not be read.','nibwoz-better-img')); }
        $quality=$settings['format'] === 'original' ? (int)$settings['quality_original'] : (int)$settings['quality_' . $settings['format']];
        $directory=dirname($path);
        $required=(int)filesize($source)+MB_IN_BYTES;
        $diskError=self::diskSpaceError($directory,$required);
        if ($diskError !== '') { throw new \RuntimeException(esc_html($diskError)); }
        $temporary=@tempnam($directory,'nwzbi-tmp-');
        if (!$temporary || dirname($temporary) !== $directory) {
            if ($temporary) { Storage::delete($temporary); }
            throw new \RuntimeException(esc_html__('The destination folder does not allow temporary files.','nibwoz-better-img'));
        }
        $tempTarget=$temporary . '.' . pathinfo($path,PATHINFO_EXTENSION);
        if (file_exists($tempTarget) || !Storage::delete($temporary)) { Storage::delete($temporary); throw new \RuntimeException(esc_html__('The conversion could not be prepared.','nibwoz-better-img')); }
        $editorOutput=$tempTarget;
        try {
            $result=self::saveWithQuality($editor,$tempTarget,$targetMime,$quality);
            if (is_wp_error($result)) {
                $diskError=self::diskSpaceError($directory,$required);
                /* translators: %s: technical error returned by the image editor. */
                throw new \RuntimeException(esc_html($diskError !== '' ? $diskError : sprintf(__('The optimized image could not be written. Check the write permission of uploads/nwz-better-img. Technical detail: %s','nibwoz-better-img'),$result->get_error_message())));
            }
            if (is_array($result) && !empty($result['path']) && is_string($result['path'])) { $editorOutput=$result['path']; }
            if (wp_normalize_path($editorOutput) !== wp_normalize_path($tempTarget)) {
                $realDirectory=realpath($directory); $outputDirectory=realpath(dirname($editorOutput));
                if (!$realDirectory || !$outputDirectory || wp_normalize_path($realDirectory) !== wp_normalize_path($outputDirectory)
                    || !is_file($editorOutput) || is_link($editorOutput) || file_exists($tempTarget) || !Storage::fs()->move($editorOutput,$tempTarget)) {
                    throw new \RuntimeException(esc_html__('The image editor wrote the conversion outside the safe temporary destination.','nibwoz-better-img'));
                }
                $editorOutput=$tempTarget;
            }
            $exists=is_file($tempTarget); $actualMime=$exists ? wp_get_image_mime($tempTarget) : '';
            $dimensions=$exists ? @getimagesize($tempTarget) : false;
            if (!is_array($result) || !$exists || $actualMime !== $targetMime || !$dimensions
                || (int)$dimensions[0] !== (int)$outputSize['width'] || (int)$dimensions[1] !== (int)$outputSize['height']) {
                $actualDimensions=is_array($dimensions) ? (int)$dimensions[0] . 'x' . (int)$dimensions[1] : '-';
                /* translators: 1: editor result (ok or invalid), 2: file state (ok or missing), 3: MIME type, 4: actual dimensions, 5: expected dimensions. */
                throw new \RuntimeException(esc_html(sprintf(__('The conversion produced an invalid image: result=%1$s, file=%2$s, MIME=%3$s, dimensions=%4$s, expected=%5$s.','nibwoz-better-img'),
                    is_array($result) ? 'ok' : 'invalid',$exists ? 'ok' : 'missing',$actualMime ?: '-',$actualDimensions,(int)$outputSize['width'] . 'x' . (int)$outputSize['height'])));
            }
            $sourceBytes=(int)filesize($source); $destBytes=(int)filesize($tempTarget);
            if ($settings['larger'] === 'skip' && $destBytes >= $sourceBytes) { return; }
            $digest=hash_file('sha256',$tempTarget);
            if (!$digest) { throw new \RuntimeException(esc_html__('The converted image could not be verified.','nibwoz-better-img')); }
            $backup='';
            if (is_file($path)) {
                $backup=@tempnam($directory,'nwzbi-bak-');
                if (!$backup || dirname($backup) !== $directory) { if ($backup) { Storage::delete($backup); } throw new \RuntimeException(esc_html__('The backup copy could not be reserved.','nibwoz-better-img')); }
                Storage::delete($backup);
                if (!Storage::fs()->move($path,$backup)) { throw new \RuntimeException(esc_html__('The previous optimized copy could not be preserved.','nibwoz-better-img')); }
            }
            try {
                if (!Storage::fs()->move($tempTarget,$path)) { throw new \RuntimeException(esc_html__('The optimized image could not be published.','nibwoz-better-img')); }
                $saved=$wpdb->replace($assets,[ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin's own custom table.
                    'attachment_id'=>$attachment,'source_rel'=>$sourceRel,'source_hash'=>hash('sha256',$sourceRel),
                    'dest_rel'=>$destRel,'format'=>(string)$settings['format'],
                    'source_bytes'=>$sourceBytes,'dest_bytes'=>$destBytes,'width'=>(int)$dimensions[0],'height'=>(int)$dimensions[1],
                    'digest'=>$digest,'updated_at'=>current_time('mysql',true),
                ]);
                /* translators: %s: database error message. */
                if ($saved === false) { throw new \RuntimeException(esc_html(sprintf(__('The optimized image could not be recorded: %s','nibwoz-better-img'),$wpdb->last_error))); }
                if ($backup) { Storage::delete($backup); }
                if ($previous && $previous->dest_rel !== $destRel && !Storage::remove((string)$previous->dest_rel,(string)$previous->digest)) {
                    $restored=$wpdb->replace($assets,[ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Restores the previous row of the plugin's own table.
                        'id'=>(int)$previous->id,'attachment_id'=>(int)$previous->attachment_id,
                        'source_rel'=>(string)$previous->source_rel,'source_hash'=>hash('sha256',(string)$previous->source_rel),
                        'dest_rel'=>(string)$previous->dest_rel,
                        'format'=>(string)$previous->format,'source_bytes'=>(int)$previous->source_bytes,
                        'dest_bytes'=>(int)$previous->dest_bytes,'width'=>(int)$previous->width,
                        'height'=>(int)$previous->height,'digest'=>(string)$previous->digest,
                        'updated_at'=>(string)$previous->updated_at,
                    ]);
                    if ($restored !== false) { throw new \RuntimeException(esc_html__('The previous optimized copy could not be removed; the replacement was rolled back.','nibwoz-better-img')); }
                    wp_trigger_error(__METHOD__,esc_html('The previous record could not be restored after a cleanup failure.'),E_USER_WARNING);
                }
            } catch (\Throwable $error) {
                if (is_file($path)) { Storage::delete($path); }
                if ($backup && is_file($backup)) { Storage::fs()->move($backup,$path); }
                throw $error;
            }
        } finally {
            if (is_file($tempTarget)) { Storage::delete($tempTarget); }
            if (wp_normalize_path($editorOutput) !== wp_normalize_path($tempTarget) && is_file($editorOutput) && !is_link($editorOutput)) {
                $realDirectory=realpath($directory); $outputDirectory=realpath(dirname($editorOutput));
                if ($realDirectory && $outputDirectory && wp_normalize_path($realDirectory) === wp_normalize_path($outputDirectory)) { Storage::delete($editorOutput); }
            }
        }
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$destinationLock)); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the destination lock.
    }
}
