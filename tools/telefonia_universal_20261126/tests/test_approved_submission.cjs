'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_atencion.js','utf8');
const values=new Map();const storage={get length(){return values.size;},key:i=>[...values.keys()][i]??null,getItem:k=>values.get(k)??null,setItem:(k,v)=>values.set(k,String(v)),removeItem:k=>values.delete(k)};
let now=1800000000000;
function load(){
 const window={crypto:crypto.webcrypto,IMPE_CURRENT_USER_ID:42,IMPE_PHONE_HTTP:{request:async()=>({ok:true,status:200,json:async()=>({ok:true})})}};
 vm.runInNewContext(source,{window,document:{baseURI:'http://fixture.test/',readyState:'loading',addEventListener(){}},URL,WeakMap,Set,Map,Uint8Array,Date:{now:()=>now},localStorage:storage,sessionStorage:storage});
 return window.IMPE_CALL_DRAFTS;
}
(async()=>{
 let api=load(),data={csrf_token:'secret-old',resultado:'NO_INTERESADO',observacion:'Fixture'};
 const first=api.prepareSubmission(42,['sdk-approved-test'],data,{adaptador:'ANALISTA'});
 const meta=JSON.parse(first.values.captura_confirmacion);assert.match(meta.request_id,/^[a-f0-9]{48}$/);assert.equal(meta.revision,now);
 assert.equal([...values.values()].some(v=>v.includes('secret-old')),false,'CSRF never persisted');
 now+=1000;api=load();const retry=api.prepareSubmission(42,['sdk-approved-test'],{...data,csrf_token:'secret-new'},{adaptador:'ANALISTA'});
 assert.equal(retry.values.captura_confirmacion,first.values.captura_confirmacion,'Lost ACK/reload keeps the exact approved request');
 assert.equal(retry.values.csrf_token,'secret-new','Transport still uses fresh CSRF');
 const edited=api.prepareSubmission(42,['sdk-approved-test'],{...data,observacion:'Edited'},{adaptador:'ANALISTA'});
 assert.notEqual(JSON.parse(edited.values.captura_confirmacion).request_id,meta.request_id,'Human edit creates a distinct approved command');
 await api.remove(42,['sdk-approved-test'],'current',first.revision);
 assert.equal(api.prepareSubmission(42,['sdk-approved-test'],{...data,observacion:'Edited'},{adaptador:'ANALISTA'}).values.captura_confirmacion,edited.values.captura_confirmacion,'Old ACK cannot erase a newer approved attempt');
 assert.throws(()=>api.prepareSubmission(41,['sdk-approved-test'],data,{adaptador:'ANALISTA'}),/otra sesión/);
 console.log('PASS approved submission: durable exact retry, fresh CSRF, user isolation, explicit edit separation and late ACK protection. No automatic result execution.');
})().catch(e=>{console.error(e);process.exitCode=1;});
