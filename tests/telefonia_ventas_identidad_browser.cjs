const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
(async()=>{const b=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'msedge'});try{
 const p=await b.newPage();let requests=[],staleRoute=null;
 await p.route('http://identidad.test/**',r=>{if(new URL(r.request().url()).pathname==='/detail'){if(new URL(r.request().url()).searchParams.has('pendientes'))return r.fulfill({json:{ok:true,pendientes:[]}});const ref=new URL(r.request().url()).searchParams.get('pbx_call_id');requests.push(ref);if(ref==='call-first'&&requests.length>1){staleRoute=r;return;}return r.fulfill({json:{ok:true,llamada:{nombre:ref==='call-first'?'Susana':'Susan',origen_extension:'100'}}});}return r.fulfill({contentType:'text/html',body:'<div data-incoming-panel><div class="persistent-phone-origin"></div><div class="persistent-phone-actions"></div></div><iframe id="impeCrmFrame"></iframe>'});});
 await p.goto('http://identidad.test/');await p.evaluate(()=>{
  window.s={active:true,phase:'in-progress',direction:'incoming',callToken:'sip-first',pbxCallId:'call-first',destination:'+527777885173',context:{sdkConnected:true}};
  window.IMPE_PHONE_SHELL_CONFIG={salesResults:true,userId:8,extension:'150',salesDetailUrl:'/detail'};
  window.IMPE_TELEPHONY_SHELL={getCallState:()=>s,getPhoneStatus:()=>({active:s.active}),isResultFormOpen:()=>false};
 });
 await p.addScriptTag({content:fs.readFileSync('public/javascript/telefonia_ventas_shell.js','utf8')});
 await p.waitForFunction(()=>document.querySelector('.impe-availability-source strong').textContent==='Susana');
 await p.evaluate(()=>{s={...s,active:false,phase:'incoming-finished'};});await p.waitForTimeout(850);
 await p.evaluate(()=>{s={active:true,direction:'incoming',phase:'incoming-ringing',callToken:'sip-second',pbxCallId:'',destination:'+527777885173',context:{}};});
 await p.waitForTimeout(1700);if(staleRoute)await staleRoute.fulfill({json:{ok:true,llamada:{nombre:'Susana',origen_extension:'100'}}});await p.waitForTimeout(100);assert(await p.locator('.impe-availability-source').isHidden());
 const at=requests.length;await p.waitForTimeout(900);assert.equal(requests.length,at,'No consultar pendiente viejo durante llamada nueva sin referencia');
 await p.evaluate(()=>{s.context.transferReference='call-second';});
 await p.waitForFunction(()=>document.querySelector('.impe-availability-source strong').textContent==='Susan');assert(await p.locator('.impe-availability-source').isVisible());
 console.log('Identidad: Susana → fin → mismo número sin referencia → Susan por referencia actual: OK');
}finally{await b.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
