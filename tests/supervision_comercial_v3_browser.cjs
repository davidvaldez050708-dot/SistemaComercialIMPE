const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const dir=process.env.IMPE_SUPERVISION_RENDER_DIR;
if(!dir)throw new Error('Exporta primero las vistas sintéticas desde supervision_comercial_v3_db.php.');
const raiz=path.resolve(__dirname,'..');
const app='http://supervision-v3.test';
const index=app+'/index.php?controller=embudoComercial&action=index&asesor=22';
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROME_PATH||undefined});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}});
  const errors=[];let posts=0,documentos=0;
  page.on('pageerror',e=>errors.push(e.message));
  page.on('request',r=>{if(r.isNavigationRequest() && r.frame()===page.mainFrame())documentos++;});
  await page.route(app+'/**',async route=>{
   const req=route.request(),u=new URL(req.url());
   if(req.method()!=='GET'){posts++;return route.fulfill({status:403,body:'Solo consulta'});}
   if(u.pathname.startsWith('/public/')){
    const file=path.resolve(raiz,'.'+u.pathname);
    assert.ok(file.startsWith(raiz+path.sep));return route.fulfill({path:file});
   }
   let file='admin.html';
   if(u.searchParams.get('perfil')==='asesor')file='asesor.html';
   else if(u.searchParams.get('action')==='detalle')file=(u.searchParams.get('panel')||'resumen')+'.html';
   else if(u.searchParams.get('action')==='expedientes')file=u.searchParams.get('asesor')==='22'?'cerrados22.html':'cerrados.html';
   else if(['recuperacion','descartados'].includes(u.searchParams.get('vista')))file=u.searchParams.get('vista')+'.html';
   else if(u.searchParams.get('asesor')==='23')file=u.searchParams.get('p3_nuevo')==='2'?'admin23pagina2.html':'admin23.html';
   return route.fulfill({body:fs.readFileSync(path.join(dir,file),'utf8'),contentType:'text/html'});
  });
  const estilos=()=>page.locator('.impe-v3o-columna').evaluateAll(cols=>cols.map(col=>{
   const h=getComputedStyle(col.querySelector('.impe-embudo-lane-head'));
   const card=col.querySelector('.impe-v3o-card');const c=card?getComputedStyle(card):null;
   return {fondo:h.backgroundColor,borde:h.borderBottomColor,fuente:h.fontFamily,
    tarjeta:c?{borde:c.borderColor,radio:c.borderRadius,padding:c.padding,fuente:c.fontFamily}:null};
  }));
  await page.goto(index+'&perfil=asesor');const asesor=await estilos();
  await page.goto(index);const supervisor=await estilos();
  assert.deepEqual(supervisor,asesor,'mismas columnas y estilos de tarjetas que el asesor');
  assert.equal(await page.locator('.impe-v3o-columna').count(),5);
  const titulos=await page.locator('.impe-v3o-columna h3').allTextContents();
  assert.deepEqual(titulos.map(s=>s.trim()),['Nuevos','Contactados','Potenciales','Seguimiento','Proceso Inscripción']);
  assert.equal(await page.locator('.impe-embudo-tabs a').count(),4);
  assert.equal(await page.locator('[name=asesor]').inputValue(),'22');
  assert.equal(await page.locator('[data-embudo-marcar],[data-v3-mover],[data-v3-actividad],[data-v3i-iniciar],[draggable=true]').count(),0);
  await page.locator('[data-asignacion-id="4"] .impe-v3o-gestion-boton').click();
  const menu=page.locator('#impe-v3o-gestion-4');
  assert.equal(await menu.locator('button').count(),0);
  await menu.getByRole('link',{name:'Ver expediente comercial'}).click();
  await page.getByRole('navigation',{name:'Expediente comercial'}).waitFor();
  assert.match(await page.locator('h2').innerText(),/Prospecto sintético 4/);
  await page.getByRole('link',{name:'Actividad',exact:true}).click();
  assert.match(await page.locator('body').textContent(),/Prueba de actividad/);
  await page.getByRole('link',{name:'Llamadas',exact:true}).click();
  assert.equal(await page.locator('.linkage-call-recording-card').count(),1);
  assert.match(await page.locator('.linkage-call-recording-card').innerText(),/157/);
  assert.equal(await page.locator('[data-expediente-grabacion]').count(),0);
  await page.goto(index);
  const antes=documentos;
  await page.locator('[name=asesor]').selectOption('23');
  await page.waitForURL(/asesor=23/);
  assert.equal(documentos,antes,'filtro automático por asesor sin recargar');
  assert.match(await page.locator('.impe-v3o-card').first().innerText(),/Asesor 23/);
  await page.locator('.impe-v3o-columna[data-etapa=NUEVO] .impe-v3o-paginacion a').click();
  await page.waitForURL(/p3_nuevo=2/);
  assert.match(page.url(),/asesor=23/);assert.equal(documentos,antes);
  assert.equal(await page.locator('.impe-v3o-columna[data-etapa=NUEVO] .impe-v3o-card').count(),2);
  for(const vista of ['recuperacion','descartados']){
   await page.goto((vista==='recuperacion'?index:index.replace('asesor=22','asesor=23'))+'&vista='+vista);
   assert.equal(await page.locator('.impe-embudo-tabs a').count(),4);
   assert.equal(await page.locator('[name=asesor]').count(),1);
   assert.equal(await page.locator('[data-v3b-llamar]').count(),0);
   await page.getByRole('link',{name:'Ver ficha e historial'}).waitFor();
  }
  await page.goto(index);
  await page.getByRole('link',{name:'Expedientes cerrados',exact:true}).click();
  assert.equal(await page.locator('.linkage-call-recording-card').count(),1);
  assert.equal(await page.locator('[name=asesor] option[value="24"]').count(),1);
  assert.equal(await page.getByRole('link',{name:'Abrir expediente'}).count(),1);
  assert.match(page.url(),/asesor=22/);
  for(const width of [1440,390]){
   await page.setViewportSize({width,height:1000});await page.goto(index);
   assert.equal(await page.locator('.impe-v3o-columna').count(),5);
   await page.screenshot({path:path.join(dir,'supervision-'+width+'.png'),fullPage:true});
  }
  assert.equal(posts,0);assert.deepEqual(errors,[]);
  console.log('CHECK_OK supervisión navegador: estilos compartidos, cinco columnas, cuatro pestañas, asesor automático, expediente, paginación y solo consulta.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
