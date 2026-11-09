// Presentador real y respuestas reales del servicio sobre SQL en memoria.
// Reloj controlado, sin sesión del CRM, BD real ni red externa.
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=process.cwd(),out=path.join(root,'tmp/agenda_toasts');
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
 const html='<!doctype html><html lang="es"><head><meta charset="utf-8"><style>'+css+'</style>'+ (withBootstrap?'<script>'+fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),'utf8')+'</script>':'')+'</head><body><h1>Escenario aislado de agenda</h1><script>window.IMPE_CURRENT_USER_ID='+user+';</script><div data-reminder-root data-reminder-endpoint="/pendientes"><span data-reminder-badge></span><div data-reminder-content></div></div><script>'+source+'</script></body></html>';
 await page.route('http://agenda-toast.test/**',async route=>{
  if(route.request().method()!=='GET'){posts.push(route.request().url());return route.abort();}
  if(new URL(route.request().url()).pathname==='/pendientes'){
   calls++;const avisos=delivered?[]:data.avisos;delivered=true;
   return route.fulfill({json:{ok:true,total:data.recordatorios.length,recordatorios:data.recordatorios,avisos}});
  }
  return route.fulfill({contentType:'text/html',body:html});
 });
 await page.goto('http://agenda-toast.test/inicio');await page.locator('.topbar-reminder-item').first().waitFor();
 return {page,errors,posts,setData(value){data=value},get calls(){return calls}};
}
const avanzarMinutos=async(page,n)=>{for(let i=0;i<n;i++){const before=await page.evaluate(()=>IMPE_REMINDER_STATUS().loadedCount);await page.clock.runFor(minute);await page.waitForFunction(v=>IMPE_REMINDER_STATUS().loadedCount>v,before);}};
const refresh=async page=>{const before=await page.evaluate(()=>IMPE_REMINDER_STATUS().loadedCount);await page.evaluate(()=>dispatchEvent(new Event('impe:phone-notices-refresh')));await page.waitForFunction(n=>IMPE_REMINDER_STATUS().loadedCount>n,before);};
(async()=>{const browser=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'chrome'});let count=0;try{
 for(const [kind,user,title] of [['kam',7,'Nueva solicitud de reunión'],['analista',5,'Reunión confirmada por Cuenta Clave'],['urgente',7,'Nueva solicitud de reunión']]){
  for(const withBootstrap of [true,false]){
   const f=await fixture(browser,kind,user,withBootstrap),p=f.page;
   assert.equal(await p.locator('.reminder-toast').count(),0,'Se conserva el turno inicial de ocho minutos');
   const start=await p.evaluate(()=>IMPE_REMINDER_STATUS().startedAt);
   await avanzarMinutos(p,4);await p.goto('http://agenda-toast.test/otra-pagina');await p.locator('.topbar-reminder-item').first().waitFor();
   assert.equal(await p.evaluate(()=>IMPE_REMINDER_STATUS().startedAt),start,'La navegación conserva la cadencia');
   await avanzarMinutos(p,3);await p.clock.runFor(minute-1);assert.equal(await p.locator('.reminder-toast').count(),0);
   await p.clock.runFor(1);const toast=p.locator('.reminder-toast');await toast.waitFor({state:'visible'});
   assert.equal(await toast.count(),1);assert.match(await toast.textContent(),new RegExp(title));assert.equal(await toast.getAttribute('role'),'status');assert.equal(await toast.getAttribute('tabindex'),'0');
   assert.equal(await toast.evaluate(e=>e.classList.contains('is-overdue')),false,'El evento informativo conserva el acabado institucional azul');
   const box=await toast.boundingBox();assert.ok(box.x>900&&box.y<100,'Misma ubicación superior derecha');
   assert.ok(f.calls>=8,'La consulta mantiene el intervalo de sesenta segundos');
   await p.screenshot({path:path.join(out,kind+(withBootstrap?'-bootstrap':'-fallback')+'.png')});
   await p.clock.runFor(6999);assert.equal(await toast.count(),1,'Visible durante siete segundos');await p.clock.runFor(1);assert.equal(await toast.count(),0);
   await p.clock.runFor(period+1);assert.equal(await toast.count(),0,'El mismo evento no se repite al consultar otra vez');
   assert.equal(await p.locator('.topbar-reminder-item').count(),1,'El pendiente continúa en la campana');
   assert.deepEqual(f.errors,[]);assert.deepEqual(f.posts,[]);await p.close();count++;
  }
 }
 // Resuelta antes del turno: la cola elimina el toast que ya no corresponde.
 {
  const f=await fixture(browser,'kam',7),p=f.page;f.setData({recordatorios:[],avisos:[]});await refresh(p);await p.clock.runFor(period+1);assert.equal(await p.locator('.reminder-toast').count(),0);assert.deepEqual(f.errors,[]);await p.close();count++;
 }
 // El clic abre la agenda con el identificador real del aviso, sin confirmar nada.
 {
  const f=await fixture(browser,'analista',5),p=f.page;await p.clock.runFor(period+1);await p.locator('.reminder-toast').waitFor({state:'visible'});
  await Promise.all([p.waitForURL('**/index.php?controller=agendaReunion&action=index&reunion_id=8'),p.locator('.reminder-toast').click()]);assert.deepEqual(f.posts,[]);assert.deepEqual(f.errors,[]);await p.close();count++;
 }
 console.log('OK: '+count+' escenarios, toast de solicitud/confirmación y urgentes con el presentador real; 4+4 minutos, siete segundos, polling 60 s, persistencia de cola, evento único, descarte al resolver y enlace directo. Cero producción o envíos.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
