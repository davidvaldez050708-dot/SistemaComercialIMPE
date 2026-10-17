# Modal y selectores de recepción

El resultado de llamada usa el mismo componente Bootstrap y las clases
`system-form-modal`, `btn-system-cancel` y `btn-system-save` de Seguimiento.
La apariencia procede de `dashboard.css`; recepción sólo limita la altura y
define una región de desplazamiento dentro del cuerpo del formulario.

Los tres selectores de recepción llevan `data-native-select`, la excepción ya
soportada por `selectores_uniformes.js`. Evita que el transformador global de
Marketing inserte un menú adicional dentro del modal. Los filtros conservan
la altura de 42 px y los colores y radios de los filtros de Seguimiento.

En ventanas pequeñas, el encabezado y el pie quedan accesibles mientras el
cuerpo admite la rueda del ratón. Bootstrap gestiona el foco y el cierre.
El guardado impide cerrar el formulario mientras la petición está pendiente;
un error conserva los valores. Tras guardar se actualiza el historial.
Las confirmaciones y errores usan `toast system-toast` en el contenedor
superior derecho, con los iconos, tiempos y apariencia de los otros módulos.

El filtro contiene siete opciones: Todos los estados, Respondidas, Sin
respuesta, Transferidas, En curso, No completadas y Pendientes de resultado.
Agrupa estados técnicos de la centralita sin alterar sus datos. El resultado
registrado se busca por su texto en el buscador. Estado y Resultado tienen
columnas independientes. El resultado y su nota quedan definitivos al
guardar; el servidor rechaza tanto ediciones como envíos simultáneos que
intentaran sustituir el primer registro, con HTTP 409.

## Publicación

Instalar juntos, conservando copia de los archivos actuales:

- `app/views/telefonia/recepcion_marketing.php`
- `public/css/telefonia_recepcion_marketing.css`
- `public/javascript/telefonia_recepcion_marketing.js`
- `app/services/TelefoniaRecepcionMarketingService.php`
- `prueba_telefonia/api/resultado_recepcion_marketing.php`

No requiere cambios de base de datos ni modificar los selectores globales de
otros módulos. Si el CSS publicado incluye ajustes locales de presentación,
conservar su prefijo anterior al marcador `/* Recepción operativa: filtros en
una fila propia, aislados del título. */` y reemplazar desde el marcador hasta
el final por el bloque actualizado. El paquete entregado conserva el prefijo
del CSS público comprobado en esta revisión.

Después de instalar, recargar con Ctrl+F5 y abrir Registrar resultado.
Comprobar la primera y última opción, cancelar, guardar y reducir la altura
de la ventana. El desplazamiento debe afectar al cuerpo, con los botones a
la vista. Las opciones desplegadas las gestiona el control estándar del
navegador, igual que en Seguimiento.

## Prueba de navegador

`tests/marketing_modal_seguimiento_browser.cjs` renderiza la vista PHP sin
sesión real ni acceso a la centralita. Carga Bootstrap 5.3.8, los estilos
compartidos, el CSS de Marketing y su transformador global de selectores.
Simula exclusivamente las respuestas del historial y del guardado.

Comprueba estilos compartidos, la excepción de los tres selectores, filtros,
selección de la última opción por teclado, foco, cierre, desplazamiento con
rueda, cuatro tamaños de ventana, guardado y errores. El workflow
`marketing-recepcion.yml` instala las dependencias de prueba en un directorio
temporal y ejecuta la prueba en Chromium. No añade dependencias al servidor.
La prueba operativa PHP también verifica que se conserva el resultado y la
nota originales frente a una segunda escritura o una inserción concurrente.

Variables opcionales: `PLAYWRIGHT_MODULE_PATH`, `BOOTSTRAP_MODULE_PATH`,
`PLAYWRIGHT_CHANNEL`, `MARKETING_BROWSER_OUTPUT` y `MARKETING_DEPLOY_CSS`.
`MARKETING_PREVIEW_FONTS=1` añade las fuentes e iconos externos de producción
para las capturas locales; la prueba de CI funciona sin esas descargas.
