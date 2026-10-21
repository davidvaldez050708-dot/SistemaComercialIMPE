const assert=require('node:assert/strict');
const {spawn,execFileSync}=require('node:child_process');
const path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php';
const base=process.env.IMPE_BASE_URL||'http://127.0.0.1:8097/';
assert.match(process.env.IMPE_DB_NAME||'',/^impe_test_handly_aliados[a-z0-9_]*$/);
const query=sql=>JSON.parse(execFileSync(php,['-r',`require 'config/db_connection.php'; echo json_encode((new Database())->connect(false)->query(${JSON.stringify(sql)})->fetch_all(MYSQLI_ASSOC));`],{cwd:root,encoding:'utf8'}));
const token=query("SELECT token FROM formulario_aliados WHERE slug='handly-lenguaje-center'")[0].token;
const server=spawn(php,['-S',new URL(base).host,'-t',root],{cwd:root,stdio:['ignore','ignore','pipe']});
let logs='';server.stderr.on('data',d=>logs+=d);
(async()=>{
 let browser,count=0;const check=(ok,msg)=>{assert.ok(ok,msg);count++;console.log('OK '+msg);};
 try{
  for(let i=0;i<80;i++){try{await fetch(base);break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
  const context=await browser.newContext();
  await context.route('https://**/*',r=>r.request().url().includes('bootstrap.min.css')?r.fulfill({path:path.join(process.env.BOOTSTRAP_MODULE_PATH||path.join(root,'../browser-deps/node_modules/bootstrap'),'dist/css/bootstrap.min.css'),contentType:'text/css'}):r.abort());
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  for(const action of ['registro','inscripcion']){
   const url=base+'index.php?controller=formularioPublico&action='+action+'&aliado='+token+(action==='registro'?'&convocatoria_ref='+'a'.repeat(32):'');
   check((await page.goto(url)).status()===200,'enlace internacional '+action);
   check(await page.locator('[name=estado_provincia]').count()===1 && await page.locator('select[name=estado_id]').count()===0,'ubicación libre '+action);
   const data={nombre:'Persona',apellido:'Internacional',fecha_nacimiento:'1990-01-01',movil:'7135550999',movil_confirmacion:'7135550999',correo:action+'-pr114@example.test',correo_confirmacion:action+'-pr114@example.test',perfil_interes:'Bachillerato General',pais_nombre:'Estados Unidos',estado_provincia:'Texas',ciudad:'Houston',csrf_formulario_publico:await page.locator('[name=csrf_formulario_publico]').inputValue()};
   const save=url.replace('action='+action,'action='+ (action==='registro'?'guardarRegistro':'guardarInscripcion'));
   check((await page.request.post(save,{form:{...data,csrf_formulario_publico:''}})).status()===403,'CSRF existente conservado '+action);
   check((await page.request.post(save,{form:{...data,perfil_interes:'Inglés'}})).status()===422,'programa no autorizado '+action);
   check((await page.request.post(save,{form:{...data,estado_id:'1'}})).status()===422,'IDs mexicanos manipulados '+action);
   for(const key of ['nombre','apellido','fecha_nacimiento','movil','movil_confirmacion','correo','correo_confirmacion','pais_nombre','estado_provincia','ciudad'])await page.locator('[name='+key+']').fill(data[key]);
   await page.locator('[name=perfil_interes]').selectOption(data.perfil_interes);
   await page.locator('[data-publico-submit]').click();await page.waitForURL(action==='registro'?/action=gracias(?:&|$)/:/action=graciasInscripcion/);
   const a=await page.request.post(save,{form:data}),b=await page.request.post(save,{form:data});
   check(a.status()===200 && (await a.json()).registro_id===(await b.json()).registro_id,'envío y reenvío internacional '+action);
   const table=action==='registro'?'formulario_registros':'formulario_inscripciones';
   const rows=query(`SELECT r.estado_id,r.municipio_id,r.estado_provincia,r.ciudad,p.convocatoria_id FROM ${table} r JOIN formulario_aliado_procedencias p ON p.tipo='${action}' AND p.registro_id=r.id WHERE correo='${data.correo}'`);
   check(rows.length===1 && rows[0].estado_id===null && rows[0].municipio_id===null && rows[0].estado_provincia==='Texas' && rows[0].ciudad==='Houston','persistencia internacional '+action);
   if(action==='registro')check(Number(rows[0].convocatoria_id)===91,'procedencia convocatoria en Registro');
  }
  check((await page.goto(base+'index.php?controller=formularioPublico&action=registro&aliado='+token+'&convocatoria_ref='+'b'.repeat(32))).status()===404,'convocatoria inválida no disponible');
  check((await page.goto(base+'index.php?controller=formularioPublico&action=inscripcion&aliado='+token+'&convocatoria_ref='+'a'.repeat(32))).status()===404,'enlace de Registro no se convierte en Inscripción');
  check(errors.length===0 && !/PHP (Warning|Fatal|Parse|Deprecated)/.test(logs),'sin errores PHP/JavaScript');
  console.log('PASS '+count+' comprobaciones navegador PR114');
 } finally {if(browser)await browser.close();server.kill();}
})().catch(e=>{console.error(e);console.error(logs.slice(-5000));process.exitCode=1;});
