// Interfaz real del shell + formulario comercial + Bootstrap. API/PBX simulados.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright'),repo=path.resolve(__dirname,'..');
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.dirname(require.resolve('bootstrap/package.json'));
const read=p=>fs.readFileSync(path.join(repo,p),'utf8'),php=(f,...args)=>cp.execFileSync('php',[path.join(__dirname,'fixtures',f),...args],{encoding:'utf8'});
const shell=php('telefonia_ventas_shell.php');
const css=fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'),'utf8')+['global.css','dashboard.css','telefonia.css','telefonia_marcador.css','telefonia_persistente.css','telefonia_teclado.css','responsive_sistema.css'].map(f=>read('public/css/'+f)).join('\n');
let saved=false,payload,closed=false,details=0,delayDetails=0,evidenceRequests=0;
const call={id:1,pbx_call_id:'pbx-sales-call-001',usuario_id:8,extension:'150',direccion:'Entrante',numero:'+525551112233',nombre:'María',origen_extension:'100',fecha:'2026-10-19 10:00:00',finalizada:true,conectada:true,segundos:18,estado_pbx:'Conectada',segmentos:['record-sales-a','record-sales-b'],registro:null};
const view=u=>`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style><script>window.IMPE_CURRENT_USER_ID=8;</script><body><main class="admin-shell">${php('telefonia_ventas_vista.php',u.searchParams.get('pbx_call_id')||'',u.searchParams.get('extension')||'',saved?'guardado':'')}</main><script>${fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),'utf8')}</script><script>${read('public/javascript/telefonia_atencion.js')}</script><script>${read('public/javascript/telefonia_segmentos_player.js')}</script><script>${read('public/javascript/telefonia_ventas_resultados.js')}</script></body>`;
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});try{
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://ventas.test/**',route=>{const u=new URL(route.request().url()),action=u.searchParams.get('action');
  if(u.pathname.startsWith('/public/javascript/'))return route.fulfill({contentType:'application/javascript',body:read(u.pathname.slice(1))});
  if(action==='disponibilidad')return route.fulfill({json:{ok:true,entrante:null,devoluciones:0}});
  if(action==='detalleVenta'){details++;return new Promise(resolve=>setTimeout(resolve,delayDetails)).then(()=>route.fulfill({json:{ok:true,llamada:{...call,finalizada:closed,registro:saved?{resultado:'INTERESADO',observacion:'Enviar información',prospecto_nombre:'María'}:null}}}));}
  if(action==='guardarResultadoVenta'){payload=Object.fromEntries(new URLSearchParams(route.request().postData()));saved=true;return route.fulfill({json:{ok:true,registro:{resultado:'INTERESADO'}}});}
  if(action==='actualizarEvidenciaVenta'){const p=new URLSearchParams(route.request().postData());assert.equal(p.get('pbx_call_id'),call.pbx_call_id);assert.equal(p.get('extension'),'150');assert(p.get('csrf_token'));evidenceRequests++;return route.fulfill({json:{ok:true,llamada:call,mensaje:'Segmentos propios comprobados.'}});}
  if(action==='host')return route.fulfill({contentType:'text/html',body:'<html>Transporte PBX simulado</html>'});
  return route.fulfill({contentType:'text/html',body:u.pathname==='/'?shell:view(u)});
 });
 await page.goto('http://ventas.test/');await page.waitForFunction(()=>IMPE_SALES_ATTENTION&&document.querySelector('[data-shell-engine]'));
 await page.frameLocator('#impeCrmFrame').locator('[data-sales-history]').waitFor();
 await page.frameLocator('#impeCrmFrame').locator('[data-sales-history]').evaluate(root=>{const win=root.ownerDocument.defaultView;win.noticeRefreshes=0;win.addEventListener('impe:phone-notices-refresh',()=>win.noticeRefreshes++);});
 const push=(phase,active)=>page.evaluate(({phase,active})=>{const state={userId:8,hostReady:true,updatedAt:Date.now(),active,phase,direction:'incoming',destination:'+525551112233',duration:18,pbxCallId:'pbx-sales-call-001',callToken:'sdk-sales-1234567890',answeredAtMs:Date.now()-18000,context:{type:'INCOMING',sdkSessionId:'sdk-sales-1234567890'}};localStorage.setItem('impe:telephony:state:8',JSON.stringify(state));dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:8',newValue:JSON.stringify(state)}));},{phase,active});
 await push('in-progress',true);await page.evaluate(()=>window.testHost=document.querySelector('[data-shell-engine]'));await page.waitForTimeout(850);
 await push('incoming-finished',false);
 const crm=page.frameLocator('#impeCrmFrame'),modal=crm.locator('#modalResultadoVenta'),phone=page.locator('[data-incoming-panel]');
 await page.waitForFunction(()=>window.IMPE_SALES_ATTENTION.hasPending());
 await page.waitForTimeout(900);assert.equal(await page.evaluate(()=>document.querySelector('#impeCrmFrame').contentWindow.noticeRefreshes),0,'WebRTC finalizado no acredita cierre PBX ni genera aviso confirmado');
 assert.equal(await modal.isVisible(),false,'No abrir resultado automáticamente encima del teléfono');
 await phone.waitFor({state:'visible'});
 await page.locator('[data-sales-open]').click();
 await modal.waitFor({state:'visible'});await phone.waitFor({state:'hidden'});
 assert.equal(await modal.locator('[data-sales-submit]').isVisible(),false,'No permite guardar sin cierre PBX');
 assert.match(await modal.locator('[data-sales-feedback]').textContent(),/confirmación del cierre/);
 const frame=page.frames().find(f=>f.url().includes('misLlamadas'));
 await frame.evaluate(()=>{window.salesNotices=[];window.IMPE_TELEPHONY_NOTIFY=(text)=>window.salesNotices.push(text);});
 await frame.addScriptTag({content:read('public/javascript/telefonia_ventas_resultados.js')});
 await page.addScriptTag({content:read('public/javascript/telefonia_ventas_shell.js')});
 assert.equal(await page.locator('[data-sales-open]').count(),1,'Reinicializar no duplica observadores ni botones');
 await page.waitForTimeout(1700);
 assert.equal(await frame.evaluate(()=>salesNotices.length),0,'Los reintentos pendientes no emiten toasts de error');
 delayDetails=200;const before=details;
 await modal.locator('[data-sales-recheck]').evaluate(button=>{button.click();button.click();button.click();});
 await page.waitForTimeout(400);assert.equal(details-before,1,'Tres clics concurrentes comparten una sola consulta');
 await modal.locator('[data-bs-dismiss]').first().click();await modal.waitFor({state:'hidden'});
 const stopped=details;await page.waitForTimeout(1800);assert.equal(details,stopped,'Cerrar cancela el reintento del formulario anterior');
 await page.locator('[data-sales-open]').click();await modal.waitFor({state:'visible'});closed=true;
 await modal.locator('[data-sales-recheck]').click();await modal.locator('[data-sales-submit]').waitFor({state:'visible'});delayDetails=0;
 assert.equal(await phone.isVisible(),false,'La recuperación mantiene oculto el teléfono finalizado');
 assert.equal(await frame.evaluate(()=>salesNotices.length),0);
 assert.equal(await page.evaluate(()=>IMPE_TELEPHONY_SHELL.isResultFormOpen()),true);
 assert.equal(await crm.locator('.modal-backdrop').count(),1);
 for(const size of [{width:1366,height:900},{width:390,height:600},{width:740,height:360}]){
  await page.setViewportSize(size);await page.waitForTimeout(250);const h=await modal.locator('.modal-header').boundingBox(),f=await modal.locator('.modal-footer').boundingBox();assert.ok(h.y>=0&&f.y+f.height<=size.height+1,'cabecera y guardado accesibles '+JSON.stringify({size,h,f}));
  assert.equal(await page.evaluate(()=>testHost===document.querySelector('[data-shell-engine]')),true);
  assert.equal(await phone.isVisible(),false);
  if(process.env.IMPE_VENTAS_SCREENSHOTS)await page.screenshot({path:path.join(process.env.IMPE_VENTAS_SCREENSHOTS,'ventas_resultado_'+size.width+'.png')});
  const scroll=await modal.locator('.modal-body').evaluate(el=>{const overflow=el.scrollHeight>el.clientHeight+1;el.scrollTop=el.scrollHeight;return{overflow,top:el.scrollTop};});if(scroll.overflow)assert.ok(scroll.top>0,'El cuerpo del formulario se desplaza');await modal.locator('.modal-body').evaluate(el=>el.scrollTop=0);
 }
 await page.setViewportSize({width:1366,height:900});
 await modal.locator('[name=observacion]').fill('Borrador comercial conservado');await modal.locator('[name=guardar_contacto]').check();
 await page.frames().find(f=>f!==page.mainFrame()&&f.url().includes('misLlamadas')).evaluate(()=>location.reload());
 await modal.waitFor({state:'visible'});assert.equal(await modal.locator('[name=observacion]').inputValue(),'Borrador comercial conservado');assert.equal(await modal.locator('[name=guardar_contacto]').isChecked(),true);assert.equal(await phone.isVisible(),false);
 // Recarga con llamada finalizada: conserva el pendiente, sin necesitar otra llamada.
 await page.reload();await page.waitForFunction(()=>window.IMPE_SALES_ATTENTION?.hasPending());
 assert.equal(await page.locator('.impe-analyst-call-result').count(),0,'Sin aviso flotante duplicado');
 await crm.locator('[data-sales-detail]').first().click();await modal.waitFor({state:'visible'});assert.equal(await modal.locator('[name=observacion]').inputValue(),'Borrador comercial conservado');assert.equal(await modal.locator('[name=guardar_contacto]').isChecked(),true);assert.equal(await phone.isVisible(),false);
 await modal.locator('[name=resultado]').selectOption('INTERESADO');await modal.locator('[name=observacion]').fill('Enviar información');
 await modal.locator('[data-sales-submit]').click();await modal.waitFor({state:'hidden'});
 assert.equal(payload.pbx_call_id,call.pbx_call_id);assert.equal(payload.extension,'150');assert.equal(payload.resultado,'INTERESADO');assert.equal(payload.prospecto_nombre,'María');
 await page.waitForFunction(()=>!IMPE_SALES_ATTENTION.hasPending());
 await crm.locator('[data-sales-audio]').first().click();await crm.locator('[data-sales-audio-slot] audio').first().waitFor({state:'attached'});assert.equal(evidenceRequests,1,'Consulta unica con CSRF y tramo exacto');
 assert.equal(await crm.locator('[data-sales-audio-slot] audio').count(),2,'Reproductor compartido de multiples segmentos');
 assert.match(await crm.locator('.linkage-call-inline-player-heading').first().textContent(),/Tramo 1.*0:08/);
 if(process.env.IMPE_VENTAS_SCREENSHOTS)for(const width of [1366,768,390]){await push('ready',false);await page.setViewportSize({width,height:850});await crm.locator('.linkage-call-history-card').first().screenshot({path:path.join(process.env.IMPE_VENTAS_SCREENSHOTS,'ventas_grabaciones_'+width+'.png')});}
 assert.deepEqual(errors,[]);console.log('Ventas C: fin → formulario sin superposición, responsive, resultado y dos segmentos propios: OK');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
