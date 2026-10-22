// Chrome aislado sobre HTML PHP y API interceptada. Nunca accede a producción.
const { chromium } = require(process.env.IMPE_PLAYWRIGHT_PATH || 'playwright');
const fs=require('fs'), path=require('path'), cp=require('child_process'), assert=require('node:assert/strict');
const root=process.cwd(), out=path.join(root,'tmp/prueba_correo_convocatorias_selectores');fs.mkdirSync(out,{recursive:true});
const legacy=process.argv.includes('--legacy');
const php="define('ROOT_PATH',$argv[1]);define('BASE_URL','https://impe-cv.test/');require $argv[1].'/app/views/correos/index.php';";
const render=cp.spawnSync('C:/xampp/php/php.exe',['-r',php,root],{encoding:'utf8'});assert.equal(render.status,0,render.stderr);
const html='<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+
 '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">'+
 '<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">'+
 ['global.css','dashboard.css','correos_marketing.css','responsive_sistema.css','marketing_selectores_uniformes.css'].map(x=>'<link rel="stylesheet" href="/public/css/'+x+'">').join('')+
 '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><script src="/public/javascript/correos_marketing.js"></script></head><body>'+render.stdout+
 '<select id="selector_ajeno"><option>Original</option></select><script src="/public/javascript/selectores_uniformes.js"></script></body></html>';
const states=[{id:11,nombre:'Guanajuato'},{id:25,nombre:'Sinaloa'}];
const rows=[{id:1,titulo:'Handly',estados:[states[0]],convenios:[{id:9,nombre:'Convenio QA',referencia:'QA-9'}]},
 {id:2,titulo:'Handly',estados:[states[1]],convenios:[]},{id:3,titulo:'Sin imagen',estados:[states[0]],convenios:[]}];
