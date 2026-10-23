/* Presentación del listado existente; no interviene en creación ni borradores. */
(() => {
    'use strict';
    const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const identity = c => {
        const a = c.aliado || {};
        return '<strong class="convocatoria-ally-name">' + escape(a.nombre || 'Sin aliado asociado') + '</strong>' +
            '<span class="convocatoria-origin-tag' + (a.estado === 'revision' ? ' is-review' : '') + '">' + escape(a.procedencia || 'Publicación territorial') + '</span>' +
            (a.detalle_url ? '<a class="convocatoria-ally-detail" href="' + escape(a.detalle_url) + '">Consultar aliado <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>' : '');
    };
    const row = (c, html) => {
        const template = document.createElement('template'); template.innerHTML = html;
        const tr = template.content.firstElementChild;
        tr.dataset.convocatoriaId = String(c.id);
        const program = document.createElement('span');program.className = 'convocatoria-program-name';program.textContent = c.programa_nombre || 'Clasificación por revisar';
        tr.children[1].append(program);
        const ally = document.createElement('td');ally.innerHTML = identity(c);tr.children[1].after(ally);
        ['Imagen','Convocatoria y programa','Aliado y procedencia','Periodo','Territorio','Enlace','Estatus','Acciones'].forEach((label,i) => tr.children[i].dataset.label = label);
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
    const detail = c => '<div class="convocatoria-identity-detail">'+info('Programa',c.programa_nombre)+
        '<div class="user-detail-info-row"><div class="user-detail-info-icon"><i class="bi bi-building" aria-hidden="true"></i></div><div class="user-detail-info-label">Aliado y procedencia</div><div class="user-detail-info-value">'+identity(c)+'</div></div>'+
        info('Municipio',c.aliado?.municipio)+info('Región',c.aliado?.region)+
        info('Creación',[c.created_at,c.creador_nombre,c.creador_apellidos].filter(Boolean).join(' · '))+
        info('Última edición',[c.updated_at,c.editor_nombre,c.editor_apellidos].filter(Boolean).join(' · '))+'</div>';
    const edit = c => {
        const modal=document.getElementById('modalEditarConvocatoria');
        let panel=modal.querySelector('[data-v5-edit-identity]');
        if(!panel){panel=document.createElement('div');panel.className='convocatoria-edit-identity system-form-full';panel.dataset.v5EditIdentity='';modal.querySelector('.system-form-grid').prepend(panel);}
        panel.innerHTML='<span class="convocatoria-program-name">Programa: '+escape(c.programa_nombre)+'</span>'+identity(c);
        const linked=Boolean(c.aliado?.tiene_relacion),url=modal.querySelector('[name="enlace_registro"]');
        url.readOnly=linked;url.setAttribute('aria-readonly',String(linked));
        const help=url.parentElement.querySelector('small');
        if(help)help.textContent=linked?'Enlace compartido del aliado. Se conserva al editar esta publicación.':'Opcional. Se incluirá en el material preparado para difusión.';
        modal.querySelector('[name="tipo"]').value=c.tipo_convocatoria;
        modal.querySelector('[name="subtipo"]').value=c.subtipo_convocatoria;
        modal.querySelectorAll('[data-state-option] input').forEach(x=>x.disabled=linked);
        modal.querySelectorAll('input[type="date"]').forEach(x=>x.required=c.subtipo_convocatoria!=='inscripciones-abiertas');
        const file=modal.querySelector('input[type="file"]');if(file)file.value='';
    };
    window.IMPEConvocatoriasV5 = {render,detail,edit};
})();
