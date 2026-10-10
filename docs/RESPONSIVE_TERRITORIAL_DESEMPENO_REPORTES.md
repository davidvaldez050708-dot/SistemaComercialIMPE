# Auditoría responsive – Información Territorial, Desempeño y Reportes

Se revisaron las estructuras PHP y las hojas CSS del Analista y vistas
compartidas por otros perfiles. Se encontraron dos riesgos concretos:

1. `data-territorial-detail-switch` usa `min-width:310px` en `dashboard.css`,
   aun cuando el panel móvil puede medir menos. Corregido de forma
   específica para móviles sin cambiar el selector ni el filtrado.
2. `performance-chart-scroll` tenía `overflow-x:hidden` en `desempeno.css`;
   podía impedir leer una tendencia mensual en pantallas pequeñas.
   La gráfica ahora se desplaza dentro de su región, admite foco con
   teclado y mantiene cada fecha con un ancho mínimo útil.

También se reforzaron los controles y títulos de Reportes, y las
tarjetas KPI de Desempeño pasan a dos columnas en teléfonos de 360 px
o más (una columna en pantallas más estrechas).

No se cambiaron cálculos, filtros, permisos, rutas, consultas o datos.

## Comprobar local y en Hostinger

```bash
php tests/responsive_smoke.php
php tests/responsive_overflow_smoke.php
php tests/analista_inicio_responsive_smoke.php
php tests/analista_modulos_responsive_smoke.php
php -l app/views/desempeno/index.php
```

Después de verificar en navegador, publicar únicamente
`public/css/responsive_sistema.css` y
`app/views/desempeno/index.php` (sin `--delete`).

## Matriz visual pendiente

A 320, 375, 430, 768, 1024 y 1440 píxeles:

- **Información Territorial:** búsqueda y filtro, cambio de estado,
  menú de Resumen/Secretarías/Economía/Educación/Municipios, tablas,
  mapas, vínculos de gestión y modales de actualización según permiso
- **Desempeño:** área, periodo, persona, filtros, fechas personalizadas,
  tarjetas, ranking, gráfica con 7/30/31 días, exportar PDF
- **Reportes:** catálogo, selector de territorio, reporte generado,
  cambiar territorio, exportar PDF, indicadores, tablas y fuentes

Los tests son estáticos y no demuestran la ausencia de fallas en todos
los navegadores; la validación visual y funcional es necesaria antes de
declarar la revisión completa.
