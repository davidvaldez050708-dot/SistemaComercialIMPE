const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const base=path.join(__dirname,'files');
const source=fs.readFileSync(path.join(base,'public/javascript/agenda_comentarios.js'),'utf8');
const partial=fs.readFileSync(path.join(base,'app/views/seguimiento_vinculacion/partials/agenda_comentarios.php'),'utf8').replace(/<\?php[\s\S]*?\?>/g,'').replace(/<\?=[\s\S]*?\?>/g,'').replace(/<link[^>]*>|<script[\s\S]*?<\/script>/g,'');
const css=fs.readFileSync(path.join(base,'public/css/agenda_destinatarios.css'),'utf8');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 const errors=[];const posts=[];
 try {
  for(const width of [1366,390]) {
   const page=await browser.newPage({viewport:{width,height:900}});
   page.on('pageerror',e=>errors.push(e.message));
   let recipients=[{id:2,nombre:'Ana',rol:'Analista'},{id:3,nombre:'Bea',rol:'Cuenta Clave'}];
   const html=`<!doctype html><html lang="es"><meta charset="utf-8"><style>body{font-family:Arial} dialog{width: min(485px,calc(100vw - 48px));box-sizing:border-box;border:1px solid #dbe4f3;border-radius:12px;padding:18px}dialog::backdrop{background:#eef1f666}form textarea{box-sizing:border-box;width:100%;padding:12px} .agenda-comments-heading{display:flex;justify-content:space-between}.agenda-comments-heading h3{margin:6px 0}.agenda-comments-hint{font-size:13px;color:#7684a0;line-height:1.5}.agenda-comment-hour-label{display:block;margin:14px 0 8px}footer{display:flex;gap:8px;justify-content:flex-end}button{padding:10px}.agenda-comment-privacy{margin:16px 0} ${css}</style><section data-agenda-root><div class="agenda-layout"><aside class="agenda-side-card"></aside><div class="agenda-calendar-grid"><div data-agenda-date="2026-11-24"><div class="agenda-day-events"></div><button data-agenda-comment-add="2026-11-24">Agregar</button></div></div></div>${partial}</section><script>${source}</script></html>`;
   await page.route('http://agenda.test/**',async route=>{
    const url=new URL(route.request().url());
    const action=url.searchParams.get('action');
    if(action==='listar') return route.fulfill({json:{ok:true,comentarios:[],privados_disponibles:true,destinatarios_disponibles:true,multiples_destinatarios_disponibles:true,destinatarios:recipients}});
    if(action==='crear') { posts.push(new URLSearchParams(route.request().postData()));return route.fulfill({json:{ok:true,comentario:{id:21}}}); }
    return route.fulfill({contentType:'text/html',body:html});
   });
   await page.goto('http://agenda.test/');
   const add=page.locator('[data-agenda-comment-add]');await add.waitFor();await add.click();
   const details=page.locator('[data-agenda-recipients]');await details.locator('summary').click();
   const boxes=details.locator('input');
   await boxes.nth(1).check();await boxes.nth(2).check();
   assert.match(await details.locator('summary').textContent(),/Ana, Bea/);
   assert.equal(await boxes.nth(0).isChecked(),false);
   await page.locator('textarea').fill('Comentario para dos usuarios');
   await page.locator('input[name=privado]').check();assert.equal(await boxes.nth(1).isDisabled(),true);
   await page.locator('input[name=privado]').uncheck();await details.locator('summary').click();
   assert.equal(await boxes.nth(1).isChecked(),true);
   assert.equal(await boxes.nth(2).isChecked(),true);
   assert.equal(await page.locator('input[name=sin_hora]').isChecked(),true);
   assert.equal(await page.locator('input[name=hora]').isDisabled(),true);
   await page.locator('[data-agenda-comment-save]').click();await page.waitForFunction(()=>!document.querySelector('dialog').open);
   assert.deepEqual(posts.at(-1).getAll('destinatarios_ids[]'),['2','3']);
   assert.equal(posts.at(-1).get('hora'),'');
   await add.click();assert.equal(await details.locator('summary').textContent(),'Todos los usuarios con Agenda');
   await details.locator('summary').click();await boxes.nth(1).check();await boxes.nth(2).check();
   // Refresh while editing must preserve a now-unavailable selection, then
   // block submission rather than silently send it to everyone.
   recipients=recipients.slice(0,1);await page.evaluate(()=>dispatchEvent(new Event('online')));
   await page.waitForFunction(()=>document.querySelector('[data-agenda-recipients-list]').textContent.includes('Destinatario no disponible'));
   await page.locator('textarea').fill('No ampliar audiencia');await page.locator('[data-agenda-comment-save]').click();
   assert.match(await page.locator('[data-agenda-comment-error]').textContent(),/ya no está disponible/);
   assert.equal(await page.locator('dialog').first().evaluate(d=>d.open),true);
   const dir=path.join(process.cwd(),'output/agenda_destinatarios_20261124');fs.mkdirSync(dir,{recursive:true});
   await page.screenshot({path:path.join(dir,`seleccion-${width}.png`)});
   await page.close();
  }
  assert.deepEqual(errors,[]);console.log('OK: selección múltiple, envío, privacidad, restablecimiento y cambios de permisos; escritorio y móvil.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
