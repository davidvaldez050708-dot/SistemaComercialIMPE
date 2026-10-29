// Fallos reales de fetch simulados en Chrome, sin acceder al CRM publicado.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),bootstrap=process.env.BOOTSTRAP_MODULE_PATH;
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const view=cp.execFileSync('C:/xampp/php/php.exe',[path.join(root,'tests/fixtures/agenda_comentarios_vista.php')],{encoding:'utf8'}).replaceAll('http://localhost/SistemaComercialIMPE/','http://agenda-conexion.test/');
const css=fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'),'utf8')+read('public/css/agenda_comentarios.css');
const boot=fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),'utf8');
const note={id:1,fecha:'2026-10-14',hora:null,privado:true,comentario:'Privado ya cargado',autor:'Analista Prueba',creado_at:'2026-10-14 10:00:00',puede_eliminar:true,leido:false,atendido:false};
async function fixture(browser,mode='ok',clock=false){
 const p=await browser.newPage();const errors=[],posts=[],gets=[];let current=mode,readAttempt=0;
 p.on('pageerror',e=>errors.push(e.message));
 if(clock){await p.clock.install({time:new Date('2026-10-29T16:00:00Z')});await p.clock.pauseAt(new Date('2026-10-29T16:00:01Z'));}
 await p.route('**/*',async route=>{
  const r=route.request(),u=new URL(r.url());
  if(u.searchParams.get('controller')==='agendaComentario'){
   if(r.method()==='POST'){posts.push({action:u.searchParams.get('action'),body:Object.fromEntries(new URLSearchParams(r.postData())),csrf:r.headers()['x-csrf-token']});return route.abort('failed');}
   gets.push(r.headers());readAttempt++;
   if(current==='hang'&&readAttempt===1)return;
   if(current==='network'||(current==='once'&&readAttempt===1))return route.abort('failed');
   if(current==='twice503'&&readAttempt<=2)return route.fulfill({status:503,json:{ok:false,mensaje:'Temporal'}});
   if(current==='malformed')return route.fulfill({status:200,body:'<html>Error temporal</html>',contentType:'text/html'});
   if(current==='missing')return route.fulfill({json:{ok:true}});
   if(current==='invalidRow')return route.fulfill({json:{ok:true,comentarios:[{}]}});
   if(current==='401'||current==='403')return route.fulfill({status:Number(current),json:{ok:false,mensaje:'Inicia sesión nuevamente.'}});
   if(current==='429')return route.fulfill({status:429,headers:{'Retry-After':'60'},json:{ok:false,mensaje:'Intenta más tarde.'}});
   return route.fulfill({json:{ok:true,comentarios:[note],privados_disponibles:true,hay_mas:false}});
  }
  if(u.pathname.includes('/public/javascript/agenda_comentarios.js'))return route.fulfill({contentType:'application/javascript',body:read('public/javascript/agenda_comentarios.js')});
  if(u.pathname.includes('/public/'))return route.fulfill({body:''});
  return route.fulfill({contentType:'text/html',body:'<!doctype html><html><head><meta charset="utf-8"><style>'+css+'</style><script>'+boot+'</script></head><body>'+view+'<script>window.IMPE_CSRF_TOKEN="prueba-csrf";</script></body></html>'});
 });
 await p.goto('http://agenda-conexion.test/index.php');
 return {p,errors,posts,gets,setMode(m){current=m;readAttempt=0;}};
}
const ready=async p=>p.locator('[data-agenda-comment-add="2026-10-14"]:enabled').waitFor();
const refresh=async p=>p.evaluate(()=>dispatchEvent(new Event('online')));
(async()=>{const browser=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'chrome'});let cases=0;try{
 for(const [mode,expected]of[['once',2],['twice503',3]]){
  const f=await fixture(browser,mode),p=f.p;await ready(p);
  assert.equal(f.gets.length,expected);assert.equal(f.gets[0]['x-requested-with'],'fetch','El router reconoce la petición AJAX');
  assert.equal(await p.locator('[data-agenda-comments-status]').isVisible(),false,'Un fallo recuperado no deja banner');
  assert.deepEqual(f.errors,[]);await p.close();cases++;
 }
 for(const mode of ['network','malformed','missing','invalidRow']){
  const f=await fixture(browser),p=f.p;await ready(p);
  await p.locator('[data-agenda-comment-add="2026-10-14"]').click();await p.keyboard.press('Escape');
  f.setMode(mode);await refresh(p);
  const status=p.locator('[data-agenda-comments-status]');await status.waitFor({state:'visible'});
  assert.doesNotMatch(await status.textContent(),/Failed to fetch|JSON|Unexpected/);
  assert.equal(f.gets.length,4,'Consulta inicial más dos reintentos del fallo');
  assert.equal(await p.getByText('Privado ya cargado',{exact:true}).isVisible(),true,'No borrar comentarios tras fallo temporal');
  f.setMode('ok');await refresh(p);await status.waitFor({state:'hidden'});
  assert.deepEqual(f.posts,[]);assert.deepEqual(f.errors,[]);await p.close();cases++;
 }
 {
  const f=await fixture(browser),p=f.p;await ready(p);const before=f.gets.length;
  await p.context().setOffline(true);await refresh(p);await p.locator('[data-agenda-comments-status]').waitFor({state:'visible'});
  assert.equal(f.gets.length,before,'No enviar consultas mientras el navegador informa desconexión');
  await p.context().setOffline(false);await p.locator('[data-agenda-comments-status]').waitFor({state:'hidden'});
  assert.equal(f.gets.length,before+1,'Al volver la conexión se carga de inmediato');assert.deepEqual(f.errors,[]);await p.close();cases++;
 }
 for(const mode of ['401','403']){
  const f=await fixture(browser),p=f.p;await ready(p);await p.locator('[data-agenda-comment-add="2026-10-14"]').click();await p.keyboard.press('Escape');
  f.setMode(mode);await refresh(p);await p.locator('[data-agenda-comments-status]').waitFor({state:'visible'});
  assert.equal(f.gets.length,2,'No reintentar errores de acceso');
  assert.equal(await p.locator('[data-comment-id="1"]').count(),0,'Retirar datos privados ante pérdida de acceso');
  assert.equal(await p.locator('[data-agenda-comment-add="2026-10-14"]').isDisabled(),true);assert.deepEqual(f.errors,[]);await p.close();cases++;
 }
 {
  const f=await fixture(browser),p=f.p;await ready(p);f.setMode('429');await refresh(p);await p.locator('[data-agenda-comments-status]').waitFor({state:'visible'});
  assert.equal(f.gets.length,2,'Respetar Retry-After sin ráfaga de peticiones');assert.deepEqual(f.errors,[]);await p.close();cases++;
 }
 {
  const f=await fixture(browser),p=f.p;await ready(p);await p.locator('[data-agenda-comment-add="2026-10-14"]').click();
  await p.locator('#agendaComentarioTexto').fill('Conservar borrador privado');await p.locator('[name=privado]').check();
  await p.locator('[data-agenda-comment-save]').click();const error=p.locator('[data-agenda-comment-error]');await error.waitFor({state:'visible'});
  assert.match(await error.textContent(),/No se pudo comprobar/);assert.equal(await p.locator('#agendaComentarioTexto').inputValue(),'Conservar borrador privado');
  assert.equal(await p.locator('[name=privado]').isChecked(),true);await p.waitForTimeout(2600);
  assert.equal(f.posts.length,1,'No reintentar un POST ambiguo');assert.equal(f.posts[0].csrf,'prueba-csrf');assert.equal(f.posts[0].body.privado,'1');
  assert.equal(await p.locator('[data-agenda-comment-confirmation]').count(),0,'No mostrar éxito sin respuesta');assert.deepEqual(f.errors,[]);await p.close();cases++;
 }
 {
  const f=await fixture(browser,'hang',true),p=f.p;await p.waitForFunction(()=>document.querySelector('[data-agenda-root]').dataset.commentsReady==='1');
  assert.equal(f.gets.length,1);await p.clock.runFor(15001);await p.waitForTimeout(30);await p.clock.runFor(801);await ready(p);
  assert.equal(f.gets.length,2,'Una lectura colgada se cancela y libera la carga');assert.deepEqual(f.errors,[]);await p.close();cases++;
 }
 console.log('OK: '+cases+' escenarios de red, reintento GET acotado, cache y recuperación, offline/online, sesión/permisos, Retry-After, timeout y POST único con borrador privado.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
