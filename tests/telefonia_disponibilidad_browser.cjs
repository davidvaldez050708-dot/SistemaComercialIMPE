const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const repo=path.resolve(__dirname,'..'),fixture=path.join(__dirname,'fixtures/telefonia_flujo_shell.php');
const sourceHtml=cp.execFileSync('php',[fixture],{encoding:'utf8'}),destHtml=cp.execFileSync('php',[fixture,'analista'],{encoding:'utf8'});
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 try{for(const scenario of ['aceptar','rechazar','vencer','pendiente']){
  let op=null,authorizations=0,saved=null;const errors=[],contexts=[];
  const pages=[];
  for(const actor of [7,8]){
   const context=await browser.newContext({viewport:{width:1366,height:900}});contexts.push(context);const page=await context.newPage();pages.push(page);page.on('pageerror',e=>errors.push(e.message));
   await page.addInitScript(()=>{
    window.audioProbe={created:0,started:0,stopped:0,frequencies:[]};
    window.AudioContext=class{constructor(){audioProbe.created++;this.state='running';this.currentTime=0;this.destination={};}resume(){this.state='running';return Promise.resolve();}createOscillator(){return{frequency:{set value(v){audioProbe.frequencies.push(v);}},connect(){},disconnect(){},start(){audioProbe.started++;},stop(){audioProbe.stopped++;}};}createGain(){return{gain:{setValueAtTime(){},linearRampToValueAtTime(){}},connect(){}};}};
   });
   await page.route('**/*',async route=>{
    const req=route.request(),u=new URL(req.url()),post=new URLSearchParams(req.postData()||''),action=u.searchParams.get('action');
    if(u.hostname!=='llamadas.test')return route.abort();
    if(action==='disponibilidad'){
     const command=post.get('accion');
     if(command==='ACEPTAR')op={...op,estado:'ACEPTADA',segundos:30};
     if(command==='RECHAZAR')op={...op,estado:'RECHAZADA'};
     if(command==='CANCELAR')op={...op,estado:'CANCELADA'};
     if(command==='DEVOLUCION'){saved=Object.fromEntries(post);op={...op,estado:'DEVOLUCION'};}
     return route.fulfill({json:{ok:true,solicitud:command==='POLL'?(Number(post.get('id'))>0?op:null):(command==='PRESENCIA'?null:(u.searchParams.has('id')||command?op:null)),entrante:actor===8&&op?.estado==='PENDIENTE'?op:null,devoluciones:actor===8&&saved?1:0}});
    }
    if(action==='destinosTransferencia')return route.fulfill({json:{ok:true,estados:[{id:17,nombre:'Morelos'}],destinos:[{usuario_id:8,extension:'101',estado_id:17,estado_nombre:'Morelos',nombre:'Analista',rol:'Analista de Datos'}],identificacion:[]}});
    if(action==='autorizarTransferencia'){
     if(post.get('intencion')==='SOLICITAR'){
      op={id:1,version:1,estado:'PENDIENTE',referencia:'pbx-call-availability',origen_usuario:7,origen_extension:'100',destino_usuario:8,destino_extension:'101',numero:'5551234567',responsable:'Analista',segundos:45};
      return route.fulfill({json:{ok:true,solicitud:op}});
     }
     authorizations++;assert.equal(op.estado,'ACEPTADA');assert.equal(post.get('disponibilidad_id'),'1');
     return route.fulfill({json:{ok:true,token:'a'.repeat(48)}});
    }
    if(u.pathname.endsWith('/avisos_llamadas.php'))return route.fulfill({json:{ok:true,extension:actor===7?'100':'101',llamadas:[]}});
    if(u.pathname.startsWith('/public/javascript/'))return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(path.join(repo,u.pathname),'utf8')});
    return route.fulfill({contentType:'text/html',body:u.pathname==='/'?(actor===7?sourceHtml:destHtml):'<html></html>'});
   });
   await page.goto('http://llamadas.test/');await page.waitForFunction(()=>window.IMPE_AVAILABILITY);
   await page.evaluate(actor=>{
    const state={userId:actor,updatedAt:Date.now(),hostReady:true,active:actor===7,phase:actor===7?'in-progress':'ready',direction:'incoming',destination:'5551234567',pbxCallId:actor===7?'pbx-call-availability':'',callToken:actor===7?'pbx-call-availability':'',duration:10};
    localStorage.setItem('impe:telephony:state:'+actor,JSON.stringify(state));window.dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:'+actor,newValue:JSON.stringify(state)}));
    window.commands=[];window.testChannel=new BroadcastChannel('impe-telephony-'+actor);window.testChannel.onmessage=e=>{if(e.data.action==='TRANSFER')window.commands.push(e.data);};
   },actor);
  }
  const [s,d]=pages;await s.locator('[data-shell-transfer-toggle]').click();await s.locator('[data-routing-destination]').selectOption('8:101:17');await s.locator('[data-routing-search]').fill('Institución prueba');await s.locator('[data-routing-attended]').click();
  await s.locator('[data-availability-source]').waitFor({state:'visible'});assert.equal(authorizations,0);
  assert.equal(await s.locator('[data-availability-transfer]').isVisible(),false,'Transferir sólo se muestra después de aceptar');
  assert.equal(await s.locator('[data-availability-callback-open]').isVisible(),false,'Devolución sólo se ofrece al rechazar o vencer');
  assert.equal(await s.locator('[data-shell-transfer-toggle]').isDisabled(),true,'La solicitud pendiente bloquea otro envío aunque se cierre la ficha');
  assert.equal(await s.evaluate(()=>window.commands.length),0,'solicitar disponibilidad no envía transferencia');
  if(scenario==='pendiente')await d.evaluate(()=>document.body.classList.add('impe-sdk-result-in-modal'));
  await d.evaluate(()=>window.IMPE_AVAILABILITY.tick());await d.locator('[data-availability-incoming]').waitFor({state:'visible'});
  assert.equal(await d.evaluate(()=>audioProbe.started),0,'sin gesto confiable no intenta sonido');
  await d.locator('[data-availability-incoming] h2').click();
  assert.equal(await d.evaluate(()=>audioProbe.started),2,'dos notas distintivas tras gesto confiable');
  assert.deepEqual(await d.evaluate(()=>audioProbe.frequencies),[740,990]);
  await d.evaluate(()=>window.IMPE_AVAILABILITY.tick());
  assert.equal(await d.evaluate(()=>audioProbe.started),2,'poll repetido no repite sonido');
  if(process.env.DISP_SCREENSHOT&&scenario==='aceptar'){await d.screenshot({path:process.env.DISP_SCREENSHOT+'-analista.png'});await s.screenshot({path:process.env.DISP_SCREENSHOT+'-recepcion.png'});}
  assert.equal(await d.locator('[data-incoming-panel]').isVisible(),false,'la confirmación no es una llamada ni un timbrado SIP');
  assert.ok(await d.locator('[data-availability-incoming] h2').evaluate(e=>parseFloat(getComputedStyle(e).fontSize))<=20,'tipografía del modal uniforme');
  if(scenario==='pendiente'){
   assert.equal(await d.locator('[data-availability-accept]').isDisabled(),true,'Resultado pendiente impide aceptar, pero muestra solicitud');
   assert.equal(await d.locator('[data-availability-pending]').isVisible(),true);
   await d.locator('[data-availability-pending]').click();await d.locator('[data-availability-waiting]').waitFor({state:'visible'});
   await d.evaluate(()=>document.body.classList.remove('impe-sdk-result-in-modal'));await d.evaluate(()=>window.IMPE_AVAILABILITY.tick());
   await d.locator('[data-availability-incoming]').waitFor({state:'visible'});assert.equal(await d.locator('[data-availability-accept]').isDisabled(),false);
   await d.locator('[data-availability-decline]').click();assert.equal(authorizations,0);
  }else if(scenario==='aceptar'){
   await d.locator('[data-availability-accept]').click();await s.evaluate(()=>window.IMPE_AVAILABILITY.tick());
   await s.locator('[data-availability-transfer]').waitFor({state:'visible'});assert.equal(authorizations,0,'aceptar no entrega automáticamente');
   await s.locator('[data-availability-transfer]').click();await s.waitForFunction(()=>window.commands.length===1);assert.equal(authorizations,1);
   assert.equal(await s.locator('[data-availability-source]').isVisible(),false);
  }else{
   if(scenario==='rechazar')await d.locator('[data-availability-decline]').click();else op={...op,estado:'VENCIDA',segundos:0};
   await s.evaluate(()=>window.IMPE_AVAILABILITY.tick());await s.locator('[data-availability-callback-open]').waitFor({state:'visible'});
   assert.equal(await s.evaluate(()=>window.commands.length),0,'rechazo o vencimiento conserva la llamada sin DTMF');
   await s.locator('[data-availability-callback-open]').click();const dialog=s.locator('[data-availability-callback]');
   await dialog.locator('[name=contacto]').fill('Ana');await dialog.locator('[name=nota]').fill('Institución Uno: solicita información');
   await s.setViewportSize({width:390,height:600});const box=await dialog.boundingBox();assert.ok(box.y>=0&&box.y+box.height<=601,'formulario accesible en móvil');
   await dialog.locator('button[type=submit]').click();await dialog.waitFor({state:'hidden'});assert.equal(saved.contacto,'Ana');assert.equal(saved.telefono,'5551234567');
  }
  assert.deepEqual(errors,[]);for(const c of contexts)await c.close();
 }
 console.log('Disponibilidad/navegador: confirmación sin llamada, aceptación explícita, transferencia única, rechazo, vencimiento y devolución móvil: OK');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
