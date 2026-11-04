// Vista PHP y JavaScript reales. Red simulada, sin DB ni INEGI.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const assert=require('node:assert/strict');
const crypto=require('node:crypto');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const bootstrapRoot=process.env.DENUE_BOOTSTRAP_DIR||path.join(root,'tmp/denue-bootstrap');
const view=cp.execFileSync(process.env.PHP_BIN||'php',[path.join(__dirname,'fixtures/denue_paginado_vista.php')],{encoding:'utf8'});
const helper=read('public/javascript/seguimiento_candidatos_paginacion.js');
const helperHash=crypto.createHash('sha256').update(fs.readFileSync(path.join(root,'public/javascript/seguimiento_candidatos_paginacion.js'))).digest('hex');
const legacyHelper=cp.execFileSync('git',['show','ac6c3648:public/javascript/seguimiento_candidatos_paginacion.js'],{cwd:root,encoding:'utf8'});
const css=['global.css','dashboard.css','seguimiento_vinculacion.css','seguimiento_filtros_layout.css','responsive_sistema.css']
    .filter(p=>fs.existsSync(path.join(root,'public/css',p))).map(p=>read('public/css/'+p)).join('\n');
const html='<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+
    '<style>'+fs.readFileSync(path.join(bootstrapRoot,'bootstrap.min.css'),'utf8')+'\n'+css+'</style>'+
    '<script>'+fs.readFileSync(path.join(bootstrapRoot,'bootstrap.bundle.min.js'),'utf8')+'</script>'+
    '</head><body>'+view;
(async()=>{
    const browser=await chromium.launch({headless:true,...(process.env.DENUE_CHROME_CHANNEL?{channel:process.env.DENUE_CHROME_CHANNEL}:{})});
    try {
        const page=await browser.newPage({viewport:{width:1440,height:1000}});
        const errors=[],requests=[],scripts=[];
        let continuado=false,falloOnce=false;
        page.on('pageerror',e=>errors.push(e.message));
        await page.route('http://denue.test/**',async route=>{
            const url=new URL(route.request().url());
            if(url.pathname==='/public/javascript/seguimiento_candidatos_paginacion.js'){
                scripts.push(url.searchParams.get('v'));
                assert.equal(url.searchParams.get('v'),helperHash,'La vista versiona su dependencia por contenido.');
                return route.fulfill({contentType:'text/javascript',body:helper});
            }
            if(url.searchParams.get('action')!=='buscarCandidatos') return route.fulfill({contentType:'text/html',body:html+
                (url.pathname==='/footer-antiguo'?'<script>'+legacyHelper+'</script>':'')+'</body></html>'});
            assert.equal(url.searchParams.get('denue_paginado'),'1');
            const p=Number(url.searchParams.get('pagina'));
            const municipio=url.searchParams.get('municipio_id');
            requests.push({p,municipio});
            if(p===1&&!continuado){continuado=true;return route.fulfill({json:{ok:true,paginacion_denue:true,denue_continuar:true,denue_revisados:100}});}
            if(p===11&&!falloOnce){falloOnce=true;return route.fulfill({status:503,json:{ok:false,reintentable:true,mensaje:'Fallo ficticio'}});}
            const candidatos=Array.from({length:10},(_,i)=>({origen:'DENUE',clave_origen:'DENUE:'+(10*(p-1)+i+1),
                nombre:'Institución ficticia '+(10*(p-1)+i+1),tipo_entidad:'INSTITUCION',municipio_nombre:municipio==='12'?'Coyoacán':'Tláhuac',
                municipio_id:Number(municipio),actividad:'Educación',sector:'61',estrato_valor:1,estrato_etiqueta:'0–5 personas'}));
            return route.fulfill({json:{ok:true,paginacion_denue:true,denue_continuar:false,candidatos,pagina:p,
                total_resultados:p*10,resultados_por_pagina:10,total_exacto:false,hay_mas:true,cache_candidatos:[],advertencias:[]}});
        });
        await page.goto('http://denue.test/');
        assert.equal(scripts.length,1,'La vista carga su dependencia incluso sin el script del footer.');
        assert.deepEqual(errors,[]);
        await page.evaluate(()=>bootstrap.Modal.getOrCreateInstance(document.getElementById('modalBuscarCandidato')).show());
        await page.locator('[data-candidate-type]').selectOption('INSTITUCIONES');
        await page.locator('[data-candidate-municipality]').selectOption('11');
        await page.locator('[data-candidate-auto-search]').click();
        const cards=page.locator('[data-candidate-results] .linkage-candidate-card');
        await cards.first().getByText('Institución ficticia 1',{exact:true}).waitFor();
        assert.equal(await cards.count(),10);
        assert.match(await page.locator('[data-candidate-page-label]').innerText(),/Hay más registros/);
        for(let p=2;p<=32;p++){
            await page.locator('[data-candidate-page-next]').click();
            await cards.first().getByText('Institución ficticia '+(10*(p-1)+1),{exact:true}).waitFor();
        }
        assert.equal(requests.filter(r=>r.p===11).length,2,'Recuperación automática del rango fallido.');
        assert.ok(requests.some(r=>r.p===32),'Se accede a registros posteriores al 300.');
        await page.locator('[data-candidate-page-prev]').click();
        await cards.first().getByText('Institución ficticia 301',{exact:true}).waitFor();
        await page.locator('[data-candidate-municipality]').selectOption('12');
        await page.locator('[data-candidate-auto-search]').click();
        await cards.first().getByText('Institución ficticia 1',{exact:true}).waitFor();
        assert.match(await cards.first().innerText(),/Coyoacán/);
        assert.equal(requests.at(-1).p,1,'Cambiar filtros reinicia la página visible.');
        await page.setViewportSize({width:390,height:844});
        await page.waitForTimeout(150);
        assert.ok(await page.locator('#modalBuscarCandidato').evaluate(e=>e.scrollWidth<=e.clientWidth+1),'Modal sin desbordamiento horizontal.');
        await page.goto('http://denue.test/footer-antiguo');
        assert.equal(await page.evaluate(()=>typeof window.SeguimientoCandidatosPaginacion.consultarPagina),'undefined','Se reprodujo un footer con la versión antigua.');
        await page.evaluate(()=>bootstrap.Modal.getOrCreateInstance(document.getElementById('modalBuscarCandidato')).show());
        await page.locator('[data-candidate-type]').selectOption('INSTITUCIONES');
        await page.locator('[data-candidate-municipality]').selectOption('11');
        await page.locator('[data-candidate-auto-search]').click();
        await cards.first().getByText('Institución ficticia 1',{exact:true}).waitFor();
        assert.equal(await cards.count(),10,'El footer antiguo no reemplaza la dependencia capturada por la vista.');
        assert.deepEqual(errors,[]);
        console.log('Modal real DENUE: carga propia versionada, footer ausente/antiguo, 32 páginas, recuperación 503, filtros y móvil OK');
    } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
