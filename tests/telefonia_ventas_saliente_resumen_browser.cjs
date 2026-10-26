'use strict';
// Shell y formulario reales; transporte y API simulados. Sin llamadas ni datos de producción.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process'),{chromium,webkit}=require('playwright');
const root=path.resolve(__dirname,'..'),read=p=>fs.readFileSync(path.join(root,p),'utf8');
const php=(file,...args)=>execFileSync(process.env.PHP_BINARY||'php',[path.join(root,'tests/fixtures',file),...args],{encoding:'utf8'});
const bootstrap=path.dirname(require.resolve('bootstrap/package.json'));
let shell=php('telefonia_ventas_shell.php');
if(process.env.SALES_SUMMARY_SHELL)shell=shell.replace(read('public/javascript/telefonia_shell.js'),fs.readFileSync(process.env.SALES_SUMMARY_SHELL,'utf8'));
else if(process.env.SALES_SUMMARY_VARIANT==='hostinger'){
 const variant=JSON.parse(read('tools/ventas_resumen_saliente_release.json')).files[0].variants.find(v=>v.label==='Hostinger Opera');
 shell=shell.replace(read('public/javascript/telefonia_shell.js'),require('node:zlib').gunzipSync(Buffer.from(variant.after,'base64')).toString('utf8'));
}
const scripts=['telefonia_atencion.js','telefonia_segmentos_player.js','telefonia_ventas_resultados.js'];
const styles=fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'),'utf8')+read('public/css/global.css');
const resultHtml=(ref,saved)=>'<html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+styles+'</style><body><script>window.IMPE_CURRENT_USER_ID=8;</script>'
 +'<main class="admin-shell">'+php('telefonia_ventas_vista.php',ref,'150',saved?'guardado':'')+'</main>'
 +'<script>'+fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),'utf8')+'</script>'
 +scripts.map(p=>'<script>'+read('public/javascript/'+p)+'</script>').join('')+'</body></html>';
