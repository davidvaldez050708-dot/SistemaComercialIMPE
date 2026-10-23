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
            return '<section class="convocatoria-program-group" data-modalidad="'+escape(code)+'"><h3 class="convocatoria-program-heading">'+escape(program.title)+' <span>'+program.rows.length+'</span></h3>'+body+'</section>';
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
