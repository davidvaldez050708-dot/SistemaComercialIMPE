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
«Sin clasificar» retira la clasificación visible. Los resultados se muestran
separados del estado PBX y pueden filtrarse; nunca reescriben eventos firmados.

`resultado_recepcion_marketing.php` sólo acepta POST con sesión, permisos y CSRF.
El servicio vuelve a verificar propiedad, asignación, período y fin de la llamada
antes de escribir. No permite registrar llamadas ajenas ni aún activas. Los
resultados quedan asociados a usuario, extensión e identificador PBX. Se crea
`telefonia_recepcion_resultados` con `CREATE TABLE IF NOT EXISTS` en el primer
guardado autorizado. Leer el historial funciona antes de que exista esa tabla.

Las grabaciones transferidas de Marketing utilizan el endpoint de segmentos
existente, después de verificar origen propio, permiso, asignación, transferencia,
fin y grabación reportada. El acceso de Analistas conserva su validación existente.

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
