/* Transporte simulado, Web Locks reales y código de host/shell de producción. */
'use strict';
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),php=process.env.PHP_BIN||'php';
const zlib=require('node:zlib');
const hostinger=process.env.IMPE_PHONE_HOSTINGER_VARIANT==='1';
const variants=hostinger?JSON.parse(fs.readFileSync('tools/telefonia_conexion_release.json','utf8')).files:[];
const variantSource=name=>{const row=variants.find(r=>r.path===name);return row?zlib.gunzipSync(Buffer.from(row.variants.at(-1).after,'base64')).toString('utf8'):null;};
const render=(kind,role,out)=>{
 let html=execFileSync(php,['tests/fixtures/telefonia_conexion.php',kind,role,out?'salientes':''],{cwd:root,encoding:'utf8'});
 if(hostinger&&kind==='shell')html=html.replace(fs.readFileSync('public/javascript/telefonia_shell.js','utf8'),variantSource('public/javascript/telefonia_shell.js'));
 return html;
};
const sdk=`
window.__sdkStarts=0; window.__socketConnects=0;
window.io=function(){
 const events={};const socket={connected:false,on(n,fn){(events[n]??=[]).push(fn);return this;},
  connect(){window.__socketConnects++;return this;},
  emitTest(n){if(n==='init')this.connected=true;if(n==='disconnect')this.connected=false;for(const fn of events[n]||[])fn();}};
 window.__socket=socket;return socket;
};
window.io.protocol=4;
window.zdrmWebrtcPhoneInterface=function(){};
window.zadarmaWidgetFn=function(){window.__sdkStarts++;window.io('https://ws.zadarma.com/webrtc_pushes?key=synthetic');
 window.zdrmWebPhone={regToCall(){},callState:'',webCallSession:null};};`;
