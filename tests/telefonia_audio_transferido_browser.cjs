const fs=require('node:fs'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
(async()=>{const b=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'msedge'});try{
 const page=await b.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const view=cp.execFileSync('php',['tests/fixtures/analista_atencion_vista.php','audio'],{encoding:'utf8'});
 const bootstrap=fs.readFileSync(process.env.BOOTSTRAP_MODULE_PATH+'/dist/js/bootstrap.bundle.min.js','utf8');
 let status=200,data={ok:true,estado:'GRABACION_EN_PREPARACION',disponible:false,segmentos:0};
 await page.route('http://atencion.test/**',r=>{if(r.request().url().includes('grabacion_transferida_pbx.php'))return r.fulfill({status,json:data});return r.fulfill({contentType:'text/html',body:`<!doctype html><meta charset=utf-8>${view}<script>${bootstrap}</script><script>${fs.readFileSync('public/javascript/telefonia_segmentos_player.js','utf8')}</script><script>${fs.readFileSync('public/javascript/seguimiento_entrantes_analista.js','utf8')}</script>`});});
 await page.goto('http://atencion.test/');const toggle=page.locator('[data-incoming-pbx-toggle]').first(),panel=page.locator('[data-incoming-pbx-panel]').first();
 for(const code of ['GRABACION_EN_PREPARACION','SIN_GRABACION_REPORTADA','TRAMO_PENDIENTE_VINCULACION','ERROR_TEMPORAL','DENIED']){
  status=code==='DENIED'?403:200;data={ok:status===200,estado:code,disponible:false,segmentos:0};
  await toggle.click();await panel.locator('[data-incoming-pbx-error]').waitFor({state:'visible'});
  assert(!/Grabación disponible/.test(await panel.textContent()),'No anunciar disponibilidad y error simultáneamente');assert.equal(await panel.locator('audio').count(),0);
  if(code==='DENIED')assert.match(await panel.textContent(),/Acceso no autorizado/);
  await toggle.click();
 }
 status=200;data={ok:true,estado:'DISPONIBLE',disponible:true,segmentos:2,segmentos_info:[{indice:0,extension:'100',etiqueta:'Recepción · Ext. 100',segundos:30},{indice:1,extension:'101',etiqueta:'Extensión 101',segundos:22}]};
 await toggle.click();await panel.locator('audio').first().waitFor({state:'attached'});assert.equal(await panel.locator('audio').count(),2);assert.match(await panel.textContent(),/Recepción.*100.*Extensión 101/s);await toggle.click();
 data={...data,estado:'DISPONIBLE_PARCIAL',segmentos:1,segmentos_info:[data.segmentos_info[1]]};await toggle.click();await panel.locator('audio').waitFor({state:'attached'});assert.match(await panel.locator('audio').getAttribute('src'),/segmento=1/);assert.match(await panel.textContent(),/otro tramo aún en preparación/);
 assert.deepEqual(errors,[]);console.log('Audio Analista: dos tramos, uno preparado, reintentos, permisos, estados coherentes y reproductor real: OK');
}finally{await b.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
