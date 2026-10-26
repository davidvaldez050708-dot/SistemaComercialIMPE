const {chromium}=require(process.env.IMPE_PLAYWRIGHT_PATH||'playwright');
const fs=require('fs'),path=require('path'),cp=require('child_process'),assert=require('node:assert/strict'),crypto=require('crypto');
const root=process.cwd(),out=path.join(root,'tmp/correo_convocatoria_preview');fs.mkdirSync(out,{recursive:true});
const baseline='3ea6d960';
const oldView=cp.execFileSync('git',['show',baseline+':app/views/correos/index.php']);
const oldCss=cp.execFileSync('git',['show',baseline+':public/css/correos_marketing.css']);
const baselinePath=path.join(out,'antes.php');fs.writeFileSync(baselinePath,oldView);
const render=before=>{const p=before?baselinePath:path.join(root,'app/views/correos/index.php');const r=cp.spawnSync('C:/xampp/php/php.exe',['-r',"define('ROOT_PATH',$argv[1]);define('BASE_URL','https://impe-preview.test/');require $argv[2];",root,p],{encoding:'utf8'});assert.equal(r.status,0,r.stderr);assert.ok(!/Warning:|Fatal error:/.test(r.stdout));return r.stdout;};
const html=before=>'<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+
 '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">'+
 ['global.css','dashboard.css','correos_marketing.css','responsive_sistema.css','marketing_selectores_uniformes.css'].map(f=>`<link rel="stylesheet" href="/public/css/${f}">`).join('')+
 '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><script src="/public/javascript/correos_marketing.js"></script></head><body>'+render(before)+'<script src="/public/javascript/selectores_uniformes.js"></script></body></html>';
