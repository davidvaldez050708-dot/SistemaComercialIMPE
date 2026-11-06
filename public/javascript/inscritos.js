(function () {
    'use strict';
    const app = document.querySelector('[data-inscritos]');
    if (!app) return;
    const form = app.querySelector('[data-inscritos-filtros]');
    const body = app.querySelector('[data-inscritos-body]');
    const summary = app.querySelector('[data-inscritos-resumen]');
    const pagination = app.querySelector('[data-inscritos-paginacion]');
    const vistaCompacta = window.matchMedia('(max-width:600px)');
    const clear = app.querySelector('[data-inscritos-limpiar]');
    const exportButton = app.querySelector('[data-inscritos-excel]');
    const drawer = document.querySelector('[data-inscritos-drawer]');
    const overlay = document.querySelector('[data-inscritos-overlay]');
    const close = drawer?.querySelector('[data-inscritos-cerrar]');
    const ficha = drawer?.querySelector('[data-inscritos-ficha]');
    const fichaTitulo = drawer?.querySelector('[data-inscritos-ficha-titulo]');
    const fichaPrograma = drawer?.querySelector('[data-inscritos-ficha-programa]');
    const comprobanteActions = drawer?.querySelector('[data-inscritos-comprobante-actions]');
    const comprobanteLink = drawer?.querySelector('[data-inscritos-comprobante]');

    let pagina = 1;
    let paginas = 1;
    let total = 0;
    let revision = 0;
    let loading = null;
    let timer = null;
    let drawerRevision = 0;
    let anteriorFoco = null;

    const programas = new Map(
        [...form.querySelectorAll('[name="programa"] option')]
            .map(option => [option.value, option.textContent.trim()])
    );

    function params(includePage) {
        const values = new URLSearchParams();
        for (const el of form.elements) {
            if (!el.name || !String(el.value).trim()) continue;
            values.set(el.name, String(el.value).trim());
        }
        if (includePage) values.set('pagina', String(pagina));
        return values;
    }
    function localDate(value) {
        if (!value) return '—';
        const d = new Date(value.replace(' ', 'T') + 'Z');
        if (Number.isNaN(d.getTime())) return value;
        return new Intl.DateTimeFormat('es-MX', {
            year:'numeric', month:'2-digit', day:'2-digit',
            hour:'2-digit', minute:'2-digit',
            hour12:false, timeZone:'America/Mexico_City'
        }).format(d);
    }
    function cell(text, className) {
        const el = document.createElement('td');
        if (className) el.className=className;
        el.textContent=String(text ?? '—') || '—';
        return el;
    }
    function mensaje(text, error) {
        body.replaceChildren();
        const tr=document.createElement('tr');
        const td=cell(text, error?'inscritos-message is-error':'inscritos-message');
        td.colSpan=7; tr.append(td); body.append(tr);
    }
    function showError(text) {
        mensaje(text,true);
        summary.textContent=text;
        if (exportButton) exportButton.disabled=true;
        pagination.replaceChildren(); pagination.hidden=true;
    }

    function renderPaginacion() {
        pagination.replaceChildren();pagination.hidden=total===0;
        if(total===0) return;
        const boton=(numero,texto,etiqueta,deshabilitado)=>{
            const b=document.createElement('button');b.type='button';
            b.dataset.inscritosPagina=String(numero);b.textContent=texto;
            b.setAttribute('aria-label',etiqueta);b.disabled=deshabilitado;
            if(numero===pagina&&texto===String(numero)){
                b.className='is-current';b.setAttribute('aria-current','page');
            }
            pagination.appendChild(b);
        };
        boton(pagina-1,'‹','Página anterior',pagina<=1);
        const numeros=new Set([1,paginas]);
        if(vistaCompacta.matches&&paginas>5){
            if(pagina<=3){numeros.add(2);numeros.add(3);}
            else if(pagina>=paginas-2){numeros.add(paginas-2);numeros.add(paginas-1);}
            else numeros.add(pagina);
        }else{
            const inicio=Math.max(2,Math.min(pagina-2,paginas-5));
            for(let n=inicio;n<=Math.min(paginas-1,inicio+4);n++) numeros.add(n);
        }
        let anterior=0;
        Array.from(numeros).sort((a,b)=>a-b).forEach(n=>{
            if(anterior&&n-anterior>1){const separador=document.createElement('span');separador.className='inscritos-pagination-ellipsis';separador.textContent='…';separador.setAttribute('aria-hidden','true');pagination.appendChild(separador);}
            boton(n,String(n),'Página '+n+(n===pagina?' (actual)':''),n===pagina);
            anterior=n;
        });
        boton(pagina+1,'›','Página siguiente',pagina>=paginas);
    }
    function render(registros) {
        body.replaceChildren();
        if (!Array.isArray(registros) || registros.length===0) {
            mensaje('No hay inscripciones que coincidan con los filtros.',false);
            return;
        }
        for (const r of registros) {
            const tr=document.createElement('tr');
            tr.append(cell(localDate(String(r.created_at||'')),'inscritos-date'));
            tr.append(cell([r.nombre,r.apellido].filter(Boolean).join(' '),'inscritos-name'));
            const program=document.createElement('td');
            const badge=document.createElement('span');
            badge.className='inscritos-program-badge';
            badge.textContent=programas.get(r.programa_slug) || r.programa_slug;
            program.append(badge);tr.append(program);
            tr.append(cell(r.correo));
            tr.append(cell(r.movil));
            const origin=document.createElement('td');
            const pill=document.createElement('span');
            pill.className='inscritos-origin';
            pill.textContent=r.origen==='PUBLICO'?'Público':r.origen==='MARKETING'?'Interno':String(r.origen||'—');
            origin.append(pill);tr.append(origin);
            const accion=document.createElement('td');
            const button=document.createElement('button');
            button.type='button';button.className='inscritos-view';
            button.dataset.inscritosId=String(r.id);
            const icon=document.createElement('i');
            icon.className='bi bi-eye';icon.setAttribute('aria-hidden','true');
            button.append(icon,document.createTextNode(' Ver ficha'));
            button.setAttribute('aria-label','Ver ficha de '+[r.nombre,r.apellido].filter(Boolean).join(' '));
            accion.append(button);tr.append(accion);
            body.append(tr);
        }
    }
    async function cargar() {
        const thisRevision=++revision;
        if (loading) loading.abort();
        loading=new AbortController();
        if (form.elements.namedItem('desde').value && form.elements.namedItem('hasta').value &&
            form.elements.namedItem('desde').value>form.elements.namedItem('hasta').value) {
            showError('La fecha inicial no puede ser posterior a la fecha final.');
            return;
        }
        pagination.querySelectorAll('button').forEach(button=>{button.disabled=true;});
        mensaje('Buscando inscripciones…',false);
        summary.textContent='Actualizando resultados…';
        if (exportButton) exportButton.disabled=true;
        try {
            const url=app.dataset.listUrl+'&'+params(true).toString();
            const response=await fetch(url,{
                credentials:'same-origin',cache:'no-store',
                headers:{'X-Requested-With':'fetch','Accept':'application/json'},
                signal:loading.signal
            });
            const json=await response.json();
            if (thisRevision!==revision) return;
            if (!response.ok || !json.ok) throw new Error(json.mensaje||'No fue posible consultar los inscritos.');
            pagina=Number(json.pagina)||1;
            paginas=Number(json.paginas)||1;
            total=Number(json.total)||0;
            const porPagina=Number(json.por_pagina)||25;
            const desde=total===0?0:(pagina-1)*porPagina+1;
            const hasta=Math.min(pagina*porPagina,total);
            summary.textContent=total===0?'0 inscritos':
                'Mostrando '+desde+'–'+hasta+' de '+total.toLocaleString('es-MX')+' inscritos';
            renderPaginacion();
            if (exportButton) {
                exportButton.disabled=total===0||total>10000;
                exportButton.title=total>10000
                    ? 'Acota el programa o el rango de fechas a 10,000 resultados como máximo.'
                    : 'Descargar todos los registros que coinciden con los filtros';
            }
            render(json.registros);
            if (total>10000) {
                summary.textContent+=' · Acota los filtros para exportar (máximo 10,000).';
            }
        } catch (err) {
            if (err.name==='AbortError'||thisRevision!==revision) return;
            showError(err.message||'No se pudo actualizar la tabla.');
        }
    }
    function recargar() {
        pagina=1;
        clearTimeout(timer);
        timer=setTimeout(cargar,280);
    }
    form.addEventListener('submit', e=>{e.preventDefault();recargar();});
    form.querySelector('[name="buscar"]').addEventListener('input',recargar);
    for(const name of ['programa','desde','hasta']) {
        form.elements.namedItem(name).addEventListener('change',recargar);
    }
    clear.addEventListener('click',()=>{
        form.reset();pagina=1;clearTimeout(timer);cargar();
        form.elements.namedItem('buscar').focus();
    });
    pagination.addEventListener('click',event=>{
        const button=event.target.closest('button[data-inscritos-pagina]');
        if(!button||button.disabled) return;
        const destino=Number(button.dataset.inscritosPagina);
        if(destino>=1&&destino<=paginas&&destino!==pagina){pagina=destino;cargar();}
    });
    exportButton?.addEventListener('click',()=>{
        if (total<1||total>10000) return;
        // Mismos parámetros que el listado, excluyendo solo la paginación.
        const url=app.dataset.exportUrl+'&'+params(false).toString();
        window.location.assign(url);
    });

    function agregarSeccion(titulo, icon, items) {
        const section=document.createElement('section');
        section.className='inscritos-ficha-section';
        const heading=document.createElement('h4');
        const i=document.createElement('i');i.className='bi '+icon;
        i.setAttribute('aria-hidden','true');
        heading.append(i,document.createTextNode(titulo));
        section.append(heading);
        const dl=document.createElement('dl');dl.className='inscritos-ficha-grid';
        for (const [label,value,wide] of items) {
            const block=document.createElement('div');
            block.className='inscritos-ficha-item'+(wide?' is-wide':'');
            const dt=document.createElement('dt');dt.textContent=label;
            const dd=document.createElement('dd');dd.textContent=String(value??'').trim()||'—';
            block.append(dt,dd);dl.append(block);
        }
        section.append(dl);ficha.append(section);
    }
    async function abrir(id,button) {
        const thisRevision=++drawerRevision;
        anteriorFoco=button||document.activeElement;
        overlay.hidden=false;
        drawer.hidden=false;
        drawer.setAttribute('aria-hidden','false');
        document.body.classList.add('inscritos-modal-abierto');
        comprobanteActions.hidden=true;
        ficha.replaceChildren();
        const loading=document.createElement('p');
        loading.className='inscritos-drawer-loading';
        loading.textContent='Consultando ficha…';
        ficha.append(loading);
        fichaTitulo.textContent='Inscripción #'+id;
        fichaPrograma.textContent='Cargando expediente';
        close.focus();
        try {
            const response=await fetch(app.dataset.detailUrl+'&id='+encodeURIComponent(id),{
                credentials:'same-origin',cache:'no-store',
                headers:{'X-Requested-With':'fetch','Accept':'application/json'}
            });
            const json=await response.json();
            if (thisRevision!==drawerRevision) return;
            if (!response.ok||!json.ok) throw new Error(json.mensaje||'No fue posible cargar la ficha.');
            const r=json.registro,d=r.datos||{};
            fichaTitulo.textContent=[d.nombre,d.apellidos].filter(Boolean).join(' ')||'Inscripción #'+r.id;
            fichaPrograma.textContent=(programas.get(r.programa_slug)||r.programa_slug)+' · Folio #'+r.id;
            ficha.replaceChildren();
            agregarSeccion('Inscripción','bi-file-earmark-check',[
                ['Folio','#'+r.id],
                ['Fecha de registro',localDate(r.created_at)],
                ['Programa',programas.get(r.programa_slug)||r.programa_slug,true],
                ['Origen',r.origen==='PUBLICO'?'Enlace público':'Captura interna'],
                ['Fecha de inscripción',d.fecha_inscripcion]
            ]);
            agregarSeccion('Datos personales','bi-person-vcard',[
                ['Nombre(s)',d.nombre],['Apellidos',d.apellidos],
                ['CURP',d.curp,true],['Fecha de nacimiento',d.fecha_nacimiento],
                ['Sexo en CURP',d.genero],['Nombre de los padres',d.nombre_padres,true]
            ]);
            agregarSeccion('Contacto y domicilio','bi-geo-alt',[
                ['Correo electrónico',d.correo,true],['Teléfono móvil',d.movil],
                ['Calle',d.calle],['Número',d.numero],
                ['Ciudad',d.ciudad],['Código postal',d.codigo_postal],
                ['Municipio',d.municipio],['Entidad federativa',d.entidad_federativa]
            ]);
            agregarSeccion('Información laboral','bi-briefcase',[
                ['Empresa donde labora',d.empresa_labora,true],
                ['Cargo o puesto',d.cargo_puesto],
                ['Perfil de evaluación',r.programa_slug==='bachillerato-286'?'No aplica':d.perfil_evaluacion,true]
            ]);
            agregarSeccion('Documentación y consentimiento','bi-shield-check',[
                ['Comprobante de pago',r.comprobante_recibido?'Archivo recibido · pago sin validar':'No disponible',true],
                ['Términos y condiciones',r.terminos_registrados?'Aceptación registrada':'Sin constancia'],
                ['Aviso de privacidad',r.privacidad_registrada?'Aceptación registrada':'Sin constancia'],
                ['Aceptado el',localDate(r.aceptado_at)]
            ]);
            if (r.puede_descargar_comprobante&&r.comprobante_recibido) {
                comprobanteLink.href=app.dataset.comprobanteUrl+'&id='+encodeURIComponent(r.id);
                comprobanteActions.hidden=false;
            }
        } catch(e) {
            if (thisRevision!==drawerRevision) return;
            ficha.replaceChildren();
            const p=document.createElement('p');
            p.className='inscritos-drawer-loading';p.textContent=e.message||'No se pudo cargar la ficha.';
            ficha.append(p);
        }
    }
    function cerrar() {
        if(drawer.hidden) return;
        ++drawerRevision;
        drawer.hidden=true;overlay.hidden=true;
        drawer.setAttribute('aria-hidden','true');
        comprobanteActions.hidden=true;
        document.body.classList.remove('inscritos-modal-abierto');
        if(anteriorFoco&&document.contains(anteriorFoco)) anteriorFoco.focus();
    }
    body.addEventListener('click',e=>{
        const button=e.target.closest('[data-inscritos-id]');
        if (button) abrir(Number(button.dataset.inscritosId),button);
    });
    close?.addEventListener('click',cerrar);
    overlay?.addEventListener('click',cerrar);
    document.addEventListener('keydown',e=>{
        if(!drawer||drawer.hidden) return;
        if(e.key==='Escape'){e.preventDefault();cerrar();}
        if(e.key==='Tab'){
            const focusables=[...drawer.querySelectorAll('button:not(:disabled),a[href]')]
                .filter(el=>!el.closest('[hidden]'));
            if(!focusables.length) return;
            const first=focusables[0],last=focusables[focusables.length-1];
            if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
            else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
        }
    });
    if(vistaCompacta.addEventListener) vistaCompacta.addEventListener('change',renderPaginacion);
    else if(vistaCompacta.addListener) vistaCompacta.addListener(renderPaginacion);
    cargar();
})();
