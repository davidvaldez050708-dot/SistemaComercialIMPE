const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium, webkit} = require('playwright');
const root = path.resolve(__dirname, '..');
const html = fs.readFileSync(process.argv[2], 'utf8');
const bootstrap = path.dirname(require.resolve('bootstrap/package.json'));
const icons = path.dirname(require.resolve('bootstrap-icons/package.json'));

(async () => {
  const engine = process.env.SIDEBAR_BROWSER === 'webkit' ? webkit : chromium;
  const browser = await engine.launch(process.env.PLAYWRIGHT_CHANNEL ? {channel: process.env.PLAYWRIGHT_CHANNEL} : {});
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.hostname !== 'sidebar.test') return route.abort();
      const name = url.pathname;
      if (name === '/' || name === '/logout.php') return route.fulfill({contentType:'text/html', body: name === '/' ? html : '<p>Salida de prueba</p>'});
      let file = name === '/bootstrap.css' ? path.join(bootstrap, 'dist/css/bootstrap.min.css') :
        name === '/bootstrap.js' ? path.join(bootstrap, 'dist/js/bootstrap.bundle.min.js') :
        name.startsWith('/icons/') ? path.join(icons, 'font', name.slice(7)) :
        name === '/public/css/responsive_sistema.css' && process.env.SIDEBAR_RESPONSIVE_CSS ? process.env.SIDEBAR_RESPONSIVE_CSS : path.join(root, name);
      if (!fs.existsSync(file)) return route.abort();
      await route.fulfill({path:file});
    });
    for (const [width,height] of [[320,568],[360,640],[390,844],[430,932],[768,1024],[844,390],[667,320]]) {
      await page.setViewportSize({width,height});
      await page.goto('http://sidebar.test/');
      await page.locator('#open').click();
      await page.locator('#mobileSidebar.show').waitFor();
      await page.waitForFunction(() => !document.querySelector('#mobileSidebar').classList.contains('showing'));
      const verify = async () => {
        const visibleHeight=page.viewportSize().height;
        const result = await page.evaluate(() => {
          const side=document.querySelector('#mobileSidebar'), foot=side.querySelector('.sidebar-footer'), nav=side.querySelector('nav');
          const box=e=>{const b=e.getBoundingClientRect();return {x:b.x,y:b.y,width:b.width,height:b.height,bottom:b.bottom,right:b.right};};
          const controls=['.sidebar-user-avatar','.sidebar-user-name','.sidebar-user-email','.sidebar-logout'].map(s=>{
            const e=side.querySelector(s), b=e.getBoundingClientRect();
            return {s, ...box(e), hit: e.contains(document.elementFromPoint(b.x+b.width/2,b.y+b.height/2))};
          });
          return {foot:box(foot),nav:box(nav),controls,sibling:foot.parentElement===side,scroll:nav.scrollHeight,inner:nav.clientHeight,logout:side.querySelector('.sidebar-logout').getAttribute('href')};
        });
        assert(result.sibling, 'Perfil fuera del área desplazable');
        assert(result.foot.bottom <= visibleHeight+1 && result.foot.y >= 0, JSON.stringify({width,height,result}));
        assert(result.nav.bottom <= result.foot.y+1 && result.nav.height>20, 'Menú con espacio propio y sin solapar perfil');
        for(const c of result.controls) assert(c.width>0 && c.height>0 && c.y>=0 && c.bottom<=visibleHeight+1 && c.right<=width && c.hit, JSON.stringify(c));
        assert.equal(result.logout,'http://sidebar.test/logout.php');
        assert(result.controls[3].width>=44 && result.controls[3].height>=44);
        return result;
      };
      const first=await verify();
      if(height<700) assert(first.scroll>first.inner, 'Menú largo realmente desplazable');
      await page.locator('#mobileSidebar nav').evaluate(e=>e.scrollTop=e.scrollHeight);
      await verify();
      // La altura disponible cambia con orientación y barras del navegador.
      await page.setViewportSize({width,height:Math.max(320,height-100)});
      await verify();
      await page.setViewportSize({width,height});
      await verify();
      if(width===390 && process.env.SIDEBAR_SCREENSHOT) await page.screenshot({path:process.env.SIDEBAR_SCREENSHOT});
      await page.locator('#mobileSidebar .sidebar-user-name').evaluate(e=>e.textContent='Nombre completo de prueba muy largo para pantalla pequeña');
      await verify();
      console.log(`Perfil, rol, avatar y salida visibles; menú desplazable: ${width}x${height}`);
    }
    await page.locator('#mobileSidebar .sidebar-logout').click();
    await page.waitForURL('http://sidebar.test/logout.php');
    await page.setViewportSize({width:1366,height:768});
    await page.goto('http://sidebar.test/');
    await page.locator('.admin-sidebar-fixed .sidebar-user-name').waitFor({state:'visible'});
    const desktop = await page.locator('.admin-sidebar-fixed .sidebar-footer').boundingBox();
    assert(desktop && desktop.y>=0 && desktop.y+desktop.height<=769);
    await page.locator('.admin-sidebar-fixed .sidebar-logout').click();
    await page.locator('#modalCerrarSesion.show').waitFor();
    assert.equal(await page.locator('#modalCerrarSesion .logout-confirm-button').getAttribute('href'),'http://sidebar.test/logout.php');
    assert.deepEqual(errors,[]);
    console.log('Salida móvil conserva su ruta; escritorio conserva confirmación. Sin errores JS.');
  } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
