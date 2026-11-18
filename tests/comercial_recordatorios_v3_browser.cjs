const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..');
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.resolve(root,'../browser-legacy-deps/node_modules/bootstrap');
const css=fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'),'utf8')+'\n'+fs.readFileSync(path.join(root,'public/css/recordatorios.css'),'utf8');
const source=fs.readFileSync(process.env.IMPE_REMINDER_TEST_SOURCE||path.join(root,'public/javascript/recordatorios.js'),'utf8');
const duration=Number(process.env.IMPE_REMINDER_TEST_DURATION||7000);
const base=Date.parse('2026-11-18T18:00:00Z'),period=480000;
const item=(ventana='3H',ciclo=10)=>({id:1,comercial_id:1,actividad_ciclo:ciclo,
 usuario_id:22,nombre_entidad:'Prospecto <prueba>',accion:'Revisar documentos',detalle:'Revisar certificado <script>sin ejecutar</script>.',
 fecha:'2026-11-18T21:00:00Z',fecha_texto:'18 nov 2026, 3:00 p. m. · hora de México',
 etiqueta:ventana==='VENCIDA'?'Vencida':'En 60 min',estado:ventana==='VENCIDA'?'vencida':'proxima',
 ventana,icono:'bi-calendar-event',url:'/expediente?id=1'});

