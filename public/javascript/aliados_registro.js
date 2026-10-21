document.addEventListener('DOMContentLoaded', () => {
    'use strict';
    const admin = document.querySelector('[data-registro-admin]');
    const enlace = document.querySelector('[data-registro-enlace]');
    const base = admin?.dataset.base || enlace?.dataset.base;
    if (!base) return;
    const request = async (action, data, write = false) => {
        const url = new URL(base + 'index.php');
        url.searchParams.set('controller', 'aliado'); url.searchParams.set('action', action);
        const options = {credentials: 'same-origin', headers: {'X-Requested-With':'fetch'}};
        if (write) {
            options.method = 'POST'; options.body = data;
            data.set('csrf_token', window.IMPE_CSRF_TOKEN || '');
        } else { for (const [k,v] of Object.entries(data)) url.searchParams.set(k,v); }
        const response = await fetch(url,options); const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.mensaje || 'No fue posible completar la operación.');
        return result.datos;
    };
    const errorIn = (node, message = '') => { node.textContent = message; node.classList.toggle('d-none', !message); };
    if (admin && window.bootstrap) {
        const modal = bootstrap.Modal.getOrCreateInstance(admin), form = admin.querySelector('form');
        const manual = admin.querySelector('[data-registro-manual]'), error = admin.querySelector('[data-registro-error]');
        const save = admin.querySelector('[data-registro-guardar]'), deactivate = admin.querySelector('[data-registro-desactivar]');
        let operation = 'guardar_manual', loading = false, revision = 0;
        const open = async (button) => {
            if (loading) return;
            const version = ++revision; form.reset(); errorIn(error); save.disabled = true;
            const origin = button.dataset.registroOrigen || 'manual', id = button.dataset.registroId || '';
            const editing = button.hasAttribute('data-registro-editar') || !id;
            operation = editing ? 'guardar_manual' : 'programas';
            form.elements.id.value = id; form.elements.origen.value = origin;
            manual.hidden = !editing;
            manual.querySelectorAll('input,select,textarea').forEach(el => { if (el.id !== 'aliadoManualEstado') el.disabled = !editing; });
            admin.querySelector('.modal-title').textContent = editing ? (id ? 'Editar aliado' : 'Agregar aliado') : 'Programas de Registro';
            deactivate.classList.toggle('d-none', !id || !editing);
            modal.show(); loading = true;
            try {
                let detail;
                if (id) detail = await request('registroDetalle',{origen:origin,id});
                if (version !== revision) return;
                if (editing) {
                    const response = await fetch(base + 'index.php?controller=formularioPublico&action=municipios&estado_id=' + admin.dataset.estado);
                    const municipalities = await response.json();
                    if (!municipalities.ok) throw new Error('No fue posible cargar los municipios.');
                    const select = form.elements.municipio_id;
                    select.replaceChildren(new Option('Selecciona municipio',''));
                    municipalities.municipios.forEach(m => select.add(new Option(m.nombre,m.id)));
                }
                if (detail) {
                    for (const [name,value] of Object.entries(detail.origen)) {
                        const input = form.elements.namedItem(name);
                        if (input && name !== 'id') input.value = value ?? '';
                    }
                    form.querySelectorAll('[name="programas[]"]').forEach(el => { el.checked = detail.programas.includes(el.value); });
                    admin.querySelector('[data-registro-subtitulo]').textContent = detail.origen.nombre_entidad;
                } else admin.querySelector('[data-registro-subtitulo]').textContent = 'Relación registrada manualmente, sin formalización documental.';
                save.disabled = false;
            } catch (e) { errorIn(error,e.message); }
            finally { loading = false; }
        };
        document.querySelectorAll('[data-registro-abrir]').forEach(b => b.addEventListener('click',() => open(b)));
        admin.addEventListener('hidden.bs.modal',() => { revision++; });
        form.addEventListener('submit',async e => {
            e.preventDefault(); if (loading || save.disabled) return;
            if (!form.querySelector('[name="programas[]"]:checked')) { errorIn(error,'Selecciona al menos un programa.'); return; }
            loading = true; save.disabled = true; errorIn(error);
            try { const data = new FormData(form); data.set('operacion',operation); await request('registroGestion',data,true); location.reload(); }
            catch (e) { errorIn(error,e.message); loading=false; save.disabled=false; }
        });
        deactivate.addEventListener('click',async () => {
            if (loading || !window.confirm('¿Desactivar este aliado y su acceso de Registro? Se conservará el historial.')) return;
            loading=true;
            try { const data=new FormData(form); data.set('operacion','desactivar_manual'); await request('registroGestion',data,true); location.reload(); }
            catch (e) { errorIn(error,e.message); loading=false; }
        });
    }
    if (enlace) {
        let origin, id, version = 0, busy = false, current;
        const error = enlace.querySelector('[data-enlace-error]'), url = enlace.querySelector('[data-enlace-url]');
        const buttons = [...enlace.querySelectorAll('[data-enlace-accion]')];
        const render = detail => {
            current = detail; url.value = detail.url;
            const names = detail.catalogo.filter(p => detail.programas.includes(p.codigo)).map(p => p.nombre);
            enlace.querySelector('[data-enlace-programas]').textContent = names.length ? names.join(' · ') : 'Sin programas asignados. Solicita la configuración a Cuenta Clave.';
            enlace.querySelector('[data-enlace-estado]').textContent = detail.publicado ? (detail.habilitado ? 'Captura habilitada' : 'Captura deshabilitada') : 'Enlace sin publicar';
            enlace.querySelector('[data-enlace-publicado]').hidden = !detail.publicado;
            enlace.querySelector('[data-enlace-preview]').href = detail.url;
            enlace.querySelector('[data-enlace-qr-panel]').hidden = true;
            buttons.forEach(b => { b.disabled = busy || (b.dataset.enlaceAccion !== 'generar' && !detail.publicado) || (b.dataset.enlaceAccion !== 'deshabilitar' && !names.length); });
        };
        document.addEventListener('impe:convenio-abierto',async event => {
            const v = ++version; origin = event.detail.origen_tipo || 'institucional'; id = event.detail.origen_id || event.detail.seguimiento_id;
            current = null; url.value = ''; errorIn(error); buttons.forEach(b => { b.disabled=true; });
            enlace.querySelector('[data-enlace-publicado]').hidden = true;
            enlace.querySelector('[data-enlace-programas]').textContent = 'Consultando programas…';
            enlace.querySelector('[data-enlace-estado]').textContent = '';
            try { const result = await request('registroDetalle',{origen:origin,id}); if (v===version) render(result); }
            catch (e) { if (v===version) errorIn(error,e.message); }
        });
        buttons.forEach(button => button.addEventListener('click',async () => {
            if (busy) return; const v=version; busy=true; buttons.forEach(b => { b.disabled=true; }); errorIn(error);
            try {
                const data=new FormData(); data.set('origen',origin); data.set('id',id); data.set('operacion',button.dataset.enlaceAccion);
                const result=await request('registroGestion',data,true); if (v===version) current=result;
            } catch (e) { if(v===version) errorIn(error,e.message); }
            finally { busy=false; if(v===version && current) render(current); }
        }));
        enlace.querySelector('[data-enlace-copiar]').addEventListener('click',async () => {
            try { await navigator.clipboard.writeText(url.value); enlace.querySelector('[data-enlace-estado]').textContent='Enlace copiado'; }
            catch (_) { url.select(); errorIn(error,'Copia el enlace seleccionado con Ctrl+C.'); }
        });
        const qr = () => {
            try {
                if (!current?.url || !window.qrcode) throw new Error('No fue posible cargar el generador QR local.');
                const code=window.qrcode(0,'M'); code.addData(current.url); code.make();
                const count=code.getModuleCount(), canvas=enlace.querySelector('canvas'), size=Number(enlace.querySelector('[data-qr-size]').value);
                canvas.width=canvas.height=size; const ctx=canvas.getContext('2d'); ctx.fillStyle='#ffffff'; ctx.fillRect(0,0,size,size);
                ctx.fillStyle=enlace.querySelector('[data-qr-color]').value;
                const cell=Math.floor(size/(count+8)),offset=Math.floor((size-count*cell)/2);
                for(let r=0;r<count;r++) for(let c=0;c<count;c++) if(code.isDark(r,c)) ctx.fillRect(offset+c*cell,offset+r*cell,cell,cell);
                enlace.querySelector('[data-enlace-qr-panel]').hidden=false;
            } catch(e) { errorIn(error,e.message); }
        };
        enlace.querySelector('[data-enlace-qr]').addEventListener('click',qr);
        enlace.querySelectorAll('[data-qr-color],[data-qr-size]').forEach(e => e.addEventListener('change',qr));
        enlace.querySelector('[data-enlace-descargar]').addEventListener('click',() => {
            const a=document.createElement('a'); a.download='registro-aliado.png'; a.href=enlace.querySelector('canvas').toDataURL('image/png'); a.click();
        });
    }
});
