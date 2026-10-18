const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const read=p=>fs.readFileSync(path.join(__dirname,'../public/javascript/',p),'utf8');
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 try{for(const marketing of [false,true]){
  const page=await browser.newPage();let readBody='',listed=0;const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const bell=marketing?'<div data-convocatoria-notification-root data-endpoint="/list"><span data-convocatoria-notification-badge></span><div data-convocatoria-notification-content></div></div>':'<div data-reminder-root data-reminder-endpoint="/list"><span data-reminder-badge></span><div data-reminder-content></div></div>';
  const notice={id:-15,telefonia_id:15,titulo:'Transferencia sin respuesta',nombre_entidad:'Transferencia sin respuesta',mensaje:'Recepción intentó comunicarte.',accion:'Recepción intentó comunicarte.',url:'/historial',fecha:'2026-10-18 10:17:00',created_at:'2026-10-18 10:17:00',estado:'informativa',etiqueta:'Sin respuesta',tipo:'TELEFONIA_PERDIDA'};
  const html=`<!doctype html><div data-phone-notification-read="/read" data-phone-notification-csrf="csrf-own">${bell}</div><script>${read(marketing?'convocatoria_notificaciones.js':'recordatorios.js')}</script><script>${read('telefonia_notificaciones.js')}</script>`;
  await page.route('http://campana.test/**',async route=>{
   const u=new URL(route.request().url());
   if(u.pathname==='/list'){listed++;return route.fulfill({json:{ok:true,total:1,no_leidas:1,notificaciones:[notice],recordatorios:[notice]}});}
   if(u.pathname==='/read'){readBody=route.request().postData();return route.fulfill({json:{ok:true}});}
   return route.fulfill({contentType:'text/html',body:u.pathname==='/'?html:'Historial'});
  });
  await page.goto('http://campana.test/');
  const item=page.locator('[data-telefonia-id="15"]');await item.waitFor();assert.match(await item.textContent(),/Transferencia sin respuesta/);
  await page.evaluate(()=>window.dispatchEvent(new Event('impe:phone-notices-refresh')));
  await page.waitForFunction(()=>document.querySelector('[data-telefonia-id]'));
  await item.click();await page.waitForURL('**/historial');
  assert.match(readBody,/id=15/);assert.match(readBody,/csrf_token=csrf-own/);assert.ok(listed>=1);assert.deepEqual(errors,[]);await page.close();
 }
 console.log('Campanas analista/marketing: aviso, refresco, lectura con CSRF e historial: OK');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
