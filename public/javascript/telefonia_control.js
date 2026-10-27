(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.querySelector('[data-telephony-control]');
        if (!root) return;
        const history = root.querySelector('[data-telephony-control-history]');
        const body = history?.querySelector('[data-telephony-control-history-rows]');
        const input = history?.querySelector('[data-telephony-control-search]');
        const count = history?.querySelector('[data-telephony-control-count]');
        const pages = history?.querySelector('[data-telephony-control-pages]');
        const filters = root.querySelector('.telephony-control-filters');

        function fold(value) {
            return String(value || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLocaleLowerCase('es-MX')
                .trim();
        }

        if (history && body && input && count && pages) {
            // El servidor conserva el total histórico y envía los últimos 500;
            // el buscador de la tabla solo filtra esas filas ya descargadas.
            const rows = Array.from(body.querySelectorAll(
                '[data-telephony-control-history-row]'
            ));
            const pageSize = 10;
            let page = 1;

            function render() {
                const search = fold(input.value);
                const matches = rows.filter(function (row) {
                    return !search || fold(row.textContent).includes(search);
                });
                const totalPages = Math.ceil(matches.length / pageSize);
                page = Math.min(Math.max(1, page), Math.max(1, totalPages));
                const start = (page - 1) * pageSize;
                const visible = new Set(matches.slice(start, start + pageSize));
                rows.forEach(function (row) { row.hidden = !visible.has(row); });

                let empty = body.querySelector('[data-telephony-control-empty-search]');
                if (!empty) {
                    empty = document.createElement('tr');
                    empty.setAttribute('data-telephony-control-empty-search', '');
                    const td = document.createElement('td');
                    td.colSpan = 9;
                    td.className = 'text-center text-muted py-4';
                    td.textContent = 'No hay llamadas que coincidan con la búsqueda.';
                    empty.appendChild(td);
                    body.appendChild(empty);
                }
                empty.hidden = matches.length !== 0 || rows.length === 0;
                count.textContent = matches.length > 0
                    ? 'Mostrando ' + (start + 1) + '–' +
                        Math.min(start + pageSize, matches.length) +
                        ' de ' + matches.length + ' atenciones visibles'
                    : rows.length > 0 ? 'Sin coincidencias' : 'Sin actividad registrada';

                const controls = [];
                const createButton = function (target, label, disabled, active) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'telephony-control-page';
                    button.dataset.telephonyPage = String(target);
                    button.textContent = label;
                    button.disabled = disabled;
                    button.setAttribute('aria-label', 'Página ' + target);
                    if (active) {
                        button.classList.add('is-current');
                        button.setAttribute('aria-current', 'page');
                    }
                    controls.push(button);
                };
                if (totalPages > 1) {
                    createButton(page - 1, '‹', page <= 1, false);
                    const numbers = new Set([1, totalPages]);
                    for (let n = Math.max(1, page - 2);
                        n <= Math.min(totalPages, page + 2); n++) numbers.add(n);
                    let previous = 0;
                    Array.from(numbers).sort(function (a, b) { return a - b; })
                        .forEach(function (n) {
                            if (previous > 0 && n > previous + 1) {
                                const dots = document.createElement('span');
                                dots.className = 'telephony-control-page-dots';
                                dots.textContent = '…';
                                controls.push(dots);
                            }
                            createButton(n, String(n), false, n === page);
                            previous = n;
                        });
                    createButton(page + 1, '›', page >= totalPages, false);
                }
                pages.hidden = totalPages <= 1;
                pages.replaceChildren(...controls);
            }

            input.addEventListener('input', function () {
                page = 1;
                render();
            });
            pages.addEventListener('click', function (event) {
                const button = event.target.closest('button[data-telephony-page]');
                if (!button || button.disabled) return;
                const next = Number(button.dataset.telephonyPage);
                if (!Number.isInteger(next) || next < 1) return;
                page = next;
                render();
            });
            render();
        }

        if (filters) {
            const from = filters.querySelector('[name="desde"]');
            const through = filters.querySelector('[name="hasta"]');
            function verify() {
                if (!from || !through) return;
                from.setCustomValidity('');
                through.setCustomValidity('');
                if (!from.value || !through.value) return;
                const start = new Date(from.value + 'T12:00:00');
                const end = new Date(through.value + 'T12:00:00');
                const span = Math.round((end - start) / 86400000);
                if (span < 0) {
                    through.setCustomValidity('La fecha final debe ser posterior a la inicial.');
                } else if (span > 89) {
                    through.setCustomValidity('Selecciona un máximo de 90 días por intervalo.');
                }
            }
            const message = filters.querySelector('[data-control-filter-message]');
            const loading = filters.querySelector('[data-control-filter-loading]');
            const clear = filters.querySelector('[data-control-clear]');
            let pending = false;
            let timer = null;

            function tell(text) {
                if (!message) return;
                message.textContent = text || '';
                message.hidden = !text;
            }

            function clearStatus() {
                if (loading) loading.hidden = true;
                filters.classList.remove('is-updating');
                pending = false;
            }

            function apply() {
                if (pending) return;
                verify();
                if (!filters.checkValidity()) {
                    clearStatus();
                    tell(through?.validationMessage || from?.validationMessage ||
                        'Selecciona fechas válidas antes de actualizar.');
                    return;
                }
                tell('');
                const params = new URLSearchParams(new FormData(filters));
                const url = new URL(filters.action, window.location.href);
                url.search = params.toString();
                // Mantener métricas, tendencia, ranking e historial sincronizados:
                // todos se vuelven a consultar en el servidor.
                const current = new URL(window.location.href);
                if (url.search === current.search) {
                    clearStatus();
                    return;
                }
                pending = true;
                filters.classList.add('is-updating');
                if (loading) loading.hidden = false;
                window.location.assign(url.toString());
            }

            function schedule() {
                if (pending) return;
                window.clearTimeout(timer);
                verify();
                tell('');
                timer = window.setTimeout(apply, 240);
            }

            filters.querySelectorAll('select').forEach(function (field) {
                field.addEventListener('change', schedule);
            });
            from?.addEventListener('change', schedule);
            through?.addEventListener('change', schedule);
            [from, through].forEach(function (field) {
                field?.addEventListener('input', function () {
                    window.clearTimeout(timer);
                    verify();
                    tell('');
                });
            });
            filters.addEventListener('submit', function (event) {
                event.preventDefault();
                window.clearTimeout(timer);
                apply();
            });
            clear?.addEventListener('click', function () {
                if (loading) loading.hidden = false;
            });
            window.addEventListener('pageshow', clearStatus);
        }
    });
})();