/* Abrir una notificación ubica la tarjeta sin completar la gestión. */
(() => {
  'use strict';
  const aviso=document.querySelector('[data-notificacion-destino]');
  if(!aviso)return;
  const id=aviso.dataset.notificacionDestino;
  const tarjeta=/^[1-9][0-9]*$/.test(id)
    ?document.querySelector(`article[data-asignacion-id="${id}"]`):null;
  if(tarjeta){
    tarjeta.before(aviso);
    tarjeta.classList.add('impe-notificacion-destacada');
    const teniaTabindex=tarjeta.hasAttribute('tabindex');
    if(!teniaTabindex)tarjeta.tabIndex=-1;
    tarjeta.focus({preventScroll:true});
    tarjeta.scrollIntoView({behavior:'instant',block:'center',inline:'nearest'});
    window.setTimeout(()=>{
      tarjeta.classList.remove('impe-notificacion-destacada');
      if(!teniaTabindex)tarjeta.removeAttribute('tabindex');
    },10000);
  }else if(id!=='0'){
    aviso.textContent='El tablero cambió mientras se abría la notificación. Vuelve a abrirla para localizar el estado actual.';
  }
  // No volver a resaltar ni arrastrar este contexto al recargar o filtrar.
  const url=new URL(window.location.href);
  url.searchParams.delete('destacar');
  url.searchParams.delete('aviso_actividad');
  window.history.replaceState(window.history.state,'',url);
})();
