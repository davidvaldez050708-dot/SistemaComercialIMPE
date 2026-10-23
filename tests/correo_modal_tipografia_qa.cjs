// Se ejecuta desde el escenario existente --tipografia, con API interceptada.
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
module.exports=async(page,root,out)=>{
 const css=fs.readFileSync(path.join(root,'public/css/correos_marketing.css'),'utf8');
 const block=css.slice(css.indexOf('/* Redactar correo: escala'));
 assert.ok(block.includes('#modalCorreoMarketing'));
 // Todo el bloque pertenece al modal y sólo declara propiedades tipográficas.
 const rules=[...block.replace(/\/\*[\s\S]*?\*\//g,'').matchAll(/([^{}]+)\{([^{}]+)\}/g)];
 for(const [,selector,body] of rules){
  assert.ok(selector.trim().startsWith('#modalCorreoMarketing'));
  for(const property of body.split(';').filter(x=>x.trim()))assert.match(property.trim(),/^(font-(?:size|family|weight)|line-height):/);
 }
 const style=await page.addStyleTag({content:block});
 const measure=()=>page.evaluate(()=>{
  const modal=document.querySelector('#modalCorreoMarketing');
  const selectors=['.correo-marketing-compose-dialog','.correo-marketing-compose-modal','.modal-header','.modal-body','.modal-footer',
   '#correo_marketing_de','#correo_marketing_para','#correo_marketing_asunto','#correo_marketing_mensaje','#correo_marketing_adjuntos',
   '.uniform-select-trigger','[data-marketing-mail-draft]','[data-marketing-mail-send]'];
  const box=e=>{const s=getComputedStyle(e);return Object.fromEntries(['width','maxWidth','maxHeight','minHeight','padding','borderRadius','backgroundColor','color','boxShadow','overflowY','position'].map(k=>[k,s[k]]));};
  const type=e=>{const s=getComputedStyle(e);return [s.fontFamily,s.fontSize,s.fontWeight,s.lineHeight];};
  return {geometry:selectors.map(s=>[s,box(modal.querySelector(s))]),
   outside:['.correo-marketing-toolbar-panel','.correo-marketing-compose-button','#selector_ajeno + .uniform-select-trigger',
    '#modalCorreoMarketingDetalle .system-form-modal-title'].map(s=>{const e=document.querySelector(s);return [s,e?type(e):null];}),
   typography:Object.fromEntries(['.system-form-modal-title','.system-form-modal-subtitle','.form-label','.form-text',
    '.uniform-select-trigger','.uniform-select-option','#correo_marketing_de','#correo_marketing_mensaje',
    '[data-convocatoria-enlace-url]','[data-marketing-mail-draft]','[data-marketing-mail-send]'].map(s=>[s,type(modal.querySelector(s))])),
   overflow:document.documentElement.scrollWidth>innerWidth};
 });
 const report=[];
 await page.evaluate(()=>document.fonts.ready);
 for(const [name,width,height] of [['escritorio',1440,1000],['tableta',820,1180],['movil',390,844]]){
  await page.setViewportSize({width,height});await style.evaluate(e=>{e.disabled=true;});
  const before=await measure();
  await style.evaluate(e=>{e.disabled=false;});const after=await measure();
  // Sólo la tipografía puede variar: medidas, colores y scroll CSS idénticos.
  assert.deepEqual(after.geometry,before.geometry,name+' contenedores y estados visuales');
  assert.deepEqual(after.outside,before.outside,name+' estilos fuera del modal');assert.equal(after.overflow,false);
  const expected={'.system-form-modal-title':'16px','.system-form-modal-subtitle':'11px','.form-label':'11px','.form-text':'11px',
   '.uniform-select-trigger':'12px','.uniform-select-option':'12px','#correo_marketing_de':'12px','#correo_marketing_mensaje':'12px',
   '[data-convocatoria-enlace-url]':'12px','[data-marketing-mail-draft]':'13px','[data-marketing-mail-send]':'11px'};
  for(const [selector,size] of Object.entries(expected)){
   assert.match(after.typography[selector][0],/Manrope/);assert.equal(after.typography[selector][1],size,name+' '+selector);
  }
  const menu=page.locator('#correo_marketing_convocatoria + .uniform-select');
  await menu.locator('[data-uniform-select-trigger]').click();await menu.locator('.uniform-select-menu').waitFor({state:'visible'});
  await page.screenshot({path:path.join(out,'tipografia-'+name+'-selector.png')});
  await menu.locator('[data-uniform-select-trigger]').click();
  await page.locator('#modalCorreoMarketing [data-convocatoria-enlace-url]').scrollIntoViewIfNeeded();
  await page.screenshot({path:path.join(out,'tipografia-'+name+'-enlace.png')});
  const footer=await page.locator('#modalCorreoMarketing .modal-footer').boundingBox();
  assert.ok(footer.y>=0 && footer.y+footer.height<=height+1,name+' botones accesibles');
  report.push({name,...after});
 }
 // Apertura/cierre y edición permanecen en el mismo formulario.
 await page.locator('#modalCorreoMarketing .btn-close').click();await page.locator('#modalCorreoMarketing').waitFor({state:'hidden'});
 await page.locator('[data-marketing-compose]').click();await page.locator('#modalCorreoMarketing').waitFor({state:'visible'});
 await page.locator('[data-marketing-mail-body]').fill('Texto QA que debe permanecer íntegro.');
 await page.locator('[data-marketing-mail-files]').setInputFiles({name:'manual.txt',mimeType:'text/plain',buffer:Buffer.from('Adjunto QA')});
 await page.locator('[data-marketing-mail-convocatoria]').selectOption('1');
 await page.waitForFunction(()=>document.querySelector('[data-convocatoria-imagen-sha]').value.length===64);
 // El manejador real prepara el borrador; la ruta devuelve aceptación ficticia.
 await Promise.all([page.waitForEvent('domcontentloaded'),page.locator('[data-marketing-mail-draft]').click()]);
 await page.locator('[data-marketing-compose]').click();
 await page.locator('[data-marketing-mail-convocatoria] option[value="1"]').waitFor({state:'attached'});
 await page.locator('[data-marketing-mail-body]').fill('Texto QA que debe permanecer íntegro.');
 await page.locator('[data-marketing-mail-to]').fill('destino@example.test');await page.locator('[data-marketing-mail-subject]').fill('Prueba QA');
 await page.locator('[data-marketing-mail-files]').setInputFiles({name:'manual.txt',mimeType:'text/plain',buffer:Buffer.from('Adjunto QA')});
 await page.locator('[data-marketing-mail-convocatoria]').selectOption('1');
 await page.waitForFunction(()=>document.querySelector('[data-convocatoria-imagen-sha]').value.length===64);
 await page.setViewportSize({width:1440,height:1000});
 fs.writeFileSync(path.join(out,'tipografia-medidas.json'),JSON.stringify(report,null,2));
 console.log('OK: Manrope y escala institucional; escritorio/tableta/móvil, geometría/colores/scroll CSS y controles externos intactos. API ficticia.');
};
