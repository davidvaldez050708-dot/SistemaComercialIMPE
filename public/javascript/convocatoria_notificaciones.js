document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const root = document.querySelector('[data-convocatoria-notification-root]');
    if (!root) {
        return;
    }

    const endpoint = root.dataset.endpoint || '';
    const readEndpoint = root.dataset.readEndpoint || '';
    const readAllEndpoint = root.dataset.readAllEndpoint || '';
    const content = root.querySelector('[data-convocatoria-notification-content]');
    const badge = root.querySelector('[data-convocatoria-notification-badge]');
    const markAll = root.querySelector('[data-convocatoria-notification-read-all]');

    const escapeHtml = function (value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    };

    const formatDate = function (value) {
        if (!value) {
            return '';
        }

        const normalized = String(value).replace(' ', 'T');
        const date = new Date(normalized);

        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        return new Intl.DateTimeFormat('es-MX', {
            day: '2-digit',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit'
        }).format(date);
    };

    const setBadge = function (count) {
        const total = Number(count || 0);

        if (!badge) {
            return;
        }

        if (total <= 0) {
            badge.classList.add('d-none');
            badge.textContent = '0';
            return;
        }

        badge.textContent = total > 9 ? '9+' : String(total);
        badge.classList.remove('d-none');
    };

    const render = function (items) {
        if (!content) {
            return;
        }

        if (!Array.isArray(items) || items.length === 0) {
            content.innerHTML =
                '<div class="topbar-reminder-empty convocatoria-notification-empty">' +
                    '<i class="bi bi-bell"></i>' +
                    '<strong>Sin alertas nuevas</strong>' +
                    '<span>Las activaciones automáticas aparecerán aquí.</span>' +
                '</div>';
            return;
        }

        content.innerHTML = items.map(function (item) {
            const unread = Number(item.leida || 0) === 0;
            const url = String(item.url || '#');

            return (
                '<a class="convocatoria-notification-item' + (unread ? ' is-unread' : '') + '"' +
                    ' href="' + escapeHtml(url) + '"' +
                    ' data-convocatoria-notification-id="' + Number(item.id || 0) + '">' +
                    '<span class="convocatoria-notification-icon"><i class="bi bi-megaphone"></i></span>' +
                    '<span class="convocatoria-notification-copy">' +
                        '<strong>' + escapeHtml(item.titulo || 'Convocatoria activada') + '</strong>' +
                        '<span>' + escapeHtml(item.mensaje || '') + '</span>' +
                        '<small>' + escapeHtml(formatDate(item.created_at)) + '</small>' +
                    '</span>' +
                    (unread ? '<span class="convocatoria-notification-dot" aria-label="No leída"></span>' : '') +
                '</a>'
            );
        }).join('');
    };

    const load = async function () {
        if (!endpoint || !content) {
            return;
        }

        try {
            const response = await fetch(endpoint, {
                headers: { 'Accept': 'application/json' }
            });
            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.mensaje || 'No fue posible cargar las notificaciones.');
            }

            setBadge(data.no_leidas || 0);
            render(data.notificaciones || []);
        } catch (error) {
            content.innerHTML =
                '<div class="topbar-reminder-empty convocatoria-notification-empty">' +
                    '<i class="bi bi-exclamation-circle"></i>' +
                    '<strong>No se pudieron cargar las alertas</strong>' +
                    '<span>Verifica que la migración de notificaciones esté aplicada.</span>' +
                '</div>';
        }
    };

    root.addEventListener('click', async function (event) {
        const item = event.target.closest('[data-convocatoria-notification-id]');
        if (!item || !readEndpoint) {
            return;
        }

        event.preventDefault();

        const destination = item.getAttribute('href') || '#';
        const body = new URLSearchParams();
        body.set('id', item.dataset.convocatoriaNotificationId || '0');

        try {
            await fetch(readEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: body.toString()
            });
        } catch (error) {
            // La navegación no se bloquea si el marcado de lectura falla.
        }

        window.location.href = destination;
    });

    if (markAll) {
        markAll.addEventListener('click', async function (event) {
            event.preventDefault();

            if (!readAllEndpoint) {
                return;
            }

            try {
                await fetch(readAllEndpoint, { method: 'POST' });
                await load();
            } catch (error) {
                // Mantener la campana operativa aunque el marcado falle.
            }
        });
    }

    load();

    window.setInterval(load, 60000);
});
