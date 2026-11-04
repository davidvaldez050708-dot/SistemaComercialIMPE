const fs=require('fs'),cp=require('child_process'),assert=require('assert/strict'),{chromium}=require('playwright');
const read=p=>fs.readFileSync(p,'utf8');
const view=cp.execFileSync(process.env.PHP_BIN||'php',['tests/fixtures/analista_saliente_vista.php'],{encoding:'utf8'});
const dir=process.env.BOOTSTRAP_DIR||'../impe-denue-estabilidad/tmp/denue-bootstrap';
const css=read(dir+'/bootstrap.min.css'),bs=read(dir+'/bootstrap.bundle.min.js');
const formHtml='<!doctype html><meta charset="utf-8"><style>'+css+'</style>'+view+
 '<a id="otherModule" href="/other">Otro módulo</a><script>'+bs+'</script><script>window.IMPE_CURRENT_USER_ID=1;</script><script>'+read('public/javascript/telefonia_atencion.js')+'</script><script>'+read('public/javascript/seguimiento_entrantes_analista.js')+'</script>';
const journal=read('public/javascript/telefonia_analista_historial_pasivo.js');
const html='<!doctype html><meta charset="utf-8"><style>'+css+'\n'+read('public/css/telefonia_persistente.css')+'</style><iframe id="impeCrmFrame" src="/empty" style="width:1300px;height:1000px"></iframe><script>'+bs+'</script><script>'+`
window.state=null;window.formOpen=false;window.commands=[];
window.IMPE_PHONE_SHELL_CONFIG={userId:1,analystIncomingResults:true,incomingResultsUrl:'http://salientes.test/index.php?controller=telefonia&action=misLlamadas&vista=recibidas'};
window.IMPE_TELEPHONY_NOTIFY=()=>{};
window.IMPE_TELEPHONY_SHELL={getCallState:()=>state,isResultFormOpen:()=>formOpen,beginCallResult:()=>{formOpen=true;return true;},endCallResult:()=>{formOpen=false;},dismissFinishedCall:()=>{formOpen=false;},getPhoneStatus:()=>({pending:window.IMPE_ANALYST_OUTGOING_RESULT?.hasPending()})};
window.publish=s=>{state=s;localStorage.setItem('impe:telephony:state:1',JSON.stringify(s));dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:1'}));};
`+'</script><script>'+journal+'</script>';
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{channel:'chrome'})});try{
 const page=await browser.newPage({viewport:{width:1440,height:1200}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 let posted=null,mode='failure',stateCalls=0,providerRef='out-fixture-001',posts=0;const savedRecords={};
 await page.route('http://salientes.test/**',async r=>{
  const u=new URL(r.request().url());
  if(u.pathname.endsWith('zadarma_estado_llamada.php')){stateCalls++;assert.match(u.searchParams.get('after_id'),/^(500|600|700)$/);if(stateCalls<=2)return r.fulfill({json:{ok:true,call:null}});return r.fulfill({json:{ok:true,call:{pbx_call_id:providerRef,status:'completed'}}});}
  if(u.searchParams.get('action')==='estadoResultadoSaliente')return r.fulfill({json:{ok:true,llamada:{pbx_call_id:u.searchParams.get('pbx_call_id'),clasificacion:savedRecords[u.searchParams.get('pbx_call_id')]||'PENDIENTE',seguimiento_id:10}}});
  if(u.pathname.endsWith('borrador_llamada.php'))return r.fulfill({json:{ok:true,borrador:null}});
  if(u.pathname.endsWith('municipios_llamada.php'))return r.fulfill({json:{ok:true,municipios:[{id:11,nombre:'Cuernavaca'}]}});
  if(u.searchParams.get('action')==='buscarEntrantesSeguimientos')return r.fulfill({json:{ok:true,resultados:[{id:10,nombre_entidad:'Institución de prueba',estado_nombre:'Morelos'}]}});
  if(u.searchParams.get('action')==='registrarLlamadaEntrante'){
   posted=r.request().postData();posts++;
   if(mode==='lost-response'){savedRecords['out-fixture-003']='VINCULADA';return r.fulfill({status:503,json:{ok:false,mensaje:'Respuesta perdida después de guardar'}});}
   return mode==='failure'?r.fulfill({status:503,json:{ok:false,mensaje:'Error de prueba; conserva la captura'}}):r.fulfill({json:{ok:true,resultado:{seguimiento_id:10}}});
  }
  return r.fulfill({contentType:'text/html',body:u.pathname==='/empty'?'Inicio':u.searchParams.has('saliente_ref')?formHtml.replaceAll('out-fixture-001',u.searchParams.get('saliente_ref')):u.searchParams.get('action')==='detalle'?'Expediente guardado':html});
 });
 await page.goto('http://salientes.test/');
 assert.equal(await page.locator('[data-analyst-outgoing-pending]').isVisible(),false,'El botón oculto no bloquea contenido con el CSS real del teléfono');
 const active={userId:1,updatedAt:Date.now(),active:true,phase:'in-progress',direction:'outgoing',context:{type:'DIALER'},callToken:'manual-prueba-001',destination:'+525551234567',requestedAt:1793793600,afterEventId:500,pbxCallId:'out-fixture-001'};
 await page.evaluate(s=>publish(s),active);
 assert.equal(await page.evaluate(()=>IMPE_ANALYST_OUTGOING_RESULT.hasPending()),false);
 await page.evaluate(s=>publish({...s,active:false,phase:'finished',finalMetadata:{pbx_call_id:s.pbxCallId}}),active);
 await page.locator('[data-analyst-outgoing-preparing]').waitFor({state:'visible'});
 assert.equal(await page.evaluate(()=>formOpen),true,'Reservar el registro de inmediato sin mostrar el resumen de fin');
 assert.equal(await page.locator('[data-analyst-outgoing-pending]').isVisible(),false,'No exigir un botón mientras llega el webhook');
 await page.waitForFunction(()=>document.querySelector('#impeCrmFrame').contentWindow.location.search.includes('saliente_ref='));
 const frame=page.frames().find(f=>f.url().includes('saliente_ref='));
 assert.ok(frame.url().includes('controller=seguimientoVinculacion'));
 await frame.locator('#modalLlamadaEntranteAnalista.show').waitFor();
 assert.equal(await frame.locator('[name=pbx_call_id]').inputValue(),'out-fixture-001');
 assert.equal(await frame.locator('[name=sdk_session_id]').inputValue(),'','No fingir una llamada entrante SDK');
 assert.match(await frame.locator('[data-incoming-duration]').textContent(),/00:07/);
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
 await frame.locator('#modalLlamadaEntranteAnalista [data-bs-dismiss]').first().click();
 assert.equal(await frame.locator('#modalLlamadaEntranteAnalista.show').count(),1,'No cerrar sin guardar');
 await frame.locator('#otherModule').evaluate(e=>e.click());assert.ok(frame.url().includes('saliente_ref='),'No salir a otro módulo');
 assert.equal(await frame.locator('[name=observacion]').inputValue(),'Notas capturadas en el formulario compartido');
 // Otra referencia no libera este resultado.
 await page.evaluate(()=>dispatchEvent(new MessageEvent('message',{origin:location.origin,source:document.querySelector('#impeCrmFrame').contentWindow,data:{type:'IMPE_ANALYST_RESULT_SAVED',callId:'otra-llamada'}})));
 assert.equal(await page.evaluate(()=>IMPE_ANALYST_OUTGOING_RESULT.hasPending()),true);
 mode='success';await frame.locator('[data-incoming-submit]').click();
 await page.waitForFunction(()=>!IMPE_ANALYST_OUTGOING_RESULT.hasPending());
 assert.equal(await page.evaluate(()=>sessionStorage.getItem('impe:analista:saliente-pendiente:1')),null);
 assert.equal(stateCalls,3,'Abrir automáticamente después de dos respuestas todavía sin confirmación');
 // Una segunda marcación al mismo destino no reutiliza ni el resultado ni el PBX anteriores.
 const second={...active,callToken:'manual-prueba-002',pbxCallId:'out-fixture-002',afterEventId:600};
 await page.evaluate(s=>publish(s),second);
 await page.evaluate(s=>publish({...s,active:false,phase:'finished',finalMetadata:{pbx_call_id:s.pbxCallId}}),second);
 await page.waitForFunction(()=>IMPE_ANALYST_OUTGOING_RESULT.hasPending());
 await page.locator('[data-analyst-outgoing-preparing]').waitFor({state:'visible'});
 assert.equal(JSON.parse(await page.evaluate(()=>sessionStorage.getItem('impe:analista:saliente-pendiente:1'))).ref,'out-fixture-002');
 providerRef='out-fixture-002';
 await page.waitForFunction(()=>document.querySelector('#impeCrmFrame').contentWindow.location.search.includes('out-fixture-002'));
 const secondForm=page.frames().find(f=>f.url().includes('out-fixture-002'));
 await secondForm.locator('#modalLlamadaEntranteAnalista.show').waitFor();
 assert.equal(await secondForm.locator('[name=pbx_call_id]').inputValue(),'out-fixture-002');
 assert.equal(await secondForm.locator('[name=observacion]').inputValue(),'','No arrastrar notas anteriores');
 await secondForm.locator('[name=decision]').selectOption('sin_identificar');
 await secondForm.locator('[data-incoming-submit]').click();
 await page.waitForFunction(()=>!IMPE_ANALYST_OUTGOING_RESULT.hasPending());
 assert.ok(stateCalls>=5,'El reintento automático recupera exclusivamente la nueva llamada');
 // El servidor guardó pero se perdió el OK: comprobar la referencia sin repetir el POST.
 providerRef='out-fixture-003';mode='lost-response';
 const third={...active,callToken:'manual-prueba-003',pbxCallId:providerRef,afterEventId:700};
 await page.evaluate(s=>publish(s),third);
 await page.evaluate(s=>publish({...s,active:false,phase:'finished',finalMetadata:{pbx_call_id:s.pbxCallId}}),third);
 await page.waitForFunction(()=>document.querySelector('#impeCrmFrame').contentWindow.location.search.includes('out-fixture-003'));
 const thirdForm=page.frames().find(f=>f.url().includes('out-fixture-003'));
 await thirdForm.locator('#modalLlamadaEntranteAnalista.show').waitFor();
 await thirdForm.locator('[name=decision]').selectOption('sin_identificar');
 const before=posts;await thirdForm.locator('[data-incoming-submit]').click();
 await page.waitForFunction(()=>!IMPE_ANALYST_OUTGOING_RESULT.hasPending());
 assert.equal(posts,before+1,'Recuperar un OK perdido no genera otro registro');
 assert.ok(stateCalls>=6);

 assert.deepEqual(errors,[]);
 // Shell PHP real: el resumen no puede quedar encima mientras llega el webhook.
 const shell=cp.execFileSync(process.env.PHP_BIN||'php',['tests/fixtures/telefonia_flujo_shell.php','analista'],{encoding:'utf8'});
 const real=await browser.newPage({viewport:{width:1366,height:900}});let confirmed=false,lookups=0;
 real.on('pageerror',e=>errors.push(e.message));
 await real.route('http://llamadas.test/**',async r=>{
  const u=new URL(r.request().url());
  if(u.pathname.startsWith('/public/javascript/'))return r.fulfill({contentType:'application/javascript',body:read(u.pathname.slice(1))});
  if(u.pathname.startsWith('/public/css/'))return r.fulfill({contentType:'text/css',body:read(u.pathname.slice(1))});
  if(u.pathname.endsWith('zadarma_estado_llamada.php')){lookups++;return r.fulfill({json:{ok:true,call:confirmed?{pbx_call_id:'out-real-shell-001',status:'completed'}:null}});}
  if(u.searchParams.get('action')==='estadoResultadoSaliente')return r.fulfill({json:{ok:true,llamada:{pbx_call_id:'out-real-shell-001',clasificacion:'PENDIENTE'}}});
  if(u.pathname==='/')return r.fulfill({contentType:'text/html',body:shell});
  if(u.searchParams.has('saliente_ref'))return r.fulfill({contentType:'text/html',body:formHtml.replaceAll('out-fixture-001','out-real-shell-001')});
  return r.fulfill({contentType:'text/html',body:'<!doctype html><title>Motor y módulo de prueba</title>'});
 });
 await real.goto('http://llamadas.test/');
 await real.waitForFunction(()=>window.IMPE_ANALYST_OUTGOING_RESULT&&window.IMPE_TELEPHONY_SHELL);
 const realState={...active,userId:8,callToken:'manual-real-shell-001',pbxCallId:'out-real-shell-001'};
 const publishReal=async s=>real.evaluate(s=>{s.updatedAt=Date.now();localStorage.setItem('impe:telephony:state:8',JSON.stringify(s));dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:8',newValue:JSON.stringify(s)}));},s);
 await publishReal(realState);
 await real.waitForFunction(()=>IMPE_TELEPHONY_SHELL.getCallState()?.active);
 await publishReal({...realState,active:false,phase:'finished'});
 await real.locator('[data-analyst-outgoing-preparing]').waitFor({state:'visible'});
 await real.waitForTimeout(400);
 assert.equal(await real.locator('[data-incoming-panel]').isVisible(),false,'El shell real oculta el resumen al reservar el resultado');
 assert.equal(await real.locator('[data-analyst-outgoing-pending]').isVisible(),false);
 confirmed=true;
 await real.frameLocator('#impeCrmFrame').locator('#modalLlamadaEntranteAnalista.show').waitFor();
 await real.locator('[data-analyst-outgoing-preparing]').waitFor({state:'hidden'});
 assert.equal(await real.locator('[data-incoming-panel]').isVisible(),false,'El resumen tampoco tapa el formulario cargado');
 assert.ok(lookups>=2,'Esperar y reintentar sin clic manual');
 assert.deepEqual(errors,[]);
 console.log('PASS formulario y shell reales: apertura inmediata, webhook tardío sin clic, resumen oculto, ID exacto, alta, notas, recuperación y cierre obligatorio');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
