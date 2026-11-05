'use strict';
const fs=require('fs'),assert=require('assert/strict'),cp=require('child_process'),{chromium}=require('playwright');
const read=require('./helpers/analista_resultado_ui_source.cjs');
const fixture=cp.execFileSync(process.env.PHP_BIN||'php',['tests/fixtures/analista_entrante_transferida_vista.php'],{encoding:'utf8'});
const dir=process.env.BOOTSTRAP_DIR||'tmp/bootstrap';
const css=read(dir+'/bootstrap.min.css'),bs=read(dir+'/bootstrap.bundle.min.js');
const form='<html><meta charset="utf-8"><style>'+css+'</style>'+fixture+
 '<script>'+bs+'</script><script>window.IMPE_CURRENT_USER_ID=8;</script><script>'+
 read('public/javascript/seguimiento_entrantes_analista.js')+'</script>';
const controls=['state','title','eyebrow','number','timer','compact-timer','notice',
 'close-finished','answer','decline','mute','hangup','answer-compact',
 'decline-compact','mute-compact','hangup-compact'];
const shell='<html><meta charset="utf-8"><div data-incoming-panel class="impe-incoming-panel" hidden>'+
 '<div data-incoming-state></div><div data-incoming-title></div><div data-incoming-eyebrow></div>'+
 '<div><span data-incoming-number></span></div><div data-incoming-timer></div>'+
 '<div data-incoming-compact-timer></div><p data-incoming-notice></p>'+
 '<div class="persistent-phone-actions">'+controls.filter(x=>!['state','title','eyebrow','number','timer','compact-timer','notice'].includes(x)).map(x=>'<button data-incoming-'+x+'></button>').join('')+'</div>'+
 '<div class="persistent-phone-compact-actions"></div></div>'+
 '<iframe id="impeCrmFrame" src="/origin"></iframe>'+
 '<script>window.IMPE_PHONE_SHELL_CONFIG={userId:8,analystIncomingResults:true,incomingResultsUrl:"http://transferidas.test/index.php?controller=telefonia&action=misLlamadas&vista=recibidas"};'+
 'window.IMPE_ANALYST_WEBRTC_JOURNAL={enabled:true,csrf:"test-csrf",endpoint:"http://transferidas.test/index.php?controller=seguimientoVinculacion&action=eventoEntranteWebrtc"};'+
 'window.IMPE_TELEPHONY_SHELL={isResultFormOpen:()=>false,dismissFinishedCall:()=>true};</script>'+
 '<script>'+read('public/javascript/telefonia_analista_resultado_superpuesto.js')+'</script>'+
 '<script>'+read('public/javascript/telefonia_analista_historial_pasivo.js')+'</script>';
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{channel:'chrome'})});try{
 const page=await browser.newPage({viewport:{width:1366,height:900}});
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 let saved=0, events=0;
 await page.route('http://transferidas.test/**',async route=>{
  const u=new URL(route.request().url());
  if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:shell});
  if(u.pathname==='/origin')return route.fulfill({contentType:'text/html',body:'<h1 id="origin">Territorios · vista original</h1>'});
  if(u.searchParams.get('action')==='eventoEntranteWebrtc'){events++;return route.fulfill({json:{ok:true,interna:false}});}
  if(u.searchParams.get('action')==='registrarLlamadaEntrante'){
   saved++;const body=route.request().postData();
   assert.match(body,/sdk_session_id/);
   return route.fulfill({json:{ok:true,resultado:{estado:'SIN_IDENTIFICAR',seguimiento_id:0}}});
  }
  if(u.searchParams.get('action')==='contextoTransferencia')return route.fulfill({json:{ok:true,contexto:null}});
  if(u.pathname.endsWith('borrador_llamada.php'))return route.fulfill({json:{ok:true,borrador:null}});
  if(u.searchParams.has('sdk_session_id'))return route.fulfill({contentType:'text/html',body:form});
  return route.fulfill({contentType:'text/html',body:'<!doctype html>Otro contenido'});
 });
 await page.goto('http://transferidas.test/');
 await page.frameLocator('#impeCrmFrame').locator('#origin').waitFor();
 const callId='sdk-transfer-abc123xyz456';
 const active={userId:8,direction:'incoming',active:true,phase:'incoming-ringing',status:'incoming-ringing',
  callToken:callId,pbxCallId:'pbx-transfer-00001',destination:'+525551234567',
  context:{type:'SDK_INCOMING',transferReference:'pbx-transfer-00001',sdkSessionId:callId}};
 const publish=async state=>page.evaluate(state=>{
  state.updatedAt=Date.now();
  const data=JSON.stringify(state);
  localStorage.setItem('impe:telephony:state:8',data);
  dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:8',newValue:data}));
 },state);
 await publish(active);
 await publish({...active,phase:'in-progress',status:'in-progress',answeredAtMs:Date.now()-2000,duration:10});
 await publish({...active,active:false,phase:'incoming-finished',status:'completed',duration:20,answeredAtMs:Date.now()-20000});
 await page.locator('[data-incoming-register-finished]').waitFor({state:'visible'});
 assert.equal(await page.frameLocator('#impeCrmFrame').locator('#origin').count(),1);
 await page.locator('[data-incoming-register-finished]').click();
 const frame=page.frameLocator('[data-analyst-result-overlay] iframe');
 await frame.locator('#modalLlamadaEntranteAnalista.show').waitFor();
 assert.equal(await frame.locator('[name=sdk_session_id]').inputValue(),callId);
 assert.equal(await page.frameLocator('#impeCrmFrame').locator('#origin').count(),1);
 await frame.locator('[name=decision]').selectOption('sin_identificar');
 await frame.locator('[name=observacion]').fill('Transferida y atendida; registro sin cambiar de vista');
 await frame.locator('[data-incoming-submit]').click();
 await page.waitForFunction(()=>!document.querySelector('[data-analyst-result-overlay]'));
 assert.equal(saved,1,'Guardar una única clasificación');
 assert.equal(await page.frameLocator('#impeCrmFrame').locator('#origin').count(),1,
  'La transferencia vuelve a la vista de origen');
 assert.ok(events>=3,'Evento ringing, answered y ended registrado por el observador');
 assert.deepEqual(errors,[]);
 console.log('PASS entrante transferida: sesión SDK, formulario compartido, guardado único y vista de origen');
}finally{await browser.close()}})().catch(error=>{console.error(error);process.exitCode=1});
