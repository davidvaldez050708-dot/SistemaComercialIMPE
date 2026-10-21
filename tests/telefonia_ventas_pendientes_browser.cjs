/* Regression dirigida: cierres sin contestar y persistencia del resultado.
 * node tests/telefonia_ventas_pendientes_browser.cjs
 * No utiliza red, base de datos ni navegador real.
 */
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const code=fs.readFileSync(require('node:path').resolve(__dirname,'../public/javascript/telefonia_ventas_shell.js'),'utf8');
const local=new Map(),session=new Map(),timers=[],holder={call:null};
const storage=map=>({getItem:key=>map.get(key)||null,setItem:(key,v)=>map.set(key,v),removeItem:key=>map.delete(key)});
const el=()=>({hidden:false,textContent:'',dataset:{},append(){},after(){},addEventListener(type,fn){this['on'+type]=fn;}});
const close=el(),actions={buttons:[],querySelector:sel=>sel==='[data-incoming-close-finished]'?close:null,insertBefore(button){this.buttons.push(button);},append(button){this.buttons.push(button);}};
const panel={querySelector:sel=>sel==='.persistent-phone-origin'?el():sel==='.persistent-phone-actions'?actions:null};
const frame={src:'',contentWindow:{postMessage(){},dispatchEvent(){},Event:class{}}};
const phone={getCallState:()=>holder.call,getPhoneStatus:()=>({active:Boolean(holder.call?.active)}),isResultFormOpen:()=>false,dismissFinishedCall(){}};
const window={IMPE_PHONE_SHELL_CONFIG:{userId:44,salesResults:true,extension:'150',salesHistoryUrl:'https://example.test/history',salesDetailUrl:'https://example.test/detail'},IMPE_TELEPHONY_SHELL:phone,addEventListener(){}};
const document={getElementById:id=>id==='impeCrmFrame'?frame:null,querySelector:sel=>sel==='[data-incoming-panel]'?panel:null,createElement:el};
vm.runInNewContext(code,{window,document,localStorage:storage(local),sessionStorage:storage(session),setInterval:fn=>{timers.push(fn);},fetch:async()=>({ok:false,json:async()=>({ok:false})}),URL,location:{href:'https://example.test/shell',origin:'https://example.test'}});
const tick=()=>timers.forEach(fn=>fn()),button=actions.buttons[0];
assert.ok(button,'Debe crear acción de resultado en el shell');
holder.call={active:true,direction:'incoming',phase:'incoming-ringing',callToken:'sdk-1',pbxCallId:'',context:{type:'SDK_INCOMING'}};tick();
holder.call={active:false,direction:'incoming',phase:'incoming-finished',callToken:'sdk-1',pbxCallId:'',context:{type:'SDK_INCOMING'}};tick();
assert.equal(button.textContent,'Revisar llamadas');
assert.equal(button.hidden,false);
holder.call={active:true,direction:'incoming',phase:'incoming-ringing',callToken:'sdk-2',pbxCallId:'ABCDEF123456',context:{type:'INCOMING'}};tick();
holder.call={active:false,direction:'incoming',phase:'incoming-finished',callToken:'sdk-2',pbxCallId:'ABCDEF123456',context:{type:'INCOMING'}};tick();
assert.equal(button.textContent,'Registrar resultado comercial');
assert.equal(button.hidden,false);
assert.match(local.get('impe:sales-pending:44'),/ABCDEF123456/);
button.onclick();assert.match(frame.src,/pbx_call_id=ABCDEF123456/);
console.log('OK: no contestada, referencia PBX, persistencia y apertura de resultado');
