(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.querySelector('[data-reminder-root]');

        if (!root) {
            return;
        }

        const endpoint = root.getAttribute('data-reminder-endpoint') || '';
        const badge = root.querySelector('[data-reminder-badge]');
        const contenido = root.querySelector('[data-reminder-content]');
        let consultaEnCurso = false;
        let avisoMigracionMostrado = false;

        if (endpoint === '') {
            return;
        }

        const escapar = function (valor) {
            const div = document.createElement('div');
            div.textContent = String(valor || '');
            return div.innerHTML;
        };

        const urlRecordatorio = function (recordatorio) {
            const personalizada = String(recordatorio.url || '').trim();
            if (personalizada !== '') {
                return personalizada;
            }

            return 'index.php?controller=seguimientoVinculacion&action=detalle&id=' +
                Number(recordatorio.id || recordatorio.seguimiento_id || 0);
        };

        const asegurarContenedorToasts = function () {
            let contenedor = document.querySelector('[data-reminder-toast-container]');

            if (contenedor) {
                return contenedor;
            }

            contenedor = document.createElement('div');
            contenedor.className = 'toast-container position-fixed top-0 end-0 p-3 reminder-toast-container';
            contenedor.setAttribute('data-reminder-toast-container', '');
            document.body.appendChild(contenedor);

            return contenedor;
        };

        const mostrarToast = function (aviso) {
            if (!window.bootstrap) {
                return;
            }

            const contenedor = asegurarContenedorToasts();
            const toast = document.createElement('div');
            const vencida = String(aviso.tipo || '') === 'VENCIDA';
            toast.className = 'toast reminder-toast' + (vencida ? ' is-overdue' : '');
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            toast.setAttribute('aria-atomic', 'true');

            toast.innerHTML =
                '<div class="reminder-toast-body">' +
                    '<span class="reminder-toast-icon">' +
                        '<i class="bi ' + escapar(aviso.icono || 'bi-bell') + '"></i>' +
                    '</span>' +
                    '<span class="reminder-toast-copy">' +
                        '<strong>' + escapar(aviso.titulo || 'Notificación') + '</strong>' +
                        '<span>' + escapar(aviso.mensaje || '') + '</span>' +
                    '</span>' +
                '</div>';

            const url = urlRecordatorio(aviso);
            if (url !== '') {
                toast.classList.add('is-clickable');
                toast.addEventListener('click', function () {
                    window.location.href = url;
                });
            }

            contenedor.appendChild(toast);
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });

            const instanciaToast = new bootstrap.Toast(toast, {
                autohide: true,
                delay: 6000
            });
            instanciaToast.show();
        };

        const renderizarRecordatorios = function (recordatorios) {
            const lista = Array.isArray(recordatorios) ? recordatorios : [];

            if (badge) {
                if (lista.length > 0) {
                    badge.textContent = lista.length > 9 ? '9+' : String(lista.length);
                    badge.classList.remove('d-none');
                } else {
                    badge.textContent = '0';
                    badge.classList.add('d-none');
                }
            }

            if (!contenido) {
                return;
            }

            if (lista.length === 0) {
                contenido.innerHTML =
                    '<div class="topbar-reminder-empty">' +
                        '<i class="bi bi-check2-circle"></i>' +
                        '<strong>Sin notificaciones pendientes</strong>' +
                        '<span>No tienes acciones o reuniones pendientes.</span>' +
                    '</div>';
                return;
            }

            contenido.innerHTML =
                '<div class="topbar-reminder-list">' +
                lista.map(function (recordatorio) {
                    const url = escapar(urlRecordatorio(recordatorio));
                    return (
                        '<a class="topbar-reminder-item" href="' + url + '">' +
                            '<span class="topbar-reminder-icon">' +
                                '<i class="bi ' + escapar(recordatorio.icono || 'bi-bell') + '"></i>' +
                            '</span>' +
                            '<span class="topbar-reminder-copy">' +
                                '<strong>' + escapar(recordatorio.nombre_entidad || 'Seguimiento') + '</strong>' +
                                '<span>' + escapar(recordatorio.accion || '') + '</span>' +
                            '</span>' +
                            '<span class="topbar-reminder-time is-' + escapar(recordatorio.estado || 'normal') + '">' +
                                escapar(recordatorio.etiqueta || '') +
                            '</span>' +
                        '</a>'
                    );
                }).join('') +
                '</div>';
        };

        const consultarRecordatorios = async function () {
            if (consultaEnCurso || document.hidden) {
                return;
            }

            consultaEnCurso = true;

            try {
                const respuesta = await fetch(endpoint, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    cache: 'no-store'
                });

                if (!respuesta.ok) {
                    return;
                }

                const datos = await respuesta.json();

                if (!datos || !datos.ok) {
                    return;
                }

                renderizarRecordatorios(datos.recordatorios || []);

                if (datos.requiere_migracion) {
                    if (!avisoMigracionMostrado) {
                        avisoMigracionMostrado = true;
                        console.warn(
                            'Recordatorios: ejecuta database/migrations/2026_09_03_recordatorios_vinculacion.sql para habilitar los avisos automáticos.'
                        );
                    }
                    return;
                }

                (datos.avisos || []).forEach(mostrarToast);
            } catch (error) {
                console.error('No fue posible actualizar las notificaciones.', error);
            } finally {
                consultaEnCurso = false;
            }
        };

        consultarRecordatorios();
        window.setInterval(consultarRecordatorios, 60000);

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                consultarRecordatorios();
            }
        });
    });
})();
