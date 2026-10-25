(function () {
    'use strict';

    const numeros=typeof module!=='undefined'&&module.exports?require('./telefonia_numeros.js'):window.IMPE_PHONE_NUMBERS;

    // Reutiliza los mismos criterios de normalización del marcador,
    // sin marcar extensiones internas ni números incompletos.
    function normalizarNumeroDevolucion(valor) {
        const original = String(valor || '').trim();
        if (!/^\+?[\d\s().-]+$/.test(original)) return '';
        let digitos = numeros.key(original);
        // Descartar números placeholder del historial, como 0000000000.
        if (/^52([0-9])\1{9}$/.test(digitos)) return '';
        return /^[1-9][0-9]{10,14}$/.test(digitos) ? digitos : '';
    }

    function puedeLlamarAlRegistro(call) {
        return normalizarNumeroDevolucion(call?.numero || '') !== '';
    }

    function coincideEstadoRecepcion(call, selected) {
        if (!selected) return true;
        if (selected === 'pendientes') return Boolean(call.requiere_resultado);
        const grupos = {
            respondidas: ['Atendida','Completada'],
            'sin-respuesta': ['Perdida','Sin respuesta'],
            activas: ['En curso','Sonando','Marcando'],
            'no-completadas': ['Ocupada','Cancelada','Fallida','Número inexistente',
                'No disponible','Sin saldo','Límite excedido']
        };
        return grupos[selected] ? grupos[selected].includes(call.estado) : call.estado === selected;
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {
            normalizarNumeroDevolucion: normalizarNumeroDevolucion,
            puedeLlamarAlRegistro: puedeLlamarAlRegistro,
            coincideEstadoRecepcion: coincideEstadoRecepcion
        };
    }
    if (typeof document === 'undefined') return;

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.querySelector('[data-marketing-reception]');
        if (!root) return;

        const tableBody = root.querySelector('[data-marketing-reception-rows]');
        const search = root.querySelector('[data-marketing-reception-search]');
        // Al llegar desde Historial, el número queda visible en el filtro.
        const buscarInicial = new URLSearchParams(window.location.search).get('buscar');
        if (search && buscarInicial) search.value = buscarInicial.slice(0, 60);
        const filter = root.querySelector('[data-marketing-reception-filter]');
        const typeFilter = root.querySelector('[data-marketing-reception-type]');
        const count = root.querySelector('[data-marketing-reception-count]');
        const pages = root.querySelector('[data-marketing-reception-pages]');
        const refresh = root.querySelector('[data-marketing-reception-refresh]');
        const detailPanel = root.querySelector('[data-marketing-reception-details]');
        const detailToggle = root.querySelector('[data-marketing-reception-details-toggle]');
        const detailLabel = root.querySelector('[data-marketing-reception-details-label]');
        const resultDialog = root.querySelector('[data-marketing-result-dialog]');
        const resultForm = root.querySelector('[data-marketing-result-form]');
        const resultSelect = root.querySelector('[data-marketing-result-select]');
        const resultNote = root.querySelector('[data-marketing-result-note]');
        const resultSave = root.querySelector('[data-marketing-result-save]');
        let resultCallId = '';
        let savingResult = false;
        let resultModalOpen = false;
        let resultNeedsRefresh = false;
        let closingAfterSave = false;
        let resultTrigger = null;
        const resultModal = function () {
            return resultDialog && window.bootstrap?.Modal?.getOrCreateInstance(resultDialog);
        };
        const metrics = {
            total: root.querySelector('[data-marketing-reception-total]'),
            atendidas: root.querySelector('[data-marketing-reception-answered]'),
            perdidas: root.querySelector('[data-marketing-reception-missed]'),
            salientes: root.querySelector('[data-marketing-reception-outgoing]'),
            transferidas: root.querySelector('[data-marketing-reception-transferred]'),
            sinContacto: root.querySelector('[data-marketing-reception-no-contact]')
        };
        const telefono = window.IMPE_TELEPHONY_PERSISTENT || null;
        let puedeDevolver = false;
        let marcacionEnCurso = false;
        const explicarDevolucion = function (mensaje, error) {
            if (!mensaje || !window.bootstrap?.Toast) return;
            let container = document.querySelector('.toast-container');
            if (!container) {
                container = node('div','toast-container position-fixed top-0 end-0 p-3');
                document.body.appendChild(container);
            }
            // El contenedor es hermano del modal y permanece sobre su fondo.
            container.style.zIndex = '1095';
            const toast = node('div','toast system-toast' + (error ? ' system-toast-error' : ''));
            toast.setAttribute('role',error ? 'alert' : 'status');
            toast.setAttribute('aria-live',error ? 'assertive' : 'polite');
            toast.setAttribute('aria-atomic','true');
            const body = node('div','toast-body');
            const icon = node('i','bi ' + (error ? 'bi-exclamation-circle' : 'bi-check2-circle'));
            icon.setAttribute('aria-hidden','true');
            body.append(icon,node('span','',mensaje));
            toast.appendChild(body);
            container.appendChild(toast);
            const instance = window.bootstrap.Toast.getOrCreateInstance(toast,{
                autohide:true, delay:error ? 4500 : 3200
            });
            toast.addEventListener('hidden.bs.toast',function () {instance.dispose();toast.remove();});
            instance.show();
        };
        const pageSize = 8;
        let calls = [];
        let page = 1;
        let loading = false;
        let initialized = false;
        let receptionConfigured = false;
        // Abrir la fila es inmediato; la confirmación del audio privado sigue
        // pendiente hasta que el servidor entregue una respuesta válida.
        function mostrarComprobacion(slot) {
            slot.setAttribute('aria-busy', 'true');
            const status = node('div', 'marketing-reception-muted');
            status.setAttribute('role', 'status');
            const spinner = node('span', 'spinner-border spinner-border-sm');
            spinner.setAttribute('aria-hidden', 'true');
            status.append(spinner, document.createTextNode(' Preparando grabación…'));
            slot.replaceChildren(status);
        }
        function mostrarErrorGrabacion(slot, mensaje) {
            slot.replaceChildren(node('p', 'linkage-incoming-player-error',
                mensaje || 'No fue posible consultar la grabación. Inténtalo nuevamente.'));
        }

        function formatDuration(seconds) {
            const s = Math.max(0, Number(seconds) || 0);
            const hours = Math.floor(s / 3600);
            const minutes = Math.floor((s % 3600) / 60);
            const secs = Math.floor(s % 60);
            const mm = String(minutes).padStart(2, '0');
            const ss = String(secs).padStart(2, '0');
            return hours > 0 ? hours + ':' + mm + ':' + ss : mm + ':' + ss;
        }

        function normalizeNumber(text) {
            return numeros.key(text);
        }

        function node(tag, className, content) {
            const el = document.createElement(tag);
            if (className) el.className = className;
            if (content !== undefined && content !== null) el.textContent = String(content);
            return el;
        }

        function addCell(row, value, className) {
            const cell = node('td', className || '', value);
            row.appendChild(cell);
            return cell;
        }

        function recordingRow(call, index, row) {
            const td = addCell(row, '');
            if (call.excluir_grabacion) {
                const historyOnly = node('span','marketing-reception-muted','Solo historial');
                historyOnly.title = 'No se ofrece audio de llamadas sin contacto o registradas como buzón, no disponible o número incorrecto.';
                td.appendChild(historyOnly);
                return null;
            }
            if (!call.tiene_grabacion) {
                td.appendChild(node('span', 'marketing-reception-muted',
                    call.estado === 'Transferida' ? 'No disponible' :
                    call.grabacion_salida_pendiente ? 'Audio saliente pendiente' :
                    'Sin grabación'));
                return null;
            }
            if (call.estado === 'Transferida') {
                const audioId = 'marketing-transfer-record-' + index;
                const endpoint = new URL('prueba_telefonia/api/grabacion_transferida_pbx.php',
                    document.baseURI);
                endpoint.searchParams.set('pbx_call_id', String(call.pbx_call_id || ''));
                const button = node('button',
                    'linkage-call-audio-state is-available linkage-call-recording-toggle');
                button.type = 'button';
                button.dataset.marketingPbxAudio = endpoint.toString();
                button.setAttribute('aria-controls', audioId);
                button.setAttribute('aria-expanded','false');
                button.innerHTML = '<i class="bi bi-record-circle" aria-hidden="true"></i>' +
                    '<span>Consultar grabación</span>' +
                    '<i class="bi bi-chevron-down linkage-call-recording-chevron" aria-hidden="true"></i>';
                td.appendChild(button);
                const detail = node('tr','telephony-history-recording-row');
                detail.id = audioId;
                detail.hidden = true;
                const content = node('td');
                content.colSpan = 9;
                const slot = node('div','telephony-history-recording-slot');
                slot.dataset.marketingPbxAudios = '';
                content.appendChild(slot);
                detail.appendChild(content);
                return detail;
            }
            const audioId = 'marketing-reception-record-' + index;
            const url = new URL(root.dataset.recordingUrl, window.location.href);
            url.searchParams.set('pbx_call_id', String(call.pbx_call_id || ''));
            url.searchParams.set('tipo',
                call.tipo === 'Saliente' ? 'saliente' : 'entrante');
            const button = node('button',
                'linkage-call-audio-state linkage-call-recording-toggle marketing-reception-record-check');
            button.type = 'button';
            button.setAttribute('data-sales-recording-toggle', '');
            button.setAttribute('data-recording-url', url.toString());
            button.setAttribute('data-recording-seconds', String(call.segundos || 0));
            button.setAttribute('aria-controls', audioId);
            button.setAttribute('aria-expanded', 'false');
            button.title = 'Zadarma reportó una grabación; comprobar disponibilidad.';
            button.innerHTML = '<i class="bi bi-record-circle" aria-hidden="true"></i>' +
                '<span>Consultar grabación</span>' +
                '<i class="bi bi-chevron-down linkage-call-recording-chevron" aria-hidden="true"></i>';
            td.appendChild(button);
            const detail = node('tr', 'telephony-history-recording-row');
            detail.id = audioId;
            detail.hidden = true;
            detail.setAttribute('data-sales-recording-row', '');
            const content = node('td');
            content.colSpan = 9;
            const slot = node('div', 'telephony-history-recording-slot');
            slot.setAttribute('data-sales-recording-slot', '');
            content.appendChild(slot);
            detail.appendChild(content);
            return detail;
        }

        function drawRow(call, index) {
            const row = node('tr');
            addCell(row, String(call.fecha || '').slice(0, 16).replace('T', ' '));
            const tipo = String(call.tipo || 'Entrante');
            const tipoCelda = addCell(row, '', 'marketing-reception-type-cell');
            const tipoBadge = node('span',
                'marketing-reception-direction ' +
                (tipo === 'Saliente' ? 'is-outgoing' : 'is-incoming'));
            tipoBadge.innerHTML = '<i class="bi ' +
                (tipo === 'Saliente' ? 'bi-telephone-outbound' : 'bi-telephone-inbound') +
                '" aria-hidden="true"></i>';
            tipoBadge.appendChild(node('span', '', tipo));
            tipoCelda.appendChild(tipoBadge);
            const numeroCelda = addCell(
                row, '', 'marketing-reception-number'
            );
            numeroCelda.appendChild(node('span', '',
                numeros.display(call.numero) || 'Número no disponible'));
            const telefonoNormalizado = normalizarNumeroDevolucion(call.numero);
            const accion = addCell(row, '', 'marketing-reception-action-cell');
            if (puedeLlamarAlRegistro(call)) {
                const llamar = node('button', 'marketing-reception-call');
                llamar.type = 'button';
                llamar.innerHTML =
                    '<i class="bi bi-telephone-outbound" aria-hidden="true"></i>' +
                    '<span>Llamar</span>';
                llamar.dataset.marketingCallback = telefonoNormalizado;
                llamar.dataset.marketingCallOrigin = String(call.pbx_call_id || '');
                llamar.disabled = !puedeDevolver || marcacionEnCurso;
                llamar.title = !puedeDevolver
                    ? 'Requiere permiso para llamadas salientes y una extensión habilitada'
                    : 'Llamar a este número';
                llamar.setAttribute('aria-label',
                    'Llamar al ' + String(call.numero || 'número'));
                accion.appendChild(llamar);
            } else {
                accion.appendChild(node('span', 'marketing-reception-muted', '—'));
            }
            const statusCell = addCell(row, '');
            const status = String(call.estado || '');
            const badge = node('span', 'marketing-reception-status',
                status === 'Completada' ? 'Respondida' : status);
            badge.title = status === 'Completada'
                ? 'La centralita confirmó una respuesta; puede ser una persona, buzón o locución.'
                : 'Estado confirmado por la centralita';
            if (status === 'Atendida') badge.classList.add('is-answered');
            if (status === 'Perdida') badge.classList.add('is-missed');
            if (status === 'Transferida') badge.classList.add('is-transferred');
            if (status === 'Completada') badge.classList.add('is-answered');
            if (['Sin respuesta','Ocupada','Fallida','Cancelada','Número inexistente',
                'No disponible','Sin saldo','Límite excedido'].includes(status))
                badge.classList.add('is-missed');
            statusCell.appendChild(badge);
            if (status === 'Transferida' && call.estado_cierre &&
                !['Completada','Atendida','En curso','Marcando','Sonando'].includes(call.estado_cierre)) {
                badge.title += ' · Cierre: ' + call.estado_cierre;
            }
            const resultCell = addCell(row,'','marketing-reception-result-cell');
            if (call.resultado) {
                const observed = node('span','marketing-reception-result-label',call.resultado_etiqueta || 'Registrado');
                observed.title = 'Registrado por recepción' + (call.nota ? ': ' + call.nota : '');
                resultCell.appendChild(observed);
                if (call.nota) resultCell.appendChild(node('span','marketing-reception-result-note',call.nota));
            } else if (call.requiere_resultado && resultDialog && root.dataset.resultUrl) {
                const edit = node('button','marketing-reception-result-edit',
                    'Registrar resultado');
                edit.type = 'button';
                edit.dataset.marketingResult = String(call.pbx_call_id || '');
                edit.setAttribute('aria-haspopup','dialog');
                resultCell.appendChild(edit);
            } else {
                resultCell.appendChild(node('span','marketing-reception-muted','—'));
            }
            addCell(row, formatDuration(call.segundos));
            addCell(row, call.destino_transferencia
                ? 'Ext. ' + call.destino_transferencia : '—',
                call.destino_transferencia ? '' : 'marketing-reception-muted');
            return [row, recordingRow(call, index, row)];
        }

        function renderList() {
            const query = normalizeNumber(search.value);
            const raw = String(search.value || '').trim().toLocaleLowerCase('es-MX');
            const selected = filter.value;
            const selectedType = typeFilter?.value || '';
            const filtered = calls.filter(function (call) {
                if (selectedType && call.tipo !== selectedType) return false;
                if (!coincideEstadoRecepcion(call, selected)) return false;
                if (!raw) return true;
                const phone = String(call.numero || '');
                const transfer = String(call.destino_transferencia || '');
                const direction = String(call.tipo || '');
                return phone.toLocaleLowerCase('es-MX').includes(raw) ||
                    transfer.includes(raw) ||
                    direction.toLocaleLowerCase('es-MX').includes(raw) ||
                    String(call.resultado_etiqueta || '').toLocaleLowerCase('es-MX').includes(raw) ||
                    (query !== '' &&
                        (normalizeNumber(phone).includes(query) ||
                            normalizeNumber(transfer).includes(query)));
            });
            const totalPages = Math.ceil(filtered.length / pageSize);
            page = Math.min(Math.max(1, page), Math.max(1, totalPages));
            const begin = (page - 1) * pageSize;
            const rows = [];
            filtered.slice(begin, begin + pageSize).forEach(function (call, idx) {
                drawRow(call, begin + idx).forEach(function (el) {
                    if (el) rows.push(el);
                });
            });
            if (rows.length === 0) {
                const empty = node('tr');
                addCell(empty, !initialized
                    ? 'Consultando el historial…'
                    : (!receptionConfigured
                        ? 'Tu usuario aún no tiene una extensión activa de recepción.'
                        : calls.length === 0
                            ? 'Todavía no hay llamadas registradas en esta extensión.'
                            : 'No hay llamadas que coincidan con la búsqueda.'),
                    'marketing-reception-placeholder').colSpan = 9;
                rows.push(empty);
            }
            tableBody.replaceChildren(...rows);

            count.textContent = filtered.length
                ? 'Mostrando ' + (begin + 1) + '–' +
                    Math.min(begin + pageSize, filtered.length) +
                    ' de ' + filtered.length + ' llamadas'
                : '0 llamadas visibles';

            const buttons = [];
            if (totalPages > 1) {
                const addButton = function (number, label, disabled, current) {
                    const button = node('button', 'marketing-reception-page', label);
                    button.type = 'button';
                    button.dataset.marketingPage = String(number);
                    button.disabled = disabled;
                    button.setAttribute('aria-label',
                        label === '‹' ? 'Página anterior' :
                            label === '›' ? 'Página siguiente' : 'Página ' + number);
                    if (current) {
                        button.classList.add('is-current');
                        button.setAttribute('aria-current', 'page');
                    }
                    buttons.push(button);
                };
                addButton(page - 1, '‹', page === 1, false);
                const selectedPages = new Set([1, totalPages]);
                for (let n = Math.max(1, page - 2); n <= Math.min(totalPages, page + 2); n++) {
                    selectedPages.add(n);
                }
                let previous = 0;
                Array.from(selectedPages).sort(function (a, b) { return a - b; })
                    .forEach(function (number) {
                        if (previous && number > previous + 1) {
                            buttons.push(node('span', 'marketing-reception-muted', '…'));
                        }
                        addButton(number, String(number), false, number === page);
                        previous = number;
                    });
                addButton(page + 1, '›', page === totalPages, false);
            }
            pages.hidden = buttons.length === 0;
            pages.replaceChildren(...buttons);
        }

        async function loadHistory(manual) {
            if (loading) return;
            if (resultModalOpen) return;
            // No cerrar el reproductor al refrescar, aunque el audio esté
            // pausado o el servidor todavía esté verificando su disponibilidad.
            if (!manual && Array.from(tableBody.querySelectorAll(
                '.telephony-history-recording-row')).some(row => !row.hidden)) return;
            loading = true;
            refresh.disabled = true;
            refresh.querySelector('i')?.classList.add('marketing-reception-spinning');
            try {
                const response = await fetch(root.dataset.historyUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {Accept: 'application/json', 'X-Requested-With': 'fetch'}
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.mensaje || 'No se pudo actualizar la recepción.');
                }
                const data = payload.recepcion || {};
                receptionConfigured = Boolean(data.configurada);
                calls = Array.isArray(data.llamadas) ? data.llamadas : [];
                metrics.total.textContent = String(data.total || 0);
                metrics.atendidas.textContent = String(data.atendidas || 0);
                metrics.perdidas.textContent = String(data.perdidas || 0);
                if (metrics.salientes) metrics.salientes.textContent =
                    String(data.salientes || 0);
                metrics.transferidas.textContent = String(data.transferidas || 0);
                if (metrics.sinContacto) metrics.sinContacto.textContent = String(data.sin_contacto || 0);
                initialized = true;
                renderList();
                if (manual) {
                    explicarDevolucion('Historial actualizado.',false);
                }
            } catch (error) {
                if (!initialized) {
                    tableBody.replaceChildren();
                    const row = node('tr');
                    addCell(row, error.message || 'No fue posible consultar las llamadas.',
                        'marketing-reception-placeholder').colSpan = 9;
                    tableBody.appendChild(row);
                    count.textContent = 'No se pudo actualizar el historial.';
                } else if (manual) {
                    explicarDevolucion(error.message || 'No se pudo actualizar.',true);
                }
            } finally {
                loading = false;
                refresh.disabled = false;
                refresh.querySelector('i')?.classList.remove('marketing-reception-spinning');
            }
        }

        // Solo se habilita la devolución cuando el backend confirme
        // el permiso del rol Y la capacidad saliente de esta extensión.
        if (telefono && window.IMPE_CAN_CALL_OUT === true) {
            void telefono.probe().then(function (configuracion) {
                puedeDevolver = Boolean(configuracion?.permite_salientes);
                if (initialized) renderList();
            }).catch(function () {
                puedeDevolver = false;
            });
        }

        // Abrir inmediatamente; la verificación remota sólo decide si puede
        // mostrarse audio, no si se puede desplegar la fila.
        tableBody.addEventListener('click', async function (event) {
            const button = event.target.closest('[data-marketing-pbx-audio]');
            if (!button) return;
            event.preventDefault();
            event.stopPropagation();
            const detail = document.getElementById(button.getAttribute('aria-controls'));
            if (!detail) return;
            if (!detail.hidden) {
                detail.querySelectorAll('audio').forEach(a => a.pause());
                detail.hidden = true;
                button.setAttribute('aria-expanded', 'false');
                button.classList.remove('is-open');
                return;
            }
            detail.hidden = false;
            button.setAttribute('aria-expanded', 'true');
            button.classList.add('is-open');
            const slot = detail.querySelector('[data-marketing-pbx-audios]');
            if (!slot || slot.dataset.recordingLoaded === '1' ||
                slot.dataset.recordingLoading === '1') return;
            slot.dataset.recordingLoading = '1';
            mostrarComprobacion(slot);
            const endpoint = new URL(button.dataset.marketingPbxAudio);
            endpoint.searchParams.set('verificar', '1');
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 12000);
            try {
                const response = await fetch(endpoint.toString(), {
                    credentials: 'same-origin', cache: 'no-store', signal: controller.signal
                });
                if (!response.ok) throw new Error('No se pudo verificar la grabación.');
                const info = await response.json();
                if (!info.ok || !info.disponible || !(info.segmentos > 0))
                    throw new Error('La grabación todavía está en preparación.');
                if (!detail.isConnected) return;
                slot.replaceChildren();
                if (!window.IMPE_TELEPHONY_SEGMENTS?.create?.(
                    slot, endpoint.toString(), info.segmentos, info.segmentos_info || [])) {
                    throw new Error('No fue posible preparar el reproductor.');
                }
                slot.dataset.recordingLoaded = '1';
            } catch (error) {
                if (detail.isConnected) mostrarErrorGrabacion(slot,
                    error.name === 'AbortError'
                        ? 'La consulta tardó demasiado. Inténtalo nuevamente.'
                        : error.message);
            } finally {
                window.clearTimeout(timeout);
                delete slot.dataset.recordingLoading;
                slot.removeAttribute('aria-busy');
            }
        });

        // Cada llamada normal continúa validándose desde el endpoint
        // privado. La fila, el estado de carga y su cierre son inmediatos.
        tableBody.addEventListener('click', async function (event) {
            const button = event.target.closest('[data-sales-recording-toggle]');
            if (!button || button.dataset.recordingVerified === '1') return;
            event.preventDefault();
            event.stopPropagation();
            const detail = document.getElementById(button.getAttribute('aria-controls'));
            if (!detail) return;
            if (!detail.hidden) {
                detail.hidden = true;
                button.setAttribute('aria-expanded', 'false');
                button.classList.remove('is-open');
                return;
            }
            detail.hidden = false;
            button.setAttribute('aria-expanded', 'true');
            button.classList.add('is-open');
            const slot = detail.querySelector('[data-sales-recording-slot]');
            if (!slot || button.dataset.recordingChecking === '1') return;
            button.dataset.recordingChecking = '1';
            mostrarComprobacion(slot);
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 12000);
            try {
                const url = new URL(button.dataset.recordingUrl, window.location.href);
                url.searchParams.set('verificar', '1');
                const response = await fetch(url.toString(), {
                    credentials: 'same-origin', cache: 'no-store',
                    headers: {Accept: 'application/json'}, signal: controller.signal
                });
                const result = await response.json();
                if (!response.ok || !result.ok)
                    throw new Error('No fue posible verificar la grabación.');
                if (!result.disponible)
                    throw new Error('La grabación todavía está en preparación.');
                if (!detail.isConnected) return;
                button.dataset.recordingVerified = '1';
                button.classList.add('is-available');
                button.title = 'Consultar grabación';
                slot.replaceChildren();
                if (!detail.hidden) {
                    // El reproductor compartido de Asesor conserva los mismos
                    // controles. El cambio de fila es síncrono y no parpadea.
                    detail.hidden = true;
                    button.setAttribute('aria-expanded', 'false');
                    button.click();
                }
            } catch (error) {
                if (!detail.isConnected) return;
                mostrarErrorGrabacion(slot,
                    error.name === 'AbortError'
                        ? 'La consulta tardó demasiado. Inténtalo nuevamente.'
                        : error.message);
                button.title = 'La grabación no pudo verificarse. Puedes volver a intentarlo.';
            } finally {
                window.clearTimeout(timeout);
                delete button.dataset.recordingChecking;
                slot.removeAttribute('aria-busy');
            }
        }, true);

        tableBody.addEventListener('click', function (event) {
            const button = event.target.closest('[data-marketing-result]');
            if (!button || !resultDialog || savingResult) return;
            const call = calls.find(c => c.pbx_call_id === button.dataset.marketingResult);
            if (!call?.requiere_resultado) return;
            const modal = resultModal();
            if (!modal) {
                explicarDevolucion('No se pudo abrir el formulario. Actualiza la página.',true);
                return;
            }
            resultCallId = call.pbx_call_id;
            resultTrigger = button;
            root.querySelector('[data-marketing-result-phone]').textContent =
                (call.tipo || 'Llamada') + ' · ' + (call.numero || 'Número privado');
            resultSelect.value = call.resultado || '';
            resultNote.value = call.nota || '';
            modal.show(button);
        });
        resultDialog?.addEventListener('show.bs.modal', function () {
            resultModalOpen = true;
        });
        resultDialog?.addEventListener('shown.bs.modal', function () {
            resultSelect.focus({preventScroll:true});
        });
        resultDialog?.addEventListener('hide.bs.modal', function (event) {
            if (savingResult && !closingAfterSave) event.preventDefault();
        });
        resultDialog?.addEventListener('hidden.bs.modal', function () {
            resultModalOpen = false;
            closingAfterSave = false;
            resultCallId = '';
            if (resultTrigger?.isConnected) resultTrigger.focus({preventScroll:true});
            else filter?.focus({preventScroll:true});
            if (resultNeedsRefresh) {
                resultNeedsRefresh = false;
                void loadHistory(false);
            }
        });
        resultForm?.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (savingResult || !resultCallId || !resultForm.reportValidity()) return;
            savingResult = true;
            resultSave.disabled = true;
            resultSelect.disabled = true;
            resultNote.disabled = true;
            resultDialog.querySelectorAll('[data-bs-dismiss="modal"]').forEach(b=>b.disabled=true);
            try {
                const body = new URLSearchParams({
                    csrf_token:root.dataset.resultCsrf || '', pbx_call_id:resultCallId,
                    resultado:resultSelect.value, nota:resultNote.value.trim()
                });
                const response = await fetch(root.dataset.resultUrl, {
                    method:'POST', credentials:'same-origin', cache:'no-store', body,
                    headers:{Accept:'application/json'}
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.mensaje || 'No se pudo guardar.');
                const call = calls.find(c=>c.pbx_call_id===resultCallId);
                if (call) Object.assign(call,payload.registro,{requiere_resultado:false});
                if(window.parent!==window)window.parent.postMessage({type:'IMPE_RECEPTION_RESULT_SAVED',id:resultCallId},window.location.origin);
                resultNeedsRefresh = true;
                closingAfterSave = true;
                resultModal().hide();
                renderList();
                explicarDevolucion('Resultado guardado.',false);
            } catch(error) {
                explicarDevolucion(error.message || 'No se pudo guardar. Inténtalo nuevamente.',true);
            } finally {
                savingResult = false;
                resultSave.disabled = false;
                resultSelect.disabled = false;
                resultNote.disabled = false;
                resultDialog.querySelectorAll('[data-bs-dismiss="modal"]').forEach(b=>b.disabled=false);
            }
        });

        // Llamar desde cualquier estado precarga el número en el MISMO
        // teléfono flotante. El usuario confirma pulsando Llamar.
        tableBody.addEventListener('click', function (event) {
            const boton = event.target.closest('[data-marketing-callback]');
            if (!boton || boton.disabled || !telefono || !puedeDevolver) return;
            const destino = normalizarNumeroDevolucion(boton.dataset.marketingCallback);
            if (!destino) {
                explicarDevolucion('El número no permite devolución automática.', true);
                return;
            }
            const estado = telefono.getState?.();
            if (estado?.active) {
                explicarDevolucion('Finaliza primero la llamada que tienes activa.', true);
                return;
            }
            const abierto = telefono.openDialer?.({
                number: destino,
                context: {
                    type: 'MARKETING_CALLBACK',
                    llamada_origen: String(boton.dataset.marketingCallOrigin || '')
                }
            });
            explicarDevolucion(
                abierto ? '' : 'No se pudo abrir el marcador. Actualiza la página.',
                !abierto
            );
        });
        window.addEventListener('message',function(event){
            if(event.origin===window.location.origin && event.source===window.parent && event.data?.type==='IMPE_RECEPTION_RESULT_SAVED')void loadHistory(false);
        });

        search.addEventListener('input', function () {
            page = 1;
            renderList();
        });
        filter.addEventListener('change', function () {
            page = 1;
            renderList();
        });
        typeFilter?.addEventListener('change', function () {
            page = 1;
            renderList();
        });
        pages.addEventListener('click', function (event) {
            const button = event.target.closest('button[data-marketing-page]');
            if (!button || button.disabled) return;
            const next = Number(button.dataset.marketingPage);
            if (Number.isInteger(next) && next > 0) {
                page = next;
                renderList();
            }
        });
        detailToggle?.addEventListener('click', function () {
            if (!detailPanel) return;
            const open = detailPanel.hidden;
            detailPanel.hidden = !open;
            detailToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            detailToggle.classList.toggle('is-expanded', open);
            if (detailLabel) detailLabel.textContent =
                open ? 'Ocultar historial' : 'Ver historial';
            if (open && !loading) void loadHistory(false);
        });
        refresh.addEventListener('click', function () {
            void loadHistory(true);
        });

        void loadHistory(false);
        window.setInterval(function () {
            if (document.visibilityState !== 'hidden') {
                void loadHistory(false);
            }
        }, 45000);
    });
})();
