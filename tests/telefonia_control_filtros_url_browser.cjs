const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});
 try {
  for(const prefix of ['/','/crm/']){
   const context=await browser.newContext(),errors=[],requests=[];
   await context.route('**/*',async route=>{
    const u=new URL(route.request().url());
    if(route.request().resourceType()!=='document')return route.fulfill({body:''});
    requests.push(u);
    const output=cp.spawnSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'fixtures/telefonia_control_filtros_url.php'),'https://crm.test'+prefix,JSON.stringify(Object.fromEntries(u.searchParams))],{cwd:root,encoding:'utf8'});
    assert.equal(output.status,0,output.stderr);assert.equal(output.stderr,'');
    await route.fulfill({contentType:'text/html',body:'<!doctype html><meta charset="utf-8">'+output.stdout+'<script>'+fs.readFileSync(path.join(root,'public/javascript/telefonia_control.js'),'utf8')+'</script>'});
   });
   const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
   const base='https://crm.test'+prefix+'index.php';
   await page.goto(base+'?controller=telefonia&action=index');
   assert.equal(await page.evaluate(()=>document.querySelector('.telephony-control-filters').action instanceof HTMLInputElement),true,'reproduce la colisión DOM de name=action');
   const form=page.locator('.telephony-control-filters');
   async function changed(name,value){
    await Promise.all([page.waitForURL(u=>u.searchParams.get(name)===value&&u.searchParams.has('desde')),form.locator(`[name="${name}"]`).selectOption(value)]);
    const u=new URL(page.url());assert.equal(u.pathname,prefix+'index.php');assert.equal(u.searchParams.get('action'),'index');assert.equal(u.searchParams.get('controller'),'telefonia');
   }
   await changed('rol','Asesor de Ventas');
   await changed('usuario_id','22');
   await changed('direccion','Entrante');
   assert.match(await page.locator('body').innerText(),/222/,'métricas renderizadas por la siguiente petición');
   await changed('orden','segundos');
   const originalThrough=await form.locator('[name="hasta"]').inputValue();
   const previousDay=new Date(originalThrough+'T12:00:00Z');previousDay.setUTCDate(previousDay.getUTCDate()-1);const through=previousDay.toISOString().slice(0,10);
   await form.locator('[name="hasta"]').fill(through);await form.locator('[name="hasta"]').dispatchEvent('change');
   await page.waitForURL(u=>u.searchParams.get('hasta')===through);
   const firstDay=new Date(through+'T12:00:00Z');firstDay.setUTCDate(firstDay.getUTCDate()-5);const from=firstDay.toISOString().slice(0,10);
   await form.locator('[name="desde"]').fill(from);await form.locator('[name="desde"]').dispatchEvent('change');
   await page.waitForURL(u=>u.searchParams.get('desde')===from);
   let before=requests.length;
   await form.evaluate(f=>f.requestSubmit());await page.waitForTimeout(360);
   assert.equal(requests.length,before,'mismos filtros no vuelven a navegar');
   // Cambios próximos comparten una petición y conservan todos los filtros.
   await form.locator('[name="rol"]').selectOption('Analista de Datos');
   await form.locator('[name="direccion"]').selectOption('Saliente');
   await page.waitForURL(u=>u.searchParams.get('rol')==='Analista de Datos'&&u.searchParams.get('direccion')==='Saliente');
   assert.equal(requests.length,before+1);
   before=requests.length;
   await form.locator('[name="desde"]').fill(originalThrough);await form.locator('[name="desde"]').dispatchEvent('change');
   await page.waitForTimeout(360);assert.equal(requests.length,before,'rango invertido no navega');
   assert.match(await form.locator('[data-control-filter-message]').innerText(),/posterior/);
   const tooOld=new Date(originalThrough+'T12:00:00Z');tooOld.setUTCDate(tooOld.getUTCDate()-100);
   await form.locator('[name="desde"]').fill(tooOld.toISOString().slice(0,10));await form.locator('[name="desde"]').dispatchEvent('change');
   await page.waitForTimeout(360);assert.equal(requests.length,before);assert.match(await form.locator('[data-control-filter-message]').innerText(),/90 días/);
   await form.locator('[name="desde"]').fill(from);await form.locator('[name="desde"]').dispatchEvent('change');await page.waitForTimeout(360);
   await form.locator('[data-control-clear]').click();await page.waitForURL(base+'?controller=telefonia&action=index');
   assert(requests.every(u=>u.pathname===prefix+'index.php'),'ninguna petición utiliza [object HTMLInputElement]');
   assert.deepEqual(errors,[]);await context.close();
  }
  console.log('CHECK_OK navegador: vista PHP y cliente reales, colisión action reproducida, seis filtros, root/subcarpeta, métricas recargadas, debounce, no duplicados, fechas inválidas y limpiar; sin SQL ni PBX.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
