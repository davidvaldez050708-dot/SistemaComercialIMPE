const fs=require('fs'),cp=require('child_process'),assert=require('assert/strict'),{chromium}=require('playwright');
const read=require('./helpers/analista_resultado_ui_source.cjs');
const view=cp.execFileSync(process.env.PHP_BIN||'php',['tests/fixtures/analista_saliente_vista.php'],{encoding:'utf8'});
const noAnswerView=cp.execFileSync(process.env.PHP_BIN||'php',['tests/fixtures/analista_saliente_vista.php','sin-respuesta'],{encoding:'utf8'});
const notEndedView=cp.execFileSync(process.env.PHP_BIN||'php',['tests/fixtures/analista_saliente_vista.php','sin-cierre'],{encoding:'utf8'});
assert.match(noAnswerView,/data-call-id="out-fixture-001"/,'Saliente finalizada sin respuesta debe ofrecer registro');
assert.match(noAnswerView,/data-answer="0"/,'No inventar conversación humana');
assert.match(noAnswerView,/Registrar resultado/);
assert.doesNotMatch(notEndedView,/data-call-id="out-fixture-001"/,'Sin final PBX no habilitar guardado definitivo');

const dir=process.env.BOOTSTRAP_DIR||'../impe-denue-estabilidad/tmp/denue-bootstrap';
const css=read(dir+'/bootstrap.min.css'),bs=read(dir+'/bootstrap.bundle.min.js');
const formHtml='<!doctype html><meta charset="utf-8"><style>'+css+'</style>'+view+
 '<a id="otherModule" href="/other">Otro módulo</a><script>'+bs+'</script><script>window.IMPE_CURRENT_USER_ID=1;</script><script>'+read('public/javascript/telefonia_atencion.js')+'</script><script>'+read('public/javascript/seguimiento_entrantes_analista.js')+'</script>';
