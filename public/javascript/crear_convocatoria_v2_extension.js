/* Convenios V2: extensión sobre el script personalizado de Hostinger.
 * No reemplaza crear_convocatoria.js ni altera la telefonía.
 */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const root = document.querySelector('[data-crear-convocatoria-module]');
    if (!root) return;

    const normalize = value => String(value || '').normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '').trim().toLowerCase();
    const stateCards = Array.from(root.querySelectorAll('[data-convenio-territorio-card]'));
    const stateSearch = root.querySelector('[data-convenios-territorio-buscar]');
    const stateKind = root.querySelector('[data-convenios-territorio-tipo]');
    const stateChooser = root.querySelector('[data-convenios-territorio-seleccionar]');
    const stateCount = root.querySelector('[data-convenios-territorio-contador]');
    const stateEmpty = root.querySelector('[data-convenios-territorio-vacio]');

    function applyTerritoryFilters() {
        let visible = 0;
        const term = normalize(stateSearch?.value);
        const kind = stateKind?.value || '';
        stateCards.forEach(card => {
            const has = card.dataset.tiene === '1';
            const match = (!term || normalize(card.dataset.nombre).includes(term)) &&
                (!kind || (kind === 'con' ? has : !has));
            card.hidden = !match;
            if (match) visible++;
        });
        if (stateCount) stateCount.textContent = visible + (visible === 1 ? ' territorio' : ' territorios');
        stateEmpty?.classList.toggle('d-none', visible > 0);
    }

    stateSearch?.addEventListener('input', applyTerritoryFilters);
    stateKind?.addEventListener('change', applyTerritoryFilters);
    stateChooser?.addEventListener('change', () => {
        const stateId = Number(stateChooser.value);
        if (!Number.isSafeInteger(stateId) || stateId <= 0) return;
        for (const card of stateCards) {
            const url = card.querySelector('a[href]')?.href;
            if (!url) continue;
            const destination = new URL(url, window.location.href);
            if (Number(destination.searchParams.get('estado_id')) === stateId &&
                destination.origin === window.location.origin) {
                window.location.assign(destination.href);
                return;
            }
        }
    });
    if (stateCards.length) applyTerritoryFilters();

    const rows = Array.from(root.querySelectorAll('[data-convenio-notification]'));
    const search = root.querySelector('[data-convenio-search]');
    const origin = root.querySelector('[data-convenio-origin-filter]');
    const city = root.querySelector('[data-convenio-municipio-filter]');
    const state = root.querySelector('[data-convenio-state-filter]');
    const clear = root.querySelector('[data-convenio-clear-filters]');
    const results = root.querySelector('[data-convenio-results]');
    const empty = root.querySelector('[data-convenio-empty-filter]');

    function applyRowFilters() {
        if (!rows.length) return;
        let visible = 0;
        const term = normalize(search?.value);
        const stateValue = String(state?.value || '');
        const cityValue = String(city?.value || '');
        const originValue = String(origin?.value || '');
        rows.forEach(row => {
            const matches = (!term || normalize(row.dataset.convenioSearchText).includes(term)) &&
                (!stateValue || row.dataset.convenioState === stateValue) &&
                (!cityValue || row.dataset.convenioMunicipio === cityValue) &&
                (!originValue || row.dataset.convenioOrigin === originValue);
            row.classList.toggle('d-none', !matches);
            if (matches) visible++;
        });
        for (const header of root.querySelectorAll('[data-convenio-municipio-header]')) {
            let next = header.nextElementSibling;
            let hasVisible = false;
            while (next && !next.matches('[data-convenio-municipio-header]')) {
                if (next.matches('[data-convenio-notification]') && !next.classList.contains('d-none')) {
                    hasVisible = true;
                    break;
                }
                next = next.nextElementSibling;
            }
            header.classList.toggle('d-none', !hasVisible);
        }
        if (results) results.textContent = visible + (visible === 1 ? ' convenio' : ' convenios');
        empty?.classList.toggle('d-none', visible > 0);
        clear?.classList.toggle('d-none',
            !String(search?.value || '').trim() && !stateValue && !cityValue && !originValue);
    }

    // El script anterior registra primero sus eventos. Esta extensión recalcula
    // después, sin interceptar ni cancelar comportamiento personalizado.
    search?.addEventListener('input', applyRowFilters);
    origin?.addEventListener('change', applyRowFilters);
    city?.addEventListener('change', applyRowFilters);
    state?.addEventListener('change', applyRowFilters);
    root.querySelectorAll('[data-state-value]').forEach(el =>
        el.addEventListener('click', applyRowFilters));
    clear?.addEventListener('click', () => {
        if (city) city.value = '';
        applyRowFilters();
    });

    const modal = document.getElementById('modalConvenioCrearConvocatoria');

    function refreshModal(data) {
        if (!modal || !data || typeof data !== 'object') return;
        const link = modal.querySelector('[data-convenio-create-link]');
        const alias = modal.querySelector('[data-convenio-modal-programas]');
        if (alias) {
            alias.replaceChildren();
            const programas=Array.isArray(data.registro_programas_items)?data.registro_programas_items:[];
            for (const programa of programas) {
                if (!programa || typeof programa.nombre!=='string') continue;
                const chip=document.createElement('span');
                chip.className='aliados-source-chip';
                chip.textContent=programa.nombre;
                alias.appendChild(chip);
            }
            if (!alias.childElementCount) {
                const vacio=document.createElement('small');vacio.className='text-muted';
                vacio.textContent='Sin programas de Registro';alias.appendChild(vacio);
            }
        }
        const origen=modal.querySelector('[data-convenio-origin-label]');
        const descripcion=modal.querySelector('[data-convenio-origin-description]');
        const manual=data.origen_tipo==='manual';
        if(origen)origen.textContent=manual?'Relación manual':'Vinculación';
        if(descripcion)descripcion.textContent=manual?'Sin formalización documental':(data.fecha_recibido && data.fecha_recibido!=='—'?'Formalizado '+data.fecha_recibido:'Fecha de formalización no registrada');
        const notas=modal.querySelector('[data-convenio-modal-notes]');
        if(notas)notas.classList.toggle('text-muted',!String(data.notas || '').trim());
        if (!link) return;
        const kind = data.origen_tipo === 'manual' ? 'manual' : 'institucional';
        const id = Number(data.origen_id || data.seguimiento_id || 0);
        if (!Number.isSafeInteger(id) || id <= 0) {
            link.hidden = true;
            return;
        }
        const url = new URL(root.dataset.convocatoriaBaseUrl || link.href, window.location.href);
        if (url.origin !== window.location.origin) {
            link.hidden = true;
            return;
        }
        const stateId = Number(data.estado_id || 0);
        if (Number.isSafeInteger(stateId) && stateId > 0) {
            url.searchParams.set('territorio_id', String(stateId));
        }
        url.searchParams.set('desde_convenio', '1');
        url.searchParams.set('abrir_nueva_convocatoria', '1');
        url.searchParams.set('aliado_tipo', kind);
        url.searchParams.set('aliado_id', String(id));
        if (data.estado) url.searchParams.set('estado_nombre', String(data.estado));
        if (data.institucion) url.searchParams.set('convenio_nombre', String(data.institucion));
        if (kind === 'institucional' && Number(data.seguimiento_id) > 0) {
            url.searchParams.set('seguimiento_id', String(data.seguimiento_id));
        }
        link.href = url.href;
        link.hidden = false;
        const help = modal.querySelector('.crear-convocatoria-modal-help span');
        if (help) help.textContent =
            'Cada programa tiene su propia convocatoria e imagen; el enlace y QR de Registro son compartidos por el aliado.';
    }

    document.addEventListener('impe:convenio-abierto', event => refreshModal(event.detail));

    // Fallback para variantes personalizadas que abren el modal sin emitir
    // el evento legado. El evento en la fila ya fue procesado al llegar aquí.
    root.addEventListener('click', event => {
        const row = event.target.closest('[data-convenio-notification]');
        if (!row || !root.contains(row) || !event.target.closest('[data-convenio-open]')) return;
        let data;
        try { data = JSON.parse(row.dataset.convenio || '{}'); }
        catch (_) { return; }
        refreshModal(data);
    });

    if (rows.length) applyRowFilters();
    const requestedDetail = new URLSearchParams(window.location.search);
    let returnToManagement=null;
    try {
        const value=requestedDetail.get('volver_gestion');
        if(value){const candidate=new URL(value,window.location.origin);if(candidate.origin===window.location.origin&&candidate.pathname===window.location.pathname&&candidate.searchParams.get('controller')==='convocatoria'&&candidate.searchParams.get('action')==='index')returnToManagement=candidate;}
    } catch (_) { /* Un retorno inválido no altera el directorio. */ }
    if(returnToManagement){
        const detailModal=document.getElementById('modalConvenioCrearConvocatoria');let closeRequested=false;
        detailModal?.addEventListener('click',event=>{if(event.target===detailModal||event.target.closest('[data-bs-dismiss="modal"],.btn-close'))closeRequested=true;});
        detailModal?.addEventListener('keydown',event=>{if(event.key==='Escape')closeRequested=true;});
        detailModal?.addEventListener('hidden.bs.modal',()=>{if(closeRequested){closeRequested=false;window.location.assign(returnToManagement.href);}});
    }
    const detailType = requestedDetail.get('detalle_tipo');
    const detailId = Number(requestedDetail.get('detalle_id'));
    if (['manual', 'institucional'].includes(detailType) && detailId > 0) {
        const row = rows.find(item => {
            try { const data = JSON.parse(item.dataset.convenio || '{}'); return (data.origen_tipo || 'institucional') === detailType && Number(data.origen_id || data.seguimiento_id) === detailId; }
            catch (_) { return false; }
        });
        row?.querySelector('[data-convenio-open]')?.click();
    }
});
