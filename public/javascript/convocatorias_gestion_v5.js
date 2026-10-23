/* Presentación del listado existente; no interviene en creación ni borradores. */
(() => {
    'use strict';
    const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const identity = c => {
        const a = c.aliado || {};
        return '<strong class="convocatoria-ally-name">' + escape(a.nombre || 'Sin aliado asociado') + '</strong>' +
            '<span class="convocatoria-origin-tag' + (a.estado === 'revision' ? ' is-review' : '') + '">' + escape(a.procedencia || 'Publicación territorial') + '</span>';
    };
    const row = (c, html) => {
        const template = document.createElement('template'); template.innerHTML = html;
        const tr = template.content.firstElementChild;
        tr.dataset.convocatoriaId = String(c.id);
        const program = document.createElement('span');program.className = 'convocatoria-program-name';program.textContent = c.programa_nombre || 'Clasificación por revisar';
        tr.children[1].append(program);
        const ally = document.createElement('td');ally.innerHTML = identity(c);tr.children[1].after(ally);
        ['Imagen','Convocatoria y programa','Aliado y procedencia','Periodo','Territorio','Enlace','Estatus','Acciones'].forEach((label,i) => tr.children[i].dataset.label = label);
        const period=tr.children[3];const dates=period.textContent.split(' — ');period.replaceChildren(...dates.map(value=>{const span=document.createElement('span');span.className='convocatoria-date';span.textContent=value;return span;}));
        const status = tr.querySelector('[data-bs-target="#modalEstadoConvocatoria"]');
        if(status){status.dataset.tipo=c.tipo_convocatoria;status.dataset.subtipo=c.subtipo_convocatoria;}
        if (c.aliado?.estado === 'revision' || c.aliado?.tipo === 'formulario') {
            const button = tr.querySelector('.btn-editar-convocatoria');
            if(button) {button.disabled = true;button.title = c.aliado.estado === 'revision' ? 'Revisa las relaciones antes de editar' : 'Gestionar desde Aliados internacionales';}
        }
        return tr.outerHTML;
    };
    const render = (rows, monthOf, rowOf) => {
        const programs = new Map();
        for (const c of rows) {
            const key = c.tipo_convocatoria + '|' + c.subtipo_convocatoria;
            if(!programs.has(key)) programs.set(key, {title:c.programa_nombre || 'Clasificación por revisar', rows:[]});
            programs.get(key).rows.push(c);
        }
        return [...programs.entries()].sort((a,b)=>a[1].title.localeCompare(b[1].title,'es')).map(([code,program]) => {
            const months = new Map();
            for(const c of program.rows) {const m=monthOf(c);if(!months.has(m.key))months.set(m.key,{...m,rows:[]});months.get(m.key).rows.push(c);}
            const body=[...months.values()].sort((a,b)=>b.orden-a.orden).map(m =>
                '<section class="convocatoria-month-group"><div class="convocatoria-month-group-header"><div class="convocatoria-month-group-title"><i class="bi bi-calendar3" aria-hidden="true"></i><strong>' + escape(m.titulo) + '</strong></div><span class="convocatoria-month-group-count">' + m.rows.length + ' convocatorias</span></div>' +
                '<div class="table-responsive"><table class="table users-table align-middle mb-0 convocatoria-month-table"><thead><tr>' +
                ['Imagen','Convocatoria y programa','Aliado y procedencia','Periodo','Territorio','Enlace','Estatus','Acciones'].map(x=>'<th scope="col">'+x+'</th>').join('') +
                '</tr></thead><tbody>'+m.rows.map(c=>row(c,rowOf(c))).join('')+'</tbody></table></div></section>').join('');
            return '<section class="convocatoria-program-group" data-modalidad="'+escape(code)+'"><div class="convocatoria-program-heading convocatoria-month-group-header"><div class="convocatoria-month-group-title"><i class="bi bi-mortarboard convocatoria-month-group-icon" aria-hidden="true"></i><h3>'+escape(program.title)+'</h3></div><span class="convocatoria-month-group-count">'+program.rows.length+' '+(program.rows.length===1?'convocatoria':'convocatorias')+'</span></div>'+body+'</section>';
        }).join('');
    };
    const info = (label,value) => '<div class="user-detail-info-row"><div class="user-detail-info-icon"><i class="bi bi-info-circle" aria-hidden="true"></i></div><div class="user-detail-info-label">'+escape(label)+'</div><div class="user-detail-info-value">'+escape(value || '—')+'</div></div>';
    const detail = c => info('Programa',c.programa_nombre)+
        '<div class="user-detail-info-row"><div class="user-detail-info-icon"><i class="bi bi-building" aria-hidden="true"></i></div><div class="user-detail-info-label">Aliado y procedencia</div><div class="user-detail-info-value">'+identity(c)+'</div></div>'+
        info('Municipio',c.aliado?.municipio)+info('Región',c.aliado?.region)+
        info('Creación',[c.created_at,c.creador_nombre,c.creador_apellidos].filter(Boolean).join(' · '))+
        info('Última edición',[c.updated_at,c.editor_nombre,c.editor_apellidos].filter(Boolean).join(' · '))+
        (c.aliado?.detalle_url ? '<div class="convocatoria-detail-actions"><a class="btn btn-system-light" data-v5-consultar-aliado data-convocatoria-id="'+Number(c.id)+'" href="'+escape(c.aliado.detalle_url)+'"><i class="bi bi-building" aria-hidden="true"></i> Consultar aliado</a></div>' : '');
    const make=(tag,cls,text)=>{const node=document.createElement(tag);if(cls)node.className=cls;if(text!==undefined)node.textContent=text;return node;};
    const edit = c => {
        const modal=document.getElementById('modalEditarConvocatoria'),grid=modal.querySelector('.system-form-grid'),form=modal.querySelector('form');
        const linked=Boolean(c.aliado?.tiene_relacion),url=form.querySelector('[name="enlace_registro"]');
        let shell=grid.querySelector('[data-v5-edit-shell]');
        if(!shell){
            shell=make('section','convocatoria-edit-program-shell impe-aliado-multi-section system-form-full');shell.dataset.v5EditShell='';
            const head=make('div','impe-aliado-multi-card-head');
            const heading=make('div');heading.append(make('small','','PUBLICACIÓN DEL ALIADO'),make('h3'),make('p','','Edita esta convocatoria conservando su programa, aliado y enlace de Registro.'));const origin=make('span','impe-aliado-multi-shared-tag');origin.dataset.v5EditOrigin='';head.append(heading,origin);shell.append(head);
            const shared=make('div','convocatoria-edit-shared');shared.append(url.parentElement);shell.append(shared);
            const qrActions=make('div','convocatoria-edit-qr-actions');
            const qrButton=(label,icon)=>{const button=make('button','btn btn-system-light');button.type='button';const glyph=make('i','bi '+icon);glyph.setAttribute('aria-hidden','true');button.append(glyph,make('span','',label));qrActions.append(button);return button;};
            const viewQr=qrButton('Ver QR','bi-qr-code'),downloadQr=qrButton('Descargar PNG','bi-download');viewQr.dataset.v5ViewQr='';downloadQr.dataset.v5DownloadQr='';
            const qrPanel=make('div','convocatoria-edit-qr');qrPanel.id='convocatoria-editar-qr';qrPanel.hidden=true;
            const canvas=make('canvas');canvas.width=canvas.height=600;canvas.setAttribute('role','img');canvas.setAttribute('aria-label','Código QR del enlace de Registro guardado');
            const qrText=make('div');qrText.append(make('strong','','QR de Registro'),make('p','','Puedes volver a descargarlo cuando lo necesites. Corresponde al enlace guardado de esta publicación.'));qrPanel.append(canvas,qrText);
            viewQr.setAttribute('aria-controls',qrPanel.id);viewQr.setAttribute('aria-expanded','false');
            const qrError=make('div','alert alert-warning convocatoria-edit-qr-error');qrError.dataset.v5QrError='';qrError.setAttribute('role','alert');qrError.hidden=true;
            shared.append(qrActions,qrPanel,qrError);
            const drawQr=()=>{
                qrError.hidden=true;
                try{
                    const savedUrl=String(modal.__v5Publication?.enlace_registro||'');
                    if(!savedUrl)throw new Error('Esta publicación no tiene un enlace de Registro guardado.');
                    if(!window.qrcode)throw new Error('No se pudo cargar el generador QR. Cierra el editor y vuelve a abrir la página.');
                    const qr=window.qrcode(0,'M');qr.addData(savedUrl);qr.make();
                    const ctx=canvas.getContext('2d');if(!ctx)throw new Error('No se pudo dibujar el QR en este navegador.');
                    canvas.width=canvas.height=600;ctx.fillStyle='#fff';ctx.fillRect(0,0,600,600);
                    const modules=qr.getModuleCount(),size=Math.floor(600/(modules+10)),offset=Math.floor((600-modules*size)/2);ctx.fillStyle='#273a8a';
                    for(let y=0;y<modules;y++)for(let x=0;x<modules;x++)if(qr.isDark(y,x))ctx.fillRect(offset+x*size,offset+y*size,size,size);
                    qrPanel.hidden=false;viewQr.setAttribute('aria-expanded','true');return true;
                }catch(error){qrPanel.hidden=true;viewQr.setAttribute('aria-expanded','false');qrError.textContent=error.message;qrError.hidden=false;return false;}
            };
            viewQr.addEventListener('click',drawQr);
            downloadQr.addEventListener('click',()=>{if(!drawQr())return;const link=make('a');link.download='registro-convocatoria-'+Number(modal.__v5Publication.id)+'-qr.png';link.href=canvas.toDataURL('image/png');link.click();});
            const card=make('section','impe-aliado-multi-card');card.dataset.v5EditIdentity='';
            const title=make('div','impe-aliado-multi-card-head');const description=make('div');description.append(make('small','','PROGRAMA'),make('h4'),make('p','','Se actualizará únicamente esta publicación.'));title.append(description,make('span','impe-aliado-multi-shared-tag','Registro y QR compartidos'));card.append(title);
            const field=name=>{const node=form.querySelector('[name="'+name+'"]');const wrap=node.parentElement;wrap.classList.add('impe-aliado-multi-field');return wrap;};
            card.append(field('titulo'));const row=make('div','impe-aliado-multi-row');['fecha_inicio','fecha_termino','estado','imagen'].forEach(name=>row.append(field(name)));card.append(row);shell.append(card);
            const preview=make('img','convocatoria-edit-preview');preview.alt='Imagen actual de la convocatoria';shell.querySelector('[name="imagen"]').parentElement.append(preview);
            const ask=make('div','convocatoria-edit-other-question');ask.append(make('p','','¿Quieres revisar también otras convocatorias publicadas de este aliado?'));
            const show=make('button','btn btn-system-light','Sí, mostrar otras');show.type='button';show.dataset.v5ShowOthers='';ask.append(show,make('small','','Cada publicación se edita y guarda por separado.'));shell.append(ask);
            const other=make('div','impe-aliado-multi-cards');other.dataset.v5OtherPublications='';other.hidden=true;shell.append(other);grid.prepend(shell);
            form.addEventListener('input',()=>{modal.dataset.v5Dirty='1';});form.addEventListener('change',()=>{modal.dataset.v5Dirty='1';});
            show.addEventListener('click',async()=>{
                if(!other.hidden){other.hidden=true;show.textContent='Sí, mostrar otras';return;}
                const current=modal.__v5Publication;if(!current)return;show.disabled=true;other.replaceChildren();other.hidden=false;
                try{
                    const endpoint=new URL(form.action);endpoint.search='';endpoint.searchParams.set('controller','convocatoria');endpoint.searchParams.set('action','listadoFiltrado');endpoint.searchParams.set('territorio_id',String(current.aliado.estado_id||current.estados_ids?.[0]||0));
                    const response=await fetch(endpoint,{credentials:'same-origin',cache:'no-store'});if(!response.ok)throw new Error('No se pudieron consultar las publicaciones del aliado.');const data=await response.json();
                    if(modal.__v5Publication?.id!==current.id)return;
                    const siblings=(data.convocatorias||[]).filter(row=>row.id!==current.id&&row.aliado?.estado==='verificado'&&row.aliado.tipo===current.aliado.tipo&&row.aliado.id===current.aliado.id);
                    if(!siblings.length)other.append(make('p','form-text','No hay otras publicaciones verificadas para este aliado en este territorio.'));
                    for(const sibling of siblings){const card=make('section','impe-aliado-multi-card');const head=make('div','impe-aliado-multi-card-head');const text=make('div');text.append(make('small','','OTRA PUBLICACIÓN'),make('h4','',sibling.programa_nombre),make('p','',sibling.titulo));const button=make('button','btn btn-system-light','Editar esta publicación');button.type='button';head.append(text,button);card.append(head);other.append(card);
                        button.addEventListener('click',()=>{if(modal.dataset.v5Dirty==='1'){let warning=other.querySelector('[role="alert"]');if(!warning){warning=make('div','login-alert');warning.setAttribute('role','alert');other.prepend(warning);}warning.textContent='Guarda o cancela los cambios actuales antes de abrir otra publicación.';return;}document.dispatchEvent(new CustomEvent('impe:editar-convocatoria',{detail:{id:sibling.id}}));});
                    }
                    show.textContent='Ocultar otras publicaciones';
                }catch(error){if(modal.__v5Publication?.id===current.id)other.append(make('div','login-alert',error.message));}finally{show.disabled=false;}
            });
        }
        modal.__v5Publication=c;modal.dataset.v5Dirty='0';
        shell.querySelector('.convocatoria-edit-qr').hidden=true;shell.querySelector('[data-v5-qr-error]').hidden=true;
        shell.querySelector('canvas').getContext('2d')?.clearRect(0,0,600,600);
        shell.querySelector('[data-v5-view-qr]').setAttribute('aria-expanded','false');
        shell.querySelectorAll('[data-v5-view-qr],[data-v5-download-qr]').forEach(button=>button.disabled=!c.enlace_registro);
        modal.querySelector('.modal-dialog').classList.add('impe-aliado-multi-dialog','modal-xl');
        shell.querySelector('h3').textContent=c.aliado?.nombre||'Publicación territorial';shell.querySelector('h4').textContent=c.programa_nombre;
        shell.querySelector('[data-v5-edit-origin]').textContent=c.aliado?.procedencia||'Publicación territorial';
        form.querySelector('label[for="editar_convocatoria_titulo"]').textContent='Título de la convocatoria';form.querySelector('label[for="editar_convocatoria_imagen"]').textContent='Imagen de la convocatoria';
        const other=shell.querySelector('[data-v5-other-publications]');other.replaceChildren();other.hidden=true;
        shell.querySelector('[data-v5-show-others]').textContent='Sí, mostrar otras';shell.querySelector('.convocatoria-edit-other-question').hidden=!linked||c.aliado?.estado!=='verificado';
        const preview=shell.querySelector('.convocatoria-edit-preview');preview.hidden=!c.imagen;preview.src=c.imagen?new URL(c.imagen,window.location.href).href:'';
        url.readOnly=linked;url.setAttribute('aria-readonly',String(linked));
        const help=url.parentElement.querySelector('small');if(help)help.textContent=linked?'Enlace de Registro compartido. Se conserva al actualizar esta publicación.':'Opcional. Se incluirá en el material preparado para difusión.';
        form.querySelector('[name="tipo"]').value=c.tipo_convocatoria;form.querySelector('[name="subtipo"]').value=c.subtipo_convocatoria;
        form.querySelectorAll('[data-state-option] input').forEach(x=>x.disabled=linked);
        const states=form.querySelector('[data-state-picker]');if(states)states.closest('.system-form-full').hidden=linked;
        form.querySelectorAll('input[type="date"]').forEach(x=>x.required=c.subtipo_convocatoria!=='inscripciones-abiertas');form.querySelector('input[type="file"]').value='';
    };
    document.addEventListener('click',event=>{
        const link=event.target.closest('[data-v5-consultar-aliado]');if(!link)return;
        const filters=document.querySelector('.convocatoria-list-workspace-filters');if(!filters)return;
        const target=new URL(link.href),back=new URL(target.pathname,location.origin);back.search=new URLSearchParams(new FormData(filters)).toString();back.searchParams.set('reabrir_convocatoria',link.dataset.convocatoriaId);back.searchParams.set('gestion_scroll',String(Math.round(scrollY)));
        target.searchParams.set('volver_gestion',back.pathname+back.search);link.href=target.href;
    });
    document.addEventListener('DOMContentLoaded',()=>setTimeout(()=>{
        const query=new URLSearchParams(location.search),id=Number(query.get('reabrir_convocatoria'));if(!Number.isSafeInteger(id)||id<1)return;
        const top=Number(query.get('gestion_scroll'));if(Number.isFinite(top))window.scrollTo(0,Math.max(0,top));document.querySelector('.btn-ver-convocatoria[data-id="'+id+'"]')?.click();
    },0),{once:true});
    window.IMPEConvocatoriasV5 = {render,detail,edit};
})();


