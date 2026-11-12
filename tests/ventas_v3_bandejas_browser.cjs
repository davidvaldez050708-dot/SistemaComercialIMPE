const assert=require('node:assert/strict');
const path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');

const script=path.join(__dirname,'../public/javascript/embudo_comercial_v3_bandejas.js');
const css=path.join(__dirname,'../public/css/embudo_v3_bandejas.css');
const baseCss=path.join(__dirname,'../public/css/embudo_comercial.css');
const origin='http://bandejas-v3.test';

const html=`<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="/base.css"><link rel="stylesheet" href="/bandejas.css"></head>
<body><main class="impe-embudo impe-embudo--v3-operativo" data-dia="2026-11-12">
<article class="impe-v3o-card" data-asignacion-id="101" data-etapa="NUEVO"
 data-nombre="Prospecto de prueba">
<button data-v3b-accion="RECUPERACION">Revisar Recuperación</button>
<button data-v3b-accion="DESCARTAR">Descartar</button></article>
<article class="impe-v3o-card" data-asignacion-id="102" data-etapa="RECUPERACION"
 data-nombre="Prospecto en Recuperación">
<button data-v3b-llamar="+527771234567">Llamar recuperación</button>
<button data-v3b-accion="REACTIVAR">Reactivar</button></article>
<article data-asignacion-id="103" data-etapa="RECUPERACION"
 data-no-contactar="1"><button data-v3b-llamar="+527771234599">
 Llamada bloqueada</button></article>
<dialog class="impe-embudo-dialog impe-v3b-dialog" id="impe-v3b-dialog">
<form method="dialog" id="impe-v3b-form">
<header><h3 id="impe-v3b-titulo">Revisión</h3><button type="button" data-v3b-cerrar>Cerrar</button></header>
<div class="impe-v3b-form-cuerpo">
<p id="impe-v3b-datos"></p><p id="impe-v3b-ayuda"></p>
<label data-v3b-dia hidden>Jornada<input name="dia" type="date" max="2026-11-12"></label>
<label data-v3b-subetapa hidden>Motivo<select name="subetapa">
<option value="">Seleccionar</option><option value="NO_INTERESADO">No interesado</option>
<option value="NUMERO_INVALIDO">Número inválido</option></select></label>
<textarea name="motivo" minlength="8" maxlength="125" required></textarea>
<label><input name="confirmacion" type="checkbox" value="1" required>Confirmar</label>
<p data-v3b-error role="alert" hidden></p>
</div><div class="impe-embudo-dialog-footer">
<button type="button" data-v3b-cerrar>Cancelar</button>
<button type="submit" id="impe-v3b-confirmar">Confirmar decisión</button>
</div></form></dialog>
</main><script src="/bandejas.js"></script></body></html>`;

