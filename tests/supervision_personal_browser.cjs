const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..'),deps=path.resolve(root,'../browser-legacy-deps/node_modules'),{chromium}=require(path.join(deps,'playwright'));
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const php=input=>JSON.parse(cp.execFileSync('C:/xampp/php/php.exe',['tests/fixtures/supervision_personal_http.php'],{cwd:root,input:JSON.stringify(input),encoding:'utf8'}));
const bootstrap=fs.readFileSync(path.join(deps,'bootstrap/dist/js/bootstrap.bundle.min.js'),'utf8');
const reference=p=>fs.existsSync(path.join(root,'tmp/hostinger-base',p))?fs.readFileSync(path.join(root,'tmp/hostinger-base',p),'utf8'):read(p);
const css=fs.readFileSync(path.join(deps,'bootstrap/dist/css/bootstrap.min.css'),'utf8')+['global.css','dashboard.css','usuarios.css','system_forms.css'].filter(f=>fs.existsSync(path.join(root,'public/css',f))).map(f=>reference('public/css/'+f)).join('\n');
(async()=>{
 // Estados HTTP reales del controlador, con la conexión a una base efímera local.
 assert.equal(php({actor:10,action:'catalogo'}).status,403);
 assert.equal(php({actor:2,action:'detalle',query:{kam_id:10}}).status,403);
 assert.equal(php({actor:1,action:'guardar',method:'GET'}).status,405);
 assert.equal(php({actor:1,action:'previsualizar',method:'POST',post:{kam_id:10,revision:1},csrf:'incorrecto'}).status,403);
 const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{for(const width of [1440,390]){
  const page=await browser.newPage({viewport:{width,height:960}}),errors=[];page.on('pageerror',e=>errors.push(e.message));let saves=0,failDetail=false;
  await page.route('http://personal.test/**',async route=>{
   const req=route.request(),url=new URL(req.url());
   if(url.pathname.endsWith('.css'))return route.fulfill({contentType:'text/css',body:fs.existsSync(path.join(root,url.pathname))?read(url.pathname):''});
   if(url.pathname.endsWith('.js'))return route.fulfill({contentType:'application/javascript',body:read(url.pathname)});
   const action=url.searchParams.get('action');
   if(action==='detalle'&&failDetail){failDetail=false;return route.fulfill({status:503,json:{ok:false,mensaje:'Servicio temporalmente no disponible.'}})}
   if(action){let post={};if(req.method()==='POST'){
    const form=await new Request(req.url(),{method:'POST',headers:req.headers(),body:req.postDataBuffer()}).formData();
    for(const[k,v]of form.entries())if(k==='colaboradores[]')(post.colaboradores??=[]).push(v);else post[k]=v;
    if(action==='guardar')saves++;
   }
    const out=php({action,method:req.method(),query:Object.fromEntries(url.searchParams),post,csrf:req.headers()['x-csrf-token']});return route.fulfill({status:out.status,json:out.body});
   }
   return route.fulfill({contentType:'text/html',body:'<!doctype html><meta charset="utf-8"><style>'+css+'</style><main style="padding:20px">'+php({action:'render'}).html+'</main><script>'+bootstrap+'</script>'});
  });
  await page.goto('http://personal.test/');
  // Inicializar después de Bootstrap, igual que el dashboard.
  await page.addScriptTag({content:read('public/javascript/supervision_personal.js')});
  await page.locator('[data-kam-card="10"]').click();await page.locator('[data-personal-editor]').waitFor({state:'visible'});
  await page.waitForTimeout(400);if(process.env.SP_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.SP_SCREENSHOT_DIR,'equipo-'+width+'.png')});
  const checkbox=page.getByRole('checkbox',{name:'Supervisar a Mayra Prueba'});
  const old=await checkbox.isChecked();await checkbox.setChecked(!old);
  await page.locator('[data-personal-role]').selectOption('4');
  await page.locator('[data-personal-search]').fill('Luis');
  assert.equal(await page.locator('[data-personal-list] input:checked').count(),1,'Filtrar no pierde otras selecciones');
  await page.locator('[data-personal-preview]').click();await page.locator('[data-personal-review]').waitFor({state:'visible'});
  assert.match(await page.locator(old?'[data-personal-removed]':'[data-personal-added]').innerText(),/Mayra Prueba/);
  assert.equal(saves,0,'Previsualizar no guarda');
  await page.waitForTimeout(400);if(process.env.SP_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.SP_SCREENSHOT_DIR,'revision-'+width+'.png')});
  await page.locator('[data-personal-save]').click();await page.locator('#modalSupervisionPersonal').waitFor({state:'hidden'});
  assert.equal(saves,1);assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'Sin desbordamiento');
  await page.locator('[data-kam-card="10"]').click();await page.locator('[data-personal-editor]').waitFor({state:'visible'});
  assert.equal(await page.getByRole('checkbox',{name:'Supervisar a Mayra Prueba'}).isChecked(),!old,'Guardado persistido');
  await page.locator('[data-personal-cancel]').click();await page.locator('#modalSupervisionPersonal').waitFor({state:'hidden'});
  await page.locator('[data-personal-history]').click();await page.locator('.personal-history-row').first().waitFor();
  assert.match(await page.locator('[data-personal-history-list]').innerText(),/Mayra Prueba/);
  if(width===1440){
   await page.locator('#modalBitacoraSupervision [data-bs-dismiss]').click();await page.locator('#modalBitacoraSupervision').waitFor({state:'hidden'});
   failDetail=true;await page.locator('[data-kam-card="10"]').click();await page.locator('[data-personal-load-error]').waitFor({state:'visible'});
   assert.equal(await page.locator('[data-personal-preview]').isDisabled(),true,'Un fallo de carga no permite guardar un equipo vacío');
   const toast=page.locator('.system-toast-error');await toast.waitFor({state:'visible'});await toast.waitFor({state:'detached',timeout:6000});
  }
  assert.deepEqual(errors,[]);await page.close();console.log('PASS '+width+': selección persistente al filtrar, resumen previo, un guardado, reapertura y bitácora.');
 }}finally{await browser.close()}
 console.log('PASS: administrador exclusivo; CSRF; verbos HTTP; vista compacta de escritorio y móvil; cambios auditados.');
})().catch(e=>{console.error(e);process.exit(1)});
