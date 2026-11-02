"use strict";
const assert=require('node:assert/strict'),fs=require('node:fs'),{chromium}=require('playwright');
const source=fs.readFileSync('public/javascript/telefonia_audio_dispositivos.js','utf8');
const css=fs.readFileSync('public/css/telefonia_audio_dispositivos.css','utf8');
const dialerJS=fs.readFileSync('public/javascript/telefonia_teclado.js','utf8');
const dialerCSS=fs.readFileSync('public/css/telefonia_teclado.css','utf8');
const hostSource=fs.readFileSync('public/javascript/telefonia_host.js','utf8');
const hook=hostSource.slice(hostSource.indexOf('    if (window.IMPE_AUDIO_DEVICES_CONFIG?.enabled === true)'),hostSource.indexOf('    const fetchWebRtcSession'));
const crmHTML=`<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
body{margin:0;background:#f3f6fb;color:#243c60;font:14px Arial}header{padding:14px 18px;background:#fff;display:flex;align-items:center;justify-content:space-between;gap:12px}h1{font-size:19px}.topbar-actions{display:flex;align-items:center;gap:10px}.topbar-reminder-button{border:1px solid #dbe4f1;border-radius:14px;background:#fff;color:#2d3f8f;width:52px;height:52px;font-size:20px}main{padding:18px}.card{max-width:420px;background:white;padding:18px;border:1px solid #dbe4f1;border-radius:16px}form>button{padding:12px 20px;background:#2d3f8f;color:white;border:0;border-radius:10px}input{display:block;box-sizing:border-box;width:100%;margin:10px 0;padding:12px;border:1px solid #dbe4f1;border-radius:10px}form>label{font-weight:bold}@media(max-width:575px){header{padding:8px;gap:6px}.topbar-actions{gap:6px}h1{font-size:15px}.topbar-reminder-button{width:42px;height:42px}main{padding:10px}}
</style></head><body><header><h1>Mi teléfono</h1><div class="topbar-actions"><button data-telephony-reception-toggle class="topbar-reminder-button" aria-label="Teléfono">☎</button><button class="topbar-reminder-button" aria-label="Notificaciones">♧</button></div></header><main><div class="card"><h2>Marcar número</h2><form data-telephony-dialer-form><label>Número a marcar<input type="tel" value="5512345678"></label><button data-telephony-dial-call type="submit">Llamar</button></form></div></main><script>document.querySelector('form').addEventListener('submit',e=>{e.preventDefault();window.submits=(window.submits||0)+1;});</script></body></html>`;
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  for(const [extension,width,height] of [['100',1366,768],['101',1366,768],['150',1366,768],['155',375,667],['155',320,568],['155',1024,320]]){
   const context=await browser.newContext({viewport:{width,height}}),page=await context.newPage(),errors=[];
   page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/*',r=>{const url=r.request().url();return r.fulfill({contentType:url.endsWith('.css')?'text/css':'text/html',body:url.endsWith('.css')?css:url.includes('/crm')?crmHTML:'<!doctype html><html><body></body></html>'});});
   await page.goto('https://impe.test/');
   await page.setContent('<style>html,body{height:100%;margin:0;overflow:hidden}#impeCrmFrame{position:fixed;left:0;top:0;border:0;width:100%;height:100%}</style><iframe id="impeCrmFrame" src="/crm"></iframe><iframe id="host" src="/host" style="position:absolute;width:1px;height:1px;left:-10000px" allow="microphone"></iframe>');
   await page.evaluate(()=>{window.IMPE_TELEPHONY_SHELL={ownsPhoneHost:child=>child===document.getElementById('host').contentWindow};});
   await page.waitForFunction(()=>document.getElementById('host').contentDocument?.readyState==='complete'&&document.getElementById('impeCrmFrame').contentDocument?.querySelector('.topbar-actions'));
   const frame=page.frames().find(f=>f.url().endsWith('/host')),crm=page.frames().find(f=>f.url().endsWith('/crm'));assert(frame&&crm);
   await frame.evaluate(extension=>{
    const input=(deviceId,label)=>({deviceId,label,kind:'audioinput',groupId:deviceId});
    window.MIC_TEST={state:{active:false,phase:'ready'},calls:[],stops:0,list:[input('default','Predeterminado - Realtek'),input('realtek','Varios micrófonos Realtek'),input('usb','Logi USB Headset')]};
    const media=new EventTarget();media.enumerateDevices=async()=>MIC_TEST.list;
    media.getUserMedia=async c=>{MIC_TEST.calls.push(c);return {getTracks:()=>[{stop:()=>MIC_TEST.stops++}]};};
    Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:media});
    Object.defineProperty(navigator,'permissions',{configurable:true,value:{query:async()=>Object.assign(new EventTarget(),{state:'granted'})}});
    window.RTCPeerConnection=()=>{throw Error('No crear motor');};
    window.IMPE_AUDIO_DEVICES_CONFIG={enabled:true,userId:33,extension,cssUrl:'https://impe.test/audio.css'};
   },extension);
   await frame.addScriptTag({content:source});
   await frame.addScriptTag({content:'const state=MIC_TEST.state;const ownsTransport=true;'+hook});
   await frame.evaluate(()=>IMPE_AUDIO_DEVICES.refresh());
   const panel=page.locator('[data-impe-audio-devices]'),toggle=crm.locator('[data-audio-devices-toggle]'),advice=crm.locator('[data-audio-before-call]');
   await toggle.waitFor();assert.equal(await panel.count(),1);assert.equal(await panel.isHidden(),true);
   assert.equal(await crm.evaluate(()=>document.querySelector('[data-telephony-reception-toggle]').nextElementSibling.hasAttribute('data-audio-devices-toggle')),true,'Acceso pegado al auricular');
   assert.equal(await advice.isVisible(),true);assert.equal(await frame.evaluate(()=>MIC_TEST.calls.length),0,'Sin capturas al mostrar aviso');
   await toggle.click();assert.equal(await panel.isVisible(),true);
   await panel.getByLabel('Micrófono preferido',{exact:true}).selectOption('usb');
   assert.match(await panel.locator('[role=status]').textContent(),/Aún no se ha verificado/);
   await panel.locator('[data-audio-preferred]').click();assert.equal(await panel.isVisible(),true,'Clic dentro conserva panel');
   await crm.locator('h1').click();assert.equal(await panel.isHidden(),true,'Clic fuera en iframe cierra');
   await toggle.click();await page.keyboard.press('Escape');assert.equal(await panel.isHidden(),true,'Escape cierra y devuelve foco');
   await advice.locator('[data-audio-review]').click();assert.equal(await panel.isVisible(),true);
   await panel.locator('[data-audio-test]').click();await frame.waitForFunction(()=>MIC_TEST.stops===1);
   assert.equal(await frame.evaluate(()=>MIC_TEST.calls.length),1);
   await panel.locator('[data-audio-reviewed]').click();assert.equal(await panel.isHidden(),true);assert.equal(await advice.isHidden(),true);
   assert.equal(await frame.evaluate(()=>IMPE_AUDIO_DEVICES.snapshot().actual),null,'Revisión manual no inventa sender');
   await frame.evaluate(()=>navigator.mediaDevices.dispatchEvent(new Event('devicechange')));await frame.evaluate(()=>IMPE_AUDIO_DEVICES.refresh());
   assert.equal(await advice.isVisible(),true,'Cambio de hardware vuelve a pedir revisión');
   // El aviso no cancela ni duplica submits de la marcación ya existente.
   await crm.locator('[data-telephony-dial-call]').click();assert.equal(await crm.evaluate(()=>submits),1);
   // Marcador flotante REAL del shell: aviso visible y API startCall una sola vez.
   await page.addStyleTag({content:dialerCSS});await page.addScriptTag({content:dialerJS});
   await page.evaluate(extension=>{
    window.ops=[];const api={probe:async()=>({permite_salientes:true}),startCall:async p=>ops.push(p)};
    window.dial=IMPE_TELEPHONY_DIALER.create({extension,getApi:()=>api,getState:()=>({active:false}),hasOutgoingRole:()=>true,singleCallPanel:true});
    dial.open({number:'55 1234 5678'});
   },extension);
   const floatingAdvice=page.locator('[data-dialer-form] [data-audio-before-call]');await floatingAdvice.waitFor();
   await floatingAdvice.locator('[data-audio-review]').click();assert.equal(await panel.isVisible(),true,'Panel del shell visible sobre marcador');
   await panel.locator('[data-audio-close]').click();await page.locator('[data-dialer-call]').click();
   assert.equal(await page.evaluate(()=>ops.length),1,'No impedir ni repetir el inicio original');
   // Al comenzar una llamada no tapar Contestar/Colgar con el panel de dispositivos.
   await toggle.click();assert.equal(await panel.isVisible(),true);
   // Cambio activo no toca pista, SDP, SIP ni getUserMedia.
   await frame.evaluate(()=>{
    MIC_TEST.state.active=true;MIC_TEST.state.phase='in-progress';MIC_TEST.state.callToken='same-call';
    MIC_TEST.track={kind:'audio',readyState:'live',enabled:true,label:'Varios micrófonos Realtek',getSettings:()=>({deviceId:'realtek'}),getConstraints:()=>({deviceId:'realtek'}),stop:()=>{throw Error('No detener sender');}};
    window.zdrmWebPhone={webCallSession:{connection:{getSenders:()=>[{track:MIC_TEST.track}]}}};IMPE_AUDIO_DEVICES.observe();
   });
   assert.equal(await panel.isHidden(),true,'Inicio de llamada deja libres los controles');
   await toggle.click();assert.match(await panel.locator('[data-audio-used]').textContent(),/Realtek/);
   assert.equal(await panel.locator('[data-audio-test]').isDisabled(),true);assert.equal(await panel.locator('[data-audio-reviewed]').isDisabled(),true);
   await frame.evaluate(()=>navigator.mediaDevices.dispatchEvent(new Event('devicechange')));await frame.evaluate(()=>IMPE_AUDIO_DEVICES.refresh());
   assert.match(await panel.locator('[role=status]').textContent(),/llamada en curso/);assert.equal(await frame.evaluate(()=>MIC_TEST.calls.length),1);
   const box=await panel.boundingBox();assert(box.x>=0&&box.y>=0&&box.x+box.width<=width+1&&box.y+box.height<=height+1);
   assert.equal(await panel.evaluate(e=>getComputedStyle(e).overflowY),'auto');
   fs.mkdirSync('tmp/microfonos-qa',{recursive:true});await page.screenshot({path:`tmp/microfonos-qa/encabezado-${extension}-${width}-${height}.png`});
   // Navegar el CRM no destruye el host; reconstruye sólo el acceso del encabezado.
   await page.locator('#impeCrmFrame').evaluate(el=>el.src='/crm?second=1');await crm.waitForURL('**/crm?second=1');
   await crm.locator('[data-audio-devices-toggle]').waitFor();assert.equal(await panel.isHidden(),true);
   assert.equal(await crm.locator('[data-audio-devices-toggle]').count(),1);assert.equal(await page.locator('#host').count(),1);
   await crm.locator('[data-audio-devices-toggle]').click();assert.equal(await panel.isVisible(),true);
   await page.evaluate(()=>{const b=document.createElement('button');b.id='outside';b.textContent='Fuera';b.style.cssText='position:fixed;left:0;bottom:0;z-index:2300';document.body.append(b);});
   await page.locator('#outside').click();assert.equal(await panel.isHidden(),true,'Clic fuera en shell cierra');
   await frame.evaluate(()=>IMPE_AUDIO_DEVICES.dispose());assert.equal(await panel.count(),0);assert.equal(await crm.locator('[data-audio-devices-toggle]').count(),0);
   assert.equal(await crm.locator('[data-audio-before-call]').count(),0);assert.deepEqual(errors,[]);await context.close();
  }
  const p=await browser.newPage();await p.goto('about:blank');
  await p.addScriptTag({content:'window.IMPE_AUDIO_DEVICES_FACTORY={create(){throw Error("disabled path");}};'+hook});
  assert.equal(await p.evaluate(()=>window.IMPE_AUDIO_DEVICES),undefined);
  console.log('PASS encabezado: 6 perfiles/ventanas, acceso junto al auricular, aviso previo, revisión sin sender ficticio, clic fuera en ambos documentos/Escape, navegación sin duplicados, marcador real sin bloqueo y cambios activos sin capturas.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
