<?php
/**
 * Admin screens, Media Library integration and admin actions.
 *
 * @package NIBWOZ\BetterImg
 */

namespace NIBWOZ\BetterImg;

use NIBWOZ\UI\V1_2_0\Ui;

if (!defined('ABSPATH')) { exit; }

final class Admin {
    private const DOMAIN='nibwoz-better-img';

    public static function boot(): void {
        add_action('admin_menu',static function () { add_management_page('NIBWOZ Better Img','NWZ Better Img','manage_options','nwzbi',[self::class,'render']); });
        add_action('admin_enqueue_scripts',[self::class,'assets']);
        add_filter('plugin_action_links_' . plugin_basename(FILE),static function ($links) {
            array_unshift($links,'<a href="' . esc_url(admin_url('tools.php?page=nwzbi&view=settings')) . '">' . esc_html__('Settings','nibwoz-better-img') . '</a>');
            return $links;
        });
        add_action('admin_post_nwzbi_action',[self::class,'action']);
        add_action('wp_ajax_nwzbi_queue_status',[self::class,'queueStatus']);
        add_action('wp_ajax_nwzbi_queue_all',[self::class,'queueAllAsync']);
        add_action('wp_ajax_nwzbi_optimize_attachment',[self::class,'optimizeAttachment']);
        add_filter('manage_upload_columns',static function ($columns) { $columns['nwzbi']=__('Optimized image','nibwoz-better-img'); return $columns; });
        add_action('manage_media_custom_column',[self::class,'column'],10,2);
        add_filter('bulk_actions-upload',static function ($actions) { $actions['nwzbi_optimize']=__('NWZ: optimize','nibwoz-better-img'); return $actions; });
        add_filter('handle_bulk_actions-upload',[self::class,'bulk'],10,3);
        add_filter('media_row_actions',[self::class,'row'],10,2);
        add_filter('attachment_fields_to_edit',[self::class,'attachmentFields'],20,2);
        add_filter('removable_query_args',static fn(array $args): array => array_merge($args,['nwzbi_notice','nwzbi_count']));
        add_action('admin_notices',static function (): void {
            global $pagenow;
            if ($pagenow === 'upload.php') { echo wp_kses_post(self::notice(false)); }
        });
    }

    public static function assets(string $hook): void {
        $pluginPage=$hook === 'tools_page_nwzbi';
        $mediaPage=in_array($hook,['upload.php','post.php','post-new.php','media.php'],true);
        if (!$pluginPage && !$mediaPage) { return; }
        if ($pluginPage) {
            self::kit();
            $ui=Ui::enqueue('nwzbi',plugins_url('assets/nibwoz-ui/',FILE));
            wp_enqueue_style('nwzbi-admin',plugins_url('assets/admin.css',FILE),[$ui],VERSION);
        } else {
            wp_enqueue_style('nwzbi-media',plugins_url('assets/media.css',FILE),[],VERSION);
        }
        if ($mediaPage || in_array(self::currentView(),['settings','processing'],true)) {
            wp_enqueue_script('nwzbi-admin',plugins_url('assets/admin.js',FILE),[],VERSION,['in_footer'=>true,'strategy'=>'defer']);
            wp_localize_script('nwzbi-admin','nwzbiAdmin',['locale'=>str_replace('_','-',get_user_locale()),'i18n'=>self::scriptStrings()]);
        }
    }

    private static function scriptStrings(): array {
        return [
            /* translators: 1: number of finished images, 2: total images in the round. */
            'progressOne'=>_n('%1$s of %2$s image','%1$s of %2$s images',1,'nibwoz-better-img'),
            /* translators: 1: number of finished images, 2: total images in the round. */
            'progressMany'=>_n('%1$s of %2$s image','%1$s of %2$s images',2,'nibwoz-better-img'),
            'progressNone'=>__('No images in the queue','nibwoz-better-img'),
            'currentRound'=>__('Current round','nibwoz-better-img'),
            'lastRound'=>__('Last round','nibwoz-better-img'),
            /* translators: %s: estimated remaining time, such as 1:05. */
            'remaining'=>__('≈ %s remaining','nibwoz-better-img'),
            'processing'=>_x('Processing','queue state','nibwoz-better-img'),
            'finished'=>__('Finished','nibwoz-better-img'),
            'idle'=>__('Idle','nibwoz-better-img'),
            'preparing'=>__('Preparing the queue…','nibwoz-better-img'),
            'sessionExpired'=>__('Your session expired or permission was denied. Reload the page to keep following the queue; processing was not interrupted.','nibwoz-better-img'),
            /* translators: %s: HTTP status code. */
            'httpError'=>__('HTTP error %s while updating the queue.','nibwoz-better-img'),
            /* translators: %s: HTTP status code. */
            'httpErrorShort'=>__('HTTP error %s.','nibwoz-better-img'),
            'timeout'=>__('20-second timeout','nibwoz-better-img'),
            'connection'=>__('temporary connection failure','nibwoz-better-img'),
            'invalidResponse'=>__('invalid server response','nibwoz-better-img'),
            'genericConnection'=>__('connection failure','nibwoz-better-img'),
            /* translators: 1: failure reason, 2: seconds until the next attempt. */
            'queryFailed'=>__('The queue could not be checked (%1$s). Retrying in %2$ss; processing was not interrupted.','nibwoz-better-img'),
            'queueStartFailed'=>__('The queue could not be started.','nibwoz-better-img'),
            /* translators: %s: image engine name, such as GD or Imagick. */
            'automaticWith'=>__('Automatic - %s','nibwoz-better-img'),
            'automaticNone'=>__('Automatic — unavailable','nibwoz-better-img'),
            /* translators: %s: output format, such as WebP. */
            'noEngine'=>__('No available engine can write %s on this server.','nibwoz-better-img'),
            /* translators: 1: image engine name, 2: output format. */
            'automaticUses'=>__('Automatic mode will use %1$s to write %2$s on this server.','nibwoz-better-img'),
            'compatibleEngine'=>__('the compatible engine','nibwoz-better-img'),
            /* translators: 1: image engine name, 2: output format. */
            'enginePassed'=>__('%1$s passed the real %2$s encoding test on this server.','nibwoz-better-img'),
            'keepOriginal'=>__('the original format','nibwoz-better-img'),
            'copyFailed'=>__('The URL could not be copied.','nibwoz-better-img'),
            'copied'=>__('URL copied','nibwoz-better-img'),
            'copyError'=>__('Copy failed','nibwoz-better-img'),
            'adding'=>__('Adding…','nibwoz-better-img'),
            'added'=>__('Added to the queue','nibwoz-better-img'),
            'optimizeFailed'=>__('This image could not be optimized.','nibwoz-better-img'),
        ];
    }

    private static function currentView(): string {
        $view=isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation between the plugin's own screens.
        return in_array($view,['dashboard','settings','processing','diagnostics','about'],true) ? $view : 'dashboard';
    }

    private static function pageUrl(string $view): string {
        return add_query_arg(['page'=>'nwzbi','view'=>$view],admin_url('tools.php'));
    }

    private static function actionUrl(string $do,array $arguments=[]): string {
        return wp_nonce_url(add_query_arg(array_merge(['action'=>'nwzbi_action','do'=>$do],$arguments),admin_url('admin-post.php')),'nwzbi_' . $do);
    }

    private static function dateTime(int $timestamp): string {
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'),$timestamp);
    }

    public static function row(array $actions,\WP_Post $post): array {
        if (Processor::eligible((int)$post->ID) && current_user_can('manage_options')) { $actions['nwzbi']='<a href="' . esc_url(self::actionUrl('enqueue',['id'=>$post->ID])) . '">' . esc_html__('NWZ: optimize','nibwoz-better-img') . '</a>'; }
        return $actions;
    }

    public static function column(string $column,int $attachment): void {
        if ($column !== 'nwzbi') { return; }
        $summary=self::assetSummary($attachment);
        if ($summary) { echo esc_html($summary['format'] . ' · ' . size_format($summary['source_bytes']) . ' → ' . size_format($summary['dest_bytes'])); }
        else { echo esc_html__('Not optimized','nibwoz-better-img'); }
    }

    private static function assetSummary(int $attachment,bool $includeUrl=false): ?array {
        global $wpdb;
        $assets=Core::table('assets');
        $row=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) files,COALESCE(SUM(source_bytes),0) source_bytes,COALESCE(SUM(dest_bytes),0) dest_bytes,GROUP_CONCAT(DISTINCT format ORDER BY format SEPARATOR ',') formats,MAX(updated_at) updated_at FROM %i WHERE attachment_id=%d",$assets,$attachment)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only summary of the plugin's own table.
        if (!$row || (int)$row->files<1) { return null; }
        $formats=array_values(array_filter(explode(',',(string)$row->formats)));
        /* translators: %s: list of formats, such as WEBP + AVIF. */
        $format=count($formats)>1 ? sprintf(__('MIXED: %s','nibwoz-better-img'),implode(' + ',array_map('strtoupper',$formats))) : strtoupper((string)($formats[0]??''));
        $source=(int)$row->source_bytes; $dest=(int)$row->dest_bytes; $saved=$source-$dest;
        $optimizedUrl='';
        if ($includeUrl) {
            $main=get_attached_file($attachment);
            $sourceRel=is_string($main) ? Storage::relative($main) : '';
            if ($sourceRel !== '') {
                $destRel=(string)$wpdb->get_var($wpdb->prepare('SELECT dest_rel FROM %i WHERE attachment_id=%d AND source_rel=%s LIMIT 1',$assets,$attachment,$sourceRel)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only lookup in the plugin's own table.
                if ($destRel !== '' && Storage::exists($destRel)) { $optimizedUrl=Storage::url($destRel); }
            }
        }
        return ['files'=>(int)$row->files,'source_bytes'=>$source,'dest_bytes'=>$dest,'saved_bytes'=>$saved,
            'saved_percent'=>$source>0 ? round(($saved/$source)*100,1) : 0,'format'=>$format,
            'updated_at'=>(string)$row->updated_at,'optimized_url'=>$optimizedUrl];
    }

