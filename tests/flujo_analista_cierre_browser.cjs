// Auricular -> observador -> iframe -> confirmacion -> resultado. Sin PBX/BD reales.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..'),deps=path.resolve(root,'../browser-legacy-deps/node_modules');
const {chromium}=require(path.join(deps,'playwright'));
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const ref='out_'+'a'.repeat(32),token='sdk-prueba-saliente-12345678';
const template=read('tests/fixtures/errores_resultado_analista_form_20261125.php.txt');
const form=cp.execFileSync('C:/xampp/php/php.exe',[],{input:`<?php define('BASE_URL','http://flujo.test/');$_SESSION=['csrf_token'=>'csrf-prueba'];$esc=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');$estadosEntrantes=[['id'=>9,'nombre'=>'Territorio sintético']]; ?>${template}`,encoding:'utf8'});
const timers=`const nativeTimeout=window.setTimeout;window.setTimeout=(fn,ms,...a)=>nativeTimeout(fn,ms>=10000?300:ms>=1000?100:ms,...a);`;
const css=fs.readFileSync(path.join(deps,'bootstrap/dist/css/bootstrap.min.css'),'utf8');
const bootstrap=fs.readFileSync(path.join(deps,'bootstrap/dist/js/bootstrap.bundle.min.js'),'utf8');
const observer=read('public/javascript/telefonia_analista_historial_pasivo.js').split('/* Resultado de marcación libre del Analista.')[1];
const shell=`<!doctype html><meta charset="utf-8"><iframe id="impeCrmFrame" src="/crm"></iframe><script>${timers}
window.IMPE_PHONE_SHELL_CONFIG={analystIncomingResults:true,userId:30,incomingResultsUrl:'http://flujo.test/index.php?controller=seguimientoVinculacion&action=llamadasEntrantes'};
window.callState={active:false};window.owner=null;window.IMPE_TELEPHONY_SHELL={getCallState:()=>callState,isResultFormOpen:()=>!!owner,beginCallResult:(t,refs)=>{owner={t,refs};},dismissFinishedCall:r=>{if(owner?.refs.includes(r))owner=null;}};
</script><script>${read('public/javascript/telefonia_analista_resultado_superpuesto.js')}</script><script>/* Resultado de marcación libre del Analista.${observer}</script>`;
const draftStub=`window.IMPE_CALL_DRAFTS={localSave(u,ids,v){for(const id of ids)sessionStorage.setItem('draft:'+id,JSON.stringify(v));},async read(u,ids){for(const id of ids){const v=sessionStorage.getItem('draft:'+id);if(v)return JSON.parse(v);}return null;},async save(u,ids,v){this.localSave(u,ids,v);},async remove(){},resultValues:v=>v,unificarContacto(){}};`;
const child=`<!doctype html><meta charset="utf-8"><style>${css}</style><main data-linkage-incoming data-current-user="30" data-outgoing-result="1" data-save-url="/save">${form}</main><script>${timers}${draftStub}</script><script>${bootstrap}</script><script>${read('public/javascript/seguimiento_entrantes_analista.js')}</script>`;
(async()=>{const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});try{
 for(const scenario of ['respuesta_perdida','guardado_normal','formulario_no_carga','guardado_tarda']){
  const page=await browser.newPage({viewport:{width:1366,height:900}});page.setDefaultTimeout(10000);
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  let finalAvailable=false,metaAvailable=false,saved=false,stateGets=0,childGets=0,posts=0,formViews=0,uncertainGets=0;
  await page.route('http://flujo.test/**',async route=>{
   const req=route.request(),url=new URL(req.url()),isChild=req.frame()!==page.mainFrame();
   if(url.pathname==='/save'){
    assert.equal(req.method(),'POST');posts++;saved=true;
    if(scenario==='respuesta_perdida')return route.abort('failed');
    if(scenario==='guardado_tarda')return;
    return route.fulfill({json:{ok:true,resultado:{estado:'VINCULADA'}}});
   }
   if(url.pathname.endsWith('zadarma_estado_llamada.php')){
    stateGets++;
    if(scenario==='respuesta_perdida'&&stateGets===1)return; // petición colgada, el AbortController debe liberarla.
    return route.fulfill({json:{ok:true,call:finalAvailable?{pbx_call_id:ref,status:'completed'}:null}});
   }
   if(url.searchParams.get('action')==='estadoResultadoSaliente'){
    if(saved && scenario==='respuesta_perdida' && uncertainGets++===0)return route.fulfill({status:503,json:{ok:false,mensaje:'Comprobación temporalmente no disponible'}});
    if(isChild){childGets++;if(scenario==='respuesta_perdida'&&childGets===1)return;}
    if(isChild&&!metaAvailable)return route.fulfill({status:503,json:{ok:false,mensaje:'Evento tardío sintético'}});
    return route.fulfill({json:{ok:true,llamada:{pbx_call_id:ref,estado_pbx:'ended',clasificacion:saved?'VINCULADA':'PENDIENTE',contestada:true,duracion:12,numero:'5550000000'}}});
   }
   if(url.searchParams.get('action')==='llamadasEntrantes' && scenario==='formulario_no_carga' && formViews++===0)return route.fulfill({status:503,contentType:'text/html',body:'Formulario no disponible temporalmente'});
   return route.fulfill({contentType:'text/html',body:url.pathname==='/crm'?'<html><body>Vista de origen</body></html>':url.pathname==='/'?shell:child});
  });
  await page.goto('http://flujo.test/');
  await page.waitForFunction(()=>window.IMPE_ANALYST_OUTGOING_RESULT);
  // Colgado inmediato: no depender de que el sondeo alcance a ver la fase activa.
  await page.evaluate(({token})=>{const value={userId:30,active:false,direction:'outgoing',phase:'finished',callToken:token,context:{type:'DIALER'},requestedAt:Date.now()/1000,checkpointReady:true,afterEventId:50,destination:'5550000000',duration:12};localStorage.setItem('impe:telephony:state:30',JSON.stringify(value));dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:30'}));},{token});
  await page.locator('[data-analyst-result-overlay] iframe').waitFor();
  if(scenario==='formulario_no_carga'){
   await page.locator('[data-analyst-result-recovery]').waitFor({state:'visible'});
   assert.equal(await page.evaluate(()=>IMPE_ANALYST_OUTGOING_RESULT.hasPending()),true);
   await page.getByRole('button',{name:'Volver a cargar el formulario'}).click();
  }
  let frame=page.frameLocator('[data-analyst-result-overlay] iframe');
  await frame.locator('[name=decision]').selectOption('nueva');
  await frame.locator('[name=nombre_entidad]').fill('Captura que debe conservarse');
  await frame.locator('[name=correo_capturado]').fill('contacto@example.test');
  await frame.locator('[name=estado_id]').selectOption('9');
  await frame.locator('[name=resultado]').selectOption('CONTACTO_CORRECTO');
  assert.equal(await frame.locator('[data-incoming-submit]').isDisabled(),true,'no guardar sin confirmar cierre');
  finalAvailable=true;
  await page.waitForFunction(()=>!!document.querySelector('[data-analyst-result-overlay] iframe')?.contentWindow);
  await frame.getByText('Comprobar cierre de nuevo').waitFor({state:'visible'});
  await page.waitForTimeout(600);
  assert.equal(await frame.locator('[data-incoming-submit]').isDisabled(),true,'el envío de READY no sustituye el acuse del formulario');
  await page.evaluate(ref=>window.postMessage({type:'IMPE_ANALYST_RESULT_SAVED',callId:ref},location.origin),ref);
  assert.equal(await page.evaluate(()=>IMPE_ANALYST_OUTGOING_RESULT.hasPending()),true,'otro emisor no puede liberar el resultado');
  metaAvailable=true;
  await frame.locator('[data-incoming-submit]').waitFor({state:'visible'});
  await page.waitForFunction(()=>!document.querySelector('[data-analyst-result-overlay] iframe').contentDocument.querySelector('[data-incoming-submit]').disabled);
  assert.equal(await frame.locator('[name=nombre_entidad]').inputValue(),'Captura que debe conservarse');
  const firstIframe=await page.locator('[data-analyst-result-overlay] iframe').elementHandle();
  await firstIframe.evaluate(e=>e.contentWindow.location.reload());
  await page.waitForFunction(()=>{const f=document.querySelector('[data-analyst-result-overlay] iframe')?.contentDocument?.querySelector('[data-incoming-form]');return f?.elements.nombre_entidad.value==='Captura que debe conservarse'&&!f.querySelector('[data-incoming-submit]').disabled;});
  assert.equal(await page.locator('[data-analyst-result-overlay]').count(),1,'sin formularios duplicados al recargar');
  await frame.locator('[data-incoming-submit]').click();
  await frame.locator('[data-incoming-error]').waitFor({state:'visible'});
  assert.match(await frame.locator('[data-incoming-error]').innerText(),/confirmar teléfono y correo/);
  assert.equal(posts,0,'validación no crea registros');
  await frame.locator('[name=verificacion_telefono_confirmado]').check();
  await frame.locator('[name=verificacion_correo_confirmado]').check();
  await frame.locator('[data-incoming-submit]').click();
  await page.waitForFunction(()=>!IMPE_ANALYST_OUTGOING_RESULT.hasPending());
  await page.locator('[data-analyst-result-overlay]').waitFor({state:'detached'});
  assert.equal(posts,1,'una solicitud; no repetir POST tras perder respuesta');
  assert.equal(await page.evaluate(()=>!!owner),false,'libera el teléfono tras confirmar guardado');
  await page.reload();await page.waitForFunction(()=>window.IMPE_ANALYST_OUTGOING_RESULT);await page.waitForTimeout(600);
  assert.equal(await page.locator('[data-analyst-result-overlay]').count(),0,'un estado antiguo no vuelve a exigir el mismo resultado');
  assert.equal(await page.evaluate(()=>IMPE_ANALYST_OUTGOING_RESULT.hasPending()),false);
  assert.deepEqual(errors,[]);assert.ok(stateGets<15&&childGets<15,'reintentos acotados durante el escenario');
  console.log('PASS '+scenario+': evento tardío, petición colgada, acuse READY, recarga con borrador, validación, un POST y liberación.');
  await page.close();
 }
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});
