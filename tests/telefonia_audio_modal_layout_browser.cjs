const fs=require('fs'),vm=require('vm'),assert=require('assert/strict'),{chromium}=require('playwright');
const read=p=>fs.readFileSync(p,'utf8');
const phone=read('public/javascript/telefonia_persistente.js');
const start=phone.indexOf('        panel.innerHTML =')+'        panel.innerHTML ='.length;
const end=phone.indexOf('        document.body.appendChild(panel);',start);
const markup=vm.runInNewContext(phone.slice(start,end).trim().replace(/;$/,''));
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{channel:'chrome'})});try{
for(const width of [1366,768,390,320]){
 const page=await browser.newPage({viewport:{width,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://modal.test/**',r=>r.fulfill({contentType:r.request().url().includes('.css')?'text/css':'text/html',body:r.request().url().includes('.css')?read('public/css/telefonia_audio_dispositivos.css'):'<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body></body></html>'}));
 await page.goto('https://modal.test/?display=tab');
 await page.addStyleTag({content:read('public/css/telefonia_persistente.css')+read('public/css/telefonia_shell.css')+'html,body{margin:0;font-family:Manrope,Arial,sans-serif}.persistent-phone-panel{width:700px;max-width:calc(100vw - 20px);left:10px;top:80px;bottom:auto}'});
 await page.evaluate(markup=>{document.body.innerHTML='<header class="topbar-actions"><button data-telephony-reception-toggle>Teléfono</button></header><section class="persistent-phone-panel">'+markup+'</section>';document.querySelector('[data-phone-transfer-toggle]').hidden=true;
 const media=new EventTarget();media.enumerateDevices=async()=>[];media.getUserMedia=async()=>{throw Error('No capturar audio');};Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:media});Object.defineProperty(navigator,'permissions',{configurable:true,value:{query:async()=>{throw Error('Sin permisos ficticios');}}});window.state={active:false};},markup);
 await page.addScriptTag({content:read('public/javascript/telefonia_audio_dispositivos.js')});
 await page.evaluate(()=>{window.audio=IMPE_AUDIO_DEVICES_FACTORY.create(window,{enabled:true,userId:99,extension:'101',cssUrl:'https://modal.test/audio.css'},()=>state,()=>null,()=>true);});
 const advice=page.locator('[data-phone-active-actions] > [data-audio-before-call]');await advice.waitFor();await page.waitForTimeout(50);
 if(width===1366){fs.mkdirSync('tmp',{recursive:true});await page.screenshot({path:'tmp/analista-modal-corregido.png'});}
 const a=await advice.boundingBox(),grid=await page.locator('[data-phone-active-actions]').boundingBox();
 assert.ok(Math.abs(a.width-grid.width)<2,'El aviso debe ocupar toda la fila a '+width+'px');
 for(const selector of ['[data-phone-start]','[data-phone-mute]','[data-phone-hangup]']){const b=await page.locator(selector).boundingBox();assert.ok(b.y>=a.y+a.height-1,'Aviso separado de acciones');assert.ok(b.height>=44&&b.height<=60,'Altura habitual de botones');}
 await page.locator('[data-audio-review]').click();assert.ok(await page.locator('[data-impe-audio-devices]').isVisible());await page.mouse.click(width-2,898);assert.ok(await page.locator('[data-impe-audio-devices]').isHidden());
 await page.evaluate(()=>document.querySelector('[data-phone-transfer-toggle]').hidden=false);
 assert.ok((await page.locator('[data-phone-transfer-toggle]').boundingBox()).height<=60,'Transferir conserva su tamaño');
 await page.evaluate(()=>{state.active=true;audio.observe();});assert.ok(await advice.isHidden(),'Ocultar aviso durante llamada');
 await page.evaluate(()=>{state.active=false;audio.observe();});assert.ok(await advice.isVisible(),'Conservar aviso después');assert.deepEqual(errors,[]);await page.close();
}
console.log('PASS modal compartido Analista: markup real, aviso real a ancho completo, controles habituales, cuatro tamaños y revisión de micrófono intacta');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
