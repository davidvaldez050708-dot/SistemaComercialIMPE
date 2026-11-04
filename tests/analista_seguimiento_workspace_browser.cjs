const fs=require('fs'),cp=require('child_process'),assert=require('assert/strict'),{chromium}=require('playwright');
const read=p=>fs.readFileSync(p,'utf8');
const view=cp.execFileSync(process.env.PHP_BIN||'php',['tests/fixtures/denue_paginado_vista.php'],{encoding:'utf8'}).replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,'');
const dir=process.env.BOOTSTRAP_DIR||'../impe-denue-estabilidad/tmp/denue-bootstrap';
const css=read(dir+'/bootstrap.min.css')+read('public/css/global.css')+read('public/css/dashboard.css')+read('public/css/telefonia_persistente.css')+read('public/css/seguimiento_llamada_registro_compacto.css');
const shell=read('public/javascript/telefonia_shell.js'),persistent=read('public/javascript/telefonia_persistente.js');
assert.match(shell,/const seguimientoOutgoing = Boolean/,'El shell abre la misma ventana para Seguimiento');
assert.match(persistent,/const seguimientoInShell = embedded/,'No crear una tarjeta telefónica duplicada');
const rendered=cp.execFileSync(process.env.PHP_BIN||'php',['tests/fixtures/telefonia_flujo_shell.php','analista'],{encoding:'utf8'});
assert.match(rendered,/data-shell-capture-toggle/,'El analista tiene Datos de llamada');
assert.match(rendered,/data-routing-capture-form/,'El borrador se captura en el shell');
assert.match(rendered,/"canCapture":true/,'La ficha de datos está habilitada');
const setup=[
"window.IMPE_CURRENT_ROLE_ID=4;window.IMPE_CURRENT_USER_ID=99;window.IMPE_TELEPHONY_NOTIFY=()=>{};",
"document.addEventListener('DOMContentLoaded',()=>{const p=document.getElementById('offcanvasSeguimientoTrabajo');p.dataset.flowSeguimientoId='10';p.querySelector('[data-work-title]').textContent='Institución de prueba';",
"document.querySelector('[data-work-toggle-interaction]').onclick=()=>document.querySelector('[data-work-interaction-form]').classList.remove('d-none');});",
"window.publish=s=>{s.updatedAt=Date.now();localStorage.setItem('impe:telephony:state:99',JSON.stringify(s));dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:99',newValue:JSON.stringify(s)}));};"
].join('\n');
const html='<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+css+'</style>'+view+'<button data-work-follow-id="10" hidden>Expediente</button><script>'+read(dir+'/bootstrap.bundle.min.js')+'</script><script>'+setup+'</script><script>'+persistent+'</script><script>'+read('public/javascript/seguimiento_llamada_registro_obligatorio.js')+'</script><script>'+read('public/javascript/seguimiento_llamada_registro_compacto.js')+'</script>';
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{channel:'chrome'})});try{
 for(const width of [1440,1024,390]){
  const page=await browser.newPage({viewport:{width,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('http://workspace.test/**',r=>r.request().resourceType()==='document'?r.fulfill({contentType:'text/html',body:html}):r.fulfill({json:{ok:true,extension:'101',permite_salientes:true}}));
  await page.goto('http://workspace.test/');await page.waitForFunction(()=>window.IMPE_TELEPHONY_PERSISTENT&&document.querySelector('[data-persistent-phone]'));
  await page.evaluate(()=>window.originalTelephone=document.querySelector('[data-persistent-phone]'));
  const state={userId:99,hostReady:true,active:true,phase:'in-progress',status:'in-progress',direction:'outgoing',callToken:'workspace-test-call',pbxCallId:'out-workspace-test',destination:'+525551234567',duration:18,context:{type:'VINCULACION',seguimientoId:10,institution:'Institución de prueba'}};
  const panel=page.locator('#offcanvasSeguimientoTrabajo'),form=panel.locator('[data-work-interaction-form]');
  assert.equal(await form.isVisible(),false);
  await page.evaluate(s=>publish(s),state);await page.waitForTimeout(140);
  assert.equal(await panel.locator('[data-persistent-phone]').count(),0,'No mover el teléfono al Panel de trabajo');
  assert.equal(await panel.evaluate(e=>e.classList.contains('impe-call-workspace')),false,'Sin ventana híbrida inventada');
  assert.equal(await page.locator('[data-persistent-phone]').evaluate(e=>e===originalTelephone),true);
  assert.equal(await form.isVisible(),false,'No mostrar el formulario definitivo en conversación');
  assert.equal(await panel.locator('[data-call-registration-required-note]').count(),0);
  await page.evaluate(s=>publish({...s,active:false,phase:'finished',finalMetadata:{pbx_call_id:s.pbxCallId,call_token:s.callToken}}),state);
  await form.waitFor({state:'visible'});
  assert.equal(await panel.evaluate(e=>e.classList.contains('impe-call-workspace')),false);
  assert.equal(await panel.locator('[data-persistent-phone]').count(),0);
  assert.equal(await panel.locator('[data-work-contact-form]').count(),1,'El formulario de contacto permanece en el panel original');
  const note=panel.locator('[data-call-registration-required-note]');
  assert.match(await note.textContent(),/Resultado pendiente/);
  assert.equal(await note.evaluate(e=>getComputedStyle(e).fontSize),'13px');
  assert.equal(await note.evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(248, 250, 252)','Aviso de estilo neutro');
  assert.equal(await note.evaluate(e=>getComputedStyle(e.querySelector('strong')).fontSize),'12.5px');
  // El resumen técnico puede aparecer tarde; el script compacto debe ocultarlo,
  // pero nunca sobrescribir el aviso con duración o estado de grabación.
  await form.evaluate(f=>{
    const summary=document.createElement('div');
    summary.dataset.telephonyCallSummary='';
    summary.dataset.callDurationSeconds='36';
    summary.dataset.callRecordingState='none';
    summary.textContent='Llamada finalizada · 00:36 · Sin grabación';
    f.append(summary);
    document.dispatchEvent(new CustomEvent('impe:call-registration-pending-changed',{detail:{pending:true,seguimientoId:10}}));
  });
  await page.waitForTimeout(450);
  assert.equal(await note.locator('strong').textContent(),'Resultado pendiente');
  assert.match(await note.locator('span').textContent(),/Selecciona el resultado y guarda la interacción/);
  assert.equal(await form.locator('[data-telephony-call-summary]').isVisible(),false,'Resumen técnico redundante oculto');
  const avisoEstable=await note.textContent();
  await page.evaluate(()=>document.dispatchEvent(new CustomEvent('impe:flow-updated')));
  await page.waitForTimeout(550);
  assert.equal(await note.textContent(),avisoEstable,'Sin alternancia de mensajes al sondear o refrescar el expediente');
  assert.doesNotMatch(await note.textContent(),/00:36|grabación/i);
  await form.locator('[name=observacion]').fill('Resultado guardado en el expediente');
  await page.evaluate(()=>document.dispatchEvent(new CustomEvent('impe:flow-updated')));await page.waitForTimeout(70);
  assert.equal(await form.locator('[name=observacion]').inputValue(),'Resultado guardado en el expediente');
  assert.equal(await form.locator('[type=submit]').isEnabled(),true);
  await page.evaluate(()=>{document.dispatchEvent(new CustomEvent('impe:interaction-exact-id-ready',{detail:{seguimientoId:10,interaccionId:77}}));document.dispatchEvent(new CustomEvent('impe:telephony-call-linked',{detail:{provider:'ZADARMA',callToken:'workspace-test-call',seguimientoId:10,interaccionId:77}}));});
  assert.equal(await note.count(),0,'Sólo el vínculo exacto libera el aviso');
  await page.evaluate(s=>publish({...s,callToken:'otra-saliente-prueba',context:{type:'DIALER'}}),state);
  assert.equal(await panel.evaluate(e=>e.classList.contains('impe-call-workspace')),false);
  assert.deepEqual(errors,[]);await page.close();
 }
 console.log('PASS: ventana telefonica separada del Panel de trabajo, borrador del shell, registro final, aviso institucional y tres tamaños');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});