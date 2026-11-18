const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const dir=process.env.IMPE_SUPERVISION_RENDER_DIR;
if(!dir)throw new Error('Exporta las vistas sintéticas con supervision_comercial_v3_db.php.');
const root=path.resolve(__dirname,'..');
const app='http://supervision-v3.test';
const inicio=app+'/index.php?controller=embudoComercial&action=index';
const icons=process.env.BOOTSTRAP_ICONS_DIR;
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.resolve(root,'../browser-legacy-deps/node_modules/bootstrap');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROME_PATH||undefined});
 try{
  const page=await browser.newPage({viewport:{width:1600,height:1050}});
  const errors=[];const posts=[];const versiones=new Map();let documentos=0;
  page.on('pageerror',e=>errors.push(e.message));
  page.on('request',r=>{if(r.isNavigationRequest() && r.frame()===page.mainFrame())documentos++;});
  await page.addInitScript(()=>{
   window.IMPE_CSRF_TOKEN='acciones-sinteticas';window.llamadas=0;
   window.IMPE_TELEPHONY_PERSISTENT={getState:()=>({active:false}),openDialer:()=>window.llamadas++};
  });
  await page.route(app+'/**',async route=>{
   const req=route.request(),u=new URL(req.url());
   if(u.pathname==='/bootstrap.css')return route.fulfill({path:path.join(bootstrap,'dist/css/bootstrap.min.css')});
   if(u.pathname==='/icons.css')return route.fulfill({path:path.join(icons,'bootstrap-icons.css')});
   if(u.pathname.endsWith('.woff2'))return route.fulfill({path:path.join(icons,'bootstrap-icons.woff2')});
   if(u.pathname.startsWith('/public/')){
    const file=path.resolve(root,'.'+u.pathname);assert.ok(file.startsWith(root+path.sep));
    return fs.existsSync(file)?route.fulfill({path:file}):route.fulfill({status:404,body:''});
   }
   if(req.method()==='POST'){
    assert.equal(req.headers()['x-csrf-token'],'acciones-sinteticas');
    const body=new URLSearchParams(req.postData());posts.push({action:u.searchParams.get('action'),body});
    if(u.searchParams.get('action')==='seguimientoOperativoV3'){
     versiones.set(body.get('asignacion_id'),Number(body.get('version'))+1);
    }
    return route.fulfill({json:{ok:true}});
   }
   const file=u.searchParams.get('caso')||'asesor';
   assert.ok(['asesor','asesor23','asesor-no-contactar','asesor-consulta','admin'].includes(file));
   let html=fs.readFileSync(path.join(dir,file+'.html'),'utf8');
   for(const [id,version] of versiones){
    const dato=html.indexOf('data-asignacion-id="'+id+'"');
    if(dato<0)continue;
    const a=html.lastIndexOf('<article',dato),b=html.indexOf('</article>',dato);
    html=html.slice(0,a)+html.slice(a,b).replace(/data-version="[0-9]+"/,'data-version="'+version+'"')+html.slice(b);
   }
   html='<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="/bootstrap.css">'+
    '<link rel="stylesheet" href="/public/css/global.css">'+(icons?'<link rel="stylesheet" href="/icons.css">':'')+
    '<style>body{background:#f5f7fa;padding:24px}.workspace{margin-left:280px}</style></head><body><div class="workspace">'+
    html.replace('<!doctype html>','')+'</div></body></html>';
   return route.fulfill({body:html,contentType:'text/html'});
  });
  await page.goto(inicio);
  const card=id=>page.locator('.impe-v3o-card[data-asignacion-id="'+id+'"]');
  for(const [id,numero] of [[1,4],[2,4],[3,6],[4,5],[5,5]]){
   const barra=card(id).locator('.impe-v3o-acciones');
   assert.equal(await barra.locator(':scope > button').count(),numero);
   assert.equal(await card(id).locator('[data-v3-actividad]').count(),1);
   const wa=card(id).locator('[data-whatsapp-contexto]');
   assert.equal(await wa.isEnabled(),true);assert.match(await wa.getAttribute('title'),/WhatsApp/);
   assert.equal(await wa.getAttribute('data-whatsapp-id'),String(id));
   await card(id).locator('[data-v3-actividad]').click();
   const modal=page.locator('#impe-v3o-actividad-dialog');assert.equal(await modal.isVisible(),true);
   const disponibles=await modal.locator('[name=operacion]').evaluate(el=>Array.from(el.options).filter(o=>!o.disabled).map(o=>o.value));
   assert.deepEqual(disponibles,id===4?['REGISTRAR','REPROGRAMAR','COMPLETAR']:['REGISTRAR','PROGRAMAR']);
   await modal.locator('[data-actividad-cerrar]').first().click();
  }
  assert.equal(await page.locator('[aria-label="Ver expediente comercial"]').count(),0);
  assert.match(await card(1).locator('.impe-embudo-persona').getAttribute('href'),/action=detalle/);
  for(const id of [3,4,5]){
   await card(id).locator('[data-v3i-iniciar]').click();
   assert.equal(await page.locator('#impe-v3i-dialog').isVisible(),true);
   assert.equal(await page.locator('[data-v3i-preparar]').isVisible(),id!==5);
   await page.locator('#impe-v3i-dialog [data-v3i-cerrar]').first().click();
  }
  // Real handlers still send one protected request after the server-rendered swap.
  for(let i=0;i<2;i++){
   await card(1).locator('[data-v3-actividad]').click();
   await page.locator('#impe-v3o-actividad-dialog [name=motivo]').fill('Gestión de prueba desde acceso directo.');
   await page.locator('#impe-v3o-actividad-dialog [type=submit]').click();
   await page.locator('.system-toast.show').waitFor();
   assert.equal(posts.length,i+1);assert.equal(posts[i].action,'seguimientoOperativoV3');
   assert.equal(posts[i].body.get('asignacion_id'),'1');assert.equal(posts[i].body.get('version'),String(i));
   await page.locator('.system-toast .btn-close').click();
   await card(1).locator('[data-embudo-marcar]').click();
   assert.equal(await page.evaluate(()=>window.llamadas),i+1);
  }
  assert.equal(documentos,1,'guardar conserva el documento y los nuevos controles');
  await card(3).locator('[data-v3-gestionar]').click();
  assert.match(await page.locator('#impe-v3o-potencial-dialog h3').innerText(),/avance comercial/);
  await page.locator('#impe-v3o-potencial-subetapa').selectOption('REUNIENDO_DOCUMENTOS');
  await page.locator('#impe-v3o-potencial-motivo').fill('Avance de prueba desde la tarjeta.');
  await page.locator('#impe-v3o-potencial-guardar').click();await page.locator('.system-toast.show').waitFor();
  assert.equal(posts.length,3);assert.equal(posts[2].action,'gestionarPotencialV3');
  await page.locator('.system-toast .btn-close').click();
  await card(1).locator('.impe-v3o-gestion-boton').click();
  const menu=page.locator('#impe-v3o-gestion-1');
  assert.equal(await menu.locator('[data-v3-actividad],[data-v3-gestionar],[data-v3i-iniciar]').count(),0);
  await menu.getByRole('link',{name:'Mis llamadas y resultados'}).waitFor();
  await card(1).locator('.impe-v3o-gestion-boton').click();
  for(const width of [1920,1440,1280,390]){
   await page.setViewportSize({width,height:1050});
   await page.locator('.workspace').evaluate((el,width)=>el.style.marginLeft=width<700?'0':'280px',width);
   const cortados=await page.locator('.impe-v3o-acciones').evaluateAll(barras=>barras.flatMap(barra=>{
    const caja=barra.getBoundingClientRect();
    return Array.from(barra.querySelectorAll(':scope > button')).filter(b=>{
     const r=b.getBoundingClientRect();return r.left<caja.left-1||r.right>caja.right+1||r.bottom>caja.bottom+1;
    }).map(b=>b.getAttribute('aria-label'));
   }));
   assert.deepEqual(cortados,[],'acciones dentro de la tarjeta a '+width+' px');
   if(icons && [1440,390].includes(width))await page.screenshot({path:path.join(dir,'acciones-'+width+'.png'),fullPage:true});
  }
  for(const [caso,id] of [['asesor-no-contactar',4],['asesor23',13]]){
   await page.goto(inicio+'&caso='+caso);
   assert.equal(await card(id).locator('[data-whatsapp-contexto],[data-v3-actividad],[data-v3-gestionar],[data-v3i-iniciar]').count(),0);
  }
  await page.goto(inicio+'&caso=asesor-consulta');
  assert.equal(await page.locator('[data-v3-actividad],[data-v3-gestionar],[data-v3i-iniciar]').count(),0);
  await page.goto(inicio+'&caso=admin');
  assert.equal(await page.locator('[data-whatsapp-contexto],[data-embudo-marcar],[data-v3-actividad],[data-v3-gestionar],[data-v3i-iniciar]').count(),0);
  assert.equal(posts.length,3);assert.deepEqual(errors,[]);
  await page.goto(inicio);
  const modal=page.locator('#impe-v3o-actividad-dialog');
  // A new appointment requires a classified task. Browsers block incomplete forms.
  await card(1).locator('[data-v3-actividad]').click();
  await modal.locator('[name=operacion]').selectOption('PROGRAMAR');
  assert.equal(await modal.locator('[name=tipo_actividad]').isVisible(),true);
  assert.equal(await modal.locator('[name=tipo_actividad]').getAttribute('required'),'');
  await modal.locator('[name=fecha]').fill('2099-02-01T10:00');
  await modal.locator('[name=motivo]').fill('Revisar el certificado y su legibilidad.');
  await modal.locator('[type=submit]').click();assert.equal(posts.length,3);
  assert.equal(await modal.isVisible(),true);
  await modal.locator('[name=tipo_actividad]').selectOption('DOCUMENTOS');
  await modal.locator('[type=submit]').click();await page.locator('.system-toast.show').waitFor();
  assert.equal(posts.length,4);assert.equal(posts[3].body.get('tipo_actividad'),'DOCUMENTOS');
  await page.locator('.system-toast .btn-close').click();
  await card(4).locator('[data-v3-actividad]').click();
  assert.match(await modal.locator('[data-actividad-pendiente]').innerText(),/Revisar documentos.*Revisar certificado <prueba>/);
  await modal.locator('[name=operacion]').selectOption('REPROGRAMAR');
  assert.equal(await modal.locator('[name=tipo_actividad]').isVisible(),false);
  assert.equal(await modal.locator('[name=fecha]').inputValue(),'2099-01-01T10:00');
  await modal.locator('[name=fecha]').fill('2099-01-02T10:00');
  await modal.locator('[name=motivo]').fill('Se acordó atenderlo un día después.');
  await modal.locator('[type=submit]').click();await page.locator('.system-toast.show').waitFor();
  assert.equal(posts.length,5);assert.equal(posts[4].body.get('tipo_actividad'),'');
  await page.locator('.system-toast .btn-close').click();
  await card(3).locator('[data-v3-gestionar]').click();
  await page.locator('#impe-v3o-potencial-subetapa').selectOption('REUNIENDO_DOCUMENTOS');
  await page.locator('#impe-v3o-potencial-motivo').fill('Verificar documentos con el interesado.');
  await page.locator('#impe-v3o-potencial-fecha').fill('2099-02-01T11:00');
  assert.equal(await page.locator('#impe-v3o-potencial-tipo').isVisible(),true);
  await page.locator('#impe-v3o-potencial-guardar').click();assert.equal(posts.length,5);
  await page.locator('#impe-v3o-potencial-tipo').selectOption('DOCUMENTOS');
  await page.locator('#impe-v3o-potencial-guardar').click();await page.locator('.system-toast.show').waitFor();
  assert.equal(posts.length,6);assert.equal(posts[5].body.get('tipo_actividad'),'DOCUMENTOS');
  await page.locator('.system-toast .btn-close').click();
  // The existing institutional modal must scroll to its footer on narrow screens.
  await page.setViewportSize({width:390,height:844});
  await page.locator('.workspace').evaluate(el=>el.style.marginLeft='0');
  await card(1).locator('[data-v3-actividad]').click();
  await modal.locator('[name=operacion]').selectOption('PROGRAMAR');
  await modal.locator('[name=tipo_actividad]').selectOption('MENSAJE');
  await modal.locator('[type=submit]').scrollIntoViewIfNeeded();
  assert.equal(await modal.locator('[type=submit]').isVisible(),true);
  await page.screenshot({path:path.join(dir,'actividad-tipo-390.png')});
  await modal.locator('[data-actividad-cerrar]').first().click();
  assert.deepEqual(errors,[]);

  console.log('CHECK_OK acciones directas: cinco etapas, cita pendiente, avance, inscripción, WhatsApp contextual, refresco, permisos y diseño responsive.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
