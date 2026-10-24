/* El servidor conserva toda la procedencia dentro de un alias estable. */
(() => {
    'use strict';
    const root=new URL('../../',document.currentScript.src);
    window.IMPERegistroEnlaces={acortar:async value=>{
        const url=new URL(value,location.href);
        if(url.origin!==location.origin)return value;
        const current=url.pathname.includes('/registro/obtener-beca/');
        const short=current||url.pathname.includes('/registro/el-primer-paso-hacia-tu-meta-comienza-aqui/');
        if(current&&!url.search)return value;
        if(!short&&(url.searchParams.get('controller')!=='formularioPublico'||url.searchParams.get('action')!=='registro'))return value;
        const endpoint=new URL('index.php',root);
        endpoint.search='?controller=formulario&action=acortarRegistro';
        const body=new FormData();body.set('url',url.href);body.set('csrf_token',window.IMPE_CSRF_TOKEN||document.querySelector('meta[name="csrf-token"]')?.content||document.querySelector('[name="csrf_token"]')?.value||'');
        const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'fetch'},body});
        const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.mensaje||'No se pudo generar el enlace de Registro.');return data.url;
    }};
})();
