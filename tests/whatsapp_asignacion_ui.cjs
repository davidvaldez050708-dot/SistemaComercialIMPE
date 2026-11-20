// Navegador aislado con datos y respuestas sintéticos; no abre el CRM real.
const fs=require('fs'),http=require('http'),path=require('path'),cp=require('child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..');
const fixture=`
define('BASE_URL','/');
$_SESSION=['csrf_token'=>'csrf-sintetico'];
$busqueda='';
$pendientes=['total'=>2,'pagina'=>1,'paginas'=>1,'filas'=>[
 ['id'=>1,'nombre_contacto'=>'Contacto de prueba','telefono_normalizado'=>'525500000001','canal'=>'Fundación','ultimo_mensaje_preview'=>'Hola','ultimo_mensaje_at'=>'2026-11-20 13:00:00'],
 ['id'=>2,'nombre_contacto'=>'<img src=x onerror=alert(1)>','telefono_normalizado'=>'525500000002','canal'=>'Fundación','ultimo_mensaje_preview'=>'Consulta','ultimo_mensaje_at'=>'2026-11-20 13:01:00']]];
$candidatos=[['id'=>22,'nombre'=>'Asesor de prueba','rol'=>'Asesor de Ventas']];
require $argv[1].'/app/views/whatsapp/asignaciones.php';`;
const body=cp.execFileSync(process.env.PHP_BINARY||'php',['-r',fixture,root],{encoding:'utf8'});
const head='<meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"><link rel="stylesheet" href="/public/css/global.css"><link rel="stylesheet" href="/public/css/dashboard.css">';
let calls=0,conflict=false;
const server=http.createServer(async(req,res)=>{
 const url=new URL(req.url,'http://localhost');
 if(req.method==='POST'){
  let payload='';for await(const chunk of req)payload+=chunk;
  if(!payload.includes('csrf-sintetico')||!payload.includes('responsable_id')){res.writeHead(400);return res.end('{}');}
  calls++;const status=conflict?409:200;
  setTimeout(()=>{res.writeHead(status,{'Content-Type':'application/json'});res.end(JSON.stringify({ok:status===200,mensaje:status===200?'Conversación asignada.':'Otro usuario ya asignó esta conversación.'}));},120);return;
 }
 if(url.pathname.startsWith('/public/')){
  const file=path.resolve(root,'.'+url.pathname);
  if(!file.startsWith(root+path.sep)||!fs.existsSync(file)){res.writeHead(404);return res.end();}
  res.setHeader('Content-Type',file.endsWith('.js')?'application/javascript':'text/css');return res.end(fs.readFileSync(file));
 }
 res.setHeader('Content-Type','text/html; charset=utf-8');res.end('<!doctype html><html><head>'+head+'</head><body style="padding:24px">'+body+'</body></html>');
});
(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BINARY||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try {
  const page=await browser.newPage({viewport:{width:1440,height:900}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const url='http://127.0.0.1:'+server.address().port+'/';await page.goto(url);
  if(await page.locator('img').count())throw Error('El contacto se interpretó como HTML');
  await page.locator('[name=responsable_id]').first().selectOption('22');
  await page.locator('[data-wa-assignment-form]').first().evaluate(form=>{form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));});
  await page.waitForFunction(()=>document.querySelectorAll('[data-wa-assignment-row]').length===1);
  if(calls!==1)throw Error('Envío duplicado de asignación');
  conflict=true;await page.locator('[name=responsable_id]').selectOption('22');await page.locator('[data-wa-assignment-form] button').click();
  await page.waitForFunction(()=>document.querySelector('[data-wa-assignment-feedback]').textContent.includes('Otro usuario'));
  if(await page.locator('[data-wa-assignment-row]').count()!==1)throw Error('Se eliminó una fila rechazada');
  conflict=false;await page.locator('[data-wa-assignment-form] button').click();
  await page.waitForFunction(()=>!document.querySelector('[data-wa-assignment-empty]').hidden);
  if(await page.locator('[data-wa-pending-count]').textContent()!=='0')throw Error('Contador incorrecto');
  await page.setViewportSize({width:390,height:844});await page.goto(url);
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth+1);
  if(overflow)throw Error('La página desborda el móvil');
  if(errors.length)throw Error(errors.join('; '));
  console.log('PASS: formulario, CSRF, doble clic, conflicto, contador, estado vacío, escape HTML y móvil en Chrome aislado.');
 } finally {await browser.close();server.close();}
})().catch(error=>{server.close();console.error(error.message);process.exitCode=1;});
