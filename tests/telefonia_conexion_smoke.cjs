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
