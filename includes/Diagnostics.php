<?php
/**
 * Server and image-engine capabilities, verified with a real 1x1 encode.
 *
 * @package NIBWOZ\BetterImg
 */

namespace NIBWOZ\BetterImg;

if (!defined('ABSPATH')) { exit; }

final class Diagnostics {
    private static array $encodeSupport=[];
    private static ?array $engines=null;

    private static function loadEditors(): void {
        if (!class_exists('WP_Image_Editor',false)) { require_once ABSPATH . WPINC . '/class-wp-image-editor.php'; }
        if (!class_exists('WP_Image_Editor_GD',false)) { require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php'; }
        if (!class_exists('WP_Image_Editor_Imagick',false)) { require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php'; }
    }

    public static function server(): array {
        global $wpdb;
        $software=isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '';
        $lower=strtolower($software);
        $type=str_contains($lower,'litespeed') ? 'LiteSpeed' : (str_contains($lower,'nginx') ? 'Nginx' : (str_contains($lower,'apache') ? 'Apache' : (str_contains($lower,'microsoft-iis') ? 'IIS' : (str_contains($lower,'caddy') ? 'Caddy' : ''))));
        $lock='nwzbi_diag_' . substr(hash('sha256',DB_NAME . '|' . $wpdb->prefix),0,32);
        $namedLocks=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)) === 1; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Probes MySQL named-lock support; no table is read.
        if ($namedLocks) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the probe lock.
        return ['type'=>$type,'software'=>$software,'sapi'=>PHP_SAPI,'php'=>PHP_VERSION,'os'=>PHP_OS_FAMILY,
            'database'=>method_exists($wpdb,'db_server_info') ? (string)$wpdb->db_server_info() : (string)$wpdb->db_version(),
            'named_locks'=>$namedLocks];
    }

    public static function engines(): array {
        if (self::$engines !== null) { return self::$engines; }
        self::loadEditors();
        return self::$engines=[
            'gd'=>self::engine('gd','GD','WP_Image_Editor_GD'),
            'imagick'=>self::engine('imagick','Imagick','WP_Image_Editor_Imagick'),
        ];
    }

    private static function engine(string $id,string $label,string $class): array {
        $available=class_exists($class) && method_exists($class,'test') && (bool)$class::test();
        $formats=[];
        foreach (['image/jpeg'=>'JPEG','image/png'=>'PNG','image/webp'=>'WebP','image/avif'=>'AVIF'] as $mime=>$name) {
            if ($available && self::canEncode($id,$mime,$class)) { $formats[]=$name; }
        }
        $version='';
        if ($id === 'gd' && function_exists('gd_info')) { $info=gd_info(); $version=(string)($info['GD Version']??''); }
        if ($id === 'imagick' && class_exists('Imagick')) { try { $info=\Imagick::getVersion(); $version=(string)($info['versionString']??''); } catch (\Throwable $e) { $version=''; } }
        $readFormats=[];
        foreach (['JPEG'=>'imagecreatefromjpeg','PNG'=>'imagecreatefrompng','WebP'=>'imagecreatefromwebp','AVIF'=>'imagecreatefromavif'] as $name=>$function) {
            if (!$available) { continue; }
            if ($id === 'gd' && function_exists($function)) { $readFormats[]=$name; }
            if ($id === 'imagick' && class_exists('Imagick')) {
                try { if (\Imagick::queryFormats(strtoupper($name))) { $readFormats[]=$name; } } catch (\Throwable $error) { /* Codec not available. */ }
            }
        }
        return ['label'=>$label,'available'=>$available,'version'=>$version,'formats'=>$formats,'read_formats'=>$readFormats];
    }

    private static function canEncode(string $engine,string $mime,string $class): bool {
        $key=$engine . '|' . $mime;
        if (array_key_exists($key,self::$encodeSupport)) { return self::$encodeSupport[$key]; }
        if (!method_exists($class,'supports_mime_type') || !$class::supports_mime_type($mime)) { return self::$encodeSupport[$key]=false; }
        if ($engine === 'gd') { return self::$encodeSupport[$key]=self::gdCanEncode($mime); }
        if ($engine === 'imagick') { return self::$encodeSupport[$key]=self::imagickCanEncode($mime); }
        return self::$encodeSupport[$key]=false;
    }

    private static function gdCanEncode(string $mime): bool {
        $function=match ($mime) {
            'image/jpeg'=>'imagejpeg','image/png'=>'imagepng','image/webp'=>'imagewebp','image/avif'=>'imageavif',default=>'',
        };
        if ($function === '' || !function_exists($function) || !function_exists('imagecreatetruecolor')) { return false; }
        $image=@imagecreatetruecolor(1,1);
        if (!$image) { return false; }
        $written=false; $blob='';
        ob_start();
        try {
            $written=match ($mime) {
                'image/jpeg'=>@imagejpeg($image,null,50),
                'image/png'=>@imagepng($image),
                'image/webp'=>@imagewebp($image,null,50),
                'image/avif'=>@imageavif($image,null,50,10),
            };
            $blob=(string)ob_get_contents();
        } catch (\Throwable) {
            $written=false;
        } finally {
            ob_end_clean();
        }
        return $written === true && $blob !== '';
    }

    private static function imagickCanEncode(string $mime): bool {
        if (!class_exists('Imagick') || !class_exists('ImagickPixel')) { return false; }
        $format=match ($mime) {
            'image/jpeg'=>'JPEG','image/png'=>'PNG','image/webp'=>'WEBP','image/avif'=>'AVIF',default=>'',
        };
        if ($format === '') { return false; }
        $image=null;
        try {
            $image=new \Imagick();
            $image->newImage(1,1,new \ImagickPixel('white'));
            $image->setImageFormat($format);
            if (method_exists($image,'setImageCompressionQuality')) { $image->setImageCompressionQuality(50); }
            $blob=$image->getImagesBlob();
            return is_string($blob) && strlen($blob)>0;
        } catch (\Throwable $error) {
            return false;
        } finally {
            if ($image instanceof \Imagick) {
                try { $image->clear(); $image->destroy(); } catch (\Throwable $cleanupError) {}
            }
        }
    }

    public static function requiredFormats(string $format): array {
        return $format === 'original' ? ['JPEG','PNG','WebP'] : [$format === 'avif' ? 'AVIF' : 'WebP'];
    }

    public static function supports(string $format,string $engine='auto'): bool {
        if (!in_array($format,['webp','avif','original'],true)) { return false; }
        $engines=self::engines();
        $candidates=$engine === 'auto' ? $engines : (isset($engines[$engine]) ? [$engines[$engine]] : []);
        foreach ($candidates as $item) {
            if ($item['available'] && !array_diff(self::requiredFormats($format),$item['formats'])) { return true; }
        }
        return false;
    }

    public static function formatsForEngine(string $engine): array {
        $supported=[];
        foreach (['webp','avif','original'] as $format) { if (self::supports($format,$engine)) { $supported[]=$format; } }
        return $supported;
    }

    public static function extensions(): array {
        return ['exif'=>extension_loaded('exif'),'fileinfo'=>extension_loaded('fileinfo'),'gd'=>extension_loaded('gd'),'imagick'=>extension_loaded('imagick')];
    }
}
