/* Contrato de interfaz V3 para solicitud institucional; sin BD/Meta reales. */
const assert=require('node:assert/strict');
const path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const app='http://inscripciones-v3.test';
const js=path.join(__dirname,'../public/javascript/embudo_comercial_v3_inscripcion.js');
const html=`<!doctype html><html><head><meta charset="utf-8"></head><body>
<main class="impe-embudo--v3-operativo">
  <article data-asignacion-id="101" data-etapa="PROCESO_INSCRIPCION" data-v3-subetapa="SOLICITUD_INICIADA"
     data-nombre="Prospecto sintético"><button data-v3i-iniciar>Verificar inscripción</button></article>
  <article data-asignacion-id="102" data-etapa="POTENCIAL" data-v3-subetapa="NO_CONTACTAR">
     <button data-v3i-iniciar>Inscripción bloqueada</button></article>
  <article data-asignacion-id="103" data-etapa="NUEVO">
     <button data-v3i-iniciar>Ingreso no autorizado</button></article>
  <dialog id="impe-v3i-dialog" class="impe-v3b-dialog">
    <form id="impe-v3i-form" method="dialog">
      <header><h3>Inscripción institucional</h3>
       <button type="button" data-v3i-cerrar>Cerrar</button></header>
      <p id="impe-v3i-datos"></p>
      <section data-v3i-preparar hidden>
        <input type="checkbox" name="documentos"><input type="checkbox" name="comprobante">
        <button type="button" data-v3i-arrancar>Iniciar inscripción</button>
      </section>
      <p data-v3i-ligas hidden></p>
      <button type="button" data-v3i-buscar>Buscar solicitud recibida</button>
      <p data-v3i-estado role="status"></p>
      <section data-v3i-confirmar hidden>
         <textarea name="motivo" minlength="8" maxlength="125" required></textarea>
         <label><input name="confirmacion" type="checkbox" value="1" required>Confirmo</label>
      </section>
      <p data-v3i-error hidden role="alert"></p>
      <button type="submit" data-v3i-guardar disabled>Vincular solicitud</button>
    </form>
  </dialog>
</main><script src="/inscripcion.js"></script></body></html>`;

async function run(mode){
  const browser=await chromium.launch({executablePath:process.env.CHROME_PATH||undefined});
  const page=await browser.newPage();
  let consultas=0,vinculos=0,navegaciones=0;
  await page.addInitScript(()=>{
    window.IMPE_CURRENT_USER_ID=8;
    window.IMPE_CSRF_TOKEN='csrf-sintetico';
    window.IMPE_TELEPHONY_PERSISTENT={getState:()=>({active:true})};
  });
  await page.route(app+'/**',async route=>{
    const req=route.request(),url=new URL(req.url());
    if(url.pathname==='/inscripcion.js')return route.fulfill({path:js});
    if(url.pathname==='/index.php'){
      assert.equal(req.method(),'POST');
      assert.equal(req.headers()['x-csrf-token'],'csrf-sintetico');
      assert.equal(url.searchParams.get('action'),'inscripcionOperativaV3');
      const b=new URLSearchParams(req.postData());
      assert.equal(b.get('asignacion_id'),'101');
      if(b.get('operacion')==='BUSCAR'){
        consultas++;
        const consulta=mode==='ambigua'
          ?{estado:'REVISION_INSTITUCIONAL',mensaje:'Revisión institucional requerida.'}
          :mode==='incompleta'
          ?{estado:'SOLICITUD_INCOMPLETA',mensaje:'Falta el detalle para aparecer en Inscritos.'}
          :mode==='ausente'
          ?{estado:'SIN_SOLICITUD',mensaje:'No se encontró inscripción.'}
          :{estado:'CANDIDATA',formulario_id:501,fecha:'2026-11-12 13:00:00'};
        return route.fulfill({json:{ok:true,consulta}});
      }
      assert.equal(b.get('operacion'),'VINCULAR');
      assert.equal(b.get('formulario_id'),'501');
      assert.equal(b.get('confirmacion'),'1');
      assert.match(b.get('motivo')||'',/Solicitud institucional/);
      vinculos++;
      if(mode==='conflicto'){
        return route.fulfill({status:409,json:{ok:false,
          mensaje:'La solicitud ya quedó vinculada a otro expediente.'}});
      }
      return route.fulfill({json:{ok:true,movimiento:{etapa:'PROCESO_INSCRIPCION'}}});
    }
    navegaciones++;
    return route.fulfill({body:html,contentType:'text/html'});
  });
  try{
    await page.goto(app);
    await page.getByRole('button',{name:'Inscripción bloqueada'}).click();
    assert.equal(await page.locator('#impe-v3i-dialog[open]').count(),0,
       'No contactar no permite abrir la decisión');
    await page.getByRole('button',{name:'Ingreso no autorizado'}).click();
    assert.equal(await page.locator('#impe-v3i-dialog[open]').count(),0,
       'Nuevos no permite iniciar trámites');
    await page.getByRole('button',{name:'Verificar inscripción'}).click();
    assert.equal(await page.locator('#impe-v3i-dialog[open]').count(),1);
    assert.equal(await page.locator('[data-v3i-guardar]').isEnabled(),false);
    await page.getByRole('button',{name:'Buscar solicitud recibida'}).click();
    await page.waitForFunction(()=>document.querySelector('[data-v3i-buscar]').disabled===false);
    assert.equal(consultas,1);
    if(['ausente','ambigua','incompleta'].includes(mode)){
      assert.equal(await page.locator('[data-v3i-confirmar]').isVisible(),false);
      assert.equal(await page.locator('[data-v3i-guardar]').isEnabled(),false);
      if(mode==='incompleta') assert.match(await page.locator('[data-v3i-estado]').textContent(),/Inscritos/);
    }else{
      assert.equal(await page.locator('[data-v3i-confirmar]').isVisible(),true);
      await page.locator('[name=motivo]').fill('Solicitud institucional recibida');
      await page.locator('[name=confirmacion]').check();
      await page.locator('[data-v3i-guardar]').click();
      if(mode==='conflicto'){
        await page.locator('[data-v3i-error]:visible').waitFor();
        assert.match(await page.locator('[data-v3i-error]').textContent(),/vinculada/);
        assert.equal(await page.locator('#impe-v3i-dialog[open]').count(),1);
      }else {
        await page.locator('.system-toast.show').waitFor();
        assert.equal(await page.locator('#impe-v3i-dialog[open]').count(),0);
        assert.equal(navegaciones,1,'no recargar durante llamada');
      }
    }
    assert.equal(vinculos,['ausente','ambigua','incompleta'].includes(mode)?0:1);
    console.log('CHECK_OK: inscripción V3 Chromium',mode);
  }finally{await browser.close();}
}
(async()=>{for(const m of ['ausente','ambigua','incompleta','conflicto','valida'])await run(m);})()
  .catch(e=>{console.error(e);process.exitCode=1;});
