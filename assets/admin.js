(() => {
    'use strict';

    const text=(tag,value,className='') => {
        const element=document.createElement(tag);
        if (className) { element.className=className; }
        element.textContent=value;
        return element;
    };

    const settings=window.nwzbiAdmin||{};
    const strings=settings.i18n||{};
    const locale=settings.locale||document.documentElement.lang||undefined;
    const t=(key,...values) => {
        let position=0;
        return String(strings[key]??key).replace(/%(?:(\d+)\$)?s/g,(match,index) => String(values[index ? Number(index)-1 : position++]??''));
    };

    const pad=(value) => String(value).padStart(2,'0');
    const clock=(seconds) => {
        const total=Math.max(0,Math.round(Number(seconds)||0));
        const h=Math.floor(total/3600), m=Math.floor((total%3600)/60), s=total%60;
        return h?`${h}:${pad(m)}:${pad(s)}`:`${m}:${pad(s)}`;
    };
    const decimal=(value) => Number(value||0).toLocaleString(locale,{minimumFractionDigits:1,maximumFractionDigits:1});

    const root=document.querySelector('.nwzbi-queue-monitor');
    if (root) {
        const counterScope=root;
        const activityText=root.querySelector('[data-nwzbi-activity]');
        const track=root.querySelector('.nwzbi-track');
        const bar=root.querySelector('[data-nwzbi-progress-bar]');
        const idleDelay=5000;
        let timer=0, loading=false, consecutiveFailures=0, sessionExpired=false, preparing=false;
        const connectionNotice=text('p','','nwzbi-status-error');

        const setText=(selector,value) => { const element=root.querySelector(selector); if (element) { element.textContent=value; } };
        const showNotice=(message) => { connectionNotice.textContent=message; if (!connectionNotice.isConnected) { root.append(connectionNotice); } };

        const render=(payload) => {
            const counts=payload.counts||{};
            for (const state of ['pending','processing','done','failed']) {
                for (const counter of counterScope.querySelectorAll(`[data-nwzbi-count="${state}"]`)) { counter.textContent=String(counts[state]??0); }
            }
            const progress=payload.progress||{};
            const total=Number(progress.total||0), finished=Number(progress.finished||0);
            const percent=Math.max(0,Math.min(100,Number(progress.percent||0)));
            const number=(value) => Number(value||0).toLocaleString(locale);
            setText('[data-nwzbi-progress-label]',total?t(total===1?'progressOne':'progressMany',number(finished),number(total)):t('progressNone'));
            setText('[data-nwzbi-progress-percent]',`${decimal(percent)}%`);
            if (bar) { bar.style.width=`${percent}%`; }
            if (track) { track.setAttribute('aria-valuenow',String(percent)); track.classList.remove('is-preparing'); }

            const session=payload.session||null;
            const box=root.querySelector('[data-nwzbi-session]');
            if (box) {
                box.hidden=!session || (!session.active && !session.files);
                if (session) {
                    setText('[data-nwzbi-session-title]',t(session.active?'currentRound':'lastRound'));
                    setText('[data-nwzbi-session-elapsed]',clock(session.elapsed));
                    setText('[data-nwzbi-session-files]',number(session.files));
                    setText('[data-nwzbi-session-rate]',decimal(session.rate));
                    box.classList.toggle('is-active',Boolean(session.active));
                    const eta=root.querySelector('[data-nwzbi-session-eta]');
                    if (eta) {
                        const remaining=Number(counts.pending||0)+Number(counts.processing||0);
                        const elapsed=Number(session.elapsed||0);
                        const show=Boolean(session.active) && remaining>0 && finished>=2 && elapsed>=3;
                        eta.hidden=!show;
                        if (show) { eta.textContent=t('remaining',clock(remaining*elapsed/finished)); }
                    }
                }
            }

            const current=Array.isArray(payload.current)?payload.current:[];
            const currentBox=root.querySelector('[data-nwzbi-current]');
            if (currentBox) { currentBox.hidden=!current.length; setText('[data-nwzbi-current-list]',current.join(' · ')); }

            const active=Boolean(payload.activity);
            root.classList.toggle('is-active',active);
            if (activityText && !preparing) { activityText.textContent=t(active?'processing':(total && finished>=total?'finished':'idle')); }
            for (const element of document.querySelectorAll('[data-nwzbi-when-active]')) { element.hidden=!active; }
        };

        const shouldRepeat=() => !sessionExpired && !document.hidden;

        const refresh=async () => {
            if (timer) { window.clearTimeout(timer); timer=0; }
            if (loading) { return; }
            loading=true;
            let delay=idleDelay;
            try {
                const body=new URLSearchParams({action:'nwzbi_queue_status',nonce:root.dataset.nonce});
                const response=await fetch(root.dataset.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body,signal:AbortSignal.timeout(20000)});
                if (response.status===403) {
                    sessionExpired=true;
                    showNotice(t('sessionExpired'));
                    return;
                }
                const json=await response.json();
                if (!response.ok || !json.success) { throw new Error(json.data?.message||t('httpError',response.status)); }
                render(json.data);
                consecutiveFailures=0;
                connectionNotice.remove();
                delay=json.data.activity?1000:idleDelay;
            } catch (error) {
                consecutiveFailures++;
                delay=Math.min(30000,5000*2**Math.min(consecutiveFailures-1,3));
                const reason=error?.name==='TimeoutError'||error?.name==='AbortError'?t('timeout'):error instanceof TypeError?t('connection'):error instanceof SyntaxError?t('invalidResponse'):error instanceof Error?error.message:t('genericConnection');
                showNotice(t('queryFailed',reason,delay/1000));
            } finally {
                loading=false;
                if (shouldRepeat()) { timer=window.setTimeout(refresh,delay); }
            }
        };

        const prepare=(message) => {
            preparing=true;
            if (activityText) { activityText.textContent=message; }
            root.classList.add('is-active');
            setText('[data-nwzbi-progress-label]',message);
            setText('[data-nwzbi-progress-percent]','');
            if (bar) { bar.style.width='0%'; }
            if (track) { track.classList.add('is-preparing'); }
        };

        document.addEventListener('visibilitychange',() => { if (shouldRepeat()) { refresh(); } });
        refresh();

        for (const form of document.querySelectorAll('form[data-nwzbi-async="queue_all"]')) {
            form.addEventListener('submit',async (event) => {
                event.preventDefault();
                const button=form.querySelector('button');
                if (button) { button.disabled=true; }
                const notice=document.querySelector('[data-nwzbi-async-notice]')||text('div','','nwz-notice is-info');
                notice.setAttribute('data-nwzbi-async-notice','');
                prepare(t('preparing'));
                try {
                    const body=new FormData(form);
                    body.set('action','nwzbi_queue_all');
                    const response=await fetch(root.dataset.endpoint,{method:'POST',credentials:'same-origin',body});
                    const json=await response.json();
                    if (!response.ok || !json.success) { throw new Error(json.data?.message||t('httpErrorShort',response.status)); }
                    notice.remove();
                    (root.closest('.nwz-ui')||root).dispatchEvent(new CustomEvent('nwz:toast',{detail:{message:json.data.message,tone:'success'}}));
                    preparing=false;
                    if (json.data.status) { render(json.data.status); }
                } catch (error) {
                    notice.className='nwz-notice is-error'; notice.setAttribute('role','alert'); notice.replaceChildren(text('p',error instanceof Error?error.message:t('queueStartFailed')));
                    preparing=false;
                } finally {
                    if (!notice.isConnected && notice.classList.contains('is-error')) { form.closest('.nwz-card')?.append(notice); }
                    if (button) { button.disabled=false; }
                    refresh();
                }
            });
        }
    }

    const formatSelect=document.querySelector('[data-nwzbi-format]');
    const engineSelect=document.querySelector('[data-nwzbi-engine]');
    const compatibility=document.querySelector('[data-nwzbi-compatibility]');
    if (formatSelect && engineSelect) {
        let autoLabels={};
        try { autoLabels=JSON.parse(engineSelect.dataset.autoLabels||'{}'); } catch (error) {}
        const synchronizeCompatibility=() => {
            const format=formatSelect.value;
            const formatLabel=format==='original'?t('keepOriginal'):(formatSelect.selectedOptions[0]?.textContent?.trim()||format.toUpperCase());
            for (const option of engineSelect.options) {
                const formats=(option.dataset.formats||'').split(',').filter(Boolean);
                option.disabled=!formats.includes(format);
                if (option.value==='auto') {
                    const label=autoLabels[format]||'';
                    option.textContent=label?t('automaticWith',label):t('automaticNone');
                }
            }
            let selected=engineSelect.selectedOptions[0];
            if (!selected || selected.disabled) {
                const fallback=[...engineSelect.options].find((option) => !option.disabled);
                if (fallback) { engineSelect.value=fallback.value; selected=fallback; }
            }
            if (!compatibility) { return; }
            compatibility.dataset.state=(!selected || selected.disabled) ? 'error' : 'ok';
            if (!selected || selected.disabled) {
                compatibility.textContent=t('noEngine',formatLabel);
            } else if (selected.value==='auto') {
                compatibility.textContent=t('automaticUses',autoLabels[format]||t('compatibleEngine'),formatLabel);
            } else {
                compatibility.textContent=t('enginePassed',selected.textContent.trim(),formatLabel);
            }
        };
        formatSelect.addEventListener('change',synchronizeCompatibility);
        engineSelect.addEventListener('change',synchronizeCompatibility);
        synchronizeCompatibility();
    }

    const copyValue=async (input) => {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(input.value);
            return;
        }
        input.focus();
        input.select();
        if (!document.execCommand('copy')) { throw new Error(t('copyFailed')); }
    };

    document.addEventListener('click',async (event) => {
        if (!(event.target instanceof Element)) { return; }
        const copyButton=event.target.closest('[data-nwzbi-copy-url]');
        if (copyButton) {
            const input=copyButton.closest('.nwzbi-media-url')?.querySelector('input[readonly]');
            if (!(input instanceof HTMLInputElement) || input.value==='') { return; }
            const original=copyButton.textContent;
            try {
                await copyValue(input);
                copyButton.textContent=t('copied');
            } catch (error) {
                copyButton.textContent=t('copyError');
            }
            window.setTimeout(() => { copyButton.textContent=original; },1800);
            return;
        }

        const optimizeButton=event.target.closest('[data-nwzbi-optimize]');
        if (!optimizeButton || optimizeButton.disabled) { return; }
        const actions=optimizeButton.closest('.nwzbi-media-actions');
        const feedback=actions?.querySelector('[data-nwzbi-media-feedback]');
        const original=optimizeButton.textContent;
        optimizeButton.disabled=true;
        optimizeButton.textContent=t('adding');
        if (feedback) { feedback.textContent=''; feedback.classList.remove('is-error','is-success'); }
        try {
            const body=new URLSearchParams({
                action:'nwzbi_optimize_attachment',
                attachment:optimizeButton.dataset.attachment||'',
                nonce:optimizeButton.dataset.nonce||'',
            });
            const response=await fetch(optimizeButton.dataset.endpoint||'',{
                method:'POST',credentials:'same-origin',
                headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body,
            });
            const json=await response.json();
            if (!response.ok || !json.success) { throw new Error(json.data?.message||t('optimizeFailed')); }
            optimizeButton.textContent=t('added');
            if (feedback) { feedback.textContent=json.data?.message||t('added'); feedback.classList.add('is-success'); }
        } catch (error) {
            optimizeButton.disabled=false;
            optimizeButton.textContent=original;
            if (feedback) { feedback.textContent=error instanceof Error?error.message:t('optimizeFailed'); feedback.classList.add('is-error'); }
        }
    });
})();