async function scenario(browser,{answered,width,marketing=false,explicitClose=false}){
 const context=await browser.newContext({viewport:{width,height:900}}),page=await context.newPage();
 const ref='pbx-sales-call-001';let ended=false,saved=false,saves=0,posted=null;
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const facts=()=>({id:1,usuario_id:8,pbx_call_id:ref,extension:'150',direccion:'Saliente',numero:'+525551112233',nombre:'María',origen_extension:'',
  finalizada:ended,conectada:answered,segundos:answered?14:0,segmentos:[],estado_pbx:answered?'Conectada':'Cancelada',
  registro:saved?{resultado:answered?'INTERESADO':'SIN_RESPUESTA',observacion:'Resultado de prueba'}:null});
 await page.route('http://ventas.test/**',route=>{
  const url=new URL(route.request().url()),action=url.searchParams.get('action');
  if(url.pathname.startsWith('/public/'))return route.fulfill({path:path.join(root,url.pathname)});
  if(action==='host')return route.fulfill({contentType:'text/html',body:'<html>Transporte simulado</html>'});
  if(action==='disponibilidad')return route.fulfill({json:{ok:true,entrante:null,devoluciones:0,solicitud:null}});
  if(action==='detalleVenta')return route.fulfill({json:url.searchParams.has('pendientes')?{ok:true,pendientes:ended&&!saved?[facts()]:[]}:{ok:true,llamada:facts()}});
  if(action==='guardarResultadoVenta'){
   posted=Object.fromEntries(new URLSearchParams(route.request().postData()));saved=true;saves++;
   return route.fulfill({json:{ok:true,registro:{resultado:posted.resultado}}});
  }
  if(url.pathname==='/'){
   const html=marketing?shell.replace('"salesResults":true','"salesResults":false'):shell;
   return route.fulfill({contentType:'text/html',body:html});
  }
  return route.fulfill({contentType:'text/html',body:url.searchParams.has('pbx_call_id')?resultHtml(ref,saved):'<html><body><main class="admin-shell">Inicio</main></body></html>'});
 });
 try{
  await page.clock.install();await page.goto('http://ventas.test/');
  await page.waitForFunction(()=>window.IMPE_TELEPHONY_SHELL&&document.querySelector('[data-shell-engine]'));
  const engine=await page.locator('[data-shell-engine]').elementHandle();
  const push=async(phase,active,extra={})=>page.evaluate(({phase,active,answered,ref,extra})=>{
   const value={userId:8,hostReady:true,connection:{verified:true},updatedAt:Date.now(),active,phase,direction:phase==='ready'?'':'outgoing',
    destination:'+525551112233',duration:answered?14:0,callToken:'sales-outgoing-summary-001',pbxCallId:phase==='ready'?'':ref,
    context:phase==='ready'?{}:{type:'TELEPHONY_MANUAL',pbxAnswered:answered},...extra};
   localStorage.setItem('impe:telephony:state:8',JSON.stringify(value));dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:8',newValue:JSON.stringify(value)}));
  },{phase,active,answered,ref,extra});
  await push(answered?'in-progress':'dialing',true);await page.clock.runFor(1000);
  ended=true;await push('finished',false);await page.clock.runFor(1500);
  const panel=page.locator('[data-incoming-panel]');assert(await panel.isVisible(),'Resumen visible al colgar');
  if(marketing){
   await page.clock.runFor(10000);assert(await panel.isHidden(),'Marketing conserva resumen temporal');
   assert.equal(await page.evaluate(()=>IMPE_PHONE_SHELL_CONFIG.salesResults),false);
   return;
  }
  await page.waitForFunction(()=>IMPE_SALES_ATTENTION.hasPending());
  await push('ready',false);await page.clock.runFor(2500);
  assert(await panel.isVisible(),'El heartbeat ready no debe retirar el resumen pendiente del asesor');
  assert.equal(await page.evaluate(()=>IMPE_TELEPHONY_SHELL.getCallState()?.pbxCallId),ref,'El API consulta la misma llamada que muestra el resumen');
  await page.clock.runFor(120000);
  assert(await panel.isVisible(),'El resumen de Ventas no vence por tiempo');
  assert.equal(await panel.locator('[data-incoming-register-finished]').count(),1,'Un solo botón de resultado');
  assert.equal(saves,0,'El paso del tiempo no guarda resultados');
  if(explicitClose){
   await panel.locator('[data-incoming-close-finished]').click();assert(await panel.isHidden(),'Cerrar explícitamente oculta el resumen cancelado');
   return;
  }
  await panel.locator('[data-incoming-register-finished]').click();
  const frame=page.frameLocator('#impeCrmFrame'),modal=frame.locator('#modalResultadoVenta');
  await modal.waitFor({state:'visible'});assert(await panel.isHidden(),'Sólo se oculta el teléfono al abrir el resultado');
  await modal.locator('[name=resultado]').selectOption(answered?'INTERESADO':'SIN_RESPUESTA');
  await modal.locator('[data-sales-submit]').click();await modal.waitFor({state:'hidden'});
  await page.waitForFunction(()=>!IMPE_SALES_ATTENTION.hasPending());
  assert.equal(saves,1);assert.equal(posted.pbx_call_id,ref);assert.equal(posted.extension,'150');
  await page.clock.runFor(15000);assert(await panel.isHidden(),'Guardar retira el resumen y no reaparece con otro heartbeat');
  assert(await engine.evaluate(el=>el.isConnected),'No se recrea el motor telefónico');
  await push('in-progress',true,{pbxCallId:'pbx-next-sales-002',callToken:'sales-outgoing-summary-002'});await page.clock.runFor(1000);
  assert.equal(await page.evaluate(()=>IMPE_TELEPHONY_SHELL.getCallState().pbxCallId),'pbx-next-sales-002','Una llamada nueva no hereda la anterior');
  assert.deepEqual(errors,[]);
 }finally{await context.close();}
}
(async()=>{
 const browser=await(process.env.SALES_BROWSER==='webkit'?webkit:chromium).launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 try{
  for(const width of [1366,390])for(const answered of [true,false])await scenario(browser,{answered,width});
  await scenario(browser,{answered:false,width:1366,explicitClose:true});
  await scenario(browser,{answered:true,width:1366,marketing:true});
  console.log('OK: 6 escenarios; saliente contestada/cancelada, ready tardío, 2 minutos, guardar, cierre explícito, móvil y Marketing sin alterar el motor.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
