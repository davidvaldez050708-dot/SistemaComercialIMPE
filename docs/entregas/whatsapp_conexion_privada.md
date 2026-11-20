# Conexión privada de WhatsApp: primer paso real

El canal de prueba identificado por el usuario tiene Phone Number ID
`1508758925647776` y número `+15551485468`. El identificador mostrado como cuenta
de mensajes es `2683955112050855`; no se presupone que equivalga al WABA ID.
Ningún token, App Secret ni PIN se incorpora al código o a esta documentación.

## Configuración por SSH

Obtener Access Token desde la aplicación de Meta y App Secret desde su
configuración básica. Ejecutar `tools/configurar_whatsapp_privado.py` en una
terminal SSH interactiva, con Python 3 y PHP CLI:

```bash
python3 tools/configurar_whatsapp_privado.py --site "$SITIO" --configure
python3 tools/configurar_whatsapp_privado.py --site "$SITIO" --check
```

Los dos secretos se solicitan mediante entrada oculta; no van en argumentos ni
en el historial del shell. La herramienta rechaza entradas no ocultables. Guarda
`whatsapp.local.php` en el directorio privado que admite el lector existente,
fuera de `public_html`, con permisos 0600 y escritura atómica. No se copia el
repositorio al sitio. No modifica código, permisos, canales, mensajes ni SQL.

Conserva las demás claves y la plantilla existente. No presupone que la cuenta
de Meta incluya `hello_world`: la pantalla del usuario ofrece otra plantilla de
ejemplo con parámetros. Si hay valores WHATSAPP_* de entorno, exige actualizar
esa fuente en lugar de escribir un archivo que pueda quedar ignorado.

Al actualizar una configuración privada existente crea un respaldo 0600 dentro
de una carpeta 0700. Conserva Verify Token, para que renovar Access Token no rompa
el webhook. Comprueba sintaxis PHP y que el lector real resuelva el nuevo archivo;
restaura la versión anterior si falla esta última comprobación. Una configuración
antigua en config/ queda intacta; el lector da prioridad al directorio privado.

La salida de `--configure` muestra **Verify Token para copiar en Meta**, pero
nunca Access Token ni App Secret. No compartir esa salida completa. `--check`
solo muestra el estado y nombres de campos ausentes; no muestra valores.

## Webhook y prueba real posterior

Callback del CRM:

```text
https://fundacionrededumex.org/index.php?controller=whatsappWebhook&action=webhook
```

Tras configurar, se puede comprobar el GET sin imprimir el token:

```bash
python3 tools/configurar_whatsapp_privado.py --site "$SITIO" \
  --probe-webhook --base-url https://fundacionrededumex.org
```

Exige HTTPS, no sigue redirecciones y verifica la respuesta exacta del challenge.
Un resultado correcto acredita la verificación GET, no la recepción POST ni la
validez de Access Token en Meta. La configuración local completa tampoco acredita
conectividad del proveedor ni permisos del token.

Registrar el Phone Number ID como canal de tipo PRUEBA antes de probar entradas:
el receptor existente no crea canales automáticamente. Si hay un canal viejo con
mensajes, conservarlo y registrar el nuevo número como canal separado. No cambiar
el Phone Number ID de un canal histórico ni reasignar sus conversaciones.
Vincular el webhook en Meta y suscribir el campo `messages`. Enviar primero desde
el celular autorizado de prueba hacia el número de prueba y comprobar que la
entrada aparezca en el CRM. Con esa respuesta se prueba el envío de texto desde
un usuario autorizado. La cuenta Administrador no tiene envío operativo en la
política vigente. No migrar el número definitivo hasta comprobar ambos sentidos.

La prueba pendiente debe confirmar entrada, asignación, mensaje de respuesta y
estados entregado/leído, con usuarios y destinatario de prueba autorizados.
Este paso no instala plantillas de producción, avisos nuevos ni un bot.

## Validación

`tests/whatsapp_configuracion_privada_test.py` usa el lector PHP real en carpetas
temporales y secretos sintéticos. Cubre ausencia de secretos en salida, lectura
posterior, conservación de opciones existentes, renovación con respaldo exacto,
Verify Token estable, rechazo de formato y fuentes contradictorias, restauración
ante fallo y verificación GET simulada sin POST. No llama a Meta ni usa SQL.
