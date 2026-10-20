/* Pruebas visuales sobre controladores y BD reales de la fixture aislada. */
const assert=require('node:assert/strict');
const fs=require('node:fs');const path=require('node:path');const {execFileSync}=require('node:child_process');
module.exports=async function verify({page,base,name,id,php,env,root}){
 assert.match(env.IMPE_DB_NAME,/^impe_test_handly[a-z0-9_]*$/);
 if(process.env.IMPE_TEST_OUTPUT){await page.goto(base+'__rol?rol=marketing');for(const width of [1366,390]){await page.setViewportSize({width,height:900});await page.goto(base+'__before');await page.evaluate(()=>document.fonts.ready);await page.screenshot({path:path.join(process.env.IMPE_TEST_OUTPUT,`internacionales_antes_${width}.png`),fullPage:true});}}

 const seed=`require 'config/db_connection.php';require 'app/services/AliadoInternacionalService.php';$db=(new Database())->connect(false);$s=new AliadoInternacionalService($db);$db->query("INSERT IGNORE INTO formulario_programas VALUES ('ingles','Inglés',1)");$p=array_column($db->query('SELECT codigo FROM formulario_programas WHERE activo=1')->fetch_all(MYSQLI_ASSOC),'codigo');for($i=0;$i<9;$i++){$s->guardar(['nombre'=>'Universidad internacional de prueba '.date('His').' '.$i,'pais_codigo'=>'CA','region'=>'Ontario','ciudad'=>'Toronto','contacto_nombre'=>'Coordinación de programas internacionales','contacto_telefono'=>'+1 416 555 0100','programas'=>$p],2);}`;
 execFileSync(php,['-r',seed],{cwd:root,env});
 // Cuenta Clave con consulta y sin asignaciones nacionales: acceso, sin elevar operaciones.
 await page.goto(base+'__rol?rol=cuenta');
 assert.equal(await page.getByRole('button',{name:'Registrar institución'}).count(),0);
 assert.equal(await page.locator('a[href*=crear_convocatoria]').count(),0);
 assert.equal((await page.request.post(base+'index.php?controller=aliado&action=guardarInternacional',{form:{nombre:'NO PERMITIDO'}})).status(),403);
 assert.equal((await page.request.post(base+'index.php?controller=aliado&action=convenioInternacional',{form:{id}})).status(),403);
 assert.equal((await page.request.get(base+'index.php?controller=aliado&action=internacionales&editar='+id)).status(),200);
 assert.equal(await page.locator('#modalAliadoInternacional').count(),0);
 assert.equal((await page.goto(base+'index.php?controller=aliado&action=index')).status(),200);
 await page.locator('a[href*="action=internacionales"]').first().click();assert.match(page.url(),/action=internacionales/);
 await page.locator('#international-search').fill('toronto');assert.match(await page.locator('[data-international-count]').textContent(),/^9 instituciones/);
 assert.equal(await page.locator('[data-international-row]:visible').count(),8);
 await page.locator('[data-international-next]').click();assert.equal(await page.locator('[data-international-row]:visible').count(),1);
 await page.locator('#international-search').fill('no existe este aliado');assert.equal(await page.locator('[data-international-empty]').isVisible(),true);
 if(process.env.IMPE_TEST_OUTPUT)await page.screenshot({path:path.join(process.env.IMPE_TEST_OUTPUT,'internacionales_vacio.png'),fullPage:true});
 await page.locator('#international-search').fill('Toronto');await page.locator('#international-status').selectOption('0');assert.equal(await page.locator('[data-international-row]:visible').count(),0);await page.locator('#international-status').selectOption('');
 // Marketing no necesita permiso territorial nacional para navegar o crear convocatorias.
 assert.equal((await page.goto(base+'__rol?rol=marketing')).status(),200);
 assert.equal((await page.goto(base+'index.php?controller=aliado&action=index')).status(),200);
 await page.locator('a[href*="action=internacionales"]').first().click();
 async function bounds(modal){
  await modal.waitFor({state:'visible'});
  await page.waitForFunction(id=>{const el=document.getElementById(id);return el===document.activeElement||el.contains(document.activeElement);},await modal.getAttribute('id'));
  const problems=await modal.evaluate(el=>{const r=el.querySelector('.modal-content').getBoundingClientRect();const x=el.querySelector('.btn-close').getBoundingClientRect();return {outside:r.left<0||r.right>innerWidth+1||r.top<0||r.bottom>innerHeight+1,close:x.right>innerWidth||x.top<0,overflow:el.querySelector('.modal-body').scrollWidth>el.querySelector('.modal-body').clientWidth+1};});
  assert.deepEqual(problems,{outside:false,close:false,overflow:false});
  assert.equal(await page.locator('.modal.show').count(),1);assert.equal(await page.locator('.modal-backdrop.show').count(),1);
  assert.equal(await modal.locator('.modal-header h2,.modal-header h5').first().isVisible(),true);
 }
 for(const width of [1920,1366,1024,768,390,360]){
  await page.setViewportSize({width,height:900});await page.goto(base+'index.php?controller=aliado&action=internacionales');await page.evaluate(()=>document.fonts.ready);
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,`directorio sin overflow ${width}`);
  if(process.env.IMPE_TEST_OUTPUT)await page.screenshot({path:path.join(process.env.IMPE_TEST_OUTPUT,`internacionales_despues_${width}.png`),fullPage:true});
  // Institución con todos los programas disponibles.
  await page.locator('#international-search').fill('Toronto');const many=page.locator('[data-international-row]:visible').first();await many.getByRole('button',{name:'Ver detalles'}).click();const details=page.locator('#modalDetalleInternacional');await bounds(details);
  assert.match(await details.textContent(),/Inglés/); // Solo esta fixture tiene ese programa asignado.
  if(width===360&&process.env.IMPE_TEST_OUTPUT)await page.screenshot({path:path.join(process.env.IMPE_TEST_OUTPUT,'internacionales_detalles_360.png')});
  await details.locator('.modal-footer button').click();await details.waitFor({state:'hidden'});
  await page.locator('#international-search').fill(name);const card=page.locator('[data-international-row]:visible');await card.getByRole('button',{name:'Acciones de '+name}).click();
  const menu=card.locator('.dropdown-menu');assert.equal(await menu.isVisible(),true);const box=await menu.boundingBox();assert.ok(box.x>=0&&box.x+box.width<=width+1,'acciones sin recorte');
  await card.getByRole('button',{name:'Consultar convocatorias y QR'}).click();const campaigns=page.locator('#modalConvocatoriasInternacional');await bounds(campaigns);
  assert.equal(await campaigns.locator('[data-international-campaign]').count(),2);assert.equal(await campaigns.locator('[data-international-campaign] input').count(),2);
  if(process.env.IMPE_TEST_OUTPUT)await page.screenshot({path:path.join(process.env.IMPE_TEST_OUTPUT,`internacionales_convocatorias_${width}.png`)});
  await page.keyboard.press('Escape');await campaigns.waitFor({state:'hidden'});
 }
 await page.goto(base+'__rol?rol=admin');
 for(const width of [1920,1366,1024,768,390,360]){
  await page.setViewportSize({width,height:900});await page.getByRole('button',{name:'Registrar institución'}).click();const form=page.locator('#modalAliadoInternacional');await bounds(form);
  await form.locator('[name=pais_codigo]').selectOption('US');assert.equal(await form.locator('label[for=aliado_region]').textContent(),'Estado');
  await form.locator('[name=pais_codigo]').selectOption('CA');assert.match(await form.locator('label[for=aliado_region]').textContent(),/Provincia/);
  await form.locator('[name=pais_codigo]').selectOption('OTRO');assert.equal(await form.locator('[name=pais_nombre]').isVisible(),true);assert.equal(await form.locator('[name=pais_nombre]').getAttribute('required'),'');
  await form.locator('[name=pais_codigo]').selectOption('MX');assert.equal(await form.locator('[type=submit]').isDisabled(),true);await form.locator('[name=pais_codigo]').selectOption('US');
  if(process.env.IMPE_TEST_OUTPUT)await page.screenshot({path:path.join(process.env.IMPE_TEST_OUTPUT,`internacionales_formulario_${width}.png`)});
  await form.locator('.btn-close').click();await form.waitFor({state:'hidden'});
 }
 console.log('OK: Marketing/Cuenta Clave sin territorios, acciones autorizadas, filtros/paginación/vacío y modales en 1920/1366/1024/768/390/360');
};

