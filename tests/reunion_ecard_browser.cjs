const fs=require('fs'),path=require('path'),assert=require('assert/strict'),vm=require('vm'),{chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{channel:'chrome'})});
 try{
 const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.resolve('../browser-deps/node_modules/bootstrap');
 const source=fs.readFileSync('public/javascript/agenda_reunion.js','utf8');
 const compact=source.slice(source.indexOf('        const ecardCorreoCompacta ='),source.indexOf('        const configurarModalCorreo ='));
 const change=source.slice(source.indexOf("        document.addEventListener('change', function (event) {\n            const select = event.target.closest('[data-ecard-template]');"),source.indexOf("        document.addEventListener('click'",source.indexOf("const select = event.target.closest('[data-ecard-template]');")));
 const card=vm.runInNewContext(compact+'ecardCorreoCompacta(reunion)',{escapar:s=>String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'),reunion:{id:1,ecard_preview_url_manuel:'http://ecard.test/default.png?x=1',ecard_preview_url_sergio:'http://ecard.test/default.png?x=2'}});
 const view=fs.readFileSync('app/views/seguimiento_vinculacion/agenda.php','utf8');
 let modal=view.slice(view.indexOf('    <div class="modal fade" id="modalAgendaCorreo"'),view.indexOf('    <div class="modal fade" id="modalAgendaEcardVista"'));
 modal=modal.replace('<div data-agenda-mail-ecard></div>','<div data-agenda-mail-ecard>'+card+'</div>');
 const errors=[];
 for(const width of [1366,390,320]){
  const page=await browser.newPage({viewport:{width,height:950}});page.on('pageerror',e=>errors.push(e.message));let submitted='';
  await page.route('http://ecard.test/**',async route=>{
   const u=new URL(route.request().url());
   if(u.pathname==='/index.php'){submitted=route.request().postData()||'';return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({ok:false,mensaje:'Respuesta simulada; no se envió correo.'})});}
   if(u.pathname==='/default.png')return route.fulfill({contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1cAAAAASUVORK5CYII=','base64')});
   if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:'<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/bootstrap.css">'+['global','dashboard','agenda_reunion','agenda_reunion_refinamientos','reunion_ecard_imagen'].map(s=>'<link rel="stylesheet" href="/public/css/'+s+'.css">').join('')+'</head><body>'+modal+'<script src="/bootstrap.js"></script><script>'+change+'</script><script src="/public/javascript/reunion_ecard_imagen.js"></script><script src="/public/javascript/correo_firma_envio.js"></script></body></html>'});
   const file=u.pathname==='/bootstrap.css'?path.join(bootstrap,'dist/css/bootstrap.min.css'):u.pathname==='/bootstrap.js'?path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'):path.resolve('.'+u.pathname);
   return route.fulfill({contentType:file.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(file)});
  });
  await page.goto('http://ecard.test/');
  await page.evaluate(()=>{const f=document.querySelector('[data-agenda-mail-form]');f.querySelector('[name=reunion_id]').value='1';f.querySelector('[name=asunto]').value='Prueba';f.querySelector('[name=cuerpo]').value='Mensaje sintético';bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAgendaCorreo')).show();});
  const upload=page.locator('[data-ecard-upload-file]');
  await upload.setInputFiles({name:'imagen.png',mimeType:'image/png',buffer:Buffer.from(await page.evaluate(()=>{const c=document.createElement('canvas');c.width=120;c.height=180;return Array.from(Uint8Array.from(atob(c.toDataURL('image/png').split(',')[1]),x=>x.charCodeAt(0)));}))});
  await page.getByText('Imagen lista. Revisa la vista previa antes de enviar.').waitFor();
  assert.equal(await page.locator('[data-ecard-template]').isDisabled(),true);
  assert.equal(await page.locator('[data-ecard-upload-expected]').inputValue(),'1');
  await page.locator('[data-agenda-mail-submit]').click();
  await page.getByText('Respuesta simulada; no se envió correo.').waitFor();
  assert.ok(submitted.includes('name="ecard_imagen"; filename="imagen.png"')&&submitted.includes('name="ecard_personalizada_esperada"'));
  await page.locator('[data-ecard-upload]').scrollIntoViewIfNeeded();
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
  fs.mkdirSync('tmp',{recursive:true});await page.screenshot({path:'tmp/reunion-ecard-'+width+'.png'});
  await page.locator('[data-ecard-upload-reset]').click();assert.equal(await page.locator('[data-ecard-template]').isEnabled(),true);
  assert.equal(await page.locator('[data-ecard-upload-expected]').inputValue(),'0');
  assert.match(await page.locator('[data-ecard-preview-image]').getAttribute('src'),/default.png/);
  await upload.setInputFiles({name:'mal.png',mimeType:'image/png',buffer:Buffer.from('no es imagen')});
  await page.getByText('No se pudo leer la imagen. Selecciona otra imagen o usa la predeterminada.').waitFor();
  assert.equal(await upload.evaluate(e=>e.checkValidity()),false);
  await page.locator('[data-ecard-upload-reset]').click();
  await page.evaluate(()=>{document.querySelector('[data-agenda-mail-ecard]').innerHTML=document.querySelector('[data-agenda-mail-ecard]').innerHTML;});
  assert.equal(await upload.inputValue(),'');
  await page.close();
 }
 assert.deepEqual(errors,[]);console.log('PASS: modal institucional, reemplazo, vista previa, FormData real, predeterminada, archivos inválidos y tres tamaños; sin correos reales.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
