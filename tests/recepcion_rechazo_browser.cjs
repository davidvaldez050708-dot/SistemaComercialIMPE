'use strict';
// Navegador real, SDK simulado y red bloqueada: nunca realiza llamadas.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('playwright');
const path=require('node:path');
const shell = fs.readFileSync(path.join(process.env.IMPE_REJECTION_SOURCE_ROOT||'.','public/javascript/telefonia_shell.js'),'utf8');
const fields = [...new Set([...shell.matchAll(/querySelector\('\[([a-z-]+)\]'/g)].map(m=>m[1]))];
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try {
  const page=await browser.newPage();const errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',route=>route.fulfill({contentType:'text/html',body:'<!doctype html><html><body></body></html>'}));
  await page.goto('https://crm.example.test/');
  await page.evaluate(fields=>{
   Object.defineProperty(navigator,'locks',{value:undefined});
   for(const attr of fields){const el=document.createElement('button');el.setAttribute(attr,'');document.body.append(el);}
   const crm=document.createElement('iframe');crm.id='impeCrmFrame';document.body.append(crm);
   window.IMPE_PHONE_NUMBERS={display:String};
   window.IMPE_PHONE_SHELL_CONFIG={userId:8,extension:'100',autoReception:true,canReceive:true,hostUrl:'https://crm.example.test/host'};
  },fields);
  await page.addScriptTag({content:shell});
  await page.waitForFunction(()=>document.querySelector('iframe[data-shell-engine]')?.contentDocument?.readyState==='complete');
  await page.evaluate(()=>{
   window.testOps=[];
   const bridge={
    decline(){testOps.push('decline');return true;},
    answer(){testOps.push('answer');return true;},
    hangup(){testOps.push('hangup');return true;},
    recheck(){testOps.push('recheck');return true;},error(){return 'Error SDK';}
   };
   document.querySelector('iframe[data-shell-engine]').contentWindow.IMPE_ZADARMA_INCOMING=bridge;
  });
  const push=async(phase,extra={})=>page.evaluate(({phase,extra})=>{
   const s={userId:8,updatedAt:Date.now(),hostReady:true,connection:{verified:true},active:true,direction:'incoming',destination:'5550000000',phase,pbxCallId:'pbx-a',callToken:'invite-a',...extra};
   localStorage.setItem('impe:telephony:state:8',JSON.stringify(s));
   dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:8',newValue:JSON.stringify(s)}));
  },{phase,extra});
  const prop=(attr,key)=>page.locator(`[${attr}]`).evaluate((el,key)=>el[key],key);
  await push('incoming-ringing');
  assert.equal(await prop('data-incoming-panel','hidden'),false);
  await page.locator('[data-incoming-decline]').click();
  assert.equal(await prop('data-incoming-panel','hidden'),false,'No ocultar antes de confirmar');
  assert.match(await prop('data-incoming-title','textContent'),/Rechazando/);
  await push('rejecting',{context:{declineRequested:true}});
  assert.equal(await prop('data-incoming-mute','hidden'),true,'No presentar conversación falsa');
  assert.equal(await prop('data-incoming-answer','hidden'),true);
  // El pending no debe bloquear Comprobar estado indefinidamente.
  await page.waitForTimeout(4600);
  await push('rejecting',{context:{declineRequested:true}});
  assert.equal(await prop('data-incoming-hangup','disabled'),false);
  assert.match(await prop('data-incoming-hangup','textContent'),/Comprobar estado/);
  await page.locator('[data-incoming-hangup]').click();
  assert.deepEqual(await page.evaluate(()=>testOps),['decline','recheck']);
  // Nueva invitación del mismo PBX y número: vuelven Contestar/Rechazar.
  await push('incoming-ringing',{callToken:'invite-b',context:{}});
  assert.equal(await prop('data-incoming-panel','hidden'),false);
  assert.equal(await prop('data-incoming-answer','disabled'),false);
  await page.locator('[data-incoming-decline]').click();
  // Otro reintento antes de vencer el pending anterior tampoco queda bloqueado.
  await push('rejecting',{callToken:'invite-b',context:{declineRequested:true}});
  await push('incoming-ringing',{callToken:'invite-b',context:{}});
  assert.equal(await prop('data-incoming-answer','disabled'),false);
  await page.locator('[data-incoming-answer]').click();
  await push('in-progress',{callToken:'invite-c',duration:1});
  assert.equal(await prop('data-incoming-hangup','hidden'),false);
  await page.locator('[data-incoming-hangup]').click();
  await push('incoming-finished',{active:false,callToken:'invite-c',duration:2});
  assert.match(await prop('data-incoming-title','textContent'),/finalizada/);
  await page.locator('[data-incoming-close-finished]').click();
  assert.equal(await prop('data-incoming-panel','hidden'),true);
  assert.deepEqual(await page.evaluate(()=>testOps),['decline','recheck','decline','answer','hangup']);
  assert.equal(await page.locator('iframe[data-shell-engine]').count(),1,'Un solo transporte durante todo el flujo');
  assert.deepEqual(errors,[]);
  console.log('PASS navegador: rechazo visible, espera limitada, consulta sin recolgar, reintentos del mismo PBX, respuesta y cierre, un único motor.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
