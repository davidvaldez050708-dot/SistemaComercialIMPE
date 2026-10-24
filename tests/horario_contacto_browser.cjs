const assert=require('node:assert/strict'),path=require('node:path'),fs=require('node:fs'),{spawn,execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php',preview=process.env.IMPE_HORARIO_PREVIEW==='1',base=process.env.IMPE_BASE_URL||'http://127.0.0.1:8142/';
assert.equal(process.env.APP_ENV,'testing');if(!preview)assert.match(process.env.IMPE_DB_NAME||'',/^impe_test_handly_horario_[a-z0-9_]+$/);
const out=process.env.IMPE_HORARIO_OUTPUT||path.resolve(root,'../../outputs/horario-contacto');fs.mkdirSync(out,{recursive:true});
const deps=process.env.IMPE_BROWSER_DEPS||path.resolve(root,'../browser-deps/node_modules');let server,log='';
if(!preview){server=spawn(php,['-S',new URL(base).host,'-t',root,path.join(__dirname,'horario_contacto_router.php')],{cwd:root,env:process.env,stdio:['ignore','ignore','pipe']});server.stderr.on('data',x=>log+=x);}
(async()=>{let browser,n=0;const check=(v,m)=>{assert.ok(v,m);n++;console.log('OK '+m);};try{
 if(!preview)for(let i=0;i<70;i++){try{await fetch(base+'index.php?controller=formularioPublico&action=registro');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});const context=await browser.newContext(),page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await context.route('https://**/*',async route=>{
  const url=route.request().url();let file,type='text/css';
  if(url.includes('bootstrap@')&&url.includes('/css/'))file='bootstrap/dist/css/bootstrap.min.css';
  else if(url.includes('bootstrap@')&&url.includes('/js/')){file='bootstrap/dist/js/bootstrap.bundle.min.js';type='application/javascript';}
  else if(url.includes('bootstrap-icons')&&url.endsWith('.css'))file='bootstrap-icons/font/bootstrap-icons.min.css';
  else if(url.includes('bootstrap-icons')&&url.includes('.woff')){file='bootstrap-icons/font/fonts/bootstrap-icons.woff2';type='font/woff2';}
  else if(url.includes('fonts.googleapis.com')){await route.fulfill({body:fs.readFileSync(path.join(deps,'@fontsource/manrope/latin.css'),'utf8').replaceAll('./files/',base+'fixture-font/'),contentType:type});return;}
  if(file)await route.fulfill({path:path.join(deps,file),contentType:type});else await route.abort();
 });
 await context.route(base+'fixture-font/**',r=>r.fulfill({path:path.join(deps,'@fontsource/manrope/files',new URL(r.request().url()).pathname.split('/').pop()),contentType:'font/woff2'}));
 if(process.env.IMPE_HORARIO_JS_VARIANT)await context.route('**/public/javascript/formularios_registro_publico.js*',r=>r.fulfill({path:process.env.IMPE_HORARIO_JS_VARIANT,contentType:'application/javascript'}));
 let posted=[];
 if(preview)await context.route(base+'**',async route=>{
  const req=route.request(),url=new URL(req.url());if(url.pathname.startsWith('/fixture-font/'))return route.fallback();
  if(url.pathname.endsWith('/formularios_registro_publico.js')&&process.env.IMPE_HORARIO_JS_VARIANT)return route.fallback();
  if(url.searchParams.get('action')==='municipios')return route.fulfill({json:{ok:true,municipios:[{id:1,nombre:'Cuernavaca'}]}});
  if(req.method()==='POST'){posted.push(req.postData());return route.fulfill({json:{ok:true,registro_id:900,mensaje:'Registro guardado correctamente.'}});}
  if(url.pathname==='/index.php'||url.pathname==='/_horario_internal')return route.fulfill({contentType:'text/html',body:execFileSync(php,[path.join(__dirname,'horario_contacto_preview.php'),url.pathname.includes('internal')?'interno':'publico'],{cwd:root,env:process.env,encoding:'utf8'})});
  const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));if(!file.startsWith(root+path.sep)||!fs.existsSync(file))return route.fulfill({status:404,body:''});
  const type={'.js':'application/javascript','.css':'text/css','.png':'image/png','.svg':'image/svg+xml'}[path.extname(file)];await route.fulfill({path:file,contentType:type||'application/octet-stream'});
 });
 const urls=[['publico',base+'index.php?controller=formularioPublico&action=registro'],['interno',base+'_horario_internal']];
 if(!preview){const short=execFileSync(php,['-r',"require 'config/db_connection.php';echo (new Database())->connect(false)->query('SELECT codigo FROM registro_enlaces_amigables ORDER BY codigo LIMIT 1')->fetch_row()[0];"],{cwd:root,env:process.env,encoding:'utf8'});urls.push(['liga-anterior',base+'registro/el-primer-paso-hacia-tu-meta-comienza-aqui/'+short]);}
 for(const [kind,url] of urls){
  if(kind==='liga-anterior')await context.clearCookies(); // Un prospecto abre la liga sin sesión del CRM.
  await page.goto(url);const form=page.locator('form').filter({has:page.locator('[data-horario-contacto]')});
  check(await page.locator('[data-horario-contacto]').count()===1,'Campo único en '+kind);check(!await page.locator('[data-horario-config]').isVisible(),'Configuración oculta al iniciar '+kind);
  await form.evaluate(f=>f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));check(await page.locator('[data-horario-error]').isVisible(),'Preferencia requerida '+kind);
  await page.locator('[data-horario-modo]').selectOption('personalizado');
  await form.evaluate(f=>f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));check((await page.locator('[data-horario-error]').textContent()).includes('al menos un día'),'Días requeridos '+kind);
  await page.locator('[data-horario-dia][value=lun]').check();await page.locator('[data-horario-dia][value=vie]').check();await page.locator('[data-horario-desde]').fill('18:00');await page.locator('[data-horario-hasta]').fill('17:00');
  await form.evaluate(f=>f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));check((await page.locator('[data-horario-error]').textContent()).includes('posterior'),'Rango invertido rechazado '+kind);await page.locator('[data-horario-hasta]').fill('20:30');
  await page.locator('[data-horario-hasta]').blur();
  for(const width of [1440,768,390,360]){
   await page.setViewportSize({width,height:900});await page.locator('[data-horario-contacto]').scrollIntoViewIfNeeded();
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Sin desbordamiento '+kind+' '+width);
   check(await page.locator('[data-horario-contacto]').evaluate(el=>getComputedStyle(el).fontFamily.includes('Manrope')),'Tipografía del sistema '+kind+' '+width);
   await page.screenshot({path:path.join(out,kind+'-'+width+'.png')});
  }
  const mail='horario-'+kind+'@example.test';
  for(const [name,value] of Object.entries({nombre:'Prueba',apellido:'Horario',fecha_nacimiento:'1990-01-01',movil:'7772345678',movil_confirmacion:'7772345678',correo:mail,correo_confirmacion:mail}))await form.locator('[name='+name+']').fill(value);
  await form.locator('[name=perfil_interes]').selectOption({label:'Bachillerato General'});await form.locator('[name=estado_id]').selectOption('1');await form.locator('[name=municipio_id] option[value="1"]').waitFor({state:'attached'});await form.locator('[name=municipio_id]').selectOption('1');
  if(!preview){
   const action=await form.getAttribute('action'),payload=await form.evaluate(f=>Object.fromEntries(new FormData(f)));
   const bad=await context.request.post(action,{form:{...payload,contacto_hasta:'17:00'}});
   check(bad.status()===422&&(await bad.json()).mensaje.includes('posterior'),'Validación servidor sin confiar en JavaScript '+kind);
   check((await(await context.request.get(base+'_horario_rows?buscar='+encodeURIComponent(mail))).json()).length===0,'Horario inválido no crea un prospecto '+kind);
  }
  const response=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('guardarRegistro'));await form.locator('button[type=submit]').click();check((await response).ok(),'Envío con JS original '+kind);
  if(!preview){const rows=await(await context.request.get(base+'_horario_rows?buscar='+encodeURIComponent(mail))).json();check(rows.length===1&&rows[0].horario_contacto==='Lun, Vie · 18:00–20:30 (hora local)','Horario persistido y consultable en Prospectos '+kind);}
  else check(posted.at(-1).includes('contacto_desde')&&posted.at(-1).includes('18:00'),'FormData conserva el horario '+kind);
  await page.goto(url);await page.locator('[data-horario-modo]').selectOption('personalizado');await page.locator('[data-horario-dia]').first().check();await page.locator('[data-horario-modo]').selectOption('sin_preferencia');
  check(await page.locator('[data-horario-config]').evaluate(el=>el.disabled&&el.hidden),'Sin preferencia desactiva datos anteriores '+kind);
  await form.evaluate(f=>f.reset());await page.waitForTimeout(40);check(await page.locator('[data-horario-modo]').inputValue()===''&&!await page.locator('[data-horario-config]').isVisible(),'Restablecer limpia la preferencia '+kind);
 }
 check(errors.length===0&&!/PHP (?:Warning|Fatal|Notice)|Uncaught/.test(log),'Sin errores JavaScript ni PHP '+errors.join(';'));
 console.log('PASS '+n+' comprobaciones navegador horario '+(preview?'vista y transporte simulado':'HTTP y BD reales'));
 }finally{if(browser)await browser.close();server?.kill();}})().catch(e=>{console.error(e.stack);console.error(log.slice(-3000));process.exitCode=1;});
