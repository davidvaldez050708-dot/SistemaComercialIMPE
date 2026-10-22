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
    const convenios = (rows, estado) => {
        const unique = new Map();
        filtrar(rows, estado, '').forEach(row => row.convenios.forEach(c => unique.set(String(c.id), c)));
        return Array.from(unique.values()).sort((a, b) => a.nombre.localeCompare(b.nombre, 'es') || a.id - b.id);
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
        let rows = [], catalog = null, sequence = 0, abort = null, loading = false, ready = false;
        const option = (value, label) => {
            const o = doc.createElement('option'); o.value = String(value); o.textContent = label; return o;
        };
        const options = (field, values, placeholder, label) => {
            const prior = field.value;
            field.replaceChildren(option('', placeholder), ...values.map(r => option(r.id, label(r))));
            field.value = values.some(r => String(r.id) === prior) ? prior : '';
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
            options(agreement, convenios(rows, state.value), 'Todos los convenios', c => c.nombre + (c.referencia ? ' · ' + c.referencia : '') + ' · #' + c.id);
            const prior = select.value;
            const filtered = filtrar(rows, state.value, agreement.value);
            options(select, filtered, 'Selecciona una convocatoria', etiqueta);
            if (prior !== select.value) { clearImage(); }
            if (!select.value) { status.textContent = filtered.length ? '' : 'No hay convocatorias que coincidan con estos filtros.'; }
        };
        const load = () => {
            if (!catalog) {
                state.disabled = agreement.disabled = select.disabled = true;
                catalog = request(endpoint, { headers: { 'X-Requested-With': 'fetch' } }).then(async r => {
                    const data = await r.json();
                    if (!r.ok || !data.ok || !Array.isArray(data.convocatorias) || !Array.isArray(data.estados)) { throw new Error('Catálogo no disponible'); }
                    rows = data.convocatorias;
                    options(state, data.estados, 'Todos los estados', e => e.nombre);
                    list();
                    return data;
                }).catch(() => {
                    catalog = null;
                    status.textContent = 'No fue posible cargar las convocatorias. Puedes redactar sin seleccionar una.';
                    return null;
                }).finally(() => { state.disabled = agreement.disabled = select.disabled = false; });
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
            clearImage(); state.value = ''; agreement.value = ''; select.value = ''; list(); status.textContent = '';
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
            state.value = stateId; agreement.value = ''; list();
            agreement.value = String(row.filtro_convenio || ''); list(); select.value = String(row.id);
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