const formNoAnswerHtml=formHtml.replace(view,noAnswerView).replaceAll('out-fixture-001','out-fixture-004');
const overlay=read('public/javascript/telefonia_analista_resultado_superpuesto.js');
const journal=read('public/javascript/telefonia_analista_historial_pasivo.js');
const html='<!doctype html><meta charset="utf-8"><style>'+css+'\n'+read('public/css/telefonia_persistente.css')+'</style><iframe id="impeCrmFrame" src="/empty" style="width:1300px;height:1000px"></iframe><script>'+bs+'</script><script>'+`
window.state=null;window.formOpen=false;window.commands=[];
window.IMPE_PHONE_SHELL_CONFIG={userId:1,analystIncomingResults:true,incomingResultsUrl:'http://salientes.test/index.php?controller=telefonia&action=misLlamadas&vista=recibidas'};
window.IMPE_TELEPHONY_NOTIFY=()=>{};
window.IMPE_TELEPHONY_SHELL={getCallState:()=>state,isResultFormOpen:()=>formOpen,beginCallResult:()=>{formOpen=true;return true;},endCallResult:()=>{formOpen=false;},dismissFinishedCall:()=>{formOpen=false;},getPhoneStatus:()=>({pending:window.IMPE_ANALYST_OUTGOING_RESULT?.hasPending()})};
window.publish=s=>{state=s;localStorage.setItem('impe:telephony:state:1',JSON.stringify(s));dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:1'}));};
`+'</script><script>'+overlay+'</script><script>'+journal+'</script>';
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{channel:'chrome'})});try{
 const page=await browser.newPage({viewport:{width:1440,height:1200}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 let posted=null,mode='failure',stateCalls=0,providerRef='out-fixture-001',posts=0;const savedRecords={};
 await page.route('http://salientes.test/**',async r=>{
  const u=new URL(r.request().url());
  if(u.pathname.endsWith('zadarma_estado_llamada.php')){stateCalls++;assert.match(u.searchParams.get('after_id'),/^(500|600|700|800)$/);if(stateCalls<=2)return r.fulfill({json:{ok:true,call:null}});return r.fulfill({json:{ok:true,call:{pbx_call_id:providerRef,status:'completed'}}});}
  if(u.searchParams.get('action')==='estadoResultadoSaliente')return r.fulfill({json:{ok:true,llamada:{pbx_call_id:u.searchParams.get('pbx_call_id'),clasificacion:savedRecords[u.searchParams.get('pbx_call_id')]||'PENDIENTE',seguimiento_id:10,estado_pbx:'ended',numero:'5551234567',duracion:7,contestada:!u.searchParams.get('pbx_call_id').endsWith('004')}}});
  if(u.pathname.endsWith('borrador_llamada.php'))return r.fulfill({json:{ok:true,borrador:null}});
  if(u.pathname.endsWith('municipios_llamada.php'))return r.fulfill({json:{ok:true,municipios:[{id:11,nombre:'Cuernavaca'}]}});
  if(u.searchParams.get('action')==='buscarEntrantesSeguimientos')return r.fulfill({json:{ok:true,resultados:[{id:10,nombre_entidad:'Institución de prueba',estado_nombre:'Morelos'}]}});
  if(u.searchParams.get('action')==='registrarLlamadaEntrante'){
   posted=r.request().postData();posts++;
   if(mode==='lost-response'){savedRecords['out-fixture-003']='VINCULADA';return r.fulfill({status:503,json:{ok:false,mensaje:'Respuesta perdida después de guardar'}});}
   return mode==='failure'?r.fulfill({status:503,json:{ok:false,mensaje:'Error de prueba; conserva la captura'}}):r.fulfill({json:{ok:true,resultado:{seguimiento_id:10}}});
  }
  return r.fulfill({contentType:'text/html',body:u.pathname==='/empty'?'Inicio':u.searchParams.has('saliente_ref')?(u.searchParams.get('saliente_ref')==='out-fixture-004'?formNoAnswerHtml:formHtml.replaceAll('out-fixture-001',u.searchParams.get('saliente_ref'))):u.searchParams.get('action')==='detalle'?'Expediente guardado':html});
 });
 await page.goto('http://salientes.test/');
 assert.equal(await page.locator('[data-analyst-outgoing-pending]').isVisible(),false,'El botón oculto no bloquea contenido con el CSS real del teléfono');
 const active={userId:1,updatedAt:Date.now(),active:true,phase:'in-progress',direction:'outgoing',context:{type:'DIALER'},callToken:'manual-prueba-001',destination:'+525551234567',requestedAt:1793793600,afterEventId:500,pbxCallId:'out-fixture-001'};
 await page.evaluate(s=>publish(s),active);
 assert.equal(await page.evaluate(()=>IMPE_ANALYST_OUTGOING_RESULT.hasPending()),false);
 await page.evaluate(s=>publish({...s,active:false,phase:'finished',finalMetadata:{pbx_call_id:s.pbxCallId}}),active);
 await page.locator('[data-analyst-result-overlay]').waitFor({state:'visible'});
 assert.equal(await page.evaluate(()=>formOpen),true,'Reservar el registro de inmediato sin mostrar el resumen de fin');
 assert.equal(await page.locator('[data-analyst-outgoing-pending]').isVisible(),false,'No exigir un botón mientras llega el webhook');
 await page.waitForFunction(()=>document.querySelector('[data-analyst-result-overlay] iframe')?.contentWindow.location.search.includes('saliente_ref='));
 const frame=page.frames().find(f=>f.url().includes('saliente_ref='));
 assert.ok(frame.url().includes('controller=seguimientoVinculacion'));
 await frame.locator('#modalLlamadaEntranteAnalista.show').waitFor();
 assert.equal(await frame.locator('[name=pbx_call_id]').inputValue(),'out-fixture-001');
 assert.equal(await frame.locator('[name=sdk_session_id]').inputValue(),'','No fingir una llamada entrante SDK');
 await frame.waitForFunction(()=>!document.querySelector('[data-incoming-submit]').disabled);
 assert.match(await frame.locator('[data-incoming-duration]').textContent(),/00:07/);
 assert.equal(await frame.locator('[data-bs-dismiss="modal"]').first().isVisible(),false,'Sin X ni Cancelar para un resultado obligatorio');
 assert.equal(await page.evaluate(()=>IMPE_ANALYST_RESULT_OVERLAY.dismiss()),false,'La capa exterior tampoco permite abandonar el registro');
 // El mismo formulario permite la selección de expediente o el alta territorial.
 await frame.locator('[data-incoming-lookup]').fill('Institución');
 await frame.locator('[name=seguimiento_id] option[value="10"]').waitFor({state:'attached'});
 await frame.locator('[name=seguimiento_id]').selectOption('10');
 assert.equal(await frame.locator('[name=seguimiento_id]').inputValue(),'10');
 await frame.locator('[name=decision]').selectOption('nueva');
 await frame.locator('[name=nombre_entidad]').fill('Institución de prueba');
 await frame.locator('[name=estado_id]').selectOption('1');
 await frame.locator('[name=municipio_id] option[value="11"]').waitFor({state:'attached'});
 await frame.locator('[name=municipio_id]').selectOption('11');
 await frame.locator('[name=resultado]').selectOption('SOLICITO_INFORMACION');
 await frame.locator('[name=persona_atendio]').fill('Persona de prueba');
 await frame.locator('[name=observacion]').fill('Notas capturadas en el formulario compartido');
 await frame.locator('[data-incoming-submit]').click();await frame.locator('[data-incoming-submit]').waitFor({state:'visible'});
 await page.waitForTimeout(200);
 assert.ok(posted.includes('out-fixture-001'));
 assert.equal(await page.evaluate(()=>IMPE_ANALYST_OUTGOING_RESULT.hasPending()),true,'Error de guardado conserva obligación');
 await frame.evaluate(()=>bootstrap.Modal.getInstance(document.getElementById('modalLlamadaEntranteAnalista')).hide());
 assert.equal(await frame.locator('#modalLlamadaEntranteAnalista.show').count(),1,'No cerrar sin guardar');
 await frame.locator('#otherModule').evaluate(e=>e.click());assert.ok(frame.url().includes('saliente_ref='),'No salir a otro módulo');
 assert.equal(await frame.locator('[name=observacion]').inputValue(),'Notas capturadas en el formulario compartido');
 // Otra referencia no libera este resultado.
 await page.evaluate(()=>dispatchEvent(new MessageEvent('message',{origin:location.origin,source:document.querySelector('#impeCrmFrame').contentWindow,data:{type:'IMPE_ANALYST_RESULT_SAVED',callId:'otra-llamada'}})));
 assert.equal(await page.evaluate(()=>IMPE_ANALYST_OUTGOING_RESULT.hasPending()),true);
 await page.evaluate(()=>dispatchEvent(new MessageEvent('message',{origin:location.origin,source:document.querySelector('#impeCrmFrame').contentWindow,data:{type:'IMPE_ANALYST_RESULT_SAVED',callId:'out-fixture-001'}})));
 assert.equal(await page.evaluate(()=>IMPE_ANALYST_OUTGOING_RESULT.hasPending()),true,'La pantalla de origen no confirma el guardado de la capa de resultado');
 mode='success';await frame.locator('[data-incoming-submit]').click();
 await page.waitForFunction(()=>!IMPE_ANALYST_OUTGOING_RESULT.hasPending());
 assert.equal(await page.evaluate(()=>sessionStorage.getItem('impe:analista:saliente-pendiente:1')),null);
 assert.equal(await page.locator('[data-analyst-result-overlay]').count(),0,'El formulario desaparece tras guardar');
 await page.locator('[data-analyst-result-toasts] .system-toast').first().waitFor({state:'visible'});
 assert.match(await page.locator('[data-analyst-result-toasts]').textContent(),/registrado correctamente/);
 assert.equal(await page.locator('#impeCrmFrame').evaluate(e=>new URL(e.contentWindow.location.href).pathname),'/empty',
  'La pantalla de origen conserva su ubicación y no redirige a Recibidas');
 assert.equal(stateCalls,3,'Abrir automáticamente después de dos respuestas todavía sin confirmación');
 // Una segunda marcación al mismo destino no reutiliza ni el resultado ni el PBX anteriores.
 const second={...active,callToken:'manual-prueba-002',pbxCallId:'out-fixture-002',afterEventId:600};
 await page.evaluate(s=>publish(s),second);
 await page.evaluate(s=>publish({...s,active:false,phase:'finished',finalMetadata:{pbx_call_id:s.pbxCallId}}),second);
 await page.waitForFunction(()=>IMPE_ANALYST_OUTGOING_RESULT.hasPending());
 await page.locator('[data-analyst-result-overlay]').waitFor({state:'visible'});
 assert.equal(JSON.parse(await page.evaluate(()=>sessionStorage.getItem('impe:analista:saliente-pendiente:1'))).ref,'out-fixture-002');
 providerRef='out-fixture-002';
 await page.waitForFunction(()=>document.querySelector('[data-analyst-result-overlay] iframe')?.contentWindow.location.search.includes('out-fixture-002'));
 const secondForm=page.frames().find(f=>f.url().includes('out-fixture-002'));
 await secondForm.locator('#modalLlamadaEntranteAnalista.show').waitFor();
 assert.equal(await page.locator('#impeCrmFrame').evaluate(e=>new URL(e.contentWindow.location.href).pathname),'/empty');
 assert.equal(await secondForm.locator('[name=pbx_call_id]').inputValue(),'out-fixture-002');
 assert.equal(await secondForm.locator('[name=observacion]').inputValue(),'','No arrastrar notas anteriores');
 await secondForm.waitForFunction(()=>!document.querySelector('[data-incoming-submit]').disabled);
 await secondForm.locator('[name=decision]').selectOption('sin_identificar');
 await secondForm.locator('[data-incoming-submit]').click();
 await page.waitForFunction(()=>!IMPE_ANALYST_OUTGOING_RESULT.hasPending());
 assert.ok(stateCalls>=5,'El reintento automático recupera exclusivamente la nueva llamada');
 // El servidor guardó pero se perdió el OK: comprobar la referencia sin repetir el POST.
 providerRef='out-fixture-003';mode='lost-response';
 const third={...active,callToken:'manual-prueba-003',pbxCallId:providerRef,afterEventId:700};
 await page.evaluate(s=>publish(s),third);
 await page.evaluate(s=>publish({...s,active:false,phase:'finished',finalMetadata:{pbx_call_id:s.pbxCallId}}),third);
 await page.waitForFunction(()=>document.querySelector('[data-analyst-result-overlay] iframe')?.contentWindow.location.search.includes('out-fixture-003'));
 const thirdForm=page.frames().find(f=>f.url().includes('out-fixture-003'));
 await thirdForm.locator('#modalLlamadaEntranteAnalista.show').waitFor();
 await thirdForm.waitForFunction(()=>!document.querySelector('[data-incoming-submit]').disabled);
 await thirdForm.locator('[name=decision]').selectOption('sin_identificar');
 const before=posts;await thirdForm.locator('[data-incoming-submit]').click();
 await page.waitForFunction(()=>!IMPE_ANALYST_OUTGOING_RESULT.hasPending());
 assert.equal(posts,before+1,'Recuperar un OK perdido no genera otro registro');
 assert.ok(stateCalls>=6);
 // La salida que terminó sin conversación necesita el mismo formulario.
 providerRef='out-fixture-004'; mode='success';
 const fourth={...active,callToken:'manual-prueba-004',pbxCallId:providerRef,afterEventId:800};
 await page.evaluate(s=>publish(s),fourth);
 await page.evaluate(s=>publish({...s,active:false,phase:'finished',finalMetadata:{pbx_call_id:s.pbxCallId}}),fourth);
 await page.waitForFunction(()=>document.querySelector('[data-analyst-result-overlay] iframe')?.contentWindow.location.search.includes('out-fixture-004'));
 const fourthForm=page.frames().find(f=>f.url().includes('out-fixture-004'));
 await fourthForm.locator('#modalLlamadaEntranteAnalista.show').waitFor();
 await fourthForm.waitForFunction(()=>!document.querySelector('[data-incoming-submit]').disabled);
 assert.equal(await fourthForm.locator('[name=decision]').inputValue(),'sin_identificar',
  'No respuesta no equivale a contacto institucional');
 assert.equal(await fourthForm.locator('[name=resultado]').isVisible(),false,
  'No contabilizar conversación ni datos verificados');
 await fourthForm.locator('[name=observacion]').fill('Intento sin respuesta, 00:00');
 await fourthForm.locator('[data-incoming-submit]').click();
 await page.waitForFunction(()=>!IMPE_ANALYST_OUTGOING_RESULT.hasPending());

 assert.deepEqual(errors,[]);
 // Shell PHP real: el resumen no puede quedar encima mientras llega el webhook.
 const shell=cp.execFileSync(process.env.PHP_BIN||'php',['tests/fixtures/telefonia_flujo_shell.php','analista'],{encoding:'utf8'});
 const real=await browser.newPage({viewport:{width:1366,height:900}});let confirmed=false,lookups=0,earlyPosts=0;
 real.on('pageerror',e=>errors.push(e.message));
 await real.route('http://llamadas.test/**',async r=>{
  const u=new URL(r.request().url());
  if(u.pathname.startsWith('/public/javascript/'))return r.fulfill({contentType:'application/javascript',body:read(u.pathname.slice(1))});
  if(u.pathname.startsWith('/public/css/'))return r.fulfill({contentType:'text/css',body:read(u.pathname.slice(1))});
  if(u.pathname.endsWith('zadarma_estado_llamada.php')){lookups++;return r.fulfill({json:{ok:true,call:confirmed?{pbx_call_id:'out-real-shell-001',status:'completed'}:null}});}
  if(u.searchParams.get('action')==='estadoResultadoSaliente')return r.fulfill({json:{ok:true,llamada:{pbx_call_id:'out-real-shell-001',clasificacion:'PENDIENTE',estado_pbx:'ended',numero:'5551234567',duracion:7,contestada:true}}});
  if(u.searchParams.get('action')==='registrarLlamadaEntrante'){earlyPosts++;return r.fulfill({status:503,json:{ok:false,mensaje:'No debe guardar antes de confirmar'}});}
  if(u.pathname==='/')return r.fulfill({contentType:'text/html',body:shell});
  if(u.searchParams.has('saliente_ref')){
   let body=formHtml.replaceAll('out-fixture-001','out-real-shell-001');
   if(!confirmed)body=body.replace(/<option\s+value="1"[^>]*>Morelos<\/option>/g,'');
   return r.fulfill({contentType:'text/html',body});
  }
  return r.fulfill({contentType:'text/html',body:'<!doctype html><title>Motor y módulo de prueba</title>'});
 });
 await real.goto('http://llamadas.test/');
 await real.waitForFunction(()=>window.IMPE_ANALYST_OUTGOING_RESULT&&window.IMPE_TELEPHONY_SHELL);
 const realState={...active,userId:8,callToken:'manual-real-shell-001',pbxCallId:'out-real-shell-001'};
 const publishReal=async s=>real.evaluate(s=>{s.updatedAt=Date.now();localStorage.setItem('impe:telephony:state:8',JSON.stringify(s));dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:8',newValue:JSON.stringify(s)}));},s);
 await publishReal(realState);
 await real.waitForFunction(()=>IMPE_TELEPHONY_SHELL.getCallState()?.active);
 await publishReal({...realState,active:false,phase:'finished'});
 await real.frameLocator('[data-analyst-result-overlay] iframe').locator('#modalLlamadaEntranteAnalista.show').waitFor();
 const earlyForm=real.frames().find(f=>f.url().includes('saliente_ref='));
 assert.equal(await earlyForm.locator('[data-incoming-submit]').isDisabled(),true,'Captura inmediata con guardado protegido mientras falta confirmación');
 await earlyForm.locator('[name=observacion]').fill('Captura antes de recibir el webhook final');
 await earlyForm.locator('[name=decision]').selectOption('sin_identificar');
 await earlyForm.locator('[data-incoming-form]').evaluate(e=>e.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
 assert.equal(earlyPosts,0,'La captura provisional no ejecuta un POST de resultado');
 await real.evaluate(s=>{s.updatedAt=Date.now();localStorage.setItem('impe:telephony:state:8',JSON.stringify(s));dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:8',newValue:JSON.stringify(s)}));},{...realState,direction:'incoming',phase:'incoming-ringing',callToken:'incoming-priority-test',context:{type:'SDK_INCOMING'}});
 await real.locator('[data-analyst-result-overlay]').waitFor({state:'hidden'});
 assert.equal(await real.evaluate(()=>IMPE_ANALYST_OUTGOING_RESULT.hasPending()),true,'Una entrante no descarta el pendiente anterior');
 await publishReal({...realState,active:false,phase:'finished'});
 await real.locator('[data-analyst-result-overlay]').waitFor({state:'visible'});
 await real.waitForTimeout(400);
 assert.equal(await real.locator('[data-incoming-panel]').isVisible(),false,'El shell real oculta el resumen al reservar el resultado');
 assert.equal(await real.locator('[data-analyst-outgoing-pending]').isVisible(),false);
 confirmed=true;
 await real.frameLocator('[data-analyst-result-overlay] iframe').locator('#modalLlamadaEntranteAnalista.show').waitFor();
 await earlyForm.waitForFunction(()=>!document.querySelector('[data-incoming-submit]').disabled);
 assert.equal(await earlyForm.locator('[name=observacion]').inputValue(),'Captura antes de recibir el webhook final','Confirmar sin recargar ni borrar la captura');
 assert.equal(await earlyForm.locator('[name=estado_id] option[value="1"]').count(),1,'Recuperar el catálogo territorial si la referencia no existía al abrir');
 assert.equal(await real.locator('[data-analyst-outgoing-preparing]').count(),0,'No mostrar una ventana intermedia de espera');
 assert.equal(await real.locator('[data-analyst-result-overlay]').count(),1);
 assert.notEqual(await real.locator('#impeCrmFrame').evaluate(e=>new URL(e.contentWindow.location.href).searchParams.get('saliente_ref')),'out-real-shell-001',
  'El shell no sustituye la vista actual mientras captura el resultado');
 assert.equal(await real.locator('[data-incoming-panel]').isVisible(),false,'El resumen tampoco tapa el formulario cargado');
 assert.ok(lookups>=2,'Esperar y reintentar sin clic manual');
 assert.deepEqual(errors,[]);
 // Recuperar el mismo formulario tras recargar, sin un botón flotante.
 const restored=await browser.newPage({viewport:{width:1280,height:800}});
 restored.on('pageerror',e=>errors.push(e.message));
 await restored.addInitScript(()=>sessionStorage.setItem('impe:analista:saliente-pendiente:8',JSON.stringify({
   user:8,token:'manual-recuperacion-001',ref:'',number:'+525551234567',
   requestedAt:Math.floor(Date.now()/1000),afterEventId:500,at:Date.now()-20000
 })));
 await restored.route('http://llamadas.test/**',async r=>{
   const u=new URL(r.request().url());
   if(u.pathname.startsWith('/public/javascript/'))return r.fulfill({contentType:'application/javascript',body:read(u.pathname.slice(1))});
   if(u.pathname.startsWith('/public/css/'))return r.fulfill({contentType:'text/css',body:read(u.pathname.slice(1))});
   if(u.pathname.endsWith('zadarma_estado_llamada.php'))return r.fulfill({json:{ok:true,call:null}});
   if(u.pathname==='/')return r.fulfill({contentType:'text/html',body:shell});
   if(u.searchParams.has('saliente_ref'))return r.fulfill({contentType:'text/html',body:formHtml});
   return r.fulfill({contentType:'text/html',body:'<!doctype html><title>Motor o módulo</title>'});
 });
 await restored.goto('http://llamadas.test/');
 await restored.frameLocator('[data-analyst-result-overlay] iframe').locator('#modalLlamadaEntranteAnalista.show').waitFor();
 assert.equal(await restored.locator('[data-analyst-outgoing-pending]').count(),0,'Sin botón flotante');
 assert.equal(await restored.locator('[data-analyst-outgoing-preparing]').isVisible(),false,'No repetir modal al recargar');
 assert.equal(await restored.evaluate(()=>IMPE_ANALYST_OUTGOING_RESULT.hasPending()),true,'Pendiente conservado');
 assert.deepEqual(errors,[]);
 console.log('PASS formulario y shell reales: apertura inmediata, webhook tardío sin clic, resumen oculto, ID exacto, alta, notas, recuperación y cierre obligatorio');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
