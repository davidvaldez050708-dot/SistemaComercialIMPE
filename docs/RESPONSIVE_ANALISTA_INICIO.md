# Responsive · Inicio del Analista

Se revisó la vista `app/views/dashboard/analista.php` y sus cinco hojas CSS.
El diseño ya contaba con reorganización de tarjetas en móvil y tablet,
pero algunos elementos de la agenda y actividad reciente imponían
`white-space: nowrap`, ocultando nombres y textos largos.

## Correcciones

- A 767 px o menos, el texto de eventos y actividades ocupa hasta dos líneas.
- Los nombres y títulos largos se mantienen dentro de sus tarjetas.
- Los indicadores y el avance mensual permanecen en dos columnas con
  `minmax(0, 1fr)`.
- A 380 px o menos, se reducen únicamente los espacios interiores de
  las tarjetas compactas.
- No cambian las consultas, indicadores, acciones, permisos ni enlaces.

## Comprobaciones pendientes

Se requiere probar con cuentas reales del Analista en navegadores a
320, 375, 390, 430, 768, 1024 y 1440 px, tanto con agenda y pendientes
vacíos como con nombres institucionales largos. Verificar acciones,
lectura de la agenda y enlaces al detalle.

Ejecutar `php tests/analista_inicio_responsive_smoke.php`.
