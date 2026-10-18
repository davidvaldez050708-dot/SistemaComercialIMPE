const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright'),repo=path.resolve(__dirname,'..');
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.dirname(require.resolve('bootstrap/package.json'));
const view=cp.execFileSync('php',[path.join(__dirname,'fixtures/telefonia_devoluciones_vista.php')],{encoding:'utf8'});
const read=p=>fs.readFileSync(path.join(repo,p),'utf8');
const html=`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'))}\n${read('public/css/global.css')}\n${read('public/css/dashboard.css')}</style><body><main>${view}</main><script>${fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'))}</script><script>${read('public/javascript/telefonia_atencion.js')}</script><script>${read('public/javascript/seguimiento_entrantes_analista.js')}</script><script>
window.IMPE_CURRENT_USER_ID=1;window.callPayloads=[];window.phoneState={active:false};window.subs=[];
window.IMPE_TELEPHONY_PERSISTENT={getState:()=>window.phoneState,subscribe:f=>{subs.push(f);f(phoneState);},async startCall(p){callPayloads.push(p);phoneState={active:true,context:p.context};for(const f of subs)f(phoneState);}};
window.finishCallback=()=>{phoneState={...phoneState,active:false,phase:'finished'};for(const f of subs)f(phoneState);};
</script></body>`;
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 try{
  const page=await browser.newPage({viewport:{width:1366,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  let tasks=null,attempt=null,saved=0,fail=true,lastBody='',nextId=2;
  await page.route('http://devoluciones.test/**',async route=>{
   const req=route.request(),u=new URL(req.url()),body=new URLSearchParams(req.postData()||'');
   if(u.searchParams.get('action')==='devolucion'){
    if(!tasks)tasks=await page.locator('[data-callback-data]').evaluate(e=>JSON.parse(e.textContent));
    let command=body.get('accion');if(req.headers()['content-type']?.startsWith('multipart/form-data')){for(const m of (req.postData()||'').matchAll(/name="([^"]+)"\r\n\r\n([^\r]*)/g))body.set(m[1],m[2]);command=body.get('accion');}
    if(command==='PREPARAR'){
     attempt={id:nextId++,solicitud_id:7,estado:'PREPARADO',resultado:'',numero:'+525551112233',puede_registrar:false,estado_llamada:'Marcando',contestada:false};tasks[0].intento=attempt;
     return route.fulfill({json:{ok:true,intento:attempt}});
    }
    if(command==='RESULTADO'){
     saved++;lastBody=req.postData();if(fail)return route.fulfill({status:500,json:{ok:false,mensaje:'No se pudo guardar. Intenta nuevamente.'}});
     attempt={...attempt,resultado:body.get('resultado'),observacion:body.get('observacion'),estado:'FINALIZADO',puede_registrar:false};tasks[0].intento=attempt;if(attempt.resultado==='CONTACTO_CORRECTO')tasks[0].devolucion='ATENDIDA';
     return route.fulfill({json:{ok:true,intento:attempt}});
    }
    return route.fulfill({json:{ok:true,...(u.searchParams.has('intento_id')?{intento:attempt}:{devoluciones:tasks})}});
   }
   if(u.searchParams.get('action')==='buscarEntrantesSeguimientos')return route.fulfill({json:{ok:true,resultados:[{id:1,nombre_entidad:'Institución Uno',estado_nombre:'Morelos'}]}});
   if(u.pathname.startsWith('/public/javascript/'))return route.fulfill({contentType:'application/javascript',body:read(u.pathname.slice(1))});
   return route.fulfill({contentType:'text/html',body:html});
  });
  await page.goto('http://devoluciones.test/');
  const row=page.locator('[data-callback-id="7"]');await row.waitFor();assert.match(await row.textContent(),/Institución Uno/);
  assert.equal(await row.getByRole('button',{name:'Llamar',exact:true}).count(),1);assert.equal(await page.getByRole('button',{name:'Marcar atendida'}).count(),0);
  if(process.env.CALLBACK_SCREENSHOT)await page.screenshot({path:process.env.CALLBACK_SCREENSHOT+'-lista.png'});
  await row.getByRole('button',{name:'Llamar',exact:true}).click();await page.waitForFunction(()=>callPayloads.length===1);
  assert.equal(await page.evaluate(()=>callPayloads[0].context.type),'DEVOLUCION');assert.equal(await page.evaluate(()=>callPayloads[0].destination),'+525551112233');
  attempt={...attempt,puede_registrar:true,finalizada:true,contestada:true,duracion:12,estado_llamada:'Completada',pbx_call_id:'callback-test-001'};await page.evaluate(()=>finishCallback());
  const modal=page.locator('#modalLlamadaEntranteAnalista');await modal.waitFor({state:'visible'});const form=modal.locator('form');
  assert.equal(await form.locator('[name=decision]').inputValue(),'existente');assert.equal(await form.locator('[name=seguimiento_id]').inputValue(),'1');
  await form.locator('[name=decision]').selectOption('identificada');await form.locator('[name=institucion_nombre]').fill('Institución identificada');
  assert.equal(await form.locator('[data-incoming-new]').isVisible(),false);
  await form.locator('[name=decision]').selectOption('nueva');assert.equal(await form.locator('[name=estado_id]').isVisible(),true);assert.equal(await form.locator('[name=nombre_entidad]').getAttribute('required'),'');
  await form.locator('[name=decision]').selectOption('existente');
  await form.locator('[name=resultado]').selectOption('BUZON_VOZ');await form.locator('[name=observacion]').fill('Dejó buzón; reintentar por la tarde.');
  await form.locator('[type=submit]').click();await page.locator('.system-toast-error').waitFor();assert.equal(await modal.isVisible(),true);assert.match(await form.locator('[name=observacion]').inputValue(),/reintentar/);
  await page.setViewportSize({width:390,height:650});const box=await modal.locator('.modal-content').boundingBox();assert.ok(box.y>=0&&box.y+box.height<=651,'modal accesible en móvil');
  if(process.env.CALLBACK_SCREENSHOT)await page.screenshot({path:process.env.CALLBACK_SCREENSHOT+'-resultado.png'});
  fail=false;await form.locator('[type=submit]').click();await modal.waitFor({state:'hidden'});assert.equal(saved,2);assert.match(lastBody,/test-csrf/);assert.match(lastBody,/seguimiento_id/);
  await row.getByRole('button',{name:'Llamar',exact:true}).waitFor();assert.match(await row.textContent(),/Buzón de voz/);assert.equal(await row.getByRole('button',{name:'Consultar grabación'}).count(),0);
  await page.setViewportSize({width:1366,height:900});await row.getByRole('button',{name:'Llamar',exact:true}).click();await page.waitForFunction(()=>callPayloads.length===2);
  attempt={...attempt,puede_registrar:true,finalizada:true,contestada:true,duracion:12,estado_llamada:'Completada',pbx_call_id:'callback-test-002'};await page.evaluate(()=>finishCallback());await modal.waitFor({state:'visible'});
  await form.locator('[name=resultado]').selectOption('CONTACTO_CORRECTO');await form.locator('[name=observacion]').fill('Conversó Ana.');await form.locator('[type=submit]').click();await modal.waitFor({state:'hidden'});
  await row.waitFor({state:'detached'});await page.locator('[data-callback-filter]').selectOption('ATENDIDA');await row.waitFor();assert.match(await row.textContent(),/Contacto correcto/);assert.equal(await row.getByRole('button',{name:'Llamar',exact:true}).count(),0);
  tasks=Array.from({length:12},(_,i)=>({...tasks[0],id:20+i,contacto:'Contacto '+i,devolucion:'PENDIENTE',intento:null}));await page.locator('[data-callback-filter]').selectOption('PENDIENTE');await page.locator('[data-callback-refresh]').click();
  await page.waitForFunction(()=>document.querySelectorAll('[data-callback-id]').length===10);await page.locator('[data-callback-next]').click();assert.equal(await page.locator('[data-callback-id]').count(),2);assert.match(await page.locator('[data-callback-range]').textContent(),/11–12 de 12/);
  assert.deepEqual(errors,[]);console.log('Devoluciones/navegador: llamada propia, resultado automático, expediente, error sin pérdida, buzón reintentable, cierre por contacto, filtro y móvil: OK');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
