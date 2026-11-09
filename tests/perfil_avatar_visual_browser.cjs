// PHP/JS reales, imágenes y API ficticias. Nunca usa producción.
const {chromium}=require(process.env.IMPE_PLAYWRIGHT_PATH||'playwright');
const fs=require('fs'),path=require('path'),cp=require('child_process'),assert=require('node:assert/strict'),zlib=require('zlib');
const root=process.cwd(),out=path.join(root,'tmp/perfil_avatar_visual');fs.mkdirSync(out,{recursive:true});
const png=(w,h)=>{const crc=b=>{let n=0xffffffff;for(const v of b){n^=v;for(let k=0;k<8;k++)n=(n>>>1)^((n&1)?0xedb88320:0);}return (n^0xffffffff)>>>0;};
 const chunk=(name,b)=>{const t=Buffer.from(name),n=Buffer.alloc(4),c=Buffer.alloc(4);n.writeUInt32BE(b.length);c.writeUInt32BE(crc(Buffer.concat([t,b])));return Buffer.concat([n,t,b,c]);};
 const ih=Buffer.alloc(13);ih.writeUInt32BE(w);ih.writeUInt32BE(h,4);ih[8]=8;ih[9]=2;const rows=[];
 for(let y=0;y<h;y++){const row=Buffer.alloc(1+w*3);for(let x=0;x<w;x++){const rgb=x<w/3?[39,58,138]:x>w*2/3?[10,143,122]:[242,192,82];for(let c=0;c<3;c++)row[1+x*3+c]=rgb[c];}rows.push(row);}
 return Buffer.concat([Buffer.from([137,80,78,71,13,10,26,10]),chunk('IHDR',ih),chunk('IDAT',zlib.deflateSync(Buffer.concat(rows))),chunk('IEND',Buffer.alloc(0))]);};