let checks=0;const check=(value,msg)=>{assert.ok(value,msg);checks++;};
(async()=>{
 const browser=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||undefined,
  args:['--autoplay-policy=no-user-gesture-required','--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream']});
 try {
 for(const [role,out] of [['Marketing',false],['Analista de Datos',false],['Asesor de Ventas',false],['Asesor de Ventas',true]]){
  const context=await browser.newContext({permissions:['microphone'],viewport:{width:1366,height:900}});
  let keyRequests=0;
  const errors=[];context.on('page',p=>p.on('pageerror',e=>errors.push(e.message)));
  await context.route('**/*',async route=>{
   const url=new URL(route.request().url());
   if(url.hostname==='phone.test'){
    if(url.pathname==='/shell')return route.fulfill({contentType:'text/html',body:render('shell',role,out)});
    if(url.searchParams.get('action')==='host')return route.fulfill({contentType:'text/html',body:render('host',role,out)});
    if(url.pathname.endsWith('zadarma_webrtc.php')){
     keyRequests++;
     if(out && keyRequests<=2)return route.fulfill({status:503,json:{ok:false,mensaje:'Fallo temporal sintético'}});
     return route.fulfill({json:{ok:true,webrtc_key:'synthetic',sip_login:'test-101'}});
    }
    if(url.pathname.startsWith('/public/')){
     const file=path.join(root,url.pathname);
     const custom=variantSource(url.pathname.slice(1));if(custom)return route.fulfill({body:custom,contentType:url.pathname.endsWith('.js')?'application/javascript':'text/html'});
     if(fs.existsSync(file))return route.fulfill({path:file});
    }
    if(url.pathname.includes('/api/'))return route.fulfill({json:{ok:true,call:null,items:[],datos:[]}});
    return route.fulfill({contentType:'text/html',body:'<!doctype html><title>Módulo sintético</title><h1>CRM</h1>'});
   }
   if(url.pathname.includes('loader-phone-lib'))return route.fulfill({contentType:'application/javascript',body:sdk});
   if(url.pathname.includes('loader-phone-fn'))return route.fulfill({contentType:'application/javascript',body:''});
   return route.fulfill({body:'',contentType:'text/css'});
  });
  const page=await context.newPage();await page.goto('https://phone.test/shell');
  const host=()=>page.frames().find(f=>f.url().includes('action=host'));
  await page.waitForFunction(()=>!!document.querySelector('[data-shell-engine]'));
  if(out){
   await page.waitForFunction(()=>document.querySelector('[data-shell-engine]')?.contentWindow.IMPE_PHONE_CONNECTION);
   await host().evaluate(()=>{window.__baseNow=Date.now;});
   for(let step=1;step<=2;step++){
    await page.waitForTimeout(100);
    await host().evaluate(step=>{Date.now=()=>__baseNow()+step*120000;return IMPE_PHONE_CONNECTION.check();},step);
   }
   check(keyRequests===3,'dos errores consecutivos se recuperan automáticamente');
   await host().evaluate(()=>{Date.now=__baseNow;});
  }
  await page.waitForFunction(()=>document.querySelector('[data-shell-engine]')?.contentWindow.__sdkStarts===1);
  check(await host().evaluate(()=>window.__sdkStarts===1),role+': inicio automático único');
  check(!(await page.evaluate(()=>IMPE_TELEPHONY_SHELL.isPhoneReady())),role+': SDK cargado no equivale a conexión');
  await host().evaluate(()=>__socket.emitTest('init'));
  await page.waitForFunction(()=>IMPE_TELEPHONY_SHELL.isPhoneReady());checks++;
  check(await host().evaluate(()=>!IMPE_PHONE_CONNECTION.snapshot().sipRegistered),role+': push confirmado no inventa registro SIP');
  await host().evaluate(()=>{zdrmWebPhone.webPhoneUA={isRegistered:()=>true,isConnected:()=>true};});
  check(await host().evaluate(()=>IMPE_PHONE_CONNECTION.snapshot().sipRegistered),role+': SIP real separado');
  await host().evaluate(()=>{delete zdrmWebPhone.webPhoneUA;});
  // Restricción de audio: la evidencia del canal no basta para poner verde.
  await host().evaluate(()=>{window.__audioStatus=IMPE_CALL_AUDIO.getStatus;IMPE_CALL_AUDIO.getStatus=()=> 'suspended';});
  check(!(await page.evaluate(()=>IMPE_TELEPHONY_SHELL.isPhoneReady())),role+': audio bloqueado no es disponible');
  await host().evaluate(()=>IMPE_PHONE_CONNECTION.check());
  await page.waitForFunction(()=>!document.querySelector('[data-shell-retry]').hidden && document.querySelector('[data-shell-status-text]').textContent.includes('Activar teléfono'));
  check((await page.locator('[data-shell-status-text]').textContent()).includes('Activar teléfono'),role+': explicación de permiso visible');
  await host().evaluate(()=>{IMPE_CALL_AUDIO.getStatus=__audioStatus;});
  await page.locator('[data-shell-retry]').click();
  await page.waitForFunction(()=>IMPE_TELEPHONY_SHELL.isPhoneReady());
  const direct=await context.newPage();
  await direct.goto('https://phone.test/index.php?controller=telefonia&action=host&display=tab');
  await direct.waitForTimeout(100);
  check(await direct.evaluate(()=>window.__sdkStarts===undefined),role+': pestaña directa espera al dueño');
  check(await page.evaluate(()=>IMPE_TELEPHONY_SHELL.isPhoneReady()),role+': pestaña directa no sobreescribe estado');
  await direct.close();
  const second=await context.newPage();await second.goto('https://phone.test/shell');
  await second.waitForTimeout(150);
  check(await second.locator('[data-shell-engine]').count()===0,role+': segunda pestaña no compite');
  for(const minutes of [5,15,30,60]){
   // Salto de reloj sin ejecutar los intervalos intermedios: simula timers suspendidos.
   await host().evaluate(minutes=>{const original=Date.now;window.__realNow??=original;Date.now=()=>__realNow()+minutes*60000;},minutes);
   check(!(await host().evaluate(()=>IMPE_PHONE_CONNECTION.snapshot().verified)),role+': evidencia vieja no pone verde');
   await host().evaluate(()=>{__socket.emitTest('pong');document.dispatchEvent(new Event('visibilitychange'));});
   check(await host().evaluate(()=>IMPE_PHONE_CONNECTION.snapshot().verified),role+': vuelve evidencia servidor tras '+minutes+' min');
   check(await host().evaluate(()=>__sdkStarts===1),role+': no reinicia tras background');
   check(await second.locator('[data-shell-engine]').count()===0,role+': mantiene dueño sin lease cronometrado');
  }
  await host().evaluate(()=>{Date.now=__realNow;__socket.emitTest('disconnect');});
  await context.setOffline(true);
  check(!(await host().evaluate(()=>IMPE_PHONE_CONNECTION.snapshot().online)),role+': navegador offline real');
  const offlineRetries=await host().evaluate(()=>__socketConnects);
  await host().evaluate(()=>IMPE_PHONE_CONNECTION.check());
  check(await host().evaluate(()=>__socketConnects)===offlineRetries,role+': sin reintentos mientras offline');
  await context.setOffline(false);
  check(!(await page.evaluate(()=>IMPE_TELEPHONY_SHELL.isPhoneReady())),role+': desconexión quita verde inmediatamente');
  await host().evaluate(()=>IMPE_PHONE_CONNECTION.check());
  check(await host().evaluate(()=>__socketConnects>=1),role+': reconecta mismo socket');
  const prior=await host().evaluate(()=>__socketConnects);
  await host().evaluate(()=>Promise.all(Array.from({length:8},()=>IMPE_PHONE_CONNECTION.check())));
  check(await host().evaluate(()=>__socketConnects)===prior,role+': no superpone reintentos');
  await host().evaluate(()=>{zdrmWebPhone.webCallSession={isEnded:()=>false};dispatchEvent(new Event('offline'));});
  await host().evaluate(()=>{Date.now=()=>__realNow()+120000;});
  await host().evaluate(()=>IMPE_PHONE_CONNECTION.check());
  check(await host().evaluate(()=>__socketConnects)===prior,role+': llamada viva protegida');
  await host().evaluate(()=>{zdrmWebPhone.webCallSession=null;Date.now=__realNow;__socket.emitTest('init');dispatchEvent(new Event('online'));});
  check(await page.evaluate(()=>IMPE_TELEPHONY_SHELL.isPhoneReady()),role+': recupera sin recarga');
  await host().evaluate(()=>IMPE_PHONE_CONNECTION.check());
  check(await host().evaluate(()=>JSON.parse(localStorage.getItem('impe:telephony:state:8')).phase!=='error'),role+': limpia error al recuperar');
  check(await page.evaluate(()=>IMPE_TELEPHONY_SHELL.getReceptionStatus().assigned)===!out,role+': permisos entrantes conservados');
  await page.evaluate(()=>{document.getElementById('impeCrmFrame').src='https://phone.test/modulo';});
  check(await host().evaluate(()=>__sdkStarts===1),role+': navegación conserva motor');
  await page.close();await second.waitForFunction(()=>!!document.querySelector('[data-shell-engine]'));
  check(await second.locator('[data-shell-engine]').count()===1,role+': cierre del dueño entrega bloqueo');
  check(errors.length===0,role+': sin errores JS: '+errors.join('; '));
  await context.close();
 }
 console.log('PASS conexión navegador '+(hostinger?'Hostinger':'GitHub')+': '+checks+' comprobaciones; transporte sintético, no llamadas reales Zadarma.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
