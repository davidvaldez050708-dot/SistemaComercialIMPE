'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const common=fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_atencion.js','utf8');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_ventas_resultados.js','utf8');
const rows=new Map(),storage={get length(){return rows.size;},key:i=>[...rows.keys()][i]??null,getItem:k=>rows.get(k)??null,setItem:(k,v)=>rows.set(k,String(v)),removeItem:k=>rows.delete(k)};
const window={IMPE_CURRENT_USER_ID:41,IMPE_PHONE_HTTP:{request:async()=>({ok:true,status:200,json:async()=>({ok:true})})}};
const sdk='sdk-fixture-sales-capture',pbx='fixture-sales-capture-pbx';
const box={window,document:{baseURI:'http://fixture.test/',addEventListener(){}},URL,Date,Map,Set,WeakMap,localStorage:storage,sessionStorage:storage,
 openContext:{sessionKey:sdk},callRef:'',draftAliases:new Set()};
vm.createContext(box);vm.runInContext(common,box);
const start=source.indexOf(" const draftKey=ref=>"),end=source.indexOf(' const storeDraft=',start);assert(start>=0&&end>start);
vm.runInContext(source.slice(start,end)+'\nids=captureIds;read=readDraft;',box);
(async()=>{
 let checks=0;const api=window.IMPE_CALL_DRAFTS;
 assert.deepEqual(Array.from(box.ids()),[sdk]);checks++;
 api.localSave(41,['sdk-'+sdk],{observacion:'Borrador anterior a la unificación'},{adaptador:'VENTAS'});
 api.localSave(41,[sdk],{},{adaptador:'VENTAS',cierre_local:{at:Date.now(),transferencia:false,conciliado:false}});
 assert.equal(box.read('').values.observacion,'Borrador anterior a la unificación');checks++;
 api.localSave(41,[sdk],{observacion:''});
 assert.equal(box.read('').values.observacion,'','An explicitly cleared newer field is not restored from a legacy alias');checks++;
 api.localSave(41,[sdk],{observacion:'Borrador anterior a la unificación'});
 box.callRef=pbx;const ids=Array.from(box.ids(pbx));
 assert.equal(ids.length,3);assert.equal(ids.includes(sdk),true);assert.equal(ids.includes('sdk-'+sdk),true);checks+=3;
 const captured=api.localSave(41,ids,{resultado:'BUZON_VOZ'});
 assert.equal(captured.valores.observacion,'Borrador anterior a la unificación');checks++;
 await api.remove(41,[pbx],'fixture-csrf',captured.saved_at);
 assert.equal(api.list(41).length,0);assert.equal(api.localRead(41,sdk),null);assert.equal(api.localRead(41,'sdk-'+sdk),null);checks+=3;
 console.log(`PASS ${checks} Sales capture identity assertions: canonical host SDK id, legacy double-prefix migration, PBX alias union and single-reference close clears the exact group. No SDK or network.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
