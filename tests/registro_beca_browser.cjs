const assert=require('node:assert/strict'),path=require('node:path'),{spawn,execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php',base=process.env.IMPE_BASE_URL;
assert.equal(process.env.APP_ENV,'testing');assert.match(process.env.IMPE_DB_NAME||'',/^impe_test_handly_horario_/);
const server=spawn(php,['-S',new URL(base).host,'-t',root,path.join(__dirname,'registro_beca_router.php')],{cwd:root,env:process.env,stdio:['ignore','ignore','pipe']});let log='';server.stderr.on('data',b=>log+=b);
(async()=>{let browser,n=0;const check=(v,m)=>{assert.ok(v,m);n++;console.log('OK '+m);};try{
 for(let i=0;i<70;i++){try{await fetch(base+'_beca_links');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});const auth=await browser.newContext(),publicContext=await browser.newContext(),page=await publicContext.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await publicContext.route('https://**/*',r=>r.request().url().includes('/bootstrap.bundle.min.js')?r.fulfill({path:path.join(process.env.IMPE_BROWSER_DEPS||path.resolve(root,'../browser-deps/node_modules'),'bootstrap/dist/js/bootstrap.bundle.min.js'),contentType:'application/javascript'}):r.abort());
 await auth.route('https://**/*',r=>r.abort());
 const links=await(await auth.request.get(base+'_beca_links')).json();const old=links.url,next=old.replace('/el-primer-paso-hacia-tu-meta-comienza-aqui/','/obtener-beca/');
 await auth.request.get(base+'_beca_session');const endpoint=base+'index.php?controller=formulario&action=acortarRegistro';
 let response=await auth.request.post(endpoint,{form:{url:old,csrf_token:'a'.repeat(64)}});check(response.ok()&&(await response.json()).url===next,'Generar enlace de un aliado existente devuelve la ruta nueva con el mismo código');
 response=await auth.request.post(endpoint,{form:{url:old,csrf_token:'bad'}});check(response.status()===419,'Se mantiene el control CSRF de generación');
 const authPage=await auth.newPage();await authPage.goto(base+'index.php?controller=formularioPublico&action=registro');await authPage.addScriptTag({url:base+'public/javascript/registro_enlaces_amigables.js'});await authPage.evaluate(()=>window.IMPE_CSRF_TOKEN='a'.repeat(64));
 check(await authPage.evaluate(url=>window.IMPERegistroEnlaces.acortar(url),old)===next,'El helper convierte la liga anterior incluso sin parámetros');
 check(await authPage.evaluate(url=>window.IMPERegistroEnlaces.acortar(url),next)===next,'La liga nueva no necesita regenerarse');
 for(const [label,url] of [['anterior',old],['nueva',next]]){
  await page.goto(url);check(await page.locator('[name=nombre]').isVisible(),'El QR o liga '+label+' abre Registro sin sesión');
  const action=await page.locator('form').getAttribute('action');check(new URL(action).searchParams.get('aliado')===links.token,'La liga '+label+' conserva al aliado original');
  const email='beca-'+label+'@example.test';for(const [field,value] of Object.entries({nombre:'Prueba',apellido:'Beca',fecha_nacimiento:'1990-01-01',movil:'7772345678',movil_confirmacion:'7772345678',correo:email,correo_confirmacion:email}))await page.locator('[name='+field+']').fill(value);
  await page.locator('[name=perfil_interes]').selectOption('Bachillerato General');await page.locator('[data-horario-modo]').selectOption('sin_preferencia');await page.locator('[name=estado_id]').selectOption('1');await page.locator('[name=municipio_id] option[value="1"]').waitFor({state:'attached'});await page.locator('[name=municipio_id]').selectOption('1');
  const result=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('guardarRegistro'));await page.locator('button[type=submit]').click();check((await result).ok(),'La liga '+label+' permite enviar el formulario');await page.waitForURL(/action=gracias/);
  const rows=await(await publicContext.request.get(base+'_horario_rows?buscar='+encodeURIComponent(email))).json();check(rows.length===1&&Number(rows[0].aliado_id)===links.aliado_id&&rows[0].programa_codigo==='bachillerato-general'&&rows[0].horario_contacto==='Sin preferencia','Prospectos conserva aliado, programa y horario desde la liga '+label);
 }
 for(const slug of ['obtener-beca','el-primer-paso-hacia-tu-meta-comienza-aqui']){const r=await page.goto(base+'registro/'+slug+'/000000000000');check(r.status()===404&&await page.locator('h1').textContent()==='Registro no disponible','Código inexistente no abre otro formulario: '+slug);}
 check(errors.length===0&&!/PHP (?:Fatal|Warning|Notice)|Uncaught/.test(log),'Sin errores PHP ni JavaScript');
 execFileSync(php,['tests/registro_beca_db.php'],{cwd:root,env:process.env});check(true,'Tras los envíos siguen intactas las ligas, tokens y convocatorias existentes');
 console.log('PASS '+n+' comprobaciones HTTP obtener-beca');
 }finally{await browser?.close();server.kill();}})().catch(e=>{console.error(e.stack);console.error(log.slice(-4000));process.exitCode=1;});
