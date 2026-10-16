(function () {
    'use strict';

    if (window.IMPE_EXACT_INTERACTION_BRIDGE_READY || typeof window.fetch !== 'function') {
        return;
    }

    window.IMPE_EXACT_INTERACTION_BRIDGE_READY = true;

    const fetchOriginal = window.fetch.bind(window);
    const interaccionesExactas = new Map();
    // Protege el resultado ya confirmado de la misma llamada frente a clics
    // repetidos. La protección no sustituye idempotencia de servidor.
    const guardadosPorLlamada = new Map();
    const tokenLlamadaFinalizada = function () {
        const phone = window.IMPE_TELEPHONY_PERSISTENT;
        const estado = phone && typeof phone.getState === 'function'
            ? phone.getState() : null;
        if (estado?.phase !== 'finished' || !estado.finalMetadata) return '';
        return String(estado.callToken || estado.finalMetadata.call_token || '');
    };


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
        const llamadas = interacciones.filter(function (interaccion) {
            return String(interaccion && interaccion.canal || '') === contexto.canal;
        });
        const fechaMinuto = String(contexto.fechaInicio || '').slice(0, 16);

        if (llamadas.length === 0) {
            return 0;
        }

        const candidatas = fechaMinuto === ''
            ? llamadas
            : llamadas.filter(function (interaccion) {
                return String(interaccion.fecha_inicio || '').slice(0, 16) === fechaMinuto;
            });

        if (candidatas.length === 0) {
            return 0;
        }

        return candidatas.reduce(function (mayor, interaccion) {
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
            // El servidor proporciona el ID confirmado; no deducirlo por fecha.
            interaccionId = Number(datos.interaccion_id || 0);
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
        const token = contexto?.tipo === 'trabajo' ? tokenLlamadaFinalizada() : '';
        const claveRegistro = token && contexto
            ? contexto.seguimientoId + ':' + token : '';
        if (claveRegistro && guardadosPorLlamada.has(claveRegistro)) {
            // Reutiliza la confirmación del primer registro, sin repetir INSERT.
            return guardadosPorLlamada.get(claveRegistro).then(function (data) {
                return new Response(JSON.stringify(data), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' }
                });
            });
        }

        const esVinculacion =
            esUrl(url, 'prueba_telefonia/api/vincular_interaccion_zadarma.php');
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

        const originalRequest = fetchOriginal(input, init);
        if (claveRegistro) {
            const confirmation = originalRequest.then(async function (response) {
                const data = await response.clone().json();
                if (!response.ok || data?.ok !== true || Number(data.interaccion_id || 0) <= 0) {
                    throw new Error('El servidor no confirmó el identificador de la interacción.');
                }
                return data;
            });
            guardadosPorLlamada.set(claveRegistro, confirmation);
            // No conservar un intento fallido, permite reintentar tras error.
            confirmation.catch(function () {
                if (guardadosPorLlamada.get(claveRegistro) === confirmation) {
                    guardadosPorLlamada.delete(claveRegistro);
                }
            });
        }

        return originalRequest.then(async function (response) {
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
