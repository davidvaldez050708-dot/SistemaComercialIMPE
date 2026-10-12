'use strict';
// La identificación de los botones está probada sin depender del SDK remoto.
const assert = require('node:assert/strict');
const factory = require('../public/javascript/telefonia_entrantes_bridge.js');
const calls = [];
function makeButton(color,x,y,label='') {
    return {
        disabled:false,
        style:{color},
        getAttribute(key) {return key==='aria-label' ? label : null;},
        getBoundingClientRect() {return {left:x,top:y,width:64,height:64};},
        querySelectorAll() {return [];},
        click() {calls.push(label || color);}
    };
}
const red=makeButton('rgb(220,10,18)',50,60);
const green=makeButton('rgb(40,185,20)',125,60);
const win={getComputedStyle(el) {
    return {color:el.style.color,fill:el.style.color,stroke:'none',
        backgroundColor:'transparent'};
}};
const doc={
    defaultView:win,
    querySelectorAll(selector) {
        return selector==='iframe'?[]:[red,green];
    }
};
let ringing=true;
let talking=false;
const bridge=factory.create(doc,()=>ringing,()=>talking);
assert.equal(bridge.available(),true);
assert.equal(bridge.answer(),true);
assert.equal(calls.length,1);
assert.equal(calls[0],'rgb(40,185,20)');
ringing=false;
talking=true;
assert.equal(bridge.answer(),false);
assert.equal(bridge.canHangup(),true);
assert.equal(bridge.hangup(),true);
assert.equal(calls[1],'rgb(220,10,18)');
talking=false;
assert.equal(bridge.hangup(),false);
const unknown=factory.create({...doc,querySelectorAll() {return [];}},()=>true,()=>false);
assert.equal(unknown.available(),false);
assert.equal(unknown.answer(),false);
assert.equal(calls.length,2,'no se pulsa ningún botón equivocado');
// Zadarma puede dibujar los controles como SVG dentro de un DIV interactivo.
const svgCalls=[];
function fakeIcon(color,x) {
    const button={
        tagName:'DIV',style:{},parentElement:null,
        hasAttribute(k){return k==='onclick';},
        getAttribute(){return null;},
        getBoundingClientRect(){return {left:x,top:35,width:68,height:68};},
        click(){svgCalls.push(color);}
    };
    const path={
        tagName:'path',parentElement:button,
        style:{fill:color},
        hasAttribute(){return false;},
        getAttribute(k){return k==='fill'?color:null;},
        getBoundingClientRect(){return {left:x+14,top:49,width:36,height:36};}
    };
    const svg={
        tagName:'svg',parentElement:button,style:{color},
        hasAttribute(){return false;},
        getAttribute(){return null;},
        getBoundingClientRect(){return {left:x+13,top:48,width:38,height:38};}
    };
    return {button,svg,path};
}
const redSvg=fakeIcon('#c91b24',40);
const greenSvg=fakeIcon('#32be19',140);
const svgDoc={
    defaultView:{getComputedStyle(el) {
        return {color:el.style?.color||'transparent',fill:el.style?.fill||'none',
            stroke:'none',backgroundColor:'transparent',cursor:'pointer'};
    }},
    querySelectorAll(sel){return sel==='iframe'?[]:[
        greenSvg.svg,greenSvg.path,redSvg.svg,redSvg.path
    ];}
};
let svgRinging=true,svgTalking=false;
const svgBridge=factory.create(svgDoc,()=>svgRinging,()=>svgTalking);
assert.equal(svgBridge.available(),true,'los SVG del proveedor son reconocibles');
assert.equal(svgBridge.answer(),true);
assert.deepEqual(svgCalls,['#32be19']);
svgRinging=false;svgTalking=true;
assert.equal(svgBridge.canHangup(),true,
    'un icono SVG con path interno no debe contarse como dos botones');
assert.equal(svgBridge.hangup(),true);
assert.deepEqual(svgCalls,['#32be19','#c91b24']);
console.log('Puente WebRTC: OK (HTML, SVG anidados, colgado y control desconocido)');
