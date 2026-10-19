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
  await page.clock.runFor(8*60*1000+200);
  await nextToast.waitFor({state:'visible'});
  await nextToast.click();await page.waitForURL('**/historial');
  assert.match(readBody,/id=16/);assert.match(readBody,/csrf_token=csrf-own/);assert.ok(listed>=1);assert.deepEqual(errors,[]);await page.close();
 }
 console.log('Campanas analista/marketing: ocho minutos, turno único, autocierre, campana persistente, recarga y lectura con CSRF: OK');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
