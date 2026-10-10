# Agenda · espacio vertical entre el mes y la navegación móvil

Incidencia detectada en captura de móvil: el nombre del mes, descripción y
botones de navegar/s solicitar reunión aparecían separados por un
gran bloque vacío. En `agenda_reunion.css`, la cabecera cambia a
`flex-direction: column` a 991 px, pero conservaba el
`justify-content: space-between` de escritorio. Cuando su pista de
cuadrícula ganaba altura, esos dos elementos se separaban verticalmente.

La corrección acota la pista de grid al tamaño de su contenido, impide
que la cabecera se estire y alinea los controles justo después de la
descripción con un espacio uniforme de 12 px. No toca el calendario, el
estado de reuniones, las acciones ni el JavaScript.

Verificar en el navegador a 320, 375, 430, 768 y 1024 px:
- Título y descripción directamente encima de flechas/Hoy.
- Botón Solicitar reunión debajo de los controles en móvil.
- Calendario debajo de la indicación de deslizar, sin hueco vertical.
- Cambio de mes y modales funcionando sin regresiones.

Ejecutar `php tests/agenda_toolbar_layout_smoke.php`.
La prueba estructural no sustituye la comprobación visual.

Publicación: actualizar `~/crm-preparacion`, probar y copiar **solo**
`public/css/agenda_reunion.css` a `public_html/public/css/`.
No tocar base de datos, fotografías, adjuntos ni credenciales.
