const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),read=p=>fs.readFileSync(path.join(root,p),'utf8');
const html=cp.execFileSync('php',[path.join(__dirname,'fixtures/telefonia_ventas_shell.php')],{encoding:'utf8'});
const view='<!doctype html><meta charset="utf-8"><main class="admin-shell"><button id="work">Trabajar en CRM</button></main>';
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});try{
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 let capture={version:0,valores:{nombre:'Diana Estrada',telefono:'+527771234567',correo:'',programa:'',notas:''}},saves=0;
 let op={id:44,version:1,segundos:45,origen_extension:'100',numero:'+527771234567',institucion_nombre:'Diana Estrada'};
 await page.addInitScript(()=>{window.audioProbe={created:0,tones:0};window.AudioContext=class{constructor(){audioProbe.created++;this.state='suspended';this.currentTime=0;this.destination={};}resume(){this.state='running';return Promise.resolve();}createOscillator(){return {frequency:{},connect(){},disconnect(){},start(){audioProbe.tones++;},stop(){}};}createGain(){return{gain:{setValueAtTime(){},linearRampToValueAtTime(){}},connect(){}};}};});
 await page.route('http://ventas.test/**',route=>{const u=new URL(route.request().url()),action=u.searchParams.get('action');
  if(u.pathname.startsWith('/public/javascript/'))return route.fulfill({contentType:'application/javascript',body:read(u.pathname.slice(1))});
  if(action==='disponibilidad'){const body=new URLSearchParams(route.request().postData());if(body.get('accion')==='ACEPTAR')op=null;return route.fulfill({json:{ok:true,entrante:op,devoluciones:0,solicitud:{estado:'ACEPTADA'}}});}
  if(action==='datosProspectoVenta'){
   if(route.request().method()==='POST'){const p=Object.fromEntries(new URLSearchParams(route.request().postData()));assert.equal(p.pbx_call_id,'pbx-prospect-capture');assert.equal(p.extension,'150');assert(p.csrf_token);capture={version:++saves,valores:Object.fromEntries(['nombre','telefono','correo','programa','notas'].map(k=>[k,p[k]]))};}
   return route.fulfill({json:{ok:true,captura:capture}});
  }
  if(action==='detalleVenta')return route.fulfill({json:{ok:false,mensaje:'PBX simulado'}});
  return route.fulfill({contentType:'text/html',body:u.pathname==='/'?html:view});
 });
 await page.goto('http://ventas.test/');await page.waitForFunction(()=>IMPE_AVAILABILITY&&IMPE_TELEPHONY_SHELL);
 const push=(phase,active)=>page.evaluate(({phase,active})=>{const s={userId:8,updatedAt:Date.now(),hostReady:true,phase,active,direction:'incoming',pbxCallId:active?'pbx-prospect-capture':'',callToken:'sdk-capture-123456',destination:'+527771234567',context:{pbxAnswered:active}};localStorage.setItem('impe:telephony:state:8',JSON.stringify(s));dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:8',newValue:JSON.stringify(s)}));},{phase,active});
 await push('ready',false);await page.evaluate(()=>IMPE_AVAILABILITY.tick());
 const availability=page.locator('[data-availability-incoming]');await availability.waitFor({state:'visible'});
 await page.locator('[data-availability-audio]').click();await page.waitForFunction(()=>audioProbe.tones===3);
 await page.evaluate(()=>IMPE_AVAILABILITY.tick());assert.equal(await page.evaluate(()=>audioProbe.created),1);assert.equal(await page.evaluate(()=>audioProbe.tones),3,'Polling no duplica el aviso de Ventas');
 await page.locator('[data-availability-accept]').click();await availability.waitFor({state:'hidden'});
 await push('in-progress',true);await page.waitForTimeout(850);const host=await page.locator('[data-shell-engine]').getAttribute('src');
 await page.locator('[data-sales-prospect]').click();const dialog=page.locator('[data-sales-prospect-dialog]');await dialog.waitFor({state:'visible'});
 assert.equal(await dialog.locator('[name=nombre]').inputValue(),'Diana Estrada');await dialog.locator('[name=correo]').fill('diana@example.test');await dialog.locator('[name=programa]').fill('Bachillerato 286');await dialog.locator('[name=notas]').fill('Notas de atención durante la conversación');
 for(const width of [1366,768,390]){await page.setViewportSize({width,height:800});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);const box=await dialog.boundingBox();assert(box.x>=0&&box.x+box.width<=width+1);if(process.env.IMPE_VENTAS_SCREENSHOTS)await page.screenshot({path:path.join(process.env.IMPE_VENTAS_SCREENSHOTS,'ventas_prospecto_'+width+'.png')});}
 await dialog.locator('[type=submit]').click();await dialog.locator('[data-sales-prospect-feedback]').filter({hasText:'Datos guardados'}).waitFor();assert.equal(saves,1);assert.equal(await page.evaluate(()=>IMPE_TELEPHONY_SHELL.getPhoneStatus().active),true,'Guardar notas no cuelga');assert.equal(await page.locator('[data-shell-engine]').getAttribute('src'),host);
 await dialog.locator('[data-sales-prospect-close]').click();await page.locator('[data-sales-prospect]').click();await dialog.waitFor({state:'visible'});assert.equal(await dialog.locator('[name=notas]').inputValue(),capture.valores.notas);await dialog.locator('[data-sales-prospect-close]').click();
 await page.addScriptTag({content:read('public/javascript/telefonia_ventas_prospecto.js')});assert.equal(await page.locator('[data-sales-prospect]').count(),1);
 // Navegación real del iframe, después F5 del shell: conserva las tres pestañas.
 await push('ready',false);
 for(const vista of ['', 'devoluciones','agenda']){await page.evaluate(vista=>{document.querySelector('#impeCrmFrame').src='http://ventas.test/index.php?controller=telefonia&action=misLlamadas'+(vista?'&vista='+vista:'');},vista);await page.frameLocator('#impeCrmFrame').locator('#work').waitFor();await page.waitForTimeout(100);await page.reload();await page.frameLocator('#impeCrmFrame').locator('#work').waitFor();assert.equal(await page.evaluate(()=>new URL(document.querySelector('#impeCrmFrame').contentWindow.location.href).searchParams.get('vista')||''),vista);}
 assert.deepEqual(errors,[]);console.log('Ventas Etapa2/navegador: aviso sonoro único con gesto, notas sin colgar, captura recuperable, responsive y F5 de tres bandejas: OK');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