async function run(mode) {
  const browser=await chromium.launch({executablePath:process.env.CHROME_PATH||undefined});
  const page=await browser.newPage();
  let writes=0;
  let navigations=0;
  await page.addInitScript(()=>{
    window.IMPE_CURRENT_USER_ID=8;
    window.IMPE_CSRF_TOKEN='csrf-para-test';
    window.__dialed=[];
    window.IMPE_TELEPHONY_PERSISTENT={
      getState:()=>({active:Boolean(window.pruebaLlamadaActiva)}),
      openDialer:options=>window.__dialed.push(options)
    };
  });
  await page.route(origin+'/**',async route=>{
    const req=route.request();
    const url=new URL(req.url());
    if(url.pathname==='/base.css')return route.fulfill({path:baseCss});
    if(url.pathname==='/bandejas.css')return route.fulfill({path:css});
    if(url.pathname==='/bandejas.js')return route.fulfill({path:script});
    if(url.pathname==='/index.php'){
      writes++;
      assert.equal(url.searchParams.get('controller'),'embudoComercial');
      assert.equal(url.searchParams.get('action'),'bandejasOperativasV3');
      assert.equal(req.method(),'POST');
      assert.equal(req.headers()['x-csrf-token'],'csrf-para-test');
      const body=new URLSearchParams(req.postData());
      assert.equal(body.get('confirmacion'),'1');
      if(mode==='recuperacion'){
        assert.equal(body.get('operacion'),'RECUPERACION');
        assert.equal(body.get('asignacion_id'),'101');
        assert.equal(body.get('dia'),'2026-11-12');
        assert.equal(body.get('subetapa'),'');
      }
      if(mode==='descarte'){
        assert.equal(body.get('operacion'),'DESCARTAR');
        assert.equal(body.get('subetapa'),'NO_INTERESADO');
        return route.fulfill({status:409,json:{ok:false,mensaje:'No hay resultado PBX acreditado.'}});
      }
      if(mode==='reactivacion'){
        assert.equal(body.get('operacion'),'REACTIVAR');
        assert.equal(body.get('asignacion_id'),'102');
      }
      return route.fulfill({json:{ok:true,mensaje:'Decisión comercial registrada y auditada.'}});
    }
    navigations++;
    return route.fulfill({body:html,contentType:'text/html'});
  });
  try{
    await page.goto(origin);
    if(mode==='marcacion'){
      await page.getByRole('button',{name:'Llamar recuperación'}).click();
      assert.deepEqual(await page.evaluate(()=>window.__dialed),
        [{number:'+527771234567'}]);
      const contexto=await page.evaluate(()=>JSON.parse(
        sessionStorage.getItem('impe:embudo:llamada-pendiente')||'null'));
      assert.equal(contexto.asignacionId,'102');
      assert.equal(contexto.usuarioId,8);
      assert.equal(contexto.numero,'+527771234567');
      await page.getByRole('button',{name:'Llamada bloqueada'}).click();
      assert.equal((await page.evaluate(()=>window.__dialed)).length,1,
        'No contactar nunca abre el dialer en Gestión Comercial');
      assert.equal(writes,0,'abrir el dialer no crea resultados');
      assert.equal(navigations,1,'no se pierde contexto por navegar');
      console.log('Bandeja V3: marcación y No contactar OK');
      return;
    }
    if(mode==='recuperacion'){
      await page.getByRole('button',{name:'Revisar Recuperación'}).click();
      assert.equal(await page.locator('[name=dia]').inputValue(),'2026-11-12');
      assert.equal(await page.locator('[data-v3b-subetapa]').isVisible(),false);
    } else if(mode==='descarte'){
      await page.getByRole('button',{name:'Descartar'}).click();
      assert.equal(await page.locator('[data-v3b-subetapa]').isVisible(),true);
      assert.equal(await page.locator('option[value=NO_CONTACTAR]').count(),0);
      await page.selectOption('[name=subetapa]','NO_INTERESADO');
    } else {
      await page.evaluate(()=>window.pruebaLlamadaActiva=true);
      await page.getByRole('button',{name:'Reactivar'}).click();
    }
    await page.locator('[name=motivo]').fill('Motivo comercial revisado y confirmado.');
    await page.locator('[name=confirmacion]').check();
    await page.locator('#impe-v3b-confirmar').click();
    if(mode==='descarte'){
      await page.locator('[data-v3b-error]:visible').waitFor();
      assert.match(await page.locator('[data-v3b-error]').textContent(),/PBX acreditado/);
      assert.equal(await page.locator('#impe-v3b-dialog[open]').count(),1);
      assert.equal(navigations,1);
    } else if(mode==='reactivacion'){
      await page.locator('.system-toast.show').waitFor();
      assert.equal(navigations,1,'no recargar durante llamada activa');
    } else {
      await page.waitForLoadState('load');
      await page.locator('.system-toast.show').waitFor();
      assert.equal(navigations,2,'recargar solo tras guardar');
    }
    assert.equal(writes,1);
    console.log('Bandeja V3:',mode,'OK');
  }finally{await browser.close();}
}
(async()=>{for(const mode of ['marcacion','recuperacion','descarte','reactivacion'])await run(mode);})()
  .catch(err=>{console.error(err);process.exitCode=1;});
