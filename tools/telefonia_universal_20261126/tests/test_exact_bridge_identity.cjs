'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
(async()=>{
 const calls=[],events=[],box={URL,Request,Response,FormData,console,CustomEvent:class{constructor(type,o){this.type=type;this.detail=o.detail;}},
  document:{dispatchEvent:e=>events.push(e)},window:{fetch:async(url,init)=>{calls.push({url,id:init.body.get('interaccion_id')});return Response.json({ok:true,interaccion_id:99});}}};
 vm.runInNewContext(fs.readFileSync(__dirname+'/../../../public/javascript/seguimiento_interaccion_id_bridge.js','utf8'),box);
 const registration=new FormData();registration.set('seguimiento_id','7');registration.set('canal','LLAMADA');
 await box.window.fetch('/?action=registrarInteraccionTrabajo',{body:registration});
 assert.equal(events[0].detail.interaccionId,99);
 const link=new FormData();link.set('seguimiento_id','7');link.set('interaccion_id','19');
 await box.window.fetch('/prueba_telefonia/api/vincular_interaccion_zadarma.php',{body:link});
 assert.equal(calls[1].id,'19','A prior native receipt for the same institution must not replace the explicitly queued native ID');
 link.delete('interaccion_id');await box.window.fetch('/prueba_telefonia/api/vincular_interaccion_zadarma.php',{body:link});
 assert.equal(calls[2].id,null,'Never guess a missing native ID from the last interaction');
 console.log('PASS 3 actual compatibility-bridge identity checks: original native confirmation, explicit ID preserved, absent ID never guessed. Mock fetch.');
})().catch(e=>{console.error(e);process.exitCode=1;});
