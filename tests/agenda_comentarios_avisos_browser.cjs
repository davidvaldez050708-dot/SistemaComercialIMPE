// Respuestas simuladas, Chrome y reloj real del módulo. Sin CRM publicado.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),minute=60000,period=8*minute;
const script=fs.readFileSync(process.env.IMPE_REMINDER_TEST_SOURCE||path.join(root,'public/javascript/recordatorios.js'),'utf8');
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.dirname(require.resolve('bootstrap/package.json'));
const css=fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'),'utf8')+fs.readFileSync(path.join(root,'public/css/recordatorios.css'),'utf8');
const comment={id:0,agenda_comentario_id:41,agenda_comentario_evento:'41|nuevo',nombre_entidad:'Comentario de Susana Molina',
 accion:'Añadió un comentario a la agenda: Revisar reporte',fecha:'2026-10-28 10:00:00',estado:'normal',etiqueta:'Sin hora',
 url:'/index.php?controller=agendaReunion&action=index&mes=2026-10&dia=2026-10-28&comentario_id=41'};
const other={id:90,nombre_entidad:'Otro pendiente',accion:'Revisar seguimiento',estado:'normal',fecha:'2026-10-28 10:00:00',url:'/otro'};
async function fixture(browser,options={}){
 const page=await browser.newPage({viewport:{width:1366,height:900}}),errors=[],reads=[];
 await page.clock.install({time:new Date('2026-10-28T17:01:00Z')});await page.clock.pauseAt(new Date('2026-10-28T17:01:01Z'));
 let opened=false,fail=false,finishRead=null,incoming=!options.empty;
 const item={...comment,...(options.timed?{agenda_comentario_evento:'41|hora',estado:'vencida',etiqueta:'Hora programada'}:{})};
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://comment-notices.test/**',async route=>{
  const r=route.request(),u=new URL(r.url());
  if(u.pathname==='/pendientes')return route.fulfill({json:{ok:true,total:opened||!incoming?1:2,recordatorios:opened||!incoming?[other]:[item,other],avisos:[]}});
  if(u.searchParams.get('controller')==='agendaComentario'){
   reads.push({method:r.method(),body:r.postData(),csrf:r.headers()['x-csrf-token'],action:u.searchParams.get('action')});
   assert.equal(u.searchParams.get('action'),'leer','No borrar ni modificar comentarios');
   if(options.delay)await new Promise(resolve=>{finishRead=resolve});
   if(fail)return route.fulfill({status:419,json:{ok:false}});
   opened=true;return route.fulfill({json:{ok:true}});
  }
  return route.fulfill({contentType:'text/html',body:'<!doctype html><html lang="es"><head><meta charset="utf-8"><style>'+css+'</style><script>window.IMPE_CURRENT_USER_ID=5;window.IMPE_CSRF_TOKEN="qa-csrf";</script></head><body><div data-reminder-root data-reminder-endpoint="/pendientes"><span data-reminder-badge></span><div data-reminder-content></div></div><script>'+script+'</script></body></html>'});
 });
 await page.goto('http://comment-notices.test/inicio');await page.locator('.topbar-reminder-item').first().waitFor();
 return {page,errors,reads,arrive(){incoming=true},setFail(v){fail=v},release(){finishRead?.()},isWaiting(){return Boolean(finishRead)}};
}
async function popup(p,timed=false){if(timed)await p.clock.runFor(period+1);await p.locator('.agenda-comment-toast').waitFor({state:'visible'});}
(async()=>{
 const browser=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'chrome'});let cases=0;
 try{
  for(const own of [false,true]){
   const f=await fixture(browser,{empty:true}),p=f.page;assert.equal(await p.locator('.agenda-comment-toast').count(),0);f.arrive();
   if(own)await p.evaluate(()=>window.dispatchEvent(new CustomEvent('impe:agenda-comments-refresh',{detail:{comentarioPropioId:41}})));
   else await p.clock.runFor(minute);
   await p.locator('.topbar-reminder-item[data-agenda-comentario-id="41"]').waitFor();
   if(own)assert.equal(await p.locator('.agenda-comment-toast').count(),0,'No duplicar la confirmación del autor');
   else await p.locator('.agenda-comment-toast').waitFor({state:'visible'});
   assert.equal(f.reads.length,0);assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  {
   const f=await fixture(browser),p=f.page;await popup(p);
   assert.match(await p.locator('.agenda-comment-toast').textContent(),/Nuevo comentario.*Susana Molina/s);
   assert.equal(f.reads.length,0,'Mostrar toast no marca leído');
   assert.equal(await p.evaluate(()=>window.IMPE_REMINDER_STATUS().lastShownAt),0,'Novedad no consume el turno de otras fuentes');
   await p.clock.runFor(2599);assert.equal(await p.locator('.agenda-comment-toast').count(),1);
   await p.clock.runFor(1);assert.equal(await p.locator('.agenda-comment-toast').count(),0);
   await p.clock.runFor(period+1);assert.equal(await p.locator('.agenda-comment-toast').count(),0,'Sin hora se muestra una vez');
   await p.reload();await p.locator('.topbar-reminder-item[data-agenda-comentario-id="41"]').waitFor();
   await p.clock.runFor(period+1);assert.equal(await p.locator('.agenda-comment-toast').count(),0,'Recargar no repite el aviso ya visible');
   assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  for(const fromToast of [false,true]){
   const f=await fixture(browser,{delay:true}),p=f.page;if(fromToast)await popup(p);
   const link=p.locator(fromToast?'.agenda-comment-toast[data-agenda-comentario-id="41"]':'.topbar-reminder-item[data-agenda-comentario-id="41"]');
   await link.click();await p.waitForFunction(()=>document.querySelector('[data-agenda-comentario-id="41"][aria-busy="true"]'));
   assert.equal(new URL(p.url()).pathname,'/inicio','No navegar antes de confirmar lectura');
   assert.equal(await p.locator('.topbar-reminder-item[data-agenda-comentario-id="41"]').count(),1);
   await link.click();assert.equal(f.reads.length,1,'No duplicar lectura durante el envío');
   assert.deepEqual(f.reads[0],{method:'POST',body:'id=41',csrf:'qa-csrf',action:'leer'});
   f.release();await p.waitForURL('**/*comentario_id=41*');await p.locator('.topbar-reminder-item').waitFor();
   assert.equal(new URL(p.url()).searchParams.get('comentario_leido'),'1');
   assert.equal(await p.locator('.topbar-reminder-item[data-agenda-comentario-id="41"]').count(),0);
   assert.equal(await p.locator('[data-reminder-badge]').textContent(),'1');
   assert.match(await p.locator('.topbar-reminder-item').textContent(),/Otro pendiente/);
   await p.clock.runFor(period+1);assert.equal(await p.locator('.agenda-comment-toast').count(),0,'Comentario abierto no revive');
   assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  {
   const f=await fixture(browser),p=f.page;f.setFail(true);await p.locator('.topbar-reminder-item[data-agenda-comentario-id="41"]').click();
   await p.locator('[data-agenda-comment-read-error]').waitFor();assert.equal(new URL(p.url()).pathname,'/inicio');
   assert.equal(await p.locator('.topbar-reminder-item').count(),2,'Un fallo conserva campana');
   f.setFail(false);await p.locator('.topbar-reminder-item[data-agenda-comentario-id="41"]').click();
   await p.waitForURL('**/*comentario_id=41*');assert.equal(f.reads.length,2);assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  {
   const f=await fixture(browser,{timed:true}),p=f.page;await popup(p,true);await p.clock.runFor(2600);
   const left=await p.evaluate(period=>window.IMPE_REMINDER_STATUS().lastShownAt+period-Date.now(),period);
   await p.clock.runFor(left+1);await p.locator('.agenda-comment-toast').waitFor({state:'visible'});
   assert.match(await p.locator('.agenda-comment-toast').textContent(),/Recordatorio de agenda/);
   assert.equal(f.reads.length,0);assert.deepEqual(f.errors,[]);await p.close();cases++;
  }
  console.log('OK: '+cases+' escenarios; comentario al detectar, 2600 ms, lectura CSRF y confirmación al navegar, no repetición, otros turnos intactos y recurrencia con hora.');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exit(1)});
