const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {spawn}=require('node:child_process'),{chromium}=require('playwright');
const root=path.resolve(__dirname,'..'),base='http://127.0.0.1:8133/';
(async()=>{let browser,server;try{
 fs.mkdirSync(path.join(root,'tmp'),{recursive:true});const log=fs.openSync(path.join(root,'tmp/inscritos-diseno-http.log'),'w');
 server=spawn(process.env.PHP_BINARY||'php',['-d','opcache.jit_buffer_size=0','-S','127.0.0.1:8133','-t',root],{cwd:root,env:{...process.env,APP_ENV:'testing'},stdio:['ignore',log,log]});
 for(let i=0;i<80;i++){try{await fetch(base+'public/css/inscritos.css');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 const page=await browser.newPage({viewport:{width:1500,height:1050}}),errors=[],requests=[];page.on('pageerror',e=>errors.push(e.message));
 const bs=process.env.BOOTSTRAP_MODULE_PATH||path.join(root,'../browser-deps/node_modules/bootstrap');
 await page.route('https://**/*',r=>r.request().url().includes('bootstrap.min.css')?r.fulfill({path:path.join(bs,'dist/css/bootstrap.min.css'),contentType:'text/css'}):r.abort());
 let total=2225,exportParams=null;
 await page.route('**/index.php?*',async route=>{
  const q=new URL(route.request().url()).searchParams,accion=q.get('action');requests.push(new URLSearchParams(q));
  if(accion==='exportar'){exportParams=q;return route.fulfill({status:200,headers:{'Content-Type':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','Content-Disposition':'attachment; filename="sintetico.xlsx"'},body:Buffer.from('PK\x03\x04 sintético')});}
  if(accion==='detalle')return route.fulfill({json:{ok:true,registro:{id:1,programa_slug:'bachillerato-286',origen:'PUBLICO',created_at:'2026-11-06 18:00:00',aceptado_at:'2026-11-06 18:00:00',datos:{nombre:'Persona de prueba',apellidos:'Sintética',correo:'prueba@example.test',movil:'5512345678'},comprobante_recibido:true,puede_descargar_comprobante:true,terminos_registrados:true,privacidad_registrada:true}}});
  const paginas=Math.max(1,Math.ceil(total/25)),pagina=Math.max(1,Math.min(Number(q.get('pagina'))||1,paginas));
  const nombres=['Persona de prueba','Registro sintético','Ejemplo de inscripción'];
  const registros=Array.from({length:Math.min(25,Math.max(0,total-(pagina-1)*25))},(_,i)=>({id:(pagina-1)*25+i+1,nombre:nombres[i%3],apellido:'Apellido sintético',correo:'prueba'+i+'@example.test',movil:'5512345678',programa_slug:q.get('programa')||'bachillerato-286',origen:i%2?'MARKETING':'PUBLICO',created_at:'2026-11-06 18:00:00'}));
  return route.fulfill({json:{ok:true,registros,total,pagina,paginas,por_pagina:25}});
 });
 await page.goto(base+'tests/inscritos_diseno_fixture.php');
 await page.locator('[data-inscritos-resumen]').filter({hasText:'Mostrando 1–25'}).waitFor();
 assert.equal(await page.locator('.inscritos-heading h2').innerText(),'Todos los inscritos');assert.equal(await page.locator('.inscritos-heading-icon').count(),0);
 assert.equal(await page.locator('h1').innerText(),'Inscritos');assert.equal(await page.locator('h2').allTextContents().then(a=>a.includes('Inscripciones recibidas')),false);
 const nav=page.locator('[data-inscritos-paginacion]');assert.equal(await nav.locator('[aria-current=page]').innerText(),'1');assert((await nav.locator('button').count())<13,'paginación acotada, no 89 botones');
 await nav.locator('button[data-inscritos-pagina="89"]').click();await page.locator('[data-inscritos-resumen]').filter({hasText:'2201–2225'}).waitFor();assert.equal(await nav.locator('[aria-current=page]').innerText(),'89');assert(await nav.getByRole('button',{name:'Página siguiente',exact:true}).isDisabled());
 await nav.locator('button[data-inscritos-pagina="1"]').click();await page.locator('[data-inscritos-resumen]').filter({hasText:'1–25'}).waitFor();
 await page.locator('[name=programa]').selectOption('titulacion-ejecutivas');await page.waitForTimeout(400);
 await page.locator('[name=buscar]').fill('Instituto');await page.locator('[name=desde]').fill('2026-11-01');await page.locator('[name=hasta]').fill('2026-11-06');await page.waitForTimeout(450);
 await nav.locator('button[data-inscritos-pagina="3"]').click();await page.locator('[data-inscritos-resumen]').filter({hasText:'51–75'}).waitFor();
 const ultima=requests.filter(q=>q.get('action')==='listar').at(-1);for(const [k,v] of Object.entries({pagina:'3',programa:'titulacion-ejecutivas',buscar:'Instituto',desde:'2026-11-01',hasta:'2026-11-06'}))assert.equal(ultima.get(k),v,k);
 const download=page.waitForEvent('download');await page.locator('[data-inscritos-excel]').click();await download;assert.equal(exportParams.has('pagina'),false);assert.equal(exportParams.get('programa'),'titulacion-ejecutivas');assert.equal(exportParams.get('buscar'),'Instituto');assert.equal(exportParams.get('desde'),'2026-11-01');assert.equal(exportParams.get('hasta'),'2026-11-06');
 await page.locator('[data-inscritos-limpiar]').click();await page.locator('[data-inscritos-resumen]').filter({hasText:'1–25'}).waitFor();assert.equal(await page.locator('[name=buscar]').inputValue(),'');
 await page.screenshot({path:path.join(root,'tmp/inscritos-diseno-desktop.png'),fullPage:true});
 await page.locator('[data-inscritos-id]').first().click();await page.locator('[data-inscritos-ficha]').filter({hasText:'pago sin validar'}).waitFor();assert(!(await page.locator('[data-inscritos-ficha]').innerText()).includes('Pago validado'));await page.locator('[data-inscritos-cerrar]').click();
 await page.setViewportSize({width:390,height:844});await page.waitForTimeout(100);await page.screenshot({path:path.join(root,'tmp/inscritos-diseno-movil.png'),fullPage:true});assert(await page.locator('body').evaluate(e=>e.scrollWidth<=innerWidth+1));assert(await nav.evaluate(e=>e.getBoundingClientRect().right<=innerWidth+1&&e.getBoundingClientRect().height<=40));
 total=0;await page.locator('[data-inscritos-limpiar]').click();await page.locator('[data-inscritos-resumen]').filter({hasText:'0 inscritos'}).waitFor();assert(await nav.isHidden());assert(await page.locator('[data-inscritos-excel]').isDisabled());
 total=10001;await page.locator('[data-inscritos-limpiar]').click();await page.locator('[data-inscritos-resumen]').filter({hasText:'10,001'}).waitFor();assert(await page.locator('[data-inscritos-excel]').isDisabled());
 await page.goto(base+'tests/inscritos_diseno_fixture.php?exportar=0');assert.equal(await page.locator('[data-inscritos-excel]').count(),0);
 assert.deepEqual(errors,[]);const logs=fs.readFileSync(path.join(root,'tmp/inscritos-diseno-http.log'),'utf8');assert(!/PHP (Warning|Fatal)/.test(logs),logs.slice(-2000));
 console.log('PASS UI: Inscritos sin encabezado duplicado; números y extremos de 89 páginas; filtros conservados; exportación sin página y límite; vacío, ficha, permisos y móvil.');
}finally{if(browser)await browser.close();if(server)server.kill();}})().catch(e=>{console.error(e);process.exitCode=1;});
