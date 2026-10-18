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

    const getNotificationVisual = function (item) {
        const eventType = String(item && item.tipo_evento ? item.tipo_evento : '');

        if(Number(item?.telefonia_id)>0)return {icon:item.icono||'bi-telephone-forward',tone:'warning',fallbackTitle:'Telefonía'};

        if (eventType === 'vencimiento_hoy') {
            return {
                icon: 'bi-exclamation-octagon-fill',
                tone: 'danger',
                fallbackTitle: 'Convocatoria vence hoy'
            };
        }

        if (eventType === 'vencimiento_1_dia' || eventType === 'vencimiento_2_dias') {
            return {
                icon: 'bi-clock-fill',
                tone: 'warning',
                fallbackTitle: 'Convocatoria próxima a vencer'
            };
        }

        return {
            icon: 'bi-check-circle-fill',
            tone: 'success',
            fallbackTitle: 'Convocatoria activada'
        };
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

    const markAsRead = async function (id) {
        if (!readEndpoint || Number(id || 0) <= 0) {
            return;
        }

        const body = new URLSearchParams();
        body.set('id', String(Number(id || 0)));

        try {
            await fetch(readEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'fetch'
                },
                body: body.toString()
            });
        } catch (error) {
            // La alerta seguirá visible si no fue posible marcarla.
        }
    };

    const showActivationToast = function (item) {
        const visual = getNotificationVisual(item);
        const toast = document.createElement('a');
        toast.className = 'convocatoria-activation-toast is-' + visual.tone;
        toast.href = String(item.url || '#');
        if(Number(item.telefonia_id)>0)toast.dataset.telefoniaId=String(item.telefonia_id);
        toast.innerHTML =
            '<span class="convocatoria-activation-toast-icon"><i class="bi ' + visual.icon + '"></i></span>' +
            '<span class="convocatoria-activation-toast-copy">' +
                '<strong>' + escapeHtml(item.titulo || visual.fallbackTitle) + '</strong>' +
                '<span>' + escapeHtml(item.mensaje || '') + '</span>' +
            '</span>' +
            '<span class="convocatoria-activation-toast-close" aria-hidden="true">&times;</span>';

        toast.addEventListener('click', async function (event) {
            const close = event.target.closest('.convocatoria-activation-toast-close');

            if (close) {
                event.preventDefault();
                toast.remove();
                return;
            }

            event.preventDefault();
            await markAsRead(item.id);
            window.location.href = toast.href;
        });

        document.body.appendChild(toast);

        window.setTimeout(function () {
            toast.classList.add('is-visible');
        }, 30);

        window.setTimeout(function () {
            toast.classList.remove('is-visible');
            window.setTimeout(function () {
                toast.remove();
            }, 220);
        }, 8000);
    };

    const noticesSeen=new Set();let toastBusy=false,currentItems=[];
    const notifyUnseen = function(items){
        currentItems=Array.isArray(items)?items:[];if(toastBusy)return;
        const item=currentItems.find(item=>{
            if(Number(item.leida||0)!==0||(Number(item.id)<=0&&Number(item.telefonia_id)<=0))return false;
            const key=Number(item.telefonia_id)>0?'impe:phone-toast:'+Number(window.IMPE_CURRENT_USER_ID||0)+':'+Number(item.telefonia_id):'impe_convocatoria_notificacion_'+Number(item.id);
            if(noticesSeen.has(key))return false;
            try{if(sessionStorage.getItem(key)==='1')return false;}catch(_){}return true;
        });
        if(!item)return;
        const key=Number(item.telefonia_id)>0?'impe:phone-toast:'+Number(window.IMPE_CURRENT_USER_ID||0)+':'+Number(item.telefonia_id):'impe_convocatoria_notificacion_'+Number(item.id);
        noticesSeen.add(key);try{sessionStorage.setItem(key,'1');}catch(_){}
        showActivationToast(item);toastBusy=true;
        window.setTimeout(()=>{toastBusy=false;notifyUnseen(currentItems);},8300);
    };

    const render = function (items) {
        if (!content) {
            return;
        }

        if (!Array.isArray(items) || items.length === 0) {
            content.innerHTML =
                '<div class="topbar-reminder-empty">' +
                    '<i class="bi bi-bell"></i>' +
                    '<strong>Sin alertas nuevas</strong>' +
                    '<span>Las activaciones y vencimientos próximos aparecerán aquí.</span>' +
                '</div>';
            return;
        }

        content.innerHTML =
            '<div class="topbar-reminder-list">' +
            items.map(function (item) {
                const unread = Number(item.leida || 0) === 0;
                const url = String(item.url || '#');
                const visual = getNotificationVisual(item);
                const timeTone = visual.tone === 'danger'
                    ? 'vencida'
                    : (
                        visual.tone === 'warning'
                            ? 'proxima'
                            : (visual.tone === 'success' ? 'activa' : 'manana')
                    );

                return (
                    '<a class="topbar-reminder-item' + (unread ? ' is-unread' : '') + '"' +
                        ' href="' + escapeHtml(url) + '"' +
                        (Number(item.telefonia_id)>0?' data-telefonia-id="'+Number(item.telefonia_id)+'"':' data-convocatoria-notification-id="' + Number(item.id || 0) + '"')+'>' +
                        '<span class="topbar-reminder-icon">' +
                            '<i class="bi ' + visual.icon + '"></i>' +
                        '</span>' +
                        '<span class="topbar-reminder-copy">' +
                            '<strong>' + escapeHtml(item.titulo || visual.fallbackTitle) + '</strong>' +
                            '<span>' + escapeHtml(item.mensaje || '') + '</span>' +
                        '</span>' +
                        '<span class="topbar-reminder-time is-' + timeTone + '">' +
                            escapeHtml(formatDate(item.created_at)) +
                        '</span>' +
                    '</a>'
                );
            }).join('') +
            '</div>';
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
            notifyUnseen(data.notificaciones || []);
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
        await markAsRead(item.dataset.convocatoriaNotificationId || '0');
        window.location.href = destination;
    });

    if (markAll) {
        markAll.addEventListener('click', async function (event) {
            event.preventDefault();

            if (!readAllEndpoint) {
                return;
            }

            try {
                await fetch(readAllEndpoint, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'fetch' }
                });
                await load();
            } catch (error) {
                // Mantener la campana operativa aunque el marcado falle.
            }
        });
    }

    window.addEventListener('impe:phone-notices-refresh',()=>void load());
    load();

    window.setInterval(load, 60000);
});
