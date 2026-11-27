'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/seguimiento_llamada_zadarma.js','utf8').replace(/\r\n/g,'\n');
const piece=(a,b)=>{const i=source.indexOf(a),j=source.indexOf(b,i);assert(i>=0&&j>i);return source.slice(i,j);};
const token='sdk-old-approved-call-0001',newToken='sdk-new-active-call-0002',pbx='out_'+ 'a'.repeat(32);
function fixture(){
 const values={llamada_pbx_id:pbx,seguimiento_id:'7'},events=[],clears=[],stored=new Map();
 const form={querySelector:s=>({value:values[s.match(/name="([^"]+)/)[1]]})};
 const rules={metadata:{call_token:token,sdk_session_id:token,session_protocol:2,pbx_call_id:pbx,seguimiento_id:7},enviada:true};
 const map=new WeakMap([[form,rules]]);
 const box={window:{},reglasPorFormulario:map,pendingMetadata:rules.metadata,pendingInteractionId:0,pendingInteractionFeedback:{resultado:'CONTACTO_CORRECTO'},
  awaitingInteractionSave:true,queue:{},queueKey:'queue',securedTokens:new Set(),localStorage:{getItem:k=>stored.get(k)||null,setItem:(k,v)=>stored.set(k,v)},
  document:{dispatchEvent:e=>events.push(e)},CustomEvent:class{constructor(type,o){this.type=type;this.detail=o.detail;}},
  phone:{clearFinished:t=>clears.push(t)},offcanvas:{querySelector:()=>null},leerVinculoPendiente:()=>null,limpiarVinculoPendiente(){},mostrarToast(){}};
 vm.createContext(box);vm.runInContext(piece('        const writeQueue = (token, job) => {','        const guardarVinculoPendiente = function (')+
  piece('        window.IMPE_ZADARMA_LINKS = Object.freeze({','        let lastFinishedToken'),box);
 return {box,form,values,rules,map,events,clears,stored};
}
let checks=0;
const f=fixture(),link=f.box.window.IMPE_ZADARMA_LINKS.submission(f.form);
assert(link);checks++;
// A newer call and form exist before the old native receipt arrives.
f.box.pendingMetadata={call_token:newToken,seguimiento_id:8};f.box.pendingInteractionId=0;
f.values.llamada_pbx_id='out_'+'b'.repeat(32);f.values.seguimiento_id='8';
f.map.set(f.form,{metadata:f.box.pendingMetadata});
link.onCommitted({payload:{ok:true,interaccion_id:19}});
const job=JSON.parse(f.stored.get('queue'))[token];
assert.equal(job.interactionId,19);assert.equal(job.metadata.pbx_call_id,pbx);assert.equal(job.metadata.sdk_session_id,token);assert.equal(job.metadata.seguimiento_id,7);checks+=4;
assert.equal(f.box.pendingMetadata.call_token,newToken);assert.equal(f.box.pendingInteractionId,0);assert.equal(f.values.seguimiento_id,'8');checks+=3;
assert.equal(f.events[0].detail.callToken,token);assert.deepEqual(f.clears,[token]);assert.equal(link.committed,true);checks+=3;
assert.throws(()=>link.onCommitted({payload:{ok:true,interaccion_id:20}}),/otro resultado/);checks++;
assert.equal(JSON.parse(f.stored.get('queue'))[token].interactionId,19);checks++;
assert.equal(f.box.window.IMPE_ZADARMA_LINKS.submission(f.form),null);checks++;
const g=fixture(),current=g.box.window.IMPE_ZADARMA_LINKS.submission(g.form);
assert.throws(()=>current.onCommitted({payload:{ok:true,interacciones:[{id:999}]}}),/ID confirmado/);checks++;
assert.equal(g.stored.size,0);checks++;
current.onCommitted({payload:{ok:true,interaccion:{id:21}}});
assert.equal(g.box.pendingMetadata,null);assert.equal(g.box.awaitingInteractionSave,false);assert.equal(g.box.queue[token].interactionId,21);checks+=3;
console.log(`PASS ${checks} actual native-ID handoff checks: late receipt survives new call/form, exact PBX/SDK/native ID, no DOM inference, duplicate conflict, initial and informative envelopes.`);
