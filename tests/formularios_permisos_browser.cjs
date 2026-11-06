const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {spawn,execFileSync}=require('node:child_process');const {chromium}=require('playwright');
const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php';
const base='http://127.0.0.1:8129/';
const env={...process.env,IMPE_BASE_URL:base,APP_ENV:'testing',IMPE_DB_HOST:process.env.IMPE_DB_HOST||'127.0.0.1:33339',IMPE_DB_USER:process.env.IMPE_DB_USER||'root',IMPE_DB_PASSWORD:process.env.IMPE_DB_PASSWORD||'',IMPE_DB_NAME:'impe_test_formularios_'+Date.now()};
const db=action=>execFileSync(php,['tests/formularios_permisos_db.php',action],{cwd:root,env,encoding:'utf8'});
(async()=>{let browser,server,created=false;try{
 console.log(db('crear').trim());created=true;fs.mkdirSync(path.join(root,'tmp'),{recursive:true});
 const log=fs.openSync(path.join(root,'tmp/formularios-permisos-http.log'),'w');
 // CI loads instrumentation extensions incompatible with PHP JIT. Keep genuine
 // application warnings fatal to this test, without enabling JIT on its server.
 server=spawn(php,['-d','opcache.jit=0','-d','opcache.enable_cli=0','-S','127.0.0.1:8129','-t',root],{cwd:root,env,stdio:['ignore',log,log]});
 for(let i=0;i<80;i++){try{await fetch(base+'public/css/formularios.css');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const bs=process.env.BOOTSTRAP_MODULE_PATH||path.join(root,'../browser-deps/node_modules/bootstrap');
 await page.route('https://**/*',r=>{const u=r.request().url();if(u.includes('bootstrap.min.css'))return r.fulfill({path:path.join(bs,'dist/css/bootstrap.min.css'),contentType:'text/css'});if(u.includes('bootstrap.bundle.min.js'))return r.fulfill({path:path.join(bs,'dist/js/bootstrap.bundle.min.js'),contentType:'application/javascript'});return r.abort();});
 const endpoint=async(rol,accion)=>{const r=await page.request.post(base+'tests/formularios_permisos_fixture.php?vista=endpoint&rol='+rol+'&accion='+accion,{form:{}});const body=await r.text();assert(!body.includes('Fatal error'),body);return{status:r.status(),body};};
 for(const action of ['municipios','generarEnlaceRegistro','generarEnlaceInscripcion','guardarRegistro','guardarInscripcion','guardarInscripcionCompleta','comprobanteInscripcion','validarEjecutivas','acortarRegistro'])assert.equal((await endpoint(8,action)).status,403,action+' no tiene bypass por rol Marketing');
 for(const [rol,action,status] of [[2,'municipios',422],[2,'guardarRegistro',403],[2,'guardarInscripcionCompleta',403],[2,'generarEnlaceRegistro',403],[2,'comprobanteInscripcion',403],[3,'guardarRegistro',422],[3,'guardarInscripcion',403],[4,'guardarRegistro',403],[4,'guardarInscripcion',422],[4,'guardarInscripcionCompleta',419],[6,'comprobanteInscripcion',404],[6,'guardarInscripcionCompleta',403],[7,'generarEnlaceRegistro',200],[7,'generarEnlaceInscripcion',200],[7,'guardarRegistro',403],[9,'acortarRegistro',200]]){const r=await endpoint(rol,action);assert.equal(r.status,status,JSON.stringify({rol,action,...r}));}
 const url=(vista,rol,extra='')=>base+'tests/formularios_permisos_fixture.php?vista='+vista+'&rol='+rol+extra;
 for(const vista of ['registro','inscripcion']){
  await page.goto(url(vista,2));assert.equal(await page.locator('button[type=submit]').count(),0,'consulta sin guardar');assert.equal(await page.locator('fieldset.formularios-consulta-fieldset[disabled]').count(),1);assert.equal(await page.locator('[name=nombre]').isDisabled(),true);assert.equal(await page.locator('[data-inscripcion-enlace]').count(),0,'sin compartir');
  await page.goto(url(vista,vista==='registro'?3:4));assert.equal(await page.locator('button[type=submit]').count(),1,'captura autorizada');assert.equal(await page.locator('[name=nombre]').isDisabled(),false);
 }
 await page.goto(url('inscripcion',7));assert.equal(await page.locator('[data-inscripcion-enlace]').count(),1);assert.equal(await page.locator('button[type=submit]').count(),0);
 for(const rol of [2,3,4,6,7]){await page.goto(url('menu',rol));assert.equal(await page.locator('a[href*="controller=formulario&"]').count(),2,'menú de escritorio y móvil sin exigir Convocatorias');}
 await page.goto(url('menu',8));assert.equal(await page.locator('a[href*="controller=formulario&"]').count(),0);
 await page.goto(url('catalogo',2));assert.equal(await page.locator('a[href*="ya-estas-inscrito"]').count(),0);
 await page.goto(url('catalogo',7));assert.equal(await page.locator('a[href*="ya-estas-inscrito"]').count(),5);
 await page.goto(url('inscripcion',8,'&publico=1'));assert.equal(await page.locator('[name=nombre]').isDisabled(),false,'el público no requiere permiso CRM');
 await page.locator('button[type=submit]').click();
 const sizes=await page.locator('.invalid-feedback:not(:empty)').evaluateAll(elements=>elements.map(el=>({font:getComputedStyle(el).fontSize,line:getComputedStyle(el).lineHeight})));assert(sizes.length>10);assert(sizes.every(v=>v.font==='9px'&&v.line==='12.6px'),JSON.stringify(sizes));
 for(const field of ['date','file'])assert.equal(await page.locator('input[type='+field+'].is-invalid').first().evaluate(e=>getComputedStyle(e).backgroundImage),'none');
 await page.locator('.formularios-registration-section').last().screenshot({path:path.join(root,'tmp/formularios-errores-desktop.png')});
 await page.setViewportSize({width:390,height:844});assert.equal(await page.locator('body').evaluate(e=>e.scrollWidth<=innerWidth+1),true);await page.locator('.formularios-registration-section').last().screenshot({path:path.join(root,'tmp/formularios-errores-movil.png')});
 assert.deepEqual(errors,[]);
 const logs=fs.readFileSync(path.join(root,'tmp/formularios-permisos-http.log'),'utf8');assert(!/PHP (Warning|Fatal)/.test(logs),logs.split('\n').filter(line=>/PHP (Warning|Fatal)/.test(line)).join('\n'));
 console.log('PASS HTTP/UI: nueve rutas protegidas, roles independientes, consulta sin captura, compartir y comprobantes separados, menús sin Convocatorias, público y errores uniformes en escritorio/móvil.');
}finally{if(browser)await browser.close();if(server){server.kill();await new Promise(r=>server.once('exit',r));}if(created)console.log(db('limpiar').trim());}})().catch(e=>{console.error(e);process.exitCode=1;});
