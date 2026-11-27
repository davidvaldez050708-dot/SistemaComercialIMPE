// Código instalado y reloj controlado: sin MySQL, avisos reales ni lecturas de comentarios.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..'),deps=path.resolve(root,'../browser-legacy-deps/node_modules');
const {chromium}=require(path.join(deps,'playwright'));
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const start=Date.parse('2026-11-27T18:00:00Z'),minute=60000;
const css=fs.readFileSync(path.join(deps,'bootstrap/dist/css/bootstrap.min.css'),'utf8')+read('public/css/recordatorios.css');
const comment=(id,at,estado='normal')=>({id:0,agenda_comentario_id:id,agenda_comentario_evento:id+'|nuevo',
 agenda_autor_id:30,agenda_creado_at:(start-86400000)/1000,
 agenda_horario_at:at/1000,agenda_horario_mensaje:'Comentario '+id,nombre_entidad:'Comentario '+id,
 accion:'Revisar comentario '+id,estado,leida:0,fecha:new Date(at).toISOString(),url:'/agenda?comentario_id='+id});
async function fixture(browser,items,horarios=[],isolated=false){
 const page=await browser.newPage();await page.clock.install({time:new Date(start)});await page.clock.pauseAt(new Date(start+1));
 const errors=[],posts=[];page.on('pageerror',e=>errors.push(e.message));
 const html=`<!doctype html><meta charset="utf-8"><style>${css}</style><script>window.IMPE_CURRENT_USER_ID=30;
 window.Audio=class{addEventListener(){} pause(){} play(){return Promise.reject(Object.assign(new Error('Audio bloqueado'),{name:'NotAllowedError'}))}};
 </script><div data-reminder-root data-reminder-endpoint="/pendientes"><b data-reminder-badge></b><div data-reminder-content></div></div>
 <script src="/public/javascript/agenda_alertas.js"></script>${isolated?'':'<script src="/public/javascript/recordatorios.js"></script>'}`;
 await page.route('http://agenda.test/**',route=>{
  const url=new URL(route.request().url());
  if(route.request().method()==='POST')posts.push(url.pathname);
  if(url.pathname==='/pendientes')return route.fulfill({json:{ok:true,usuario_id:30,recordatorios:items,avisos:items.map(r=>({...r,titulo:r.nombre_entidad,mensaje:r.accion})),horarios,total:items.length}});
  if(url.pathname.endsWith('.js'))return route.fulfill({contentType:'application/javascript',body:read(url.pathname.slice(1))});
  return route.fulfill({contentType:'text/html',body:html});
 });
 await page.goto('http://agenda.test/inicio');
 if(!isolated)await page.waitForFunction(()=>window.IMPE_REMINDER_STATUS?.().loadedCount>0);
 return {page,errors,posts};
}
(async()=>{const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});try{
 // Los vencidos siguen en la campana pero no consumen ni bloquean el turno de otro módulo.
 const old=comment(1,start-3*86400000,'vencida'),stale=comment(2,start-minute);
 const legacy={...comment(3,start-minute,'vencida')};delete legacy.agenda_horario_at;
 const oldWithoutTime={...comment(7,start-86400000)};delete oldWithoutTime.agenda_horario_at;
 const following={id:90,seguimiento_id:90,nombre_entidad:'Seguimiento pendiente',accion:'Volver a llamar',estado:'vencida',fecha:'2026-11-26T10:00:00Z'};
 {
  const f=await fixture(browser,[old,stale,legacy,oldWithoutTime,following],[old,stale]),p=f.page;
  assert.equal(await p.locator('.topbar-reminder-item').count(),5);
  assert.equal(await p.locator('[data-reminder-toast-container] .toast').count(),0);
  await p.clock.runFor(8*minute+1);
  await p.locator('.reminder-toast').waitFor({state:'visible'});
  assert.match(await p.locator('.reminder-toast').innerText(),/Seguimiento pendiente/);
  assert.equal(await p.locator('.topbar-reminder-item').count(),5,'No borrar ni marcar leídos');
  assert.deepEqual(f.posts,[]);assert.deepEqual(f.errors,[]);await p.close();
 }
 // El aviso de lectura es un evento distinto: no eliminarlo por la antigüedad del comentario.
 {
  const receipt={...oldWithoutTime,agenda_comentario_visto_por:31,nombre_entidad:'Tu comentario ya fue visto'};
  const f=await fixture(browser,[receipt]),p=f.page;
  await p.locator('.agenda-comment-toast').waitFor({state:'visible'});
  assert.match(await p.locator('.agenda-comment-toast').innerText(),/Tu comentario ya fue visto/);
  assert.deepEqual(f.posts,[]);assert.deepEqual(f.errors,[]);await p.close();
 }
 // El anticipo futuro se presenta y desaparece; pasado su horario deja de anunciarse.
 {
  const upcoming=comment(4,start+10*minute),f=await fixture(browser,[upcoming],[upcoming]),p=f.page;
  await p.locator('.agenda-comment-toast').waitFor({state:'visible'});
  assert.match(await p.locator('.agenda-comment-toast').innerText(),/En 10 minutos/);
  await p.clock.runFor(6601);assert.equal(await p.locator('.agenda-comment-toast').count(),0);
  await p.clock.fastForward(11*minute);await p.clock.runFor(1000);
  assert.equal(await p.locator('.agenda-comment-toast').count(),0,'No anunciar horario pasado');
  assert.equal(await p.locator('.topbar-reminder-item').count(),1);assert.deepEqual(f.posts,[]);assert.deepEqual(f.errors,[]);await p.close();
 }
 // Un anticipo que esperaba su turno caduca dentro de la cola; reuniones conservan su aviso de inicio.
 {
  const f=await fixture(browser,[],[],true),p=f.page;
  await p.evaluate(item=>{
   window.shown=[];window.accept=false;
   IMPEAgendaAlerts.setPresenter(aviso=>{if(!accept)return false;shown.push(aviso);return true});
   IMPEAgendaAlerts.ingest({usuario_id:30,horarios:[item],recordatorios:[]});
  },comment(5,start+minute));
  await p.clock.fastForward(minute+1000);
  await p.evaluate(()=>{accept=true});await p.clock.runFor(1000);
  assert.equal(await p.evaluate(()=>shown.length),0,'Retirar comentario vencido de la cola');
  await p.evaluate(()=>IMPEAgendaAlerts.ingest({usuario_id:30,horarios:[{reunion_id:6,agenda_horario_at:(Date.now()-30000)/1000,agenda_horario_mensaje:'Reunión válida'}]}));
  await p.waitForFunction(()=>shown.length===1);assert.equal(await p.evaluate(()=>shown[0].reunion_id),6);
  assert.deepEqual(f.errors,[]);await p.close();
 }
 console.log('PASS: comentarios vencidos excluidos del toast; campana intacta; próximo conserva aviso; cola caducada retirada; reuniones y Seguimiento conservados; cero escrituras.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
