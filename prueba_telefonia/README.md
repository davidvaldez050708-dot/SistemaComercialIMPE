# Prueba aislada de telefonía

Esta carpeta prueba llamadas reales desde el navegador sin modificar todavía el flujo productivo de Vinculación.

## Qué reutiliza

La prueba utiliza el quickstart de Twilio Serverless que ya está desplegado:

- `/voice-token` genera el Access Token del navegador.
- La TwiML App ya configurada enruta la llamada.
- La Function de llamada tiene activada la grabación `record-from-answer-dual`.

## Configuración local

1. Copia:

   `config/voip_config.example.php`

   como:

   `config/voip_config.php`

2. Completa únicamente en el archivo local:

- `token_url`: URL pública del endpoint `/voice-token` del quickstart.
- `account_sid`: Account SID de Twilio.
- `auth_token`: Auth Token de Twilio.
- `caller_id`: número que actualmente usa la Function como CALLER_ID.

`config/voip_config.php` está ignorado por Git y nunca debe subirse al repositorio.

## Uso

Con Apache/XAMPP activo y una sesión iniciada en SistemaComercialIMPE, abre:

`http://localhost/SistemaComercialIMPE/prueba_telefonia/`

1. Pulsa **Iniciar teléfono** y permite el micrófono.
2. Escribe un número E.164, por ejemplo `+52XXXXXXXXXX`.
3. Pulsa **Llamar**.
4. Usa **Silenciar** o **Colgar** desde la misma página.
5. Al terminar, el historial se actualiza y las grabaciones recientes pueden reproducirse desde la interfaz.

## Seguridad

Esta carpeta es una prueba técnica. Antes de pasarla a producción se debe:

- generar Access Tokens desde el backend real con la identidad del usuario autenticado;
- eliminar la dependencia del endpoint público del quickstart;
- aplicar permisos por rol a llamadas y grabaciones;
- registrar las llamadas contra el seguimiento/institución correspondiente;
- definir política de retención y consentimiento para grabaciones;
- migrar las credenciales a configuración segura del servidor.
