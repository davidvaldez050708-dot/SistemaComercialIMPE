'use strict';
document.querySelectorAll('[data-expediente-grabacion]').forEach(detalle=>{
    detalle.addEventListener('toggle',async()=>{
        if(!detalle.open || detalle.dataset.estado)return;
        const destino=detalle.querySelector('[role="status"]');
        detalle.dataset.estado='cargando';
        destino.textContent='Consultando grabación…';
        try{
            const url=detalle.dataset.expedienteGrabacion;
            const respuesta=await fetch(url,{credentials:'same-origin',cache:'no-store'});
            const datos=await respuesta.json();
            destino.textContent='';
            if(!respuesta.ok || !datos.ok || !datos.disponible ||
                !window.IMPE_TELEPHONY_SEGMENTS?.create(destino,url,Number(datos.segmentos),datos.segmentos_info||[])){
                throw new Error();
            }
            detalle.dataset.estado='lista';
        }catch(_){
            delete detalle.dataset.estado;
            destino.textContent='Grabación no disponible para esta llamada. Puedes cerrar y volver a consultar.';
        }
    });
});
