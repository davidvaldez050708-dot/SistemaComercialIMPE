# Configuración privada para Hostinger y XAMPP

## ¿Dónde quedan las credenciales?

El código de correo, INEGI, DENUE, Zadarma, WhatsApp y Hostinger Mail permanece en GitHub.
Los tokens y contraseñas reales deben guardarse solo en el servidor y en respaldos privados.

## Importante: respaldar XAMPP ANTES de actualizar con git pull

Los archivos antiguos config/api_keys.php y config/mail_config.php contenían secretos.
El nuevo commit los sustituye por cargadores de configuración sin claves reales.
ANTES de actualizar, ejecuta en PowerShell desde la raíz del proyecto:

    Copy-Item config/api_keys.php config/api_keys.local.php
    Copy-Item config/mail_config.php config/mail_config.local.php

Después puedes ejecutar git switch main y git pull origin main.
Las copias *.local.php se ignoran con .gitignore y el CRM las utilizará
para mantener las conexiones locales. Estos secretos antiguos deben rotarse
y NO se deben copiar como credenciales definitivas de producción.

## Carpeta privada en Hostinger

    domains/tu-dominio/
    ├── public_html/
    │   ├── index.php
    │   ├── .htaccess
    │   ├── app/
    │   ├── config/          (solo cargadores y ejemplos)
    │   ├── public/
    │   └── ...
    └── impe-private/        (FUERA de public_html)
        ├── app.local.php
        ├── api_keys.local.php
        ├── mail_config.local.php
        ├── zadarma_config.php
        ├── whatsapp.local.php
        └── hostinger_mail_config.php

En Hostinger hPanel abre Administrador de archivos → Acceso a todos los
archivos del hosting y crea la carpeta al mismo nivel de public_html.
No la crees dentro de public_html. PHP debe poder leerla, pero no ha
de ser accesible directamente por HTTP. Evita permisos 777.

Si la aplicación no se instala exactamente en public_html, puedes establecer
IMPE_PRIVATE_CONFIG_DIR como ruta absoluta. En XAMPP hay compatibilidad con
config/*.local.php, ignorados por Git y protegidos por .htaccess.

## Configurar las integraciones

1. Copia config/app.local.example.php como impe-private/app.local.php.
   Configura app_env=production, base_url=https://tu-dominio/ y las credenciales
   de la base MySQL creada en hPanel.
2. Copia config/api_keys.local.example.php a impe-private/api_keys.local.php.
   Completa los tokens renovados de INEGI Indicadores y DENUE.
3. Copia config/mail_config.local.example.php a impe-private/mail_config.local.php.
   Usa la contraseña de aplicación SMTP renovada del correo institucional.
4. Copia config/zadarma_config.example.php como impe-private/zadarma_config.php.
   El número virtual puede seguir en verificación; el archivo privado se puede
   preparar sin hacer llamadas.
5. Si se usa WhatsApp Cloud API, copia config/whatsapp.example.php como
   impe-private/whatsapp.local.php y configura las credenciales de Meta.
6. Si se usa Hostinger Mail API, copia config/hostinger_mail_config.example.php
   como impe-private/hostinger_mail_config.php.

No son necesarias todas las integraciones para iniciar el CRM, pero cada módulo
necesita su configuración correspondiente para funcionar.

Opcionalmente el servidor admite APP_ENV, IMPE_BASE_URL, IMPE_DB_HOST,
IMPE_DB_USER, IMPE_DB_PASSWORD, IMPE_DB_NAME, IMPE_PRIVATE_CONFIG_DIR y
las variables de entorno particulares de los servicios.

## Medidas de seguridad

- En producción la URL HTTPS debe configurarse explícitamente, sin localhost.
- En producción no se acepta usuario MySQL root ni contraseña vacía.
- PHP no muestra errores internos al navegador en producción.
- Los errores detallados de conexión MySQL se registran en error_log del servidor.
- La configuración compartida en Git NO incluye nuevas claves reales.
- El historial antiguo de GitHub todavía puede contener secretos expuestos.
  Se debe REVOCAR y REGENERAR correo SMTP y tokens de INEGI/DENUE.
  Hacer privado el repositorio no invalida credenciales comprometidas.
- No borres archivos de la base de datos ni migraciones para hacer estos cambios.

## Prueba local (sin conexiones externas)

    php tests/private_config_smoke.php

Comprueba carga de archivos privados de ejemplo, URL HTTPS, datos de MySQL de
prueba, valores ficticios de correo e INEGI, y la desactivación de display_errors.
No se conecta a proveedores.
