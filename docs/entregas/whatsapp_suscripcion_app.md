# Vinculación de la aplicación propia al webhook

La consulta real del usuario devolvió el número `1508758925647776` bajo la cuenta
`2683955112050855`, con una única aplicación suscrita: `2202427980234937`, llamada
WA DevX Webhook Events 1P App. El ID que el usuario confirmó para su aplicación
Sistema Red Educativa es `1794938525563302`. La prueba de Meta del campo messages
respondió correctamente; aún no se acredita recepción de mensajes reales.

`tools/whatsapp_vincular_webhook.py` utiliza el lector privado de la configuración
preparada anteriormente. No requiere pegar tokens en argumentos ni mostrar los
secretos. No se instala sobre el CRM: se ejecuta desde la entrega privada.

Con `--check` consulta únicamente. Con `--subscribe` realiza estas comprobaciones:

1. Comprueba el token usando debug_token y autenticación de la aplicación propia.
2. Exige token válido, App ID exacto y permisos de gestión y mensajería WhatsApp.
3. Confirma que el número indicado pertenezca a la cuenta indicada.
4. Consulta las aplicaciones suscritas. Si la propia ya existe, no escribe.
5. Si falta, hace POST a subscribed_apps y vuelve a leer para confirmar que la
   aplicación propia quedó suscrita y las anteriores siguen presentes.

No ejecuta DELETE, no cambia callback overrides, no envía mensajes, no consulta ni
modifica SQL y no cambia archivos del sitio ni su configuración privada. Los
errores de proveedor muestran únicamente HTTP y códigos numéricos, sin sus
mensajes originales ni URLs privadas. No reintenta automáticamente un POST con
resultado desconocido; se debe consultar --check.

Si el token pertenece a la aplicación de herramientas de Meta en vez de la propia,
la comprobación bloquea el POST. Se deberá generar un token de la aplicación
propia con ambos permisos y renovar el archivo privado conservando Verify Token.
No se considera arreglada la recepción solo por obtener success: falta enviar
un mensaje nuevo desde el celular autorizado y comprobar su entrada en el CRM.

Ejemplo con los IDs confirmados:

```bash
python3 tools/whatsapp_vincular_webhook.py --site "$SITIO" \
  --app-id 1794938525563302 --account-id 2683955112050855 \
  --phone-id 1508758925647776 --subscribe
```

Referencias oficiales consultadas:
- https://developers.facebook.com/docs/graph-api/reference/debug_token/
- https://developers.facebook.com/documentation/business-messaging/whatsapp/reference/whatsapp-business-account/subscribed-apps-api
- https://developers.facebook.com/documentation/business-messaging/whatsapp/account-model-evolution

`tests/whatsapp_vincular_webhook_test.py` sustituye el transporte Meta con datos
sintéticos. Comprueba bloqueo de token ajeno/vencido, permisos ausentes y número
incorrecto; ausencia de POST en --check y segunda ejecución; preservación de otras
apps; lectura posterior obligatoria; ausencia de secretos en errores. No se
realizaron llamadas reales a Meta desde las pruebas locales.
