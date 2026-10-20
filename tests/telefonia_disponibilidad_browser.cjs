const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const repo=path.resolve(__dirname,'..'),fixture=path.join(__dirname,'fixtures/telefonia_flujo_shell.php');
const sourceHtml=cp.execFileSync('php',[fixture],{encoding:'utf8'}),destHtml=cp.execFileSync('php',[fixture,'analista'],{encoding:'utf8'});
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 try{for(const scenario of ['aceptar','rechazar','vencer','pendiente','iframe','prospecto','saliente-ventas','saliente-vinculacion']){
  let op=null,authorizations=0,saved=null;const errors=[],contexts=[];
  const lookups=[];
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
     return route.fulfill({json:{ok:true,solicitud:command==='POLL'?((Number(post.get('id'))>0||post.get('referencia')===op?.referencia)?op:null):(command==='PRESENCIA'?null:(u.searchParams.has('id')||command?op:null)),entrante:actor===8&&op?.estado==='PENDIENTE'?op:null,devoluciones:actor===8&&saved?1:0}});
    }
    if(action==='destinosTransferencia'){lookups.push(u.searchParams.get('buscar'));return route.fulfill({json:{ok:true,estados:[{id:17,nombre:'Morelos'}],destinos:[{usuario_id:8,extension:'101',estado_id:17,estado_nombre:'Morelos',nombre:(scenario==='prospecto'||scenario==='saliente-ventas')?'Asesor':'Analista',rol:(scenario==='prospecto'||scenario==='saliente-ventas')?'Asesor de Ventas':'Analista de Datos'}],identificacion:[]}});}
    if(action==='autorizarTransferencia'){
     if(post.get('intencion')==='SOLICITAR'){
      op={id:1,version:1,estado:'PENDIENTE',referencia:'pbx-call-availability',origen_usuario:7,origen_extension:'100',destino_usuario:8,destino_extension:'101',numero:'5551234567',responsable:'Analista',institucion_nombre:post.get('institucion_nombre'),segundos:45};
      return route.fulfill({json:{ok:true,solicitud:op}});
     }
     authorizations++;assert.equal(op.estado,'ACEPTADA');assert.equal(post.get('disponibilidad_id'),'1');
     return route.fulfill({json:{ok:true,token:'a'.repeat(48)}});
    }
    if(u.pathname.endsWith('/avisos_llamadas.php'))return route.fulfill({json:{ok:true,extension:actor===7?'100':'101',llamadas:[]}});
    if(u.pathname.startsWith('/public/javascript/'))return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(path.join(repo,u.pathname),'utf8')});
    return route.fulfill({contentType:'text/html',body:u.pathname==='/'?(actor===7?sourceHtml:destHtml):'<html><body><main class="admin-shell"></main></body></html>'});
   });
   await page.goto('http://llamadas.test/');await page.waitForFunction(()=>window.IMPE_AVAILABILITY);
   await page.evaluate(({actor,scenario})=>{
    const state={userId:actor,updatedAt:Date.now(),hostReady:true,active:actor===7,phase:actor===7?'in-progress':'ready',direction:actor===7&&scenario.startsWith('saliente')?'outgoing':'incoming',destination:'5551234567',pbxCallId:actor===7?'pbx-call-availability':'',callToken:actor===7?'pbx-call-availability':'',duration:10,context:{type:'TELEPHONY_MANUAL'}};
    localStorage.setItem('impe:telephony:state:'+actor,JSON.stringify(state));window.dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:'+actor,newValue:JSON.stringify(state)}));
    window.commands=[];window.testChannel=new BroadcastChannel('impe-telephony-'+actor);window.testChannel.onmessage=e=>{if(e.data.action==='TRANSFER')window.commands.push(e.data);};
   },{actor,scenario});
  }
  const [s,d]=pages;
  if(scenario==='iframe'){
   await d.frameLocator('#impeCrmFrame').locator('body').evaluate(e=>{const b=document.createElement('button');b.textContent='Trabajar en CRM';e.append(b);});
   await d.frameLocator('#impeCrmFrame').getByRole('button',{name:'Trabajar en CRM'}).click();
   assert.equal(await d.evaluate(()=>audioProbe.created),1,'el clic real del iframe habilita sólo el contexto de aviso');
  }
  await s.locator('[data-shell-transfer-toggle]').click();
  if(scenario==='prospecto'||scenario==='saliente-ventas'){
   await s.locator('[data-routing-type]').selectOption('PROSPECTO');
   await s.locator('[data-routing-search-wrap]').waitFor({state:'visible'});
   assert.equal(await s.locator('[data-routing-search]').getAttribute('required'),'','Nombre requerido en la ficha del prospecto');
  }
  await s.locator('[data-routing-destination]').selectOption('8:101:17');
  await s.locator('[data-routing-search]').fill((scenario==='prospecto'||scenario==='saliente-ventas')?'Mariana López':'Institución prueba');
  if(scenario==='prospecto'||scenario==='saliente-ventas'){
   await s.waitForTimeout(400);
   assert(!lookups.includes('Mariana López'),'El nombre del prospecto no debe filtrar asesores del directorio');
   await s.locator('[data-routing-destination]').selectOption('8:101:17');
  }
  await s.locator('[data-routing-attended]').click();
  if(scenario==='prospecto'||scenario==='saliente-ventas')assert.equal(op?.institucion_nombre,'Mariana López','Recepción guarda el nombre antes de solicitar disponibilidad');
  await s.locator('[data-availability-source]').waitFor({state:'visible'});
  const sourceStyle=await s.locator('[data-availability-source]').evaluate(el=>({border:getComputedStyle(el).borderTopStyle,font:parseFloat(getComputedStyle(el.querySelector('strong')).fontSize)}));assert.equal(sourceStyle.border,'solid','Componente institucional conserva borde dentro de Transferir');assert(sourceStyle.font<=13,'Estado con tipograf�a proporcionada');
  assert.equal(await s.locator('[data-availability-source]').getAttribute('data-availability-state'),'PENDIENTE','estado de espera visible');
  await s.locator('[data-routing-close]').click();
  await s.locator('[data-shell-transfer-toggle]').click();
  await s.locator('[data-availability-source]').waitFor({state:'visible'});
  assert.equal(authorizations,0);
  assert.equal(await s.locator('[data-availability-transfer]').isVisible(),false,'Transferir sólo se muestra después de aceptar');
  assert.equal(await s.locator('[data-availability-callback-open]').isVisible(),false,'Devolución sólo se ofrece al rechazar o vencer');
  assert.equal(await s.locator('[data-shell-transfer-toggle]').isDisabled(),false,'La solicitud pendiente bloquea otro envío aunque se cierre la ficha');
  assert.equal(await s.evaluate(()=>window.commands.length),0,'solicitar disponibilidad no envía transferencia');
  if(scenario==='pendiente')await d.evaluate(()=>document.body.classList.add('impe-sdk-result-in-modal'));
  await d.evaluate(()=>window.IMPE_AVAILABILITY.tick());await d.locator('[data-availability-incoming]').waitFor({state:'visible'});
  assert.equal(await d.locator('[data-availability-incoming]').getAttribute('data-availability-blocked'),scenario==='pendiente'?'1':'0','bloqueo visual coincide con resultado pendiente');
  assert.ok(await d.locator('[data-availability-incoming] .system-form-modal-footer .persistent-phone-action').first().evaluate(el=>parseFloat(getComputedStyle(el).minHeight)>=40),'botones accesibles');
  if(scenario==='aceptar'){
   await d.setViewportSize({width:360,height:480});
   const shape=await d.locator('[data-availability-incoming]').evaluate(el=>{
    const dialog=el.getBoundingClientRect(),footer=el.querySelector('.system-form-modal-footer').getBoundingClientRect();
    return {x:dialog.left,y:dialog.top,right:dialog.right,bottom:dialog.bottom,footer:footer.bottom,width:window.innerWidth,height:window.innerHeight};
   });
   assert.ok(shape.x>=-1&&shape.y>=-1&&shape.right<=shape.width+1&&shape.bottom<=shape.height+1,'modal completo dentro del viewport móvil');
   assert.ok(shape.footer<=shape.height+1,'botones visibles sin salir de pantalla en móvil');
   await d.setViewportSize({width:1366,height:900});
  }
  if(scenario!=='iframe'){
   assert.equal(await d.evaluate(()=>audioProbe.started),0,'sin gesto confiable no intenta sonido');
   await d.locator('[data-availability-incoming] h2').click();
  }
  assert.equal(await d.evaluate(()=>audioProbe.started),3,'tres notas distintivas tras gesto confiable');
  assert.deepEqual(await d.evaluate(()=>audioProbe.frequencies),[740,880,988]);
  await d.evaluate(()=>window.IMPE_AVAILABILITY.tick());
  assert.equal(await d.evaluate(()=>audioProbe.started),3,'poll repetido no repite sonido');
  if(process.env.DISP_SCREENSHOT&&scenario==='aceptar'){await d.screenshot({path:process.env.DISP_SCREENSHOT+'-analista.png'});await s.screenshot({path:process.env.DISP_SCREENSHOT+'-recepcion.png'});}
  assert.equal(await d.locator('[data-incoming-panel]').isVisible(),false,'la confirmación no es una llamada ni un timbrado SIP');
  assert.ok(await d.locator('[data-availability-incoming] h2').evaluate(e=>parseFloat(getComputedStyle(e).fontSize))<=20,'tipografía del modal uniforme');
  if(scenario==='pendiente'){
   assert.equal(await d.locator('[data-availability-accept]').isDisabled(),true,'Resultado pendiente impide aceptar, pero muestra solicitud');
   assert.equal(await d.locator('[data-availability-pending]').isVisible(),true);
   assert.equal(await d.locator('[data-availability-incoming] [data-availability-feedback]').isVisible(),true,'aviso pendiente visible');
   await d.locator('[data-availability-pending]').click();await d.locator('[data-availability-waiting]').waitFor({state:'visible'});
   await d.evaluate(()=>document.body.classList.remove('impe-sdk-result-in-modal'));await d.evaluate(()=>window.IMPE_AVAILABILITY.tick());
   await d.locator('[data-availability-incoming]').waitFor({state:'visible'});assert.equal(await d.locator('[data-availability-accept]').isDisabled(),false);
   await d.locator('[data-availability-decline]').click();assert.equal(authorizations,0);
  }else if(scenario==='aceptar'||scenario==='iframe'||scenario==='prospecto'||scenario.startsWith('saliente')){
   await d.locator('[data-availability-accept]').click();await s.evaluate(()=>window.IMPE_AVAILABILITY.tick());
   assert.equal(await s.locator('[data-availability-source]').getAttribute('data-availability-state'),'ACEPTADA','estado de aceptación visible');
   await s.locator('[data-availability-transfer]').waitFor({state:'visible'});assert.equal(authorizations,0,'aceptar no entrega automáticamente');
   await s.locator('[data-availability-transfer]').click();await s.waitForFunction(()=>window.commands.length===1);assert.equal(authorizations,1);
   assert.equal(await s.locator('[data-availability-source]').isVisible(),false);
  }else{
   if(scenario==='rechazar')await d.locator('[data-availability-decline]').click();else op={...op,estado:'VENCIDA',segundos:0};
   await s.evaluate(()=>window.IMPE_AVAILABILITY.tick());await s.locator('[data-availability-callback-open]').waitFor({state:'visible'});
   assert.equal(await s.locator('[data-availability-source]').getAttribute('data-availability-state'),scenario==='rechazar'?'RECHAZADA':'VENCIDA','estado de rechazo o vencimiento visible');
   assert.equal(await s.evaluate(()=>window.commands.length),0,'rechazo o vencimiento conserva la llamada sin DTMF');
   await s.locator('[data-availability-callback-open]').click();const dialog=s.locator('[data-availability-callback]');
   await dialog.locator('[name=contacto]').fill('Ana');await dialog.locator('[name=nota]').fill('Institución Uno: solicita información');
   await s.setViewportSize({width:390,height:600});const box=await dialog.boundingBox();assert.ok(box.y>=0&&box.y+box.height<=601,'formulario accesible en móvil');
   await dialog.locator('button[type=submit]').click();await dialog.waitFor({state:'hidden'});assert.equal(saved.contacto,'Ana');assert.equal(saved.telefono,'5551234567');
  }
  await d.evaluate(()=>window.IMPE_AVAILABILITY.tick());
  assert.ok(await d.evaluate(()=>audioProbe.stopped)>=6,'responder o vencer detiene los nodos del aviso');
  assert.deepEqual(errors,[]);for(const c of contexts)await c.close();
 }
 console.log('Disponibilidad/navegador: confirmación sin llamada, aceptación explícita, transferencia única, rechazo, vencimiento y devolución móvil: OK');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
