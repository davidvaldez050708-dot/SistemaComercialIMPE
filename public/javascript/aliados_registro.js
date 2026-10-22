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
    // El CRM utiliza system-toast (dashboard.css). No sustituir por alerts genéricos.
    const TOAST_KEY = 'impe_aliados_registro_aviso';
    const aviso = (message, type = 'success') => {
        if (!window.bootstrap?.Toast) return;
        let tray = document.getElementById('impe-aliados-toast-tray');
        if (!tray) {
            tray = document.createElement('div');
            tray.id = 'impe-aliados-toast-tray';
            tray.className = 'toast-container position-fixed top-0 end-0 p-3';
            tray.style.zIndex = '2000';
            document.body.appendChild(tray);
        }
        const node = document.createElement('div');
        node.className = 'toast system-toast border-0 ' + (type === 'error' ? 'system-toast-error' : '');
        node.setAttribute('role', type === 'error' ? 'alert' : 'status');
        node.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
        const body = document.createElement('div');
        body.className = 'toast-body';
        const icon = document.createElement('i');
        icon.className = 'bi ' + (type === 'error' ? 'bi-exclamation-circle' : 'bi-check-circle-fill');
        const label = document.createElement('span');
        label.textContent = message;
        body.append(icon, label);
        node.appendChild(body);
        tray.appendChild(node);
        node.addEventListener('hidden.bs.toast', () => node.remove(), {once:true});
        bootstrap.Toast.getOrCreateInstance(node, {delay:4600}).show();
    };
    try {
        const previo = sessionStorage.getItem(TOAST_KEY);
        if (previo) {
            sessionStorage.removeItem(TOAST_KEY);
            aviso(previo);
        }
    } catch (_) { /* storage no disponible: el formulario continúa funcionando */ }
    const completar = (mensaje) => {
        try { sessionStorage.setItem(TOAST_KEY, mensaje); } catch (_) { /* sin persistencia visual */ }
        location.reload();
    };
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
            try { const data = new FormData(form); data.set('operacion',operation); await request('registroGestion',data,true); completar(operation === 'programas' ? 'Programas actualizados correctamente.' : (form.elements.id.value ? 'Aliado actualizado correctamente.' : 'Aliado agregado correctamente.')); }
            catch (e) { errorIn(error,e.message); aviso(e.message,'error'); loading=false; save.disabled=false; }
        });
        deactivate.addEventListener('click',async () => {
            if (loading || !window.confirm('¿Desactivar este aliado y su acceso de Registro? Se conservará el historial.')) return;
            loading=true;
            try { const data=new FormData(form); data.set('operacion','desactivar_manual'); await request('registroGestion',data,true); completar('Aliado desactivado. El historial se conserva.'); }
            catch (e) { errorIn(error,e.message); aviso(e.message,'error'); loading=false; }
        });
    }
    // Gestión independiente de contactos e historial para aliados manuales.
    const modalContactosEl = document.getElementById('modalManualContactos');
    const modalHistorialEl = document.getElementById('modalManualHistorial');
    if (modalContactosEl && modalHistorialEl && window.bootstrap) {
        const contactosModal = bootstrap.Modal.getOrCreateInstance(modalContactosEl);
        const historialModal = bootstrap.Modal.getOrCreateInstance(modalHistorialEl);
        const listaContactos = modalContactosEl.querySelector('[data-manual-contactos-list]');
        const listaHistorial = modalHistorialEl.querySelector('[data-manual-historial-list]');
        const formContacto = modalContactosEl.querySelector('[data-manual-contactos-form]');
        const formHistorial = modalHistorialEl.querySelector('[data-manual-difusion-form]');
        const errorContactos = modalContactosEl.querySelector('[data-manual-contactos-error]');
        const errorHistorial = modalHistorialEl.querySelector('[data-manual-historial-error]');
        let manualId = 0, contactosActuales = [];
        const limpiarContacto = () => {
            formContacto.reset(); formContacto.elements.id.value = String(manualId);
            formContacto.elements.contacto_id.value = '';
        };
        const mostrarContactos = (datos) => {
            contactosActuales = datos.contactos || [];
            listaContactos.replaceChildren();
            if (!contactosActuales.length) {
                const p = document.createElement('p'); p.className = 'aliados-manual-empty';
                p.textContent = 'Todavía no hay contactos adicionales registrados.';
                listaContactos.appendChild(p);
                return;
            }
            contactosActuales.forEach(c => {
                const row = document.createElement('div'); row.className = 'aliados-manual-contact-row';
                const txt = document.createElement('div');
                const nombre = document.createElement('strong'); nombre.textContent = c.nombre;
                const info = document.createElement('small');
                info.textContent = [c.cargo,c.correo,c.telefono].filter(Boolean).join(' · ') + (Number(c.whatsapp_autorizado) ? ' · WhatsApp autorizado' : '');
                txt.append(nombre,info);
                const actions = document.createElement('div'); actions.className = 'd-flex gap-1';
                const edit = document.createElement('button'); edit.type = 'button'; edit.className='btn btn-system-light';
                edit.textContent = 'Editar'; edit.addEventListener('click',() => {
                    for(const name of ['nombre','cargo','correo','telefono']) formContacto.elements[name].value = c[name] || '';
                    formContacto.elements.contacto_id.value = c.id;
                    formContacto.elements.whatsapp_autorizado.checked = Number(c.whatsapp_autorizado) === 1;
                    formContacto.elements.nombre.focus();
                });
                const remove = document.createElement('button'); remove.type = 'button'; remove.className='btn btn-system-light';
                remove.textContent = 'Desactivar';
                remove.addEventListener('click', async () => {
                    if (!confirm('¿Desactivar este contacto sin borrar su historial?')) return;
                    const data = new FormData(); data.set('id',manualId); data.set('operacion','contacto_desactivar'); data.set('contacto_id',c.id);
                    try { await request('registroGestion',data,true); aviso('Contacto desactivado.'); await recargarManual(); }
                    catch(e) { errorIn(errorContactos,e.message); aviso(e.message,'error'); }
                });
                actions.append(edit,remove); row.append(txt,actions); listaContactos.appendChild(row);
            });
        };
        const mostrarHistorial = (datos) => {
            listaHistorial.replaceChildren();
            if (!datos.historial?.length) {
                const p=document.createElement('p');p.className='aliados-manual-empty';
                p.textContent='Sin difusiones registradas. Registra aquí únicamente acciones que realmente se hayan realizado.';
                listaHistorial.appendChild(p);
                return;
            }
            datos.historial.forEach(h => {
                const row=document.createElement('div');row.className='aliados-manual-contact-row';
                const copy=document.createElement('div'),t=document.createElement('strong'),v=document.createElement('small');
                t.textContent=h.canal+' · '+h.realizado_at;
                v.textContent=[h.nota,h.responsable].filter(Boolean).join(' · ');
                copy.append(t,v);row.appendChild(copy);listaHistorial.appendChild(row);
            });
        };
        const recargarManual = async () => {
            const d=await request('registroManualDatos',{id:manualId});
            mostrarContactos(d);mostrarHistorial(d);
        };
        document.addEventListener('click',async event => {
            const bContacto = event.target.closest('[data-registro-manual-contactos]');
            const bHistorial = event.target.closest('[data-registro-manual-historial]');
            const button = bContacto || bHistorial;
            if (!button) return;
            manualId=Number(button.dataset.registroManualContactos || button.dataset.registroManualHistorial || 0);
            errorIn(errorContactos);errorIn(errorHistorial);limpiarContacto();
            formHistorial.reset();formHistorial.elements.id.value=String(manualId);
            (bContacto?contactosModal:historialModal).show();
            try { await recargarManual(); }
            catch(e) {errorIn(bContacto?errorContactos:errorHistorial,e.message);aviso(e.message,'error');}
        });
        modalContactosEl.querySelector('[data-manual-contacto-limpiar]').addEventListener('click', limpiarContacto);
        formContacto.addEventListener('submit',async event => {
            event.preventDefault();errorIn(errorContactos);
            const button=formContacto.querySelector('[type=submit]');button.disabled=true;
            try {
                const data=new FormData(formContacto);data.set('operacion','contacto_guardar');
                await request('registroGestion',data,true);
                aviso('Contacto guardado correctamente.');limpiarContacto();await recargarManual();
            } catch(e){errorIn(errorContactos,e.message);aviso(e.message,'error');}
            finally{button.disabled=false;}
        });
        formHistorial.addEventListener('submit',async event => {
            event.preventDefault();errorIn(errorHistorial);
            const button=formHistorial.querySelector('[type=submit]');button.disabled=true;
            try {
                const data=new FormData(formHistorial);data.set('operacion','difusion_manual');
                await request('registroGestion',data,true);
                aviso('Difusión realizada registrada en el historial.');
                formHistorial.elements.nota.value='';await recargarManual();
            } catch(e){errorIn(errorHistorial,e.message);aviso(e.message,'error');}
            finally{button.disabled=false;}
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
