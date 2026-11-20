# WhatsApp para la cartera de Ventas

Cada asesor abre WhatsApp desde su prospecto asignado en el embudo. Su bandeja contiene únicamente conversaciones empresariales enlazadas a prospectos con una asignación vigente a ese asesor. Los permisos antiguos de consulta general no amplían ese acceso. La reasignación retira el acceso anterior y actualiza la etiqueta del asesor. Un chat abierto sin mensajes no llena la bandeja.

Administrador, Dirección de Operaciones y Cuenta Clave tienen consulta general con etiquetas de prospecto y responsable. No se les concede envío ni respuesta de equipo automáticamente. Se mantiene la política de Administrador como supervisor. El cambio se limita a Ventas; los otros contextos conservan sus reglas instaladas.

Ventas selecciona un canal EMPRESARIAL autorizado: compartido y predeterminado, o empresarial propio. No vuelve al número de prueba si falta ese canal. Los historiales de prueba permanecen disponibles para supervisión. No se eliminan conversaciones ni se alteran las asignaciones de prospectos.

Una entrada nueva se enlaza automáticamente si su teléfono identifica un único prospecto con asesor de Ventas activo. Las coincidencias ambiguas, teléfonos sin prospecto y expedientes institucionales no se asignan a un asesor automáticamente. Un teléfono ya enlazado a otro expediente requiere revisión, evitando mezclar historiales de distintos prospectos.

## Plantilla pendiente en Meta

Todavía no hay una plantilla empresarial aprobada. No se configura `hello_world` como sustituto. Con la ventana abierta, el asesor puede responder con texto libre; para iniciar con la ventana cerrada debe existir una plantilla aprobada y validada en Inicio comercial.

Borrador para crear en el Administrador de WhatsApp de la cuenta empresarial:

- Nombre: `inicio_atencion_comercial`.
- Idioma: Español (México), código `es_MX`.
- Categoría sugerida: Marketing, sujeta a la clasificación de Meta.
- Sólo cuerpo, sin variables, encabezado, botones ni archivos:

> Hola, somos Fundación Red Educativa México. ¿Deseas que continuemos con tu registro?

Una vez APROBADA, entrar como Administrador en Conversaciones → Inicio comercial. Seleccionar el canal empresarial, ingresar el ID de la cuenta de WhatsApp (WABA) que contiene la plantilla, el nombre e idioma exactos, y pulsar Validar y guardar plantilla. Confirmar ese ID en Meta; no es el Phone Number ID. La consulta verifica la aprobación con Meta y muestra su texto; no envía mensajes. Esta versión admite sólo cuerpo de texto estático. Al enviar desde el embudo vuelve a comprobar aprobación y que el texto coincida con la vista previa guardada. La API de consulta y sus permisos están documentados en [Meta: Message Templates](https://developers.facebook.com/docs/graph-api/reference/whats-app-business-account/message_templates/).

## Publicación selectiva

Descargar del mismo commit `tools/publicar_whatsapp_ventas.py`, `tools/whatsapp_ventas_release.json` y `tools/whatsapp_ventas_permisos.php` a un directorio temporal privado. Requiere PHP CLI con mysqli, Python 3.6 o superior y el esquema de WhatsApp/contexto comercial ya instalado. Node es opcional para la comprobación de sintaxis JS en el servidor.

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PRIVADO="$HOME/domains/fundacionrededumex.org/impe-private"
mkdir -p "$PRIVADO"
chmod 700 "$PRIVADO"
php whatsapp_ventas_permisos.php "$SITIO" check "$PRIVADO/whatsapp-ventas-permisos-v1.json"
python3 publicar_whatsapp_ventas.py --site "$SITIO" --backup-root "$PRIVADO" --check
python3 publicar_whatsapp_ventas.py --site "$SITIO" --backup-root "$PRIVADO" --apply
php whatsapp_ventas_permisos.php "$SITIO" apply "$PRIVADO/whatsapp-ventas-permisos-v1.json"
```

El publicador comprueba todos los bloques antes de escribir y guarda respaldo privado. Conserva personalizaciones externas a los bloques. Si encuentra una diferencia no reconocida, se detiene para comparar; no sustituir los archivos completos. Admite las bases conocidas 626a7f4 y 46742db, conservando los cambios ajenos a Ventas de la segunda.

La migración sólo crea `whatsapp_ventas_config`, inicialmente sin plantillas. El ajuste de permisos agrega consulta a roles 1/2/6 y retira consulta global y respuesta de equipo de Ventas (3). Guarda las relaciones anteriores y posteriores fuera de public_html; una repetición no vuelve a conceder permisos revocados después. No modifica secretos ni envía mensajes. Cerrar e iniciar sesión después para renovar los permisos de sesión.

Para restaurar archivos: ejecutar el publicador con `--restore RUTA_RESPALDO` en lugar de `--apply`. Para restaurar permisos: ejecutar el PHP con modo `restore` y el mismo archivo privado; se bloquea si esos permisos cambiaron después. Se conserva la tabla de configuración y los historiales.

## Validación

Pruebas con MariaDB desechable, peticiones HTTP y Meta simulada: cartera exclusiva incluso con permisos globales antiguos, supervisión, reasignación, etiquetas, canal empresarial, entrada inequívoca/ambigua, aprobación/idioma/tipo de plantilla, CSRF, primer mensaje, respuesta dentro de ventana y prevención de repeticiones por sesión. Navegador Chrome aislado: plantilla pendiente sin envío, texto escapado, doble clic y confirmación. Instalador: comprobación sin escritura, aplicación, permisos con respaldo, repetición, restauración y rechazo íntegro ante conflicto.

No se ha publicado este cambio en producción ni enviado una plantilla real durante estas pruebas. La prueba final requiere publicación, aprobación de Meta y un prospecto de prueba asignado a un asesor.
