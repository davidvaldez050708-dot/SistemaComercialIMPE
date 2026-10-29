// Vista y JS reales. Peticiones interceptadas; ninguna reunión o correo real.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),bootstrap=process.env.BOOTSTRAP_MODULE_PATH;
let view=cp.execFileSync('C:/xampp/php/php.exe',[path.join(root,'tests/fixtures/agenda_comentarios_vista.php')],{encoding:'utf8'});
view=view.replace('data-agenda-mode="SOLICITUD"','data-agenda-mode="GESTION"').replace('data-agenda-can-request="1"','data-agenda-can-request="0"').replace('data-agenda-can-manage="0"','data-agenda-can-manage="1"');
const boot=fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),'utf8');
const css=fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'),'utf8');
const source=fs.readFileSync(path.join(root,'public/javascript/agenda_reunion.js'),'utf8');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'chrome'});let count=0;
 try{
  for(const scenario of [{status:500,body:''},{status:200,body:''},{status:502,body:'<html>Error de alojamiento</html>'},{status:200,body:'null'},
   {status:500,body:JSON.stringify({ok:false,mensaje:'Error controlado. Referencia: prueba.'})},{status:409,body:JSON.stringify({ok:false,mensaje:'La solicitud ya no está pendiente.'})},
   {status:401,body:''}]){
   const p=await browser.newPage();const errors=[];let posts=0;p.on('pageerror',e=>errors.push(e.message));
   await p.route('**/*',route=>{
    const r=route.request();
    if(r.method()==='POST'){posts++;assert.match(r.url(),/action=confirmar/);return route.fulfill({status:scenario.status,body:scenario.body,contentType:'application/json'});}
    if(r.url().includes('agenda_comentarios.'))return route.fulfill({body:''});
    return route.fulfill({body:'<!doctype html><html><head><meta charset="utf-8"><style>'+css+'</style><script>'+boot+'</script></head><body>'+view+'<script>'+source+'</script></body></html>',contentType:'text/html'});
   });
   await p.goto('http://agenda-json.test/index.php');
   await p.locator('.agenda-calendar-grid [data-agenda-meeting="31"]').click();
   const form=p.locator('[data-agenda-action="confirmar"]');await form.waitFor({state:'visible'});
   await form.locator('[name=zoom_url]').fill('https://zoom.us/j/prueba');
   await form.locator('[name=notas_kam]').fill('Conservar nota ante error');
   await form.locator('[type=submit]').click();
   const error=form.locator('.agenda-form-error');await error.waitFor({state:'visible'});
   assert.doesNotMatch(await error.textContent(),/Unexpected|JSON input|execute 'json'/i);
   assert.equal(await form.locator('[name=notas_kam]').inputValue(),'Conservar nota ante error');
   assert.equal(await form.locator('[type=submit]').isEnabled(),true);
   await p.waitForTimeout(400);assert.equal(posts,1,'Nunca reintenta automáticamente una confirmación ambigua');
   assert.deepEqual(errors,[]);await p.close();count++;
  }
  console.log('OK: '+count+' respuestas vacías/HTML/JSON inválido y errores controlados; sin SyntaxError, conserva formulario y no repite POST.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