const images=new Map([[2,[1200,1800]],[11,[1600,800]]].map(([id,[w,h]])=>{const bytes=Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}"><rect width="100%" height="100%" fill="#f4f7fc"/><rect x="1" y="1" width="${w-2}" height="${h-2}" fill="none" stroke="#24377f" stroke-width="12"/><rect width="${w}" height="${h/6}" fill="#24377f"/><text x="50" y="${h/3}" font-size="70" fill="#24377f">Convocatoria ${id}</text><text x="50" y="${h-70}" font-size="48" fill="#24377f">Contenido completo hasta el pie</text></svg>`);return [id,{bytes,sha:crypto.createHash('sha256').update(bytes).digest('hex')}];}));
const state={id:25,nombre:'Sinaloa'};
const rows=[{id:2,titulo:'DTyPE',estados:[state],convenios:[]},{id:11,titulo:'Convocatoria horizontal',estados:[state],convenios:[]}];
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.IMPE_CHROME_PATH});try{
 const page=await browser.newPage();const errors=[],requests=[],report=[];let before=false;
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://impe-preview.test/**',async route=>{
  const req=route.request(),u=new URL(req.url());requests.push({path:u.pathname,action:u.searchParams.get('action'),method:req.method()});
  if(req.method()!=='GET')throw new Error('La prueba no permite enviar ni guardar datos');
  if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:html(before)});
  if(u.pathname==='/index.php'){
   const id=Number(u.searchParams.get('id')),action=u.searchParams.get('action');
   if(action==='convocatorias'){
    if(!id)return route.fulfill({json:{ok:true,estados:[state],convocatorias:rows,convenios:[]}});
    const row=rows.find(r=>r.id===id),image=images.get(id);
    return route.fulfill({json:{ok:true,convocatoria:{...row,imagen_sha:image.sha,imagen_url:`index.php?controller=correoMarketing&action=imagenConvocatoria&id=${id}&v=${image.sha}`,imagen_mensaje:'Imagen que se incluirá dentro del correo.',enlace_publico:{url:'https://impe-preview.test/formulario-'+id,tipo:'registro',titulo:'Formulario de Registro',texto:'Completa el formulario en este enlace:',etiqueta:'Acceder al formulario'}}}});
   }
   if(action==='imagenConvocatoria')return route.fulfill({contentType:'image/svg+xml',body:images.get(id).bytes});
   return route.fulfill({status:400,json:{ok:false}});
  }
  if(before && u.pathname==='/public/css/correos_marketing.css')return route.fulfill({contentType:'text/css',body:oldCss});
  const local=path.join(root,u.pathname);if(!local.startsWith(root)||!fs.existsSync(local))return route.fulfill({status:404});
  return route.fulfill({body:fs.readFileSync(local),contentType:local.endsWith('.css')?'text/css':'application/javascript'});
 });
 const select=async id=>{await page.locator('[data-marketing-mail-convocatoria]').selectOption(String(id));await page.waitForFunction(id=>{const i=document.querySelector('[data-convocatoria-imagen]');return i.src.includes('id='+id+'&')&&i.complete&&i.naturalWidth>0;},id);};
 const measure=()=>page.evaluate(()=>{
  const modal=document.querySelector('#modalCorreoMarketing'),image=modal.querySelector('[data-convocatoria-imagen]'),status=modal.querySelector('[data-convocatoria-status]');
  const box=e=>{const r=e.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,bottom:r.bottom};};
  const style=e=>{const s=getComputedStyle(e);return Object.fromEntries(['width','height','maxWidth','maxHeight','minHeight','padding','borderRadius','backgroundColor','color','boxShadow','overflowY','position','fontSize','fontFamily'].map(k=>[k,s[k]]));};
  return {image:{...box(image),naturalWidth:image.naturalWidth,naturalHeight:image.naturalHeight,fit:getComputedStyle(image).objectFit,src:image.getAttribute('src')},status:box(status),statusText:status.textContent,
   geometry:['.correo-marketing-compose-dialog','.correo-marketing-compose-modal','.modal-header','.modal-body','.modal-footer','#correo_marketing_de','#correo_marketing_para','#correo_marketing_asunto','#correo_marketing_mensaje','#correo_marketing_adjuntos','[data-marketing-mail-draft]','[data-marketing-mail-send]'].map(s=>[s,style(modal.querySelector(s))]),
   data:Array.from(new FormData(modal.querySelector('form')).entries()).map(([k,v])=>[k,typeof v==='string'?v:{name:v.name,size:v.size,type:v.type}]),
   footer:box(modal.querySelector('.modal-footer')),body:box(modal.querySelector('.modal-body')),outside:style(document.querySelector('.correo-marketing-compose-button')),
   order:!!(status.compareDocumentPosition(image)&Node.DOCUMENT_POSITION_FOLLOWING),beforeTo:!!(image.compareDocumentPosition(modal.querySelector('[data-marketing-mail-to]'))&Node.DOCUMENT_POSITION_FOLLOWING),overflow:document.documentElement.scrollWidth>innerWidth};
 });
 const open=async()=>{await page.goto('https://impe-preview.test/',{waitUntil:'networkidle'});await page.locator('[data-marketing-compose]').click();await page.waitForFunction(()=>!document.querySelector('[data-marketing-mail-convocatoria]').disabled&&document.querySelector('[data-marketing-mail-convocatoria]').options.length===3);await page.evaluate(()=>document.fonts.ready);
  await page.locator('[data-marketing-mail-body]').fill('Mensaje que debe conservarse.');await page.locator('[data-marketing-mail-to]').fill('destino@example.test');await page.locator('[data-marketing-mail-subject]').fill('Asunto intacto');await page.locator('[data-marketing-mail-files]').setInputFiles({name:'adjunto.txt',mimeType:'text/plain',buffer:Buffer.from('Adjunto ficticio intacto')});await select(2);await page.evaluate(async()=>{document.activeElement.blur();await Promise.all(document.getAnimations().map(a=>a.finished.catch(()=>{})));});
 };
 for(const [name,width,height] of [['escritorio',1440,1000],['tableta',820,1180],['movil',390,844],['movil-estrecho',320,740]]){
  await page.setViewportSize({width,height});before=true;await open();const previous=await measure();before=false;await open();
  await page.locator('[data-convocatoria-imagen]').scrollIntoViewIfNeeded();const current=await measure();
  assert.deepEqual(current.geometry,previous.geometry,name+' geometría/estilos del modal y controles intactos');assert.deepEqual(current.outside,previous.outside);
  assert.deepEqual(current.data,previous.data,name+' contenido y archivo del formulario intactos');assert.equal(current.statusText,previous.statusText);assert.equal(current.image.src,previous.image.src,'URL original intacta');
  assert.ok(current.image.width<=300.1);assert.ok(current.image.width<=current.status.width+.1);assert.ok(Math.abs(current.image.height/current.image.width-1.5)<.01,'Imagen vertical sin recortar ni deformar');assert.equal(current.image.fit,'contain');
  const gap=current.image.y-current.status.bottom;assert.ok(gap>=8&&gap<=12,name+' separación de 8–12 px');assert.ok(Math.abs(current.image.x-current.status.x)<1,'Alineada con la leyenda');assert.equal(current.order,true);assert.equal(current.beforeTo,true);assert.equal(current.overflow,false);
  assert.ok(current.footer.y>=0&&current.footer.bottom<=height+1,'Pie fijo accesible');assert.ok(previous.image.width>=current.image.width);
  await page.screenshot({path:path.join(out,name+'.png')});
  await select(11);const horizontal=await measure();assert.ok(Math.abs(horizontal.image.height/horizontal.image.width-.5)<.01);assert.equal(await page.locator('[data-convocatoria-imagen-sha]').inputValue(),images.get(11).sha);assert.equal(await page.locator('[data-marketing-mail-body]').inputValue(),'Mensaje que debe conservarse.');
  await page.locator('[data-convocatoria-limpiar]').click();assert.equal(await page.locator('[data-convocatoria-preview]').isVisible(),false);assert.equal(await page.locator('[data-convocatoria-imagen]').getAttribute('src'),null);assert.equal(await page.locator('[data-convocatoria-imagen-sha]').inputValue(),'');assert.equal(await page.locator('[data-marketing-mail-files]').evaluate(e=>e.files[0].name),'adjunto.txt');
  report.push({name,beforeWidth:previous.image.width,afterWidth:current.image.width,afterHeight:current.image.height,gap,footer:current.footer});
 }
 assert.deepEqual(errors,[]);assert.equal(requests.some(r=>r.method!=='GET'||['enviar','guardarBorrador'].includes(r.action)),false,'Sin envíos ni guardados, ni siquiera simulados');
 fs.writeFileSync(path.join(out,'medidas.json'),JSON.stringify(report,null,2));console.log('OK: vista PHP y JavaScript reales; preview 300 px, posición/proporciones, cambio y limpieza, controles/pie/scroll CSS intactos; escritorio/tableta/móvil; cero envíos, BD o producción.');console.log(JSON.stringify(report));
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
