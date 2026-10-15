'use strict';
// Ejecutar la lógica real del observador con un DOM mínimo en Node.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/javascript/telefonia_analista_historial_pasivo.js','utf8');
const results = fs.readFileSync('public/javascript/seguimiento_entrantes_analista.js','utf8');
assert.ok(results.includes("type:'IMPE_ANALYST_RESULT_SAVED'"));
assert.ok(results.indexOf("type:'IMPE_ANALYST_RESULT_SAVED'") >
          results.indexOf("if (!res.ok || !data.ok)"));

function element() {
    const listeners={};
    return {
        hidden:false, textContent:'', innerHTML:'', style:{},
        dataset:{},
        classList:{add(){}, remove(){}, toggle(){}},
        setAttribute(){}, removeAttribute(){},
        addEventListener(name,fn){(listeners[name] ||= []).push(fn);},
        click(){for(const fn of (listeners.click||[])) fn({});},
        appendChild(){}, listeners
    };
}
async function scenario(conversation) {
    let now=1000000, stored='', captures=[], dockDismissed=0;
    const els=new Map();
    const el=k=>{if(!els.has(k)) els.set(k,element());return els.get(k)};
    const panel=element();
    const actions=element();
    panel.querySelector=(s)=>s==='.persistent-phone-actions'?actions:el(s);
    const listeners={};
    const intervals=[];
    const doc={
        body:{}, head:{appendChild(){}},
        createElement(){return element()},
        querySelector(s){
            if (s==='[data-incoming-panel]') return panel;
            if (s==='[data-analyst-dismiss-call]') return dock;
            return null;
        },
        getElementById(s){return s==='impeCrmFrame'?crm:null}
    };
    const dock=element();
    dock.addEventListener('click',()=>dockDismissed++);
    const frm={location:{href:'https://example.test/index.php'}};
    const crm={src:'',contentWindow:frm,addEventListener(){}};
    class DateStub extends Date { static now(){return now} }
    class Channel {constructor(name){this.name=name}postMessage(payload){captures.push(payload)}close(){}}
    const root={
        document:doc, console, Date:DateStub, BroadcastChannel:Channel,
        IMPE_ANALYST_WEBRTC_JOURNAL:{enabled:true,endpoint:'/event',csrf:'abc'},
        IMPE_PHONE_SHELL_CONFIG:{
            userId:8,analystIncomingResults:true,incomingResultsUrl:
                'https://example.test/index.php?controller=seguimientoVinculacion&action=llamadasEntrantes'
        },
        location:{origin:'https://example.test',href:'https://example.test/index.php'},
        localStorage:{getItem(){return stored}},
        fetch:async()=>({ok:true,json:async()=>({ok:true,interna:false})}),
        setInterval(fn){intervals.push(fn)},
        addEventListener(name,fn){(listeners[name]||=[]).push(fn)},
        MutationObserver:class {observe(){}}
    };
    const send=(state)=>{
        now=state.updatedAt;stored=JSON.stringify(state);
        for(const fn of (listeners.storage||[]))fn({key:'impe:telephony:state:8'});
    };
    vm.runInNewContext(source,{window:root,document:doc, Date:DateStub,
        URL, URLSearchParams,console, setInterval:root.setInterval});
    const id='sdk-abcd1234-abcd5678';
    const base={userId:8,active:true,direction:'incoming',callToken:id,
        destination:'+527771234567',context:{type:'SDK_INCOMING'},duration:0};
    send({...base,phase:'incoming-ringing',status:'incoming-ringing',updatedAt:now});
    if(conversation){
        now += 1500;
        send({...base,phase:'in-progress',status:'in-progress',
              answeredAtMs:now-400,updatedAt:now});
        now += 45200;
        send({...base,active:false,phase:'incoming-finished',
              status:'completed',answeredAtMs:0,duration:46,
              updatedAt:now});
    } else {
        now+=5000;
        send({...base,active:false,phase:'incoming-finished',
              status:'no-answer',duration:0,updatedAt:now});
    }
    for(let i=0;i<12;i++)await Promise.resolve();
    await new Promise(r=>setImmediate(r));
    for(const interval of intervals) interval();
    const close=el('[data-incoming-close-finished]');
    const register=el('[data-incoming-register-finished]');
    const counter=el('[data-incoming-timer]');
    if(conversation){
        assert.equal(close.hidden,true,'impedir Cerrar en llamada atendida');
        assert.equal(register.hidden,false,'registrar en modal principal');
        assert.equal(counter.textContent,'00:46','mantener duración');
        close.click();
        assert.equal(close.hidden,true,'clic de cierre sin registrar no puede descartarla');
        register.click();
        assert.ok(crm.src.includes('sdk_session_id='+id));
        assert.equal(panel.hidden,true,'no tapar el formulario de resultado');
        assert.equal(root.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG.hasFinalized(),true);
        for(const fn of listeners.message||[])fn({
            origin:'https://example.test',source:frm,
            data:{type:'IMPE_ANALYST_RESULT_SAVED',callId:id}});
        assert.equal(root.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG.hasFinalized(),false);
        assert.equal(captures.at(-1)?.action,'CLEAR_FINISHED');
        assert.equal(dockDismissed,1,'quitar aviso inferior tras guardar');
    } else {
        assert.equal(counter.textContent,'00:00');
        assert.equal(close.hidden,false,'perdida puede cerrarse');
        assert.equal(register.hidden,true,'perdida sin expediente');
    }
}
(async()=>{await scenario(true);await scenario(false);
    console.log('Analista: duración 46s, bloqueo de cierre, guardado y perdida 00:00 OK');
})().catch(e=>{console.error(e);process.exitCode=1});
