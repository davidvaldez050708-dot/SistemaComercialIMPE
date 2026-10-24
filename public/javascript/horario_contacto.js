(() => {
    'use strict';
    const init=()=>document.querySelectorAll('[data-horario-contacto]').forEach(root=>{
        const form=root.closest('form'),mode=root.querySelector('[data-horario-modo]'),config=root.querySelector('[data-horario-config]');
        const days=[...root.querySelectorAll('[data-horario-dia]')],from=root.querySelector('[data-horario-desde]'),to=root.querySelector('[data-horario-hasta]'),error=root.querySelector('[data-horario-error]');
        const sync=()=>{const custom=mode.value==='personalizado';config.hidden=!custom;config.disabled=!custom;from.required=to.required=custom;error.hidden=true;mode.classList.remove('is-invalid');};
        mode.addEventListener('change',sync);sync();
        config.addEventListener('input',()=>{error.hidden=true;});
        form.addEventListener('reset',()=>setTimeout(sync,0));
        form.addEventListener('submit',event=>{
            let message='';let target=mode;
            if(!['sin_preferencia','personalizado'].includes(mode.value))message='Selecciona tu disponibilidad de contacto.';
            else if(mode.value==='personalizado'){
                if(!days.some(day=>day.checked)){message='Selecciona al menos un día para contactarte.';target=days[0];}
                else if(!/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/.test(from.value)||!/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/.test(to.value)){message='Indica una hora de inicio y una hora de fin válidas.';target=!from.value?from:to;}
                else if(to.value<=from.value){message='La hora de fin debe ser posterior a la hora de inicio.';target=to;}
            }
            mode.classList.toggle('is-invalid',!mode.value);error.textContent=message;error.hidden=!message;
            if(message){event.preventDefault();event.stopImmediatePropagation();target.focus();}
        },true);
    });
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
