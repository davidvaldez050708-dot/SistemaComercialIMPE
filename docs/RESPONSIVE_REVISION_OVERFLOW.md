# Auditoría responsive del CRM · iteración 2

## Incidencia reproducida visualmente

En `Seguimiento > Territorios`, el panel `data-territorial-selector linkage-selector`
mostraba desplazamiento horizontal **de toda la página** y desplazaba el
botón «Generar reportes» fuera de la zona visible en móvil.

Causas en el código: cuadrículas con columna `1fr` (mínimo implícito
`auto`), textos de botón con `white-space: nowrap` y reglas genéricas
de ancho/base flexible que no se ajustaban al selector.

## Solución

- Utilizar `minmax(0,1fr)` y una cuadrícula explícita para la cabecera.
- Botón `linkage-report-button` que ocupa una fila propia en tablet/móvil.
- Proteger los filtros, tarjetas y mensajes de ancho mínimo implícito.
- Conservar las tablas extensas como tablas desplazables internamente.
- No establecer `body {overflow-x:hidden}` porque escondería fallas UX.
- Aplicar los cambios a la hoja responsive común usada por todos los roles.

## Módulos incluidos en la inspección estática

Inicio/dashboard de todos los roles; Territorios; Seguimiento y Expediente;
Información Territorial; Convocatorias/Marketing; Usuarios; Aliados;
Agenda; Desempeño; Reportes; Telefonía; WhatsApp; Formularios;
Correos; y páginas públicas de registro/acceso.

Se examinaron las hojas CSS de los módulos, identificando tamaños mínimos
grandes en tablas de Agenda, Convocatorias, Aliados, Telefonía y Reportes.
Estas deben conservar desplazamiento **dentro de sus contenedores**.

## Cómo diagnosticar un nuevo desbordamiento en el navegador

1. Abre la vista afectada e inspecciónala a 320, 375, 390, 430, 768 y
   1024 px de ancho, además de 1440 px de escritorio.
2. Abre DevTools > Console y pega el código de
   `tests/diagnosticar_overflow_navegador.js`.
3. Un resultado `scrollWidth > clientWidth` debe investigarse.
   Los modales cerrados y los menús ocultos no cuentan como desbordamientos
   de la página principal.
4. Repite por cada rol con sus permisos y menús distintos. También comprueba
   zoom al 200%, modales abiertos, menús, fechas, reportes y tablas.

La auditoría del repositorio y los smoke tests no certifican que todas las
interacciones funcionen sin realizar pruebas reales en navegadores.
Antes de abrir el CRM a todos los usuarios debe completarse esa validación.

## Publicar

Actualizar `~/crm-preparacion` desde `main` y probar:

```bash
php tests/responsive_smoke.php
php tests/responsive_overflow_smoke.php
```

Copiar **solo** `public/css/responsive_sistema.css` y
`app/views/seguimiento_vinculacion/index.php` a `public_html`.
No usar `--delete`, ni migrar/alterar MySQL.
