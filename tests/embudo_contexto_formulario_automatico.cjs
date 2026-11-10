'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const card=fs.readFileSync('public/javascript/embudo_comercial_movimientos.js','utf8');
const form=fs.readFileSync('public/javascript/telefonia_ventas_resultados.js','utf8');
const a=form.indexOf(' const numeroComparable='),z=form.indexOf(" form.addEventListener('submit'",a);
assert.ok(a>0&&z>a,'comparación limitada al formulario automático');
const pure=form.slice(a,z);
function context(input, overrides={}) {
 const ctx=Object.assign({
   usuarioId:22, asignacionId:'2', numero:'7775083225', creada:Date.now()
 },input||{});
 const sandbox={
   window:{IMPE_CURRENT_USER_ID:overrides.usuarioId??22},
   sessionStorage:{getItem(key){assert.equal(key,'impe:embudo:llamada-pendiente');return JSON.stringify(ctx)}},
   call:Object.assign({registro:null,conectada:true,pbx_call_id:'out_prueba123456',
     direccion:'Saliente',numero:'7775083225'},overrides.call||{}),
   callRef:overrides.callRef??'out_prueba123456'
 };
 return vm.runInNewContext(pure+' asignacionAutomatica();',sandbox);
}
assert.equal(context({}),'2','la misma llamada saliente vincula la tarjeta elegida');
assert.equal(context({numero:'+52 (777) 508 3225'}),'2','aceptar el mismo móvil mexicano con prefijo +52');
assert.equal(context({numero:'7770000000'}),'','otro número no vincula');
assert.equal(context({asignacionId:'2 OR 1=1'}),'','id no validado');
assert.equal(context({creada:Date.now()-31*60*1000}),'','no heredar marcación antigua');
assert.equal(context({creada:Date.now()+10000}),'','no aceptar fechas futuras');
assert.equal(context({usuarioId:24}),'','otro usuario no hereda asignación');
assert.equal(context({}, {call:{registro:{resultado:'PROSPECTO_POTENCIAL'}}}),'','llamada registrada es inmutable');
assert.equal(context({}, {call:{conectada:false}}),'','no contacto PBX');
assert.equal(context({}, {call:{direccion:'Entrante'}}),'','no vincular entrante de otro origen');
assert.equal(context({}, {call:{numero:'0000000000'}}),'','no vincular otro destino');
assert.equal(context({}, {callRef:'out_otra123456'}),'','otro identificador PBX no vincula');
assert.equal(context({}, {usuarioId:0}),'','usuario inválido no vincula');
assert.ok(card.includes("sessionStorage.setItem('impe:embudo:llamada-pendiente'"),
 'guardar contexto explícito del botón de tarjeta');
assert.ok(card.includes("b.closest('.impe-embudo-card')"),'id de tarjeta y no del último prospecto');
assert.ok(card.includes("dial.openDialer({number:numero})"),'marcador actual intacto');
assert.ok(form.includes('const automatico=Boolean(openContext.prepared'), 'no usar contexto para resultados ajenos');
assert.ok(form.includes('root.dataset.autoCall&&root.dataset.autoCall===callRef'),
 'identificar sólo llamada automática propia');
assert.ok(form.includes("campo.value=/^[1-9][0-9]*$/.test(vinculo)?vinculo:''"), 'id sólo si validado');
assert.ok(form.includes('if(vinculo)sessionStorage.removeItem'), 'consumir contexto al guardar');
assert.ok(form.includes("form.elements.prospecto_asignacion_id.value"),'formulario V4 opt-in intacto');
console.log('CHECK OK: marcado explícito, vínculo automático, PBX, usuario, teléfono, 30 min y resultados inmutables.');
