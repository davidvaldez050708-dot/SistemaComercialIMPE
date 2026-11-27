'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_analista_historial_pasivo.js','utf8');
const start=source.indexOf('    const postEvent = function'),end=source.indexOf('    const draw = function',start);assert(start>=0&&end>start);
const requests=[],call={id:'sdk-fixture-reader-session',writerProtocol:2,number:'5550000000',duration:2};
let reply={ok:true,sesion:{session_id:call.id,fecha_fin:'2026-11-27T12:00:00Z',interna:false}};
const box={URL,URLSearchParams,Promise,chain:Promise.resolve(),finished:call,cfg:{endpoint:'/legacy',sdkEndpoint:'/prueba_telefonia/api/sesion_local.php',csrf:'fixture-csrf'},
 root:{location:{href:'http://fixture.test/'},console,IMPE_PHONE_HTTP:{request:async(url,options)=>{requests.push({url:String(url),...options});return {ok:true,json:async()=>reply};}}}};
vm.createContext(box);vm.runInContext(source.slice(start,end)+'\ninvoke=postEvent;',box);
(async()=>{
 let checks=0;
 await box.invoke('ringing',call);await box.invoke('answered',call);assert.equal(requests.length,0);checks++;
 assert.equal(await box.invoke('ended',call),true);assert.equal(requests.length,1);checks+=2;
 assert.equal(requests[0].method,undefined);assert.equal(new URL(requests[0].url).searchParams.get('session_id'),call.id);checks+=2;
 reply={ok:true,sesion:{session_id:call.id,fecha_fin:null}};assert.equal(await box.invoke('ended',call),false);checks++;
 reply={ok:true,sesion:{session_id:'sdk-unrelated-reader-session',fecha_fin:'2026-11-27T12:00:00Z'}};assert.equal(await box.invoke('ended',call),false);checks++;
 reply={ok:true,sesion:{session_id:call.id,fecha_fin:'2026-11-27T12:00:00Z',interna:true}};
 assert.equal(await box.invoke('ended',call),'INTERNA');checks++;
 assert.equal(await box.invoke('ended',{...call,transferredCustomer:true}),true);checks++;
 const pbx={id:'fixture-reader-pbx',pbxReference:'fixture-reader-pbx',writerProtocol:2,pbxOnly:true};box.finished=pbx;
 reply={ok:true,pbx:{pbx_call_id:pbx.id,finalizada:false,intento_finalizado:true,contestada:false,interna:false}};
 assert.equal(await box.invoke('missed',pbx),true);checks++;
 assert.equal(new URL(requests.at(-1).url).searchParams.get('pbx_call_id'),pbx.id);assert.equal(requests.every(r=>r.method!=='POST'),true);checks+=2;
 const legacy={...call,writerProtocol:1};box.finished=legacy;reply={ok:true,interna:false};
 assert.equal(await box.invoke('ended',legacy),true);assert.equal(requests.at(-1).method,'POST');checks+=2;
 assert.equal(new URLSearchParams(requests.at(-1).body).get('session_id'),legacy.id);checks++;
 console.log(`PASS ${checks} actual Analyst journal reader assertions: modern host is sole writer, terminal ACK/read scope, PBX-only path without SDK fabrication and explicit legacy protocol compatibility. No SDK/network.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
