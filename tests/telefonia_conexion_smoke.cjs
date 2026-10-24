/* API observada Socket.IO 2.3.0: errores, backoff, identidad y evidencia. */
'use strict';
const assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs');
let time=100000,connects=0,changed=0,sockets=[];
const view={Date:{now:()=>time},navigator:{onLine:true},URL,Proxy,Reflect,
 io:function(){const events={};const s={connected:false,on(n,fn){(events[n]??=[]).push(fn);},
 connect(){connects++;},fire(n){for(const fn of events[n]||[])fn();}};sockets.push(s);return s;}};
view.io.protocol=4;const original=view.io;
vm.runInNewContext(fs.readFileSync('public/javascript/telefonia_conexion.js','utf8'),{window:view});
const monitor=view.IMPE_PHONE_CONNECTION_FACTORY.create(view,()=>changed++);
assert.equal(monitor.snapshot().verified,false);
assert.equal(monitor.install(),true);assert.equal(monitor.install(),true);
assert.equal(view.io.protocol,original.protocol,'Proxy conserva propiedades estáticas');
const foreign=view.io('https://other.test/webrtc_pushes');foreign.connected=true;foreign.fire('init');
assert.equal(monitor.snapshot().verified,false,'socket ajeno no acredita Zadarma');
const first=view.io('https://ws.zadarma.com/webrtc_pushes?key=synthetic');first.connected=true;
assert.equal(monitor.snapshot().verified,false,'connected sin init no acredita proveedor');
first.fire('init');assert.equal(monitor.snapshot().pushConnected,true);assert.equal(monitor.snapshot().sipRegistered,false);
for(const minutes of [5,15,30,60]){time+=minutes*60000;assert.equal(monitor.snapshot().verified,false);first.fire('pong');assert.equal(monitor.snapshot().verified,true);}
first.connected=false;first.fire('disconnect');assert.equal(monitor.snapshot().verified,false);
monitor.recover();assert.equal(connects,1);for(let i=0;i<20;i++)monitor.recover();assert.equal(connects,1);
for(const wait of [5000,10000,20000,40000,60000,60000]){time+=wait;monitor.recover();}
assert.equal(connects,7,'backoff crece y sigue tras más de tres fallos');
time+=60000;monitor.recover(true);assert.equal(connects,7,'llamada activa protegida');
view.navigator.onLine=false;monitor.recover();assert.equal(connects,7,'offline sin reintento');
view.navigator.onLine=true;first.connected=true;first.fire('init');assert.equal(monitor.snapshot().verified,true);
first.connected=false;first.fire('disconnect');monitor.recover();assert.equal(connects,8,'éxito reinicia backoff');
const next=view.io('https://ws.zadarma.com/webrtc_pushes?key=synthetic');first.fire('init');
assert.equal(monitor.snapshot().verified,false,'evento de socket anterior no valida al nuevo');
next.connected=true;next.fire('init');assert.equal(monitor.snapshot().verified,true);
view.zdrmWebPhone={webPhoneUA:{isRegistered:()=>true,isConnected:()=>true}};
assert.equal(monitor.snapshot().sipRegistered,true);
view.zdrmWebPhone.webPhoneUA.isConnected=()=>false;assert.equal(monitor.snapshot().sipRegistered,false);
assert.ok(changed>0);
console.log('PASS: conexión, init/pong, SIP, errores, backoff y sesiones ajenas.');

(async()=>{
 const source=fs.readFileSync('public/javascript/telefonia_host.js','utf8');
 const a=source.indexOf('    const loadScript = function'),b=source.indexOf('    // Sólo pistas enviadas',a);
 assert.ok(a>0&&b>a);
 let script=null,appends=0,timers=new Map(),seq=0;
 const win={setTimeout(fn){timers.set(++seq,fn);return seq;},clearTimeout(id){timers.delete(id);}};
 const element=()=>({dataset:{},events:{},setAttribute(){},
  addEventListener(name,fn){(this.events[name]??=new Set()).add(fn);},
  removeEventListener(name,fn){this.events[name]?.delete(fn);},
  fire(name){for(const fn of [...this.events[name]||[]])fn();},remove(){script=null;}});
 const doc={querySelector:()=>script,createElement:element,head:{appendChild(node){script=node;appends++;}}};
 const load=new Function('window','document',source.slice(a,b)+'return loadScript;')(win,doc);
 const failed=load('https://provider.test/lib.js','lib').then(()=>false,()=>true);
 for(const fn of [...timers.values()])fn();
 assert.equal(await failed,true,'carga colgada rechaza en lugar de preparar indefinidamente');
 const retry=load('https://provider.test/lib.js','lib');
 assert.equal(appends,1,'no duplica el script lento');script.fire('load');await retry;
 await load('https://provider.test/lib.js','lib');assert.equal(appends,1,'reutiliza el script que termina tarde');
 script=null;const error=load('https://provider.test/fn.js','fn').catch(()=>{});script.fire('error');await error;
 const recovered=load('https://provider.test/fn.js','fn');script.fire('load');await recovered;
 assert.equal(appends,3,'error de red permite volver a descargar el recurso fallido');
 console.log('PASS: tiempo límite, carga tardía única y recuperación de error de recurso.');
})().catch(error=>{console.error(error);process.exitCode=1;});
