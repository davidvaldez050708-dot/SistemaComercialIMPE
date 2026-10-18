/* Las perdidas usan las campanas existentes y se leen sólo por su propietario. */
document.addEventListener('DOMContentLoaded',()=>{
 const root=document.querySelector('[data-phone-notification-read]');if(!root)return;
 document.addEventListener('click',async event=>{
  const item=event.target.closest('[data-telefonia-id]');if(!item)return;
  event.preventDefault();event.stopImmediatePropagation();
  const destination=new URL(item.getAttribute('href'),window.location.href);
  if(destination.origin!==window.location.origin)return;
  try{
   const response=await fetch(root.dataset.phoneNotificationRead,{method:'POST',credentials:'same-origin',body:new URLSearchParams({id:item.dataset.telefoniaId,csrf_token:root.dataset.phoneNotificationCsrf})});
   const data=await response.json();if(!response.ok||!data.ok)throw Error('No se pudo marcar la notificación. Intenta nuevamente.');
   window.location.href=destination.href;
  }catch(e){window.IMPE_TELEPHONY_NOTIFY?.(e.message,true);}
 },true);
 window.addEventListener('message',event=>{
  if(event.origin!==window.location.origin||event.source!==window.parent||event.data?.type!=='IMPE_PHONE_CALL_CHANGED')return;
  for(const delay of [1200,3500])setTimeout(()=>window.dispatchEvent(new Event('impe:phone-notices-refresh')),delay);
 });
});