async function fixture(browser,options={}){
 const context=await browser.newContext({timezoneId:options.timezone||'Asia/Tokyo'});
 const state={items:options.items||[item()],ok:true,calls:0,posts:0,block:null,old:null};
 const errors=[];
 const html=`<!doctype html><html><head><meta charset="utf-8"><style>${css}</style></head><body>
 <script>window.IMPE_CURRENT_USER_ID=22;</script><div data-reminder-root data-reminder-endpoint="/pendientes">
 <span data-reminder-badge></span><div class="topbar-reminder-menu"><div class="topbar-reminder-header"><strong>Notificaciones</strong></div><div data-reminder-content></div></div></div><main>CRM</main><script>${source}</script></body></html>`;
 await context.route('https://avisos.test/**',async route=>{
  const req=route.request(),url=new URL(req.url());if(req.method()!=='GET')state.posts++;
  if(url.pathname==='/pendientes'){
   state.calls++;const items=JSON.parse(JSON.stringify(state.items));
   if(state.block){const bloquear=state.block;state.block=null;await bloquear();}
   return route.fulfill({json:{ok:true,comercial_ok:state.ok,parcial:!state.ok,total:items.length,recordatorios:items,avisos:items}});
  }
  return route.fulfill({body:html,contentType:'text/html'});
 });
 const open=async()=>{
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.clock.install({time:new Date(base)});await page.clock.pauseAt(new Date(base+1));
  if(options.blockStorage)await page.addInitScript(()=>Object.defineProperty(window,'localStorage',{get(){throw new DOMException('Bloqueado','SecurityError');}}));
  await page.goto('https://avisos.test/crm');await page.waitForFunction(()=>window.IMPE_REMINDER_STATUS?.().loadedCount>0);
  return page;
 };
 const page=await open();
 const refresh=async(p=page)=>{
  const n=await p.evaluate(()=>window.IMPE_REMINDER_STATUS().loadedCount);
  await p.evaluate(()=>window.dispatchEvent(new CustomEvent('impe:comercial-actividad-refresh',{detail:{usuarioId:22}})));
  await p.waitForFunction(n=>window.IMPE_REMINDER_STATUS().loadedCount>n,n);
 };
 return {context,page,state,errors,open,refresh};
}
const toast=p=>p.locator('.reminder-toast');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROME_PATH||undefined});
 let casos=0;
 try{
  for(const ventana of ['3H','1H','10M','VENCIDA']){
   const f=await fixture(browser,{items:[item(ventana)]}),p=f.page;
   assert.equal(await p.locator('.topbar-reminder-item').count(),1);
   assert.match(await p.locator('.topbar-reminder-copy').innerText(),/Revisar documentos.*\n.*certificado/);
   assert.match(await p.locator('.topbar-reminder-copy time').innerText(),/3:00 p. m.*México/);
   assert.equal(await p.locator('script').count(),2,'detalle escapado');
   await p.clock.runFor(period+1);
   await toast(p).waitFor({state:'visible',timeout:3000});
   assert.match(await toast(p).innerText(),/Prospecto <prueba>.*Revisar documentos/);
   await p.clock.runFor(duration-1);assert.equal(await toast(p).count(),1);
   await p.clock.runFor(1);assert.equal(await toast(p).count(),0);
   assert.equal(await p.locator('.topbar-reminder-item').count(),1,'caducar toast no completa la tarea');
   await p.clock.runFor(period-duration);
   assert.equal(await toast(p).count(),ventana==='VENCIDA'?1:0,'anticipación única, vencida mantiene cadencia');
   assert.equal(f.state.posts,0);assert.deepEqual(f.errors,[]);await f.context.close();casos++;
  }
  // Una nota no crea otro ciclo; una reprogramación sí, y retira el aviso viejo visible.
  {
   const f=await fixture(browser),p=f.page;await p.clock.runFor(period+1);await toast(p).waitFor();
   f.state.items[0].detalle='Nota actualizada sin cambiar la tarea.';await f.refresh();
   await toast(p).locator('button').click();assert.equal(await p.locator('.topbar-reminder-item').count(),1);
   await p.clock.runFor(period);assert.equal(await toast(p).count(),0);
   f.state.items=[item('1H',20)];await f.refresh();await p.clock.runFor(1);await toast(p).waitFor();
   f.state.items=[{...item('',21),fecha:'2026-11-19T16:00:00Z'}];await f.refresh();
   assert.equal(await toast(p).count(),0,'reprogramar retira toast del ciclo anterior');
   f.state.items=[];await f.refresh();assert.equal(await p.locator('.topbar-reminder-item').count(),0);
   await p.clock.runFor(period);assert.equal(await toast(p).count(),0);
   assert.equal(f.state.posts,0);assert.deepEqual(f.errors,[]);await f.context.close();casos++;
  }
  // Una respuesta anterior a completar no puede revivir la tarea en campana.
  {
   const f=await fixture(browser),p=f.page;let release;
   f.state.block=()=>new Promise(resolve=>{release=resolve;});
   await p.evaluate(()=>window.dispatchEvent(new Event('impe:comercial-actividad-refresh')));
   await p.waitForTimeout(100);assert.equal(typeof release,'function');
   f.state.items=[];await p.evaluate(()=>window.dispatchEvent(new Event('impe:comercial-actividad-refresh')));
   release();await p.waitForFunction(()=>window.IMPE_REMINDER_STATUS().pendingCount===0);
   assert.equal(await p.locator('.topbar-reminder-item').count(),0);
   assert.deepEqual(f.errors,[]);await f.context.close();casos++;
  }
  // Fallo parcial: conservar la última lectura válida y recuperar al reintentar.
  {
   const f=await fixture(browser),p=f.page;f.state.ok=false;f.state.items=[];
   await p.evaluate(()=>window.dispatchEvent(new Event('impe:comercial-actividad-refresh')));
   await p.waitForTimeout(80);assert.equal(await p.locator('.topbar-reminder-item').count(),1);
   f.state.ok=true;await f.refresh();assert.equal(await p.locator('.topbar-reminder-item').count(),0);
   assert.deepEqual(f.errors,[]);await f.context.close();casos++;
  }
  // Una pestaña oculta no consume turno. Sin localStorage se conserva memoria local.
  for(const blockStorage of [false,true]){
   const f=await fixture(browser,{blockStorage}),p=f.page;
   await p.evaluate(()=>Object.defineProperty(document,'hidden',{get:()=>window.oculto===true,configurable:true}));
   await p.evaluate(()=>{window.oculto=true;document.dispatchEvent(new Event('visibilitychange'));});
   await p.clock.runFor(period);assert.equal(await toast(p).count(),0);
   const cargadas=await p.evaluate(()=>window.IMPE_REMINDER_STATUS().loadedCount);
   await p.evaluate(()=>{window.oculto=false;document.dispatchEvent(new Event('visibilitychange'));});
   await p.waitForFunction(n=>window.IMPE_REMINDER_STATUS().loadedCount>n,cargadas);
   await p.clock.runFor(1);await toast(p).waitFor();await p.clock.runFor(duration);
   await p.clock.runFor(period);assert.equal(await toast(p).count(),0);
   assert.deepEqual(f.errors,[]);await f.context.close();casos++;
  }
  // Dos pestañas simultáneas: mismo origen, misma ventana, un solo aviso visible.
  {
   const f=await fixture(browser),p=f.page,otra=await f.open();
   assert.equal(await p.evaluate(()=>Boolean(navigator.locks)),true);
   await Promise.all([p.clock.runFor(period+1),otra.clock.runFor(period+1)]);
   await p.waitForTimeout(60);
   assert.equal(await toast(p).count()+await toast(otra).count(),1);
   assert.deepEqual(f.errors,[]);await f.context.close();casos++;
  }
  // La campana conserva todas las tareas y sigue siendo usable en móvil.
  {
   const items=Array.from({length:85},(_,i)=>({...item('',i+1),id:i+1,comercial_id:i+1}));
   const f=await fixture(browser,{items}),p=f.page;assert.equal(await p.locator('.topbar-reminder-item').count(),85);
   await p.setViewportSize({width:390,height:844});
   assert.equal(await p.locator('[data-reminder-badge]').innerText(),'9+');
   assert.deepEqual(f.errors,[]);await f.context.close();casos++;
  }
  console.log('CHECK_OK navegador comercial: '+casos+' escenarios; tiempos heredados, duración institucional, ciclo estable, sin avisos obsoletos, errores, pestañas, storage y 85 tareas.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
