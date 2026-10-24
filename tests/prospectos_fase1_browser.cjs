const assert=require('node:assert/strict'),path=require('node:path'),fs=require('node:fs'),{spawn,execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),preview=process.env.IMPE_PROSPECTOS_PREVIEW==='1',base=process.env.IMPE_BASE_URL||'http://127.0.0.1:8130/';
assert.equal(process.env.APP_ENV,'testing');if(!preview)assert.match(process.env.IMPE_DB_NAME||'',/^impe_test_prospectos_fase1_[a-z0-9_]+$/);
const output=process.env.IMPE_PROSPECTOS_OUTPUT||path.resolve(root,'../../outputs/prospectos-fase1');fs.mkdirSync(output,{recursive:true});
let server,log='';if(!preview){server=spawn(process.env.PHP_BINARY||'php',['-S',new URL(base).host,'-t',root,path.join(__dirname,'prospectos_fase1_router.php')],{cwd:root,env:{...process.env,IMPE_PROSPECTOS_ROUTER:'1'},stdio:['ignore','ignore','pipe']});server.stderr.on('data',data=>log+=data);}
(async()=>{let browser,count=0;const check=(ok,message)=>{assert.ok(ok,message);console.log('OK '+message);count++;};try{
 if(!preview)for(let i=0;i<80;i++){try{await fetch(base+'_prospectos_session');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});const context=await browser.newContext(),page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const deps=process.env.IMPE_BROWSER_DEPS||path.resolve(root,'../browser-deps/node_modules');
 await context.route('https://**/*',async route=>{
  const url=route.request().url();let file,type;
  if(url.includes('bootstrap@')&&url.includes('/css/')){file=path.join(deps,'bootstrap/dist/css/bootstrap.min.css');type='text/css';}
  else if(url.includes('bootstrap@')&&url.includes('/js/')){file=path.join(deps,'bootstrap/dist/js/bootstrap.bundle.min.js');type='application/javascript';}
  else if(url.includes('bootstrap-icons')&&url.endsWith('.css')){file=path.join(deps,'bootstrap-icons/font/bootstrap-icons.min.css');type='text/css';}
  else if(url.includes('bootstrap-icons')&&/\.woff2?(\?|$)/.test(url)){file=path.join(deps,'bootstrap-icons/font/fonts/bootstrap-icons.woff2');type='font/woff2';}
  else if(url.includes('fonts.googleapis.com')){const css=fs.readFileSync(path.join(deps,'@fontsource/manrope/latin.css'),'utf8').replaceAll('./files/',base+'fixture-font/');await route.fulfill({contentType:'text/css',body:css});return;}
  if(file&&fs.existsSync(file))await route.fulfill({path:file,contentType:type});else await route.abort();
 });
 await context.route(base+'fixture-font/**',route=>route.fulfill({path:path.join(deps,'@fontsource/manrope/files',new URL(route.request().url()).pathname.split('/').pop()),contentType:'font/woff2'}));
 if(preview)await context.route(base+'**',async route=>{
  const url=new URL(route.request().url());if(url.pathname.startsWith('/fixture-font/')){await route.fallback();return;}
  if(url.pathname==='/index.php'){
   const html=execFileSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'prospectos_fase1_preview.php'),url.search.slice(1)],{cwd:root,env:process.env,encoding:'utf8'});
   await route.fulfill({contentType:'text/html',body:html});return;
  }
  const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));if(!file.startsWith(root+path.sep)||!fs.existsSync(file)||!fs.statSync(file).isFile()){await route.fulfill({status:404,body:''});return;}
  const ext=path.extname(file),types={'.css':'text/css','.js':'application/javascript','.png':'image/png','.svg':'image/svg+xml','.woff2':'font/woff2','.jpg':'image/jpeg'};await route.fulfill({path:file,contentType:types[ext]||'application/octet-stream'});
 });
 if(!preview)await context.request.get(base+'_prospectos_session?role=marketing');
 const home=base+'index.php?controller=prospecto&action=index';await page.goto(home);await page.waitForSelector('[data-prospectos-state]');
 check(await page.locator('.prospectos-view[aria-current="page"]').innerText().then(t=>t.includes('Por estados')),'Vista inicial por estados, dos controles institucionales');
 check(await page.locator('.prospectos-view').count()===2&&await page.locator('[data-prospectos-state]').count()===32,'Todos los estados activos del catálogo');
 for(const [key,value] of Object.entries({total:'62',estados:'2',aliados:'40',generales:'22'}))check(await page.locator('[data-prospectos-indicador="'+key+'"] strong').textContent()===value,'Indicador '+key+' correcto');
 await page.locator('[data-prospectos-state] img').evaluateAll(images=>images.forEach(image=>image.loading='eager'));await page.waitForFunction(()=>[...document.querySelectorAll('[data-prospectos-state] img')].every(image=>image.complete&&image.naturalWidth>0));
 check(await page.locator('[data-prospectos-state] img').count()===32,'Mapas existentes completos, sin duplicar ni usar imágenes externas');
 const searchFocus=async selector=>{await page.locator(selector).focus();const css=await page.locator(selector).evaluate(el=>({input:getComputedStyle(el).outlineStyle,shadow:getComputedStyle(el).boxShadow,container:getComputedStyle(el.closest('.module-search')).boxShadow}));check(css.input==='none'&&css.shadow==='none'&&css.container!=='none','Buscador con un único foco visible: '+selector);};
 await searchFocus('[data-prospectos-state-search]');
 // Retener la navegación permite comprobar la página antigua mientras espera la respuesta.
 const next=page.locator('.prospectos-view').filter({hasText:'Todos los prospectos'});
 await next.evaluate(el=>el.addEventListener('click',event=>event.preventDefault(),{once:true}));
 const oldTop=await page.locator('.prospectos-summary').evaluate(el=>el.getBoundingClientRect().top);await next.click();
 const snapshot=await page.evaluate(()=>({banner:!!document.querySelector('[data-prospectos-loading]'),top:document.querySelector('.prospectos-summary').getBoundingClientRect().top}));
 check(!snapshot.banner&&snapshot.top===oldTop,'Cambio de pestaña sin aviso ni salto del contenido');
 await next.click();await page.waitForURL(/vista=todos/);
 await page.goto(home);
 await page.locator('[data-prospectos-state-search]').fill('Queretaro');check(await page.locator('[data-prospectos-state]:visible').count()===1,'Búsqueda de estados sin distinguir acentos');
 await page.locator('[data-prospectos-state-search]').fill('Sin coincidencia');check(await page.locator('[data-prospectos-state-empty]').isVisible(),'Búsqueda sin resultados con estado visual');
 await page.locator('[data-prospectos-state-search]').fill('Guanajuato');await page.locator('[data-prospectos-state="Guanajuato"]').click();await page.waitForSelector('.prospectos-table-wrap');
 check(await page.locator('.prospectos-heading h2').textContent()==='Prospectos de Guanajuato','Tarjeta abre directamente la tabla, sin pantalla intermedia');
 await searchFocus('#prospectoBuscar');
 check(await page.locator('thead th').count()===14,'Conservados los 14 campos y el filtro de aliado confirmado por el usuario');
 check(await page.locator('tbody tr').count()===25,'Primera página de 25 prospectos');
 check(await page.locator('tbody tr td:nth-child(9)').allTextContents().then(rows=>rows.every(value=>value==='Morelos')),'Tabla conserva la residencia Morelos del prospecto cuyo aliado está en Guanajuato');
 await page.locator('[rel="next"]').click();await page.waitForURL(/pagina=2/);check(new URL(page.url()).searchParams.get('estado_id')==='11'&&await page.locator('tbody tr').count()===4,'Paginación conserva estado y muestra cuatro restantes');
 check(await page.locator('tbody img').count()===0,'Datos HTML escapados');
 await page.locator('#prospectosCambiarEstado').selectOption('1');await page.locator('.prospectos-state-navigation button[type="submit"]').click();await page.waitForURL(/estado_id=1(?:&|$)/);check(await page.locator('.prospectos-empty').textContent().then(t=>t.includes('No hay prospectos registrados')),'Estado sin prospectos conserva tabla y estado vacío');
 await page.locator('.prospectos-view').filter({hasText:'Todos los prospectos'}).click();await page.waitForURL(/vista=todos/);check(await page.locator('.prospectos-total strong').textContent()==='62','Todos incluye registros generales, de aliados e históricos');
 for(const [width,height] of [[1440,1000],[1024,900],[768,1024],[390,844],[360,800]]){
  await page.setViewportSize({width,height});await page.goto(home);await page.evaluate(()=>document.fonts.ready);
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Inicio sin desbordamiento horizontal a '+width);
  await page.screenshot({path:path.join(output,'estados-'+width+'.png')});await page.goto(home+'&estado_id=11');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1&&document.querySelector('.prospectos-table-wrap').scrollWidth>document.querySelector('.prospectos-table-wrap').clientWidth),'Desplazamiento contenido en la tabla a '+width);
  const font=await page.locator('tbody td').first().evaluate(el=>({family:getComputedStyle(el).fontFamily,size:getComputedStyle(el).fontSize}));check(font.family.includes('Manrope')&&font.size==='12px','Manrope institucional conservada a '+width);
  await page.screenshot({path:path.join(output,'tabla-'+width+'.png')});
 }
 await page.goto(home+'&estado_id=999999');check(await page.locator('.prospectos-fase1 [role="alert"]').first().textContent().then(t=>t.includes('Estado no encontrado')),'Estado no válido muestra error institucional');
 if(preview){
  await page.goto(home+'&_case=origin');check(await page.locator('[data-prospectos-indicador="aliados"] strong').textContent()==='—','Procedencia no verificada nunca se presenta como cero');await page.screenshot({path:path.join(output,'procedencia-no-disponible.png')});
  await page.goto(home+'&_case=territory');check(await page.locator('.prospectos-fase1 [role="alert"]').count()>0&&await page.locator('[data-prospectos-indicador="estados"] strong').textContent()==='—','Error territorial controlado con indicador sin verificar');
  await page.goto(home+'&_case=error');check(await page.locator('.prospectos-fase1 [role="alert"]').count()>0&&await page.locator('tbody tr').count()===0,'Error de consulta no se presenta como listado vacío válido');
  await page.goto(home+'&_case=denied');check(await page.locator('.prospectos-fase1 [role="alert"]').textContent().then(t=>t.includes('No tienes permiso')),'Error de permiso con diseño institucional');
 }else{await context.request.get(base+'_prospectos_session?role=denied');const denied=await page.goto(home);check(denied.status()===403&&await page.locator('.prospectos-fase1 [role="alert"]').textContent().then(t=>t.includes('No tienes permiso')),'Permisos actuales: acceso no autorizado devuelve 403');}
 check(errors.length===0&&!/PHP (?:Warning|Fatal|Notice)|Uncaught/.test(log),'Sin errores JavaScript/PHP: '+errors.join('; '));
 fs.writeFileSync(path.join(output,'resultado.json'),JSON.stringify({checks:count,mode:preview?'Vista y layout reales con datos simulados; sin validar SQL':'Controlador, modelo, vista, layout y BD reales en esquema sintético',errors},null,2));console.log('PASS '+count+' comprobaciones '+(preview?'visuales con datos simulados':'HTTP/BD reales'));
}finally{if(browser)await browser.close();if(server)server.kill();}})().catch(error=>{console.error(error.stack);console.error(log.slice(-2500));process.exitCode=1;});
