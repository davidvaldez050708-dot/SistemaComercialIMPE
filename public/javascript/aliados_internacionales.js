document.addEventListener('DOMContentLoaded', function () {
    const pais=document.querySelector('#aliado_pais');
    const actualizar=function () {
        const otro=document.querySelector('[data-aliado-otro-pais]');
        otro?.classList.toggle('d-none',pais?.value!=='OTRO');
        if (otro?.querySelector('input')) otro.querySelector('input').required=pais?.value==='OTRO';
        document.querySelector('[data-aliado-mexico]')?.classList.toggle('d-none',pais?.value!=='MX');
    };
    pais?.addEventListener('change',actualizar);actualizar();
    const modal=document.querySelector('[data-aliado-edit-open]');
    if(modal&&window.bootstrap)bootstrap.Modal.getOrCreateInstance(modal).show();
});
