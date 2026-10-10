/*
 * Pegar el contenido completo en la consola DevTools de una vista abierta.
 * No modifica el DOM, la base de datos ni las sesiones.
 * Repetir en cada ruta/rol y con diferentes anchos.
 */
(() => {
  const viewport = document.documentElement.clientWidth;
  const width = document.documentElement.scrollWidth;
  const offenders = [...document.body.querySelectorAll('*')]
    .filter(el => {
      const rect = el.getBoundingClientRect();
      const style = getComputedStyle(el);
      if (style.display === 'none' || style.visibility === 'hidden') return false;
      if (!rect.width || !rect.height) return false;
      // Elementos fuera de flujo ocultos no ensanchan el documento.
      if (['fixed', 'absolute'].includes(style.position) &&
          (rect.left >= viewport || rect.right < 0)) return false;
      if (el.closest('.offcanvas:not(.show), .modal:not(.show)')) return false;
      return rect.right > viewport + 2 || rect.left < -2;
    })
    .map(el => {
      const r = el.getBoundingClientRect();
      return {
        element: el.tagName.toLowerCase(),
        selector: el.id ? '#' + el.id : '.' +
          [...el.classList].slice(0, 3).join('.'),
        right: Math.round(r.right),
        left: Math.round(r.left),
        width: Math.round(r.width)
      };
    })
    .sort((a, b) => b.right - a.right)
    .slice(0, 20);
  const result = {
    path: location.pathname + location.search,
    viewport, documentWidth: width,
    documentOverflow: width > viewport + 2,
    offenders
  };
  console.log('Diagnóstico responsive', result);
  console.table(offenders);
  return result;
})();
