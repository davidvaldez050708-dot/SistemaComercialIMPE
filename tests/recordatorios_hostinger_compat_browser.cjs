const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..');
const source=fs.readFileSync(path.join(root,'tools/recordatorios_comerciales_v3_hostinger/after/public/javascript/recordatorios.js'),'utf8');
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.resolve(root,'../browser-legacy-deps/node_modules/bootstrap');
const css=fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'),'utf8')+fs.readFileSync(path.join(root,'public/css/recordatorios.css'),'utf8');
const now=Date.parse('2026-11-18T18:00:00Z');

async function fixture(browser,{records=[],horarios=[]}={}){
 const context=await browser.newContext();const page=await context.newPage();
 await page.clock.install({time:new Date(now)});await page.clock.pauseAt(new Date(now+1));
 const state={records,horarios,avisos:[],posts:[],errors:[],block:null};
 page.on('pageerror',e=>state.errors.push(e.message));
 const html=`<!doctype html><style>${css}</style><script>window.IMPE_CURRENT_USER_ID=22;window.IMPE_CSRF_TOKEN='comentario-prueba';</script>
 <div data-reminder-root data-reminder-endpoint="/pendientes"><span data-reminder-badge></span><div data-reminder-content></div></div><script>${source}</script>`;
 await page.route('https://agenda-compat.test/**',async route=>{
  const req=route.request(),url=new URL(req.url());
  if(url.pathname==='/pendientes'){
   const data=JSON.parse(JSON.stringify({ok:true,total:state.records.length,recordatorios:state.records,avisos:state.avisos,horarios:state.horarios,servidor_at:now/1000}));
   if(state.block){const block=state.block;state.block=null;await block();}
   return route.fulfill({json:data});
  }
  if(req.method()==='POST'){
   assert.equal(req.headers()['x-csrf-token'],'comentario-prueba');
   const body=new URLSearchParams(req.postData());state.posts.push({action:url.searchParams.get('action'),id:body.get('id'),lector:body.get('lector_id')});
   state.records=[];return route.fulfill({json:{ok:true}});
  }
  return route.fulfill({body:html,contentType:'text/html'});
 });
 await page.goto('https://agenda-compat.test/crm');
 await page.waitForFunction(()=>window.IMPE_REMINDER_STATUS?.().loadedCount>0);
 return {context,page,state};
}
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROME_PATH||undefined});let cases=0;
 try{
  for(const minutos of [30,20,10,5,1]){
   const f=await fixture(browser,{horarios:[{reunion_id:7,agenda_horario_at:now/1000+minutos*60,agenda_horario_mensaje:'Reunión preservada',url:'/reunion?id=7'}]});
   const toast=f.page.locator('.reminder-toast');await toast.waitFor();
   assert.match(await toast.innerText(),new RegExp('En '+minutos+' minuto'));
   await f.page.clock.runFor(7999);assert.equal(await toast.count(),1);
   await f.page.clock.runFor(1);assert.equal(await toast.count(),0);
   assert.equal(f.state.posts.length,0);assert.deepEqual(f.state.errors,[]);await f.context.close();cases++;
  }
  // Recordatorio de un comentario conserva su presentación y duración propias.
  {
   const f=await fixture(browser,{horarios:[{agenda_comentario_id:11,agenda_horario_at:now/1000+600,agenda_horario_mensaje:'Comentario programado',url:'/agenda'}]});
   const toast=f.page.locator('.agenda-comment-toast');await toast.waitFor();
   assert.match(await toast.innerText(),/En 10 minutos/);
   await f.page.clock.runFor(2599);assert.equal(await toast.count(),1);
   await f.page.clock.runFor(1);assert.equal(await toast.count(),0);
   assert.deepEqual(f.state.errors,[]);await f.context.close();cases++;
  }
  for(const lector of [0,37]){
   const record={id:201,agenda_comentario_id:201,agenda_comentario_evento:'201|nuevo',agenda_solo_aviso:true,
    agenda_comentario_visto_por:lector,nombre_entidad:'Comentario de agenda',accion:'Revisar comentario',estado:'normal',
    url:'/index.php?controller=agendaReunion&action=index&comentario_id=201'};
   const f=await fixture(browser,{records:[record]});await f.page.locator('.agenda-comment-toast').waitFor();
   assert.equal(f.state.posts.length,0,'mostrar no confirma lectura');
   await f.page.locator('.topbar-reminder-item').click();
   await f.page.waitForFunction(()=>window.IMPE_REMINDER_STATUS().pendingCount===0);
   assert.deepEqual(f.state.posts,[{action:lector?'leerVisto':'leer',id:'201',lector:lector?'37':null}]);
   assert.equal(f.page.url(),'https://agenda-compat.test/crm','solo aviso no navega');
   assert.deepEqual(f.state.errors,[]);await f.context.close();cases++;
  }
  // Un aviso telefónico leído sigue reiterándose si su tarea continúa sin atender.
  {
   const f=await fixture(browser,{records:[{id:-9,telefonia_id:9,pendiente_operativo:true,reiterar_hasta_atender:true,
    leida:1,nombre_entidad:'Llamada pendiente',titulo:'Llamada por devolver',accion:'Devolver llamada',estado:'informativa',url:'/llamada'}]});
   await f.page.clock.runFor(480001);await f.page.locator('.reminder-toast').waitFor();
   assert.equal(f.state.posts.length,0);assert.deepEqual(f.state.errors,[]);await f.context.close();cases++;
  }
  // Una consulta descartada por un cambio comercial no pierde el aviso de sesión de Agenda.
  {
   const f=await fixture(browser);let release;
   const reunion={id:7,reunion_id:7,nombre_entidad:'Institución preservada',accion:'Reunión confirmada · enviar correo',fecha:'2026-11-19 12:00:00',estado:'proxima',url:'/agenda?id=7'};
   f.state.records=[reunion];f.state.avisos=[{...reunion,tipo:'REUNION_CONFIRMADA',titulo:'Reunión confirmada por Cuenta Clave',mensaje:'Confirmación preservada'}];
   f.state.block=()=>new Promise(resolve=>{release=resolve;});
   const loaded=await f.page.evaluate(()=>window.IMPE_REMINDER_STATUS().loadedCount);
   await f.page.evaluate(()=>window.dispatchEvent(new Event('impe:comercial-actividad-refresh')));
   await f.page.waitForTimeout(80);assert.equal(typeof release,'function');
   f.state.avisos=[];await f.page.evaluate(()=>window.dispatchEvent(new Event('impe:comercial-actividad-refresh')));release();
   await f.page.waitForFunction(n=>window.IMPE_REMINDER_STATUS().loadedCount>n,loaded);
   await f.page.clock.runFor(480001);await f.page.locator('.reminder-toast').waitFor();
   assert.match(await f.page.locator('.reminder-toast').innerText(),/Reunión confirmada por Cuenta Clave.*Confirmación preservada/s);
   assert.deepEqual(f.state.errors,[]);await f.context.close();cases++;
  }
  console.log('CHECK_OK Hostinger: '+cases+' escenarios de Agenda 30/20/10/5/1, ocho segundos, comentarios 2.6 s, lectura con CSRF y reiteración telefónica conservados.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
