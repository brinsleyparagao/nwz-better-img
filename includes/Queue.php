<?php
/**
 * Persistent queue and signed loopback workers.
 *
 * @package NIBWOZ\BetterImg
 */

namespace NIBWOZ\BetterImg;

if (!defined('ABSPATH')) { exit; }

final class Queue {
    private const ROUND_IMAGES=25;
    private const ROUND_SECONDS=25;

    public static function parallel(): int {
        return max(1,min(4,(int)Core::settings()['concurrency']));
    }

    public static function boot(): void {
        add_action('nwzbi_tick',[self::class,'tick']);
        add_action('nwzbi_resume',[self::class,'tick']);
        add_action('admin_post_nwzbi_worker',[self::class,'handle_worker']);
        add_action('admin_post_nopriv_nwzbi_worker',[self::class,'handle_worker']);
        add_filter('cron_schedules',static function ($schedules) {
            $schedules['nwzbi_minute']=['interval'=>60,'display'=>'NIBWOZ Better Img'];
            return $schedules;
        });
        if (!wp_next_scheduled('nwzbi_tick')) { wp_schedule_event(time()+60,'nwzbi_minute','nwzbi_tick'); }
    }

    public static function enqueue(int $attachment,bool $force=false,bool $verified=false): bool {
        global $wpdb;
        if (!$verified && !Processor::eligible($attachment)) { return false; }
        delete_transient('nwzbi_stop_requested');
        self::startSession();
        $result=$wpdb->query($wpdb->prepare("INSERT INTO %i (attachment_id,state,attempts,lease_until,error,updated_at) VALUES (%d,'pending',0,NULL,NULL,%s) ON DUPLICATE KEY UPDATE state=IF(state='processing',state,'pending'),attempts=IF(state='processing',attempts,0),lease_until=IF(state='processing',lease_until,NULL),error=IF(state='processing',error,NULL),updated_at=VALUES(updated_at)",Core::table('queue'),$attachment,current_time('mysql',true))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin's own queue table.
        return $result !== false;
    }

    private static function startSession(): void {
        global $wpdb;
        $session=get_option('nwzbi_session',[]);
        if (is_array($session) && !empty($session['started']) && empty($session['ended'])) {
            $busy=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE state IN ('pending','processing')",Core::table('queue'))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live queue state; caching would be wrong.
            if ($busy > 0 || $wpdb->last_error) { return; }
        }
        update_option('nwzbi_session',['started'=>microtime(true),'ended'=>0],false);
    }

    public static function session(bool $active): array {
        global $wpdb;
        $session=get_option('nwzbi_session',[]);
        if (!is_array($session) || empty($session['started'])) { return []; }
        $started=(float)$session['started']; $ended=(float)($session['ended']??0);
        if (!$active && !$ended) {
            $last=(int)$wpdb->get_var($wpdb->prepare('SELECT MAX(ended_us) FROM %i WHERE started_us>=%d',Core::table('attempts'),(int)floor($started*1000000))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live run state from the plugin's own table.
            $ended=$last > 0 ? $last/1000000 : microtime(true);
            update_option('nwzbi_session',['started'=>$started,'ended'=>$ended],false);
        }
        if ($active) { $ended=0; }
        $elapsed=max(0,(int)round(($ended ?: microtime(true))-$started));
        $files=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE updated_at>=%s',Core::table('assets'),gmdate('Y-m-d H:i:s',(int)floor($started)))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live run state from the plugin's own table.
        return ['active'=>$active,'elapsed'=>$elapsed,'files'=>$files,'rate'=>$elapsed>0 ? round($files/$elapsed,1) : (float)$files];
    }

    public static function enqueueSelection(array $attachments): int {
        if (self::halt() < 0) { return -1; }
        $queued=0;
        foreach (array_values(array_unique(array_map('absint',$attachments))) as $attachment) {
            if ($attachment > 0 && self::enqueue($attachment,true)) { ++$queued; }
        }
        return $queued;
    }

    public static function scan(bool $manual=false,?int &$skipped=null): int {
        global $wpdb;
        $skipped=0;
        if (!$manual || !is_admin() || !current_user_can('manage_options')) { return -1; }
        delete_transient('nwzbi_stop_requested');
        $page=1; $added=0;
        do {
            $ids=get_posts(['post_type'=>'attachment','post_mime_type'=>Processor::SOURCE_MIMES,'post_status'=>'inherit','fields'=>'ids','posts_per_page'=>200,'paged'=>$page,'orderby'=>'ID','order'=>'ASC','no_found_rows'=>true]);
            if (!is_array($ids) || $wpdb->last_error) { return -1; }
            update_postmeta_cache($ids);
            foreach ($ids as $id) {
                if (!Processor::eligible((int)$id)) { ++$skipped; continue; }
                if (!self::enqueue((int)$id,true,true)) { return -1; }
                ++$added;
            }
            ++$page;
        } while (count($ids) === 200);
        return $added;
    }

    public static function halt(): int {
        global $wpdb;
        set_transient('nwzbi_stop_requested',1,0);
        foreach (['nwzbi_continue','nwzbi_resume'] as $hook) { wp_clear_scheduled_hook($hook); }
        delete_transient('nwzbi_dispatch_lease');
        $removed=$wpdb->query($wpdb->prepare("DELETE FROM %i WHERE state='pending'",Core::table('queue'))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin's own queue table.
        return $removed === false ? -1 : (int)$removed;
    }

    private static function stopRequested(): bool {
        return (bool)get_transient('nwzbi_stop_requested');
    }

    public static function tick(): void {
        global $wpdb;
        $queue=Core::table('queue'); $now=current_time('mysql',true);
        $cutoff=(int)round((microtime(true)-7*DAY_IN_SECONDS)*1000000);
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE started_us<%d LIMIT 1000',Core::table('attempts'),$cutoff)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prunes the plugin's own log table.
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE started_us<%d LIMIT 1000',Core::table('workers'),$cutoff)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prunes the plugin's own log table.
        Cleanup::staleTemporaries();
        if (self::stopRequested()) {
            $wpdb->query($wpdb->prepare("UPDATE %i SET state='failed',lease_until=NULL,error=%s,updated_at=UTC_TIMESTAMP() WHERE state='processing' AND lease_until<%s",$queue,__('Processing stopped by the administrator.','nibwoz-better-img'),$now)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin's own queue table.
            return;
        }
        $wpdb->query($wpdb->prepare("UPDATE %i SET state='pending',lease_until=NULL WHERE state='processing' AND lease_until<%s AND attempts<3",$queue,$now)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin's own queue table.
        $wpdb->query($wpdb->prepare("UPDATE %i SET state='failed',lease_until=NULL,error=%s WHERE state='processing' AND lease_until<%s AND attempts>=3",$queue,__('Processing time exceeded after three attempts.','nibwoz-better-img'),$now)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin's own queue table.
        self::dispatch();
    }

    public static function retry_failed(): int {
        global $wpdb;
        delete_transient('nwzbi_stop_requested');
        $changed=$wpdb->query($wpdb->prepare("UPDATE %i SET state='pending',attempts=0,lease_until=NULL,error=NULL WHERE state='failed'",Core::table('queue'))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin's own queue table.
        return $changed === false ? -1 : (int)$changed;
    }

    private static function count(string $state): int {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE state=%s',Core::table('queue'),$state)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live queue state; caching would be wrong.
    }

    public static function dispatch(): void {
        global $wpdb;
        if (self::stopRequested()) { return; }
        $lock=Core::lockPrefix() . '_dispatch';
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)) !== 1) { return; } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL named lock that prevents duplicate dispatches.
        try {
            $now=microtime(true); $lease=(float)get_transient('nwzbi_dispatch_lease');
            if ($lease>$now) { return; }
            $activeCutoff=(int)round(($now-6*MINUTE_IN_SECONDS)*1000000);
            $active=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE ended_us=0 AND started_us>=%d',Core::table('workers'),$activeCutoff)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live worker state; caching would be wrong.
            $processing=self::count('processing');
            if ($wpdb->last_error || $active || $processing) { return; }
            $pending=self::count('pending');
            if ($wpdb->last_error || !$pending) { return; }
            set_transient('nwzbi_dispatch_lease',$now+3,10);
            $workers=min(self::parallel(),$pending);
            $quota=self::ROUND_IMAGES;
            $run=wp_generate_uuid4(); $expires=time()+120;
            $launched=0;
            $sslverify=apply_filters('https_local_ssl_verify',false); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter for loopback requests.
            for ($worker=0;$worker<$workers;$worker++) {
                $body=['run'=>$run,'worker'=>(string)$worker,'quota'=>(string)$quota,'expected'=>(string)$workers,'expires'=>(string)$expires];
                $body['signature']=self::signature($run,$worker,$quota,$workers,$expires);
                $response=wp_remote_post(admin_url('admin-post.php?action=nwzbi_worker'),['body'=>$body,'blocking'=>false,'timeout'=>1,'redirection'=>0,'sslverify'=>$sslverify]);
                if (!is_wp_error($response)) { ++$launched; }
            }
            update_option('nwzbi_last_dispatch',time(),false);
            wp_schedule_single_event(time()+($launched<$workers ? 5 : 15),'nwzbi_resume',[$run]);
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the dispatch lock.
    }

    private static function signature(string $run,int $worker,int $quota,int $expected,int $expires): string {
        return hash_hmac('sha256',$run . '|' . $worker . '|' . $quota . '|' . $expected . '|' . $expires,wp_salt('auth'));
    }

    private static function field(string $name): string {
        return isset($_POST[$name]) && is_string($_POST[$name]) ? sanitize_text_field(wp_unslash($_POST[$name])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Loopback worker request authenticated by an HMAC signature checked in handle_worker().
    }

    public static function handle_worker(): void {
        $method=isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ($method !== 'POST') { status_header(405); exit; }
        $run=self::field('run'); $worker=self::field('worker'); $quota=self::field('quota');
        $expected=self::field('expected'); $expires=self::field('expires'); $signature=self::field('signature');
        if (!preg_match('/^[a-f0-9-]{36}$/D',$run) || !preg_match('/^[0-3]$/D',$worker) || !preg_match('/^[1-9][0-9]{0,2}$/D',$quota)
            || !preg_match('/^[1-4]$/D',$expected) || (int)$worker>=(int)$expected || !preg_match('/^[0-9]{10}$/D',$expires)
            || (int)$quota>100 || (int)$expires<time() || (int)$expires>time()+120 || !preg_match('/^[a-f0-9]{64}$/D',$signature)
            || !hash_equals(self::signature($run,(int)$worker,(int)$quota,(int)$expected,(int)$expires),$signature)) {
            status_header(403); exit;
        }
        ignore_user_abort(true);
        $route=!empty($_SERVER['HTTP_CF_RAY']) ? 'cloudflare' : ((!empty($_SERVER['HTTP_X_FORWARDED_FOR']) || !empty($_SERVER['HTTP_VIA'])) ? 'proxy' : 'direct');
        update_option('nwzbi_worker_route',['at'=>time(),'via'=>$route],false);
        self::work($run,(int)$worker,(int)$quota,(int)$expected);
        status_header(204); exit;
    }

    public static function has_stalled_pending(): bool {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE state='pending' AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE)",Core::table('queue')))>0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live queue state; caching would be wrong.
    }

    public static function recover(): void {
        if (self::stopRequested()) { return; }
        $pending=self::count('pending');
        if (!$pending) { return; }
        self::work(wp_generate_uuid4(),0,min(self::ROUND_IMAGES,$pending),1);
    }

    private static function work(string $run,int $worker,int $quota,int $expected=1): void {
        global $wpdb;
        if (self::stopRequested()) { return; }
        $workers=Core::table('workers'); $started=(int)round(microtime(true)*1000000);
        if ($wpdb->insert($workers,['run_id'=>$run,'worker_id'=>$worker,'expected_workers'=>$expected,'started_us'=>$started,'ended_us'=>0,'tasks'=>0]) !== 1) { return; } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Writes to the plugin's own log table.
        $rowId=(int)$wpdb->insert_id; $count=0; $deadline=microtime(true)+self::ROUND_SECONDS;
        try {
            for ($i=0;$i<$quota;$i++) {
                if (self::stopRequested() || microtime(true)>=$deadline) { break; }
                if (function_exists('set_time_limit')) { @set_time_limit(300); } // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Background worker: one large image may need more than the default limit; each round stops after 25 seconds.
                if (!self::one($run,$worker)) { break; }
                ++$count;
            }
        }
        finally {
            $wpdb->update($workers,['ended_us'=>(int)round(microtime(true)*1000000),'tasks'=>$count],['id'=>$rowId]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin's own log table.
            self::continueRun($run);
        }
    }

    private static function continueRun(string $run): void {
        global $wpdb;
        if (self::stopRequested()) { delete_transient('nwzbi_dispatch_lease'); return; }
        $summary=$wpdb->get_row($wpdb->prepare('SELECT COUNT(*) started,SUM(CASE WHEN ended_us>0 THEN 1 ELSE 0 END) ended,MAX(expected_workers) expected FROM %i WHERE run_id=%s',Core::table('workers'),$run),ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live worker state; caching would be wrong.
        if (!$summary || $wpdb->last_error) { return; }
        if ((int)$summary['started'] < (int)$summary['expected'] || (int)$summary['ended'] < (int)$summary['expected']) { return; }
        delete_transient('nwzbi_dispatch_lease');
        self::dispatch();
    }

    private static function lock(string $key): bool {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key)) === 1; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL named lock for worker slots and attachments.
    }

    private static function unlock(string $key): void {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases a MySQL named lock.
    }

    private static function one(string $run,int $worker): bool {
        global $wpdb;
        if (self::stopRequested()) { return false; }
        $queue=Core::table('queue'); $max=self::parallel();
        $prefix=Core::lockPrefix(); $slot='';
        for ($i=0;$i<$max;$i++) {
            if (self::lock($prefix . '_slot_' . $i)) { $slot=$prefix . '_slot_' . $i; break; }
        }
        if (!$slot) { return false; }
        $attachmentLock=''; $taskId=0;
        try {
            $candidates=$wpdb->get_results($wpdb->prepare("SELECT id,attachment_id FROM %i WHERE state='pending' ORDER BY id ASC LIMIT 20",$queue)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live queue state; caching would be wrong.
            foreach ((array)$candidates as $candidate) {
                $key=$prefix . '_attachment_' . (int)$candidate->attachment_id;
                if (!self::lock($key)) { continue; }
                $claimed=$wpdb->query($wpdb->prepare("UPDATE %i SET state='processing',attempts=attempts+1,lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),updated_at=UTC_TIMESTAMP() WHERE id=%d AND state='pending'",$queue,(int)$candidate->id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic claim in the plugin's own queue table.
                if ($claimed) { $attachmentLock=$key; $taskId=(int)$candidate->id; break; }
                self::unlock($key);
            }
            if (!$taskId) { return false; }
            $task=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d',$queue,$taskId)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live queue state; caching would be wrong.
            if (!$task) { return false; }
            $attemptId=0; $attempts=Core::table('attempts');
            if ($run && $wpdb->insert($attempts,['run_id'=>$run,'worker_id'=>$worker,'task_id'=>$taskId,'attachment_id'=>(int)$task->attachment_id,'started_us'=>(int)round(microtime(true)*1000000),'ended_us'=>0,'result'=>'processing']) === 1) { $attemptId=(int)$wpdb->insert_id; } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Writes to the plugin's own log table.
            $result='done';
            try {
                Processor::run((int)$task->attachment_id);
                if ($wpdb->update($queue,['state'=>'done','lease_until'=>null,'error'=>null,'updated_at'=>current_time('mysql',true)],['id'=>$taskId]) !== 1) { throw new \RuntimeException(esc_html__('The task completion could not be recorded.','nibwoz-better-img')); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin's own queue table.
            } catch (\Throwable $error) {
                $result='failed';
                $message=substr(wp_specialchars_decode($error->getMessage(),ENT_QUOTES),0,500);
                if ($wpdb->update($queue,['state'=>'failed','lease_until'=>null,'error'=>$message,'updated_at'=>current_time('mysql',true)],['id'=>$taskId]) !== 1) { $result='state_write_failed'; } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin's own queue table.
            } finally {
                if ($attemptId) { $wpdb->update($attempts,['ended_us'=>(int)round(microtime(true)*1000000),'result'=>$result],['id'=>$attemptId]); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin's own log table.
            }
            return true;
        } finally {
            if ($attachmentLock) { self::unlock($attachmentLock); }
            self::unlock($slot);
        }
    }
}
