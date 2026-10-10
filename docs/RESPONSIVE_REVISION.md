# Revisión responsive · Sistema Comercial IMPE

## Alcance del cambio

Todos los perfiles autenticados cargan `dashboard_head.php`; allí se añade
`public/css/responsive_sistema.css` al final de las hojas del módulo. El
diseño común no altera permisos ni la lógica comercial.

Correcciones incluidas:

- Cabecera en dos filas cuando la ventana no permite título y accesos rápidos
  a la vez. Telefonía, agenda, notificaciones y menú de cuenta se conservan
  disponibles; en móvil la cuenta se representa mediante el avatar.
- Menú móvil lateral con ancho limitado al viewport.
- Contexto de **Seguimiento por territorio**: enlace de regreso a la izquierda,
  identidad territorial clara, KPIs en dos columnas (aliados ocupa la fila).
- Textos largos, filtros, botones y tablas con contenedores adaptables.
- Espaciado móvil, blancos disponibles, foco de teclado y preferencia de
  reducción de movimiento.

## Validaciones

`php tests/responsive_smoke.php` comprueba que las reglas globales están
conectadas y que los patrones indispensables siguen definidos. La prueba
estática no sustituye una auditoría visual e interactiva.

## Matriz para pruebas reales antes de publicar

Anchos sugeridos: **320**, **375**, **430**, **768**, **1024** y **1440** píxeles,
más tablet apaisada y zoom del navegador al 200 %.

Para cada rol que exista en la base (Administrador, Analista, Cuenta Clave,
Marketing, Ventas, etc.), comprobar:

1. Inicio, sidebar/offcanvas, título, avatar y acciones del encabezado.
2. Territorios, Información Territorial y Seguimiento (listado, estado y
   expediente): KPIs, filtros, datos largos, acciones y modales.
3. Agenda, formularios, convocatorias, aliados y reportes: filtros,
   calendarios, previsualizaciones, PDF/Word, tablas y gráficos.
4. Telefonía y WhatsApp cuando el usuario tenga permiso y la integración esté
   habilitada: controles, popups y estados activos.
5. Tacto y teclado: botones accesibles, desplazamiento dentro de tablas,
   foco visible, zoom, formularios y mensajes sin recortes.

**Limitación:** esta auditoría aplica cambios al código común y los módulos
prioritarios, pero no demuestra por sí sola que cada pantalla pase en todos
los dispositivos. Validar en navegadores reales antes del despliegue masivo.

## Despliegue

Actualizar `~/crm-preparacion` desde main; ejecutar smoke; sincronizar **solo**
`public/css/responsive_sistema.css`,
`app/views/layout/dashboard_head.php` y
`app/views/layout/topbar.php` a `public_html`.

No usar `rsync --delete`, no sobrescribir configuraciones privadas y no
ejecutar migraciones SQL como parte de la actualización visual.
