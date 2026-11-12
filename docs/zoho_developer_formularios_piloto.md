# Piloto Zoho CRM Developer Edition — formularios de registro

> **NO PUBLICAR EN PRODUCCIÓN.** Esta rama es un piloto opt-in para una
> instancia de prueba del CRM y una organización **Developer** de Zoho.
> No sincroniza inscripciones ni prospectos reales.
> No contiene credenciales ni ejecuta SQL automáticamente.

## Qué hace

- Tras guardar **un registro** en formulario_registros, el modelo comprueba
  una lista privada **exacta** de correos ficticios del dominio example.com.
- Solo si está habilitado, agrega el ID local a una tabla auxiliar de cola.
  No ejecuta peticiones HTTP durante el registro y no modifica la respuesta
  del formulario. Si no hay configuración, no agrega nada.
- Un worker PHP **CLI** ejecutado por cron lee la cola, comprueba que el
  token corresponde a la organización **Developer esperada**, y envía
  First_Name, Last_Name, Email, Mobile y Company a Zoho Leads.
- Utiliza /crm/v8/Leads/upsert con un campo personalizado único y clave local
  estable IMPE_REGISTRO_<ID>. Esto permite reintentos sin nuevas filas en Zoho.
- Los IDs de Zoho, estados, intentos y códigos de error se guardan en la cola;
  **no** guarda tokens ni copia datos personales en la cola.
- Las inscripciones de formulario_inscripciones y todos los correos no
  autorizados quedan fuera, incluso cuando el worker lee una fila.

## Antes de habilitar: requisitos

1. Trabajar en una **copia/staging** de la base de datos y del sitio,
   sin automatismos de asignación comercial activos. Enviar un formulario
   ficticio a la instalación real también puede contaminar los flujos de
   ventas, aunque Zoho sea Developer.
2. Verificar que PHP CLI tiene cURL, mysqli y conexión a MySQL de staging.
3. Conservar **solo fuera de la web** el archivo OAuth existente:
   ~/.config/sistema_comercial_impe/zoho_oauth.json (permisos 600).
   Su campo api_domain puede decir www.zohoapis.com: la API del worker
   usa deliberadamente developer.zohoapis.com.
4. En **Posibles clientes** de Zoho Developer, crear un campo
   personalizado **Texto de una línea**, por ejemplo "IMPE Registro ID".
   Activar **No permitir valores duplicados**. Verificar el API name real
   en Developer Hub (por ejemplo IMPE_Registro_ID) y usarlo en la
   configuración. La modalidad "External ID" no es necesaria: Zoho la
   limita a ediciones Enterprise/Ultimate.
   **No ejecutar --run si el campo no existe o no es único.**
5. Comprobar que los alcances OAuth permiten crear Leads y leer org.
6. Revisar acceso a datos, avisos de privacidad y retención antes de
   extender este piloto más allá de datos ficticios.

## Configuración privada (solo en staging)

Ubicación preferida: carpeta hermana de public_html llamada
impe-private/zoho_sync.local.php, según config/private_config.php.
Nunca colocar este archivo en Git ni incluir credenciales en él.

~~~php
<?php
return [
    'enabled' => true,
    'environment' => 'developer',
    'expected_org_id' => '4608581000000025312',
    'unique_field' => 'IMPE_Registro_ID',
    'test_emails' => ['demo.zoho.impe@example.com'],
];
~~~

**IMPORTANTE:** este ejemplo solo habilita la dirección exacta indicada.
No admite comodines ni dominios de usuarios reales. El archivo de
configuración no contiene Client ID, Client Secret o tokens. La
habilitación debe realizarse solo en la copia de staging.

## Procedimiento controlado

1. Revisar respaldo de la BD de staging. Aplicar manualmente la migración
   database/zoho_dev_registros_pendientes.sql **solo en staging**.
2. Validar: php -l app/models/FormularioRegistroModel.php
3. Validar: php -l app/services/ZohoDeveloperRegistroSync.php
4. Validar: php -l tools/zoho_developer_registros_worker.php
5. Prueba sin BD ni red: php tests/zoho_developer_registro_smoke.php
6. Validar organización sin escribir: 
   php tools/zoho_developer_registros_worker.php --check
7. Registrar **un solo** formulario con nombre y correo ficticios
   demo.zoho.impe@example.com en staging.
8. Revisar contador sin PII:
   php tools/zoho_developer_registros_worker.php --status
9. Ejecutar **una vez**:
   php tools/zoho_developer_registros_worker.php --run
10. Verificar estado 'enviado' e ID en la cola; comprobar en Zoho Developer
    que el registro aparece y que repetir el worker no crea duplicados.
11. Solo después de las comprobaciones, programar cron en staging:
    * * * * * /usr/bin/php /RUTA_ABSOLUTA/tools/zoho_developer_registros_worker.php --run
    Ajustar la ruta del intérprete y del repositorio según Hostinger.
    La cadencia mínima es un minuto, no envío inmediato síncrono.

## Manejo de fallas y aislamiento

- Fallos transitorios: hasta 6 intentos, con reintentos exponenciales.
- Fallos definitivos o superado el límite: estado revision y revisión manual;
  no reintentar masivamente sin analizar el motivo.
- Si se detiene el proceso tras recibir respuesta de Zoho pero antes de
  guardar la confirmación, el campo único de Zoho permite un upsert idempotente.
- Si falta la migración, el formulario conserva su guardado normal; el
  sistema registra un aviso técnico genérico sin revelar datos.
- No hay SQL de eliminación ni cambios en formulario_inscripciones.
- Para desactivar: quitar cron y establecer enabled=false en la
  configuración privada. No borrar la tabla ni el historial.
- Este PR **no** incluye despliegue, migración en producción, creación de
  campos remotos ni activación de cron.

## Pendientes antes de habilitar el piloto

- Comprobar el estado real de Hostinger y diferencias con la rama principal.
- Validar en staging con MySQL y API Developer reales.
- Verificar que el campo de sincronización está marcado único en la cuenta Zoho.
- Revisar el origen de formularios en otras ramas, en particular los
  cambios posteriores en horario_contacto, antes de integrar a main.
