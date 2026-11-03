// Only fictional fixtures in a disposable local database. No production sessions.
const assert=require('node:assert/strict'),path=require('node:path'),fs=require('node:fs'),{spawn,execFileSync}=require('node:child_process');
 const {chromium}=require('playwright'),root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php';
assert.equal(process.env.APP_ENV,'testing');assert.match(process.env.IMPE_DB_NAME||'',/^impe_test_prospectos_[a-z0-9_]+$/);
const base='http://127.0.0.1:8149/',env={...process.env,IMPE_BASE_URL:base,IMPE_CENTRAL_BROWSER:'1'},output=path.join(root,'docs/prospectos_central_capturas');fs.mkdirSync(output,{recursive:true});
const fixture=(op)=>execFileSync(php,[path.join(__dirname,'prospectos_central_fixture.php'),op],{env,cwd:root,encoding:'utf8'});
let server,log='',browser;
(async()=>{try {
 execFileSync(php,[path.join(__dirname,'prospectos_central_db.php')],{cwd:root,env:{...env,IMPE_CENTRAL_KEEP_FIXTURE:'1'},stdio:'inherit'});fixture('--seed');
 // Revisión de referencias antiguas, exclusivamente en base de datos ficticia.
 execFileSync(php,['-r',`require 'config/db_connection.php';$d=(new Database())->connect(false);$d->query("UPDATE prospecto_integraciones SET referencia_estable='impe:prospecto:13' WHERE prospecto_id=13 AND sistema='ZOHO'");$d->query("UPDATE prospecto_integraciones SET referencia_estable='impe:prospecto:14',estado='CONFIGURADO',externo_id='fixture-14' WHERE prospecto_id=14 AND sistema='ZOHO'");`],{cwd:root,env});
 const migrador=(modo)=>JSON.parse(execFileSync(php,['tools/prospectos_referencias_neutrales.php','--site',root,modo],{cwd:root,env,encoding:'utf8'}));
 const antesMigracion=fixture('--snapshot'),previoMigracion=migrador('--check');
 assert.equal(previoMigracion.candidatas,1);assert.equal(previoMigracion.actualizadas,0);
 assert.equal(fixture('--snapshot'),antesMigracion,'Comprobación de referencias sin escrituras');
 const corregido=migrador('--apply');
 assert.equal(corregido.actualizadas,1);assert.equal(corregido.referencias_historicas_despues,1,'Configurados se respetan');
 assert.equal(migrador('--apply').actualizadas,0,'Conversión de referencia idempotente');
 const referencias=JSON.parse(execFileSync(php,['-r',`require 'config/db_connection.php';$d=(new Database())->connect(false);echo json_encode($d->query("SELECT prospecto_id,referencia_estable FROM prospecto_integraciones WHERE prospecto_id IN (13,14) ORDER BY prospecto_id")->fetch_all(MYSQLI_ASSOC));`],{cwd:root,env,encoding:'utf8'}));
 assert.equal(referencias[0].referencia_estable,'CRM-PROS-00013');assert.equal(referencias[1].referencia_estable,'impe:prospecto:14');
 console.log('PASS referencias neutrales: vista previa, conversión idempotente y protección de vínculos configurados.');
 const before=fixture('--snapshot');
 server=spawn(php,['-S','127.0.0.1:8149','-t',root,path.join(__dirname,'prospectos_central_router.php')],{cwd:root,env,stdio:['ignore','ignore','pipe']});server.stderr.on('data',b=>log+=b);
 for (let n=0;n<80;n++) {try{await fetch(base+'_session');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});const context=await browser.newContext({viewport:{width:1440,height:1050}}),page=await context.newPage(),errors=[];page.setDefaultTimeout(15000);page.setDefaultNavigationTimeout(15000);page.on('response',r=>{if(r.url().includes('controller=prospecto'))console.log('HTTP',r.status(),r.url());});page.on('pageerror',e=>errors.push(e.message));
 const deps=process.env.IMPE_BROWSER_DEPS||path.resolve(root,'../browser-deps/node_modules');
 await context.route('https://**/*',async route=>{const u=route.request().url();let file,type;
  if(u.includes('bootstrap-icons')&&u.includes('.css')){file=path.join(deps,'bootstrap-icons/font/bootstrap-icons.min.css');type='text/css';}
  else if(u.includes('bootstrap-icons')&&/\.woff2?(\?|$)/.test(u)){file=path.join(deps,'bootstrap-icons/font/fonts/bootstrap-icons.woff2');type='font/woff2';}
  else if(u.includes('bootstrap@')&&u.includes('/css/')){file=path.join(deps,'bootstrap/dist/css/bootstrap.min.css');type='text/css';}
  else if(u.includes('bootstrap@')&&u.includes('/js/')){file=path.join(deps,'bootstrap/dist/js/bootstrap.bundle.min.js');type='application/javascript';}
  else if(u.includes('fonts.googleapis.com')){await route.fulfill({body:fs.readFileSync(path.join(deps,'@fontsource/manrope/latin.css'),'utf8').replaceAll('./files/',base+'fixture-font/'),contentType:'text/css'});return;}
  if(file&&fs.existsSync(file))await route.fulfill({path:file,contentType:type});else await route.abort();
 });
 await context.route(base+'fixture-font/**',r=>r.fulfill({path:path.join(deps,'@fontsource/manrope/files',new URL(r.request().url()).pathname.split('/').pop()),contentType:'font/woff2'}));
 const home=base+'index.php?controller=prospecto&action=index';await context.request.get(base+'_session?rol=marketing');
 await page.goto(home);await page.locator('.central-table').waitFor();assert.equal(await page.locator('.central-hero').count(),0,'Cabecera redundante eliminada en Todos');assert.equal(await page.locator('.central-table thead th').count(),8);assert.equal(await page.locator('.central-table tbody tr').count(),25);
 await page.screenshot({path:path.join(output,'todos-escritorio.png'),fullPage:true});
 await page.locator('[data-central-results] a[rel=next]').click();await page.waitForURL(/pagina=2/,{timeout:15000});console.log('OK paginación');assert.ok((await page.locator('.central-table tbody tr').count())>0&&(await page.locator('.central-table tbody tr').count())<=25);
 await page.locator('#centralBuscar').fill('Prospecto ficticio 35');await page.waitForFunction(()=>document.querySelector('[data-central-results]').textContent.includes('1 prospectos'));assert.equal(await page.locator('.central-table tbody tr').count(),1);assert.ok((await page.locator('.central-table').innerText()).includes('ficticio 35'));
 const listadoAntes=page.url(),ojo=page.locator('.central-table .central-link').first();await ojo.click();await page.locator('[data-prospecto-ficha-panel] #informacion-formulario').waitFor();assert.equal(page.url(),listadoAntes,'El ojo abre el panel sin navegar');assert.equal(await page.locator('[data-prospecto-ficha-panel]').getAttribute('aria-modal'),'true');assert.ok((await page.locator('[data-prospecto-ficha-panel] #informacion-formulario').innerText()).includes('Empresa ficticia'));assert.ok((await page.locator('[data-prospecto-ficha-panel]').innerText()).includes('2026-11-02 09:00:00'));assert.ok(!(await page.locator('[data-prospecto-ficha-panel]').innerText()).toLowerCase().includes('impe'));assert.ok((await page.locator('[data-prospecto-ficha-panel]').innerText()).includes('CRM-PROS-'));await page.screenshot({path:path.join(output,'ficha-panel-escritorio.png'),fullPage:false});await page.keyboard.press('Escape');assert.equal(await page.locator('[data-prospecto-ficha-capa]').isHidden(),true);assert.equal(await ojo.evaluate(el=>el===document.activeElement),true,'Foco vuelve al ojo');
 await page.goto(base+'index.php?controller=prospecto&action=detalle&id=1');assert.equal(await page.locator('.prospectos-central img').count(),0);assert.ok((await page.locator('h2').innerText()).includes('<img'));
 await page.goto(home+'&buscar=aliada%40example.test');assert.equal(await page.locator('[data-prospectos-modulo]').count(),1,'Enlace antiguo de búsqueda conserva el listado original');
 await page.goto(home+'&seccion=aliados');assert.equal(await page.locator('[data-prospectos-state]').count(),32);assert.equal(await page.locator('.central-hero').count(),0,'Aliados conserva cabecera compacta');assert.equal(await page.locator('.prospectos-view.is-active').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(237, 242, 255)','Selector territorial azul');await page.locator('[data-prospectos-state-search]').fill('Guanajuato');assert.equal(await page.locator('[data-prospectos-state]:visible').count(),1);await page.locator('[data-prospectos-state]:visible').click();await page.locator('[data-prospectos-resultados]').waitFor();assert.equal(await page.locator('.prospectos-table-wrap thead th').count(),8);assert.ok(await page.locator('#prospectoAliado option').count()>2);await page.screenshot({path:path.join(output,'aliados-escritorio.png'),fullPage:true});
 await page.selectOption('#prospectoAliado','1');await page.waitForURL(/aliado_id=1/);await page.selectOption('#prospectoPrograma',{index:1});await page.waitForURL(/programa=.+/);assert.ok((await page.locator('[data-prospectos-resultados]').innerText()).includes('Persona ficticia aliada'));await page.locator('[data-prospectos-resultados] [data-prospecto-ficha]').first().click();await page.locator('[data-prospecto-ficha-contenido]').waitFor();assert.ok((await page.locator('[data-prospecto-ficha-panel]').innerText()).includes('Persona ficticia aliada'));await page.locator('.prospecto-ficha-cerrar').click();assert.equal(await page.locator('[data-prospecto-ficha-capa]').isHidden(),true);
 await page.goto(home+'&seccion=meta');assert.equal(await page.locator('.central-hero').count(),0,'Meta mantiene cabecera compacta');assert.equal(await page.locator('.central-table tbody tr').count(),1);assert.equal(await page.locator('[data-meta-abrir]').count(),1,'Marketing puede importar Meta Ads');
 await page.goto(home+'&seccion=asignaciones');assert.ok((await page.locator('.prospectos-central').innerText()).includes('Aún no hay asignaciones'));
 await page.setViewportSize({width:390,height:844});await page.goto(home);await page.screenshot({path:path.join(output,'todos-movil.png'),fullPage:true});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await page.locator('.central-table .central-link').first().click();await page.locator('[data-prospecto-ficha-panel] #informacion-formulario').waitFor();await page.screenshot({path:path.join(output,'ficha-movil.png'),fullPage:false});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await page.keyboard.press('Escape');
 for(const role of ['admin','marketing','director','lector','asesor_autorizado']) {await context.request.get(base+'_session?rol='+role);assert.equal((await page.goto(home)).status(),200);}
 for(const role of ['asesor','sin_permiso']) {await context.request.get(base+'_session?rol='+role);for(const url of [home,base+'index.php?controller=prospecto&action=detalle&id=1',base+'index.php?controller=prospecto&action=detalleRegistro&registro_id=1']) {assert.equal((await page.goto(url)).status(),403);assert.equal((await context.request.get(url+'&panel=1',{headers:{'X-Requested-With':'XMLHttpRequest'}})).status(),403);}}
 assert.equal(fixture('--snapshot'),before,'Navegación GET no modifica datos');
 await context.request.get(base+'_session?rol=marketing');
 await page.goto(base+'index.php?controller=formularioPublico&action=registro');const csrf=await page.locator('input[name=csrf_formulario_publico]').inputValue();assert.equal(csrf.length,64);
 const form={nombre:'Persona Ficticia',apellido:'HTTP Prueba',fecha_nacimiento:'1990-01-01',movil:'5500000011',movil_confirmacion:'5500000011',correo:'publico-http@example.test',correo_confirmacion:'publico-http@example.test',perfil_interes:'Bachillerato General',lugar_laboras:'Empresa ficticia HTTP',cargo_puesto:'Cargo ficticio',estado_id:'1',municipio_id:'1',contacto_disponibilidad:'sin_preferencia'};
 const publicUrl=base+'index.php?controller=formularioPublico&action=guardarRegistro';
 assert.equal((await context.request.post(publicUrl,{form})).status(),403,'CSRF original continúa obligatorio');
 let response=await context.request.post(publicUrl,{form:{...form,csrf_formulario_publico:csrf}});assert.equal(response.status(),200);assert.ok((await response.json()).ok,'Registro público original');
 response=await context.request.post(base+'index.php?controller=formulario&action=guardarRegistro',{form:{...form,correo:'interno-http@example.test',correo_confirmacion:'interno-http@example.test'}});assert.equal(response.status(),200);assert.ok((await response.json()).ok,'Registro interno original');
 const allyForm={...form,correo:'aliado-http@example.test',correo_confirmacion:'aliado-http@example.test',perfil_interes:'Programa A (prueba)',csrf_formulario_publico:csrf};
 const allyUrl=publicUrl+'&aliado='+('a'.repeat(64));response=await context.request.post(allyUrl,{form:allyForm});assert.equal(response.status(),200);const allySaved=await response.json();assert.ok(allySaved.ok,JSON.stringify(allySaved));
 const repeat=await (await context.request.post(allyUrl,{form:allyForm})).json();assert.equal(repeat.registro_id,allySaved.registro_id,'Reenvío aliado no duplica original');
 response=await context.request.post(base+'index.php?controller=formularioPublico&action=guardarInscripcion',{form:{...form,correo:'inscripcion-http@example.test',correo_confirmacion:'inscripcion-http@example.test',csrf_formulario_publico:csrf}});assert.equal(response.status(),200);assert.ok((await response.json()).ok,'Inscripción original');
 const internationalContext='&aliado='+('b'.repeat(64))+'&convocatoria_ref='+('c'.repeat(32));
 await page.goto(base+'index.php?controller=formularioPublico&action=registro'+internationalContext);
 assert.equal(await page.locator('[data-ubicacion-internacional]').count(),3);assert.equal(await page.locator('[data-publico-estado]').count(),0);
 response=await context.request.post(publicUrl+internationalContext,{form:{...form,perfil_interes:'Programa A (prueba)',correo:'internacional-http@example.test',correo_confirmacion:'internacional-http@example.test',estado_id:'',municipio_id:'',pais_nombre:'País ficticio',estado_provincia:'Provincia ficticia',ciudad:'Ciudad ficticia',csrf_formulario_publico:csrf}});assert.equal(response.status(),200);assert.ok((await response.json()).ok,'Registro internacional por convocatoria');
 console.log('PASS POST con controladores reales: CSRF, público, interno, aliado repetido, inscripción e internacional por convocatoria.');

 // Importación Meta real: autorización, CSRF, vista previa sin escritura y doble carga idempotente.
 await page.goto(home+'&seccion=meta');
 await context.request.get(base+'_session?rol=admin');
 await page.reload();
 assert.equal(await page.locator('[data-meta-abrir]').count(),0,'Administrador mantiene rol de supervisión, sin importador');
 assert.equal((await context.request.post(base+'index.php?controller=prospecto&action=metaConfirmar',{form:{csrf_meta:'no'}})).status(),403);
 await context.request.get(base+'_session?rol=director');
 await page.reload();
 assert.equal(await page.locator('[data-meta-abrir]').count(),1,'Director autorizado desde Roles y permisos puede importar');
 assert.equal((await context.request.post(base+'index.php?controller=prospecto&action=metaConfirmar',{form:{csrf_meta:'no'}})).status(),422,'Director autorizado pasa el guard y valida CSRF');
 await context.request.get(base+'_session?rol=lector');
 await page.reload();
 assert.equal(await page.locator('[data-meta-abrir]').count(),0,'Solo lectura no habilita Importar');
 assert.equal((await context.request.post(base+'index.php?controller=prospecto&action=metaConfirmar',{form:{csrf_meta:'no'}})).status(),403,'POST sin permiso denegado');
 for(const action of ['metaPrevisualizar','metaCancelar'])assert.equal((await context.request.post(base+'index.php?controller=prospecto&action='+action,{form:{csrf_meta:'no'}})).status(),403,'Endpoint directo sin permiso: '+action);
 await context.request.get(base+'_session?rol=marketing');
 await page.reload();
 const beforeMeta=fixture('--snapshot');
 const bad=(await context.request.post(base+'index.php?controller=prospecto&action=metaConfirmar',{form:{csrf_meta:'no'}}));
 assert.equal(bad.status(),422,'CSRF de importación obligatorio');
 assert.equal(fixture('--snapshot'),beforeMeta,'CSRF incorrecto no escribe BD');
 const cols=['id','created_time','ad_id','ad_name','adset_id','adset_name','campaign_id','campaign_name','form_id','form_name','is_organic','platform','full_name','phone_number','estado','retailer_item_id'];
 const record=(id,nombre,telefono)=>['l:'+id,'2026-11-01T17:59:45-06:00','ag:12','Anuncio', 'as:12','Conjunto','c:900','Campaña ficticia','f:900','Formulario ficticio','false','fb',nombre,'p:'+telefono,'Morelos',''];
 const metaData=[cols,record('9988776655','Importación Ficticia Uno','+525511223344'),record('9988776656','Importación Ficticia Dos','+525544332211'),record('9988776655','Importación Ficticia Uno','+525511223344')].map(row=>row.join('\t')).join('\n');
 const metaBytes=Buffer.concat([Buffer.from([0xff,0xfe]),Buffer.from(metaData,'utf16le')]);
 const uploadMeta=async()=> {
   await page.goto(home+'&seccion=meta');
   await page.locator('[data-meta-abrir]').click();
   await page.locator('input[name=archivo_meta]').setInputFiles({name:'Meta_ficticio.csv',mimeType:'text/csv',buffer:metaBytes});
   await page.locator('.meta-import-upload button[type=submit]').click();
   await page.locator('[data-meta-confirmacion]').waitFor();
   assert.equal(await page.locator('.meta-import-kpis strong').nth(1).innerText(),'2','Dos leads únicos');
   assert.equal(await page.locator('.meta-import-kpis strong').nth(3).innerText(),'1','Duplicado dentro del archivo');
 };
 await uploadMeta();
 assert.equal(fixture('--snapshot'),beforeMeta,'La previsualización de Meta no escribe BD');
 await page.selectOption('[data-meta-formulario]',{index:1});
 await page.locator('[data-meta-confirmar]').click();
 await page.locator('.meta-import-result').waitFor();
 assert.ok((await page.locator('.meta-import-result').innerText()).includes('2 prospectos nuevos'));
 assert.equal(await page.locator('.central-table tbody tr').count(),3,'Meta conserva también la procedencia preexistente');
 await page.screenshot({path:path.join(output,'meta-importacion-completada.png'),fullPage:true});
 // Formato histórico: sólo identificador_origen y sistema META, sin meta_lead_id.
 execFileSync(php,['-r','require "config/db_connection.php";$d=(new Database())->connect(false);$d->query("UPDATE prospecto_origenes SET meta_lead_id=NULL,sistema_origen=\'META\' WHERE meta_lead_id=\'9988776655\'");'],{cwd:root,env});
 await uploadMeta();
 assert.equal(await page.locator('.meta-import-kpis strong').nth(2).innerText(),'2','Vista previa detecta 2 IDs existentes');
 await page.locator('[data-meta-confirmar]').click();
 await page.locator('.meta-import-result').waitFor();
 assert.ok((await page.locator('.meta-import-result').innerText()).includes('0 prospectos nuevos'),'Segunda carga no duplica');
 const sqlCheck='require "config/db_connection.php";$d=(new Database())->connect(false);echo json_encode(["meta"=>(int)$d->query("SELECT COUNT(*) n FROM prospecto_origenes WHERE tipo_origen=\'META_ADS\'")->fetch_assoc()["n"],"asignaciones"=>(int)$d->query("SELECT COUNT(*) n FROM prospecto_asignaciones")->fetch_assoc()["n"]]);';
 const estadoMeta=JSON.parse(execFileSync(php,['-r',sqlCheck],{cwd:root,env,encoding:'utf8'}));
 assert.equal(estadoMeta.meta,4,'Se conservan las dos procedencias Meta históricas y se agregan dos leads nuevos');assert.equal(estadoMeta.asignaciones,0,'Importador no asigna asesores');
 console.log('PASS Meta Ads: permisos, CSRF, UTF-16, vista previa, campaña, importación idempotente, sin asignaciones.');

 // Clasificación por formulario y excepciones: datos ficticios, tres lotes y fallo recuperable.
 const sql=(code)=>execFileSync(php,['-r','require "config/db_connection.php";$d=(new Database())->connect(false);'+code],{cwd:root,env,encoding:'utf8'});
 const many=Array.from({length:151},(_,i)=>{
   const r=record(String(900000000+i),'Lote Ficticio '+i,'+525500000001');
   r[0]=(i===0?'\ufeff':'')+r[0];r[6]='c:batch'+i%3;
   r[7]=['GENERAL Ciudad ficticia','PRUEBA BACHILLERATO','Pendiente ficticio'][i%3];
   r[8]='f:batch'+i%2;r[9]=i%2?'Formulario alterno':'Titulacion Exp';return r;
 });
 const manyBytes=Buffer.concat([Buffer.from([0xff,0xfe]),Buffer.from([cols,...many].map(r=>r.join('\t')).join('\n'),'utf16le')]);
 const beforeBatch=fixture('--snapshot');
 await page.goto(home+'&seccion=meta');await page.locator('[data-meta-abrir]').click();
 await page.locator('[data-meta-file]').setInputFiles({name:'Meta_lotes_ficticios',mimeType:'text/plain',buffer:manyBytes});
 await page.locator('.meta-import-upload button[type=submit]').click();await page.locator('[data-meta-confirmacion]').waitFor();
 assert.equal(fixture('--snapshot'),beforeBatch,'Vista previa multiformulario sin escrituras');
 assert.equal(await page.locator('[data-meta-formulario]').count(),2);assert.equal(await page.locator('[data-meta-campania]').count(),3);
 assert.equal(await page.locator('.meta-import-kpis strong').nth(1).innerText(),'151','Primer ID con BOM importable');
 const forms=page.locator('[data-meta-formulario]');await forms.nth(0).selectOption('programa-a');await forms.nth(1).selectOption('programa-b');
 await page.locator('.meta-import-exceptions summary').click();
 const campaigns=page.locator('[data-meta-campania]');
 assert.ok((await campaigns.nth(0).innerText()).includes('Programa A (prueba)'));
 await campaigns.nth(1).locator('[data-meta-excepcion]').check();await campaigns.nth(1).locator('select').selectOption('programa-b');
 await campaigns.nth(2).locator('[data-meta-excepcion]').check();await campaigns.nth(2).locator('select').selectOption('');
 assert.ok((await page.locator('.meta-import-warning').last().innerText()).includes('Verifica su clasificación'));
 await page.setViewportSize({width:375,height:812});
 assert.ok(await page.locator('.meta-import-dialog').evaluate(el=>el.getBoundingClientRect().width<=375));
 assert.ok(await page.locator('.meta-import-body').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Modal móvil sin desborde horizontal');
 await page.keyboard.press('Escape');assert.ok(await page.locator('[data-meta-overlay]').isHidden());await page.locator('[data-meta-abrir]').click();
 await page.setViewportSize({width:1440,height:1050});
 await page.locator('.meta-import-body').evaluate(el=>el.scrollTop=0);
 await page.screenshot({path:path.join(output,'meta-formularios-escritorio.png'),fullPage:true});
 const payload=await page.locator('[data-meta-confirmacion]').evaluate(el=>Object.fromEntries(new FormData(el)));
 const endpoint=base+'index.php?controller=prospecto&action=metaConfirmar';
 response=await context.request.post(endpoint,{form:{...payload,'programa_formulario[desconocido]':'programa-a'}});
 assert.equal(response.status(),422);assert.equal(fixture('--snapshot'),beforeBatch,'Mapa manipulado no escribe');
 response=await context.request.post(endpoint,{form:payload});let batch=await response.json();assert.equal(response.status(),200);assert.equal(batch.avance,75);assert.equal(batch.terminado,false);
 sql(`$d->query("CREATE TRIGGER meta_fallo BEFORE INSERT ON prospectos_comerciales FOR EACH ROW BEGIN IF NEW.nombre_completo='Lote Ficticio 80' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo ficticio Meta'; END IF; END");`);
 const beforeFailure=fixture('--snapshot');response=await context.request.post(endpoint,{form:payload});assert.equal(response.status(),503);
 assert.equal(fixture('--snapshot'),beforeFailure,'Lote fallido revierte todas sus filas');
 sql('$d->query("DROP TRIGGER meta_fallo");');
 await page.reload();assert.equal(await forms.nth(0).inputValue(),'programa-a');assert.ok(await forms.nth(0).isDisabled(),'Reanudación conserva clasificación bloqueada');
 // Aunque se altere el POST al reanudar, se conserva el mapa ya confirmado.
 response=await context.request.post(endpoint,{form:{...payload,[Object.keys(payload).find(k=>k.startsWith('programa_formulario['))]:'programa-b'}});
 batch=await response.json();assert.equal(batch.avance,150);assert.equal(batch.terminado,false);
 await page.reload();await page.locator('[data-meta-confirmar]').click();await page.locator('.meta-import-result').waitFor();
 assert.ok((await page.locator('.meta-import-result').innerText()).includes('151 prospectos nuevos'));
 const saved=JSON.parse(sql(`echo json_encode($d->query("SELECT o.identificador_origen,o.datos_originales_ref,o.formulario_id,o.campania_id,o.conjunto_id,o.anuncio_id,o.plataforma,o.fecha_registro_original,o.fecha_registro_texto,i.programa_codigo FROM prospecto_origenes o LEFT JOIN prospecto_intereses i ON i.origen_id=o.id WHERE o.meta_lead_id BETWEEN '900000000' AND '900000150' ORDER BY o.meta_lead_id")->fetch_all(MYSQLI_ASSOC));`));
 assert.equal(saved.length,151);saved.forEach((r,i)=>{
   const expected=i%3===2?null:(i%3===1?'programa-b':(i%2?'programa-b':'programa-a'));
   assert.equal(r.programa_codigo,expected,'Prioridad servidor en fila '+i);assert.equal(r.formulario_id,'f:batch'+i%2);assert.equal(r.campania_id,'c:batch'+i%3);
   assert.equal(r.fecha_registro_original,'2026-11-01 23:59:45');assert.equal(r.fecha_registro_texto,'2026-11-01T17:59:45-06:00');assert.equal(r.plataforma,'fb');
   assert.equal(r.conjunto_id,'as:12');assert.equal(r.anuncio_id,'ag:12');assert.equal(r.datos_originales_ref,'meta:lead:l:'+(900000000+i));
 });
 assert.equal(Number(JSON.parse(sql('echo json_encode($d->query("SELECT COUNT(*) n FROM prospecto_asignaciones")->fetch_assoc());')).n),0);
 console.log('PASS formularios, excepciones, pendiente explícito, BOM, móvil/teclado, mapa inválido, 151 filas/3 lotes, rollback, reanudación congelada, metadatos y fechas.');

 assert.deepEqual(errors,[]);assert.ok(!/PHP (Warning|Fatal|Parse)|Uncaught/.test(log),log);
 console.log('PASS interfaz real: 8 columnas, búsqueda automática, paginación, 32 estados, filtros, ficha, XSS, fechas, desktop/móvil, permisos y GET sin escrituras.');
}finally {if(browser)await browser.close();if(server)server.kill();try{fixture('--drop');}catch{}if(/PHP (Warning|Fatal|Parse)/.test(log))console.error(log);}})().catch(e=>{console.error(e);process.exitCode=1;});
