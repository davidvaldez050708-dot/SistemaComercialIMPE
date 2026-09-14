(function () {
    'use strict';

    const texto = function (valor) {
        return String(valor || '').replace(/\s+/g, ' ').trim();
    };

    const normalizado = function (valor) {
        return texto(valor).toLowerCase();
    };

    const describirEventoSistema = function (registro) {
        const notas = normalizado(registro?.notas);

        if (notas.includes('cuenta clave confirmó la reunión')) {
            return 'Reunión · Confirmada por Cuenta Clave';
        }
        if (notas.includes('cuenta clave solicitó modificar la propuesta de reunión')) {
            return 'Reunión · Reprogramación solicitada';
        }
        if (notas.includes('nueva propuesta de reunión enviada a cuenta clave')) {
            return 'Reunión · Nueva fecha propuesta';
        }
        if (notas.includes('solicitud de reunión enviada a cuenta clave')) {
            return 'Reunión · Propuesta enviada';
        }
        if (notas.includes('correo de confirmación de reunión registrado como enviado')) {
            return 'Correo · Confirmación de reunión enviada';
        }
        if (notas.includes('seguimiento de acuerdos programado para')) {
            return 'Seguimiento · Acuerdos programados';
        }
        if (notas.includes('seguimiento posterior a reunión')) {
            return 'Seguimiento · Resultado posterior a reunión';
        }

        return '';
    };

    const etiquetaActor = function (registro) {
        const actorId = Number(registro?.actor_id || 0);
        const usuarioActual = Number(window.IMPE_CURRENT_USER_ID || 0);
        if (actorId <= 0 || actorId === usuarioActual) {
            return '';
        }

        const rol = texto(registro?.actor_rol);
        const nombre = texto(registro?.actor_nombre);
        let etiqueta = rol || 'Otro usuario';

        if (normalizado(rol).includes('cuenta clave')) {
            etiqueta = 'Cuenta Clave';
        }

        return nombre !== '' ? etiqueta + ': ' + nombre : etiqueta;
    };

    const limpiarMensajeAnterior = function (tablero, detalleCorrecto) {
        const mensajeAnterior = 'Ordenados por urgencia para que sepas por dónde continuar.';

        tablero.querySelectorAll('span, p, div').forEach(function (elemento) {
            if (elemento === detalleCorrecto || elemento.children.length > 0) {
                return;
            }

            if (texto(elemento.textContent) === mensajeAnterior) {
                elemento.remove();
            }
        });
    };

    const corregirResumenAtencion = function (tablero) {
        const meta = window.IMPE_ANALISTA_DASHBOARD_META || {};
        const total = Number(meta.requieren_atencion_total);
        if (!Number.isFinite(total) || total < 0) {
            return;
        }

        const estado = tablero.querySelector('.analyst-welcome-status');
        const titulo = estado?.querySelector('strong');
        const detalle = estado?.querySelector('div > span');
        const icono = estado?.querySelector('.analyst-welcome-status-icon i');
        if (!estado || !titulo || !detalle) {
            return;
        }

        limpiarMensajeAnterior(tablero, detalle);

        const estaAlDia = total === 0;
        estado.classList.toggle('is-clear', estaAlDia);
        estado.classList.toggle('is-attention', !estaAlDia);

        if (icono) {
            icono.className = 'bi ' + (estaAlDia
                ? 'bi-check2-circle'
                : 'bi-exclamation-circle');
            icono.setAttribute('aria-hidden', 'true');
        }

        if (estaAlDia) {
            titulo.textContent = 'Tu operación está al día';
            detalle.hidden = false;
            detalle.textContent = 'No hay seguimientos prioritarios detectados en este momento.';
            return;
        }

        titulo.textContent = total + ' ' +
            (total === 1 ? 'seguimiento requiere atención' : 'seguimientos requieren atención');

        // El conteo comunica el estado operativo; no repetimos una frase auxiliar debajo.
        detalle.textContent = '';
        detalle.hidden = true;
    };

    const enriquecerActividad = function (tablero) {
        const datos = Array.isArray(window.IMPE_ANALISTA_ACTIVIDAD_RECIENTE)
            ? window.IMPE_ANALISTA_ACTIVIDAD_RECIENTE
            : [];
        const items = Array.from(tablero.querySelectorAll('.analyst-activity-item'));

        items.forEach(function (item, indice) {
            const registro = datos[indice];
            if (!registro) {
                return;
            }

            const detalle = item.querySelector('.analyst-activity-copy > span');
            if (!detalle) {
                return;
            }

            const sistema = describirEventoSistema(registro);
            if (sistema !== '') {
                detalle.textContent = sistema;
            }

            const actor = etiquetaActor(registro);
            if (actor !== '') {
                detalle.textContent = texto(detalle.textContent) + ' · ' + actor;
            }
        });
    };

    document.addEventListener('DOMContentLoaded', function () {
        const tablero = document.querySelector('[data-analyst-dashboard]');
        if (!tablero || Number(window.IMPE_CURRENT_ROLE_ID || 0) !== 4) {
            return;
        }

        corregirResumenAtencion(tablero);
        enriquecerActividad(tablero);
    });
})();
