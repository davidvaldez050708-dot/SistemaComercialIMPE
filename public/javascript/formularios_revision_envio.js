(function () {
    'use strict';
    if (window.FormularioRevision) return;
    const script = document.currentScript;
    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = new URL('../css/formularios_revision_envio.css?v=20261106-1', script.src).href;
    const estilosListos = new Promise((resolve, reject) => {
        const reloj = setTimeout(() => reject(new Error('No se pudo preparar la revisión. Recarga la página e intenta nuevamente.')), 10000);
        css.onload = () => { clearTimeout(reloj); resolve(); };
        css.onerror = () => { clearTimeout(reloj); reject(new Error('No se pudo preparar la revisión. Recarga la página e intenta nuevamente.')); };
    });
    estilosListos.catch(() => {});
    document.head.appendChild(css);
    const logo = new URL('../img/brand/Logotipos-blanco.png', script.src).href;
    let abierto = false;
    const textoAviso = 'Antes de enviar, verifica que tu nombre, datos de contacto y demás información sean correctos. Deben coincidir con tus documentos oficiales.';
    function montar(form) {
        if (!form || form.querySelector('[data-revision-aviso]')) return;
        const submit = form.querySelector('[type="submit"]');
        if (!submit) return;
        const aviso = document.createElement('p');
        aviso.className = 'formulario-revision-aviso';
        aviso.dataset.revisionAviso = '1';
        aviso.textContent = textoAviso;
        const acciones = submit.closest('.formularios-registration-actions');
        if (acciones) acciones.before(aviso); else submit.before(aviso);
    }
    async function confirmar(form) {
        await estilosListos;
        montar(form);
        if (abierto) return Promise.resolve(false);
        abierto = true;
        return new Promise(resolve => {
            const previo = document.activeElement;
            const overflow = document.body.style.overflow;
            const capa = document.createElement('div');
            capa.className = 'formulario-revision-capa';
            // Static markup only. Every value captured from a form uses textContent.
            capa.innerHTML = '<section class="formulario-revision-dialogo" role="dialog" aria-modal="true" aria-labelledby="formulario-revision-titulo" aria-describedby="formulario-revision-descripcion" tabindex="-1">'
                + '<header class="formulario-revision-marca"><img alt="Fundación Red Educativa México e IMPE"><button type="button" class="formulario-revision-cerrar" aria-label="Volver a revisar los datos">×</button></header>'
                + '<div class="formulario-revision-cuerpo"><span class="formulario-revision-etiqueta">REVISIÓN FINAL</span><h2 id="formulario-revision-titulo">¿Tus datos son correctos?</h2>'
                + '<p id="formulario-revision-descripcion">Revisa la información antes de enviarla. Tu nombre y tus datos personales deben coincidir con tus documentos oficiales.</p><dl class="formulario-revision-resumen"></dl>'
                + '<p class="formulario-revision-aclaracion">Al confirmar, enviarás el formulario con todos los datos y archivos que capturaste.</p></div>'
                + '<footer class="formulario-revision-acciones"><button type="button" class="formulario-revision-volver">Revisar datos</button><button type="button" class="formulario-revision-confirmar">Confirmar y enviar</button></footer></section>';
            capa.querySelector('img').src = logo;
            const valor = name => {
                const input = form.elements.namedItem(name);
                return input && 'value' in input ? String(input.value).trim() : '';
            };
            const nombre = [valor('nombre'), valor('apellidos') || valor('apellido')].filter(Boolean).join(' ');
            const fecha = valor('fecha_nacimiento');
            const datos = [['Nombre completo', nombre], ['CURP', valor('curp')],
                ['Fecha de nacimiento', /^\d{4}-\d{2}-\d{2}$/.test(fecha) ? fecha.split('-').reverse().join('/') : fecha],
                ['Correo electrónico', valor('correo')], ['Teléfono móvil', valor('movil')]];
            const resumen = capa.querySelector('dl');
            datos.filter(([, v]) => v !== '').forEach(([etiqueta, dato]) => {
                const bloque = document.createElement('div'), dt = document.createElement('dt'), dd = document.createElement('dd');
                dt.textContent = etiqueta; dd.textContent = dato; bloque.append(dt, dd); resumen.appendChild(bloque);
            });
            const dialogo = capa.querySelector('[role="dialog"]');
            const volver = capa.querySelector('.formulario-revision-volver');
            let terminado = false;
            const terminar = confirmado => {
                if (terminado) return;
                terminado = true; abierto = false;
                document.removeEventListener('keydown', teclado, true);
                document.removeEventListener('focusin', foco, true);
                capa.remove(); document.body.style.overflow = overflow;
                if (previo && previo.isConnected) previo.focus();
                resolve(confirmado);
            };
            const foco = event => { if (!dialogo.contains(event.target)) volver.focus(); };
            const teclado = event => {
                if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); terminar(false); }
                if (event.key === 'Tab') {
                    const botones = Array.from(dialogo.querySelectorAll('button'));
                    const primero = botones[0], ultimo = botones[botones.length - 1];
                    if (event.shiftKey && document.activeElement === primero) { event.preventDefault(); ultimo.focus(); }
                    else if (!event.shiftKey && document.activeElement === ultimo) { event.preventDefault(); primero.focus(); }
                }
            };
            volver.addEventListener('click', () => terminar(false));
            capa.querySelector('.formulario-revision-cerrar').addEventListener('click', () => terminar(false));
            capa.querySelector('.formulario-revision-confirmar').addEventListener('click', () => terminar(true));
            capa.addEventListener('click', event => { if (event.target === capa) terminar(false); });
            document.body.appendChild(capa); document.body.style.overflow = 'hidden';
            document.addEventListener('keydown', teclado, true);
            document.addEventListener('focusin', foco, true);
            volver.focus();
        });
    }
    window.FormularioRevision = {montar, confirmar};
}());
