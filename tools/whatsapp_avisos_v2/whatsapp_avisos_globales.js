/* Read-only, no independent toast engine, no message read or send. */
(()=>{'use strict';
 const root=document.querySelector('[data-wa-global]');if(!root||root.dataset.ready)return;root.dataset.ready='1';
 const user=Number(root.dataset.userId);if(!Number.isSafeInteger(user)||user<1||Number(window.IMPE_CURRENT_USER_ID)!==user)return;
 const button=root.querySelector('[data-wa-button]'),count=root.querySelector('[data-wa-count]'),summary=root.querySelector('[data-wa-summary]'),list=root.querySelector('[data-wa-list]'),status=root.querySelector('[data-wa-status]');
 const url=new URL('index.php',new URL(root.dataset.baseUrl,location.href));if(url.origin!==location.origin)return;
 url.searchParams.set('controller','whatsapp');url.searchParams.set('action','avisosGlobales');
 const key='impe:whatsapp-toast-seen:v3:'+user;
 let memory=null;
 const readSeen=()=>{try{const d=JSON.parse(localStorage.getItem(key)||'null');if(d&&typeof d==='object'&&!Array.isArray(d))return d;}catch{}return memory;};
 const saveSeen=map=>{const entries=Object.entries(map).filter(([k,v])=>/^[1-9]\d*$/.test(k)&&typeof v==='string'&&/^[1-9]\d*$/.test(v)).sort((a,b)=>BigInt(a[1])>BigInt(b[1])?-1:1).slice(0,500);memory=Object.fromEntries(entries);try{localStorage.setItem(key,JSON.stringify(memory));}catch{}};
 let timer=null,inflight=null,stopped=false,delay=15000,last='',epoch=0;
 const el=(tag,cls,value)=>{const node=document.createElement(tag);node.className=cls;if(value!==undefined)node.textContent=String(value);return node;};
 const message=(title,detail,icon)=>{status.hidden=false;const i=el('i','bi '+icon);i.setAttribute('aria-hidden','true');status.replaceChildren(i,el('strong','',title),el('span','',detail));};
 const clear=msg=>{count.classList.add('d-none');count.textContent='0';button.setAttribute('aria-label','Abrir WhatsApp');summary.textContent='Mensajes pendientes';list.replaceChildren();message('WhatsApp',msg,'bi-exclamation-circle');last='';};
 const toast=rows=>{
    const version=epoch;
    const perform=()=>{
      if(version!==epoch||stopped||document.hidden)return;
      const seen=readSeen();
      if(seen===null){saveSeen(Object.fromEntries(rows.map(r=>[String(r.id),r.entrada_id])));return;}
      const newer=rows.filter(r=>{const v=seen[String(r.id)];return typeof v!=='string'||!/^\d+$/.test(v)||BigInt(r.entrada_id)>BigInt(v);});
      if(!newer.length||typeof window.IMPE_WHATSAPP_TOAST!=='function')return;
      const row=newer[0];
      if(window.IMPE_WHATSAPP_TOAST({id:row.id,nombre:row.nombre,mensaje:row.mensaje,otros:newer.length-1})){
        for(const r of newer)seen[String(r.id)]=r.entrada_id;
        saveSeen(seen);
      }
    };
    if(navigator.locks?.request){navigator.locks.request(key,{ifAvailable:true},lock=>{if(lock)perform();}).catch(()=>{});}
    else perform();
 };
 const render=data=>{
   if(!Number.isInteger(data.total)||data.total<0||!Array.isArray(data.conversaciones))throw Error('invalid');
   const rows=data.conversaciones.slice(0,8);
   if(rows.some(r=>!Number.isSafeInteger(r.id)||r.id<=0||!Number.isInteger(r.no_leidos)||r.no_leidos<=0||typeof r.entrada_id!=='string'||! /^[1-9]\d*$/.test(r.entrada_id)))throw Error('invalid row');
   count.classList.toggle('d-none',data.total===0);count.textContent=data.total>9?'9+':String(data.total);
   button.setAttribute('aria-label',data.total?'WhatsApp: '+data.total+' conversaciones pendientes':'Abrir WhatsApp');
   summary.textContent=data.total===1?'1 conversación con mensajes no leídos':data.total+' conversaciones con mensajes no leídos';
   if(rows.length)status.hidden=true;else message('Sin mensajes pendientes','Tus nuevas conversaciones aparecerán aquí.','bi-check2-circle');
   const signature=JSON.stringify(rows);
   if(signature!==last){last=signature;const fragment=document.createDocumentFragment();
     for(const r of rows){
       const b=el('button','topbar-reminder-item');b.type='button';b.dataset.whatsappConversation=String(r.id);
       const avatar=el('span','topbar-reminder-icon'),icon=el('i','bi bi-whatsapp');icon.setAttribute('aria-hidden','true');avatar.append(icon);
       const copy=el('span','topbar-reminder-copy');copy.append(el('strong','',r.nombre||'Contacto'),el('span','',r.mensaje||'Nuevo mensaje'));
       b.append(avatar,copy,el('span','topbar-reminder-time is-activa',r.no_leidos>99?'99+':r.no_leidos));fragment.append(b);
     }list.replaceChildren(fragment);
   }
   toast(rows);
 };
 const schedule=()=>{clearTimeout(timer);if(!stopped&&!document.hidden)timer=setTimeout(poll,delay);};
 async function poll(){
   if(stopped||document.hidden||inflight)return;clearTimeout(timer);
   const ctrl=new AbortController(),version=++epoch;inflight=ctrl;
   const timeout=setTimeout(()=>ctrl.abort(),8000);
   try{
     const res=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:ctrl.signal,headers:{'X-Requested-With':'fetch'}});
     if(version!==epoch)return;
     if(res.status===401||res.status===403){stopped=true;clear('Tu acceso cambió. Recarga la página.');return;}
     if(!res.ok)throw Error('http');
     const data=await res.json();if(version!==epoch)return;if(!data.ok)throw Error('response');
     render(data);delay=15000;
   }catch{if(version===epoch&&!document.hidden){clear('No se pudo actualizar WhatsApp.');delay=60000;}}
   finally{clearTimeout(timeout);if(inflight===ctrl)inflight=null;schedule();}
 }
 button.addEventListener('click',()=>{if(!stopped)poll();});
 list.addEventListener('click',e=>{if(e.target.closest('[data-whatsapp-conversation]')){try{window.bootstrap?.Dropdown.getInstance(button)?.hide();}catch{}}});
 document.addEventListener('visibilitychange',()=>{if(document.hidden){clearTimeout(timer);epoch++;inflight?.abort();}else poll();});
 window.addEventListener('pagehide',()=>{clearTimeout(timer);epoch++;inflight?.abort();});
 window.addEventListener('pageshow',()=>{if(!document.hidden)poll();});
 document.addEventListener('impe:whatsapp-chat-closed',()=>poll());
 poll();
})();
