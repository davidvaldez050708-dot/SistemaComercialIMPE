const assert=require('node:assert/strict');
const {spawn,execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php';
const base=process.env.IMPE_BASE_URL||'http://127.0.0.1:8097/';
assert.match(process.env.IMPE_DB_NAME||'',/^impe_test_handly_aliados[a-z0-9_]*$/);
const env={...process.env,APP_ENV:'testing',IMPE_TEST_ALLOW_ROUTER:'1'};
const sql=q=>JSON.parse(execFileSync(php,['-r',`require 'config/db_connection.php';$d=(new Database())->connect(false);$r=$d->query(${JSON.stringify(q)});echo json_encode($r===true?[]:$r->fetch_all(MYSQLI_ASSOC));`],{cwd:root,env,encoding:'utf8'}));
const manual=sql("SELECT id,telefono_fuente FROM aliados_manuales WHERE nombre_entidad='Aliado Regional' LIMIT 1")[0];
const original=sql('SELECT telefono_fuente FROM seguimientos_vinculacion WHERE id=10')[0].telefono_fuente;
const server=spawn(php,['-S',new URL(base).host,'-t',root,'tests/aliados_registro_router.php'],{cwd:root,env,stdio:['ignore','ignore','pipe']});
let logs='';server.stderr.on('data',d=>logs+=d);
const output=env.IMPE_CANALES_OUTPUT,bootstrap=env.BOOTSTRAP_MODULE_PATH||path.join(root,'../browser-deps/node_modules/bootstrap');
(async()=>{
 let browser,checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;console.log('OK '+label);};
 try{
  for(let i=0;i<70;i++){try{await fetch(base+'_fixture?role=contacts');break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({headless:true,...(env.PLAYWRIGHT_CHANNEL?{channel:env.PLAYWRIGHT_CHANNEL}:{})});
  const context=await browser.newContext(),page=await context.newPage(),errors=[];
  page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
  await context.route('**/*',r=>{
   const url=r.request().url();
   if(url.endsWith('/fixture-bootstrap.css'))return r.fulfill({path:path.join(bootstrap,'dist/css/bootstrap.min.css'),contentType:'text/css'});
   if(url.endsWith('/fixture-bootstrap.js'))return r.fulfill({path:path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),contentType:'application/javascript'});
   if(url.includes('/fixture-font/'))return r.fulfill({path:path.join(bootstrap,'../@fontsource/manrope/files',path.basename(new URL(url).pathname)),contentType:'font/woff2'});
   if(url.includes('/fixture-icon/'))return r.fulfill({path:path.join(bootstrap,'../bootstrap-icons/font/fonts',path.basename(new URL(url).pathname)),contentType:'font/woff2'});
   if(url.startsWith('https://'))return r.abort();return r.continue();
  });
  const ids=['#modalAliadoContactos','#modalManualContactos'];
  const open=async(index)=>{
   await page.goto(base+'_fixture?view=aliados&role=contacts');
   await page.addStyleTag({content:fs.readFileSync(path.join(bootstrap,'../@fontsource/manrope/latin.css'),'utf8').replaceAll('./files/','/fixture-font/')});
   await page.addStyleTag({content:fs.readFileSync(path.join(bootstrap,'../bootstrap-icons/font/bootstrap-icons.css'),'utf8').replaceAll('./fonts/','/fixture-icon/')});
   await page.evaluate(()=>document.fonts.ready);
   const button=page.locator(index===1?`[data-registro-manual-contactos="${manual.id}"]`:'[data-aliado-contacts="10"]');
   const row=button.locator('xpath=ancestor::tr');await row.locator('[data-bs-toggle=dropdown]').click();await button.click();
   await page.locator(ids[index]+'.show .aliados-contact-item').first().waitFor();
   await page.mouse.move(0,0);
   await page.waitForTimeout(180);
  };
  const styles=async(index)=>page.locator(ids[index]).evaluate(el=>{
   const groups={dialog:'.modal-dialog',content:'.modal-content',header:'.modal-header',title:'.modal-title',context:'.modal-header p',kicker:'.aliados-eyebrow',body:'.modal-body',note:'.aliados-contact-source-note',card:'.aliados-contact-item',icon:'.aliados-contact-item-icon',copy:'.aliados-contact-item-copy',meta:'.aliados-contact-item-copy > span',badge:'.aliados-contact-badges span',action:'.aliados-contact-item-actions .btn',editor:'.aliados-contact-editor',heading:'.aliados-contact-editor-heading',fields:'.aliados-contact-form-grid',field:'.aliados-contact-form-grid input',label:'.form-label',options:'.aliados-contact-options',choice:'.aliados-contact-options label',checkbox:'.aliados-contact-options input',help:'.aliados-contact-options small',actions:'.aliados-contact-form-actions',save:'button[type=submit]',close:'.btn-close'};
   const props=['fontFamily','fontSize','fontWeight','lineHeight','padding','marginTop','marginBottom','gap','border','borderRadius','boxShadow','color','backgroundColor','display','gridTemplateColumns','minHeight'];
   return Object.fromEntries(Object.entries(groups).map(([key,selector])=>{const node=el.querySelector(selector),s=getComputedStyle(node);return [key,Object.fromEntries(props.map(p=>[p,s[p]]))];}));
  });
  for(const [width,height] of [[1440,900],[1024,768],[768,1024],[390,844],[360,740]]){
   await page.setViewportSize({width,height});let reference;
   for(const index of [0,1]){
    await open(index);const modal=page.locator(ids[index]);const current=await styles(index);
    if(index===0)reference=current;else assert.deepEqual(current,reference,'estilos compartidos '+width);
    check(await modal.evaluate(e=>e.scrollWidth<=innerWidth && e.querySelector('.modal-body').scrollWidth<=e.querySelector('.modal-body').clientWidth),'sin desbordamiento '+index+' '+width);
    const box=await modal.locator('.modal-dialog').boundingBox();check(box.width<=Math.min(width,800),'ancho proporcionado '+index+' '+width);
    check(await modal.locator('button[type=submit]').evaluate(e=>e.getBoundingClientRect().height<55),'guardar proporcionado '+index+' '+width);
    if(output){fs.mkdirSync(output,{recursive:true});await page.screenshot({path:path.join(output,`canales-${index}-${width}.png`),fullPage:true});}
    // Muchos contactos: comprobar el scroll real del modal Bootstrap, sin tocar la BD.
    await modal.locator('.aliados-contact-item').first().evaluate(node=>{for(let n=0;n<12;n++)node.parentElement.appendChild(node.cloneNode(true));});
    await modal.locator('button[type=submit]').scrollIntoViewIfNeeded();
    check(await modal.locator('button[type=submit]').evaluate(e=>{const r=e.getBoundingClientRect();return r.top>=0 && r.bottom<=innerHeight;}),'guardar accesible con scroll '+index+' '+width);
    await modal.locator('.btn-close').scrollIntoViewIfNeeded();
    check(await modal.locator('.btn-close').evaluate(e=>{const r=e.getBoundingClientRect();return r.top>=0 && r.bottom<=innerHeight;}),'cierre accesible con scroll '+index+' '+width);
    await modal.locator('.btn-close').click();await modal.waitFor({state:'hidden'});
   }
   check(true,'igualdad de tipografía, espaciados, campos, tarjetas y botones '+width);
  }
  await page.setViewportSize({width:1366,height:900});
  const savedStyles=[];
  for(const index of [0,1]){
   await open(index);const modal=page.locator(ids[index]);
   const form=modal.locator(index?'[data-manual-contactos-form]':'[data-aliado-contact-form]');
   await modal.getByRole('button',{name:'Usar para difusión'}).first().click();
   check(await form.locator(index?'[name=telefono]':'[name=numero]').inputValue()===manual.telefono_fuente,'usar referencia '+index);
   check(!await form.locator(index?'[name=whatsapp_autorizado]':'[name=autorizado_whatsapp]').isChecked(),'referencia no asume autorización '+index);
   await form.locator('[name=etiqueta]').fill('Canal probado');
   const submit=async()=>{
    const response=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes(index?'action=registroGestion':'action=guardarContacto'));
    await form.locator('[type=submit]').click();const res=await response;check(res.status()===200 && (await res.json()).ok,'guardar HTTP real '+index);
    await form.locator('[type=submit]').waitFor({state:'visible'});await page.waitForTimeout(180);
   };
   await submit();
   const list=modal.locator(index?'[data-manual-contactos-list]':'[data-aliado-contact-list]');
   const card=list.locator('.aliados-contact-item').filter({hasText:'Canal probado'});
   await card.waitFor();check(await card.locator('.aliados-contact-item-icon').count()===1,'tarjeta compartida después de guardar '+index);
   await card.getByRole('button',{name:'Editar'}).click();
   check(await form.locator('[name=etiqueta]').inputValue()==='Canal probado','edición recupera etiqueta '+index);
   await form.locator('[name=etiqueta]').fill('Canal editado');
   await form.locator(index?'[name=whatsapp_confirmado]':'[name=confirmado_whatsapp]').check();
   await form.locator('[name=preferido_difusion]').check();await submit();
   const table=index?'aliados_manuales_contactos':'aliados_contactos';
   const saved=sql(`SELECT * FROM ${table} WHERE ${index?'aliado_id='+manual.id:'seguimiento_id=10'} AND activo=1`);
   check(saved.length===1 && saved[0].etiqueta==='Canal editado','editar sin duplicar ni mezclar '+index);
   check(Number(saved[0][index?'whatsapp_confirmado':'confirmado_whatsapp'])===1 && Number(saved[0][index?'whatsapp_autorizado':'autorizado_whatsapp'])===0 && Number(saved[0].preferido_difusion)===1,'tres estados independientes '+index);
   const edited=list.locator('.aliados-contact-item').filter({hasText:'Canal editado'});
   await edited.getByRole('button',{name:'Editar'}).click();
   const savedStyle=await edited.evaluate(el=>{
    const selectors=['','.aliados-contact-item-icon','.aliados-contact-item-copy','.aliados-contact-item-copy strong','.aliados-contact-item-copy > span','.aliados-contact-badges .is-confirmed','.aliados-contact-item-actions .btn','.aliados-contact-remove'];
    return selectors.map(selector=>{const s=getComputedStyle(selector?el.querySelector(selector):el);return ['fontFamily','fontSize','fontWeight','padding','border','borderRadius','backgroundColor','minHeight','gap'].map(p=>s[p]);});
   });
   if(index===0)savedStyles.push(savedStyle);else assert.deepEqual(savedStyle,savedStyles[0],'misma tarjeta después de guardar y editar');
   check(true,'estilos preservados al editar '+index);
   const cancel=modal.locator(index?'[data-manual-contacto-limpiar]':'[data-contact-editor-cancel]');
   check(await cancel.isVisible(),'cancelar edición accesible '+index);
   await cancel.click();
   check(!Number(await form.locator('[name=contacto_id]').inputValue()),'cancelar limpia edición '+index);
   await edited.getByRole('button',{name:'Editar'}).click();
   await form.locator(index?'[name=whatsapp_autorizado]':'[name=autorizado_whatsapp]').check();await submit();
   check(Number(sql(`SELECT ${index?'whatsapp_autorizado':'autorizado_whatsapp'} v FROM ${table} WHERE id=${saved[0].id}`)[0].v)===1,'autorización explícita persiste '+index);
   await list.locator('.aliados-contact-item').filter({hasText:'Canal editado'}).getByRole('button',{name:index?'Desactivar contacto de difusión':'Retirar contacto de difusión'}).click();
   await page.waitForTimeout(250);
   check(Number(sql(`SELECT activo FROM ${table} WHERE id=${saved[0].id}`)[0].activo)===0,'desactivar conserva historial '+index);
   check(await modal.locator('[name=telefono], [name=numero]').count()===1,'formulario intacto tras editar y guardar '+index);
   check(index===1?sql('SELECT telefono_fuente FROM aliados_manuales WHERE id='+manual.id)[0].telefono_fuente===manual.telefono_fuente:sql('SELECT telefono_fuente FROM seguimientos_vinculacion WHERE id=10')[0].telefono_fuente===original,'teléfono original intacto '+index);
  }
  check(errors.length===0,'sin errores JavaScript');
  check(!/PHP (Warning|Fatal|Parse|Deprecated)/.test(logs),'sin errores PHP: '+logs.split('\n').filter(x=>/PHP (Warning|Fatal|Parse|Deprecated)/.test(x)).join('\n'));
  console.log('PASS '+checks+' comprobaciones de ambos modales');
 }finally{if(browser)await browser.close();server.kill();}
})().catch(e=>{console.error(e);console.error(logs.slice(-4000));process.exitCode=1;});
