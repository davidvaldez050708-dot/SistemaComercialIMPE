'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.dirname(require.resolve('bootstrap/package.json'));
const script=p=>fs.readFileSync(path.join(__dirname,'../public/javascript',p),'utf8');
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 try{
 for(const marketing of [false,true]){
  let read=0,readAll=0,leida=0,many=false;const errors=[];
  const page=await browser.newPage();await page.clock.install();
  page.on('pageerror',e=>errors.push(e.message));
  const button=marketing
   ? '<button data-convocatoria-notification-read-all>Marcar leídas</button>'
   : '<button data-phone-notification-read-all data-phone-notification-read-all-url="/read-all">Marcar llamadas leídas</button>';
  const shell=marketing
   ? '<div data-convocatoria-notification-root data-endpoint="/list" data-read-endpoint="/read" data-read-all-endpoint="/read-all" data-csrf-token="session-csrf"><span data-convocatoria-notification-badge></span>'+button+'<div data-convocatoria-notification-content></div></div>'
   : '<div data-reminder-root data-reminder-endpoint="/list"><span data-reminder-badge></span>'+button+'<div data-reminder-content></div></div>';
  const html=`<!doctype html><meta charset="UTF-8">
    <style>${fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'))}</style>
    <script>${fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'))}</script>
    <script>window.IMPE_CURRENT_USER_ID=8;</script>
    <div data-phone-notification-read="/read" data-phone-notification-csrf="session-csrf">${shell}</div>
    <script>${script(marketing?'convocatoria_notificaciones.js':'recordatorios.js')}</script>
    <script>${script('telefonia_notificaciones.js')}</script>`;
  const phone=()=>({id:-23,telefonia_id:23,leida,pendiente_operativo:true,
      titulo:'Llamada por devolver',nombre_entidad:'Llamada por devolver',
      mensaje:'Devolución pendiente',accion:'Devolución pendiente',
      created_at:'2026-10-23 08:00:00',fecha:'2026-10-23 08:00:00',
      url:'/devoluciones',icono:'bi-telephone-forward',etiqueta:'Por devolver',estado:'informativa'});
  const group=()=>many?Array.from({length:18},(_,i)=>({...phone(),id:-23-i,telefonia_id:23+i})): [phone()];
  await page.route('http://avisos-qa.test/**',route=>{
   const u=new URL(route.request().url());
   if(u.pathname==='/list')return route.fulfill({json:{
     ok:true,total:many?18:1,no_leidas:many?18:1,recordatorios:group(),notificaciones:group(),avisos:[]}});
   if(u.pathname==='/read-all'){
     ++readAll;leida=1;
     assert.match(route.request().postData()||'',/csrf_token=session-csrf/);
     return route.fulfill({json:{ok:true}});
   }
   if(u.pathname==='/read'){
     assert.match(route.request().postData()||'',/csrf_token=session-csrf/);
     ++read;leida=1;return route.fulfill({json:{ok:true}});
   }
   return route.fulfill({contentType:'text/html',body:u.pathname==='/'?html:'Detalle de devolución'});
  });
  await page.goto('http://avisos-qa.test/');
  const row=page.locator('.topbar-reminder-item[data-telefonia-id="23"]');
  await row.waitFor();
  assert.equal(await row.count(),1,'Tarea inicial aparece');
  const btn=page.locator(marketing?'[data-convocatoria-notification-read-all]':'[data-phone-notification-read-all]');
  await btn.click();
  await page.waitForFunction(()=>!!document.querySelector('.topbar-reminder-item[data-telefonia-id="23"]:not(.is-unread)'));
  assert.equal(readAll,1,'Marcar todas no duplica solicitudes');
  assert.equal(await row.count(),1,'Leer no elimina la devolución sin atender');
  const badge=page.locator(marketing?'[data-convocatoria-notification-badge]':'[data-reminder-badge]');
  assert.equal((await badge.textContent()).trim(),'1','La obligación sigue contándose como pendiente');
  await page.clock.runFor(8*60*1000+500);
  assert.equal(await page.locator('.reminder-toast[data-telefonia-id="23"],.convocatoria-activation-toast[data-telefonia-id="23"]').count(),0,
    'Leída no produce toasts telefónicos recurrentes');
  await row.click();
  await page.waitForURL('**/devoluciones');
  assert.equal(read,1,'Abrir expediente verifica lectura propia');
  many=true;
  await page.goto('http://avisos-qa.test/');
  await page.waitForFunction(()=>document.querySelectorAll('.topbar-reminder-item[data-telefonia-id]').length===18);
  assert.equal(await page.locator('.topbar-reminder-item[data-telefonia-id]').count(),18,
    'Campana puede mostrar más de doce obligaciones sin recortes del navegador');
  assert.deepEqual(errors,[],'Sin errores frontend');
  await page.close();
 }
 console.log('OK: Marketing, Analista y Ventas: leer conserva obligaciones, evita toast, admite navegación');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
