const assert=require('node:assert/strict'),fs=require('node:fs');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const read=p=>fs.readFileSync(p,'utf8');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});
 try {
  for(const name of ['telefonia_shell.js','telefonia_persistente.js']) {
   const source=read('public/javascript/'+name),host=read('public/javascript/telefonia_host.js');
   const start=source.indexOf("const keypad = document.createElement('details');");
   const end=source.indexOf(name.includes('shell')?'const keypadPilot':'const acciones',start);
   assert(start>0&&end>start);
   const hostStart=host.indexOf("} else if (action === 'IVR_DIGIT') {");
   const guard=host.slice(hostStart+"} else if (action === 'IVR_DIGIT') {".length,host.indexOf("} else if (action === 'MUTE'",hostStart));
   const css=read('public/javascript/telefonia_persistente.js').match(/estilo\.textContent = `([\s\S]*?)`;/)[1];
   const page=await browser.newPage({viewport:{width:390,height:740}}),errors=[];
   page.on('pageerror',e=>errors.push(e.message));
   await page.setContent('<meta charset="utf-8"><style>body{padding:360px 16px 20px;margin:0}'+css+'</style>');
   await page.addScriptTag({content:`window.sent=[];window.commands=[];
    window.RTCPeerConnection=class {constructor(){this.iceConnectionState='connected';this.signalingState='stable';this.connectionState='connected';this.sender={track:{kind:'audio',readyState:'live'},dtmf:{canInsertDTMF:true,toneBuffer:'',insertDTMF(t){sent.push(t);this.toneBuffer=t;}}};}addEventListener(){}getSenders(){return [this.sender]}};`});
   await page.addScriptTag({content:read('public/javascript/telefonia_webrtc_dtmf.js')});
   await page.addScriptTag({content:`IMPE_ZADARMA_WEBRTC_DTMF.install();window.peer=new RTCPeerConnection();
    window.state={active:true,phase:'in-progress',callToken:'call-actual'};window.currentState=state;
    const config={ivrKeypad:true},transferFlow={sourcePending:()=>false,holdsIncoming:()=>false};
    const panel={hidden:false},tokenTecladoAnalista=()=>state.active&&state.phase==='in-progress'?state.callToken:'';
    const publish=p=>Object.assign(state,p),freshState=()=>true;
    const deliver=payload=>{${guard}};
    const sendCommand=(action,payload)=>{commands.push({action,payload});deliver(payload)};
    const sendPhoneCommand=sendCommand;
    ${source.slice(start,end)}
    document.body.appendChild(keypad);keypad.hidden=false;keypad.open=true;
    window.openKeypad=()=>{keypad.open=true};window.keys=keypad;`});
   const keys=page.locator('.impe-ivr-keys button');
   assert.deepEqual(await keys.allTextContents(),'123456789*0#'.split(''));
   for(const key of ['*','0','#'])await keys.filter({hasText:new RegExp('^'+key.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+'$')}).click();
   assert.equal(await page.evaluate(()=>sent.at(-1)),'*0#');
   await page.locator('summary').focus();
   for(const key of ['1','*','#','0'])await page.keyboard.type(key);
   assert.equal(await page.evaluate(()=>sent.at(-1)),'*0#1*#0','teclado físico conserva el orden');
   const before=await page.evaluate(()=>sent.length);
   await page.keyboard.press('Control+1');await page.keyboard.type('a+');
   assert.equal(await page.evaluate(()=>sent.length),before);
   await page.evaluate(()=>{deliver({digit:'#',callToken:'otra-llamada'});deliver({digit:'12',callToken:state.callToken});state.active=false;deliver({digit:'*',callToken:state.callToken})});
   assert.equal(await page.evaluate(()=>sent.length),before,'rechaza llamada ajena, tonos múltiples y llamada finalizada');
   await page.keyboard.press('Escape');assert.equal(await page.evaluate(()=>keys.open),false);
   await page.keyboard.type('#');assert.equal(await page.evaluate(()=>sent.length),before,'cerrado no captura teclado');
   assert.deepEqual(errors,[]);await page.close();
  }
  console.log('CHECK_OK navegador: ambos teclados, botones y teclado físico *0#, orden, modificadores, llamada vigente, cierre y Escape; DTMF WebRTC simulado sin red.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
