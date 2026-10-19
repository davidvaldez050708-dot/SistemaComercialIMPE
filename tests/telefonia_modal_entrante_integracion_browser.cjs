// Shell, observador pasivo, vista PHP y Bootstrap reales. Transporte PBX simulado.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright'),repo=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(repo,p),'utf8'),bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.dirname(require.resolve('bootstrap/package.json'));
const shell=cp.execFileSync('php',[path.join(__dirname,'fixtures/telefonia_flujo_shell.php'),'analista'],{encoding:'utf8'});
const view=cp.execFileSync('php',[path.join(__dirname,'fixtures/analista_atencion_vista.php')],{encoding:'utf8'}).replaceAll('http://atencion.test/','http://llamadas.test/').replace('data-current-user="1"','data-current-user="8"');
const html=`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'))}\n${['global.css','dashboard.css','seguimiento_entrantes_analista.css','seguimiento_llamadas_expediente.css'].map(f=>read('public/css/'+f)).join('\n')}</style><body><main class="admin-shell">${view}</main><script>${fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'))}</script><script>${read('public/javascript/telefonia_atencion.js')}</script><script>${read('public/javascript/seguimiento_entrantes_analista.js')}</script></body>`;
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});try{
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://llamadas.test/**',route=>{const u=new URL(route.request().url());
  if(u.pathname.startsWith('/public/javascript/'))return route.fulfill({contentType:'application/javascript',body:read(u.pathname.slice(1))});
  if(u.searchParams.get('action')==='eventoEntranteWebrtc')return route.fulfill({json:{ok:true,clasificacion:'PENDIENTE'}});
  if(u.pathname.endsWith('.php')&&u.searchParams.get('action')!=='index'&&u.searchParams.get('action')!=='misLlamadas')return route.fulfill({json:{ok:true,borrador:null,entrante:null,devoluciones:0,llamadas:[],contexto:null}});
  return route.fulfill({contentType:'text/html',body:u.pathname==='/'?shell:u.searchParams.get('action')==='host'?'<html>Host simulado</html>':html});
 });
 await page.goto('http://llamadas.test/');await page.waitForFunction(()=>window.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG);
 const push=(phase,active)=>page.evaluate(({phase,active})=>{const state={userId:8,hostReady:true,updatedAt:Date.now(),active,phase,direction:'incoming',destination:'5551234567',duration:6,pbxCallId:'pbx-confirmed-001',callToken:'sdk-confirmed-12345678',answeredAtMs:Date.now()-6000,context:{type:'INCOMING',sdkSessionId:'sdk-confirmed-12345678'}};localStorage.setItem('impe:telephony:state:8',JSON.stringify(state));dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:8',newValue:JSON.stringify(state)}));},{phase,active});
 await push('incoming-ringing',true);await push('in-progress',true);await push('incoming-finished',false);
 await page.evaluate(()=>window.testHost=document.querySelector('[data-shell-engine]'));
 const phone=page.locator('[data-incoming-panel]');await page.waitForFunction(()=>IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG.hasFinalized());await phone.waitFor({state:'visible'});
 const crm=page.frameLocator('#impeCrmFrame'),modal=crm.locator('#modalLlamadaEntranteAnalista');
 await crm.locator('[data-call-id="pbx-confirmed-001"]').click();await modal.waitFor({state:'visible'});await phone.waitFor({state:'hidden'});
 assert.equal(await page.evaluate(()=>IMPE_TELEPHONY_SHELL.isResultFormOpen()),true);
 await push('incoming-finished',false);await page.evaluate(()=>document.querySelector('[data-incoming-panel]').hidden=false);await phone.waitFor({state:'hidden'});
 for(const size of [{width:1366,height:900},{width:390,height:600},{width:740,height:360}]){
  await page.setViewportSize(size);const footer=await modal.locator('.modal-footer').boundingBox(),header=await modal.locator('.modal-header').boundingBox();assert.ok(header.y>=0&&footer.y+footer.height<=size.height+1,'cabecera y guardado accesibles');assert.equal(await page.locator('[data-incoming-panel]').isVisible(),false);assert.equal(await crm.locator('.modal-backdrop').count(),1);
  if(process.env.INTEGRAL_SCREENSHOT)await page.screenshot({path:process.env.INTEGRAL_SCREENSHOT+'-'+size.width+'x'+size.height+'.png'});
 }
 assert.equal(await page.evaluate(()=>testHost&&testHost===document.querySelector('[data-shell-engine]')),true,'abrir resultado no desmonta el host WebRTC');
 await modal.locator('.btn-close').click();await modal.waitFor({state:'hidden'});await phone.waitFor({state:'visible'});
 // La tarjeta completa permanece desplazable en móvil, con controles de cabecera visibles.
 for(const size of [{width:390,height:600},{width:740,height:360}]){
  await page.setViewportSize(size);
  await page.waitForFunction(()=>{const r=document.querySelector('[data-incoming-panel]').getBoundingClientRect();return r.y>=0&&r.bottom<=innerHeight+1;});
  const box=await phone.boundingBox();assert.ok(box.y>=0&&box.y+box.height<=size.height+1,'teléfono dentro del viewport móvil');
 }
 await crm.locator('[data-call-id="pbx-confirmed-001"]').click();await modal.waitFor({state:'visible'});
 await push('incoming-ringing',true);await modal.waitFor({state:'hidden'});await phone.waitFor({state:'visible'});await crm.locator('.modal-backdrop').waitFor({state:'detached',timeout:2000});assert.equal(await crm.locator('.modal-backdrop').count(),0,'llamada nueva interrumpe limpiamente el formulario');
 assert.deepEqual(errors,[]);console.log('Entrante: shell + observador + formulario, heartbeat, interrupción y tres tamaños sin superposición: OK');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
