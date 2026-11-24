/* IMPE_AGENDA_MARKETING_V1 */
(function () {
    'use strict';
    const ownerScript=document.currentScript;
    function start() {
        const root=document.querySelector('[data-convocatoria-notification-root]');
        if (!root || root.dataset.agendaMarketingReady==='1') return;
        root.dataset.agendaMarketingReady='1';
        const ownsAgenda=()=>{
            const other=document.querySelector('[data-reminder-root]');
            return !other || other.dataset.reminderInitialized!=='1' || other.hidden || other.getClientRects().length===0 ||
                getComputedStyle(other).visibility==='hidden';
        };
        let user=Number(window.IMPE_CURRENT_USER_ID||0);
        let records=[], active=null, activeItem=null, offset=0, lastData=null, engineLoading=null;
        const reads=new Set(), seen=new Set();
        const call=(method,...args)=>{
            try { return window.IMPEAgendaAlerts?.[method]?.(...args); }
            catch (error) { console.warn('No se pudo conectar la alerta de Agenda en Marketing.',error);return undefined; }
        };
        const refresh=()=>window.dispatchEvent(new CustomEvent('impe:agenda-comments-refresh'));
        function syncEngine() {
            if (!ownsAgenda() || !lastData || lastData.agenda_ok!==true || Number(lastData.usuario_id)!==user) return;
            call('setPresenter',present);call('ingest',lastData);
        }
        function ensureEngine() {
            if (typeof window.IMPEAgendaAlerts?.onToastShown==='function') return Promise.resolve(true);
            if (!user || engineLoading) return engineLoading||Promise.resolve(false);
            engineLoading=new Promise(resolve=>{
                const script=document.createElement('script');
                const source=new URL(ownerScript?.src||'public/javascript/convocatoria_notificaciones.js',location.href);
                const url=new URL('agenda_alertas.js',source);url.search='v=marketing-agenda-v2';
                script.src=url.href;script.async=true;
                if (ownerScript?.nonce) script.nonce=ownerScript.nonce;
                script.onload=()=>{syncEngine();resolve(typeof window.IMPEAgendaAlerts?.onToastShown==='function');};
                script.onerror=()=>{console.warn('No se pudo cargar el sonido existente de Agenda.');resolve(false);};
                document.head.append(script);
            });
            return engineLoading;
        }
        function recentComment(item) {
            const created=Number(item.agenda_creado_at)*1000, author=Number(item.agenda_autor_id), now=Date.now()+offset;
            return Number.isFinite(created) && created>0 && author>0 && author!==user &&
                created<=now+1000 && now-created<=5*60000;
        }
        function target(item) {
            try {
                const url=new URL(item.url,location.href);
                return url.origin===location.origin && url.searchParams.get('controller')==='agendaReunion' &&
                    Number(url.searchParams.get('comentario_id'))===Number(item.agenda_comentario_id)?url:null;
            } catch (_) { return null; }
        }
        async function read(item,link) {
            const id=Number(item.agenda_comentario_id),lector=Number(item.agenda_comentario_visto_por)||0;
            const destination=target(item), key=id+':'+lector;
            if (!destination || !Number.isSafeInteger(id) || id<=0 || reads.has(key)) return;
            reads.add(key);link.setAttribute('aria-busy','true');
            try {
                const url=new URL(root.dataset.endpoint,location.href);
                url.search=new URLSearchParams({controller:'agendaComentario',action:lector?'leerVisto':'leer'}).toString();
                const response=await fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',
                    headers:{'X-CSRF-Token':String(window.IMPE_CSRF_TOKEN||root.dataset.csrfToken||''),'X-Requested-With':'fetch'},
                    body:new URLSearchParams(lector?{id:String(id),lector_id:String(lector)}:{id:String(id)})});
                const data=await response.json();
                if (!response.ok || data.ok!==true) throw Error('No se confirmó la lectura');
                if (item.agenda_solo_aviso) { if (Number(activeItem?.agenda_comentario_id)===id) close();refresh();return; }
                destination.searchParams.set(lector?'aviso_visto_leido':'comentario_leido','1');
                location.href=destination.href;
            } catch (_) {
                if (!link.querySelector('[data-agenda-comment-read-error]')) {
                    const error=document.createElement('span');error.dataset.agendaCommentReadError='';
                    error.setAttribute('role','alert');error.textContent='No se pudo confirmar la lectura. Intenta de nuevo.';
                    link.append(error);
                }
            } finally { reads.delete(key);link.removeAttribute('aria-busy'); }
        }
        function close(resume=true) {
            const existed=Boolean(active);
            active?.remove();active=null;activeItem=null;
            if (existed && resume) window.dispatchEvent(new CustomEvent('impe:marketing-agenda-toast-closed'));
        }
        function present(item,onShown) {
            const busy=Array.from(document.querySelectorAll('.convocatoria-activation-toast')).some(toast=>
                toast.getClientRects().length>0 && getComputedStyle(toast).display!=='none' && getComputedStyle(toast).visibility!=='hidden');
            if (!ownsAgenda() || document.hidden || active?.isConnected || busy || !target(item)) return false;
            let container=document.querySelector('[data-agenda-marketing-toast-container]');
            if (!container) {
                container=document.createElement('div');
                container.className='toast-container position-fixed top-0 end-0 p-3';
                container.dataset.agendaMarketingToastContainer='';
                Object.assign(container.style,{position:'fixed',top:'0',right:'0',padding:'16px',zIndex:'1098'});
                document.body.append(container);
            }
            const toast=document.createElement('a');
            toast.className='toast system-toast agenda-comment-toast show';toast.href=target(item).href;
            toast.dataset.agendaMarketingToast='';toast.setAttribute('role','status');
            toast.setAttribute('aria-live','polite');toast.setAttribute('aria-atomic','true');
            Object.assign(toast.style,{display:'block',boxSizing:'border-box',width:'min(350px, calc(100vw - 32px))',textDecoration:'none'});
            const body=document.createElement('div');body.className='toast-body';body.style.padding='12px';
            const glyph=document.createElement('i');glyph.className='bi bi-chat-left-text';glyph.setAttribute('aria-hidden','true');
            const copy=document.createElement('span');Object.assign(copy.style,{flex:'1',minWidth:'0',overflowWrap:'anywhere'});
            const title=document.createElement('strong');title.textContent=item.titulo||'Nuevo comentario en la agenda';
            const message=document.createElement('span');message.textContent=item.mensaje||item.accion||'';
            message.style.display='block';
            copy.append(title,message);
            const button=document.createElement('button');button.type='button';button.className='btn-close agenda-marketing-toast-close';
            button.setAttribute('aria-label','Cerrar aviso');
            Object.assign(button.style,{border:'0',background:'transparent',padding:'4px',width:'24px',height:'24px',flexShrink:'0',display:'inline-flex',alignItems:'center',justifyContent:'center',color:'var(--color-text-secondary, #6d7480)'});
            const cross=document.createElementNS('http://www.w3.org/2000/svg','svg');
            cross.setAttribute('viewBox','0 0 16 16');cross.setAttribute('width','12');cross.setAttribute('height','12');cross.setAttribute('aria-hidden','true');
            const lines=document.createElementNS('http://www.w3.org/2000/svg','path');
            lines.setAttribute('d','M3 3l10 10M13 3L3 13');lines.setAttribute('fill','none');lines.setAttribute('stroke','currentColor');lines.setAttribute('stroke-width','1.8');lines.setAttribute('stroke-linecap','round');cross.append(lines);button.append(cross);
            button.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();close();});
            body.append(glyph,copy,button);toast.append(body);
            toast.addEventListener('click',event=>{if(event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;event.preventDefault();void read(item,toast);});
            active=toast;activeItem=item;container.append(toast);
            window.setTimeout(()=>{
                if (!toast.isConnected) return;
                if (document.hidden) {if(active===toast)close(false);return;}
                toast.classList.add('is-visible');
                Object.assign(toast.style,{opacity:'1',visibility:'visible',transform:'none'});
                window.requestAnimationFrame(()=>{
                    if (document.hidden || !toast.isConnected || toast.getBoundingClientRect().width<=0) return;
                    if (typeof onShown==='function') onShown();
                    void ensureEngine().then(ready=>{
                        if (ready && !document.hidden && toast.isConnected) call('onToastShown',item);
                    });
                });
            },30);
            window.setTimeout(()=>{if(active===toast)close();else toast.remove();showNext();},8300);
            return true;
        }
        function showNext() {
            if (!ownsAgenda() || document.hidden || active?.isConnected) return;
            for (const item of records) {
                if (!(Number(item.agenda_comentario_id)>0) || item.agenda_comentario_visto_por || Number(item.leida||0)!==0 ||
                    !String(item.agenda_comentario_evento||'').endsWith('|nuevo') || !recentComment(item)) continue;
                const key='impe:agenda:marketing:mostrado:'+user+':'+item.agenda_comentario_id;
                let stored=false;try {stored=localStorage.getItem(key)==='1';}catch(_){}
                if (stored || seen.has(key)) continue;
                if (!present({...item,agenda_alerta_clave:'comentario:'+item.agenda_comentario_id,
                    titulo:'Nuevo comentario en la agenda',mensaje:item.mensaje||item.accion},()=>{
                        seen.add(key);try {localStorage.setItem(key,'1');}catch(_){}
                    })) return;
                return;
            }
        }
        window.addEventListener('impe:marketing-agenda-data',event=>{
            const data=event.detail;
            if (!data || !ownsAgenda()) return;
            if (!user && Number.isSafeInteger(Number(data.usuario_id)) && Number(data.usuario_id)>0) {
                user=Number(data.usuario_id);window.IMPE_CURRENT_USER_ID=user;
            }
            if (data.agenda_ok!==true || Number(data.usuario_id)!==user) {
                records=[];lastData=null;close(false);call('suspend');return;
            }
            lastData=data;
            offset=Number(data.servidor_at)>0?Number(data.servidor_at)*1000-Date.now():0;
            records=Array.isArray(data.recordatorios)?data.recordatorios:[];
            syncEngine();void ensureEngine();
            for (const link of root.querySelectorAll('a.topbar-reminder-item')) {
                const item=records.find(r=>target(r)?.href===link.href);
                if (item) { const icon=link.querySelector('.topbar-reminder-icon i');if(icon)icon.className='bi bi-chat-left-text'; }
            }
            showNext();
        });
        root.addEventListener('click',event=>{
            const link=event.target.closest('a.topbar-reminder-item');
            if (!link || event.button!==0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const item=records.find(r=>target(r)?.href===link.href);
            if (!item) return;
            event.preventDefault();event.stopImmediatePropagation();void read(item,link);
        },true);
        document.addEventListener('visibilitychange',showNext);
        window.setInterval(showNext,1000);
    }
    if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',start);else start();
})();
