'use strict';
/* Prueba de recepción real en DOM: API de audio simulada y deliberadamente lenta. */
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const html=execFileSync('php',['-r',
  "define('BASE_URL','https://reception.test/');require 'tests/fixtures/marketing_recepcion_vista.php';"],
  {cwd:root,encoding:'utf8'});
const sources=['telefonia_numeros','telefonia_segmentos_player',
  'telefonia_grabaciones_ventas','telefonia_recepcion_marketing'];
const wrapped='<!doctype html><html lang="es"><head><meta charset="utf-8"></head><body>'+
  html+sources.map(s=>'<script src="/public/javascript/'+s+'.js"></script>').join('')+'</body></html>';
const baseCall=(id,estado)=>({pbx_call_id:id,fecha:'2026-10-25 10:30:00',
  tipo:'Entrante',estado,estado_cierre:'Atendida',numero:'7777885173',
  finalizada:true,segundos:42,tiene_grabacion:true,requiere_resultado:false,
  destino_transferencia:estado==='Transferida'?'150':''});
const records=[baseCall('normal-1','Atendida'),baseCall('transfer-1','Transferida'),
  baseCall('normal-2','Atendida')];
let checks=0;
function ok(value,msg){assert.ok(value,msg);checks++;}
async function run() {
const browser=await chromium.launch({headless:true,args:['--autoplay-policy=no-user-gesture-required']});
try{
 const context=await browser.newContext();
 const pendingDirect=[],pendingTransfer=[],errors=[];
 let historyCount=0;
 await context.route('**/*',async route=>{
  const u=new URL(route.request().url());
  if(u.hostname!=='reception.test')return route.fulfill({status:404,body:''});
  if(u.pathname==='/'||u.pathname==='/index.html')
   return route.fulfill({contentType:'text/html',body:wrapped});
  if(u.pathname.startsWith('/public/javascript/')){
   const name=path.basename(u.pathname);
   return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(path.join(root,'public/javascript',name))});
  }
  if(u.pathname.includes('historial_recepcion_marketing.php')){
   historyCount++;
   return route.fulfill({json:{ok:true,recepcion:{configurada:true,llamadas:records,
    total:3,atendidas:2,perdidas:0,salientes:0,transferidas:1,sin_contacto:0}}});
  }
  if(u.searchParams.get('verificar')==='1'&&u.pathname.includes('grabacion_recepcion_marketing.php')){
   pendingDirect.push(route);return;
  }
  if(u.searchParams.get('verificar')==='1'&&u.pathname.includes('grabacion_transferida_pbx.php')){
   pendingTransfer.push(route);return;
  }
  if(u.pathname.includes('grabacion_')){
   return route.fulfill({status:206,contentType:'audio/mpeg',body:Buffer.from([0xff,0xfb,0x90,0x64])});
  }
  return route.fulfill({contentType:'text/html',body:''});
 });
 const page=await context.newPage();
 page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(()=>{
  window.__refreshHistory=null;
  const original=setInterval.bind(window);
  window.setInterval=(fn,ms)=>{
   if(ms===45000){window.__refreshHistory=fn;return 0;}
   return original(fn,ms);
  };
 });
 await page.goto('https://reception.test/');
 await page.locator('[data-marketing-reception-rows] .marketing-reception-record-check').first().waitFor();
 const content=await page.content();
 for(const message of [
  'Telefonía integrada en el CRM. Puedes cambiar de módulo',
  'Extensión 100 conectada. Puedes recibir y transferir',
  'El estado lo confirma la centralita',
  'Consulta tus llamadas entrantes y salientes desde esta extensión'
 ])ok(!content.includes(message),'texto repetido eliminado: '+message);

 const direct=page.locator('[data-sales-recording-toggle]').first();
 const directRow=page.locator('[data-sales-recording-row]').first();
 await direct.click();
 ok(await directRow.isVisible(),'fila individual visible antes del servidor');
 ok((await directRow.locator('[aria-busy="true"]').count())===1,'indicador visible mientras espera');
 ok(pendingDirect.length===1,'única petición de validación individual');
 ok((await directRow.locator('audio').count())===0,'no inventa audio sin validar');
 await direct.click();
 ok(!(await directRow.isVisible()),'cerrar durante la verificación no espera el servidor');
 await pendingDirect.shift().fulfill({json:{ok:true,disponible:true}});
 await page.waitForFunction(()=>document.querySelector('[data-sales-recording-toggle]').dataset.recordingVerified==='1');
 ok(!(await directRow.isVisible()),'respuesta tardía no reabre fila cerrada');
 await direct.click();
 ok(await directRow.isVisible(),'reabrir sin nuevo viaje al servidor');
 ok((await directRow.locator('audio').count())===1,'usa el reproductor compartido');
 ok(pendingDirect.length===0,'no repite consulta validada en la misma fila');

 const transfer=page.locator('[data-marketing-pbx-audio]').first();
 const transferRow=page.locator('[data-marketing-pbx-audios]').first();
 await transfer.click();
 ok(await transferRow.isVisible(),'transferencia abre instantáneamente');
 ok(pendingTransfer.length===1,'verificación segura por transferencia');
 ok((await transferRow.locator('audio').count())===0,'sin audio antes de autorización');
 await pendingTransfer.shift().fulfill({json:{ok:true,disponible:true,segmentos:2,
    segmentos_info:[{indice:0,duracion:13},{indice:1,duracion:25}]}});
 await page.waitForFunction(()=>document.querySelector('[data-marketing-pbx-audios]').dataset.recordingLoaded==='1');
 ok((await transferRow.locator('audio').count())===2,'dos segmentos autorizados en reproductor');
 await transfer.click();await transfer.click();
 ok((await transferRow.locator('audio').count())===2,'reabre segmentos conservados');
 ok(pendingTransfer.length===0,'reabrir no repite verificación');
 const previous=historyCount;
 await page.evaluate(()=>window.__refreshHistory());
 await page.waitForTimeout(80);
 ok(historyCount===previous,'autoactualización respeta panel abierto');

 const second=page.locator('[data-sales-recording-toggle]').nth(1);
 const secondRow=page.locator('[data-sales-recording-row]').nth(1);
 await second.click();
 ok(await secondRow.isVisible(),'segundo audio también abre sin espera');
 ok(pendingDirect.length===1,'segunda llamada identificada separadamente');
 await pendingDirect.shift().fulfill({json:{ok:true,disponible:false}});
 await secondRow.locator('.linkage-incoming-player-error').waitFor();
 ok((await secondRow.locator('audio').count())===0,'no fabrica audio ante negativa');
 await second.click();await second.click();
 ok(pendingDirect.length===1,'permite reintento desde fila');
 await pendingDirect.shift().fulfill({json:{ok:true,disponible:true}});
 await secondRow.locator('audio').waitFor({state:'attached'});
 ok((await secondRow.locator('audio').count())===1,'reintento exitoso conserva llamada');
 ok(errors.length===0,'sin errores de JavaScript: '+errors.join('; '));
 await context.close();
 console.log('PASS recepción compacta y grabaciones: '+checks+' verificaciones (audio simulado)');
}finally{await browser.close();}
}
run().catch(error=>{console.error(error);process.exitCode=1;});