const images={'horizontal.png':png(360,120),'vertical.png':png(120,360)};
const fakeRoot=path.join(out,'fixture'),uploads=path.join(fakeRoot,'public/uploads/usuarios');fs.mkdirSync(uploads,{recursive:true});
for(const [name,bytes]of Object.entries(images))fs.writeFileSync(path.join(uploads,name),bytes);
const php=`define('ROOT_PATH',$argv[2]);define('BASE_URL','https://perfil-qa.test/');require $argv[1].'/app/helpers/AvatarHelper.php';
echo '<header class="admin-topbar"><button class="topbar-account-button">';echo renderAvatarUsuario('Diana','marke','Marketing','','sm','general','topbar-user-avatar',false);echo '</button><a href="#" data-my-profile-open>Mi perfil</a></header>';
echo '<main class="admin-content"><section class="dashboard-panel"><div class="sidebar-user">';echo renderAvatarUsuario('Diana','marke','Marketing','','sm','general','sidebar-user-avatar');echo '<div style="color:#252525">Diana marke<br>Marketing</div></div><div class="qa-photos">';
foreach(['horizontal.png','vertical.png'] as $f){echo '<div class="sidebar-user">';echo renderAvatarUsuario('Diana','marke','Marketing','public/uploads/usuarios/'.$f,'sm');echo '<span>'.$f.'</span></div>';}
echo '</div><div class="qa-outside">';echo renderAvatarUsuario('Diana','marke','','','lg');echo '<input type="file" id="archivo_ajeno" class="form-control"></div></section></main>';
require $argv[1].'/app/views/layout/my_profile_modal.php';require $argv[1].'/app/views/layout/profile_photo_modal.php';`;
const rendered=cp.spawnSync('C:/xampp/php/php.exe',['-r',php,root,fakeRoot],{encoding:'utf8'});assert.equal(rendered.status,0,rendered.stderr);
const footer=fs.readFileSync(path.join(root,'app/views/layout/dashboard_footer.php'),'utf8');
const photoScript=footer.match(/<script>\s*(document\.addEventListener\('DOMContentLoaded',[\s\S]*?)<\/script>/)[1];
const profile={nombre:'Diana',apellidos:'marke',telefono:'',correo:'qa@example.test',usuario:'qa',rol:'Marketing',estado:'Activo',iniciales:'DM',foto_url:'',es_administrador:false};
const html=baseline=>'<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+
 '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">'+
 '<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">'+
 ['global.css','dashboard.css','responsive_sistema.css'].map(f=>'<link rel="stylesheet" href="/public/css/'+f+'?baseline='+baseline+'">').join('')+
 '<style>.qa-photos{display:flex;gap:24px;margin:24px 0}.qa-outside{margin-top:20px}.sidebar-user{max-width:300px;color:#252525}.admin-topbar{position:static}.admin-content{padding:16px}</style>'+
 '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><script src="/public/javascript/mi_perfil.js"></script></head><body>'+rendered.stdout+'<script>'+photoScript+'</script></body></html>';
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.IMPE_CHROME_PATH});try{
 const page=await browser.newPage();let updates=0;const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://perfil-qa.test/**',async route=>{const u=new URL(route.request().url());
  if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:html(u.searchParams.get('baseline')||'0')});
  if(u.pathname==='/index.php'){
   if(u.searchParams.get('action')==='obtenerMiPerfil')return route.fulfill({json:{ok:true,perfil:profile}});
   if(u.searchParams.get('action')==='actualizarMiPerfil'){updates++;const body=route.request().postDataBuffer();assert.ok(body.includes(Buffer.from('name="foto_perfil"')));assert.ok(body.includes(images['vertical.png']));
    return route.fulfill({json:{ok:true,mensaje:'Actualización ficticia',perfil:{...profile,foto_url:'https://perfil-qa.test/public/uploads/usuarios/vertical.png'}}});}
   return route.fulfill({status:400});
  }
  if(u.pathname.startsWith('/public/uploads/usuarios/'))return images[path.basename(u.pathname)]?route.fulfill({contentType:'image/png',body:images[path.basename(u.pathname)]}):route.fulfill({status:404});
  if(u.pathname==='/public/css/global.css'&&u.searchParams.get('baseline')==='1')return route.fulfill({contentType:'text/css',body:cp.execFileSync('git',['show','b7b25125:public/css/global.css'])});
  const p=path.join(root,u.pathname);if(!p.startsWith(root)||!fs.existsSync(p))return route.fulfill({status:404});
  return route.fulfill({contentType:p.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(p)});
 });
 const geometry=()=>page.evaluate(()=>Array.from(document.querySelectorAll('.topbar-user-avatar,.sidebar-user > .system-avatar,.qa-outside > *')).map(e=>{
  const s=getComputedStyle(e);return [e.className,s.width,s.height,s.borderRadius,s.backgroundColor,s.color];}));
 const report=[];
 for(const [name,width,height]of [['escritorio',1440,1000],['tableta',820,1180],['movil',390,844]]){
  await page.setViewportSize({width,height});await page.goto('https://perfil-qa.test/?baseline=1',{waitUntil:'networkidle'});await page.evaluate(()=>document.fonts.ready);const before=await geometry();
  await page.goto('https://perfil-qa.test/',{waitUntil:'networkidle'});await page.evaluate(()=>document.fonts.ready);assert.deepEqual(await geometry(),before,name+' medidas/colores y componentes externos');
  const initials=await page.locator('.system-avatar-initials').evaluateAll(es=>es.filter(e=>!e.closest('.qa-outside')).map(e=>{const s=getComputedStyle(e),r=e.getBoundingClientRect(),range=document.createRange();range.selectNodeContents(e);const t=range.getBoundingClientRect();return {font:s.fontSize,family:s.fontFamily,line:s.lineHeight,dx:Math.abs(t.x+t.width/2-r.x-r.width/2),dy:Math.abs(t.y+t.height/2-r.y-r.height/2)};}));
  for(const m of initials){assert.equal(m.font,'14px');assert.match(m.family,/Manrope/);assert.ok(m.dx<1&&m.dy<4,JSON.stringify(m));}
  const photos=await page.locator('.qa-photos img').evaluateAll(es=>es.map(e=>{const s=getComputedStyle(e),r=e.getBoundingClientRect(),p=e.parentElement.getBoundingClientRect();return {fit:s.objectFit,position:s.objectPosition,width:r.width,height:r.height,pw:p.width,ph:p.height,nw:e.naturalWidth,nh:e.naturalHeight};}));
  for(const m of photos){assert.equal(m.fit,'cover');assert.equal(m.position,'50% 50%');assert.equal(m.width,m.height);assert.ok(m.width<=m.pw&&m.height<=m.ph);assert.ok(m.nw>0&&m.nh>0);}
  await page.screenshot({path:path.join(out,name+'-avatares.png')});
  await page.locator('[data-my-profile-open]').click();await page.locator('[data-my-profile-preview] > strong').waitFor();
  await page.waitForFunction(()=>getComputedStyle(document.querySelector('#modalMiPerfil')).opacity==='1'&&getComputedStyle(document.querySelector('#modalMiPerfil .modal-dialog')).transform==='none');
  const preview=await page.locator('[data-my-profile-preview]').evaluate(e=>{const s=getComputedStyle(e);return {width:s.width,height:s.height,radius:s.borderRadius,text:e.textContent.trim()};});assert.equal(preview.width,preview.height);assert.equal(preview.radius,'50%');assert.equal(preview.text,'DM');
  const centered=await page.locator('[data-my-profile-preview]').evaluate(e=>{const r=e.getBoundingClientRect(),t=e.firstElementChild.getBoundingClientRect();return {dx:Math.abs(t.x+t.width/2-r.x-r.width/2),dy:Math.abs(t.y+t.height/2-r.y-r.height/2)};});assert.ok(centered.dx<1&&centered.dy<1);
  await page.screenshot({path:path.join(out,name+'-iniciales.png')});
  const file=page.locator('#mi_perfil_foto'),fileBefore=await file.evaluate(e=>({type:e.type,accept:e.accept,name:e.name}));
  assert.deepEqual(fileBefore,{type:'file',accept:'image/jpeg,image/png,image/webp',name:'foto_perfil'});
  await file.setInputFiles({name:'fotografia_de_perfil_nombre_largo_sin_modificar_12345678901234567890.png',mimeType:'image/png',buffer:images['horizontal.png']});
  await page.waitForFunction(()=>document.querySelector('[data-my-profile-preview] img')?.naturalWidth===360);
  await file.scrollIntoViewIfNeeded();await file.focus();
  const sizes=await file.evaluate(e=>{const s=getComputedStyle(e),b=getComputedStyle(e,'::file-selector-button'),r=e.getBoundingClientRect();return {height:s.height,padding:s.padding,buttonHeight:b.height,buttonLine:b.lineHeight,buttonMargin:b.marginTop,file:e.files[0].name,width:r.width,space:innerWidth-r.right,overflow:document.documentElement.scrollWidth>innerWidth};});
  assert.equal(sizes.height,'44px');assert.equal(sizes.buttonHeight,'42px');assert.equal(sizes.buttonLine,'42px');assert.equal(sizes.buttonMargin,'0px');assert.ok(sizes.file.length>70);assert.ok(sizes.width>200&&sizes.space>=0);assert.equal(sizes.overflow,false);
  await page.screenshot({path:path.join(out,name+'-selector.png')});
  await file.setInputFiles({name:'vertical.png',mimeType:'image/png',buffer:images['vertical.png']});await page.waitForFunction(()=>document.querySelector('[data-my-profile-preview] img')?.naturalHeight===360);
  await file.setInputFiles([]);await page.locator('[data-my-profile-preview] > strong').waitFor();assert.equal(await page.locator('[data-my-profile-preview]').innerText(),'DM');
  report.push({name,initials,photos,preview,sizes});
  await page.locator('#modalMiPerfil [data-bs-dismiss="modal"]').last().click();await page.locator('#modalMiPerfil').waitFor({state:'hidden'});
 }
 // Guardar se comprueba una vez, únicamente con respuesta de API ficticia.
 await page.locator('[data-my-profile-open]').click();await page.locator('[data-my-profile-preview] > strong').waitFor();
 await page.locator('#mi_perfil_foto').setInputFiles({name:'vertical.png',mimeType:'image/png',buffer:images['vertical.png']});
 await Promise.all([page.waitForResponse(r=>r.url().includes('actualizarMiPerfil')),page.locator('[data-my-profile-submit]').click()]);
 await page.waitForFunction(()=>document.querySelector('.topbar-user-avatar img')?.naturalHeight===360);
 assert.equal(updates,1);assert.equal(await page.locator('#mi_perfil_foto').evaluate(e=>e.files.length),0);
 // Error de imagen: ejercitar el fallback existente del footer, sin reescribirlo.
 await page.locator('.topbar-user-avatar img').evaluate(e=>{e.src='https://perfil-qa.test/public/uploads/usuarios/no-existe.png';});
 await page.locator('.topbar-user-avatar.system-avatar-initials').waitFor();assert.equal(await page.locator('.topbar-user-avatar').innerText(),'DM');
 assert.deepEqual(errors,[]);fs.writeFileSync(path.join(out,'medidas.json'),JSON.stringify(report,null,2));
 console.log('OK: avatares y selección nativa en escritorio/tableta/móvil; fotos horizontales/verticales, iniciales, fallback existente, carga/actualización interceptadas, archivo largo y componentes externos intactos. Sin producción. '+out);
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
