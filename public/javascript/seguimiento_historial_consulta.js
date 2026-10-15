/* Una consulta compartida por expediente: reintentos acotados ante 5xx y red.
 * Los fallos no se guardan en caché; los 401/403/4xx no se reintentan.
 */
(function (global) {
    'use strict';
    function consultar(seguimientoId) {
        const id = Number(seguimientoId);
        if (!Number.isSafeInteger(id) || id <= 0) {
            return Promise.reject(new Error('Seguimiento no válido.'));
        }
        const previous = global.IMPE_HISTORIAL_LLAMADAS_EXPEDIENTE;
        if (previous && previous.seguimientoId === id && previous.promise) {
            return previous.promise;
        }
        const url = 'prueba_telefonia/api/llamadas_seguimiento.php?seguimiento_id=' +
            encodeURIComponent(id);
        const request = async function () {
            let response;
            try {
                response = await fetch(url, {
                    headers: {'X-Requested-With': 'fetch'},
                    credentials: 'same-origin', cache: 'no-store'
                });
            } catch (error) {
                const fail = new Error('No se pudo conectar con el historial de llamadas.');
                fail.reintentable = true;
                throw fail;
            }
            let data = null;
            try {
                const body = await response.text();
                data = body.trim() ? JSON.parse(body) : null;
            } catch (_) {}
            if (!response.ok || !data?.ok) {
                const fail = new Error(
                    data?.mensaje || (response.status === 401 || response.status === 403
                        ? 'La sesión expiró o no tienes acceso. Actualiza el CRM.'
                        : 'No fue posible consultar el historial (HTTP ' + response.status + ').')
                );
                fail.reintentable = response.status >= 500 ||
                    (response.ok && !data);
                throw fail;
            }
            return data;
        };
        const promise = (async function () {
            for (let attempt = 0; attempt < 3; attempt++) {
                try { return await request(); }
                catch (error) {
                    if (!error.reintentable || attempt === 2) throw error;
                    await new Promise(resolve => setTimeout(resolve, attempt === 0 ? 600 : 1300));
                }
            }
        })();
        const entry = {seguimientoId: id, promise};
        global.IMPE_HISTORIAL_LLAMADAS_EXPEDIENTE = entry;
        // No dejar una promesa fallida o completada como fuente para futuras
        // visitas; compartir sólo mientras existe una petición pendiente.
        promise.then(() => {
            if (global.IMPE_HISTORIAL_LLAMADAS_EXPEDIENTE === entry)
                global.IMPE_HISTORIAL_LLAMADAS_EXPEDIENTE = null;
        }, () => {
            if (global.IMPE_HISTORIAL_LLAMADAS_EXPEDIENTE === entry)
                global.IMPE_HISTORIAL_LLAMADAS_EXPEDIENTE = null;
        });
        return promise;
    }
    global.IMPE_CONSULTAR_LLAMADAS_EXPEDIENTE = consultar;
})(window);
