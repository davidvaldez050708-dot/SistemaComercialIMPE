const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright');

const bootstrap = path.join(process.env.BOOTSTRAP_MODULE_PATH || require.resolve('bootstrap/package.json').replace(/package\.json$/, ''),
  'dist/js/bootstrap.bundle.min.js');
const tablero = path.join(__dirname, '../public/javascript/embudo_comercial_v3_operativo.js');
const css = path.join(__dirname, '../public/css/embudo_v3_operativo.css');
const cssBase = path.join(__dirname, '../public/css/embudo_comercial.css');
const origen = 'http://embudo.test';
const guardar = '#impe-v3o-potencial-guardar';
const aviso = '.system-toast.show';
const fixture = `<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/embudo.css"><link rel="stylesheet" href="/tablero.css"><main class="impe-embudo--v3-operativo">
  <article class="impe-v3o-card" data-asignacion-id="1" data-etapa="INTERESADO" data-v3-subetapa="CALIFICADO">
    <button data-v3-gestionar>Gestionar</button>
    <button data-v3-actividad data-version="7" data-pendiente="1">Actividad</button>
  </article>
  <article class="impe-v3o-card" data-asignacion-id="2" data-etapa="CONTACTADO" data-v3-destinos="SEGUIMIENTO,POTENCIAL">
    <button data-v3-mover>Mover</button>
  </article>
  <dialog id="impe-v3o-dialog"><form id="impe-v3o-mover-form">
    <span id="impe-v3o-dialog-datos"></span>
    <select id="impe-v3o-destino"><option>SEGUIMIENTO</option><option>POTENCIAL</option></select>
    <input id="impe-v3o-motivo"><label id="impe-v3o-fecha-label">
    <input id="impe-v3o-fecha" type="datetime-local"></label>
    <label id="impe-v3o-calificacion-label" hidden>
      <input id="impe-v3o-calificacion" type="checkbox">Intención confirmada
    </label><p id="impe-v3o-dialog-error" hidden></p><button id="impe-v3o-enviar">Confirmar</button>
  </form></dialog>
  <dialog id="impe-v3o-potencial-dialog"><form id="impe-v3o-potencial-form">
    <span id="impe-v3o-potencial-datos"></span>
    <select id="impe-v3o-potencial-subetapa"><option>CALIFICADO</option><option>REUNIENDO_DOCUMENTOS</option></select>
    <input id="impe-v3o-potencial-fecha"><input id="impe-v3o-potencial-motivo">
    <p id="impe-v3o-potencial-error" hidden></p><button id="impe-v3o-potencial-guardar">Guardar</button>
  </form></dialog>
  <dialog class="impe-embudo-dialog" id="impe-v3o-actividad-dialog"><form>
    <header><h3>Actividad comercial</h3></header>
    <div class="impe-v3o-actividad-cuerpo">
      <p data-actividad-prospecto></p>
      <select name="operacion"><option>REGISTRAR</option><option>PROGRAMAR</option><option>REPROGRAMAR</option><option>COMPLETAR</option></select>
      <label data-actividad-fecha><input name="fecha" type="datetime-local"></label>
      <label data-actividad-subetapa><select name="subetapa"><option value=""></option></select></label>
      <input name="motivo" required>
      <details class="impe-v3o-actividad-historial"><summary>Historial de actividades</summary>
        <div class="impe-v3o-actividad-historial-panel">
          <p class="impe-v3o-actividad-historial-estado" data-actividad-historial-estado></p>
          <div class="impe-v3o-actividad-historial-scroll"><ol data-actividad-historial></ol></div>
          <button type="button" data-actividad-mas hidden>Ver anteriores</button>
        </div>
      </details>
      <p data-actividad-error hidden></p>
    </div>
    <div class="impe-embudo-dialog-footer"><button type="submit">Guardar actividad</button></div>
  </form></dialog>
  </main><script src="/bootstrap.js"></script><script src="/tablero.js"></script>`;

