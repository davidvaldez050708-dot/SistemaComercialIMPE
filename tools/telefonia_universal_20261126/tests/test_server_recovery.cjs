'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/seguimiento_llamada_zadarma.js','utf8').replace(/\r\n/g,'\n');
const piece=(a,b)=>{const i=source.indexOf(a),j=source.indexOf(b,i);assert(i>=0&&j>i);return source.slice(i,j);};
const pbx='out_'+'a'.repeat(32),other='out_'+'b'.repeat(32);
const job=(id,ref=pbx)=>({interactionId:id,metadata:{call_token:'receipt-'+id,seguimiento_id:7,pbx_call_id:ref,native_receipt:true}});
function fixture(pages,options={}){
 const values=new Map(),calls=[];
 if(options.existing)values.set('queue',JSON.stringify(options.existing));
 const box={window:{IMPE_CURRENT_USER_ID:42},usuarioId:42,queue:options.existing||{},queueKey:'queue',URLSearchParams,
  activeCall:{token:'sdk-new-active',seguimiento_id:8},localStorage:{getItem:k=>values.get(k)||null,setItem:(k,v)=>{if(options.storageFails)throw Error('full');values.set(k,v);}},
  fetchTechnical:async url=>{calls.push(url);return {ok:true,json:async()=>{if(options.changeUser)box.window.IMPE_CURRENT_USER_ID=43;return pages[Math.min(calls.length-1,pages.length-1)];}};}};
 vm.createContext(box);vm.runInContext(piece('        const writeQueue = (token, job) => {','        const secureResult = (job, announce) => {')+
  piece('        let restoreFlight=null;','        const probe = function () {')+'\ninvoke=restoreConfirmedLinks;',box);
 return {box,calls,values};
}
(async()=>{
 let count=0;
 const existing={'sdk-original':{interactionId:19,metadata:{call_token:'sdk-original',pbx_call_id:pbx,seguimiento_id:7},retryAt:900000,feedback:{tieneEvidencia:true}}};
 const f=fixture([{ok:true,conciliaciones:[job(19)],cursor:19,has_more:true},{ok:true,conciliaciones:[job(20,other)],cursor:20,has_more:false}],{existing});
 assert.equal(await f.box.invoke(),true);assert.equal(f.calls.length,2);count+=2;
 const saved=JSON.parse(f.values.get('queue'));assert.equal(saved['sdk-original'].retryAt,900000);assert.equal(saved['sdk-original'].feedback.tieneEvidencia,true);assert.equal(saved['receipt-19'],undefined);count+=3;
 assert.equal(saved['receipt-20'].interactionId,20);assert.equal(saved['receipt-20'].metadata.pbx_call_id,other);assert.equal(f.box.activeCall.token,'sdk-new-active');count+=3;
 const lost=fixture([{ok:true,conciliaciones:[job(21)],cursor:21,has_more:false}]);
 assert.equal(await lost.box.invoke(),true);assert.equal(JSON.parse(lost.values.get('queue'))['receipt-21'].interactionId,21);assert.equal(lost.calls.length,1);count+=3;
 const changed=fixture([{ok:true,conciliaciones:[job(21)]}],{changeUser:true});assert.equal(await changed.box.invoke(),false);assert.equal(changed.values.size,0);count+=2;
 const full=fixture([{ok:true,conciliaciones:[job(21)]}],{storageFails:true});assert.equal(await full.box.invoke(),false);assert.equal(full.values.size,0);count+=2;
 const malformed=fixture([{ok:true,conciliaciones:[job(0),job(22,'out_bad'),{interactionId:23,metadata:{...job(23).metadata,call_token:'another-call'}}],has_more:false}]);
 assert.equal(await malformed.box.invoke(),true);assert.equal(malformed.values.size,0);count+=2;
 const stalled=fixture([{ok:true,conciliaciones:[],has_more:true,cursor:0}]);assert.equal(await stalled.box.invoke(),false);assert.equal(stalled.calls.length,1);count+=2;
 console.log(`PASS ${count} actual server-recovery reader checks: lost local storage, exact receipt IDs, bounded cursor, unchanged active call, no duplicate SDK work, preserved backoff, user/storage/malformed guards. Mock GET only; no commercial POST.`);
})().catch(e=>{console.error(e);process.exitCode=1;});
