// Presentador real y respuestas reales del servicio sobre SQL en memoria.
// Reloj controlado, sin sesión del CRM, BD real ni red externa.
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=process.cwd(),out=path.join(root,'tmp/ecard_aliados');
const fixtures=JSON.parse(fs.readFileSync(path.join(out,'respuestas.json'),'utf8'));
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH;
const css=fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'),'utf8')+'\n'+fs.readFileSync('public/css/recordatorios.css','utf8');
const source=fs.readFileSync('public/javascript/recordatorios.js','utf8');
const minute=60000,period=8*minute;
async function fixture(browser,kind,user,withBootstrap=true){
 const page=await browser.newPage({viewport:{width:1366,height:900},timezoneId:'America/Mexico_City'});
 const now=new Date(fixtures.generated_at_ms);await page.clock.install({time:now});await page.clock.pauseAt(new Date(now.getTime()+1000));
 let data=structuredClone(fixtures[kind]),delivered=false,calls=0;const errors=[],posts=[];
 page.on('pageerror',e=>errors.push(e.message));
 const html='<!doctype html><html lang="es"><head><meta charset="utf-8"><style>'+css+'</style>'+ (withBootstrap?'<script>'+fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),'utf8')+'</script>':'')+'</head><body><h1>Escenario aislado de nuevo aliado</h1><script>window.IMPE_CURRENT_USER_ID='+user+';</script><div data-reminder-root data-reminder-endpoint="/pendientes"><span data-reminder-badge></span><div data-reminder-content></div></div><script>'+source+'</script></body></html>';
 await page.route('http://aliado-toast.test/**',async route=>{
  if(route.request().method()!=='GET'){posts.push(route.request().url());return route.abort();}
  if(new URL(route.request().url()).pathname==='/pendientes'){
   calls++;const avisos=delivered?[]:data.avisos;delivered=true;
   return route.fulfill({json:{ok:true,total:data.recordatorios.length,recordatorios:data.recordatorios,avisos}});
  }
  return route.fulfill({contentType:'text/html',body:html});
 });
 await page.goto('http://aliado-toast.test/inicio');await page.locator('.topbar-reminder-item').first().waitFor();
 return {page,errors,posts,setData(value){data=value},get calls(){return calls}};
}
const avanzarMinutos=async(page,n)=>{for(let i=0;i<n;i++){const before=await page.evaluate(()=>IMPE_REMINDER_STATUS().loadedCount);await page.clock.runFor(minute);await page.waitForFunction(v=>IMPE_REMINDER_STATUS().loadedCount>v,before);}};
const refresh=async page=>{const before=await page.evaluate(()=>IMPE_REMINDER_STATUS().loadedCount);await page.evaluate(()=>dispatchEvent(new Event('impe:phone-notices-refresh')));await page.waitForFunction(n=>IMPE_REMINDER_STATUS().loadedCount>n,before);};
(async()=>{const browser=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'chrome'});let count=0;try{
 for(const withBootstrap of [true,false]){
  const f=await fixture(browser,'aliado',7,withBootstrap),p=f.page;
  const item=p.locator('.topbar-reminder-item');assert.equal(await item.count(),1);
  assert.match(await item.textContent(),/Institución <prueba> México/);assert.match(await item.textContent(),/Nuevo aliado asignado/);
  assert.equal(await item.locator('prueba').count(),0,'Escapar el nombre institucional');
  assert.equal(await item.getAttribute('href'),'index.php?controller=aliado&action=estado&estado_id=11&aviso_aliado=1');
  assert.equal(await p.locator('.reminder-toast').count(),0);
  await avanzarMinutos(p,4);await p.goto('http://aliado-toast.test/otra-pagina');await item.waitFor();
  await avanzarMinutos(p,4);const toast=p.locator('.reminder-toast');await toast.waitFor({state:'visible'});
  assert.match(await toast.textContent(),/Nuevo aliado institucional/);assert.match(await toast.textContent(),/Institución <prueba> México/);
  assert.equal(await toast.getAttribute('role'),'status');assert.equal(await toast.count(),1);
  await p.screenshot({path:path.join(out,'aliado-'+(withBootstrap?'bootstrap':'fallback')+'.png')});
  await p.clock.runFor(6999);assert.equal(await toast.count(),1);await p.clock.runFor(1);assert.equal(await toast.count(),0);
  await p.clock.runFor(period+1);assert.equal(await toast.count(),0,'Toast único; campana continúa hasta leer');assert.equal(await item.count(),1);
  f.setData({avisos:[],recordatorios:[]});await refresh(p);assert.equal(await item.count(),0,'Lectura retira campana');
  assert.deepEqual(f.errors,[]);assert.deepEqual(f.posts,[]);await p.close();count++;
 }
 {
  const f=await fixture(browser,'aliado',7),p=f.page;f.setData({avisos:[],recordatorios:[]});await refresh(p);await p.clock.runFor(period+1);
  assert.equal(await p.locator('.reminder-toast').count(),0,'Leído o desasignado antes del turno: descartar cola');assert.deepEqual(f.errors,[]);await p.close();count++;
 }
 {
  const f=await fixture(browser,'aliado',7),p=f.page;await p.clock.runFor(period+1);await p.locator('.reminder-toast').waitFor({state:'visible'});
  await Promise.all([p.waitForURL('**/index.php?controller=aliado&action=estado&estado_id=11&aviso_aliado=1'),p.locator('.reminder-toast').click()]);
  assert.deepEqual(f.errors,[]);assert.deepEqual(f.posts,[]);await p.close();count++;
 }
 console.log('OK: '+count+' escenarios de nuevo aliado, campana, escape HTML, cola 4+4 minutos, 7 segundos, sondeo 60 s, navegación, lectura/desasignación y enlace al territorio. Sin producción.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
