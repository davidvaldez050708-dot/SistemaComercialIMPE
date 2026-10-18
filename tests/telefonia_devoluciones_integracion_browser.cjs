const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright'),repo=path.resolve(__dirname,'..');
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.dirname(require.resolve('bootstrap/package.json'));
const view=cp.execFileSync('php',[path.join(__dirname,'fixtures/telefonia_devoluciones_vista.php')],{encoding:'utf8'});
const read=p=>p==='public/javascript/telefonia_persistente.js'&&process.env.IMPE_TEST_CLIENT_REF?cp.execFileSync('git',['show',process.env.IMPE_TEST_CLIENT_REF+':'+p],{cwd:repo,encoding:'utf8'}):fs.readFileSync(path.join(repo,p),'utf8');
const shellHtml=cp.execFileSync('php',[path.join(__dirname,'fixtures/telefonia_flujo_shell.php'),'analista'],{encoding:'utf8'}).replaceAll('http://llamadas.test/','http://devoluciones.test/');
const html=`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'))}\n${read('public/css/global.css')}\n${read('public/css/dashboard.css')}</style><body><main class="admin-shell">${view}</main><script>${fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'))}</script><script>${read('public/javascript/telefonia_atencion.js')}</script><script>${read('public/javascript/seguimiento_entrantes_analista.js')}</script><script>
window.IMPE_CURRENT_USER_ID=8;window.callPayloads=[];window.phoneState={active:false,userId:8,hostReady:true,phase:'ready'};
window.publishParent=()=>{const state={...phoneState,userId:8,hostReady:true,updatedAt:Date.now(),direction:phoneState.direction||'outgoing',destination:'+525551112233',callToken:'sdk-callback-integration-'+callPayloads.length,duration:12};localStorage.setItem('impe:telephony:state:8',JSON.stringify(state));window.parent.dispatchEvent(new window.parent.StorageEvent('storage',{key:'impe:telephony:state:8',newValue:JSON.stringify(state)}));window.dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:8',newValue:JSON.stringify(state)}));};
window.testMotor=new BroadcastChannel('impe-telephony-8');testMotor.onmessage=e=>{if(e.data.type!=='COMMAND')return;if(e.data.action==='START'){const p=e.data.payload;callPayloads.push(p);phoneState={active:true,phase:'in-progress',direction:'outgoing',context:p.context};publishParent();}else if(e.data.action==='PING'){publishParent();}else if(e.data.action==='CLEAR_FINISHED'){phoneState={active:false,phase:'ready',context:{}};publishParent();}};
window.finishCallback=()=>{phoneState={...phoneState,active:false,phase:'finished'};publishParent();};
document.addEventListener('DOMContentLoaded',()=>publishParent());
</script><script>${read('public/javascript/telefonia_persistente.js')}</script></body>`;
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 try{
  const page=await browser.newPage({viewport:{width:1366,height:900}}),errors=[];await page.clock.install();page.on('pageerror',e=>errors.push(e.message));
  let tasks=null,attempt=null,saved=0,fail=true,lastBody='',nextId=2;
  await page.route('http://devoluciones.test/**',async route=>{
   const req=route.request(),u=new URL(req.url()),body=new URLSearchParams(req.postData()||'');
   if(u.searchParams.get('action')==='devolucion'){
    if(!tasks)tasks=await crm.locator('[data-callback-data]').evaluate(e=>JSON.parse(e.textContent));
    let command=body.get('accion');if(req.headers()['content-type']?.startsWith('multipart/form-data')){for(const m of (req.postData()||'').matchAll(/name="([^"]+)"\r\n\r\n([^\r]*)/g))body.set(m[1],m[2]);command=body.get('accion');}
    if(command==='PREPARAR'){
     attempt={id:nextId++,solicitud_id:7,estado:'PREPARADO',resultado:'',numero:'+525551112233',puede_registrar:false,estado_llamada:'Marcando',contestada:false};tasks[0].intento=attempt;
     return route.fulfill({json:{ok:true,intento:attempt}});
    }
    if(command==='RESULTADO'){
     saved++;lastBody=req.postData();if(fail)return route.fulfill({status:500,json:{ok:false,mensaje:'No se pudo guardar. Intenta nuevamente.'}});
     attempt={...attempt,resultado:body.get('resultado'),observacion:body.get('observacion'),estado:'FINALIZADO',puede_registrar:false};tasks[0].intento=attempt;if(attempt.resultado==='CONTACTO_CORRECTO')tasks[0].devolucion='ATENDIDA';
     return route.fulfill({json:{ok:true,intento:attempt}});
    }
    return route.fulfill({json:{ok:true,...(u.searchParams.has('intento_id')?{intento:attempt}:{devoluciones:tasks})}});
   }
   if(u.searchParams.get('action')==='estadoUsuario')return route.fulfill({json:{ok:true,permite_salientes:true,permite_entrantes:true,permite_transferir:true,extension:'101'}});
   if(u.searchParams.get('action')==='buscarEntrantesSeguimientos')return route.fulfill({json:{ok:true,resultados:[{id:1,nombre_entidad:'Institución Uno',estado_nombre:'Morelos'}]}});
   if(u.pathname.startsWith('/public/javascript/'))return route.fulfill({contentType:'application/javascript',body:read(u.pathname.slice(1))});
   return route.fulfill({contentType:'text/html',body:u.pathname==='/'?shellHtml:u.searchParams.get('action')==='host'?'<html>Motor simulado</html>':html});
  });
  const crm=page.frameLocator('#impeCrmFrame');
  await page.goto('http://devoluciones.test/');
  const row=crm.locator('[data-callback-id="7"]');await row.waitFor();assert.match(await row.textContent(),/Institución Uno/);
  assert.equal(await row.getByRole('button',{name:'Llamar',exact:true}).count(),1);assert.equal(await crm.getByRole('button',{name:'Marcar atendida'}).count(),0);
  if(process.env.CALLBACK_SCREENSHOT)await page.screenshot({path:process.env.CALLBACK_SCREENSHOT+'-lista.png'});
  await row.getByRole('button',{name:'Llamar',exact:true}).click();await page.frame({url:/action=index/}).waitForFunction(()=>callPayloads.length===1);
  assert.equal(await page.frame({url:/action=index/}).evaluate(()=>callPayloads[0].context.type),'DEVOLUCION');assert.equal(await page.frame({url:/action=index/}).evaluate(()=>callPayloads[0].destination),'+525551112233');
  assert.equal(await crm.locator('.persistent-phone-panel').isVisible(),false,'el cliente persistente real no duplica el teléfono del shell');
  attempt={...attempt,puede_registrar:true,finalizada:true,contestada:true,duracion:12,estado_llamada:'Completada',pbx_call_id:'callback-test-001'};await page.frame({url:/action=index/}).evaluate(()=>finishCallback());
  const modal=crm.locator('#modalLlamadaEntranteAnalista');await modal.waitFor({state:'visible'});const form=modal.locator('form');
  await page.locator('[data-incoming-panel]').waitFor({state:'hidden'});
  assert.equal(await crm.locator('.persistent-phone-panel').isVisible(),false,'no aparece un segundo teléfono al abrir el formulario');
  assert.equal(await page.locator('[data-shell-status]').isVisible(),false,'sin aviso de conexión sobre el formulario');
  // Heartbeat sin contexto después de que el formulario tomó posesión del resultado.
  await page.evaluate(()=>{const state={userId:8,updatedAt:Date.now(),hostReady:true,active:false,phase:'ready',context:{}};window.dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:8',newValue:JSON.stringify(state)}));});
  assert.equal(await page.locator('[data-incoming-panel]').isVisible(),false,'estado preparado no reabre llamada finalizada');
  assert.equal(await crm.locator('.persistent-phone-panel').isVisible(),false,'no reaparece el panel hijo durante el resultado');
  assert.equal(await crm.locator('.modal-backdrop').count(),1,'un único fondo en el CRM');
  assert.equal(await page.locator('.modal-backdrop').count(),0,'sin fondo adicional en shell');
  await page.clock.fastForward(21000);
  assert.equal(await page.locator('[data-incoming-panel]').isVisible(),false,'caducar heartbeat no reabre teléfono finalizado');
  assert.equal(await page.locator('[data-shell-status]').isVisible(),false,'sin aviso de reconexión superpuesto tras caducar estado');
  await modal.getByRole('button',{name:'Cancelar',exact:true}).click();await modal.waitFor({state:'hidden'});await crm.locator('.modal-backdrop').waitFor({state:'detached'});
  assert.equal(await crm.locator('.modal-backdrop').count(),0,'cerrar limpia el único fondo');
  await row.getByRole('button',{name:'Registrar resultado',exact:true}).click();await modal.waitFor({state:'visible'});
  await form.locator('[name=observacion]').fill('Borrador antes de otra entrante');
  await page.frame({url:/action=index/}).evaluate(()=>{phoneState={active:true,direction:'incoming',phase:'incoming-ringing',context:{}};publishParent();});
  await modal.waitFor({state:'hidden'});await crm.locator('.modal-backdrop').waitFor({state:'detached'});await page.locator('[data-incoming-panel]').waitFor({state:'visible'});
  await page.frame({url:/action=index/}).evaluate(()=>{phoneState={active:false,direction:'incoming',phase:'incoming-finished',context:{}};publishParent();});
  await row.getByRole('button',{name:'Registrar resultado',exact:true}).click();await modal.waitFor({state:'visible'});
  await page.frame({url:/action=index/}).waitForFunction(()=>document.querySelector('[name=observacion]').value.includes('Borrador antes'),{},{timeout:3000});
    const submitBox=await form.locator('[type=submit]').boundingBox();
  const hit=await page.evaluate(({x,y})=>document.elementFromPoint(x,y)?.id,{x:submitBox.x+submitBox.width/2,y:submitBox.y+submitBox.height/2});
  assert.equal(hit,'impeCrmFrame','ningún panel del shell intercepta Guardar');


  assert.equal(await form.locator('[name=decision]').inputValue(),'existente');assert.equal(await form.locator('[name=seguimiento_id]').inputValue(),'1');
  await form.locator('[name=resultado]').selectOption('BUZON_VOZ');await form.locator('[name=observacion]').fill('Dejó buzón; reintentar por la tarde.');
  await form.locator('[type=submit]').click();await crm.locator('.system-toast-error').waitFor();assert.equal(await modal.isVisible(),true);assert.match(await form.locator('[name=observacion]').inputValue(),/reintentar/);
  await page.setViewportSize({width:390,height:650});const box=await modal.locator('.modal-content').boundingBox();assert.ok(box.y>=0&&box.y+box.height<=651,'modal accesible en móvil');
  if(process.env.CALLBACK_SCREENSHOT)await page.screenshot({path:process.env.CALLBACK_SCREENSHOT+'-resultado.png'});
  fail=false;await form.locator('[type=submit]').click();await modal.waitFor({state:'hidden'});assert.equal(saved,2);assert.match(lastBody,/test-csrf/);assert.match(lastBody,/seguimiento_id/);
  await row.getByRole('button',{name:'Llamar',exact:true}).waitFor();assert.match(await row.textContent(),/Buzón de voz/);assert.equal(await row.getByRole('button',{name:'Consultar grabación'}).count(),0);
  await page.setViewportSize({width:1366,height:900});await row.getByRole('button',{name:'Llamar',exact:true}).click();await page.frame({url:/action=index/}).waitForFunction(()=>callPayloads.length===2);
  attempt={...attempt,puede_registrar:true,finalizada:true,contestada:true,duracion:12,estado_llamada:'Completada',pbx_call_id:'callback-test-002'};await page.frame({url:/action=index/}).evaluate(()=>finishCallback());await modal.waitFor({state:'visible'});
  await form.locator('[name=resultado]').selectOption('CONTACTO_CORRECTO');await form.locator('[name=observacion]').fill('Conversó Ana.');await form.locator('[type=submit]').click();await modal.waitFor({state:'hidden'});
  await row.waitFor({state:'detached'});await crm.locator('[data-callback-filter]').selectOption('ATENDIDA');await row.waitFor();assert.match(await row.textContent(),/Contacto correcto/);assert.equal(await row.getByRole('button',{name:'Llamar',exact:true}).count(),0);
  tasks[0]={...tasks[0],devolucion:'PENDIENTE',intento:null};await crm.locator('[data-callback-filter]').selectOption('PENDIENTE');await crm.locator('[data-callback-refresh]').click();await row.waitFor();
  await row.getByRole('button',{name:'Llamar',exact:true}).click();await page.frame({url:/action=index/}).waitForFunction(()=>callPayloads.length===3);
  attempt={...attempt,puede_registrar:true,finalizada:true,contestada:false,duracion:0,estado_llamada:'Sin respuesta',pbx_call_id:'callback-test-003'};await page.frame({url:/action=index/}).evaluate(()=>finishCallback());await modal.waitFor({state:'visible'});
  await form.locator('[name=decision]').selectOption('nueva');assert.equal(await form.locator('[name=decision] option[value=nueva]').isDisabled(),false,'sin deshabilitar silenciosamente el alta nueva');
  assert.equal(await form.locator('[data-incoming-callback-answer]').isVisible(),true);
  assert.match(await form.locator('[data-incoming-callback-answer]').textContent(),/sin conversación confirmada/);
  assert.equal(await crm.locator('.persistent-phone-panel').isVisible(),false,'también oculta segundo panel para sin respuesta');
  assert.equal(await page.locator('[data-incoming-panel]').isVisible(),false);
  assert.deepEqual(errors,[]);console.log('Devoluciones/shell e iframe reales: llamada propia, resultado automático, expediente, error sin pérdida, buzón reintentable, cierre por contacto, filtro y móvil: OK');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
