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
        let recordatoriosActuales = [];
        let temporizadorToastVencido = null;

        const INTERVALO_TOAST_VENCIDO = 8 * 60 * 1000;
        const REINTENTO_TOAST_OCULTO = 60 * 1000;
        const DURACION_TOAST = 7000;
        const CLAVE_ULTIMO_TOAST_VENCIDO_AT =
            'recordatorios:ultimo-toast-vencido-at';
        const CLAVE_ULTIMO_TOAST_VENCIDO_ID =
            'recordatorios:ultimo-toast-vencido-id';

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

        const dosDigitos = function (valor) {
            return String(valor).padStart(2, '0');
        };

        const leerSesion = function (clave) {
            try {
                return window.sessionStorage.getItem(clave) || '';
            } catch (error) {
                return '';
            }
        };

        const guardarSesion = function (clave, valor) {
            try {
                window.sessionStorage.setItem(clave, String(valor));
            } catch (error) {
                // El recordatorio sigue funcionando aunque sessionStorage no esté disponible.
            }
        };

        const claveRecordatorio = function (recordatorio) {
            const reunionId = Number(recordatorio.reunion_id || 0);
            const seguimientoId = Number(
                recordatorio.seguimiento_id || recordatorio.id || 0
            );
            const accion = String(recordatorio.accion || '').trim();
            const fecha = String(recordatorio.fecha || '').trim();

            return [
                reunionId > 0 ? 'reunion:' + reunionId : 'seguimiento:' + seguimientoId,
                accion,
                fecha
            ].join('|');
        };

        const registrarToastVencido = function (recordatorio) {
            guardarSesion(CLAVE_ULTIMO_TOAST_VENCIDO_AT, Date.now());
            guardarSesion(
                CLAVE_ULTIMO_TOAST_VENCIDO_ID,
                claveRecordatorio(recordatorio)
            );
        };

        const fechaRecordatorio = function (recordatorio) {
            const fechaTexto = String(recordatorio.fecha || '').trim();
            if (fechaTexto === '') {
                return null;
            }

            const momento = new Date(fechaTexto.replace(' ', 'T'));
            return Number.isNaN(momento.getTime()) ? null : momento;
        };

        const estadoVisibleRecordatorio = function (recordatorio) {
            const estado = String(recordatorio.estado || 'normal').trim().toLowerCase();
            const momento = fechaRecordatorio(recordatorio);

            // Algunos avisos de agenda representan una acción que requiere atención
            // (por ejemplo, reprogramar una reunión) y el backend puede marcarlos
            // como "vencida" para darles prioridad. Si la fecha de la reunión sigue
            // siendo futura, no debe mostrarse visualmente como una reunión vencida.
            if (estado === 'vencida' && momento && momento.getTime() > Date.now()) {
                return 'proxima';
            }

            return estado || 'normal';
        };

        const etiquetaVisibleRecordatorio = function (recordatorio) {
            const etiquetaOriginal = String(recordatorio.etiqueta || '').trim();
            const estado = String(recordatorio.estado || '').trim().toLowerCase();
            const momento = fechaRecordatorio(recordatorio);

            if (estado !== 'vencida' || !momento) {
                return etiquetaOriginal;
            }

            // Si la fecha todavía no ocurre, conservamos la etiqueta operativa
            // (por ejemplo, "Requiere ajuste") en lugar de mostrar "Vencida".
            if (momento.getTime() > Date.now()) {
                return etiquetaOriginal;
            }

            const ahora = new Date();
            const hoy = new Date(
                ahora.getFullYear(),
                ahora.getMonth(),
                ahora.getDate()
            );
            const diaEvento = new Date(
                momento.getFullYear(),
                momento.getMonth(),
                momento.getDate()
            );
            const diferenciaDias = Math.round(
                (diaEvento.getTime() - hoy.getTime()) / 86400000
            );
            const hora = dosDigitos(momento.getHours()) + ':' +
                dosDigitos(momento.getMinutes());
            const diaMes = dosDigitos(momento.getDate()) + '/' +
                dosDigitos(momento.getMonth() + 1);

            if (diferenciaDias === 0) {
                return 'Vencida hoy · ' + hora;
            }

            if (diferenciaDias === -1) {
                return 'Ayer · ' + diaMes + ' · ' + hora;
            }

            if (momento.getFullYear() === ahora.getFullYear()) {
                return 'Vencida · ' + diaMes + ' · ' + hora;
            }

            return 'Vencida · ' + diaMes + '/' + momento.getFullYear() +
                ' · ' + hora;
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

            // Desactivamos el autohide de Bootstrap porque éste pausa el contador
            // cuando el usuario mantiene el cursor o el foco sobre el toast. El
            // temporizador propio garantiza un cierre automático aunque haya hover o foco.
            const instanciaToast = new bootstrap.Toast(toast, {
                autohide: false
            });
            let temporizadorCierre = null;

            toast.addEventListener('hidden.bs.toast', function () {
                if (temporizadorCierre !== null) {
                    window.clearTimeout(temporizadorCierre);
                    temporizadorCierre = null;
                }
                toast.remove();
            });

            instanciaToast.show();
            temporizadorCierre = window.setTimeout(function () {
                instanciaToast.hide();
            }, DURACION_TOAST);
        };

        const esRecordatorioVencidoRecurrente = function (recordatorio) {
            return estadoVisibleRecordatorio(recordatorio) === 'vencida';
        };

        const mostrarSiguienteToastVencido = function () {
            const disponibles = recordatoriosActuales.filter(
                esRecordatorioVencidoRecurrente
            );

            if (disponibles.length === 0) {
                return false;
            }

            const ultimaClave = leerSesion(CLAVE_ULTIMO_TOAST_VENCIDO_ID);
            let indice = 0;

            if (ultimaClave !== '') {
                const indiceAnterior = disponibles.findIndex(function (recordatorio) {
                    return claveRecordatorio(recordatorio) === ultimaClave;
                });

                if (indiceAnterior >= 0) {
                    indice = (indiceAnterior + 1) % disponibles.length;
                }
            }

            const recordatorio = disponibles[indice];
            const entidad = String(
                recordatorio.nombre_entidad || 'Seguimiento'
            ).trim();
            const accion = String(
                recordatorio.accion || 'Revisar pendiente'
            ).trim();
            const etiqueta = etiquetaVisibleRecordatorio(recordatorio);

            mostrarToast({
                id: recordatorio.id,
                seguimiento_id: recordatorio.seguimiento_id,
                reunion_id: recordatorio.reunion_id,
                url: recordatorio.url,
                icono: recordatorio.icono,
                tipo: 'VENCIDA',
                titulo: 'Acción vencida',
                mensaje: accion + ' · ' + entidad +
                    (etiqueta ? ' · ' + etiqueta : '')
            });

            registrarToastVencido(recordatorio);
            return true;
        };

        const programarSiguienteToastVencido = function () {
            if (temporizadorToastVencido !== null) {
                window.clearTimeout(temporizadorToastVencido);
                temporizadorToastVencido = null;
            }

            const ultimoToast = Number(
                leerSesion(CLAVE_ULTIMO_TOAST_VENCIDO_AT)
            );
            const ahora = Date.now();
            const espera = Number.isFinite(ultimoToast) && ultimoToast > 0
                ? Math.max(
                    1000,
                    INTERVALO_TOAST_VENCIDO - Math.max(0, ahora - ultimoToast)
                )
                : INTERVALO_TOAST_VENCIDO;

            temporizadorToastVencido = window.setTimeout(function ejecutar() {
                temporizadorToastVencido = null;

                if (document.hidden) {
                    temporizadorToastVencido = window.setTimeout(
                        ejecutar,
                        REINTENTO_TOAST_OCULTO
                    );
                    return;
                }

                mostrarSiguienteToastVencido();
                programarSiguienteToastVencido();
            }, espera);
        };

        const renderizarRecordatorios = function (recordatorios) {
            const lista = Array.isArray(recordatorios) ? recordatorios : [];
            recordatoriosActuales = lista;

            if (contenido) {
                contenido.setAttribute('aria-busy', 'false');
            }

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
                    const etiqueta = etiquetaVisibleRecordatorio(recordatorio);
                    const estadoVisual = estadoVisibleRecordatorio(recordatorio);
                    return (
                        '<a class="topbar-reminder-item" href="' + url + '">' +
                            '<span class="topbar-reminder-icon">' +
                                '<i class="bi ' + escapar(recordatorio.icono || 'bi-bell') + '"></i>' +
                            '</span>' +
                            '<span class="topbar-reminder-copy">' +
                                '<strong>' + escapar(recordatorio.nombre_entidad || 'Seguimiento') + '</strong>' +
                                '<span>' + escapar(recordatorio.accion || '') + '</span>' +
                            '</span>' +
                            '<span class="topbar-reminder-time is-' + escapar(estadoVisual) + '">' +
                                escapar(etiqueta) +
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

                let huboAvisoVencido = false;

                (datos.avisos || []).forEach(function (aviso) {
                    mostrarToast(aviso);

                    if (String(aviso.tipo || '').toUpperCase() === 'VENCIDA') {
                        registrarToastVencido(aviso);
                        huboAvisoVencido = true;
                    }
                });

                if (huboAvisoVencido) {
                    programarSiguienteToastVencido();
                }
            } catch (error) {
                console.error('No fue posible actualizar las notificaciones.', error);
            } finally {
                consultaEnCurso = false;
            }
        };

        consultarRecordatorios();
        window.setInterval(consultarRecordatorios, 60000);
        programarSiguienteToastVencido();

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                consultarRecordatorios();
                programarSiguienteToastVencido();
            }
        });
    });
})();
