const fs=require('node:fs'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const read=p=>fs.readFileSync(p,'utf8').replace(/\r\n/g,'\n'),host=read('public/javascript/telefonia_host.js');
const start=host.indexOf('    const startAnalystSdkObserver = function () {'),end=host.indexOf('\n    const ensureWidget',start);
const apiStart=host.indexOf('    window.IMPE_ZADARMA_INCOMING = Object.freeze({'),apiEnd=host.indexOf('\n    const stopPolling',apiStart);
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});try{
 for(const sales of [false,true]){
  const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const html=cp.execFileSync('php',[sales?'tests/fixtures/telefonia_ventas_shell.php':'tests/fixtures/telefonia_flujo_shell.php',...(sales?[]:['analista'])],{encoding:'utf8'});
  const hostHtml=`<!doctype html><body><script>${read('public/javascript/telefonia_sdk_entrantes_observador.js')}</script><script>${read('public/javascript/telefonia_entrantes_bridge.js')}</script><script>
   let state={userId:8,hostReady:true,active:false,phase:'ready',direction:'incoming',context:{}};
   const config={analystSdkIncoming:true},modoEmbebido=true,permiteEntrantes=true,widgetReady=true,transferFlow=null;
   let analystSdkObserver=null,answeredAtMs=0,muted=false;const channel=new BroadcastChannel('impe-telephony-8');
   const publish=patch=>{Object.assign(state,patch,{updatedAt:Date.now()});localStorage.setItem('impe:telephony:state:8',JSON.stringify(state));channel.postMessage({type:'STATE',state});};
   window.zdrmWebPhone={};const isSdkOnlyIncoming=()=>state.context.type==='SDK_INCOMING'&&!state.pbxCallId;
   const clearSdkOnlyIncoming=()=>publish({active:false,phase:'ready',context:{}}),pollIncoming=()=>{},currentDuration=()=>0,actualizarEstadoTrasAccion=()=>{};
   const declinedInvitations=new Map();
   const incomingBridge=IMPE_ZADARMA_NATIVE_BRIDGE_FACTORY.create(document,()=>state.phase==='incoming-ringing',()=>state.phase==='in-progress');
   zdrmWebPhone.answer=()=>{session.established=true;};
   ${host.slice(start,end)}
   ${host.slice(apiStart,apiEnd)}
   let session;window.invite=id=>{session={id,direction:'incoming',remote_identity:{uri:{user:'100'},display_name:'Recepción'},established:false,ended:false,isEstablished(){return this.established;},isEnded(){return this.ended;},terminate(){this.ended=true;}};zdrmWebPhone.webCallSession=session;};
   startAnalystSdkObserver();setInterval(()=>publish({}),500);
  </script>`;
  await page.route('**/*',route=>{const u=new URL(route.request().url());if(u.pathname.startsWith('/public/javascript/'))return route.fulfill({contentType:'application/javascript',body:read(u.pathname.slice(1))});if(u.searchParams.get('action')==='host')return route.fulfill({contentType:'text/html',body:hostHtml});if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:html});if(u.searchParams.has('action'))return route.fulfill({json:{ok:true,entrante:null,devoluciones:0}});return route.fulfill({contentType:'text/html',body:'<main>CRM</main>'});});
  await page.goto(sales?'http://ventas.test/':'http://llamadas.test/');await page.waitForFunction(()=>IMPE_TELEPHONY_SHELL?.isPhoneReady());
  const frame=page.frames().find(f=>f.url().includes('action=host'));assert(frame);
  await frame.evaluate(()=>invite('sip-1'));await page.locator('[data-incoming-answer]').waitFor({state:'visible'});assert(await page.locator('[data-incoming-decline]').isVisible());
  await page.locator('[data-incoming-decline]').click();await page.locator('[data-incoming-answer]').waitFor({state:'hidden'});await page.waitForTimeout(500);assert(!(await page.locator('[data-incoming-answer]').isVisible()));
  await frame.evaluate(()=>invite('sip-2'));await page.locator('[data-incoming-answer]').waitFor({state:'visible'});await page.locator('[data-incoming-answer]').click();await page.waitForFunction(()=>IMPE_TELEPHONY_SHELL.getCallState().phase==='in-progress');
  assert.deepEqual(errors,[]);await page.close();
 }console.log('INVITE → host/observador/bridge → modal real, rechazo y nueva llamada 100→101/150: OK');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
