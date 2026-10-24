const assert=require('node:assert/strict'),path=require('node:path'),fs=require('node:fs'),{spawn,execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=process.env.IMPE_V5_APP_ROOT||path.resolve(__dirname,'..'),base=process.env.IMPE_BASE_URL||'http://127.0.0.1:8120/';
assert.match(process.env.IMPE_DB_NAME||'',/^impe_test_handly_aliados_v4_v5_[a-z0-9_]+$/);
const server=spawn(process.env.PHP_BINARY||'php',['-S',new URL(base).host,'-t',root,path.join(__dirname,'convocatorias_v5_router.php')],{env:{...process.env,IMPE_TEST_ALLOW_ROUTER:'1'},cwd:root,stdio:['ignore','ignore','pipe']});let log='';server.stderr.on('data',b=>log+=b);
(async()=>{let browser,count=0;const check=(v,m)=>{assert.ok(v,m);count++;console.log('OK '+m);};try{
 for(let i=0;i<80;i++){try{await fetch(base+'_v5_stats');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 const auth=await browser.newContext(),publicContext=await browser.newContext(),page=await publicContext.newPage(),errors=[];
 page.on('pageerror',e=>errors.push(e.message));await publicContext.route('https://**/*',r=>r.abort());
 const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.join(root,'../browser-deps/node_modules/bootstrap');
 await publicContext.route('**/fixture-bootstrap.js',r=>r.fulfill({path:path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),contentType:'application/javascript'}));
 await publicContext.route('**/fixture-bootstrap.css',r=>r.fulfill({path:path.join(bootstrap,'dist/css/bootstrap.min.css'),contentType:'text/css'}));
 await auth.request.get(base+'_v5_session?role=cc');const endpoint=base+'index.php?controller=formulario&action=acortarRegistro';
 const shorten=async(url,csrf='a'.repeat(64))=>auth.request.post(endpoint,{form:{url,csrf_token:csrf}});
 const long=base+'index.php?controller=formularioPublico&action=registro';let response=await shorten(long);let data=await response.json();const general=data.url;
 check(response.ok()&&/\/registro\/el-primer-paso-hacia-tu-meta-comienza-aqui\/[A-Za-z0-9_-]{12}$/.test(general),'API devuelve frase completa sin query ni token visible');
 response=await shorten('https://evil.example/?controller=formularioPublico&action=registro');check(response.status()===422,'API impide destinos externos');response=await shorten(long,'malo');check(response.status()===419,'API exige CSRF');
 response=await auth.request.get(endpoint);check(response.status()===405,'API exige POST');await auth.request.get(base+'_v5_session?role=reader');response=await shorten(long);check(response.status()===403,'API no concede generación a lector');await auth.request.get(base+'_v5_session?role=cc');
 const listed=await(await auth.request.get(base+'index.php?controller=convocatoria&action=listadoFiltrado&territorio_id=1&tipo=bachillerato')).json();const row=listed.convocatorias.find(c=>c.aliado?.id===10&&c.enlace_registro.includes('/registro/el-primer-'));assert.ok(row);const allyUrl=row.enlace_registro;
 await page.goto(general);check(await page.locator('[name="nombre"]').isVisible(),'enlace general abre formulario público sin sesión');
 await page.goto(allyUrl);check(await page.locator('[name="perfil_interes"] option').allTextContents().then(items=>items.some(x=>x.includes('Bachillerato General'))&&!items.some(x=>x.trim()==='Inglés')),'enlace de aliado conserva sólo sus programas');
 const formAction=await page.locator('form').getAttribute('action');const token=new URL(formAction).searchParams.get('aliado');check(/^[a-f0-9]{64}$/.test(token),'formulario conserva token original al guardar');
 const correo='alias-'+Date.now()+'@example.test';
 for(const [key,value] of Object.entries({nombre:'Prueba',apellido:'Alias',fecha_nacimiento:'1990-01-01',movil:'7772345678',movil_confirmacion:'7772345678',correo,correo_confirmacion:correo}))await page.locator('[name="'+key+'"]').fill(value);
 await page.locator('[name="perfil_interes"]').selectOption('Bachillerato General');await page.locator('[name="estado_id"]').selectOption('1');await page.locator('[name="municipio_id"] option[value="1"]').waitFor({state:'attached'});await page.locator('[name="municipio_id"]').selectOption('1');
 const sending=page.waitForResponse(r=>r.url().includes('action=guardarRegistro')&&r.request().method()==='POST');if(await page.locator('[data-horario-modo]').count())await page.locator('[data-horario-modo]').selectOption('sin_preferencia');
   await page.locator('button[type="submit"]').click();check((await sending).ok(),'Registro desde liga amigable se guarda mediante formulario real');await page.waitForURL(/action=gracias/);
 const attribution=JSON.parse(execFileSync(process.env.PHP_BINARY||'php',['-r',"require 'config/db_connection.php'; $d=(new Database())->connect(false);$q=$d->prepare('SELECT p.aliado_id,p.programa_codigo FROM formulario_aliado_procedencias p JOIN formulario_registros r ON r.id=p.registro_id WHERE p.tipo=? AND r.correo=?');$email='"+correo+"';$kind='registro';$q->bind_param('ss',$kind,$email);$q->execute();echo json_encode($q->get_result()->fetch_assoc());"],{cwd:root,env:process.env,encoding:'utf8'}));check(attribution?.programa_codigo==='bachillerato-general','registro guardado conserva procedencia y programa autorizado');
 const legacy=long+'&aliado='+token;await page.goto(legacy);check(await page.locator('[name="nombre"]').isVisible(),'liga larga anterior sigue funcionando');
 const a=await shorten(legacy),b=await shorten(legacy);check((await a.json()).url===(await b.json()).url,'generar repetidamente recupera mismo código');
 const contextUrl=(await(await shorten(legacy+'&convocatoria_ref=ref-http&estado_id=1&titulo=Prueba')).json()).url;check(contextUrl!==allyUrl&&!contextUrl.includes('?'),'referencia contextual tiene alias propio sin query');
 await page.goto(base+'registro/el-primer-paso-hacia-tu-meta-comienza-aqui/000000000000');check((await page.locator('h1').textContent())==='Registro no disponible','código desconocido no abre Registro general ni otro aliado');
 const before=await(await auth.request.get(base+'_v5_stats')).json();await auth.request.get(base+'_v5_session?role=marketing');
 const status=active=>auth.request.post(base+'index.php?controller=aliado&action=registroGestion',{form:{csrf_token:'a'.repeat(64),origen:'institucional',id:'10',operacion:active?'habilitar':'deshabilitar'}});
 try{await status(false);await page.goto(allyUrl);check(!(await page.locator('[name="nombre"]').count()),'Registro deshabilitado también bloquea alias');await page.goto(legacy);check(!(await page.locator('[name="nombre"]').count()),'Registro deshabilitado bloquea igualmente liga anterior');}finally{await status(true);}
 await page.goto(allyUrl);check(await page.locator('[name="nombre"]').isVisible(),'rehabilitar recupera mismo enlace corto');
 // El helper de navegador acorta los parámetros del editor general antes de dibujar QR.
 await publicContext.request.get(base+'_v5_session?role=cc');await page.goto(base+'_v5');
 const generated=await page.evaluate(async url=>{window.IMPE_CSRF_TOKEN='a'.repeat(64);return window.IMPEConvocatoriasV5.acortarRegistro(url);},legacy+'&estado_id=1&convocatoria_ref=browser-ref');check(!generated.includes('?')&&generated.includes('/registro/el-primer-'),'helper del editor genera liga contextual limpia');
 const after=await(await auth.request.get(base+'_v5_stats')).json();check(JSON.stringify(before.rows)===JSON.stringify(after.rows)&&JSON.stringify(before.links)===JSON.stringify(after.links),'acortar no modifica convocatorias ni asociaciones existentes');
 check(errors.length===0&&!/PHP (?:Warning|Fatal|Notice)|Uncaught/.test(log),'sin errores JavaScript/PHP: '+errors.join('; '));console.log('PASS '+count+' comprobaciones HTTP/navegador de Registro amigable');
}catch(e){console.error(e.stack);console.error(log.slice(-3500));process.exitCode=1;}finally{if(browser)await browser.close();server.kill();}})();
