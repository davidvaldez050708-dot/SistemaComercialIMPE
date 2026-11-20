document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    const root=document.querySelector('[data-wa-assignment-root]');
    if(!root)return;
    const feedback=root.querySelector('[data-wa-assignment-feedback]');
    root.addEventListener('submit',async function(event){
        const form=event.target.closest('[data-wa-assignment-form]');
        if(!form)return;
        event.preventDefault();
        if(form.dataset.pending==='1' || !form.reportValidity())return;
        form.dataset.pending='1';
        const button=form.querySelector('button');button.disabled=true;
        feedback.className='';feedback.textContent='Asignando conversación…';
        try {
            const response=await fetch(form.action,{method:'POST',credentials:'same-origin',body:new FormData(form),headers:{'X-Requested-With':'fetch'}});
            const data=await response.json();
            if(!response.ok || !data.ok)throw Error(data.mensaje||'No se pudo confirmar la asignación. Actualiza la bandeja.');
            feedback.className='alert alert-success';feedback.textContent=data.mensaje;
            form.closest('[data-wa-assignment-row]').remove();
            const count=root.querySelector('[data-wa-pending-count]');count.textContent=String(Math.max(0,Number(count.textContent)-1));
            root.querySelector('[data-wa-assignment-empty]').hidden=Boolean(root.querySelector('[data-wa-assignment-row]'));
        } catch(error){feedback.className='alert alert-danger';feedback.textContent=error instanceof SyntaxError?'No se pudo confirmar la asignación. Actualiza la bandeja antes de reintentar.':(error.message||'No se pudo confirmar la asignación. Actualiza la bandeja antes de reintentar.');}
        finally {form.dataset.pending='0';button.disabled=false;}
    });
});
