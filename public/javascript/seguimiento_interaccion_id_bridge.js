(function () {
    'use strict';

    if (window.IMPE_EXACT_INTERACTION_BRIDGE_READY || typeof window.fetch !== 'function') {
        return;
    }

    window.IMPE_EXACT_INTERACTION_BRIDGE_READY = true;

    const fetchOriginal = window.fetch.bind(window);
    const interaccionesExactas = new Map();

    const obtenerUrl = function (input) {
        if (typeof input === 'string') {
            return input;
        }

        if (input instanceof URL) {
            return input.href;
        }

        if (typeof Request !== 'undefined' && input instanceof Request) {
            return input.url;
        }

        return String(input || '');
    };

    const esUrl = function (url, fragmento) {
        return String(url || '').includes(fragmento);
    };

    const valorFormData = function (body, campo) {
        if (!(body instanceof FormData)) {
            return '';
        }

        return String(body.get(campo) || '').trim();
    };

    const normalizarFecha = function (valor) {
        const texto = String(valor || '').trim();

        if (texto === '') {
            return '';
        }

        if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(texto)) {
            return texto.replace('T', ' ') + ':00';
        }

        return texto.replace('T', ' ');
    };

    const contextoRegistro = function (url, init) {
        const body = init && init.body instanceof FormData ? init.body : null;

        if (!body) {
            return null;
        }

        const seguimientoId = Number(valorFormData(body, 'seguimiento_id') || 0);
        const canalFormulario = valorFormData(body, 'canal').toUpperCase();

        if (seguimientoId <= 0 || canalFormulario !== 'LLAMADA') {
            return null;
        }

        if (esUrl(url, 'action=registrarInteraccionTrabajo')) {
            return {
                tipo: 'trabajo',
                seguimientoId: seguimientoId,
                fechaInicio: normalizarFecha(valorFormData(body, 'fecha_inicio')),
                canal: 'LLAMADA_IP'
            };
        }

        if (esUrl(url, 'action=registrarInformativa')) {
            return {
                tipo: 'informativa',
                seguimientoId: seguimientoId,
                fechaInicio: normalizarFecha(valorFormData(body, 'fecha_inicio')),
                canal: 'LLAMADA_IP'
            };
        }

        return null;
    };

    const resolverIdTrabajo = function (datos, contexto) {
        const interacciones = Array.isArray(datos && datos.interacciones)
            ? datos.interacciones
            : [];
        const fechaMinuto = String(contexto.fechaInicio || '').slice(0, 16);

        const candidatas = interacciones.filter(function (interaccion) {
            if (String(interaccion.canal || '') !== contexto.canal) {
                return false;
            }

            if (fechaMinuto === '') {
                return true;
            }

            return String(interaccion.fecha_inicio || '').slice(0, 16) === fechaMinuto;
        });

        const fuente = candidatas.length > 0 ? candidatas : interacciones;

        return fuente.reduce(function (mayor, interaccion) {
            const id = Number(interaccion && interaccion.id || 0);
            return id > mayor ? id : mayor;
        }, 0);
    };

    const guardarInteraccionExacta = function (datos, contexto) {
        if (!datos || datos.ok !== true || !contexto) {
            return;
        }

        let interaccionId = 0;

        if (contexto.tipo === 'informativa') {
            interaccionId = Number(datos.interaccion && datos.interaccion.id || 0);
        } else {
            interaccionId = resolverIdTrabajo(datos, contexto);
        }

        if (interaccionId <= 0) {
            console.warn('No se pudo resolver el ID exacto de la interacción telefónica guardada.');
            return;
        }

        interaccionesExactas.set(contexto.seguimientoId, interaccionId);

        document.dispatchEvent(new CustomEvent('impe:interaction-exact-id-ready', {
            detail: {
                seguimientoId: contexto.seguimientoId,
                interaccionId: interaccionId
            }
        }));
    };

    window.fetch = function (input, init) {
        const url = obtenerUrl(input);
        const contexto = contextoRegistro(url, init || {});
        const esVinculacion = esUrl(url, 'prueba_telefonia/api/vincular_interaccion.php');
        const body = init && init.body instanceof FormData ? init.body : null;
        let seguimientoVinculacion = 0;
        let interaccionVinculacion = 0;

        if (esVinculacion && body) {
            seguimientoVinculacion = Number(valorFormData(body, 'seguimiento_id') || 0);
            interaccionVinculacion = Number(
                interaccionesExactas.get(seguimientoVinculacion) || 0
            );

            if (interaccionVinculacion > 0) {
                body.set('interaccion_id', String(interaccionVinculacion));
            }
        }

        return fetchOriginal(input, init).then(async function (response) {
            if (contexto) {
                try {
                    const datos = await response.clone().json();
                    guardarInteraccionExacta(datos, contexto);
                } catch (error) {
                    // La respuesta principal conserva su manejo normal de errores.
                }
            }

            if (esVinculacion && seguimientoVinculacion > 0 && interaccionVinculacion > 0) {
                try {
                    const datos = await response.clone().json();
                    if (datos && datos.ok === true) {
                        interaccionesExactas.delete(seguimientoVinculacion);
                    }
                } catch (error) {
                    // Conserva el ID para permitir un reintento si la respuesta no fue JSON.
                }
            }

            return response;
        });
    };
})();
