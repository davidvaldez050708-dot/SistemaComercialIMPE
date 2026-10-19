const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.dirname(require.resolve('bootstrap/package.json'));
const read=p=>fs.readFileSync(path.join(__dirname,'../public/javascript/',p),'utf8');
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 try{for(const marketing of [false,true]){
  const page=await browser.newPage();await page.clock.install();let readBody='',listed=0;const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const bell=marketing?'<div data-convocatoria-notification-root data-endpoint="/list"><span data-convocatoria-notification-badge></span><div data-convocatoria-notification-content></div></div>':'<div data-reminder-root data-reminder-endpoint="/list"><span data-reminder-badge></span><div data-reminder-content></div></div>';
  let notice={id:-15,telefonia_id:15,titulo:'Transferencia sin respuesta',nombre_entidad:'Transferencia sin respuesta',mensaje:'Recepción intentó comunicarte.',accion:'Recepción intentó comunicarte.',url:'/historial',fecha:'2026-10-18 10:17:00',created_at:'2026-10-18 10:17:00',estado:'informativa',etiqueta:'Sin respuesta',tipo:'TELEFONIA_PERDIDA'};
  const html=`<!doctype html><style>${fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'))}</style><script>${fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'))}</script><script>window.IMPE_CURRENT_USER_ID=8;</script><div data-phone-notification-read="/read" data-phone-notification-csrf="csrf-own">${bell}</div><script>${read(marketing?'convocatoria_notificaciones.js':'recordatorios.js')}</script><script>${read('telefonia_notificaciones.js')}</script>`;
  await page.route('http://campana.test/**',async route=>{
   const u=new URL(route.request().url());
   if(u.pathname==='/list'){listed++;return route.fulfill({json:{ok:true,total:1,no_leidas:1,notificaciones:[notice],recordatorios:[notice]}});}
   if(u.pathname==='/read'){readBody=route.request().postData();return route.fulfill({json:{ok:true}});}
   return route.fulfill({contentType:'text/html',body:u.pathname==='/'?html:'Historial'});
  });
  await page.goto('http://campana.test/');
  const item=page.locator('.topbar-reminder-item[data-telefonia-id="15"]');await item.waitFor();assert.match(await item.textContent(),/Transferencia sin respuesta/);
  const toast=page.locator(marketing?'.convocatoria-activation-toast[data-telefonia-id="15"]':'.reminder-toast[data-telefonia-id="15"]');
  assert.equal(await toast.count(),0,'los avisos telefónicos no deben salir inmediatamente al ingresar');
  await page.clock.runFor(8*60*1000+200);
  await toast.waitFor({state:'visible'});
  assert.equal(await page.locator('.reminder-toast,.convocatoria-activation-toast').count(),1,'un solo aviso por turno');
  await page.evaluate(()=>window.dispatchEvent(new Event('impe:phone-notices-refresh')));
  await page.clock.runFor(10000);await toast.waitFor({state:'detached'});
  assert.equal(await item.count(),1,'cerrar el toast no elimina la campana');assert.equal(readBody,'','el cierre automático no marca leído');
  await page.reload();await item.waitFor();assert.equal(await toast.count(),0,'no reaparece al cambiar de página o recargar');
  notice={...notice,id:-16,telefonia_id:16,titulo:'Llamada por devolver',etiqueta:'Por devolver'};
  await page.evaluate(()=>window.dispatchEvent(new Event('impe:phone-notices-refresh')));
  await page.locator('.topbar-reminder-item[data-telefonia-id="16"]').waitFor();
  const nextToast=page.locator(marketing?'.convocatoria-activation-toast[data-telefonia-id="16"]':'.reminder-toast[data-telefonia-id="16"]');
  assert.equal(await nextToast.count(),0,'el aviso nuevo respeta el turno pendiente');
  // Después de recargar ya han transcurrido 10 s desde el aviso anterior.
  // Avanzar ocho minutos completos sobrepasaría su ventana de visibilidad.
  const restante=await page.evaluate(()=>{
   const clave='recordatorios:ultimo-toast-global-at:usuario:'+Number(window.IMPE_CURRENT_USER_ID||0);
   const ultimo=Number(sessionStorage.getItem(clave)||0);
   return ultimo>0?Math.max(1000,8*60*1000-(Date.now()-ultimo))+300:8*60*1000+300;
  });
  await page.clock.runFor(restante);
  await nextToast.waitFor({state:'visible'});
  await nextToast.click();await page.waitForURL('**/historial');
  assert.match(readBody,/id=16/);assert.match(readBody,/csrf_token=csrf-own/);assert.ok(listed>=1);assert.deepEqual(errors,[]);await page.close();
 }

 // Inicio del analista: un error transitorio no debe dejar la campana ni /25 congelados.
 {
  const page=await browser.newPage();await page.clock.install();
  let lecturas=0,resumenes=0;const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const html=`<!doctype html><style>${fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'))}</style>
   <script>${fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'))}</script>
   <script>window.IMPE_CURRENT_USER_ID=8;</script>
   <div data-reminder-root data-reminder-endpoint="/list">
    <span data-reminder-badge class="d-none">0</span><div data-reminder-content>Actualizando notificaciones</div>
   </div>
   <div data-topbar-call-goal data-endpoint="/goal">
    <strong data-topbar-call-effective>—</strong><b data-topbar-call-effective-menu>—</b>
    <strong data-topbar-call-total>—</strong><strong data-topbar-call-contact>—</strong>
    <strong data-topbar-call-remaining>—</strong><span data-topbar-call-progress></span>
    <span data-topbar-call-status>Actualizando avance…</span>
   </div>
   <script>${read('recordatorios.js')}</script>
   <script>${read('topbar_llamadas_analista.js')}</script>`;
  await page.route('http://inicio-analista.test/**',async route=>{
   const u=new URL(route.request().url());
   if(u.pathname==='/list'){
    lecturas++;
    if(lecturas===1)return route.fulfill({status:503,json:{ok:false,mensaje:'Temporal'}});
    return route.fulfill({json:{ok:true,recordatorios:[{telefonia_id:21,id:-21,nombre_entidad:'Llamada por devolver',accion:'Revisar llamada',estado:'informativa',leida:0,url:'/mis-llamadas'}],total:1,avisos:[]}});
   }
   if(u.pathname==='/goal'){
    resumenes++;
    if(resumenes===1)return route.fulfill({status:503,json:{ok:false,mensaje:'Temporal'}});
    return route.fulfill({json:{ok:true,resumen:{meta:25,verificaciones_efectivas:7,llamadas_realizadas:11,llamadas_con_contacto:8}}});
   }
   return route.fulfill({contentType:'text/html',body:html});
  });
  await page.goto('http://inicio-analista.test/');
  await page.waitForFunction(()=>document.querySelector('[data-reminder-content]')?.textContent.includes('Reintentando automáticamente'));
  assert.equal(await page.locator('[data-topbar-call-effective]').textContent(),'—','no inventar cifras mientras falla la consulta');
  assert.equal(await page.locator('.reminder-toast').count(),0,'sin ráfaga al inicio');
  await page.clock.runFor(5300);
  await page.locator('[data-reminder-badge]').waitFor({state:'visible'});
  assert.equal(await page.locator('[data-reminder-badge]').textContent(),'1','la campana recupera el pendiente');
  assert.equal(await page.locator('[data-topbar-call-effective]').textContent(),'7','la meta se hidrata sin abrir el menú');
  assert.equal(await page.locator('[data-topbar-call-total]').textContent(),'11');
  assert.ok(lecturas>=2&&resumenes>=2,'ambos componentes hicieron reintento');
  assert.equal(await page.locator('.reminder-toast').count(),0,'el reintento no anticipa el aviso flotante');
  assert.deepEqual(errors,[]);
  await page.close();
 }
 console.log('Inicio analista: reintento automático de campana y actividad telefónica: OK');

 console.log('Campanas analista/marketing: ocho minutos, turno único, autocierre, campana persistente, recarga y lectura con CSRF: OK');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
