const assert = require('node:assert/strict');
const {consultarPagina} = require('../public/javascript/seguimiento_candidatos_paginacion.js');
const response = (body, ok = true) => ({ok, headers:{get:()=> 'application/json'}, json:async()=>body});
(async () => {
    let llamadas = 0;
    const progreso = [];
    const r = await consultarPagina('/ficticio', {}, m => progreso.push(m), async () => {
        llamadas++;
        return response(llamadas < 12
            ? {ok:true, denue_continuar:true, denue_revisados:100*llamadas}
            : {ok:true, candidatos:[{clave_origen:'DENUE:1101'}], total_exacto:true, denue_continuar:false});
    });
    assert.equal(llamadas, 12); assert.equal(progreso.length, 11);
    assert.equal(r.candidatos[0].clave_origen, 'DENUE:1101');
    llamadas = 0;
    await consultarPagina('/ficticio', {}, ()=>{}, async () => ++llamadas === 1
        ? response({ok:false,reintentable:true,mensaje:'Interrupción'},false)
        : response({ok:true,denue_continuar:false}));
    assert.equal(llamadas,2,'Recuperar pendiente automáticamente.');
    llamadas = 0;
    await assert.rejects(consultarPagina('/ficticio', {}, ()=>{}, async () => {
        llamadas++; return response({ok:false,reintentable:false,mensaje:'Autorización rechazada'},false);
    }), /Autorización/);
    assert.equal(llamadas,1,'No reintentar fallos permanentes.');
    await assert.rejects(consultarPagina('/ficticio', {}, ()=>{}, async () =>
        response({ok:true,denue_continuar:true,denue_revisados:100})), /avanzar/);
    const abort = new AbortController();
    await assert.rejects(consultarPagina('/ficticio',{signal:abort.signal},()=>abort.abort(),async()=>
        response({ok:true,denue_continuar:true,denue_revisados:100})), {name:'AbortError'});
    console.log('Cliente DENUE: continuación >1000, recuperación automática, error permanente y cancelación OK');
})().catch(error => {console.error(error);process.exitCode=1;});
