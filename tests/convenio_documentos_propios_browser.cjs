const fs=require('fs'),path=require('path'),assert=require('assert/strict'),{chromium}=require('playwright');
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:process.env.CI?{}:{channel:'chrome'})});try{
 const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.resolve('../browser-deps/node_modules/bootstrap');
 for(const [width,height] of [[1366,900],[1280,720],[390,844]]){
  const page=await browser.newPage({viewport:{width,height}});const errors=[];page.on('pageerror',e=>errors.push(e.message));let sent;
  await page.route('http://convenio.test/**',async route=>{
   const req=route.request(),u=new URL(req.url());
   if(u.searchParams.get('action')==='borradorConvenio')return route.fulfill({json:{ok:true,correo:{para:'institucion@example.test',carta_fecha:'2026-11-05',asunto:'Documentación para convenio de colaboración',cuerpo:'Buen día:\n\nCompartimos la carta propuesta y el convenio para su revisión.\n\nUna vez requisitado el convenio, agradeceremos nos lo compartan para continuar con la formalización.\n\nQuedamos atentos a cualquier duda o comentario.\n\nSaludos cordiales,\nAnalista de prueba'}}});
   if(u.searchParams.get('action')==='registrarPostEnvio'){
    const data=await new Response(req.postDataBuffer(),{headers:{'Content-Type':req.headers()['content-type']}}).formData();
    sent={carta:data.get('carta_origen'),convenio:data.get('convenio_origen'),fecha:data.get('carta_fecha'),cartaArchivo:data.get('carta_archivo')?.name,convenioArchivo:data.get('convenio_archivo')?.name};
    return route.fulfill({status:422,json:{ok:false,mensaje:'Respuesta simulada, sin enviar correo.'}});
   }
   if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:'<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/bootstrap.css">'+['global','dashboard','seguimiento_post_envio'].map(f=>'<link rel="stylesheet" href="/public/css/'+f+'.css">').join('')+'<button data-work-follow data-work-follow-id="1">Expediente</button><div id="offcanvasSeguimientoTrabajo"><div data-work-flow-section><button data-flow-action="ENVIAR_DOCUMENTACION_CONVENIO">Preparar documentos</button><button data-flow-action="REGISTRAR_CONVENIO_RECIBIDO">Otro paso</button></div></div><script src="/bootstrap.js"></script><script src="/public/javascript/seguimiento_post_envio.js"></script>'});
   const file=u.pathname==='/bootstrap.css'?path.join(bootstrap,'dist/css/bootstrap.min.css'):u.pathname==='/bootstrap.js'?path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'):path.resolve('.'+u.pathname);
   return route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':'application/javascript'});
  });
  await page.goto('http://convenio.test/');await page.getByText('Expediente',{exact:true}).click();await page.getByText('Preparar documentos',{exact:true}).click();
  const modal=page.locator('#modalSeguimientoPostEnvio');await modal.locator('[name=carta_origen]').waitFor();await page.waitForFunction(()=>{const m=document.querySelector('#modalSeguimientoPostEnvio'),d=m?.querySelector('.modal-dialog');return m&&getComputedStyle(m).opacity==='1'&&d&&getComputedStyle(d).transform==='none';});
  assert.equal(await modal.locator('[name=carta_archivo]').isDisabled(),true);assert.equal(await modal.locator('[name=carta_fecha]').isVisible(),true);
  await modal.locator('[name=carta_origen]').selectOption('propio');assert.equal(await modal.locator('[name=carta_fecha]').isDisabled(),true);assert.equal(await modal.locator('[name=carta_fecha]').isVisible(),false);
  await modal.locator('[name=carta_archivo]').setInputFiles({name:'Mi carta.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4 test')});
  await modal.locator('[name=convenio_origen]').selectOption('propio');await modal.locator('[name=convenio_archivo]').setInputFiles({name:'Convenio actualizado.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4 test')});
  const header=await modal.locator('.modal-header').boundingBox();assert.ok(header.y>=0,'Encabezado accesible');
  const footer=await modal.locator('.modal-footer').boundingBox();assert.ok(footer.y>=0&&footer.y+footer.height<=height+1,'Botones accesibles');
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
  if(width===1366)assert.ok(await modal.locator('.modal-body').evaluate(e=>e.scrollHeight<=e.clientHeight+1),'Sin scroll interno en escritorio');
  fs.mkdirSync('tmp',{recursive:true});await page.screenshot({path:'tmp/convenio-propios-'+width+'.png'});
  await modal.locator('[data-post-envio-save]').click();await modal.getByText('Respuesta simulada, sin enviar correo.').waitFor();
  assert.deepEqual(sent,{carta:'propio',convenio:'propio',fecha:null,cartaArchivo:'Mi carta.pdf',convenioArchivo:'Convenio actualizado.pdf'});
  await modal.locator('[name=carta_origen]').selectOption('sistema');assert.equal(await modal.locator('[name=carta_fecha]').isVisible(),true);assert.equal(await modal.locator('[name=carta_archivo]').isDisabled(),true);
  await modal.locator('[name=convenio_origen]').selectOption('sistema');await modal.locator('[data-post-envio-save]').click();await page.waitForTimeout(100);assert.equal(sent.cartaArchivo,undefined);assert.equal(sent.convenioArchivo,undefined);assert.equal(sent.fecha,'2026-11-05');
  await modal.locator('.btn-close').click();await page.getByText('Otro paso',{exact:true}).click();assert.equal(await modal.evaluate(e=>e.classList.contains('convenio-compose')),false,'No cambia otros formularios');
  assert.deepEqual(errors,[]);await page.close();
 }
 console.log('PASS: formulario real, reemplazos separados, fecha condicional, FormData, restauración, 3 tamaños, sin scroll interno a 1366x900 y botones accesibles.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});
