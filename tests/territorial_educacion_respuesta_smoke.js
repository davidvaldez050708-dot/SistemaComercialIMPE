'use strict';

/* Comprueba recuperación de errores transitorios sin navegar ni acceder a DB. */
const assert = require('node:assert/strict');
const {
    consultarEducacionLocal
} = require('../public/javascript/educacion_objetivo.js');

const respuesta = (status, texto, tipo = 'application/json') => ({
    status,
    ok: status >= 200 && status < 300,
    headers: { get: (nombre) => nombre.toLowerCase() === 'content-type' ? tipo : null },
    text: async () => texto
});

async function probar() {
    const correcto = JSON.stringify({
        ok: true,
        estado: { clave: '15', metricas: {} },
        perfil_educativo_prioritario: { disponible: true }
    });
    let veces = 0;

    let resultado = await consultarEducacionLocal('public/inegi_educacion_objetivo.php', async () => {
        veces++;
        return veces === 1
            ? respuesta(503, '<html>Hostinger temporalmente no disponible</html>', 'text/html')
            : respuesta(200, correcto);
    });
    assert.equal(veces, 2);
    assert.equal(resultado.estado.clave, '15');

    veces = 0;
    resultado = await consultarEducacionLocal('public/inegi_educacion_objetivo.php', async () => {
        veces++;
        return veces === 1
            ? respuesta(200, '<html>Respuesta inesperada</html>', 'text/html')
            : respuesta(200, correcto);
    });
    assert.equal(veces, 2);
    assert.equal(resultado.ok, true);

    veces = 0;
    await assert.rejects(
        consultarEducacionLocal('url', async () => {
            veces++;
            return respuesta(403, '{"ok":false,"mensaje":"Acceso denegado"}');
        }),
        /Acceso denegado/
    );
    assert.equal(veces, 1, 'No reintentar respuestas JSON de autorización');

    veces = 0;
    await assert.rejects(
        consultarEducacionLocal('url', async () => {
            veces++;
            return respuesta(401, '<html>Sesión expirada</html>', 'text/html');
        }),
        /HTTP 401/
    );
    assert.equal(veces, 1, 'No reintentar HTML de sesión expirada');

    veces = 0;
    await assert.rejects(
        consultarEducacionLocal('url', async () => {
            veces++;
            return respuesta(200, '<html>Fallo repetido</html>', 'text/html');
        }),
        /HTTP 200/
    );
    assert.equal(veces, 2, 'Límite estricto de un reintento');

    veces = 0;
    resultado = await consultarEducacionLocal('url', async () => {
        veces++;
        if (veces === 1) {
            throw new TypeError('Fallo temporal de red');
        }
        return respuesta(200, correcto);
    });
    assert.equal(veces, 2);
    assert.equal(resultado.ok, true);

    veces = 0;
    await assert.rejects(
        consultarEducacionLocal('url', async () => {
            veces++;
            return respuesta(502, '{"ok":false,"mensaje":"Consulta local temporalmente no disponible"}');
        }),
        /Consulta local temporalmente no disponible/
    );
    assert.equal(veces, 1);

    console.log('Consulta educativa: OK (HTML transitorio, red, sesión, JSON y límite de reintentos)');
}
probar().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
