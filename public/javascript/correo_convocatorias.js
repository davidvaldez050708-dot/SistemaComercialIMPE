(function (root, factory) {
    'use strict';
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
    if (root.document) { root.document.addEventListener('DOMContentLoaded', () => api.iniciar(root.document, root.fetch.bind(root))); }
}(globalThis, function () {
    'use strict';
    const coincide = (row, estado, convenio) =>
        (!estado || row.estados.some(e => String(e.id) === String(estado))) &&
        (!convenio || row.convenios.some(c => String(c.id) === String(convenio)));
    const filtrar = (rows, estado, convenio) => rows.filter(row => coincide(row, estado, convenio));
    const convenios = (rows, estado, catalogo) => {
        const unique = new Map();
        const relacionados = new Set(filtrar(rows, estado, '').flatMap(row => row.convenios.map(c => String(c.id))));
        const disponibles = Array.isArray(catalogo) ? catalogo : rows.flatMap(row => row.convenios);
        disponibles.forEach(c => {
            if (!estado || (c.estado_id ? String(c.estado_id) === String(estado) : relacionados.has(String(c.id)))) { unique.set(String(c.id), c); }
        });
        return Array.from(unique.values()).sort((a, b) => a.nombre.localeCompare(b.nombre, 'es') || String(a.id).localeCompare(String(b.id), 'es', { numeric: true }));
    };
    const etiqueta = row => [row.titulo, row.convenios.map(c => c.nombre).join(', '), row.estados.map(e => e.nombre).join(', '), '#' + row.id].filter(Boolean).join(' · ');

    function iniciar(doc, request) {
        const modal = doc.getElementById('modalCorreoMarketing');
        const q = selector => modal?.querySelector(selector);
        const state = q('[data-convocatoria-estado]');
        const agreement = q('[data-convocatoria-convenio]');
        const select = q('[data-marketing-mail-convocatoria]');
        if (!modal || !state || !agreement || !select) { return; }
        const status = q('[data-convocatoria-status]');
        const sha = q('[data-convocatoria-imagen-sha]');
        const preview = q('[data-convocatoria-preview]');
        const image = q('[data-convocatoria-imagen]');
        const caption = q('[data-convocatoria-caption]');
        const endpoint = 'index.php?controller=correoMarketing&action=convocatorias';
        let rows = [], agreements = null, catalog = null, sequence = 0, abort = null, loading = false, ready = false;
        const option = (value, label) => {
            const o = doc.createElement('option'); o.value = String(value); o.textContent = label; return o;
        };
        // El acabado global clona las opciones una sola vez. Actualizar únicamente
        // los tres menús de convocatorias, conservando sus clases y el select original.
        const uniform = field => {
            const wrapper = field.nextElementSibling;
            if (!wrapper?.matches?.('.uniform-select')) { return; }
            const menu = wrapper.querySelector('.uniform-select-menu');
            const trigger = wrapper.querySelector('[data-uniform-select-trigger]');
            const label = wrapper.querySelector('[data-uniform-select-label]');
            if (!menu || !trigger || !label) { return; }
            const buttons = Array.from(field.options, (o, index) => {
                const button = doc.createElement('button');
                button.type = 'button'; button.className = 'uniform-select-option';
                button.setAttribute('role', 'option'); button.setAttribute('data-uniform-select-value', o.value);
                button.dataset.uniformSelectIndex = String(index); button.textContent = o.textContent;
                button.disabled = Boolean(o.disabled); button.hidden = Boolean(o.hidden);
                button.classList.toggle('is-selected', index === field.selectedIndex);
                button.setAttribute('aria-selected', index === field.selectedIndex ? 'true' : 'false');
                button.addEventListener('click', () => {
                    if (field.disabled || button.disabled) { return; }
                    const changed = field.selectedIndex !== index; field.selectedIndex = index;
                    uniform(field);
                    if (changed) { field.dispatchEvent(new Event('change', { bubbles: true })); }
                });
                return button;
            });
            menu.replaceChildren(...buttons);
            label.textContent = field.options[field.selectedIndex]?.textContent || '';
            trigger.disabled = Boolean(field.disabled); trigger.classList.toggle('is-placeholder', !field.value);
            wrapper.classList.remove('is-open'); trigger.setAttribute('aria-expanded', 'false');
        };
        const options = (field, values, placeholder, label) => {
            const prior = field.value;
            field.replaceChildren(option('', placeholder), ...values.map(r => option(r.id, label(r))));
            field.value = values.some(r => String(r.id) === prior) ? prior : '';
            uniform(field);
        };
        const clearImage = () => {
            sequence++; abort?.abort(); loading = false; ready = false; sha.value = '';
            preview.classList.add('d-none'); image.removeAttribute('src'); caption.textContent = '';
        };
        const showImage = row => {
            sha.value = row?.imagen_sha || '';
            const url = String(row?.imagen_url || '');
            const valid = url.startsWith('index.php?controller=correoMarketing&action=imagenConvocatoria&');
            preview.classList.toggle('d-none', !valid);
            if (valid) { image.src = url; image.alt = row.titulo; }
            else { image.removeAttribute('src'); }
            caption.textContent = row ? etiqueta(row) : '';
            status.textContent = row?.imagen_mensaje || '';
        };
        const list = () => {
            options(agreement, convenios(rows, state.value, agreements), 'Todos los convenios', c => c.nombre + (c.referencia ? ' · ' + c.referencia : '') + ' · #' + (c.origen_id || c.id) + (c.origen === 'manual' ? ' (manual)' : ''));
            const prior = select.value;
            const filtered = filtrar(rows, state.value, agreement.value);
            options(select, filtered, 'Selecciona una convocatoria', etiqueta);
            if (prior !== select.value) { clearImage(); }
            if (!select.value) { status.textContent = filtered.length ? '' : 'No hay convocatorias que coincidan con estos filtros.'; }
        };
        const load = () => {
            if (!catalog) {
                state.disabled = agreement.disabled = select.disabled = true;
                [state, agreement, select].forEach(uniform);
                catalog = request(endpoint, { headers: { 'X-Requested-With': 'fetch' } }).then(async r => {
                    const data = await r.json();
                    if (!r.ok || !data.ok || !Array.isArray(data.convocatorias) || !Array.isArray(data.estados)) { throw new Error('Catálogo no disponible'); }
                    rows = data.convocatorias;
                    agreements = Array.isArray(data.convenios) ? data.convenios : null;
                    options(state, data.estados, 'Todos los estados', e => e.nombre);
                    list();
                    if (data.avisos?.includes('CONVENIOS_CONSULTA_NO_DISPONIBLE')) { status.textContent = 'Los estados y las convocatorias están disponibles; falta revisar la consulta de convenios.'; }
                    return data;
                }).catch(() => {
                    catalog = null;
                    status.textContent = 'No fue posible cargar las convocatorias. Puedes redactar sin seleccionar una.';
                    return null;
                }).finally(() => { state.disabled = agreement.disabled = select.disabled = false; [state, agreement, select].forEach(uniform); });
            }
            return catalog;
        };
        const choose = async () => {
            clearImage();
            if (!select.value) { status.textContent = ''; return; }
            const id = select.value, version = sequence;
            loading = true; status.textContent = 'Cargando imagen de la convocatoria…'; abort = new AbortController();
            try {
                const r = await request(endpoint + '&id=' + encodeURIComponent(id), { signal: abort.signal, headers: { 'X-Requested-With': 'fetch' } });
                const data = await r.json();
                if (version !== sequence || select.value !== id) { return; }
                if (!r.ok || !data.ok || String(data.convocatoria?.id) !== id) { throw new Error('Convocatoria no disponible'); }
                showImage(data.convocatoria); ready = true;
            } catch (error) {
                if (version === sequence && error.name !== 'AbortError') { status.textContent = 'No se pudo cargar la convocatoria. Vuelve a seleccionarla o limpia la selección para enviar sin ella.'; }
            } finally { if (version === sequence) { loading = false; } }
        };
        const reset = () => {
            clearImage(); state.value = ''; uniform(state); agreement.value = ''; select.value = ''; list(); status.textContent = '';
        };
        modal.addEventListener('shown.bs.modal', load);
        modal.addEventListener('correo:convocatoria-limpiar', reset);
        q('[data-convocatoria-limpiar]').addEventListener('click', reset);
        state.addEventListener('change', list); agreement.addEventListener('change', list);
        select.addEventListener('change', choose);
        image.addEventListener('error', () => {
            preview.classList.add('d-none'); status.textContent = 'No se pudo mostrar la vista previa de la imagen.';
        });
        const guard = event => {
            if (select.value && (loading || !ready)) {
                event.preventDefault(); event.stopImmediatePropagation();
                status.textContent = loading ? 'Espera a que termine de cargar la convocatoria.' : 'Revisa la convocatoria o limpia la selección antes de continuar.';
            }
        };
        q('[data-marketing-mail-form]').addEventListener('submit', guard, true);
        q('[data-marketing-mail-draft]').addEventListener('click', guard, true);
        modal.addEventListener('correo:convocatoria-borrador', async event => {
            const row = event.detail;
            if (!row) { return; }
            const version = sequence;
            await load();
            if (version !== sequence) { return; }
            if (!rows.some(r => r.id === row.id)) { rows = rows.concat(row); }
            const stateId = String(row.filtro_estado || '');
            if (stateId && !Array.from(state.options).some(o => o.value === stateId)) {
                const oldState = row.estados.find(e => String(e.id) === stateId);
                if (oldState) { state.appendChild(option(oldState.id, oldState.nombre)); }
            }
            state.value = stateId; uniform(state); agreement.value = ''; list();
            agreement.value = String(row.filtro_convenio || ''); list(); select.value = String(row.id);
            uniform(select);
            showImage(row); ready = true;
        });
        const detail = doc.getElementById('modalCorreoMarketingDetalle');
        detail?.addEventListener('correo:convocatoria-detalle', event => {
            const row = event.detail;
            const block = detail.querySelector('[data-convocatoria-detalle]');
            const img = detail.querySelector('[data-convocatoria-detalle-imagen]');
            const label = detail.querySelector('[data-convocatoria-detalle-caption]');
            if (!block || !img || !label) { return; }
            const url = String(row?.imagen_url || '');
            const valid = url.startsWith('index.php?controller=correoMarketing&action=imagenConvocatoria&');
            block.classList.toggle('d-none', !row);
            img.classList.toggle('d-none', !valid);
            if (valid) { img.src = url; img.alt = row.titulo; } else { img.removeAttribute('src'); }
            label.textContent = row ? etiqueta(row) + (valid ? '' : ' · Imagen no disponible') : '';
        });
        return { load, choose, reset };
    }
    return { filtrar, convenios, etiqueta, iniciar };
}));
