(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.querySelector('[data-marketing-reception]');
        if (!root) return;

        const tableBody = root.querySelector('[data-marketing-reception-rows]');
        const search = root.querySelector('[data-marketing-reception-search]');
        const filter = root.querySelector('[data-marketing-reception-filter]');
        const count = root.querySelector('[data-marketing-reception-count]');
        const pages = root.querySelector('[data-marketing-reception-pages]');
        const refresh = root.querySelector('[data-marketing-reception-refresh]');
        const detailPanel = root.querySelector('[data-marketing-reception-details]');
        const detailToggle = root.querySelector('[data-marketing-reception-details-toggle]');
        const detailLabel = root.querySelector('[data-marketing-reception-details-label]');
        const metrics = {
            total: root.querySelector('[data-marketing-reception-total]'),
            atendidas: root.querySelector('[data-marketing-reception-answered]'),
            perdidas: root.querySelector('[data-marketing-reception-missed]'),
            transferidas: root.querySelector('[data-marketing-reception-transferred]')
        };
        const pageSize = 8;
        let calls = [];
        let page = 1;
        let loading = false;
        let initialized = false;
        let receptionConfigured = false;

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
            return String(text || '').replace(/\D/g, '');
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
            if (!call.tiene_grabacion) {
                td.appendChild(node('span', 'marketing-reception-muted',
                    call.estado === 'Transferida' ? 'No disponible' : 'Sin grabación'));
                return null;
            }
            const audioId = 'marketing-reception-record-' + index;
            const url = new URL(root.dataset.recordingUrl, window.location.href);
            url.searchParams.set('pbx_call_id', String(call.pbx_call_id || ''));
            const button = node('button',
                'linkage-call-audio-state is-available linkage-call-recording-toggle');
            button.type = 'button';
            button.setAttribute('data-sales-recording-toggle', '');
            button.setAttribute('data-recording-url', url.toString());
            button.setAttribute('data-recording-seconds', String(call.segundos || 0));
            button.setAttribute('aria-controls', audioId);
            button.setAttribute('aria-expanded', 'false');
            button.innerHTML = '<i class="bi bi-record-circle" aria-hidden="true"></i>' +
                '<span>Grabación</span>' +
                '<i class="bi bi-chevron-down linkage-call-recording-chevron" aria-hidden="true"></i>';
            td.appendChild(button);
            const detail = node('tr', 'telephony-history-recording-row');
            detail.id = audioId;
            detail.hidden = true;
            detail.setAttribute('data-sales-recording-row', '');
            const content = node('td');
            content.colSpan = 6;
            const slot = node('div', 'telephony-history-recording-slot');
            slot.setAttribute('data-sales-recording-slot', '');
            content.appendChild(slot);
            detail.appendChild(content);
            return detail;
        }

        function drawRow(call, index) {
            const row = node('tr');
            addCell(row, String(call.fecha || '').slice(0, 16).replace('T', ' '));
            addCell(row, call.numero || 'Número no disponible', 'marketing-reception-number');
            const statusCell = addCell(row, '');
            const status = String(call.estado || '');
            const badge = node('span', 'marketing-reception-status', status);
            if (status === 'Atendida') badge.classList.add('is-answered');
            if (status === 'Perdida') badge.classList.add('is-missed');
            if (status === 'Transferida') badge.classList.add('is-transferred');
            statusCell.appendChild(badge);
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
            const filtered = calls.filter(function (call) {
                if (selected && call.estado !== selected) return false;
                if (!raw) return true;
                const phone = String(call.numero || '');
                const transfer = String(call.destino_transferencia || '');
                return phone.toLocaleLowerCase('es-MX').includes(raw) ||
                    transfer.includes(raw) ||
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
                            ? 'Todavía no hay llamadas entrantes registradas en esta extensión.'
                            : 'No hay llamadas que coincidan con la búsqueda.'),
                    'marketing-reception-placeholder').colSpan = 6;
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
            // No interrumpir una reproducción abierta con el refresco periódico.
            if (!manual && Array.from(tableBody.querySelectorAll('audio'))
                .some(function (audio) { return !audio.paused; })) return;
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
                metrics.transferidas.textContent = String(data.transferidas || 0);
                initialized = true;
                renderList();
                if (manual) {
                    count.textContent += ' · actualizado';
                }
            } catch (error) {
                if (!initialized) {
                    tableBody.replaceChildren();
                    const row = node('tr');
                    addCell(row, error.message || 'No fue posible consultar las llamadas.',
                        'marketing-reception-placeholder').colSpan = 6;
                    tableBody.appendChild(row);
                    count.textContent = 'No se pudo actualizar el historial.';
                } else if (manual) {
                    count.textContent = error.message || 'No se pudo actualizar.';
                }
            } finally {
                loading = false;
                refresh.disabled = false;
                refresh.querySelector('i')?.classList.remove('marketing-reception-spinning');
            }
        }

        search.addEventListener('input', function () {
            page = 1;
            renderList();
        });
        filter.addEventListener('change', function () {
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