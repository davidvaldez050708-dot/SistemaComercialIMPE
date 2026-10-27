// Navegador real, red simulada y reloj controlado: no llama ni modifica el CRM real.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const repo=path.resolve(__dirname,'..');
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.dirname(require.resolve('bootstrap/package.json'));
const read=name=>fs.readFileSync(path.join(repo,'public/javascript',name),'utf8');
const source=process.env.IMPE_REMINDER_TEST_SOURCE?fs.readFileSync(process.env.IMPE_REMINDER_TEST_SOURCE,'utf8'):read('recordatorios.js');
const css=fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'),'utf8')+'\n'+fs.readFileSync(path.join(repo,'public/css/recordatorios.css'),'utf8');
const minute=60000, period=8*minute;
const notices=[
 {id:-71,telefonia_id:71,fecha:'2026-10-18 12:30:00',leida:0,nombre_entidad:'Transferencia sin confirmación',titulo:'Transferencia sin confirmación',accion:'Revisa el historial',mensaje:'Revisa el historial',estado:'informativa',url:'/destino?aviso=71'},
 {id:-72,telefonia_id:72,fecha:'2026-10-18 12:31:00',leida:0,nombre_entidad:'Llamada por devolver',titulo:'Llamada por devolver',accion:'Recepción dejó datos',mensaje:'Recepción dejó datos',estado:'informativa',url:'/destino?aviso=72'}
];
const following={id:9,seguimiento_id:9,nombre_entidad:'Institución Uno',accion:'Volver a llamar',fecha:'2026-01-01 08:00:00',estado:'vencida',url:'/seguimiento?id=9'};
async function fixture(browser,options={}){
 const page=await browser.newPage({viewport:{width:1366,height:900}});await page.clock.install({time:new Date('2026-10-18T18:00:00Z')});
 await page.clock.pauseAt(new Date('2026-10-18T18:00:01Z'));
 const errors=[],reads=[],shown=[];let listCalls=0,items=options.items||notices,avisos=options.avisos||[];
 page.on('pageerror',e=>errors.push(e.message));
 const html=()=>`<!doctype html><html><head><meta charset="utf-8"><style>${css}</style>
 ${options.bootstrap?'<script>'+fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'))+'</script>':''}
 <script>window.IMPE_CURRENT_USER_ID=Number(new URL(location.href).searchParams.get('usuario')||8);</script></head><body>
 <header data-phone-notification-read="/leer" data-phone-notification-csrf="csrf-8">
 <div data-reminder-root data-reminder-endpoint="/pendientes"><span data-reminder-badge class="d-none">0</span><div data-reminder-content></div></div></header>
 <main><h1>CRM: ${options.name||'Inicio'}</h1><a href="/seguimiento">Seguimiento</a><a href="/mis-llamadas">Mis llamadas</a></main>
 <script>${read('telefonia_notificaciones.js')}</script><script>${source}</script></body></html>`;
 await page.route('http://toasts.test/**',async route=>{
  const u=new URL(route.request().url());
  if(u.pathname==='/pendientes'){listCalls++;return route.fulfill({json:{ok:true,requiere_migracion:options.partial||false,total:items.length,recordatorios:items,avisos}});}
  if(u.pathname==='/leer'){reads.push(route.request().postData());return route.fulfill({json:{ok:true}});}
  return route.fulfill({contentType:'text/html',body:html()});
 });
 await page.goto('http://toasts.test/inicio');
 await page.locator('.topbar-reminder-item').first().waitFor();
 if(items.some(n=>n.telefonia_id))assert.ok(await page.locator('.topbar-reminder-item time').count()>0,'phone notices include date and time');
 await page.exposeFunction('toastObserved',value=>shown.push(value));
 await page.evaluate(()=>new MutationObserver(ms=>{
  for(const m of ms)for(const n of m.addedNodes)if(n.nodeType===1&&n.matches('.reminder-toast'))window.toastObserved({at:Date.now(),id:n.dataset.telefoniaId||'',text:n.textContent});
 }).observe(document.body,{childList:true,subtree:true}));
 return {page,errors,reads,shown,setItems(value){items=value},setAvisos(value){avisos=value},get calls(){return listCalls}};
}
const count=page=>page.locator('.reminder-toast').count();
const status=page=>page.evaluate(()=>window.IMPE_REMINDER_STATUS?.());
const refresh=async page=>{
 const before=(await status(page)).loadedCount;
 await page.evaluate(()=>window.dispatchEvent(new Event('impe:phone-notices-refresh')));
 await page.waitForFunction(before=>window.IMPE_REMINDER_STATUS().loadedCount>before,before);
};
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 let cases=0;
 try{
  // Dos pendientes al entrar; 4 + navegación + 4; cierre, rotación, lectura y navegación.
  for(const withBootstrap of [false,true]){
   const f=await fixture(browser,{bootstrap:withBootstrap});const p=f.page;
   assert.equal(await p.locator('[data-reminder-badge]').textContent(),'2');assert.equal(await count(p),0);
   let s=await status(p);assert.ok(s?.initialized&&s.timerActive&&s.startedAt>0,'inicio real y temporizador verificables');
   assert.equal(s.bootstrapAvailable,withBootstrap);const start=s.startedAt;
   await p.clock.runFor(4*minute);await p.getByRole('link',{name:'Seguimiento',exact:true}).click();
   await p.locator('.topbar-reminder-item').first().waitFor();assert.equal((await status(p)).startedAt,start);
   await p.clock.runFor(4*minute-1);assert.equal(await count(p),0);
   await p.clock.runFor(1);const toast=p.locator('.reminder-toast[data-telefonia-id="71"]');await toast.waitFor({state:'visible'});
   assert.equal(await count(p),1);assert.equal((await status(p)).lastShownAt,start+period);
   const box=await toast.boundingBox();assert.ok(box.x>900&&box.y<180,'toast del CRM en la esquina superior derecha');
   await p.clock.runFor(7999);assert.equal(await count(p),1);await p.clock.runFor(1);assert.equal(await count(p),0);
   assert.equal(f.reads.length,0);assert.equal(await p.locator('.topbar-reminder-item').count(),2);
   await p.getByRole('link',{name:'Mis llamadas',exact:true}).click();await p.locator('.topbar-reminder-item').first().waitFor();
   await p.clock.runFor(period-8000);const next=p.locator('.reminder-toast[data-telefonia-id="72"]');await next.waitFor({state:'visible'});
   assert.equal(await count(p),1);await next.click();await p.waitForURL('**/destino?aviso=72');
   assert.equal(f.reads.length,1);assert.match(f.reads[0],/id=72/);assert.match(f.reads[0],/csrf_token=csrf-8/);
   assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  // Seguimiento y telefonía usan una sola rotación, incluso con entradas repetidas.
  {
   const f=await fixture(browser,{items:[...notices,notices[0],following]});const p=f.page;
   for(const id of ['71','72','']){
    const last=(await status(p)).lastShownAt|| (await status(p)).startedAt;
    const left=await p.evaluate(({last,period})=>Math.max(0,last+period-Date.now()),{last,period});await p.clock.runFor(left+1);
    await p.locator('.reminder-toast').waitFor({state:'visible'});assert.equal(await count(p),1);
    assert.equal(await p.locator('.reminder-toast').getAttribute('data-telefonia-id')||'',id);
    if(!id)assert.match(await p.locator('.reminder-toast').textContent(),/Acción vencida.*Institución Uno/);
    await p.clock.runFor(8000);
   }
   assert.equal(f.reads.length,0);assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  // Un toast oculto por CSS no consume el turno ni la posición de rotación.
  {
   const f=await fixture(browser),p=f.page;
   const style=await p.addStyleTag({content:'.reminder-toast{display:none!important}'});
   await p.clock.runFor(period+1);assert.equal(await count(p),0);assert.equal((await status(p)).lastShownAt,0);
   assert.equal(await p.evaluate(()=>sessionStorage.getItem('recordatorios:ultimo-toast-vencido-id:usuario:8')),null);
   await style.evaluate(e=>e.remove());await refresh(p);await p.clock.runFor(1);
   await p.locator('.reminder-toast[data-telefonia-id="71"]').waitFor({state:'visible'});
   assert.ok((await status(p)).lastShownAt>0);assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  // Pestaña oculta: sin consumo. Al volver se utiliza el turno ya vencido.
  {
   const f=await fixture(browser),p=f.page;
   await p.evaluate(()=>Object.defineProperty(document,'hidden',{get:()=>window.testHidden===true,configurable:true}));
   await p.evaluate(()=>{window.testHidden=true;document.dispatchEvent(new Event('visibilitychange'));});
   await p.clock.runFor(period+minute);assert.equal(await count(p),0);assert.equal((await status(p)).lastShownAt,0);
   await p.evaluate(()=>{window.testHidden=false;document.dispatchEvent(new Event('visibilitychange'));});await p.clock.runFor(1);
   await p.locator('.reminder-toast').waitFor({state:'visible'});assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  // Avisos futuros de Seguimiento se encolan; navegar conserva el aviso y el reloj.
  {
   const upcoming={...following,estado:'proxima',fecha:'2026-10-18 12:10:00'};
   const aviso={...upcoming,tipo:'10M',titulo:'En aproximadamente 10 minutos',mensaje:'Volver a llamar · Institución Uno'};
   const f=await fixture(browser,{items:[upcoming],avisos:[aviso]}),p=f.page;
   assert.equal(await count(p),0);f.setAvisos([]);await p.clock.runFor(4*minute);await p.goto('http://toasts.test/mis-llamadas');
   await p.locator('.topbar-reminder-item').waitFor();await p.clock.runFor(4*minute+1);
   await p.locator('.reminder-toast').waitFor({state:'visible'});assert.match(await p.locator('.reminder-toast').textContent(),/10 minutos/);
   await p.clock.runFor(period+1);assert.equal(await count(p),0,'aviso próximo no se repite sin nueva condición del backend');
   assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  // Claves antiguas/corruptas no bloquean el arranque; otro usuario tiene su reloj.
  {
   const f=await fixture(browser),p=f.page;await p.evaluate(()=>{
    sessionStorage.setItem('recordatorios:ultimo-toast-vencido-at','999999999999999');
    sessionStorage.setItem('recordatorios:inicio-toast-global-at:usuario:8','NaN');
    sessionStorage.setItem('recordatorios:ultimo-toast-global-at:usuario:8','999999999999999');
    sessionStorage.setItem('recordatorios:cola-toast-v1:usuario:8','{invalido');
   });await p.reload();await p.locator('.topbar-reminder-item').first().waitFor();await p.clock.runFor(period+1);
   await p.locator('.reminder-toast').waitFor({state:'visible'});
   await p.goto('http://toasts.test/inicio?usuario=9');await p.locator('.topbar-reminder-item').first().waitFor();
   assert.equal(await count(p),0);assert.equal((await status(p)).lastShownAt,0);assert.equal((await status(p)).userId,9);
   assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  // Script cargado tarde o dos veces: una sola instancia y un solo temporizador.
  {
   const f=await fixture(browser),p=f.page;
   await p.evaluate(()=>{delete document.querySelector('[data-reminder-root]').dataset.reminderInitialized;});
   // Una ventana nueva prueba la inicialización posterior a DOMContentLoaded sin una instancia previa.
   await p.route('http://toasts.test/tarde',r=>r.fulfill({contentType:'text/html',body:'<!doctype html><style>'+css+'</style><script>window.IMPE_CURRENT_USER_ID=8;</script><div data-reminder-root data-reminder-endpoint="/pendientes"><span data-reminder-badge></span><div data-reminder-content></div></div>'}));
   await p.goto('http://toasts.test/tarde');await p.addScriptTag({content:source});await p.locator('.topbar-reminder-item').first().waitFor();
   await p.addScriptTag({content:source});await p.clock.runFor(period+1);await p.locator('.reminder-toast').waitFor({state:'visible'});
   assert.equal(await count(p),1);assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  // Storage bloqueado: el reloj sigue operativo en memoria durante la página.
  {
   const f=await fixture(browser),p=f.page;
   await p.addInitScript(()=>Object.defineProperty(window,'sessionStorage',{get(){throw new DOMException('Bloqueado','SecurityError')}}));
   await p.reload();await p.locator('.topbar-reminder-item').first().waitFor();await p.clock.runFor(period+1);
   await p.locator('.reminder-toast').waitFor({state:'visible'});assert.equal((await status(p)).persistentStorage,false);
   assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  // datos.avisos no dispara ráfagas y la respuesta parcial conserva los avisos telefónicos.
  for(const partial of [false,true]){
   const f=await fixture(browser,{avisos:[...notices],partial}),p=f.page;
   assert.equal(await count(p),0);await p.clock.runFor(period+1);
   await p.locator('.reminder-toast[data-telefonia-id="71"]').waitFor({state:'visible'});
   assert.equal(await count(p),1);await p.clock.runFor(8000);
   assert.equal(await p.locator('.topbar-reminder-item').count(),2);assert.equal(f.reads.length,0);
   assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  // Ventas: leer no resuelve la tarea; las vencidas continúan cada ocho minutos.
  for(const kind of ['devolucion','programada']){
   const task={...notices[0],leida:1,tipo_evento:kind,pendiente_operativo:true,reiterar_hasta_atender:true,
     estado:'vencida',titulo:kind==='devolucion'?'Devolución vencida':'Llamada programada vencida',fecha_iso:'2026-10-17T23:59:59-06:00'};
   const f=await fixture(browser,{items:[task]}),p=f.page;
   await p.clock.runFor(period+1);await p.locator('.reminder-toast.is-overdue').waitFor({state:'visible'});
   assert.match(await p.locator('.reminder-toast').innerText(),/vencida/);
   assert.equal(f.reads.length,0,'El temporizador no registra lectura ni atención');
   await p.clock.runFor(period);await p.locator('.reminder-toast.is-overdue').waitFor({state:'visible'});
   assert.equal(f.shown.length,2,'La tarea leída reaparece al siguiente turno');
   f.setItems([]);await refresh(p);await p.clock.runFor(period);
   assert.equal(await count(p),0,'Atender la tarea detiene la reiteración');
   assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  console.log(`Toasts CRM: ${cases} escenarios de navegador aprobados; Bootstrap opcional, 4+4 minutos, ocho segundos, rotación, cola, lectura y sesión.`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
