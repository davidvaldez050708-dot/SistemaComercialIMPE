const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),out=path.join(root,'tmp/formularios-logo');fs.mkdirSync(out,{recursive:true});
const views=['publico_registro.php','publico_inscripcion.php','publico_inscripcion_completa.php','inscripcion_ejecutivas_previa.php'];
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});
 try{
  const context=await browser.newContext();
  await context.route('**/*',route=>{
   const u=new URL(route.request().url()),name=path.basename(u.pathname);
   if(process.env.IMPE_FORM_LOGO_PRODUCTION&&name==='formularios_hero_institucional.css')return route.fulfill({contentType:'text/css',body:Buffer.concat([fs.readFileSync(path.join(process.env.IMPE_FORM_LOGO_PRODUCTION,'hero.css')),fs.readFileSync(path.join(root,'tools/formularios_logos_equilibrados.css'))])});
   if(process.env.IMPE_FORM_LOGO_PRODUCTION&&name==='Logo-Sep-Blanco.png')return route.fulfill({contentType:'image/png',body:fs.readFileSync(path.join(process.env.IMPE_FORM_LOGO_PRODUCTION,'sep.png'))});
   if(u.pathname==='/render'){
    const r=cp.spawnSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'fixtures/formularios_logo.php'),u.searchParams.get('vista')],{cwd:root,encoding:'utf8'});
    assert.equal(r.status,0,r.stderr);assert.equal(r.stderr,'');
    return route.fulfill({contentType:'text/html',body:r.stdout.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,'')});
   }
   if(u.pathname.endsWith('/bootstrap.min.css'))return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(process.env.BOOTSTRAP_MODULE_PATH,'dist/css/bootstrap.min.css'))});
   if(u.hostname==='fonts.googleapis.com'&&process.env.IMPE_FORMS_ASSETS)return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(process.env.IMPE_FORMS_ASSETS,'manrope.css'))});
   if(process.env.IMPE_FORMS_ASSETS){const asset=path.join(process.env.IMPE_FORMS_ASSETS,name);if(fs.existsSync(asset)&&fs.statSync(asset).isFile())return route.fulfill({body:fs.readFileSync(asset)});}
   const file=path.resolve(root,'.'+u.pathname);
   if(file.startsWith(root+path.sep)&&fs.existsSync(file)&&fs.statSync(file).isFile())return route.fulfill({contentType:file.endsWith('.css')?'text/css':file.endsWith('.png')?'image/png':'image/jpeg',body:fs.readFileSync(file)});
   return route.abort();
  });
  const page=await context.newPage();
  for(const width of [1752,1440,1024,390,320])for(const view of views){
   await page.setViewportSize({width,height:1000});await page.goto('https://formularios.test/render?vista='+view);await page.evaluate(()=>document.fonts.ready);
   const logo=page.locator('.formulario-publico-hero-logo-principal img');
   if(process.env.IMPE_FORM_LOGO_PRODUCTION){
    await page.evaluate(()=>document.querySelectorAll('.formulario-publico-hero-logo-hlc,.formulario-publico-hero-acuerdo').forEach(el=>{if(el.previousElementSibling?.classList.contains('formulario-publico-hero-logo-separator'))el.previousElementSibling.remove();el.remove();}));
    const painted=await page.locator('.formulario-publico-hero-logo-principal img,.formulario-publico-hero-logo-sep img').evaluateAll(images=>images.map(img=>{
     const c=document.createElement('canvas');c.width=img.naturalWidth;c.height=img.naturalHeight;const x=c.getContext('2d');x.drawImage(img,0,0);const d=x.getImageData(0,0,c.width,c.height).data;let left=c.width,right=0,top=c.height,bottom=0;
     for(let y=0;y<c.height;y++)for(let x=0;x<c.width;x++)if(d[(y*c.width+x)*4+3]>100){left=Math.min(left,x);right=Math.max(right,x);top=Math.min(top,y);bottom=Math.max(bottom,y);}
     const r=img.getBoundingClientRect(),scale=Math.min(r.width/c.width,r.height/c.height);return {h:(bottom-top+1)*scale,left:r.left+(r.width-c.width*scale)/2+left*scale,right:r.left+(r.width-c.width*scale)/2+(right+1)*scale};
    }));
    assert(Math.abs(painted[0].h/painted[1].h-1)<.12,'alturas visibles equilibradas: '+JSON.stringify({width,painted}));
    const divider=await page.locator('.formulario-publico-hero-logo-separator').first().boundingBox();
    const gaps=[divider.x-painted[0].right,painted[1].left-divider.x-divider.width];
    assert(gaps.every(g=>g>=0)&&Math.abs(gaps[0]-gaps[1])<4,'espacios visibles simétricos: '+JSON.stringify({width,gaps}));
   }
   assert.equal(await logo.getAttribute('alt'),'Fundación Red Educativa México');
   assert((await logo.getAttribute('src')).endsWith('/fundacion-red-educativa-mexico-blanco.png'));
   assert.deepEqual(await logo.evaluate(img=>[img.naturalWidth,img.naturalHeight]),[752,332]);
   const alpha=await logo.evaluate(img=>{const c=document.createElement('canvas');c.width=img.naturalWidth;c.height=img.naturalHeight;const x=c.getContext('2d');x.drawImage(img,0,0);return x.getImageData(0,0,1,1).data[3];});assert.equal(alpha,0,'logo conserva transparencia');
   const rect=await logo.boundingBox();assert(rect.width>40&&rect.height>20);
   assert.equal(await page.locator('.formulario-publico-hero-logo-sep img').count(),1,'SEP conservada');
   assert.equal(await page.locator('body').evaluate(e=>e.scrollWidth<=innerWidth+1),true,'sin desbordamiento');
   await page.locator('.formulario-publico-hero').screenshot({path:path.join(out,view+'-'+width+'.png')});
  }
  console.log('CHECK_OK: cuatro vistas reales, escritorio/móvil, logo adjunto transparente y SEP conservada.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
