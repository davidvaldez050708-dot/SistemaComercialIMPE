(function () {
    'use strict';
    function iniciar() {
        const root = document.querySelector('[data-agenda-root]');
        const panel = root?.querySelector('[data-agenda-comments-panel]');
        if (!root || !panel || root.dataset.commentsReady === '1') return;
        root.dataset.commentsReady = '1';
        const layout = root.querySelector('.agenda-layout');
        const previousPanel = layout.querySelector('.agenda-side-card');
        const previousHidden = previousPanel?.hidden || false;
        layout.append(panel);
        const editor = root.querySelector('[data-agenda-comment-editor]');
        const removeDialog = root.querySelector('[data-agenda-comment-delete]');
        const form = editor.querySelector('form');
        const text = form.elements.comentario;
        const hour = form.elements.hora;
        const noHour = form.elements.sin_hora;
        const status = root.querySelector('[data-agenda-comments-status]');
        layout.before(status);
        const error = form.querySelector('[data-agenda-comment-error]');
        const days = Array.from(root.querySelectorAll('[data-agenda-date]'));
        let comments = [], selected = '', tab = 'comentarios', deleting = null;
        let saving = false, removing = false, loading = false, available = false, refreshAgain = false;
        let changeVersion = 0;
        let editorOrigin = null, deleteOrigin = null, initialDone = false;
        const readsInFlight = new Set();
        const params = new URLSearchParams(window.location.search);
        const initialId = Number(params.get('comentario_id') || 0);
        const initialDay = params.get('dia') || '';
        const label = date => new Intl.DateTimeFormat('es-MX', { weekday:'long',day:'numeric',month:'long',year:'numeric' }).format(new Date(date+'T12:00:00'));
        const node = (tag, cls, value) => {
            const el = document.createElement(tag);
            if (cls) el.className = cls;
            if (value !== undefined) el.textContent = value;
            return el;
        };
        const inform = (message, failure = false) => {
            status.textContent = message;
            status.hidden = !message;
            status.classList.toggle('is-error', failure);
        };
        function endpoint(action) {
            const url = new URL('index.php', window.location.href);
            url.search = new URLSearchParams({ controller:'agendaComentario', action }).toString();
            return url;
        }
        async function request(action, data = null) {
            const url = endpoint(action);
            const options = { cache:'no-store', credentials:'same-origin', headers:{ 'X-Requested-With':'XMLHttpRequest' } };
            if (data) {
                options.method = 'POST';
                options.headers['X-CSRF-Token'] = String(window.IMPE_CSRF_TOKEN || '');
                options.body = new URLSearchParams(data);
            } else {
                const start = days[0]?.dataset.agendaDate;
                const last = days[days.length-1]?.dataset.agendaDate;
                const end = new Date(last+'T12:00:00');
                end.setDate(end.getDate()+1);
                url.searchParams.set('desde', start || '');
                url.searchParams.set('hasta', end.getFullYear()+'-'+String(end.getMonth()+1).padStart(2,'0')+'-'+String(end.getDate()).padStart(2,'0'));
            }
            const response = await fetch(url, options);
            let result;
            try { result = await response.json(); } catch (_) { throw new Error('No fue posible cargar los comentarios. Intenta nuevamente.'); }
            if (!response.ok || !result.ok) throw new Error(result.mensaje || 'No fue posible procesar el comentario.');
            return result;
        }
        const refreshBell = () => window.dispatchEvent(new Event('impe:agenda-comments-refresh'));
        function chooseTab(value) {
            tab = value;
            panel.querySelectorAll('[data-agenda-comments-tab]').forEach(button => {
                const active = button.dataset.agendaCommentsTab === value;
                button.setAttribute('aria-selected', String(active));
                button.tabIndex = active ? 0 : -1;
            });
            panel.querySelector('[data-agenda-comments-meetings]').hidden = value !== 'reuniones';
            panel.querySelector('[data-agenda-comments-list]').hidden = value !== 'comentarios';
            panel.querySelector('[data-agenda-comments-new]').hidden = value !== 'comentarios';
        }
        // El servidor decide el vencimiento en la misma zona horaria de la agenda.
        function isDue(c) { return Boolean(c.recordatorio_pendiente) && !c.atendido; }
        async function read(c) {
            if (readsInFlight.has(c.id) || (c.leido && !isDue(c))) return;
            readsInFlight.add(c.id);
            try {
                await request('leer', {id:c.id});
                c.leido = true;
                if (isDue(c)) { c.atendido = true;c.recordatorio_pendiente = false; }
                refreshBell();
                renderPanel();
            } catch (e) { inform(e.message, true); }
            finally { readsInFlight.delete(c.id); }
        }
        function renderPanel() {
            if (!selected) return;
            panel.querySelector('[data-agenda-comments-date]').textContent = label(selected);
            const cell = days.find(d => d.dataset.agendaDate === selected);
            const meetings = cell ? Array.from(cell.querySelectorAll('.agenda-day-events [data-agenda-meeting]')) : [];
            const meetingList = panel.querySelector('[data-agenda-comments-meetings]');
            meetingList.replaceChildren(...meetings.map(button => button.cloneNode(true)));
            if (!meetings.length) meetingList.append(node('p','agenda-comments-empty','No hay reuniones para este día.'));
            panel.querySelector('[data-agenda-comments-meeting-count]').textContent = '('+meetings.length+')';
            const today = comments.filter(c => c.fecha === selected);
            panel.querySelector('[data-agenda-comments-count]').textContent = '('+today.length+')';
            const list = panel.querySelector('[data-agenda-comments-list]');
            list.replaceChildren();
            if (!today.length) list.append(node('p','agenda-comments-empty','Aún no hay comentarios para este día.'));
            today.forEach(c => {
                const card = node('article', 'agenda-comment-card'+(c.leido?' is-read':''));
                card.dataset.commentId = c.id;
                const avatar = node('span','agenda-comment-avatar',Array.from(c.autor.trim()).filter((v,i,a)=>i===0||a[i-1]===' ').slice(0,2).join('').toUpperCase() || 'U');
                const content = node('div','agenda-comment-copy');
                content.append(node('strong','',c.autor));
                const date = new Date(c.creado_at.replace(' ','T'));
                const created = Number.isNaN(date.getTime()) ? c.creado_at : new Intl.DateTimeFormat('es-MX',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',hour12:false}).format(date);
                content.append(node('small','',created));
                content.append(node('p','agenda-comment-text',c.comentario));
                content.append(node('span','agenda-comment-time',c.hora ? 'Recordatorio: '+c.hora : 'Sin hora'));
                const menu = node('details','agenda-comment-menu');
                const summary = node('summary','','⋮'); summary.setAttribute('aria-label','Opciones del comentario de '+c.autor);
                menu.append(summary);
                const actions = node('div','agenda-comment-menu-actions');
                const readButton = node('button','',isDue(c)?'Marcar atendido':(c.leido?'Leído':'Marcar leído'));
                readButton.type = 'button'; readButton.disabled = c.leido && !isDue(c);
                readButton.addEventListener('click',()=>void read(c)); actions.append(readButton);
                if (c.puede_eliminar) {
                    const button = node('button','is-danger','Eliminar'); button.type='button';
                    button.addEventListener('click',()=>{
                        deleting=c; deleteOrigin=button;
                        removeDialog.querySelector('[data-agenda-comment-delete-error]').hidden=true;
                        removeDialog.showModal();
                    });
                    actions.append(button);
                }
                menu.append(actions); card.append(avatar,content,menu); list.append(card);
            });
            chooseTab(tab);
        }
        function openDay(date, focus = false) {
            if (!days.some(d=>d.dataset.agendaDate===date)) return;
            selected=date;
            panel.hidden=false;
            layout.classList.add('has-comment-panel');
            if (previousPanel) previousPanel.hidden=true;
            days.forEach(d=>d.classList.toggle('has-comments-selected',d.dataset.agendaDate===date));
            renderPanel();
            if (focus) panel.querySelector('[data-agenda-comments-tab="comentarios"]').focus();
        }
        function closePanel() {
            const origin=days.find(d=>d.dataset.agendaDate===selected)?.querySelector('[data-agenda-comment-add]');
            panel.hidden=true; layout.classList.remove('has-comment-panel');
            if (previousPanel) previousPanel.hidden=previousHidden;
            days.forEach(d=>d.classList.remove('has-comments-selected'));
            selected='';
            origin?.focus();
        }
        function updateMarkers() {
            days.forEach(day=>{
                let holder=day.querySelector('.agenda-comment-markers');
                if (!holder) { holder=node('div','agenda-comment-markers');day.append(holder); }
                holder.replaceChildren();
                const notes=comments.filter(c=>c.fecha===day.dataset.agendaDate);
                if (notes.length) {
                    const button=node('button','agenda-comment-day-count',notes.length+' comentario'+(notes.length===1?'':'s'));
                    button.type='button';button.addEventListener('click',()=>{tab='comentarios';openDay(day.dataset.agendaDate,true);});
                    holder.append(button);
                }
            });
        }
        async function load() {
            if (loading) { refreshAgain=true;return; }
            if (document.hidden) return;
            loading=true;
            const version=changeVersion;
            try {
                const result=await request('listar');
                if(version!==changeVersion){refreshAgain=true;return;}
                comments=Array.isArray(result.comentarios)?result.comentarios:[];
                available=true;
                root.querySelectorAll('[data-agenda-comment-add]').forEach(b=>b.disabled=false);
                updateMarkers();renderPanel();
                if (result.hay_mas) inform('Se muestran los primeros 500 comentarios de este intervalo.');
                if (!initialDone) {
                    initialDone=true;
                    const comment=comments.find(c=>c.id===initialId);
                    if (comment) { tab='comentarios';openDay(comment.fecha);await read(comment); }
                    else if (initialId>0) { openDay(initialDay);inform('El comentario ya no está disponible.',true); }
                }
            } catch (e) {
                if (!available) root.querySelectorAll('[data-agenda-comment-add]').forEach(b=>b.disabled=true);
                inform(e.message,true);
            } finally { loading=false;if(refreshAgain){refreshAgain=false;void load();} }
        }
        function counter() {
            const length=Array.from(text.value).length;
            form.querySelector('[data-agenda-comment-counter]').textContent=length+' / 250';
            text.setAttribute('aria-invalid',String(length>250));
        }
        function openEditor(date, origin) {
            if (!available || editor.open) return;
            tab='comentarios';openDay(date);
            form.reset();counter();error.hidden=true;hour.disabled=true;
            editorOrigin=origin;
            editor.querySelector('[data-agenda-comment-date]').textContent=label(date);
            editor.style.left='';editor.style.top='';editor.classList.remove('is-anchored');
            editor.showModal();
            const rect=origin?.getBoundingClientRect();
            if (rect && window.innerWidth>760) {
                editor.classList.add('is-anchored');
                const width=editor.getBoundingClientRect().width;
                let left=rect.right+12;
                if (left+width>window.innerWidth-16) left=rect.left-width-12;
                left=Math.max(16,Math.min(left,window.innerWidth-width-16));
                const top=Math.max(16,Math.min(rect.top-130,window.innerHeight-editor.getBoundingClientRect().height-16));
                editor.style.left=left+'px';editor.style.top=top+'px';
            } else { editor.style.left='';editor.style.top=''; }
            text.focus();
        }
        root.querySelectorAll('[data-agenda-comment-add]').forEach(button=>{
            button.disabled=true;
            button.addEventListener('click',()=>openEditor(button.dataset.agendaCommentAdd,button));
        });
        root.querySelector('.agenda-calendar-grid').addEventListener('click',event=>{
            if (event.target.closest('button,a,input,textarea,select')) return;
            const day=event.target.closest('[data-agenda-date]');
            if (day && available) { tab='comentarios';openDay(day.dataset.agendaDate); }
        });
        panel.querySelector('[data-agenda-comments-close]').addEventListener('click',closePanel);
        panel.querySelector('[data-agenda-comments-new]').addEventListener('click',event=>openEditor(selected,event.currentTarget));
        panel.querySelectorAll('[data-agenda-comments-tab]').forEach(button=>{
            button.addEventListener('click',()=>chooseTab(button.dataset.agendaCommentsTab));
            button.addEventListener('keydown',event=>{
                if (event.key==='ArrowLeft'||event.key==='ArrowRight') {
                    event.preventDefault();chooseTab(tab==='comentarios'?'reuniones':'comentarios');
                    panel.querySelector('[aria-selected="true"]').focus();
                }
            });
        });
        form.querySelectorAll('[data-agenda-comment-cancel]').forEach(b=>b.addEventListener('click',()=>{if(!saving)editor.close();}));
        editor.addEventListener('cancel',event=>{if(saving)event.preventDefault();});
        editor.addEventListener('close',()=>editorOrigin?.focus());
        text.addEventListener('input',counter);
        noHour.addEventListener('change',()=>{hour.disabled=noHour.checked;if(noHour.checked)hour.value='';else hour.focus();});
        form.addEventListener('submit',async event=>{
            event.preventDefault();if(saving)return;
            const content=text.value.trim();
            if (!content||Array.from(content).length>250||(!noHour.checked&&!hour.value)) {
                error.textContent=!content||Array.from(content).length>250?'Escribe entre 1 y 250 caracteres.':'Selecciona una hora o marca «Sin hora».';error.hidden=false;return;
            }
            saving=true;error.hidden=true;
            form.querySelector('[data-agenda-comment-save]').disabled=true;
            try {
                await request('crear',{fecha:selected,comentario:content,hora:noHour.checked?'':hour.value});
                changeVersion++;
                editor.close();inform('Comentario añadido a la agenda.');
                await load();refreshBell();
            } catch(e) {error.textContent=e.message;error.hidden=false;}
            finally {saving=false;form.querySelector('[data-agenda-comment-save]').disabled=false;}
        });
        removeDialog.querySelector('[data-agenda-comment-delete-cancel]').addEventListener('click',()=>{if(!removing)removeDialog.close();});
        removeDialog.addEventListener('cancel',event=>{if(removing)event.preventDefault();});
        removeDialog.addEventListener('close',()=>deleteOrigin?.isConnected?deleteOrigin.focus():panel.querySelector('[data-agenda-comments-new]').focus());
        removeDialog.querySelector('[data-agenda-comment-delete-confirm]').addEventListener('click',async event=>{
            if(removing||!deleting)return;
            removing=true;event.currentTarget.disabled=true;
            const localError=removeDialog.querySelector('[data-agenda-comment-delete-error]');localError.hidden=true;
            try {
                await request('eliminar',{id:deleting.id});
                changeVersion++;
                removeDialog.close();inform('Comentario eliminado de la agenda.');await load();refreshBell();
                panel.querySelector('[data-agenda-comments-new]').focus();
            } catch(e){localError.textContent=e.message;localError.hidden=false;}
            finally {removing=false;removeDialog.querySelector('[data-agenda-comment-delete-confirm]').disabled=false;}
        });
        void load();
        window.setInterval(()=>void load(),60000);
        document.addEventListener('visibilitychange',()=>{if(!document.hidden)void load();});
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',iniciar,{once:true});else iniciar();
})();
