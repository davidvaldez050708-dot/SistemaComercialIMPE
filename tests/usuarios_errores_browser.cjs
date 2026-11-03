const assert=require('node:assert/strict'),path=require('node:path'),fs=require('node:fs'),{spawn,execFileSync}=require('node:child_process'),{chromium}=require('playwright');
const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php',base='http://127.0.0.1:8156/';
const env={...process.env,APP_ENV:'testing',IMPE_BASE_URL:base,IMPE_DB_HOST:'127.0.0.1:33319',IMPE_DB_USER:'root',IMPE_DB_PASSWORD:'',IMPE_DB_NAME:'impe_test_usuarios_errores_'+Date.now()};
const fixture=op=>execFileSync(php,[path.join(__dirname,'usuarios_errores_fixture.php'),op],{env,cwd:root,encoding:'utf8'});let server,browser,log='';
(async()=>{try{
 fixture('--seed');server=spawn(php,['-S','127.0.0.1:8156','-t',root,path.join(__dirname,'usuarios_errores_router.php')],{env,cwd:root,stdio:['ignore','ignore','pipe']});server.stderr.on('data',b=>log+=b);
 for(let i=0;i<80;i++){try{await fetch(base+'_session');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 browser=await chromium.launch({headless:true,channel:'chrome'});const ctx=await browser.newContext({viewport:{width:1440,height:1000}}),page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const deps=path.resolve(root,'../browser-deps/node_modules');await ctx.route('https://**/*',async r=>{const u=r.request().url();let file,type;
  if(u.includes('bootstrap@')&&u.includes('/css/')){file='bootstrap/dist/css/bootstrap.min.css';type='text/css';}
  else if(u.includes('bootstrap@')&&u.includes('/js/')){file='bootstrap/dist/js/bootstrap.bundle.min.js';type='application/javascript';}
  else if(u.includes('bootstrap-icons')&&u.includes('.css')){file='bootstrap-icons/font/bootstrap-icons.min.css';type='text/css';}
  else if(u.includes('bootstrap-icons')&&u.includes('.woff')){file='bootstrap-icons/font/fonts/bootstrap-icons.woff2';type='font/woff2';}
  else if(u.includes('fonts.googleapis.com')){return r.fulfill({body:fs.readFileSync(path.join(deps,'@fontsource/manrope/latin.css'),'utf8').replaceAll('./files/',base+'fixture-font/'),contentType:'text/css'});}
  return file?r.fulfill({path:path.join(deps,file),contentType:type}):r.abort();
 });await ctx.route(base+'fixture-font/**',r=>r.fulfill({path:path.join(deps,'@fontsource/manrope/files',new URL(r.request().url()).pathname.split('/').pop()),contentType:'font/woff2'}));
 await ctx.request.get(base+'_session');const url=a=>base+'index.php?controller=usuario&action='+a;await page.goto(url('index'));
 await page.locator('[data-bs-target="#modalCrearUsuario"]').click();await page.locator('#modalCrearUsuario button[type=submit]').click();
 const create=page.locator('#modalCrearUsuario');assert.equal((await create.locator('[data-user-summary]').innerText()).trim(),'Revisa los campos señalados.');assert.equal(await create.locator('[data-user-error]:visible').count(),7);assert.equal(fixture('--count'),'3');assert.equal(await page.locator('#crear_nombre').evaluate(e=>e===document.activeElement),true);
 const out=path.join(root,'docs/usuarios_errores_capturas');fs.mkdirSync(out,{recursive:true});await page.screenshot({path:path.join(out,'crear-escritorio.png')});
 await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(out,'crear-movil.png')});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await create.locator('[data-bs-dismiss=modal]').first().click();
 await page.goto(url('index'));const data={nombre:'Nombre Ficticio',apellidos:'Prueba',usuario:'usuario_nuevo',correo:'nuevo@example.test',rol_id:'3',password:'ClavePrueba123',confirmar_password:'ClavePrueba123'};
 // Server validation remains authoritative without JavaScript.
 let response=await ctx.request.post(url('guardar'),{form:{...data,correo:'fixture3@example.test'}});assert.equal(response.status(),200);let html=await response.text();assert.ok(html.includes('El correo electrónico ya está registrado.'));assert.equal(fixture('--count'),'3');assert.ok(!html.includes('value="ClavePrueba123"'));
 response=await ctx.request.post(url('guardar'),{form:{...data,password:'',confirmar_password:''}});html=await response.text();assert.ok(html.includes('Escribe una contraseña.'));assert.equal(fixture('--count'),'3');
 await ctx.request.post(url('guardar'),{form:data});assert.equal(fixture('--count'),'4');
 const edit={id:'3',nombre:'Editado',apellidos:'Prueba',usuario:'fixture3',correo:'fixture3@example.test',rol_id:'5',estado:'1'};
 response=await ctx.request.post(url('actualizar'),{form:{...edit,correo:'incorrecto'}});html=await response.text();assert.ok(html.includes('El formato del correo electrónico no es válido.'));assert.ok(html.includes('value="Editado"'));
 await page.goto(url('index'));await page.locator('[data-bs-target="#modalEditarUsuario"][data-id="3"]').click();await page.locator('#editar_nombre').fill('');await page.locator('#modalEditarUsuario button[type=submit]').click();assert.ok(await page.locator('#editar_nombre_error').isVisible());assert.equal(await page.locator('#editar_nombre').getAttribute('aria-invalid'),'true');await page.locator('#editar_nombre').fill('Editado');assert.ok(await page.locator('#editar_nombre_error').isHidden());
 await page.locator('#modalEditarUsuario [data-bs-dismiss=modal]').first().click();
 response=await ctx.request.post(url('eliminar'),{form:{id:'1'}});assert.ok((await response.text()).includes('No puedes eliminar tu propia cuenta.'));assert.equal(fixture('--count'),'4');
 response=await ctx.request.post(url('eliminar'),{form:{id:'3'}});assert.ok((await response.text()).includes('Usuario desactivado correctamente.'));assert.equal(fixture('--count'),'4','Eliminar sigue desactivando; no borra registros');
 fixture('--last-admin');response=await ctx.request.post(url('cambiarEstado'),{form:{id:'2',estado:'0'}});assert.ok((await response.text()).includes('Debe quedar al menos un administrador activo.'));
 fixture('--fail-insert');response=await ctx.request.post(url('guardar'),{form:{...data,usuario:'fallo_nuevo',correo:'fallo@example.test'}});html=await response.text();assert.ok(html.includes('No se pudo guardar el usuario. Inténtalo de nuevo.'));assert.ok(!html.includes('DETALLE_SQL_PRIVADO_DE_PRUEBA'));assert.ok(!html.includes('Fatal error'));assert.equal(fixture('--count'),'4');
 fixture('--fail-update');response=await ctx.request.post(url('actualizar'),{form:{...edit,id:'4',usuario:'usuario_nuevo',correo:'nuevo@example.test'}});html=await response.text();assert.ok(html.includes('No se pudieron guardar los cambios. Inténtalo de nuevo.'));assert.ok(!html.includes('DETALLE_SQL_PRIVADO_DE_PRUEBA'));
 response=await ctx.request.post(url('eliminar'),{form:{id:'4'}});html=await response.text();assert.ok(html.includes('No fue posible desactivar el usuario.'));assert.ok(!html.includes('DETALLE_SQL_PRIVADO_DE_PRUEBA'));assert.equal(fixture('--count'),'4');
 assert.deepEqual(errors,[]);assert.ok(!/PHP (Warning|Fatal|Parse)|Uncaught/.test(log),log);console.log('PASS: validación cliente/servidor, duplicados, conservación de datos, edición, desactivación, protecciones, fallos SQL sin detalles y diseño escritorio/móvil.');
}finally{if(browser)await browser.close();if(server)server.kill();fixture('--drop');}})().catch(e=>{console.error(e);process.exitCode=1;});
