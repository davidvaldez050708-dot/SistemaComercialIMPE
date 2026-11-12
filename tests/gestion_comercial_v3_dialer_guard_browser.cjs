/* Prohibición real: ningún WebRTC antes del POST de autorización. */
const assert=require('node:assert/strict');
const path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const js=path.join(__dirname,'../public/javascript/telefonia_marcador.js');
const origin='http://ventas-guard.test';

const selectors=[
 'dial-hangup','dial-mute','dial-mute-label','dial-mute-icon','dial-new',
 'aftercall','aftercall-save','assignment-warning','extension-identity',
 'dial-status','contact-name','contact-number','contact-id','contact-email',
 'contact-note','contact-next','contact-cancel','contacts-list',
 'contacts-empty','contact-status','contact-save','contact-search',
 'contact-search-clear','contacts-no-results','contacts-count',
 'contact-pagination','contact-pages','contact-prev','sales-result-block',
 'sales-result-select','sales-result-submit','sales-result-feedback',
 'sales-contact-editor','sales-open-hub','contact-from-dialer'
];
const inputNames=new Set(['contact-name','contact-number','contact-id','contact-email',
 'contact-note','contact-next','contact-search']);
const buttonNames=new Set(['dial-hangup','dial-mute','dial-new','aftercall-save',
 'contact-cancel','contact-save','contact-search-clear','contact-prev',
 'sales-result-submit','sales-open-hub','contact-from-dialer']);
const items=selectors.map(x=>{
  const sel=x.startsWith('sales-')?'data-'+x:'data-telephony-'+x;
  const tag=inputNames.has(x)?'input':buttonNames.has(x)?'button':'div';
  return '<'+tag+' '+sel+(tag==='button'?' type="button"':'')+'></'+tag+'>';
}).join('\n');
const html=`<!doctype html><html><head><meta charset="utf-8"></head><body>
<main data-telephony-dialer data-call-start-url="/start" data-call-bind-url="/bind"
 data-csrf-token="csrf-no-contactar" data-can-call="1">
<form data-telephony-dialer-form>
<input data-telephony-dial-number value="7771234567">
<button data-telephony-dial-call type="submit">Llamar</button>
</form>
<form data-telephony-contact-form></form>
${items}
</main>
<script src="/marcador.js"></script></body></html>`;

async function test(mode) {
  const browser=await chromium.launch({executablePath:process.env.CHROME_PATH||undefined});
  const page=await browser.newPage();
  let requests=0,approved=false;
  const errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.addInitScript(()=>{
    window.__calls=[];
    window.IMPE_TELEPHONY_PERSISTENT={
      getState:()=>({active:false,phase:'ready'}),
      subscribe:()=>{},
      probe:async()=>({extension:'150',permite_salientes:true}),
      startCall:async info=>{window.__calls.push(info);}
    };
  });
  await page.route(origin+'/**',async route=>{
    const req=route.request(),url=new URL(req.url());
    if(url.pathname==='/marcador.js')return route.fulfill({path:js});
    if(url.pathname==='/start'){
      requests++;
      const body=new URLSearchParams(req.postData()||'');
      assert.equal(req.method(),'POST');
      assert.equal(body.get('csrf_token'),'csrf-no-contactar');
      assert.equal(body.get('destino'),'527771234567');
      assert.match(body.get('token')||'',/^[a-f0-9]{32}$/);
      await new Promise(resolve=>setTimeout(resolve,120));
      if(mode==='bloqueada')return route.fulfill({status:409,json:{ok:false,
        mensaje:'El destinatario solicitó No contactar.'}});
      if(mode==='falla')return route.fulfill({status:503,json:{ok:false,
        mensaje:'No se pudo verificar la autorización.'}});
      approved=true;
      return route.fulfill({json:{ok:true,token:body.get('token')}});
    }
    return route.fulfill({body:html,contentType:'text/html'});
  });
  try {
    await page.goto(origin);
    await page.locator('[data-telephony-dial-call]:not(:disabled)').waitFor();
    await page.getByRole('button',{name:'Llamar'}).click();
    await page.waitForTimeout(35);
    assert.equal((await page.evaluate(()=>window.__calls)).length,0,
      'NO iniciar WebRTC mientras está pendiente el permiso');
    await page.waitForFunction((blocked)=>blocked
      ?document.querySelector('[data-telephony-dial-status]')?.textContent?.includes(
        blocked==='bloqueada'?'No contactar':'No se pudo verificar')
      :window.__calls.length===1,mode==='permitida'?false:mode);
    const calls=await page.evaluate(()=>window.__calls);
    assert.equal(requests,1);
    if(mode!=='permitida'){
      assert.equal(calls.length,0,'bloqueo denegado/falla: no llamar jamás');
      assert.equal(approved,false);
    }else {
      assert.equal(approved,true);
      assert.equal(calls.length,1,'una llamada con aprobación');
      assert.equal(calls[0].context.type,'DIALER');
      assert.match(calls[0].context.venta_intento,/^[a-f0-9]{32}$/);
    }
    assert.deepEqual(errors,[],'sin errores del navegador');
    console.log('CHECK_OK: WebRTC espera autorización y respeta',mode);
  }finally{await browser.close();}
}
(async()=>{for(const m of ['bloqueada','falla','permitida'])await test(m);})()
  .catch(err=>{console.error(err);process.exitCode=1;});
