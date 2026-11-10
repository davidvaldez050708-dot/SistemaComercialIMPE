// Regresión aislada: señal de resultado guardado -> sólo vista del embudo.
// No PBX, SIP, transferencias ni datos reales.
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const board=fs.readFileSync(path.join(root,'public/javascript/embudo_comercial_movimientos.js'),'utf8');
const sales=fs.readFileSync(path.join(root,'public/javascript/telefonia_ventas_resultados.js'),'utf8');

assert.ok(sales.includes("if(!r.ok||!j.ok)"),'el guardado requiere OK real del servidor');
const savedAt=sales.indexOf("localStorage.setItem('impe:embudo:resultado-guardado:'");
const serverOk=sales.indexOf("if(!r.ok||!j.ok)");
assert.ok(savedAt>serverOk,'la señal debe emitirse exclusivamente después del OK');
assert.ok(sales.includes("if(usuarioGuardado>0&&/^[1-9][0-9]*$/.test(asignacionGuardada))"),
 'no emitir avisos sin asignación comercial válida');

function setup(opts={}){
 let now=100000,refreshes=0,active=Boolean(opts.active),hidden=Boolean(opts.hidden);
 const stored=new Map(),winEvents={},docEvents={};
 const rootNode={querySelectorAll:()=>[],querySelector:()=>null};
 const document={
  get hidden(){return hidden;},
  querySelector:s=>s==='.impe-embudo'?rootNode:null,
  addEventListener:(type,fn)=>{docEvents[type]=fn;}
 };
 const window={
  IMPE_CURRENT_USER_ID:8,
  IMPE_TELEPHONY_PERSISTENT:{getState:()=>({active})},
  location:{reload:()=>{refreshes++;}},
  addEventListener:(type,fn)=>{winEvents[type]=fn;},
 };
 window.parent=window;
 const localStorage={
  getItem:key=>stored.get(key)||null,
  setItem:(key,value)=>{stored.set(key,value);}
 };
 class MockDate extends Date{static now(){return now;}}
 vm.runInNewContext(board,{window,document,localStorage,Date:MockDate,console},{filename:'embudo_comercial_movimientos.js'});
 const key='impe:embudo:resultado-guardado:8';
 const notify=(overrides={})=>{
  now+=25;
  stored.set(key,JSON.stringify(Object.assign({usuarioId:8,asignacionId:'42',creada:now},overrides)));
  winEvents.storage({key});
 };
 return {notify,key,events:winEvents,docs:docEvents,
  state:()=>({refreshes}),active:v=>{active=v;},hidden:v=>{hidden=v;},advance:v=>{now+=v;}
 };
}

const auto=setup();auto.notify();assert.equal(auto.state().refreshes,1,'actualiza el tablero visible tras guardar');
auto.events.storage({key:auto.key});assert.equal(auto.state().refreshes,1,'no recarga dos veces');

const unrelated=setup();unrelated.notify({usuarioId:9});
assert.equal(unrelated.state().refreshes,0,'no actualizar tableros de otros asesores');
unrelated.notify({asignacionId:'../'});assert.equal(unrelated.state().refreshes,0,'ignorar asignación inválida');
unrelated.notify({creada:50000});assert.equal(unrelated.state().refreshes,0,'ignorar guardados anteriores a la vista');
unrelated.notify({creada:0});assert.equal(unrelated.state().refreshes,0,'ignorar ausencia de fecha');

const busy=setup({active:true});busy.notify();
assert.equal(busy.state().refreshes,0,'jamás recargar durante llamada activa');
busy.active(false);busy.events.focus();
assert.equal(busy.state().refreshes,1,'actualizar al terminar llamada sin polling');

const background=setup({hidden:true});background.notify();
assert.equal(background.state().refreshes,0,'no alterar vista oculta');
background.hidden(false);background.docs.visibilitychange();
assert.equal(background.state().refreshes,1,'actualizar al recuperar visibilidad');

const otherTab=setup();otherTab.notify({usuarioId:7});
otherTab.events.pageshow({persisted:true});
assert.equal(otherTab.state().refreshes,0,'ignorar otros usuarios tras BFCache');

assert.ok(!board.includes("setInterval("),'el embudo no debe hacer consultas periódicas');
console.log('Embudo: refresco automático por resultado confirmado, aislamiento de usuario, llamada activa, BFCache y deduplicación OK');
