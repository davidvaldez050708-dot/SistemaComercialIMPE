const fs=require('node:fs'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
(async()=>{const b=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'msedge'});try{
 const styles=[];
 for(const sales of [false,true]){
  const page=await b.newPage();await page.route('**/*',r=>r.abort());
  let html=cp.execFileSync('php',[sales?'tests/fixtures/telefonia_ventas_shell.php':'tests/fixtures/telefonia_flujo_shell.php',...(sales?[]:['analista'])],{encoding:'utf8'});
  html=html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,'').replace(/<iframe\b[^>]*>[\s\S]*?<\/iframe>/gi,'');
  await page.setContent(html,{waitUntil:'domcontentloaded'});await page.addStyleTag({content:fs.readFileSync('public/css/telefonia_shell.css','utf8')});
  await page.evaluate(()=>{const panel=document.querySelector('[data-incoming-panel]');panel.hidden=false;panel.querySelectorAll('.persistent-phone-actions button').forEach(b=>b.hidden=!b.hasAttribute('data-incoming-register-finished'));});
  const sizes=[];for(const width of [1366,390]){await page.setViewportSize({width,height:800});sizes.push(await page.locator('[data-incoming-register-finished]').evaluate(el=>{const s=getComputedStyle(el),a=el.parentElement.getBoundingClientRect(),r=el.getBoundingClientRect();return {font:s.fontFamily,size:s.fontSize,padding:s.padding,radius:s.borderRadius,background:s.backgroundColor,gap:Math.round(r.top-a.top),height:Math.round(r.height)};}));}
  styles.push(sizes);await page.close();
 }
 assert.deepEqual(styles[0],styles[1],'Mismo componente y estilos terminales de Analista y Ventas');
 for(const style of styles[1])assert(style.gap<=1,'Sin fila vacía antes del único botón');
 console.log('Paridad visual: componente PHP compartido, escritorio/móvil, tamaños, tipografía, botón y ausencia de fila vacía: OK');
}finally{await b.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
