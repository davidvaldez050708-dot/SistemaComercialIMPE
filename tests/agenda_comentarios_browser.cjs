// Navegador real y peticiones simuladas. Nunca accede al CRM publicado.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..');
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.dirname(require.resolve('bootstrap/package.json'));
const php=process.env.IMPE_TEST_PHP||'C:/xampp/php/php.exe';
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const reminders=process.env.IMPE_REMINDER_TEST_SOURCE?fs.readFileSync(process.env.IMPE_REMINDER_TEST_SOURCE,'utf8'):read('public/javascript/recordatorios.js');
const out=path.join(root,'tmp/agenda_comentarios');fs.mkdirSync(out,{recursive:true});
const view=cp.execFileSync(php,[path.join(root,'tests/fixtures/agenda_comentarios_vista.php')],{encoding:'utf8'});
const style=fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'),'utf8')+['global.css','dashboard.css','agenda_reunion.css','agenda_reunion_refinamientos.css','agenda_comentarios.css','recordatorios.css'].map(f=>read('public/css/'+f)).join('\n');
const boot=fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),'utf8');
function html(){return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+style+'body{margin:0;padding:20px}body>main{max-width:1450px;margin:auto}</style><script>'+boot+'</script><script>window.IMPE_CURRENT_USER_ID=5;window.IMPE_CSRF_TOKEN="qa-csrf";</script></head><body><main>'+view+'</main><script>'+read('public/javascript/agenda_reunion.js')+'</script></body></html>';}
const initial=[{id:1,fecha:'2026-10-14',hora:null,comentario:'Comentario de otra persona',autor:'Cuenta Clave',creado_at:'2026-10-14 10:30:00',puede_eliminar:false,leido:false,atendido:false}];
async function pageFixture(browser,options={}){
 const page=await browser.newPage({viewport:options.mobile?{width:390,height:844}:{width:1450,height:980},timezoneId:'America/Mexico_City'});
 let notes=structuredClone(initial),nextId=2;const posts=[],errors=[];let fail=false;
 if(options.read)notes.forEach(c=>c.leido=true);
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',async route=>{
  const request=route.request(),u=new URL(request.url());
  if(u.searchParams.get('controller')==='agendaComentario'){
   const action=u.searchParams.get('action');
   if(action==='listar')return route.fulfill({status:options.unavailable?503:200,json:options.unavailable?{ok:false,mensaje:'Los comentarios de la agenda aún no están disponibles.'}:{ok:true,comentarios:notes,hay_mas:false}});
   const body=Object.fromEntries(new URLSearchParams(request.postData()||''));posts.push({action,body,csrf:request.headers()['x-csrf-token']});
   if(fail)return route.fulfill({status:500,json:{ok:false,mensaje:'Fallo de prueba; conserva el comentario.'}});
   if(action==='crear'){
    await new Promise(resolve=>setTimeout(resolve,150));
    const c={id:nextId++,fecha:body.fecha,hora:body.hora||null,comentario:body.comentario,autor:'Analista de Prueba',creado_at:'2026-10-28 10:00:00',puede_eliminar:true,leido:false,atendido:false};notes.push(c);
    return route.fulfill({json:{ok:true,comentario:c,mensaje:'Comentario añadido.'}});
   }
   if(action==='eliminar')notes=notes.filter(n=>n.id!==Number(body.id));
   if(action==='leer')notes.forEach(n=>{if(n.id===Number(body.id))n.leido=true;});
   return route.fulfill({json:{ok:true}});
  }
  if(u.pathname.includes('/public/javascript/agenda_comentarios.js'))return route.fulfill({contentType:'application/javascript',body:read('public/javascript/agenda_comentarios.js')});
  if(u.pathname.includes('/public/css/agenda_comentarios.css'))return route.fulfill({contentType:'text/css',body:read('public/css/agenda_comentarios.css')});
  if(request.method()!=='GET')throw Error('Se intentó modificar una reunión: '+request.url());
  if(u.hostname==='agenda-comments.test')return route.fulfill({contentType:'text/html',body:html()});
  return route.abort();
 });
 await page.goto('http://agenda-comments.test/index.php'+(options.query||''));
 await page.locator(options.unavailable?'[data-agenda-comments-status]':'[data-agenda-comment-add="2026-10-14"]:enabled').waitFor();
 return {page,posts,errors,setFail(value){fail=value}};
}
async function toastFixture(browser,kind){
 const payload=JSON.parse(read('tmp/agenda_comentarios/avisos.json'))[kind];
 if(kind==='normal'){
  const second=structuredClone(payload.recordatorios[0]);second.agenda_comentario_id=900;second.agenda_comentario_evento='900|nuevo';
  payload.recordatorios.push(second);payload.avisos.push({...payload.avisos[0],...second});
 }
 const page=await browser.newPage({viewport:{width:1366,height:900},timezoneId:'America/Mexico_City'});
 await page.clock.install({time:new Date('2026-10-28T17:01:00Z')});await page.clock.pauseAt(new Date('2026-10-28T17:01:01Z'));
 const errors=[];page.on('pageerror',e=>errors.push(e.message));let calls=0;
 await page.route('http://agenda-notices.test/**',async route=>{
  if(new URL(route.request().url()).pathname==='/pendientes'){calls++;return route.fulfill({json:{ok:true,total:payload.recordatorios.length,recordatorios:payload.recordatorios,avisos:calls===1?payload.avisos:[]}});}
  return route.fulfill({contentType:'text/html',body:'<!doctype html><html><head><style>'+style+'</style><script>window.IMPE_CURRENT_USER_ID=7;</script></head><body><div data-reminder-root data-reminder-endpoint="/pendientes"><span data-reminder-badge></span><div data-reminder-content></div></div><script>'+reminders+'</script></body></html>'});
 });
 await page.goto('http://agenda-notices.test/inicio');await page.locator('.topbar-reminder-item').first().waitFor();
 return {page,errors,get calls(){return calls}};
}
(async()=>{
 const browser=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'chrome'});let cases=0;
 try{
  const f=await pageFixture(browser),p=f.page;
  assert.equal(await p.locator('[data-agenda-date]').count(),42);
  assert.equal(await p.locator('[data-agenda-new-request]').isDisabled(),true,'Solicitud sin elegibles conserva su comportamiento');
  await p.locator('[data-agenda-comment-add="2026-10-14"]').click();
  const editor=p.locator('[data-agenda-comment-editor]');await editor.waitFor({state:'visible'});
  assert.equal(await p.locator('[name=sin_hora]').isChecked(),true);assert.equal(await p.locator('#agendaComentarioHora').isDisabled(),true);
  await p.locator('#agendaComentarioTexto').fill('Revisar avances del módulo de reportes.');await p.locator('[data-agenda-comment-save]').click();
  await editor.waitFor({state:'hidden'});await p.getByText('Revisar avances del módulo de reportes.',{exact:true}).waitFor();
  const confirmation=p.locator('[data-agenda-comment-confirmation]');await confirmation.waitFor({state:'visible'});
  assert.equal(await confirmation.textContent(),'Comentario añadido a la agenda.');
  assert.equal(await confirmation.evaluate(el=>window.bootstrap.Toast.getInstance(el)._config.delay),2600,'Misma duración que Agenda');
  assert.equal(await p.locator('[data-agenda-comments-status]').isVisible(),false,'No dejar franja de éxito');
  await confirmation.waitFor({state:'detached',timeout:4500});
  assert.equal(f.posts.filter(x=>x.action==='crear').length,1);assert.equal(f.posts[0].body.hora,'');assert.equal(f.posts[0].csrf,'qa-csrf');
  assert.equal(await p.locator('[data-agenda-comments-count]').textContent(),'(2)');
  await p.locator('[data-agenda-comments-tab="reuniones"]').click();
  await p.locator('[data-agenda-comments-meetings] [data-agenda-meeting="31"]').click();
  await p.locator('#modalAgendaDetalle').waitFor({state:'visible'});
  assert.match(await p.locator('#modalAgendaDetalle').textContent(),/Institución de prueba/);
  await p.locator('#modalAgendaDetalle .btn-close').click();await p.locator('#modalAgendaDetalle').waitFor({state:'hidden'});
  await p.locator('[data-agenda-comments-tab="comentarios"]').click();
  await p.locator('[data-agenda-comments-new]').click();await p.locator('#agendaComentarioTexto').fill('Recordatorio con hora');await p.locator('[name=sin_hora]').uncheck();
  await p.locator('#agendaComentarioHora').fill('10:30');await p.locator('[data-agenda-comment-save]').click();
  assert.equal(await p.locator('[data-agenda-comment-save]').isDisabled(),true);
  await editor.waitFor({state:'hidden'});await p.getByText('Recordatorio con hora',{exact:true}).waitFor();
  assert.equal(f.posts.filter(x=>x.action==='crear').length,2);assert.equal(f.posts.at(-1).body.hora,'10:30');
  await p.locator('[data-agenda-comments-new]').click();await p.locator('#agendaComentarioTexto').fill('<img src=x onerror="window.comentarioXss=1">');
  await p.locator('[data-agenda-comment-save]').click();await editor.waitFor({state:'hidden'});
  await p.getByText('<img src=x onerror="window.comentarioXss=1">',{exact:true}).waitFor();assert.equal(await p.evaluate(()=>window.comentarioXss),undefined);
  assert.equal(await p.locator('[data-comment-id="1"] .is-danger').count(),0,'No ofrecer borrado ajeno');
  await p.locator('[data-comment-id="1"] summary').click();await p.getByRole('button',{name:'Marcar leído',exact:true}).first().click();
  const readToast=p.locator('[data-agenda-comment-confirmation]').filter({hasText:'Comentario marcado como leído.'});
  await readToast.waitFor({state:'visible'});assert.equal(await readToast.evaluate(el=>window.bootstrap.Toast.getInstance(el)._config.delay),2600);
  await p.locator('[data-comment-id="2"] summary').click();await p.locator('[data-comment-id="2"] .is-danger').click();
  await p.locator('[data-agenda-comment-delete-cancel]').click();assert.equal(f.posts.filter(x=>x.action==='eliminar').length,0);
  await p.locator('[data-comment-id="2"] .is-danger').click();await p.locator('[data-agenda-comment-delete-confirm]').click();
  await p.locator('[data-comment-id="2"]').waitFor({state:'detached'});assert.equal(f.posts.filter(x=>x.action==='eliminar').length,1);
  const deleteToast=p.locator('[data-agenda-comment-confirmation]').filter({hasText:'Comentario eliminado de la agenda.'});
  await deleteToast.waitFor({state:'visible'});assert.equal(await deleteToast.evaluate(el=>window.bootstrap.Toast.getInstance(el)._config.delay),2600);
  assert.equal(await p.locator('[data-agenda-comments-status]').isVisible(),false);
  await p.locator('[data-agenda-comments-new]').click();await p.locator('#agendaComentarioTexto').fill('x'.repeat(251));await p.locator('[data-agenda-comment-save]').click();
  assert.match(await p.locator('[data-agenda-comment-error]').textContent(),/250/);assert.equal(f.posts.filter(x=>x.action==='crear').length,3);
  await p.keyboard.press('Escape');await editor.waitFor({state:'hidden'});
  f.setFail(true);await p.locator('[data-agenda-comments-new]').click();await p.locator('#agendaComentarioTexto').fill('Conservar borrador');await p.locator('[data-agenda-comment-save]').click();
  await p.getByText('Fallo de prueba; conserva el comentario.',{exact:true}).waitFor();assert.equal(await p.locator('#agendaComentarioTexto').inputValue(),'Conservar borrador');
  assert.equal(await p.locator('[data-agenda-comment-save]').isDisabled(),false);await p.keyboard.press('Escape');
  await p.locator('[data-agenda-comments-close]').click();assert.equal(await p.locator('.agenda-layout > .agenda-side-card').first().isVisible(),true);
  assert.deepEqual(f.errors,[]);await p.close();cases+=8;
  for(const mobile of [false,true]){
   const f=await pageFixture(browser,{mobile}),p=f.page;await p.locator('[data-agenda-comment-add="2026-10-14"]').click();
   await p.locator('[data-agenda-comment-editor]').waitFor({state:'visible'});
   const box=await p.locator('[data-agenda-comment-editor]').boundingBox(),viewport=p.viewportSize();
   assert.ok(box.x>=0&&box.y>=0&&box.x+box.width<=viewport.width&&box.y+box.height<=viewport.height,'Editor dentro de viewport');
   assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Sin desbordamiento de página');
   await p.locator('#agendaComentarioTexto').fill('Prueba de comentario compartido');
   await p.screenshot({path:path.join(out,mobile?'comentario-movil.png':'comentario-escritorio.png')});
   assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  {
   const f=await pageFixture(browser,{query:'?dia=2026-10-14&comentario_id=1'}),p=f.page;
   await p.waitForFunction(()=>document.querySelector('[data-comment-id="1"]')?.classList.contains('is-read'));
   assert.equal(f.posts.filter(x=>x.action==='leer').length,1);assert.equal(f.posts[0].body.id,'1');assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  {
   const f=await pageFixture(browser,{read:true,query:'?dia=2026-10-14&comentario_id=1&comentario_leido=1'}),p=f.page;
   const toast=p.locator('[data-agenda-comment-confirmation]');await toast.waitFor({state:'visible'});
   assert.equal(await toast.textContent(),'Comentario marcado como leído.');
   assert.equal(await toast.evaluate(el=>window.bootstrap.Toast.getInstance(el)._config.delay),2600);
   assert.equal(f.posts.filter(x=>x.action==='leer').length,0,'No leer dos veces al llegar desde campana');
   assert.equal(new URL(p.url()).searchParams.has('comentario_leido'),false);await p.close();cases++;
  }
  {
   const f=await pageFixture(browser,{query:'?dia=2026-10-14&comentario_id=1&comentario_visto_por=7&aviso_visto_leido=1'}),p=f.page;
   const toast=p.locator('[data-agenda-comment-confirmation]');await toast.waitFor({state:'visible'});
   assert.equal(await toast.textContent(),'Notificación de lectura marcada como leída.');
   assert.equal(await toast.evaluate(el=>window.bootstrap.Toast.getInstance(el)._config.delay),2600);
   assert.deepEqual(f.posts.map(r=>r.action),['leerVisto'],'Abrir visto no lee el comentario original');
   assert.equal(f.posts[0].body.lector_id,'7');assert.equal(new URL(p.url()).searchParams.has('comentario_visto_por'),false);
   await p.reload();await p.locator('[data-agenda-comment-add="2026-10-14"]:enabled').waitFor();
   assert.equal(f.posts.length,1,'Recargar no repite ack de visto');await p.close();cases++;
  }
  {
   const f=await pageFixture(browser,{unavailable:true}),p=f.page;assert.equal(await p.locator('[data-agenda-comment-add="2026-10-14"]').isDisabled(),true);
   await p.locator('.agenda-calendar-grid [data-agenda-meeting="31"]').click();await p.locator('#modalAgendaDetalle').waitFor({state:'visible'});
   assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  for(const kind of ['normal','hora']){
   const f=await toastFixture(browser,kind),p=f.page;
   if(kind==='hora') {await p.clock.runFor(8*60000-1);assert.equal(await p.locator('.agenda-comment-toast').count(),0);await p.clock.runFor(2);}
   await p.locator('.agenda-comment-toast').waitFor({state:'visible'});
   assert.match(await p.locator('.agenda-comment-toast').textContent(),kind==='hora'?/Recordatorio de agenda/:/Nuevo comentario en la agenda/);
   const firstId=await p.locator('.agenda-comment-toast').getAttribute('data-agenda-comentario-id');
   const visibleLeft=await p.evaluate(()=>{
    const shown=JSON.parse(sessionStorage.getItem('recordatorios:comentarios-mostrados-v1:usuario:7')||'{}');
    return Math.max(...Object.values(shown).map(Number))+2600-Date.now();
   });
   await p.clock.runFor(visibleLeft-1);assert.equal(await p.locator('.agenda-comment-toast').count(),1);await p.clock.runFor(1);
   if(kind==='normal'){
    await p.locator('.agenda-comment-toast').waitFor({state:'visible'});
    assert.notEqual(await p.locator('.agenda-comment-toast').getAttribute('data-agenda-comentario-id'),firstId,'Rotar entre dos comentarios nuevos');
    await p.clock.runFor(2600);await p.clock.runFor(8*60000);
    assert.equal(await p.locator('.agenda-comment-toast').count(),0,'Sin hora no se vuelve recurrente');
   }else{
    const left=await p.evaluate(()=>Math.max(0,window.IMPE_REMINDER_STATUS().lastShownAt+8*60000-Date.now()));
    await p.clock.runFor(left+1);await p.locator('.agenda-comment-toast').waitFor({state:'visible'});
   }
   assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  console.log('OK: '+cases+' escenarios: agregado/leído/eliminado 2600 ms, aviso compartido al detectar, autor/borrado, errores, reuniones, móvil y recordatorios con hora. Sin producción.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
