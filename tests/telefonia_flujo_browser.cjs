// Interfaz PHP real, navegador real, sin llamadas ni credenciales externas.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const repo=path.resolve(__dirname,'..');
const html=cp.execFileSync('php',[path.join(__dirname,'fixtures/telefonia_flujo_shell.php')],{encoding:'utf8'});
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 try{
  const page=await browser.newPage({viewport:{width:1366,height:900}}),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  let calls=[],saveFails=true,saveCount=0,lastBody=null;
  await page.route('**/*',async route=>{
   const u=new URL(route.request().url());
   if(u.hostname!=='llamadas.test')return route.abort();
   if(u.pathname.endsWith('/avisos_llamadas.php'))return route.fulfill({json:{ok:true,extension:'100',llamadas:calls}});
   if(u.pathname.endsWith('/resultado_recepcion_marketing.php')){
    saveCount++;lastBody=route.request().postData();
    if(saveFails)return route.fulfill({status:500,json:{ok:false,mensaje:'No se pudo guardar. Inténtalo nuevamente.'}});
    for(const c of calls)if(c.requiere_resultado)c.requiere_resultado=false;
    return route.fulfill({json:{ok:true,registro:{resultado:'CONTACTO'}}});
   }
   if(u.pathname.startsWith('/public/javascript/'))return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(path.join(repo,u.pathname),'utf8')});
   if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
   return route.fulfill({contentType:'text/html',body:'<!doctype html><title>CRM</title>'});
  });
  await page.goto('http://llamadas.test/');
  await page.waitForFunction(()=>window.IMPE_CALL_CLOSURE && window.IMPE_TELEPHONY_SHELL);
  const push=(phase,active,id='pbx-real-001',extra={})=>page.evaluate(({phase,active,id,extra})=>{
   const state={userId:7,updatedAt:Date.now(),hostReady:true,active,phase,direction:'incoming',destination:'5551234567',pbxCallId:id,callToken:id,duration:phase==='in-progress'||!active?20:0,transferFrom:'100',...extra};
   localStorage.setItem('impe:telephony:state:7',JSON.stringify(state));
   window.dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:7',newValue:JSON.stringify(state)}));
  },{phase,active,id,extra});
  await push('incoming-ringing',true);
  const panel=page.locator('[data-incoming-panel]');await panel.waitFor({state:'visible'});
  assert.equal(await page.locator('[data-incoming-origin]').textContent(),'Extensión 100');
  assert.equal(await page.locator('[data-incoming-origin-label]').textContent(),'Transferida desde:');
  const box=await panel.boundingBox();assert.ok(Math.abs(box.x+box.width/2-683)<2 && Math.abs(box.y+box.height/2-450)<2,'modal centrado al recibir');
  await push('in-progress',true);
  calls=[{id:'pbx-real-001',aliases:[],numero:'5551234567',tipo:'Entrante',fecha_ts:Date.now()-30000,fin_ts:Date.now(),finalizada:true,atendida:true,requiere_resultado:true,transferida:false,perdida:false}];
  await push('incoming-finished',false);
  const modal=page.locator('[data-call-result-required]');await modal.waitFor({state:'visible'});
  if(process.env.FLUJO_SCREENSHOT)await page.screenshot({path:process.env.FLUJO_SCREENSHOT});
  await page.keyboard.press('Escape');assert.equal(await modal.isVisible(),true,'Escape no omite resultado obligatorio');
  await modal.locator('select').selectOption('CONTACTO');await modal.locator('textarea').fill('Atendió Ana');
  await modal.locator('button[type=submit]').click();
  await page.waitForFunction(()=>document.querySelector('[data-result-feedback]').textContent.includes('No se pudo guardar'));
  assert.equal(await modal.locator('textarea').inputValue(),'Atendió Ana','error conserva captura');
  assert.equal(await page.evaluate(()=>window.IMPE_CALL_CLOSURE.hasPending()),true);
  for(const size of [{width:390,height:600},{width:740,height:360},{width:1366,height:900}]){
   await page.setViewportSize(size);
   const bounds=await modal.boundingBox();assert.ok(bounds.y>=0 && bounds.y+bounds.height<=size.height+1,'modal dentro de pantalla');
   const footer=await modal.locator('.system-form-modal-footer').boundingBox();assert.ok(footer.y+footer.height<=size.height+1,'Guardar accesible');
  }
  await push('incoming-ringing',true,'pbx-next-002');await modal.waitFor({state:'hidden'});
  assert.equal(await panel.isVisible(),true,'entrante prioritaria puede contestarse');
  await push('incoming-finished',false,'pbx-next-002',{duration:0});await modal.waitFor({state:'visible'});
  assert.equal(await modal.locator('textarea').inputValue(),'Atendió Ana','nueva llamada no borra resultado anterior');
  saveFails=false;await modal.locator('button[type=submit]').click();await modal.waitFor({state:'hidden'});
  assert.equal(saveCount,2);assert.ok(lastBody.includes('test-csrf')&&lastBody.includes('pbx-real-001'),'CSRF y llamada exacta');
  // Una transición conocida como perdida se cierra automáticamente al llegar su registro.
  calls.push({id:'pbx-next-002',aliases:[],numero:'5551234567',tipo:'Entrante',fecha_ts:Date.now(),fin_ts:Date.now(),finalizada:true,atendida:false,requiere_resultado:false,perdida:true,transferida:false});
  await page.evaluate(()=>window.IMPE_CALL_CLOSURE.showPending());
  await page.waitForFunction(()=>!document.querySelector('[data-missed-calls]').hidden);
  assert.equal(await modal.isVisible(),false,'perdida no pide resultado');
  await page.locator('[data-missed-calls]').click();
  assert.equal(await page.locator('[data-missed-calls]').isVisible(),false,'aviso leído queda reconocido');
  await push('dialing',true,'pbx-out-003',{direction:'outgoing',context:{type:'TELEPHONY_MANUAL'}});
  assert.equal(await page.locator('[data-incoming-title]').textContent(),'Marcando…');
  await push('ringing',true,'pbx-out-003',{direction:'outgoing',context:{type:'TELEPHONY_MANUAL'}});
  assert.equal(await page.locator('[data-incoming-title]').textContent(),'Timbrando…');
  calls.push({id:'pbx-out-003',aliases:[],numero:'5551234567',tipo:'Saliente',fecha_ts:Date.now(),fin_ts:Date.now(),finalizada:true,atendida:true,requiere_resultado:false,perdida:false,transferida:true,destino:'101'});
  await push('finished',false,'pbx-out-003',{direction:'outgoing',status:'transferred',context:{type:'TELEPHONY_MANUAL'}});
  await page.waitForFunction(()=>!window.IMPE_CALL_CLOSURE.hasPending());
  assert.equal(await modal.isVisible(),false,'transferida no pide resultado manual');
  // Persistencia de una conversación pendiente al recargar la sesión.
  await push('incoming-ringing',true,'pbx-reload-004');
  calls.push({id:'pbx-reload-004',aliases:[],numero:'5551234567',tipo:'Entrante',fecha_ts:Date.now(),fin_ts:Date.now(),finalizada:true,atendida:true,requiere_resultado:true,perdida:false,transferida:false});
  await push('incoming-finished',false,'pbx-reload-004');await modal.waitFor({state:'visible'});
  await modal.locator('textarea').fill('Conservar nota al recargar');
  await page.reload();await modal.waitFor({state:'visible'});
  assert.ok((await modal.textContent()).includes('5551234567'),'pendiente recuperado después de recargar');
  assert.equal(await modal.locator('textarea').inputValue(),'Conservar nota al recargar','captura del resultado recuperada al recargar');
  assert.deepEqual(errors,[]);
  console.log('Flujo real de interfaz: origen, centro, progreso, resultado obligatorio, reintento, entrante prioritaria, perdida, transferencia y recarga: OK');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
