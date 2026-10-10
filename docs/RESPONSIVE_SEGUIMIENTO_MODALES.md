# Revisión responsive · Modal de candidatos y altas en Seguimiento

En la vista Seguimiento de un estado se inspeccionaron:
- «Buscar candidato» (`modalBuscarCandidato`) con filtros, resultados y paginación
- «Iniciar seguimiento» (`modalConfirmarSeguimiento`)
- «Agregar seguimiento manualmente» (`modalAgregarSeguimientoManual`)

En escritorio, el modal de candidatos usa un grid con
`grid-template-rows: auto auto auto minmax(0, 1fr) auto` y
`overflow: hidden`. Al apilar filtros en teléfonos y abrir
el teclado, el conjunto podría superar la altura visible y dejar
resultados y paginación inaccesibles.

Para anchos de hasta 767px o pantallas de altura reducida, los modales
admiten hasta la altura disponible (`100dvh`), el scroll ocurre dentro
del cuerpo y los botones de guardar permanecen en el pie. El buscador
utiliza un solo scroll interno, evitando una barra adicional de resultados.

## Comprobaciones visuales pendientes

A 320, 375, 430, 768, 1024 y 1440px, por rol autorizado:
- Buscar 3+ caracteres, filtros, lista de instituciones y paginación
- «Buscar candidatos automáticamente» y error/reintento
- Confirmación, selección de analista cuando corresponda y guardar
- Alta manual con teclado móvil abierto, recorrer hasta último campo y guardar
- Rotación horizontal, zoom 200 %, teclado físico y scroll dentro del modal

No se modifica la lógica de negocio ni se ejecutan operaciones o migraciones.
La comprobación automatizada es estructural, no una prueba visual real.

## Publicación Hostinger

```bash
cd ~/crm-preparacion
git pull --ff-only origin main
php tests/seguimiento_modales_responsive_smoke.php
rsync -avR public/css/responsive_sistema.css \
  ~/domains/royalblue-wallaby-591050.hostingersite.com/public_html/
```

No ejecutar `rsync --delete` ni tocar los datos, sesiones o archivos privados.

## Ajuste posterior por validación real · PR #24

La validación con capturas mostró dos regresiones del primer ajuste:

1. En computadoras con ventana de poca altura, la condición combinada
   `max-height: 680px` aplicaba estilos móviles y estiraba el modal. Se eliminó
   ese criterio: el apilado ahora depende únicamente del **ancho** de 767px.
   En escritorio, Buscar candidato conserva un máximo de 1120px; los otros
   dos diálogos quedan limitados a 760/800px respectivamente.
2. En móvil, el contador y las flechas de página seguían `position: sticky`
   desde `dashboard.css`. Con el cuerpo desplazable eso cubría tarjetas.
   Ahora forman parte del flujo normal, después del último resultado, sin
   superponerse a las tarjetas. Se conserva su comportamiento funcional.

Regresión comprobable con `php tests/seguimiento_modales_responsive_smoke.php`.
Validación visual pendiente en Chrome móvil y escritorio antes de cerrar.
