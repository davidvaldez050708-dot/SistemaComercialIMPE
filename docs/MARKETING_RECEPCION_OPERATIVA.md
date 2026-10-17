# Recepción operativa de Marketing

El bloque sigue habilitándose sólo a Marketing con una extensión activa propia
y permisos de telefonía y recepción. No se asigna por nombre ni se muestra a
todo el equipo de Marketing. La asignación de Tania a la extensión 100 se
administra con las herramientas de extensiones existentes.

## Historial

- Barra de búsqueda y filtros independiente del título, alineada en escritorio
  y adaptada a móvil. Mismos tamaños, bordes y campos que el resto del CRM.
- Todas las grabaciones usan «Consultar grabación» y el mismo botón verde.
- Una saliente también muestra «Transferida» y el destino cuando el webhook
  del mismo `pbx_call_id` contiene `transfer_from` de su extensión en
  `NOTIFY_INTERNAL` o `NOTIFY_ANSWER`. No se infieren transferencias por número,
  duración, fechas cercanas u órdenes enviadas desde el navegador.
- El contador de transferidas incluye entrantes y salientes. «Salientes sin
  contacto» incluye los fallos PBX y los resultados observados de buzón,
  no disponible o número incorrecto; un contacto humano registrado lo excluye.
- Los cierres salientes muestran ocupado, sin respuesta, cancelada, fallida,
  número inexistente, sin saldo o límite excedido según el resultado recibido.
  Un fallo final explícito no se convierte en completada por una señal previa
  de respuesta. Cero segundos no elimina una llamada del historial.
- La respuesta de red se presenta como «Respondida». No prueba que haya atendido
  una persona: puede ser buzón o una locución del operador.

Los estados y notificaciones del proveedor están documentados en
https://zadarma.com/en/support/api/ . Una grabación reportada se verifica al
abrirla; no se promete que esté disponible inmediatamente.

## Resultado observado

Recepción puede registrar Persona atendió, Buzón de voz, No disponible, Número
incorrecto u Otro resultado con una nota opcional de hasta 500 caracteres.
«Sin clasificar» guarda ese resultado explícito. Los resultados y las notas se
muestran en su columna, separados del estado PBX; se buscan por su etiqueta.
El filtro agrupa los estados de llamada en siete opciones. Ningún resultado
reescribe eventos firmados.

`resultado_recepcion_marketing.php` sólo acepta POST con sesión, permisos y CSRF.
El servicio vuelve a verificar propiedad, asignación, período y fin de la llamada
antes de escribir. No permite registrar llamadas ajenas ni aún activas. Los
resultados quedan asociados a usuario, extensión e identificador PBX. Se crea
`telefonia_recepcion_resultados` con `CREATE TABLE IF NOT EXISTS` en el primer
guardado autorizado. Leer el historial funciona antes de que exista esa tabla.
Una vez guardados, resultado y nota no se pueden editar. Se rechazan los
intentos posteriores con HTTP 409. Una inserción simple y la clave única
conservan el primer registro también frente a dos envíos simultáneos.

Las grabaciones transferidas de Marketing utilizan el endpoint de segmentos
existente, después de verificar origen propio, permiso, asignación, transferencia,
fin y grabación reportada. El acceso de Analistas conserva su validación existente.

## Llamadas sin contacto: sólo historial

Recepción no ofrece grabación para resultados BUZON, NO_DISPONIBLE o
NUMERO_INCORRECTO ni para estados Perdida, Sin respuesta, Ocupada, Cancelada,
Fallida, Número inexistente, No disponible, Sin saldo o Límite excedido.
También se comprueba el cierre de una transferencia, aunque su estado visible
sea Transferida. Esas llamadas conservan número, estado, duración, destino,
resultado y nota; la columna Grabación muestra «Solo historial».

La regla se aplica al agrupar eventos, después de incorporar el resultado
registrado y en la respuesta de guardado para ocultar el botón inmediatamente.
El endpoint de recepción verifica el historial antes de consultar a Zadarma;
el endpoint de transferencias aplica la misma exclusión en su rama Marketing.
La verificación, reproducción, descarga y peticiones Range no eluden la regla.

Una respuesta de red por sí sola no identifica un buzón. Mientras una llamada
respondida no tenga un resultado negativo conocido, conserva la consulta de
audio si hay evidencia de grabación. Este cambio regula el acceso en el CRM:
no modifica la configuración de grabación de Zadarma ni borra sus archivos.

## Verificación

```sh
php tests/marketing_recepcion_operativa_smoke.php
php tests/marketing_historial_pbx_unificado_smoke.php
php tests/marketing_grabaciones_salientes_smoke.php
php tests/marketing_recepcion_pestana_smoke.php
node tests/marketing_recepcion_historial_smoke.js
node --check public/javascript/telefonia_recepcion_marketing.js
```

La prueba operativa usa un doble de base de datos para persistencia y guardas;
no utiliza la base de datos de producción. También se verificaron en navegador
la vista PHP real y su JavaScript con respuestas API simuladas: filtros, destinos,
formulario, guardado, error de permisos, móvil y ausencia de errores JavaScript.

## Publicación y validación real

Publicar juntos el servicio, la vista, CSS, JavaScript y los dos endpoints
incluidos en el paquete. Respaldar los archivos existentes fuera de `public_html`.
El CSS de Hostinger puede conservar personalizaciones ajenas al cambio: aplicar
el bloque final de recepción operativa sobre su versión publicada.

Recargar el dashboard y probar con la sesión de Tania: una entrante, una saliente
sin respuesta, una transferencia saliente a 101, reproducción de grabaciones y
guardado/recarga de un resultado de buzón. Verificar que otro Marketing sin
extensión no vea el bloque. La base de datos debe permitir crear la tabla nueva
en el primer guardado; el fallo se informa y no cambia el estado de la llamada.

El agrupador reevalúa eventos históricos sin modificar la base. Si una llamada
antigua no recibió el evento firmado de transferencia, no se inventa el destino.
