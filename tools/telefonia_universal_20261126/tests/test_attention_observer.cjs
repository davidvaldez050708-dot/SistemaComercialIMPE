'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_atencion.js','utf8');
function fixture(uid){
 const rows=new Map(),storage={get length(){return rows.size;},key:i=>[...rows.keys()][i]??null,getItem:k=>rows.get(k)??null,setItem:(k,v)=>rows.set(k,String(v)),removeItem:k=>rows.delete(k)};
 const window={IMPE_CURRENT_USER_ID:uid};
 vm.runInNewContext(source,{window,document:{baseURI:'http://fixture.test/',addEventListener(){}},URL,Date,Map,Set,WeakMap,localStorage:storage,sessionStorage:storage});
 return window.IMPE_CALL_DRAFTS;
}
let checks=0;
for(const [user,adapter]of [[41,'VENTAS'],[42,'ANALISTA'],[43,'RECEPCION']]){
 const api=fixture(user),sdk='sdk-fixture-attention-'+user;
 const live={userId:user,active:true,direction:'incoming',phase:'in-progress',callToken:sdk,context:{sdkSessionId:sdk}};
 const ended={...live,active:false,phase:'incoming-finished'};
 assert.equal(api.observeAttention(user,ended,adapter),null);assert.equal(api.list(user).length,0);checks+=2;
 api.observeAttention(user,live,adapter);const close=api.observeAttention(user,ended,adapter);
 assert.equal(close.ended,true);assert.equal(api.list(user).length,1);checks+=2;
 assert.equal(close.draft.contexto.adaptador,adapter);assert.equal(close.draft.contexto.cierre_local.conciliado,false);checks+=2;
 assert.equal(Object.keys(close.draft.valores).length,0,'Observed closure fabricates no human result or contact');checks++;
 api.localSave(user,[sdk],{observacion:'Texto humano',verificacion_telefono_confirmado:'0'},{adaptador:adapter});
 const revision=api.localRead(user,sdk).saved_at;
 api.observeAttention(user,ended,adapter);assert.equal(api.localRead(user,sdk).saved_at,revision);checks++;
 const pbx='fixture-attention-pbx-'+user;api.observeAttention(user,{...ended,pbxCallId:pbx},adapter);
 assert.equal(api.localRead(user,pbx).valores.observacion,'Texto humano');checks++;
 const fromNativeForm=api.localSave(user,[pbx],{resultado:'BUZON_VOZ'});
 assert.equal(fromNativeForm.ids.length,2);assert.equal(api.localRead(user,sdk).valores.resultado,'BUZON_VOZ');checks+=2;
 const second={...live,callToken:sdk+'-next',context:{sdkSessionId:sdk+'-next'}};
 api.observeAttention(user,second,adapter);api.observeAttention(user,ended,adapter);
 api.observeAttention(user,{...second,active:false,phase:'incoming-finished'},adapter);
 assert.equal(api.list(user).length,2,'A late closure cannot consume the next attention transition');checks++;
 assert.equal(api.observeAttention(user,{...ended,userId:999},adapter),null);checks++;
}
console.log(`PASS ${checks} shared attention observer assertions across Sales, Analyst and Reception: exact closure/alias capture, no invented result, no initial false close, idempotent updates and stale-call/user isolation. No SDK or HTTP.`);
