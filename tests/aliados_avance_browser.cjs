/* Vista y scripts reales; transporte sintético sin envíos ni conexión a producción. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),base='http://127.0.0.1:8135/',deps=process.env.BROWSER_DEPS||path.resolve(root,'../browser-deps/node_modules'),php=process.env.PHP_BINARY||'php';
const render=q=>execFileSync(php,[path.join(root,'tests/aliados_historial_preview.php'),q],{encoding:'utf8'});
const stamp='2026-10-23 12:00:00',states={ESPERANDO_RESPUESTA:'Sin respuesta registrada',SOLICITA_INFORMACION:'Solicita información',DIFUSION_CONFIRMADA:'La institución confirmó la difusión',NO_PARTICIPARA:'No participará',SIN_RESPUESTA:'Contactado, sin respuesta'};
const initial=()=>[1,2].map(id=>({id,titulo:id===1?'Bachillerato · Publicación compartida':'Inglés · Publicación pendiente',programa_nombre:id===1?'Bachillerato General':'Inglés',fecha_inicio:'2026-10-01',fecha_termino:'2026-11-30',estado_texto:'Publicada · Vigente',imagen:'',enlace_registro:base+'registro/prueba/Ab7K9',compartida:id===1,difusion_canales:id===1?{CORREO:{fecha:stamp,id:8}}:{},respuesta:{estado:'ESPERANDO_RESPUESTA',etiqueta:states.ESPERANDO_RESPUESTA,version:0,nota:'',responsable:'',fecha:''},puede_responder:id===1}));
(async()=>{let browser,n=0;const errors=[],writes=[];let db={manual:initial(),institucional:initial()};
 const check=(v,m)=>{assert.ok(v,m);n++;console.log('OK '+m);};
 try{
  browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});const context=await browser.newContext(),page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  const data=kind=>{const items=db[kind].map(p=>({...p,puede_responder:p.compartida&&!page.url().includes('role=consulta')})),sent=items.filter(p=>p.compartida).length;return {ok:true,origen_tipo:kind,origen_id:1,aliado:{nombre_entidad:kind==='manual'?'IEPFI manual':'Instituto Celayense',contacto_nombre:'Contacto'},estados_respuesta:states,respuestas_disponibles:true,avance:{total:2,compartidas:sent,pendientes:2-sent,convocatorias:items}};};
  await context.route('**/*',async route=>{
   const req=route.request(),u=new URL(req.url()),action=u.searchParams.get('action'),kind=u.searchParams.get('origen_tipo')||'manual';if(u.origin!==new URL(base).origin)return route.abort();
   if(action){
    if(action==='avanceDifusion')return route.fulfill({json:data(kind)});
    const body=req.postData()||'',value=name=>body.match(new RegExp('name="'+name+'"\\r\\n\\r\\n([^\\r]*)'))?.[1]||'';
    if(action==='guardarRespuestaDifusion'){
     writes.push(action);const p=db[kind].find(p=>p.id===Number(value('convocatoria_id')));p.respuesta={estado:value('estado'),etiqueta:states[value('estado')],version:1,nota:value('nota'),responsable:'Cuenta Clave',fecha:stamp};return route.fulfill({json:data(kind)});
    }
    if(action==='prepararEnvio'){const d=data(kind);return route.fulfill({json:{...d,convocatorias:d.avance.convocatorias,operacion_id:'nonce',canales:{correo:{disponible:true,destinatarios:['contacto@example.test']},whatsapp_manual:{disponible:true,contactos:[{id:1,telefono:'7771113333',etiqueta:'Contacto',telefono_whatsapp:'527771113333',disponible:true}],telefono:'7771113333',telefono_whatsapp:'527771113333'}}}});}
    if(action==='borradorEnvio'){const p=db[kind].find(p=>p.id===Number(u.searchParams.get('convocatoria_id')));return route.fulfill({json:{ok:true,convocatoria:p,borrador:{asunto:'Convocatoria',cuerpo:'Mensaje de prueba'},borrador_whatsapp:'Mensaje para WhatsApp'}});}
    if(action==='enviarConvocatoria'||action==='registrarConvocatoriaWhatsappManual'){
     writes.push(action);const p=db[value('origen_tipo')].find(p=>p.id===Number(value('convocatoria_id')));p.compartida=true;p.difusion_canales[action==='enviarConvocatoria'?'CORREO':'WHATSAPP_MANUAL']={fecha:stamp,id:10};return route.fulfill({json:{ok:true,mensaje:'Difusión registrada.'}});
    }
   }
   if(u.pathname==='/index.php')return route.fulfill({body:render(u.search.slice(1)),contentType:'text/html'});
   const map={'/fixture-bootstrap.css':'bootstrap/dist/css/bootstrap.min.css','/fixture-bootstrap.js':'bootstrap/dist/js/bootstrap.bundle.min.js','/fixture-font.css':'@fontsource/manrope/latin.css','/fixture-icons.css':'bootstrap-icons/font/bootstrap-icons.css'};
   let file=map[u.pathname]?path.join(deps,map[u.pathname]):path.join(root,u.pathname);if(u.pathname.startsWith('/fonts/'))file=path.join(deps,'bootstrap-icons/font',u.pathname);if(u.pathname.startsWith('/files/'))file=path.join(deps,'@fontsource/manrope',u.pathname);
   return fs.existsSync(file)?route.fulfill({path:file,contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'application/javascript':undefined}):route.fulfill({status:404,body:''});
  });
  const modal=page.locator('#modalAliadoAvance'),share=page.locator('#modalAliadoCompartir');
  const go=async(q='')=>{await page.goto(base+'index.php?'+q);await page.locator('[data-avance-abrir]').first().waitFor();await page.evaluate(()=>document.fonts.ready);};
  const open=async kind=>{await page.locator('[data-avance-abrir][data-origen="'+kind+'"]').click();await modal.locator('.aliados-avance-card').first().waitFor();};
  const close=async()=>{await modal.locator('.modal-footer button').click();await modal.waitFor({state:'hidden'});};
  for(const width of [1440,1366,1024,768,390,360]){
   await page.setViewportSize({width,height:900});db={manual:initial(),institucional:initial()};await go();await open('manual');
   check(await modal.locator('.aliados-avance-card').count()===2,'convocatorias y estados separados '+width);
   check((await modal.textContent()).includes('Sin respuesta registrada')&&!(await modal.textContent()).includes('Esperando respuesta'),'sin espera automática '+width);
   check(await modal.locator('[data-avance-responder]').count()===1,'sólo responde publicaciones compartidas '+width);
   await modal.locator('[data-avance-only-pending]').check();check(await modal.locator('.aliados-avance-card').count()===1&&(await modal.locator('.aliados-avance-card').textContent()).includes('Inglés'),'filtro de pendientes '+width);await modal.locator('[data-avance-only-pending]').uncheck();
   check(await modal.evaluate(n=>n.querySelector('.modal-body').scrollWidth<=n.querySelector('.modal-body').clientWidth),'modal sin desbordamiento '+width);
   if(process.env.IMPE_AVANCE_OUTPUT){fs.mkdirSync(process.env.IMPE_AVANCE_OUTPUT,{recursive:true});await page.screenshot({path:path.join(process.env.IMPE_AVANCE_OUTPUT,'avance-'+width+'.png'),fullPage:true});}
   await close();await page.locator('[data-aliado-share-origen="manual"]').click();await share.locator('[data-envio-convocatoria="2"]').waitFor();
   check((await share.locator('[data-aliado-envio-avance]').textContent()).includes('1 de 2 compartidas'),'avance en Compartir '+width);
   await share.locator('[data-envio-only-pending]').check();check(await share.locator('[data-envio-convocatoria]').count()===1,'sólo pendientes en selección '+width);
   await share.locator('[data-envio-convocatoria="2"]').click();await share.locator('[data-aliado-send-button]:enabled').waitFor();
   check(await share.evaluate(n=>n.querySelector('.modal-body').scrollWidth<=n.querySelector('.modal-body').clientWidth),'Compartir sin desbordamiento '+width);
   if(process.env.IMPE_AVANCE_OUTPUT)await page.screenshot({path:path.join(process.env.IMPE_AVANCE_OUTPUT,'compartir-'+width+'.png'),fullPage:true});
   await share.locator('[data-aliado-send-button]').click();await page.waitForFunction(()=>document.querySelector('[data-aliado-envio-avance]').textContent.includes('2 de 2 compartidas'));
   check(await share.isVisible()&&(await share.locator('[data-aliado-send-status]').textContent()).includes('siguiente convocatoria'),'continuar en el modal tras envío '+width);
   await share.locator('.modal-header .btn-close').click();await share.waitFor({state:'hidden'});
   check((await page.locator('[data-avance-summary][data-origen="manual"] [data-avance-count]').textContent()).includes('2 de 2'),'tabla actualizada sin recarga '+width);
  }
  await page.setViewportSize({width:1440,height:900});db={manual:initial(),institucional:initial()};await go();await open('manual');await modal.locator('[data-avance-responder="1"]').click();await modal.locator('[name=estado]').selectOption('SOLICITA_INFORMACION');await modal.locator('[name=nota]').fill('Nota <img src=x onerror=alert(1)> registrada.');await modal.locator('[type=submit]').click();await modal.locator('[data-avance-status]').filter({hasText:'Respuesta guardada'}).waitFor();
  check((await modal.locator('.aliados-avance-card').first().textContent()).includes('Solicita información')&&await modal.locator('.aliados-avance-response img').count()===0,'respuesta guardada y nota escapada');
  check((await modal.locator('[data-avance-totals]').textContent()).includes('1'),'respuesta no sustituye avance');await close();await open('institucional');check(!(await modal.textContent()).includes('Nota <img'),'IDs iguales de distintos orígenes no mezclan respuestas');await close();
  await go('role=consulta');await open('manual');check(await modal.locator('[data-avance-responder]').count()===0,'permiso de consulta no ofrece editar');await close();
  check(writes.filter(a=>a==='guardarRespuestaDifusion').length===1,'navegar y filtrar no registra respuestas');check(errors.length===0,'sin errores JavaScript: '+errors.join(';'));
  console.log('PASS '+n+' comprobaciones visuales del flujo de difusión');
 }finally{if(browser)await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
