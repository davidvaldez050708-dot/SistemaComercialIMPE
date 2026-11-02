'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),zlib=require('node:zlib');
const {chromium}=require('playwright');
const spec=JSON.parse(fs.readFileSync('tools/compatibilidad_shell_release.json','utf8'));
const baseline=zlib.gunzipSync(Buffer.from(spec.files[0].variants[0].before,'base64')).toString();
const current=fs.readFileSync('public/css/telefonia_shell.css','utf8');
// Modelo acotado del parser CSS de Chrome 80-86: se ignora inset y dvh.
// No simula una versión antigua de JavaScript ni cambia el user agent.
const legacy=css=>css.replace(/\binset\s*:[^;{}]+;/g,'')
 .replace(/@supports not \(height:\s*100dvh\)/g,'@media all')
 .replace(/(?:max-|min-)?height\s*:[^;{}]*dvh[^;{}]*;/g,'');
const child=`<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>
*{box-sizing:border-box}body{margin:0;font:14px Arial;background:#f3f6fb;color:#182b4d}
header{height:80px;background:white;padding:20px}aside{position:fixed;left:0;top:0;width:270px;height:100%;background:#16223b;color:white;padding:24px}
main{margin-left:270px}section{margin:24px;padding:24px;background:white;border:1px solid #dce5f1;border-radius:16px}
.mobile{display:none}@media(max-width:991.98px){aside{display:none}main{margin-left:0}.mobile{display:inline}}
</style></head><body><aside>APG<br><br>Inicio<br><br>Seguimiento</aside><main><header><span class="mobile">☰ </span>Inicio</header><section><h2>Seguimiento territorial</h2><p>Contenido de prueba del marco del CRM.</p>${'<p>Registro de seguimiento</p>'.repeat(70)}</section></main></body></html>`;
const html=css=>`<!doctype html><html data-impe-telephony-shell="1"><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>${css}</style></head><body><main class="impe-phone-shell"><iframe id="impeCrmFrame" title="CRM"></iframe><div class="impe-shell-navigation-mask" hidden>Recuperando módulo</div></main><aside class="impe-phone-call"><iframe title="Motor de prueba"></iframe></aside></body></html>`;
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  fs.mkdirSync('tmp/shell-compat-qa',{recursive:true});
  let runs=0;
  const render=async(css,width,height,scale=1)=>{
   const context=await browser.newContext({viewport:{width,height},deviceScaleFactor:scale});
   const page=await context.newPage();await page.setContent(html(css));
   await page.locator('#impeCrmFrame').evaluate((el,child)=>el.srcdoc=child,child);
   await page.waitForFunction(()=>document.getElementById('impeCrmFrame').contentDocument?.querySelector('h2'));
   return {page,context,frame:page.frames().find(f=>f.parentFrame()===page.mainFrame()&&f.name()!=='')||page.frames()[1]};
  };
  const dimensions=page=>page.evaluate(()=>{
   const container=document.querySelector('.impe-phone-shell'),frame=document.getElementById('impeCrmFrame'),r=frame.getBoundingClientRect(),c=container.getBoundingClientRect();
   return {width:r.width,height:r.height,left:r.left,top:r.top,parentWidth:c.width,parentHeight:c.height,childWidth:frame.contentWindow.innerWidth,childHeight:frame.contentWindow.innerHeight,mobile:frame.contentWindow.matchMedia('(max-width:991.98px)').matches};
  });
  const broken=await render(legacy(baseline),1366,768);
  const small=await dimensions(broken.page);
  assert(small.childWidth<992,'La versión publicada reproduce el marco móvil en una pantalla de PC al ignorar inset');
  assert(small.width<1366);console.log('ANTES, sin inset:',JSON.stringify(small));
  await broken.page.screenshot({path:'tmp/shell-compat-qa/antes-legacy.png'});await broken.context.close();
  for(const profile of ['modern','legacy'])for(const [width,height,scale] of [[1366,768,1],[1280,720,1],[1024,768,1],[1280,720,1.25],[768,1024,1],[499,816,1],[375,667,2],[320,568,1]]){
   const {page,context}=await render(profile==='legacy'?legacy(current):current,width,height,scale);
   const d=await dimensions(page);
   assert.equal(d.width,width);assert.equal(d.height,height);assert.equal(d.childWidth,width);assert.equal(d.childHeight,height);
   assert.equal(d.left,0);assert.equal(d.top,0);assert.equal(d.mobile,width<=991.98,'Responsive depende del ancho real del marco');
   const geometry=await page.evaluate(()=>{const mask=document.querySelector('.impe-shell-navigation-mask');mask.hidden=false;const r=mask.getBoundingClientRect();mask.hidden=true;const host=document.querySelector('.impe-phone-call');host.classList.add('is-visible');const h=host.getBoundingClientRect();return {mask:{width:r.width,height:r.height,left:r.left,top:r.top},host:{width:h.width,height:h.height}};});
   assert.deepEqual(geometry.mask,{width,height,left:0,top:0});
   assert(geometry.host.height>100 && geometry.host.height<=height-12,'El motor oculto conserva un viewport útil sin dvh');
   assert(geometry.host.width<=width && geometry.host.width>100);
   const crm=page.frames().find(f=>f.url()==='about:srcdoc');assert(crm);
   assert.equal(await crm.evaluate(()=>document.documentElement.scrollHeight>innerHeight),true,'Se conserva el desplazamiento dentro del módulo');
   await crm.evaluate(()=>scrollTo(0,500));assert((await crm.evaluate(()=>scrollY))>0);
   await crm.evaluate(()=>scrollTo(0,0));
   await page.locator('.impe-phone-call').evaluate(el=>el.classList.remove('is-visible'));
   if(profile==='legacy'&&width===1366)await page.screenshot({path:'tmp/shell-compat-qa/despues-legacy.png'});
   if(profile==='modern'&&width===375)await page.screenshot({path:'tmp/shell-compat-qa/movil.png'});
   runs++;await context.close();
  }
  console.log('PASS: regresión del iframe intrínseco, '+runs+' combinaciones de escritorio/móvil y densidad, máscara completa, altura de teléfono y scroll interno.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
