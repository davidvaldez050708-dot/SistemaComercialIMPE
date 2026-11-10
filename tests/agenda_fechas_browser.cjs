// Vista real, peticiones simuladas. Sin CRM, BD ni envíos reales.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),bootstrap=process.env.BOOTSTRAP_MODULE_PATH;
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const css=fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'),'utf8')+['global.css','dashboard.css','agenda_reunion.css','agenda_comentarios.css','agenda_fechas_referencia.css'].map(f=>read('public/css/'+f)).join('\n');
const boot=fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),'utf8');
const out=path.join(root,'tmp/agenda_festivos');fs.mkdirSync(out,{recursive:true});
async function fixture(browser,month,mobile=false){
 const p=await browser.newPage({viewport:mobile?{width:390,height:844}:{width:1450,height:980},locale:'es-MX',timezoneId:'America/Mexico_City'});
 const errors=[],posts=[],requests=[];p.on('pageerror',e=>errors.push(e.message));
 const view=cp.execFileSync('C:/xampp/php/php.exe',[path.join(root,'tests/fixtures/agenda_comentarios_vista.php')],{encoding:'utf8',env:{...process.env,AGENDA_QA_MES:month}}).replaceAll('http://localhost/SistemaComercialIMPE/','http://agenda-fechas.test/');
 await p.route('**/*',route=>{
  const r=route.request(),u=new URL(r.url());requests.push(u.href);
  if(u.searchParams.get('controller')==='agendaComentario'){
   if(r.method()!=='GET')posts.push(r.postData());
   return route.fulfill({json:{ok:true,comentarios:[],privados_disponibles:true}});
  }
  if(u.pathname.includes('/public/javascript/'))return route.fulfill({contentType:'application/javascript',body:read('public/javascript/'+u.pathname.split('/').at(-1))});
  if(u.pathname.includes('/public/css/'))return route.fulfill({body:read('public/css/'+u.pathname.split('/').at(-1)),contentType:'text/css'});
  if(r.method()!=='GET')posts.push(r.postData());
  return route.fulfill({contentType:'text/html',body:'<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+css+'body{padding:20px}</style><script>'+boot+'</script><script>window.IMPE_CSRF_TOKEN="prueba";</script></head><body>'+view+'<script>'+read('public/javascript/agenda_reunion.js')+'</script></body></html>'});
 });
 await p.goto('http://agenda-fechas.test/index.php?mes='+month);
 await p.waitForFunction(()=>document.querySelector('[data-agenda-root]').dataset.fechasReferenciaReady==='1');
 return {p,errors,posts,requests};
}
(async()=>{const browser=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'chrome'});let count=0;try{
 for(const [month,date,kinds] of [['2026-11','2026-11-16',['oficial','fsl']],['2026-12','2026-12-25',['oficial','fsl']],['2027-01','2027-01-01',['oficial','fsl']],['2027-02','2027-02-01',['oficial','fsl']],['2027-03','2027-03-21',['tradicional','santa']],['2027-06','2027-06-06',['oficial']]]){
  const f=await fixture(browser,month),p=f.p,cell=p.locator('[data-agenda-date="'+date+'"]');
  assert.equal(await p.locator('[data-agenda-fecha-categoria]').count(),4);
  assert.deepEqual((await cell.locator('[data-agenda-fecha-tipo]').evaluateAll(es=>es.map(e=>e.dataset.agendaFechaTipo))).sort(),kinds.sort());
  const before=await p.locator('[data-agenda-meeting]').count();
  for(const type of kinds){await p.locator('[data-agenda-fecha-categoria="'+type+'"]').uncheck();assert.equal(await cell.locator('[data-agenda-fecha-tipo="'+type+'"]').isVisible(),false);}
  assert.equal(await p.locator('[data-agenda-meeting]').count(),before,'Filtrar referencias no retira reuniones');
  assert.equal(await p.locator('[aria-label="Mes siguiente"]').count(),1);
  assert.deepEqual(f.posts,[]);assert.ok(f.requests.every(url=>new URL(url).hostname==='agenda-fechas.test'),'Sin consultas externas a calendario.mx');
  assert.deepEqual(f.errors,[]);await p.close();count++;
 }
 for(const mobile of [false,true]){
  const f=await fixture(browser,'2026-11',mobile),p=f.p;
  await p.locator('[data-agenda-fecha-categoria="oficial"]').focus();await p.keyboard.press('Space');
  assert.equal(await p.locator('[data-agenda-fecha-categoria="oficial"]').isChecked(),false);
  await p.keyboard.press('Space');await p.screenshot({path:path.join(out,mobile?'fechas-movil.png':'fechas-escritorio.png')});
  await p.locator('[data-agenda-date="2026-11-16"] [data-agenda-fecha-tipo="oficial"]').click();
  assert.equal(await p.locator('[data-agenda-comments-tab="reuniones"]').getAttribute('aria-selected'),'true','Seleccionar fecha mantiene Reuniones como primera pestaña');
  await p.locator('[data-agenda-date="2026-11-16"]').evaluate(el=>el.classList.add('is-today'));
  await p.locator('[data-agenda-fecha-categoria="oficial"]').uncheck();await p.locator('[data-agenda-fecha-categoria="oficial"]').check();
  assert.equal(await p.locator('[data-agenda-date="2026-11-16"] .agenda-fecha-numero-oficial').count(),0,'Hoy conserva su acabado');
  await p.locator('[data-agenda-comment-add="2026-11-16"]').click();
  await p.locator('[data-agenda-comment-editor]').waitFor({state:'visible'});
  assert.deepEqual(f.posts,[]);assert.deepEqual(f.errors,[]);await p.close();count++;
 }
 {
  const f=await fixture(browser,'2026-10'),p=f.p;
  await p.locator('.agenda-calendar-grid [data-agenda-meeting="31"]').click();await p.locator('#modalAgendaDetalle').waitFor({state:'visible'});
  assert.match(await p.locator('#modalAgendaDetalle').textContent(),/Institución de prueba/);
  assert.deepEqual(f.posts,[]);assert.deepEqual(f.errors,[]);await p.close();count++;
 }
 console.log('OK: '+count+' escenarios de fechas, categorías simultáneas, teclado, móvil, comentarios y modal de reunión; cero POST o peticiones externas.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
