const fs=require('fs'),cp=require('child_process'),assert=require('assert/strict'),{chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{channel:'chrome'})});
 try{
 for(const legacy of [true,false]){
  const page=await browser.newPage();let sourceOrigin='DENUE',origin='institucional',fail=false,detailFail=false;const posts=[],errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  const code=legacy?cp.execFileSync('git',['show','d1a08f56:public/javascript/aliados_registro.js'],{encoding:'utf8'}):fs.readFileSync('public/javascript/aliados_registro.js','utf8');
  await page.route('http://aliados.test/**',async route=>{
   const req=route.request(),u=new URL(req.url());
   if(u.searchParams.get('action')==='registroDetalle')return route.fulfill({status:detailFail?403:200,json:detailFail?{ok:false,mensaje:'Sin acceso al territorio.'}:{ok:true,datos:{origen:{id:10,origen:sourceOrigin,nombre_entidad:'Institución de Vinculación',estado_id:7},programas:['uno']}}});
   if(u.searchParams.get('action')==='municipios')return route.fulfill({json:{ok:true,municipios:[{id:1,nombre:'Municipio'}]}});
   if(u.searchParams.get('action')==='registroGestion'){
    const data=await new Response(req.postDataBuffer(),{headers:{'Content-Type':req.headers()['content-type']}}).formData();
    posts.push({origen:data.get('origen'),id:data.get('id'),programas:data.getAll('programas[]'),operacion:data.get('operacion'),csrf:data.get('csrf_token')});
    const ok=data.get('origen')===origin&&!fail;
    return route.fulfill({status:ok?200:422,json:{ok,mensaje:fail?'Error de prueba al guardar.':'Origen de aliado inválido.',datos:{}}});
   }
   return route.fulfill({contentType:'text/html',body:`<!doctype html><meta charset="utf-8"><style>.d-none,[hidden]{display:none!important}</style>
    <button data-registro-abrir data-registro-origen="${origin}" data-registro-id="10" ${origin==='manual'?'data-registro-editar':''}>Abrir</button>
    <div hidden data-registro-admin data-base="http://aliados.test/" data-estado="7"><h2 class="modal-title"></h2><small data-registro-subtitulo></small><form>
     <div class="alert alert-danger d-none" data-registro-error></div><input type="hidden" name="id"><input type="hidden" name="origen">
     <div data-registro-manual><input name="nombre_entidad"><input id="aliadoManualEstado" name="estado_id" value="7"><select name="municipio_id"></select></div>
     <label><input type="checkbox" name="programas[]" value="uno">Uno</label><label><input type="checkbox" name="programas[]" value="dos">Dos</label>
     <button type="submit" data-registro-guardar>Guardar</button><button type="button" data-registro-desactivar></button>
    </form></div><script>window.IMPE_CSRF_TOKEN='token-sintetico';window.bootstrap={Modal:{getOrCreateInstance:e=>({show:()=>{e.hidden=false;}})},Toast:{getOrCreateInstance:e=>({show:()=>{e.classList.add('show');}})}};</script><script>${code}</script>`});
  });
  const open=async()=>{await page.goto('http://aliados.test/');await page.getByText('Abrir',{exact:true}).click();await page.locator('[data-registro-guardar]:enabled').waitFor();};
  const save=async()=>{const response=page.waitForResponse(r=>r.url().includes('action=registroGestion'));await page.getByText('Guardar',{exact:true}).click();await response;};
  await open();await page.locator('[value=dos]').check();await save();
  if(legacy){assert.equal(posts.at(-1).origen,'DENUE');await page.locator('[data-registro-error]:not(.d-none)').waitFor();assert.equal(await page.locator('.system-toast-error').count(),1);console.log('REPRODUCIDO: origen institucional sobrescrito con DENUE y aviso rojo duplicado.');await page.close();continue;}
  assert.equal(posts.at(-1).origen,'institucional');assert.deepEqual(posts.at(-1).programas,['uno','dos']);assert.equal(posts.at(-1).id,'10');assert.equal(posts.at(-1).csrf,'token-sintetico');
  for(const value of ['MANUAL','DENUE','OTRA_FUENTE']){
   sourceOrigin=value;await open();await page.locator('[value=uno]').uncheck();await page.locator('[value=dos]').check();await save();assert.equal(posts.at(-1).origen,'institucional');assert.deepEqual(posts.at(-1).programas,['dos']);
  }
  fail=true;await open();await save();await page.locator('.system-toast-error').waitFor();assert.equal(await page.locator('[data-registro-error]:not(.d-none)').count(),0);assert.equal(await page.locator('.system-toast-error').count(),1);
  await open();await page.locator('[value=uno]').uncheck();const n=posts.length;await page.getByText('Guardar',{exact:true}).click();await page.locator('.system-toast-error').waitFor();assert.equal(posts.length,n);assert.equal(await page.locator('[data-registro-error]:not(.d-none)').count(),0);
  origin='manual';sourceOrigin='manual';fail=false;await open();await page.locator('[value=dos]').check();await save();assert.equal(posts.at(-1).origen,'manual');assert.equal(posts.at(-1).operacion,'guardar_manual');
  detailFail=true;await page.goto('http://aliados.test/');await page.getByText('Abrir',{exact:true}).click();await page.locator('.system-toast-error').waitFor();assert.equal(await page.locator('[data-registro-error]:not(.d-none)').count(),0);assert.equal(await page.locator('[data-registro-guardar]').isDisabled(),true);
  assert.deepEqual(errors,[]);await page.close();
 }
 console.log('PASS: Vinculación DENUE/MANUAL, agregar/quitar programas, aliados manuales, CSRF/identidad, errores y permisos sólo con toast; sin SQL.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