/* IMPE_QR_INLINE_V5_20261023
 * Reutiliza los controles y el motor QR ya existentes, sin duplicarlos.
 * Se instala tras los manejadores del módulo para conservar su vista previa,
 * descarga, copia y configuración de imagen central.
 */
(function () {
    'use strict';

    function instalarPersonalizadorEnConvocatoria() {
        window.setTimeout(function () {
            var modal = document.getElementById('modalCrearConvocatoria');
            var formulario = modal && modal.querySelector('form.convocatoria-create-form');
            var compartir = formulario && formulario.querySelector('[data-convocatoria-form-share]');
            var modalQr = document.querySelector('[data-convocatoria-qr-modal]');
            var editor = modalQr && modalQr.querySelector('.convocatoria-qr-workspace');
            var ayuda = modalQr && modalQr.querySelector('.convocatoria-qr-help');
            var generar = compartir && compartir.querySelector('[data-convocatoria-generate-qr]');
            var urlGenerada = compartir && compartir.querySelector('[data-convocatoria-form-url]');
            var resultado = compartir && compartir.querySelector('[data-convocatoria-form-result]');
            var enlaceConvocatoria = formulario && formulario.querySelector('[data-convocatoria-link-input]');
            var color = editor && editor.querySelector('[data-convocatoria-qr-dark]');
            var enlaceVistaPrevia = editor && editor.querySelector('[data-convocatoria-qr-link]');

            if (!formulario || !compartir || !editor || !generar || !color ||
                formulario.querySelector('[data-convocatoria-qr-inline-v5]')) {
                return;
            }

            var aliado = compartir.dataset.aliadoRegistroContexto === '1';
            var panel = document.createElement('section');
            panel.className = 'system-form-full convocatoria-qr-inline-v5';
            panel.setAttribute('data-convocatoria-qr-inline-v5', '');
            panel.hidden = true;
            panel.innerHTML =
                '<div class="convocatoria-qr-inline-heading">' +
                    '<div class="convocatoria-qr-inline-title">' +
                        '<span class="convocatoria-qr-inline-icon"><i class="bi bi-qr-code" aria-hidden="true"></i></span>' +
                        '<div><span class="convocatoria-public-form-kicker">PERSONALIZACIÓN</span>' +
                        '<h3>Diseño del código QR</h3>' +
                        '<p>Personaliza el código y consulta los cambios en tiempo real.</p></div>' +
                    '</div>' +
                    '<button type="button" class="convocatoria-qr-inline-close" data-convocatoria-qr-inline-close ' +
                        'aria-label="Cerrar personalizador"><i class="bi bi-x-lg" aria-hidden="true"></i></button>' +
                '</div>' +
                '<div class="convocatoria-qr-inline-body" data-convocatoria-qr-inline-body></div>';

            var cuerpo = panel.querySelector('[data-convocatoria-qr-inline-body]');
            cuerpo.appendChild(editor);
            if (ayuda) cuerpo.appendChild(ayuda);
            compartir.insertAdjacentElement('afterend', panel);

            function urlActual() {
                if (aliado) {
                    if (compartir.dataset.aliadoRegistroHabilitado !== '1') return '';
                    return String(compartir.dataset.aliadoRegistroUrl || '').trim();
                }
                if (!resultado || resultado.classList.contains('d-none')) return '';
                return String(urlGenerada && urlGenerada.value || '').trim();
            }

            function ocultar() {
                panel.hidden = true;
                generar.setAttribute('aria-expanded', 'false');
            }

            function actualizarVistaPrevia() {
                var enlace = urlActual();
                if (!enlace) {
                    ocultar();
                    return;
                }
                if (aliado && urlGenerada) urlGenerada.value = enlace;
                if (enlaceVistaPrevia) enlaceVistaPrevia.textContent = enlace;
                // El controlador QR original escucha "input" y actualiza
                // todos los parámetros y la descarga sin crear otro motor.
                color.dispatchEvent(new Event('input', { bubbles: true }));
            }

            generar.setAttribute('aria-controls', 'convocatoriaQrPersonalizadorV5');
            panel.id = 'convocatoriaQrPersonalizadorV5';
            generar.setAttribute('aria-expanded', 'false');

            // En convocatorias vinculadas, el enlace y QR son compartidos.
            // No inventar un enlace ni habilitar uno que siga inactivo.
            if (aliado) generar.disabled = urlActual() === '';

            generar.addEventListener('click', function (event) {
                var enlace = urlActual();
                if (!enlace) return;
                // Evita que el controlador original abra otro modal encima.
                event.preventDefault();
                event.stopImmediatePropagation();
                panel.hidden = false;
                generar.setAttribute('aria-expanded', 'true');
                actualizarVistaPrevia();
                panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, true);

            panel.querySelector('[data-convocatoria-qr-inline-close]').addEventListener(
                'click',
                function () {
                    ocultar();
                    generar.focus();
                }
            );

            // Si cambia la clasificación, caduca el enlace anterior.
            var clasificacion = formulario.querySelector('[data-convocatoria-context-classification]');
            ['input', 'change'].forEach(function (tipo) {
                if (clasificacion) clasificacion.addEventListener(tipo, ocultar);
            });

            // El título también forma parte del enlace generado. Evitar que
            // se guarde un QR/enlace anterior si se modifica después.
            var titulo = formulario.querySelector('[data-convocatoria-context-title]');
            if (titulo) titulo.addEventListener('input', function () {
                if (!aliado && resultado && !resultado.classList.contains('d-none')) {
                    var anterior = String(urlGenerada && urlGenerada.value || '');
                    resultado.classList.add('d-none');
                    if (urlGenerada) urlGenerada.value = '';
                    if (enlaceConvocatoria && enlaceConvocatoria.value === anterior) {
                        enlaceConvocatoria.value = '';
                    }
                    generar.disabled = true;
                }
                ocultar();
            });

            if (urlGenerada) urlGenerada.addEventListener('input', function () {
                if (!panel.hidden) actualizarVistaPrevia();
            });

            // El vínculo del aliado puede habilitarse en la misma pantalla.
            // Refrescar el botón cuando el módulo de aliado lo notifique.
            compartir.addEventListener('impe:registro-aliado-actualizado', function () {
                if (aliado) generar.disabled = urlActual() === '';
                if (!panel.hidden) actualizarVistaPrevia();
            });

            if (modal) modal.addEventListener('hidden.bs.modal', ocultar);
        }, 0);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', instalarPersonalizadorEnConvocatoria, { once: true });
    } else {
        instalarPersonalizadorEnConvocatoria();
    }
})();
