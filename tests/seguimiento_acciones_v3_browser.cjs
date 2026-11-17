// Interfaz de actividades: campos por etapa, CSRF, doble envío y refresco.
const assert=require('node:assert/strict');
const path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const origen='http://acciones-v3.test';
const tablero=path.join(__dirname,'../public/javascript/embudo_comercial_v3_operativo.js');
const fixture=etapa=>`<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/embudo.css"><link rel="stylesheet" href="/tablero.css"><main class="impe-embudo--v3-operativo">
  <article class="impe-v3o-card" data-asignacion-id="1" data-etapa="${etapa}" data-v3-subetapa="CALIFICADO">
    <button data-v3-gestionar>Gestionar</button>
    <button data-v3-actividad data-version="7" data-pendiente="0">Actividad</button>
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
      <p data-actividad-error hidden></p>
    </div>
    <div class="impe-embudo-dialog-footer"><button type="submit">Guardar actividad</button></div>
  </form></dialog>
  </main><script src="/bootstrap.js"></script><script src="/tablero.js"></script>`;
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROME_PATH||undefined});
 try{
  for(const etapa of ['NUEVO','CONTACTADO','POTENCIAL','INTERESADO','SEGUIMIENTO','PROCESO_INSCRIPCION']){
   const page=await browser.newPage();let guardados=0,navegaciones=0;
   await page.addInitScript(()=>{
    window.IMPE_CURRENT_USER_ID=22;window.IMPE_CSRF_TOKEN='fixture';
    window.IMPE_TELEPHONY_PERSISTENT={getState:()=>({active:false})};
   });
   await page.route(origen+'/**',async route=>{
    const req=route.request(),url=new URL(req.url());
    if(url.pathname==='/tablero.js')return route.fulfill({path:tablero});
    if(url.pathname.endsWith('.css') || url.pathname==='/bootstrap.js')return route.fulfill({body:''});
    if(url.pathname==='/index.php'){
     assert.equal(url.searchParams.get('action'),'seguimientoOperativoV3');
     assert.equal(req.method(),'POST');assert.equal(req.headers()['x-csrf-token'],'fixture');
     const b=new URLSearchParams(req.postData());
     assert.equal(b.get('etapa_actual'),etapa);assert.equal(b.get('operacion'),'PROGRAMAR');
     assert.equal(b.get('fecha'),'2099-01-02T10:00');assert.equal(b.get('version'),'7');
     guardados++;
     await new Promise(r=>setTimeout(r,150));
     return route.fulfill({json:{ok:true}});
    }
    navegaciones++;return route.fulfill({body:fixture(etapa),contentType:'text/html'});
   });
   await page.goto(origen);
   await page.locator('[data-v3-actividad]').click();
   const sub=page.locator('[data-actividad-subetapa]');
   assert.equal(await sub.isVisible(),etapa==='SEGUIMIENTO');
   await page.locator('[name=operacion]').selectOption('PROGRAMAR');
   assert.equal(await sub.isVisible(),['CONTACTADO','POTENCIAL','INTERESADO','SEGUIMIENTO'].includes(etapa));
   await page.locator('[name=fecha]').fill('2099-01-02T10:00');
   await page.locator('[name=motivo]').fill('Próxima gestión acordada.');
   await page.locator('#impe-v3o-actividad-dialog [type=submit]').dblclick();
   await page.waitForFunction(()=>!document.querySelector('#impe-v3o-actividad-dialog').open);
   await page.locator('.system-toast.show').waitFor();
   assert.equal(guardados,1);assert.equal(navegaciones,2);
   console.log('CHECK_OK interfaz actividades',etapa);await page.close();
  }
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
