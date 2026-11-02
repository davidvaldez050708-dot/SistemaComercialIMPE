'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),zlib=require('node:zlib');
const {chromium}=require('playwright');
const manifest=JSON.parse(fs.readFileSync('tools/telefono_flotante_compat_release.json','utf8'));
const before=name=>zlib.gunzipSync(Buffer.from(manifest.files.find(r=>r.path===name).variants[0].before,'base64')).toString();
const css=fs.readFileSync('public/css/telefonia_teclado.css','utf8'),js=fs.readFileSync('public/javascript/telefonia_teclado.js','utf8');
const shell=fs.readFileSync('public/css/telefonia_shell.css','utf8');
const persistent=fs.readFileSync('public/css/telefonia_persistente.css','utf8');
// Modelo de reglas CSS que Chrome 86 ignora. El motor JS es Chrome actual.
const legacy=css=>css.replace(/\binset\s*:[^;{}]+;/g,'')
 .replace(/@supports not \((?:max-)?height:\s*100dvh\)/g,'@media all')
 .replace(/@supports not selector\(:has\(\*\)\)/g,'@media all')
 .replace(/(?:max-|min-)?height\s*:[^;{}]*dvh[^;{}]*;/g,'')
 .replace(/[^{}]*:has\([^{}]*\)\s*\{[^{}]*\}/g,'');
