'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),{chromium}=require('playwright');
const source=fs.readFileSync('public/javascript/telefonia_audio_dispositivos.js','utf8');
const css=fs.readFileSync('public/css/telefonia_audio_dispositivos.css','utf8');
const hostSource=fs.readFileSync('public/javascript/telefonia_host.js','utf8');
const hook=hostSource.slice(hostSource.indexOf('    if (window.IMPE_AUDIO_DEVICES_CONFIG?.enabled === true)'),hostSource.indexOf('    const fetchWebRtcSession'));
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  for(const [width,height] of [[1366,768],[375,667]]){
   const context=await browser.newContext({viewport:{width,height}}),page=await context.newPage(),errors=[];
   page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/*',r=>r.fulfill({contentType:r.request().url().endsWith('.css')?'text/css':'text/html',body:r.request().url().endsWith('.css')?css:'<!doctype html><html><body></body></html>'}));
   await page.goto('https://crm.test/');
   await page.setContent('<style>body{margin:0;background:#f3f6fb;font:14px Arial;color:#243c60}header{padding:24px;background:white}main{margin:24px;padding:24px;background:white;border-radius:16px}</style><header>Sistema Comercial · Prueba.llamadas</header><main>Mi teléfono y agenda · Extensión 155</main><iframe id="host" src="/host" style="position:absolute;width:1px;height:1px;left:-10000px" allow="microphone"></iframe>');
   await page.evaluate(()=>{window.IMPE_TELEPHONY_SHELL={ownsPhoneHost:child=>child===document.getElementById('host').contentWindow};});
   await page.waitForFunction(()=>document.getElementById('host').contentDocument?.readyState==='complete');
   const frame=page.frames().find(f=>f.url().endsWith('/host'));assert(frame);
   await frame.evaluate(()=>{
    const input=(deviceId,label)=>({deviceId,label,kind:'audioinput',groupId:deviceId});
    const t=window.MIC_TEST={state:{active:false,phase:'ready'},session:null,calls:[],stops:0,list:[input('default','Predeterminado - Realtek'),input('realtek','Varios micrófonos Realtek'),input('usb','Logi USB Headset')]};
    const media=new EventTarget();media.enumerateDevices=async()=>t.list;
    media.getUserMedia=async c=>{t.calls.push(c);return {getTracks:()=>[{stop:()=>t.stops++}]};};
    Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:media});
    Object.defineProperty(navigator,'permissions',{configurable:true,value:{query:async()=>Object.assign(new EventTarget(),{state:'granted'})}});
    window.RTCPeerConnection=()=>{throw Error('No crear motor');};
    window.IMPE_AUDIO_DEVICES_CONFIG={enabled:true,userId:33,extension:'155',cssUrl:'https://crm.test/audio.css'};
   });
   await frame.addScriptTag({content:source});
   await frame.addScriptTag({content:'const state=MIC_TEST.state;const ownsTransport=true;'+hook});
   await frame.evaluate(()=>IMPE_AUDIO_DEVICES.refresh());
   assert.equal(await frame.evaluate(()=>MIC_TEST.calls.length),0,'Sin captura automática ni permiso');
   const panel=page.locator('[data-impe-audio-devices]');assert.equal(await panel.count(),1);
   await panel.locator('summary').click();
   await page.getByLabel('Micrófono preferido',{exact:true}).selectOption('usb');
   assert.match(await panel.locator('[role=status]').textContent(),/Aún no se ha verificado/);
   await panel.locator('[data-audio-test]').click();
   await frame.waitForFunction(()=>MIC_TEST.stops===1);
   assert.equal(await frame.evaluate(()=>MIC_TEST.calls.length),1);
   // Sender real simulado diferente del preferido; nunca confundir la prueba con la llamada.
   await frame.evaluate(()=>{
    MIC_TEST.state.active=true;MIC_TEST.state.phase='in-progress';MIC_TEST.state.callToken='same-call';
    MIC_TEST.track={kind:'audio',readyState:'live',enabled:true,label:'Varios micrófonos Realtek',getSettings:()=>({deviceId:'realtek'}),getConstraints:()=>({deviceId:'realtek'}),stop:()=>{throw Error('No detener sender');}};
    window.zdrmWebPhone={webCallSession:{connection:{getSenders:()=>[{track:MIC_TEST.track}]}}};
    IMPE_AUDIO_DEVICES.observe();
   });
   assert.match(await panel.locator('[data-audio-used]').textContent(),/Realtek/);
   assert.equal(await panel.locator('[data-audio-test]').isDisabled(),true);
   await frame.evaluate(()=>{navigator.mediaDevices.dispatchEvent(new Event('devicechange'));});
   await frame.evaluate(()=>IMPE_AUDIO_DEVICES.refresh());
   assert.match(await panel.locator('[role=status]').textContent(),/llamada en curso/);
   assert.equal(await frame.evaluate(()=>MIC_TEST.calls.length),1);
   const r=await panel.locator('.impe-audio-devices-body').boundingBox();assert(r.x>=0&&r.y>=0&&r.x+r.width<=width+1&&r.y+r.height<=height);
   assert(r.width<=350&&r.height<height-80,'Panel compacto, sin tapar toda la pantalla');
   fs.mkdirSync('tmp/microfonos-qa',{recursive:true});await page.screenshot({path:'tmp/microfonos-qa/piloto-'+width+'.png'});
   await frame.evaluate(()=>IMPE_AUDIO_DEVICES.dispose());assert.equal(await panel.count(),0);
   assert.deepEqual(errors,[]);await context.close();
  }
  // El hook real con flag apagado no llama al factory ni altera getUserMedia.
  const p=await browser.newPage();await p.goto('about:blank');
  await p.addScriptTag({content:'window.IMPE_AUDIO_DEVICES_FACTORY={create(){throw Error("disabled path");}};'+hook});
  assert.equal(await p.evaluate(()=>window.IMPE_AUDIO_DEVICES),undefined);
  console.log('PASS navegador: hook real, selector y estado sender en escritorio/móvil, permiso voluntario, pistas temporales cerradas, UI única, cambio activo sin capturas y flag apagado.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
