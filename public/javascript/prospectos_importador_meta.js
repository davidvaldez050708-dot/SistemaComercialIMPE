(function () {
    'use strict';
    var layer=document.querySelector('[data-meta-overlay]');
    if (!layer) return;
    var dialog=layer.querySelector('.meta-import-dialog'),confirm=layer.querySelector('[data-meta-confirmacion]');
    var open=document.querySelector('[data-meta-abrir]'),file=layer.querySelector('[data-meta-file]');
    var error=layer.querySelector('[data-meta-error]'),status=layer.querySelector('[data-meta-estado]');
    var progress=layer.querySelector('[data-meta-progreso]'),bar=layer.querySelector('[data-meta-barra]');
    var button=layer.querySelector('[data-meta-confirmar]');
    var lastFocus=null,scrollBefore='',running=false;
    function show(){
        if (!layer.hidden) return;
        lastFocus=document.activeElement;
        scrollBefore=document.body.style.overflow;
        document.body.style.overflow='hidden';
        layer.hidden=false;dialog.focus();
    }
    function hide(){
        if (running) return;
        layer.hidden=true;
        document.body.style.overflow=scrollBefore;
        if (lastFocus && lastFocus.isConnected && typeof lastFocus.focus==='function') lastFocus.focus();
    }
    if(open)open.addEventListener('click',show);
    layer.querySelectorAll('[data-meta-cerrar]').forEach(function(el){el.addEventListener('click',hide);});
    if(layer.dataset.metaAutoabrir==='1')show();
    if(file)file.addEventListener('change',function(){
        var name=layer.querySelector('[data-meta-file-nombre]');
        if(name)name.textContent=file.files.length?file.files[0].name:'Selecciona un archivo.';
    });
    function focusables(){
        return Array.from(dialog.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled]),a[href],summary'))
            .filter(function(el){return el.getClientRects().length>0;});
    }
    layer.addEventListener('keydown',function(e){
        if(e.key==='Escape'){e.preventDefault();hide();return;}
        if(e.key!=='Tab')return;
        var choices=focusables();
        if(!choices.length){e.preventDefault();dialog.focus();return;}
        var first=choices[0],last=choices[choices.length-1];
        if(e.shiftKey&&(document.activeElement===first||document.activeElement===dialog)){
            e.preventDefault();last.focus();
        } else if(!e.shiftKey&&document.activeElement===last){
            e.preventDefault();first.focus();
        }
    });
    if(!confirm)return;
    function refreshCampaigns(){
        confirm.querySelectorAll('[data-meta-campania]').forEach(function(row){
            var check=row.querySelector('[data-meta-excepcion]'),select=row.querySelector('[data-meta-programa]');
            if(!check||!select)return;
            if(!check.disabled)select.disabled=!check.checked;
            select.hidden=!check.checked;
            row.querySelector('[data-meta-excepcion-control]').hidden=!check.checked;
            // Actualizar también el selector visual compartido del sistema.
            select.dispatchEvent(new Event('change',{bubbles:true}));
            var inherited=(row.dataset.metaFormularios||'').split(' ').map(function(key){
                var form=confirm.querySelector('[data-meta-formulario="'+key+'"]');
                return form?form.options[form.selectedIndex].textContent.trim():'';
            }).filter(function(value,index,all){return value&&all.indexOf(value)===index;});
            row.querySelector('[data-meta-herencia]').textContent=check.checked?'Excepción de campaña':'Usa: '+inherited.join(' · ');
        });
    }
    confirm.querySelectorAll('[data-meta-excepcion],[data-meta-formulario]').forEach(function(el){el.addEventListener('change',refreshCampaigns);});
    refreshCampaigns();
    if(!window.fetch || !window.FormData)return;
    confirm.addEventListener('submit',async function(e){
        e.preventDefault();
        if(running)return;
        running=true;
        if(error)error.hidden=true;
        button.disabled=true;
        button.textContent='Importando prospectos…';
        if(status)status.hidden=false;
        var payload=new FormData(confirm);
        // Congelar los controles después de capturar el envío. Los reintentos
        // usan exactamente la misma clasificación que guardó el servidor.
        confirm.querySelectorAll('select,[data-meta-excepcion]').forEach(function(el){
            el.disabled=true;
            if(el.tagName==='SELECT')el.dispatchEvent(new Event('change',{bubbles:true}));
        });
        var destino=confirm.action,volver=confirm.dataset.metaVolver;
        try {
            for(var bloque=0;bloque<100;bloque++) {
                var respuesta=await fetch(destino,{
                    method:'POST',credentials:'same-origin',body:payload,
                    headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
                });
                var resultado;
                try{resultado=await respuesta.json();}catch(ex){throw new Error('El servidor devolvió una respuesta inesperada. Comprueba tu sesión.');}
                if(!respuesta.ok || !resultado.ok)throw new Error(resultado.mensaje||'No se pudo procesar este lote.');
                var cantidad=Math.max(1,Number(resultado.total)||1),avance=Number(resultado.avance)||0;
                if(bar)bar.style.width=Math.min(100,Math.round(100*avance/cantidad))+'%';
                if(progress)progress.textContent=avance+' de '+cantidad+' procesados · '+resultado.nuevos+' nuevos · '+resultado.repetidos+' ya existentes.';
                if(resultado.terminado){window.location.assign(volver);return;}
            }
            throw new Error('Se detuvo la importación para evitar procesar demasiados lotes. Puedes reanudar.');
        }catch(ex){
            if(error){error.textContent=ex.message||'Error durante la importación.';error.hidden=false;}
            button.textContent='Reintentar importación';
            button.disabled=false;
            running=false;
        }
    });
})();
