/* IMPE_AGENDA_MARKETING_V1 */
(function () {
    'use strict';
    function start() {
        const root=document.querySelector('[data-convocatoria-notification-root]');
        if (!root || root.dataset.agendaMarketingReady==='1') return;
        root.dataset.agendaMarketingReady='1';
        const ownsAgenda=!document.querySelector('[data-reminder-root]');
        const user=Number(window.IMPE_CURRENT_USER_ID||0);
        let records=[], active=null, activeItem=null;
        const reads=new Set(), seen=new Set();
        const call=(method,...args)=>{
            try { return window.IMPEAgendaAlerts?.[method]?.(...args); }
            catch (error) { console.warn('No se pudo conectar la alerta de Agenda en Marketing.',error);return undefined; }
        };
        const refresh=()=>window.dispatchEvent(new CustomEvent('impe:agenda-comments-refresh'));
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
        function present(item) {
            if (!ownsAgenda || document.hidden || active?.isConnected ||
                document.querySelector('.convocatoria-activation-toast') || !target(item)) return false;
            const toast=document.createElement('a');
            toast.className='convocatoria-activation-toast is-info';toast.href=target(item).href;
            toast.dataset.agendaMarketingToast='';toast.setAttribute('role','status');
            const icon=document.createElement('span');icon.className='convocatoria-activation-toast-icon';
            const glyph=document.createElement('i');glyph.className='bi bi-chat-left-text';icon.append(glyph);
            const copy=document.createElement('span');copy.className='convocatoria-activation-toast-copy';
            const title=document.createElement('strong');title.textContent=item.titulo||'Nuevo comentario en la agenda';
            const message=document.createElement('span');message.textContent=item.mensaje||item.accion||'';
            copy.append(title,message);
            const button=document.createElement('button');button.type='button';button.className='convocatoria-activation-toast-close';
            button.setAttribute('aria-label','Cerrar aviso');button.textContent='×';
            button.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();close();});
            toast.append(icon,copy,button);
            toast.addEventListener('click',event=>{if(event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;event.preventDefault();void read(item,toast);});
            active=toast;activeItem=item;document.body.append(toast);
            window.setTimeout(()=>{
                if (!toast.isConnected) return;
                toast.classList.add('is-visible');
                window.requestAnimationFrame(()=>{
                    if (!document.hidden && toast.isConnected && toast.getBoundingClientRect().width>0) call('onToastShown',item);
                });
            },30);
            window.setTimeout(()=>{if(active===toast)close();else toast.remove();showNext();},8300);
            return true;
        }
        function showNext() {
            if (!ownsAgenda || document.hidden || active?.isConnected) return;
            for (const item of records) {
                if (!(Number(item.agenda_comentario_id)>0) || item.agenda_comentario_visto_por || Number(item.leida||0)!==0 ||
                    !String(item.agenda_comentario_evento||'').endsWith('|nuevo') || call('canAnnounceComment',item)!==true) continue;
                const key='impe:agenda:marketing:mostrado:'+user+':'+item.agenda_comentario_id;
                let stored=false;try {stored=localStorage.getItem(key)==='1';}catch(_){}
                if (stored || seen.has(key)) continue;
                if (!present({...item,agenda_alerta_clave:'comentario:'+item.agenda_comentario_id,
                    titulo:'Nuevo comentario en la agenda',mensaje:item.mensaje||item.accion})) return;
                seen.add(key);try {localStorage.setItem(key,'1');}catch(_){}
                return;
            }
        }
        window.addEventListener('impe:marketing-agenda-data',event=>{
            const data=event.detail;
            if (!data || !ownsAgenda) return;
            if (data.agenda_ok!==true || Number(data.usuario_id)!==user) {
                records=[];close(false);call('suspend');return;
            }
            records=Array.isArray(data.recordatorios)?data.recordatorios:[];
            call('setPresenter',present);call('ingest',data);
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
        window.addEventListener('visibilitychange',showNext);
        window.setInterval(showNext,1000);
    }
    if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',start);else start();
})();
