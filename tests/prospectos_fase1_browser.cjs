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
 const setupRoutes=async target=>{
 await target.route('https://**/*',async route=>{
  const url=route.request().url();let file,type;
  if(url.includes('bootstrap@')&&url.includes('/css/')){file=path.join(deps,'bootstrap/dist/css/bootstrap.min.css');type='text/css';}
  else if(url.includes('bootstrap@')&&url.includes('/js/')){file=path.join(deps,'bootstrap/dist/js/bootstrap.bundle.min.js');type='application/javascript';}
  else if(url.includes('bootstrap-icons')&&url.endsWith('.css')){file=path.join(deps,'bootstrap-icons/font/bootstrap-icons.min.css');type='text/css';}
  else if(url.includes('bootstrap-icons')&&/\.woff2?(\?|$)/.test(url)){file=path.join(deps,'bootstrap-icons/font/fonts/bootstrap-icons.woff2');type='font/woff2';}
  else if(url.includes('fonts.googleapis.com')){const css=fs.readFileSync(path.join(deps,'@fontsource/manrope/latin.css'),'utf8').replaceAll('./files/',base+'fixture-font/');await route.fulfill({contentType:'text/css',body:css});return;}
  if(file&&fs.existsSync(file))await route.fulfill({path:file,contentType:type});else await route.abort();
 });
 await target.route(base+'fixture-font/**',route=>route.fulfill({path:path.join(deps,'@fontsource/manrope/files',new URL(route.request().url()).pathname.split('/').pop()),contentType:'font/woff2'}));
 if(preview)await target.route(base+'**',async route=>{
  const url=new URL(route.request().url());if(url.pathname.startsWith('/fixture-font/')){await route.fallback();return;}
  if(url.pathname==='/index.php'){
   const html=execFileSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'prospectos_fase1_preview.php'),url.search.slice(1)],{cwd:root,env:process.env,encoding:'utf8'});
   await route.fulfill({contentType:'text/html',body:html});return;
  }
  const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));if(!file.startsWith(root+path.sep)||!fs.existsSync(file)||!fs.statSync(file).isFile()){await route.fulfill({status:404,body:''});return;}
  const ext=path.extname(file),types={'.css':'text/css','.js':'application/javascript','.png':'image/png','.svg':'image/svg+xml','.woff2':'font/woff2','.jpg':'image/jpeg'};await route.fulfill({path:file,contentType:types[ext]||'application/octet-stream'});
 });
 };await setupRoutes(context);
 if(!preview)await context.request.get(base+'_prospectos_session?role=marketing');
 const home=base+'index.php?controller=prospecto&action=index';
 await page.goto(home);await page.waitForSelector('[data-prospectos-state]');
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

 const searchAndWait=async term=>{await page.locator('#prospectoBuscar').fill(term);await page.waitForFunction(term=>new URL(location.href).searchParams.get('buscar')===term&& !document.querySelector('[data-prospectos-resultados]').hasAttribute('aria-busy'),term);};
 for(const [scope,expected] of [['&vista=todos','62'],['&estado_id=11','29']]){
  await page.goto(home+scope);
  check(await page.locator('[data-prospectos-filtros] button[type="submit"]').count()===0,'Sin botón Buscar redundante en '+scope);
  await searchAndWait('prueba29@example.test');
  check(await page.locator('.prospectos-total strong').textContent()==='1'&&await page.locator('tbody td:nth-child(3)').textContent()==='prueba29@example.test','Correo automático encuentra un prospecto fuera de la primera página en '+scope);
  check(await page.locator('#prospectoBuscar').evaluate(el=>el===document.activeElement),'La actualización conserva el foco del buscador');
  await searchAndWait('Prospecto 29');check(await page.locator('.prospectos-total strong').textContent()==='1','Nombre sigue disponible en la búsqueda automática');
  await searchAndWait('7771234567');check(await page.locator('.prospectos-total strong').textContent()===expected,'Teléfono sigue disponible en la búsqueda automática');
  await searchAndWait('');check(await page.locator('.prospectos-total strong').textContent()===expected,'Limpiar el buscador recupera el conjunto completo');
  await page.locator('#prospectoAliado').selectOption('7');await page.waitForFunction(()=>new URL(location.href).searchParams.get('aliado_id')==='7');
  check(await page.locator('.prospectos-total strong').textContent()==='20','El filtro de aliado se aplica automáticamente');
  await searchAndWait('prueba29@example.test');check(await page.locator('.prospectos-total strong').textContent()==='0','La búsqueda por correo conserva el filtro de aliado');
  await searchAndWait('');await page.locator('#prospectoAliado').selectOption('');await page.waitForFunction(()=>new URL(location.href).searchParams.get('aliado_id')==='');
  await page.locator('[rel="next"]').click();await page.waitForURL(/pagina=2/);await searchAndWait('prueba29@example.test');
  check(!new URL(page.url()).searchParams.has('pagina')&&await page.locator('.prospectos-total strong').textContent()==='1','Un filtro nuevo reinicia la página y conserva el ámbito');
 }
 // Incluso si el transporte no respeta AbortController, una respuesta anterior no puede sobrescribir la nueva.
 await page.goto(home+'&vista=todos');
 await page.evaluate(()=>{const original=window.fetch;window.fetch=(url,options)=>original(url,{...options,signal:undefined});});
 let releaseOld,oldRequested;const oldGate=new Promise(resolve=>releaseOld=resolve),requested=new Promise(resolve=>oldRequested=resolve);
 await page.route('**/index.php?**',async route=>{const url=new URL(route.request().url());if(route.request().resourceType()==='fetch'&&url.searchParams.get('buscar')==='prueba28@example.test'){oldRequested();await oldGate;}await route.fallback();});
 await page.locator('#prospectoBuscar').fill('prueba28@example.test');await requested;
 await searchAndWait('prueba29@example.test');const oldResponse=page.waitForResponse(r=>new URL(r.url()).searchParams.get('buscar')==='prueba28@example.test');releaseOld();await oldResponse;
 await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
 check(await page.locator('tbody td:nth-child(3)').textContent()==='prueba29@example.test','Las respuestas fuera de orden no sustituyen la última búsqueda');
 await page.unroute('**/index.php?**');
 await page.route('**/index.php?**',async route=>{if(route.request().resourceType()==='fetch')await route.fulfill({status:500,body:'Error simulado'});else await route.fallback();},{times:1});
 await page.locator('#prospectoBuscar').fill('sin-resultados');await page.locator('[data-prospectos-search-error]').waitFor({state:'visible'});
 check(await page.locator('.prospectos-total strong').textContent()==='1','Un error de red conserva la tabla e informa que no se actualizó');
 await page.locator('#prospectoBuscar').press('Enter');await page.waitForFunction(()=>document.querySelector('.prospectos-total strong').textContent==='0');
 check(await page.locator('[data-prospectos-search-error]').isHidden(),'Enter reintenta la consulta y limpia el error');
 await page.goto(home);check(await page.locator('[data-prospectos-state-search-form] button').count()===0,'El buscador automático de estados no tiene botón Buscar');

 if(process.env.IMPE_PROSPECTOS_FASE2==='1'){
  const key=(type,value)=>type+':'+Buffer.from(value).toString('base64url');
  const aGeneral=key('a','bachillerato-general'),aDos=key('a','bachillerato-2-anos'),gGeneral=key('g','Bachillerato General'),gIngles=key('g','Inglés');
  const waitFilter=async(name,value)=>page.waitForFunction(({name,value})=>(new URL(location.href).searchParams.get(name)||'')===value&&!document.querySelector('[data-prospectos-resultados]').hasAttribute('aria-busy'),{name,value}).catch(async error=>{console.error(await page.evaluate(()=>({url:location.href,program:document.querySelector('#prospectoPrograma')?.value,error:document.querySelector('[data-prospectos-search-error]')?.textContent,options:[...document.querySelectorAll('#prospectoPrograma option')].map(o=>[o.value,o.textContent])})));throw error;});
  const setAlly=async value=>{await page.locator('#prospectoAliado').selectOption(value);await waitFilter('aliado_id',value);};
  const setProgram=async value=>{await page.locator('#prospectoPrograma').selectOption(value);await waitFilter('programa',value);};
  const clearFilters=async()=>{await page.locator('[data-prospectos-limpiar]').click();await page.waitForFunction(()=>!document.querySelector('#prospectoBuscar').value&&!document.querySelector('#prospectoAliado').value&&!document.querySelector('#prospectoPrograma').value&&!new URL(location.href).searchParams.get('aliado_id')&&!document.querySelector('[data-prospectos-resultados]').hasAttribute('aria-busy'));};
  await page.goto(home+'&estado_id=11');
  check(await page.locator('#prospectoAliado option').evaluateAll(options=>options.map(o=>o.value).join(','))===',general,7,8','Aliados de este estado por ID, sin catálogo nacional mezclado');
  const allyBox=page.locator('#prospectoAliado + .prospectos-searchable');
  await allyBox.locator('.uniform-select-trigger').click();await allyBox.locator('input').fill('Institución duplicada');
  check(await allyBox.locator('[role="option"]').count()===2&&await allyBox.locator('[role="option"]').allTextContents().then(t=>t.every(v=>v.includes('ID #'))),'Buscador distingue dos aliados con el mismo nombre');
  await allyBox.locator('input').press('ArrowDown');await page.keyboard.press('Enter');await waitFilter('aliado_id','7');
  check(await page.locator('.prospectos-total strong').textContent()==='20','Selector con teclado consulta sólo el ID elegido');
  check(await page.locator('#prospectoPrograma option').count()===2,'Sólo programas con prospectos del aliado, no su oferta actual sin registros');
  await setProgram(aGeneral);await searchAndWait('Prospecto 2');check(await page.locator('.prospectos-total strong').textContent()==='2','Estado, aliado, programa y nombre combinados');
  await clearFilters();check(new URL(page.url()).searchParams.get('estado_id')==='11'&&await page.locator('.prospectos-total strong').textContent()==='29'&&await page.locator('[data-prospectos-limpiar]').isHidden(),'Limpiar filtros conserva el estado y oculta la acción cuando no hace falta');
  await setAlly('8');const programBox=page.locator('#prospectoPrograma + .prospectos-searchable');await programBox.locator('.uniform-select-trigger').click();await programBox.locator('input').fill('2 años');await programBox.locator('[role="option"]').click();await waitFilter('programa',aDos);
  check(await page.locator('.prospectos-total strong').textContent()==='3','Programas actualizados al cambiar de aliado, con selector buscable');
  await searchAndWait('prueba29@example.test');check(await page.locator('.prospectos-total strong').textContent()==='1','Los cuatro filtros incluyen el correo');
  await setAlly('7');check(await page.locator('#prospectoPrograma').inputValue()===''&&await page.locator('#prospectoBuscar').inputValue()==='prueba29@example.test'&&await page.locator('.prospectos-total strong').textContent()==='0','Cambiar aliado limpia sólo el programa incompatible y conserva la búsqueda');
  await clearFilters();await setProgram(aGeneral);await page.locator('[rel="next"]').click();await page.waitForURL(/pagina=2/);
  check(new URL(page.url()).searchParams.get('programa')===aGeneral&&new URL(page.url()).searchParams.get('estado_id')==='11'&&await page.locator('tbody tr').count()===1,'Paginación conserva estado y programa con total exacto');
  await searchAndWait('prueba1@example.test');check(await page.locator('.prospectos-total strong').textContent()==='1'&&!new URL(page.url()).searchParams.has('pagina'),'Nueva búsqueda reinicia página conservando programa y estado');
  check(await page.locator('tbody img').count()===0,'Datos de nombre escapados también en resultados automáticos');
  await clearFilters();await setAlly('general');check(await page.locator('.prospectos-total strong').textContent()==='0'&&await page.locator('.prospectos-empty').innerText().then(t=>t.includes('No hay prospectos para este aliado')),'Estado vacío con mensaje institucional, sin usar residencia para generales');
  await page.goto(home+'&vista=todos');await setAlly('general');check(await page.locator('.prospectos-total strong').textContent()==='22','Generales siguen completos en Todos');
  await setProgram(gGeneral);check(await page.locator('.prospectos-total strong').textContent()==='8','Perfil general no se confunde con el código de un aliado');
  await setProgram(gIngles);check(await page.locator('.prospectos-total strong').textContent()==='5','Perfil general real, con acentos');
  await setAlly('8');check(await page.locator('#prospectoPrograma').inputValue()===''&&await page.locator('.prospectos-total strong').textContent()==='9','No se inventa equivalencia al pasar de perfil general a programa de aliado');
  await clearFilters();check(new URL(page.url()).searchParams.get('vista')==='todos'&&await page.locator('.prospectos-total strong').textContent()==='62','Limpiar conserva la vista nacional y todos sus registros');
  await page.locator('#prospectoAliado').evaluate(select=>{for(let i=0;i<100;i++)select.add(new Option('Extra '+i,String(1000+i)));});
  await allyBox.locator('.uniform-select-trigger').click();check(await allyBox.locator('[role="option"]').count()===40,'Lista acotada a 40 opciones aunque existan muchos aliados');
  await allyBox.locator('input').fill('Extra 99');check(await allyBox.locator('[role="option"]').count()===1,'La búsqueda localiza un aliado fuera de las primeras 40 opciones');await allyBox.locator('input').press('Escape');
  await page.goto(home+'&vista=todos');
  const nojs=await browser.newContext({javaScriptEnabled:false,storageState:await context.storageState()});await setupRoutes(nojs);const fallback=await nojs.newPage();
  await fallback.goto(home+'&estado_id=11');await fallback.locator('#prospectoAliado').selectOption('8');await fallback.locator('#prospectoPrograma').selectOption(aDos);await fallback.locator('#prospectoBuscar').fill('prueba29@example.test');await fallback.getByRole('button',{name:'Aplicar filtros'}).click();
  check(await fallback.locator('.prospectos-total strong').textContent()==='1'&&new URL(fallback.url()).searchParams.get('estado_id')==='11','Sin JavaScript: formulario GET combina estado, aliado, programa y correo');
  await fallback.locator('[data-prospectos-limpiar]').click();check(await fallback.locator('.prospectos-total strong').textContent()==='29'&&new URL(fallback.url()).searchParams.get('estado_id')==='11','Sin JavaScript: limpiar conserva contexto y resultados');
  await nojs.close();
  if(!preview)await context.request.get(base+'_prospectos_session?role=clave');
  await page.goto(home+'&vista=todos'+(preview?'&_role=clave':''));
  check(await page.locator('#prospectoAliado + .prospectos-searchable .uniform-select-trigger').evaluate(el=>getComputedStyle(el).borderStyle==='solid')&&await page.locator('#prospectoAliado').evaluate(el=>getComputedStyle(el).position==='absolute'),'Mismo selector institucional para Cuenta Clave autorizado, sin ampliar permisos');
  if(!preview)await context.request.get(base+'_prospectos_session?role=marketing');
  if(preview){await page.goto(home+'&vista=todos&_case=programs');check(await page.locator('[data-prospectos-program-error]').isVisible()&&await page.locator('#prospectoPrograma').isDisabled()&&await page.locator('.prospectos-total strong').textContent()==='62','Error de programas con estilo, selector deshabilitado y tabla conservada');await page.screenshot({path:path.join(output,'error-programas.png')});}
 }
 for(const [width,height] of [[1440,1000],[1024,900],[768,1024],[390,844],[360,800]]){
  await page.setViewportSize({width,height});await page.goto(home);await page.evaluate(()=>document.fonts.ready);
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Inicio sin desbordamiento horizontal a '+width);
  await page.screenshot({path:path.join(output,'estados-'+width+'.png')});await page.goto(home+'&estado_id=11');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1&&document.querySelector('.prospectos-table-wrap').scrollWidth>document.querySelector('.prospectos-table-wrap').clientWidth),'Desplazamiento contenido en la tabla a '+width);
  const font=await page.locator('tbody td').first().evaluate(el=>({family:getComputedStyle(el).fontFamily,size:getComputedStyle(el).fontSize}));check(font.family.includes('Manrope')&&font.size==='12px','Manrope institucional conservada a '+width);
  if(process.env.IMPE_PROSPECTOS_FASE2==='1'){
   const heights=await page.locator('.prospectos-fase2-filters').evaluate(form=>[form.querySelector('.module-search'),...form.querySelectorAll('.uniform-select-trigger')].map(el=>el.getBoundingClientRect().height));
   check(Math.max(...heights)-Math.min(...heights)<=1,'Alturas uniformes de los tres filtros a '+width);
  }
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