async function gestionar(page) {
  await page.locator('[data-v3-gestionar]').click();
  await page.selectOption('#impe-v3o-potencial-subetapa', 'REUNIENDO_DOCUMENTOS');
  await page.fill('#impe-v3o-potencial-motivo', 'Solicitó reunir documentos');
  await page.click(guardar);
}

async function probar(browser, modo) {
  const page = await browser.newPage();
  let navegaciones = 0;
  let guardados = 0;
  await page.addInitScript(sinStorage => {
    window.IMPE_CURRENT_USER_ID = 42;
    window.IMPE_CSRF_TOKEN = 'fixture';
    window.IMPE_TELEPHONY_PERSISTENT = { getState: () => ({ active: Boolean(window.llamadaActiva) }) };
    if (sinStorage) Object.defineProperty(window, 'sessionStorage', { get() { throw new Error('bloqueado'); } });
  }, modo === 'sin-storage');
  await page.route(origen + '/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/bootstrap.js') return route.fulfill({ path: bootstrap });
    if (url.pathname === '/tablero.js') return route.fulfill({ path: tablero });
    if (url.pathname === '/tablero.css') return route.fulfill({ path: css });
    if (url.pathname === '/embudo.css') return route.fulfill({ path: cssBase });
    if (url.pathname === '/index.php') {
      if (modo === 'actividad') {
        assert.equal(url.searchParams.get('action'), 'seguimientoOperativoV3');
        if (route.request().method() === 'GET') {
          assert.equal(url.searchParams.get('asignacion_id'), '1');
          const antes=Number(url.searchParams.get('antes')||0);
          const ids=antes===0?Array.from({length:20},(_,i)=>40-i):
            antes===21?Array.from({length:5},(_,i)=>20-i):[];
          return route.fulfill({ json: { ok: true, historial: ids.map(id=>({
            id, creado_at: '2026-11-11 20:00:00', accion: 'PROGRAMAR', usuario_id: 42,
            motivo: id===40?'<b>Primera gestión</b>':'Gestión comercial número '+id,
            fecha_pendiente: '2099-01-01 16:00:00'
          })) } });
        }
        const datos = new URLSearchParams(route.request().postData());
        assert.equal(datos.get('version'), '7');
        assert.equal(datos.get('etapa_actual'), 'INTERESADO');
        assert.equal(datos.get('operacion'), 'REPROGRAMAR');
        assert.equal(datos.get('fecha'), '2099-01-02T10:00');
      }
      guardados++;
      assert.equal(route.request().headers()['x-csrf-token'], 'fixture');
      if (modo === 'calificacion') {
        assert.equal(url.searchParams.get('action'), 'moverEtapaV3');
        const datos = new URLSearchParams(route.request().postData());
        assert.equal(datos.get('etapa_anterior'), 'CONTACTADO');
        assert.equal(datos.get('etapa_nueva'), 'POTENCIAL');
        assert.equal(datos.get('calificacion_confirmada'), '1');
        assert.equal(datos.get('proxima_accion'), '');
      }
      if (modo === 'conflicto') return route.fulfill({ status: 409, json: { ok: false, mensaje: 'La tarjeta cambió.' } });
      if (modo === 'html') return route.fulfill({ status: 503, body: '<html>Servicio temporal</html>' });
      if (modo === 'llamada') await page.evaluate(() => { window.llamadaActiva = true; });
      return route.fulfill({ json: { ok: true } });
    }
    navegaciones++;
    return route.fulfill({ contentType: 'text/html', body: fixture });
  });
  await page.goto(origen);
  if (modo === 'movimiento' || modo === 'calificacion') {
    await page.locator('[data-v3-mover]').click();
    await page.fill('#impe-v3o-motivo', 'Agendó llamada de seguimiento');
    if (modo === 'calificacion') {
      await page.selectOption('#impe-v3o-destino', 'POTENCIAL');
      await page.click('#impe-v3o-enviar');
      assert.equal(guardados, 0);
      assert.equal(await page.locator('#impe-v3o-calificacion').evaluate(el => el.validity.valueMissing), true);
      await page.check('#impe-v3o-calificacion');
    } else {
      await page.fill('#impe-v3o-fecha', '2099-01-01T10:00');
    }
    await page.click('#impe-v3o-enviar');
  } else if (modo === 'actividad') {
    await page.locator('[data-v3-actividad]').click();
    await page.locator('details summary').click();
    const entrada = page.locator('[data-actividad-historial] li');
    await entrada.first().waitFor();
    assert.ok((await entrada.first().textContent()).includes('<b>Primera gestión</b>'));
    assert.equal(await entrada.locator('b').count(), 0);
    assert.equal(await entrada.first().locator('.impe-v3o-actividad-item-tipo').textContent(), 'Programación');
    assert.equal(await entrada.count(), 20);
    assert.equal(await page.locator('[data-actividad-mas]').isVisible(), true);
    const dimensiones=await page.locator('.impe-v3o-actividad-historial-scroll').evaluate(el=>({
      visible:el.clientHeight,total:el.scrollHeight
    }));
    assert.ok(dimensiones.visible<=252 && dimensiones.total>dimensiones.visible,
      'El historial debe mantener el scroll interno dentro de 252 px');
    await page.locator('[data-actividad-mas]').click();
    await entrada.nth(24).waitFor();
    assert.equal(await entrada.count(),25);
    assert.equal(await page.locator('[data-actividad-mas]').isVisible(),false);
    const dialogo=await page.locator('#impe-v3o-actividad-dialog').evaluate(el=>({
      visible:el.getBoundingClientRect().height,limite:window.innerHeight
    }));
    assert.ok(dialogo.visible<dialogo.limite,'El modal debe mantenerse dentro de la pantalla');
    await page.selectOption('[name=operacion]', 'REPROGRAMAR');
    await page.fill('[name=fecha]', '2099-01-02T10:00');
    await page.fill('[name=motivo]', 'Cambio de horario acordado');
    await page.getByRole('button', { name: 'Guardar actividad' }).click();
  } else {
    await gestionar(page);
  }
  if (modo === 'conflicto' || modo === 'html') {
    const error = page.locator('#impe-v3o-potencial-error');
    await error.waitFor({ state: 'visible' });
    assert.equal(await error.textContent(), modo === 'conflicto'
      ? 'La tarjeta cambió.' : 'No se pudo registrar la gestión.');
    assert.equal(await page.locator('#impe-v3o-potencial-dialog[open]').count(), 1);
    assert.equal(await page.locator(aviso).count(), 0);
    assert.equal(navegaciones, 1);
  } else {
    const toast = page.locator(aviso);
    await toast.waitFor({ state: 'visible' });
    assert.equal(await toast.getAttribute('role'), 'status');
    assert.equal(await toast.getAttribute('aria-live'), 'polite');
    assert.match(await toast.textContent(), ['movimiento', 'calificacion'].includes(modo)
      ? /Movimiento registrado/ : modo === 'actividad' ? /Actividad comercial registrada/ : /Gestión comercial registrada/);
    assert.equal(await toast.count(), 1);
    assert.equal(navegaciones, ['llamada', 'sin-storage'].includes(modo) ? 1 : 2);
    if (modo === 'sin-storage') {
      await Promise.all([page.waitForNavigation(), toast.getByRole('button', { name: 'Cerrar aviso' }).click()]);
      assert.equal(navegaciones, 2);
      assert.equal(await page.locator(aviso).count(), 0);
    } else {
      await toast.getByRole('button', { name: 'Cerrar aviso' }).click();
      await toast.waitFor({ state: 'detached' });
      if (modo === 'llamada') {
        assert.equal(navegaciones, 1);
      } else {
        await page.reload();
        assert.equal(await page.locator(aviso).count(), 0);
      }
    }
  }
  assert.equal(guardados, 1);
  await page.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || undefined });
  try {
    for (const modo of ['gestion', 'movimiento', 'calificacion', 'actividad', 'conflicto', 'html', 'llamada', 'sin-storage']) {
      await probar(browser, modo);
      console.log('Toast V3:', modo, 'OK');
    }
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
