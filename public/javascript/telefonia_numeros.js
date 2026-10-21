/* Identidad mexicana común; nunca modifica el número original del evento PBX. */
(function(root){
 'use strict';
 const raw=value=>String(value||'').trim();
 const digits=value=>raw(value).replace(/\D/g,'');
 const mexican=value=>{
  const text=raw(value),n=digits(text);
  if(!/^\+?[\d\s().-]+$/.test(text))return '';
  if(!text.startsWith('+')&&/^[1-9]\d{9}$/.test(n))return n;
  if(/^52\d{10}$/.test(n))return n.slice(2);
  if(/^521\d{10}$/.test(n))return n.slice(3);
  return '';
 };
 const display=value=>mexican(value)?'+52'+mexican(value):raw(value);
 const key=value=>mexican(value)?'52'+mexican(value):digits(value);
 const dial=value=>{
  const text=raw(value);
  if(!/^\+?[\d\s().*#-]+$/.test(text))return '';
  // Configuración nacional ya utilizada por este host: diez dígitos MX.
  // Se conserva el marcado internacional previo del SDK (sin el signo +).
  return mexican(value)||text.replace(/[^0-9*#]/g,'');
 };
 const api=Object.freeze({display,key,dial});
 root.IMPE_PHONE_NUMBERS=api;
 if(typeof module!=='undefined'&&module.exports)module.exports=api;
})(typeof window==='undefined'?globalThis:window);