const image=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jR1sAAAAASUVORK5CYII=','base64');
const agreements=[{id:9,nombre:'Convenio QA',referencia:'QA-9',estado_id:11},{id:10,nombre:'Sin convocatoria',referencia:'QA-10',estado_id:11}];
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.IMPE_CHROME_PATH});
 try {
  const page=await browser.newPage({viewport:{width:1440,height:1000}});const errors=[];let catalogs=0,sends=0,sentBody='';
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://impe-cv.test/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
   if(url.pathname==='/index.php') {
    const action=url.searchParams.get('action');
    if(action==='convocatorias') {
     const id=Number(url.searchParams.get('id'));
     if(!id){catalogs++;return route.fulfill({json:{ok:true,estados:states,convocatorias:rows,convenios:agreements}});}
     const row=rows.find(x=>x.id===id);return route.fulfill({json:{ok:true,convocatoria:{...row,imagen_sha:id===3?'':'a'.repeat(64),
      imagen_url:id===3?'':'index.php?controller=correoMarketing&action=imagenConvocatoria&id='+id+'&v='+'a'.repeat(64),imagen_mensaje:id===3?'Sin imagen disponible':'La imagen se incluirá dentro del correo.'}}});
    }
    if(action==='imagenConvocatoria')return route.fulfill({contentType:'image/png',body:image});
    if(action==='enviar'){sends++;sentBody=route.request().postData();return route.fulfill({json:{ok:true,mensaje:'Aceptación simulada',estado_entrega:'ACEPTADO'}});}
    return route.fulfill({status:400,json:{ok:false}});
   }
   const local=path.join(root,url.pathname);
   if(!local.startsWith(root)||!fs.existsSync(local))return route.fulfill({status:404});
   if(legacy && url.pathname==='/public/javascript/correo_convocatorias.js')return route.fulfill({contentType:'application/javascript',body:cp.execFileSync('git',['show','2ba65dc4:public/javascript/correo_convocatorias.js'])});
   return route.fulfill({body:fs.readFileSync(local),contentType:local.endsWith('.css')?'text/css':'application/javascript'});
  });
  await page.goto('https://impe-cv.test/',{waitUntil:'networkidle'});
  await page.locator('[data-marketing-compose]').click();
  await page.locator('[data-convocatoria-estado] option[value="11"]').waitFor({state:'attached'});
  const stateMenu=page.locator('#correo_convocatoria_estado + .uniform-select');
  const agreementMenu=page.locator('#correo_convocatoria_convenio + .uniform-select');
  const callMenu=page.locator('#correo_marketing_convocatoria + .uniform-select');
  await page.waitForFunction(()=>!document.querySelector('[data-convocatoria-estado]').disabled);
  const visibleOptions=await stateMenu.locator('[data-uniform-select-value]').count();
  if(legacy){assert.equal(visibleOptions,1);assert.equal(await page.locator('[data-convocatoria-estado] option').count(),3);console.log('REPRODUCIDO: select nativo con 3 opciones y menú institucional con sólo 1. Sin cambios en producción.');return;}
  assert.equal(visibleOptions,3,'Menú institucional sincronizado con el catálogo asíncrono');
  assert.equal(await agreementMenu.locator('[data-uniform-select-value="10"]').count(),1,'Convenio sin convocatorias presente');
  const choose=async(menu,value)=>{await menu.locator('[data-uniform-select-trigger]').click();await menu.locator('[data-uniform-select-value="'+value+'"]').click();};
  await page.locator('[data-marketing-mail-body]').fill('Texto QA que debe permanecer íntegro.');
  await page.locator('[data-marketing-mail-to]').fill('destino@example.test');
  await page.locator('[data-marketing-mail-subject]').fill('Prueba QA');
  await page.locator('[data-marketing-mail-files]').setInputFiles({name:'manual.txt',mimeType:'text/plain',buffer:Buffer.from('Adjunto QA')});
  await choose(stateMenu,'11');
  assert.match(await stateMenu.locator('[data-uniform-select-label]').innerText(),/Guanajuato/);
  assert.deepEqual(await page.locator('[data-marketing-mail-convocatoria] option').evaluateAll(os=>os.map(o=>o.value)),['','1','3']);
  await choose(agreementMenu,'10');
  assert.equal(await callMenu.locator('[data-uniform-select-value]').count(),1,'Convenio sin relaciones no inventa convocatorias');
  assert.equal(await page.locator('[data-convocatoria-convenio]').inputValue(),'10','Selección de convenio independiente permanece');
  await choose(agreementMenu,'9');
  await choose(callMenu,'1');
  await page.waitForFunction(()=>document.querySelector('[data-convocatoria-imagen-sha]').value.length===64);
  assert.equal(await page.locator('[data-convocatoria-imagen]').evaluate(i=>i.complete&&i.naturalWidth>0),true);
  assert.equal(await page.locator('[data-marketing-mail-body]').inputValue(),'Texto QA que debe permanecer íntegro.');
  await page.locator('[data-convocatoria-limpiar]').click();
  assert.match(await stateMenu.locator('[data-uniform-select-label]').innerText(),/Todos los estados/);
  assert.match(await agreementMenu.locator('[data-uniform-select-label]').innerText(),/Todos los convenios/);
  assert.equal(await page.locator('[data-marketing-mail-body]').inputValue(),'Texto QA que debe permanecer íntegro.');
  assert.equal(await page.locator('[data-marketing-mail-to]').inputValue(),'destino@example.test');
  assert.equal(await page.locator('[data-marketing-mail-subject]').inputValue(),'Prueba QA');
  assert.equal(await page.locator('[data-marketing-mail-files]').evaluate(e=>e.files.length),1);
  assert.equal(await page.locator('#selector_ajeno + .uniform-select [data-uniform-select-value]').count(),1,'Selector ajeno intacto');
  await choose(stateMenu,'11');await choose(agreementMenu,'9');await choose(callMenu,'1');
  await page.waitForFunction(()=>document.querySelector('[data-convocatoria-imagen-sha]').value.length===64);
  await page.screenshot({path:path.join(out,'escritorio.png')});
  await page.setViewportSize({width:390,height:844});
  await page.screenshot({path:path.join(out,'movil.png')});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'Sin desbordamiento horizontal móvil');
  assert.equal(await page.locator('#modalCorreoMarketing .modal-footer').isVisible(),true);
  assert.equal(catalogs,1,'Una consulta del catálogo');
  await page.locator('[data-marketing-mail-from]').fill('qa@rededucativamexico.org');
  await page.locator('[data-marketing-mail-to]').fill('destino@example.test');
  await page.locator('[data-marketing-mail-subject]').fill('Prueba QA');
  await Promise.all([page.waitForResponse(r=>r.url().includes('action=enviar')),page.locator('[data-marketing-mail-send]').click()]);
  assert.equal(sends,1);assert.match(sentBody,/name="convocatoria_id"\r\n\r\n1/);assert.match(sentBody,/Texto QA que debe permanecer/);assert.match(sentBody,/manual.txt/);
  assert.deepEqual(errors,[]);
  console.log('OK: Chrome real con selectores_uniformes.js sin cambios, menús visibles actualizados, convenios sin convocatoria, filtros combinados, limpieza y rótulos, imagen, selector ajeno, texto/archivo manual, desktop/móvil y envío interceptado. Datos ficticios; sin producción. Capturas: '+out);
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
