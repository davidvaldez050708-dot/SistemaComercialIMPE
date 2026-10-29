// Vista real en Chrome y rutas interceptadas; sin usuarios ni comentarios reales.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),bootstrap=process.env.BOOTSTRAP_MODULE_PATH;
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const view=cp.execFileSync('C:/xampp/php/php.exe',[path.join(root,'tests/fixtures/agenda_comentarios_vista.php')],{encoding:'utf8'}).replaceAll('http://localhost/SistemaComercialIMPE/','http://agenda-destinatarios.test/');
const css=fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'),'utf8')+read('public/css/agenda_comentarios.css');
const boot=fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),'utf8');
const user={id:7,nombre:'Cuenta <Clave>',rol:'Cuenta Clave'};
async function setup(browser,mode='ok',mobile=false){
 const p=await browser.newPage({viewport:mobile?{width:390,height:844}:{width:1440,height:1000}}),posts=[],errors=[];let users=[user],notes=[],gets=0;
 p.on('pageerror',e=>errors.push(e.message));
 await p.route('**/*',async route=>{
  const r=route.request(),u=new URL(r.url());
  if(u.searchParams.get('controller')==='agendaComentario'){
   if(r.method()==='POST'){
    const data=Object.fromEntries(new URLSearchParams(r.postData()));posts.push(data);assert.equal(r.headers()['x-csrf-token'],'csrf-destinatarios');
    if(mode==='network')return route.abort('failed');
    const comment={id:1,fecha:data.fecha,hora:data.hora||null,comentario:data.comentario,autor:'Analista Prueba',creado_at:'2026-10-29 10:00:00',privado:data.privado==='1',destinatario_id:data.destinatario_id?Number(data.destinatario_id):null,destinatario_nombre:user.nombre,puede_eliminar:true};
    notes=[comment];return route.fulfill({json:{ok:true,comentario:comment}});
   }
   gets++;return route.fulfill({json:{ok:true,comentarios:notes,privados_disponibles:true,destinatarios_disponibles:mode!=='legacy',destinatarios:users}});
  }
  if(u.pathname.includes('/public/javascript/agenda_comentarios.js'))return route.fulfill({contentType:'application/javascript',body:read('public/javascript/agenda_comentarios.js')});
  if(u.pathname.includes('/public/'))return route.fulfill({body:''});
  return route.fulfill({contentType:'text/html',body:'<!doctype html><html><head><meta charset="utf-8"><style>'+css+'</style><script>'+boot+'</script></head><body>'+view+'<script>window.IMPE_CSRF_TOKEN="csrf-destinatarios";</script></body></html>'});
 });
 await p.goto('http://agenda-destinatarios.test/index.php');
 await p.locator('[data-agenda-comment-add="2026-10-14"]:enabled').waitFor();
 await p.locator('[data-agenda-comment-add="2026-10-14"]').click();
 await p.locator('#agendaComentarioTexto').fill('Comentario para la persona elegida');
 return {p,posts,errors,get gets(){return gets;},removeUser(){users=[];}};
}
(async()=>{const browser=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'chrome'});let cases=0;try{
 for(const mobile of [false,true]){
  const f=await setup(browser,'ok',mobile),p=f.p,select=p.locator('[name=destinatario_id]');
  assert.equal(await select.inputValue(),'');assert.equal(await select.locator('option').count(),2);
  assert.match(await select.locator('option[value="7"]').textContent(),/Cuenta <Clave>/,'El nombre se trata como texto');
  await select.selectOption('7');await p.locator('[name=sin_hora]').uncheck();await p.locator('[name=hora]').fill('13:45');
  const out=path.join(root,'tmp/agenda_destinatarios');fs.mkdirSync(out,{recursive:true});
  await p.locator('[data-agenda-comment-editor]').screenshot({path:path.join(out,mobile?'selector-movil.png':'selector-escritorio.png')});
  await p.locator('[data-agenda-comment-save]').click();await p.locator('[data-agenda-comment-editor]').waitFor({state:'hidden'});
  assert.equal(f.posts.length,1);assert.equal(f.posts[0].destinatario_id,'7');assert.equal(f.posts[0].hora,'13:45');
  await p.getByText('Avisos para: Cuenta <Clave>',{exact:true}).waitFor();assert.deepEqual(f.errors,[]);await p.close();cases++;
 }
 {
  const f=await setup(browser),p=f.p;await p.locator('[data-agenda-comment-save]').click();await p.locator('[data-agenda-comment-editor]').waitFor({state:'hidden'});
  assert.equal(f.posts[0].destinatario_id,'');assert.equal(f.posts[0].privado,'0');assert.equal(f.posts[0].hora,'');assert.deepEqual(f.errors,[]);await p.close();cases++;
 }
 {
  const f=await setup(browser),p=f.p,select=p.locator('[name=destinatario_id]');await select.selectOption('7');await p.locator('[name=privado]').check();
  assert.equal(await select.isDisabled(),true);assert.match(await p.locator('#agendaComentarioDestinatarioAyuda').textContent(),/sólo tú/);
  await p.locator('[name=privado]').uncheck();assert.equal(await select.inputValue(),'7');await p.locator('[name=privado]').check();
  await p.locator('[data-agenda-comment-save]').click();await p.locator('[data-agenda-comment-editor]').waitFor({state:'hidden'});
  assert.equal(f.posts[0].destinatario_id,'');assert.equal(f.posts[0].privado,'1');assert.deepEqual(f.errors,[]);await p.close();cases++;
 }
 {
  const f=await setup(browser,'network'),p=f.p;await p.locator('[name=destinatario_id]').selectOption('7');await p.locator('[data-agenda-comment-save]').click();await p.locator('[data-agenda-comment-error]').waitFor();
  assert.equal(await p.locator('[name=destinatario_id]').inputValue(),'7');assert.equal(await p.locator('#agendaComentarioTexto').inputValue(),'Comentario para la persona elegida');
  await p.waitForTimeout(1800);assert.equal(f.posts.length,1);assert.deepEqual(f.errors,[]);await p.close();cases++;
 }
 {
  const f=await setup(browser),p=f.p;await p.locator('[name=destinatario_id]').selectOption('7');f.removeUser();
  await p.evaluate(()=>dispatchEvent(new Event('online')));await p.locator('[name=destinatario_id] option[value="7"]').filter({hasText:'no disponible'}).waitFor({state:'attached'});
  await p.locator('[data-agenda-comment-save]').click();await p.locator('[data-agenda-comment-error]').waitFor();assert.equal(f.posts.length,0,'No cambiar a Todos si se desactiva el destinatario');
  await p.locator('[name=destinatario_id]').selectOption('');await p.locator('[data-agenda-comment-save]').click();await p.locator('[data-agenda-comment-editor]').waitFor({state:'hidden'});assert.equal(f.posts[0].destinatario_id,'');assert.deepEqual(f.errors,[]);await p.close();cases++;
 }
 {
  const f=await setup(browser,'legacy'),p=f.p;assert.equal(await p.locator('[name=destinatario_id]').isDisabled(),true);await p.locator('[data-agenda-comment-save]').click();await p.locator('[data-agenda-comment-editor]').waitFor({state:'hidden'});
  assert.equal(f.posts[0].destinatario_id,'');assert.deepEqual(f.errors,[]);await p.close();cases++;
 }
 console.log('OK: '+cases+' escenarios de destinatarios, escritorio/móvil, Todos, privado, nombres seguros, permisos cambiantes, borrador y compatibilidad sin migración.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