    public static function attachmentFields(array $fields,\WP_Post $post): array {
        if (!current_user_can('edit_post',$post->ID) || !Processor::eligible((int)$post->ID)) { return $fields; }
        $summary=self::assetSummary((int)$post->ID,true);
        if (!$summary) { $html='<div class="nwzbi-media-summary is-empty"><strong>' . esc_html__('Not optimized yet','nibwoz-better-img') . '</strong><p>' . esc_html__('No active optimized copy is registered for this media item.','nibwoz-better-img') . '</p></div>'; }
        else {
            /* translators: %s: number of optimized files. */
            $files=sprintf(_n('%s file','%s files',$summary['files'],'nibwoz-better-img'),number_format_i18n($summary['files']));
            $savingLabel=$summary['saved_bytes']>=0 ? __('Savings','nibwoz-better-img') : __('Increase','nibwoz-better-img');
            $saving=size_format(abs($summary['saved_bytes'])) . ' (' . number_format_i18n(abs((float)$summary['saved_percent']),1) . '%)';
            $updated=$summary['updated_at']!=='' ? self::dateTime((int)strtotime($summary['updated_at'] . ' UTC')) : __('Not reported','nibwoz-better-img');
            $html='<div class="nwzbi-media-summary"><dl>'
                . '<div><dt>' . esc_html__('Format','nibwoz-better-img') . '</dt><dd>' . esc_html($summary['format']) . '</dd></div>'
                . '<div><dt>' . esc_html__('Optimized copies','nibwoz-better-img') . '</dt><dd>' . esc_html($files) . '</dd></div>'
                . '<div><dt>' . esc_html__('Original size','nibwoz-better-img') . '</dt><dd>' . esc_html(size_format($summary['source_bytes'])) . '</dd></div>'
                . '<div><dt>' . esc_html__('Optimized size','nibwoz-better-img') . '</dt><dd>' . esc_html(size_format($summary['dest_bytes'])) . '</dd></div>'
                . '<div><dt>' . esc_html($savingLabel) . '</dt><dd>' . esc_html($saving) . '</dd></div>'
                . '<div><dt>' . esc_html__('Last optimization','nibwoz-better-img') . '</dt><dd>' . esc_html($updated) . '</dd></div></dl>';
            if ($summary['optimized_url'] !== '') {
                $inputId='nwzbi-optimized-url-' . (int)$post->ID;
                $html.='<div class="nwzbi-media-url"><label for="' . esc_attr($inputId) . '">' . esc_html__('Optimized file URL','nibwoz-better-img') . '</label><div><input id="' . esc_attr($inputId) . '" type="text" value="' . esc_attr($summary['optimized_url']) . '" readonly><button type="button" class="button" data-nwzbi-copy-url>' . esc_html__('Copy URL','nibwoz-better-img') . '</button></div></div>';
            }
            $html.='</div>';
        }
        if (current_user_can('manage_options')) {
            $buttonLabel=$summary ? __('Re-optimize image','nibwoz-better-img') : __('Optimize image','nibwoz-better-img');
            $html.='<div class="nwzbi-media-actions"><button type="button" class="button button-primary" data-nwzbi-optimize data-endpoint="' . esc_url(admin_url('admin-ajax.php')) . '" data-attachment="' . (int)$post->ID . '" data-nonce="' . esc_attr(wp_create_nonce('nwzbi_optimize_attachment_' . (int)$post->ID)) . '">' . esc_html($buttonLabel) . '</button><span data-nwzbi-media-feedback aria-live="polite"></span></div>';
        }
        $fields['nwzbi_optimization']=['label'=>__('Optimized image','nibwoz-better-img'),'input'=>'html','html'=>$html,'show_in_edit'=>true,'show_in_modal'=>true];
        return $fields;
    }