const bounds=el=>{const r=el.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom};};
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  fs.mkdirSync('tmp/telefono-compat-qa',{recursive:true});
  async function setup({width=1366,height=768,profile='legacy',mode='shell',extension='100',baseline=false,single=true,allowed=true}={}){
   const context=await browser.newContext({viewport:{width,height}}),page=await context.newPage(),errors=[];
   page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/*',r=>r.abort());
   const cssText=(profile==='legacy'?legacy:x=>x)(shell+persistent+(baseline?before('public/css/telefonia_teclado.css'):css));
   await page.setContent(`<!doctype html><html ${mode==='shell'?'data-impe-telephony-shell="1"':''}><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${cssText}</style></head><body>${mode==='shell'?'<main class="impe-phone-shell"><iframe id="impeCrmFrame" title="CRM"></iframe></main>':'<main>CRM clásico</main>'}</body></html>`);
   await page.addScriptTag({content:baseline?before('public/javascript/telefonia_teclado.js'):js});
   await page.evaluate(({extension,single,allowed})=>{
    window.ops=[];window.callState={active:false};
    const api={probe:async()=>({permite_salientes:true}),startCall:async p=>ops.push(['start',p]),toggleMute:()=>ops.push(['mute']),hangup:()=>ops.push(['hangup']),transferCall:async(...p)=>ops.push(['transfer',...p])};
    window.dial=IMPE_TELEPHONY_DIALER.create({extension,getApi:()=>api,getState:()=>callState,hasOutgoingRole:()=>allowed,singleCallPanel:single,onCallRequested:p=>ops.push(['requested',p]),showIncoming:()=>ops.push(['incoming']),showOngoing:()=>ops.push(['ongoing'])});
    dial.open({number:'55 1234 5678',context:{type:'MARKETING_CALLBACK',llamada_origen:'origin-test'}});
   },{extension,single,allowed});
   const dialog=page.locator('.impe-dialer-dialog'),header=page.locator('.impe-dialer-heading');
   return {page,context,errors,dialog,header,rect:()=>dialog.evaluate(bounds)};
  }
  const old=await setup({baseline:true});const bad=await old.rect();
  assert(bad.x<0 || bad.y<0,'El CSS publicado reproduce el marcador recortado al ignorar inset');
  console.log('ANTES sin inset:',JSON.stringify(bad));await old.page.screenshot({path:'tmp/telefono-compat-qa/antes.png'});await old.context.close();
  let cases=0;
  for(const profile of ['legacy','modern'])for(const [width,height,extension,mode] of [[1366,768,'100','shell'],[1024,600,'101','shell'],[1280,720,'150','shell'],[499,816,'100','classic'],[375,667,'150','shell'],[320,480,'101','classic']]){
   const t=await setup({profile,width,height,extension,mode});const {page}=t;
   await page.waitForFunction(()=>!document.querySelector('[data-dialer-call]').disabled);
   let r=await t.rect();assert(r.x>=0 && r.y>=0 && r.right<=width && r.bottom<=height);
   assert(Math.abs(r.x+r.width/2-width/2)<2,'Marcador centrado horizontalmente');
   const wrapper=await page.locator('.impe-dialer').evaluate(bounds);assert.equal(wrapper.width,width);assert.equal(wrapper.height,height);
   const h=await t.header.boundingBox();await page.mouse.move(h.x+80,h.y+20);await page.mouse.down();await page.mouse.move(width-2,height-2,{steps:4});await page.mouse.up();
   r=await t.rect();assert(r.x>=0 && r.y>=0 && r.right<=width && r.bottom<=height,'Arrastre conserva el panel dentro del viewport');
   const dragged=await t.header.boundingBox();await page.mouse.move(dragged.x+80,dragged.y+20);await page.mouse.down();await page.mouse.move(0,0,{steps:4});await page.mouse.up();
   const corner=await t.rect();assert(corner.x>=0 && corner.y>=0);assert(corner.x<r.x || corner.y<r.y,'El marcador realmente se mueve');
   assert.equal(await page.locator('[data-dialer-input]').inputValue(),'55 1234 5678');
   assert.deepEqual(await page.evaluate(()=>ops),[],'Abrir y mover no ejecuta ninguna operación telefónica');
   if(width===1366){
    await page.setViewportSize({width:800,height:400});await page.waitForFunction(()=>document.querySelector('.impe-dialer-dialog').getBoundingClientRect().bottom<=400);
    const shrunk=await t.rect();assert(shrunk.right<=800 && shrunk.y>=0);
    await page.setViewportSize({width,height});
    await page.locator('[data-dialer-close]').click();assert.equal(await page.locator('.impe-dialer').evaluate(el=>el.hidden),true,'Cerrar sigue funcionando sin iniciar arrastre');
    await page.evaluate(()=>dial.open({number:'55 1234 5678'}));
    await page.waitForFunction(()=>!document.querySelector('[data-dialer-call]').disabled);
    if(profile==='legacy')await page.screenshot({path:'tmp/telefono-compat-qa/despues.png'});
   }
   await page.locator('[data-dialer-call]').scrollIntoViewIfNeeded();await page.locator('[data-dialer-call]').click();
   await page.waitForFunction(()=>ops.some(x=>x[0]==='start'));
   const ops=await page.evaluate(()=>window.ops);assert.equal(ops.filter(x=>x[0]==='start').length,1);assert.equal(ops.find(x=>x[0]==='start')[1].destination,'525512345678');
   assert.equal(ops.filter(x=>x[0]==='requested').length,1);assert.equal(await page.locator('.impe-dialer').evaluate(el=>el.hidden),true,'Entrega al panel de llamada único');
   assert.deepEqual(t.errors,[]);cases++;await t.context.close();
  }
  // Respaldo clásico durante conversación: mover, minimizar/restaurar,
  // transferir, silenciar y colgar siguen usando la API existente una vez.
  const classic=await setup({single:false,mode:'classic'});const p=classic.page;
  await p.evaluate(()=>{callState={active:true,direction:'outgoing',phase:'in-progress',status:'in-progress',destination:'525512345678'};dial.sync(callState);});
  const activeBefore=await classic.rect(),activeHeader=await classic.header.boundingBox();
  await p.mouse.move(activeHeader.x+80,activeHeader.y+20);await p.mouse.down();await p.mouse.move(250,200,{steps:4});await p.mouse.up();
  const activeMoved=await classic.rect();assert(activeMoved.x!==activeBefore.x || activeMoved.y!==activeBefore.y,'También se puede mover el respaldo clásico en conversación');
  assert.deepEqual(await p.evaluate(()=>ops),[],'Arrastrar en conversación no silencia ni cuelga');
  await p.locator('[data-dialer-mute]').click();await p.locator('[data-dialer-transfer]').click();await p.locator('[data-dialer-transfer-input]').fill('101');await p.locator('[data-dialer-transfer-direct]').click();
  await p.locator('[data-dialer-minimize]').click();await p.locator('[data-dialer-restore]').click();await p.locator('[data-dialer-hangup]').click();
  assert.deepEqual(await p.evaluate(()=>ops),[['mute'],['transfer','101',false],['hangup']]);assert.deepEqual(classic.errors,[]);await classic.context.close();
  const restricted=await setup({allowed:false});assert.equal(await restricted.page.locator('[data-dialer-call]').isDisabled(),true);assert.deepEqual(await restricted.page.evaluate(()=>ops),[]);await restricted.context.close();
  // Botones de resultado y datos sin :has(), con todas sus combinaciones.
  const ctx=await browser.newContext({viewport:{width:375,height:667}}),page=await ctx.newPage();
  await page.setContent('<html><head><style>'+legacy(shell+persistent)+'</style></head><body><section class="persistent-phone-panel impe-incoming-panel" style="left:8px;top:8px;right:auto;bottom:auto"><div class="persistent-phone-actions"><button data-sales-open>Registrar resultado</button><button data-sales-prospect>Datos del prospecto</button><button data-incoming-close-finished>Cerrar</button></div></section></body></html>');
  for(const visible of [[true,true],[true,false],[false,true]]){
   await page.evaluate(([a,b])=>{document.querySelector('[data-sales-open]').hidden=!a;document.querySelector('[data-sales-prospect]').hidden=!b;},visible);
   const rows=await page.locator('.persistent-phone-actions > button:not([hidden])').evaluateAll(es=>es.map(e=>{const r=e.getBoundingClientRect();return {x:r.x,y:r.y,right:r.right,bottom:r.bottom};}));
   for(let i=0;i<rows.length;i++){assert(rows[i].x>=0 && rows[i].right<=375);for(let j=i+1;j<rows.length;j++)assert(rows[j].y>=rows[i].bottom || rows[i].y>=rows[j].bottom,'Sin superposición de acciones');}
  }
  await ctx.close();console.log('PASS: '+cases+' casos de rol/tamaño/modo, centrado, arrastre, resize, cierre, permisos, llamada única y controles clásicos; acciones de Ventas legibles sin :has().');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
