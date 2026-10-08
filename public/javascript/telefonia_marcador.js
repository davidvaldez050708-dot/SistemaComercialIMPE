(function(){
'use strict';
document.addEventListener('DOMContentLoaded',function(){
const root=document.querySelector('[data-telephony-dialer]');if(!root)return;
const api=window.IMPE_TELEPHONY_PERSISTENT;
const input=root.querySelector('[data-telephony-dial-number]');
const call=root.querySelector('[data-telephony-dial-call]');
const hangup=root.querySelector('[data-telephony-dial-hangup]');
const status=root.querySelector('[data-telephony-dial-status]');
let ready=false,busy=false;
function message(t,error){status.textContent=String(t||'');status.classList.toggle('is-error',!!error)}
function render(){
const s=api?.getState?.()||{};const active=!!s.active;
call.disabled=!ready||active||busy;hangup.disabled=!active;
if(active)message(s.message||'Llamada activa…');
else if(s.phase==='finished')message(s.message||'Llamada finalizada. Puedes marcar nuevamente.');
else if(s.phase==='error')message(s.message||'Error de llamada.',true);
}
root.querySelectorAll('[data-dial-key]').forEach(function(b){
b.addEventListener('click',function(){const key=b.dataset.dialKey;
if(key==='⌫')input.value=input.value.slice(0,-1);
else if(key==='+'){if(!input.value)input.value='+';}else input.value+=key;
input.focus();});
});
function normalize(value){
const raw=String(value||'').trim();
if(!/^\+?[\d\s().-]+$/.test(raw))throw Error('Introduce un número telefónico válido.');
let digits=raw.replace(/\D/g,'');if(digits.length===10)digits='52'+digits;
if(!/^\d{11,15}$/.test(digits))throw Error('Usa 10 dígitos de México o número internacional con prefijo.');
return digits;
}
root.querySelector('[data-telephony-dialer-form]').addEventListener('submit',function(e){
e.preventDefault();if(!ready||busy)return;
let dest;try{dest=normalize(input.value)}catch(err){message(err.message,true);return}
busy=true;render();
void api.startCall({destination:dest,institution:'',context:{type:'DIALER',source:'VENTAS'}})
.then(function(){message('Solicitud enviada. Esperando respuesta de Zadarma…')})
.catch(function(err){message(err.message||'No fue posible marcar.',true)})
.finally(function(){busy=false;render()});
});
hangup.addEventListener('click',function(){api?.hangup?.();message('Finalizando llamada…')});
if(!api||typeof api.probe!=='function'){message('Telefonía no disponible.',true);return}
void api.probe().then(function(info){ready=!!info?.permite_salientes;message(ready?'Listo para marcar. Permite acceso al micrófono.':'No tienes salientes habilitadas.',!ready);render()})
.catch(function(err){message(err.message||'No se pudo preparar la extensión.',true)});
api.subscribe(render);render();
});
})();