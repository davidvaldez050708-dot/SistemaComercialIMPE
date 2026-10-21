const fs=require('node:fs'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const read=p=>fs.readFileSync(p,'utf8').replace(/\r\n/g,'\n'),host=read('public/javascript/telefonia_host.js');
const start=host.indexOf('    const startAnalystSdkObserver = function () {'),end=host.indexOf('\n    const ensureWidget',start);
const incomingStart=host.indexOf('    const applyIncomingState = function (call) {'),incomingEnd=host.indexOf('\n    const pollIncoming',incomingStart);
const apiStart=host.indexOf('    window.IMPE_ZADARMA_INCOMING = Object.freeze({'),apiEnd=host.indexOf('\n    const stopPolling',apiStart);
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});try{
 for(const sales of [false,true]) for(const origin of ['Entrante','Saliente']){
  const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const html=cp.execFileSync('php',[sales?'tests/fixtures/telefonia_ventas_shell.php':'tests/fixtures/telefonia_flujo_shell.php',...(sales?[]:['analista'])],{encoding:'utf8'});
  const phpHost=cp.execFileSync('php',['tests/fixtures/telefonia_host_observador.php',JSON.stringify({role:sales?'Asesor de Ventas':'Analista de Datos'})],{encoding:'utf8'});
  const hostConfig=JSON.parse(phpHost.match(/window\.IMPE_TELEPHONY_HOST = (\{[^;]+\});/)[1]);
  assert.equal(hostConfig.analystSdkIncoming,true,'La configuración PHP real debe habilitar el observador para este rol');
  assert(phpHost.includes('public/javascript/telefonia_sdk_entrantes_observador.js?'),'La vista real debe cargar el mismo observador');
  const hostHtml=`<!doctype html><body><script>${read('public/javascript/telefonia_sdk_entrantes_observador.js')}</script><script>${read('public/javascript/telefonia_entrantes_bridge.js')}</script><script>
   let state={userId:8,hostReady:true,active:false,phase:'ready',direction:'incoming',context:{}};
   const config=${JSON.stringify(hostConfig)},modoEmbebido=true,permiteEntrantes=config.permiteEntrantes,widgetReady=true,transferFlow=null;
   let analystSdkObserver=null,answeredAtMs=0,muted=false;const channel=new BroadcastChannel('impe-telephony-8');
   const publish=patch=>{Object.assign(state,patch,{updatedAt:Date.now()});localStorage.setItem('impe:telephony:state:8',JSON.stringify(state));channel.postMessage({type:'STATE',state});};
   window.zdrmWebPhone={};const isSdkOnlyIncoming=()=>state.context.type==='SDK_INCOMING'&&!state.pbxCallId;
   const clearSdkOnlyIncoming=()=>publish({active:false,phase:'ready',context:{}}),pollIncoming=()=>{},currentDuration=()=>0,actualizarEstadoTrasAccion=()=>{};
   const declinedInvitations=new Map(),retiredIds=new Set(),extension=config.extension;
   const retirePbxCall=id=>retiredIds.add(id);
   const declinedInvitation=()=>false,marcarPestanaEntrante=()=>{},mostrarHostEntrante=()=>{},ocultarHostEntrante=()=>{};
   const finalizarEntrante=()=>publish({active:false,phase:'incoming-finished'});
   ${host.slice(incomingStart,incomingEnd)}
   window.pbxRing=()=>applyIncomingState({pbx_call_id:'pbx-inbound-100',estado:'ringing',caller_id:'+527777885173',transfer_from:'100'});
   const incomingBridge=IMPE_ZADARMA_NATIVE_BRIDGE_FACTORY.create(document,()=>state.phase==='incoming-ringing',()=>state.phase==='in-progress',()=>state.callToken);
   zdrmWebPhone.answer=()=>{session.established=true;};
   zdrmWebPhone.finishCall=()=>{session.ended=true;zdrmWebPhone.webCallSession=null;};
   ${host.slice(start,end)}
   ${host.slice(apiStart,apiEnd)}
   let session;window.invite=(id,caller='100')=>{session={id,direction:'incoming',remote_identity:{uri:{user:caller},display_name:'Recepción'},established:false,ended:false,isEstablished(){return this.established;},isEnded(){return this.ended;},terminate(){this.ended=true;}};zdrmWebPhone.webCallSession=session;};
   window.pushInvite=()=>{
    zdrmWebPhone.webCallSession=null;zdrmWebPhone.callState='incoming';
    document.body.insertAdjacentHTML('beforeend','<input id="zdrm-webphone-phonenumber-input" class="incoming" value="7777885173"><i class="zdrm-webphone-call-ico zdrm-ringing"></i>');
   };
   zdrmWebPhone.cancel=()=>{zdrmWebPhone.callState='';document.querySelector('input')?.remove();document.querySelector('i')?.remove();};
   const answerSip=zdrmWebPhone.answer;zdrmWebPhone.answer=()=>{if(!zdrmWebPhone.webCallSession){invite('sip-from-push','7777885173');zdrmWebPhone.cancel();}answerSip();};
   startAnalystSdkObserver();setInterval(()=>publish({}),500);
  </script>`;
  await page.route('**/*',route=>{const u=new URL(route.request().url());if(u.pathname.startsWith('/public/javascript/'))return route.fulfill({contentType:'application/javascript',body:read(u.pathname.slice(1))});if(u.searchParams.get('action')==='host')return route.fulfill({contentType:'text/html',body:hostHtml});if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:html});if(u.searchParams.has('action'))return route.fulfill({json:{ok:true,entrante:null,devoluciones:0}});return route.fulfill({contentType:'text/html',body:'<main>CRM</main>'});});
  await page.goto(sales?'http://ventas.test/':'http://llamadas.test/');await page.waitForFunction(()=>IMPE_TELEPHONY_SHELL?.isPhoneReady());
  const frame=page.frames().find(f=>f.url().includes('action=host'));assert(frame);
  if(origin==='Entrante'){
   await frame.evaluate(()=>pbxRing());await page.locator('[data-incoming-answer]').waitFor({state:'visible'});
   await frame.evaluate(()=>invite('sip-1'));
  }else{
   // PUSH real del SDK precede a RTCSession, sin INTERNAL del destino.
   await frame.evaluate(()=>pushInvite());
  }
  await page.locator('[data-incoming-answer]').waitFor({state:'visible'});assert(await page.locator('[data-incoming-decline]').isVisible());
  await page.locator('[data-incoming-decline]').click();await page.locator('[data-incoming-answer]').waitFor({state:'hidden'});await page.waitForTimeout(500);assert(!(await page.locator('[data-incoming-answer]').isVisible()));
  await frame.evaluate(()=>invite('sip-2'));await page.locator('[data-incoming-answer]').waitFor({state:'visible'});await page.locator('[data-incoming-answer]').click();await page.waitForFunction(()=>IMPE_TELEPHONY_SHELL.getCallState().phase==='in-progress');
  // Otro diálogo llega antes del END PBX del anterior; el origen saliente
  // puede llegar por PUSH/SIP sin el NOTIFY_INTERNAL de la ruta entrante.
  await frame.evaluate(origin=>{session.ended=true;publish({phase:'recovering'});invite('sip-3',origin==='Saliente'?'7777885173':'100');},origin);
  await page.locator('[data-incoming-answer]').waitFor({state:'visible'});
  await page.locator('[data-incoming-decline]').click();
  await page.locator('[data-incoming-answer]').waitFor({state:'hidden'});
  if(origin==='Saliente'){
   await frame.evaluate(()=>{session.ended=true;zdrmWebPhone.webCallSession=null;zdrmWebPhone.callState='';});
   await page.waitForTimeout(300);
   await frame.evaluate(()=>pushInvite());await page.locator('[data-incoming-answer]').waitFor({state:'visible'});
   await page.locator('[data-incoming-decline]').click();await page.locator('[data-incoming-answer]').waitFor({state:'hidden'});
   await page.waitForTimeout(500);assert(!(await page.locator('[data-incoming-answer]').isVisible()));
   await frame.evaluate(()=>pushInvite());await page.locator('[data-incoming-answer]').waitFor({state:'visible'});
   await page.locator('[data-incoming-answer]').click();await page.waitForFunction(()=>IMPE_TELEPHONY_SHELL.getCallState().phase==='in-progress');
  }
  // Cuelga el celular tras contestar: END de la sesión observada sin END PBX aún.
  await frame.evaluate(()=>{if(session)session.ended=true;zdrmWebPhone.webCallSession=null;zdrmWebPhone.cancel();});
  await page.waitForFunction(()=>!IMPE_TELEPHONY_SHELL.getCallState().active);
  await frame.evaluate(()=>invite('sip-unanswered'));
  await page.locator('[data-incoming-answer]').waitFor({state:'visible'});
  await frame.evaluate(()=>{session.ended=true;zdrmWebPhone.webCallSession=null;});
  await page.waitForFunction(()=>!IMPE_TELEPHONY_SHELL.getCallState().active);
  assert.notEqual(await page.evaluate(()=>IMPE_TELEPHONY_SHELL.getCallState().phase),'recovering');
  await frame.evaluate(()=>invite('sip-user-hangup'));
  await page.locator('[data-incoming-answer]').click();await page.waitForFunction(()=>IMPE_TELEPHONY_SHELL.getCallState().phase==='in-progress');
  await page.locator('[data-incoming-hangup]').click();await page.waitForFunction(()=>!IMPE_TELEPHONY_SHELL.getCallState().active);
  assert.deepEqual(errors,[]);await page.close();
 }console.log('INVITE → host/observador/bridge → modal real, rechazo y nueva llamada 100→101/150: OK');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
