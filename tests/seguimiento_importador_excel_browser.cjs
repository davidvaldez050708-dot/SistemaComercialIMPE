/* Formulario y estilos reales. El transporte simula el contrato del controlador;
 * verifica las partes multipart exactas, sin importar instituciones en una base. */
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium,webkit}=require('playwright'),root=path.resolve(__dirname,'..');
const bootstrap=path.dirname(require.resolve('bootstrap/package.json')),icons=path.dirname(require.resolve('bootstrap-icons/package.json'));
const php=(...args)=>cp.execFileSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'fixtures/seguimiento_excel_importador.php'),...args]);
const xlsx=php('xlsx'),baseline=process.env.IMPE_EXCEL_BASELINE?cp.execFileSync('git',['show','3771d58a:public/javascript/seguimiento_importacion_excel.js'],{cwd:root}):null;
(async()=>{const browser=await(process.env.SALES_BROWSER==='webkit'?webkit:chromium).launch(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{});
try{
 const page=await browser.newPage(),errors=[],sent=[];page.on('pageerror',e=>errors.push(e.message));
 let mode='analista',analyses=0,commits=0,fault='',zero=false,missingToken=false,previewToken='';
 await page.route('**/*',async route=>{
  const req=route.request(),url=new URL(req.url());if(url.hostname!=='excel.test')return route.abort();
  const action=url.searchParams.get('action');
  if(action==='analistasImportacionExcel')return route.fulfill({json:{ok:true,analistas:[{id:url.searchParams.get('estado_id')==='3'?501:502,nombre:'Analista de prueba'}]}});
  if(action==='importarInstitucionesExcel'){
   const data=await new Response(req.postDataBuffer(),{headers:{'Content-Type':req.headers()['content-type']}}).formData();
   assert.equal(data.get('csrf_token'),'fixture-excel-csrf');
   assert.equal(req.headers()['x-requested-with'],baseline?undefined:'fetch');
   if(data.get('operacion')==='analizar'){
    analyses++;const file=data.get('archivo');sent.push({estado:data.get('estado_id'),analista:data.get('analista_id'),file});
    if(!data.get('estado_id'))return route.fulfill({status:422,json:{ok:false,mensaje:'Selecciona un territorio asignado.'}});
    if(data.get('estado_id')!=='3')return route.fulfill({status:403,json:{ok:false,mensaje:'No tienes acceso a ese territorio.'}});
    assert(file&&file.name==='plantilla_v3.xlsx');assert.deepEqual(Buffer.from(await file.arrayBuffer()),xlsx,'El archivo se envía completo y sin alteraciones');
    if(mode!=='analista')assert.equal(data.get('analista_id'),'501');
    await new Promise(r=>setTimeout(r,120));previewToken=missingToken?'':'a'.repeat(39)+String(analyses%10);
    return route.fulfill({json:{ok:true,token:previewToken,resumen:{nuevas:zero?0:1,existentes:1,revisar:1,invalidas:1},filas:[
     {fila:2,institucion:'Institución <img src=x onerror=alert(1)>',estado:zero?'existente':'nueva',mensaje:'Revisada'},
     {fila:3,institucion:'Existente',estado:'existente',mensaje:'No se modifica'},
     {fila:4,institucion:'Ambigua',estado:'revisar',mensaje:'Revisar'},
     {fila:5,institucion:'Incompleta',estado:'invalida',mensaje:'Falta correo'}]}});
   }
   assert.equal(data.get('operacion'),'confirmar');assert.equal(data.get('token'),previewToken);
   assert.equal(data.get('estado_id'),null);assert.equal(data.get('archivo'),null,'La confirmación usa la revisión guardada en el servidor');previewToken='';
   if(fault)return route.fulfill({status:Number(fault),json:{ok:false,mensaje:fault==='409'?'La revisión caducó. Analiza el Excel nuevamente.':'No se pudo completar la operación.'}});
   commits++;await new Promise(r=>setTimeout(r,100));return route.fulfill({json:{ok:true,resultado:{insertadas:1,omitidas_adicionales:0}}});
  }
  if(url.pathname==='/bootstrap.css')return route.fulfill({path:path.join(bootstrap,'dist/css/bootstrap.min.css')});
  if(url.pathname==='/bootstrap.js')return route.fulfill({path:path.join(bootstrap,'dist/js/bootstrap.bundle.min.js')});
  if(url.pathname.startsWith('/icons/'))return route.fulfill({path:path.join(icons,'font',url.pathname.slice(7))});
  if(baseline&&url.pathname==='/public/javascript/seguimiento_importacion_excel.js')return route.fulfill({contentType:'application/javascript',body:baseline});
  if(url.pathname.startsWith('/public/')){const file=path.join(root,url.pathname);return fs.existsSync(file)?route.fulfill({path:file}):route.abort();}
  return route.fulfill({contentType:'text/html',body:'<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/bootstrap.css"><link rel="stylesheet" href="/icons/bootstrap-icons.css"><link rel="stylesheet" href="/public/css/global.css"><link rel="stylesheet" href="/public/css/dashboard.css"><script src="/bootstrap.js"></script><body class="admin-content">'+php(mode).toString('utf8')+'</body></html>'});
 });
 const modal=page.locator('#modalImportacionSeguimientoExcel'),message=modal.locator('[data-impe-excel-message]'),analyze=modal.locator('[data-impe-excel-analyze]'),confirm=modal.locator('[data-impe-excel-confirm]');
 const prepare=async()=>{
  await modal.locator('[name=estado_id]').selectOption('3');
  if(mode!=='analista'){await modal.locator('[name=analista_id] option[value="501"]').waitFor({state:'attached'});await modal.locator('[name=analista_id]').selectOption('501');}
  await modal.locator('[name=archivo]').setInputFiles({name:'plantilla_v3.xlsx',mimeType:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',buffer:xlsx});
 };
 const review=async()=>{await analyze.click();await message.filter({hasText:'Revisión completada'}).waitFor();};
 for(const [area,width]of [['analista',1366],['supervisor',390],['administrador',1366]]){
  mode=area;await page.setViewportSize({width,height:900});await page.goto('http://excel.test/index.php?controller=seguimientoVinculacion&action=index');
  await page.locator('[data-bs-target="#modalImportacionSeguimientoExcel"]').click();await modal.waitFor({state:'visible'});await prepare();
  if(baseline){await analyze.click();await message.filter({hasText:'Selecciona un territorio asignado.'}).waitFor();assert.equal(sent[0].estado,null);assert.equal(sent[0].file,null);console.log('REPRODUCIDO: la versión instalada omite territorio y archivo al deshabilitarlos antes de FormData.');return;}
  const count=analyses;await analyze.click();await analyze.evaluate(el=>el.click());await message.filter({hasText:'Revisión completada'}).waitFor();assert.equal(analyses,count+1,'Sin doble análisis');
  assert.equal(commits,area==='analista'?0:area==='supervisor'?1:2,'Analizar no confirma registros');
  assert.equal(await modal.locator('[data-impe-excel-rows] tr').count(),4);assert.equal(await modal.locator('[data-impe-excel-rows] img').count(),0);
  assert(await confirm.isVisible());assert(await confirm.isEnabled());assert.equal(await modal.locator('[name=estado_id]').inputValue(),'3');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);
  if(process.env.SALES_SCREENSHOT_DIR){fs.mkdirSync(process.env.SALES_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.SALES_SCREENSHOT_DIR,'excel_'+area+'_'+width+'.png')});}
  await confirm.click();await message.filter({hasText:'Importación finalizada: 1'}).waitFor();assert(await confirm.isHidden());assert(await analyze.isEnabled());assert.equal(await modal.locator('[name=archivo]').inputValue(),'');
 }
 await prepare();zero=true;await review();assert(await confirm.isHidden());assert(await confirm.isDisabled());zero=false;
 for(const code of ['409','500']){
  fault=code;await review();const previous=commits;await confirm.click();await message.filter({hasText:code==='409'?'La revisión caducó':'Revisa los seguimientos'}).waitFor();
  assert(await confirm.isHidden());assert(await confirm.isDisabled());assert(await modal.locator('[data-impe-excel-preview]').isHidden());assert.equal(commits,previous);assert(await analyze.isEnabled());
  assert(await modal.locator('[name=archivo]').inputValue());
 }
 fault='';missingToken=true;await analyze.click();await message.filter({hasText:'confirmación válida'}).waitFor();assert(await confirm.isHidden());missingToken=false;
 // Cambiar cualquier dato invalida la revisión para no importar otro alcance.
 await review();await modal.locator('[name=archivo]').setInputFiles([]);assert(await confirm.isHidden());assert(await confirm.isDisabled());
 const count=analyses;await analyze.click();await message.filter({hasText:'Selecciona el archivo'}).waitFor();assert.equal(analyses,count);
 await modal.locator('[name=archivo]').setInputFiles({name:'grande.xlsx',mimeType:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',buffer:Buffer.alloc(2097153)});await analyze.click();await message.filter({hasText:'2 MB'}).waitFor();assert.equal(analyses,count);
 await prepare();await review();await modal.locator('[name=estado_id]').selectOption('17');assert(await confirm.isHidden());
 await modal.locator('[name=analista_id] option[value="502"]').waitFor({state:'attached'});await modal.locator('[name=analista_id]').selectOption('502');await analyze.click();await message.filter({hasText:'No tienes acceso a ese territorio.'}).waitFor();assert(await confirm.isHidden());
 assert.deepEqual(errors,[]);console.log('Importador: XLSX/estado/analista/CSRF completos, análisis sin confirmar, controles recuperables, revisión caducada, errores, límites y diseño analista/equipo/global OK');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
