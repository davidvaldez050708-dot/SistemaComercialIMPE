// Shell y cliente persistente reales; sin conexión al proveedor ni credenciales.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const repo=path.resolve(__dirname,'..'),read=p=>fs.readFileSync(path.join(repo,p),'utf8');
const shell=cp.execFileSync('php',[path.join(__dirname,'fixtures/telefonia_ventas_shell.php')],{encoding:'utf8'});
const view=`<!doctype html><meta charset="utf-8"><script>window.IMPE_CURRENT_USER_ID=8;window.IMPE_CURRENT_ROLE_ID=3;window.IMPE_CAN_CALL_OUT=true;</script><body><main class="admin-shell"><button data-telephony-reception-toggle><span data-telephony-reception-indicator></span></button></main><script>${read('public/javascript/telefonia_persistente.js')}</script></body>`;
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});try{
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://ventas.test/**',route=>{const u=new URL(route.request().url());
  if(u.pathname.startsWith('/public/javascript/'))return route.fulfill({contentType:'application/javascript',body:read(u.pathname.slice(1))});
  if(u.searchParams.get('action')==='estadoUsuario')return route.fulfill({status:503,json:{ok:false,mensaje:'Fallo transitorio de consulta'}});
  if(u.searchParams.get('action')==='disponibilidad')return route.fulfill({json:{ok:true,entrante:null,devoluciones:0}});
  return route.fulfill({contentType:'text/html',body:u.pathname==='/'?shell:u.searchParams.get('action')==='host'?'<html>Transporte simulado</html>':view});
 });
 await page.goto('http://ventas.test/');const crm=page.frameLocator('#impeCrmFrame'),button=crm.locator('[data-telephony-reception-toggle]');
 await button.waitFor();await page.waitForFunction(()=>document.querySelector('[data-shell-engine]'));
 await page.evaluate(()=>{const s={userId:8,updatedAt:Date.now(),hostReady:true,phase:'ready',active:false};localStorage.setItem('impe:telephony:state:8',JSON.stringify(s));dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:8',newValue:JSON.stringify(s)}));});
 await button.evaluate(el=>el.dataset.seen='1');
 await page.waitForFunction(()=>document.getElementById('impeCrmFrame').contentDocument.querySelector('[data-telephony-reception-toggle]').classList.contains('is-active'));
 assert.equal(await button.isDisabled(),false);assert.equal(await page.evaluate(()=>IMPE_TELEPHONY_SHELL.getReceptionStatus().extension),'150');
 await button.click();await page.locator('[data-impe-dialer].is-open').waitFor({state:'visible'});
 // Un latido vencido debe apagar el indicador aunque se conserve el último estado.
 await page.waitForFunction(()=>!document.getElementById('impeCrmFrame').contentDocument.querySelector('[data-telephony-reception-toggle]').classList.contains('is-active'),{},{timeout:13000});
 assert.equal(await page.evaluate(()=>IMPE_TELEPHONY_SHELL.isPhoneReady()),false);
 assert.deepEqual(errors,[]);console.log('Ventas/topbar: shell real, consulta secundaria fallida, extensión 150 y expiración del latido: OK');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