    public static function optimizeAttachment(): void {
        if (!current_user_can('manage_options')) { wp_send_json_error(['message'=>__('You do not have permission to do this.','nibwoz-better-img')],403); }
        $attachment=isset($_POST['attachment']) ? absint($_POST['attachment']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The ID selects the nonce action verified on the next line.
        check_ajax_referer('nwzbi_optimize_attachment_' . $attachment,'nonce');
        if (!Processor::eligible($attachment)) { wp_send_json_error(['message'=>__('This media item has no eligible JPEG, PNG, WebP or AVIF image.','nibwoz-better-img')],400); }
        $queued=Queue::enqueueSelection([$attachment]);
        if ($queued !== 1) { wp_send_json_error(['message'=>__('This image could not be added to the queue.','nibwoz-better-img')],500); }
        Queue::dispatch();
        wp_send_json_success(['message'=>__('Image added to the optimization queue.','nibwoz-better-img')]);
    }

    public static function bulk(string $redirect,string $action,array $ids): string {
        if ($action !== 'nwzbi_optimize' || !current_user_can('manage_options')) { return $redirect; }
        check_admin_referer('bulk-media'); $queued=Queue::enqueueSelection($ids);
        if ($queued < 0) { return add_query_arg('nwzbi_notice','selection_failed',$redirect); }
        if ($queued > 0) { Queue::dispatch(); }
        return add_query_arg(['nwzbi_notice'=>'queued','nwzbi_count'=>$queued],$redirect);
    }

    private static function confirmPhrase(): string {
        return __('DELETE OPTIMIZED','nibwoz-better-img');
    }

    public static function action(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('You do not have permission to do this.','nibwoz-better-img')); }
        $do=isset($_REQUEST['do']) ? sanitize_key(wp_unslash($_REQUEST['do'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The value selects the nonce action verified on the next line.
        check_admin_referer('nwzbi_' . $do);
        $post=isset($_SERVER['REQUEST_METHOD']) && 'POST' === $_SERVER['REQUEST_METHOD'];
        $notice='ok'; $view='dashboard'; $noticeCount=null;
        if ($do === 'save' && $post) {
            $view='settings';
            $raw=isset($_POST['nwzbi']) && is_array($_POST['nwzbi']) ? map_deep(wp_unslash($_POST['nwzbi']),'sanitize_text_field') : [];
            $hosts=isset($_POST['nwzbi']['cdn_hosts']) ? sanitize_textarea_field(wp_unslash($_POST['nwzbi']['cdn_hosts'])) : '';
            $settings=Core::settings();
            $format=sanitize_key((string)($raw['format']??'webp')); $engine=sanitize_key((string)($raw['engine']??'auto'));
            if (!in_array($engine,['auto','gd','imagick'],true)) { $engine='auto'; }
            if (Diagnostics::supports($format,$engine)) { $settings['format']=$format; $settings['engine']=$engine; }
            else { $notice='unsupported'; }
            foreach (['quality_webp','quality_avif','quality_original'] as $key) { $settings[$key]=max(1,min(100,(int)($raw[$key]??$settings[$key]))); }
            $settings['max_width']=max(0,min(20000,(int)($raw['max_width']??0)));
            $settings['max_height']=max(0,min(20000,(int)($raw['max_height']??0)));
            $settings['concurrency']=max(1,min(4,(int)($raw['concurrency']??1)));
            $settings['automatic']=empty($raw['automatic']) ? 0 : 1;
            $settings['thumbnails']=empty($raw['thumbnails']) ? 0 : 1;
            $settings['delivery_enabled']=empty($raw['delivery_enabled']) ? 0 : 1;
            $settings['cdn_hosts']=Core::sanitizeHosts($hosts);
            $settings['larger']=($raw['larger']??'skip') === 'keep' ? 'keep' : 'skip';
            update_option('nwzbi_settings',$settings); Metrics::invalidate();
        } elseif ($do === 'queue_all' && $post) {
            $view='processing'; $skipped=0; $count=Queue::scan(true,$skipped);
            if ($count < 0) { $notice='scan_failed'; }
            elseif ($count === 0) { $notice='library_empty'; $noticeCount=$skipped; }
            else { $notice='library_queued'; $noticeCount=$count; Queue::dispatch(); }
        } elseif ($do === 'stop' && $post) {
            $view='processing'; $count=Queue::halt(); $noticeCount=max(0,$count);
            $notice=$count < 0 ? 'stop_failed' : 'queue_stopped';
        } elseif ($do === 'work' && $post) {
            $view='processing'; Queue::recover(); $notice='batch_done';
        } elseif ($do === 'retry' && $post) {
            $view='processing'; $retried=Queue::retry_failed(); $notice=$retried<0 ? 'retry_failed' : 'retried';
            if ($retried > 0) { Queue::dispatch(); }
        } elseif ($do === 'refresh' && $post) {
            Metrics::snapshot(true); $notice='metrics_refreshed';
        } elseif ($do === 'save_uninstall' && $post) {
            $view='about'; $settings=Core::settings();
            $settings['uninstall_cleanup']=empty($_POST['uninstall_cleanup']) ? 0 : 1;
            update_option('nwzbi_settings',$settings); $notice='uninstall_saved';
        } elseif ($do === 'delete_all' && $post) {
            $view='processing'; $confirm=isset($_POST['confirm']) ? strtoupper(trim(sanitize_text_field(wp_unslash($_POST['confirm'])))) : '';
            if (!in_array($confirm,array_unique(['DELETE OPTIMIZED',self::confirmPhrase()]),true)) { $notice='confirmation_required'; }
            else { [, $unresolved]=Cleanup::all(); $notice=$unresolved ? 'cleanup_incomplete' : 'all_deleted'; }
        } elseif ($do === 'enqueue') {
            $id=isset($_GET['id']) ? absint($_GET['id']) : 0; $queued=$id ? Queue::enqueueSelection([$id]) : -1;
            if ($queued===1) { Queue::dispatch(); }
            wp_safe_redirect(add_query_arg('nwzbi_notice',$queued===1?'queued':'selection_failed',admin_url('upload.php'))); exit;
        }
        $redirect=['page'=>'nwzbi','view'=>$view,'nwzbi_notice'=>$notice];
        if ($noticeCount !== null) { $redirect['nwzbi_count']=$noticeCount; }
        wp_safe_redirect(add_query_arg($redirect,admin_url('tools.php'))); exit;
    }

    private static function kit(): void {
        require_once dirname(FILE) . '/assets/nibwoz-ui/Ui.php';
    }

    /** Runs a NIBWOZ UI renderer, which escapes its own output, and returns the markup. */
    private static function capture(callable $render): string {
        ob_start();
        $render();
        return (string)ob_get_clean();
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) { return; }
        self::kit();
        $view=self::currentView();
        $sections=[
            'dashboard'=>[__('Home','nibwoz-better-img'),'dashicons-admin-home'],
            'settings'=>[__('Optimization','nibwoz-better-img'),'dashicons-admin-settings'],
            'processing'=>[__('Processing','nibwoz-better-img'),'dashicons-images-alt2'],
            'diagnostics'=>[__('Diagnostics','nibwoz-better-img'),'dashicons-search'],
            'about'=>[__('About','nibwoz-better-img'),'dashicons-info-outline'],
        ];
        $items=[];
        foreach ($sections as $id=>[$label,$icon]) { $items[]=[$label,self::pageUrl($id),$icon,$view===$id]; }
        $forms=['settings'=>['nwzbi-settings',__('Save settings','nibwoz-better-img')],'about'=>['nwzbi-uninstall',__('Save','nibwoz-better-img')]];
        Ui::shell_open([
            'title'=>'NIBWOZ Better Img','product'=>'Better Img',
            /* translators: %s: plugin version number. */
            'version'=>sprintf(__('Version %s','nibwoz-better-img'),VERSION),
            'wordmark_url'=>plugins_url('assets/nibwoz-ui/nibwoz-wordmark.svg',FILE),
            'nav_label'=>__('Better Img sections','nibwoz-better-img'),
            'items'=>$items,
            'save'=>isset($forms[$view]) ? ['form'=>$forms[$view][0],'label'=>$forms[$view][1],'dirty'=>__('Unsaved changes','nibwoz-better-img')] : null,
            'leave'=>__('There are unsaved changes on this screen.','nibwoz-better-img'),
            'class'=>'nwzbi',
        ]);
        $html=self::notice();
        $html.=match ($view) { 'settings'=>self::settings(),'processing'=>self::processing(),'diagnostics'=>self::diagnostics(),'about'=>self::about(),default=>self::dashboard() };
        echo wp_kses($html,self::allowedHtml());
        Ui::shell_close();
    }

    /** Markup allowed in the plugin screens: the escaped HTML built here and by NIBWOZ UI, including forms, ARIA and data attributes. */
    private static function allowedHtml(): array {
        $attributes=['class'=>true,'id'=>true,'style'=>true,'role'=>true,'hidden'=>true,'title'=>true,'data-*'=>true,
            'aria-label'=>true,'aria-labelledby'=>true,'aria-describedby'=>true,'aria-hidden'=>true,'aria-live'=>true,'aria-current'=>true,
            'aria-valuemin'=>true,'aria-valuemax'=>true,'aria-valuenow'=>true,'aria-busy'=>true];
        $tags=['div','section','header','article','span','strong','small','p','h2','h3','h4','dl','dt','dd','ul','li','code','i','b','table','thead','tbody','tr','th','td','details','summary'];
        $allowed=array_fill_keys($tags,$attributes);
        $allowed['a']=$attributes+['href'=>true,'target'=>true,'rel'=>true];
        $allowed['img']=$attributes+['src'=>true,'alt'=>true,'width'=>true,'height'=>true];
        $allowed['form']=$attributes+['method'=>true,'action'=>true];
        $allowed['label']=$attributes+['for'=>true];
        $allowed['input']=$attributes+['type'=>true,'name'=>true,'value'=>true,'checked'=>true,'disabled'=>true,'readonly'=>true,'required'=>true,'autocomplete'=>true,'min'=>true,'max'=>true,'step'=>true];
        $allowed['select']=$attributes+['name'=>true,'disabled'=>true];
        $allowed['option']=$attributes+['value'=>true,'selected'=>true,'disabled'=>true];
        $allowed['textarea']=$attributes+['name'=>true,'rows'=>true,'spellcheck'=>true,'placeholder'=>true];
        $allowed['button']=$attributes+['type'=>true,'name'=>true,'value'=>true,'disabled'=>true,'form'=>true];
        $allowed['details']['open']=true;
        return $allowed;
    }

    /** Result of the last action: success as a NIBWOZ UI toast inside the plugin screen, errors as a fixed notice; plain WordPress notices elsewhere. */
    private static function notice(bool $kit=true): string {
        if (empty($_GET['nwzbi_notice'])) { return ''; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice after a verified action redirect.
        $hasCount=isset($_GET['nwzbi_count']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice after a verified action redirect.
        $count=$hasCount ? absint($_GET['nwzbi_count']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice after a verified action redirect.
        $key=sanitize_key(wp_unslash($_GET['nwzbi_notice'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice after a verified action redirect.
        $number=number_format_i18n($count);
        $messages=[
            'ok'=>__('Settings saved.','nibwoz-better-img'),
            'unsupported'=>__('The format is not supported by the selected engine.','nibwoz-better-img'),
            /* translators: %s: number of images. */
            'queued'=>!$hasCount || $count===1 ? __('Image added to the queue.','nibwoz-better-img') : ($count>1 ? sprintf(_n('%s image added to the queue.','%s images added to the queue.',$count,'nibwoz-better-img'),$number) : __('No eligible image was selected. Only JPEG, PNG, WebP and AVIF images with a local file can be queued.','nibwoz-better-img')),
            /* translators: %s: number of images. */
            'library_queued'=>sprintf(_n('%s image added to the queue. Processing has started.','%s images added to the queue. Processing has started.',$count,'nibwoz-better-img'),$number),
            /* translators: %s: number of skipped attachments. */
            'library_empty'=>$count>0 ? sprintf(_n('No eligible image was found in the Media Library (%s skipped because of a missing local file or an unrecognized format).','No eligible image was found in the Media Library (%s skipped because of a missing local file or an unrecognized format).',$count,'nibwoz-better-img'),$number) : __('No eligible image was found in the Media Library.','nibwoz-better-img'),
            'selection_failed'=>__('A dedicated queue could not be created for the selection. No previous queue was resumed.','nibwoz-better-img'),
            'scan_failed'=>__('The Media Library scan failed. No original file was changed.','nibwoz-better-img'),
            /* translators: %s: number of removed pending images. */
            'queue_stopped'=>sprintf(_n('Conversion stopped. %s pending image was removed from the queue. Images already being written can finish safely.','Conversion stopped. %s pending images were removed from the queue. Images already being written can finish safely.',$count,'nibwoz-better-img'),$number),
            'stop_failed'=>__('The pending images could not be removed. The stop request remains active.','nibwoz-better-img'),
            'batch_done'=>__('Queue resumed manually.','nibwoz-better-img'),
            'retry_failed'=>__('The failed tasks could not be reopened.','nibwoz-better-img'),
            'retried'=>__('Failed tasks reopened.','nibwoz-better-img'),
            'metrics_refreshed'=>__('Inventory updated.','nibwoz-better-img'),
            'uninstall_saved'=>__('Uninstall preference saved.','nibwoz-better-img'),
            /* translators: %s: confirmation phrase to type. */
            'confirmation_required'=>sprintf(__('Type exactly %s to confirm.','nibwoz-better-img'),self::confirmPhrase()),
            'cleanup_incomplete'=>__('Cleanup stopped because one or more files could not be verified or removed.','nibwoz-better-img'),
            'all_deleted'=>__('All registered optimized images were removed. The originals were preserved.','nibwoz-better-img'),
        ];
        if (!isset($messages[$key])) { return ''; }
        $error=in_array($key,['library_empty','unsupported','scan_failed','stop_failed','retry_failed','selection_failed','confirmation_required','cleanup_incomplete'],true) || ($key==='queued' && $hasCount && $count===0);
        if (!$kit || !class_exists(Ui::class)) {
            return '<div class="notice notice-' . ($error?'error':'success') . ' is-dismissible"><p>' . esc_html($messages[$key]) . '</p></div>';
        }
        return self::capture(static fn() => $error ? Ui::notice('error',[$messages[$key]]) : Ui::toast($messages[$key]));
    }

    private static function pageHead(string $kicker,string $title,string $text,string $actions=''): string {
        return '<header class="nwz-page-head"><div><p class="nwz-kicker">' . esc_html($kicker) . '</p><h2>' . esc_html($title) . '</h2><p>' . esc_html($text) . '</p></div>' . ($actions !== '' ? '<div class="nwz-head-actions">' . $actions . '</div>' : '') . '</header>';
    }

    private static function icon(string $name): string {
        return '<span class="dashicons ' . esc_attr($name) . '" aria-hidden="true"></span>';
    }

    private static function postButton(string $do,string $label,string $class,string $icon='',string $formAttributes=''): string {
        return '<form class="nwz-inline-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . $formAttributes . '><input type="hidden" name="action" value="nwzbi_action"><input type="hidden" name="do" value="' . esc_attr($do) . '">' . wp_nonce_field('nwzbi_' . $do,'_wpnonce',true,false) . '<button class="' . esc_attr($class) . '" type="submit" data-nwz-busy-on-submit>' . ($icon !== '' ? self::icon($icon) : '') . esc_html($label) . '</button></form>';
    }

    private static function chip(bool $on,string $label): string {
        return '<li class="' . ($on ? 'is-on' : 'is-off') . '">' . self::icon($on ? 'dashicons-yes-alt' : 'dashicons-minus') . esc_html($label) . '</li>';
    }

    private static function dashboard(): string {
        $stats=Metrics::snapshot(); $settings=Core::settings(); $format=strtoupper((string)$settings['format']); $queue=self::queuePayload();
        $saving=(int)$stats['saved_bytes']; $savingLabel=$saving >= 0 ? __('Savings','nibwoz-better-img') : __('Increase','nibwoz-better-img');
        $sourceBar=$stats['optimized_source_bytes']>0 ? 100 : 0;
        $optimizedBar=$stats['optimized_source_bytes']>0 ? max(2,min(100,round($stats['optimized_bytes']/$stats['optimized_source_bytes']*100,1))) : 0;
        $currentFormatFiles=(int)$stats['current_format_files'];
        $html=self::pageHead(__('Overview','nibwoz-better-img'),__('Image library','nibwoz-better-img'),__('WordPress inventory and the real result of the optimized files.','nibwoz-better-img'),self::postButton('refresh',__('Refresh inventory','nibwoz-better-img'),'button','dashicons-update'));
        if (!empty($queue['activity'])) {
            $html.='<div class="nwz-running-note" role="status"><span class="nwz-live" aria-hidden="true"></span><p><strong>' . esc_html__('Optimization in progress:','nibwoz-better-img') . '</strong> ' . esc_html(self::progressLabel((int)$queue['progress']['finished'],(int)$queue['progress']['total'])) . ' (' . esc_html(number_format_i18n((float)$queue['progress']['percent'],1)) . '%).</p><a class="button" href="' . esc_url(self::pageUrl('processing')) . '">' . self::icon('dashicons-images-alt2') . esc_html__('Follow in Processing','nibwoz-better-img') . '</a></div>';
        }
        $html.='<div class="nwz-stat-grid">' . self::capture(static function () use ($stats,$savingLabel,$saving,$format,$currentFormatFiles): void {
            /* translators: 1: total size, 2: number of files. */
            Ui::stat(__('Images in the library','nibwoz-better-img'),number_format_i18n((int)$stats['attachments']),sprintf(_n('%1$s in %2$s file','%1$s in %2$s files',(int)$stats['files'],'nibwoz-better-img'),size_format((int)$stats['bytes']),number_format_i18n((int)$stats['files'])),'blue');
            /* translators: %s: total size. */
            Ui::stat(__('Optimized files','nibwoz-better-img'),number_format_i18n((int)$stats['optimized_files']),sprintf(__('%s stored','nibwoz-better-img'),size_format((int)$stats['optimized_bytes'])),'green');
            /* translators: %s: percentage. */
            Ui::stat($savingLabel,(string)size_format(abs($saving)),sprintf(__('%s%% on the processed files','nibwoz-better-img'),number_format_i18n(abs((float)$stats['saved_percent']),1)),'orange');
            /* translators: %s: number of files. */
            Ui::stat(__('Configured format','nibwoz-better-img'),$format,sprintf(_n('%s file in this format','%s files in this format',$currentFormatFiles,'nibwoz-better-img'),number_format_i18n($currentFormatFiles)),'dark');
        }) . '</div>';
        $html.='<div class="nwz-grid-2 nwzbi-charts"><section class="nwz-card nwzbi-coverage"><h3>' . esc_html__('Library coverage','nibwoz-better-img') . '</h3><div class="nwzbi-donut" style="--value:' . esc_attr((string)$stats['coverage_percent']) . '"><span>' . esc_html(number_format_i18n((float)$stats['coverage_percent'],1)) . '%</span></div><p>'
            /* translators: 1: optimized files, 2: eligible files. */
            . esc_html(sprintf(__('%1$s of %2$s eligible files have an active optimized copy.','nibwoz-better-img'),number_format_i18n((int)$stats['optimized_files']),number_format_i18n((int)$stats['files']))) . '</p></section>';
        $html.='<section class="nwz-card nwzbi-bars"><h3>' . esc_html__('Size of the processed files','nibwoz-better-img') . '</h3><div class="nwzbi-bar-row"><div><span>' . esc_html__('Matching originals','nibwoz-better-img') . '</span><strong>' . esc_html(size_format((int)$stats['optimized_source_bytes'])) . '</strong></div><i><b style="width:' . esc_attr((string)$sourceBar) . '%"></b></i></div><div class="nwzbi-bar-row is-optimized"><div><span>' . esc_html__('Optimized','nibwoz-better-img') . '</span><strong>' . esc_html(size_format((int)$stats['optimized_bytes'])) . '</strong></div><i><b style="width:' . esc_attr((string)$optimizedBar) . '%"></b></i></div><p class="description">' . esc_html__('Savings compare each active optimized copy with its original file. Originals without an optimized copy are not part of this percentage.','nibwoz-better-img') . '</p></section></div>';
        $html.='<div class="nwz-grid-2"><section class="nwz-card"><h3>' . esc_html__('Active features','nibwoz-better-img') . '</h3><ul class="nwz-chips">'
            . self::chip(!empty($settings['automatic']),__('Automatic optimization of new uploads','nibwoz-better-img'))
            . self::chip(!empty($settings['thumbnails']),__('WordPress sizes included','nibwoz-better-img'))
            . self::chip(!empty($settings['delivery_enabled']),__('Optimized images shown to visitors','nibwoz-better-img'))
            . self::chip(!empty($settings['cdn_hosts']),__('CDN domains recognized','nibwoz-better-img'))
            . '</ul></section><section class="nwz-card"><h3>' . esc_html__('Shortcuts','nibwoz-better-img') . '</h3><div class="nwz-actions">'
            . '<a class="button button-primary" href="' . esc_url(self::pageUrl('processing')) . '">' . self::icon('dashicons-images-alt2') . esc_html__('Processing','nibwoz-better-img') . '</a>'
            . '<a class="button" href="' . esc_url(self::pageUrl('settings')) . '">' . self::icon('dashicons-admin-settings') . esc_html__('Optimization','nibwoz-better-img') . '</a>'
            . '<a class="button" href="' . esc_url(admin_url('upload.php?mode=list')) . '">' . self::icon('dashicons-admin-media') . esc_html__('Media Library','nibwoz-better-img') . '</a></div></section></div>';
        return $html;
    }

    private static function settings(): string {
        $settings=Core::settings(); $engines=Diagnostics::engines();
        $autoLabels=[];
        foreach (['webp','avif','original'] as $format) { $autoLabels[$format]=self::automaticEngine($engines,$format); }
        $automaticEngine=$autoLabels[(string)$settings['format']]??'';
        $html=self::pageHead(__('Settings','nibwoz-better-img'),__('Optimization','nibwoz-better-img'),__('Format, engine, quality, resizing and processing.','nibwoz-better-img'));
        $html.='<form id="nwzbi-settings" class="nwzbi-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-nwz-settings><input type="hidden" name="action" value="nwzbi_action"><input type="hidden" name="do" value="save">' . wp_nonce_field('nwzbi_save','_wpnonce',true,false);
        $formats='';
        foreach (['webp'=>'WebP','avif'=>'AVIF','original'=>__('Keep original format','nibwoz-better-img')] as $value=>$label) { $formats.='<option value="' . esc_attr($value) . '"' . selected($settings['format'],$value,false) . '>' . esc_html($label) . '</option>'; }
        /* translators: %s: image engine name, such as GD or Imagick. */
        $autoLabel=$automaticEngine !== '' ? sprintf(__('Automatic - %s','nibwoz-better-img'),$automaticEngine) : __('Automatic','nibwoz-better-img');
        $engineOptions='<option value="auto" data-formats="' . esc_attr(implode(',',Diagnostics::formatsForEngine('auto'))) . '"' . selected($settings['engine'],'auto',false) . '>' . esc_html($autoLabel) . '</option>';
        foreach ($engines as $id=>$engine) {
            /* translators: %s: image engine name. */
            $label=$engine['available'] ? $engine['label'] : sprintf(__('%s — unavailable','nibwoz-better-img'),$engine['label']);
            $engineOptions.='<option value="' . esc_attr($id) . '" data-formats="' . esc_attr(implode(',',Diagnostics::formatsForEngine($id))) . '"' . selected($settings['engine'],$id,false) . disabled(!$engine['available'],true,false) . '>' . esc_html($label) . '</option>';
        }
        $current=max(1,min(4,(int)$settings['concurrency']));
        $parallel='<option value="1"' . selected($current,1,false) . '>' . esc_html__('1 at a time (recommended)','nibwoz-better-img') . '</option>';
        /* translators: %s: number of images processed at the same time. */
        for ($value=2;$value<=4;$value++) { $parallel.='<option value="' . $value . '"' . selected($current,$value,false) . '>' . esc_html(sprintf(__('%s at the same time','nibwoz-better-img'),number_format_i18n($value))) . '</option>'; }
        /* translators: %s: link to the "Re-optimize the whole library" action. */
        $hint=sprintf(esc_html__('Changing the format applies to the next conversions. To convert what already exists, use %s.','nibwoz-better-img'),'<a href="' . esc_url(self::pageUrl('processing')) . '">' . esc_html__('Re-optimize the whole library','nibwoz-better-img') . '</a>');
        $html.='<section class="nwz-card"><h3>' . esc_html__('Format and engine','nibwoz-better-img') . '</h3><div class="nwz-fields-2">'
            . self::selectField('format',__('Output format','nibwoz-better-img'),$formats,__('WebP is recommended. "Keep original format" only recompresses.','nibwoz-better-img'),' data-nwzbi-format')
            . self::selectField('engine',__('Image engine','nibwoz-better-img'),$engineOptions,__('Automatic uses the compatible engine. A fixed GD or Imagick choice never switches by itself.','nibwoz-better-img'),' data-nwzbi-engine data-auto-labels="' . esc_attr((string)wp_json_encode($autoLabels)) . '"')
            . '</div><p class="nwzbi-compat" data-nwzbi-compatibility data-state="ok" aria-live="polite">' . esc_html__('Compatibility is checked with a real encoding test on the server.','nibwoz-better-img') . '</p><p class="description">' . $hint . '</p></section>';
        $html.='<section class="nwz-card"><h3>' . esc_html__('Processing','nibwoz-better-img') . '</h3><div class="nwz-fields-2">'
            . self::selectField('concurrency',__('Images at the same time','nibwoz-better-img'),$parallel,__('Example: 20 queued with value 3 means 3 at a time. Faster, but uses more CPU and memory.','nibwoz-better-img'))
            . '</div><div class="nwzbi-toggles">'
            . self::toggle('automatic','nwzbi[automatic]',!empty($settings['automatic']),__('Optimize new uploads automatically','nibwoz-better-img'),__('Existing images are only processed with the "Optimize the whole library" button.','nibwoz-better-img'))
            . self::toggle('thumbnails','nwzbi[thumbnails]',!empty($settings['thumbnails']),__('Include the sizes generated by WordPress','nibwoz-better-img'),__('Thumbnail, medium, large and the other sizes, besides the main file.','nibwoz-better-img'))
            . '</div></section>';
        $html.='<section class="nwz-card"><h3>' . esc_html__('Quality and dimensions','nibwoz-better-img') . '</h3><div class="nwz-fields-3">'
            . self::number('quality_webp',__('WebP quality','nibwoz-better-img'),$settings,1,100,'%')
            . self::number('quality_avif',__('AVIF quality','nibwoz-better-img'),$settings,1,100,'%')
            . self::number('quality_original',__('Quality in the original format','nibwoz-better-img'),$settings,1,100,'%')
            . self::number('max_width',__('Maximum width','nibwoz-better-img'),$settings,0,20000,'px')
            . self::number('max_height',__('Maximum height','nibwoz-better-img'),$settings,0,20000,'px')
            . self::selectField('larger',__('If the optimized copy is larger','nibwoz-better-img'),'<option value="skip"' . selected($settings['larger'],'skip',false) . '>' . esc_html__('Keep the previous copy or the original','nibwoz-better-img') . '</option><option value="keep"' . selected($settings['larger'],'keep',false) . '>' . esc_html__('Keep the optimized file','nibwoz-better-img') . '</option>','')
            . '</div><p class="description">' . esc_html__('Resizing is always proportional: width and height shrink together, without distortion, for landscape and portrait images. Use 0 to leave a dimension unlimited. Original files are never resized. In original-format mode the percentage controls JPEG, WebP and AVIF; PNG stays visually lossless and may change little in size.','nibwoz-better-img') . '</p></section>';
        $html.='<section class="nwz-card nwzbi-delivery-card"><div class="nwzbi-delivery-copy"><h3>' . esc_html__('Optimized images on the site','nibwoz-better-img') . '</h3>'
            . self::toggle('delivery_enabled','nwzbi[delivery_enabled]',!empty($settings['delivery_enabled']),__('Show optimized images to visitors','nibwoz-better-img'),__('When this option is on, the site shows the optimized versions that are already ready. Images not optimized yet keep appearing normally.','nibwoz-better-img'))
            . '<p class="description">' . esc_html__('When the option is off, visitors see the original files even if optimized versions exist. The swap applies to images provided by WordPress; images inserted directly by the theme may keep using the original file. After turning it on or off, clear the site and CDN caches to see the change.','nibwoz-better-img') . '</p>'
            . '<details class="nwz-details"' . ($settings['cdn_hosts'] ? ' open' : '') . '><summary>' . esc_html__('CDN that rewrites image addresses (optional)','nibwoz-better-img') . '</summary><div class="nwz-field"><label for="nwzbi-cdn_hosts">' . esc_html__('Recognized CDN domains','nibwoz-better-img') . '</label><textarea id="nwzbi-cdn_hosts" name="nwzbi[cdn_hosts]" rows="2" spellcheck="false" placeholder="cdn.example.com" aria-describedby="nwzbi-cdn_hosts-help">' . esc_textarea(implode("\n",(array)$settings['cdn_hosts'])) . '</textarea><small id="nwzbi-cdn_hosts-help">' . esc_html__('On most sites, leave this empty: plugins such as the Bunny CDN plugin work without configuration. Fill it in only if, with delivery on, images keep being served in the original format because another plugin rewrites their address to the CDN before Better Img. One domain per line. Use only CDNs that pull files from this site; do not enter external storage (Amazon S3 or offload plugins), where the optimized files do not exist.','nibwoz-better-img') . '</small></div></details></div>'
            . '<div class="nwzbi-delivery-visual" role="img" aria-label="' . esc_attr__('Illustration: the original image is replaced by the optimized version when it exists','nibwoz-better-img') . '"><div class="nwzbi-delivery-file is-original">' . self::icon('dashicons-format-image') . '<strong>' . esc_html__('Original','nibwoz-better-img') . '</strong><small>' . esc_html__('Larger file','nibwoz-better-img') . '</small></div>' . self::icon('dashicons-arrow-right-alt') . '<div class="nwzbi-delivery-file is-optimized">' . self::icon('dashicons-format-image') . '<strong>' . esc_html_x('Optimized','single image','nibwoz-better-img') . '</strong><small>' . esc_html__('Ready for the site','nibwoz-better-img') . '</small></div></div></section></form>';
        return $html;
    }

    private static function selectField(string $field,string $label,string $options,string $help,string $attributes=''): string {
        $id='nwzbi-' . $field;
        return '<div class="nwz-field"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><select id="' . esc_attr($id) . '" name="' . esc_attr('nwzbi[' . $field . ']') . '"' . ($help !== '' ? ' aria-describedby="' . esc_attr($id . '-help') . '"' : '') . $attributes . '>' . $options . '</select>' . ($help !== '' ? '<small id="' . esc_attr($id . '-help') . '">' . esc_html($help) . '</small>' : '') . '</div>';
    }

    /** NIBWOZ UI switch; the label points only at the title so the help text is read as the description. */
    private static function toggle(string $field,string $name,bool $checked,string $title,string $help): string {
        $id='nwzbi-' . $field;
        return '<label class="nwz-toggle" for="' . esc_attr($id) . '"><input type="hidden" name="' . esc_attr($name) . '" value="0"><input type="checkbox" role="switch" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1" aria-labelledby="' . esc_attr($id . '-title') . '" aria-describedby="' . esc_attr($id . '-help') . '"' . checked($checked,true,false) . '><span class="nwz-toggle-control" aria-hidden="true"></span><span class="nwz-toggle-text"><strong id="' . esc_attr($id . '-title') . '">' . esc_html($title) . '</strong><small id="' . esc_attr($id . '-help') . '">' . esc_html($help) . '</small></span></label>';
    }

    private static function number(string $name,string $label,array $settings,int $min,int $max,string $unit): string {
        $id='nwzbi-' . $name;
        return '<div class="nwz-field"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><div class="nwz-input-unit"><input id="' . esc_attr($id) . '" type="number" name="' . esc_attr('nwzbi[' . $name . ']') . '" value="' . esc_attr((string)(int)$settings[$name]) . '" min="' . (int)$min . '" max="' . (int)$max . '" step="1"><span aria-hidden="true">' . esc_html($unit) . '</span></div></div>';
    }

    private static function counter(string $state,string $label,int $value): string {
        return '<div class="nwzbi-counter-' . esc_attr($state) . '"><span>' . esc_html($label) . '</span><strong data-nwzbi-count="' . esc_attr($state) . '">' . esc_html(number_format_i18n($value)) . '</strong></div>';
    }

    private static function monitor(array $queue): string {
        $active=!empty($queue['activity']); $progress=$queue['progress']; $counts=$queue['counts'];
        $html='<section class="nwz-card nwz-monitor nwzbi-queue-monitor' . ($active?' is-active':'') . '" data-endpoint="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr(wp_create_nonce('nwzbi_queue_status')) . '">';
        $html.='<div class="nwz-card-head"><div><h3>' . esc_html__('Processing monitor','nibwoz-better-img') . '</h3><p class="description">' . esc_html__('Updates every second while images are queued.','nibwoz-better-img') . '</p></div><span class="nwz-state"><span class="nwz-live" aria-hidden="true"></span><span data-nwzbi-activity>' . esc_html($active ? _x('Processing','queue state','nibwoz-better-img') : __('Idle','nibwoz-better-img')) . '</span></span></div>';
        $html.='<div class="nwz-counters nwzbi-counters">' . self::counter('pending',__('Pending','nibwoz-better-img'),(int)$counts['pending']) . self::counter('processing',_x('Processing','queue state','nibwoz-better-img'),(int)$counts['processing']) . self::counter('done',__('Completed','nibwoz-better-img'),(int)$counts['done']) . self::counter('failed',__('Failures','nibwoz-better-img'),(int)$counts['failed']) . '</div>';
        $html.='<div class="nwz-meter"><div class="nwz-meter-label"><strong data-nwzbi-progress-label>' . esc_html(self::progressLabel((int)$progress['finished'],(int)$progress['total'])) . '</strong><span data-nwzbi-progress-percent>' . esc_html(number_format_i18n((float)$progress['percent'],1) . '%') . '</span></div><div class="nwz-track nwzbi-track" role="progressbar" aria-label="' . esc_attr__('Round progress','nibwoz-better-img') . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr((string)$progress['percent']) . '"><span data-nwzbi-progress-bar style="width:' . esc_attr((string)$progress['percent']) . '%"></span></div></div>';
        $html.=self::sessionLine($queue['session']??[]);
        $html.='<p class="nwzbi-current"' . (empty($queue['current'])?' hidden':'') . ' data-nwzbi-current><span>' . esc_html__('Converting now','nibwoz-better-img') . '</span><span data-nwzbi-current-list>' . esc_html(implode(' · ',(array)($queue['current']??[]))) . '</span></p>';
        return $html . '</section>';
    }

    private static function sessionLine(array $session): string {
        $hidden=$session ? '' : ' hidden';
        $title=!empty($session['active']) ? __('Current round','nibwoz-better-img') : __('Last round','nibwoz-better-img');
        $files=(int)($session['files']??0); $rate=(float)($session['rate']??0);
        return '<p class="nwzbi-session"' . $hidden . ' data-nwzbi-session aria-live="off"><strong data-nwzbi-session-title>' . esc_html($title) . '</strong><span><span class="screen-reader-text">' . esc_html__('Elapsed time:','nibwoz-better-img') . ' </span><b data-nwzbi-session-elapsed>' . esc_html(self::formatElapsed((int)($session['elapsed']??0))) . '</b></span><span><b data-nwzbi-session-files>' . esc_html(number_format_i18n($files)) . '</b> ' . esc_html__('files converted','nibwoz-better-img') . '</span><span><b data-nwzbi-session-rate>' . esc_html(number_format_i18n($rate,1)) . '</b> ' . esc_html__('files/s','nibwoz-better-img') . '</span><span data-nwzbi-session-eta hidden></span></p>';
    }

    private static function processing(): string {
        global $wpdb;
        $stats=Metrics::snapshot(); $queue=self::queuePayload(); $pending=(int)$queue['counts']['pending']; $processing=(int)$queue['counts']['processing']; $failed=(int)$queue['counts']['failed']; $stalled=$pending>0 && $processing===0 && Queue::has_stalled_pending();
        $html=self::pageHead(__('Library','nibwoz-better-img'),__('Processing','nibwoz-better-img'),__('Optimize one image from the Media Library or process the whole library.','nibwoz-better-img'));
        $html.='<section class="nwz-card"><h3>' . esc_html__('Bulk actions','nibwoz-better-img') . '</h3><p>' . esc_html__('The whole library is only queued after this manual action. Each active optimized copy is replaced only after the new file is validated.','nibwoz-better-img') . '</p><div class="nwz-actions">'
            . self::postButton('queue_all',$stats['optimized_files'] ? __('Re-optimize the whole library','nibwoz-better-img') : __('Optimize the whole library','nibwoz-better-img'),'button button-primary','dashicons-controls-play',' data-nwzbi-async="queue_all"')
            . '<span class="nwzbi-when-active"' . (!empty($queue['activity'])?'':' hidden') . ' data-nwzbi-when-active>' . self::postButton('stop',__('Stop conversion','nibwoz-better-img'),'button','dashicons-controls-pause') . '</span>'
            . ($stalled ? self::postButton('work',__('Resume queue','nibwoz-better-img'),'button','dashicons-controls-repeat') : '')
            . ($failed ? self::postButton('retry',__('Retry failures','nibwoz-better-img'),'button','dashicons-image-rotate') : '')
            . '<a class="button" href="' . esc_url(admin_url('upload.php?mode=list')) . '">' . self::icon('dashicons-admin-media') . esc_html__('Open the Media Library','nibwoz-better-img') . '</a></div><p class="description">' . esc_html__('Stopping removes the pending tasks. An image that is already being written finishes safely; the originals are not changed.','nibwoz-better-img') . '</p>'
            . ($stalled ? '<p class="description">' . esc_html__('The queue has been pending for more than two minutes without an active worker. "Resume queue" runs a recovery round locally.','nibwoz-better-img') . '</p>' : '') . '</section>';
        $html.=self::monitor($queue);
        $errors=$wpdb->get_results($wpdb->prepare("SELECT attachment_id,error,attempts,updated_at FROM %i WHERE state='failed' ORDER BY updated_at DESC LIMIT 10",Core::table('queue'))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only list of recent failures from the plugin's own table.
        if ($errors) {
            $html.='<section class="nwz-card"><h3>' . esc_html__('Recent failures','nibwoz-better-img') . '</h3><table class="widefat striped nwzbi-table"><thead><tr><th>' . esc_html__('Attachment','nibwoz-better-img') . '</th><th>' . esc_html__('Attempts','nibwoz-better-img') . '</th><th>' . esc_html__('Error','nibwoz-better-img') . '</th><th>' . esc_html__('Updated (UTC)','nibwoz-better-img') . '</th></tr></thead><tbody>';
            foreach ($errors as $error) { $html.='<tr><td>#' . esc_html((string)$error->attachment_id) . '</td><td>' . esc_html((string)$error->attempts) . '</td><td>' . esc_html(wp_specialchars_decode((string)$error->error,ENT_QUOTES)) . '</td><td>' . esc_html((string)$error->updated_at) . '</td></tr>'; }
            $html.='</tbody></table></section>';
        }
        /* translators: %s: confirmation phrase to type. */
        $confirmLabel=sprintf(esc_html__('Type %s to confirm','nibwoz-better-img'),'<strong>' . esc_html(self::confirmPhrase()) . '</strong>');
        $html.='<section class="nwz-card nwz-danger"><h3>' . esc_html__('Delete all optimized images','nibwoz-better-img') . '</h3><p>' . esc_html__('Removes only the optimized copies registered by the plugin, including legacy structures. The original files and the WordPress metadata stay intact. The site goes back to serving the originals.','nibwoz-better-img') . '</p><form class="nwz-danger-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="nwzbi_action"><input type="hidden" name="do" value="delete_all">' . wp_nonce_field('nwzbi_delete_all','_wpnonce',true,false) . '<div class="nwz-danger-field"><label for="nwzbi-confirm">' . $confirmLabel . '</label><input id="nwzbi-confirm" type="text" name="confirm" required autocomplete="off"></div><button class="button nwz-button-danger" type="submit" data-nwz-busy-on-submit>' . self::icon('dashicons-trash') . esc_html__('Delete optimized images','nibwoz-better-img') . '</button></form></section>';
        return $html;
    }

    private static function badge(bool $ok,string $text): string {
        return Ui::badge($ok,$text,$text);
    }

    /** @param array<int, array{0: string, 1: string}> $rows Value cells hold escaped HTML. */
    private static function dl(array $rows): string {
        return self::capture(static fn() => Ui::dl($rows));
    }

    private static function diagnostics(): string {
        global $wpdb;
        $server=Diagnostics::server(); $engines=Diagnostics::engines(); $extensions=Diagnostics::extensions(); $settings=Core::settings();
        $available=__('Available','nibwoz-better-img'); $unavailable=__('Unavailable','nibwoz-better-img');
        $html=self::pageHead(__('Environment','nibwoz-better-img'),__('Diagnostics','nibwoz-better-img'),__('Capabilities detected on the server and the recent processing history.','nibwoz-better-img'));
        $cron=defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $rows=[
            [__('Web server','nibwoz-better-img'),esc_html($server['type'] !== '' ? $server['type'] : __('Not identified','nibwoz-better-img'))],
            [__('Identification','nibwoz-better-img'),esc_html($server['software'] !== '' ? $server['software'] : __('Not reported','nibwoz-better-img'))],
            ['PHP',esc_html($server['php'] . ' · ' . $server['sapi'])],
            [__('System','nibwoz-better-img'),esc_html($server['os'])],
            [__('Database','nibwoz-better-img'),esc_html($server['database'])],
            [__('Named locks','nibwoz-better-img'),self::badge($server['named_locks'],$server['named_locks'] ? $available : __('Unavailable; processing is blocked','nibwoz-better-img'))],
            ['WP-Cron',self::badge(!$cron,$cron ? __('Disabled; a real cron job is required','nibwoz-better-img') : __('Active','nibwoz-better-img'))],
            self::workerRouteRow(),
        ];
        $extensionRows=[];
        foreach ($extensions as $name=>$loaded) { $extensionRows[]=[strtoupper($name),self::badge($loaded,$loaded ? $available : $unavailable)]; }
        $html.='<div class="nwz-grid-2"><section class="nwz-card"><h3>' . esc_html__('Server','nibwoz-better-img') . '</h3>' . self::dl($rows) . '</section><section class="nwz-card"><h3>' . esc_html__('PHP extensions','nibwoz-better-img') . '</h3>' . self::dl($extensionRows) . '</section></div>';
        $html.='<section class="nwz-card"><h3>' . esc_html__('Image engines','nibwoz-better-img') . '</h3><p class="description">' . esc_html__('GD and Imagick are the native engines exposed by WordPress. Other converters need a dedicated integration and are not offered as a fake option.','nibwoz-better-img') . '</p><div class="nwzbi-engines">';
        foreach ($engines as $id=>$engine) {
            $html.='<article><h4>' . esc_html($engine['label']) . ($settings['engine']===$id ? ' <span class="nwz-pill is-ok">' . esc_html__('Selected','nibwoz-better-img') . '</span>' : '') . '</h4>' . self::dl([
                [__('Status','nibwoz-better-img'),self::badge($engine['available'],$engine['available'] ? $available : $unavailable)],
                [__('Version','nibwoz-better-img'),esc_html($engine['version'] ?: __('Not reported','nibwoz-better-img'))],
                [__('Confirmed writing','nibwoz-better-img'),esc_html($engine['formats'] ? implode(', ',$engine['formats']) : __('none','nibwoz-better-img'))],
                [__('Announced reading','nibwoz-better-img'),esc_html($engine['read_formats'] ? implode(', ',$engine['read_formats']) : __('none detected','nibwoz-better-img'))],
            ]) . '<p class="description">' . esc_html($id==='gd' ? __('Uses the GD functions available in this installation. Reading and writing support may differ.','nibwoz-better-img') : __('Uses the WordPress Imagick editor and the codecs available in this server\'s ImageMagick.','nibwoz-better-img')) . '</p></article>';
        }
        $html.='</div></section>';
        $runs=$wpdb->get_results($wpdb->prepare('SELECT run_id,MAX(expected_workers) expected,COUNT(*) started,MAX(started_us) started_us,SUM(tasks) tasks FROM %i GROUP BY run_id ORDER BY MAX(id) DESC LIMIT 10',Core::table('workers'))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only processing history from the plugin's own table.
        $measured=self::measuredConcurrency();
        $html.='<section class="nwz-card"><h3>' . esc_html__('Recent processing','nibwoz-better-img') . '</h3><p class="description">' . esc_html__('"Measured simultaneous maximum" counts how many conversions really overlapped in time. If it stays below the configured value, the hosting is queuing the requests and converting fewer images at the same time.','nibwoz-better-img') . '</p>';
        if (!$runs) { $html.='<p>' . esc_html__('No processing recorded in the last seven days.','nibwoz-better-img') . '</p>'; }
        else {
            $html.='<table class="widefat striped nwzbi-table"><thead><tr><th>' . esc_html__('Start (UTC)','nibwoz-better-img') . '</th><th>' . esc_html__('At the same time (configured)','nibwoz-better-img') . '</th><th>' . esc_html__('Processes started','nibwoz-better-img') . '</th><th>' . esc_html__('Measured simultaneous maximum','nibwoz-better-img') . '</th><th>' . esc_html__('Images','nibwoz-better-img') . '</th><th>' . esc_html__('Round','nibwoz-better-img') . '</th></tr></thead><tbody>';
            foreach ($runs as $run) { $html.='<tr><td>' . esc_html(gmdate('Y-m-d H:i:s',intdiv((int)$run->started_us,1000000))) . '</td><td>' . esc_html((string)$run->expected) . '</td><td>' . esc_html((string)$run->started) . '</td><td>' . esc_html((string)($measured[(string)$run->run_id]??0)) . '</td><td>' . esc_html((string)$run->tasks) . '</td><td><code>' . esc_html(substr((string)$run->run_id,0,8)) . '</code></td></tr>'; }
            $html.='</tbody></table>';
        }
        return $html . '</section>';
    }

    private static function workerRouteRow(): array {
        $route=get_option('nwzbi_worker_route',[]); $dispatch=(int)get_option('nwzbi_last_dispatch',0);
        $labels=['cloudflare'=>__('through Cloudflare','nibwoz-better-img'),'proxy'=>__('through a proxy or CDN','nibwoz-better-img'),'direct'=>__('directly to the server','nibwoz-better-img')];
        $received=is_array($route) ? (int)($route['at']??0) : 0;
        $term=__('Internal queue calls','nibwoz-better-img');
        if ($dispatch > 0 && $dispatch > $received + 2*MINUTE_IN_SECONDS) {
            /* translators: %s: date and time of the last dispatch. */
            return [$term,self::badge(false,sprintf(__('Dispatched on %s without any call received. A firewall, Cloudflare (Bot Fight Mode, WAF) or a wp-admin access block may be stopping the server\'s own requests; use "Resume queue" and allow admin-post.php for the server IP.','nibwoz-better-img'),self::dateTime($dispatch)))];
        }
        if ($received < 1) { return [$term,esc_html__('Not recorded yet','nibwoz-better-img')]; }
        /* translators: 1: date and time, 2: route, such as "through Cloudflare". */
        return [$term,self::badge(true,sprintf(__('Received on %1$s, %2$s','nibwoz-better-img'),self::dateTime($received),$labels[(string)($route['via']??'')]??__('unknown route','nibwoz-better-img')))];
    }

    private static function about(): string {
        $settings=Core::settings();
        $external=' target="_blank" rel="noopener noreferrer"';
        $html=self::pageHead(__('Information','nibwoz-better-img'),__('About the plugin','nibwoz-better-img'),__('Development, environment, support and storage of NIBWOZ Better Img.','nibwoz-better-img'));
        /* translators: 1: plugin version, 2: NIBWOZ UI kit version. */
        $versions=sprintf(__('Version %1$s · NIBWOZ UI %2$s','nibwoz-better-img'),VERSION,Ui::VERSION);
        $html.='<div class="nwz-about-hero"><img class="nwz-wordmark is-large" src="' . esc_url(plugins_url('assets/nibwoz-ui/nibwoz-wordmark.svg',FILE)) . '" alt="NIBWOZ" width="208" height="55"><div><h2>NIBWOZ Better Img</h2><p>' . esc_html__('Non-destructive optimization for the WordPress Media Library.','nibwoz-better-img') . '</p><p>' . esc_html($versions) . '</p></div></div><div class="nwz-grid-2">';
        $html.='<section class="nwz-card"><h3>' . esc_html__('Development','nibwoz-better-img') . '</h3>' . self::dl([[__('Developer','nibwoz-better-img'),'Brinsley Aragão'],[__('Company','nibwoz-better-img'),'NIBWOZ'],[__('Plugin version','nibwoz-better-img'),esc_html(VERSION)],[__('Interface kit','nibwoz-better-img'),esc_html('NIBWOZ UI ' . Ui::VERSION)]]) . '</section>';
        $html.='<section class="nwz-card"><h3>' . esc_html__('Environment','nibwoz-better-img') . '</h3>' . self::dl([['WordPress',esc_html((string)get_bloginfo('version'))],['PHP',esc_html(PHP_VERSION)],[__('Installation','nibwoz-better-img'),esc_html(is_multisite() ? __('Multisite','nibwoz-better-img') : __('Single site','nibwoz-better-img'))]]) . '</section>';
        $html.='<section class="nwz-card"><h3>' . esc_html__('About NIBWOZ','nibwoz-better-img') . '</h3><p>' . esc_html__('Hosting, WordPress, domains, development and technical support.','nibwoz-better-img') . '</p><div class="nwz-actions"><a class="button button-primary" href="https://nibwoz.com/"' . $external . '>' . self::icon('dashicons-admin-site-alt3') . esc_html__('NIBWOZ website','nibwoz-better-img') . '</a><a class="button" href="https://nibwoz.com/sobre/"' . $external . '>' . self::icon('dashicons-building') . esc_html__('About the company','nibwoz-better-img') . '</a></div></section>';
        $html.='<section class="nwz-card"><h3>' . esc_html__('Support and documentation','nibwoz-better-img') . '</h3><p>' . esc_html__('For questions about the plugin or the services, use the company\'s official channels.','nibwoz-better-img') . '</p><p><a href="mailto:falecom@nibwoz.com">falecom@nibwoz.com</a></p><div class="nwz-actions"><a class="button" href="https://central.nibwoz.com/knowledgebase"' . $external . '>' . self::icon('dashicons-book') . esc_html__('Knowledge base','nibwoz-better-img') . '</a><a class="button" href="https://central.nibwoz.com/submitticket.php"' . $external . '>' . self::icon('dashicons-sos') . esc_html__('Open a ticket','nibwoz-better-img') . '</a></div></section>';
        $html.='<section class="nwz-card nwz-span-all"><h3>' . esc_html__('Storage and delivery','nibwoz-better-img') . '</h3><ul class="nwz-list"><li>' . esc_html__('The original files and the Media Library metadata are not changed.','nibwoz-better-img') . '</li><li>' . esc_html__('Optimized copies are stored in uploads/nwz-better-img/year/month, keeping any existing subfolders.','nibwoz-better-img') . '</li><li>' . esc_html__('Settings and records stay in this WordPress site. The plugin sends no telemetry to NIBWOZ.','nibwoz-better-img') . '</li><li>' . esc_html__('Public delivery is optional, off by default and does not depend on .htaccess rules.','nibwoz-better-img') . '</li></ul><p><a href="https://nibwoz.com/politica-de-privacidade/"' . $external . '>' . esc_html__('NIBWOZ privacy policy','nibwoz-better-img') . '</a></p></section>';
        $html.='<section class="nwz-card nwz-card-critical nwz-span-all"><h3>' . esc_html__('Uninstall and database','nibwoz-better-img') . '</h3><form id="nwzbi-uninstall" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-nwz-settings><input type="hidden" name="action" value="nwzbi_action"><input type="hidden" name="do" value="save_uninstall">' . wp_nonce_field('nwzbi_save_uninstall','_wpnonce',true,false)
            . self::toggle('uninstall_cleanup','uninstall_cleanup',!empty($settings['uninstall_cleanup']),__('Delete all data on uninstall','nibwoz-better-img'),__('Removes the settings, queues, records and optimized copies created by the plugin, only when it is deleted from the Plugins screen.','nibwoz-better-img'))
            . '<p class="description">' . esc_html__('Off is the safe default: deactivating or uninstalling keeps the data for a possible reinstall. On runs the complete, verified cleanup; the original WordPress files are always preserved.','nibwoz-better-img') . '</p></form></section></div>';
        return $html;
    }

    public static function queueStatus(): void {
        check_ajax_referer('nwzbi_queue_status','nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message'=>__('You do not have permission to do this.','nibwoz-better-img')],403); }
        $payload=self::queuePayload();
        if ((int)$payload['counts']['pending']>0 && (int)$payload['counts']['processing']===0 && (int)$payload['active_workers']===0) { Queue::dispatch(); }
        wp_send_json_success($payload);
    }


    public static function queueAllAsync(): void {
        check_ajax_referer('nwzbi_queue_all');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message'=>__('You do not have permission to do this.','nibwoz-better-img')],403); }
        $skipped=0; $count=Queue::scan(true,$skipped);
        if ($count < 0) { wp_send_json_error(['message'=>__('The library scan failed. Nothing was deleted; please try again.','nibwoz-better-img')],500); }
        /* translators: %s: number of images. */
        $message=$count>0 ? sprintf(_n('%s image added to the queue.','%s images added to the queue.',$count,'nibwoz-better-img'),number_format_i18n($count)) : __('No eligible image was found in the Media Library.','nibwoz-better-img');
        $data=['queued'=>$count,'skipped'=>$skipped,'message'=>$message,'status'=>self::queuePayload()];
        if ($count < 1) { wp_send_json_success($data); }
        $finish=function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request' : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : '');
        if ($finish === '') { Queue::dispatch(); wp_send_json_success($data); }
        ignore_user_abort(true);
        if (!headers_sent()) { status_header(200); header('Content-Type: application/json; charset=' . get_option('blog_charset')); nocache_headers(); }
        echo wp_json_encode(['success'=>true,'data'=>$data]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON body of an AJAX response, sent before the request is finished so the workers start after the reply.
        $finish();
        Queue::dispatch();
        exit;
    }


    private static function queuePayload(): array {
        global $wpdb;
        $queue=Core::table('queue'); $states=['pending'=>0,'processing'=>0,'done'=>0,'failed'=>0];
        $session=get_option('nwzbi_session',[]); $since=is_array($session) && !empty($session['started']) ? gmdate('Y-m-d H:i:s',(int)floor((float)$session['started'])) : '1970-01-01 00:00:00';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT state,COUNT(*) total FROM %i WHERE state IN ('pending','processing') OR updated_at>=%s GROUP BY state",$queue,$since),ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live queue state polled by the monitor; caching would be wrong.
        foreach ((array)$rows as $row) { $states[(string)$row['state']]=(int)$row['total']; }
        $current=[];
        $processing=$wpdb->get_col($wpdb->prepare("SELECT attachment_id FROM %i WHERE state='processing' ORDER BY updated_at ASC LIMIT 4",$queue)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live queue state polled by the monitor; caching would be wrong.
        foreach ((array)$processing as $attachment) {
            $file=get_attached_file((int)$attachment);
            $current[]=is_string($file) && $file !== '' ? wp_basename($file) : '#' . (int)$attachment;
        }
        $workerCutoff=(int)round((microtime(true)-6*MINUTE_IN_SECONDS)*1000000);
        $activeWorkers=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE ended_us=0 AND started_us>=%d',Core::table('workers'),$workerCutoff)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live worker state polled by the monitor; caching would be wrong.
        $total=array_sum($states); $finished=$states['done']+$states['failed']; $activity=$activeWorkers>0 || $states['processing']>0 || $states['pending']>0;
        return ['counts'=>$states,'active_workers'=>$activeWorkers,'activity'=>$activity,'session'=>Queue::session($activity),'current'=>$current,'progress'=>['total'=>$total,'finished'=>$finished,'percent'=>$total>0?round(($finished/$total)*100,1):0]];
    }


    private static function progressLabel(int $finished,int $total): string {
        if ($total < 1) { return __('No images in the queue','nibwoz-better-img'); }
        /* translators: 1: number of finished images, 2: total images in the round. */
        return sprintf(_n('%1$s of %2$s image','%1$s of %2$s images',$total,'nibwoz-better-img'),number_format_i18n($finished),number_format_i18n($total));
    }


    private static function formatElapsed(int $seconds): string {
        $hours=intdiv($seconds,3600); $minutes=intdiv($seconds%3600,60); $rest=$seconds%60;
        return $hours>0 ? sprintf('%d:%02d:%02d',$hours,$minutes,$rest) : sprintf('%d:%02d',$minutes,$rest);
    }


    private static function automaticEngine(array $engines,string $format): string {
        $required=Diagnostics::requiredFormats($format);
        $order=$format === 'avif' ? ['gd','imagick'] : ['imagick','gd'];
        foreach ($order as $id) {
            if (empty($engines[$id]['available'])) { continue; }
            if (!array_diff($required,(array)$engines[$id]['formats'])) { return (string)$engines[$id]['label']; }
        }
        return '';
    }


    private static function measuredConcurrency(): array {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT run_id,started_us,ended_us FROM %i WHERE ended_us>started_us AND run_id IN (SELECT run_id FROM (SELECT run_id FROM %i GROUP BY run_id ORDER BY MAX(id) DESC LIMIT 10) recent)',Core::table('attempts'),Core::table('workers'))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only processing history from the plugin's own tables.
        $events=[];
        foreach ((array)$rows as $row) {
            $events[(string)$row->run_id][]=[(int)$row->started_us,1];
            $events[(string)$row->run_id][]=[(int)$row->ended_us,-1];
        }
        $result=[];
        foreach ($events as $run=>$items) {
            usort($items,static fn(array $a,array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
            $active=0; $peak=0;
            foreach ($items as [, $delta]) { $active+=$delta; $peak=max($peak,$active); }
            $result[$run]=$peak;
        }
        return $result;
    }
}
