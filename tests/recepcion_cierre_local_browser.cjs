'use strict';
// Shell y adaptador reales en Chrome; centralita simulada y red interceptada.
const assert=require('node:assert/strict'),fs=require('node:fs'),{chromium}=require('playwright');
const shell=fs.readFileSync('public/javascript/telefonia_shell.js','utf8');
const bridge=fs.readFileSync('public/javascript/telefonia_entrantes_bridge.js','utf8');
const host=fs.readFileSync('public/javascript/telefonia_host.js','utf8').replace(/\r\n/g,'\n');
const take=(a,b)=>{const start=host.indexOf(a);assert(start>=0);const end=host.indexOf(b,start+a.length);assert(end>start);return host.slice(start+a.length,end);};
const confirm='const reconcileReceptionDecline=function(){'+take('    const reconcileReceptionDecline = function () {','\n    };')+'};';
const decline='function(){'+take('        decline: function () {','\n        },\n        recheck:')+'}';
const fields=[...new Set([...shell.matchAll(/querySelector\('\[([a-z-]+)\]'/g)].map(m=>m[1]))];
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',r=>r.fulfill({contentType:'text/html',body:'<!doctype html><html><body></body></html>'}));
  await page.goto('https://crm.example.test/');
  await page.evaluate(fields=>{
   Object.defineProperty(navigator,'locks',{value:undefined});
   for(const attr of fields){const el=document.createElement('button');el.setAttribute(attr,'');document.body.append(el);}
   const crm=document.createElement('iframe');crm.id='impeCrmFrame';document.body.append(crm);
   window.IMPE_PHONE_NUMBERS={display:String};
   window.IMPE_PHONE_SHELL_CONFIG={userId:8,extension:'100',autoReception:true,canReceive:true,hostUrl:'https://crm.example.test/host'};
  },fields);
  await page.addScriptTag({content:shell});await page.addScriptTag({content:bridge});
  await page.waitForFunction(()=>document.querySelector('iframe[data-shell-engine]')?.contentDocument?.readyState==='complete');
  const phone=page.frames().find(f=>f.url().endsWith('/host'));assert(phone);
  await phone.addScriptTag({content:`(()=>{
   const sdk=window.zdrmWebPhone={callState:'incoming',calls:0,finishCall(){this.calls++;this.callState='canceling';}};
   const nativeRing=()=>{document.body.innerHTML='<input id="zdrm-webphone-phonenumber-input" class="incoming"><i class="zdrm-webphone-call-ico zdrm-ringing"></i>';};nativeRing();
   const base=id=>({active:true,direction:'incoming',phase:'incoming-ringing',callToken:id,pbxCallId:'pbx-'+id,destination:'5550000000',context:{}});
   let state=base('call-a'),answeredAtMs=0,muted=false;
   const config={},permiteEntrantes=true,widgetReady=true,declinedInvitations=new Map(),analystSdkObserver=null;
   const emptyState=()=>({active:false,direction:'',phase:'idle',context:{}});
   const publish=p=>{Object.assign(state,p);const s={...state,userId:8,updatedAt:Date.now(),hostReady:true,connection:{verified:true}};parent.localStorage.setItem('impe:telephony:state:8',JSON.stringify(s));parent.dispatchEvent(new parent.StorageEvent('storage',{key:'impe:telephony:state:8',newValue:JSON.stringify(s)}));};
   const incomingBridge=parent.IMPE_ZADARMA_NATIVE_BRIDGE_FACTORY.create(document,()=>state.phase==='incoming-ringing',()=>false,()=>state.callToken);
   const ocultarHostEntrante=()=>{},marcarPestanaEntrante=()=>{},actualizarEstadoTrasAccion=()=>{};
   ${confirm}
   window.IMPE_ZADARMA_INCOMING={decline:${decline},recheck:reconcileReceptionDecline,error:incomingBridge.error};
   window.testIdle=()=>{sdk.callState='';document.body.innerHTML='';};
   window.testNext=()=>{state=base('call-b');sdk.callState='incoming';nativeRing();publish(state);};
   setInterval(reconcileReceptionDecline,500);publish(state);
  })();`});
  const panel=page.locator('[data-incoming-panel]');assert.equal(await panel.evaluate(el=>el.hidden),false);
  await page.locator('[data-incoming-decline]').click();
  assert.match(await page.locator('[data-incoming-title]').textContent(),/Rechazando/);
  await page.waitForTimeout(2300);
  assert.equal(await panel.evaluate(el=>el.hidden),false,'No cerrar por tiempo mientras no hay confirmación');
  await phone.evaluate(()=>testIdle());
  await page.waitForFunction(()=>document.querySelector('[data-incoming-panel]').hidden,null,{timeout:6000});
  assert.equal(await page.evaluate(()=>IMPE_TELEPHONY_SHELL.getCallState().active),false);
  assert.equal(await phone.evaluate(()=>zdrmWebPhone.calls),1,'No ejecutar un segundo colgado');
  assert.equal(await page.locator('iframe[data-shell-engine]').count(),1,'Conservar motor montado');
  await phone.evaluate(()=>testNext());
  assert.equal(await panel.evaluate(el=>el.hidden),false,'La siguiente llamada vuelve a mostrar el modal');
  assert.equal(await page.locator('[data-incoming-answer]').evaluate(el=>el.disabled),false);
  await page.locator('[data-incoming-decline]').click();
  await phone.evaluate(()=>testIdle());
  await page.waitForFunction(()=>document.querySelector('[data-incoming-panel]').hidden,null,{timeout:6000});
  assert.equal(await phone.evaluate(()=>zdrmWebPhone.calls),2);
  assert.deepEqual(errors,[]);
  console.log('PASS Chrome: rechazo conserva modal hasta reposo SDK, cierre automático sin END PBX, siguiente llamada operativa y único motor montado.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
